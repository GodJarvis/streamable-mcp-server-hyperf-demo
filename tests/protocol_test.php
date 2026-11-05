<?php

declare(strict_types=1);

use App\Mcp\DiscountTool;
use App\Mcp\ProtocolHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$handler = new ProtocolHandler(new DiscountTool());

$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$request = static function (array $message) use ($handler): array {
    $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $response = $handler->handleJson($json);
    if ($response === null) {
        throw new RuntimeException('Expected a response, got null.');
    }
    return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
};

$modernMeta = [
    'io.modelcontextprotocol/protocolVersion' => ProtocolHandler::MODERN_PROTOCOL_VERSION,
    'io.modelcontextprotocol/clientInfo' => ['name' => 'protocol-test', 'version' => '1.0.0'],
    'io.modelcontextprotocol/clientCapabilities' => [],
];

$discover = $request([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'server/discover',
    'params' => ['_meta' => $modernMeta],
]);
$expect($discover['result']['supportedVersions'] === ['2026-07-28'], 'Discover version mismatch.');
$expect(isset($discover['result']['capabilities']['tools']), 'Tools capability missing.');

$list = $request([
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'tools/list',
    'params' => ['_meta' => $modernMeta],
]);
$expect($list['result']['resultType'] === 'complete', 'Modern resultType missing.');
$expect($list['result']['tools'][0]['name'] === DiscountTool::NAME, 'Tool name mismatch.');

$call = $request([
    'jsonrpc' => '2.0',
    'id' => 3,
    'method' => 'tools/call',
    'params' => [
        'name' => DiscountTool::NAME,
        'arguments' => [
            'original_price' => 100,
            'discount_percent' => 20,
            'quantity' => 3,
        ],
        '_meta' => $modernMeta,
    ],
]);
$expect($call['result']['isError'] === false, 'Valid call returned an error.');
$expect($call['result']['structuredContent']['total_payable'] === 240.0, 'Payable amount mismatch.');
$expect($call['result']['structuredContent']['total_discount_amount'] === 60.0, 'Discount amount mismatch.');

$invalidCall = $request([
    'jsonrpc' => '2.0',
    'id' => 4,
    'method' => 'tools/call',
    'params' => [
        'name' => DiscountTool::NAME,
        'arguments' => ['original_price' => 100, 'discount_percent' => 101],
        '_meta' => $modernMeta,
    ],
]);
$expect($invalidCall['result']['isError'] === true, 'Invalid arguments must return isError=true.');

$initialize = $request([
    'jsonrpc' => '2.0',
    'id' => 5,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-11-25',
        'capabilities' => [],
        'clientInfo' => ['name' => 'legacy-test', 'version' => '1.0.0'],
    ],
]);
$expect($initialize['result']['protocolVersion'] === '2025-11-25', 'Legacy version negotiation failed.');

$parseError = json_decode($handler->handleJson('{'), true, 512, JSON_THROW_ON_ERROR);
$expect($parseError['error']['code'] === -32700, 'Parse error code mismatch.');

$notification = $handler->handleJson('{"jsonrpc":"2.0","method":"notifications/initialized"}');
$expect($notification === null, 'Notifications must not produce a response.');

echo "protocol tests passed\n";
