# 管理员端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

管理员端点用于站点管理和运营，包括安全配置、验证码、内容审核、敏感词、日志、用户管理、版块管理和封禁管理等。所有管理员端点挂载在 `/api/v1/admin/` 路径下，需要管理员权限（`admin` scope）。

---

## 安全配置

### GET /admin/security

获取当前安全配置。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

无

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/security \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "security_captcha_login": true,
    "security_captcha_register": true,
    "security_login_attempts": 5,
    "security_password_min_length": 6
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### PUT /admin/security

更新安全配置。

#### HTTP 方法

`PUT`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| security_captcha_login | int | body | 否 | 登录验证码开关 |
| security_captcha_register | int | body | 否 | 注册验证码开关 |
| security_login_attempts | int | body | 否 | 登录失败锁定次数 |

#### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/admin/security \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"security_captcha_login": 1, "security_login_attempts": 5}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

## 验证码配置

### GET /admin/security/captcha

获取验证码配置。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

无

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/security/captcha \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "captcha_type": "image",
    "captcha_length": 4,
    "captcha_expire": 300
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### PUT /admin/security/captcha

更新验证码配置。

#### HTTP 方法

`PUT`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| captcha_type | string | body | 否 | 验证码类型 |
| captcha_length | int | body | 否 | 验证码长度 |
| captcha_expire | int | body | 否 | 验证码过期时间（秒） |

#### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/admin/security/captcha \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"captcha_type": "image", "captcha_length": 4}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

## 内容审核

### GET /admin/audit/pending

获取待审核内容列表。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| type | string | query | 否 | 类型：thread/post/profile |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/audit/pending \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "id": 1, "type": "thread", "title": "待审帖子", "created_at": 1719900000 }
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

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### POST /admin/audit/approve

审核通过指定内容。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| target_type | string | body | 是 | 目标类型：thread/post/profile |
| target_id | int | body | 是 | 目标ID |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/audit/approve \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"target_type": "thread", "target_id": 1}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 目标不存在 |

---

### POST /admin/audit/reject

审核驳回指定内容。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| target_type | string | body | 是 | 目标类型：thread/post/profile |
| target_id | int | body | 是 | 目标ID |
| reason | string | body | 否 | 驳回原因 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/audit/reject \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"target_type": "thread", "target_id": 1, "reason": "内容违规"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 目标不存在 |

---

### POST /admin/audit/batch-approve

批量审核通过。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| target_type | string | body | 是 | 目标类型：thread/post/profile |
| ids | array | body | 是 | 目标ID数组 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/audit/batch-approve \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"target_type": "thread", "ids": [1, 2, 3]}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "approved": 3
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

### POST /admin/audit/batch-reject

批量审核驳回。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| target_type | string | body | 是 | 目标类型：thread/post/profile |
| ids | array | body | 是 | 目标ID数组 |
| reason | string | body | 否 | 驳回原因 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/audit/batch-reject \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"target_type": "thread", "ids": [1, 2], "reason": "内容违规"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "rejected": 2
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

## 敏感词管理

### GET /admin/sensitive-words

获取敏感词列表。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

无

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/sensitive-words \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "words": ["违规词1", "违规词2"]
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### POST /admin/sensitive-words

添加单个敏感词。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| word | string | body | 是 | 敏感词 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/sensitive-words \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"word": "违规词"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

### DELETE /admin/sensitive-words

清空所有敏感词。

#### HTTP 方法

`DELETE`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

无

#### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/admin/sensitive-words \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### POST /admin/sensitive-words/import

批量导入敏感词。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| words | array | body | 是 | 敏感词数组 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/sensitive-words/import \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"words": ["词1", "词2", "词3"]}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "imported": 3
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

### DELETE /admin/sensitive-words/{word}

删除指定敏感词。

#### HTTP 方法

`DELETE`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| word | string | path | 是 | 敏感词 |

#### 请求示例

```bash
curl -X DELETE "https://your-site.com/api/v1/admin/sensitive-words/违规词" \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 敏感词不存在 |

---

## 日志

### GET /admin/log/credits

获取积分日志。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | query | 否 | 用户ID |
| type | string | query | 否 | 积分类型 |
| date_start | string | query | 否 | 开始日期 |
| date_end | string | query | 否 | 结束日期 |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/log/credits \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "id": 1, "uid": 1, "amount": 10, "balance": 100, "reason": "签到奖励", "created_at": 1719900000 }
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

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### GET /admin/log/login

获取登录日志。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | query | 否 | 用户ID |
| success | int | query | 否 | 是否成功：0/1 |
| date_start | string | query | 否 | 开始日期 |
| date_end | string | query | 否 | 结束日期 |
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/log/login \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "id": 1, "uid": 1, "ip": "192.168.1.1", "success": true, "created_at": 1719900000 }
    ],
    "pagination": {
      "page": 1,
      "pagesize": 20,
      "total": 200,
      "total_pages": 10
    }
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

## 用户管理

### GET /admin/user

获取用户管理列表。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |
| keyword | string | query | 否 | 关键词搜索 |

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/user \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "uid": 1, "username": "admin", "email": "admin@xiuno.com", "gid": 1 }
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

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### POST /admin/user

创建新用户。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| username | string | body | 是 | 用户名 |
| email | string | body | 是 | 邮箱 |
| password | string | body | 是 | 密码 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/user \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"username": "newuser", "email": "new@example.com", "password": "123456"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "uid": 10
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 409 | 已存在 |
| 422 | 参数验证失败 |

---

### PUT /admin/user/{uid}

管理员更新指定用户信息。

#### HTTP 方法

`PUT`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |
| username | string | body | 否 | 用户名 |
| email | string | body | 否 | 邮箱 |
| gid | int | body | 否 | 用户组ID |

#### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/admin/user/1 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"gid": 1}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 用户不存在 |

---

### DELETE /admin/user/{uid}

删除指定用户。

#### HTTP 方法

`DELETE`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |

#### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/admin/user/1 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 用户不存在 |

---

### POST /admin/user/{uid}/ban

封禁指定用户。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |
| reason | string | body | 否 | 封禁原因 |
| expire | int | body | 否 | 封禁到期时间戳（0=永久） |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/user/5/ban \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"reason": "违规", "expire": 0}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 用户不存在 |

---

### DELETE /admin/user/{uid}/ban

解封指定用户。

#### HTTP 方法

`DELETE`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |

#### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/admin/user/5/ban \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 用户未封禁 |

---

## 版块管理

### GET /admin/forum

获取版块管理列表。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

无

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/forum \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "fid": 1, "name": "默认版块", "threads": 100 }
    ]
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### POST /admin/forum

创建新版块。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| name | string | body | 是 | 版块名称 |
| fup | int | body | 否 | 父版块ID |
| description | string | body | 否 | 描述 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/forum \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"name": "新版块"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "fid": 10
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

### PUT /admin/forum/{fid}

更新指定版块。

#### HTTP 方法

`PUT`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| fid | int | path | 是 | 版块ID |
| name | string | body | 否 | 版块名称 |
| description | string | body | 否 | 描述 |

#### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/admin/forum/1 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"name": "改后名称"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 版块不存在 |

---

### DELETE /admin/forum/{fid}

删除指定版块。

#### HTTP 方法

`DELETE`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| fid | int | path | 是 | 版块ID |

#### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/admin/forum/10 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 版块不存在 |

---

## 帖子批量操作

### PUT /admin/thread/batch

批量更新帖子（管理员）。

#### HTTP 方法

`PUT`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| tids | array | body | 是 | 帖子ID数组 |
| update | object | body | 是 | 更新内容（top/closed/type） |

#### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/admin/thread/batch \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"tids": [1, 2], "update": {"top": 1}}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "updated": 2
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

## 站点设置

### GET /admin/setting

获取站点设置。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

无

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/setting \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "sitename": "Xiuno BBS",
    "description": "简洁的社区系统"
  }
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### PUT /admin/setting

更新站点设置。

#### HTTP 方法

`PUT`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| sitename | string | body | 否 | 站点名称 |

#### 请求示例

```bash
curl -X PUT https://your-site.com/api/v1/admin/setting \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"sitename": "新名称"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

## 封禁 IP

### GET /admin/banned-ip

获取封禁 IP 列表。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/banned-ip \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "ip": "1.2.3.4", "reason": "恶意攻击", "expire": 0 }
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

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### POST /admin/banned-ip

封禁指定 IP。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| ip | string | body | 是 | IP地址 |
| reason | string | body | 否 | 封禁原因 |
| expire | int | body | 否 | 到期时间戳 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/banned-ip \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"ip": "1.2.3.4"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 422 | 参数验证失败 |

---

### DELETE /admin/banned-ip/{ip}

解封指定 IP。

#### HTTP 方法

`DELETE`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| ip | string | path | 是 | IP地址 |

#### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/admin/banned-ip/1.2.3.4 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | IP未封禁 |

---

## 封禁用户

### GET /admin/banned-user

获取封禁用户列表。

#### HTTP 方法

`GET`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| page | int | query | 否 | 页码，默认 1 |
| pagesize | int | query | 否 | 每页数量，默认 20 |

#### 请求示例

```bash
curl https://your-site.com/api/v1/admin/banned-user \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "list": [
      { "uid": 5, "username": "baduser", "reason": "违规", "expire": 0 }
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

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |

---

### POST /admin/banned-user

封禁指定用户。

#### HTTP 方法

`POST`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | body | 是 | 用户ID |
| reason | string | body | 否 | 封禁原因 |
| expire | int | body | 否 | 到期时间戳 |

#### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/admin/banned-user \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"uid": 5, "reason": "违规"}'
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 用户不存在 |

---

### DELETE /admin/banned-user/{uid}

解封指定用户。

#### HTTP 方法

`DELETE`

#### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 需要认证（Bearer Token） |
| 级别 | Admin |
| Scope | admin |

#### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| uid | int | path | 是 | 用户ID |

#### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/admin/banned-user/5 \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <admin_token>"
```

#### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": null
}
```

#### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 用户未封禁 |