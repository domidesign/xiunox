<?php

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

// 头像 URL 生成（参考 user.php sanitizeUserData 写法）
function notifyBuildAvatarUrl(array $user): string {
    global $conf;
    if (!empty($user['avatar_url'])) {
        return $user['avatar_url'];
    }
    if (isset($user['avatar']) && $user['avatar'] > 0) {
        return $conf['upload_url'] . 'avatar/' . substr(sprintf("%09d", $user['uid']), 0, 3) . '/' . $user['uid'] . '.png?' . $user['avatar'];
    }
    return default_avatar_url();
}

// 确保 notify 模型函数可用（API 模式下 model 函数未自动加载）
if (!function_exists('notify__read')) {
    include APP_PATH . 'model/notify.func.php';
}

$authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());

$subResource = $segments[1] ?? '';

switch ($method) {
    case 'GET':
        if ($subResource === 'unread') {
            $count = notify_count_unread(intval($authUser['uid']));
            ApiResponse::success(['count' => $count]);
        } else {
            $page = intval($_GET['page'] ?? 1);
            $pagesize = intval($_GET['pagesize'] ?? 20);
            $type = $_GET['type'] ?? '';
            $cond = ['uid' => intval($authUser['uid'])];
            if (!empty($type)) {
                $cond['type'] = $type;
            }
            // 查询 notify 表（含 from_uid 字段，支持 from_user 详情）
            $list = $db->find('notify', $cond, ['nid' => -1], $page, $pagesize, 'nid');
            $total = $db->count('notify', $cond);

            // 批量查询 from_user，避免 N+1
            $fromUids = array_unique(array_filter(array_map('intval', array_column($list, 'from_uid'))));
            $fromUsers = [];
            if (!empty($fromUids)) {
                $users = $db->find('user', ['uid' => $fromUids], [], 1, count($fromUids), 'uid');
                foreach ($users as $u) {
                    $fromUsers[intval($u['uid'])] = [
                        'uid' => intval($u['uid']),
                        'username' => $u['username'],
                        'nickname' => $u['nickname'] ?? $u['username'],
                        'avatar_url' => notifyBuildAvatarUrl($u),
                    ];
                }
            }

            // 给每条通知附加 from_user
            foreach ($list as &$n) {
                $fromUid = intval($n['from_uid'] ?? 0);
                $n['from_user'] = $fromUsers[$fromUid] ?? null;
            }
            unset($n);

            ApiResponse::success(paginateResult(array_values($list), $page, $pagesize, $total));
        }
        break;

    case 'PUT':
        if ($subResource === 'read-all') {
            notify_mark_all_read(intval($authUser['uid']));
            ApiResponse::success(null, 'All marked as read');
        } elseif (isset($segments[2]) && $segments[2] === 'read' && is_numeric($subResource)) {
            $id = intval($subResource);
            // 验证通知存在且属于当前用户
            $notify = notify__read($id);
            if (empty($notify)) {
                ApiResponse::notFound('Notification not found');
            }
            if (intval($notify['uid']) !== intval($authUser['uid'])) {
                ApiResponse::forbidden('Notification does not belong to current user');
            }
            notify_mark_read($id);
            ApiResponse::success(null, 'Marked as read');
        } else {
            ApiResponse::notFound();
        }
        break;

    case 'DELETE':
        if (is_numeric($subResource) && $subResource > 0) {
            $id = intval($subResource);
            // 验证通知存在且属于当前用户（参考 my.php notify_delete 逻辑）
            $notify = notify__read($id);
            if (empty($notify)) {
                ApiResponse::notFound('Notification not found');
            }
            if (intval($notify['uid']) !== intval($authUser['uid'])) {
                ApiResponse::forbidden('Notification does not belong to current user');
            }
            // notify_delete 内部会更新 user.unread_notices 并清理缓存
            $r = notify_delete($id);
            if ($r === FALSE) {
                ApiResponse::error(500, 'Delete failed');
            }
            ApiResponse::success(null, 'Deleted');
        } else {
            ApiResponse::notFound();
        }
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
