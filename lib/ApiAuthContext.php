<?php

class ApiAuthContext {
    private static ?array $user = null;

    public static function setUser(?array $user): void {
        self::$user = $user;
    }

    public static function getUser(): ?array {
        return self::$user;
    }

    public static function isAdmin(): bool {
        if (empty(self::$user)) return false;
        $gid = intval(self::$user['gid'] ?? 0);
        return in_array($gid, [1, 2], true);
    }

    public static function requireAuth(): void {
        if (empty(self::$user)) {
            ApiResponse::unauthorized();
        }
    }

    public static function getUid(): int {
        return intval(self::$user['uid'] ?? 0);
    }

    public static function getGid(): int {
        return intval(self::$user['gid'] ?? 0);
    }

    public static function getUsername(): string {
        return self::$user['username'] ?? '';
    }
}
