<?php

if ($method !== 'POST') {
    ApiResponse::error(405, 'Method not allowed');
}

$action = $segments[1] ?? '';

switch ($action) {
    case 'login':
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
            ApiResponse::validationError($errors);
        }

        $user = $db->find_one('user', ['email' => $email]);
        if (empty($user)) {
            $user = $db->find_one('user', ['username' => $email]);
        }

        if (empty($user) || !user_login_verify($password, $user)) {
            ApiResponse::error(401, 'Invalid credentials');
        }

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
            ApiResponse::validationError($errors);
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
