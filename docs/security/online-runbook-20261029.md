# 线上部署 Runbook（2026-10-29 安全修复全量）

覆盖两轮修复：附件直链泄露处置 + XSS/CSRF/SQL 功能修复。全程预计 30~60 分钟（不含观察）。
原则：**先封直链 → 再部署代码 → 再迁移文件 → 最后验收 → 验收通过前不开 API**。

## 第 0 步：准备（5 分钟）

1. 备份：整站文件 + 数据库（mysqldump 或宝塔备份）。迁移工具会把附件**移出** Web 根，私有存储目录必须纳入后续备份范围。
2. 保存事发时段（2026-10-29 前后）的 Nginx 访问日志副本，留作取证。
3. 确认 API 当前状态：线上此前返回 503（API is disabled），**保持关闭**，直到第 5 步验收全部通过。

## 第 1 步：封压缩包直链（部署代码前先做，最优先）

Nginx 站点配置的 `server {}` 中、通用静态资源 `location` **之前**加入（详见 attach-disclosure-20261029.md）：

```nginx
location ~* ^/upload/(?:attach|tmp)/.*\.(?:zip|rar|7z|tar|gz|tgz|bz|bz2|tbz2|xz|txz|zst|iso)$ {
    add_header Cache-Control "private, no-store" always;
    return 403;
}
location ~* ^/upload/tmp/.*\.json$ {
    add_header Cache-Control "private, no-store" always;
    return 403;
}
```

注意：
- 若已有 `location ^~ /upload/` 前缀匹配，顶层正则不会执行，必须把拒绝规则放进该前缀内。
- 附件若有独立域名/CDN 源站域名，每个域名都要加同样规则。
- 若用了云存储（S3/OSS/COS）且包是公开读的，关闭公开读。
- `nginx -t` 通过后 reload。
- **CDN**：清理 `upload/attach`、`upload/tmp` 路径的全部历史缓存；确认 CDN 没有“忽略源站缓存头”类设置，源站 `private, no-store` 必须能穿透。

验证（用任一已知真实压缩包地址）：

```bash
curl -f -o /dev/null -s -w '%{http_code}\n' 'https://xiunox.org/upload/attach/xxxxx.zip'   # 期望 403
```

（注意必须 `curl -f`：不带 -f 时 403 页面也会被写进文件，造成“还能下载”的误判。）

## 第 2 步：部署代码（10 分钟）

上传本次改动文件（清单见下节）。两种方式任选：

- **方式 A（推荐）**：按清单逐个上传对应文件，覆盖同名文件。
- **方式 B**：整站打包覆盖（排除 `tmp/`、`log/`、`upload/`、`conf/conf.php`）。

### 本次改动文件清单（2026-10-29，共 75 个）

核心：`index.inc.php 除外`，直接照下表上传——

```
api/v1/{admin,attach,bootstrap,mod,post,search,thread,user}.php
admin/route/api.php
admin/route/forum.php
lib/{ApiAuthService,ApiContentService,ApiResponse,AttachmentAccessService,AttachmentDownloadService,HiddenContentGuard,OnlineUpgradeService,PrivateAttachmentStorage,RateLimitService,SecurityAuditService}.php
lib/security/AuditService.php
model/{attach,post,thread}.func.php
model/user_profile_audit.func.php
route/{attach,index,mod,my,search,thread,user}.php
service/AttachmentService.php
tool/{migrate_private_storage,test_attach_metadata_security,test_hidden_content_guard}.php
view/htm/header.inc.htm  view/htm/mod_auditlist.htm  view/htm/my_notify.htm  view/htm/thread_top_list.inc.htm
xiunophp/{cache_mysql.class.php,db.func.php,db_pdo_mysql.class.php,xiunophp.min.php}
upload/attach/.htaccess
upload/tmp/.htaccess
docs/ 与 update_20261029.md 可选（不影响运行）
plugin/navgcn_ticket/{conf.json,model/NavgcnTicketService.php}
plugin/xnx_ai_reply/{conf.json,model/AiReplyService.php,model/AiTriggerService.php}
plugin/xnx_appcenter/{conf.json,model/AppCenterService.php,route/appcenter.php}
plugin/xnx_demo_data/{conf.json,model/DemoDataService.php}
plugin/xnx_fcomment/{conf.json,model/FcommentService.php}
plugin/xnx_hidden/{conf.json,hook/model_post_format_end.php,model/HiddenService.php,route/api_hidden.php}
plugin/xnx_pwa/{conf.json,route/push.php}
plugin/xnx_setting_transfer/{conf.json,route/setting_transfer.php}
plugin/xnx_theme_moments/{conf.json,hook/thread_list_inc_start.htm,view/htm/thread_list_moments.inc.htm}
```

上传后三件事（顺序执行）：

1. **清编译缓存**（必做，改了多个 `_include` 文件）：
   ```bash
   rm -rf /网站根目录/tmp/*
   ```
2. **刷新 OPcache**：重启 php-fpm（或 `service php-fpm reload`；宝塔在 PHP 设置里重载）。
3. **后台插件**：进「后台 → 插件」，确认 6 个插件版本已显示为新版本（xnx_appcenter 1.15.40、xnx_hidden 1.1.8、xnx_pwa 1.0.4、navgcn_ticket 1.2.4、xnx_setting_transfer 1.0.1、xnx_theme_moments 1.0.2）。本次升级无数据库变更，若插件列表有“升级”按钮点一次即可。

### 迁移前确认私有存储位置

私有附件默认存到**网站根目录之外**的同级目录（`.xiunox-private-xxxxxxxxxxxx/`，自动创建，0700）。
如需自定义位置，在部署迁移前创建 `conf/private_storage.php`：

```php
<?php
return array('path' => '/data/xiunox-private');   // 绝对路径，必须在 Web 根之外
```

（或设置环境变量 `XIUNOX_PRIVATE_PATH`。）
**该目录务必纳入备份计划。**

## 第 3 步：存量附件迁移（5~30 分钟，视附件量）

```bash
cd /网站根目录
php tool/migrate_private_storage.php             # 先 dry-run，看「需保护附件」数量与抽样列表
php tool/migrate_private_storage.php --apply     # 确认无误后实际执行
```

迁移动作 = 复制 → SHA-256 校验 → 删除公开副本 → 重写正文里的旧 URL。应用包/文档等非公开媒体全部迁移，隐藏帖/待审/软删/受限版块内的图片视频一并迁移，普通帖子公开图片原地不动。
失败条目会逐行打印 `[FAILED]`，单独处理后可重复运行（幂等，已迁移的自动跳过）。

迁移完成后：**后台 → 其他 → 缓存 → 清空全站缓存**（列表缓存可能残留旧 URL），再清一次 CDN 的 `upload/attach` 历史缓存。

## 第 4 步：线上验收（10 分钟）

按顺序逐条打勾，任何一条不符先停下排查：

| # | 验收项 | 期望 |
|---|---|---|
| 1 | 已泄露的旧 zip 直链（源站、CDN、大写 `.ZIP`、tmp 下的） | 全部 403/404 |
| 2 | 匿名带 AppID 调 `/api/v1/attach/1.html` | 401（API 仍关闭则 503，属正常） |
| 3 | 游客打开应用中心点下载 | 提示需登录，不吐文件 |
| 4 | 无购买用户下载付费应用 | 拒绝；已购买用户、作者、管理员正常下载 |
| 5 | 下载响应头 | `Cache-Control: private, no-store`（F12 → Network 看） |
| 6 | 游客搜索含隐藏块的帖子：结果摘要 + 页面源码 meta description | 无隐藏正文，只有锁定占位 |
| 7 | 帖子详情页右键源码看 `application/ld+json` | 无 `</script>` 字面量逃逸 |
| 8 | 隐藏帖「指定用户可见」的选人搜索框 | 能搜出用户（不再 500） |
| 9 | 后台 API 日志/登录日志带条件筛选 | 能出数据（不再恒空） |
| 10 | 版主后台移动帖子：目标版块下拉 | 只列出自己管辖的版块 |
| 11 | 普通帖子图片、头像、公开应用图标 | 正常显示（迁移没误伤） |
| 12 | 发一帖带图 + 带 [hidden] 块 → 游客/未满足条件账号看 | 图片正常，隐藏块锁定；满足条件的账号解锁 |
| 13 | PWA 推送订阅（如启用了该插件） | 订阅成功（本次修复后 POST 会被正确放行/校验） |

## 第 5 步：事后处理

1. **取证**：在保存的访问日志里关联排查（判断泄露范围）：
   ```bash
   grep '/api/v1/attach/' access.log | awk '{print $1, $7}' | sort | uniq -c | sort -rn | head
   grep '/upload/attach/.*\.zip' access.log | awk '{print $1, $9, $10}' | sort | uniq -c | sort -rn | head
   ```
   顺序枚举 aid 后跟大量 200 下载的 IP/UA = 攻击者；统计下载的 zip 数量即实际泄露范围。
2. **API 开启**：验收全部通过后再决定是否开启 API。开启前：
   - 后台「API 设置」检查应用列表，**删除不认识的应用**（攻击脚本用的 AppID `254c97876512060b` 查一下是否存在、何时创建，存在即删）；
   - 全部应用设 IP 白名单/最小 scope；默认公开 AppID 只用于浏览器端。
3. 观察一天 `error_log` 与审计日志（私有存储 `audit/security-YYYYMMDD.jsonl`），确认无新增异常。

## 回滚预案

- 直链封锁出问题（正常图片/视频受影响）：删掉第 1 步的 Nginx 两段 location 并 reload，立即恢复。
- 代码出问题：用第 0 步备份覆盖回去 + `rm -rf tmp/*` + 重启 php-fpm。
- 迁移出问题：`--apply` 中断可安全重跑；已迁移文件仍在私有目录，恢复公开需手工拷回 `upload/attach/`（正常流程不需要）。
