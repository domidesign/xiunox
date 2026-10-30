<?php

/** Atomic sliding window. An unavailable counter rejects instead of silently allowing. */
class RateLimitService {
    private string $cacheDir;
    private int $maxRequests;
    private int $maxRequestsAuthenticated;
    private int $windowSeconds;
    private $redis = null;
    private string $redisPrefix;

    public function __construct(int $maxRequests = 60, int $windowSeconds = 60, ?string $cacheDir = null, int $maxRequestsAuthenticated = 120) {
        $this->maxRequests = max(0, $maxRequests);
        $this->maxRequestsAuthenticated = max(0, $maxRequestsAuthenticated);
        $this->windowSeconds = max(1, $windowSeconds);
        $site = substr(hash('sha256', realpath(APP_PATH) ?: APP_PATH), 0, 16);
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . '/xiunox_ratelimit_' . $site;
        $this->redisPrefix = 'xiunox:' . $site . ':ratelimit:';
        $cache = $_SERVER['cache'] ?? null;
        try {
            if ($cache && class_exists('cache_redis', false) && $cache instanceof cache_redis) {
                if (!$cache->isConnected()) $cache->connect();
                if ($cache->link instanceof Redis) $this->redis = $cache->link;
            }
        } catch (Throwable $e) { /* A stable file backend remains available for file deployments. */ }
    }

    public function check(string $key): bool { return $this->state($key, $this->maxRequests, true)['allowed']; }
    public function checkWithAuth(string $key, bool $authenticated): bool { return $this->state($key, $authenticated ? $this->maxRequestsAuthenticated : $this->maxRequests, true)['allowed']; }
    public function getRemaining(string $key): int { return $this->state($key, $this->maxRequests, false)['remaining']; }
    public function getRemainingWithAuth(string $key, bool $authenticated): int { return $this->state($key, $authenticated ? $this->maxRequestsAuthenticated : $this->maxRequests, false)['remaining']; }
    public function getResetTime(string $key): int { return $this->state($key, $this->maxRequests, false)['reset']; }
    public function getRetryAfter(string $key): int { return max(1, $this->getResetTime($key) - time()); }

    public static function getClientKey(): string {
        // REMOTE_ADDR must be normalized by the trusted reverse-proxy configuration.
        // Never trust arbitrary X-Forwarded-For or a cookie-controlled uid here.
        return 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function state(string $key, int $limit, bool $consume): array {
        $now = time();
        if ($limit <= 0) return array('allowed'=>true, 'remaining'=>PHP_INT_MAX, 'reset'=>$now + $this->windowSeconds);
        try {
            if ($this->redis) {
                $script = <<<'LUA'
local k, now, window, lim, consume = KEYS[1], tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3]), tonumber(ARGV[4])
redis.call('ZREMRANGEBYSCORE', k, '-inf', now-window)
local count = redis.call('ZCARD', k)
local allowed = 0
if count < lim then
    allowed = 1
    if consume == 1 then
        redis.call('ZADD', k, now, ARGV[5])
        count = count+1
        redis.call('EXPIRE', k, window+1)
    end
end
local oldest = redis.call('ZRANGE', k, 0, 0, 'WITHSCORES')
local reset = now+window
if #oldest > 0 then reset = tonumber(oldest[2])+window end
return {allowed, math.max(0,lim-count), reset}
LUA;
                $value = $this->redis->eval($script, array($this->redisPrefix . hash('sha256', $key), $now, $this->windowSeconds, $limit, $consume ? 1 : 0, bin2hex(random_bytes(16))), 1);
                if (!is_array($value) || count($value) !== 3) throw new RuntimeException('Invalid Redis limit state');
                return array('allowed'=>(bool)$value[0], 'remaining'=>intval($value[1]), 'reset'=>intval($value[2]));
            }
            if (!is_dir($this->cacheDir) && (!function_exists('mkdir') || !mkdir($this->cacheDir, 0700, true))) throw new RuntimeException('Limit directory unavailable');
            $file = $this->cacheDir . '/' . md5($key) . '.json';
            if (is_link($this->cacheDir) || is_link($file)) throw new RuntimeException('Limit symlink rejected');
            $handle = fopen($file, 'c+');
            if (!$handle) throw new RuntimeException('Limit counter unavailable');
            try {
                if (!flock($handle, LOCK_EX)) throw new RuntimeException('Limit lock failed');
                $raw = stream_get_contents($handle);
                $data = $raw === '' ? array() : json_decode($raw, true);
                if (!is_array($data)) throw new RuntimeException('Corrupt limit counter');
                $data = array_values(array_filter($data, function ($stamp) use ($now) { return is_int($stamp) && $stamp > $now - $this->windowSeconds; }));
                $allowed = count($data) < $limit;
                if ($allowed && $consume) $data[] = $now;
                if ($consume) {
                    $json = json_encode($data);
                    rewind($handle);
                    if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new RuntimeException('Limit write failed');
                }
                return array('allowed'=>$allowed, 'remaining'=>max(0, $limit-count($data)), 'reset'=>($data ? min($data) : $now) + $this->windowSeconds);
            } finally { flock($handle, LOCK_UN); fclose($handle); }
        } catch (Throwable $e) {
            error_log('XIUNOX rate counter unavailable; request denied');
            return array('allowed'=>false, 'remaining'=>0, 'reset'=>$now+$this->windowSeconds);
        }
    }
}
