<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * 安全增强服务 - 补充现有安全模块的缺失功能
 * 
 * 功能：
 * - 防止用户名枚举
 * - 敏感操作二次验证
 * - 登录信息展示
 */
class SecurityService {

    /**
     * 获取模糊错误提示（防止用户名枚举）
     * 用于注册、找回密码等场景，不暴露具体是邮箱不存在还是密码错误
     * @param string $field 原始字段名
     * @param string $original_message 原始错误消息
     * @return string 模糊化后的错误消息
     */
    public static function obfuscate_error(string $field, string $original_message): string {
        // 统一返回模糊提示
        return '操作失败，请检查输入信息';
    }

    /**
     * 验证敏感操作身份
     * 默认实现：验证当前密码
     * 插件可定义 security_verify_action($uid, $action) 函数覆盖
     * 
     * @param int $uid 用户ID
     * @param string $password 用户输入的当前密码
     * @param string $action 操作类型（如 change_password, bind_email）
     * @return bool
     */
    public static function verify_sensitive_action(int $uid, string $password, string $action = ''): bool {
        // hook 扩展点
        $hook_func = 'security_verify_action';
        if (function_exists($hook_func)) {
            return (bool) $hook_func($uid, $action);
        }

        // 默认实现：验证当前密码
        $user = user_read($uid);
        if (empty($user)) return false;

        return user_login_verify($password, $user);
    }

    /**
     * 获取上次登录信息
     * @param int $uid 用户ID
     * @return array ['last_login_ip' => string, 'last_login_time' => int, 'last_login_time_fmt' => string, 'last_login_ip_fmt' => string]
     */
    public static function get_last_login_info(int $uid): array {
        $user = user_read($uid);
        if (empty($user)) {
            return [
                'last_login_ip' => 0,
                'last_login_time' => 0,
                'last_login_time_fmt' => '',
                'last_login_ip_fmt' => '',
            ];
        }

        $last_ip = intval($user['last_login_ip'] ?? 0);
        $last_time = intval($user['last_login_time'] ?? 0);

        return [
            'last_login_ip' => $last_ip,
            'last_login_time' => $last_time,
            'last_login_time_fmt' => $last_time > 0 ? date('Y-m-d H:i:s', $last_time) : '',
            'last_login_ip_fmt' => $last_ip > 0 ? long2ip($last_ip) : '',
        ];
    }

    /**
     * 检查是否需要显示登录安全提示
     * 在登录成功后调用，返回上次登录信息供前端展示
     * @param int $uid 用户ID
     * @return array|null 如果有上次登录记录则返回信息，否则返回 null
     */
    public static function get_login_security_notice(int $uid): ?array {
        $info = self::get_last_login_info($uid);
        if (empty($info['last_login_time'])) {
            return null;
        }
        return $info;
    }
}
