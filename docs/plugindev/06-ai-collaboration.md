# 06 AI 协作规范与避坑清单

> 本文档面向 AI 编码助手和开发者。**写任何 Xiuno 插件代码前先过此清单。**
> 基于 `AGENTS.md`、`lib/PluginScanner.php`、`lib/PluginScannerRules.php` 核对。

---

## 一、硬规则（违反 = 功能异常或被扫描器拦截）

### ❌ 绝对禁止

| 规则 | 说明 | 后果 |
|---|---|---|
| **禁止 jQuery** | 新代码不准出现 `$(...)`、`.on()`、`.ajax()` 等 | 扫描器 fatal，后续迁移困难 |
| **禁止 Alpine.js** | 不准出现 `x-data`、`x-show`、`x-bind`、`x-text`、`x-on`、`x-model`、`x-if`、`x-for`、`x-cloak` | 扫描器 fatal |
| **禁止 idiomorph / alpine-morph** | 不准出现 `hx-swap="morph:idom"` 或 `alpine-morph` 扩展 | htmx 4 内置 morph |
| **禁止 `htmlspecialchars` 裸写** | 必须用 `esc_html()` / `esc_attr()` / `esc_js()` | XSS 风险 |
| **禁止 POST 表单不带 CSRF** | 所有 `<form method="post">` 必须含 `CsrfService::input()` | CSRF 漏洞 |
| **禁止 POST 处理不校验 CSRF** | 所有 POST 处理函数开头必须 `CsrfService::check()` | CSRF 漏洞 |
| **禁止裸 `include`** | 源文件必须走 `_include()`，否则 hook 不生效 | 插件失效 |
| **禁止覆盖核心路径** | `plugin_find_overwrite()` 有 `protected_paths` 白名单：`conf/`、`xiunophp/`、`lib/`、`admin/`、`api/`、`cli/`、`tool/`、`install/`、`log/`、`tmp/`、`upload/`、`index.php`、`model.inc.php`、`index.inc.php` | 静默跳过 + 记日志 `plugin_overwrite_error`，开发者误以为覆盖生效 |
| **禁止 `window.__xxxData`** | 状态放 DOM（`data-*`、hidden input），不放全局 JS 变量 | 违反 htmx 架构 |
| **禁止非 pdo_mysql 驱动** | 只用 `pdo_mysql` | 架构约束 |
| **禁止用 `db_find('user', ...)` 取用户信息后直接取 `username` 字段显示** | `db_find` 不调用 `user_format()`，返回数据**不含** `display_name` 字段。`$u['display_name'] ?? $u['username']` fallback 写法无效（`display_name` 键根本不存在，始终落到 `username` 登录名）。必须改用 `user_find_by_uids('1,2,3')` / `user_read()` / `user_read_cache()` 等核心函数（自动 format），模板取 `$user['display_name']` | 显示登录用户名而非昵称，已违反 1 次影响 5 个插件 |
| **禁止改核心 `model/*.func.php` 后不清 `tmp/model.min.php`** | 生产环境走 `model.min.php` 合并加载，只在不存在时生成；改了核心函数必须删 `tmp/model.min.php` 让核心重编译，否则修改不生效（已违反 2 次，最高频违规）。详见 [01-architecture.md](01-architecture.md)「model.min.php 合并加载机制」 | 修改不生效，调试误判 |
| **禁止密码/token/API key 用 `param()` 默认 `htmlspecialchars`** | `param($key, $defval, $htmlspecialchars=TRUE)` 第 3 参默认 TRUE 会转义特殊字符，导致密码比对失败；敏感字段必须传第 3 参 `FALSE`（已违反 1 次）。详见 [04-api-cheatsheet.md](04-api-cheatsheet.md) 第 1 节「请求输入」 | 密码比对失败，登录/找回密码失效 |

### ✅ 必须做

| 规则 | 正确做法 |
|---|---|
| **新代码用 htmx 4 属性** | `hx-get`/`hx-post`/`hx-target`/`hx-optimistic`/`hx-live`/`hx-on` |
| **JS 交互用 `XN.*` API** | `XN.toast()`、`XN.ajax()`、`XN.confirm()`、`XN.alert()` |
| **UI 用 Bootstrap 5** | `.container` 居中，Light Theme，Tabler Icons |
| **所有输出用 `esc_*`** | `esc_html()`、`esc_attr()`、`esc_js()`（来自 `lib/EscapeService.php`） |
| **模板用 `_include()`** | `include _include(APP_PATH.'view/htm/xxx.htm');` |
| **改 hook/conf.json 后清 `tmp/`** | `plugin_clear_tmp_dir()` 或手动删除 `tmp/` 目录内容 |
| **命名带插件前缀** | 表/变量/语言键/JS 全局/setting 键 全部前缀隔离 |
| **PHP hook 以 `<?php exit;` 开头** | 编译器剥掉，防止直接访问 |
| **后台页不用 htmx** | 后台 `admin/` 模板只用原生 JS + Bootstrap |
| **获取用户信息用于显示时用核心函数** | `user_find_by_uids('1,2,3')` / `user_read($uid)` / `user_read_cache($uid)` 自动调用 `user_format()` 生成 `display_name`（昵称优先 fallback username）。模板显示用户名统一取 `$user['display_name']`，禁止直接取 `$user['username']`（登录名） |
| **htmx 事件名用 4.x 冒号格式** | 用 `htmx:config:request` / `htmx:after:swap` 等 4.x 冒号格式，禁止 `htmx:configRequest` / `htmx:afterSettle` 等 2.x 旧名，用旧名静默失效（已违反 1 次，影响 8 个核心页面）。详见 [05-frontend-security.md](05-frontend-security.md) 第 3 节「htmx 4 事件名约定」 |
| **Admin 路径检测用 `SCRIPT_NAME`** | 检测当前是否在 admin 路径用 `$_SERVER['SCRIPT_NAME']`（而非 `REQUEST_URI` / `PHP_SELF`），避免被伪静态 URL 误导（已违反 1 次） |

---

## 二、命名前缀隔离（防冲突）

所有符号都带插件前缀，参考 `xnx_tag`、`xnx_checkin` 模式：

```php
// 数据库表（建表时加 $tablepre）
"CREATE TABLE IF NOT EXISTS {$db->tablepre}my_plugin (...) ENGINE=InnoDB"

// 语言键
$lang['my_plugin_title'] = '标题';
// 用法：lang('my_plugin_title')

// PHP 全局变量
$my_plugin_settings = setting_get('my_plugin');

// JS 全局（避免冲突，用插件前缀）
var __myPluginConfig = <?php echo json_encode($settings);?>;

// Setting 键
setting_get('my_plugin');    // 返回整个设置对象
setting_set('my_plugin', $settings);

// CSS class
.my-plugin-badge { ... }
.my-plugin-container { ... }
```

---

## 三、hook 文件常见坑

| 坑 | 说明 | 正确做法 |
|---|---|---|
| **扩展名写错** | `thread_subject_after.php` ≠ `thread_subject_after.htm` | 扩展名必须和源标记**一模一样** |
| **忘记 `<?php exit;`** | PHP hook 文件被人直接 URL 访问触发 | PHP hook 以 `<?php exit;` 开头 |
| **改了 hook 文件不清缓存** | 页面没变化，以为没生效 | 改完清 `tmp/` |
| **hook 名拼错** | 编译时找不到匹配，静默跳过 | 对照 [03-hooks-catalog.md](03-hooks-catalog.md) 核对 |
| **lang hook 格式错** | 不匹配 `$lang['key'] = value;` 的行被跳过 | 每行严格 `$lang['my_prefix_xxx'] = 'xxx';` |
| **model_inc_file 忘了逗号** | 拼进数组时语法错误 | 每行 `APP_PATH.'plugin/...',` 以逗号结尾 |

### 运行时 hook 分发：`plugin_hook()`

除编译期内联（`plugin_compile_srcfile_callback` 将 hook 文件合并到源文件）外，还提供运行时分发机制 `plugin_hook()`（`model/plugin.func.php` 第 742 行）：

```php
// 运行时触发 hook，带错误隔离
plugin_hook('my_custom_event.php', $data);
```

- **带 try/catch 错误隔离**：单个 hook 抛出 `Throwable` 不会终止其他 hook 和主流程，错误记录到 `plugin_error` 日志。
- **仅支持 `.php` hook**：`.htm` 模板 hook 走编译期内联。
- **按 `hooks_rank` 降序**：与编译期排序一致。
- **兼容 `xn_hook()`**：旧版 `xn_hook()` 已被 `plugin_hook()` 替代，调用时自动补 `.php` 后缀，仅向后兼容保留。
- **适用场景**：需要运行时动态触发的事件（非标准 hook 点的自定义事件），或需要错误隔离的场景。

---

## 四、数据库常见坑

| 坑 | 说明 | 正确做法 |
|---|---|---|
| **建表忘加 `$tablepre`** | 表名和核心表不一致 | `{$db->tablepre}my_plugin` |
| **用 PDO bindValue** | Xiuno 不走 PDO 预编译 | 用**条件数组**语法（见 [04-api-cheatsheet.md](04-api-cheatsheet.md)） |
| **`user_update()` 改密码** | 受保护字段被剥离 | 用 `user_change_password()` / `user_change_group()` |
| **直接调 `__` 层** | 跳过缓存/计数/通知 | 调单下划线业务层（`thread_create` 而非 `thread__create`） |
| **install.php 没有幂等保护** | 重复安装报错 | `CREATE TABLE IF NOT EXISTS` / `IF NOT EXISTS` 判断 |
| **uninstall.php 忘删 setting** | 卸载后残留 KV 数据 | `kv_delete('my_plugin')` 或 `setting_delete` |
| **数据库结构变更写进 install.php** | 已安装用户无法迁移 | 走 `upgrade.php` 幂等迁移（见下方） |

### 数据库升级机制：`upgrade.php`

数据库结构变更（加字段/改字段）走 `upgrade.php` 幂等迁移，不在 `install.php` 或 `setting.php` 加字段自愈代码：

1. **检测逻辑**（`admin/route/plugin.php` 第 44-55 行）：对比 `conf.json.version` 与数据库 `bbs_plugin.version`，不一致且存在 `upgrade.php` 时标记 `need_upgrade`，后台显示升级按钮。
2. **执行流程**（`admin/route/plugin.php` 第 339-368 行 `upgrade` 动作）：用户点升级 → `plugin_install()` 同步 `db.version` → 执行 `upgrade.php` 迁移脚本。
3. **版本同步**：`plugin_db_set_version()`（`model/plugin.func.php` 第 672 行）将 `conf.json.version` 写入 `bbs_plugin.version`，升级后两者一致。
4. **幂等要求**：`upgrade.php` 中字段迁移用 `SHOW COLUMNS` + `ALTER TABLE ADD COLUMN`（可重复执行），递增 `conf.json.version` 版本号触发升级提示。

---

## 五、前端常见坑

| 坑 | 说明 | 正确做法 |
|---|---|---|
| **用 `$()` 写新代码** | jQuery 虽在页面上但不该用 | htmx 属性 + `XN.*` API |
| **用 `onclick="..."` 写复杂逻辑** | 难维护 | htmx 属性 或 `hx-on="click: ..."` |
| **CSS 路径写绝对路径** | `APP_PATH` 是 PHP 变量不是 URL | `plugin/my_plugin/view/css/x.css`（相对路径） |
| **忽略暗色模式** | 自定义颜色在暗色主题下异常 | 用 `[data-bs-theme="dark"]` 覆盖 |
| **后台模板加 htmx** | 后台不用 htmx | 只用原生 JS + Bootstrap |
| **JS 全局变量名冲突** | `var data = ...` 和别人冲突 | `var __myPluginData = ...` |

---

## 六、扫描器规则（PluginScanner）

安装前自动运行（`lib/PluginScanner.php` + `lib/PluginScannerRules.php`），按严重性分级。规则定义见 `PluginScannerRules::getRules()`，严重级别见 `getSeverityLevels()`，不可跳过分类见 `getForceCategories()`。

### Fatal（阻止安装，`?force=1` 不可跳过）

以下分类严重级别为 `fatal`，且全部在 `getForceCategories()` 中（即使带 `?force=1` 也阻止安装）：

| 分类 | 拦截内容 |
|---|---|
| **php_deprecated_functions** | PHP 7.x 已移除函数：`mysql_*`、`each()`、`create_function()`、`split()`/`spliti()`、`ereg*()`、`call_user_method()` |
| **php8_syntax** | PHP 8 不兼容语法：`&new`、`preg_replace` `/e` 修饰符 |
| **curly_brace_access** | 花括号数组访问 `$arr{0}`（PHP 8 移除，改用 `$arr[0]`） |
| **http_post_vars** | `HTTP_POST_VARS`/`HTTP_GET_VARS`/`HTTP_SESSION_VARS`（旧式超全局） |
| **dangerous_functions** | 危险函数：`eval`/`assert`/`system`/`exec`/`passthru`/`shell_exec`/`popen`/`proc_open`/`pcntl_exec` |
| **php8_deprecated** | PHP 8.0+ 废弃：`get_magic_quotes_gpc`、`utf8_encode`/`utf8_decode`、`money_format`、`is_resource`（对 PDO 返回 false） |
| **php_comment_close_tag** | 单行注释 `//` 或 `#` 中包含 `?>`（触发 headers already sent） |
| **service_undefined_var** | Service 类 SQL 拼接使用未定义变量 `$tableName`/`$tablePrefix`（应用 `$this->tablepre`） |
| **heredoc_php_tag** | HEREDOC 块内含 `<?php` 标签（应用 `{$var}` 语法） |
| **hook_htm_header** | `.htm` hook 文件以 `<?php exit;` 开头（会白屏，只能用 `<?php` 开头） |
| **app_path_in_url** | `<script>`/`<link>` 的 `src`/`href` 用 `APP_PATH`（浏览器无法访问，应用 `$conf['view_url']`） |

### Error（阻止安装，`?force=1` 不可跳过）

| 分类 | 拦截内容 |
|---|---|
| **conf_version** | `bbs_version` 兼容性校验：必须两位制（X.Y，如 "1.0"），且不能高于当前核心主次版本（`XIUNOX_VERSION` 取前两段，如 1.0.9 → 1.0）。语义：声明兼容核心 X.Y.0-X.Y.x 分支。当前核心版本可通过 `version.php` 中的 `XIUNOX_VERSION` 查看。此分类严重级别为 `error`，在 `getForceCategories()` 中，`?force=1` 不可跳过。格式不符或高于核心版本均阻止安装。 |

### Warning（提示，可跳过）

| 分类 | 拦截内容 |
|---|---|
| **plugin_version_format** | 插件 `version` 必须三位制（X.Y.Z，如 "1.0.0"）。不符合给 warning（可跳过） |
| **permission_security** | `user_update()` 修改 `password`/`gid`/`salt`/`password_hash`（应用 `user_change_password()`/`user_change_group()`） |
| **bs_js_api** | jQuery 调用 Bootstrap 插件：`$().modal()`/`.dropdown()`/`.tooltip()`/`.collapse()` 等 |
| **frontend_md5** | 前端 MD5 哈希 `hex_md5()`/`md5_hex()`（密码应明文提交由服务端 `password_md5()` 处理） |
| **md5js_global_load** | 全局加载 `md5.js`（`<script src="*md5*.js">`） |
| **password_update_api** | `user_update()` 修改 password 字段（找回密码应用 `user__update()`） |
| **db_charset** | 数据库字符集 `utf8`（非 `utf8mb4`） |
| **raw_htmlspecialchars** | 裸 `htmlspecialchars()`（应用 `esc_html()`/`esc_attr()`/`esc_js()`） |
| **bs_tab_navigation** | 外层导航误用 `data-bs-toggle="tab"` + `href="*.htm"`（应用 `<a>` 链接） |
| **db_find_col_string** | `db_find_one()` 第 4 参数传字符串（应传数组） |
| **install_non_idempotent** | `CREATE TABLE` 缺少 `IF NOT EXISTS` |
| **capabilities_format** | 扫描器**强制**校验 `capabilities` 字段格式（要求 `lowercase.dots` 字符串数组，如 `["thread.post.create"]`），不符给 warning；此为已生效规则，非"未来"功能 |
| **php_superglobal_output** | 直接 `echo`/`print` 超全局变量 `$_GET`/`$_POST` 等（反射型 XSS） |
| **js_eval_call** | JS `eval()` 调用（代码注入风险） |
| **js_dom_xss** | `document.write()`/`innerHTML =`/`outerHTML =`/`insertAdjacentHTML()`（DOM XSS） |
| **jquery_html_xss** | jQuery `.html()` 调用（XSS 风险） |

### Medium（兼容建议）

| 分类 | 拦截内容 |
|---|---|
| **bs4_classes** | BS4 旧类名：`ml-`→`ms-`、`mr-`→`me-`、`form-group`→`mb-3`、`btn-block`→`w-100` 等 |
| **bs4_data_attrs** | BS4 旧 data 属性：`data-toggle`→`data-bs-toggle`、`data-dismiss`→`data-bs-dismiss` 等 |
| **bs3_classes** | BS3 旧类名：`panel-*`→`card-*`、`well`、`glyphicon`、`pull-left`→`float-start`、`col-xs-`→`col-` 等 |
| **fontello_icons** | Fontello 旧图标：`icon-lock`→`ti-lock`、`icon-home`→`ti-home` 等 |
| **icon_libraries** | 非 Tabler Icons：`fa-*`（Font Awesome）、`bi-*`（Bootstrap Icons）、`glyphicon glyphicon-*` |
| **jquery_usage** | jQuery 使用：`$.ajax()`、`$.each()`、`$.fn.`、`jQuery()`、`$(document).ready` 等 |

### Info（仅提示）

| 分类 | 拦截内容 |
|---|---|
| **missing_csrf** | POST 表单缺少 `CsrfService::input()` 或 `csrf_token`（注意：此分类是 `info` 级，不阻止安装） |
| **direct_db** | 原始 SQL：`db_exec()`/`db_sql_find()`/`db_sql_find_one()`（仅 `model/` 目录检测） |

> **关于 `?force=1`**：带 `?force=1` 可跳过 fatal/error 之外的拦截（warning/medium/info），但 `getForceCategories()` 中的分类（所有 fatal + `conf_version`）即使带 `?force=1` 也阻止安装。

---

## 七、交付前检查表

每次完成插件开发后，逐项核对：

### 结构

- [ ] `conf.json` 所有必填字段完整
- [ ] `install.php` 建表有 `IF NOT EXISTS` / `CREATE TABLE IF NOT EXISTS`
- [ ] `uninstall.php` 删表 + 删 KV/setting
- [ ] 数据库结构变更走 `upgrade.php` 幂等迁移（`SHOW COLUMNS` + `ALTER TABLE`），递增 `conf.json.version`
- [ ] 所有 PHP hook 以 `<?php exit;` 开头
- [ ] hook 文件名（含扩展名）与源标记完全匹配
- [ ] `model_inc_file.php` 每行以逗号结尾

### 安全

- [ ] 所有 `<form method="post">` 包含 `CsrfService::input()`
- [ ] 所有 POST 处理以 `CsrfService::check()` 开头
- [ ] 所有输出使用 `esc_html()` / `esc_attr()` / `esc_js()`
- [ ] `setting.php` / `install.php` / `uninstall.php` 有 `!defined('DEBUG') AND exit('Access Denied');`（文案可自定义，如 `exit('Forbidden')`，功能一致）
- [ ] `setting.php` 有权限检查（`$gid != 1 && $gid != 2`）
- [ ] `.htm` hook 文件不含危险函数（`_include_scan_dangerous_php()` 在编译期检测 `eval`/`assert`/`system`/`exec`/`shell_exec`/`passthru`/`proc_open`/`popen`/`create_function` 及 `preg_replace /e`，命中 `die()` 并记日志 `template_security_error`）

### 前端

- [ ] 无 jQuery 代码（`$()`、`.on()`、`.ajax()`）
- [ ] 无 Alpine.js 属性（`x-data`、`x-show` 等）
- [ ] 交互使用 htmx 4 属性或 `XN.*` API
- [ ] 图标使用 Tabler Icons（`ti ti-xxx`）
- [ ] CSS/JS 路径使用相对路径 `plugin/<dir>/...`
- [ ] 暗色模式支持（如需）

### 命名

- [ ] 数据库表有插件前缀（`{$tablepre}my_plugin_*`）
- [ ] 语言键有前缀（`my_plugin_*`）
- [ ] PHP 全局变量有前缀（`$my_plugin_*`）
- [ ] JS 全局变量有前缀（`__myPlugin*`）
- [ ] Setting 键有前缀（`setting_get('my_plugin')`）
- [ ] CSS class 有前缀（`.my-plugin-*`）

### 功能

- [ ] 改 hook 文件后清 `tmp/` 缓存
- [ ] 帖子删除有级联清理（如有关联数据）
- [ ] `message()` 返回正确（0=成功，非0=错误）
- [ ] 分页正确（`pagination()` + `db_find()` 参数一致）
- [ ] **获取用户信息用于显示用 `user_find_by_uids()` / `user_read()` / `user_read_cache()` 等核心函数（自动生成 `display_name`），禁止用 `db_find('user', ...)` 绕过核心层后直接取 `username` 字段；模板显示用户名统一取 `display_name` 字段**

---

## 八、调试流程

1. **hook 不生效** → 清 `tmp/` → 检查文件名（含扩展名）→ 检查插件是否启用
2. **页面白屏** → 检查 PHP 语法（hook 拼接后语法错误）→ 开 DEBUG 查日志
3. **CSRF 报错** → 检查表单是否含 `CsrfService::input()` → htmx 检查 `X-CSRF-TOKEN` header
4. **数据没保存** → 检查条件数组语法 → 检查表名（是否加了 `$tablepre`）
5. **前端样式异常** → 硬刷新（Ctrl+F5）→ 检查 CSS 加载路径 → 检查暗色模式
6. **install.php 不执行** → 确认文件名是 `install.php`（不是 `Install.php`）→ 确认 `DEBUG` 常量可达

---

## 九、给 AI 的特别提醒

1. **先读手册再写代码**：本目录下的 `.md` 文件 + `AGENTS.md` 是权威规范。
2. **对照源码**：不确定时，读对应的源文件确认（`model/*.func.php`、`xiunophp/*.func.php`、`lib/*.php`）。
3. **hook 选择要精准**：参照 [03-hooks-catalog.md](03-hooks-catalog.md) 的决策树选对 hook 点。
4. **API 签名要精确**：参照 [04-api-cheatsheet.md](04-api-cheatsheet.md)，不要臆造参数。
5. **命名前缀是硬要求**：不是建议，是避免冲突的唯一方式。
6. **CSRF 不是可选的**：每个 POST 表单 + 每个 POST 处理函数都必须有。
7. **`esc_html` 不是可选的**：每个输出到 HTML 的变量都必须转义。
8. **不要用 `__` 层**：除非你清楚自己在做什么，否则永远调单下划线业务层。
9. **先写 conf.json → 再写 install → 再写 Service → 再写 hook → 最后写 setting/view**。
10. **完成后跑一遍检查表**：[交付前检查表](#七交付前检查表)。
