# v1.1.8 更新日志 - 2026-08-22

> **版本说明**: 插件统一通知中心 + 编辑器粘贴优化 + 在线升级体验增强 + 页脚配置化重构 —— 新增插件通知中心（站内消息/邮件/红点三通道统一管理），编辑器大段富文本粘贴自动清理（MutationObserver 死循环修复 + 阈值拦截），一键关闭所有已启用插件，在线升级错误信息提取与复制，IP 查询页 URL 解析兼容，DEBUG 常量严格校验，页脚配置化重构，附件兜底扫描路径补云存储 hook

## 🆕 新功能

### 插件统一通知中心（全新）
- **通知门面函数** `model/plugin_notify.func.php`：新增 `plugin_notify_fire()` 统一通知门面，插件一处调用即可同时投递站内消息/邮件/红点三通道，支持节流（throttle 秒数）与自定义标题/正文/跳转链接
- **通知设置页** `admin/route/plugin.php` + `admin/view/htm/plugin_notice.htm`：新增 notice 路由与设置页，统一管理各插件三通道开关与提醒邮箱，支持发送测试邮件（SMTP 配置校验 + CSRF 校验）
- **红点角标** `admin/view/htm/plugin_list.htm` + `admin/view/htm/sidebar.inc.htm`：插件列表与后台侧栏展示待处理事项红点角标，走 `core_plugin_notice` 聚合缓存（单次 cache_get，无 N+1 查询）
- **举报通知迁移** `lib/security/ReportService.php`：举报审核待办通知从 AdminNotifyService 迁移到 `plugin_notify_fire()` 统一门面（300s 节流），待办清零自动清除节流标记并失效红点缓存
- **插件协议接入**：插件通过 `hook/plugin_notice_count.php` 参与红点聚合，通过配置记录持久化通道开关

### 一键关闭插件
- **批量禁用路由** `admin/route/plugin.php`：新增 disable-all 路由，一键关闭所有已启用插件
- **前端交互** `admin/view/htm/plugin_list.htm`：工具栏按钮 + JS 确认弹窗 + AJAX 批量禁用 + Toast 结果提示

### 在线升级错误提取与复制
- **错误信息提取** `admin/view/htm/online_upgrade.htm`：新增 `extractErrorMessage()` 从非 JSON 响应（HTML 错误页/维护模式/CSRF 拦截）中提取人类可读错误
- **错误框渲染**：新增 `renderErrorBox()` 渲染带复制按钮的错误框，`copyErrorText()`/`fallbackCopy()` 兼容旧浏览器，checkUpdate/preflight/升级步骤/重装的错误显示全面替换

### 编辑器粘贴富文本清理
- **自动清理** `lib/EditorService.php`：粘贴 HTML 超过阈值（300KB）时 capture 阶段拦截，DOMParser 解析后剥离 Office 命名空间元素/非语义属性/空 span，保留语义结构（p/h/ul/ol/li/a/strong/em/img/code/pre/blockquote/table），体积从 891KB 缩到约 13KB（-98%）
- **粗体斜体保留**：`<span style="font-weight:bold">` 等先转换为 `<strong>`/`<em>` 语义标签再剥离样式
- **失败回退**：清理或插入失败时回退纯文本插入，调试日志输出每次粘贴的 text/html 体积与走向
- **MutationObserver 修复** `view/js/xiuno-modern.js`：`_captchaObserver` 跳过编辑器内 DOM 变化，避免与大段粘贴形成观察器循环及性能浪费

### 文档与工具
- **Bug 反馈手册** `docs/error-reporting.md`：新增实用排错与反馈指南
- **通知中心开发文档** `docs/plugindev/18-plugin-notify-hub.md` + `docs/plugin-notify-guide.md` + `docs/xiunox-plugin-dev/references/notify-patterns.md`：插件接入统一通知中心的完整协议文档
- **管理员密码重置工具** `tool/reset_password.php`：命令行重置管理员密码
- **用户指南** `docs/userguide/reset-admin-password.md` + 开发笔记 `docs/dev-notes/`：配套文档

## 🔧 重构与优化

### 页脚配置化重构
- **页脚模板** `view/htm/footer_nav.inc.htm`：改为配置驱动——功能链接（sitemap/小黑屋公示）受后台开关控制，ICP/公安备案任一填写即展示备案区，OPcache 状态检测兼容扩展未启用场景，未设置版权时显示站点名称
- **页脚样式** `view/css/bootstrap-bbs.css`：页脚新布局样式适配

### 核心框架与模型
- **插件模型** `model/plugin.func.php`：新增 `plugin_paths_enabled()` 等通知中心支撑函数
- **附件模型** `model/attach.func.php`：兜底扫描路径补云存储 `storage_save` hook（与主上传路径行为一致）
- **通知服务适配** `lib/AdminNotifyService.php` + `lib/CacheHelper.php`：通知投递与缓存适配统一通知中心
- **Redis 缓存** `xiunophp/cache_redis.class.php` + `xiunophp/xiunophp.min.php`：缓存层优化
- **后台菜单/侧栏** `admin/menu.conf.php` + `admin/view/htm/sidebar.inc.htm`：接入通知设置入口

### 多语言包同步
- **简体中文** `lang/zh-cn/bbs_admin.php`、`bbs_common.php`
- **繁体中文** `lang/zh-tw/bbs_admin.php`、`bbs_common.php`
- **英文** `lang/en-us/bbs_admin.php`、`bbs_common.php`

## 🐛 问题修复

- **免审权限静默失效** `lib/PermissionService.php`：修复 `tableExists()` 表前缀取值 bug（改用 `db_check_table_exists()`），非 `bbs_` 前缀站点免审权限不再失效
- **权限迁移缺字段** `lib/UpgradeService.php`：权限迁移补 3 个免审字段（allow_direct_post/reply/profile）到 group_permission 表
- **IP 查询页参数丢失** `route/ip.php` + `view/htm/ip.htm`：IP 参数从 `$_GET` 改为 REQUEST_URI 正则提取，兼容双 `?` 格式（`/?ip.htm?ip=xxx`）下 xn_url_parse 不覆盖 `$_GET` 导致的参数丢失；无 IP 输入时隐藏查询结果区
- **升级版本号误读** `lib/UpgradeService.php`：targetVersion 不再用 XIUNOX_VERSION 常量初始化（OPcache validate_timestamps=0 时持有旧字节码，extract 后常量不可同进程重定义），改从磁盘 version.php 直接读取
- **维护模式验证码** `model/misc.func.php`：维护模式下 captcha 路由放行 generate/verify，登录页仍可请求/校验验证码
- **DEBUG 非法值防御** `index.inc.php` + `index.php`：DEBUG 严格校验（仅允许 0/1/2，非法值语言包报错），移除旧版 DEBUG=3 超管免登录说明

## 📊 统计
- 文件变更：56 文件（+2736 / -266）
- 提交范围：`855f908`（v1.1.7）→ `c0c7451`（v1.1.8），涵盖 `77cdeb0`、`1b81574`、`71e831d`、`971c60b` 等
- 版本号：`version.php` 升至 `1.1.8`
