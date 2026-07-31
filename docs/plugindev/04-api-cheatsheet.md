# 04 API 速查表

> 所有签名均来自源码逐条核对。`xiunophp/misc.func.php`、`xiunophp/db.func.php`、`xiunophp/array.func.php`、`model/*.func.php`、`lib/*.php`

---

## 1. 请求输入

| 函数 | 签名 | 说明 |
|---|---|---|
| `param()` | `param($key, $defval = '', $htmlspecialchars = TRUE, $addslashes = FALSE)` | 读 `$_REQUEST`；`param(0)` = URL 第一段（路由），`param(1)` = URL 第二段，`param(2)` = URL 第三段。**$defval 类型决定返回类型**（`0`→int，`''`→string，`[]`→array） |
| `param_word()` | `param_word($key, $len = 32)` | 仅字母数字下划线，超长截断 |
| `param_base64()` | `param_base64($key, $len = 0)` | Base64 解码 |
| `param_json()` | `param_json($key)` | JSON 解码 |
| `param_url()` | `param_url($key)` | URL 解码 |

```php
// URL: thread-create-1.htm
$route = param(0, 'index');  // 'thread'
$action = param(1);          // 'create'
$tid = param(2, 0);          // 1 (int)

// 表单
$keyword = param('keyword');  // 自动 htmlspecialchars
$page = param('page', 1);     // int
```

> ⚠️ **URL 按 `-` 分隔参数，`case` 值禁止含 `-`**（已违反 1 次）：Xiuno URL 中 `-` 是参数分隔符，`myplugin-list-settings` 被解析为 `param(0)='myplugin'`、`param(1)='list'`、`param(2)='settings'`——`param(1)` 永远只取到单段 `'list'`，不可能得到 `'list-settings'`。因此路由 `switch` 的 `case` 值必须是**不含 `-` 的单段字符串**。多段子动作用 `param(2)`、`param(3)` 逐段取。
>
> ```php
> // ❌ 错误：case 值含 -，永远匹配不到
> $action = param(1);
> switch ($action) {
>     case 'projects-settings': ...  // 不可能匹配，param(1) 只返回 'projects'
> }
>
> // ✅ 正确：param(1) 取主动作，param(2) 取子动作
> $action = param(1);
> $sub = param(2);
> switch ($action) {
>     case 'projects':
>         if (is_numeric($sub)) {
>             // gitee-projects-{uid} 独立页面
>         } else {
>             switch ($sub) {
>                 case 'settings': ...  // gitee-projects-settings
>                 case 'sync': ...      // gitee-projects-sync
>             }
>         }
>         break;
> }
> ```

> ⚠️ **敏感字段禁止用默认 htmlspecialchars**（已违反 1 次）：`param()` 第 3 参 `$htmlspecialchars` 默认 `TRUE` 会自动转义 `<`、`>`、`&` 等字符。**密码、token、API key 等敏感配置必须传第 3 参 `FALSE`**，否则特殊字符被转义后导致密码比对失败。
>
> ```php
> // ❌ 错误：密码含 & 会被转义成 &amp;，比对失败
> $password = param('password');
> // ✅ 正确：关闭 htmlspecialchars
> $password = param('password', '', FALSE);
> ```

---

## 2. URL 与消息

| 函数 | 签名 | 说明 |
|---|---|---|
| `url()` | `url($url, $extra = array())` | 逻辑 URL → 实际 URL。**所有链接必须用 `url()`** |
| `message()` | `message($code, $message, $extra = array())` | **终止执行**。`< 0` 系统错误，`0` 成功，`> 0` 业务错误。自动检测 AJAX/HTMX/API 返回 JSON 或 HTML |
| `jump()` | `jump($message, $url = '', $delay = 3)` | 跳转提示页（内含 redirect） |
| `lang()` | `lang($key, $arr = array())` | 语言包替换：`lang('count', [5])` → `"5 个"` |
| `http_404()` | `http_404()` | 404 终止 |
| `http_403()` | `http_403()` | 403 终止 |
| `http_location()` | `http_location($url, $allow_external = FALSE)` | 302 跳转。默认仅允许站内跳转(防开放重定向),传 `$allow_external = TRUE` 才允许外链 |

```php
// message() 用法
message(0, '保存成功');
message(0, '保存成功', ['redirect_url' => url('plugin-setting-xnx_tag')]);
message(-1, '权限不足');
message(1, '参数错误');

// message() $extra 键
// redirect_url  → 重定向 URL（支持 htmx toast 后跳转）
// skip_navigate → 跳过跳转
```

> ⚠️ **route_url 系列函数禁止再套 `url()`**（已违反 1 次）：`admin_plugin_setting_url($dir)` / `thread_url($tid)` / `forum_url($fid)` 等 route_url 系列函数**内部已调用 `url()`** 返回完整 URL。模板中直接 `echo esc_attr($setting_url);` 即可，**禁止再套 `url($setting_url)`**。
>
> 否则 `url()` 会把已带 `.htm` 后缀的字符串当作 query 再拼一次后缀，得到 `??xxx.htm.htm`；服务器 `parse_url` 把 `??xxx` 当 query，path 仅剩 `/admin/`，`xn_url_parse` 返回空数组，`param(0)` 取默认 `'index'`，导致跳到后台首页且保存不生效。
>
> ```php
> // ❌ 错误：双重包裹
> $url = url(admin_plugin_setting_url('my_plugin'));
> // ✅ 正确：route_url 已返回完整 URL
> $url = admin_plugin_setting_url('my_plugin');
> ```

---

## 3. 数据库（db_* 系列）

> 驱动：**仅 `pdo_mysql`**。全局 `$db = $_SERVER['db']`。表前缀 `$tablepre`（默认 `bbs_`）自动加。
> ⚠️ 默认 CRUD 函数（`db_insert/db_find` 等）不走 PDO 预编译，用条件数组语法；如需 PDO 参数绑定，可使用 `db_exec_prepared()` / `db_sql_find_prepared()` / `db_sql_find_one_prepared()`。

### 条件数组语法（`$cond`）

```php
// 等值
['id' => 123]                              // WHERE id = 123
['uid' => [1, 2, 3]]                       // WHERE uid = 1 OR uid = 2 OR uid = 3
['id' => ['>' => 100, '<' => 200]]         // WHERE id > 100 AND id < 200
['subject' => ['LIKE' => 'keyword']]      // WHERE subject LIKE '%keyword%'
['created' => ['>=' => 1234567890]]        // WHERE created >= 1234567890
// 空 = 无条件（全表）
```

### CRUD

| 函数 | 签名 | 返回值 |
|---|---|---|
| `db_insert()` | `db_insert($table, $arr, $d = NULL)` | `lastInsertId` |
| `db_replace()` | `db_replace($table, $arr, $d = NULL)` | `lastInsertId` |
| `db_update()` | `db_update($table, $cond, $update, $d = NULL)` | affected rows |
| `db_delete()` | `db_delete($table, $cond, $d = NULL)` | affected rows |
| `db_exec()` | `db_exec($sql, $d = NULL)` | lastInsertId / affected rows |
| `db_read()` | `db_read($table, $cond, $d = NULL)` | 单行（`SELECT * LIMIT 1`） |
| `db_find()` | `db_find($table, $cond = array(), $orderby = array(), $page = 1, $pagesize = 10, $key = '', $col = array(), $d = NULL)` | 多行数组 |
| `db_find_one()` | `db_find_one($table, $cond = array(), $orderby = array(), $col = array(), $d = NULL)` | 单行或 NULL |
| `db_count()` | `db_count($table, $cond = array(), $d = NULL)` | int |
| `db_maxid()` | `db_maxid($table, $field, $cond = array(), $d = NULL)` | int |
| `db_sql_find_one()` | `db_sql_find_one($sql, $d = NULL)` | 单行 |
| `db_sql_find()` | `db_sql_find($sql, $key = NULL, $d = NULL)` | 多行 |

### 排序约定

`$orderby` 数组：**`1` = ASC，`-1` 或其它 = DESC**

```php
$orderby = ['tid' => -1];           // ORDER BY tid DESC
$orderby = ['created' => 1];        // ORDER BY created ASC
$orderby = ['top' => -1, 'tid' => -1]; // ORDER BY top DESC, tid DESC
```

### 增量更新

```php
db_update('thread', ['tid' => 123], ['views+' => 1]);
db_update('user', ['uid' => 456], ['threads+' => 1, 'todaythreads+' => 1]);
```

```php
// 完整 CRUD 示例
$id = db_insert('my_table', ['uid' => $uid, 'content' => $content, 'created' => time()]);
db_update('my_table', ['id' => $id], ['content' => 'new content']);
$row = db_find_one('my_table', ['id' => $id]);
$list = db_find('my_table', ['uid' => $uid], ['created' => -1], 1, 20, 'id');
$total = db_count('my_table', ['uid' => $uid]);
db_delete('my_table', ['id' => $id]);

// 带分页
$page = param('page', 1);
$pagesize = 20;
$total = db_count('my_table', $cond);
$list = db_find('my_table', $cond, ['created' => -1], $page, $pagesize, 'id');
$pagination = pagination(url('myroute-list-{page}'), $total, $page, $pagesize);
```

---

## 4. 模型层（三级命名约定）

> 所有模型在 `model/*.func.php`。**插件应调用单下划线业务层，不碰双下划线原始层。**

### 命名规则

| 层级 | 命名 | 特点 | 示例 |
|---|---|---|---|
| 原始层 | `model__create` / `model__read` | 纯 DB，无缓存/计数/通知 | `thread__create($arr)` |
| 业务层 | `model_create` / `model_read` | 调原始层 + 更新缓存/计数/通知 | `thread_create($arr, &$pid, $options = array())` |
| 格式化 | `model_format(&$row)` | 装饰显示字段（`*_fmt`, `url`, `username`） | `thread_format(&$thread)` |

### Thread 模型

| 函数 | 签名 | 说明 |
|---|---|---|
| `thread__create` | `thread__create($arr)` | 原始创建 |
| `thread_create` | `thread_create($arr, &$pid, $options = array())` | ✅ 业务创建（含首帖、计数、通知） |
| `thread_read` | `thread_read($tid)` | 读取 + format |
| `thread_read_cache` | `thread_read_cache($tid)` | 带请求级缓存的读取 |
| `thread_update` | `thread_update($tid, $arr)` | 更新 |
| `thread_delete` | `thread_delete($tid)` | 删除（含计数、通知） |
| `thread_format` | `thread_format(&$thread)` | 格式化 |

### Post 模型

| 函数 | 签名 | 说明 |
|---|---|---|
| `post__create` | `post__create($arr, $gid)` | 原始创建 |
| `post_create` | `post_create($arr, $fid, $gid, $options = array())` | ✅ 业务创建 |
| `post_read` | `post_read($pid)` | 读取 |
| `post_update` | `post_update($pid, $arr, $tid = 0, $options = array())` | 更新 |
| `post_delete` | `post_delete($pid)` | 删除 |
| `post_format` | `post_format(&$post)` | 格式化 |

### User 模型

| 函数 | 签名 | 说明 |
|---|---|---|
| `user__create` | `user__create($arr)` | 原始创建 |
| `user_create` | `user_create($arr)` | ✅ 业务创建 |
| `user_read` | `user_read($uid)` | 读取 + format |
| `user_read_cache` | `user_read_cache($uid)` | 带缓存的读取 + format |
| `user_read_by_email` | `user_read_by_email($email)` | 按 email 读取 + format |
| `user_read_by_username` | `user_read_by_username($username)` | 按 username 读取 + format |
| `user_find` | `user_find($cond, $orderby, $page, $pagesize)` | 分页查询 + 逐条 format |
| `user_find_by_uids` | `user_find_by_uids($uids)` | ✅ 批量按 uid 查询（`$uids` 为 `"1,2,3"` 逗号字符串），内部走 `user_read_cache` + format |
| `user_find_by_usernames` | `user_find_by_usernames($usernames)` | 批量按 username 查询 + format |
| `user_count` | `user_count($cond)` | 计数 |
| `user_update` | `user_update($uid, $arr)` | 更新（⚠️ 受保护字段白名单限制） |
| `user_format` | `user_format(&$user)` | 格式化（引用传入，生成 `display_name` 等派生字段，见下表） |
| `user_login_check` | `user_login_check()` | 未登录则跳转登录页（终止执行） |
| `user_change_password` | `user_change_password($uid, $new_password, $old_password = '', $is_admin = FALSE)` | ✅ 改密码（唯一安全方式） |
| `user_change_group` | `user_change_group($uid, $new_gid)` | ✅ 改用户组（唯一安全方式） |
| `user_safe_info` | `user_safe_info($user)` | 脱敏（去掉密码/email/IP） |

> ⚠️ `user_update()` 有 `USER_UPDATE_PROTECTED_FIELDS` 白名单，`password` 和 `gid` 在白名单中但被剥离。改密码/改组**必须用** `user_change_password()` / `user_change_group()`。

#### `user_format()` 生成的派生字段

`user_format(&$user)` 是**引用传入**，会在原始 `$user` 上追加以下派生字段。所有 `user_read*` / `user_find*` 系列函数都会自动调用它。

| 派生字段 | 生成规则 | 用途 |
|---|---|---|
| **`display_name`** | `!empty($user['nickname']) ? $user['nickname'] : $user['username']` | **模板显示用户名的唯一正确字段**（昵称优先，为空 fallback 到登录用户名） |
| `avatar_url` | 根据 `$user['avatar']` 正负/0 查找 jpg/png/webp 文件 | 头像 URL（含 `?` 版本号防缓存） |
| `avatar_path` | 头像文件绝对路径 | 删除头像时用 |
| `groupname` | `group_name($user['gid'])` | 用户组名 |
| `group_icon_class` / `group_color` | 从 `$grouplist[$gid]` 取 | 组图标/组颜色 |
| `create_ip_fmt` / `login_ip_fmt` | `long2ip()` | 可读 IP |
| `create_date_fmt` / `login_date_fmt` | `date('Y-m-d')` | 可读日期 |
| `online_status` / `is_followed` | 默认 1 / 0（`is_followed` 需页面显式预加载 `$g_preloaded_follows`） | 在线/关注状态 |

> ⚠️ **`db_find('user', ...)` 不调用 `user_format()`**，返回的数据**不含** `display_name` / `avatar_url` 等派生字段。插件层获取用户信息用于显示时**必须**用 `user_find_by_uids()` / `user_read()` / `user_read_cache()` 等核心函数（自动 format），或在 `db_find` 结果上手动 `user_format($user)` 补齐。直接取 `$user['username']` 会显示登录用户名而非用户可修改的昵称。

```php
// ❌ 错误：db_find 不生成 display_name，$u['display_name'] ?? $u['username'] 始终 fallback 到 username
$users = db_find('user', ['uid' => [1, 2, 3]], [], 1, 3, 'uid');
echo $users[1]['display_name'] ?? $users[1]['username'];  // 永远是 username（登录名）

// ✅ 正确：user_find_by_uids 自动 format，display_name 已生成
$users = user_find_by_uids('1,2,3');  // 注意参数是逗号字符串
echo $users[1]['display_name'];  // 昵称（或 fallback 到 username）

// ✅ 已有 db_find 结果时手动补 format
$users = db_find('user', ['uid' => [1, 2, 3]], [], 1, 3, 'uid');
foreach ($users as &$u) { user_format($u); }
unset($u);
echo $users[1]['display_name'];
```

### Forum 模型

| 函数 | 签名 | 说明 |
|---|---|---|
| `forum__read` | `forum__read($fid)` | 原始读取 |
| `forum_create` | `forum_create($arr)` | ✅ 业务创建(调原始层 + 清板块列表缓存;含 `model_forum_create_start/end.php` hook) |
| `forum_read` | `forum_read($fid)` | 读取 + format |
| `forum_list_cache` | `forum_list_cache()` | 缓存读取板块列表 |
| `forum_list_cache_delete` | `forum_list_cache_delete()` | 清除板块列表缓存 |
| `forum_format` | `forum_format(&$forum)` | 格式化 |

### Group 模型

| 函数 | 签名 | 说明 |
|---|---|---|
| `group_list_cache` | `group_list_cache()` | 缓存读取用户组列表 |

---

## 5. 权限系统

### 全局变量

```php
$uid    // 当前用户 ID（0 = 游客）
$user   // 当前用户数组
$gid    // 当前用户组 ID（0=游客, 1=管理员, 2=超版, 3/4=版主, >=100=普通）
$group  // 当前用户组数组（含权限标志）
$grouplist  // 所有用户组
$forumlist  // 所有板块
```

### 板块权限

| 函数 | 签名 | 说明 |
|---|---|---|
| `forum_access_user` | `forum_access_user($fid, $gid, $access)` | 用户权限：`allowread`/`allowthread`/`allowpost`/`allowattach`/`allowdown` |
| `forum_access_mod` | `forum_access_mod($fid, $gid, $access)` | 版主权限：`allowtop`/`allowmove`/`allowupdate`/`allowdelete`/`allowbanuser`/`allowviewip`/`allowdeleteuser` |
| `forum_is_mod` | `forum_is_mod($fid, $gid, $uid)` | 是否该板块版主 |

```php
// 检查发帖权限
if(!forum_access_user($fid, $gid, 'allowthread')) {
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
| `getAllRegisteredKeys` | `PermissionService::getAllRegisteredKeys(): array` | 获取所有已注册权限键 |

```php
// 在 install.php 中注册插件权限
PermissionService::register('my_plugin', 'my_plugin_manage', '我的插件管理');

// 检查权限
if(!PermissionService::check('my_plugin_manage', $uid)) {
    message(-1, '权限不足');
}
```

---

## 6. 缓存 / KV / 设置

### KV 存储（持久化，DB-backed `bbs_kv` 表）

| 函数 | 签名 | 说明 |
|---|---|---|
| `kv_get` | `kv_get($k)` | 读取 KV（JSON 反序列化） |
| `kv_set` | `kv_set($k, $v, $life = 0)` | 写入 KV（$life=0 永久） |
| `kv_delete` | `kv_delete($k)` | 删除 KV |
| `kv_cache_get` | `kv_cache_get($k)` | KV + 缓存组合读取 |
| `kv_cache_set` | `kv_cache_set($k, $v, $life = 0)` | KV + 缓存组合写入 |
| `kv_cache_delete` | `kv_cache_delete($k)` | KV + 缓存组合删除 |

### 站点设置

| 函数 | 签名 | 说明 |
|---|---|---|
| `setting_get` | `setting_get($k)` | 读取站点设置(从 KV 的 `setting` 键) |
| `setting_set` | `setting_set($k, $v)` | 写入站点设置 |
| `setting_delete` | `setting_delete($k)` | 删除站点设置键(插件 `uninstall.php` 清理配置时使用) |

```php
// 插件设置 —— 推荐模式
$settings = setting_get('my_plugin');
// $settings = ['enabled' => 1, 'max_tags' => 5, ...]

$settings['enabled'] = 1;
setting_set('my_plugin', $settings);
```

### 通用缓存

| 函数 | 签名 | 说明 |
|---|---|---|
| `cache_get` | `cache_get($k, $c = NULL)` | 读取缓存（驱动：file/redis/memcached/mysql） |
| `cache_set` | `cache_set($k, $v, $life = 0, $c = NULL)` | 写入缓存 |
| `cache_delete` | `cache_delete($k, $c = NULL)` | 删除缓存 |
| `cache_truncate` | `cache_truncate($c = NULL)` | 清空缓存。⚠️ **redis 驱动下会 `flushdb` 误删 session**,禁止用于清插件缓存;改用 `CacheHelper::deleteByPrefix()` 或 `CacheHelper::pluginDeletePrefix($plugin)` |

### CacheHelper(推荐)

> 项目规范:**新增缓存用 `CacheHelper::remember()` 代替手写 `cache_get/cache_set`**;插件缓存键用 `CacheHelper::pluginKey()` 生成;清插件缓存用 `CacheHelper::pluginDeletePrefix()`。缓存键命名:核心 `core_{name}`,插件 `p_{plugin}_{name}`。

| 方法 | 签名 | 说明 |
|---|---|---|
| `remember` | `CacheHelper::remember($key, $ttl, $callback, $plugin = '')` | 读取缓存,未命中则执行 `$callback` 并写入(自动加插件前缀) |
| `get` | `CacheHelper::get($key, $plugin = '')` | 读取缓存(带命中统计) |
| `set` | `CacheHelper::set($key, $value, $ttl = 0, $plugin = '')` | 写入缓存(带统计) |
| `delete` | `CacheHelper::delete($key, $plugin = '')` | 删除单个缓存键 |
| `pluginKey` | `CacheHelper::pluginKey($key, $plugin = '')` | 生成命名空间键:核心加 `core_` 前缀,插件加 `p_{plugin}_` 前缀 |
| `deleteByPrefix` | `CacheHelper::deleteByPrefix($prefix)` | 按前缀删除缓存(基于 SCAN,生产安全) |
| `pluginDeletePrefix` | `CacheHelper::pluginDeletePrefix($plugin)` | 清除整个插件的所有缓存(等同 `deleteByPrefix('p_{plugin}_')`) |
| `registerKeys` | `CacheHelper::registerKeys($plugin, $keys)` | 注册插件的缓存键(`$keys` 为 `[key => [ttl, desc]]`),用于统计和批量清理 |
| `unregisterPlugin` | `CacheHelper::unregisterPlugin($plugin)` | 移除插件缓存键注册(禁用/卸载时调用) |
| `getRegisteredKeys` | `CacheHelper::getRegisteredKeys(): array` | 获取所有已注册的缓存键(合并持久化 + 内存) |
| `getStats` | `CacheHelper::getStats(): array` | 获取缓存统计(命中/未命中/键级统计) |
| `warmup` | `CacheHelper::warmup($target = 'all')` | 缓存预热(主动生成核心高频数据缓存;`'core'`/插件名/`'all'`) |

```php
// ✅ 推荐:一行搞定缓存读写
return CacheHelper::remember('rank_total', 300, function() {
    return db_find(...);
}, 'checkin');  // 第4参传插件名,自动加 p_checkin_ 前缀

// ✅ 插件缓存键生成(避免键名冲突)
$key = CacheHelper::pluginKey('today_stats', 'checkin');  // → p_checkin_today_stats

// ✅ 清除整个插件的缓存(写操作后必须调用)
CacheHelper::pluginDeletePrefix('checkin');

// ❌ 旧写法(样板代码,已废弃)
$cached = cache_get($key);
if($cached !== NULL) return $cached;
$result = db_find(...);
cache_set($key, $result, 300);
return $result;
```

> ⚠️ **缓存驱动配置变更必须触发实例重建**（已违反 1 次）：当缓存驱动配置（host/port/password/database）变更时，CacheHelper 内部**必须比较所有连接参数**，发现变更时丢弃旧实例重新创建，**不复用旧实例**。否则修改配置后仍使用旧连接，导致缓存读写失败或读到旧数据。

> ⚠️ **`session_redis` 和 `cache.redis` 字段名必须统一**（已违反 1 次）：两处配置字段名**必须用 `password`/`database`**，**禁止用 `auth`/`db`**（旧字段名）。否则 session 和 cache 驱动初始化失败，用户登录态丢失。

### 运行时统计

| 函数 | 签名 | 说明 |
|---|---|---|
| `runtime_get` | `runtime_get($k)` | 读取运行时值 |
| `runtime_set` | `runtime_set($k, $v)` | 写入运行时值（支持 `'threads+'` 增量语法） |
| `runtime_save` | `runtime_save()` | 保存到缓存（shutdown 时自动调） |

---

## 7. 安全

### XSS 防护（`lib/EscapeService.php`）

| 函数 | 签名 | 说明 |
|---|---|---|
| `esc_html` | `esc_html($var)` | `htmlspecialchars($var, ENT_QUOTES\|ENT_HTML5, 'UTF-8')` |
| `esc_attr` | `esc_attr($var)` | 同上（语义别名，用于属性值） |
| `esc_js` | `esc_js($var)` | `json_encode`(UNESCAPED_UNICODE/SLASHES) + 去外层双引号 + 转义单引号 `\'` + 防 `</` script 注入;用于 `<script>` 内单引号字符串上下文 |

```php
// ❌ 禁止
echo htmlspecialchars($thread['subject']);
// ✅ 正确
echo esc_html($thread['subject']);
echo '<a href="#" data-id="'.esc_attr($id).'">';
echo '<script>var x = '.esc_js($value).';</script>';
```

> 三个函数均由 `EscapeService` 提供为全局函数（非静态方法），按输出上下文选择：
> - `esc_html` — HTML body 文本内容（如帖子标题、用户昵称）
> - `esc_attr` — HTML 属性值上下文（如 `data-id="..."`、`value="..."`）
> - `esc_js` — `<script>` 标签内单引号字符串字面量（**不用于** HTML 内联事件属性如 `onclick`，那属于 attr 上下文）

### CSRF 防护（`lib/CsrfService.php`）

> token 存储在 **cookie**(`bbs_csrf`,有效期 7 天,SameSite=Lax,`httponly=true`)而非 session,避免 session GC(online_hold_time=3600s)清除 session 数据后 token 丢失。`const COOKIE_NAME = 'bbs_csrf'`,`getToken()` 从 `$_COOKIE` 读取。cookie 有效期与 auth cookie 一致(7 天),`SameSite=Lax` 阻止跨站 POST 携带 cookie。

| 方法 | 签名 | 说明 |
|---|---|---|
| `generate` | `CsrfService::generate(): string` | 生成/获取 token(空则 `random_bytes(16)` 并写 cookie) |
| `getToken` | `CsrfService::getToken(): string` | 获取当前 token(从 `$_COOKIE['bbs_csrf']` 读取) |
| `input` | `CsrfService::input(): string` | 返回 `<input type="hidden" name="csrf_token" value="...">` hidden input |
| `check` | `CsrfService::check(): void` | 验证 token。失败时:htmx 请求返回 HTML 错误片段(`Content-Type: text/html`),非 htmx 请求返回 JSON `{"code":"-1","message":"CSRF token verification failed"}`;均 `exit` 终止 |

> **token 传递方式**：`check()` 从 `$_POST['csrf_token']` 或 `HTTP_X_CSRF_TOKEN` 请求头读取（两者任一即可）。`xiuno-modern.js` 的 `XN.ajax()` 会自动注入 `X-CSRF-TOKEN` header。

> **中央化 CSRF 校验**：`index.inc.php` 已对非 GET 请求（POST/PUT/DELETE）统一调用 `CsrfService::check()`（`ai` 路由除外，AIEditor 的 OpenAI client 不支持自定义 headers，`ai.php` 内部有独立校验）。**普通路由无需重复调用 `CsrfService::check()`**；API 入口（`api/v1/bootstrap.php`）走 token 鉴权（`ApiAuthService`），不经过 `index.inc.php`，也无需 CSRF 校验。

```php
// 表单中
<form method="post">
    <?php echo CsrfService::input();?>
    ...
</form>

// htmx/fetch 请求（自动从 X-CSRF-TOKEN header 读取）
// xiuno-modern.js 的 XN.ajax() 会自动注入
```

### LoginSecurityService 登录安全 API（`lib/LoginSecurityService.php`）

> 所有方法均为 `static`。提供 uid 维度和 IP 维度的登录失败锁定，防止暴力破解。阈值与锁定时长由后台 `security-account` 配置（`login_max_attempts` / `login_ban_duration`，默认 5 次 / 900 秒）。

| 方法 | 签名 | 说明 |
|---|---|---|
| `checkBan` | `LoginSecurityService::checkBan($uid)` | 检查 uid 维度锁定。读取 `user.banned_until`，未到期 `message(-1003)` 终止；到期自动重置 `login_attempts=0`/`banned_until=0`。仅对 `ban_type=0`（非 UserBanService 封禁）的用户生效 |
| `checkIpBan` | `LoginSecurityService::checkIpBan($longip)` | 检查 IP 维度锁定。实时统计 `user_login_log` 表中该 IP 在锁定窗口内的失败次数，达阈值则 `message(-1003)` 终止。防止用不存在的用户名枚举绕过 uid 限流 |
| `recordAttempt` | `LoginSecurityService::recordAttempt($uid, $success, $ip, $ua)` | 记录 uid 维度登录尝试：写 `user_login_log` + 更新 `user` 表（成功重置计数，失败累加并达阈值写入 `banned_until`） |
| `recordIpAttempt` | `LoginSecurityService::recordIpAttempt($longip, $success, $ua)` | 记录 IP 维度尝试（uid=0），仅写 `user_login_log` 用于 IP 统计，不依赖真实 uid。用户名/邮箱不存在时调用 |
| `resetAttempts` | `LoginSecurityService::resetAttempts($uid)` | 手动重置 uid 维度锁定（`login_attempts=0`、`banned_until=0`） |

> 跨文件参考：完整登录安全机制（含 `UserBanService` 封禁/禁言、`user_login_log` 表结构、配置项）见 [08-login-security.md](08-login-security.md)。

### Service 缓存自清理范例（StatusService / FriendLinkService）

> 以下两个 Service 是「**Service 写方法自身清理缓存，不依赖调用方**」的范例（来自 bugfix_rules.md 第三章）。前后台入口共用同一 Service 方法时，缓存清理在 Service 内部完成，避免调用方遗漏。

| 方法 | 签名 | 说明 |
|---|---|---|
| `StatusService::setThreadStatus` | `setThreadStatus(int $tid, int $statusId, int $operatorUid): array` | 设置帖子状态（0=清除）。**每个 `return` 前都调用 `self::clearCache()`**，`statusId=0` 清除分支尤其注意。位于 `plugin/xnx_status/model/StatusService.php` |
| `FriendLinkService::create` | `FriendLinkService::create($arr)` | 创建友情链接（`static`）。`db_insert` 后立即 `self::clearCache()`，不依赖调用方清缓存。位于 `plugin/xnx_friendlink/model/FriendLinkService.php` |

```php
// ✅ StatusService::setThreadStatus 范例：每个 return 前清缓存
if ($statusId == 0) {
    db_delete('xnx_status_thread', array('tid' => $tid));
    self::clearCache();  // ← 清除分支 return 前清缓存
    return array('ok' => true, 'message' => '状态已清除');
}
// ... 正常分支末尾也清缓存
self::clearCache();
return array('ok' => true, 'message' => '状态已更新');

// ✅ FriendLinkService::create 范例：Service 内部清缓存
public static function create($arr) {
    $linkid = db_insert('xnx_friendlink', $arr);
    self::clearCache();  // ← 调用方无需再清
    return $linkid;
}
```

---

## 8. 工具函数

### 文件操作

| 函数 | 说明 |
|---|---|
| `xn_mkdir($dir, $chmod = 0777)` | 递归创建目录 |
| `xn_unlink($file)` | 删除文件 |
| `xn_copy($src, $dest)` | 复制文件 |
| `rmdir_recusive($dir)` | 递归删除目录 |
| `file_ext($filename)` | 文件扩展名 |
| `file_pre($filename)` | 文件名（去扩展名） |
| `file_name($filename)` | 文件名含扩展名 |

### HTTP

| 函数 | 签名 |
|---|---|
| `http_get` | `http_get($url, $cookie = '', $timeout = 30, $times = 3)` |
| `http_post` | `http_post($url, $post = '', $cookie = '', $timeout = 30, $times = 3)` |
| `https_get` | `https_get($url, $cookie = '', $timeout = 30, $times = 1)` |
| `https_post` | `https_post($url, $post = '', $cookie = '', $timeout = 30, $times = 1, $method = 'POST')` |

### 格式化

| 函数 | 签名 |
|---|---|
| `humandate` | `humandate($timestamp, $lan = array())` |
| `humannumber` | `humannumber($num)` |
| `humansize` | `humansize($num)` |
| `pagination` | `pagination($url, $totalnum, $page, $pagesize = 20)` |

### 其它

| 函数 | 签名 |
|---|---|
| `xn_rand` | `xn_rand($n = 16)` — 随机 hex 字符串 |
| `xn_log` | `xn_log($s, $file = 'error')` — 写日志 |
| `is_word` | `is_word($s)` — 仅 [a-zA-Z0-9_] |
| `is_email` | `is_email($s)` |
| `ip` | `ip()` — 客户端 IP |
| `is_robot` | `is_robot()` — 爬虫检测 |

### 数组工具（`xiunophp/array.func.php`）

| 函数 | 签名 |
|---|---|
| `array_value` | `array_value($arr, $key, $default = '')` |
| `arrlist_values` | `arrlist_values($arrlist, $key)` — 提取列 |
| `arrlist_key_values` | `arrlist_key_values($arrlist, $key, $value = NULL, $pre = '')` — 提取键值映射 |
| `arrlist_multisort` | `arrlist_multisort($arrlist, $col, $asc = TRUE)` — 按列排序 |
| `arrlist_change_key` | `arrlist_change_key($arrlist, $key = '', $pre = '')` — 按列重建索引 |

---

## 9. 分页

```php
$page = param('page', 1);
$pagesize = 20;
$cond = ['fid' => $fid];
$total = db_count('thread', $cond);
$threadlist = db_find('thread', $cond, ['tid' => -1], $page, $pagesize, 'tid');

// 分页组件 HTML
$pagination = pagination(url("forum-$fid-{page}"), $total, $page, $pagesize);

// 模板中
echo $pagination;
```

---

## 10. 常用全局变量速查

| 变量 | 类型 | 说明 |
|---|---|---|
| `$uid` | int | 当前用户 ID（0=游客） |
| `$user` | array | 当前用户行（login_check 后可用） |
| `$gid` | int | 当前用户组 ID |
| `$group` | array | 当前用户组行 |
| `$grouplist` | array | 所有用户组（gid 索引） |
| `$forumlist` | array | 所有板块（fid 索引） |
| `$forumlist_arr` | array | 板块列表（平铺数组） |
| `$runtime` | array | 运行时统计 |
| `$conf` | array | 全局配置（来自 `conf/conf.default.php` + `conf/conf.php`） |
| `$g_setting` | array | 站点设置（来自 `setting_get('setting')`） |
| `$lang` | array | 语言包 |
| `$header` | array | 页头数据（title/keywords/description/csrf_token 等） |
| `$fid` | int | 当前板块 ID（板块页内） |
| `$tid` | int | 当前帖子 ID（帖子页内） |
| `$route` | string | 当前路由名 |
| `$time` | int | `time()` |
| `$ip` | string | 客户端 IP |

---

> 下一步：[05-frontend-security.md](05-frontend-security.md) 了解前端规范和安全要求。
