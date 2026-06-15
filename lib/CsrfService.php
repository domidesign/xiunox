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

            // 调试信息
            $debug = array(
                'token_received' => empty($token) ? '(empty)' : substr($token, 0, 8) . '...',
                'session_token' => empty($sessionToken) ? '(empty)' : substr($sessionToken, 0, 8) . '...',
                'session_id' => session_id(),
                'post_csrf' => isset($_POST['csrf_token']) ? substr($_POST['csrf_token'], 0, 8) . '...' : '(not set)',
                'header_csrf' => isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? substr($_SERVER['HTTP_X_CSRF_TOKEN'], 0, 8) . '...' : '(not set)',
                'cookie_sid' => isset($_COOKIE['bbs_sid']) ? substr($_COOKIE['bbs_sid'], 0, 8) . '...' : '(not set)',
            );
            error_log('[CSRF] check failed: ' . json_encode($debug));

            if ($is_htmx) {
                // htmx 请求：返回 HTML 错误片段（含调试信息）
                header('Content-Type: text/html; charset=utf-8');
                $debugHtml = 'SID:' . session_id() . ' Cookie:' . $debug['cookie_sid'] . ' Post:' . $debug['post_csrf'] . ' Session:' . $debug['session_token'];
                echo '<div class="alert alert-danger py-2 small mb-2">CSRF验证失败，请刷新页面重试<br><small class="text-muted">' . $debugHtml . '</small></div>';
                exit;
            }

            // 非 htmx 请求：返回 JSON
            header('Content-Type: application/json; charset=utf-8');
            echo xn_json_encode(array(
                'code' => '-1',
                'message' => 'CSRF token verification failed',
                'debug_token_received' => $debug['token_received'],
                'debug_session_token' => $debug['session_token'],
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
