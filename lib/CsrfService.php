<?php

!defined('DEBUG') AND exit('Access Denied.');

/**
 * CSRF 防护服务
 *
 * token 存储在 cookie（bbs_csrf）而非 session，避免 session GC（online_hold_time=3600s）
 * 清除 session 数据后 token 丢失，导致用户在长时间未刷新的页面提交表单时 CSRF 验证失败。
 * cookie 有效期 7 天与 auth cookie 一致，SameSite=Lax 阻止跨站 POST 携带 cookie，
 * 攻击者无法读取跨域 cookie 值构造 csrf_token 字段，安全性不变。
 */
class CsrfService {

    const COOKIE_NAME = 'bbs_csrf';
    const COOKIE_LIFETIME = 604800; // 7 天，与 auth cookie 一致

    public static function generate(): string {
        $token = self::getToken();
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
            self::setCookie($token);
        }
        return $token;
    }

    public static function check(): void {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $cookieToken = self::getToken();
        if (empty($token) || empty($cookieToken) || !hash_equals($cookieToken, $token)) {
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
        return $_COOKIE[self::COOKIE_NAME] ?? '';
    }

    private static function setCookie(string $token): void {
        global $conf;
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        // Cookie Secure：与 session/auth cookie 一致的安全配置
        if (isset($conf['security_cookie_secure'])) {
            $secure = intval($conf['security_cookie_secure']) > 0 || $is_https;
        } elseif (isset($conf['cookie_secure'])) {
            $secure = intval($conf['cookie_secure']) > 0;
        } else {
            $secure = $is_https;
        }

        // Cookie SameSite：默认 Lax（防 CSRF）
        if (isset($conf['security_cookie_samesite']) && in_array($conf['security_cookie_samesite'], array('Lax', 'Strict', 'None'), true)) {
            $samesite = $conf['security_cookie_samesite'];
        } else {
            $samesite = 'Lax';
        }

        setcookie(self::COOKIE_NAME, $token, array(
            'expires' => time() + self::COOKIE_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $samesite,
        ));
        // 当前请求内立即可用（setcookie 下次请求才生效）
        $_COOKIE[self::COOKIE_NAME] = $token;
    }

}
