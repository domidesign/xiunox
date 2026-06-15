<?php

// 管理后台 API 路由 — 所有端点要求管理员权限（gid=1）

include_once APP_PATH . 'lib/security/SecurityConfigService.php';
include_once APP_PATH . 'lib/security/CaptchaService.php';
include_once APP_PATH . 'lib/security/AuditService.php';
include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';

// 本地分页辅助函数
function paginateResult(array $list, int $page, int $pagesize, int $total): array {
    return [
        'list' => $list,
        'pagination' => [
            'page' => $page,
            'pagesize' => $pagesize,
            'total' => $total,
            'total_pages' => $pagesize > 0 ? ceil($total / $pagesize) : 0,
        ],
    ];
}

// 管理员鉴权
$authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
if (!$authUser || intval($authUser['gid']) !== 1) {
    ApiResponse::forbidden('Admin access required');
}

$operatorUid = intval($authUser['uid']);
$seg1 = $segments[1] ?? '';
$seg2 = $segments[2] ?? '';
$seg3 = $segments[3] ?? '';

// 路由分发
if ($seg1 === 'security') {
    // ========== 安全配置 ==========

    if ($seg2 === 'captcha') {
        // 验证码配置：/admin/security/captcha
        if ($method === 'GET') {
            $config = CaptchaService::get_config();
            ApiResponse::success($config);
        } elseif ($method === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (empty($input)) {
                ApiResponse::validationError('请求体不能为空');
            }
            $result = CaptchaService::save_config($input);
            if ($result) {
                ApiResponse::success(CaptchaService::get_config(), '验证码配置已更新');
            } else {
                ApiResponse::error(500, '保存验证码配置失败');
            }
        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } else {
        // 安全配置：/admin/security
        if ($method === 'GET') {
            $config = SecurityConfigService::get_config();
            ApiResponse::success($config);
        } elseif ($method === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (empty($input)) {
                ApiResponse::validationError('请求体不能为空');
            }
            // 仅提取 security_ 前缀的字段
            $data = [];
            foreach ($input as $key => $value) {
                if (strpos($key, 'security_') === 0) {
                    $data[$key] = $value;
                }
            }
            if (empty($data)) {
                ApiResponse::validationError('未提供有效的 security_* 配置项');
            }
            $result = SecurityConfigService::save_config($data);
            if ($result) {
                ApiResponse::success(SecurityConfigService::get_config(), '安全配置已更新');
            } else {
                ApiResponse::error(500, '保存安全配置失败');
            }
        } else {
            ApiResponse::error(405, 'Method not allowed');
        }
    }

} elseif ($seg1 === 'audit') {
    // ========== 审核管理 ==========

    if ($seg2 === 'pending') {
        // GET /admin/audit/pending — 待审列表
        if ($method !== 'GET') ApiResponse::error(405, 'Method not allowed');

        $type = $_GET['type'] ?? 'thread';
        if (!in_array($type, ['thread', 'post', 'profile'], true)) {
            $type = 'thread';
        }
        $page = max(1, intval($_GET['page'] ?? 1));
        $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

        if ($type === 'profile') {
            $list = AuditService::get_pending_profile_list($page, $pagesize);
            $total = AuditService::get_pending_profile_count();
        } else {
            $list = AuditService::get_pending_list($type, $page, $pagesize);
            $total = AuditService::get_pending_count($type);
        }

        ApiResponse::success(paginateResult($list, $page, $pagesize, $total));

    } elseif ($seg2 === 'approve') {
        // POST /admin/audit/approve — 审核通过
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetType = $input['target_type'] ?? '';
        $targetId = intval($input['target_id'] ?? 0);

        if (empty($targetType) || $targetId <= 0) {
            ApiResponse::validationError('target_type 和 target_id 为必填项');
        }

        if ($targetType === 'profile') {
            $result = AuditService::approve_profile($targetId, $operatorUid);
        } elseif (in_array($targetType, ['thread', 'post'], true)) {
            $result = AuditService::approve($targetType, $targetId, $operatorUid);
        } else {
            ApiResponse::validationError('target_type 必须为 thread、post 或 profile');
        }

        if ($result) {
            ApiResponse::success(null, '审核通过');
        } else {
            ApiResponse::error(400, '审核操作失败，目标可能不存在');
        }

    } elseif ($seg2 === 'reject') {
        // POST /admin/audit/reject — 审核驳回
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetType = $input['target_type'] ?? '';
        $targetId = intval($input['target_id'] ?? 0);
        $reason = $input['reason'] ?? '';

        if (empty($targetType) || $targetId <= 0) {
            ApiResponse::validationError('target_type 和 target_id 为必填项');
        }

        if ($targetType === 'profile') {
            $result = AuditService::reject_profile($targetId, $operatorUid, $reason);
        } elseif (in_array($targetType, ['thread', 'post'], true)) {
            $result = AuditService::reject($targetType, $targetId, $operatorUid, $reason);
        } else {
            ApiResponse::validationError('target_type 必须为 thread、post 或 profile');
        }

        if ($result) {
            ApiResponse::success(null, '已驳回');
        } else {
            ApiResponse::error(400, '驳回操作失败，目标可能不存在');
        }

    } elseif ($seg2 === 'batch-approve') {
        // POST /admin/audit/batch-approve — 批量通过
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetType = $input['target_type'] ?? '';
        $ids = $input['ids'] ?? [];

        if (empty($targetType) || !in_array($targetType, ['thread', 'post'], true)) {
            ApiResponse::validationError('target_type 必须为 thread 或 post');
        }
        if (empty($ids) || !is_array($ids)) {
            ApiResponse::validationError('ids 必须为非空数组');
        }

        $count = AuditService::batch_approve($targetType, $ids, $operatorUid);
        ApiResponse::success(['approved' => $count]);

    } elseif ($seg2 === 'batch-reject') {
        // POST /admin/audit/batch-reject — 批量驳回
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetType = $input['target_type'] ?? '';
        $ids = $input['ids'] ?? [];
        $reason = $input['reason'] ?? '';

        if (empty($targetType) || !in_array($targetType, ['thread', 'post'], true)) {
            ApiResponse::validationError('target_type 必须为 thread 或 post');
        }
        if (empty($ids) || !is_array($ids)) {
            ApiResponse::validationError('ids 必须为非空数组');
        }

        $count = AuditService::batch_reject($targetType, $ids, $operatorUid, $reason);
        ApiResponse::success(['rejected' => $count]);

    } else {
        ApiResponse::notFound('Unknown audit sub-resource: ' . $seg2);
    }

} elseif ($seg1 === 'sensitive-words') {
    // ========== 敏感词管理 ==========

    if ($seg2 === 'import') {
        // POST /admin/sensitive-words/import — 批量导入
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $words = $input['words'] ?? '';
        if (empty($words)) {
            ApiResponse::validationError('words 不能为空');
        }

        $count = SensitiveWordFilter::batch_import($words);
        ApiResponse::success(['imported' => $count]);

    } elseif (!empty($seg2) && $method === 'DELETE') {
        // DELETE /admin/sensitive-words/{word} — 删除指定敏感词
        $word = urldecode($seg2);
        if (empty($word)) {
            ApiResponse::validationError('敏感词不能为空');
        }
        $result = SensitiveWordFilter::delete_word($word);
        if ($result) {
            ApiResponse::success(null, '敏感词已删除');
        } else {
            ApiResponse::error(400, '删除失败');
        }

    } else {
        // /admin/sensitive-words 根路径
        if ($method === 'GET') {
            $words = SensitiveWordFilter::get_all_words();
            ApiResponse::success(['words' => $words, 'total' => count($words)]);

        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $word = trim($input['word'] ?? '');
            if (empty($word)) {
                ApiResponse::validationError('word 不能为空');
            }
            $result = SensitiveWordFilter::add_word($word);
            if ($result) {
                ApiResponse::success(null, '敏感词已添加');
            } else {
                ApiResponse::error(400, '添加失败');
            }

        } elseif ($method === 'DELETE') {
            // DELETE /admin/sensitive-words — 清空所有敏感词
            $result = SensitiveWordFilter::clear_words();
            if ($result) {
                ApiResponse::success(null, '敏感词库已清空');
            } else {
                ApiResponse::error(400, '清空失败');
            }

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }
    }

} elseif ($seg1 === 'log') {
    // ========== 日志查询 ==========

    if ($seg2 === 'credits') {
        // GET /admin/log/credits — 积分日志
        if ($method !== 'GET') ApiResponse::error(405, 'Method not allowed');

        $page = max(1, intval($_GET['page'] ?? 1));
        $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

        $cond = [];
        $uid = intval($_GET['uid'] ?? 0);
        if ($uid > 0) {
            $cond['uid'] = $uid;
        }
        $type = $_GET['type'] ?? '';
        if (!empty($type)) {
            $cond['type'] = $type;
        }

        // 日期范围
        $dateStart = intval($_GET['date_start'] ?? 0);
        $dateEnd = intval($_GET['date_end'] ?? 0);

        $whereParts = [];
        $params = [];
        foreach ($cond as $key => $val) {
            $whereParts[] = "{$key} = :{$key}";
            $params[":{$key}"] = $val;
        }
        if ($dateStart > 0) {
            $whereParts[] = "create_date >= :date_start";
            $params[':date_start'] = $dateStart;
        }
        if ($dateEnd > 0) {
            $whereParts[] = "create_date <= :date_end";
            $params[':date_end'] = $dateEnd;
        }

        $where = empty($whereParts) ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $table = $db->table('credits_log');
        $offset = ($page - 1) * $pagesize;

        $list = $db->query("SELECT * FROM {$table}{$where} ORDER BY logid DESC LIMIT {$offset}, {$pagesize}", $params)->fetchAll();
        $totalRow = $db->query("SELECT COUNT(*) AS total FROM {$table}{$where}", $params)->fetchOne();
        $total = $totalRow ? intval($totalRow['total']) : 0;

        ApiResponse::success(paginateResult($list ?: [], $page, $pagesize, $total));

    } elseif ($seg2 === 'login') {
        // GET /admin/log/login — 登录日志
        if ($method !== 'GET') ApiResponse::error(405, 'Method not allowed');

        $page = max(1, intval($_GET['page'] ?? 1));
        $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

        $cond = [];
        $uid = intval($_GET['uid'] ?? 0);
        if ($uid > 0) {
            $cond['uid'] = $uid;
        }
        $success = $_GET['success'] ?? '';
        if ($success !== '' && $success !== null) {
            $cond['success'] = intval($success);
        }

        // 日期范围
        $dateStart = intval($_GET['date_start'] ?? 0);
        $dateEnd = intval($_GET['date_end'] ?? 0);

        $whereParts = [];
        $params = [];
        foreach ($cond as $key => $val) {
            $whereParts[] = "{$key} = :{$key}";
            $params[":{$key}"] = $val;
        }
        if ($dateStart > 0) {
            $whereParts[] = "create_date >= :date_start";
            $params[':date_start'] = $dateStart;
        }
        if ($dateEnd > 0) {
            $whereParts[] = "create_date <= :date_end";
            $params[':date_end'] = $dateEnd;
        }

        $where = empty($whereParts) ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $table = $db->table('user_login_log');
        $offset = ($page - 1) * $pagesize;

        $list = $db->query("SELECT * FROM {$table}{$where} ORDER BY logid DESC LIMIT {$offset}, {$pagesize}", $params)->fetchAll();
        $totalRow = $db->query("SELECT COUNT(*) AS total FROM {$table}{$where}", $params)->fetchOne();
        $total = $totalRow ? intval($totalRow['total']) : 0;

        ApiResponse::success(paginateResult($list ?: [], $page, $pagesize, $total));

    } else {
        ApiResponse::notFound('Unknown log sub-resource: ' . $seg2);
    }

} else {
    ApiResponse::notFound('Unknown admin sub-resource: ' . $seg1);
}
