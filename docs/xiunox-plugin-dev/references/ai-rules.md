# AI 规则清单（生成代码时强制对照）

> 源自 `AGENTS.md` + `lib/PluginScanner.php` + `lib/PluginScannerRules.php`

---

## 🔴 绝对禁止（扫描器 Fatal）

| 规则 | 错误示例 | 正确做法 |
|---|---|---|
| 禁 jQuery | `$('.btn').on('click', ...)` | `hx-on="click: ..."` 或原生 JS |
| 禁 Alpine.js | `<div x-data="{ open: false }">` | htmx 属性 + DOM 状态 |
| 禁 idiomorph | `hx-swap="morph:idom"` | `hx-swap="innerMorph"`（htmx 4 内置） |
| 禁裸 htmlspecialchars | `htmlspecialchars($var)` | `esc_html($var)` |
| 禁 POST 无 CSRF | `<form method="post">` 无 hidden input | `<?php echo CsrfService::input();?>` |
| 禁 POST 处理无校验 | POST handler 不调 check | `CsrfService::check();` 在最前 |

## 🟡 高优先级

| 规则 | 说明 |
|---|---|
| `include` → `_include()` | 裸 include 绕过 hook 系统 |
| 不用 `window.__xxxData` | 状态放 DOM `data-*` / hidden input |
| 不用 `__` 原始模型层 | 调单 `_` 业务层（`thread_create` 非 `thread__create`） |
| 不用 `user_update` 改密码 | 必须用 `user_change_password()` |
| 不用 PDO bindValue | 用条件数组语法 |
| 后台不用 htmx | admin/ 模板只用原生 JS + Bootstrap |
| 不跳过 `esc_*` | 每个输出到 HTML 的变量都转义 |
| 不硬编码 URL 后缀 | 禁止 `/thread-{tid}.htm`、`$site_url.'/xxx.htm'` 等，必须用 `url()` 函数适配伪静态（url_rewrite_on 0~5 六种模式） |

## 🟢 必须做

| 规则 | 做法 |
|---|---|
| CSS 注入 | `header_link_after.htm`（全局）或模板内（局部） |
| JS 注入 | `footer_js_after.htm`（全局）或模板内（局部） |
| JS API | `XN.toast()` / `XN.ajax()` / `XN.confirm()` / `XN.alert()` |
| 图标 | Tabler Icons `<i class="ti ti-xxx"></i>` |
| 链接 | `url("route-action-param")`（禁止硬编码 `.htm`/`.html` 后缀） |
| 缓存刷新/跳转 URL | `$site_url . url("xxx")`（禁止 `$site_url . '/xxx.htm'`） |
| PHP hook | `<?php exit;` 开头 |
| hook 文件名 | 含扩展名，和源标记一模一样 |
| 建表 | `CREATE TABLE IF NOT EXISTS {$db->tablepre}xxx` |
| 卸载 | `DROP TABLE IF EXISTS` + `kv_delete()` |
| setting | `setting_get('my_plugin')` / `setting_set()` |
| 语言键 | `$lang['my_plugin_xxx']` |
| JS 全局 | `var __myPluginXxx = ...` |
| CSS class | `.my-plugin-xxx` |
| 删帖级联 | `model_thread_delete_end.php` 清理关联数据 |
| 列表全覆盖 | inc + masonry + timeline + card（`_subject_after`） |

## 📋 检查流程

生成代码后逐项自检：

```
□ 无 $() / .on() / .ajax() / .bind() 等jQuery
□ 无 x-data / x-show / x-bind / x-model / x-on / x-text / x-if 等Alpine
□ 无 window.__xxxData
□ 所有 form[method=post] 有 CsrfService::input()
□ 所有 POST handler 首行 CsrfService::check()
□ 所有 HTML 输出用 esc_html() / esc_attr()
□ 所有 JS 字符串输出用 esc_js()
□ 所有 include 是 _include()
□ 模型调用是单下划线（非 __）
□ hook 文件名含扩展名匹配
□ PHP hook 有 <?php exit;
□ 命名全带插件前缀
□ 建表有 IF NOT EXISTS
□ 卸载删表+删KV
□ setting.php 有权限检查+CSRF
□ 删帖有级联清理
□ 暗色模式（如需）
□ 无硬编码 URL 后缀（.htm/.html），所有 URL 用 url() 函数
□ 缓存刷新/跳转用 $site_url . url("xxx") 而非硬拼接
```
