# Xiuno BBS API 文档

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno BBS 提供了一套完整的 RESTful API 系统，所有接口统一挂载在 `/api/v1/` 路径下。API 架构采用分层中间件设计，请求需依次穿越 CORS 处理、全局开关检查、应用级鉴权、IP 白名单、Scope 权限校验、资源白名单以及速率限制等多个安全层，确保接口安全可控。

## 文档结构

| 文件 | 说明 |
|------|------|
| [authentication.md](authentication.md) | 认证机制说明 — 双密钥模式、Token 流程 |
| [errors.md](errors.md) | 错误码说明 — 所有错误码及含义 |
| [rate-limiting.md](rate-limiting.md) | 限流机制说明 — 限流规则与配置 |
| [endpoints/](endpoints/) | API 端点详细文档（待完善） |

## API 基础信息

### 基础路径

```
/api/v1/
```

### 响应格式

所有接口统一返回 JSON 格式：

```json
{
  "code": 0,
  "msg": "ok",
  "data": { ... }
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | int | 状态码，0 表示成功，非 0 表示错误 |
| `msg` | string | 响应消息 |
| `data` | mixed | 响应数据，可能为对象、数组或 null |

### HTTP 状态码

| 状态码 | 含义 |
|--------|------|
| 200 | 请求成功 |
| 201 | 资源创建成功 |
| 204 | 删除成功（无返回内容） |
| 400 | 请求参数错误 |
| 401 | 未授权 / 认证失败 |
| 403 | 权限不足 |
| 404 | 资源不存在 |
| 409 | 资源冲突（如重复创建） |
| 422 | 参数验证失败 |
| 429 | 请求过于频繁（限流） |
| 503 | API 已关闭 |

### 通用请求参数

大多数列表接口支持以下分页参数：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `page` | int | 否 | 页码，默认 1 |
| `pagesize` | int | 否 | 每页数量，默认 20 |
| `fields` | string | 否 | 返回字段过滤（逗号分隔） |

### 通用响应结构

列表接口响应结构：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [...],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 100,
      "total_pages": 5
    }
  }
}
```

### 中间件流程

请求到达后依次经过以下中间件层：

```
CORS 处理 → 全局开关检查 → 应用鉴权 → IP 白名单 → Scope 权限校验 → 资源白名单 → 限流检查 → 路由分发
```

各层失败时返回对应 HTTP 状态码与错误信息，成功后进入路由分发阶段。

### 核心服务类

| 服务类 | 路径 | 职责 |
|--------|------|------|
| `ApiAuthService` | `lib/ApiAuthService.php` | 应用鉴权、Token 生命周期管理、Scope 校验 |
| `ApiResponse` | `lib/ApiResponse.php` | 统一响应输出、安全输入过滤 |
| `RateLimitService` | `lib/RateLimitService.php` | 速率限制（Redis/文件双驱动） |
| `PluginApiRegistry` | `lib/PluginApiRegistry.php` | 插件 API 路由注册与解析 |
| `ApiDocService` | `lib/ApiDocService.php` | API 文档生成服务 |

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

### RESTful 约定

| 方法 | 路径模式 | 说明 |
|------|----------|------|
| GET | `/{resource}` | 列表查询（支持分页参数 `page`/`pagesize`） |
| GET | `/{resource}/{id}` | 详情查询 |
| POST | `/{resource}` | 创建资源 |
| PUT | `/{resource}/{id}` | 更新资源 |
| DELETE | `/{resource}/{id}` | 删除资源 |

### Scope 权限等级

| Scope | 说明 |
|-------|------|
| `public` | 公开访问 |
| `readonly` | 只读权限 |
| `readwrite` | 读写权限 |
| `full` | 全部权限 |

### 代码示例

**cURL 调用示例**：

```bash
# 公开接口（无需认证）
curl https://your-site.com/api/v1/site

# 服务端应用调用
curl https://your-site.com/api/v1/thread \
  -H "X-App-Id: your_app_id" \
  -H "X-App-Secret: your_app_secret"

# 用户认证调用
curl https://your-site.com/api/v1/thread \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer your_access_token"
```

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