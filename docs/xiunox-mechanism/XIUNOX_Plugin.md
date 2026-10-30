# XIUNOX_Plugin 插件机制

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 采用编译时内联与运行时分发相结合的双轨插件架构。核心机制分为两类：**Hook（钩子）** 机制通过在核心源文件的特定标记位置注入插件代码，在编译阶段将多个插件的 Hook 文件合并到模板中，无需运行时开销；**Overwrite（覆盖）** 机制允许插件直接替换核心文件中的视图模板，实现更深层的界面定制。

所有插件以目录为单位存放在 `plugin/` 目录下，通过 `conf.json` 声明元信息与权限配置。插件状态（安装、启用、版本）以数据库 `bbs_plugin` 表为唯一权威，`conf.json` 不再承载运行时状态。官方插件市场通过 `OfficialPluginService` 类实现远程清单拉取、zip 下载、在线安装与升级流程，主备源分别使用 GitHub Raw 与 jsDelivr CDN 确保可用性。

## 站长指南

### 配置入口

登录管理后台 → **插件** → **插件管理**，可执行以下操作：

| 操作 | 说明 |
|------|------|
| 安装 | 将插件文件写入数据库并标记为已安装+已启用 |
| 启用/禁用 | 切换插件的 `enable` 状态，禁用后 Hook 与 Overwrite 均不再生效 |
| 卸载 | 将插件标记为未安装，保留文件便于后续重装 |
| 设置 | 进入插件专属设置页（需插件提供 `setting.php`） |
| 官方市场 | 从远程清单浏览并一键安装/升级官方插件 |

### 配置项说明

每个插件的 `conf.json` 包含以下关键字段：

| 字段 | 说明 |
|------|------|
| `name` | 插件显示名称 |
| `brief` | 插件功能简介 |
| `version` | 插件版本号（语义化版本，如 `1.1.1`） |
| `bbs_version` | 兼容的 Xiuno X 最低版本 |
| `hooks_rank` | 各 Hook 点的优先级（数值越大越先执行） |
| `overwrites_rank` | 各覆盖文件的优先级 |
| `dependencies` | 依赖的其他插件及版本约束 |
| `type` | 插件类型：`plugin`（功能插件）或 `theme`（主题） |

### 使用场景

- **功能增强**：如签到、勋章、认证、积分赠送等社交功能插件
- **界面定制**：通过 Overwrite 替换页脚、头部等核心视图模板
- **行为扩展**：通过 Hook 在发帖、回帖、用户注册等关键节点注入逻辑
- **路由扩展**：注册独立 URL 路由，提供插件专属页面

### 注意事项

1. **依赖关系**：启用插件前检查 `dependencies` 字段，确保依赖插件已启用且版本满足约束（支持 `^`、`~`、`>=` 等语义化版本约束）
2. **互斥机制**：同类插件（相同功能标识）互斥，如 `xnx_checkin` 与 `jack_checkin` 不会同时启用
3. **缓存清理**：每次启用/禁用/安装/卸载插件后，系统自动清空 tmp 编译缓存与 OPcache，无需手动操作
4. **备份建议**：在线升级插件前系统会自动备份原目录到 `tmp/plugin_bak_*`，升级失败自动回滚
5. **安全提示**：不要从不明来源下载插件，Hook 文件可执行任意 PHP 代码

## 开发者指南

### 核心服务类

| 类/函数 | 位置 | 职责 |
|---------|------|------|
| `plugin_init()` | `model/plugin.func.php` | 初始化插件列表，扫描 `plugin/` 目录并合并数据库状态 |
| `plugin_install($dir)` | `model/plugin.func.php` | 执行安装流程（写库 + 清缓存） |
| `plugin_enable($dir)` / `plugin_disable($dir)` | `model/plugin.func.php` | 切换启用状态 |
| `plugin_compile_srcfile($srcfile)` | `model/plugin.func.php` | 编译源文件，内联所有已启用插件的 Hook 代码 |
| `plugin_find_overwrite($srcfile)` | `model/plugin.func.php` | 查找匹配的 Overwrite 文件 |
| `plugin_hook($hookname, &$data)` | `model/plugin.func.php` | 运行时 Hook 分发（带错误隔离） |
| `OfficialPluginService` | `lib/OfficialPluginService.php` | 官方插件市场：清单拉取、下载、安装、升级 |

### 钩子点

Hook 分为两类，均通过文件名与核心代码中的标记点对应：

**PHP 代码钩子**（`.php` 后缀）——在核心 PHP 文件的 `// hook {hookname}` 标记处注入：

| 常见钩子名 | 注入位置 | 用途 |
|------------|----------|------|
| `model_inc_file.php` | `model.inc.php` | 加载插件 Service 类文件 |
| `model_route_table_end.php` | 路由表构建末尾 | 注册插件自定义路由 |
| `index_route_case_end.php` | 前台路由 switch 末尾 | 注册前台页面路由 |
| `admin_index_route_case_end.php` | 后台路由 switch 末尾 | 注册后台管理路由 |
| `model_thread_create_end.php` | 主题创建完成后 | 执行发帖后逻辑 |
| `model_post_format_end.php` | 帖子格式化完成后 | 修改输出内容 |
| `lang_zh_cn_bbs.php` 等 | 语言文件加载时 | 添加/覆盖语言条目 |

**HTML 模板钩子**（`.htm` 后缀）——在模板文件的 `<!--{hook hookname}-->` 标记处注入：

| 常见钩子名 | 注入位置 | 用途 |
|------------|----------|------|
| `header_link_after.htm` | 头部链接后 | 添加 CSS/JS 引用 |
| `post_end.htm` | 帖子内容末尾 | 附加帖子相关 UI |
| `thread_list_*_subject_after.htm` | 主题列表标题后 | 在标题旁展示徽章/图标 |
| `footer_js_after.htm` | 页脚 JS 后 | 注入页面底部脚本 |
| `admin_sidebar_end.htm` | 后台侧边栏末尾 | 添加后台导航项 |

### 扩展方式

**1. 创建插件目录结构**

```
plugin/my_plugin/
├── conf.json           # 插件配置（必需）
├── icon.png            # 插件图标（推荐 128x128）
├── install.php         # 安装脚本（可选）
├── uninstall.php       # 卸载脚本（可选）
├── upgrade.php         # 升级脚本（可选）
├── setting.php         # 设置页处理（可选）
├── setting.htm         # 设置页模板（可选）
├── hook/               # Hook 文件目录
│   ├── model_inc_file.php
│   ├── index_route_case_end.php
│   └── ...
├── model/              # Service 类目录
│   └── MyPluginService.php
├── route/              # 路由文件目录
│   └── my_action.php
├── view/htm/           # 视图模板目录
│   └── my_page.htm
├── overwrite/          # 覆盖核心文件（可选）
│   └── view/htm/
│       └── footer.inc.htm
└── static/             # 静态资源
    ├── js/
    └── css/
```

**2. 编写 conf.json**

```json
{
    "name": "我的插件",
    "brief": "插件功能简介",
    "version": "1.0.0",
    "bbs_version": "1.1",
    "type": "plugin",
    "author": "dev_name",
    "hooks_rank": {
        "model_inc_file.php": 10,
        "index_route_case_end.php": 10
    },
    "overwrites_rank": {},
    "dependencies": [],
    "capabilities": []
}
```

**3. 编写 Service 类**

Service 类是插件的核心业务逻辑载体，通过 `model_inc_file.php` 钩子被框架自动加载：

```php
// plugin/my_plugin/model/MyPluginService.php
<?php
!defined('DEBUG') AND exit('Access Denied');

class MyPluginService {
    public static function getSettings() {
        return setting_get('my_plugin') ?: [/* 默认值 */];
    }

    public static function doSomething($uid) {
        // 业务逻辑
    }
}
```

对应的 `model_inc_file.php` 钩子：

```php
// plugin/my_plugin/hook/model_inc_file.php
<?php exit;
APP_PATH.'plugin/my_plugin/model/MyPluginService.php',
```

**4. 注册路由**

通过 `index_route_case_end.php` 钩子将插件路由注册到框架路由表：

```php
// plugin/my_plugin/hook/index_route_case_end.php
<?php exit;
case 'my-action':
    include APP_PATH.'plugin/my_plugin/route/my_action.php';
    break;
```

**5. Overwrite 机制**

在 `overwrite/` 目录下按照核心文件的相对路径放置同名文件，系统加载时自动替换。例如覆盖页脚：

```
plugin/my_plugin/overwrite/view/htm/footer.inc.htm
```

安全限制：`conf/`、`lib/`、`admin/`、`api/`、`install/` 等核心路径不可被 Overwrite。

### 代码示例

**完整插件示例：Hello World 插件**

```
plugin/xnx_hello/
├── conf.json
├── icon.png
├── hook/
│   ├── model_inc_file.php      # 加载 Service
│   ├── index_route_case_end.php # 注册路由
│   ├── header_link_after.htm    # 添加前端资源
│   └── lang_zh_cn_bbs.php       # 中文语言包
├── model/
│   └── HelloService.php
├── route/
│   └── hello.php
└── view/htm/
    └── hello.htm
```

`conf.json`：

```json
{
    "name": "Hello World",
    "brief": "一个演示插件，展示插件开发基本结构",
    "version": "1.0.0",
    "bbs_version": "1.1",
    "type": "plugin",
    "author": "demo",
    "hooks_rank": {
        "model_inc_file.php": 5,
        "index_route_case_end.php": 5,
        "header_link_after.htm": 5,
        "lang_zh_cn_bbs.php": 5
    },
    "overwrites_rank": {},
    "dependencies": []
}
```

`model/HelloService.php`：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

class HelloService {
    public static function getMessage() {
        $settings = setting_get('xnx_hello');
        return !empty($settings['message']) ? $settings['message'] : 'Hello, Xiuno X!';
    }
}
```

`route/hello.php`：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

$message = HelloService::getMessage();
$header['title'] = lang('xnx_hello_title') . ' - ' . $conf['sitename'];

include _include(APP_PATH.'view/htm/header.inc.htm');
include _include(APP_PATH.'plugin/xnx_hello/view/htm/hello.htm');
include _include(APP_PATH.'view/htm/footer.inc.htm');
```

`view/htm/hello.htm`：

```html
<div class="card">
    <h2><?php echo $message; ?></h2>
    <p>这是一个 Xiuno X 插件示例页面。</p>
</div>
```

## 常见问题

1. **插件启用后不生效怎么办？**
   首先检查插件依赖是否已满足（后台插件管理页会标记缺失依赖）。其次确认 `plugin/` 目录与 `tmp/` 目录有写入权限。如果仍不生效，尝试在后台点击"禁用"后再"启用"插件触发缓存重建。

2. **多个插件 Hook 同一位置时的执行顺序？**
   按 `conf.json` 中 `hooks_rank` 值降序执行，数值越大越先执行。相同 rank 的插件按目录名字母序执行。可通过调整 rank 控制优先级。

3. **Overwrite 能覆盖哪些文件？**
   只能覆盖 `view/htm/`、`view/css/`、`view/js/` 等前端视图层文件。`conf/`、`lib/`、`admin/`、`api/`、`model/`、`install/` 等核心路径受保护，Overwrite 会被系统拒绝并记录安全日志。

4. **插件升级失败如何回滚？**
   官方市场升级会自动备份原插件目录到 `tmp/plugin_bak_{dir}_{时间戳}/`，升级异常时自动恢复。手动升级时建议先备份 `plugin/{dir}/` 目录。

5. **开发调试时如何查看 Hook 是否生效？**
   在 `plugin/config.default.php` 中将 `debug` 设为 2，编译缓存将被跳过，每次请求都实时编译。同时可查看 `log/plugin_crash_error.php` 和 `log/` 下的 `plugin_error` 日志定位问题。
