# 回复端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

回复端点用于查询和操作帖子下的回复，包括回复列表、回复详情、创建回复、修改回复、删除回复、批量删除以及点赞功能。所有回复端点挂载在 `/api/v1/post/` 路径下。

---

## GET /post

获取回复列表。支持按帖子、用户筛选以及分页。

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
| tid | int | query | 否 | 帖子ID筛选 |
| uid | int | query | 否 | 用户ID筛选 |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
# 列表获取
curl https://your-site.com/api/v1/post \
  -H "X-App-Id: your_app_id"

# 按帖子筛选
curl "https://your-site.com/api/v1/post?tid=1&page=1" \
  -H "X-App-Id: your_app_id"

# 按用户筛选
curl "https://your-site.com/api/v1/post?uid=2&pagesize=10" \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      {
        "pid": 1,
        "tid": 1,
        "uid": 2,
        "message": "<p>这是一条回复</p>",
        "created_at": 1719900000,
        "likes": 3
      }
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

### 错误码

无特定错误码。

---

## GET /post/{pid}

获取指定回复的详情。

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
| pid | int | path | 是 | 回复ID |

### 请求示例

```bash
curl https://your-site.com/api/v1/post/1 \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "pid": 1,
    "tid": 1,
    "uid": 2,
    "message": "<p>这是一条回复</p>",
    "created_at": 1719900000,
    "last_modified": 1719900000,
    "likes": 3,
    "author": {
      "uid": 2,
      "username": "replyuser"
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 回复不存在 |

---

## POST /post

创建新回复。

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
| tid | int | body | 是 | 帖子ID |
| message | string | body | 是 | 回复内容（HTML 或 Markdown） |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/post \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"tid": 1, "message": "<p>这是一条新回复</p>"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "Created",
  "data": {
    "pid": 10
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 404 | 帖子不存在 |
| 422 | 参数验证失败 |

---

## PUT /post/{pid}

修改指定回复。

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
| pid | int | path | 是 | 回复ID |
| message | string | body | 否 | 新内容 |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/post/1 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"message": "<p>修改后的回复内容</p>"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "pid": 1,
    "message": "<p>修改后的回复内容</p>"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 回复不存在 |

---

## DELETE /post/{pid}

删除指定回复。

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
| pid | int | path | 是 | 回复ID |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/post/1 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "Deleted",
  "data": null
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 回复不存在 |

---

## DELETE /post/batch

批量删除回复（管理员权限）。

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
| pids | array | body | 是 | 回复ID数组 |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/post/batch \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"pids": [1, 2, 3]}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "deleted": 3
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

## POST /post/{pid}/like

点赞指定回复。

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
| pid | int | path | 是 | 回复ID |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/post/1/like \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "liked": true
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 404 | 回复不存在 |

---

## DELETE /post/{pid}/like

取消点赞指定回复。

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
| pid | int | path | 是 | 回复ID |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/post/1/like \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "liked": false
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |