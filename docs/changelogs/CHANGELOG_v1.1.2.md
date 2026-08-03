# v1.1.2 更新日志 - 2026-07-18

> **版本说明**: 后台全面优化 + 缓存可配置化 —— 新增后台通知服务（AdminNotifyService）、缓存 TTL 可后台配置、积分与等级服务调整、前端 JS 合并精简

## 🆕 新功能

- **AdminNotifyService**（`lib/AdminNotifyService.php` 新增）：后台通知服务，向管理员推送站内通知与邮件
- **缓存 TTL 配置化**（`67bb778`）：`CacheHelper::getCoreTtlKeys()` / `getTtlConfig()` 暴露核心缓存键清单，后台缓存设置页支持自定义 TTL；缓存前缀增加格式校验（仅字母/数字/下划线/短横线，1-32 字符）
- **后台用户指南配图**：`docs/userguide/backend/images/` 新增 30+ 张操作截图

## 🔧 重构与优化

### 后台全面优化
- **安全服务增强**：AuditService / ReportService / CaptchaService / SecurityConfigService 更新
- **设置页重构**：基础信息、积分、显示、邮件模板、SMTP、上传、伪静态等页面调整
- **用户/版块/帖子/插件管理优化**：banned_ip/banned_user/plugin_scanner/thread 等路由与视图
- **积分规则服务调整**（CreditsRuleService）：规则配置与校验完善
- **等级服务调整**（RankService）：用户等级计算优化

### 前端
- **JS 合并精简**：删除 `async.js` / `form.js` / `upload.js` / `xiuno.js`，功能并入 `xiuno-modern.js` / `bootstrap-plugin.js`
- **删除 my_avatar.htm、theme.htm**：头像上传与主题页面重构
- **版块页/首页/发帖页/帖子列表渲染优化**
- **详情页 video 样式**：修复 AIEditor 残留 inline 样式导致视频高度为 0

### 底层
- **xiunophp 函数优化**：misc.func.php、xn_send_mail.func.php
- **安装脚本更新**（install.sql）
- **DEBUG 切换为 0**：生产模式

## 📊 统计
- 文件总数：200（新增 59，修改 135，删除 6）
- 代码量：+5,468 行 / -4,614 行
- 提交范围：`a961df1` → `551e27a`（14 个提交）
