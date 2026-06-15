<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * 邮箱域名黑名单服务
 * 防止临时/一次性邮箱注册
 */
class EmailBlacklistService {

    // kv 存储键名
    const KV_KEY = 'security_email_blacklist';

    /**
     * 检查邮箱是否在黑名单中
     * 支持子域名匹配：若 tempmail.com 被拉黑，sub.tempmail.com 也会被拦截
     *
     * @param string $email 邮箱地址
     * @return bool
     */
    public static function is_blacklisted(string $email): bool {
        $at_pos = strrpos($email, '@');
        if ($at_pos === false) {
            return false;
        }
        $domain = strtolower(trim(substr($email, $at_pos + 1)));
        if ($domain === '') {
            return false;
        }

        $blacklist = self::get_all_domains();
        foreach ($blacklist as $blocked) {
            $blocked = strtolower($blocked);
            // 完全匹配 或 子域名匹配
            if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 添加域名到黑名单
     *
     * @param string $domain 域名
     * @return bool
     */
    public static function add_domain(string $domain): bool {
        $domain = strtolower(trim($domain));

        // 基本校验：包含点号、不含空格
        if ($domain === '' || !str_contains($domain, '.') || str_contains($domain, ' ')) {
            return false;
        }

        $blacklist = self::get_all_domains();

        // 去重
        if (in_array($domain, $blacklist, true)) {
            return false;
        }

        $blacklist[] = $domain;
        kv_set(self::KV_KEY, $blacklist);
        return true;
    }

    /**
     * 从黑名单移除域名
     *
     * @param string $domain 域名
     * @return bool
     */
    public static function remove_domain(string $domain): bool {
        $domain = strtolower(trim($domain));
        $blacklist = self::get_all_domains();

        $found = false;
        foreach ($blacklist as $i => $item) {
            if (strtolower($item) === $domain) {
                unset($blacklist[$i]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            return false;
        }

        kv_set(self::KV_KEY, array_values($blacklist));
        return true;
    }

    /**
     * 批量导入域名（文本格式，每行一个域名，# 开头为注释）
     *
     * @param string $text 文本内容
     * @return int 新增域名数量
     */
    public static function batch_import(string $text): int {
        $blacklist = self::get_all_domains();
        $existing = array_map('strtolower', $blacklist);
        $added = 0;

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);

            // 跳过空行和注释
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $domain = strtolower($line);

            // 基本校验
            if (!str_contains($domain, '.') || str_contains($domain, ' ')) {
                continue;
            }

            // 去重
            if (in_array($domain, $existing, true)) {
                continue;
            }

            $blacklist[] = $domain;
            $existing[] = $domain;
            $added++;
        }

        if ($added > 0) {
            kv_set(self::KV_KEY, $blacklist);
        }

        return $added;
    }

    /**
     * 从文件导入域名
     *
     * @param string $file_path 文件路径
     * @return int 新增域名数量
     */
    public static function import_from_file(string $file_path): int {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return 0;
        }

        $content = file_get_contents($file_path);
        if ($content === false) {
            return 0;
        }

        return self::batch_import($content);
    }

    /**
     * 获取所有黑名单域名
     *
     * @return array
     */
    public static function get_all_domains(): array {
        $data = kv_get(self::KV_KEY);
        return is_array($data) ? $data : [];
    }

    /**
     * 清空所有黑名单域名
     *
     * @return bool
     */
    public static function clear_domains(): bool {
        kv_set(self::KV_KEY, []);
        return true;
    }
}
