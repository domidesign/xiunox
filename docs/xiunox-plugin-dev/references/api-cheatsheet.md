# API 速查表（精简版）

> 完整版见项目 `docs/04-api-cheatsheet.md`。

---

## 请求输入

```php
param($key, $defval = '', $htmlspecialchars = TRUE, $addslashes = FALSE)
param_word($key, $len = 32)
param_json($key)
param_url($key)
// param(0)=路由, param(1)=action, param(2)=参数
// $defval 类型决定返回类型：0→int, ''→string, []→array
```

## URL / 消息

```php
url($url, $extra = [])                    // 所有链接用 url()
message($code, $message, $extra = [])     // 终止执行：0=成功, <0=系统错, >0=业务错
                                           // $extra: ['redirect_url' => url(...)]
lang($key, $arr = [])                     // 语言替换
jump($message, $url, $delay = 3)
http_404() / http_403() / http_location($url)
```

## DB

```php
// 条件数组（无 PDO bindValue）
['id' => 123]                             // id = 123
['uid' => [1,2,3]]                        // uid IN (1,2,3)
['id' => ['>' => 100, '<' => 200]]        // id > 100 AND id < 200
['name' => ['LIKE' => 'kw']]              // name LIKE '%kw%'

// CRUD
db_insert($table, $arr)                   // → lastInsertId
db_update($table, $cond, $update)         // 支持 ['field+' => 1] 增量
db_delete($table, $cond)
db_find($table, $cond, $orderby, $page, $pagesize, $key, $col)
db_find_one($table, $cond, $orderby, $col)
db_count($table, $cond)
db_maxid($table, $field, $cond)
db_exec($sql)
db_sql_find_one($sql)
db_sql_find($sql, $key)
// 排序：['tid' => -1] DESC, ['created' => 1] ASC
```

## 模型

```php
// Thread
thread_create($arr, &$pid)    // ✅ 业务层
thread_read($tid) / thread_read_cache($tid)
thread_update($tid, $arr)
thread_delete($tid)
thread_format(&$thread)

// Post
post_create($arr, $fid, $gid)
post_read($pid)
post_update($pid, $arr, $tid = 0)
post_delete($pid)
post_format(&$post)

// User
user_create($arr)
user_read($uid) / user_read_cache($uid)
user_update($uid, $arr)       // ⚠️ 受保护字段限制
user_format(&$user)
user_login_check()            // 未登录→跳转
user_change_password($uid, $new, $old, $is_admin)  // ✅ 唯一改密码方式
user_change_group($uid, $new_gid)  // ✅ 唯一改组方式
user_safe_info($user)         // 脱敏

// Forum
forum_read($fid)
forum_list_cache()
forum_list_cache_delete()

// Group
group_list_cache()
```

## 权限

```php
$uid, $gid, $user, $group, $grouplist, $forumlist  // 全局可用
forum_access_user($fid, $gid, 'allowthread')        // 板块用户权限
forum_access_mod($fid, $gid, 'allowdelete')         // 板块版主权限
forum_is_mod($fid, $gid, $uid)
PermissionService::register($plugin, $key, $label)  // 注册自定义权限
PermissionService::check($key, $uid)                // 检查权限
```

## 缓存 / 设置

```php
setting_get('my_plugin')                     // 插件设置
setting_set('my_plugin', $arr)
kv_get($k) / kv_set($k, $v, $life)          // KV 存储
kv_delete($k)
cache_get($k) / cache_set($k, $v, $life)    // 通用缓存
cache_delete($k)
runtime_set('threads+', 1)                   // 运行时增量
pagination($url, $total, $page, $pagesize)   // 分页 HTML
```

## 安全

```php
esc_html($var)            // htmlspecialchars ENT_QUOTES|ENT_HTML5
esc_attr($var)            // 同上（语义别名）
esc_js($var)              // JSON + HTML 转义
CsrfService::input()      // <input type="hidden" name="csrf_token" value="...">
CsrfService::check()      // 验证（失败 exit）
```

## 工具

```php
humandate($timestamp) / humannumber($num) / humansize($num)
xn_rand($n = 16)          // 随机 hex
xn_log($s, $file)         // 日志
http_get($url) / http_post($url, $post)
is_word($s) / is_email($s)
ip() / is_robot()
xn_mkdir($dir) / xn_unlink($file) / xn_copy($src, $dest)
array_value($arr, $key, $default)
arrlist_values($arrlist, $key)              // 提取列
arrlist_multisort($arrlist, $col, $asc)      // 排序
arrlist_change_key($arrlist, $key)          // 重建索引
```

## 全局变量

```php
$uid / $user / $gid / $group / $grouplist / $forumlist
$conf / $g_setting / $lang / $header / $runtime
$fid / $tid / $route / $time / $ip
```
