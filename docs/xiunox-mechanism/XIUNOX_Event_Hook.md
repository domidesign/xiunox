# XIUNOX_Event_Hook 事件与钩子系统

> **适用人群**：开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 提供了两套相互补充的扩展机制：**XnEvent 事件机制** 与 **Hook 文件机制**。前者是运行时内存级的"观察者模式"实现，用于解耦核心业务（如封禁、解封、清空内容）与插件扩展；后者基于源文件编译时内联的方式，将插件代码直接注入到模板或 PHP 源文件的指定钩子点中，具备零运行时开销的优点。

两套机制共同构成了 Xiuno X 的插件扩展基石：编译时 Hook 适合高频、输出型扩展（如注入导航、侧边栏组件），运行时 XnEvent 则适合低频、业务型扩展（如监听用户封禁、记录日志、触发第三方通知）。开发者可以根据场景灵活选择。

## 核心机制说明

### XnEvent 事件机制

`XnEvent`（位于 `lib/XnEvent.php`）是一个零依赖、静态调用的轻量事件类，具备以下特性：

- **静态调用**：无需实例化，与 `CacheHelper`、`PermissionService` 风格一致；
- **零依赖**：不依赖 db/cache/conf，可在框架最早期加载；
- **异常隔离**：回调中抛出的 `Throwable` 不会中断主流程，仅通过 `xn_log` 记录到 `error` 日志，避免"旁路逻辑拖垮主请求"；
- **参数引用传递**：回调签名为 `function(&$args)`，监听器可直接修改 `$args` 影响主流程。

事件名统一采用 `ClassName.methodName` 命名规范，例如 `UserBanService.beforeBan`、`ThreadService.afterCreate`。

### Hook 文件机制

Hook 文件机制基于源文件编译时内联：在模板（`.htm`）或 PHP 源文件中，使用 `<!--{hook hookname}-->` 或 `// hook hookname` 标记一个钩子点。编译时 `plugin_compile_srcfile()` 会扫描该标记，并按 `hooks_rank` 降序合并所有已启用插件中对应 `hook/hookname` 的文件内容，最终写入 `tmp/` 缓存文件供后续请求直接 include。

Hook 文件命名规范：
- 存放于插件目录 `plugin/{plugin_dir}/hook/` 下；
- 文件名即钩子点 ID，如 `header_body_start.htm`、`thread_create_after.php`；
- PHP 类型的 hook 文件应以 `<?php exit;` 开头，防止被直接访问；
- `.htm` 后缀为模板 hook（输出 HTML），`.php` 后缀为源码 hook（执行 PHP 逻辑）。

### 钩子加载流程

1. `plugin_init()` 扫描 `plugin/*/` 目录，合并 `conf.json` 与数据库（`bbs_plugin` 表）状态，构建 `$plugins` 全局数组；
2. 编译阶段：`plugin_compile_srcfile()` 递归解析源文件中的 `// hook xxx` 标记，调用 `plugin_compile_srcfile_callback()` 从所有已启用插件收集对应 hook 文件，按 `hooks_rank` 降序拼接进编译产物；
3. 运行时：`plugin_hook($hookname, $data)` 提供错误隔离的运行时分发通道，遍历匹配 hook 文件并在调用方作用域 `eval` 执行，单个 hook 抛出异常不会影响其他 hook 或主流程；
4. 生命周期：插件启用/禁用后调用 `plugin_clear_tmp_dir()` 清空 `tmp/` 编译缓存并 `opcache_reset()`，确保新配置即时生效。

## API 参考

### XnEvent 类方法

#### `XnEvent::on($event, $plugin, $callback, $priority = 0)`

注册一个持久监听器。

| 参数 | 类型 | 说明 |
|---|---|---|
| `$event` | string | 事件名，格式 `ClassName.methodName`，支持 `Plugin.*` 通配符模式匹配 |
| `$plugin` | string | 插件标识，用于调试与按插件卸载 |
| `$callback` | callable | 回调函数，签名 `function(&$args)` |
| `$priority` | int | 可选，优先级。数值越大越先执行，默认 0 |

#### `XnEvent::once($event, $plugin, $callback, $priority = 0)`

注册一次性监听器，触发一次后自动从注册表中移除。`$priority` 参数行为与 `on()` 一致。

#### `XnEvent::trigger($event, &$args = null)`

触发指定事件，按注册顺序依次调用所有监听器。`$args` 以引用方式传递，监听器可直接修改。单个回调异常被 `try/catch` 捕获，不影响后续监听器执行。

#### `XnEvent::off($event = null, $plugin = null)`

移除监听器：
- `off(null, null)`：清空所有事件的所有监听器；
- `off('UserBanService.beforeBan')`：移除该事件全部监听器；
- `off('UserBanService.beforeBan', 'my_plugin')`：仅移除指定插件的监听器。

#### `XnEvent::hasListeners($event)`

返回指定事件是否存在监听器（bool），可用于主流程提前短路。

#### `XnEvent::clearAll()`

清空所有事件的全部监听器，常用于测试或插件热重载场景。

#### 通配符匹配与优先级

- **通配符匹配**：事件名支持 `Plugin.*` 模式，注册时使用通配符可匹配该插件的所有事件。例如 `my_plugin.*` 可匹配 `my_plugin.beforeSave`、`my_plugin.afterDelete` 等。匹配逻辑由私有方法 `matchWildcard()` 实现。
- **优先级排序**：同事件名下的监听器按 `$priority` 降序执行，数值越大越先触发。相同优先级的监听器按注册先后顺序执行。

### 常用钩子点列表

以下为系统中最常用的高频钩子点（完整列表见 `docs/xiunox-plugin-dev/references/hooks-catalog.md`）：

| 钩子点 | 类型 | 位置 | 典型用途 |
|---|---|---|---|
| `header_body_start.htm` | htm | `<body>` 开始后 | 全局顶部横幅/公告条 |
| `body_start.htm` | htm | `<main>` 容器开始 | 页面级全局组件 |
| `footer_js_after.htm` | htm | JS 加载后 | 注入全局插件 JS |
| `footer_body_after.htm` | htm | `</body>` 前 | 统计代码、全局弹窗 |
| `header_nav_custom_after.htm` | htm | 导航自定义菜单后 | 扩展导航项 |
| `thread_create_after.php` | php | 发帖成功后 | 第三方通知、积分发放 |
| `post_create_after.php` | php | 回帖成功后 | 触发 AI 审核、消息推送 |
| `user_login_after.php` | php | 用户登录成功后 | 登录统计、设备绑定 |
| `model_inc_start.php` | php | 模型加载最早期 | 注册 XnEvent 监听器的最佳位置 |

## 代码示例

### 注册事件监听器

推荐在 `model_inc_start.php`（模型加载最早的 hook）中完成注册，确保监听尽早生效：

```php
// plugin/my_plugin/hook/model_inc_start.php
<?php exit;

XnEvent::on('UserBanService.beforeBan', 'my_plugin', function(&$args) {
    $args['extra_log'] = 'my_plugin noted this ban';
});

XnEvent::once('ThreadService.afterCreate', 'my_plugin', function(&$args) {
    xn_log('first thread created: '.$args['tid'], 'info');
});
```

### 触发事件

在核心业务代码（如 Service 层）中触发事件：

```php
// lib/UserBanService.php
$banData = array('uid' => $uid, 'reason' => $reason);
XnEvent::trigger('UserBanService.beforeBan', $banData);
// 监听器可通过 $banData 引用修改数据
// ... 继续执行封禁逻辑
XnEvent::trigger('UserBanService.afterBan', $banData);
```

### 使用 Hook 文件

在插件目录下创建对应的 hook 文件：

```php
// plugin/my_plugin/hook/footer_body_after.htm
<div class="my-plugin-banner" style="position:fixed;bottom:0;width:100%;background:#f60;color:#fff;text-align:center;padding:6px;">
    Powered by My Plugin
</div>
```

PHP 类型 hook 可直接在调用方作用域中访问上下文：

```php
// plugin/my_plugin/hook/thread_create_after.php
<?php exit;

if (!empty($tid)) {
    // 调用 $tid, $fid, $uid 等变量（由 plugin_hook 调用方 extract 注入）
    my_plugin_send_notification($tid);
}
```

若需运行时分发（带错误隔离），可主动调用：

```php
plugin_hook('thread_create_after.php', array('tid' => $tid, 'fid' => $fid));
```

## 常见问题

1. **XnEvent 与 Hook 文件机制应如何选择？**  
   输出 HTML、高频（每页请求触发）的场景优先使用 Hook 文件，具备编译时内联、零运行时开销的优势；业务逻辑、低频（如发帖、封禁）场景优先使用 XnEvent，具备解耦、异常隔离的优势。两者可组合使用。

2. **Hook 文件不生效怎么办？**  
   首先确认插件已在后台"插件管理"中启用（`bbs_plugin.enable=1`）；其次检查文件名是否与源文件中 `// hook xxx` 标记完全一致（区分大小写与扩展名）；最后在"插件管理"中点击"禁用 → 启用"强制清理 `tmp/` 编译缓存与 OPcache。

3. **多个插件注册同一个钩子，执行顺序如何控制？**  
   通过 `conf.json` 中的 `hooks_rank` 字段控制，数值越大优先级越高（降序执行）。同 rank 情况下按插件目录名字母序确定，保证顺序稳定。

4. **XnEvent 回调抛异常会影响主流程吗？**  
   不会。`trigger()` 内部通过 `try/catch` 隔离异常，错误仅通过 `xn_log` 写入 `error` 日志，不会中断主流程。建议回调中尽量避免业务致命错误。

5. **可以在 Hook 文件中直接 include 插件的其他 PHP 文件吗？**  
   可以。Hook 文件运行在调用方作用域中，可自由 `include` 插件目录下的类文件。建议将业务逻辑封装为类（如 `MyPluginService`），hook 文件仅做薄封装，便于单元测试与复用。
