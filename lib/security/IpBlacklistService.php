<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * IP 黑名单/白名单管理服务
 * 支持格式：单个 IP、CIDR（192.168.1.0/24）、范围（192.168.1.1-192.168.1.10）
 * 存储方式：kv_set 序列化存储
 *
 * kv 条目结构：
 * [
 *     'ip'          => '192.168.1.0/24',  // 单 IP / CIDR / 范围
 *     'remark'      => '备注',
 *     'create_date' => 时间戳,
 *     'expire_time' => 0 或过期时间戳,    // 0=永久
 *     'admin_uid'   => 操作管理员 uid,    // 0=系统/未知
 * ]
 */
class IpBlacklistService {

    /**
     * 检查 IP 是否在黑名单中
     * 白名单优先：如果 IP 在白名单中，直接返回 false
     * 已过期条目自动跳过（不主动清理，避免每次查询都写 kv）
     */
    public static function is_blacklisted(string $ip): bool {
        // 白名单优先
        if (self::is_whitelisted($ip)) {
            return false;
        }
        $list = self::get_blacklist();
        $now = time();
        foreach ($list as $entry) {
            // 跳过已过期条目
            if (!empty($entry['expire_time']) && $entry['expire_time'] <= $now) {
                continue;
            }
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
     * 添加条目到黑名单（完整版，支持过期时间和操作管理员）
     * @param string $ip IP/CIDR/范围（如 192.168.1.1、192.168.1.0/24、192.168.1.1-192.168.1.10）
     * @param string $remark 备注
     * @param int $expire_time 过期时间戳，0=永久
     * @param int $admin_uid 操作管理员 uid
     * @return bool
     */
    public static function add_blacklist_entry(string $ip, string $remark = '', int $expire_time = 0, int $admin_uid = 0): bool {
        if (!self::validate_ip($ip)) {
            return false;
        }
        $list = self::get_blacklist();
        // 检查重复（按 IP 字符串精确去重）
        foreach ($list as $entry) {
            if ($entry['ip'] === $ip) {
                return false;
            }
        }
        global $time;
        $list[] = [
            'ip'          => $ip,
            'remark'      => $remark,
            'create_date' => $time ?? time(),
            'expire_time' => intval($expire_time),
            'admin_uid'   => intval($admin_uid),
        ];
        kv_set('security_ip_blacklist', $list);
        return true;
    }

    /**
     * 添加条目到白名单（完整版，支持过期时间和操作管理员）
     */
    public static function add_whitelist_entry(string $ip, string $remark = '', int $expire_time = 0, int $admin_uid = 0): bool {
        if (!self::validate_ip($ip)) {
            return false;
        }
        $list = self::get_whitelist();
        foreach ($list as $entry) {
            if ($entry['ip'] === $ip) {
                return false;
            }
        }
        global $time;
        $list[] = [
            'ip'          => $ip,
            'remark'      => $remark,
            'create_date' => $time ?? time(),
            'expire_time' => intval($expire_time),
            'admin_uid'   => intval($admin_uid),
        ];
        kv_set('security_ip_whitelist', $list);
        return true;
    }

    /**
     * 添加 IP 到黑名单（向后兼容版本，无过期时间和管理员）
     * @deprecated 已废弃，请使用 add_blacklist_entry()
     */
    public static function add_to_blacklist(string $ip, string $remark = ''): bool {
        return self::add_blacklist_entry($ip, $remark, 0, 0);
    }

    /**
     * 添加 IP 到白名单（向后兼容版本）
     * @deprecated 已废弃，请使用 add_whitelist_entry()
     */
    public static function add_to_whitelist(string $ip, string $remark = ''): bool {
        return self::add_whitelist_entry($ip, $remark, 0, 0);
    }

    /**
     * 从黑名单移除指定 IP/CIDR/范围的条目
     * @param string $ip 必须与存储时完全一致的字符串
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
     * 从白名单移除指定 IP/CIDR/范围的条目
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
     * 获取黑名单列表（全部）
     * 注意：返回的条目可能包含已过期但未清理的记录，调用方如需过滤请用 get_blacklist_page(true)
     */
    public static function get_blacklist(): array {
        $list = kv_get('security_ip_blacklist');
        return empty($list) ? [] : $list;
    }

    /**
     * 获取白名单列表（全部）
     */
    public static function get_whitelist(): array {
        $list = kv_get('security_ip_whitelist');
        return empty($list) ? [] : $list;
    }

    /**
     * 分页获取黑名单列表
     * @param int $page 页码从 1 开始
     * @param int $pagesize 每页条数
     * @param bool $exclude_expired 是否排除已过期条目
     * @return array 条目数组（已重置索引）
     */
    public static function get_blacklist_page(int $page = 1, int $pagesize = 50, bool $exclude_expired = false): array {
        $list = self::get_blacklist();
        if ($exclude_expired) {
            $now = time();
            $list = array_filter($list, function($entry) use ($now) {
                return empty($entry['expire_time']) || $entry['expire_time'] > $now;
            });
        }
        // 按 create_date 倒序（新条目在前）
        usort($list, function($a, $b) {
            $ta = isset($a['create_date']) ? intval($a['create_date']) : 0;
            $tb = isset($b['create_date']) ? intval($b['create_date']) : 0;
            return $tb - $ta;
        });
        $page = max(1, $page);
        $pagesize = max(1, $pagesize);
        $offset = ($page - 1) * $pagesize;
        return array_slice($list, $offset, $pagesize);
    }

    /**
     * 统计黑名单条数
     * @param bool $exclude_expired 是否排除已过期条目
     */
    public static function count_blacklist(bool $exclude_expired = false): int {
        $list = self::get_blacklist();
        if (!$exclude_expired) {
            return count($list);
        }
        $now = time();
        $count = 0;
        foreach ($list as $entry) {
            if (empty($entry['expire_time']) || $entry['expire_time'] > $now) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 清理所有已过期条目（用于后台手动清理或定时任务）
     * @return int 清理的条目数
     */
    public static function purge_expired_blacklist(): int {
        $list = self::get_blacklist();
        $now = time();
        $new_list = [];
        $purged = 0;
        foreach ($list as $entry) {
            if (!empty($entry['expire_time']) && $entry['expire_time'] <= $now) {
                $purged++;
            } else {
                $new_list[] = $entry;
            }
        }
        if ($purged > 0) {
            kv_set('security_ip_blacklist', $new_list);
        }
        return $purged;
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

        // 处理 32 位系统负数（ip2long 可能返回负数）
        $ip_long = sprintf('%u', $ip_long);
        $subnet_long = sprintf('%u', $subnet_long);

        // 计算掩码
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));
        $mask = sprintf('%u', $mask);

        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

    /**
     * 检查 IP 是否在范围内（如 192.168.1.1-192.168.1.10）
     */
    private static function ip_in_range(string $ip, string $range): bool {
        $parts = explode('-', $range);
        if (count($parts) !== 2) {
            return false;
        }
        $start = trim($parts[0]);
        $end = trim($parts[1]);
        $ip_long = ip2long($ip);
        $start_long = ip2long($start);
        $end_long = ip2long($end);
        if ($ip_long === false || $start_long === false || $end_long === false) {
            return false;
        }
        // 处理 32 位系统负数
        $ip_long = sprintf('%u', $ip_long);
        $start_long = sprintf('%u', $start_long);
        $end_long = sprintf('%u', $end_long);
        // 确保 start <= end
        if ($start_long > $end_long) {
            $tmp = $start_long; $start_long = $end_long; $end_long = $tmp;
        }
        return $ip_long >= $start_long && $ip_long <= $end_long;
    }

    /**
     * 验证 IP/CIDR/范围 格式
     */
    private static function validate_ip(string $ip): bool {
        // CIDR 格式
        if (strpos($ip, '/') !== false) {
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
        // 范围格式
        if (strpos($ip, '-') !== false) {
            $parts = explode('-', $ip);
            if (count($parts) !== 2) {
                return false;
            }
            $start = trim($parts[0]);
            $end = trim($parts[1]);
            if (!filter_var($start, FILTER_VALIDATE_IP) || !filter_var($end, FILTER_VALIDATE_IP)) {
                return false;
            }
            return true;
        }
        // 单个 IP
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * IP 匹配：支持 CIDR、范围、精确匹配
     */
    private static function ip_match(string $ip, string $pattern): bool {
        // CIDR 格式
        if (strpos($pattern, '/') !== false) {
            return self::ip_in_cidr($ip, $pattern);
        }
        // 范围格式
        if (strpos($pattern, '-') !== false) {
            return self::ip_in_range($ip, $pattern);
        }
        // 精确匹配
        return ip2long($ip) === ip2long($pattern);
    }
}
