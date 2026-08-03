# v1.0.1 - 2026-06-16

> **版本说明**: 完成核心框架搭建 —— 新增统一服务层（缓存/积分/API 鉴权/CSRF/编辑器/错误处理）、完整后台管理系统（设置/安全/日志/版块/插件/升级）、社交互动体系（关注/点赞/收藏/通知/精华），重构用户模型与登录态，并对个人中心、发帖、帖子页等核心路由进行大规模重写。

## 🆕 新功能

### 后台管理系统（全新，`admin/` 共 19 个路由 + 58 个视图）
- **系统设置中心**（`route/setting.php`，1014 行）：整合基础信息、显示、积分、AI、导航、页脚、伪静态（permalink）、上传、SMTP 邮件、邮件模板、邮件日志共 11 类设置页
- **安全中心**（`route/security.php`）：安全审计、IP 黑名单、验证码策略、安全防护开关、敏感词管理 5 个页面
- **日志中心**（`route/log.php`）：附件日志、内容审核日志、积分流水、登录日志、操作日志 5 类查询
- **版块管理**：版块创建/编辑/列表，支持排序与展示控制
- **帖子管理**：帖子列表、搜索、回收站找回（`thread_found`）
- **插件管理重构**（`route/plugin.php`，411 行）：列表/详情页重写；新增**插件扫描器**（`plugin_scanner.php`）扫描未安装/未启用的插件
- **积分规则管理**（`route/credits_rule.php`）：按版块配置积分规则
- **API 调试台 + API 文档页**（`api_debug.htm` / `api_doc.htm`）：在线调试与查看接口说明
- **分阶段升级流程**（`upgrade_phase1(_do).php`）：升级拆分为多个阶段，每阶段自动执行 DDL 变更，降低升级失败风险
- 另新增：附件管理、友情链接管理、主题管理、后台公告发布（`admin_notice_*`）、用户/用户组管理

### 社交互动体系（新增 7 个模型）
- 版块关注（`model/forum_follow`）、用户关注（`model/user_follow`）
- 帖子点赞（`model/post_like`）、帖子收藏（`model/thread_favorite`）、帖子精华（`model/thread_digest`）
- **通知中心**（`model/notice` + `route/notice.php`，388 行）：站内通知列表、未读计数、HTMX 下拉实时提醒（`notify_dropdown`）
- **提醒系统**（`model/notify`，204 行）：回复/@ 提醒，支持未读统计

### 统一服务层（`lib/`，9 个新服务）
- **CacheService**：统一文件缓存，缓存目录可配置
- **CreditsService**：积分加减、流水记录、负数预检查（防积分透支）
- **ApiAuthService**：API token 鉴权体系（refresh token 30 天 / access token 2 小时 / 绝对过期 90 天）
- **ApiResponse + ApiDocService**：统一 API 响应格式与文档生成
- **EditorService**：编辑器资源注入、上传集成
- **ErrorHandler**：全局异常/错误处理，Fatal Error 兜底渲染 500
- **CsrfService**：表单/API 统一 CSRF 校验
- **DatabaseInterface + EscapeService**：数据库访问抽象与输出转义

### 前端新路由
- 验证码（`route/captcha.php`）、版块首页（`forum_index.php`）、友情链接（`friendlink.php`）、语言切换（`lang.php`）、排行（`rank.php`）、搜索（`search.php`，269 行）

## 🔧 重构与优化

### 用户模型重构（`model/user.func.php`，988 行改动，31 个函数）
- 重写登录态体系：token 生成/校验/清除、cookie 选项统一、登录检查与鉴权分离（`user_login_check` / `user_auth_check`）
- 新增用户匿名化（`user_purge` / `user_is_anonymized`）、密码修改、用户组变更流程
- 用户查询支持批量预加载（`user_preload`）与多用户名批量查询

### 核心前端路由重写
- **个人中心**（`route/my.php`，1159 行）：完整重写，含通知/提醒子页面；HTMX 轮询在会话过期时不再强制跳转登录页；DDL 检查首次执行后写标记文件跳过
- **发帖**（`route/post.php`，807 行）、**帖子页**（`route/thread.php`，758 行）、**用户主页**（`route/user.php`，983 行）：重写并接入新模型层
- **附件上传**（`route/attach.php`，538 行 + `model/attach.func.php`，373 行）：重构并接入统一服务
- **版主管理**（`route/mod.php`，158 行）：扩展审核能力

### 入口与配置
- `index.inc.php` / `model.inc.php` 统一加载新模型与钩子机制
- 新增邮件模板配置（`conf/email_templates.conf.php`）与邮件发送日志（`model/email_log`，105 行）
- 新增管理操作日志（`model/admin_log`）
- 根目录 `.htaccess` 新增 URL 重写规则

## 🛡️ 安全加固
- 集成 **HTMLPurifier**（131 个文件）净化用户 HTML，防 XSS
- **CsrfService**：所有表单/API 统一 CSRF 校验（cookie 有效期 7 天）
- **密码体系升级**：升级脚本为 user 表新增 `password_hash`（bcrypt）、`login_attempts`（失败计数）、`banned_until`（登录封禁）字段，并新建 `user_login_log` 登录日志表
- `admin/.htaccess` 后台目录访问控制，保护管理入口
- `config/security.php` 集中安全配置 + `config/sensitive_words.txt` 敏感词表
- 新增用户资料审核模型（`model/user_profile_audit`）

## 📊 统计
- 文件总数：567（新增 489，修改 73，删除 5）
- 代码量：+71,207 行 / -7,935 行
- 提交范围：`2089c2c` → `8040f07`（3 个提交）
