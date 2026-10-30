# 帖子端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

帖子端点用于查询和操作论坛帖子，包括帖子列表、帖子详情、创建帖子、更新帖子、删除帖子、点赞、收藏、举报、批量操作和公告管理等。所有帖子端点挂载在 `/api/v1/thread/` 路径下。

---

## GET /thread

获取帖子列表。支持分页、筛选（按版块、用户）、关键词搜索和排序。

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
| fid | int | query | 否 | 版块ID筛选 |
| uid | int | query | 否 | 用户ID筛选 |
| keyword | string | query | 否 | 关键词搜索 |
| orderby | string | query | 否 | 排序字段 |
| order | int | query | 否 | 排序方向 -1降序 1升序 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
# 列表获取
curl https://your-site.com/api/v1/thread \
  -H "X-App-Id: your_app_id"

# 按版块筛选
curl "https://your-site.com/api/v1/thread?fid=1&page=1" \
  -H "X-App-Id: your_app_id"

# 关键词搜索
curl "https://your-site.com/api/v1/thread?keyword=测试&orderby=created_at&order=-1" \
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
        "tid": 1,
        "fid": 1,
        "uid": 1,
        "subject": "测试帖子",
        "created_at": 1719900000,
        "last_modified": 1719900000,
        "views": 100,
        "replies": 5
      }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 100,
      "total_pages": 5
    }
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 422 | 参数验证失败 |

---

## GET /thread?ids=

批量获取指定帖子。通过 `ids` 参数传入帖子ID列表。

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
| ids | string | query | 是 | 帖子ID列表，逗号分隔 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
curl "https://your-site.com/api/v1/thread?ids=1,2,3" \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": [
    { "tid": 1, "subject": "帖子1", "fid": 1 },
    { "tid": 2, "subject": "帖子2", "fid": 1 },
    { "tid": 3, "subject": "帖子3", "fid": 2 }
  ]
}
```

### 错误码

| code | 说明 |
|------|------|
| 422 | 参数验证失败 |

---

## GET /thread/hot

获取近期热门帖子。

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
| days | int | query | 否 | 天数范围，默认 7 |
| pagesize | int | query | 否 | 每页数量，默认 10 |

### 请求示例

```bash
curl "https://your-site.com/api/v1/thread/hot?days=7&pagesize=10" \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "tid": 1, "subject": "热门帖子1", "heat": 98 },
      { "tid": 5, "subject": "热门帖子2", "heat": 85 }
    ]
  }
}
```

### 错误码

无特定错误码。

---

## GET /thread/{tid}

获取指定帖子的详情。

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
| tid | int | path | 是 | 帖子ID |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
curl https://your-site.com/api/v1/thread/1 \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "tid": 1,
    "fid": 1,
    "uid": 1,
    "subject": "测试帖子",
    "message": "<p>帖子内容</p>",
    "created_at": 1719900000,
    "last_modified": 1719900000,
    "views": 100,
    "replies": 5,
    "likes": 10,
    "favorites": 3,
    "top": 0,
    "close": 0
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 帖子不存在 |

---

## POST /thread

创建新帖子。

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
| subject | string | body | 是 | 帖子标题 |
| message | string | body | 是 | 帖子内容（HTML 或 Markdown） |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/thread \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"fid": 1, "subject": "新帖子标题", "message": "<p>帖子内容</p>"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "Created",
  "data": {
    "tid": 10
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 404 | 版块不存在 |
| 422 | 参数验证失败 |

---

## PUT /thread/{tid}

更新指定帖子。

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
| tid | int | path | 是 | 帖子ID |
| subject | string | body | 否 | 新标题 |
| message | string | body | 否 | 新内容 |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/thread/1 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"subject": "新标题"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "tid": 1,
    "subject": "新标题"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 帖子不存在 |

---

## DELETE /thread/{tid}

删除指定帖子。

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
| tid | int | path | 是 | 帖子ID |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/thread/1 \
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
| 404 | 帖子不存在 |

---

## POST /thread/{tid}/like

点赞指定帖子。

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
| tid | int | path | 是 | 帖子ID |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/thread/1/like \
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
| 404 | 帖子不存在 |

---

## DELETE /thread/{tid}/like

取消点赞指定帖子。

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
| tid | int | path | 是 | 帖子ID |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/thread/1/like \
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

---

## POST /thread/{tid}/favorite

收藏指定帖子。

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
| tid | int | path | 是 | 帖子ID |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/thread/1/favorite \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "favorited": true
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 404 | 帖子不存在 |

---

## DELETE /thread/{tid}/favorite

取消收藏指定帖子。

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
| tid | int | path | 是 | 帖子ID |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/thread/1/favorite \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "favorited": false
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## POST /thread/{tid}/report

举报指定帖子。

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
| tid | int | path | 是 | 帖子ID |
| reason | string | body | 是 | 举报原因 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/thread/1/report \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"reason": "违规内容"}'
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
| 404 | 帖子不存在 |
| 422 | 参数验证失败 |

---

## DELETE /thread/batch

批量删除帖子（管理员权限）。

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
| tids | array | body | 是 | 帖子ID数组 |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/thread/batch \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"tids": [1, 2, 3]}'
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

## PUT /thread/batch

批量更新帖子（管理员权限）。可批量设置置顶、关闭或类型等属性。

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
| tids | array | body | 是 | 帖子ID数组 |
| update | object | body | 是 | 更新内容（top/closed/type 等字段） |

### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/thread/batch \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"tids": [1, 2], "update": {"top": 1}}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "updated": 2
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

## POST /thread/{tid}/announcement

设置或取消帖子公告。

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
| tid | int | path | 是 | 帖子ID |
| is_announcement | int | body | 是 | 0=取消公告, 1=设置公告 |
| announcement_order | int | body | 否 | 排序权重，数值越小越靠前 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/thread/1/announcement \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"is_announcement": 1, "announcement_order": 0}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "tid": 1,
    "is_announcement": 1
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 帖子不存在 |