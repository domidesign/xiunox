# XIUNOX  插件开发教程

## 目录

- [1. 概述](#1-概述)
- [2. 插件机制原理](#2-插件机制原理)
- [3. 插件目录结构](#3-插件目录结构)
- [4. conf.json 配置详解](#4-confjson-配置详解)
- [5. Hook 机制详解](#5-hook-机制详解)
- [6. Overwrite 机制详解](#6-overwrite-机制详解)
- [7. 插件生命周期](#7-插件生命周期)
- [8. 路由与页面开发](#8-路由与页面开发)
- [9. 模型与服务类](#9-模型与服务类)
- [10. 前端开发规范](#10-前端开发规范)
- [11. 安全规范](#11-安全规范)
- [12. 多语言支持](#12-多语言支持)
- [13. 缓存与设置](#13-缓存与设置)
- [14. 完整插件示例：每日签到](#14-完整插件示例每日签到)
- [15. 调试技巧](#15-调试技巧)
- [16. 常见问题](#16-常见问题)

---

## 1. 概述

XIUNOX 提供两种插件扩展方式：

1. **Hook（钩子）**：在源文件的 hook 点注入代码，不修改原文件。适合小范围功能增强，如添加按钮、注入语言、扩展逻辑。
2. **Overwrite（覆盖）**：直接替换源文件，适合大范围修改，如重写整个页面模板。

优先使用 Hook 方式，Overwrite 是最后手段。多个插件可以共存于同一个 hook 点，但同一文件只能被一个 overwrite 覆盖。

---

## 2. 插件机制原理

Xiuno BBS 的插件系统基于**编译时合并**实现。核心流程如下：

1. 系统扫描 `plugin/` 目录下所有包含 `conf.json` 的子目录
2. 读取每个插件的 `conf.json`，获取 hook 列表、overwrite 列表、排序权重
3. 当页面请求到达时，通过 `_include()` 函数编译源文件
4. 编译过程中，将 hook 点替换为对应插件 hook 文件的内容
5. 如果存在 overwrite 文件，则直接使用 overwrite 文件替代原文件
6. 编译结果写入 `tmp/` 缓存目录，后续请求直接使用缓存

关键函数在 `model/plugin.func.php` 中：

- `plugin_compile_srcfile($srcfile)` — 编译源文件，合并 hook 内容
- `plugin_find_overwrite($srcfile)` — 查找最高优先级的 overwrite 文件
- `plugin_compile_srcfile_callback($m)` — 将 hook 点替换为插件代码的回调函数
- `_include($srcfile)` — 编译并返回缓存文件路径

---

## 3. 插件目录结构

```
plugin/my_plugin/
├── conf.json              # 插件配置文件（必需）
├── icon.png               # 插件图标 96x96（推荐）
├── install.php            # 安装脚本
├── uninstall.php          # 卸载脚本
├── setting.php            # 设置页面逻辑
├── hook/                  # Hook 文件目录
│   ├── lang_zh_cn_bbs.php       # 中文语言 hook
│   ├── model_inc_file.php       # 模型引入 hook
│   ├── index_route_case_end.php # 路由注册 hook
│   ├── post_start_init.htm      # 发帖页面初始化 hook
│   └── post_subject_after.htm   # 标题后注入 hook
├── overwrite/             # 覆盖文件目录（按需）
│   └── view/htm/header.inc.htm  # 覆盖头部模板
├── route/                 # 路由文件（按需）
│   └── my_page.php
├── view/                  # 视图目录（按需）
│   └── htm/
│       ├── my_page.htm
│       └── setting.htm
├── model/                 # 模型/服务类目录（按需）
│   └── MyService.php
├── lang/                  # 独立语言包目录（按需）
│   └── zh-cn.php
└── api.php                # API 入口（按需）
```

其中只有 `conf.json` 是必需的，其他文件按需创建。

---

## 4. conf.json 配置详解

```json
{
    "name": "插件名称",
    "brief": "插件简介",
    "version": "1.0",
    "bbs_version": "4.5",
    "installed": 0,
    "enable": 0,
    "hooks_rank": {
        "model_inc_file.php": 10,
        "lang_zh_cn_bbs.php": 10,
        "index_route_case_end.php": 10,
        "post_start_init.htm": 10
    },
    "overwrites_rank": {
        "view/htm/header.inc.htm": 10
    },
    "dependencies": {
        "xn_search": "1.0"
    },
    "type": "plugin"
}
```

### 字段说明

| 字段 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `name` | string | 是 | 插件显示名称 |
| `brief` | string | 是 | 插件简介，支持 HTML |
| `version` | string | 是 | 插件版本号 |
| `bbs_version` | string | 是 | 兼容的 BBS 最低版本 |
| `installed` | int | 是 | 是否已安装（0/1），系统自动维护 |
| `enable` | int | 是 | 是否已启用（0/1），系统自动维护 |
| `hooks_rank` | object | 否 | hook 文件的排序权重 |
| `overwrites_rank` | object | 否 | 覆盖文件的优先级 |
| `dependencies` | object | 否 | 依赖的其他插件 |
| `type` | string | 否 | 插件类型：`"plugin"` 或 `"theme"` |

### hooks_rank 排序规则

当多个插件注册同一个 hook 点时，按 `hooks_rank` 值**升序**执行。值越小越先执行，值越大越后执行。默认值为 0。

```json
"hooks_rank": {
    "model_inc_file.php": 10,
    "lang_zh_cn_bbs.php": 10
}
```

key 是 `hook/` 目录下的文件名（不含路径），value 是排序权重。

### overwrites_rank 优先级规则

当多个插件覆盖同一文件时，`overwrites_rank` 值最大的生效，其他插件的覆盖文件被忽略。

```json
"overwrites_rank": {
    "view/htm/header.inc.htm": 10
}
```

key 是相对于项目根目录的文件路径，value 是优先级权重。

### dependencies 依赖声明

```json
"dependencies": {
    "xn_search": "1.0"
}
```

key 是依赖插件的目录名，value 是最低版本号。安装插件时系统会检查依赖是否已安装并启用。

---

## 5. Hook 机制详解

### 5.1 hook 点的命名与位置

hook 点在源文件中以注释形式存在，有两种格式：

**PHP 文件中的 hook 点：**
```php
// hook hook_name.php
```

**HTM 模板文件中的 hook 点：**
```html
<!--{hook hook_name}-->
```

或：
```php
// hook hook_name.htm
```

### 5.2 hook 文件命名规则

插件 `hook/` 目录下的文件名必须与源文件中的 hook 点名称完全一致。

例如，源文件中有 `<!--{hook post_subject_after}-->`，则 hook 文件名为 `post_subject_after.htm`。

源文件中有 `// hook model_inc_file.php`，则 hook 文件名为 `model_inc_file.php`。

### 5.3 PHP hook 文件编写

PHP hook 文件必须以 `<?php\nexit;` 开头。Xiuno 编译时会自动去除这个头部：

```php
<?php
exit;

// 你的代码
$my_var = 'hello';
$db->find_one('my_table', array('id' => $id));
```

**重要**：`exit;` 是安全保护，防止 hook 文件被直接通过 URL 访问。编译时系统会用正则去除 `<?php exit;` 部分，只保留后续代码注入到目标文件中。

实际编译时的处理逻辑（来自 `plugin.func.php`）：

```php
if($fileext == 'php' && preg_match('#^\s*<\?php\s+exit;#is', $t)) {
    $t = preg_replace('#^\s*<\?php\s*exit;(.*?)(?:\?>)?\s*$#is', '\\1', $t);
}
```

也兼容不带 `exit;` 的裸 `<?php` 开头：

```php
elseif($fileext == 'php') {
    $t = preg_replace('#^\s*<\?php\s*#', '', $t);
    $t = preg_replace('#\?>\s*$#', '', $t);
}
```

### 5.4 HTM hook 文件编写

HTM hook 文件直接写 HTML/PHP 混合代码，无需 `<?php exit;` 头部：

```html
<div class="my-plugin-section">
    <i class="ti ti-star me-1"></i>
    <span><?php echo esc_html($my_var); ?></span>
</div>
```

### 5.5 语言 hook 文件编写

语言 hook 文件名格式为 `lang_{locale}_bbs.php`，例如：

- `lang_zh_cn_bbs.php` — 简体中文
- `lang_en_us_bbs.php` — 英文
- `lang_zh_tw_bbs.php` — 繁体中文

```php
<?php
exit;

$lang['my_plugin_title'] = '我的插件';
$lang['my_plugin_desc'] = '插件描述';
$lang['my_plugin_success'] = '操作成功';
$lang['my_plugin_failed'] = '操作失败';
```

**语言 key 必须使用插件名前缀**，避免与其他插件冲突。例如 `my_plugin_title` 而不是 `title`。

语言 hook 有额外的安全检查：系统会验证每行是否为合法的 `$lang['key'] = 'value';` 赋值语句，不合法的行会被跳过并记录日志。

### 5.6 路由注册 hook

通过 `index_route_case_end.php` hook 注册自定义路由：

```php
<?php
exit;

case 'my_plugin': include APP_PATH.'plugin/my_plugin/index.php'; break;
case 'my_plugin_api': include APP_PATH.'plugin/my_plugin/api.php'; break;
case 'my_plugin_rank': include APP_PATH.'plugin/my_plugin/rank.php'; break;
```

这样访问 `/?my_plugin.htm` 就会执行 `index.php`。

### 5.7 模型引入 hook

通过 `model_inc_file.php` hook 引入自定义模型/服务类：

```php
<?php
exit;

APP_PATH.'plugin/my_plugin/model/MyService.php',
```

注意：这个 hook 的内容会被拼接到一个 PHP 数组中，所以每行需要以逗号结尾。

引入后，在项目的任何位置都可以使用 `MyService` 类。

### 5.8 常用 hook 点一览

以下是前台模板中常用的 hook 点：

| hook 点 | 所在文件 | 用途 |
|---------|----------|------|
| `header_nav_start.htm` | header_nav.inc.htm | 导航栏开始 |
| `header_nav_logo_after.htm` | header_nav.inc.htm | Logo 之后 |
| `header_nav_custom_after.htm` | header_nav.inc.htm | 自定义导航之后 |
| `header_nav_search_after.htm` | header_nav.inc.htm | 搜索框之后 |
| `header_nav_user_menu_before.htm` | header_nav.inc.htm | 用户菜单之前 |
| `index_site_brief_after.htm` | 首页 | 站点简介之后 |
| `index_site_brief_stats_after.htm` | 首页 | 站点统计之后 |
| `index_site_brief_post_after.htm` | 首页 | 发帖按钮区域 |
| `post_start_init.htm` | post.htm | 发帖页面初始化（PHP 逻辑） |
| `post_subject_after.htm` | post.htm | 标题输入框之后 |
| `post_message_after.htm` | post.htm | 内容编辑区之后 |
| `post_bottom_right.htm` | post.htm | 表单右下角按钮区 |
| `post_js.htm` | post.htm | 发帖页面 JS 区域 |
| `thread_subject_after.htm` | 帖子详情 | 帖子标题之后 |
| `thread_message_before.htm` | 帖子详情 | 帖子内容之前 |
| `thread_message_after.htm` | 帖子详情 | 帖子内容之后 |
| `thread_js.htm` | 帖子详情 | 帖子页面 JS 区域 |
| `footer_js_after.htm` | footer.inc.htm | 全局 JS 之后 |
| `footer_body_after.htm` | footer.inc.htm | body 结束之前 |
| `my_sidebar_nav_after.htm` | 个人中心 | 侧边栏导航之后 |
| `user_nav_end.htm` | 用户页 | 用户导航结束 |

后台管理页面也有对应的 hook 点，命名规则为 `admin_` 前缀，如 `admin_index_start.htm`、`admin_setting_base_end.htm` 等。

---

## 6. Overwrite 机制详解

### 6.1 基本用法

覆盖文件放在 `plugin/{插件名}/overwrite/` 目录下，路径对应项目根目录的文件路径。

例如：
```
plugin/my_plugin/overwrite/view/htm/header.inc.htm
```
会覆盖：
```
view/htm/header.inc.htm
```

### 6.2 优先级规则

多个插件覆盖同一文件时，`overwrites_rank` 值最大的生效，其他插件的覆盖被忽略。

```json
"overwrites_rank": {
    "view/htm/header.inc.htm": 10
}
```

如果插件 A 的权重为 10，插件 B 的权重为 20，则插件 B 的覆盖文件生效。

### 6.3 注意事项

- Overwrite 是完全替换，不是追加。覆盖文件需要包含原文件的所有内容，再加上你的修改。
- 优先使用 Hook 方式，Overwrite 是最后手段。
- 使用 Overwrite 时，原文件的 hook 点仍然有效，但需要在覆盖文件中保留这些 hook 点注释。
- Overwrite 会导致与其他插件的兼容性问题，尽量避免。

---

## 7. 插件生命周期

### 7.1 install.php — 安装脚本

安装时执行，通常用于创建数据库表和初始化设置。

```php
<?php
!defined('DEBUG') AND exit('Forbidden');

$tablepre = $db->tablepre;

// 创建数据表
$sql = "CREATE TABLE IF NOT EXISTS {$tablepre}my_table (
    id int(11) unsigned NOT NULL AUTO_INCREMENT,
    uid int(11) unsigned NOT NULL DEFAULT 0,
    name varchar(64) NOT NULL DEFAULT '',
    create_time int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";
db_exec($sql);

// 初始化设置
setting_set('my_plugin', array(
    'max_items' => 10,
    'enable_feature' => 1,
));
```

**要点：**

- 必须以 `!defined('DEBUG') AND exit('Forbidden');` 开头，防止直接访问
- 使用 `$db->tablepre` 获取表前缀，确保兼容不同安装
- 使用 `db_exec()` 执行 SQL
- 使用 `setting_set()` 初始化默认配置

### 7.2 uninstall.php — 卸载脚本

卸载时执行，通常用于删除数据库表和清除设置。

```php
<?php
!defined('DEBUG') AND exit('Forbidden');

$tablepre = $db->tablepre;

// 删除数据表
db_exec("DROP TABLE IF EXISTS {$tablepre}my_table");

// 清除设置
setting_delete('my_plugin');
```

**要点：**

- 卸载脚本应该清理所有该插件创建的数据
- 使用 `DROP TABLE IF EXISTS` 避免表不存在时报错
- 使用 `setting_delete()` 清除插件设置

### 7.3 setting.php — 设置页面逻辑

后台插件列表中点击"设置"时访问此文件。

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

// 权限检查：仅管理员和版主可访问
if($gid != 1 && $gid != 2) {
    message(-1, lang('user_group_insufficient_privilege'));
}

$action = param('my_action', '');

// 处理保存操作
if($action == 'save') {
    CsrfService::check();
    $settings = array(
        'max_items' => intval(param('max_items', 10)),
        'enable_feature' => intval(param('enable_feature', 1)),
    );
    // 参数范围校验
    $settings['max_items'] = max(1, min(100, $settings['max_items']));
    setting_set('my_plugin', $settings);
    message(0, lang('save_success'));
}

// 加载当前设置
$settings = setting_get('my_plugin');
if(empty($settings)) {
    $settings = array(
        'max_items' => 10,
        'enable_feature' => 1,
    );
}

// 引入设置页面模板
include _include(APP_PATH.'plugin/my_plugin/view/htm/setting.htm');
```

**要点：**

- 必须进行权限检查
- POST 操作必须调用 `CsrfService::check()` 验证 CSRF
- 使用 `param()` 函数获取请求参数
- 使用 `message()` 函数返回操作结果
- 使用 `_include()` 引入模板（支持 hook 和 overwrite 机制）

---

## 8. 路由与页面开发

### 8.1 注册路由

通过 `index_route_case_end.php` hook 注册路由：

```php
<?php
exit;

case 'my_plugin': include APP_PATH.'plugin/my_plugin/index.php'; break;
case 'my_plugin_detail': include APP_PATH.'plugin/my_plugin/detail.php'; break;
case 'my_plugin_api': include APP_PATH.'plugin/my_plugin/api.php'; break;
```

访问 URL 格式：`/?my_plugin.htm`、`/?my_plugin_detail-123.htm`

### 8.2 路由文件编写

路由文件是标准的 PHP 文件，负责参数获取、数据查询和模板渲染：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

// 获取参数
$id = param(1, 0);       // URL 第一段参数
$page = param(2, 1);     // URL 第二段参数，默认第1页
$keyword = param('keyword', '');  // 查询参数

// 数据查询
$item = db_find_one('my_table', array('id' => $id));
if(empty($item)) {
    error_page(404, '内容不存在');
}

// 分页
$pagesize = 20;
$total = db_count('my_table', array('status' => 1));
$pagination = pagination(url("my_plugin_detail-$id-{page}"), $total, $page, $pagesize);

// SEO 信息
$header['title'] = $item['name'] . ' - ' . $conf['sitename'];

// 渲染模板
include _include(APP_PATH.'view/htm/header.inc.htm');
include _include(APP_PATH.'plugin/my_plugin/view/htm/detail.htm');
include _include(APP_PATH.'view/htm/footer.inc.htm');
```

### 8.3 URL 生成

使用 `url()` 函数生成 URL：

```php
url('my_plugin');                    // ?my_plugin.htm
url('my_plugin_detail-123');         // ?my_plugin_detail-123.htm
url("my_plugin_detail-$id-{page}"); // 带分页占位符
url('plugin-setting-my_plugin');     // 后台设置页
url('user-login');                   // 登录页
url("thread-$tid");                  // 帖子详情
url("user-$uid");                    // 用户主页
```

### 8.4 常用全局变量

在路由和模板中可以直接使用的全局变量：

| 变量 | 类型 | 说明 |
|------|------|------|
| `$uid` | int | 当前登录用户 ID，未登录为 0 |
| `$user` | array | 当前登录用户信息 |
| `$gid` | int | 当前用户组 ID（1=管理员, 2=版主） |
| `$conf` | array | 站点配置 |
| `$header` | array | 页面头部信息（title/keywords/description） |
| `$time` | int | 当前时间戳 |
| `$db` | object | 数据库实例 |
| `$tablepre` | string | 表前缀（通过 `$db->tablepre` 获取） |
| `$lang` | array | 当前语言包 |

### 8.5 常用函数

| 函数 | 说明 |
|------|------|
| `param($key, $default)` | 获取请求参数 |
| `message($code, $msg)` | 返回 JSON 消息（code=0 成功，其他失败） |
| `error_page($code, $msg)` | 显示错误页面 |
| `setting_get($key)` | 获取设置 |
| `setting_set($key, $value)` | 保存设置 |
| `setting_delete($key)` | 删除设置 |
| `db_exec($sql)` | 执行 SQL |
| `db_find_one($table, $cond)` | 查询单条记录 |
| `db_find($table, $cond, $order, $page, $pagesize, $key)` | 查询多条记录 |
| `db_count($table, $cond)` | 统计记录数 |
| `db_insert($table, $arr)` | 插入记录 |
| `db_update($table, $cond, $arr)` | 更新记录 |
| `db_delete($table, $cond)` | 删除记录 |
| `user_read($uid)` | 读取用户信息 |
| `user_update($uid, $arr)` | 更新用户信息 |
| `forum_read($fid)` | 读取版块信息 |
| `thread_format(&$thread)` | 格式化帖子数据 |
| `pagination($url, $total, $page, $pagesize)` | 生成分页 HTML |
| `lang($key, $args)` | 获取语言文本 |
| `esc_html($str)` | HTML 转义输出 |
| `xn_json_encode($arr)` | JSON 编码 |
| `cache_get($key)` | 获取缓存 |
| `cache_set($key, $value, $ttl)` | 设置缓存 |

---

## 9. 模型与服务类

### 9.1 服务类编写规范

推荐使用静态方法的服务类，方便在 hook 和路由中直接调用：

```php
<?php
!defined('DEBUG') AND exit('Forbidden');

class MyService {

    /**
     * 创建记录
     */
    public static function create($data) {
        global $time;
        $data['create_time'] = $time;
        return db_insert('my_table', $data);
    }

    /**
     * 读取记录
     */
    public static function read($id) {
        return db_find_one('my_table', array('id' => $id));
    }

    /**
     * 更新记录
     */
    public static function update($id, $data) {
        return db_update('my_table', array('id' => $id), $data);
    }

    /**
     * 删除记录
     */
    public static function delete($id) {
        return db_delete('my_table', array('id' => $id));
    }

    /**
     * 获取列表
     */
    public static function findList($cond = array(), $page = 1, $pagesize = 20) {
        return db_find('my_table', $cond, array('id' => -1), $page, $pagesize);
    }

    /**
     * 获取设置
     */
    public static function getSettings() {
        $settings = setting_get('my_plugin');
        if(empty($settings)) {
            $settings = array(
                'max_items' => 10,
                'enable_feature' => 1,
            );
        }
        return $settings;
    }

    /**
     * 保存设置
     */
    public static function saveSettings($arr) {
        return setting_set('my_plugin', $arr);
    }
}
```

### 9.2 引入服务类

通过 `model_inc_file.php` hook 引入：

```php
<?php
exit;

APP_PATH.'plugin/my_plugin/model/MyService.php',
```

引入后，在项目的任何位置都可以直接使用 `MyService::xxx()` 调用。

### 9.3 数据库查询条件

`db_find` 和 `db_find_one` 支持的条件格式：

```php
// 精确匹配
array('uid' => 123)

// 多条件 AND
array('uid' => 123, 'status' => 1)

// IN 查询
array('uid' => array(1, 2, 3))

// LIKE 查询
array('name' => array('LIKE' => $keyword))

// 比较查询
array('threads' => array('>' => 0))
array('create_time' => array('>=' => $start_time))
array('create_time' => array('<=' => $end_time))

// 排序：-1 降序，1 升序
db_find('my_table', $cond, array('id' => -1), $page, $pagesize);
```

---

## 10. 前端开发规范

### 10.1 技术栈

- **UI 框架**：Bootstrap 5.3+
- **图标库**：Tabler Icons
- **交互框架**：htmx 4.x
- **JS 兼容层**：xiuno-modern.js（XN 命名空间）
- **布局**：`.container` 居中

### 10.2 禁止事项

- 禁止使用 jQuery（新代码）
- 禁止使用 Alpine.js（x-data/x-show/x-bind/x-text/x-on/x-model 等属性）
- 禁止使用 idiomorph/alpine-morph 扩展（htmx 4 内置 morph）

### 10.3 htmx 4 使用

htmx 4 是前端交互的核心框架，遵循以下原则：

**原则 1：htmx 管一切交互**

- 服务端交互：`hx-get`、`hx-post`、`hx-boost`
- 纯前端交互：`hx-live`、`hx-on`
- 乐观更新：`hx-optimistic`

**原则 2：DOM 是唯一状态源**

- 状态存在 DOM 中：`data-*` 属性、hidden input、元素内容
- 不使用 JavaScript 全局变量存储 UI 状态

**原则 3：乐观更新用 hx-optimistic**

- 点赞、收藏、关注等即时反馈场景使用 `hx-optimistic`
- 服务端返回更新后的 HTML，htmx 自动替换乐观内容

**原则 4：morph 保留状态**

- 使用 `innerMorph` / `outerMorph` 保留表单输入等 DOM 状态

**原则 5：事件驱动通信**

- 组件间通过 htmx 事件通信
- 使用 `htmx.trigger()` 触发自定义事件
- 使用 `htmx.on()` 监听事件

#### 常用 htmx 属性示例

```html
<!-- 点击加载内容到指定元素 -->
<button hx-get="?thread-123.htm" hx-target="#content" hx-swap="innerHTML">加载</button>

<!-- 表单提交 -->
<form hx-post="?post-create.htm" hx-target="#result">
    <?php CsrfService::input(); ?>
    <input type="text" name="subject" required>
    <button type="submit">提交</button>
</form>

<!-- 删除确认 -->
<button hx-delete="?thread-delete.htm" hx-vals='{"tid": 123}'
        hx-confirm="确定要删除吗？"
        hx-target="closest .thread-item"
        hx-swap="outerHTML">删除</button>

<!-- 链接增强 -->
<a href="?thread-123.htm" hx-boost="true">帖子标题</a>
```

### 10.4 xiuno-modern.js（XN 命名空间）

xiuno-modern.js 提供了不依赖 jQuery 的原生 JS API，新代码应优先使用。

#### DOM 选择

```javascript
XN.$('#my-element')       // 等价于 document.querySelector
XN.$$('.item')            // 等价于 Array.from(document.querySelectorAll)
```

#### AJAX

```javascript
// Promise 方式
XN.ajax('GET', url)       // 返回 Promise
XN.ajax('POST', url, data)

// 回调方式
XN.get(url, callback)
XN.post(url, data, callback)
```

#### DOM 操作

```javascript
XN.addClass(el, cls)      // 添加 class
XN.removeClass(el, cls)   // 移除 class
XN.toggleClass(el, cls)   // 切换 class
XN.hasClass(el, cls)      // 检查 class
XN.show(el)               // 显示元素
XN.hide(el)               // 隐藏元素
XN.toggle(el)             // 切换显示
XN.attr(el, name, value)  // 获取/设置属性
XN.val(el, value)         // 获取/设置值
XN.html(el, content)      // 获取/设置 innerHTML
XN.text(el, content)      // 获取/设置 textContent
```

#### 事件

```javascript
XN.on(el, 'click', handler)                    // 绑定事件
XN.on(document, 'click', '.delete-btn', handler) // 事件委托
XN.off(el, 'click', handler)                   // 解绑事件
XN.ready(fn)                                    // DOM 就绪
```

#### Toast 提示

```javascript
XN.toast('操作成功', 'success')   // type: success/danger/warning/info
XN.toast('操作失败', 'danger')
```

#### 表单

```javascript
XN.serialize('#my-form')              // 序列化表单为对象
XN.submit('#my-form', url, callback)  // 提交表单
```

#### Cookie / Storage

```javascript
XN.cookie(name, val, time)           // 设置 cookie
XN.cookie(name)                       // 获取 cookie
XN.storage.set(key, val)              // 设置 localStorage
XN.storage.get(key)                   // 获取 localStorage
XN.storage.remove(key)                // 删除 localStorage
```

### 10.5 Bootstrap 5 组件

```javascript
// Modal
new bootstrap.Modal(element).show()
bootstrap.Modal.getInstance(element).hide()

// Dropdown
new bootstrap.Dropdown(element).toggle()

// Tooltip
new bootstrap.Tooltip(element)

// Toast
new bootstrap.Toast(element, { delay: 3000 }).show()
```

### 10.6 Tabler Icons

```html
<i class="ti ti-heart"></i>
<i class="ti ti-star"></i>
<i class="ti ti-calendar-check"></i>
<i class="ti ti-trash"></i>
<i class="ti ti-pencil"></i>
<i class="ti ti-search"></i>
<i class="ti ti-tag"></i>
<i class="ti ti-message-circle"></i>
<i class="ti ti-settings"></i>
```

图标查询：https://tabler.io/icons

---

## 11. 安全规范

### 11.1 CSRF 防护

所有 POST 表单必须包含 CSRF token：

```html
<form method="post" action="...">
    <?php CsrfService::input(); ?>
    <!-- 其他表单字段 -->
</form>
```

在 PHP 逻辑中验证：

```php
if($method == 'POST') {
    CsrfService::check();
    // 处理逻辑
}
```

AJAX 请求也需要携带 CSRF token：

```javascript
// xiuno-modern.js 会自动从页面中的 hidden input 获取 token
// 通过 XN.ajax() 发送请求时会自动添加 X-CSRF-Token 头
XN.ajax('POST', url, data);

// 手动获取 token
var csrfToken = XN.csrfToken;
```

### 11.2 XSS 防护

输出用户内容时必须使用 `esc_html()` 转义：

```php
<?php echo esc_html($user_input); ?>
```

`esc_html()` 由 `EscapeService` 提供，会对 HTML 特殊字符进行转义。

### 11.3 SQL 注入防护

使用参数化查询，不要拼接 SQL：

```php
// 正确：使用 db_* 函数
db_find_one('my_table', array('uid' => $uid));
db_insert('my_table', $data);

// 正确：db_exec 中使用表前缀变量
$sql = "CREATE TABLE IF NOT EXISTS {$tablepre}my_table (...)";
db_exec($sql);

// 错误：直接拼接用户输入
$sql = "SELECT * FROM my_table WHERE name = '$name'";  // 禁止
```

### 11.4 权限检查

设置页面和管理操作必须检查权限：

```php
// 仅管理员和版主
if($gid != 1 && $gid != 2) {
    message(-1, lang('user_group_insufficient_privilege'));
}

// 仅管理员
if($gid != 1) {
    message(-1, lang('user_group_insufficient_privilege'));
}

// 检查登录状态
if(empty($uid)) {
    message(-1, '请先登录');
}
```

### 11.5 参数校验

所有用户输入都应进行校验：

```php
$id = intval(param('id', 0));           // 整数
$name = trim(param('name', ''));         // 字符串去空格
$page = max(1, intval(param('page', 1))); // 页码最小为1
$pagesize = min(100, max(1, intval(param('pagesize', 20)))); // 限制范围
```

---

## 12. 多语言支持

### 12.1 通过 hook 注入语言

插件通过语言 hook 注入语言 key，文件名格式为 `lang_{locale}_bbs.php`：

```
plugin/my_plugin/hook/
├── lang_zh_cn_bbs.php    # 简体中文
├── lang_en_us_bbs.php    # 英文
└── lang_zh_tw_bbs.php    # 繁体中文
```

### 12.2 语言文件编写

```php
<?php
exit;

$lang['my_plugin_title'] = '我的插件';
$lang['my_plugin_desc'] = '插件描述';
$lang['my_plugin_success'] = '操作成功';
$lang['my_plugin_confirm_delete'] = '确定删除 "%s" 吗？';
```

### 12.3 使用语言文本

```php
// 简单文本
echo lang('my_plugin_title');

// 带参数文本
echo lang('my_plugin_confirm_delete', array($name));
```

### 12.4 语言 key 命名规范

必须使用插件名前缀，格式为 `插件名_功能描述`：

```
my_plugin_title          // 正确
my_plugin_delete_confirm // 正确
title                    // 错误：无前缀，可能冲突
```

### 12.5 独立语言包

插件也可以使用独立的语言包文件（不通过 hook 注入）：

```
plugin/my_plugin/lang/
└── zh-cn.php
```

```php
<?php
return array(
    'my_plugin_title' => '我的插件',
    'my_plugin_desc' => '插件描述',
);
```

在路由中手动加载：

```php
$my_lang = include APP_PATH.'plugin/my_plugin/lang/zh-cn.php';
```

---

## 13. 缓存与设置

### 13.1 插件设置

使用 `setting_set` / `setting_get` 存取设置：

```php
// 保存设置
setting_set('my_plugin', array(
    'max_items' => 10,
    'enable_feature' => 1,
));

// 读取设置
$settings = setting_get('my_plugin');

// 删除设置
setting_delete('my_plugin');
```

设置数据存储在 `kv` 表中，以序列化形式保存。

### 13.2 缓存操作

使用 `cache_get` / `cache_set` 进行缓存：

```php
// 获取缓存
$data = cache_get('my_plugin_stats');

// 设置缓存（60秒过期）
if($data === NULL) {
    $data = MyService::getStats();
    cache_set('my_plugin_stats', $data, 60);
}
```

### 13.3 编译缓存

修改模板后需要清理 `tmp/` 目录的编译缓存：

```php
// 代码中清理
plugin_clear_tmp_dir();
```

或手动删除 `tmp/` 目录下的文件。

---

## 14. 完整插件示例：每日签到

以下是一个完整的「每日签到」插件示例，包含所有核心功能。

### 14.1 conf.json

```json
{
    "name": "每日签到",
    "brief": "每日签到、积分奖励、连续签到奖励、排行榜",
    "version": "1.0",
    "bbs_version": "4.5",
    "installed": 0,
    "enable": 0,
    "hooks_rank": {
        "lang_zh_cn_bbs.php": 10,
        "model_inc_file.php": 10,
        "index_route_case_end.php": 10,
        "index_site_brief_post_after.htm": 10,
        "index_site_brief_stats_after.htm": 10
    },
    "overwrites_rank": [],
    "dependencies": [],
    "type": "plugin"
}
```

### 14.2 install.php

```php
<?php
!defined('DEBUG') AND exit('Forbidden');

$tablepre = $db->tablepre;

// 创建签到日志表
$sql = "CREATE TABLE IF NOT EXISTS {$tablepre}my_checkin_log (
    logid int(11) unsigned NOT NULL AUTO_INCREMENT,
    uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户UID',
    checkin_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '签到日期(Ymd)',
    checkin_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '签到时间戳',
    continuous_days int(11) unsigned NOT NULL DEFAULT 0 COMMENT '连续签到天数',
    total_days int(11) unsigned NOT NULL DEFAULT 0 COMMENT '累计签到天数',
    reward int(11) unsigned NOT NULL DEFAULT 0 COMMENT '本次奖励积分',
    PRIMARY KEY (logid),
    UNIQUE KEY uid_date (uid, checkin_date),
    KEY uid (uid),
    KEY total_days (total_days DESC),
    KEY continuous_days (continuous_days DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='签到日志'";
db_exec($sql);

// 初始化默认设置
setting_set('my_checkin', array(
    'reward_base' => 5,
    'reward_continuous_3' => 10,
    'reward_continuous_7' => 20,
    'reward_continuous_30' => 50,
));
```

### 14.3 uninstall.php

```php
<?php
!defined('DEBUG') AND exit('Forbidden');

$tablepre = $db->tablepre;
db_exec("DROP TABLE IF EXISTS {$tablepre}my_checkin_log");
setting_delete('my_checkin');
```

### 14.4 hook/lang_zh_cn_bbs.php

```php
<?php
exit;

$lang['my_checkin'] = '签到';
$lang['my_checkin_daily'] = '每日签到';
$lang['my_checkin_btn'] = '立即签到';
$lang['my_checkin_already'] = '今日已签到';
$lang['my_checkin_login'] = '登录后签到';
$lang['my_checkin_continuous'] = '连续签到';
$lang['my_checkin_total'] = '累计签到';
$lang['my_checkin_days'] = '天';
$lang['my_checkin_reward'] = '获得积分';
$lang['my_checkin_success'] = '签到成功';
$lang['my_checkin_failed'] = '签到失败';
$lang['my_checkin_already_done'] = '今日已签到';
$lang['my_checkin_rank'] = '签到排行';
$lang['my_checkin_rank_total'] = '累计签到榜';
$lang['my_checkin_rank_continuous'] = '连续签到榜';
$lang['my_checkin_settings'] = '签到设置';
$lang['my_checkin_reward_base'] = '基础奖励积分';
$lang['my_checkin_reward_continuous'] = '连续签到额外奖励';
$lang['my_checkin_save'] = '保存设置';
$lang['my_checkin_today_count'] = '今日签到人数';
$lang['my_checkin_no_checkin'] = '暂无签到';
```

### 14.5 hook/model_inc_file.php

```php
<?php
exit;

APP_PATH.'plugin/my_checkin/model/CheckinService.php',
```

### 14.6 hook/index_route_case_end.php

```php
<?php
exit;

case 'my_checkin': include APP_PATH.'plugin/my_checkin/index.php'; break;
case 'my_checkin_api': include APP_PATH.'plugin/my_checkin/api.php'; break;
```

### 14.7 hook/index_site_brief_post_after.htm

在首页发帖按钮区域注入签到按钮：

```html
<?php
if (!defined('DEBUG')) exit('Forbidden');

// 获取签到状态
$my_checkin_status = array('is_checkin' => 0);
if (!empty($uid)) {
    $my_checkin_status = CheckinService::getCheckinStatus($uid);
}

if (empty($uid)) {
    echo '<a role="button" class="btn btn-outline-primary flex-fill rounded-pill" href="' . url('user-login') . '"><i class="ti ti-calendar-check me-1"></i>' . lang('my_checkin_login') . '</a>';
} elseif (!empty($my_checkin_status['is_checkin'])) {
    echo '<a role="button" class="btn btn-outline-secondary flex-fill rounded-pill" href="/?my_checkin.htm"><i class="ti ti-circle-check me-1"></i>' . lang('my_checkin_already') . '</a>';
} else {
    echo '<a role="button" class="btn btn-outline-primary flex-fill rounded-pill" href="/?my_checkin.htm"><i class="ti ti-calendar-check me-1"></i>' . lang('my_checkin_btn') . '</a>';
}
?>
```

### 14.8 hook/index_site_brief_stats_after.htm

在首页统计区域注入签到人数：

```html
<?php
if (!defined('DEBUG')) exit('Forbidden');

// 缓存60秒
$my_checkin_today = cache_get('my_checkin_today_count');
if ($my_checkin_today === NULL) {
    $my_checkin_today = CheckinService::getTodayCount();
    cache_set('my_checkin_today_count', $my_checkin_today, 60);
}

echo '<div class="text-body-secondary small">';
echo '<i class="ti ti-calendar-check text-primary me-1"></i>' . lang('my_checkin_today_count') . ' <strong class="text-body">' . intval($my_checkin_today) . '</strong>';
echo '</div>';
?>
```

### 14.9 model/CheckinService.php

```php
<?php
!defined('DEBUG') AND exit('Forbidden');

class CheckinService {

    /**
     * 执行签到
     */
    public static function doCheckin($uid) {
        global $time, $db;
        $tablepre = $db->tablepre;

        $today = intval(date('Ymd'));

        // 检查是否已签到
        $exists = db_find_one('my_checkin_log', array('uid' => $uid, 'checkin_date' => $today));
        if (!empty($exists)) {
            return array('ok' => false, 'message' => lang('my_checkin_already_done'));
        }

        // 获取昨日签到记录，计算连续天数
        $yesterday = intval(date('Ymd', strtotime('-1 day')));
        $last = db_find_one('my_checkin_log', array('uid' => $uid, 'checkin_date' => $yesterday));

        $continuous_days = $last ? intval($last['continuous_days']) + 1 : 1;
        $total_days = self::getTotalDays($uid) + 1;

        // 计算奖励
        $settings = self::getSettings();
        $reward = intval($settings['reward_base']);

        // 连续签到额外奖励
        if ($continuous_days >= 30) {
            $reward += intval($settings['reward_continuous_30']);
        } elseif ($continuous_days >= 7) {
            $reward += intval($settings['reward_continuous_7']);
        } elseif ($continuous_days >= 3) {
            $reward += intval($settings['reward_continuous_3']);
        }

        // 插入签到记录
        $arr = array(
            'uid' => $uid,
            'checkin_date' => $today,
            'checkin_time' => $time,
            'continuous_days' => $continuous_days,
            'total_days' => $total_days,
            'reward' => $reward,
        );
        db_insert('my_checkin_log', $arr);

        // 增加用户积分
        user_update($uid, array('credits+' => $reward));

        return array(
            'ok' => true,
            'message' => lang('my_checkin_success'),
            'reward' => $reward,
            'continuous_days' => $continuous_days,
            'total_days' => $total_days,
        );
    }

    /**
     * 获取签到状态
     */
    public static function getCheckinStatus($uid) {
        $today = intval(date('Ymd'));
        $today_log = db_find_one('my_checkin_log', array('uid' => $uid, 'checkin_date' => $today));

        $last = db_find_one('my_checkin_log', array('uid' => $uid), array('logid' => -1));

        return array(
            'is_checkin' => !empty($today_log) ? 1 : 0,
            'continuous_days' => $last ? intval($last['continuous_days']) : 0,
            'total_days' => self::getTotalDays($uid),
            'last_reward' => $last ? intval($last['reward']) : 0,
        );
    }

    /**
     * 获取累计签到天数
     */
    public static function getTotalDays($uid) {
        return db_count('my_checkin_log', array('uid' => $uid));
    }

    /**
     * 获取今日签到人数
     */
    public static function getTodayCount() {
        $today = intval(date('Ymd'));
        return db_count('my_checkin_log', array('checkin_date' => $today));
    }

    /**
     * 获取排行榜
     */
    public static function getRankList($type = 'total', $page = 1, $pagesize = 20) {
        $order = $type == 'continuous' ? array('continuous_days' => -1) : array('total_days' => -1);
        return db_find('my_checkin_log', array(), $order, $page, $pagesize);
    }

    /**
     * 获取设置
     */
    public static function getSettings() {
        $settings = setting_get('my_checkin');
        if (empty($settings)) {
            $settings = array(
                'reward_base' => 5,
                'reward_continuous_3' => 10,
                'reward_continuous_7' => 20,
                'reward_continuous_30' => 50,
            );
        }
        return $settings;
    }

    /**
     * 保存设置
     */
    public static function saveSettings($arr) {
        return setting_set('my_checkin', $arr);
    }
}
```

### 14.10 index.php

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

// 获取签到状态
$checkin_status = array('is_checkin' => 0, 'continuous_days' => 0, 'total_days' => 0);
if (!empty($uid)) {
    $checkin_status = CheckinService::getCheckinStatus($uid);
}

// 排行榜
$rank_list = CheckinService::getRankList('total', 1, 10);

// SEO
$header['title'] = lang('my_checkin_daily') . ' - ' . $conf['sitename'];

include _include(APP_PATH.'view/htm/header.inc.htm');
include _include(APP_PATH.'plugin/my_checkin/view/htm/index.htm');
include _include(APP_PATH.'view/htm/footer.inc.htm');
```

### 14.11 api.php

```php
<?php
!defined('DEBUG') AND exit('Forbidden');

$action = param('action', '');

// 输出 JSON
function my_checkin_json($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// 签到操作
if ($action == 'do') {
    if (empty($uid)) {
        my_checkin_json(array('code' => -1, 'message' => '请先登录'));
    }

    CsrfService::check();

    $result = CheckinService::doCheckin($uid);
    if ($result['ok']) {
        my_checkin_json(array(
            'code' => 0,
            'message' => $result['message'],
            'data' => array(
                'reward' => $result['reward'],
                'continuous_days' => $result['continuous_days'],
                'total_days' => $result['total_days'],
            ),
        ));
    } else {
        my_checkin_json(array('code' => -1, 'message' => $result['message']));
    }
}

// 获取状态
if ($action == 'status') {
    if (empty($uid)) {
        my_checkin_json(array('code' => 0, 'data' => array('is_checkin' => 0)));
    }
    $status = CheckinService::getCheckinStatus($uid);
    my_checkin_json(array('code' => 0, 'data' => $status));
}

my_checkin_json(array('code' => -1, 'message' => '无效请求'));
```

### 14.12 view/htm/index.htm

```html
<?php include _include(APP_PATH.'view/htm/header.inc.htm');?>

<div class="row g-4">
    <div class="d-none d-lg-block col-lg-2">
        <?php include _include(APP_PATH.'view/htm/sidebar_left.inc.htm');?>
    </div>
    <div class="col-lg-7" style="min-width:0">
        <!-- 签到卡片 -->
        <div class="x-card  card mb-3">
            <div class="card-body text-center py-4">
                <h4 class="mb-3"><i class="ti ti-calendar-check me-2"></i><?php echo lang('my_checkin_daily');?></h4>

                <?php if(empty($uid)) { ?>
                <a href="<?php echo url('user-login');?>" class="btn btn-primary btn-lg rounded-pill px-5">
                    <i class="ti ti-login me-1"></i><?php echo lang('my_checkin_login');?>
                </a>
                <?php } elseif(!empty($checkin_status['is_checkin'])) { ?>
                <div class="mb-3">
                    <span class="badge bg-success fs-6 px-4 py-2 rounded-pill">
                        <i class="ti ti-circle-check me-1"></i><?php echo lang('my_checkin_already');?>
                    </span>
                </div>
                <div class="text-body-secondary">
                    <?php echo lang('my_checkin_continuous');?> <strong class="text-body"><?php echo intval($checkin_status['continuous_days']);?></strong> <?php echo lang('my_checkin_days');?>
                    &nbsp;&middot;&nbsp;
                    <?php echo lang('my_checkin_total');?> <strong class="text-body"><?php echo intval($checkin_status['total_days']);?></strong> <?php echo lang('my_checkin_days');?>
                </div>
                <?php } else { ?>
                <button type="button" class="btn btn-primary btn-lg rounded-pill px-5" id="checkin-btn"
                        hx-post="/?my_checkin_api.htm" hx-vals='{"action": "do"}'
                        hx-target="#checkin-result" hx-swap="innerHTML">
                    <i class="ti ti-calendar-check me-1"></i><?php echo lang('my_checkin_btn');?>
                </button>
                <div id="checkin-result" class="mt-3"></div>
                <?php } ?>
            </div>
        </div>

        <!-- 排行榜 -->
        <?php if(!empty($rank_list)) { ?>
        <div class="x-card card">
            <div class="card-header">
                <h5 class="mb-0"><i class="ti ti-trophy me-2"></i><?php echo lang('my_checkin_rank');?></h5>
            </div>
            <ul class="list-group list-group-flush">
                <?php $rank = 0; foreach($rank_list as $item) { $rank++; ?>
                <?php $u = user_read($item['uid']); if(empty($u)) continue; ?>
                <li class="list-group-item d-flex align-items-center">
                    <span class="badge <?php echo $rank <= 3 ? 'bg-warning' : 'bg-secondary';?> rounded-circle me-3" style="width:28px;height:28px;line-height:20px;"><?php echo $rank;?></span>
                    <img src="<?php echo $u['avatar_url'];?>" class="rounded-circle me-2" style="width:32px;height:32px;object-fit:cover;" onerror="this.src='/view/img/avatar.png'">
                    <span class="fw-medium"><?php echo esc_html($u['username']);?></span>
                    <span class="ms-auto text-body-secondary small">
                        <?php echo lang('my_checkin_total');?> <?php echo intval($item['total_days']);?> <?php echo lang('my_checkin_days');?>
                    </span>
                </li>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>
    </div>
    <div class="col-lg-3">
        <?php include _include(APP_PATH.'view/htm/sidebar_right.inc.htm');?>
    </div>
</div>

<?php include _include(APP_PATH.'view/htm/footer.inc.htm');?>
```

### 14.13 setting.php

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

// 权限检查
if($gid != 1 && $gid != 2) {
    message(-1, lang('user_group_insufficient_privilege'));
}

$action = param('my_checkin_action', '');

if($action == 'save') {
    CsrfService::check();
    $settings = array(
        'reward_base' => intval(param('reward_base', 5)),
        'reward_continuous_3' => intval(param('reward_continuous_3', 10)),
        'reward_continuous_7' => intval(param('reward_continuous_7', 20)),
        'reward_continuous_30' => intval(param('reward_continuous_30', 50)),
    );
    // 参数范围校验
    $settings['reward_base'] = max(1, min(1000, $settings['reward_base']));
    $settings['reward_continuous_3'] = max(0, min(1000, $settings['reward_continuous_3']));
    $settings['reward_continuous_7'] = max(0, min(1000, $settings['reward_continuous_7']));
    $settings['reward_continuous_30'] = max(0, min(1000, $settings['reward_continuous_30']));

    CheckinService::saveSettings($settings);
    message(0, lang('save_success'));
}

$settings = CheckinService::getSettings();

include _include(APP_PATH.'plugin/my_checkin/view/htm/setting.htm');
```

### 14.14 view/htm/setting.htm

```html
<div class="x-card card">
    <div class="card-header">
        <h5 class="mb-0"><i class="ti ti-settings me-2"></i><?php echo lang('my_checkin_settings');?></h5>
    </div>
    <div class="card-body">
        <form method="post" action="<?php echo url('plugin-setting-my_checkin');?>">
            <?php echo CsrfService::input();?>
            <input type="hidden" name="my_checkin_action" value="save">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?php echo lang('my_checkin_reward_base');?></label>
                    <input type="number" name="reward_base" class="form-control"
                           value="<?php echo intval($settings['reward_base']);?>" min="1" max="1000">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo lang('my_checkin_reward_continuous');?> (3<?php echo lang('my_checkin_days');?>)</label>
                    <input type="number" name="reward_continuous_3" class="form-control"
                           value="<?php echo intval($settings['reward_continuous_3']);?>" min="0" max="1000">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo lang('my_checkin_reward_continuous');?> (7<?php echo lang('my_checkin_days');?>)</label>
                    <input type="number" name="reward_continuous_7" class="form-control"
                           value="<?php echo intval($settings['reward_continuous_7']);?>" min="0" max="1000">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo lang('my_checkin_reward_continuous');?> (30<?php echo lang('my_checkin_days');?>)</label>
                    <input type="number" name="reward_continuous_30" class="form-control"
                           value="<?php echo intval($settings['reward_continuous_30']);?>" min="0" max="1000">
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i><?php echo lang('my_checkin_save');?>
                </button>
            </div>
        </form>
    </div>
</div>
```

---

## 15. 调试技巧

### 15.1 开启调试模式

在 `conf/conf.php` 中设置：

```php
'debug' => 2,           // 开启插件开发模式
'cache_disable' => 1,   // 关闭编译缓存
```

`DEBUG = 2` 时，每次请求都会重新编译模板，修改 hook 文件后无需手动清理缓存。

### 15.2 清理缓存

修改模板后需要清理 `tmp/` 目录：

- 手动删除 `tmp/` 目录下的所有文件
- 或在后台「系统管理 - 缓存」页面清理
- 或在代码中调用 `plugin_clear_tmp_dir()`

### 15.3 浏览器调试

- **硬刷新**：`Ctrl+F5`（Windows）/ `Cmd+Shift+R`（Mac）清除 CSS 缓存
- **htmx 请求**：在浏览器开发者工具的 Network 面板中筛选 `hx-request` 请求头
- **Console**：查看 JS 错误和 htmx 事件日志

### 15.4 错误日志

查看 `log/` 目录下的错误日志文件，PHP 错误会写入对应日期的日志文件。

### 15.5 常用调试代码

```php
// 输出变量并终止
var_dump($variable); exit;

// 记录日志
xn_log("debug message: " . json_encode($data), 'my_plugin');

// 查看 SQL
$sql = db_sql_find_one("SELECT * FROM {$tablepre}my_table WHERE id = 1");
```

---

## 16. 常见问题

### Q: hook 文件修改后不生效？

A: 需要清理 `tmp/` 目录的编译缓存。开发阶段建议设置 `DEBUG = 2` 和 `cache_disable = 1`。

### Q: 语言 hook 不生效？

A: 检查语言 hook 文件名是否正确（如 `lang_zh_cn_bbs.php`），语言 key 是否使用了插件名前缀，每行是否为合法的 `$lang['key'] = 'value';` 格式。

### Q: 路由注册后访问 404？

A: 检查 `index_route_case_end.php` hook 中的 case 语句是否正确，URL 格式是否为 `/?xxx.htm`。

### Q: 多个插件注册同一个 hook 点，执行顺序如何？

A: 按 `hooks_rank` 值升序执行。值越小越先执行，值越大越后执行。默认值为 0。

### Q: 如何在后台添加设置页面？

A: 在插件目录下创建 `setting.php` 文件，系统会自动检测并在插件列表中显示"设置"链接。

### Q: 如何判断当前用户是否登录？

A: 检查 `$uid` 变量，未登录时 `$uid` 为 0。

### Q: 如何获取当前用户组？

A: 使用 `$gid` 变量，1 为管理员，2 为版主。

### Q: overwrite 和 hook 如何选择？

A: 优先使用 Hook。只有当需要大范围修改页面结构、Hook 点无法满足需求时才使用 Overwrite。

### Q: 插件安装/卸载后需要清理缓存吗？

A: 是的。系统会自动调用 `plugin_clear_tmp_dir()` 清理编译缓存，但如果手动修改了插件文件，需要手动清理。

### Q: 如何处理插件的数据库升级？

A: 在后台「系统升级」页面（`/admin/?upgrade.htm`）添加升级项，通过页面执行 SQL 变更。也可以在 `install.php` 中检测字段是否存在来兼容升级。

```php
// 检测字段是否存在
$check_sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}my_table' AND COLUMN_NAME = 'new_field'";
$check_col = db_sql_find_one($check_sql);
if (empty($check_col)) {
    db_exec("ALTER TABLE {$tablepre}my_table ADD COLUMN new_field VARCHAR(64) NOT NULL DEFAULT ''");
}
```
