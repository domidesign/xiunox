# 后台开发模式速查

> 本文件为后台开发模式速查，详细说明见 [../plugindev/14-plugin-admin-ui.md](../../plugindev/14-plugin-admin-ui.md)

## 目录

- [1. Tab 独立页面模式](#1-tab-独立页面模式)
- [2. 后台入口模式](#2-后台入口模式)
- [3. GET 搜索表单 JS 拦截](#3-get-搜索表单-js-拦截)
- [4. 分页 URL 手动拼接](#4-分页-url-手动拼接)
- [5. 命名快捷函数](#5-命名快捷函数)
- [6. 菜单/侧边栏注册](#6-菜单侧边栏注册)

---

## 1. Tab 独立页面模式

**核心理念**：每个子 tab 是独立 URL 整页跳转，**不是** Bootstrap Tab 组件（`data-bs-toggle="tab"`）切换 DOM（扫描器 `bs_tab_navigation` 会拦截）。

### admin_tab_active 签名

源码：`admin/admin.func.php:178`

```php
function admin_tab_active($arr, $active)
// $arr: array('key' => array('url'=>url('xxx'), 'text'=>lang('xxx')))
// $active: 当前激活的 key
// 输出: <ul class="nav nav-tabs nav-tabs-scroll gap-2"> 导航 HTML
```

### param(3) 分发模式

URL 格式 `?plugin-setting-{dir}-{sub_action}`：

| URL 段 | param() | 示例值 |
|---|---|---|
| `plugin` | `param(0)` | `plugin` |
| `setting` | `param(1)` | `setting` |
| `{dir}` | `param(2)` | `xnx_checkin` |
| `{sub_action}` | `param(3)` | `records` |

### 最小代码片段

```php
$sub_action = param(3);
if (empty($sub_action)) { $sub_action = 'setting'; }

include _include(ADMIN_PATH . 'view/htm/header.inc.htm');
$tabs = array(
    'setting' => array('url' => url('plugin-setting-xnx_checkin-setting'), 'text' => lang('setting')),
    'records' => array('url' => url('plugin-setting-xnx_checkin-records'), 'text' => lang('records')),
);
echo admin_tab_active($tabs, $sub_action);
switch ($sub_action) {
    case 'setting': include _include(APP_PATH . 'plugin/xnx_checkin/view/htm/setting.htm'); break;
    case 'records': include _include(APP_PATH . 'plugin/xnx_checkin/view/htm/records.htm'); break;
}
include _include(ADMIN_PATH . 'view/htm/footer.inc.htm');
```

> 详见 [14-plugin-admin-ui.md 第 5 节](../../plugindev/14-plugin-admin-ui.md#5-tab-独立页面模式)

---

## 2. 后台入口模式

### setting.php vs admin.php 选择表

| 模式 | 入口文件 | URL 格式 | 注册方式 | 适用场景 |
|---|---|---|---|---|
| **setting.php 嵌入式** | `plugin/<dir>/setting.php` | `?plugin-setting-<dir>` 或 `?plugin-setting-<dir>-<sub>` | 自动（setting.php 存在即出"设置"按钮） | 配置项较少，无独立列表页 |
| **admin.php 独立入口** | `plugin/<dir>/admin.php` | `?<dir>_admin` 或自定义 | `hook/admin_index_route_case_end.php` 注册 case | 独立 CRUD 列表/审核/批量操作 |

### 注册 hook 代码片段

```php
// plugin/<dir>/hook/admin_index_route_case_end.php
<?php exit;
case '<dir>_admin': include APP_PATH.'plugin/<dir>/admin.php'; break;
```

conf.json 必须注册：

```json
{ "hooks_rank": { "admin_index_route_case_end.php": 10 } }
```

> ⚠️ case 值禁止含 `-`（`-` 是 URL 参数分隔符，`param(1)` 只取单段）

> 详见 [14-plugin-admin-ui.md 第 6 节](../../plugindev/14-plugin-admin-ui.md#6-插件后台入口模式)

---

## 3. GET 搜索表单 JS 拦截

### 坑点（3 行）

1. 后台 URL `?plugin-setting-xxx.htm` 的路由参数是 query string 的一部分
2. 浏览器原生 GET 提交会**丢弃** action URL 的 query string，只用表单字段作为新 query string
3. 路由参数丢失 → `xn_url_parse` 命中 default 分支 → `http_404()`

### 代码片段（5 行核心）

```javascript
form.addEventListener('submit', function(e) {
    e.preventDefault();
    var base = form.getAttribute('action');  // ?plugin-setting-xxx.htm
    var fd = new FormData(form);
    var parts = [];
    fd.forEach(function(val, key) { if (val !== '') parts.push(key + '=' + encodeURIComponent(val)); });
    var sep = base.indexOf('?') !== -1 ? '&' : '?';
    window.location.href = base + sep + parts.join('&');
});
```

**判定标准**：后台插件 GET 搜索表单（含筛选条件且需翻页保留参数）必须用 JS 拦截 submit 手动构建 URL。

> 详见 [14-plugin-admin-ui.md 第 8 节](../../plugindev/14-plugin-admin-ui.md#8-后台-get-搜索表单-js-拦截)

---

## 4. 分页 URL 手动拼接

### 坑点（3 行）

1. `url($template, $extra)` 内部用 `http_build_query($extra)` 编码参数
2. `{page}` 会被编码为 `%7Bpage%7D`
3. `pagination()` 用 `str_replace('{page}', $i, $url)` 字面量替换，找不到 `%7Bpage%7D` → 分页失效

### 代码片段（5 行核心）

```php
$pagination_base = admin_plugin_setting_url('xnx_checkin');  // ?plugin-setting-xnx_checkin.htm
$pagination_qs = '?';
if ($uid > 0) $pagination_qs .= 'cr_uid=' . intval($uid) . '&';
if (!empty($date_start)) $pagination_qs .= 'cr_date_start=' . urlencode($date_start) . '&';
$pagination_qs .= 'cr_page={page}';  // {page} 不被编码
$pagination = pagination($pagination_base . $pagination_qs, $total, $page, $pagesize);
```

**判定标准**：凡是用 `pagination()` 且 URL 需带筛选参数的场景，`{page}` 必须手动拼接 query string，禁止通过 `url(..., $extra)` 传递。

> 详见 [14-plugin-admin-ui.md 第 9 节](../../plugindev/14-plugin-admin-ui.md#9-后台分页-url-手动拼接)

---

## 5. 命名快捷函数

源码：`model/route.func.php:266-562`。优先级：**命名快捷函数 > `route_url()` > `url()`**，禁止硬编码 `.htm`/`.html` 后缀。

### 核心函数

| 函数 | 签名 | 用途 |
|---|---|---|
| `route_url($name, $args, $query)` | `route_url($name, $args = array(), $query = array())` | 按路由表名生成 URL（内部调 `url()`） |
| `admin_url($url, $extra)` | `admin_url($url, $extra = array())` | 从前台生成指向 admin 后台的 URL（强制 `?xxx.htm` + `/admin/` 前缀） |

### 后台 - 插件相关

| 函数 | 签名 | 用途 |
|---|---|---|
| `admin_plugin_url($query)` | `route_url('admin_plugin', [], $query)` | 插件列表页 `?plugin.htm` |
| `admin_plugin_setting_url($dir, $query)` | `route_url('admin_plugin_setting', ['dir'=>$dir], $query)` | 插件设置页 `?plugin-setting-<dir>.htm` |
| `admin_plugin_install_url($dir, $query)` | `route_url('admin_plugin_install', ['dir'=>$dir], $query)` | 插件安装 `?plugin-install-<dir>.htm` |
| `admin_plugin_enable_url($dir, $query)` | `route_url('admin_plugin_enable', ['dir'=>$dir], $query)` | 启用插件 |
| `admin_plugin_disable_url($dir, $query)` | `route_url('admin_plugin_disable', ['dir'=>$dir], $query)` | 禁用插件 |
| `admin_plugin_unstall_url($dir, $query)` | `route_url('admin_plugin_unstall', ['dir'=>$dir], $query)` | 卸载插件 |
| `admin_plugin_upgrade_url($dir, $query)` | `route_url('admin_plugin_upgrade', ['dir'=>$dir], $query)` | 升级插件 |
| `admin_plugin_scanner_url($query)` | `route_url('admin_plugin_scanner', [], $query)` | 兼容性扫描 |

### 前台 - 帖子/版块/用户

| 函数 | 签名 | 用途 |
|---|---|---|
| `thread_url($tid, $query)` | `route_url('thread', ['tid'=>$tid], $query)` | 前台帖子 `thread-<tid>.htm` |
| `forum_url($fid, $query)` | `route_url('forum', ['fid'=>$fid], $query)` | 前台版块 `forum-<fid>.htm` |
| `user_url($uid, $query)` | `route_url('user', ['uid'=>$uid], $query)` | 前台用户主页 |

### 后台跳前台（关键）

| 函数 | 签名 | 用途 |
|---|---|---|
| `frontend_thread_url($tid, $query)` | `route_url('frontend_thread', ['tid'=>$tid], $query)` | 后台生成前台帖子链接（带 `../` 前缀跳出 admin 目录） |
| `frontend_user_url($uid, $query)` | `route_url('frontend_user', ['uid'=>$uid], $query)` | 后台生成前台用户链接 |
| `frontend_forum_url($fid, $query)` | `route_url('frontend_forum', ['fid'=>$fid], $query)` | 后台生成前台版块链接 |

### 禁止项

| 禁止 | 正确做法 |
|---|---|
| 套 `url()`：`url(admin_plugin_setting_url($dir))` | 直接用 `admin_plugin_setting_url($dir)`（`route_url` 内部已调 `url()`，再套产生 `??xxx.htm.htm` 双后缀） |
| 后台生成前台 URL 用 `url('thread-'.$tid)` | 用 `frontend_thread_url($tid)`（否则 admin 下解析为 admin 子路径 404） |
| 硬编码 `.htm`/`.html` 后缀 | 用命名快捷函数或 `url()` |

> 详见 [14-plugin-admin-ui.md 第 10 节](../../plugindev/14-plugin-admin-ui.md#10-命名快捷函数完整列表)

---

## 6. 菜单/侧边栏注册

### 一级菜单

后台一级菜单写死在 `admin/menu.conf.php`，**不可通过 hook 扩展**（插件不应修改系统文件，`admin/` 受 `protected_paths` 白名单保护）。

### 侧边栏 hook

源码：`admin/view/htm/sidebar.inc.htm`

| Hook 名 | 源码行 | 用途 |
|---|---|---|
| `admin_sidebar_start.htm` | 第 101 行 | 侧边栏顶部，适合放插件独立管理入口 |
| `admin_sidebar_end.htm` | 第 172 行 | 侧边栏底部 |

### 路由注册 hook

| Hook 名 | 用途 |
|---|---|
| `admin_index_route_case_end.php` | 注册独立后台页面路由 case（admin.php 模式必需） |

### 插件"设置"按钮

只要插件目录下存在 `setting.php` 文件，系统在插件列表页自动显示"设置"按钮，链接到 `?plugin-setting-<dir>.htm`，无需额外注册。

> 详见 [14-plugin-admin-ui.md 第 7 节](../../plugindev/14-plugin-admin-ui.md#7-后台菜单侧边栏注册)
