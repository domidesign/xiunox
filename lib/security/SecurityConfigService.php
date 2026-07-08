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
        'security_subject_min_length' => 2,             // 标题最低字数
        'security_subject_max_length' => 128,           // 标题最高字数
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

        // 邮箱验证码发送限制
        'security_email_code_interval' => 60,           // 发送验证码最小间隔（秒）
        'security_email_code_daily_limit' => 5,         // 同一邮箱每日发送上限（次）
        'security_email_code_ip_hourly_limit' => 10,    // 同一IP每小时发送上限（次）

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

        // 资料修改次数限制（30天周期内）
        'security_nickname_change_limit' => 1,         // 昵称修改次数限制（30天内，0=不限制）
        'security_signature_change_limit' => 3,        // 签名修改次数限制（30天内，0=不限制）

        // iframe 嵌入白名单（每行一个域名，支持通配符 *.example.com）
        'security_iframe_whitelist' => "player.bilibili.com\nwww.youtube.com\nwww.youtube-nocookie.com\nplayer.youku.com\nv.qq.com\nplayer.tudou.com\nplayer.vimeo.com",

        // 操作频率限制
        'security_search_interval' => 10,              // 搜索间隔（秒）
        'security_search_require_login' => 1,          // 搜索需要登录，1=需要 0=不需要

        // Cookie 安全
        // security_cookie_secure: 0=自动检测HTTPS（运行时判断，默认安全），1=强制Secure
        // 注意：PHP class const 不支持 $_SERVER 表达式，0 在运行时由 session.func.php 自动检测 HTTPS
        'security_cookie_secure' => 0,                 // Cookie Secure：0=自动检测HTTPS, 1=强制Secure
        'security_cookie_httponly' => 1,               // Cookie HttpOnly，1=禁止 JS 读取
        'security_cookie_samesite' => 'Lax',           // Cookie SameSite：Lax / Strict / None
    ];

    /**
     * 直接从 conf.php 文件读取配置，绕过 $conf 缓存
     * 安全设置要求实时生效，不走缓存
     *
     * @return array
     */
    // 文件配置缓存及版本标记（保存后递增版本，强制重新读取文件）
    private static array $_file_conf = [];
    private static int $_file_conf_version = 0;
    private static int $_file_conf_loaded_version = -1;

    /**
     * 直接从 conf.php 文件读取配置，绕过 $conf 缓存
     * 安全设置要求实时生效，不走缓存
     *
     * @return array
     */
    private static function _load_file_config(): array {
        // 版本未变化，使用缓存
        if (self::$_file_conf_loaded_version === self::$_file_conf_version && !empty(self::$_file_conf)) {
            return self::$_file_conf;
        }
        $filepath = APP_PATH . 'conf/conf.php';
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($filepath, true);
        }
        $conf = include $filepath;
        self::$_file_conf = is_array($conf) ? $conf : [];
        self::$_file_conf_loaded_version = self::$_file_conf_version;
        return self::$_file_conf;
    }

    /**
     * 清除文件配置缓存（保存后调用）
     */
    private static function _clear_cache(): void {
        self::$_file_conf_version++;
    }

    /**
     * 获取全部安全配置（与默认值合并，直接从文件读取）
     *
     * @return array
     */
    public static function get_config(): array {
        $file_conf = self::_load_file_config();
        $result = [];
        foreach (self::DEFAULT_CONFIG as $key => $default_value) {
            $result[$key] = isset($file_conf[$key]) ? $file_conf[$key] : $default_value;
        }
        return $result;
    }

    /**
     * 获取单个安全配置值（直接从文件读取）
     *
     * @param string $key     配置键名
     * @param mixed  $default 自定义默认值，为 null 时使用 DEFAULT_CONFIG 中的默认值
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        $file_conf = self::_load_file_config();
        if (isset($file_conf[$key])) {
            return $file_conf[$key];
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
     * 校验密码是否符合策略（最小长度 + 复杂度）
     *
     * 读取 security_password_min_length 和 security_password_complexity 配置，
     * 用于注册、修改密码、找回密码等场景统一校验。
     *
     * @param string $password 待校验的明文密码
     * @return string 错误消息，空字符串表示通过
     */
    public static function checkPasswordPolicy(string $password): string {
        if ($password === '') {
            return lang('please_input_password');
        }
        $min_length = self::get('security_password_min_length', 6);
        if (mb_strlen($password, 'UTF-8') < $min_length) {
            return '密码长度不能少于' . $min_length . '个字符';
        }
        $complexity = self::get('security_password_complexity', 'none');
        if ($complexity === 'number' && !preg_match('/[0-9]/', $password)) {
            return '密码必须包含数字';
        } elseif ($complexity === 'mixed') {
            if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)) {
                return '密码必须包含大小写字母';
            }
        } elseif ($complexity === 'special') {
            if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
                return '密码必须包含大小写字母、数字和特殊字符';
            }
        }
        return '';
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
            } elseif ($key === 'security_iframe_whitelist') {
                // 多行文本，按行清理空白与空行，保留换行符
                $lines = preg_split('/\r\n|\r|\n/', $value);
                $lines = array_map('trim', $lines);
                $lines = array_filter($lines, function($v) { return $v !== ''; });
                $value = implode("\n", $lines);
            } elseif ($key === 'security_cookie_samesite') {
                $value = in_array($value, ['Lax', 'Strict', 'None'], true) ? $value : 'Lax';
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

        // 清除静态缓存，下次读取时重新从文件加载
        self::_clear_cache();

        // 同步更新当前进程内存中的 $conf
        global $conf;
        foreach ($save_data as $key => $value) {
            $conf[$key] = $value;
        }

        return $r;
    }
}
