<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * IP 黑名单/白名单管理服务
 * 支持 CIDR 格式匹配
 */
class IpBlacklistService {

    /**
     * 检查 IP 是否在黑名单中
     * 白名单优先：如果 IP 在白名单中，直接返回 false
     */
    public static function is_blacklisted(string $ip): bool {
        // 白名单优先
        if (self::is_whitelisted($ip)) {
            return false;
        }
        $list = self::get_blacklist();
        foreach ($list as $entry) {
            if (self::ip_match($ip, $entry['ip'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查 IP 是否在白名单中
     */
    public static function is_whitelisted(string $ip): bool {
        $list = self::get_whitelist();
        foreach ($list as $entry) {
            if (self::ip_match($ip, $entry['ip'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 添加 IP 到黑名单
     */
    public static function add_to_blacklist(string $ip, string $remark = ''): bool {
        if (!self::validate_ip($ip)) {
            return false;
        }
        $list = self::get_blacklist();
        // 检查重复
        foreach ($list as $entry) {
            if ($entry['ip'] === $ip) {
                return false;
            }
        }
        global $time;
        $list[] = [
            'ip' => $ip,
            'remark' => $remark,
            'create_date' => $time,
        ];
        kv_set('security_ip_blacklist', $list);
        return true;
    }

    /**
     * 添加 IP 到白名单
     */
    public static function add_to_whitelist(string $ip, string $remark = ''): bool {
        if (!self::validate_ip($ip)) {
            return false;
        }
        $list = self::get_whitelist();
        // 检查重复
        foreach ($list as $entry) {
            if ($entry['ip'] === $ip) {
                return false;
            }
        }
        global $time;
        $list[] = [
            'ip' => $ip,
            'remark' => $remark,
            'create_date' => $time,
        ];
        kv_set('security_ip_whitelist', $list);
        return true;
    }

    /**
     * 从黑名单移除 IP
     */
    public static function remove_from_blacklist(string $ip): bool {
        $list = self::get_blacklist();
        $found = false;
        foreach ($list as $k => $entry) {
            if ($entry['ip'] === $ip) {
                unset($list[$k]);
                $found = true;
            }
        }
        if ($found) {
            kv_set('security_ip_blacklist', array_values($list));
        }
        return $found;
    }

    /**
     * 从白名单移除 IP
     */
    public static function remove_from_whitelist(string $ip): bool {
        $list = self::get_whitelist();
        $found = false;
        foreach ($list as $k => $entry) {
            if ($entry['ip'] === $ip) {
                unset($list[$k]);
                $found = true;
            }
        }
        if ($found) {
            kv_set('security_ip_whitelist', array_values($list));
        }
        return $found;
    }

    /**
     * 获取黑名单列表
     */
    public static function get_blacklist(): array {
        $list = kv_get('security_ip_blacklist');
        return empty($list) ? [] : $list;
    }

    /**
     * 获取白名单列表
     */
    public static function get_whitelist(): array {
        $list = kv_get('security_ip_whitelist');
        return empty($list) ? [] : $list;
    }

    /**
     * 检查 IP 是否在 CIDR 范围内
     */
    private static function ip_in_cidr(string $ip, string $cidr): bool {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2) {
            return false;
        }
        $subnet = $parts[0];
        $prefix = (int) $parts[1];

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if ($ip_long === false || $subnet_long === false) {
            return false;
        }

        // 计算掩码
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

    /**
     * 验证 IP 或 CIDR 格式
     */
    private static function validate_ip(string $ip): bool {
        if (strpos($ip, '/') !== false) {
            // CIDR 格式
            $parts = explode('/', $ip);
            if (count($parts) !== 2) {
                return false;
            }
            $addr = $parts[0];
            $prefix = $parts[1];
            if (!filter_var($addr, FILTER_VALIDATE_IP)) {
                return false;
            }
            if (!ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32) {
                return false;
            }
            return true;
        }
        // 单个 IP
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * IP 匹配：CIDR 或精确匹配
     */
    private static function ip_match(string $ip, string $pattern): bool {
        if (strpos($pattern, '/') !== false) {
            return self::ip_in_cidr($ip, $pattern);
        }
        // 精确匹配
        return ip2long($ip) === ip2long($pattern);
    }
}
