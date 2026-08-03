# API 速查表

> 本文件为 API 速查，详细说明见 [plugindev/04-api-cheatsheet.md](../../plugindev/04-api-cheatsheet.md)

## 目录

- [1. 请求输入](#1-请求输入)
- [2. URL 与消息](#2-url-与消息)
- [3. 数据库 CRUD](#3-数据库-crud)
- [4. 模型层](#4-模型层)
- [5. 权限系统](#5-权限系统)
- [6. KV / Setting / Cache](#6-kv--setting--cache)
- [7. 安全](#7-安全)
- [8. 全局变量](#8-全局变量)

---

## 1. 请求输入

| 函数 | 签名 | 说明 |
|---|---|---|
| `param()` | `param($key, $defval = '', $htmlspecialchars = TRUE, $addslashes = FALSE)` | 读 `$_REQUEST`；`param(0)` = URL 第一段（路由），`param(1)` = 第二段，`param(2)` = 第三段。**$defval 类型决定返回类型**（`0`→int，`''`→string，`[]`→array） |
| `param_word()` | `param_word($key, $len = 32)` | 仅字母数字下划线 |
| `param_base64()` | `param_base64($key, $len = 0)` | Base64 解码 |
| `param_json()` | `param_json($key)` | JSON 解码 |
| `param_url()` | `param_url($key)` | URL 解码 |

```php
// 路由：thread-create-1.htm
$route = param(0, 'index');  // 'thread'
$action = param(1);          // 'create'
$tid = param(2, 0);          // 1 (int)

// 表单
$keyword = param('keyword');      // 自动 htmlspecialchars
$page = param('page', 1);         // int
$password = param('password', '', FALSE);  // 敏感字段必须关闭 htmlspecialchars
```

> ⚠️ **URL 按 `-` 分隔参数，`case` 值禁止含 `-`**（已违反 1 次）：`-` 是参数分隔符，`myplugin-list-settings` 被解析为 `param(1)='list'`、`param(2)='settings'`——`param(1)` 只取单段。路由 `switch` 的 `case` 值必须是不含 `-` 的单段字符串，多段子动作用 `param(2)`/`param(3)` 逐段取。详见 [04-api-cheatsheet.md](../../plugindev/04-api-cheatsheet.md) 第 1 节。

> ⚠️ 密码 / token / API key 等敏感字段必须传第 3 参 `FALSE`，否则 `<`、`>`、`&` 被转义后比对失败。

---

## 2. URL 与消息

| 函数 | 签名 | 说明 |
|---|---|---|
| `url()` | `url($url, $extra = array())` | 逻辑 URL → 实际 URL。**所有链接必须用 `url()`** |
| `message()` | `message($code, $message, $extra = array())` | **终止执行**。`<0` 系统错误，`0` 成功，`>0` 业务错误。自动检测 AJAX/HTMX/API |
| `jump()` | `jump($message, $url = '', $delay = 3)` | 跳转提示页 |
| `lang()` | `lang($key, $arr = array())` | 语言包替换 |
| `http_404()` / `http_403()` | `http_404()` / `http_403()` | 404 / 403 终止 |
| `http_location()` | `http_location($url, $allow_external = FALSE)` | 302 跳转。**默认仅站内跳转**（防开放重定向），传 `TRUE` 才允许外链 |

```php
message(0, '保存成功');
message(0, '保存成功', ['redirect_url' => url('plugin-setting-xnx_tag')]);
message(-1, '权限不足');

// message() $extra 常用键：redirect_url（重定向）、skip_navigate（跳过跳转）
```

> ⚠️ `admin_plugin_setting_url($dir)` / `thread_url($tid)` 等 route_url 系列函数**内部已调用 `url()`**，禁止再套 `url()`，否则产生 `??xxx.htm.htm` 双后缀导致路由解析失败。

---

## 3. 数据库 CRUD

> 驱动：**仅 `pdo_mysql`**。全局 `$db = $_SERVER['db']`，表前缀 `$tablepre`（默认 `bbs_`）自动加。默认 CRUD 不走 PDO 预编译，用条件数组语法；需要参数绑定时用 `db_*_prepared` 系列。

### 条件数组语法（`$cond`）

```php
['id' => 123]                              // WHERE id = 123
['uid' => [1, 2, 3]]                       // WHERE uid = 1 OR uid = 2 OR uid = 3
['id' => ['>' => 100, '<' => 200]]         // WHERE id > 100 AND id < 200
['subject' => ['LIKE' => 'keyword']]       // WHERE subject LIKE '%keyword%'
['created' => ['>=' => 1234567890]]        // WHERE created >= 1234567890
```

### CRUD 函数

| 函数 | 签名 | 返回 |
|---|---|---|
| `db_insert()` | `db_insert($table, $arr, $d = NULL)` | `lastInsertId` |
| `db_replace()` | `db_replace($table, $arr, $d = NULL)` | `lastInsertId` |
| `db_update()` | `db_update($table, $cond, $update, $d = NULL)` | affected rows |
| `db_delete()` | `db_delete($table, $cond, $d = NULL)` | affected rows |
| `db_exec()` | `db_exec($sql, $d = NULL)` | lastInsertId / affected rows |
| `db_read()` | `db_read($table, $cond, $d = NULL)` | 单行 |
| `db_find()` | `db_find($table, $cond = array(), $orderby = array(), $page = 1, $pagesize = 10, $key = '', $col = array(), $d = NULL)` | 多行数组 |
| `db_find_one()` | `db_find_one($table, $cond = array(), $orderby = array(), $col = array(), $d = NULL)` | 单行或 NULL |
| `db_count()` | `db_count($table, $cond = array(), $d = NULL)` | int |
| `db_maxid()` | `db_maxid($table, $field, $cond = array(), $d = NULL)` | int |
| `db_sql_find_one()` | `db_sql_find_one($sql, $d = NULL)` | 单行 |
| `db_sql_find()` | `db_sql_find($sql, $key = NULL, $d = NULL)` | 多行 |

### PDO 预编译系列（防 SQL 注入，参数绑定）

| 函数 | 签名 |
|---|---|
| `db_exec_prepared()` | `db_exec_prepared($sql, $params = array(), $d = NULL)` |
| `db_sql_find_prepared()` | `db_sql_find_prepared($sql, $params = array(), $key = NULL, $d = NULL)` |
| `db_sql_find_one_prepared()` | `db_sql_find_one_prepared($sql, $params = array(), $d = NULL)` |

### 排序与增量

```php
// $orderby：1 = ASC，-1 = DESC
['tid' => -1]                          // ORDER BY tid DESC
['top' => -1, 'tid' => -1]            // ORDER BY top DESC, tid DESC

// 增量更新
db_update('thread', ['tid' => 123], ['views+' => 1]);
db_update('user',  ['uid' => 456], ['threads+' => 1, 'todaythreads+' => 1]);

// 分页示例
$page = param('page', 1);
$pagesize = 20;
$total = db_count('my_table', $cond);
$list = db_find('my_table', $cond, ['created' => -1], $page, $pagesize, 'id');
$pagination = pagination(url('myroute-list-{page}'), $total, $page, $pagesize);
```

---

## 4. 模型层

> 所有模型在 `model/*.func.php`。**插件应调用单下划线业务层，不碰双下划线原始层。**
> 三级命名：原始层 `model__create`（纯 DB）→ 业务层 `model_create`（+ 缓存/计数/通知）→ 格式化 `model_format(&$row)`

### Thread

| 函数 | 签名 | 说明 |
|---|---|---|
| `thread_create` | `thread_create($arr, &$pid, $options = array())` | ✅ 业务创建（含首帖、计数、通知） |
| `thread_read` | `thread_read($tid)` | 读取 + format |
| `thread_read_cache` | `thread_read_cache($tid)` | 带请求级缓存 |
| `thread_update` | `thread_update($tid, $arr)` | 更新 |
| `thread_delete` | `thread_delete($tid)` | 删除 |
| `thread_format` | `thread_format(&$thread)` | 格式化 |

### Post

| 函数 | 签名 | 说明 |
|---|---|---|
| `post_create` | `post_create($arr, $fid, $gid, $options = array())` | ✅ 业务创建 |
| `post_read` | `post_read($pid)` | 读取 |
| `post_update` | `post_update($pid, $arr, $tid = 0, $options = array())` | 更新 |
| `post_delete` | `post_delete($pid)` | 删除 |
| `post_format` | `post_format(&$post)` | 格式化 |

### User

| 函数 | 签名 | 说明 |
|---|---|---|
| `user_create` | `user_create($arr)` | ✅ 业务创建 |
| `user_read` | `user_read($uid)` | 读取 + format |
| `user_read_cache` | `user_read_cache($uid)` | 带缓存 + format |
| `user_read_by_email` | `user_read_by_email($email)` | 按 email + format |
| `user_read_by_username` | `user_read_by_username($username)` | 按 username + format |
| `user_find` | `user_find($cond, $orderby, $page, $pagesize)` | 分页 + format |
| `user_find_by_uids` | `user_find_by_uids($uids)` | ✅ 批量按 uid（`"1,2,3"` 逗号字符串），内部 `user_read_cache` + format |
| `user_find_by_usernames` | `user_find_by_usernames($usernames)` | 批量按 username + format |
| `user_count` | `user_count($cond)` | 计数 |
| `user_update` | `user_update($uid, $arr)` | ⚠️ 受 `USER_UPDATE_PROTECTED_FIELDS` 白名单限制 |
| `user_login_check` | `user_login_check()` | 未登录则跳登录页 |
| `user_change_password` | `user_change_password($uid, $new_password, $old_password = '', $is_admin = FALSE)` | ✅ 改密码（唯一安全方式） |
| `user_change_group` | `user_change_group($uid, $new_gid)` | ✅ 改用户组（唯一安全方式） |
| `user_safe_info` | `user_safe_info($user)` | 脱敏（去 password/email/IP） |
| `user_format` | `user_format(&$user)` | 引用传入，生成 `display_name`/`avatar_url` 等派生字段 |

> ⚠️ `user_update()` 白名单中 `password`/`gid` 被剥离，改密码/改组**必须用** `user_change_password()` / `user_change_group()`。
> ⚠️ `db_find('user', ...)` **不调用 `user_format()`**，不含 `display_name` 等派生字段。显示用户名**必须用** `user_find_by_uids()` / `user_read()`，或手动 `user_format($user)`。

### Forum / Group

| 函数 | 签名 | 说明 |
|---|---|---|
| `forum_create` | `forum_create($arr)` | ✅ 业务创建（清板块列表缓存） |
| `forum_read` | `forum_read($fid)` | 读取 + format |
| `forum_list_cache` | `forum_list_cache()` | 缓存读取板块列表 |
| `forum_list_cache_delete` | `forum_list_cache_delete()` | 清板块列表缓存 |
| `group_list_cache` | `group_list_cache()` | 缓存读取用户组列表 |

---

## 5. 权限系统

### 板块权限

| 函数 | 签名 | 说明 |
|---|---|---|
| `forum_access_user` | `forum_access_user($fid, $gid, $access)` | 用户权限：`allowread`/`allowthread`/`allowpost`/`allowattach`/`allowdown` |
| `forum_access_mod` | `forum_access_mod($fid, $gid, $access)` | 版主权限：`allowtop`/`allowmove`/`allowupdate`/`allowdelete`/`allowbanuser`/`allowviewip`/`allowdeleteuser` |
| `forum_is_mod` | `forum_is_mod($fid, $gid, $uid)` | 是否该板块版主 |

```php
if (!forum_access_user($fid, $gid, 'allowthread')) {
    message(-1, '您没有权限发帖');
}
```

### PermissionService（插件自定义权限）

| 方法 | 签名 | 说明 |
|---|---|---|
| `register` | `PermissionService::register(string $plugin, string $key, string $label, string $group = 'plugin')` | ✅ 插件注册自定义权限 |
| `check` | `PermissionService::check(string $permission_key, int $uid = 0): bool` | 检查权限（管理员自动 true） |
| `getPermissions` | `PermissionService::getPermissions(int $gid): array` | 获取组的所有权限 |
| `updatePermissions` | `PermissionService::updatePermissions(int $gid, array $permissions): bool` | 更新组权限 |

```php
// install.php 中注册
PermissionService::register('my_plugin', 'my_plugin_manage', '我的插件管理');
// 检查
if (!PermissionService::check('my_plugin_manage', $uid)) {
    message(-1, '权限不足');
}
```

---

## 6. KV / Setting / Cache

### KV 存储（DB-backed `bbs_kv` 表）

| 函数 | 签名 | 说明 |
|---|---|---|
| `kv_get` | `kv_get($k)` | 读取（JSON 反序列化） |
| `kv_set` | `kv_set($k, $v, $life = 0)` | 写入（$life=0 永久） |
| `kv_delete` | `kv_delete($k)` | 删除 |
| `kv_cache_get` | `kv_cache_get($k)` | KV + 缓存组合读取 |
| `kv_cache_set` | `kv_cache_set($k, $v, $life = 0)` | KV + 缓存组合写入 |
| `kv_cache_delete` | `kv_cache_delete($k)` | KV + 缓存组合删除 |

### 站点设置

| 函数 | 签名 | 说明 |
|---|---|---|
| `setting_get` | `setting_get($k)` | 读取站点设置 |
| `setting_set` | `setting_set($k, $v)` | 写入站点设置 |
| `setting_delete` | `setting_delete($k)` | 删除（`uninstall.php` 清理用） |

```php
$settings = setting_get('my_plugin');  // 推荐按插件名存为聚合数组
$settings['enabled'] = 1;
setting_set('my_plugin', $settings);
```

### 通用缓存

| 函数 | 签名 | 说明 |
|---|---|---|
| `cache_get` | `cache_get($k, $c = NULL)` | 读取（驱动：file/redis/memcached/mysql） |
| `cache_set` | `cache_set($k, $v, $life = 0, $c = NULL)` | 写入 |
| `cache_delete` | `cache_delete($k, $c = NULL)` | 删除 |
| `cache_truncate` | `cache_truncate($c = NULL)` | ⚠️ redis 下 `flushdb` 误删 session，禁用；改用 `CacheHelper::pluginDeletePrefix()` |

### CacheHelper（推荐）

> 新增缓存用 `CacheHelper::remember()`；插件缓存键用 `CacheHelper::pluginKey()` 生成；清插件缓存用 `CacheHelper::pluginDeletePrefix()`。键命名：核心 `core_{name}`，插件 `p_{plugin}_{name}`。

| 方法 | 签名 | 说明 |
|---|---|---|
| `remember` | `CacheHelper::remember($key, $ttl, $callback, $plugin = '')` | 读缓存，未命中执行 `$callback` 并写入 |
| `get` | `CacheHelper::get($key, $plugin = '')` | 读取（带命中统计） |
| `set` | `CacheHelper::set($key, $value, $ttl = 0, $plugin = '')` | 写入 |
| `delete` | `CacheHelper::delete($key, $plugin = '')` | 删除 |
| `pluginKey` | `CacheHelper::pluginKey($key, $plugin = '')` | 生成命名空间键（自动加 `p_{plugin}_` 前缀） |
| `pluginDeletePrefix` | `CacheHelper::pluginDeletePrefix($plugin)` | 清整个插件缓存 |
| `deleteByPrefix` | `CacheHelper::deleteByPrefix($prefix)` | 按前缀删除（基于 SCAN，生产安全） |

```php
// ✅ 推荐：一行搞定缓存读写
return CacheHelper::remember('rank_total', 300, function() {
    return db_find(...);
}, 'checkin');  // 自动加 p_checkin_ 前缀

// ✅ 写操作后清插件缓存
CacheHelper::pluginDeletePrefix('checkin');
```

---

## 7. 安全

### XSS 防护（`lib/EscapeService.php`）

| 函数 | 签名 | 说明 |
|---|---|---|
| `esc_html` | `esc_html($var)` | HTML body 文本（`htmlspecialchars(ENT_QUOTES\|ENT_HTML5, UTF-8)`） |
| `esc_attr` | `esc_attr($var)` | HTML 属性值（`data-id="..."`、`value="..."`） |
| `esc_js` | `esc_js($var)` | `<script>` 内单引号字符串字面量（不用于 `onclick` 内联事件） |

```php
echo esc_html($thread['subject']);
echo '<a href="#" data-id="'.esc_attr($id).'">';
echo '<script>var x = '.esc_js($value).';</script>';
```

### CSRF 防护（`lib/CsrfService.php`）

> token 存 cookie（`bbs_csrf`，7 天，SameSite=Lax，httponly）。`index.inc.php` 已对非 GET 请求统一 `CsrfService::check()`（`ai` 路由除外），普通路由无需重复调用。

| 方法 | 签名 | 说明 |
|---|---|---|
| `input` | `CsrfService::input(): string` | 返回 `<input type="hidden" name="csrf_token">` |
| `check` | `CsrfService::check(): void` | 验证 token（从 `$_POST['csrf_token']` 或 `HTTP_X_CSRF_TOKEN` header）。失败终止 |
| `generate` | `CsrfService::generate(): string` | 生成/获取 token |
| `getToken` | `CsrfService::getToken(): string` | 获取当前 token（从 `$_COOKIE['bbs_csrf']`） |

```php
<form method="post">
    <?php echo CsrfService::input();?>
    ...
</form>
// htmx/fetch：xiuno-modern.js 的 XN.ajax() 自动注入 X-CSRF-TOKEN header
```

---

## 8. 全局变量

| 变量 | 类型 | 说明 |
|---|---|---|
| `$uid` | int | 当前用户 ID（0=游客） |
| `$user` | array | 当前用户行 |
| `$gid` | int | 当前用户组 ID（0=游客, 1=管理员, 2=超版, 3/4=版主, ≥100=普通） |
| `$group` | array | 当前用户组（含权限标志） |
| `$grouplist` | array | 所有用户组（gid 索引） |
| `$forumlist` | array | 所有板块（fid 索引） |
| `$runtime` | array | 运行时统计 |
| `$conf` | array | 全局配置 |
| `$g_setting` | array | 站点设置（`setting_get('setting')`） |
| `$lang` | array | 语言包 |
| `$header` | array | 页头数据（title/keywords/csrf_token 等） |
| `$fid` / `$tid` | int | 当前板块/帖子 ID |
| `$route` | string | 当前路由名 |
| `$time` / `$ip` | int/string | `time()` / 客户端 IP |

---

## 9. 邮件发送（异步推荐）

### 核心函数

| 函数 | 签名 | 说明 |
|---|---|---|
| `xn_send_mail` | `xn_send_mail($smtp, $from_name, $to_email, $subject, $body, $options)` | 同步发送，阻塞直到完成。返回 TRUE 或错误字符串 |
| `xn_send_mail_async` | `xn_send_mail_async($smtp, $from_name, $to_email, $subject, $body, $options)` | **异步发送，立即返回 TRUE**，不阻塞页面。内部用 `register_shutdown_function` + `fastcgi_finish_request` 实现 |
| `xn_smtp_get` | `xn_smtp_get()` | 从 `smtp.conf.php` 获取随机 SMTP 配置，无配置返回 FALSE |

### 辅助函数

| 函数 | 签名 | 说明 |
|---|---|---|
| `xn_email_rate_check` | `xn_email_rate_check($email, $ip = '')` | 频率检查。通过返回 TRUE，超限返回错误消息字符串 |
| `xn_email_rate_record` | `xn_email_rate_record($email, $ip = '')` | 记录发送，供下次频率检查 |
| `xn_email_template` | `xn_email_template($key, $vars)` | 渲染邮件模板，返回 `['subject'=>..., 'body'=>...]` |

### 推荐用法

```php
// 异步发送（推荐）：立即返回，不阻塞页面跳转
xn_send_mail_async($smtp, $from_name, $email, $subject, $body, ['is_html' => TRUE]);

// 频率限制检查
$rate = xn_email_rate_check($email, $longip);
if ($rate !== TRUE) message(-1, $rate);

// 实际发送结果需查 email_log 表
xn_email_rate_record($email, $longip);
message(0, '验证码已发送');
```

> ⚠️ `xn_send_mail_async()` 立即返回 TRUE，无法同步获取结果。验证结果请查 `bbs_email_log` 表。
>
> 详细说明（`$options` 参数、频率规则、模板格式）见 [plugindev/04-api-cheatsheet.md](../../plugindev/04-api-cheatsheet.md#11-邮件发送-api)
