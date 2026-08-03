<?php

class ApiContext {
    private static ?self $instance = null;

    private ?ApiAuthContext $auth = null;
    private ?array $app = null;
    private int $uid = 0;
    private int $gid = 0;
    private ?array $user = null;

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setAuth(ApiAuthContext $auth): self {
        $this->auth = $auth;
        return $this;
    }

    public function setApp(array $app): self {
        $this->app = $app;
        return $this;
    }

    public function setUser(?array $user): self {
        $this->user = $user;
        $this->uid = $user ? intval($user['uid'] ?? 0) : 0;
        $this->gid = $user ? intval($user['gid'] ?? 0) : 0;

        $GLOBALS['uid'] = $this->uid;
        $GLOBALS['gid'] = $this->gid;
        $GLOBALS['user'] = $this->user;

        // 同步到 ApiAuthContext（路由文件通过 ApiAuthContext 静态方法获取认证信息）
        ApiAuthContext::setUser($user);

        return $this;
    }

    public function getUser(): ?array {
        return $this->user;
    }

    public function isAdmin(): bool {
        if (empty($this->user)) return false;
        return in_array($this->gid, [1, 2], true);
    }

    public function getUid(): int {
        return $this->uid;
    }

    public function getGid(): int {
        return $this->gid;
    }

    public function getApp(): ?array {
        return $this->app;
    }
}