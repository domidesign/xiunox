# 站点端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

站点端点用于获取站点基础信息、统计数据和健康检查。所有站点端点挂载在 `/api/v1/site/` 路径下，无需用户认证。

---

## GET /site

获取站点基本信息。

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
curl https://your-site.com/api/v1/site \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "name": "Xiuno BBS",
    "api_version": "1.0",
    "version": "1.0.0",
    "url": "https://your-site.com",
    "logo_url": "assets/img/logo.png",
    "keywords": "论坛,BBS",
    "description": "Xiuno BBS 默认描述"
  }
}
```

### 错误码

无特定错误码。

---

## GET /site/stats

获取站点统计数据，包括帖子数、回复数、用户数等。

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
curl https://your-site.com/api/v1/site/stats \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "threads": 100,
    "posts": 500,
    "users": 50,
    "today_threads": 5,
    "today_posts": 20,
    "today_users": 2
  }
}
```

### 错误码

无特定错误码。

---

## GET /site/health

健康检查端点，用于监控系统状态。

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
curl https://your-site.com/api/v1/site/health \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "status": "ok",
    "db": true,
    "cache": true,
    "timestamp": 1719900000
  }
}
```

### 错误码

无特定错误码。