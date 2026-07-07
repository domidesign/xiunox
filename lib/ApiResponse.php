<?php

class ApiResponse {

    public static function success($data = null, string $msg = 'ok'): void {
        self::output(0, $msg, $data);
    }

    public static function error(int $code, string $msg, $data = null, array $errors = []): void {
        self::output($code, $msg, $data, $errors);
    }

    public static function unauthorized(string $msg = 'Unauthorized'): void {
        self::output(401, $msg, null);
    }

    public static function forbidden(string $msg = 'Forbidden'): void {
        self::output(403, $msg, null);
    }

    public static function notFound(string $msg = 'Not Found'): void {
        self::output(404, $msg, null);
    }

    public static function validationError(string $msg = 'Validation Error', array $errors = []): void {
        self::output(422, $msg, null, $errors);
    }

    public static function tooManyRequests(string $msg = 'Too Many Requests'): void {
        self::output(429, $msg, null);
    }

    public static function conflict(string $msg = 'Conflict'): void {
        self::output(409, $msg, null);
    }

    private static function output(int $code, string $msg, $data, array $errors = []): void {
        http_response_code($code === 0 ? 200 : $code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-API-Version: 1.0');
        $response = [
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
        ];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        // hook api_response_before_send.php
        if (function_exists('hook')) {
            $response = hook('api_response_before_send', $response);
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function sanitizeInput(string $value): string {
        return htmlspecialchars(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function sanitizeArray(array $data): array {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = self::sanitizeInput($value);
            } elseif (is_array($value)) {
                $result[$key] = self::sanitizeArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 根据用户身份过滤审核状态条件
     * 管理员/版主（gid=1/2）不加条件，可看全部
     * 普通用户只能看 audit_status=1
     * @param array &$cond 查询条件数组（引用传递）
     * @param int $gid 用户组 ID
     * @param int $uid 用户 ID（保留参数，当前列表查询未使用）
     */
    public static function filterByAuditStatus(array &$cond, int $gid, int $uid = 0): void {
        if (in_array($gid, [1, 2], true)) {
            return; // 管理员/版主不加条件
        }
        $cond['audit_status'] = 1;
    }
}
