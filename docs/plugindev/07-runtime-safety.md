# 07 运行时安全

> 关键源码：`lib/ErrorHandler.php`、`model/plugin.func.php`（`plugin_compile_srcfile_callback` / `plugin_hook` / `xn_hook`）

---

## 本章覆盖

- **ErrorHandler 全局错误处理器**：`register()` 一次性注册三类处理器（`handleError` / `handleException` / `handleShutdown`），分别承接 PHP 常规错误、未捕获异常、致命错误
- **autoDisableCrashedPlugin 崩溃自动禁用**：1 小时滚动窗口内同插件崩溃 3 次即写 `conf.json` + 写 `bbs_plugin` 表 + 清 `tmp/`，避免反复白屏
- **plugin-compile 注释归因**：编译期向 `tmp/` 缓存注入 `// plugin-compile: {dir}  {path}` 注释，fatal error 时从错误行号往前扫描定位插件目录
- **tmp/ 缓存损坏自愈**：检测到 fatal error 文件位于 `tmp/` 时自动 `unlink` 损坏缓存文件，提示用户刷新重建
- **plugin_hook 运行时分发**：与编译时合并互补，带 `try/catch Throwable` 错误隔离，单 hook 抛异常不阻断其他 hook 和主流程

---

## 1. ErrorHandler 全局错误处理器

XIUNOX 在 `lib/ErrorHandler.php` 用一个类统一接管 PHP 的三类错误通道。入口 `index.php` 启动时调用 `ErrorHandler::register()`，之后整个请求生命周期里的错误、异常、致命错误都由它处理。

```
PHP 运行时
  ├─ E_WARNING / E_NOTICE / E_DEPRECATED ...  ──►  handleError()
  │                                                │ error_reporting 命中 → 抛 ErrorException
  │                                                ▼
  ├─ 未捕获 Throwable（含上面抛出的 ErrorException）──► handleException()
  │                                                │  BizException → HTTP 200 + 业务码
  │                                                │  其他 Throwable → HTTP 500 + 归因/禁用
  │                                                ▼
  └─ E_ERROR / E_PARSE / E_CORE_ERROR / E_COMPILE_ERROR（致命错误）
                                                 ──► handleShutdown()
                                                     │ tmp/ 缓存损坏 → 删缓存 + 提示刷新
                                                     │ 归因到插件 → 崩溃计数 → 自动禁用
                                                     ▼
                                                     renderError() 兜底渲染 500
```

### 1.1 register() —— 一次性注册三类处理器

```php
// lib/ErrorHandler.php
public static function register(): void
{
    // 确保 BizException 类已加载（handleException 中需要 instanceof 判断）
    if (!class_exists('BizException', false)) {
        require_once APP_PATH.'lib/BizException.php';
    }
    self::$previousErrorHandler = set_error_handler([self::class, 'handleError']);
    set_exception_handler([self::class, 'handleException']);
    register_shutdown_function([self::class, 'handleShutdown']);
}
```

三个 PHP 内建函数各管一段：

| 注册函数 | 触发时机 | 对应处理器 |
|---|---|---|
| `set_error_handler` | E_WARNING / E_NOTICE / E_DEPRECATED 等可恢复错误 | `handleError` |
| `set_exception_handler` | 未捕获的 `Throwable`（含 Error、Exception） | `handleException` |
| `register_shutdown_function` | 脚本结束（含致命错误） | `handleShutdown` |

> ⚠️ **`register()` 必须在 `BizException` 类可用之后调用**：`handleException` 用 `instanceof BizException` 区分业务异常和系统异常，类未加载会再抛异常导致无限循环。代码里用 `class_exists('BizException', false)` 显式预加载兜底。

### 1.2 handleError —— E_WARNING / E_NOTICE 升级为异常

```php
public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
{
    xn_log("Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}", 'error', 'WARNING');

    // error_reporting() & $errno 为 0 表示被 @ 抑制或生产环境 error_reporting=0，不抛异常
    if (error_reporting() & $errno) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    return false;
}
```

关键设计：

- **不静默吞掉 Warning/Notice**：PHP 8 起，被 `set_error_handler` 接管的 Warning/Notice 默认会被吞掉。这里在 `error_reporting() & $errno` 命中时主动抛 `ErrorException`，让它走 `handleException` 统一渲染，避免插件里的 Warning 把页面布局撕坏。
- **保留 `@` 抑制语义**：`@` 会让 `error_reporting()` 返回 0，此时不抛异常、返回 `false` 让 PHP 默认处理器继续，与原生行为一致。
- **生产环境 `error_reporting=0` 不抛异常**：避免把 Warning 全部升级成 500。

### 1.3 handleException —— BizException 与系统异常分流

```php
public static function handleException(\Throwable $exception): void
{
    $debug = defined('DEBUG') ? DEBUG : 0;

    // 记录异常信息及调用栈
    xn_log("Exception: ...", 'error', 'ERROR');

    // BizException：业务异常，HTTP 200 + 业务错误码，不泄露堆栈
    if ($exception instanceof BizException) {
        self::renderError(200, $exception->getMessage(), 200, $exception);
        return;
    }

    // 尝试归因到插件并自动禁用反复崩溃的插件
    $disabled_plugin = self::autoDisableCrashedPlugin($exception->getFile(), $exception->getLine());

    // 系统异常：返回 500
    self::renderError(500, $displayMessage, 500, $exception);
}
```

两类异常走两条路径：

| 异常类型 | HTTP 状态 | 行为 |
|---|---|---|
| `BizException`（业务异常） | 200 | 仅返回业务错误码，不泄露堆栈，不触发插件禁用 |
| 其他 `Throwable`（系统异常） | 500 | 调用 `autoDisableCrashedPlugin` 归因 + 计数，DEBUG=1 时展示堆栈 |

> 💡 **PHP 7+ 的 `undefined function` / `undefined class` 会以 `Error` 异常形式抛出**，走 `handleException` 而非 `handleShutdown`。所以归因逻辑在 `handleException` 也调用一次，覆盖致命错误的「异常形态」。

### 1.4 handleShutdown —— 致命错误兜底

```php
public static function handleShutdown(): void
{
    $error = error_get_last();
    if ($error === null) return;

    // 仅处理致命错误类型
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) return;

    // 检测 tmp/ 缓存损坏场景
    $errorFile = str_replace('\\', '/', $error['file']);
    $isCacheCorruption = (strpos($errorFile, '/tmp/') !== false);

    // 归因到插件并自动禁用
    $disabled_plugin = self::autoDisableCrashedPlugin($error['file'], $error['line']);

    if ($isCacheCorruption) {
        // 删缓存 + 提示刷新
        ...
        return;
    }

    // 非 tmp/ 错误且成功归因，渲染 500 并标注已禁用
    ...
    self::renderError(500, $displayMessage, 500);
}
```

`handleShutdown` 做三件事：

1. **类型过滤**：只处理 `E_ERROR` / `E_PARSE` / `E_CORE_ERROR` / `E_COMPILE_ERROR`，其他类型（如 `E_WARNING`）已经被 `handleError` 接走。
2. **缓存损坏检测**：详见第 4 节。
3. **插件崩溃归因 + 自动禁用**：详见第 2 节。

> ⚠️ **`handleShutdown` 在请求结束时必然被调用一次**（无论是否出错）。代码用 `error_get_last() === null` 判断本次是否有致命错误，无错直接 return，不会误渲染 500 页面。

### 1.5 renderError —— 兜底渲染

`renderError()` 优先调用项目 `error_page()` 模板，失败时回退到内置 HTML 兜底页：

- **DEBUG=0**：仅展示通用提示（如「服务器内部错误」「插件 [xxx] 反复崩溃已自动禁用，请刷新页面」），不泄露堆栈
- **DEBUG=1**：展示异常类名、消息、文件、行号、`getTraceAsString()` 堆栈
- **headers_sent 防御**：若 HTTP 头已发送（如模板渲染中途崩溃），跳过 `http_response_code()` 调用，仅输出 body，避免「headers already sent」警告

---

## 2. autoDisableCrashedPlugin 崩溃自动禁用机制

### 2.1 设计目标

插件作者写错代码（语法错误、调未定义函数、`null` 调方法等）是常态。如果听之任之，前台每次请求都白屏、后台也无法进入插件管理页禁用它。`autoDisableCrashedPlugin` 用「崩溃计数 + 自动禁用」机制兜底：

- 同一插件 **1 小时内崩溃 3 次** → 自动禁用
- 禁用后下次请求不再编译进它的 hook，页面恢复可用
- 用户可在后台「插件管理」看到该插件已被禁用，修复后重新启用即可

### 2.2 崩溃阈值：3 次/1 小时滚动窗口

```php
// 计数 +1（1 小时窗口）
$cache_key = 'plugin_crash_' . $plugin_dir;
$count = 0;
if (function_exists('cache_get')) {
    $cached = cache_get($cache_key);
    $count = $cached ? intval($cached) : 0;
}
$count++;
if (function_exists('cache_set')) {
    cache_set($cache_key, $count, 3600);  // 3600 秒 = 1 小时
}

// 阈值：3 次/小时
$threshold = 3;
if ($count < $threshold) {
    xn_log("Plugin crash count {$count}/{$threshold} for [{$plugin_dir}]", 'plugin_crash_error');
    return null;
}
```

实现要点：

- **缓存键**：`plugin_crash_{plugin_dir}`，每个插件独立计数
- **TTL = 3600 秒**：`cache_set` 第 3 参设 3600，1 小时无崩溃自动归零，避免插件作者修好后还残留计数
- **阈值 3 次**：给插件 2 次试探机会，第 3 次禁用。3 这个数字硬编码在函数里，足够区分「偶发抖动」与「必崩」
- **`function_exists` 防御**：`cache_get` / `cache_set` 在 `xiunophp` 加载前不可用，函数存在性检查避免启动早期崩溃

> 💡 **为什么用 cache 而不是 db 计数**：fatal error 发生时数据库连接可能已经不稳定，cache（Redis/文件）更轻量；且 cache 自带 TTL，无需手动清理过期计数。

### 2.3 归因策略：从错误文件定位插件目录

`autoDisableCrashedPlugin($errorFile, $errorLine)` 需要先判断「这个错误是哪个插件造成的」，才能去禁用它。两种归因路径：

```php
$file = str_replace('\\', '/', $errorFile);
$plugin_dir = null;

// 1. 错误文件直接在 plugin/xxx/ 路径下
if (preg_match('#/plugin/([^/]+)/#', $file, $m)) {
    $plugin_dir = $m[1];
}
// 2. 错误文件在 tmp/ 路径下，从行号往前找 plugin-compile 注释
elseif (strpos($file, '/tmp/') !== false && is_file($errorFile) && $line > 0) {
    $content = @file($errorFile);
    if ($content !== false) {
        $max_line = min($line, count($content));
        for ($i = $max_line - 1; $i >= 0; $i--) {
            if (preg_match('#//\s*plugin-compile:\s*(\S+)#', $content[$i], $m)) {
                $plugin_dir = $m[1];
                break;
            }
        }
    }
}

if (empty($plugin_dir)) return null;
```

| 场景 | 归因方式 | 示例 |
|---|---|---|
| 错误文件在 `plugin/xnx_demo/` 下 | 正则取目录名 | `/var/www/plugin/xnx_demo/hook/foo.php` → `xnx_demo` |
| 错误文件在 `tmp/` 下（合并缓存） | 从错误行号往前扫 `// plugin-compile:` 注释 | 详见第 3 节 |
| 错误文件在核心代码下 | 返回 null，不禁用任何插件 | `/var/www/route/thread.php` |

> ⚠️ **`tmp/` 文件错误是高频场景**：编译期会把多个插件的 hook 物理拼接到一个 `tmp/<hash>.php` 里，任何一个 hook 有语法错误都会让整个合并文件 fatal error。第 3 节的 `plugin-compile` 注释就是为了解决「合并文件崩溃时怎么定位到具体插件」。

### 2.4 禁用操作：三步组合拳

达到阈值后，禁用一个插件需要同步三处状态：

```php
$plugin_path = APP_PATH . 'plugin/' . $plugin_dir;
$conf_file = $plugin_path . '/conf.json';

// 1. 写 conf.json enable=0
if (is_file($conf_file) && function_exists('file_replace_var')) {
    try {
        file_replace_var($conf_file, array('enable' => 0), TRUE);
    } catch (\Throwable $e) { /* 忽略，继续尝试 db */ }
}

// 2. 写数据库 enable=0
global $db, $tablepre, $time;
if (is_object($db) && function_exists('db_update') && isset($tablepre)) {
    try {
        db_update('plugin', array('dir' => $plugin_dir), array('enable' => 0, 'update_time' => $time));
    } catch (\Throwable $e) { /* 忽略数据库错误 */ }
}

// 3. 清 tmp 目录，触发下次请求重新编译（已禁用插件的 hook 不会被编译进去）
global $conf;
if (isset($conf['tmp_path'])) {
    $tmp_path = $conf['tmp_path'];
    if (function_exists('rmdir_recusive')) {
        @rmdir_recusive($tmp_path, TRUE);
    }
    if (function_exists('xn_unlink')) {
        @xn_unlink($tmp_path . 'model.min.php');
    }
}

return $plugin_dir;
```

| 步骤 | 操作 | 目的 |
|---|---|---|
| 1 | `file_replace_var($conf_file, ['enable'=>0], TRUE)` | 写 `conf.json`，下次 `plugin_paths_enabled()` 读不到它 |
| 2 | `db_update('plugin', ['dir'=>$dir], ['enable'=>0, 'update_time'=>$time])` | 写 `bbs_plugin` 表，后台插件列表显示已禁用 |
| 3 | `rmdir_recusive($tmp_path, TRUE)` + `xn_unlink($tmp_path.'model.min.php')` | 清 `tmp/` 缓存，确保下次请求重新编译（不再包含已禁用插件的 hook） |

> ⚠️ **不调 `plugin_disable()`**：`plugin_disable()` 依赖 `global $plugins`（在 `plugin_init()` 里初始化），但前台请求路径并不走 `plugin_init()`（它只在 admin/upgrade 调用），`$plugins` 未初始化会导致禁用失败。直接操作 `conf.json` + `db_update` 是更底层的等价操作，不依赖运行时上下文。`ponytail:` 注释明确标注了这一取舍。

> ⚠️ **每一步都 `try/catch` 吞错**：禁用流程发生在 fatal error 之后，此时系统状态可能已经不稳定（数据库连接断开、文件系统异常），任何一步失败都不应阻断后续步骤。`conf.json` 写失败也要尝试 `db_update`，反之亦然。

### 2.5 完整流程图

```
fatal error / Throwable
        │
        ▼
autoDisableCrashedPlugin($file, $line)
        │
        ├─ plugin/xxx/ 路径？ ──► plugin_dir = xxx
        │
        ├─ tmp/ 路径？ ──► 从 $line 往前扫 // plugin-compile: 注释
        │
        └─ 都不命中 ──► return null（不禁用）
        │
        ▼
cache_get('plugin_crash_xxx') + 1
        │
        ├─ count < 3 ──► return null（记录日志，等下次）
        │
        └─ count >= 3 ──► 写 conf.json + db_update + 清 tmp/
                              │
                              ▼
                          return plugin_dir
                              │
                              ▼
              handleShutdown/handleException 展示：
              "插件 [xxx] 反复崩溃已自动禁用，请刷新页面"
```

---

## 3. plugin-compile 注释归因机制

### 3.1 问题背景

编译期 `plugin_compile_srcfile_callback()` 会把多个插件的 PHP hook 物理拼接到同一个 `tmp/<hash>.php` 缓存文件里：

```php
// tmp/abc123.php（编译后的合并文件）
<?php
// 原始 model 文件内容...
function thread_create(...) {
    // hook thread_create_after.php
    // ↓↓↓ 这里被替换为多个插件 hook 的拼接内容 ↓↓↓
    
    // plugin-compile: xnx_demo  /var/www/plugin/xnx_demo/hook/thread_create_after.php
    xnx_demo_do_something();   // ← xnx_demo 提供的 hook
    
    // plugin-compile: xnx_status  /var/www/plugin/xnx_status/hook/thread_create_after.php
    StatusService::refresh();  // ← xnx_status 提供的 hook
    
}
```

如果 `xnx_demo_do_something()` 这行 fatal error，PHP 报错的文件是 `tmp/abc123.php`、行号是这行所在的行。问题是：**怎么从 `tmp/abc123.php` 反推到 `xnx_demo` 这个插件？**

### 3.2 编译期注入注释

`plugin_compile_srcfile_callback()` 在拼接每个 PHP hook 之前，先注入一行注释作为「导航信标」：

```php
// model/plugin.func.php  (plugin_compile_srcfile_callback)
if($fileext == 'php') {
    // 从 path 反推插件 dir：plugin/{dir}/hook/{hookname}
    $plugin_dir = basename(dirname(dirname($path)));
    $s .= "\n// plugin-compile: $plugin_dir  $path\n";
}
$s .= $t;
```

注释格式：`// plugin-compile: {plugin_dir}  {绝对路径}`（中间是两个空格）。

### 3.3 仅 PHP hook 注入

```php
if($fileext == 'php') {       // ← 只有 .php hook 加注释
    $plugin_dir = basename(dirname(dirname($path)));
    $s .= "\n// plugin-compile: $plugin_dir  $path\n";
}
$s .= $t;
```

`.htm` hook 不注入注释，原因有二：

- `.htm` hook 编译后是 HTML 输出，注入 PHP 注释会污染页面源码
- `.htm` hook 是模板片段，运行时报错通常是模板变量未定义这类「软错误」，不会触发 fatal error 走 `handleShutdown`

### 3.4 token_get_all 语法预检已废弃

历史上 `plugin_compile_srcfile_callback` 曾用 `token_get_all` 对每个 hook 做语法预检，发现语法错误就跳过该 hook。现在已废弃，原因记录在源码注释里：

```php
// PHP hook 语法预检：已废弃，不再做 token_get_all 检查
// ponytail: 项目里存在多种"上下文依赖型"hook 片段，单独检查必然误报：
//   - model_inc_file.php：数组元素片段（APP_PATH.'xxx',）
//   - index_route_case_end.php：switch case 片段（case 'xxx': ... break;）
//   - 可能还有其他片段型 hook 未发现
// 逐一枚举豁免太脆弱，改为不做语法预检，语法错误的 hook 由 B 部分（autoDisableCrashedPlugin 崩溃计数）兜底
// 保留 plugin-compile 注释注入，用于 fatal error 归因
```

核心矛盾：**很多 hook 文件不是完整的 PHP 程序，而是「上下文片段」**。

| Hook 文件 | 内容形态 | 单独 token_get_all 检查 |
|---|---|---|
| `xnx_demo/hook/thread_create_after.php` | 完整语句 `foo();` | ✅ 能过 |
| `xnx_demo/hook/model_inc_file.php` | 数组元素片段 `APP_PATH.'xxx',` | ❌ 误报「语法错误」 |
| `xnx_demo/hook/index_route_case_end.php` | switch case 片段 `case 'foo': bar(); break;` | ❌ 误报「语法错误」 |

逐一枚举豁免清单太脆弱（新 hook 类型一出现就要补），所以改为：

- **编译期不预检**：让所有 hook 都拼进去
- **运行时崩溃计数兜底**：真有语法错误的 hook 触发 fatal error → `autoDisableCrashedPlugin` 计数 → 3 次后禁用
- **注释归因保留**：禁用前先靠 `// plugin-compile:` 注释定位到具体插件

> 💡 **设计取舍**：宁可让坏插件崩 3 次再禁用，也不让好插件被误报拦在编译期。这是「容错优先」而非「严格优先」的典型选择。

---

## 4. tmp/ 缓存损坏自愈机制

### 4.1 检测逻辑

`handleShutdown` 在处理致命错误时，会先判断错误文件是否在 `tmp/` 目录下：

```php
// 兼容 Unix (/tmp/) 和 Windows (\tmp\) 路径分隔符，统一规范化后匹配
$errorFile = str_replace('\\', '/', $error['file']);
$isCacheCorruption = (strpos($errorFile, '/tmp/') !== false);
```

`tmp/` 目录下存放的都是编译缓存（`tmp/<hash>.php`、`tmp/model.min.php`），属于「可重建」的产物。一旦它们 fatal error，基本都是编译产物损坏（并发写入冲突、磁盘满、opcode cache 失效等），不是源码问题。

### 4.2 自愈操作

```php
if ($isCacheCorruption) {
    xn_log("Cache corruption detected: {$cacheFile}, attempting recovery", 'cache_error', 'ERROR');

    // 删除损坏的缓存文件，下次访问时会重新编译
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }

    // 提示用户刷新页面重建缓存
    if ($debug == 0) {
        $displayMessage = $disabled_plugin
            ? "插件 [{$disabled_plugin}] 反复崩溃已自动禁用，请刷新页面"
            : '服务器缓存损坏，请刷新页面重试';
    } else {
        $displayMessage = "Cache corruption: {$error['message']}"
            . " in {$error['file']}"
            . " on line {$error['line']}"
            . ($disabled_plugin ? "（插件 [{$disabled_plugin}] 已自动禁用）" : '（缓存已清理，请刷新）');
    }

    self::renderError(500, $displayMessage, 500);
    return;
}
```

自愈三步：

1. **记日志**：`xn_log(..., 'cache_error', 'ERROR')`，文件名含 `error` 确保生产环境也写入
2. **删损坏文件**：`@unlink($cacheFile)`，`@` 抑制权限不足等次要错误
3. **提示刷新**：渲染 500 页面，提示用户「刷新页面重试」——下次请求会因缓存缺失而重新编译

### 4.3 与 autoDisableCrashedPlugin 的协作

`handleShutdown` 中，缓存损坏检测和插件归因**不是互斥**，而是**串行协作**：

```php
$isCacheCorruption = (strpos($errorFile, '/tmp/') !== false);

// 无论是否 tmp/ 损坏，都尝试归因到插件
$disabled_plugin = self::autoDisableCrashedPlugin($error['file'], $error['line']);

if ($isCacheCorruption) {
    // 1. 删缓存
    // 2. 提示消息里带上 disabled_plugin（如果有）
    ...
}
```

为什么 `tmp/` 损坏也要归因？因为 `tmp/` 文件的损坏**根源往往是某个插件的 hook 有语法错误**：

```
插件 hook 有语法错误
        │
        ▼
编译期拼进 tmp/abc123.php（语法错误蔓延到整个合并文件）
        │
        ▼
运行时 include tmp/abc123.php → E_PARSE fatal error
        │
        ▼
handleShutdown 检测到 /tmp/ 路径 → isCacheCorruption = true
        │
        ▼
autoDisableCrashedPlugin 从错误行号往前扫 // plugin-compile: 注释
        │
        ├─ 找到 → 计数 +1 → 达阈值禁用该插件
        └─ 没找到 → 仅删缓存，等下次崩溃再归因
```

所以提示消息有两种组合：

| 场景 | 提示消息 |
|---|---|
| tmp/ 损坏 + 归因成功（已禁用） | `插件 [xxx] 反复崩溃已自动禁用，请刷新页面` |
| tmp/ 损坏 + 归因失败（未达阈值或未命中） | `服务器缓存损坏，请刷新页面重试` |

> ⚠️ **第一次崩溃不会立刻禁用插件**：阈值是 3 次/小时，所以前 2 次只会删缓存 + 提示刷新，第 3 次才禁用。这是给插件作者修复的窗口期，避免偶发抖动误杀好插件。

---

## 5. plugin_hook 运行时分发机制

### 5.1 运行时分发 vs 编译时合并

第 1 节（[01-architecture.md](01-architecture.md)）讲过，XIUNOX 的主机制是**编译时合并**：`plugin_compile_srcfile_callback` 把 hook 文件物理拼进 `tmp/` 缓存。但有些场景不适合编译时合并：

- **错误隔离需求**：编译时合并后，一个 hook 崩溃整个 `tmp/` 文件 fatal error，影响所有插件。希望单 hook 崩溃不阻断其他 hook。
- **动态 hook**：hook 名在运行时才确定（如根据用户状态选择 hook），编译期无法静态匹配。
- **调试期频繁改 hook**：不想每次改都清 `tmp/` 重编译。

`plugin_hook($hookname, &$data)` 提供运行时分发替代方案：

```php
function plugin_hook($hookname, &$data = NULL) {
    global $conf;
    if(empty($hookname)) return;

    // 收集所有已启用插件中匹配 hookname 的 hook 文件，按 hooks_rank 降序
    $plugin_paths = plugin_paths_enabled();
    if(empty($plugin_paths)) return;

    $hookfiles = array();
    foreach($plugin_paths as $path => $pconf) {
        $dir = file_name($path);
        $hookpath = APP_PATH . "plugin/$dir/hook/$hookname";
        if(!is_file($hookpath)) continue;
        $rank = isset($pconf['hooks_rank'][$hookname]) ? $pconf['hooks_rank'][$hookname] : 0;
        $hookfiles[] = array('path' => $hookpath, 'rank' => $rank, 'dir' => $dir);
    }
    if(empty($hookfiles)) return;

    // 按 rank 降序（与编译时 plugin_compile_srcfile_callback 排序一致）
    usort($hookfiles, function($a, $b) {
        return $b['rank'] - $a['rank'];
    });

    foreach($hookfiles as $hf) {
        // 错误隔离：单 hook 出错不影响其他 hook 和主流程
        try {
            $t = file_get_contents($hf['path']);
            if($t === FALSE) continue;
            // 去掉防直接访问前缀，与编译时处理一致
            if(preg_match('#^\s*<\?php\s+exit;#is', $t)) {
                $t = preg_replace('#^\s*<\?php\s*exit;#is', '', $t);
                $t = preg_replace('#\?>\s*$#', '', $t);
            } elseif(preg_match('#^\s*<\?php#is', $t)) {
                $t = preg_replace('#^\s*<\?php\s*#', '', $t);
                $t = preg_replace('#\?>\s*$#', '', $t);
            }
            eval($t);
        } catch(\Throwable $e) {
            $msg = "Plugin hook error: $hookname in plugin " . $hf['dir'] . ": " . $e->getMessage();
            xn_log($msg, 'plugin_error');
            xn_log($e->getTraceAsString(), 'plugin_error_debug');
            // 继续执行后续 hook，不终止
        }
    }
}
```

两种机制对比：

| 维度 | 编译时合并（`// hook xxx`） | 运行时分发（`plugin_hook()`） |
|---|---|---|
| 触发时机 | `_include()` 编译期 | 运行时显式调用 |
| 性能 | 高（缓存命中后直接 include） | 低（每次都 glob + file_get_contents + eval） |
| 错误隔离 | ❌ 单 hook 崩溃影响整个 tmp 文件 | ✅ try/catch 隔离，单 hook 崩溃不阻断 |
| hook 类型 | `.php` + `.htm` | 仅 `.php` |
| 排序 | `hooks_rank` 降序 + `plugin_dir` 字母升序 | `hooks_rank` 降序 |
| 适用场景 | 主流程的静态 hook 点 | 动态 hook、调试、需要隔离的场景 |

### 5.2 try/catch Throwable 错误隔离

核心是每个 hook 包在 `try { eval($t); } catch(\Throwable $e) { ... }` 里：

- **`\Throwable` 而非 `\Exception`**：PHP 7+ 的 `TypeError`、`Error`（如 `undefined function`）不继承 `\Exception`，必须用 `\Throwable` 才能同时捕获。
- **错误记日志**：`xn_log($msg, 'plugin_error')` 记主消息，`xn_log($trace, 'plugin_error_debug')` 记堆栈到 debug 日志（文件名含 `error` 才会在生产环境写入）。
- **继续执行后续 hook**：catch 块不 `throw`、不 `return`，循环继续到下一个 hook，最大化「局部故障不影响全局」。

> ⚠️ **`eval` 是设计核心，无法替代**：`ponytail:` 注释明确说明——hook 文件以 `<?php exit;` 开头防直接访问，剥离标签后必须在调用方作用域执行（访问 `$data` 引用和全局变量）；`include` 会因 `exit;` 终止，`Closure` 无法注入调用方作用域的 `$data`，故只能用 `eval`。已知风险：恶意 hook 文件可执行任意代码——但 hook 文件由开发者提供，等同源代码信任级别。

### 5.3 使用示例

调用方代码（在模型层或路由层）：

```php
// model/thread.func.php
function thread_create($uid, $fid, $subject, $message) {
    // ... 创建主题的核心逻辑 ...
    $tid = db_insert('thread', $new);

    // 运行时分发：通知所有插件「主题已创建」
    $args = array('tid' => $tid, 'uid' => $uid, 'fid' => $fid);
    plugin_hook('thread_create_after.php', $args);

    return $tid;
}
```

插件方代码（`plugin/xnx_demo/hook/thread_create_after.php`）：

```php
<?php exit; ?>
// 这段代码在调用方作用域 eval，可直接访问 $args
$tid = $args['tid'];
db_update('xnx_demo_stats', array('uid' => $args['uid']), array('last_tid' => $tid));
```

> 💡 **`plugin_hook` 的 hook 文件写法与编译时 hook 完全一致**：都是 `<?php exit;` 开头防直接访问，内容是裸 PHP 语句。同一个 hook 文件既能被编译期合并（如果有 `// hook thread_create_after.php` 标记），也能被 `plugin_hook` 运行时分发。区别仅在调用方触发方式。

### 5.4 xn_hook 已废弃

```php
/**
 * 兼容旧版 xn_hook() 调用
 * @deprecated 已被 plugin_hook() 替代，仅为向后兼容保留
 */
function xn_hook($hookname, &$data = NULL) {
    // 旧版 xn_hook 不带 .php 后缀，新版 plugin_hook 需要含扩展名（如 thread_create_after.php）
    // 幂等：调用方无论是否带 .php 后缀都能正确分发
    if(substr($hookname, -4) !== '.php') {
        $hookname .= '.php';
    }
    return plugin_hook($hookname, $data);
}
```

`xn_hook` 是早期版本的运行时分发函数，现已被 `plugin_hook` 替代。保留它仅为向后兼容：

- **唯一差异**：`xn_hook` 接受不带 `.php` 后缀的 hook 名（如 `thread_create_after`），`plugin_hook` 要求带后缀（`thread_create_after.php`）。
- **幂等处理**：`xn_hook` 内部检测并补 `.php` 后缀，然后委托给 `plugin_hook`。
- **新代码禁用**：`@deprecated` 标记，IDE 会提示告警。新插件应直接用 `plugin_hook()`。

> ⚠️ **写新插件时直接用 `plugin_hook($hookname, $data)`**，`$hookname` 必须含 `.php` 后缀。`xn_hook` 仅用于兼容存量代码。

---

## 小结

- **ErrorHandler 三路接管**：`handleError`（Warning/Notice）→ `handleException`（Throwable）→ `handleShutdown`（Fatal Error），覆盖 PHP 所有错误通道
- **崩溃计数 3 次/小时自动禁用**：`autoDisableCrashedPlugin` 用 cache 滚动窗口计数，达阈值写 `conf.json` + 写 `bbs_plugin` 表 + 清 `tmp/`，不依赖 `plugin_disable()` 的运行时上下文
- **plugin-compile 注释是归因信标**：编译期注入到 `tmp/` 文件，fatal error 时从错误行号往前扫描定位插件目录，解决「合并文件崩溃怎么找具体插件」难题
- **token_get_all 语法预检已废弃**：上下文依赖型 hook 片段会误报，改为崩溃计数兜底
- **tmp/ 缓存损坏自愈**：检测到 `tmp/` 路径 fatal error 自动 `unlink` 损坏文件 + 提示刷新，与插件归因串行协作
- **plugin_hook 运行时分发带错误隔离**：`try/catch Throwable` 单 hook 崩溃不阻断其他 hook 和主流程，仅支持 `.php` hook；`xn_hook` 已 `@deprecated`

---

## 相关章节

- [01-architecture.md](01-architecture.md) —— 插件架构原理，编译时合并 vs 运行时分发的基础概念
- [02-plugin-structure.md](02-plugin-structure.md) —— 插件目录结构，hook 文件放置约定
- [06-ai-collaboration.md](06-ai-collaboration.md) —— AI 协作开发规范，包含安全检查清单
