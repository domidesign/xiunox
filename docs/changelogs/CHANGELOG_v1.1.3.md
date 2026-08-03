# v1.1.3 - 2026-07-23

> **版本说明**: 大批量修复 + 架构升级 —— API 日志与调试台鉴权完善、Service 自注册架构（去核心化）、发帖版块白名单强制校验、多标签页附件隔离（pageToken）、全局 lightbox、审核通知 v2.0

## 🆕 新功能

### API 管理升级
- **API 日志页**（`api_log.htm` 新增）：按 resource/method/appid 过滤查询 API 调用日志（`conf.api_log` 开启时显示入口）
- **调试台 X-App-Id 强制鉴权**（`a9026d0`）：调试台发送请求前强制选择 App；修复 revokeToken 用错主键导致 token 删不掉的问题；token 生成后 toast 提示并标注"明文仅显示一次"（DB 存 SHA-256 哈希）
- **App 配置联动**：scope=full 时禁用资源白名单复选框（避免 full 权限仍被白名单拦截）；资源列表补齐至与 bootstrap 路由一致的 15 个
- **post_create 代发安全**：`$options` 控制附件关联行为，代发场景（uid ≠ 登录用户）默认跳过附件关联，防止机器人代发窃取 session 中的上传附件

### 架构升级
- **Service 自注册架构**（`a9026d0`）：DiscoverService / NavService 去核心化重构，注册表初始为空，由各插件通过 `discover_register.php` / `nav_register.php` 自注册，`ensureRegistered()` 懒扫描启用插件，符合开闭原则

### 前端新能力
- **全局 lightbox**（`2a4d066`）：全站图片放大查看（Bootstrap 5 Modal + 原生 JS，零依赖），支持缩放/旋转/拖拽/上一张下一张/键盘操作；移除帖子页旧版 lightbox
- **特殊主题按钮组**（`369faa8`）：发帖页新增抽奖/悬赏/投票按钮容器，form 标签外移避免 htmx hook 嵌套
- **XN.prompt()**：替代原生 prompt，支持 multiline/required/validate
- **插入 Markdown 按钮**（`c79fe6a`）：编辑器工具栏主动触发 Markdown 源码粘贴转换（替代自动粘贴监听，修复代码块粘贴被误转换的问题）
- **view/.htaccess**：静态资源缓存策略

### 审核通知 v2.0
- **管理员自动接收**：站内通知自动发给所有 `gid IN (1,2)` 且未封禁的管理员；邮件优先读插件配置 `admin_notify_emails`，回退管理员邮箱；修复旧代码 `user.status` 字段不存在导致收件人恒为空的问题

## 🐛 问题修复

- **发帖版块白名单强制校验**（`d9d985a`）：新增 `forum_can_post()` 共享判断，帖子发布/移动目标版块双重拦截，模板层按钮同步显隐（此前仅下拉框过滤，curl 可直接绕过）
- **多标签页附件隔离**（`c79fe6a`）：新增 pageToken 页面会话标识，上传与发帖携带 page_token，只关联匹配的临时文件，精确清理 session 保留其他标签页附件
- **积分双提交修复**：jQuery shim 的 on() 不处理 return false 导致表单双重提交（AJAX + 原生 POST），wrap 函数捕获返回值显式 preventDefault
- **bbs_cache.k 扩容**：char(32) → varchar(255)，支持长缓存键（CACHE_KEY_MD5_THRESHOLD）；升级流程自动检测并 ALTER
- **后台日志用户模糊搜索**：积分/金币日志支持 username + nickname LIKE 模糊匹配
- **Caddy 伪静态**：后台新增 Caddy v2 伪静态规则配置
- **导航 active 服务端渲染**：NavService::isActive() 服务端输出，移除前端 JS active 逻辑
- **URL 解析修复**：先剥离 query 再取后缀（`f001bae`）、积分检查 URL query 分隔符适配 url_rewrite_on=0（`c5373c0`）
- **htmx outerHTML 替换**、附件并发 key、sitemap 转义（`1a46c13`）
- **OPcache 清理**：SMTP 保存与固定链接切换后调用 opcache_invalidate（`593500a`）
- **API 语言包加载、xn_substr null 兼容**（`593500a`）
- **插件扫描器**：force_blocked 强制阻断分类（conf.json 必填字段校验，force=1 也不可跳过）；JSON 文件跳过逐行规则扫描；规则未转义 `(` 的误报修复（`d9d985a`）
- **更多路由显式加载 NavService**：修复旧 tmp 缓存下发现项加载失败（`07dd16c`）
- **邮件链接绝对 URL**：后台通知邮件链接转绝对地址，避免邮件客户端解析失败

## 📊 统计
- 文件总数：113（新增 7，修改 104，删除 2）
- 代码量：+7,730 行 / -1,105 行
- 提交范围：`551e27a` → `8100e15`（14 个提交）
