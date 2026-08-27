# AI 协作规则速查

> 本文件为 AI 协作规则速查，详细说明见 [plugindev/06-ai-collaboration.md](manual/06-ai-collaboration.md)

## 目录

- [一、硬规则：禁止项](#一硬规则禁止项)
- [二、硬规则：必须项](#二硬规则必须项)
- [三、命名前缀隔离规范](#三命名前缀隔离规范)
- [四、hook 文件常见坑](#四hook-文件常见坑)
- [五、数据库常见坑](#五数据库常见坑)
- [六、前端常见坑](#六前端常见坑)
- [七、并发安全与积分防刷](#七并发安全与积分防刷)
- [八、扫描器规则分级](#八扫描器规则分级pluginscanner)
- [九、交付前检查表](#九交付前检查表)

---

## 一、硬规则：禁止项

| 规则 | 后果 |
|---|---|
| 禁止 jQuery（`$()`、`.on()`、`.ajax()`、`$.fn.*`）—— 已于 2026-07-24 系统性移除 | 扫描器 fatal；关键修复页面依赖 `$` 会陷入「网站坏→修复页也坏」死循环 |
| 禁止 Alpine.js（`x-data`/`x-show`/`x-bind`/`x-text`/`x-on`/`x-model`/`x-if`/`x-for`/`x-cloak`） | 扫描器 fatal |
| 禁止 `idiomorph` / `alpine-morph`（`hx-swap="morph:idom"`） | htmx 4 内置 morph |
| 禁止裸 `htmlspecialchars`，必须用 `esc_html()`/`esc_attr()`/`esc_js()` | XSS 风险 |
| 禁止 POST 表单不带 CSRF（必须含 `CsrfService::input()`） | CSRF 漏洞 |
| 禁止 POST 处理不校验 CSRF（开头必须 `CsrfService::check()`） | CSRF 漏洞 |
| 禁止裸 `include`（必须走 `_include()`，否则 hook 不生效） | 插件失效 |
| 禁止覆盖核心路径（`conf/`、`xiunophp/`、`lib/`、`admin/`、`api/`、`cli/`、`tool/`、`install/`、`log/`、`tmp/`、`upload/`、`index.php`、`model.inc.php`、`index.inc.php`） | 静默跳过 + 记 `plugin_overwrite_error` |
| 禁止 `window.__xxxData` 全局状态 | 违反 htmx 架构，状态放 DOM（`data-*`/hidden input） |
| 禁止非 `pdo_mysql` 驱动 | 架构约束 |
| 禁止 `db_find('user', ...)` 后直接取 `username` 字段显示（不含 `display_name`，fallback 写法无效） | 显示登录名而非昵称 |
| 禁止密码/token/API key 用 `param()` 默认转义（第 3 参必须传 `FALSE`） | 密码比对失败，登录/找回密码失效 |
| 禁止 hook 文件用 `return`（会从宿主函数/路由/模板返回跳过后续逻辑）—— 终止性操作用 `exit` + `if` 包裹 | 已违反 5 次，影响多个插件 |
| 禁止 `overwrites_rank` 覆盖模板放在 `plugin/<dir>/view/htm/`（必须放 `overwrite/` 子目录） | 覆盖不生效 |
| 禁止 `esc_textarea()`（项目中不存在，用 `esc_html()`） | 后台设置页白屏 |
| **禁止积分/库存/计数器操作用「先读后写」无 CAS**（已违反 6 次，导致重放刷积分、库存超发、计数器重复递增） | 重放攻击、并发刷积分 |
| **禁止 `CreditsService::add()/sub()` 不检查返回值**（add 可能因防刷限制/钩子阻止失败） | 积分未入账但记录已发，回滚时误扣 |
| **禁止多步积分发放部分失败时不回滚已成功步骤** | 用户重复领取导致重复发积分 |
| **禁止批量 UPDATE 无 CAS 条件**（会对 REJECTED 状态记录重复发积分） | 批量操作重放刷积分 |
| **禁止每日次数限制检查与实际操作非原子**（TOCTOU 漏洞，并发绕过限频） | 并发刷次数 |

## 二、硬规则：必须项

| 规则 | 正确做法 |
|---|---|
| 新代码用 htmx 4 属性 | `hx-get`/`hx-post`/`hx-target`/`hx-optimistic`/`hx-live`/`hx-on` |
| JS 交互用 `XN.*` API 或原生 JS | 关键修复页面（在线升级/数据库升级/后台登录/系统工具）必须原生 `fetch`+`confirm`+`addEventListener`，不依赖 `xiuno-modern.js` |
| UI 用 Bootstrap 5 + Tabler Icons | `.container` 居中，Light Theme，`ti ti-xxx` |
| 所有输出用 `esc_*` | `esc_html()`/`esc_attr()`/`esc_js()`（来自 `lib/EscapeService.php`） |
| 模板用 `_include()` | `include _include(APP_PATH.'view/htm/xxx.htm');` |
| 改 hook/conf.json 后清 `tmp/` | `plugin_clear_tmp_dir()` 或手动删除；含 OPcache 时调 `CacheService::clearByType(['data','opcache'])` |
| 命名带插件前缀 | 表/变量/语言键/JS 全局/setting 键/CSS class 全部前缀隔离 |
| PHP hook 以 `<?php exit;` 开头 | 编译器剥掉，防直接访问（`.htm` hook 只用 `<?php`，不能 `exit`） |
| 后台页不用 htmx | `admin/` 模板只用原生 JS + Bootstrap |
| 取用户信息用于显示用核心函数 | `user_find_by_uids('1,2,3')`/`user_read($uid)`/`user_read_cache($uid)`（自动 `user_format()` 生成 `display_name`）；模板取 `$user['display_name']` |
| htmx 事件名用 4.x 冒号格式 | `htmx:config:request`/`htmx:after:swap` 等，禁止 `htmx:configRequest`/`htmx:afterSettle`（2.x 旧名静默失效） |
| Admin 路径检测用 `$_SERVER['SCRIPT_NAME']` | 禁止用 `REQUEST_URI`/`PHP_SELF`（被伪静态 URL 误导） |
| 从前台生成 admin URL 用 `admin_url()` | `url()`/`admin_plugin_setting_url()` 前台调用不带 `admin/` 前缀 |
| `db_*` 函数传表名禁止含前缀 | 内部已拼 `$d->tablepre . $table`，传 `'xnx_oauth_bind'` 而非 `'bbs_xnx_oauth_bind'`（例外：`install.php`/`uninstall.php`/`upgrade.php` 原生 SQL 需手动拼 `$db->tablepre`） |
| API 层写操作调核心 `post_create()`/`post_update()`/`thread_create()` | 禁止用 `PostService::createPost()` 等薄封装（跳过 `message_fmt`/计数/缓存失效） |
| **邮件发送统一用 `xn_send_mail()`** | 所有场景（验证码、通知、登录提醒等）同步发送，立即拿到返回值（TRUE/错误字符串）。`xn_send_mail_async()` 已于 2026-08-05 移除（伪异步且吞错误） |
| **状态转换用 CAS（Compare-And-Swap）** | `UPDATE ... WHERE status=旧值`，检查 `affected_rows`；批量操作用逐条 CAS。详见 [security-patterns.md](security-patterns.md) |
| **业务实体唯一性用 UNIQUE 约束** | 如 `UNIQUE KEY invitee_uid`，INSERT 用 `INSERT IGNORE` 兜底并发冲突 |
| **检查 `CreditsService::add()/sub()` 返回值** | 返回 `array('ok'=>bool)`，add 失败时不记录 reward，回滚时只回滚实际入账的积分 |
| **多步积分发放部分失败回滚已成功步骤** | 记录 credited 标志，失败时 sub 回滚，sub 失败记 `xn_log('error')` |
| **每日次数限制用 GET_LOCK 串行化** | CAS 无法覆盖的 TOCTOU 场景用 `GET_LOCK('插件_操作_uid', 5)`，finally 释放 |
| **敏感接口路由层加 IP+uid 限频** | 抽奖/决斗/发奖等接口在 `route/*.php` 加 cache 实现的频率限制 |

---

## 三、命名前缀隔离规范

参考 `xnx_tag`、`xnx_checkin` 模式，所有符号带插件前缀：

| 符号类型 | 示例 |
|---|---|
| 数据库表 | `{$db->tablepre}my_plugin` |
| 语言键 | `$lang['my_plugin_title'] = '标题';` |
| PHP 全局变量 | `$my_plugin_settings` |
| JS 全局 | `var __myPluginConfig = ...;` |
| Setting 键 | `setting_get('my_plugin')` / `setting_set('my_plugin', $settings)` |
| CSS class | `.my-plugin-badge` |
| **hook 内局部变量** | `$_myplugin_settings`（带下划线前缀，避免污染宿主变量） |

### hook 内变量污染规范

编译期内联 hook（源码标记 `// hook xxx.php` 或 `<!--{hook xxx.htm}-->`）会被直接拼接进宿主函数/模板的 PHP 代码中，**共享宿主作用域**。hook 内赋值的局部变量会泄漏给宿主后续代码，可能覆盖宿主原有变量。

**禁止**：在 hook 中使用通用变量名赋值，如 `$settings`、`$conf`、`$user`、`$thread` 等。

**必须**：hook 内局部变量加插件前缀，如 `$_hidden_settings`、`$_friendlink_links`。

**真实案例**：xnx_hidden 的 `header_link_after.htm` hook 执行 `$settings = HiddenService::getSettings()`，覆盖了 xnx_friendlink 路由预设的 `$settings`，导致 links.htm 模板中 `apply_enabled` 键丢失，申请按钮不显示。

---

## 四、hook 文件常见坑

| 坑 | 正确做法 |
|---|---|
| 扩展名写错（`.php` ≠ `.htm`） | 与源标记**一模一样** |
| 忘记 `<?php exit;` | PHP hook 以 `<?php exit;` 开头（`.htm` hook 只用 `<?php`） |
| 改 hook 不清缓存 | 改完清 `tmp/`（含 OPcache） |
| hook 名拼错 | 对照 [03-hooks-catalog.md](manual/03-hooks-catalog.md) 核对 |
| lang hook 格式错 | 每行严格 `$lang['my_prefix_xxx'] = 'xxx';` |
| `model_inc_file.php` 忘逗号 | 每行 `APP_PATH.'plugin/...',` 以逗号结尾 |
| hook 内 `return` | 用 `if` 包裹 + `ob_start/ob_get_clean` 暂存输出，UA 检测分支尤其警惕 |
| **hook 文件内写 `// hook xxx` 注释** | **禁止**：编译器多趟循环（`plugin.func.php:575-584`，最多 10 层）会把 hook 文件内的 `// hook xxx.php` 注释误匹配为 hook 占位符，第二趟编译时再次拼接 hook 内容破坏代码结构，引发 `ParseError`。改用 `// hook: xxx`（冒号分隔）或 `// xxx` 格式（已违反 1 次：xnx_login_alert 注释导致 xnx_verify 崩溃被误禁用） |
| Service 内 `// hook xxx.php` 注释直接 `include_once` 加载 | 必须通过 `hook/model_inc_file.php` 注册到 model 加载列表走 `_include()` 编译 |
| `plugin_hook()` 运行时分发访问调用方局部变量 | 通过第二参数 `$data` 传关联数组，内部 `extract($data, EXTR_SKIP)` 注入 |
| **hook 内用通用变量名（`$settings`/`$conf`/`$user`）赋值** | **加插件前缀**（`$_myplugin_settings`），否则污染宿主作用域（已违反 1 次：xnx_hidden 的 `$settings` 覆盖 xnx_friendlink） |
| **模板 `include header.inc.htm` 后使用 `$settings`** | header 中其他插件 hook 会覆盖 `$settings`，需在 include 后重新获取或用前缀变量名 |

### 运行时 hook 分发：`plugin_hook()`

除编译期内联外，`plugin_hook('xxx.php', $data)`（`model/plugin.func.php` 第 742 行）提供运行时分发：带 try/catch 错误隔离、仅支持 `.php` hook、按 `hooks_rank` 降序、兼容旧版 `xn_hook()`。

---

## 五、数据库常见坑

| 坑 | 正确做法 |
|---|---|
| 建表忘加 `$tablepre` | `{$db->tablepre}my_plugin` |
| 用 PDO `bindValue` | 用**条件数组**语法（见 [04-api-cheatsheet.md](manual/04-api-cheatsheet.md)） |
| `user_update()` 改密码/组 | 用 `user_change_password()`/`user_change_group()`（受保护字段会被剥离） |
| 直接调 `__` 层（`thread__create`） | 调单下划线业务层（`thread_create`） |
| `install.php` 无幂等保护 | `CREATE TABLE IF NOT EXISTS` / `IF NOT EXISTS` 判断 |
| `uninstall.php` 忘删 setting | `kv_delete('my_plugin')` 或 `setting_delete` |
| 数据库结构变更写进 `install.php` | 走 `upgrade.php` 幂等迁移（`SHOW COLUMNS` + `ALTER TABLE`） |
| `install.php` 与 `upgrade.php` 字段清单不一致 | install 必须是 upgrade 的超集（首次安装不跑 upgrade） |
| `CacheHelper::remember` 写入的缓存键删除时缺前缀 | 删除键必须带 `core_` 前缀（核心）或 `p_{plugin}_` 前缀（插件） |

### 数据库升级机制：`upgrade.php`

1. 后台对比 `conf.json.version` 与 `bbs_plugin.version`，不一致且存在 `upgrade.php` 时显示升级按钮
2. 用户点升级 → `plugin_install()` 同步 db.version → 执行 `upgrade.php`
3. 字段迁移用 `SHOW COLUMNS` + `ALTER TABLE ADD COLUMN`（可重复执行），递增 `conf.json.version` 触发升级提示

---

## 六、前端常见坑

| 坑 | 正确做法 |
|---|---|
| 用 `$()` 写新代码 | htmx 属性 + `XN.*` API + 原生 JS |
| 用 `onclick="..."` 写复杂逻辑 | htmx 属性 或 `hx-on="click: ..."` |
| CSS/JS 路径写绝对路径 | `plugin/my_plugin/view/css/x.css`（相对路径） |
| 忽略暗色模式 | 用 `[data-bs-theme="dark"]` 覆盖 |
| 后台模板加 htmx | 只用原生 JS + Bootstrap |
| JS 全局变量名冲突 | `var __myPluginData = ...;` |
| `avatar_component_from_data()` 不传 `_uid` | 第 6 参数必须含 `array('_uid' => intval($uid))`，否则头像框/角标 hook 全部失效 |
| 头像直接用 `<img>` 标签 | 用 `avatar_component_from_data()` 统一渲染 |
| PC/移动端双模板用相同 `id` | 移动端加 `-mobile` 后缀（`id="xxx-mobile"`） |
| `response.ok` 判断 htmx 请求成败 | htmx 4 中 `ctx.response` 无 `.ok` 属性，用 `ctx.response.status >= 400` |
| 关键修复页面依赖 `xiuno-modern.js` 的 `$`/`XN` shim | 必须原生 `fetch` + `querySelectorAll` + `confirm` |
| 静态资源缺少版本号 | Hook 文件用 `$static_version`（已在 header.inc.htm 定义），视图文件用 `$conf['static_version']`；推荐用 `filemtime()` 动态版本号 |

---

## 七、并发安全与积分防刷

> 详细说明见 [plugindev/15-concurrency-security.md](manual/15-concurrency-security.md) 和 [security-patterns.md](security-patterns.md)

### CAS（Compare-And-Swap）模式

| 场景 | 错误写法 | 正确写法 |
|---|---|---|
| 状态转换 | 先读后写 | `db_update($t, ['id'=>$id, 'status'=>0], ['status'=>1])`，检查 affected |
| 批量操作 | 批量 UPDATE 无条件 | 逐条 CAS UPDATE，仅对成功的记录发积分 |
| 库存扣减 | `db_update($t, ['id'=>$id], ['stock-'=>1])` | `db_update($t, ['id'=>$id, 'stock>'=>0], ['stock-'=>1])` |
| CAS 条件 | 只允许 PENDING | 覆盖所有合法旧状态（如 PENDING + IGNORED） |
| CAS 失败 | 报错 | 幂等返回 true 或补偿性 refund |

### 幂等性设计

| 场景 | 方案 |
|---|---|
| 业务实体唯一 | `UNIQUE KEY invitee_uid` + `INSERT IGNORE` |
| 幂等检查 | `db_find_one(...)` 已存在则返回 true |
| 返回值约定 | 幂等成功返回 true（不报错），避免上层 retry |
| add/sub 返回值 | 检查 `['ok'=>bool]`，仅实际入账才记录 reward |
| 回滚 | 只回滚实际入账的积分（检查 credited 标志） |

### GET_LOCK 串行化

适用 CAS 无法覆盖的 TOCTOU 场景（如每日次数限制）：

```php
$lockKey = 'duel_join_' . intval($uid);
$stmt = $db->wlink->query("SELECT GET_LOCK(" . $db->wlink->quote($lockKey) . ", 5) AS lk");
if ($stmt->fetchColumn() != 1) return ['ok'=>false, 'message'=>'系统繁忙'];
try {
    // 串行化区域
} finally {
    $db->wlink->query("SELECT RELEASE_LOCK(" . $db->wlink->quote($lockKey) . ")");
}
```

### 部分成功回滚

多步积分发放部分失败时，回滚已成功步骤：

```php
// 记录已成功发放的积分
if (!empty($reward_detail['credits'])) {
    $r = $creditsService->sub($uid, 'credits', $reward_detail['credits'], '回滚');
    if (empty($r['ok'])) {
        xn_log("回滚失败 uid=$uid amount=" . $reward_detail['credits'], 'error');
    }
}
// 回滚业务状态（放在积分回滚之后）
db_update('quest_progress', ..., ['completed' => 0]);
```

### 路由层频率限制

```php
// route/lottery.php
$cache_key = 'lottery_draw_rate_' . $uid . '_' . $ip;
$count = cache_get($cache_key);
if ($count && intval($count) >= 10) message(-1, lang('rate_limited'));
cache_set($cache_key, intval($count) + 1, 60);
```

### 真实案例速查

| 案例 | 漏洞 | 修复 |
|---|---|---|
| AuditService::approve() | 无 CAS，重放刷积分 | CAS `audit_status IN (PENDING, IGNORED)` |
| xnx_invite useCode | 先读后写 use_count | CAS `use_count<max_use_count` + `use_count+1` |
| xnx_quest grantReward | 部分成功无回滚 | 回滚已成功发放的 credits/golds |
| xnx_duel joinDuel | daily_limit TOCTOU | GET_LOCK 串行化同用户操作 |
| xnx_lottery drawSidebar | 库存可扣成负数 | CAS `stock>0` + `stock-1` |

---

## 八、扫描器规则分级（PluginScanner）

安装前自动运行（`lib/PluginScanner.php` + `lib/PluginScannerRules.php`），按严重性分级。

### Fatal（阻止安装，`?force=1` 不可跳过）

| 分类 | 拦截内容 |
|---|---|
| `php_deprecated_functions` | PHP 7.x 移除函数：`mysql_*`、`each()`、`create_function()`、`split()`、`ereg*()` 等 |
| `php8_syntax` | PHP 8 不兼容：`&new`、`preg_replace /e` |
| `curly_brace_access` | `$arr{0}` 花括号数组访问（改用 `$arr[0]`） |
| `http_post_vars` | `HTTP_POST_VARS`/`HTTP_GET_VARS`/`HTTP_SESSION_VARS` |
| `dangerous_functions` | `eval`/`assert`/`system`/`exec`/`passthru`/`shell_exec`/`popen`/`proc_open`/`pcntl_exec` |
| `php8_deprecated` | `get_magic_quotes_gpc`、`utf8_encode`/`utf8_decode`、`money_format`、`is_resource`（对 PDO） |
| `php_comment_close_tag` | 单行注释 `//`/`#` 中含 `?>`（headers already sent） |
| `service_undefined_var` | Service 类 SQL 用未定义 `$tableName`/`$tablePrefix`（应用 `$this->tablepre`） |
| `heredoc_php_tag` | HEREDOC 块内含 `<?php`（应用 `{$var}`） |
| `hook_htm_header` | `.htm` hook 以 `<?php exit;` 开头（白屏，只能 `<?php`） |
| `app_path_in_url` | `<script>`/`<link>` 的 `src`/`href` 用 `APP_PATH`（应用 `$conf['view_url']`） |
| `conf_required_fields` | `conf.json` 缺 `name`/`type`（必须 `"plugin"`/`"theme"`）。插件唯一标识是目录名（dir），不读 `id` |

### Error（阻止安装，`?force=1` 不可跳过）

| 分类 | 拦截内容 |
|---|---|
| `conf_version` | `bbs_version` 必须两位制 X.Y，且与当前 `XIUNOX_VERSION` 前两段完全一致（同分支绑定） |

### Warning（提示，可跳过）

| 分类 | 拦截内容 |
|---|---|
| `plugin_version_format` | 插件 `version` 必须三位制 X.Y.Z（如 `1.0.0`） |
| `permission_security` | `user_update()` 修改 `password`/`gid`/`salt`/`password_hash` |
| `bs_js_api` | jQuery 调 BS 插件：`$().modal()`/`.dropdown()` 等 |
| `frontend_md5` | 前端 MD5 `hex_md5()`/`md5_hex()`（密码明文提交，服务端 `password_md5()`） |
| `md5js_global_load` | 全局加载 `md5.js` |
| `password_update_api` | `user_update()` 改 password（找回密码应用 `user__update()`） |
| `db_charset` | 数据库字符集 `utf8`（应 `utf8mb4`） |
| `raw_htmlspecialchars` | 裸 `htmlspecialchars()` |
| `bs_tab_navigation` | `data-bs-toggle="tab"` + `href="*.htm"` |
| `db_find_col_string` | `db_find_one()` 第 4 参数传字符串（应数组） |
| `install_non_idempotent` | `CREATE TABLE` 缺 `IF NOT EXISTS` |
| `capabilities_format` | `capabilities` 必须 `lowercase.dots` 数组（如 `["thread.post.create"]`） |
| `php_superglobal_output` | 直接 `echo`/`print` `$_GET`/`$_POST`（反射型 XSS） |
| `js_eval_call` | JS `eval()` |
| `js_dom_xss` | `document.write()`/`innerHTML =`/`outerHTML =`/`insertAdjacentHTML()` |
| `jquery_html_xss` | jQuery `.html()` |

### Medium（兼容建议）

| 分类 | 拦截内容 |
|---|---|
| `bs4_classes` | BS4 旧类：`ml-`→`ms-`、`mr-`→`me-`、`form-group`→`mb-3` 等 |
| `bs4_data_attrs` | BS4 旧 data：`data-toggle`→`data-bs-toggle` 等 |
| `bs3_classes` | BS3 旧类：`panel-*`→`card-*`、`well`、`glyphicon`、`pull-left`→`float-start` 等 |
| `fontello_icons` | Fontello 旧图标：`icon-lock`→`ti-lock` 等 |
| `icon_libraries` | 非 Tabler Icons：`fa-*`/`bi-*`/`glyphicon glyphicon-*` |
| `jquery_usage` | `$.ajax()`/`$.each()`/`$.fn.`/`jQuery()`/`$(document).ready` 等 |

### Info（仅提示）

| 分类 | 拦截内容 |
|---|---|
| `missing_csrf` | POST 表单缺 `CsrfService::input()` 或 `csrf_token`（不阻止安装） |
| `direct_db` | 原始 SQL `db_exec()`/`db_sql_find()`（仅 `model/` 检测） |

> **关于 `?force=1`**：可跳过 fatal/error 之外的拦截，但 `getForceCategories()` 中的分类（所有 fatal + `conf_version`）即使带 `?force=1` 也阻止安装。

---

## 八、交付前检查表

### 结构与安全

- [ ] `conf.json` 必填字段完整（`name`/`bbs_version`/`type`/`version`/`brief`），不含 `id`/`installed`/`enable`
- [ ] `install.php` 建表含 `IF NOT EXISTS`，字段清单是 `upgrade.php` 的超集
- [ ] `uninstall.php` 删表 + 删 KV/setting
- [ ] 数据库结构变更走 `upgrade.php` 幂等迁移，递增 `conf.json.version`
- [ ] 所有 PHP hook 以 `<?php exit;` 开头；hook 文件名（含扩展名）与源标记完全匹配
- [ ] `model_inc_file.php` 每行以逗号结尾
- [ ] 所有 `<form method="post">` 含 `CsrfService::input()`，POST 处理以 `CsrfService::check()` 开头
- [ ] 所有输出用 `esc_html()`/`esc_attr()`/`esc_js()`
- [ ] `setting.php`/`install.php`/`uninstall.php` 有 `!defined('DEBUG') AND exit('Access Denied');` + 权限检查
- [ ] `.htm` hook 不含危险函数（编译期 `_include_scan_dangerous_php()` 检测 `eval`/`assert`/`system` 等）

### 前端与命名

- [ ] 无 jQuery（`$()`/`.on()`/`.ajax()`/`$.fn.*`）、无 Alpine.js 属性
- [ ] 交互用 htmx 4 属性、`XN.*` API 或原生 JS（关键修复页面必须原生 JS）
- [ ] 图标用 Tabler Icons（`ti ti-xxx`），CSS/JS 用相对路径 `plugin/<dir>/...`
- [ ] 数据库表/语言键/PHP 全局/JS 全局/setting 键/CSS class 全部带插件前缀
- [ ] `avatar_component_from_data()` 调用传 `_uid`
- [ ] JS/CSS 静态资源引用带版本号（hook 文件用 `$static_version`，视图文件用 `$conf['static_version']`，推荐 `filemtime()`）

### 功能

- [ ] 改 hook 后清 `tmp/` 缓存（含 OPcache）
- [ ] 帖子删除有级联清理
- [ ] `message()` 返回正确（0=成功，非 0=错误）
- [ ] 分页 URL 首个分隔符根据 `url()` 返回值是否含 `?` 动态决定（`&` 或 `?`）
- [ ] 用户名显示统一取 `display_name`，禁止 `db_find('user', ...)` 后取 `username`
