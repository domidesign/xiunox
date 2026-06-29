<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

class ApiAuthService {
    private DatabaseInterface $db;
    private int $tokenExpireDays;
    private int $accessTokenExpireHours;

    public function __construct(DatabaseInterface $db, int $tokenExpireDays = 30, int $accessTokenExpireHours = 2) {
        $this->db = $db;
        $this->tokenExpireDays = $tokenExpireDays;
        $this->accessTokenExpireHours = $accessTokenExpireHours;
    }

    /**
     * @deprecated Use generateTokens() instead
     */
    public function generateToken(int $uid): array {
        if ($uid <= 0) throw new InvalidArgumentException('Invalid uid');
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + ($this->tokenExpireDays * 86400);

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $token,
            'expires_at' => $expiresAt,
            'created_at' => time(),
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * @deprecated Use validateAccessToken() instead
     */
    public function validateToken(string $token): ?array {
        if (empty($token)) return null;

        $row = $this->db->findOne('api_token', ['token' => $token]);
        if (!$row) return null;

        if ($row['expires_at'] < time()) {
            $this->db->delete('api_token', ['id' => $row['id']]);
            return null;
        }

        $user = $this->db->findOne('user', ['uid' => $row['uid']]);
        return $user ?: null;
    }

    /**
     * @deprecated Use refreshTokens() instead
     */
    public function refreshToken(string $token): ?array {
        $row = $this->db->findOne('api_token', ['token' => $token]);
        if (!$row) return null;

        $newExpiresAt = time() + ($this->tokenExpireDays * 86400);
        $this->db->update('api_token', ['id' => $row['id']], ['expires_at' => $newExpiresAt]);

        return ['token' => $token, 'expires_at' => $newExpiresAt];
    }

    /**
     * @deprecated Use revokeTokens() instead
     */
    public function revokeToken(string $token): bool {
        if (empty($token)) return false;
        $this->db->delete('api_token', ['token' => $token]);
        return true;
    }

    public function generateTokens(int $uid): array {
        if ($uid <= 0) throw new InvalidArgumentException('Invalid uid');

        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $accessExpiresAt = time() + ($this->accessTokenExpireHours * 3600);
        $refreshExpiresAt = time() + ($this->tokenExpireDays * 86400);
        $now = time();

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $refreshToken,
            'type' => 'refresh',
            'expires_at' => $refreshExpiresAt,
            'created_at' => $now,
            'related_id' => 0,
        ]);
        $refreshId = $this->db->lastInsertId();

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $accessToken,
            'type' => 'access',
            'expires_at' => $accessExpiresAt,
            'created_at' => $now,
            'related_id' => $refreshId,
        ]);
        $accessId = $this->db->lastInsertId();

        $this->db->update('api_token', ['id' => $refreshId], ['related_id' => $accessId]);

        $user = $this->db->findOne('user', ['uid' => $uid]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->accessTokenExpireHours * 3600,
            'user' => $user ?: [],
        ];
    }

    public function validateAccessToken(string $token): ?array {
        if (empty($token)) return null;

        $row = $this->db->findOne('api_token', ['token' => $token, 'type' => 'access']);
        if (!$row) return null;

        if ($row['expires_at'] < time()) {
            $this->db->delete('api_token', ['id' => $row['id']]);
            return null;
        }

        $user = $this->db->findOne('user', ['uid' => $row['uid']]);
        return $user ?: null;
    }

    public function validateRefreshToken(string $token): ?array {
        if (empty($token)) return null;

        $row = $this->db->findOne('api_token', ['token' => $token, 'type' => 'refresh']);
        if (!$row) return null;

        if ($row['expires_at'] < time()) {
            $this->db->delete('api_token', ['id' => $row['id']]);
            return null;
        }

        return $row;
    }

    public function refreshTokens(string $refreshToken): ?array {
        $row = $this->validateRefreshToken($refreshToken);
        if (!$row) return null;

        $uid = $row['uid'];
        $relatedId = $row['related_id'];

        $this->db->delete('api_token', ['id' => $row['id']]);
        if ($relatedId > 0) {
            $this->db->delete('api_token', ['id' => $relatedId]);
        }

        $newAccessToken = bin2hex(random_bytes(32));
        $newRefreshToken = bin2hex(random_bytes(32));
        $accessExpiresAt = time() + ($this->accessTokenExpireHours * 3600);
        $refreshExpiresAt = time() + ($this->tokenExpireDays * 86400);
        $now = time();

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $newRefreshToken,
            'type' => 'refresh',
            'expires_at' => $refreshExpiresAt,
            'created_at' => $now,
            'related_id' => 0,
        ]);
        $refreshId = $this->db->lastInsertId();

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $newAccessToken,
            'type' => 'access',
            'expires_at' => $accessExpiresAt,
            'created_at' => $now,
            'related_id' => $refreshId,
        ]);
        $accessId = $this->db->lastInsertId();

        $this->db->update('api_token', ['id' => $refreshId], ['related_id' => $accessId]);

        return [
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => $this->accessTokenExpireHours * 3600,
        ];
    }

    public function revokeTokens(string $refreshToken): bool {
        $row = $this->db->findOne('api_token', ['token' => $refreshToken, 'type' => 'refresh']);
        if (!$row) return false;

        $relatedId = $row['related_id'];
        $this->db->delete('api_token', ['id' => $row['id']]);
        if ($relatedId > 0) {
            $this->db->delete('api_token', ['id' => $relatedId]);
        }

        return true;
    }

    public static function getBearerToken(): ?string {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? getenv('HTTP_AUTHORIZATION')
            ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * 验证应用凭据
     * @param string $appid 应用ID
     * @param string $secret 应用密钥
     * @return array|null 应用信息数组，无效返回 null
     */
    public function validateApp(string $appid, string $secret): ?array {
        if (empty($appid) || empty($secret)) return null;

        $app = $this->db->findOne('api_app', ['appid' => $appid]);
        if (!$app) return null;

        if (empty($app['is_enabled'])) return null;

        if (!hash_equals($app['secret'], $secret)) return null;

        return $app;
    }

    /**
     * 仅验证 appid（客户端模式，不验证 secret）
     * 用于浏览器/UniApp 等客户端场景，secret 不暴露给前端
     * @param string $appid 应用ID
     * @return array|null 应用信息数组，无效返回 null
     */
    public function validateAppPublic(string $appid): ?array {
        if (empty($appid)) return null;

        $app = $this->db->findOne('api_app', ['appid' => $appid]);
        if (!$app) return null;

        if (empty($app['is_enabled'])) return null;

        return $app;
    }

    /**
     * 客户端模式速率限制（更严格）
     * 无 secret 的请求使用独立的、更严格的限流
     * @param string $appid 应用ID
     * @return bool true=通过, false=超限
     */
    public function checkAppPublicRateLimit(string $appid): bool {
        // 客户端模式限流：每分钟30次（比服务端的120次更严格）
        $publicLimit = 30;
        if (!class_exists('RateLimitService')) {
            include APP_PATH . 'lib/RateLimitService.php';
        }

        $rateLimit = new RateLimitService($publicLimit, 60);
        $key = 'app_pub_' . $appid;
        return $rateLimit->check($key);
    }

    /**
     * 创建应用
     * @param string $name 应用名称
     * @param string $description 应用描述
     * @param string $scope 权限范围: readonly/readwrite/full
     * @param int $uid 创建者UID
     * @return array 创建结果
     */
    public function createApp(string $name, string $description = '', string $scope = 'readonly', int $uid = 0): array {
        $appid = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(16));
        $now = time();

        $data = [
            'appid' => $appid,
            'secret' => $secret,
            'name' => $name,
            'description' => $description,
            'scope' => in_array($scope, ['readonly', 'readwrite', 'full'], true) ? $scope : 'readonly',
            'is_enabled' => 1,
            'uid' => $uid,
            'rate_limit' => 120,
            'created_at' => $now,
        ];

        $this->db->insert('api_app', $data);
        $data['id'] = $this->db->lastInsertId();

        return $data;
    }

    /**
     * 更新应用
     * @param int $id 应用ID
     * @param array $data 更新数据
     * @return bool
     */
    public function updateApp(int $id, array $data): bool {
        $allowed = ['name', 'description', 'scope', 'is_enabled', 'rate_limit'];
        $update = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $update[$key] = $data[$key];
            }
        }
        if (empty($update)) return false;

        if (isset($update['scope']) && !in_array($update['scope'], ['readonly', 'readwrite', 'full'], true)) {
            return false;
        }

        $this->db->update('api_app', ['id' => $id], $update);
        return true;
    }

    /**
     * 删除应用
     * @param int $id 应用ID
     * @return bool
     */
    public function deleteApp(int $id): bool {
        $this->db->delete('api_app', ['id' => $id]);
        return true;
    }

    /**
     * 重置应用密钥
     * @param int $id 应用ID
     * @return array|null 新的凭据信息，失败返回 null
     */
    public function regenerateSecret(int $id): ?array {
        $app = $this->db->findOne('api_app', ['id' => $id]);
        if (!$app) return null;

        $newSecret = bin2hex(random_bytes(16));
        $this->db->update('api_app', ['id' => $id], ['secret' => $newSecret]);

        return [
            'id' => $id,
            'appid' => $app['appid'],
            'secret' => $newSecret,
        ];
    }

    /**
     * 检查应用权限范围是否允许当前请求方法
     * @param array $app 应用信息
     * @param string $method HTTP 方法
     * @return bool true=允许, false=不允许
     */
    public function checkAppScope(array $app, string $method): bool {
        $scope = $app['scope'] ?? 'readonly';

        if ($scope === 'full') return true;

        if ($scope === 'readwrite') {
            return !in_array($method, ['DELETE'], true) || true; // readwrite 允许所有写操作
        }

        // readonly: 只允许 GET
        if ($scope === 'readonly') {
            return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        }

        return false;
    }

    /**
     * 检查应用级速率限制
     * @param array $app 应用信息
     * @return bool true=通过, false=超限
     */
    public function checkAppRateLimit(array $app): bool {
        $limit = intval($app['rate_limit'] ?? 120);
        if ($limit <= 0) return true; // 0 = 不限制

        if (!class_exists('RateLimitService')) {
            include APP_PATH . 'lib/RateLimitService.php';
        }

        $rateLimit = new RateLimitService($limit, 60);
        $key = 'app_' . $app['appid'];
        return $rateLimit->check($key);
    }

    /**
     * 获取所有应用列表
     * @return array
     */
    public function listApps(): array {
        return $this->db->find('api_app', [], [], 1, 100, 'id');
    }

    /**
     * 根据ID获取应用
     * @param int $id
     * @return array|null
     */
    public function getAppById(int $id): ?array {
        return $this->db->findOne('api_app', ['id' => $id]);
    }
}
