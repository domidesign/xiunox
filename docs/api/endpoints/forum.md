# 版块端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

版块端点用于查询和操作论坛版块信息，包括版块列表、版块详情、版块帖子列表、树形结构、批量获取以及关注/取消关注版块。所有版块端点挂载在 `/api/v1/forum/` 路径下。

---

## GET /forum

获取版块列表或批量获取版块。支持通过 `ids` 参数实现批量获取。

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
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |
| ids | string | query | 否 | 版块ID列表，逗号分隔（批量获取时必填） |

### 请求示例

```bash
# 列表获取
curl https://your-site.com/api/v1/forum \
  -H "X-App-Id: your_app_id"

# 批量获取
curl "https://your-site.com/api/v1/forum?ids=1,2,3" \
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
      { "fid": 1, "name": "默认版块" },
      { "fid": 2, "name": "技术版块" }
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

**批量获取响应：**

```json
{
  "code": 0,
  "msg": "ok",
  "data": [
    { "fid": 1, "name": "默认版块" },
    { "fid": 2, "name": "技术版块" }
  ]
}
```

### 错误码

| code | 说明 |
|------|------|
| 422 | 参数验证失败 |

---

## GET /forum/{fid}

获取指定版块的详情。

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
| fid | int | path | 是 | 版块ID |

### 请求示例

```bash
curl https://your-site.com/api/v1/forum/1 \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "fid": 1,
    "name": "默认版块",
    "description": "默认版块描述",
    "threads": 100,
    "posts": 500
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 版块不存在 |

---

## GET /forum/{fid}/threads

获取指定版块的帖子列表。

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
| fid | int | path | 是 | 版块ID |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |
| orderby | string | query | 否 | 排序字段 |
| keyword | string | query | 否 | 关键词搜索 |

### 请求示例

```bash
curl https://your-site.com/api/v1/forum/1/threads \
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
      "total": 50,
      "total_pages": 3
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 版块不存在 |

---

## GET /forum/tree

获取版块的树形结构，包含父子版块关系。

### HTTP 方法

`GET`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

无

### 请求示例

```bash
curl https://your-site.com/api/v1/forum/tree \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": [
    {
      "fid": 1,
      "name": "默认版块",
      "children": [
        { "fid": 3, "name": "子版块", "children": [] }
      ]
    }
  ]
}
```

### 错误码

无特定错误码。

---

## POST /forum/follow

关注指定版块。

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
| fid | int | body | 是 | 版块ID |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/forum/follow \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"fid": 1}'
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
| 404 | 版块不存在 |

---

## POST /forum/unfollow

取消关注指定版块。

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
| fid | int | body | 是 | 版块ID |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/forum/unfollow \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"fid": 1}'
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