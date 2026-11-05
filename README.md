# Hyperf Streamable HTTP + SSE MCP Server Demo

这是一个运行于 PHP 8.5、基于 Hyperf 3.2 发布线的标准 Streamable HTTP MCP Server。服务端使用单一 `POST /mcp` 端点，并以 `text/event-stream` 返回请求级 SSE 响应；暴露的 `calculate_discount` 工具用于计算折扣与应付金额。

> Hyperf `v3.2.4` 是整体仓库 Release；Hyperf 采用拆分组件发布，`hyperf/framework` 组件当前稳定版本仍是 `v3.2.0`。本项目使用 `~3.2.0` 约束并由 `composer.lock` 锁定该 Release 对应的实际组件版本。

## 启动

```bash
cp .env.example .env
composer install
php bin/hyperf.php start
```

默认监听 `0.0.0.0:9501`：

- MCP 端点：`POST http://127.0.0.1:9501/mcp`
- 健康检查：`GET http://127.0.0.1:9501/health`

现代 `2026-07-28` Streamable HTTP 已取消 GET SSE 端点和协议级 Session。因此本项目不会另设 `/sse`：SSE 就是 `POST /mcp` 的请求级响应，最终 JSON-RPC 响应发送后连接结束。

## 调用工具（2026-07-28）

```bash
curl -i -N http://127.0.0.1:9501/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/call' \
  -H 'Mcp-Name: calculate_discount' \
  --data-binary '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/call",
    "params": {
      "name": "calculate_discount",
      "arguments": {
        "original_price": 100,
        "discount_percent": 20,
        "quantity": 3
      },
      "_meta": {
        "io.modelcontextprotocol/protocolVersion": "2026-07-28",
        "io.modelcontextprotocol/clientInfo": {
          "name": "curl-demo",
          "version": "1.0.0"
        },
        "io.modelcontextprotocol/clientCapabilities": {},
        "progressToken": "discount-1"
      }
    }
  }'
```

响应的 `Content-Type` 为 `text/event-stream`。带 `progressToken` 的工具调用会先返回一条 `notifications/progress` SSE 消息，再返回最终 JSON-RPC 结果。

## 兼容旧版初始化流程

服务端也兼容 `2025-11-25`、`2025-06-18` 和 `2025-03-26`。初始化示例：

```bash
curl -i -N http://127.0.0.1:9501/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  --data-binary '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl-demo","version":"1.0.0"}}}'
```

本示例不创建 `Mcp-Session-Id`，旧版请求按无状态模式处理。工具列表和调用仍使用同一个 `POST /mcp` 端点。

## 安全边界

- 所有请求校验 `Content-Type` 和 `Accept`。
- `2026-07-28` 请求校验 `MCP-Protocol-Version`、`Mcp-Method`、`Mcp-Name` 与 JSON-RPC body 一致。
- 存在 `Origin` 时必须命中 `MCP_ALLOWED_ORIGINS` 白名单，防止 DNS rebinding。
- 生产环境应放在 TLS 与认证网关之后；本示例未内置身份认证、限流和审计存储。

## 测试

```bash
composer test
```
