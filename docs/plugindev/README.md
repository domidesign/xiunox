# XIUNO BBS X 插件开发手册

> 版本：XiunoX（XIUNOX 现代化分支）
> 核对源码：`model/plugin.func.php`、`xiunophp/*.func.php`、`model/*.func.php`、`lib/*.php`、`view/htm/*.htm`
> 本套手册基于真实源码逐条核对，所有 API 签名、hook 名称、约定均来自代码本身。

本手册是**给人和 AI 一起用的**结构化多文件文档。本手册配套根目录 `../plugindev.md` 作为简洁入口（含核心硬规则速查表 + 分册导航）。

---

## 快速导航

| 想做的事 | 看这里 |
|---|---|
| 先理解插件怎么跑起来的 | [01-architecture.md](01-architecture.md) |
| 新建插件要建哪些文件 | [02-plugin-structure.md](02-plugin-structure.md) |
| 找一个合适的位置插入代码 | [03-hooks-catalog.md](03-hooks-catalog.md)（★Hook 全量目录） |
| 查某个 API 怎么调 | [04-api-cheatsheet.md](04-api-cheatsheet.md)（★API 速查） |
| 写前端 / 加 CSS / 加 JS / 安全 | [05-frontend-security.md](05-frontend-security.md) |
| AI 协作时的硬规则和避坑 | [06-ai-collaboration.md](06-ai-collaboration.md) |
| 运行时安全 / 崩溃自动禁用 | [07-runtime-safety.md](07-runtime-safety.md) |
| 登录安全 / 账号锁定运维 | [08-login-security.md](08-login-security.md) |
| model 加载机制重构说明（v1.1.4） | [09-model-loading-refactor.md](09-model-loading-refactor.md) |
| jQuery 移除与原生 JS 迁移 | [10-jquery-removal-guide.md](10-jquery-removal-guide.md) |
| 编辑器工具栏按钮集成 | [11-editor-toolbar-integration.md](11-editor-toolbar-integration.md)（★hook 机制加按钮） |
| 头像组件使用与扩展 | [12-avatar-component.md](12-avatar-component.md)（★统一头像渲染 + hook 扩展） |
| [插件互斥机制开发者指南](plugin-mutex-guide.md) | 主题插件互斥、功能插件冲突避免、目录命名规范 |

文件命名带数字前缀，可按顺序读，也可单查。

---

## 技术栈一览（写代码前必读）

| 层 | 技术 | 备注 |
|---|---|---|
| 后端 | PHP 8.0+（需 `pdo_mysql`） | 函数式为主，OPCache 友好 |
| 数据库 | MySQL（**仅 pdo_mysql 驱动**） | 表前缀 `$tablepre`（默认 `bbs_`） |
| UI 框架 | Bootstrap 5.3+（CDN） | `.container` 居中，Light Theme |
| 图标 | Tabler Icons | `<i class="ti ti-xxx"></i>` |
| 交互 | **htmx 4.x** + `hx-live` + `hx-optimistic` | 新代码首选 |
| 兼容层 | `xiuno-modern.js`（全局 `XN.*` API） | 提供 toast/ajax/escape/confirm（非关键页面可用） |
| 原生 JS | `fetch` / `querySelectorAll` / `addEventListener` | jQuery 已移除，所有页面强制使用原生 JS |

## 禁止项（违反 = 扫描器拦截或运行异常）

- ❌ **禁止 jQuery**：**已于 2026-07-24 系统性移除全部 jQuery 依赖**。新代码用 htmx 4 属性、`XN.*` API 或原生 JS（`fetch`/`querySelectorAll`/`addEventListener`）。迁移指南见 [10-jquery-removal-guide.md](10-jquery-removal-guide.md)。
- ❌ **禁止 Alpine.js**：`x-data/x-show/x-bind/x-text/x-on/x-model` 全部禁用。
- ❌ **禁止 idiomorph / alpine-morph**：htmx 4 已内置 morph（`innerMorph`/`outerMorph`）。
- ❌ **禁止用 `htmlspecialchars` 裸写**：用 `esc_html()` / `esc_attr()`（`lib/EscapeService.php`）。
- ❌ **禁止 POST 表单不带 CSRF**：必须 `CsrfService::input()` + `CsrfService::check()`。
- ❌ **禁止非 pdo_mysql 驱动**。
- ❌ **禁止用 `window.__xxxData` 预注入全局状态**：状态放 DOM（`data-*`、hidden input）。
- ❌ **禁止 htmx 2.x 旧事件名**：必须用 4.x 冒号格式（`htmx:config:request` / `htmx:after:swap`），禁止 `htmx:configRequest` / `htmx:afterSettle` 等 2.x 旧名（⚠️ 已违反 1 次，影响 8 个核心页面）。
- ❌ **禁止用 `REQUEST_URI` / `PHP_SELF` 检测 Admin 路径**：必须用 `SCRIPT_NAME`（⚠️ 已违反 1 次）。
- ❌ **禁止密码 / token / API key 用 `param()` 默认 `htmlspecialchars`**：必须传第 3 参 `FALSE` 关闭转义（⚠️ 已违反 1 次）。
- ❌ **禁止缓存驱动配置变更不触发实例重建**：host / port / password / database 任一变更必须重建实例，不复用旧实例（⚠️ 已违反 1 次）。
- ❌ **禁止 `session_redis` / `cache.redis` 用 `auth` / `db` 字段名**：必须用 `password` / `database`（⚠️ 已违反 1 次）。

## 核心机制（先记住）

1. **插件是编译时合并的，不是运行时分发的。** 你写的 `plugin/foo/hook/bar.htm` 内容，会在 `_include()` 编译时被物理拼进 `bar.htm` 对应的 `// hook bar.htm` 标记位置，结果缓存到 `tmp/`。改了 hook 文件要清 `tmp/`。

2. **Hook 靠文件名匹配，没有注册函数。** 把文件放到 `plugin/<你的插件>/hook/<hook名>`，文件名（含扩展名）必须和 hook 点标记里的名字**一模一样**。PHP hook 文件以 `<?php exit;` 开头（防直接访问，编译器会剥掉）。

3. **模型分三层命名：`__` 原始 / 单 `_` 业务 / `_format` 装饰。** 插件应调用单下划线业务层（如 `thread_create()`）而不是原始层（`thread__create()`），业务层会自动处理缓存、计数、通知、hook。

4. **插件状态（installed/enable）唯一权威源为 db `bbs_plugin` 表，conf.json 禁止包含这两个字段，代码层任何情况下都不读。** `plugin_init()` 在 `xn_json_decode(conf.json)` 后立即 `unset` 丢弃；`plugin_paths_enabled()` / `PluginScanner::scanSingleByDir()` 只读 db，db 异常/无记录时默认 false，**不回退 conf.json**。

---

## 约定：命名前缀隔离

所有全局符号都要带插件前缀，避免冲突。参考 `xnx_tag`、`xnx_checkin`：

| 资源 | 前缀示例 |
|---|---|
| 数据库表 | `xnx_tag`、`xnx_thread_tag`（用 `{$tablepre}xnx_tag` 建表） |
| 语言键 | `xnx_tag_*`（`$lang['xnx_tag_placeholder']`） |
| 全局变量 | `$xnx_tag_*` |
| JS 全局 | `__xnxTag*` |
| setting 键 | `setting_get('xnx_tag')` |

---

## 反馈

发现手册和源码不一致时，以**源码为准**，并修正手册。手册不是规范源头，`AGENTS.md` + 源码才是。
