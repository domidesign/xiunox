# 错误码说明

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno BBS API 使用统一的错误响应格式，错误信息包含在 `code`、`msg` 和 `data` 字段中。

## 响应格式

```json
{
  "code": 401,
  "msg": "未授权",
  "data": null
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `code` | int | 错误码，对应 HTTP 状态码或业务错误码 |
| `msg` | string | 错误描述信息 |
| `data` | mixed | 附加数据，通常为 null |

## 错误码分类

### 成功状态码

| code | HTTP 状态码 | 说明 |
|------|-------------|------|
| 0 | 200 / 201 | 请求成功，操作已完成 |

### 客户端错误码 (4xx)

#### 400 Bad Request

| code | 说明 | 常见原因 |
|------|------|----------|
| 400 | 请求参数错误 | 请求格式错误、缺少必需参数 |

#### 401 Unauthorized

| code | 说明 | 常见原因 |
|------|------|----------|
| 401 | 未授权 | 缺少 `Authorization` 头、Token 格式错误 |
| 401 | 用户名或密码错误 | 登录时邮箱/用户名或密码错误 |
| 401 | 无效或过期的刷新令牌 | refresh_token 无效或已过期 |
| 401 | Access Token 已过期 | access_token 超过有效期 |
| 401 | 刷新令牌重用检测 | refresh_token 被二次使用（OAuth 2.1 保护） |
| 401 | 应用鉴权失败 | `X-App-Id` 或 `X-App-Secret` 错误 |
| 401 | Token 强制过期 | Token 超过 90 天强制上限 |

#### 403 Forbidden

| code | 说明 | 常见原因 |
|------|------|----------|
| 403 | 禁止访问 | 访问自己资源以外的受限操作 |
| 403 | 权限不足 | 应用 Scope 等级或 Permission 不满足 |
| 403 | 原密码错误 | 修改密码时原密码验证失败 |
| 403 | IP 不在白名单 | 请求 IP 未在应用白名单内 |

#### 404 Not Found

| code | 说明 | 常见原因 |
|------|------|----------|
| 404 | 用户不存在 | uid 对应的用户未找到 |
| 404 | 帖子不存在 | tid 对应的帖子未找到 |
| 404 | 回复不存在 | pid 对应的回复未找到 |
| 404 | 版块不存在 | fid 对应的版块未找到 |
| 404 | 附件不存在 | aid 对应的附件未找到 |
| 404 | 通知不存在 | id 对应的通知未找到 |
| 404 | 路由不存在 | 请求的 API 路径无效 |

#### 409 Conflict

| code | 说明 | 常见原因 |
|------|------|----------|
| 409 | 邮箱或用户名已存在 | 注册时邮箱或用户名被占用 |
| 409 | 邮箱已存在 | 修改邮箱时目标邮箱已被使用 |
| 409 | 资源冲突 | 并发操作导致的资源状态冲突 |

#### 422 Unprocessable Entity

| code | 说明 | 常见原因 |
|------|------|----------|
| 422 | 参数验证失败 | 必需参数缺失、参数格式错误、参数值不合法 |
| 422 | 文件类型不允许 | 上传的文件格式不在允许列表中 |
| 422 | 参数长度超限 | 参数值长度超过限制 |

#### 429 Too Many Requests

| code | 说明 | 常见原因 |
|------|------|----------|
| 429 | 请求过于频繁 | 触发速率限制规则 |
| 429 | 发送过于频繁 | 验证码等敏感接口的频率限制 |

### 服务端错误码 (5xx)

#### 503 Service Unavailable

| code | 说明 | 常见原因 |
|------|------|----------|
| 503 | API 已关闭 | 管理员在后台关闭了 `api_enabled` 开关 |
| 503 | 服务暂时不可用 | 系统维护或资源不足 |

#### 500 Internal Server Error

| code | 说明 | 常见原因 |
|------|------|----------|
| 500 | 服务器内部错误 | 未知错误、数据库异常、服务异常 |

## 错误码详细说明

### 认证相关错误

#### 401 - 未授权

```json
{
  "code": 401,
  "msg": "未授权",
  "data": null
}
```

**常见触发场景**：
- 调用需要认证的接口时未携带 Token
- `Authorization` 头格式错误（应为 `Bearer <token>`）
- access_token 已过期且未尝试刷新
- refresh_token 已过期（30 天有效期）
- Token 被强制撤销（管理员操作、安全检测）

**客户端处理**：
1. 检查是否携带了 `Authorization` 头
2. 检查 Token 格式是否正确
3. 尝试使用 refresh_token 刷新
4. 刷新失败则跳转到登录页

#### 401 - 刷新令牌重用检测

```json
{
  "code": 401,
  "msg": "Refresh token reuse detected, all tokens revoked",
  "data": null
}
```

**说明**：这是一种安全保护机制。当系统检测到 refresh_token 被二次使用时，会撤销该用户的所有 Token，强制重新登录。

**客户端处理**：
1. 立即清除本地存储的所有 Token
2. 跳转到登录页
3. 提示用户"账号安全异常，请重新登录"

### 权限相关错误

#### 403 - 权限不足

```json
{
  "code": 403,
  "msg": "权限不足",
  "data": null
}
```

**常见触发场景**：
- 应用的 Scope 等级不满足（如 `readonly` 应用尝试写入）
- 应用的 Permissions 矩阵中未启用对应资源
- 用户尝试操作他人资源
- 管理员操作接口但用户非管理员

**客户端处理**：
1. 检查当前应用的权限配置
2. 提示用户"权限不足"并引导联系管理员

### 资源不存在错误

#### 404 - 资源不存在

```json
{
  "code": 404,
  "msg": "资源不存在",
  "data": null
}
```

**常见触发场景**：
- 请求的资源 ID 无效
- 资源已被删除
- 资源对当前用户不可见（可能被标记为删除）

**客户端处理**：
1. 确认请求的资源 ID 是否正确
2. 检查资源是否已被删除
3. 展示"资源不存在"提示

### 参数验证错误

#### 422 - 参数验证失败

```json
{
  "code": 422,
  "msg": "参数验证失败",
  "data": {
    "errors": [
      {
        "field": "email",
        "message": "邮箱格式不正确"
      },
      {
        "field": "password",
        "message": "密码长度至少6位"
      }
    ]
  }
}
```

**常见触发场景**：
- 必需参数未提供
- 参数类型错误（应为 int 传入了 string）
- 参数格式不符合要求（如邮箱格式、手机号格式）
- 参数值不在允许范围内

**客户端处理**：
1. 检查请求参数是否完整
2. 验证参数格式是否正确
3. 展示具体的字段错误信息

### 限流相关错误

#### 429 - 请求过于频繁

```json
{
  "code": 429,
  "msg": "请求过于频繁，请稍后再试",
  "data": null
}
```

**响应头**：

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1725292800
Retry-After: 60
```

| Header | 说明 |
|--------|------|
| `X-RateLimit-Limit` | 当前时间窗口内的最大请求数 |
| `X-RateLimit-Remaining` | 剩余请求次数 |
| `X-RateLimit-Reset` | 限流重置时间（Unix 时间戳） |
| `Retry-After` | 建议等待时间（秒） |

**客户端处理**：
1. 读取 `Retry-After` 头，等待指定时间后重试
2. 读取 `X-RateLimit-Reset` 计算等待时间
3. 实现请求队列或节流机制避免频繁触发

## 错误处理最佳实践

### 客户端统一错误处理

```javascript
class ApiClient {
  async request(url, options = {}) {
    const response = await fetch(url, {
      ...options,
      headers: {
        'X-App-Id': this.appId,
        'Content-Type': 'application/json',
        ...options.headers
      }
    });

    const data = await response.json();

    // 请求成功
    if (data.code === 0) {
      return data.data;
    }

    // 错误处理
    switch (data.code) {
      case 400:
        throw new ValidationError(data.msg, data.data);
      case 401:
        if (data.msg?.includes('reuse detected')) {
          this.forceLogout();
        } else {
          await this.refreshToken();
        }
        break;
      case 403:
        throw new PermissionError(data.msg);
      case 404:
        throw new NotFoundError(data.msg);
      case 422:
        throw new ValidationError(data.msg, data.data);
      case 429:
        await this.waitAndRetry(response.headers.get('Retry-After'));
        break;
      case 503:
        throw new ServiceUnavailableError(data.msg);
      default:
        throw new ApiError(data.code, data.msg);
    }
  }
}
```

### 错误类型定义

```javascript
class ApiError extends Error {
  constructor(code, message, data = null) {
    super(message);
    this.code = code;
    this.data = data;
  }
}

class ValidationError extends ApiError {
  constructor(message, errors = []) {
    super(422, message);
    this.errors = errors;
  }
}

class PermissionError extends ApiError {
  constructor(message) {
    super(403, message);
  }
}

class NotFoundError extends ApiError {
  constructor(message) {
    super(404, message);
  }
}

class ServiceUnavailableError extends ApiError {
  constructor(message) {
    super(503, message);
  }
}
```

### 重试策略

```javascript
async function withRetry(fn, maxRetries = 3, delay = 1000) {
  for (let i = 0; i < maxRetries; i++) {
    try {
      return await fn();
    } catch (error) {
      if (error.code === 429) {
        const waitTime = parseInt(
          error.headers?.get('Retry-After') || delay / 1000
        );
        console.warn(`Rate limited, waiting ${waitTime}s...`);
        await sleep(waitTime * 1000);
      } else if (error.code >= 500 && i < maxRetries - 1) {
        await sleep(delay * Math.pow(2, i));
      } else {
        throw error;
      }
    }
  }
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}
```

## 调试技巧

### 启用详细错误日志

在 `config/api.php` 中设置：

```php
return [
  'api_debug' => true,  // 启用调试模式
  'api_log' => true,    // 启用 API 日志记录
];
```

### 查询错误日志

管理员可在后台查看 API 调用日志：

- 路径：**系统设置** → **日志管理** → **API 日志**
- 记录内容：请求时间、API 路径、请求参数、响应码、响应时间、调用者 IP

### 常见问题排查

| 错误码 | 排查方向 |
|--------|----------|
| 401 | 检查 Token 是否过期、App Id/Secret 是否正确 |
| 403 | 检查应用权限配置、用户角色 |
| 404 | 确认请求的资源 ID 是否存在 |
| 422 | 检查请求参数格式、必填项是否完整 |
| 429 | 检查限流配置、是否短时间内大量请求 |
| 503 | 确认 API 开关是否开启 |
| 500 | 查看服务器日志、检查数据库连接 |