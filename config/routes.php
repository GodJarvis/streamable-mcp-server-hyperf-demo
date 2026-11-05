<?php

declare(strict_types=1);

use App\Controller\McpController;
use Hyperf\HttpServer\Router\Router;

Router::post('/mcp', [McpController::class, 'handle']);

Router::get('/health', static fn (): array => [
    'status' => 'ok',
    'server' => 'hyperf-discount-streamable-http',
    'transports' => ['streamable-http', 'sse'],
    'protocol_versions' => ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'],
]);
