<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\ProtocolHandler;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponseInterface;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class McpController
{
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly ProtocolHandler $handler,
        private readonly HyperfResponseInterface $response,
        private readonly ConfigInterface $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $originError = $this->validateOrigin($request);
        if ($originError !== null) {
            return $originError;
        }

        if (! str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            return $this->jsonRpcError(null, -32600, 'Content-Type must be application/json', 415);
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        if (! str_contains($accept, 'application/json') || ! str_contains($accept, 'text/event-stream')) {
            return $this->jsonRpcError(null, -32600, 'Accept must include application/json and text/event-stream', 406);
        }

        $rawBody = (string) $request->getBody();
        try {
            $message = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($message)) {
                throw new JsonException('JSON-RPC body must be an object.');
            }
        } catch (JsonException) {
            return $this->jsonRpcError(null, -32700, 'Parse error', 400);
        }

        $metadataError = $this->validateRequestMetadata($request, $message);
        if ($metadataError !== null) {
            return $metadataError;
        }

        $responseJson = $this->handler->handleJson($rawBody);
        if ($responseJson === null) {
            return $this->emptyResponse(202);
        }

        $decodedResponse = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        $errorCode = $decodedResponse['error']['code'] ?? null;
        if ($errorCode === -32022) {
            return $this->jsonResponse($decodedResponse, 400);
        }
        if ($errorCode === -32601 && $this->handler->isModernMessage($message)) {
            return $this->jsonResponse($decodedResponse, 404);
        }
        if (in_array($errorCode, [-32700, -32600], true)) {
            return $this->jsonResponse($decodedResponse, 400);
        }

        $events = [];
        $progressToken = $message['params']['_meta']['progressToken'] ?? null;
        if (($message['method'] ?? null) === 'tools/call' && (is_string($progressToken) || is_int($progressToken))) {
            $events[] = $this->encode([
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => [
                    'progressToken' => $progressToken,
                    'progress' => 1,
                    'total' => 1,
                    'message' => '折扣计算完成',
                ],
            ]);
        }
        $events[] = $responseJson;

        return $this->sseResponse($events);
    }

    private function validateOrigin(ServerRequestInterface $request): ?ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return null;
        }

        $allowedOrigins = $this->config->get('mcp.allowed_origins', []);
        if (! is_array($allowedOrigins) || (! in_array('*', $allowedOrigins, true) && ! in_array($origin, $allowedOrigins, true))) {
            return $this->jsonRpcError(null, -32600, 'Forbidden Origin', 403);
        }

        return null;
    }

    private function validateRequestMetadata(ServerRequestInterface $request, array $message): ?ResponseInterface
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $bodyVersion = $message['params']['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
        $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');

        if ($bodyVersion !== null || $headerVersion === ProtocolHandler::MODERN_PROTOCOL_VERSION) {
            if (! is_string($bodyVersion) || $headerVersion !== $bodyVersion) {
                return $this->headerMismatch($id, 'MCP-Protocol-Version header does not match request metadata');
            }

            if ($request->getHeaderLine('Mcp-Method') !== $method) {
                return $this->headerMismatch($id, 'Mcp-Method header does not match the JSON-RPC method');
            }

            if ($method === 'tools/call') {
                $toolName = $message['params']['name'] ?? null;
                if (! is_string($toolName) || $request->getHeaderLine('Mcp-Name') !== $toolName) {
                    return $this->headerMismatch($id, 'Mcp-Name header does not match the requested tool');
                }
            }
        }

        if ($method !== 'initialize' && $headerVersion !== '' && ! in_array(
            $headerVersion,
            [ProtocolHandler::MODERN_PROTOCOL_VERSION, ...ProtocolHandler::LEGACY_PROTOCOL_VERSIONS],
            true,
        )) {
            return $this->jsonRpcError(
                $id,
                -32022,
                'Unsupported protocol version',
                400,
                [
                    'supported' => [ProtocolHandler::MODERN_PROTOCOL_VERSION, ...ProtocolHandler::LEGACY_PROTOCOL_VERSIONS],
                    'requested' => $headerVersion,
                ],
            );
        }

        return null;
    }

    private function headerMismatch(mixed $id, string $message): ResponseInterface
    {
        return $this->jsonRpcError($id, -32020, 'Header mismatch: ' . $message, 400);
    }

    private function jsonRpcError(mixed $id, int $code, string $message, int $status, mixed $data = null): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return $this->jsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ], $status);
    }

    private function jsonResponse(array $body, int $status): ResponseInterface
    {
        return $this->response->json($body)
            ->withStatus($status)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function emptyResponse(int $status): ResponseInterface
    {
        return $this->response->raw('')
            ->withStatus($status)
            ->withoutHeader('Content-Type');
    }

    /** @param list<string> $events */
    private function sseResponse(array $events): ResponseInterface
    {
        $body = '';
        foreach ($events as $event) {
            $body .= "event: message\n";
            foreach (explode("\n", $event) as $line) {
                $body .= 'data: ' . $line . "\n";
            }
            $body .= "\n";
        }

        return $this->response->raw($body)
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-transform')
            ->withHeader('X-Accel-Buffering', 'no');
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}
