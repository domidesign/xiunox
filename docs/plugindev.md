# XIUNOX 插件开发文档

> 本页为简洁入口，仅汇总技术栈基线、高频违规速查与分册导航。详细原理、API 与示例请沿导航表跳转至 `plugindev/` 对应分册。

## 项目简介

XIUNOX（Xiuno BBS X 重构版）插件开发文档入口。XIUNOX 在 Xiuno BBS 4.0 基础上重构核心层，引入 htmx 4、Service 化模型与运行时安全机制，保留 Hook + Overwrite 双扩展机制：

- **Hook（钩子）**：在源文件 hook 点注入代码，不修改原文件，适合小范围功能增强。
- **Overwrite（覆盖）**：直接替换源文件，适合大范围改写，如重写整个页面模板。

本入口面向已熟悉 Xiuno 基础的开发者，提供快速定位与硬规则提醒；新手请从 [01-architecture.md](plugindev/01-architecture.md) 开始通读。

## 技术栈基线

- PHP 8.0+（要求 pdo_mysql 扩展）
  - 后端运行环境，禁用过时语法，核心函数见 [04-api-cheatsheet.md](plugindev/04-api-cheatsheet.md)
- Bootstrap 5.3+（UI 框架）
  - 后台与前台布局，配合 Tabler 主题
- Tabler Icons（图标库）
  - 替代 FontAwesome，统一图标风格
- htmx 4.x（前端交互框架，事件名必须用冒号格式）
  - 前端交互首选，事件名规范见速查表第 1 条与 [05-frontend-security.md](plugindev/05-frontend-security.md)
- xiuno-modern.js（XN 命名空间 API，原生 JS 兼容层）
  - 提供 `XN.toast` / `XN.ajax` / `XN.confirm` / `XN.alert` 等高层 API（前后台 footer 已全局加载）
- 原生 JS（fetch / querySelectorAll / addEventListener）
  - **jQuery 已于 2026-07-24 系统性移除**，所有页面禁止使用 `$`/`jQuery`/`$.fn.*`
  - 关键修复页面（在线升级/数据库升级/后台登录）零外部依赖，必须用原生 fetch + confirm
  - 迁移指南见 [10-jquery-removal-guide.md](plugindev/10-jquery-removal-guide.md)

## 核心硬规则速查表（高频违规项）

> 以下规则来自 `bugfix_rules.md` 历史事故沉淀，违反任一条均会导致线上故障。每次提交前请逐项自检。

| 规则 | 违规次数 | 详见分册 |
|------|----------|----------|
| htmx 事件名必须用 4.x 冒号格式（`htmx:config:request`/`htmx:after:swap`），禁止 2.x 旧名（`htmx:configRequest`/`htmx:afterSettle`） | 已违反 1 次影响 8 页面 | [05-frontend-security.md](plugindev/05-frontend-security.md) |
| 修改核心 `model/*.func.php` 后必须删 `tmp/model.min.php` | 已违反 2 次 | [01-architecture.md](plugindev/01-architecture.md) |
| Admin 路径检测用 `SCRIPT_NAME`，禁止用 `REQUEST_URI`/`PHP_SELF` | 已违反 1 次 | [06-ai-collaboration.md](plugindev/06-ai-collaboration.md) |
| 密码/token/API key 用 `param()` 必须传第 3 参 `FALSE`（关闭 htmlspecialchars） | 已违反 1 次 | [04-api-cheatsheet.md](plugindev/04-api-cheatsheet.md) |
| 缓存驱动配置变更（host/port/password）必须触发实例重建，不复用旧实例 | 已违反 1 次 | [04-api-cheatsheet.md](plugindev/04-api-cheatsheet.md) |
| `session_redis` 与 `cache.redis` 字段名用 `password`/`database`，禁止 `auth`/`db` | 已违反 1 次 | [04-api-cheatsheet.md](plugindev/04-api-cheatsheet.md) |
| 修改 `_include()` 加载的文件后必须清 `tmp/` 编译缓存（核心不比较 mtime） | 已违反 1 次 | [01-architecture.md](plugindev/01-architecture.md) |
| URL 函数禁止双重包裹（`admin_plugin_setting_url` 等已内部调用 `url()`） | 已违反 1 次 | [04-api-cheatsheet.md](plugindev/04-api-cheatsheet.md) |
| `cache_truncate` 禁止用 `flushdb`（误删 session），改用 `deleteByPrefix` | 已违反 1 次 | [04-api-cheatsheet.md](plugindev/04-api-cheatsheet.md) |
| 数据库结构变更走 `upgrade.php` 幂等迁移，禁止 `install.php` 自愈代码 | 已违反 N 次 | [02-plugin-structure.md](plugindev/02-plugin-structure.md) |
| 卸载脚本文件名用 `uninstall.php`，禁止 `unstall.php`（旧拼写） | 已违反 2 次 | [02-plugin-structure.md](plugindev/02-plugin-structure.md) |
| 用户显示名必须用 `display_name` 字段，禁止直接取 `username` | 已违反 1 次 | [04-api-cheatsheet.md](plugindev/04-api-cheatsheet.md) |

## 详细文档导航

| 分册 | 内容 |
|------|------|
| [README.md](plugindev/README.md) | 手册入口与导航 |
| [01-architecture.md](plugindev/01-architecture.md) | 架构与原理（编译时合并、`_include`、`tmp` 缓存、`model.min.php`） |
| [02-plugin-structure.md](plugindev/02-plugin-structure.md) | 目录结构、`conf.json`、`upgrade.php` 生命周期 |
| [03-hooks-catalog.md](plugindev/03-hooks-catalog.md) | Hook 全量目录（含 Admin hook） |
| [04-api-cheatsheet.md](plugindev/04-api-cheatsheet.md) | API 速查（`param`/`url`/`db`/`CacheHelper`/`CsrfService`/`LoginSecurityService`） |
| [05-frontend-security.md](plugindev/05-frontend-security.md) | 前端规范与安全（htmx 4 事件名、Bootstrap、XSS） |
| [06-ai-collaboration.md](plugindev/06-ai-collaboration.md) | AI 协作规范（硬规则、扫描器、检查表） |
| [07-runtime-safety.md](plugindev/07-runtime-safety.md) | 运行时安全（ErrorHandler、崩溃自动禁用、`plugin_hook`） |
| [08-login-security.md](plugindev/08-login-security.md) | 登录安全（运维指南、`LoginSecurityService` API） |
| [10-jquery-removal-guide.md](plugindev/10-jquery-removal-guide.md) | jQuery 移除与原生 JS 迁移指南（关键修复页面规范、API 对照表） |
