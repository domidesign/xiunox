<?php

class RateLimitService {
    private string $cacheDir;
    private int $maxRequests;
    private int $maxRequestsAuthenticated;
    private int $windowSeconds;

    public function __construct(int $maxRequests = 60, int $windowSeconds = 60, ?string $cacheDir = null, int $maxRequestsAuthenticated = 120) {
        $this->maxRequests = $maxRequests;
        $this->maxRequestsAuthenticated = $maxRequestsAuthenticated;
        $this->windowSeconds = $windowSeconds;
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . '/xiuno_ratelimit';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    public function check(string $key): bool {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        $data = $this->readFile($file);

        $data = array_filter($data, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        });

        if (count($data) >= $this->maxRequests) {
            return false;
        }

        $data[] = $now;
        $this->writeFile($file, $data);

        return true;
    }

    public function checkWithAuth(string $key, bool $isAuthenticated): bool {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $now = time();
        $windowStart = $now - $this->windowSeconds;
        $limit = $isAuthenticated ? $this->maxRequestsAuthenticated : $this->maxRequests;

        $data = $this->readFile($file);

        $data = array_filter($data, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        });

        if (count($data) >= $limit) {
            return false;
        }

        $data[] = $now;
        $this->writeFile($file, $data);

        return true;
    }

    public function getRemaining(string $key): int {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        $data = $this->readFile($file);
        $data = array_filter($data, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        });

        return max(0, $this->maxRequests - count($data));
    }

    public function getRemainingWithAuth(string $key, bool $isAuthenticated): int {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $now = time();
        $windowStart = $now - $this->windowSeconds;
        $limit = $isAuthenticated ? $this->maxRequestsAuthenticated : $this->maxRequests;

        $data = $this->readFile($file);
        $data = array_filter($data, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        });

        return max(0, $limit - count($data));
    }

    public function getResetTime(string $key): int {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $data = $this->readFile($file);

        if (empty($data)) {
            return time() + $this->windowSeconds;
        }

        return min($data) + $this->windowSeconds;
    }

    public function getRetryAfter(string $key): int {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $now = time();

        $data = $this->readFile($file);

        if (empty($data)) {
            return $this->windowSeconds;
        }

        $windowStart = $now - $this->windowSeconds;
        $data = array_filter($data, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        });

        if (empty($data)) {
            return $this->windowSeconds;
        }

        return max(0, min($data) + $this->windowSeconds - $now);
    }

    public static function getClientKey(): string {
        $uid = 0;
        if (isset($_SESSION['uid'])) {
            $uid = intval($_SESSION['uid']);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return 'api:' . $ip . ':' . $uid;
    }

    private function readFile(string $file): array {
        if (!file_exists($file)) {
            return [];
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function writeFile(string $file, array $data): void {
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
