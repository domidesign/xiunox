# 验证码端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

验证码端点用于生成和验证验证码，支持登录、注册、发帖、回复等多种场景。所有验证码端点挂载在 `/api/v1/captcha/` 路径下。

---

## GET /captcha/{scene}

生成指定场景的验证码。返回验证码 key 和图片（Base64 编码）。

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
| scene | string | path | 是 | 场景：login/register/post/reply |

### 请求示例

```bash
# 登录验证码
curl https://your-site.com/api/v1/captcha/login \
  -H "X-App-Id: your_app_id"

# 注册验证码
curl https://your-site.com/api/v1/captcha/register \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "captcha_key": "abc123",
    "image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 422 | 参数验证失败（无效的场景） |

---

## POST /captcha/{scene}/verify

验证指定场景的验证码。

### HTTP 方法

`POST`

### 认证要求

| 项目 | 说明 |
|------|------|
| 认证 | 不需要认证 |
| 级别 | Public |
| Scope | public |

### 请求参数

| name | type | in | required | desc |
|------|------|-----|----------|------|
| scene | string | path | 是 | 场景：login/register/post/reply |
| captcha | string | body | 是 | 验证码内容 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/captcha/login/verify \
  -H "X-App-Id: your_app_id" \
  -H "Content-Type: application/json" \
  -d '{"captcha": "a3x7"}'
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "valid": true
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 422 | 参数验证失败 |
| 400 | 验证码错误或已过期 |