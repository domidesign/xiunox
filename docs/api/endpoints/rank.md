# 排行榜端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

排行榜端点提供热门帖子和活跃用户的排行信息。所有排行榜端点挂载在 `/api/v1/rank/` 路径下，无需用户认证。

---

## GET /rank

获取排行榜概览，包含热门帖子和活跃用户的简要信息。

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
curl https://your-site.com/api/v1/rank \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "hot_threads": [
      { "tid": 1, "subject": "热门帖子", "views": 1000, "replies": 50 }
    ],
    "active_users": [
      { "uid": 1, "username": "活跃用户", "threads": 30, "posts": 200 }
    ]
  }
}
```

### 错误码

无特定错误码。

---

## GET /rank/threads

获取热门帖子排行榜。

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
| period | string | query | 否 | 时间范围：week/month/all |
| page | int | query | 否 | 页码，默认 1 |
| page_size | int | query | 否 | 每页数量，默认 20 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
# 本周热门帖子
curl "https://your-site.com/api/v1/rank/threads?period=week" \
  -H "X-App-Id: your_app_id"

# 全部时间热门帖子
curl "https://your-site.com/api/v1/rank/threads?period=all" \
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
        "subject": "热门帖子标题",
        "fid": 1,
        "uid": 1,
        "username": "admin",
        "views": 1000,
        "replies": 50,
        "created_at": 1719900000
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

## GET /rank/users

获取活跃用户排行榜。

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
| period | string | query | 否 | 时间范围：week/month/all |
| page | int | query | 否 | 页码，默认 1 |
| page_size | int | query | 否 | 每页数量，默认 20 |
| fields | string | query | 否 | 返回字段过滤（逗号分隔） |

### 请求示例

```bash
# 本周活跃用户
curl "https://your-site.com/api/v1/rank/users?period=week" \
  -H "X-App-Id: your_app_id"

# 本月活跃用户
curl "https://your-site.com/api/v1/rank/users?period=month" \
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
        "uid": 1,
        "username": "admin",
        "threads": 30,
        "posts": 200,
        "credits": 500
      }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 30,
      "total_pages": 2
    }
  }
}
```

### 错误码

无特定错误码。