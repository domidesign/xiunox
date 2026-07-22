<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

class ApiAuthService {
    private DatabaseInterface $db;
    private int $tokenExpireDays;
    private int $accessTokenExpireHours;

    /**
     * 已注册的自定义 scope（供插件注册如 'lottery:participate' 等 scope）
     */
    private static $customScopes = [];

    public function __construct(DatabaseInterface $db, int $tokenExpireDays = 30, int $accessTokenExpireHours = 2) {
        $this->db = $db;
        $this->tokenExpireDays = $tokenExpireDays;
        $this->accessTokenExpireHours = $accessTokenExpireHours;
    }

    /**
     * 生成单 Token（已废弃）
     * @deprecated 已被 generateTokens() 替代，将在下一个版本删除，不要再调用此方法
     */
    public function generateToken(int $uid): array {
        if ($uid <= 0) throw new InvalidArgumentException('Invalid uid');
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = time() + ($this->tokenExpireDays * 86400);

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => time(),
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * 验证单 Token（已废弃）
     * @deprecated 已被 validateAccessToken() 替代，将在下一个版本删除，不要再调用此方法
     */
    public function validateToken(string $token): ?array {
        if (empty($token)) return null;

        $tokenHash = hash('sha256', $token);
        $row = $this->db->findOne('api_token', ['token' => $tokenHash]);
        if (!$row) return null;

        if ($row['expires_at'] < time()) {
            $this->db->delete('api_token', ['id' => $row['id']]);
            return null;
        }

        $user = $this->db->findOne('user', ['uid' => $row['uid']]);
        return $user ?: null;
    }

    /**
     * 刷新单 Token（已废弃）
     * @deprecated 已被 refreshTokens() 替代，将在下一个版本删除，不要再调用此方法
     */
    public function refreshToken(string $token): ?array {
        $tokenHash = hash('sha256', $token);
        $row = $this->db->findOne('api_token', ['token' => $tokenHash]);
        if (!$row) return null;

        $newExpiresAt = time() + ($this->tokenExpireDays * 86400);
        $this->db->update('api_token', ['id' => $row['id']], ['expires_at' => $newExpiresAt]);

        return ['token' => $token, 'expires_at' => $newExpiresAt];
    }

    /**
     * 撤销单 Token（已废弃）
     * @deprecated 已被 revokeTokens() 替代，将在下一个版本删除，不要再调用此方法
     */
    public function revokeToken(string $token): bool {
        if (empty($token)) return false;
        $tokenHash = hash('sha256', $token);
        $this->db->delete('api_token', ['token' => $tokenHash]);
        return true;
    }

    public function generateTokens(int $uid): array {
        if ($uid <= 0) throw new InvalidArgumentException('Invalid uid');

        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $accessTokenHash = hash('sha256', $accessToken);
        $refreshTokenHash = hash('sha256', $refreshToken);
        $accessExpiresAt = time() + ($this->accessTokenExpireHours * 3600);
        $refreshExpiresAt = time() + ($this->tokenExpireDays * 86400);
        $now = time();

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $refreshTokenHash,
            'type' => 'refresh',
            'expires_at' => $refreshExpiresAt,
            'created_at' => $now,
            'related_id' => 0,
        ]);
        $refreshId = $this->db->lastInsertId();

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $accessTokenHash,
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

    public function validateAccessToken(?string $token): ?array {
        if (empty($token)) return null;

        $tokenHash = hash('sha256', $token);
        $row = $this->db->findOne('api_token', ['token' => $tokenHash, 'type' => 'access']);
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

        $tokenHash = hash('sha256', $token);
        $row = $this->db->findOne('api_token', ['token' => $tokenHash, 'type' => 'refresh']);
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
        $newAccessTokenHash = hash('sha256', $newAccessToken);
        $newRefreshTokenHash = hash('sha256', $newRefreshToken);
        $accessExpiresAt = time() + ($this->accessTokenExpireHours * 3600);
        $refreshExpiresAt = time() + ($this->tokenExpireDays * 86400);
        $now = time();

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $newRefreshTokenHash,
            'type' => 'refresh',
            'expires_at' => $refreshExpiresAt,
            'created_at' => $now,
            'related_id' => 0,
        ]);
        $refreshId = $this->db->lastInsertId();

        $this->db->insert('api_token', [
            'uid' => $uid,
            'token' => $newAccessTokenHash,
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
        $tokenHash = hash('sha256', $refreshToken);
        $row = $this->db->findOne('api_token', ['token' => $tokenHash, 'type' => 'refresh']);
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

        if (!password_verify($secret, $app['secret'])) return null;

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
     * @param string $capabilities 场景级能力 JSON 字符串
     * @param string $ipWhitelist IP 白名单 JSON 数组字符串
     * @return array 创建结果
     */
    public function createApp(string $name, string $description = '', string $scope = 'readonly', int $uid = 0, string $capabilities = '', string $ipWhitelist = ''): array {
        $appid = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(16));
        $secretHash = password_hash($secret, PASSWORD_DEFAULT);
        $now = time();

        $data = [
            'appid' => $appid,
            'secret' => $secretHash,
            'name' => $name,
            'description' => $description,
            'scope' => in_array($scope, ['readonly', 'readwrite', 'full'], true) ? $scope : 'readonly',
            'is_enabled' => 1,
            'uid' => $uid,
            'rate_limit' => 120,
            'capabilities' => $capabilities,
            'ip_whitelist' => $ipWhitelist,
            'created_at' => $now,
        ];

        $this->db->insert('api_app', $data);
        $data['id'] = $this->db->lastInsertId();
        $data['secret'] = $secret;

        return $data;
    }

    /**
     * 更新应用
     * @param int $id 应用ID
     * @param array $data 更新数据
     * @return bool
     */
    public function updateApp(int $id, array $data): bool {
        $allowed = ['name', 'description', 'scope', 'is_enabled', 'rate_limit', 'capabilities', 'ip_whitelist'];
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
        $newSecretHash = password_hash($newSecret, PASSWORD_DEFAULT);
        $this->db->update('api_app', ['id' => $id], ['secret' => $newSecretHash]);

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
        // 优先读取细粒度 permissions 矩阵
        $permissions = $this->parseJsonField($app['permissions'] ?? '');
        if (!empty($permissions)) {
            return $this->checkPermissionsMatrix($permissions, $app['_current_resource'] ?? '', $method);
        }

        // 回退到 scope 字段
        $scope = $app['scope'] ?? 'readonly';

        if ($scope === 'full') return true;

        if ($scope === 'readwrite') {
            // readwrite 允许 GET/POST/PUT，禁止 DELETE
            return !in_array($method, ['DELETE'], true);
        }

        // readonly: 只允许 GET
        if ($scope === 'readonly') {
            return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        }

        return false;
    }

    /**
     * 检查应用是否具有某项场景能力
     * @param array $app 应用信息
     * @param string $capability 能力名: skip_captcha/skip_audit/skip_rate_limit
     * @return bool
     */
    public function checkAppCapability(array $app, string $capability): bool {
        $capabilities = $this->parseJsonField($app['capabilities'] ?? '{}');
        return !empty($capabilities[$capability]);
    }

    /**
     * 检查请求 IP 是否在应用 IP 白名单内
     * @param array $app 应用信息
     * @param string $ip 客户端 IP
     * @return bool true=允许, false=拒绝
     */
    public function checkAppIpWhitelist(array $app, string $ip): bool {
        $whitelist = $this->parseJsonField($app['ip_whitelist'] ?? '[]');
        if (empty($whitelist)) return true; // 空列表=不限

        foreach ($whitelist as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) return true;
        }
        return false;
    }

    /**
     * 检查应用是否允许访问指定资源
     * @param array $app 应用信息
     * @param string $resource 资源名(URL第一段,如 thread/post/user/admin)
     * @return bool true=允许, false=拒绝
     */
    public function checkAppResourceAccess(array $app, string $resource): bool {
        $capabilities = $this->parseJsonField($app['capabilities'] ?? '{}');

        // 检查 denied_endpoints 黑名单（支持通配符 admin/*）
        $denied = $capabilities['denied_endpoints'] ?? [];
        foreach ($denied as $pattern) {
            if ($this->matchWildcard($pattern, $resource)) return false;
        }

        // 检查 allowed_resources 白名单（空数组=全部允许）
        $allowed = $capabilities['allowed_resources'] ?? [];
        if (empty($allowed)) return true;
        return in_array($resource, $allowed, true);
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

    /**
     * 注册自定义 scope（供插件使用）
     * 插件通过此方法声明自身的 scope（如 'lottery:participate'），
     * 后台应用配置 UI 可读取 getCustomScopes() 列出所有可用 scope
     * @param string $scope scope 名称
     */
    public static function registerScope(string $scope): void {
        if ($scope === '' || in_array($scope, self::$customScopes, true)) return;
        self::$customScopes[] = $scope;
    }

    /**
     * 获取所有已注册的自定义 scope
     * @return string[]
     */
    public static function getCustomScopes(): array {
        return self::$customScopes;
    }

    /**
     * 解析 JSON 字段，失败返回默认空数组
     * @param string $json JSON 字符串
     * @return array 解析后的数组
     */
    private function parseJsonField(string $json): array {
        if (empty($json)) return [];
        $result = json_decode($json, true);
        return is_array($result) ? $result : [];
    }

    /**
     * 检查 IP 是否在 CIDR 范围内（仅支持 IPv4）
     * @param string $ip 客户端 IP
     * @param string $cidr CIDR（如 192.168.1.0/24）或单 IP（等同 /32）
     * @return bool
     */
    private function ipInCidr(string $ip, string $cidr): bool {
        // ponytail: 仅 IPv4，IPv6 直接返回 false（升级路径：引入 inet_pton 处理 v6）
        if (strpos($cidr, '/') === false) {
            $cidr .= '/32';
        }
        list($subnet, $maskBits) = explode('/', $cidr, 2);
        $maskBits = intval($maskBits);
        if ($maskBits < 0 || $maskBits > 32) return false;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;

        // 32 位整数统一无符号化（避免 64 位系统下负数比较异常）
        $ipLong = $ipLong & 0xFFFFFFFF;
        $subnetLong = $subnetLong & 0xFFFFFFFF;
        $mask = $maskBits === 0 ? 0 : (0xFFFFFFFF << (32 - $maskBits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * 通配符匹配（资源级，只比较 URL 第一段）
     * @param string $pattern 模式（如 admin/*、thread、admin*）
     * @param string $subject 待匹配字符串
     * @return bool
     */
    private function matchWildcard(string $pattern, string $subject): bool {
        // ponytail: 资源级匹配只比较第一段，/* 后缀剥离后等价于匹配该段（* 在资源级匹配空）
        $pattern = preg_replace('#/\*$#', '', $pattern);
        if (strpos($pattern, '*') === false) {
            return $pattern === $subject;
        }
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        return (bool)preg_match($regex, $subject);
    }

    /**
     * 细粒度权限矩阵检查
     * @param array $permissions {"thread":"rw","post":"r","admin":"-","*":"r"}
     * @param string $resource 当前资源名
     * @param string $method HTTP 方法
     * @return bool
     */
    private function checkPermissionsMatrix(array $permissions, string $resource, string $method): bool {
        $perm = $permissions[$resource] ?? ($permissions['*'] ?? '-');

        if ($perm === '-') return false;
        if ($perm === 'r') {
            return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        }
        if ($perm === 'rw') {
            return in_array($method, ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'DELETE'], true);
        }
        return false;
    }
}
