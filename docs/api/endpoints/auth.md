# 认证端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

认证端点用于用户登录、注册、令牌刷新和退出登录。所有认证端点挂载在 `/api/v1/auth/` 路径下，采用双重密钥认证体系。

---

## POST /auth/login

登录获取令牌。

### HTTP 方法

`POST`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| email | string | body | 是 | 邮箱或用户名 |
| password | string | body | 是 | 密码 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/auth/login \
  -H "X-App-Id: your_app_id" \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@xiuno.com", "password": "123456"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_in": 7200,
    "user": {
      "uid": 1,
      "username": "admin"
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 用户名或密码错误 |
| 422 | 参数验证失败 |

---

## POST /auth/register

注册新用户，注册成功后自动完成登录。

### HTTP 方法

`POST`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| email | string | body | 是 | 邮箱 |
| username | string | body | 是 | 用户名 |
| password | string | body | 是 | 密码 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/auth/register \
  -H "X-App-Id: your_app_id" \
  -H "Content-Type: application/json" \
  -d '{"email": "test@example.com", "username": "testuser", "password": "123456"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "uid": 2,
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_in": 7200
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 409 | 邮箱或用户名已存在 |
| 422 | 参数验证失败 |

---

## POST /auth/refresh

使用刷新令牌获取新的访问令牌。每个 `refresh_token` 只能使用一次，使用后自动轮换。

### HTTP 方法

`POST`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| refresh_token | string | body | 是 | 刷新令牌 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/auth/refresh \
  -H "X-App-Id: your_app_id" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "access_token": "new_eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "refresh_token": "new_eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_in": 7200
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 无效或过期的刷新令牌 |
| 401 | 刷新令牌重用检测（OAuth 2.1 保护，撤销所有令牌） |

---

## POST /auth/logout

退出登录，撤销当前令牌对。

### HTTP 方法

`POST`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readwrite |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| refresh_token | string | body | 是 | 刷新令牌 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/auth/logout \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |