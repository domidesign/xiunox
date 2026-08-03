<?php

class ApiPermissionMiddleware {

    private static array $resourcePermissions = [
        'thread' => [
            'create' => ['perm' => 'allowthread', 'type' => 'user'],
            'read'   => ['perm' => 'allowread', 'type' => 'user'],
            'update' => ['perm' => 'allowupdate', 'type' => 'mod', 'owner_check' => true],
            'delete' => ['perm' => 'allowdelete', 'type' => 'mod'],
        ],
        'post' => [
            'create' => ['perm' => 'allowpost', 'type' => 'user'],
            'read'   => ['perm' => 'allowread', 'type' => 'user'],
            'update' => ['perm' => 'allowupdate', 'type' => 'mod', 'owner_check' => true],
            'delete' => ['perm' => 'allowdelete', 'type' => 'mod'],
        ],
        'user' => [
            'create' => ['perm' => null, 'type' => 'public'],
            'read'   => ['perm' => 'allowread', 'type' => 'user'],
            'update' => ['perm' => null, 'type' => 'owner_or_admin'],
            'delete' => ['perm' => 'allowdeleteuser', 'type' => 'mod'],
        ],
        'attach' => [
            'create' => ['perm' => 'allowattach', 'type' => 'user'],
            'read'   => ['perm' => 'allowread', 'type' => 'user'],
            'delete' => ['perm' => 'allowdelete', 'type' => 'mod'],
        ],
        'forum' => [
            'create' => ['perm' => null, 'type' => 'admin_only'],
            'read'   => ['perm' => 'allowread', 'type' => 'user'],
            'update' => ['perm' => null, 'type' => 'admin_only'],
            'delete' => ['perm' => null, 'type' => 'admin_only'],
        ],
    ];

    public static function check(string $resource, string $action, array $context = []): bool {
        global $uid, $gid, $user;

        $rule = self::getRule($resource, $action);
        if ($rule === null) {
            return true;
        }

        $permType = $rule['type'] ?? 'user';

        switch ($permType) {
            case 'public':
                return true;

            case 'user':
                if (empty($uid)) {
                    return false;
                }
                return self::checkUserPermission($rule, $uid);

            case 'mod':
                if (empty($uid)) {
                    return false;
                }
                if (PermissionService::isSuperAdmin($uid)) {
                    return true;
                }
                // owner_check 需要资源所有者信息，中间件层无法获取，留给路由文件处理
                // 此处仅检查版块级权限
                return self::checkUserPermission($rule, $uid);

            case 'owner_or_admin':
                if (empty($uid)) {
                    return false;
                }
                if (PermissionService::isSuperAdmin($uid)) {
                    return true;
                }
                // 所有者检查需要资源详情，中间件层无法获取，留给路由文件处理
                // 此处放行，由路由文件做精确的所有者检查
                return true;

            case 'admin_only':
                if (empty($uid)) {
                    return false;
                }
                return PermissionService::isSuperAdmin($uid);

            default:
                return false;
        }
    }

    public static function requirePermission(string $resource, string $action, array $context = []): void {
        if (!self::check($resource, $action, $context)) {
            $debug = defined('DEBUG') ? DEBUG : 0;
            $logMsg = "Permission denied: {$resource}:{$action}";
            xn_log($logMsg, 'api_permission', 'WARNING');
            if ($debug) {
                xn_log('Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE), 'api_permission', 'DEBUG');
            }
            ApiResponse::forbidden('您没有权限执行此操作');
        }
    }

    public static function getActionForMethod(string $method): string {
        $method = strtoupper($method);
        return match($method) {
            'GET', 'HEAD', 'OPTIONS' => 'read',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'read',
        };
    }

    public static function requirePermissionForRoute(string $resource, string $method, array $context = []): void {
        $action = self::getActionForMethod($method);
        self::requirePermission($resource, $action, $context);
    }

    private static function getRule(string $resource, string $action): ?array {
        $action = self::normalizeAction($action);
        return self::$resourcePermissions[$resource][$action] ?? null;
    }

    private static function normalizeAction(string $action): string {
        return match($action) {
            'create', 'post' => 'create',
            'read', 'get', 'head', 'options', 'list', 'show', 'view' => 'read',
            'update', 'put', 'patch', 'edit', 'modify' => 'update',
            'delete', 'remove', 'destroy' => 'delete',
            default => 'read',
        };
    }

    private static function checkUserPermission(array $rule, int $uid): bool {
        $perm = $rule['perm'] ?? null;
        if ($perm === null) {
            return true;
        }

        if (!class_exists('PermissionService')) {
            if (function_exists('xn_log')) {
                xn_log('PermissionService not loaded, defaulting to deny', 'api_permission', 'ERROR');
            }
            return false;
        }

        return PermissionService::check($perm, $uid);
    }

    private static function isOwner(string $resource, array $context): bool {
        global $uid;

        if (empty($uid)) {
            return false;
        }

        $ownerField = match($resource) {
            'thread' => 'thread_uid',
            'post' => 'post_uid',
            'user' => 'user_uid',
            'attach' => 'uid',
            default => null,
        };

        if ($ownerField === null) {
            return false;
        }

        $ownerUid = $context[$ownerField] ?? ($context['uid'] ?? 0);
        return intval($ownerUid) === intval($uid);
    }

    public static function getResourcePermissions(): array {
        return self::$resourcePermissions;
    }
}