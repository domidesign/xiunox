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
}
