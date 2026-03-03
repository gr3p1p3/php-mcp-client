<?php

declare(strict_types=1);

namespace PhpMcp\Client\Transport\Http;

use Evenement\EventEmitterTrait;
use PhpMcp\Client\Contracts\TransportInterface;
use PhpMcp\Client\Exception\TransportException;
use PhpMcp\Client\JsonRpc\Message;
use PhpMcp\Client\JsonRpc\Notification;
use PhpMcp\Client\JsonRpc\Request;
use PhpMcp\Client\JsonRpc\Response;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * MCP Streamable HTTP Transport (2025-03-26 spec).
 *
 * Unlike the legacy SSE transport (which opens a persistent GET stream and
 * waits for an `endpoint` event), Streamable HTTP works as follows:
 *
 *  1. POST initialize  → server responds with SSE stream (or plain JSON)
 *                        containing the initialize result.
 *  2. POST notifications/initialized  → server responds 202 (no body).
 *  3. Every subsequent POST may return either:
 *       - 200 + Content-Type: text/event-stream  (one or more SSE events)
 *       - 200 + Content-Type: application/json   (single JSON-RPC response)
 *       - 202                                     (notification accepted)
 *
 * The `Mcp-Session-Id` header, when present in a response, must be echoed
 * back in every subsequent request.
 *
 * Required request headers (Activepieces and spec-compliant servers):
 *   Accept: application/json, text/event-stream
 *   Content-Type: application/json
 */
class StreamableHttpClientTransport implements TransportInterface, LoggerAwareInterface
{
    use EventEmitterTrait;
    use LoggerAwareTrait;

    private ?string $sessionId = null;
    private bool $connected = false;
    private bool $closing = false;

    /** Pending RPC calls waiting for a response: id => Deferred */
    private array $pending = [];

    private Browser $httpClient;

    public function __construct(
        private readonly string        $url,
        private readonly LoopInterface $loop,
        private readonly ?array        $headers = null,
    )
    {
        $this->httpClient = new Browser(loop: $this->loop);
        $this->logger = new NullLogger();
    }

    // -------------------------------------------------------------------------
    // TransportInterface
    // -------------------------------------------------------------------------

    public function connect(): PromiseInterface
    {
        $deferred = new Deferred();

        $initMessage = new Request(
            id: 1,
            method: 'initialize',
            params: [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'php-mcp-client', 'version' => '1.0.0'],
            ]
        );

        $this->logger->debug('StreamableHTTP: Connecting via initialize POST', ['url' => $this->url]);

        $this->postMessage($initMessage)
            ->then(
                function (array $responseData) use ($deferred) {
                    $this->logger->debug('StreamableHTTP: initialize response received', $responseData);

                    // Send notifications/initialized (fire-and-forget, 202 expected)
                    $notification = new Notification(
                        method: 'notifications/initialized',
                        params: []
                    );

                    $this->postMessage($notification)
                        ->then(
                            function () use ($deferred) {
                                $this->connected = true;
                                $this->logger->info('StreamableHTTP: Connected and initialized.');
                                $deferred->resolve(null);
                            },
                            function (Throwable $e) use ($deferred) {
                                // Some servers respond 202 which ReactPHP may surface as an error;
                                // treat any response to the notification as success.
                                $this->connected = true;
                                $this->logger->info('StreamableHTTP: Connected (notification ack: ' . $e->getMessage() . ')');
                                $deferred->resolve(null);
                            }
                        );
                },
                function (Throwable $e) use ($deferred) {
                    $this->logger->error('StreamableHTTP: Connection failed', ['error' => $e->getMessage()]);
                    $deferred->reject(new TransportException('Connection failed: ' . $e->getMessage(), 0, $e));
                }
            );

        return $deferred->promise();
    }

    public function send(Message $message): PromiseInterface
    {
        if ($this->closing) {
            return \React\Promise\reject(new TransportException('Transport is closing.'));
        }

        return $this->postMessage($message);
    }

    public function close(): void
    {
        if ($this->closing) {
            return;
        }

        $this->closing = false;
        $this->connected = false;

        foreach ($this->pending as $deferred) {
            $deferred->reject(new TransportException('Transport closed.'));
        }

        $this->pending = [];
        $this->emit('close', ['Client initiated close.']);
        $this->removeAllListeners();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * POST a JSON-RPC message and return a promise that resolves with the
     * decoded response array (for Requests) or resolves void (for Notifications).
     */
    private function postMessage(Message $message): PromiseInterface
    {
        $deferred = new Deferred();

        try {
            $body = json_encode($message->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = $this->buildHeaders();

            $this->logger->debug('StreamableHTTP: POST', [
                'url' => $this->url,
                'method' => $message->toArray()['method'] ?? '?',
            ]);

            $this->httpClient
                ->post($this->url, $headers, $body)
                ->then(
                    function (\Psr\Http\Message\ResponseInterface $response) use ($deferred, $message) {
                        $statusCode = $response->getStatusCode();

                        // Capture session ID from any response
                        if ($response->hasHeader('Mcp-Session-Id')) {
                            $newId = $response->getHeaderLine('Mcp-Session-Id');
                            if ($newId !== $this->sessionId) {
                                $this->sessionId = $newId;
                                $this->logger->info('StreamableHTTP: Session ID received', ['session_id' => $this->sessionId]);
                                $this->emit('session_id_received', [$this->sessionId]);
                            }
                        }

                        // 202 Accepted → notification was accepted, nothing to parse
                        if ($statusCode === 202) {
                            $deferred->resolve([]);
                            return;
                        }

                        if ($statusCode < 200 || $statusCode >= 300) {
                            $body = (string)$response->getBody();
                            $deferred->reject(new TransportException("HTTP {$statusCode}: {$body}"));
                            return;
                        }

                        $contentType = $response->getHeaderLine('Content-Type');
                        $rawBody = (string)$response->getBody();

                        $this->logger->debug('StreamableHTTP: Response received', [
                            'status' => $statusCode,
                            'content-type' => $contentType,
                        ]);

                        if (str_contains($contentType, 'text/event-stream')) {
                            $parsed = $this->parseSseBody($rawBody);
                            if ($parsed !== null) {
                                $this->dispatchParsedMessage($parsed);
                                $deferred->resolve($parsed);
                            } else {
                                $deferred->resolve([]);
                            }
                        } elseif (str_contains($contentType, 'application/json')) {
                            $parsed = json_decode($rawBody, true);
                            if ($parsed !== null) {
                                $this->dispatchParsedMessage($parsed);
                                $deferred->resolve($parsed);
                            } else {
                                $deferred->reject(new TransportException('Failed to decode JSON response: ' . $rawBody));
                            }
                        } else {
                            // Unknown content type — try JSON anyway
                            $parsed = json_decode($rawBody, true);
                            $deferred->resolve($parsed ?? []);
                        }
                    },
                    function (Throwable $error) use ($deferred) {
                        $this->logger->error('StreamableHTTP: POST failed', ['error' => $error->getMessage()]);
                        $deferred->reject(new TransportException('POST failed: ' . $error->getMessage(), 0, $error));
                    }
                );
        }
        catch (Throwable $e) {
            $deferred->reject(new TransportException('Failed to prepare POST: ' . $e->getMessage(), 0, $e));
        }

        return $deferred->promise();
    }

    /**
     * Parse a text/event-stream body and return the data from the first
     * `message` event as a decoded array.
     *
     * SSE format:
     *   event: message\n
     *   data: {...}\n
     *   \n
     */
    private function parseSseBody(string $body): ?array
    {
        $event = 'message';
        $data = '';

        foreach (explode("\n", str_replace("\r\n", "\n", $body)) as $line) {
            $line = rtrim($line, "\r");

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $chunk = ltrim(substr($line, 5), ' ');
                $data .= ($data === '' ? '' : "\n") . $chunk;
            }
        }

        if ($data === '') {
            return null;
        }

        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $e) {
            $this->logger->warning('StreamableHTTP: Failed to decode SSE data', ['data' => $data]);
            return null;
        }
    }

    /**
     * Emit a `message` event on the transport so the Client layer can handle
     * JSON-RPC responses / notifications as usual.
     */
    private function dispatchParsedMessage(array $data): void
    {
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            return;
        }

        try {
            $message = null;

            if (isset($data['method'])) {
                $message = isset($data['id'])
                    ? Request::fromArray($data)
                    : Notification::fromArray($data);
            } elseif (isset($data['id'])) {
                $message = Response::fromArray($data);
            }

            if ($message !== null) {
                $this->emit('message', [$message]);
            }
        }
        catch (Throwable $e) {
            $this->logger->warning('StreamableHTTP: Could not dispatch message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Build the full header map for each request.
     */
    private function buildHeaders(): array
    {
        $headers = $this->headers ?? [];

        // These two are mandatory for Streamable HTTP
        $headers['Accept'] = 'application/json, text/event-stream';
        $headers['Content-Type'] = 'application/json';

        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return $headers;
    }
}
