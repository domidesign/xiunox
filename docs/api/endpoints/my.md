# 个人端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

个人端点用于管理当前登录用户的个人信息和设置，包括资料、密码、邮箱、安全、积分规则、积分检查、点赞列表、动态流、关注用户和通知下拉等。所有个人端点挂载在 `/api/v1/my/` 路径下，需要用户认证。

---

## GET /my/profile

获取当前登录用户的完整资料。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

无

### 请求示例

```bash
curl https://your-site.com/api/v1/my/profile \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "uid": 1,
    "username": "admin",
    "email": "admin@xiuno.com",
    "avatar_url": "upload/avatar/1.jpg",
    "signature": "欢迎使用 Xiuno BBS",
    "threads": 10,
    "posts": 50
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## PUT /my/profile

更新当前登录用户的资料。

### HTTP 方法

`PUT`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readwrite |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| username | string | body | 否 | 用户名 |
| signature | string | body | 否 | 个性签名 |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/my/profile \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"username": "newname"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "uid": 1,
    "username": "newname"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 422 | 参数验证失败 |

---

## PUT /my/password

修改当前登录用户的密码。

### HTTP 方法

`PUT`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readwrite |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| old_password | string | body | 是 | 原密码 |
| new_password | string | body | 是 | 新密码 |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/my/password \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"old_password": "***", "new_password": "***"}'
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
| 403 | 原密码错误 |
| 422 | 参数验证失败 |

---

## GET /my/email

获取当前登录用户的邮箱信息。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

无

### 请求示例

```bash
curl https://your-site.com/api/v1/my/email \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "email": "admin@xiuno.com",
    "verified": true
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## PUT /my/email

修改当前登录用户的邮箱。需要先发送验证码。

### HTTP 方法

`PUT`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readwrite |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| email | string | body | 是 | 新邮箱 |
| code | string | body | 是 | 验证码 |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/my/email \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"email": "new@example.com", "code": "123456"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "email": "new@example.com"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 409 | 邮箱已存在 |
| 422 | 参数验证失败 |

---

## POST /my/email/send-code

发送邮箱验证码到指定邮箱。

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
| email | string | body | 是 | 目标邮箱 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/my/email/send-code \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"email": "new@example.com"}'
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
| 422 | 参数验证失败 |
| 429 | 发送过于频繁 |

---

## GET /my/security

获取当前账号的安全信息，包括邮箱验证状态、双因子认证状态、最近登录时间等。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

无

### 请求示例

```bash
curl https://your-site.com/api/v1/my/security \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "email_verified": true,
    "two_factor": false,
    "last_login": 1719900000
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## GET /my/credits/rules

获取积分规则说明。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

无

### 请求示例

```bash
curl https://your-site.com/api/v1/my/credits/rules \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "rules": [
      { "action": "thread.create", "credits": 5, "desc": "发布帖子" },
      { "action": "post.create", "credits": 2, "desc": "发布回复" },
      { "action": "thread.delete", "credits": -10, "desc": "被删帖子" }
    ]
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## POST /my/credits/check

检查积分是否足够扣除。

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
| type | string | body | 否 | 积分类型 |
| amount | int | body | 是 | 数量 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/my/credits/check \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"amount": 5}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "sufficient": true,
    "balance": 100
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 422 | 参数验证失败 |

---

## GET /my/likes

获取当前用户的点赞列表。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

### 请求示例

```bash
curl https://your-site.com/api/v1/my/likes \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "type": "thread", "id": 1, "subject": "被点赞的帖子", "created_at": 1719900000 }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 0,
      "total_pages": 0
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## GET /my/feed

获取当前用户的动态流（时间线）。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

### 请求示例

```bash
curl https://your-site.com/api/v1/my/feed \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "type": "thread", "id": 1, "subject": "最新动态", "created_at": 1719900000 }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 0,
      "total_pages": 0
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## GET /my/follow-users

获取当前用户关注的用户列表。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

### 请求示例

```bash
curl https://your-site.com/api/v1/my/follow-users \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "uid": 2, "username": "followed_user", "avatar_url": "upload/avatar/2.jpg" }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 0,
      "total_pages": 0
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## GET /my/notify/dropdown

获取通知下拉列表，用于前端通知铃铛组件。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

无

### 请求示例

```bash
curl https://your-site.com/api/v1/my/notify/dropdown \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "id": 1, "type": "reply", "content": "有人回复了你的帖子", "read": false, "created_at": 1719900000 }
    ],
    "unread": 3
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |