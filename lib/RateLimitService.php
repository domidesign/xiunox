<?php

class RateLimitService {
    private string $cacheDir;
    private int $maxRequests;
    private int $maxRequestsAuthenticated;
    private int $windowSeconds;
    /** @var \Redis|null Redis 连接实例，不可用时为 null */
    private $redis = null;
    /** @var string Redis 键前缀（含 cachepre，避免与其他应用键冲突） */
    private string $redisPrefix = 'bbs_ratelimit:';

    public function __construct(int $maxRequests = 60, int $windowSeconds = 60, ?string $cacheDir = null, int $maxRequestsAuthenticated = 120) {
        $this->maxRequests = $maxRequests;
        $this->maxRequestsAuthenticated = $maxRequestsAuthenticated;
        $this->windowSeconds = $windowSeconds;
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . '/xiuno_ratelimit';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        $this->redis = $this->detectRedis();
    }

    /**
     * 检测 Redis 是否可用
     * 通过 Xiuno 全局 $_SERVER['cache'] 判断，若为 cache_redis 且连接成功则复用其 Redis 连接
     */
    private function detectRedis(): ?\Redis {
        $cache = isset($_SERVER['cache']) ? $_SERVER['cache'] : null;
        // class_exists 第 2 参 false 不触发自动加载，避免 cache_redis 类未加载时 instanceof 报错
        if ($cache && class_exists('cache_redis', false) && $cache instanceof \cache_redis) {
            // 懒连接：若未连接则触发连接
            if (!$cache->isConnected()) {
                $cache->connect();
            }
            if ($cache->link instanceof \Redis) {
                // 复用 cachepre 作为前缀，避免与其他应用键冲突
                $this->redisPrefix = $cache->cachepre . 'ratelimit:';
                return $cache->link;
            }
        }
        return null;
    }

    public function check(string $key): bool {
        if ($this->redis) {
            return $this->checkRedis($key, $this->maxRequests);
        }
        return $this->checkFile($key, $this->maxRequests);
    }

    public function checkWithAuth(string $key, bool $isAuthenticated): bool {
        $limit = $isAuthenticated ? $this->maxRequestsAuthenticated : $this->maxRequests;
        if ($this->redis) {
            return $this->checkRedis($key, $limit);
        }
        return $this->checkFile($key, $limit);
    }

    public function getRemaining(string $key): int {
        if ($this->redis) {
            return $this->getRemainingRedis($key, $this->maxRequests);
        }
        return $this->getRemainingFile($key, $this->maxRequests);
    }

    public function getRemainingWithAuth(string $key, bool $isAuthenticated): int {
        $limit = $isAuthenticated ? $this->maxRequestsAuthenticated : $this->maxRequests;
        if ($this->redis) {
            return $this->getRemainingRedis($key, $limit);
        }
        return $this->getRemainingFile($key, $limit);
    }

    public function getResetTime(string $key): int {
        if ($this->redis) {
            return $this->getResetTimeRedis($key);
        }
        return $this->getResetTimeFile($key);
    }

    public function getRetryAfter(string $key): int {
        if ($this->redis) {
            return $this->getRetryAfterRedis($key);
        }
        return $this->getRetryAfterFile($key);
    }

    public static function getClientKey(): string {
        $uid = 0;
        if (isset($_SESSION['uid'])) {
            $uid = intval($_SESSION['uid']);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return 'api:' . $ip . ':' . $uid;
    }

    // ===== Redis 驱动实现（Sorted Set 滑动窗口）=====

    private function redisKey(string $key): string {
        return $this->redisPrefix . md5($key);
    }

    private function checkRedis(string $key, int $limit): bool {
        $redisKey = $this->redisKey($key);
        $now = time();
        $windowStart = $now - $this->windowSeconds;
        try {
            // 移除窗口外的记录
            $this->redis->zRemRangeByScore($redisKey, 0, $windowStart);
            // 获取当前窗口内的请求数
            $count = $this->redis->zCard($redisKey);
            if ($count >= $limit) {
                return false;
            }
            // 添加当前请求（时间戳+唯一ID 作为 member，避免同秒请求覆盖）
            $this->redis->zAdd($redisKey, $now, $now . ':' . uniqid('', true));
            // 设置过期时间，避免冷键堆积
            $this->redis->expire($redisKey, $this->windowSeconds);
            return true;
        } catch (\Throwable $e) {
            // Redis 异常时降级到文件系统，避免限流失控
            return $this->checkFile($key, $limit);
        }
    }

    private function getRemainingRedis(string $key, int $limit): int {
        $redisKey = $this->redisKey($key);
        $now = time();
        $windowStart = $now - $this->windowSeconds;
        try {
            $this->redis->zRemRangeByScore($redisKey, 0, $windowStart);
            $count = $this->redis->zCard($redisKey);
            return max(0, $limit - $count);
        } catch (\Throwable $e) {
            return $this->getRemainingFile($key, $limit);
        }
    }

    private function getResetTimeRedis(string $key): int {
        $redisKey = $this->redisKey($key);
        try {
            // 获取窗口内最早的请求时间戳（分数最小的元素）
            // zRange 第 4 参 true 返回 [member => score] 关联数组
            $items = $this->redis->zRange($redisKey, 0, 0, true);
            if (empty($items)) {
                return time() + $this->windowSeconds;
            }
            $minScore = (int)reset($items);
            return $minScore + $this->windowSeconds;
        } catch (\Throwable $e) {
            return $this->getResetTimeFile($key);
        }
    }

    private function getRetryAfterRedis(string $key): int {
        $redisKey = $this->redisKey($key);
        $now = time();
        try {
            // 清理过期记录
            $windowStart = $now - $this->windowSeconds;
            $this->redis->zRemRangeByScore($redisKey, 0, $windowStart);
            // 获取最早的请求时间戳
            $items = $this->redis->zRange($redisKey, 0, 0, true);
            if (empty($items)) {
                return $this->windowSeconds;
            }
            $minScore = (int)reset($items);
            return max(0, $minScore + $this->windowSeconds - $now);
        } catch (\Throwable $e) {
            return $this->getRetryAfterFile($key);
        }
    }

    // ===== 文件系统驱动实现（原有逻辑，提取为 private 方法）=====

    private function checkFile(string $key, int $limit): bool {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $now = time();
        $windowStart = $now - $this->windowSeconds;

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

    private function getRemainingFile(string $key, int $limit): int {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        $data = $this->readFile($file);
        $data = array_filter($data, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        });

        return max(0, $limit - count($data));
    }

    private function getResetTimeFile(string $key): int {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        $data = $this->readFile($file);

        if (empty($data)) {
            return time() + $this->windowSeconds;
        }

        return min($data) + $this->windowSeconds;
    }

    private function getRetryAfterFile(string $key): int {
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
