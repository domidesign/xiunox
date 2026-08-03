# 附件端点

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

附件端点用于查询、上传和删除附件。所有附件端点挂载在 `/api/v1/attach/` 路径下。

---

## GET /attach/{aid}

获取指定附件的详情。

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
| aid | int | path | 是 | 附件ID |

### 请求示例

```bash
curl https://your-site.com/api/v1/attach/1 \
  -H "X-App-Id: your_app_id"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "aid": 1,
    "filename": "image.jpg",
    "filesize": 102400,
    "url": "upload/attach/202605/image.jpg",
    "uid": 1,
    "created_at": 1719900000
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 404 | 附件不存在 |

---

## POST /attach

上传附件。支持关联到指定帖子或回复。

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
| file | file | body | 是 | 附件文件（multipart/form-data） |
| tid | int | body | 否 | 帖子ID |
| pid | int | body | 否 | 回复ID |

### 请求示例

```bash
curl -X POST https://your-site.com/api/v1/attach \
  -H "X-App-Id: your_app_id" \
  -H "Authorization: Bearer <access_token>" \
  -F "file=@/path/to/file.jpg" \
  -F "tid=1"
```

### 响应示例

```json
{
  "code": 0,
  "msg": "Uploaded",
  "data": {
    "aid": 1,
    "url": "upload/attach/202605/image.jpg"
  }
}
```

### 错误码

| code | 说明 |
|------|------|
| 401 | 未授权 |
| 422 | 文件类型不允许 |

---

## DELETE /attach/{aid}

删除指定附件。仅附件所有者或管理员可执行。

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
| aid | int | path | 是 | 附件ID |

### 请求示例

```bash
curl -X DELETE https://your-site.com/api/v1/attach/1 \
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
| 404 | 附件不存在 |