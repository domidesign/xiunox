# 认证机制说明

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno BBS API 采用双重密钥模式认证体系，将"应用身份"与"用户身份"解耦，支持三种调用模式：

| 模式 | 认证方式 | 适用场景 |
|------|----------|----------|
| 公开访问 | 无需认证 | 获取公开资源（如帖子列表、站点信息） |
| 客户端模式 | `X-App-Id` + `Bearer Token` | 移动端、前端应用调用 |
| 服务端模式 | `X-App-Id` + `X-App-Secret` + `Bearer Token` | 第三方服务、服务端对接 |

## 双密钥机制

### 应用密钥

每个 API 应用拥有独立的 `App Id` 和 `App Secret`，用于应用级身份认证。

| 密钥 | 说明 | 传递方式 |
|------|------|----------|
| `App Id` | 应用标识，公开可传递 | HTTP Header: `X-App-Id` |
| `App Secret` | 应用密钥，仅服务端持有 | HTTP Header: `X-App-Secret` |

**请求头示例**：

```
X-App-Id: your_app_id
X-App-Secret: your_app_secret
```

### 密钥管理

密钥在管理后台 → **系统设置** → **API 设置** → **应用管理** 中管理：

- **创建应用**：生成 `appid` 和 `secret`，配置权限 Scope 和 IP 白名单
- **重置密钥**：旧密钥立即失效，所有使用旧密钥的请求将被拒绝
- **删除应用**：撤销该应用的所有权限和 Token

### 权限配置

每个应用可配置细粒度权限：

| 配置项 | 说明 |
|--------|------|
| **Scope 等级** | `readonly`（只读）、`readwrite`（增改删）、`full`（全部） |
| **Permissions 矩阵** | 每个资源单独设置 `r`（只读）/ `rw`（读写）/ `-`（禁止） |
| **IP 白名单** | 支持 CIDR 格式，限制特定 IP 段访问 |
| **速率限制** | 单应用独立限流，或启用 `skip_rate_limit` 能力绕过 |

## 用户认证 Token 流程

### Token 类型

系统支持双令牌机制：

| Token | 有效期 | 用途 |
|-------|--------|------|
| `access_token` | 2 小时（可配置） | 用于 API 调用的短期凭证 |
| `refresh_token` | 30 天（可配置） | 用于获取新的 access_token |

### 认证流程

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. 用户登录                                                      │
│    POST /api/v1/auth/login                                       │
│    Body: { "email": "user@example.com", "password": "***" }      │
│    ↓                                                             │
│    Response: {                                                   │
│      "code": 0,                                                  │
│      "data": {                                                   │
│        "access_token": "abc...",                                 │
│        "refresh_token": "def...",                               │
│        "expires_in": 7200                                        │
│      }                                                           │
│    }                                                             │
│    ↓                                                             │
│ 2. 存储 Token                                                    │
│    - access_token 存储在客户端内存或 sessionStorage               │
│    - refresh_token 存储在安全位置（建议 HttpOnly Cookie）         │
│    ↓                                                             │
│ 3. 调用 API                                                      │
│    GET /api/v1/thread                                            │
│    Header: Authorization: Bearer <access_token>                  │
│    ↓                                                             │
│ 4. Token 过期处理                                                │
│    - access_token 过期后，使用 refresh_token 获取新 Token         │
│    - refresh_token 过期后，需要用户重新登录                       │
│    ↓                                                             │
│ 5. 刷新 Token                                                    │
│    POST /api/v1/auth/refresh                                     │
│    Body: { "refresh_token": "def..." }                           │
│    Response: {                                                   │
│      "code": 0,                                                  │
│      "data": {                                                   │
│        "access_token": "new_abc...",                             │
│        "refresh_token": "new_def...",                            │
│        "expires_in": 7200                                        │
│      }                                                           │
│    }                                                             │
└─────────────────────────────────────────────────────────────────┘
```

### 请求示例

**cURL 登录示例**：

```bash
# 登录获取 Token
curl -X POST https://your-site.com/api/v1/auth/login \
  -H "X-App-Id: your_app_id" \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com", "password": "123456"}'

# 响应
{
  "code": 0,
  "msg": "ok",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_in": 7200
  }
}

# 使用 access_token 访问受保护资源
curl https://your-site.com/api/v1/thread/123 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."

# 刷新 Token
curl -X POST https://your-site.com/api/v1/auth/refresh \
  -H "X-App-Id: your_app_id" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."}'
```

### 认证头格式

```
Authorization: Bearer <access_token>
```

| 部分 | 说明 |
|------|------|
| `Bearer` | 认证方案标识符（固定为 Bearer） |
| `<access_token>` | 登录获取的 access_token 值 |

## OAuth 2.1 严格重用检测

为防止刷新令牌被盗用，系统实现了 OAuth 2.1 标准的严格重用检测机制：

### 检测规则

1. **单次使用**：每个 `refresh_token` 只能使用一次
2. **自动轮换**：使用 `refresh_token` 刷新后，系统会返回新的 `refresh_token`
3. **重用检测**：如果旧 `refresh_token` 被再次使用，系统将触发安全事件

### 重用检测响应

当检测到 `refresh_token` 重用时：

```json
{
  "code": 401,
  "msg": "Refresh token reuse detected, all tokens revoked",
  "data": null
}
```

### 安全处理

检测到重用后，系统将：

1. **立即撤销**：撤销该用户的所有 access_token 和 refresh_token
2. **强制登出**：用户需要重新登录获取新的 Token 对
3. **安全审计**：记录重用事件到安全日志

### 客户端处理

```javascript
async function apiRequest(url, options = {}) {
  let response = await fetch(url, {
    ...options,
    headers: {
      'X-App-Id': appId,
      'Authorization': `Bearer ${accessToken}`,
      ...options.headers
    }
  });

  if (response.status === 401) {
    const data = await response.json();
    
    // 刷新令牌被重用，强制重新登录
    if (data.msg?.includes('reuse detected')) {
      logout();
      return;
    }
    
    // access_token 过期，尝试刷新
    if (data.msg?.includes('expired')) {
      response = await refreshAndRetry(url, options);
    }
  }

  return response.json();
}

async function refreshAndRetry(url, options) {
  const refreshResponse = await fetch('/api/v1/auth/refresh', {
    method: 'POST',
    headers: {
      'X-App-Id': appId,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ refresh_token: refreshToken })
  });

  if (refreshResponse.ok) {
    const { data } = await refreshResponse.json();
    updateTokens(data.access_token, data.refresh_token);
    
    return fetch(url, {
      ...options,
      headers: {
        'X-App-Id': appId,
        'Authorization': `Bearer ${data.access_token}`,
        ...options.headers
      }
    });
  }
  
  // 刷新失败，强制重新登录
  logout();
}
```

## 注册流程

```
POST /api/v1/auth/register
Body: {
  "email": "user@example.com",
  "username": "newuser",
  "password": "secure_password"
}

Response: {
  "code": 0,
  "msg": "ok",
  "data": {
    "uid": 2,
    "access_token": "abc...",
    "refresh_token": "def...",
    "expires_in": 7200
  }
}
```

注册成功后自动完成登录，返回 Token 对。

## 退出登录

```
POST /api/v1/auth/logout
Header: Authorization: Bearer <access_token>
Body: { "refresh_token": "def..." }

Response: {
  "code": 0,
  "msg": "ok",
  "data": null
}
```

退出后，当前 Token 对将被撤销。

## 安全建议

### 密钥保护

- **`App Secret`** 仅在服务端使用，禁止暴露到客户端代码
- 密钥泄露后立即在后台重置，旧密钥立即失效
- 生产环境为不同服务创建独立应用，隔离权限

### Token 安全

- `access_token` 存储在内存或 sessionStorage，不要写入 localStorage
- `refresh_token` 使用 HttpOnly Cookie 存储，防止 XSS 攻击
- 不要在 URL 参数中传递 Token
- Token 过期后及时刷新，避免用户体验中断

### HTTPS 强制

所有 API 请求必须使用 HTTPS 协议，防止 Token 在传输过程中被截获。

## 配置项

在管理后台 → **系统设置** → **API 设置** 中可配置：

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `api_token_expire` | Refresh Token 有效期（天） | 30 |
| `api_access_token_expire` | Access Token 有效期（小时） | 2 |
| `api_token_absolute_expire` | Token 强制上限（天） | 90 |

**注意**：`api_token_absolute_expire` 为 90 天强制上限，到期需用户重新登录。