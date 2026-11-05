<?php

declare(strict_types=1);

use function Hyperf\Support\env;

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('MCP_ALLOWED_ORIGINS', 'http://localhost,http://127.0.0.1')),
)));

return [
    'allowed_origins' => $allowedOrigins,
];
