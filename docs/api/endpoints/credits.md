# 积分端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

积分端点用于查询和操作用户积分，包括查询余额、积分日志、增加积分和扣减积分。所有积分端点挂载在 `/api/v1/credits/` 路径下，需要用户认证。

---

## GET /credits

查询当前用户的积分余额。

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
| type | string | query | 否 | 积分类型 |

### 请求示例

```bash
curl https://your-site.com/api/v1/credits \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "credits": 100,
    "type": "default"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |

---

## GET /credits/log

查询当前用户的积分日志。

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
| type | string | query | 否 | 积分类型 |

### 请求示例

```bash
curl https://your-site.com/api/v1/credits/log \
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
      { "id": 1, "type": "default", "amount": 10, "balance": 100, "reason": "签到奖励", "created_at": 1719900000 }
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
| 401 | 未授权 |

---

## POST /credits/add

增加积分。可指定为其他用户增加积分（管理员功能）。

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
| uid | int | body | 否 | 用户ID（默认当前用户） |
| type | string | body | 否 | 积分类型 |
| amount | int | body | 是 | 增加数量 |
| reason | string | body | 是 | 增加原因 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/credits/add \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"amount": 10, "reason": "签到奖励"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "credits": 110
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 422 | 参数验证失败 |

---

## POST /credits/sub

扣减积分。可指定为其他用户扣减积分（管理员功能）。

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
| uid | int | body | 否 | 用户ID（默认当前用户） |
| type | string | body | 否 | 积分类型 |
| amount | int | body | 是 | 扣减数量 |
| reason | string | body | 是 | 扣减原因 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/credits/sub \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"amount": 5, "reason": "下载附件"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "credits": 105
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 422 | 参数验证失败 |
| 403 | 积分不足 |