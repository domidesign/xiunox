# 管理操作端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

管理操作端点用于对帖子进行管理操作，包括置顶、关闭、删除、移动和公告设置。这些端点需要管理员或版主权限。所有管理操作端点挂载在 `/api/v1/mod/` 路径下。

---

## POST /mod/top

置顶或取消置顶帖子。支持版块置顶和全局置顶两种级别。

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
| tidarr | array | body | 是 | 帖子ID数组 |
| top | int | body | 是 | 置顶级别：0=取消, 1=版块置顶, 3=全局置顶 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/mod/top \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"tidarr": [1, 2], "top": 1}'
```

### 响应示例

```json
{
  "code": 0,
  "message": "设置完成",
  "redirect_url": "./"
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 权限不足 |

---

## POST /mod/close

关闭或打开帖子。

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
| tidarr | array | body | 是 | 帖子ID数组 |
| close | int | body | 是 | 0=打开, 1=关闭 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/mod/close \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"tidarr": [1], "close": 1}'
```

### 响应示例

```json
{
  "code": 0,
  "message": "设置完成",
  "redirect_url": "./"
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 权限不足 |

---

## POST /mod/delete

删除帖子。

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
| tidarr | array | body | 是 | 帖子ID数组 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/mod/delete \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"tidarr": [1, 2]}'
```

### 响应示例

```json
{
  "code": 0,
  "message": "删除完成",
  "redirect_url": "./"
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 权限不足 |

---

## POST /mod/move

移动帖子到指定版块。

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
| tidarr | array | body | 是 | 帖子ID数组 |
| newfid | int | body | 是 | 目标版块ID |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/mod/move \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"tidarr": [1], "newfid": 2}'
```

### 响应示例

```json
{
  "code": 0,
  "message": "移动完成",
  "redirect_url": "./"
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 权限不足 |
| 404 | 版块不存在 |

---

## POST /mod/announcement

设置或取消公告帖子。

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
| tidarr | array | body | 是 | 帖子ID数组 |
| is_announcement | int | body | 是 | 0=取消, 1=设置 |
| announcement_order | int | body | 否 | 排序权重 |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/mod/announcement \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"tidarr": [1], "is_announcement": 1, "announcement_order": 0}'
```

### 响应示例

```json
{
  "code": 0,
  "message": "设置完成",
  "redirect_url": "./"
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 403 | 权限不足 |