<?php

!defined('DEBUG') AND exit('Access Denied.');

class CsrfService {

    public static function generate(): string {
        if (isset($_SESSION['csrf_token'])) {
            return $_SESSION['csrf_token'];
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    public static function check(): void {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            $is_htmx = !empty($_SERVER['HTTP_HX_REQUEST']);

            // 安全修复：移除敏感信息（session_id/cookie_sid），仅记录请求特征
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            error_log('[CSRF] check failed: ip=' . $ip . ' uri=' . $uri);

            if ($is_htmx) {
                // htmx 请求：返回 HTML 错误片段
                header('Content-Type: text/html; charset=utf-8');
                echo '<div class="alert alert-danger py-2 small mb-2">CSRF验证失败，请刷新页面重试</div>';
                exit;
            }

            // 非 htmx 请求：返回 JSON
            header('Content-Type: application/json; charset=utf-8');
            echo xn_json_encode(array(
                'code' => '-1',
                'message' => 'CSRF token verification failed',
            ));
            exit;
        }
    }

    public static function input(): string {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES | ENT_HTML5) . '">';
    }

    public static function getToken(): string {
        return $_SESSION['csrf_token'] ?? '';
    }

}
