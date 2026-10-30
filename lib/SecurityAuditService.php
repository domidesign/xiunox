<?php

require_once __DIR__ . '/PrivateAttachmentStorage.php';
require_once __DIR__ . '/RateLimitService.php';

class SecurityAuditService {
    private static string $requestId = '';

    public static function begin(): void {
        self::$requestId = bin2hex(random_bytes(12));
        header('X-Request-Id: ' . self::$requestId);
        register_shutdown_function(static function () {
            $status = http_response_code() ?: 200;
            self::event('api_request', array('status'=>$status, 'uid'=>intval($GLOBALS['uid'] ?? 0)));
            if (in_array($status, array(401, 403, 404), true)) {
                $limiter = new RateLimitService(60, 300);
                $limiter->check('security_rejections:' . RateLimitService::getClientKey());
            }
        });
    }

    public static function event(string $event, array $context = array()): void {
        try {
            $segments = explode('/', trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/'));
            $row = array(
                'time'=>gmdate('c'), 'request_id'=>self::$requestId,
                'event'=>$event, 'method'=>$_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'resource'=>substr(preg_replace('/[^a-zA-Z0-9_]/', '', $segments[2] ?? ''), 0, 40),
                'object_id'=>isset($segments[3]) ? intval($segments[3]) : 0,
                'ip'=>$_SERVER['REMOTE_ADDR'] ?? '',
                'appid'=>substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $_SERVER['HTTP_X_APP_ID'] ?? ''), 0, 64),
            );
            $row += array_intersect_key($context, array_flip(array('uid','aid','status','result','reason')));
            $file = PrivateAttachmentStorage::privatePath('security-' . gmdate('Ymd') . '.jsonl', 'audit');
            if (file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('Audit write failed');
        } catch (Throwable $e) { error_log('XIUNOX security audit unavailable'); }
    }
}
