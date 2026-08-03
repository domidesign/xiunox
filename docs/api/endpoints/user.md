# 用户端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

用户端点用于查询和操作用户信息，包括用户列表、用户详情、用户社交关系、AI 配置和头像管理等。所有用户端点挂载在 `/api/v1/user/` 路径下。

---

## GET /user

获取用户列表或批量获取用户。支持通过 `ids` 参数实现批量获取。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Authenticated |
| Scope | readonly |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |
| ids | string | query | 否 | 用户ID列表，逗号分隔（批量获取时必填） |

### 请求示例

```bash
# 列表获取
curl https://your-site.com/api/v1/user \
  -H "X-App-Id: your_app_id"

# 批量获取
curl "https://your-site.com/api/v1/user?ids=1,2,3" \
  -H "X-App-Id: your_app_id"
```

### 响应示例

**列表响应：**

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "uid": 1, "username": "admin" },
      { "uid": 2, "username": "testuser" }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 50,
      "total_pages": 3
    }
  }
}
```

**批量获取响应：**

```json
{
  "code": 0,
  "msg": "ok",
  "data": [
    { "uid": 1, "username": "admin" },
    { "uid": 2, "username": "test" }
  ]
}
```

### 错误码

| code | 说明 |
|------|------|
| 422 | 参数验证失败 |

---

## GET /user/me

获取当前登录用户的完整信息。

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
curl https://your-site.com/api/v1/user/me \
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

## GET /user/me/permissions

获取当前登录用户的权限列表。

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
curl https://your-site.com/api/v1/user/me/permissions \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "permissions": [
      "thread.create",
      "post.create",
      "thread.edit",
      "post.edit"
    ]
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## GET /user/{uid}

获取指定用户的详情。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
curl https://your-site.com/api/v1/user/1 \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "uid": 1,
    "username": "admin",
    "avatar_url": "upload/avatar/1.jpg",
    "signature": "欢迎使用 Xiuno BBS"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 用户不存在 |

---

## PUT /user/{uid}

修改指定用户信息。

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
| uid | int | path | 是 | 用户ID |
| username | string | body | 否 | 用户名 |
| email | string | body | 否 | 邮箱 |
| password | string | body | 否 | 新密码 |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/user/1 \
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
| 403 | 禁止访问 |
| 404 | 用户不存在 |

---

## GET /user/{uid}/threads

获取指定用户的帖子列表。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

### 请求示例

```bash
curl https://your-site.com/api/v1/user/1/threads \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "tid": 1, "subject": "测试帖子", "fid": 1, "created_at": 1719900000 }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 10,
      "total_pages": 1
    }
  }
}
```

### 错误码

无特定错误码。

---

## GET /user/{uid}/posts

获取指定用户的回复列表。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

### 请求示例

```bash
curl https://your-site.com/api/v1/user/1/posts \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "pid": 1, "tid": 1, "message": "回复内容", "created_at": 1719900000 }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 5,
      "total_pages": 1
    }
  }
}
```

### 错误码

无特定错误码。

---

## GET /user/{uid}/favorites

获取指定用户的收藏列表。

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
| uid | int | path | 是 | 用户ID |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

### 请求示例

```bash
curl https://your-site.com/api/v1/user/1/favorites \
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
      { "tid": 1, "subject": "收藏的帖子", "favorited_at": 1719900000 }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 3,
      "total_pages": 1
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

## GET /user/{uid}/following

获取指定用户的关注列表。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
curl https://your-site.com/api/v1/user/1/following \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "uid": 2, "username": "followed_user" }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 5,
      "total_pages": 1
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 用户不存在 |

---

## GET /user/{uid}/followers

获取指定用户的粉丝列表。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
curl https://your-site.com/api/v1/user/1/followers \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "uid": 3, "username": "follower_user" }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 8,
      "total_pages": 1
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 用户不存在 |

---

## GET /user/{uid}/ai-config

获取指定用户的 AI 配置。

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
| uid | int | path | 是 | 用户ID |

### 请求示例

```bash
curl https://your-site.com/api/v1/user/1/ai-config \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "ai_provider": "openai",
    "ai_endpoint": "https://api.openai.com",
    "ai_model": "gpt-4"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

## PUT /user/{uid}/ai-config

更新指定用户的 AI 配置。

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
| uid | int | path | 是 | 用户ID |
| ai_provider | string | body | 否 | AI 提供商 |
| ai_apikey | string | body | 否 | API 密钥 |
| ai_endpoint | string | body | 否 | API 端点 |
| ai_model | string | body | 否 | 模型名称 |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/user/1/ai-config \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"ai_provider": "openai", "ai_model": "gpt-4"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "ai_provider": "openai",
    "ai_model": "gpt-4"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

## POST /user/{uid}/avatar

上传用户头像。

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
| uid | int | path | 是 | 用户ID |
| file | file | body | 是 | 头像文件（multipart/form-data） |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/user/1/avatar \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -F "file=@/path/to/avatar.jpg"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "avatar_url": "upload/avatar/1.jpg"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 文件类型不允许 |

---

## POST /user/{uid}/avatar/preset

选择预设头像。

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
| uid | int | path | 是 | 用户ID |
| avatar_index | int | body | 是 | 预设头像序号 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/user/1/avatar/preset \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"avatar_index": 3}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "avatar_url": "upload/avatar/preset_3.png"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

## GET /user/{uid}/avatar/presets

获取预设头像列表。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |

### 请求示例

```bash
curl https://your-site.com/api/v1/user/1/avatar/presets \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "presets": [
      { "index": 1, "url": "upload/avatar/preset_1.png" },
      { "index": 2, "url": "upload/avatar/preset_2.png" },
      { "index": 3, "url": "upload/avatar/preset_3.png" }
    ]
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 用户不存在 |

---

## POST /user/{uid}/follow

关注指定用户。

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
| uid | int | path | 是 | 目标用户ID |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/user/2/follow \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "following": true
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 404 | 用户不存在 |

---

## DELETE /user/{uid}/follow

取消关注指定用户。

### HTTP 方法

`DELETE`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readwrite |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 目标用户ID |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/user/2/follow \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "following": false
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |