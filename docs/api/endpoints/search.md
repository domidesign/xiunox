# 搜索端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

搜索端点提供全文搜索功能，支持对帖子、回复和用户进行关键词搜索。所有搜索端点挂载在 `/api/v1/search/` 路径下。

---

## GET /search

全文搜索。支持按类型筛选搜索范围。

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
| q | string | query | 是 | 搜索关键词（至少2字符） |
| type | string | query | 否 | 搜索类型：thread/post/user |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

### 请求示例

```bash
# 搜索帖子
curl "https://your-site.com/api/v1/search?q=关键词&type=thread" \
  -H "X-App-Id: your_app_id"

# 搜索所有类型
curl "https://your-site.com/api/v1/search?q=关键词" \
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
        "type": "thread",
        "tid": 1,
        "subject": "包含关键词的帖子",
        "highlight": "包含<span class=\"highlight\">关键词</span>的帖子",
        "fid": 1,
        "uid": 1,
        "username": "admin",
        "created_at": 1719900000
      }
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

| code | 说明 |
|------|------|
| 422 | 参数验证失败（关键词过短等） |