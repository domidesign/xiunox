# v1.1.6 更新日志 - 2026-08-04

> **版本说明**: API 中间件基础设施 + 个人中心重构 + PWA 支持 —— 新增 API 认证/权限/消毒/错误处理中间件，头像与积分设置页合并统一，个人中心路由与模板重构，新增 PWA（favicon + service worker），PHP 8 禁用函数容错，邮件伪异步与 IP 容错修复，文档体系迁移至 docs/

## 🆕 新功能

### API 中间件基础设施（全新）
- **认证上下文** `lib/ApiAuthContext.php` + `lib/ApiContext.php`：统一 API 请求上下文与用户态
- **权限中间件** `lib/ApiPermissionMiddleware.php`：路由级权限校验前置
- **输入消毒** `lib/ApiSanitizer.php`：统一入参清洗
- **统一错误处理** `lib/ApiErrorHandler.php` + `api/v1/common.php`：标准化 API 异常响应

### 头像与积分设置合并
- **头像设置合并** `admin/view/htm/setting_avatar.htm` 与 `setting_display.htm` 整合为统一设置页
- **积分规则路由统一** `admin/route/credits_rule.php` + `credits_rule.htm`：积分规则管理收敛到独立路由

### PWA 支持（全新）
- 新增 `favicon.ico` 站点图标与 `sw.js` Service Worker，支持离线缓存与可安装

## 🔧 重构与优化

- **文档体系迁移至 docs/**：`changelogs/`、`install.md`、`plugindev.md`、`xiunobbs4.0.4.md` 全部迁入 `docs/`（`a1eb501`）
  - 补全 `docs/api/`（认证、限流、15 个端点文档）、`docs/plugindev/`、`docs/xiunox-mechanism/`（18 篇机制说明）、`docs/xiunox-plugin-dev/`（SKILL + references）
  - 新增 `generate_changelogs.sh` 与历史版本 changelog 文档（v1.0.0 ~ v1.1.5）
- **个人中心重构**（`2974929`）：
  - `api/v1/my.php` + `route/my.php` + `route/thread.php` + `route/user.php` 路由优化
  - `view/htm/my.common.template.htm`、`my_security.htm` 模板整合
  - `view/js/bbs.js` 前端逻辑更新
- **后台 API 菜单恢复**（`845d865`）：`admin/route/api.php` + `other.php`，`api_debug/api_doc/api_log/api_settings.htm` 补齐，标题与 `admin_tab_active` 统一
- **AiEditor 预取与 defer 优化**：`view/js/aieditor/index.umd.js` 加载策略改进，后台编辑页 `post.htm`/`header.inc.htm` 配套调整
- **插件与在线升级**：`admin/route/plugin.php` 路由优化、`lib/OnlineUpgradeService.php` 在线升级服务改进
- **多语言包**：`lang/zh-cn`、`zh-tw`、`en-us` 同步更新

## 🐛 问题修复

- **PHP 8 兼容**：`xiunophp/cache_file.class.php` 对 `set_time_limit`/`chmod` 等禁用函数做容错，避免环境禁用时 fatal（`5d96fb5`）
- **邮件伪异步发送**：`xiunophp/xn_send_mail.func.php` 修复同步阻塞，改为伪异步投递
- **IP C 段容错**：`model/user.func.php` 放宽 IP 解析，兼容非常规 C 段
- **SEO 检测字段修正**：`admin/route/setting.php` 检测逻辑修正（`93bc57a`）
- **插件市场官方标识与提示完善**：`admin/view/htm/plugin_official_list.htm` + `lang/*/bbs_admin.php` 文案与标识补齐（`93bc57a`）
- **通用函数修正**：`model/misc.func.php` 修复边界逻辑
- **错误处理器改进**：`lib/ErrorHandler.php` 增强异常保护
- `route/ai.php` 在版本升级时同步适配（`5d96fb5`）

## 📊 统计
- 文件总数：133（新增大量 docs/ 文档，修改 76，删除少量）
- 代码量：+16,453 行 / -988 行
- 提交范围：`21c64d6` → `7be50af`（涵盖 `a1eb501`、`5d96fb5`、`845d865`、`1a4bb88`、`2974929`、`7be50af`，及收尾 `93bc57a`、脚本 `b069a86`）
- 版本号：`version.php` 升至 `1.1.6`
