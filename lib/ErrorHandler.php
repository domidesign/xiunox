<?php
/**
 * 全局错误处理器
 *
 * 统一管理 PHP 错误、异常和致命错误的捕获与处理，
 * 根据调试模式决定是否向用户展示详细错误信息。
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
        self::$previousErrorHandler = set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * 处理 PHP 常规错误
     *
     * 生产模式：仅记录日志，不输出
     * 开发模式：记录日志并委托给原始错误处理器
     *
     * @param int    $errno   错误级别
     * @param string $errstr  错误信息
     * @param string $errfile 错误文件
     * @param int    $errline 错误行号
     * @return bool  返回 TRUE 以抑制 PHP 默认错误处理器
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $debug = defined('DEBUG') ? DEBUG : 0;

        // 记录日志
        xn_log("Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}", 'error');

        if ($debug > 0) {
            // 开发模式：委托给 xiunophp 原始错误处理器继续处理
            if (is_callable(self::$previousErrorHandler)) {
                call_user_func(self::$previousErrorHandler, $errno, $errstr, $errfile, $errline);
            } elseif (function_exists('error_handle')) {
                error_handle($errno, $errstr, $errfile, $errline);
            }
        }

        // 返回 TRUE 抑制 PHP 默认错误处理器
        return true;
    }

    /**
     * 处理未捕获的异常
     *
     * @param \Throwable $exception 异常对象
     */
    public static function handleException(\Throwable $exception): void
    {
        $debug = defined('DEBUG') ? DEBUG : 0;

        // 记录异常信息及调用栈
        $trace = $exception->getTraceAsString();
        $message = get_class($exception) . ': ' . $exception->getMessage()
            . ' in ' . $exception->getFile()
            . ' on line ' . $exception->getLine();
        xn_log("Exception: {$message}\nTrace:\n{$trace}", 'error');

        // 根据调试模式决定展示内容
        if ($debug == 0) {
            $displayMessage = '服务器内部错误';
        } else {
            $displayMessage = get_class($exception) . ': ' . $exception->getMessage()
                . ' in ' . $exception->getFile()
                . ' on line ' . $exception->getLine();
        }

        // 调用错误页面展示
        if (function_exists('error_page')) {
            error_page(500, $displayMessage);
        }
    }

    /**
     * 处理致命错误（关闭阶段回调）
     *
     * 检查是否存在致命错误，若有则记录日志并展示错误页面。
     * 使用 headers_sent 检查和 try/catch 防止无限循环。
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
        xn_log($message, 'error');

        // 根据调试模式决定展示内容
        if ($debug == 0) {
            $displayMessage = '服务器内部错误';
        } else {
            $displayMessage = "Fatal Error: {$error['message']}"
                . " in {$error['file']}"
                . " on line {$error['line']}";
        }

        // 防止 error_page 自身出错导致无限循环
        try {
            if (!headers_sent() && function_exists('error_page')) {
                error_page(500, $displayMessage);
            }
        } catch (\Throwable $e) {
            // error_page 失败时静默处理，避免递归崩溃
            xn_log('ErrorHandler: error_page() failed - ' . $e->getMessage(), 'error');
        }
    }
}
