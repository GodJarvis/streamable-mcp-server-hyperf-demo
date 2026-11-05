<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'app_name' => env('APP_NAME', 'streamable-mcp-server-demo'),
    'scan_cacheable' => false,
];
