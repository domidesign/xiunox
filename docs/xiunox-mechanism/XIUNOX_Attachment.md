# XIUNOX_Attachment 附件管理

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 的附件管理系统通过 `AttachmentService` 统一服务类处理文件上传、存储、校验和清理全流程。上传过程采用双重安全校验机制——先通过 `finfo_file` 获取真实 MIME 类型与白名单比对，再结合扩展名白名单过滤，有效防止 `.php` 伪装成 `.jpg` 等恶意上传行为。文件统一存储在 `upload/attach/` 目录，采用随机文件名避免路径遍历攻击，同时支持图片缩略图自动生成和视频元数据提取。

## 站长指南

### 配置入口

后台 → 安全设置 → 上传安全，核心配置项包括：

### 配置项说明

| 配置键 | 默认值 | 说明 |
|--------|--------|------|
| `upload_max_image_size` | 10485760 (10MB) | 图片最大上传体积 |
| `upload_max_video_size` | 104857600 (100MB) | 视频最大上传体积 |
| `upload_max_music_size` | 20971520 (20MB) | 音频最大上传体积 |
| `upload_max_default_size` | 20971520 (20MB) | 其他文件默认上限 |
| `upload_thumb_enabled` | 1 | 是否启用缩略图生成 |
| `upload_thumb_width` | 200 | 缩略图最大宽度（高度自适应） |
| `security_upload_strict_mime` | 1 | 严格 MIME 校验模式，设为 0 启用兼容模式 |

### 使用场景

- **帖子附件**：发帖时上传图片/视频/文档，系统自动生成缩略图
- **头像上传**：用户中心头像上传，仅允许图片格式
- **视频嵌入**：视频上传后自动提取宽高、时长等元数据
- **批量导入**：通过 `AttachmentService::uploadFile()` 接入自定义上传流程

### 注意事项

1. **PHP 扩展依赖**：缩略图生成需要 GD 扩展（`imagecreatefromjpeg` 等函数），视频信息提取建议安装 `ffprobe` 或 `getID3` 插件
2. **目录权限**：`upload/attach/` 和 `upload/tmp/` 目录必须可写（建议 755 或 777）
3. **MIME 校验降级**：当 PHP 未启用 `fileinfo` 扩展时，`security_upload_strict_mime=1`（默认）会拒绝所有上传；如需兼容请设为 0 并安装 `fileinfo` 扩展
4. **临时文件清理**：临时文件存储在 `upload/tmp/` 下，正式发布后会迁移至 `upload/attach/`，未使用的临时文件建议定期清理

## 开发者指南

### 核心服务类

**类**：`AttachmentService`（`service/AttachmentService.php`）

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `uploadImage($file, $uid, $options)` | 文件数组、用户ID、选项数组 | `['code'=>0, 'message'=>[...]]` | 图片专用上传，自动生成缩略图 |
| `uploadVideo($file, $uid, $options)` | 文件数组、用户ID、选项数组 | `['code'=>0, 'message'=>[...]]` | 视频专用上传，自动提取宽高和时长 |
| `uploadFile($file, $uid, $options)` | 文件数组、用户ID、选项数组 | `['code'=>0, 'message'=>[...]]` | 通用文件上传，自动识别类型 |
| `upload($file, $options)` | 文件数组、选项数组 | `['code'=>0, 'message'=>[...]]` | 兼容旧接口，支持 `driver` 参数 |
| `getMaxSize($filetype)` | 类型分类 | `int` 最大字节数 | 获取某类型的大小限制 |
| `validateMime($tmpName, $allowedMimes)` | 临时文件路径、MIME白名单 | `string\|false` | 私有方法，校验真实 MIME |
| `generateThumbnail($srcPath, ...)` | 原图路径、配置、宽高 | `string\|false` | 静态方法，生成缩略图 |
| `getVideoInfo($filepath)` | 视频文件路径 | `array\|false` | 静态方法，获取视频元数据 |

### 钩子点

- **上传前校验**：通过 `security_upload_strict_mime` 配置控制 MIME 校验严格程度
- **类型白名单扩展**：修改 `conf/attach.conf.php` 中的类型数组可扩展允许的文件格式
- **大小限制覆盖**：通过 `conf` 配置键 `upload_max_{type}_size` 动态覆盖默认限制

### 扩展方式

自定义上传驱动（如 OSS 云存储），通过 `AttachmentService::upload()` 的 `driver` 参数扩展：

```php
// 示例：扩展本地存储逻辑
$service = AttachmentService::getInstance();
$result = $service->uploadFile($_FILES['attachment'], $uid);

// 校验结果
if ($result['code'] === 0) {
    $url = $result['message']['url'];
    $filetype = $result['message']['filetype'];
}
```

### 代码示例

**1. 前端 FormData 上传对接**：

```javascript
const fd = new FormData();
fd.append('attachment', fileInput.files[0]);

fetch('/api/attachment/upload', {
    method: 'POST',
    body: fd
}).then(res => res.json()).then(data => {
    if (data.code === 0) {
        console.log('文件URL:', data.message.url);
        console.log('缩略图:', data.message.thumb_url);
    }
});
```

**2. 自定义类型校验**：

```php
// 添加自定义文件类型到白名单
$custom_types = array_merge(
    include APP_PATH.'conf/attach.conf.php',
    array('apk', 'ipa')
);
// 将扩展后的数组通过 upload_allowed_file_types 配置传入
```

**3. 安全文件操作（插件内）**：

```php
// 使用 xn_safe_io 在白名单路径内操作
xn_safe_write(APP_PATH.'upload/tmp/'.$filename, $content, 'myplugin');
xn_safe_unlink(APP_PATH.'tmp/cache.tmp', 'myplugin');
```

## 常见问题

1. **上传时提示"文件类型不允许"怎么办？**
   检查 `conf/attach.conf.php` 是否包含目标扩展名；同时确认 `security_upload_strict_mime` 不为 1 且 PHP 已启用 `fileinfo` 扩展，否则 MIME 校验会拒绝上传。

2. **图片上传后没有生成缩略图？**
   确认 GD 扩展已安装（`phpinfo` 查看），且 `upload_thumb_enabled` 配置为 1；此外原图尺寸小于配置的最大宽高时不会生成缩略图。

3. **视频上传后时长显示为 0？**
   需安装 `ffprobe` 到系统 PATH 或在 `plugin/getid3/` 目录下放置 `getid3.php`，两者都不可用时视频元数据将无法提取。

4. **如何清理未使用的临时文件？**
   临时文件存放在 `upload/tmp/` 下，可编写定时任务扫描超过 24 小时的文件并删除；正式附件存储在 `upload/attach/` 下，仅在帖子删除时通过 `AttachmentService::deleteAttachment()` 清理。

5. **安全模式和兼容模式有什么区别？**
   严格模式（默认）要求 `fileinfo` 扩展必须可用，通过真实 MIME 与白名单比对；兼容模式在 `fileinfo` 不可用时，图片改用 `getimagesize()` 降级校验，非图片仅依赖扩展名白名单，安全性较低但兼容性更好。