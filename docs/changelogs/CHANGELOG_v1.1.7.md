# v1.1.7 更新日志 - 2026-08-16

> **版本说明**: API 中间件层精简重构 + 个人中心模板体系整合 + 安全模块全面优化 + 搜索排序与 IP 查询 —— 移除 6 个 API 中间件类（认证/权限/消毒/错误处理/查询构造器），个人中心删除 10+ 旧模板并整合为统一布局，安全服务（审计/报告/配置/敏感词）全量优化，插件市场增强与通知类型注册表化，在线升级服务改进，新增搜索排序、IP 归属地查询页，管理员/用户登录时效可配置

> **⚠️ Breaking Changes**
> - **移除 API 中间件层**：删除 `ApiAuthContext`、`ApiContext`、`ApiErrorHandler`、`ApiPermissionMiddleware`、`ApiSanitizer`、`QueryBuilder` 六个类，相关认证/权限/消毒逻辑已内联到路由层，二次开发依赖这些类的插件需适配
> - **移除 browser 路由**：删除 `route/browser.php` 及相关模板/图标，浏览器判断逻辑不再独立提供
> - **个人中心模板重构**：删除 `my.htm`、`my.template.htm`、`my_password.htm`、`my_feed*.htm`、`my_notify.template.htm`、`my_thread.template.htm`、`user.template.htm` 等旧模板，统一使用 `my.layout.inc.htm` 布局

## 🆕 新功能

### IP 归属地查询页（全新）
- **前台 IP 查询** `route/ip.php` + `view/htm/ip.htm`：支持 IPv4/IPv6 归属地查询（数据来源 ip9.com.cn 免费接口，限 60 次/分钟/IP）
- **查询缓存**：10 分钟短缓存，同一 IP 重复查询不重复请求外部接口，规避免费额度限制
- **输入校验**：非法 IP 直接提示错误不请求接口，未传参默认查询访客自身 IP
- **后台用户列表联动**：`admin/view/htm/user_list.htm` 增加前台 IP 查询页链接（自动拼接站点根 URL）

### 搜索排序功能
- **搜索结果排序** `route/search.php` + `view/htm/search.htm`：支持正序（asc，最早在前）与倒序（desc，最新在前）排序切换
- **SQL 层排序**：显式排序时直接在 SQL 中 `ORDER BY tid`，避免"先 LIMIT 100 再 PHP 排序"在正序场景取到错误批次
- **排序不计频控**：显式传 sort 的请求视为结果重排，跳过搜索频率限制，快速切换排序不被拦截

### 登录时效可配置
- **管理员登录时效** `admin/admin.func.php` + `admin/view/htm/security_account.htm`：新增 `security_admin_login_expire` 配置（分钟，默认 60），后台超时自动退出时间可调
- **用户登录时效** `lib/security/SecurityConfigService.php`：新增 `security_user_login_expire` 配置（天，默认 7），bbs_token cookie 有效期可调
- **安全中心配置页**：账号设置页新增登录时效配置项

### 通知类型注册表化
- **通知类型注册表** `lib/NotifyTypeRegistry.php`：通知类型改为可注册式架构，支持插件扩展自定义通知类型
- **管理后台通知服务** `lib/AdminNotifyService.php`：配合注册表重构通知投递逻辑
- **前台通知路由** `route/notice.php`：适配新通知类型体系

### 文档体系扩充
- **存储驱动扩展指南** `docs/plugindev/16-storage-driver-extension.md`：新增存储驱动扩展开发文档
- **主题插件开发指南** `docs/plugindev/17-theme-plugin-guide.md`：新增主题插件开发完整教程
- **OAuth 安全机制** `docs/xiunox-mechanism/XIUNOX_OAuth_Security.md`：新增 OAuth 安全机制说明
- **插件开发计划** `docs/plugindev/plans/2026-08-07-xnx_fish-pond-v2.md`：新增鱼塘插件 v2 规划
- **Bug 反馈指南** `docs/error-reporting.md`：新增面向普通用户的出错排查与 Bug 反馈实用手册

### 一键关闭所有插件
- **新增功能** `admin/route/plugin.php` + `admin/view/htm/plugin_list.htm` + `model/route.func.php`：一键关闭所有已启用插件（`disable-all` 路由），显示成功/失败数量，支持 CSRF 校验
- **前端交互** `admin/view/htm/plugin_list.htm`：新增"一键关闭"按钮（显示当前已启用数量），点击后确认弹窗 → AJAX 批量禁用 → Toast 提示结果
- **多语言包** `lang/zh-cn|en-us|zh-tw/bbs_admin.php`：新增 5 项语言包（`plugin_disable_all_btn`、`plugin_disable_all_confirm`、`plugin_disable_all_sucessfully`、`plugin_disable_all_partial`、`plugin_disable_all_failed`）

### 粘贴 HTML 富文本自动清理
- **防卡死** `lib/EditorService.php`：新增 `cleanPastedHtml()` 清理函数，阈值 100KB 触发——清理 Word/邮件富文本（剥离 Office 命名空间元素 `o:p`/`w:*`/`v:*`/`m:*`、`<script>`/`<style>`/`<iframe>` 等无关元素、非语义属性、空 span/p/div），体积从 800KB+ 缩到几十 KB
- **语义保留** `lib/EditorService.php`：智能转换含粗体/斜体 inline style 的 `<span>` 为 `<strong>`/`<em>` 语义标签，保留格式
- **回退机制** `lib/EditorService.php`：清理失败或插入异常时自动回退到纯文本粘贴，保证不丢失内容

## 🔧 重构与优化

### API 层精简重构
- **移除 API 中间件层**：删除 `ApiAuthContext`、`ApiContext`、`ApiErrorHandler`、`ApiPermissionMiddleware`、`ApiSanitizer`、`QueryBuilder`，认证/权限/消毒/错误处理逻辑内联到 API 路由
- **API 入口整合** `api/v1/bootstrap.php` + `api/v1/index.php`：简化启动流程
- **API 业务路由** `api/v1/my.php`：个人中心 API 适配重构

### 个人中心模板整合
- **统一布局** `view/htm/my.layout.inc.htm`：个人中心改为统一布局架构
- **页面整合** `my_avatar_page.htm` + `my_profile.htm` + `my_security.htm`：头像、资料、安全页整合到新布局
- **路由重构** `route/my.php` + `route/user.php`：个人中心路由精简，移除 browser 等冗余路由
- **删除旧模板**：`my.htm`、`my.template.htm`、`my.common.template.htm`、`my_password.htm`、`my_favorite.template.htm`、`my_feed*.htm`、`my_notify.template.htm`、`my_thread.template.htm`、`user.template.htm`、`browser.htm`

### 安全模块全面优化
- **审计服务** `lib/security/AuditService.php`：审计日志记录优化
- **安全报告** `lib/security/ReportService.php`：安全报告生成改进
- **安全配置** `lib/security/SecurityConfigService.php`：配置管理增强（新增登录时效配置）
- **安全服务** `lib/security/SecurityService.php`：核心安全逻辑优化
- **敏感词过滤** `lib/security/SensitiveWordFilter.php`：过滤算法改进
- **CSRF 服务** `lib/CsrfService.php`：CSRF 防护增强
- **后台安全页** `security_blacklist.htm` + `security_words.htm`：黑名单与敏感词管理页优化
- **后台审计/安全路由** `admin/route/audit.php` + `security.php`：路由逻辑优化

### 插件市场与扫描器增强
- **插件路由** `admin/route/plugin.php`：插件管理路由优化，安装同步作者信息改走 6h 缓存（不再强制刷新阻塞安装流程）
- **插件扫描器** `admin/route/plugin_scanner.php` + `lib/PluginScanner.php` + `lib/PluginScannerRules.php` + `lib/PluginScannerSuggestion.php`：扫描规则与建议生成增强
- **插件市场页** `plugin_official_list.htm` + `plugin_list.htm`：官方插件列表与本地插件页微调
- **官方插件服务** `lib/OfficialPluginService.php`：manifest 拉取超时从 30s 降至 8s（避免安装/升级被网络长时间阻塞），作者信息同步函数修复

### 在线升级服务改进
- **升级路由** `admin/route/online_upgrade.php` + `upgrade.php`：升级流程优化
- **升级视图** `online_upgrade.htm` + `upgrade.htm`：升级页交互改进
- **升级服务** `lib/OnlineUpgradeService.php` + `lib/UpgradeService.php`：升级服务容错与逻辑增强

### 后台路由与视图优化
- **后台路由全量更新**（19 个）：`ai`、`api`、`attach`、`audit`、`banned_ip`、`credits_rule`、`forum`、`group`、`index`、`online_upgrade`、`other`、`plugin`、`plugin_scanner`、`security`、`setting`、`theme`、`thread`、`upgrade`、`user`
- **后台视图更新**（18 个）：`ai_logs`、`ai_providers`、`api_debug`、`api_doc`、`api_settings`、`group_list`、`online_upgrade`、`other_cache`、`plugin_list`、`setting_smtp`、`setting_upload`、`thread_recycle` 等
- **后台入口** `admin/index.php`：提前写入语言包，修复动态覆盖后 lang() 取不到 bbs_admin 键的问题
- **AI 服务** `lib/AIService.php`：AI 服务逻辑优化

### 核心框架与基础设施
- **XiunoPHP 框架**（12 文件）：`cache.func.php`、`cache_file.class.php`、`cache_memcached.class.php`、`cache_redis.class.php`、`db.func.php`、`db_pdo_mysql.class.php`、`db_pdo_sqlite.class.php`、`misc.func.php`、`xiunophp.min.php`、`xiunophp.php`、`xn_html_safe.func.php`、`xn_send_mail.func.php`
- **数据模型**（9 文件）：`attach`、`email_log`、`forum`、`misc`、`plugin`、`route`、`user` 等
- **服务层**（3 文件）：`AttachmentService`、`CreditsRuleService`、`ForumService`
- **前台路由**（13 文件）：`ai`、`attach`、`captcha`、`forum`、`forum_index`、`my`、`notice`、`post`、`rank`、`search`、`thread`、`user` 等
- **前台视图** `footer.inc.htm`、`message.htm`、`post.htm`、`thread_js.inc.htm`：模板适配
- **前端脚本** `xiuno-modern.js`、`bbs.js`：前端逻辑更新
- **错误处理** `lib/ErrorHandler.php`：异常处理增强

### 多语言包同步
- **简体中文** `lang/zh-cn/bbs_admin.php`、`bbs_common.php`、`bbs_install.php`
- **繁体中文** `lang/zh-tw/bbs_admin.php`、`bbs_common.php`、`bbs_install.php`
- **英文** `lang/en-us/bbs_admin.php`、`bbs_common.php`、`bbs_install.php`

## 🐛 问题修复

- **密码迁移误伤修复** `lib/UpgradeService.php`：修复旧版本 `UPDATE user SET password_hash = '' WHERE password != ''` 误清空 password 与 password_hash 并存的用户的 bcrypt 哈希，导致 bbs_token 指纹校验失败、用户被强制掉线的问题；密码迁移不再需要任何数据改动
- **登录态排查日志增强** `model/user.func.php`：取消 300 秒去重，全量记录每条掉线请求（含请求方法、路由/子动作、来源页、会话/cookie 状态），便于完整还原"用户在做什么动作时被踢"
- **头像组件修缮** `72b4c43`：头像上传与裁剪逻辑修复
- **附件/会话模型修复** `72b4c43`：附件隔离与会话模型边界问题修正
- **帖子路由修缮** `72b4c43`：帖子路由边界情况修复
- **插件市场官方列表页微调** `3c71142`：官方列表展示细节修复
- **在线升级服务修复** `15760ce`：升级流程稳定性问题修正
- **用户管理模板修复** `15760ce`：后台用户管理模板显示问题修正
- **后台语言包更新** `15760ce`：多语言文案补齐
- **插件安装阻塞修复** `admin/route/plugin.php`：安装时不再强制刷新作者信息 manifest，避免被远程网络拉取阻塞（主源+备源最多等 60s）
- **后台语言包写入修复** `admin/index.php`：提前写入 `$_SERVER['lang']`，修复动态覆盖后 lang() 取不到 bbs_admin 键的问题
- **权限服务表前缀修复** `lib/PermissionService.php`：`tableExists()` 改用 `db_check_table_exists()` 框架助手（原 `$conf['db']['master']['tablepre']` 取值错误，非 `bbs_` 前缀站点恒返回 false，免审权限静默失效）
- **权限迁移字段补齐** `lib/UpgradeService.php`：`upgradePermissionSystem()` 补 3 个免审字段（`allow_direct_post`/`allow_direct_reply`/`allow_direct_profile`）到 `$permissionFields` 数组，随 `group_audit_permissions` 步骤同步进 `group_permission` 表
- **MutationObserver 性能优化** `view/js/xiuno-modern.js`：验证码 DOM 观察器跳过编辑器内元素（`.aie-content`/`.ProseMirror`），ProseMirror 渲染时避免大量无意义的 `addedNodes` 遍历

## 🗑️ 移除

- `lib/ApiAuthContext.php` — API 认证上下文（内联到路由）
- `lib/ApiContext.php` — API 请求上下文（内联到路由）
- `lib/ApiErrorHandler.php` — API 错误处理器（内联到路由）
- `lib/ApiPermissionMiddleware.php` — API 权限中间件（内联到路由）
- `lib/ApiSanitizer.php` — API 输入消毒器（内联到路由）
- `lib/QueryBuilder.php` — 查询构造器（不再使用）
- `route/browser.php` — 浏览器判断路由
- `view/htm/browser.htm` — 浏览器判断模板
- `view/img/browser.gif` — 浏览器图标
- `view/htm/my.common.template.htm` — 个人中心旧公共模板
- `view/htm/my.htm` — 个人中心旧首页
- `view/htm/my.template.htm` — 个人中心旧模板
- `view/htm/my_password.htm` — 旧密码修改页（合并到安全页）
- `view/htm/my_favorite.template.htm` — 旧收藏模板
- `view/htm/my_feed.htm` — 旧动态页
- `view/htm/my_feed.template.htm` — 旧动态模板
- `view/htm/my_notify.template.htm` — 旧通知模板
- `view/htm/my_thread.template.htm` — 旧主题模板
- `view/htm/user.template.htm` — 旧用户页模板

## 📊 统计
- 文件总数：约 195（核心重构 164 + 功能/修复补充 31）
- 提交范围：`7be50af` → 当前 HEAD（涵盖 `72b4c43`、`3c71142`、`28fd331`、`15760ce`、`08ff0b8`、`a07e8b8`、`aabbf06`、`77cdeb0`、`1b81574`、`634c38a` 等）
- 版本号：`version.php` 升至 `1.1.7`
