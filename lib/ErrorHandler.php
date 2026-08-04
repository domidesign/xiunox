<?php
/**
 * 全局错误处理器
 *
 * 统一管理 PHP 错误、异常和致命错误的捕获与处理，
 * 根据调试模式决定是否向用户展示详细错误信息。
 *
 * - BizException（业务异常）：返回 200 + 业务码，不泄露堆栈
 * - 其他 Throwable（系统异常）：返回 500，避免白屏
 * - Fatal Error：register_shutdown_function 兜底渲染 500
 */
class ErrorHandler
{
    /**
     * 保存原始错误处理器，用于开发模式下委托调用
     * @var callable|null
     */
    private static $previousErrorHandler = null;

    /**
     * 注册全局错误、异常和关闭处理函数
     */
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

    /**
     * 处理 PHP 常规错误
     *
     * PHP 8 的 Warning/Notice 不应被静默吞掉：当 error_reporting() 包含该错误级别时
     * 抛出 ErrorException，交由 handleException 统一渲染。
     * 被 @ 抑制（error_reporting()=0）的错误不抛异常，保留原行为。
     *
     * @param int    $errno   错误级别
     * @param string $errstr  错误信息
     * @param string $errfile 错误文件
     * @param int    $errline 错误行号
     * @return bool  返回 FALSE 让 PHP 默认错误处理器继续处理
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // 记录日志（DEBUG=0 时 xn_log 内部会按级别过滤）
        xn_log("Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}", 'error', 'WARNING');

        // error_reporting() & $errno 为 0 表示被 @ 抑制或生产环境 error_reporting=0，不抛异常
        if (error_reporting() & $errno) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }

        return false;
    }

    /**
     * 处理未捕获的异常
     *
     * BizException 视为业务错误，返回 200 + 业务码；
     * 其他异常视为系统异常，返回 500。
     *
     * @param \Throwable $exception 异常对象
     */
    public static function handleException(\Throwable $exception): void
    {
        $debug = defined('DEBUG') ? DEBUG : 0;

        // 记录异常信息及调用栈
        $trace = $exception->getTraceAsString();
        $logMessage = get_class($exception) . ': ' . $exception->getMessage()
            . ' in ' . $exception->getFile()
            . ' on line ' . $exception->getLine();
        xn_log("Exception: {$logMessage}\nTrace:\n{$trace}", 'error', 'ERROR');

        // BizException：业务异常，HTTP 200 + 业务错误码，不泄露堆栈
        if ($exception instanceof BizException) {
            self::renderError(200, $exception->getMessage(), 200, $exception);
            return;
        }

        // 尝试归因到插件并自动禁用反复崩溃的插件
        // PHP 7+ 的 undefined function / undefined class 等会以 Error 异常形式抛出，走 handleException 而非 handleShutdown
        $disabled_plugin = self::autoDisableCrashedPlugin($exception->getFile(), $exception->getLine());

        // 系统异常：返回 500
        if ($debug == 0) {
            $displayMessage = $disabled_plugin
                ? "插件 [{$disabled_plugin}] 反复崩溃已自动禁用，请刷新页面"
                : '服务器内部错误';
        } else {
            $displayMessage = get_class($exception) . ': ' . $exception->getMessage()
                . ' in ' . $exception->getFile()
                . ' on line ' . $exception->getLine()
                . ($disabled_plugin ? "（插件 [{$disabled_plugin}] 已自动禁用）" : '');
        }
        self::renderError(500, $displayMessage, 500, $exception);
    }

    /**
     * 处理致命错误（关闭阶段回调）
     *
     * 检查是否存在致命错误，若有则记录日志并展示错误页面。
     * 使用 headers_sent 检查和 try/catch 防止无限循环。
     *
     * 缓存损坏检测：当 fatal error 文件路径位于 tmp/ 目录时（如 min.php 合并场景
     * 产生的语法错误），自动删除损坏的缓存文件，提示用户刷新页面重建缓存，
     * 避免用户持续看到 500 错误。
     *
     * 插件崩溃自动禁用：对 tmp/ 文件错误和非 tmp/ 的 plugin/ 路径错误都尝试归因到
     * 具体插件目录，1 小时内同插件崩溃超过阈值（3 次）自动禁用该插件，避免反复白屏。
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        // 仅处理致命错误类型
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        $debug = defined('DEBUG') ? DEBUG : 0;

        // 记录致命错误日志
        $message = "Fatal Error [{$error['type']}]: {$error['message']}"
            . " in {$error['file']}"
            . " on line {$error['line']}";
        xn_log($message, 'error', 'ERROR');

        // 检测 tmp/ 缓存损坏场景（min.php 合并错误、模板编译损坏等）
        // 兼容 Unix (/tmp/) 和 Windows (\tmp\) 路径分隔符，统一规范化后匹配
        $errorFile = str_replace('\\', '/', $error['file']);
        $isCacheCorruption = (strpos($errorFile, '/tmp/') !== false);

        // 尝试归因到插件并自动禁用反复崩溃的插件
        // 对 tmp/ 文件错误（从行号往前找 plugin-compile 注释）和非 tmp/ 的 plugin/ 路径错误都尝试归因
        $disabled_plugin = self::autoDisableCrashedPlugin($error['file'], $error['line']);

        if ($isCacheCorruption) {
            // 记录缓存损坏日志（文件名包含 error，确保生产环境也写入）
            $cacheFile = $error['file'];
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

        // 非 tmp/ 错误（如 plugin/xxx/file.php 直接报错），且成功归因并禁用了插件
        if ($disabled_plugin) {
            if ($debug == 0) {
                $displayMessage = "插件 [{$disabled_plugin}] 反复崩溃已自动禁用，请刷新页面";
            } else {
                $displayMessage = "Fatal Error: {$error['message']}"
                    . " in {$error['file']}"
                    . " on line {$error['line']}"
                    . "（插件 [{$disabled_plugin}] 已自动禁用）";
            }
            self::renderError(500, $displayMessage, 500);
            return;
        }

        // 根据调试模式决定展示内容
        if ($debug == 0) {
            $displayMessage = '服务器内部错误';
        } else {
            $displayMessage = "Fatal Error: {$error['message']}"
                . " in {$error['file']}"
                . " on line {$error['line']}";
        }

        self::renderError(500, $displayMessage, 500);
    }

    /**
     * 归因 fatal error / Throwable 到具体插件目录，超过崩溃阈值时自动禁用该插件
     *
     * 归因策略：
     * 1. 错误文件路径直接在 plugin/xxx/ 下 → 插件 dir = xxx
     * 2. 错误文件在 tmp/ 目录下 → 读 tmp 文件，从错误行号往前找最近的 `// plugin-compile: {dir}` 注释
     *    （该注释由 plugin_compile_srcfile_callback 在编译时拼接注入）
     *
     * 计数策略：cache_get/cache_set 实现 1 小时滚动窗口计数，超阈值（3 次）自动禁用
     * 禁用操作：db_update（写 bbs_plugin.enable=0）+ 清 tmp 目录 + 清 OPcache
     * ponytail: 不调 plugin_disable() 因为它依赖 global $plugins（前台未初始化），直接 db_update
     * ponytail: conf.json 的 enable/installed 已彻底废弃，代码层任何情况下都不读，禁用只写 db
     *
     * @param string $errorFile 错误发生的文件路径（__FILE__ / $exception->getFile()）
     * @param int    $errorLine 错误发生的行号
     * @return string|null 成功归因并禁用返回插件 dir，未归因或未达阈值返回 null
     */
    private static function autoDisableCrashedPlugin(string $errorFile, int $errorLine): ?string
    {
        if (!defined('APP_PATH')) return null;

        $file = str_replace('\\', '/', $errorFile);
        $line = $errorLine;
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

        // 计数 +1（1 小时窗口）
        $cache_key = 'plugin_crash_' . $plugin_dir;
        $count = 0;
        if (function_exists('cache_get')) {
            $cached = cache_get($cache_key);
            $count = $cached ? intval($cached) : 0;
        }
        $count++;
        if (function_exists('cache_set')) {
            cache_set($cache_key, $count, 3600);
        }

        // 阈值：3 次/小时
        $threshold = 3;
        if ($count < $threshold) {
            xn_log("Plugin crash count {$count}/{$threshold} for [{$plugin_dir}]", 'plugin_crash_error');
            return null;
        }

        // 达到阈值，自动禁用
        xn_log("Plugin [{$plugin_dir}] crashed {$count} times within 1h, auto-disabling", 'plugin_crash_error');

        // ponytail: conf.json 的 enable/installed 已彻底废弃，代码层任何情况下都不读
        // 禁用插件只写 db bbs_plugin 表（唯一权威源），不再 file_replace_var 写 conf.json

        // 1. 写数据库 enable=0
        // ponytail: 检查 $_SERVER['db']（与 db_update 内部一致），不依赖 global $db（fatal 时可能未声明）
        // db_update 失败必须记录日志，否则会出现"显示已禁用但实际未禁用"的假阳性
        $db_disabled_ok = FALSE;
        $now = time();
        if (isset($_SERVER['db']) && is_object($_SERVER['db']) && function_exists('db_update')) {
            try {
                db_update('plugin', array('dir' => $plugin_dir), array('enable' => 0, 'update_time' => $now));
                // 验证写入是否成功（db_update 静默返回 FALSE 时不抛异常，需主动校验）
                $verify = function_exists('db_find_one') ? db_find_one('plugin', array('dir' => $plugin_dir)) : array();
                if (!empty($verify) && (int)$verify['enable'] === 0) {
                    $db_disabled_ok = TRUE;
                } else {
                    xn_log("autoDisableCrashedPlugin: db_update executed but verify failed for [{$plugin_dir}]", 'plugin_crash_error');
                }
            } catch (\Throwable $e) {
                xn_log("autoDisableCrashedPlugin: db_update failed for [{$plugin_dir}]: " . $e->getMessage(), 'plugin_crash_error');
            }
        } else {
            xn_log("autoDisableCrashedPlugin: db not available, skip disable for [{$plugin_dir}]", 'plugin_crash_error');
        }

        // 2. 清 tmp 编译缓存，触发下次请求重新编译（已禁用插件的 hook 不会被编译进去）
        // ponytail: PHP 8+ 中 @ 不捕获 Error（disabled functions 抛 Error），xn_unlink 内部用 @unlink
        // 若 unlink 被禁用会终止脚本，故用 function_exists 守卫 + try/catch 兜底
        global $conf;
        $tmp_files_deleted = FALSE;
        $tmp_files_invalidated = 0;
        if (isset($conf['tmp_path'])) {
            $tmp_path = $conf['tmp_path'];
            $tmp_patterns = array(
                'index.inc.php',
                'model_*.func.php',
                'route_*.php',
                'view_htm_*.htm',
                'plugin_*.php',
            );
            $deleted_any = FALSE;
            foreach ($tmp_patterns as $pattern) {
                $files = glob($tmp_path . $pattern);
                if (empty($files)) continue;
                foreach ($files as $f) {
                    // 删除前先 opcache_invalidate（文件还在，可强制当前 worker 丢弃旧字节码）
                    // ponytail: opcache_invalidate 仅当前 worker 生效，其他 worker 需依赖 validate_timestamps 或重启 FPM
                    if (function_exists('opcache_invalidate') && is_file($f)) {
                        try { @opcache_invalidate($f, true); $tmp_files_invalidated++; } catch (\Throwable $e) {}
                    }
                    try {
                        if (function_exists('unlink') && is_file($f)) {
                            @unlink($f);
                            $deleted_any = TRUE;
                        }
                    } catch (\Throwable $e) {
                        xn_log("autoDisableCrashedPlugin: unlink failed for {$f}: " . $e->getMessage(), 'plugin_crash_error');
                    }
                }
            }
            $tmp_files_deleted = $deleted_any;
            // 确保 tmp 目录存在（rmdir_recusive 可能已删除）
            if (!is_dir($tmp_path)) {
                if (function_exists('mkdir')) {
                    @mkdir($tmp_path, 0755, TRUE);
                }
            }
        }

        // 3. 同步清理数据缓存 + OPcache（与 plugin_clear_tmp_dir() 保持一致）
        // ponytail: PHP-FPM 多进程环境下，只清 tmp/ 不足以让其他 worker 进程丢弃旧字节码
        // 若不清理 OPcache，worker 仍执行已包含禁用插件 hook 的旧字节码，导致循环崩溃
        // ponytail: opcache_reset() 只清当前 worker 进程的 OPcache，其他 worker 不受影响
        //   依赖 tmp/ 文件 mtime 变化触发其他 worker 的 validate_timestamps 重载（若启用）
        //   若 validate_timestamps=0，其他 worker 需重启 PHP-FPM 才能丢弃旧字节码
        if(class_exists('CacheService', false)) {
            try {
                CacheService::clearByType(array('data', 'opcache'));
            } catch(\Throwable $e) {
                error_log('autoDisableCrashedPlugin clearByType(data,opcache) failed: '.$e->getMessage());
            }
        } else {
            if(function_exists('opcache_reset')) { @opcache_reset(); }
            if(function_exists('cache_truncate')) { @cache_truncate(); }
        }

        // 4. 重置计数（避免后续每次崩溃都重复执行禁用逻辑并显示"已自动禁用"消息）
        // ponytail: 即使 db_update 失败也重置计数，避免 1 小时内反复刷"已禁用"消息误导用户
        //   若 db 真的没禁用成功，下次崩溃会重新计数到阈值再次尝试禁用（覆盖 db_update 偶发失败场景）
        if (function_exists('cache_delete')) {
            @cache_delete($cache_key);
        } elseif (function_exists('cache_set')) {
            @cache_set($cache_key, 0, 3600);
        }

        // 日志汇总：记录禁用结果，便于排查"显示已禁用但实际没禁用"问题
        // ponytail: PHP-FPM 多 worker + validate_timestamps=0 环境下，其他 worker 仍可能执行旧字节码
        //   需重启 PHP-FPM 才能彻底生效，此处日志提示管理员关注
        xn_log("autoDisableCrashedPlugin done: plugin=[{$plugin_dir}] db_ok={$db_disabled_ok} tmp_deleted={$tmp_files_deleted} opcache_invalidated={$tmp_files_invalidated} fpm_workers_warning=may_need_fpm_restart_if_validate_timestamps_off", 'plugin_crash_error');

        // 若 db 未禁用成功，不返回 plugin_dir（不显示"已自动禁用"消息，避免误导用户）
        // ponytail: db 失败时用户看到的应该是"服务器内部错误"，由管理员手动排查
        if (!$db_disabled_ok) {
            return null;
        }

        return $plugin_dir;
    }

    /**
     * 统一 JSON 响应输出
     *
     * 供 message() 等 AJAX/JSON 场景调用，保证响应格式一致：
     * {"code":"...", "message":"...", ...extra}
     *
     * @param mixed  $code    业务码
     * @param string $message 消息
     * @param array  $data    附加字段
     */
    public static function renderJson($code, $message, $data = array()): void
    {
        $arr = is_array($data) ? $data : array();
        $arr['code'] = (string)$code;
        $arr['message'] = $message;
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo xn_json_encode($arr);
        exit;
    }

    /**
     * 渲染错误页面
     *
     * 优先调用项目 error_page()（含模板），失败或不可用时使用内置兜底页面，
     * 确保任何阶段异常都不白屏，且不泄露敏感信息。
     *
     * @param int           $code        业务/HTTP 码（用于兜底页面展示）
     * @param string        $message     展示消息
     * @param int           $http_status HTTP 状态码
     * @param \Throwable|null $e         异常对象（DEBUG 时展示堆栈）
     */
    private static function renderError($code, $message, $http_status, ?\Throwable $e = null): void
    {
        // 优先使用项目错误页模板
        if (function_exists('error_page') && !headers_sent()) {
            try {
                error_page($http_status, is_string($message) ? $message : '');
                return;
            } catch (\Throwable $ex) {
                // error_page 失败时走兜底，避免递归崩溃
                xn_log('ErrorHandler: error_page() failed - ' . $ex->getMessage(), 'error', 'ERROR');
            }
        }

        if (!headers_sent()) {
            http_response_code($http_status);
            header('Content-Type: text/html; charset=utf-8');
        }

        // 简单错误页面，不泄露敏感信息
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title>';
        echo '<style>body{font-family:-apple-system,sans-serif;padding:40px;line-height:1.6;color:#333;max-width:720px;margin:0 auto}h1{color:#dc3545}</style>';
        echo '</head><body>';
        echo '<h1>服务器错误</h1>';
        echo '<p>' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</p>';
        if (defined('DEBUG') && DEBUG && $e) {
            echo '<pre>' . htmlspecialchars($e->getTraceAsString() ?? '', ENT_QUOTES, 'UTF-8') . '</pre>';
        }
        echo '</body></html>';
    }
}
