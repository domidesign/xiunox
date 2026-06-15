<?php
/**
 * 安全配置服务 - 集中管理所有安全相关配置
 *
 * 配置项统一使用 security_ 前缀，存储在 conf/conf.php 中。
 * 读取时与默认值合并，缺失键自动补齐默认值。
 */
!defined('DEBUG') AND exit('Access Denied.');

class SecurityConfigService {

    const DEFAULT_CONFIG = [
        // 发帖限制
        'security_post_thread_interval' => 60,        // 发帖最小间隔（秒）
        'security_post_reply_interval' => 30,          // 回帖最小间隔（秒）
        'security_post_min_length' => 10,              // 发帖最低字数
        'security_reply_min_length' => 5,              // 回帖最低字数
        'security_post_max_length' => 50000,           // 发帖最高字数
        'security_same_thread_reply_interval' => 0,    // 同主题连续回复间隔（秒），0=不限制
        'security_new_user_audit_count' => 0,          // 新用户前N帖需审核，0=关闭

        // 账号安全
        'security_ip_register_interval' => 24,         // 同一IP注册间隔（小时）
        'security_password_max_retries' => 5,          // 密码错误重试次数
        'security_lockout_duration' => 15,             // 锁定时间（分钟）
        'security_password_min_length' => 6,           // 密码最小长度
        'security_password_complexity' => 'none',      // 密码复杂度: none/number/mixed/special
        'security_allowed_email_domains' => '',         // 允许注册的邮箱域名后缀，逗号分隔，空=不限制

        // 内容权限
        'security_allow_edit' => 1,                    // 允许作者修改帖子
        'security_edit_time_limit' => 60,              // 修改有效时间（分钟），0=永久
        'security_allow_delete' => 0,                  // 允许作者删除帖子
        'security_delete_time_limit' => 0,             // 删除有效时间（分钟），0=不可删除
        'security_soft_delete' => 1,                   // 软删除（进回收站）
        'security_allow_delete_reply' => 0,            // 允许作者删除自己回复

        // 上传限制
        'security_avatar_upload_limit' => 3,           // 上传头像次数限制
        'security_avatar_max_size' => 512,             // 头像文件最大尺寸（KB）

        // 操作频率限制
        'security_search_interval' => 10,              // 搜索间隔（秒）
        'security_search_require_login' => 1,          // 搜索需要登录，1=需要 0=不需要
    ];

    /**
     * 获取全部安全配置（与默认值合并）
     *
     * @return array
     */
    public static function get_config(): array {
        global $conf;
        $result = [];
        foreach (self::DEFAULT_CONFIG as $key => $default_value) {
            $result[$key] = isset($conf[$key]) ? $conf[$key] : $default_value;
        }
        return $result;
    }

    /**
     * 获取单个安全配置值
     *
     * @param string $key     配置键名
     * @param mixed  $default 自定义默认值，为 null 时使用 DEFAULT_CONFIG 中的默认值
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        global $conf;
        if (isset($conf[$key])) {
            return $conf[$key];
        }
        if ($default !== null) {
            return $default;
        }
        if (isset(self::DEFAULT_CONFIG[$key])) {
            return self::DEFAULT_CONFIG[$key];
        }
        return null;
    }

    /**
     * 保存安全配置到 conf.php
     *
     * 仅保存 DEFAULT_CONFIG 中已定义的键，并做类型校验。
     * 数值型配置强制转为 int，密码复杂度校验合法枚举值。
     *
     * @param array $data 待保存的配置键值对
     * @return bool
     */
    public static function save_config(array $data): bool {
        $allowed = self::DEFAULT_CONFIG;
        $save_data = [];

        // 密码复杂度合法值
        $complexity_values = ['none', 'number', 'mixed', 'special'];

        foreach ($data as $key => $value) {
            // 仅接受 DEFAULT_CONFIG 中存在的键
            if (!array_key_exists($key, $allowed)) {
                continue;
            }

            // 类型校验：密码复杂度和域名后缀为字符串，其余为整数
            if ($key === 'security_password_complexity') {
                $value = in_array($value, $complexity_values, true) ? $value : 'none';
            } elseif ($key === 'security_allowed_email_domains') {
                $value = trim($value);
            } else {
                $value = intval($value);
            }

            $save_data[$key] = $value;
        }

        if (empty($save_data)) {
            return false;
        }

        // 同步写入核心路由使用的配置名（LoginSecurityService 读取的键）
        // 必须合并到同一次 file_replace_var 调用中，避免第二次 include 时
        // OPcache 缓存旧文件导致第一次写入被覆盖
        $sync_map = [
            'security_password_max_retries' => 'login_max_attempts',
            'security_lockout_duration' => 'login_ban_duration',
        ];
        foreach ($sync_map as $sec_key => $core_key) {
            if (isset($save_data[$sec_key])) {
                // lockout_duration 单位是分钟，login_ban_duration 单位是秒
                if ($sec_key === 'security_lockout_duration') {
                    $save_data[$core_key] = intval($save_data[$sec_key]) * 60;
                } else {
                    $save_data[$core_key] = intval($save_data[$sec_key]);
                }
            }
        }

        $r = file_replace_var(APP_PATH . 'conf/conf.php', $save_data);

        // 同步更新当前进程内存中的 $conf
        global $conf;
        foreach ($save_data as $key => $value) {
            $conf[$key] = $value;
        }

        return $r;
    }
}
