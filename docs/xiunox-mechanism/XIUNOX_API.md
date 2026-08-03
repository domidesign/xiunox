# XIUNOX_API API 机制

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 提供了一套完整的 RESTful API 系统，所有接口统一挂载在 `/api/v1/` 路径下。API 架构采用分层中间件设计，请求需依次穿越 CORS 处理、全局开关检查、应用级鉴权、IP 白名单、Scope 权限校验、资源白名单以及速率限制等多个安全层，确保接口安全可控。

认证体系采用双重密钥模式：服务端调用需同时携带 `X-App-Id` 与 `X-App-Secret` 头完成完整鉴权，可访问受保护资源；客户端调用仅携带 `X-App-Id` 即可，安全依靠 Bearer Token 与更严格的限流策略。用户身份通过 `Authorization: Bearer <access_token>` 头识别，支持 access_token / refresh_token 双令牌机制及 OAuth 2.1 严格重用检测。响应统一使用 `{code, msg, data}` JSON 格式，并可选触发 `api_response_before_send` 钩子。

## 相关文档

| 文档 | 说明 |
|------|------|
| [API 参考文档](../api/README.md) | 按资源组织的 API 端点详细参考（路径、参数、示例） |
| [认证机制](../api/authentication.md) | 双密钥模式、Token 生命周期、OAuth 2.1 重用检测 |
| [错误码说明](../api/errors.md) | 所有 API 错误码及含义 |
| [限流机制](../api/rate-limiting.md) | 限流规则、配置与绕过方式 |
| [API 代码审查报告](../API_CODE_REVIEW.md) | API 代码库审查结果与优化建议 |

## 站长指南

### 配置入口

登录管理后台 → **系统设置** → **API 设置**，可配置以下核心项：

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `api_enabled` | API 全局开关（0=关闭，1=开启） | 1 |
| `api_cors_origin` | CORS 允许的域名，支持 `*`、单域名或逗号分隔多域名 | `*` |
| `api_rate_limit` | 限流开关（0=关闭，1=开启） | 1 |
| `api_rate_limit_max` | 匿名请求最大次数 | 60 |
| `api_rate_limit_window` | 限流时间窗口（秒） | 60 |
| `api_token_expire` | Refresh Token 有效期（天） | 30 |
| `api_access_token_expire` | Access Token 有效期（小时） | 2 |

### 配置项说明

**API 开关控制**：将 `api_enabled` 设为 0 后，所有 API 请求将返回 HTTP 503。临时关闭可用于系统维护或安全应急场景。

**密钥管理**：后台"应用管理"中可创建、重置、删除 API 应用。每个应用拥有独立的 `appid` 和 `secret`，支持细粒度权限配置：

- **Scope 等级**：`readonly`（只读）、`readwrite`（增改删）、`full`（全部）
- **Permissions 矩阵**：可为每个资源单独设置 `r`（只读）/`rw`（读写）/`-`（禁止）
- **IP 白名单**：支持 CIDR 格式，限制特定 IP 段访问
- **速率限制**：单应用独立限流，或启用 `skip_rate_limit` 能力绕过

**CORS 设置**：生产环境建议将 `api_cors_origin` 设为具体域名，启用凭据模式可携带 Cookie 与认证头。

### 使用场景

1. **站点关闭 API**：系统升级时临时关闭 API，避免外部调用导致数据不一致
2. **第三方对接**：为每个合作方创建独立应用，通过 Scope 限制其可访问的资源
3. **移动端接入**：移动端使用客户端模式（仅 X-App-Id），配合更严格的 30 次/分钟限流保护
4. **安全审计**：通过 `api_log` 记录所有 API 调用的资源、用户、耗时等信息

### 注意事项

- **密钥妥善保管**：`appsecret` 泄露后应立即在后台重置，旧密钥将立即失效
- **Redis 优先**：限流服务优先使用 Redis（Sorted Set 滑动窗口），Redis 不可用时降级为文件系统，建议生产环境启用 Redis
- **权限最小化**：为第三方应用分配最小必要权限，避免使用 `full` Scope
- **监控 API 日志**：开启 `api_log` 可在 `api_log` 表中查询调用记录，辅助问题排查与安全审计
- **Token 过期策略**：`api_token_absolute_expire` 为 90 天强制上限，到期需用户重新登录

## 开发者指南

### 核心服务类

| 服务类 | 路径 | 职责 |
|--------|------|------|
| `ApiAuthService` | `lib/ApiAuthService.php` | 应用鉴权、Token 生命周期管理、Scope 校验 |
| `ApiResponse` | `lib/ApiResponse.php` | 统一响应输出、安全输入过滤 |
| `RateLimitService` | `lib/RateLimitService.php` | 速率限制（Redis/文件双驱动） |
| `PluginApiRegistry` | `lib/PluginApiRegistry.php` | 插件 API 路由注册与解析 |

### 中间件流程

请求到达后依次经过以下中间件层：

```
CORS 处理 → 全局开关检查 → 应用鉴权 → IP 白名单 → Scope 权限校验 → 资源白名单 → 限流检查 → 路由分发
```

各层失败时返回对应 HTTP 状态码与错误信息，成功后进入路由分发阶段。

### 路由注册

核心路由位于 `api/v1/` 目录下，每个资源对应一个独立文件：

```
/api/v1/auth.php     → 认证相关接口
/api/v1/user.php     → 用户资源
/api/v1/thread.php   → 帖子资源
/api/v1/post.php     → 回复资源
/api/v1/forum.php    → 版块资源
/api/v1/notify.php   → 通知资源
/api/v1/credits.php  → 积分资源
/api/v1/mod.php      → 管理操作
/api/v1/site.php     → 站点信息
```

**RESTful 约定**：
- `GET /{resource}`：列表查询（支持分页参数 `page`/`pagesize`）
- `GET /{resource}/{id}`：详情查询
- `POST /{resource}`：创建资源
- `PUT /{resource}/{id}`：更新资源
- `DELETE /{resource}/{id}`：删除资源

### 响应格式规范

所有接口统一返回 JSON 格式：

```json
{
  "code": 0,
  "msg": "ok",
  "data": { ... }
}
```

**状态码说明**：

| code | 含义 |
|------|------|
| 0 | 成功 |
| 401 | 未授权 / 认证失败 |
| 403 | 权限不足 |
| 404 | 资源不存在 |
| 422 | 参数验证失败 |
| 429 | 请求过于频繁 |

### 认证流程

```php
// 1. 应用鉴权（请求头）
$appId = $_SERVER['HTTP_X_APP_ID'];     // 必需
$appSecret = $_SERVER['HTTP_X_APP_SECRET']; // 服务端调用必需

// 2. 用户身份认证（登录获取令牌）
POST /api/v1/auth/login
Body: { "email": "user@example.com", "password": "***" }
Response: {
  "access_token": "abc...",
  "refresh_token": "def...",
  "expires_in": 7200
}

// 3. 携带令牌访问受保护资源
GET /api/v1/thread
Header: Authorization: Bearer abc...
```

### 扩展方式

**插件 API 扩展**：插件可通过 `PluginApiRegistry` 注册自定义路由。在 `plugin/{dir}/api_register.php` 中：

```php
<?php exit;

use PluginApiRegistry;

PluginApiRegistry::register('lottery', __DIR__ . '/api/lottery.php');
```

注册后，`/api/v1/lottery/*` 路径将自动分发到插件路由文件。

**注册自定义 Scope**：插件可注册自身的权限 Scope，供后台配置 UI 使用：

```php
ApiAuthService::registerScope('lottery:participate');
```

**响应钩子**：通过 `api_response_before_send` 钩子可拦截所有 API 响应：

```php
add_hook('api_response_before_send', function($response) {
    $response['server_time'] = time();
    return $response;
});
```

### 代码示例

**PHP 客户端调用**：

```php
$appId = 'your_app_id';
$appSecret = 'your_app_secret';

$ch = curl_init('https://your-site.com/api/v1/thread');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'X-App-Id: ' . $appId,
        'X-App-Secret: ' . $appSecret,
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'fid' => 1,
        'subject' => 'Hello',
        'message' => 'World',
    ]),
]);

$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($response['code'] === 0) {
    echo '创建成功，帖子ID：' . $response['data']['tid'];
}
```

## 常见问题

1. **API 返回 503 错误？**
   检查后台"API 设置"中的 `api_enabled` 开关是否开启。关闭状态下所有请求都会返回 503。

2. **请求被限流返回 429？**
   限流规则基于 IP + 用户标识。未登录用户默认 60 次/分钟，客户端模式应用 30 次/分钟，服务端应用 120 次/分钟。可在后台调整 `api_rate_limit_max` 参数，或为应用启用 `skip_rate_limit` 能力。响应头中的 `X-RateLimit-Remaining` 显示剩余次数。

3. **刷新 Token 时返回 401？**
   可能原因：access_token 已过期需用 refresh_token 刷新；refresh_token 已过 30 天滑动有效期；触发了 OAuth 2.1 重用检测（refresh_token 被二次使用，系统会撤销该用户全部 Token）。

4. **CORS 预检请求被拦截？**
   确保 Nginx/Apache 正确转发 `OPTIONS` 请求到 PHP。CORS 预检请求在鉴权中间件之前处理，返回 204 状态码。如果 `api_cors_origin` 设置为特定域名，浏览器 Origin 必须完全匹配。

5. **如何为插件添加新的 API 端点？**
   在插件目录创建 `api_register.php` 文件，调用 `PluginApiRegistry::register()` 注册路由映射，同时确保目标路由文件返回正确的 JSON 响应格式（使用 `ApiResponse::success()` 或 `ApiResponse::error()`）。
