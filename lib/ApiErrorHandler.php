<?php

class ApiErrorHandler {

    private static bool $handled = false;

    public static function handle(\Throwable $e): void {
        if (self::$handled) {
            return;
        }
        self::$handled = true;

        $debug = defined('DEBUG') ? DEBUG : 0;

        $trace = $e->getTraceAsString();
        $logMessage = get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile()
            . ' on line ' . $e->getLine();

        xn_log("API Exception: {$logMessage}\nTrace:\n{$trace}", 'api_error', 'ERROR');

        if ($e instanceof BizException) {
            self::respond(200, $e->getMessage(), $e->getCode() ?: 200, $e->data ?? []);
            return;
        }

        if ($debug == 0) {
            $displayMessage = '服务器内部错误';
        } else {
            $displayMessage = get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile()
                . ' on line ' . $e->getLine();
        }

        self::respond(500, $displayMessage, 500);
    }

    public static function handleQuietly(\Throwable $e, string $context = ''): void {
        $debug = defined('DEBUG') ? DEBUG : 0;

        $logMessage = get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile()
            . ' on line ' . $e->getLine();

        if ($context !== '') {
            $logMessage = "[{$context}] {$logMessage}";
        }

        xn_log($logMessage, 'api_error', 'WARNING');

        if ($debug) {
            xn_log($e->getTraceAsString(), 'api_error', 'DEBUG');
        }
    }

    private static function respond(int $httpCode, string $msg, int $code, array $data = [], array $errors = []): void {
        if (!class_exists('ApiResponse', false)) {
            self::fallbackRespond($httpCode, $msg, $data, $errors);
            return;
        }

        switch ($code) {
            case 401:
                ApiResponse::unauthorized($msg);
                break;
            case 403:
                ApiResponse::forbidden($msg);
                break;
            case 404:
                ApiResponse::notFound($msg);
                break;
            case 422:
                ApiResponse::validationError($msg, $errors);
                break;
            case 429:
                ApiResponse::tooManyRequests($msg);
                break;
            default:
                ApiResponse::error($code, $msg, $data, $errors);
                break;
        }
    }

    private static function fallbackRespond(int $httpCode, string $msg, array $data, array $errors): void {
        if (headers_sent()) {
            return;
        }

        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'code' => $httpCode,
            'msg' => $msg,
            'data' => $data,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}