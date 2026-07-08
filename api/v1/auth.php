<?php

if ($method !== 'POST') {
    ApiResponse::error(405, 'Method not allowed');
}

// 防暴力破解：加载 LoginSecurityService（与 route/user.php 登录流程一致）
if (!class_exists('LoginSecurityService')) {
    include_once APP_PATH . 'lib/LoginSecurityService.php';
}

$action = $segments[1] ?? '';

switch ($action) {
    case 'login':
        // ===== 验证码能力开关（Task 2.4）=====
        global $apiApp, $apiAppServerAuth, $apiAuth, $longip;
        $skipCaptcha = $apiAppServerAuth && $apiAuth->checkAppCapability($apiApp, 'skip_captcha');
        if (!$skipCaptcha) {
            if (!class_exists('CaptchaService')) {
                include_once APP_PATH . 'lib/security/CaptchaService.php';
            }
            if (CaptchaService::is_enabled('login', 0)) {
                $captchaCode = param('captcha_code', '', false);
                if (!CaptchaService::verify('login', $captchaCode, 0)) {
                    ApiResponse::error(422, lang('captcha_error'));
                }
            }
        }

        $email = param('email', '');
        $password = param('password', '');

        $errors = [];
        if (empty($email)) {
            $errors['email'] = 'Email is required';
        }
        if (empty($password)) {
            $errors['password'] = 'Password is required';
        }
        if (!empty($errors)) {
            // ponytail: validationError 第 1 参为 string，传数组会触发 TypeError fatal
            ApiResponse::validationError('Validation Error', $errors);
        }

        // 防暴力破解：IP 维度限流检查（防止用不存在的用户名枚举绕过 uid 维度限流）
        LoginSecurityService::checkIpBan($longip);

        $user = $db->find_one('user', ['email' => $email]);
        if (empty($user)) {
            $user = $db->find_one('user', ['username' => $email]);
        }

        // 用户不存在时记录 IP 维度失败尝试，纳入 IP 限流统计
        if (empty($user)) {
            LoginSecurityService::recordIpAttempt($longip, FALSE, $_SERVER['HTTP_USER_AGENT']);
            ApiResponse::error(401, 'Invalid credentials');
        }

        // 防暴力破解：uid 维度锁定检查
        LoginSecurityService::checkBan($user['uid']);

        if (!user_login_verify($password, $user)) {
            // 登录失败时记录 uid 维度失败尝试
            LoginSecurityService::recordAttempt($user['uid'], FALSE, $longip, $_SERVER['HTTP_USER_AGENT']);
            ApiResponse::error(401, 'Invalid credentials');
        }

        // 登录成功时清空失败计数
        LoginSecurityService::recordAttempt($user['uid'], TRUE, $longip, $_SERVER['HTTP_USER_AGENT']);

        $tokenData = $apiAuth->generateTokens($user['uid']);
        unset($tokenData['user']['password'], $tokenData['user']['salt']);

        ApiResponse::success([
            'uid' => $user['uid'],
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'],
            'expires_in' => $tokenData['expires_in'],
            'user' => $tokenData['user'],
        ]);
        break;

    case 'register':
        // ===== 验证码能力开关（Task 2.4）=====
        global $apiApp, $apiAppServerAuth, $apiAuth;
        $skipCaptcha = $apiAppServerAuth && $apiAuth->checkAppCapability($apiApp, 'skip_captcha');
        if (!$skipCaptcha) {
            if (!class_exists('CaptchaService')) {
                include_once APP_PATH . 'lib/security/CaptchaService.php';
            }
            if (CaptchaService::is_enabled('register', 0)) {
                $captchaCode = param('captcha_code', '', false);
                if (!CaptchaService::verify('register', $captchaCode, 0)) {
                    ApiResponse::error(422, lang('captcha_error'));
                }
            }
        }

        $email = param('email', '');
        $username = param('username', '');
        $password = param('password', '');

        $errors = [];
        if (empty($email)) {
            $errors['email'] = 'Email is required';
        }
        if (empty($username)) {
            $errors['username'] = 'Username is required';
        }
        if (empty($password)) {
            $errors['password'] = 'Password is required';
        }
        if (!empty($errors)) {
            // ponytail: validationError 第 1 参为 string，传数组会触发 TypeError fatal
            ApiResponse::validationError('Validation Error', $errors);
        }

        $existingEmail = $db->find_one('user', ['email' => $email]);
        if (!empty($existingEmail)) {
            ApiResponse::error(409, 'Email already exists');
        }

        $existingUsername = $db->find_one('user', ['username' => $username]);
        if (!empty($existingUsername)) {
            ApiResponse::error(409, 'Username already exists');
        }

        // 直接使用 bcrypt(明文) 存入 password_hash
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $uid = $db->insert('user', [
            'email' => $email,
            'username' => $username,
            'password' => '',
            'salt' => '',
            'password_hash' => $passwordHash,
            'gid' => 101,
            'create_ip' => $ip,
            'create_date' => time(),
        ]);

        $tokenData = $apiAuth->generateTokens($uid);

        ApiResponse::success([
            'uid' => $uid,
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'],
            'expires_in' => $tokenData['expires_in'],
        ]);
        break;

    case 'refresh':
        $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
        $refreshToken = param('refresh_token', '') ?: ($jsonInput['refresh_token'] ?? '');

        $result = $apiAuth->refreshTokens($refreshToken);
        if (empty($result)) {
            ApiResponse::unauthorized('Invalid or expired refresh token');
        }

        ApiResponse::success([
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_in' => $result['expires_in'],
        ]);
        break;

    case 'logout':
        $bearerToken = ApiAuthService::getBearerToken();
        $user = $apiAuth->validateAccessToken($bearerToken);
        if (empty($user)) {
            ApiResponse::unauthorized('Invalid or expired access token');
        }

        $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
        $refreshToken = param('refresh_token', '') ?: ($jsonInput['refresh_token'] ?? '');

        $apiAuth->revokeTokens($refreshToken);

        ApiResponse::success(null, 'Logged out successfully');
        break;

    default:
        ApiResponse::error(404, 'Endpoint not found');
        break;
}
