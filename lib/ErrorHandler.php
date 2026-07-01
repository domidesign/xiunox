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

        // 系统异常：返回 500
        if ($debug == 0) {
            $displayMessage = '服务器内部错误';
        } else {
            $displayMessage = get_class($exception) . ': ' . $exception->getMessage()
                . ' in ' . $exception->getFile()
                . ' on line ' . $exception->getLine();
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
                $displayMessage = '服务器缓存损坏，请刷新页面重试';
            } else {
                $displayMessage = "Cache corruption: {$error['message']}"
                    . " in {$error['file']}"
                    . " on line {$error['line']}（缓存已清理，请刷新）";
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
