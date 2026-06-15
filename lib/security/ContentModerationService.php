<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * 内容安全审核服务 - 可插拔，内置空实现（默认 pass）
 * 
 * 审核结果映射到 audit_status：
 * - pass → 1（通过）
 * - review → 0（待审）
 * - block → 2（驳回）
 * 
 * 接口：
 * - content_moderation($type, $content, $scene): 审核内容，返回 'pass'|'review'|'block'
 * - content_moderation_status_to_audit($status): 将审核结果映射为 audit_status 值
 */
class ContentModerationService {

    // 审核结果常量
    const RESULT_PASS = 'pass';
    const RESULT_REVIEW = 'review';
    const RESULT_BLOCK = 'block';

    // 对应 audit_status 值
    const AUDIT_STATUS_PENDING = 0;
    const AUDIT_STATUS_APPROVED = 1;
    const AUDIT_STATUS_REJECTED = 2;

    /**
     * 审核内容
     * @param string $type 内容类型: thread/post/attach
     * @param string $content 内容文本
     * @param string $scene 场景: create/update
     * @return string 'pass'|'review'|'block'
     */
    public static function moderate(string $type, string $content, string $scene = 'create'): string {
        // hook 扩展点：插件可覆盖审核逻辑
        // 插件定义函数 security_moderation_check($type, $content, $scene) 返回 'pass'|'review'|'block'
        $hook_func = 'security_moderation_check';
        if (function_exists($hook_func)) {
            $result = $hook_func($type, $content, $scene);
            if (in_array($result, [self::RESULT_PASS, self::RESULT_REVIEW, self::RESULT_BLOCK])) {
                return $result;
            }
        }

        // 内置实现：默认通过
        return self::RESULT_PASS;
    }

    /**
     * 将审核结果映射为 audit_status 值
     * @param string $result 'pass'|'review'|'block'
     * @return int 0|1|2
     */
    public static function result_to_audit_status(string $result): int {
        return match($result) {
            self::RESULT_PASS => self::AUDIT_STATUS_APPROVED,
            self::RESULT_REVIEW => self::AUDIT_STATUS_PENDING,
            self::RESULT_BLOCK => self::AUDIT_STATUS_REJECTED,
            default => self::AUDIT_STATUS_APPROVED,
        };
    }

    /**
     * 将 audit_status 值映射为审核结果
     * @param int $status 0|1|2
     * @return string
     */
    public static function audit_status_to_result(int $status): string {
        return match($status) {
            self::AUDIT_STATUS_PENDING => self::RESULT_REVIEW,
            self::AUDIT_STATUS_APPROVED => self::RESULT_PASS,
            self::AUDIT_STATUS_REJECTED => self::RESULT_BLOCK,
            default => self::RESULT_PASS,
        };
    }
}
