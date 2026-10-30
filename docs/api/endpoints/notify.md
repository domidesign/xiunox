# 通知端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

通知端点用于查询和管理用户通知，包括通知列表、未读数、标记已读、全部标记已读和删除通知。所有通知端点挂载在 `/api/v1/notify/` 路径下，需要用户认证。

---

## GET /notify

获取当前用户的通知列表。

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
| type | string | query | 否 | 通知类型 |

### 请求示例

```bash
curl https://your-site.com/api/v1/notify \
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
| 401 | 未授权 |

---

## GET /notify/unread

获取当前用户的未读通知数量。

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
curl https://your-site.com/api/v1/notify/unread \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "count": 3
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## PUT /notify/{id}/read

标记指定通知为已读。

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
| id | int | path | 是 | 通知ID |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/notify/1/read \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
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

---

## PUT /notify/read-all

将所有通知标记为已读。

### HTTP 方法

`PUT`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Authenticated |
| Scope | readwrite |

### 请求参数

无

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/notify/read-all \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
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

---

## DELETE /notify/{id}

删除指定通知。

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
| id | int | path | 是 | 通知ID |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/notify/1 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
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
| 404 | 通知不存在 |