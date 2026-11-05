<?php

declare(strict_types=1);

namespace App\Mcp;

use DomainException;
use JsonException;
use Throwable;

final class ProtocolHandler
{
    public const string MODERN_PROTOCOL_VERSION = '2026-07-28';

    public const array LEGACY_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
    ];

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    public function __construct(private readonly DiscountTool $discountTool)
    {
    }

    public function handleJson(string $json): ?string
    {
        try {
            $message = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($message)) {
                throw new McpProtocolException(-32600, 'Invalid Request');
            }
        } catch (JsonException) {
            return $this->encode($this->error(null, -32700, 'Parse error'));
        } catch (McpProtocolException $exception) {
            return $this->encode($this->error(null, $exception->getCode(), $exception->getMessage()));
        }

        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message);

        try {
            $response = $this->handle($message);
            return $response === null ? null : $this->encode($response);
        } catch (McpProtocolException $exception) {
            if ($isNotification) {
                return null;
            }

            return $this->encode($this->error($id, $exception->getCode(), $exception->getMessage(), $exception->errorData));
        } catch (Throwable) {
            if ($isNotification) {
                return null;
            }

            return $this->encode($this->error($id, -32603, 'Internal error'));
        }
    }

    public function handle(array $message): ?array
    {
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! isset($message['method']) || ! is_string($message['method'])) {
            throw new McpProtocolException(-32600, 'Invalid Request');
        }

        $method = $message['method'];
        $isNotification = ! array_key_exists('id', $message);
        $params = $message['params'] ?? [];
        if (! is_array($params)) {
            throw new McpProtocolException(-32602, 'Invalid params');
        }

        if ($isNotification) {
            return null;
        }

        $id = $message['id'];
        $modern = $this->isModernRequest($method, $params);

        $result = match ($method) {
            'server/discover' => $this->discover(),
            'initialize' => $this->initialize($params),
            'ping' => [],
            'tools/list' => $this->listTools($modern),
            'tools/call' => $this->callTool($params, $modern),
            default => throw new McpProtocolException(-32601, 'Method not found'),
        };

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    public function isModernMessage(array $message): bool
    {
        $params = $message['params'] ?? [];
        if (! is_array($params)) {
            return false;
        }

        return isset($params['_meta']['io.modelcontextprotocol/protocolVersion']);
    }

    private function isModernRequest(string $method, array $params): bool
    {
        if ($method === 'initialize') {
            return false;
        }

        $version = $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
        if ($version === null) {
            if ($method === 'server/discover') {
                throw new McpProtocolException(-32602, 'Modern request metadata is required');
            }

            return false;
        }

        if ($version !== self::MODERN_PROTOCOL_VERSION) {
            throw new McpProtocolException(
                -32022,
                'Unsupported protocol version',
                [
                    'supported' => [self::MODERN_PROTOCOL_VERSION],
                    'requested' => is_scalar($version) ? (string) $version : '',
                ],
            );
        }

        $clientCapabilities = $params['_meta']['io.modelcontextprotocol/clientCapabilities'] ?? null;
        if (! is_array($clientCapabilities)) {
            throw new McpProtocolException(-32602, 'Client capabilities metadata is required');
        }

        return true;
    }

    private function discover(): array
    {
        return [
            'resultType' => 'complete',
            'supportedVersions' => [self::MODERN_PROTOCOL_VERSION],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'instructions' => '使用 calculate_discount 计算商品折扣、优惠金额与最终应付金额。',
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => $this->serverInfo(),
            ],
            'ttlMs' => 3600000,
            'cacheScope' => 'public',
        ];
    }

    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? self::LEGACY_PROTOCOL_VERSIONS[0];
        $version = is_string($requested) && in_array($requested, self::LEGACY_PROTOCOL_VERSIONS, true)
            ? $requested
            : self::LEGACY_PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => $this->serverInfo(),
            'instructions' => '使用 calculate_discount 计算商品折扣、优惠金额与最终应付金额。',
        ];
    }

    private function listTools(bool $modern): array
    {
        $result = ['tools' => [$this->discountTool->definition()]];

        if ($modern) {
            $result = [
                'resultType' => 'complete',
                ...$result,
                'ttlMs' => 300000,
                'cacheScope' => 'public',
            ];
        }

        return $result;
    }

    private function callTool(array $params, bool $modern): array
    {
        $name = $params['name'] ?? null;
        if ($name !== DiscountTool::NAME) {
            throw new McpProtocolException(-32602, 'Unknown tool: ' . (is_scalar($name) ? (string) $name : ''));
        }

        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments)) {
            throw new McpProtocolException(-32602, 'Tool arguments must be an object');
        }

        try {
            $structuredContent = $this->discountTool->invoke($arguments);
            $result = [
                'content' => [[
                    'type' => 'text',
                    'text' => $this->encode($structuredContent),
                ]],
                'structuredContent' => $structuredContent,
                'isError' => false,
            ];
        } catch (DomainException $exception) {
            $result = [
                'content' => [[
                    'type' => 'text',
                    'text' => '参数错误：' . $exception->getMessage(),
                ]],
                'isError' => true,
            ];
        }

        if ($modern) {
            $result = ['resultType' => 'complete', ...$result];
        }

        return $result;
    }

    private function serverInfo(): array
    {
        return [
            'name' => 'hyperf-discount-streamable-http',
            'title' => 'Hyperf 折扣计算 MCP Server（Streamable HTTP + SSE）',
            'version' => '1.0.0',
        ];
    }

    private function error(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}
