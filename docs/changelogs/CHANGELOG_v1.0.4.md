# v1.0.4 - 2026-07-03

> **版本说明**: 新增三大管理能力 —— AI 服务管理、用户/IP 封禁、在线升级；同时增强安全审计与敏感词过滤，前台新增 AI 路由与封禁提示页

## 🆕 新功能

### AI 服务管理（全新模块）
- **AIService + AILogService**（`lib/`）：AI 能力统一接入与调用日志记录
- **后台 AI 管理**（`admin/route/ai.php` + 4 个视图页）：`ai_editor`（编辑器 AI）、`ai_features`（功能开关）、`ai_logs`（调用日志）、`ai_providers`（服务商配置）
- **前台 AI 路由**（`route/ai.php`）：AI 功能前端接口
- 原 `setting_ai.htm` 设置页移除，AI 配置独立成模块

### 封禁体系（全新）
- **UserBanService**（`lib/UserBanService.php`）：用户封禁业务逻辑
- **封禁模型**：`ban_log`（封禁记录）、`banned_ip`（IP 封禁）
- **后台封禁管理**：`banned_user.php` / `banned_ip.php` 路由 + 列表页 + 用户封禁日志页（`user_ban_log.htm`）
- **前台封禁页**：`route/banned.php` + `banned.htm` / `banned_notice.htm`，被封禁用户访问时展示封禁原因

### 在线升级（全新）
- **OnlineUpgradeService**（`lib/OnlineUpgradeService.php`）：在线检测并拉取新版本
- **后台在线升级页**（`online_upgrade.php` + `online_upgrade.htm`）：一键检查更新

### 安全审计增强
- **SensitiveWordFilter**：敏感词过滤服务强化
- **新增保留词表**（`config/reserved_words.txt`）：防止注册保留用户名
- **AuditService / CaptchaService / IpBlacklistService / ReportService** 全面增强

## 🔧 重构与优化

- **XnEvent 事件系统**（`lib/XnEvent.php`）：轻量事件订阅/发布机制
- **LoginSecurityService**（`lib/LoginSecurityService.php`）：登录安全（失败计数、验证码策略）
- **帖子模型增强**（`model/thread.func.php`）：主题回收站相关操作完善
- **后台插件管理**：新增插件确认页（`plugin_confirm.htm`）、扫描器优化
- **多语言与安装脚本**同步更新
- **前台帖子页渲染**优化（`post_list.inc.htm` / `thread_main.inc.htm` 等）

## 🛡️ 安全加固

- 封禁粒度细化：支持用户封禁（含登录封禁）与 IP 封禁双维度
- 敏感词表扩充 + 保留词表新增，注册与发帖双重过滤
- 安全审计覆盖面扩展至 AI 调用与封禁操作

## 📊 统计
- 文件总数：147（新增 25，修改 120，删除 2）
- 代码量：+12,046 行 / -1,362 行
- 提交范围：`0bb49c3` → `8ba4b1d`（5 个提交）
