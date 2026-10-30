# 附件元数据越权与压缩包直链处置（2026-10-29）

## 已确认的代码缺口

`api/v1/attach.php` 原 GET 分支在校验 API 应用凭据后，未校验附件所有者，直接返回附件行（只移除 driver），泄露 filename。应用凭据和 public scope 不能代替用户身份或购买权限。`upload/attach/` 中的文件可被 Web 服务器静态返回，从而绕过应用中心业务校验。

本次 PHP 补丁要求 Bearer Token，仅允许所有者及 gid=1、2 查询，并通过字段白名单删除所有存储路径和 URL。应用中心下载响应禁止共享缓存。Apache 的 attach/tmp 规则阻止压缩包静态下载，并阻止临时 JSON 元数据访问。Nginx 不读取 .htaccess，必须部署下面的规则。

## Nginx 必须部署

将以下规则合并到网站 `server` 中，放在通用静态资源正则 location 之前。示例假定论坛位于域名根目录且上传目录是 `/upload/`；子目录安装、自定义上传目录、独立附件域名、CDN 源站均须修改和覆盖实际 URL。

```nginx
# 已泄露的历史压缩包地址也必须阻断，大小写扩展名均覆盖。
location ~* ^/upload/(?:attach|tmp)/.*\.(?:zip|rar|7z|tar|gz|tgz|bz|bz2|tbz2|xz|txz|zst|iso)$ {
    add_header Cache-Control "private, no-store" always;
    return 403;
}
location ~* ^/upload/tmp/.*\.json$ {
    add_header Cache-Control "private, no-store" always;
    return 403;
}
```

**如果已有 `location ^~ /upload/` 或 `location ^~ /upload/attach/` 等前缀，顶层正则可能不会执行。必须在对应前缀中落实拒绝规则或调整匹配优先级，不能只粘贴后假定生效。** 若已用 `internal` 配合 X-Accel-Redirect，保留内部授权下载能力；本补丁的应用中心下载由 PHP readfile 输出，不依赖静态 ZIP URL。

执行 `nginx -t`，成功后通过现有运维流程重载配置。不要直接对整个 attach 目录一刀切拒绝，现有帖子图片仍使用此目录。普通压缩附件仍可经 PHP 附件下载入口获取；依赖压缩包公开直链的外部工具将被拒绝，这是关闭绕过权限通道所需的行为变化。云存储若直接对外提供压缩包，同样必须关闭对象公开读取并使用权限校验后的短时授权下载。

## 上线顺序与验收

1. 立即在源站/CDN 拦截压缩包直链，再上线 PHP 修复；缓存层必须同步执行访问控制并清理既有附件 API 响应和压缩包缓存。只改 PHP 无法撤回已泄露地址。
2. 上传修改文件，后台升级应用中心至 1.15.39，清理 `_include` 编译缓存和部署环境 OPcache。
3. 用已知真实文件验证：匿名、普通用户、购买者访问同一 `/upload/attach/...zip` 均应 403；大写 `.ZIP`、临时压缩包及 `.meta.json` 也应被拒绝。图片仍正常显示。
4. API 验证：无 Token 为 401；普通用户查他人 aid 与不存在 aid 均为 404；所有者及管理员为 200，响应不含 filename/path/url/thumb_url/driver 等存储字段。
5. 应用中心业务下载验证：游客、无所需用户组/勋章、未购买付费包均拒绝；已购买者、作者、管理员按现有业务规则下载成功，响应为 private/no-store。检查 CDN 没有“忽略源站缓存头”强制缓存此入口。
6. 保留并审查 2026-10-29 事发前后访问日志及 API 审计日志，关联 `/api/v1/attach/` 顺序 aid 查询与随后 `/upload/attach/` 的成功响应、字节量、IP、User-Agent、uid/appid。区分探测请求与实际文件传输，不能仅由对话推断全部附件已被下载。

本地补丁不代表线上配置已生效。未来应将收费包迁至 Web 根目录之外的私有存储，迁移时同时清除旧公开副本及 CDN 缓存；扩展名拦截仅覆盖列出的格式，不能作为任意付费文件的通用私有存储方案。

## 2026-10-29 第二轮补全（同日审计后）

在首轮止血之上完成的扩展修复：

1. **存量迁移工具**：`php tool/migrate_private_storage.php`（默认 dry-run，加 `--apply` 实际迁移）。把所有需保护附件（应用包等非公开媒体一律迁移；隐藏帖/待审帖/软删帖/受限版块内的图片视频一并迁移）按「复制 → SHA-256 校验 → 删除公开副本 → 重写正文旧 URL」迁入私有存储。迁移后必须清全站缓存并清理 CDN 的 upload/attach 历史缓存。
2. **存储驱动接管点回归权：route/attach.php 的 read/download/fetch 在调用 AttachmentDownloadService 之前先执行 `AttachmentAccessService::assertAllowed`，并恢复了 `// hook storage_serve.php` 占位**。此前重构丢失该占位导致 xnx_clouds3 等 S3 驱动附件 404；直接恢复会让 302 重定向绕过权限，因此占位放在授权之后——存储驱动接管输出必须永远发生在业务校验之后。
3. **隐藏内容（xnx_hidden）泄露出口封堵**（搜索摘要 route/search.php、帖子页 SEO description 与 og:image route/thread.php、xnx_theme_moments 列表卡片摘要与首图、xnx_fcomment 侧栏共享缓存改为只缓存 pid 列表按查看者渲染、xnx_ai_reply 送第三方 LLM 前过滤）。统一过滤出口：lib/HiddenContentGuard.php（fail-closed）+ lib/ApiContentService.php，回归：`php tool/test_hidden_content_guard.php`。
4. **API 越权修补**：thread/hot 补版块读权限过滤；PUT /user/{uid} 改邮箱/改密必须验证当前登录密码（对齐 /my/email 防接管设计）并补邮箱唯一性校验；mod move（API 与 Web）补目标版块版主管辖与读权限校验；like/favorite/report 及 post like 对不可读帖统一 404。
5. 受影响插件版本：xnx_appcenter 1.15.39、xnx_hidden 1.1.7、xnx_theme_moments 1.0.1、xnx_fcomment 1.0.1、xnx_ai_reply 1.0.5。上线任一插件相关改动后需清理对应 `_include` 编译缓存（`tmp/` 下 route_*、model_*、plugin_* 相关条目）并刷新 OPcache。

## 上线验收补充（第二轮）

- 迁移后任选一个已迁移压缩包：直链 `/upload/attach/...zip` 应 403/404，站内下载入口按业务权限正常。
- 游客搜索含 [hidden] 的帖子：摘要只出现 `[Content locked]` 或不含隐藏正文；页面源码 meta description 同样检查。
- 无权用户调用 `GET /api/v1/thread/hot`、点赞/收藏受限版块帖子：均 404/403，不泄露存在性。
- 泄露的旧 ZIP 地址在源站、附件独立域名（如有）、CDN 三处都验证 403。
