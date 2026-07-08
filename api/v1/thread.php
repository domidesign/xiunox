<?php

function filterFields(array $data, string $fieldsParam): array {
    if (empty($fieldsParam)) return $data;
    $allowed = array_flip(explode(',', $fieldsParam));
    if (is_array($data) && isset($data[0])) {
        return array_map(function($item) use ($allowed) {
            return array_intersect_key($item, $allowed);
        }, $data);
    }
    return array_intersect_key($data, $allowed);
}

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

// 获取当前认证用户
$threadAuthToken = ApiAuthService::getBearerToken();
$threadAuthUser = $threadAuthToken ? $apiAuth->validateAccessToken($threadAuthToken) : null;
$threadIsAdmin = $threadAuthUser && in_array(intval($threadAuthUser['gid']), [1, 2], true);
$threadGid = $threadAuthUser ? intval($threadAuthUser['gid']) : 0;
$threadUid = $threadAuthUser ? intval($threadAuthUser['uid']) : 0;

// 初始化版块权限校验所需函数和全局变量（API 上下文默认未加载）
if (!function_exists('forum_access_user')) {
    include_once APP_PATH . 'model/forum_access.func.php';
}
if (!function_exists('forum_list_cache')) {
    include_once APP_PATH . 'model/forum.func.php';
}
if (!function_exists('group_list_cache')) {
    include_once APP_PATH . 'model/group.func.php';
}
if (empty($GLOBALS['forumlist'])) {
    $GLOBALS['forumlist'] = forum_list_cache();
}
if (empty($GLOBALS['grouplist'])) {
    $GLOBALS['grouplist'] = group_list_cache();
}

$seg1 = $segments[1] ?? '';
$seg2 = $segments[2] ?? '';

if ($seg1 === 'hot') {
    // 近期热门帖子
    if ($method !== 'GET') {
        ApiResponse::error(405, 'Method not allowed');
    }
    $days = intval($_GET['days'] ?? 7);
    $pagesize = intval($_GET['pagesize'] ?? 10);
    if ($pagesize > 50) $pagesize = 50;
    if ($pagesize < 1) $pagesize = 1;
    $since = time() - $days * 86400;

    // 非管理员增加审核过滤条件
    $auditWhere = $threadIsAdmin ? '' : ' AND audit_status = 1';
    $sql = "SELECT tid, fid, uid, subject, views, posts, likes, create_date FROM " . $db->table('thread') . " WHERE create_date >= {$since} AND closed = 0{$auditWhere} ORDER BY views DESC, posts DESC LIMIT {$pagesize}";
    $list = db_sql_find($sql);

    // 补充版块名称和用户信息
    if (!empty($list)) {
        $fids = array_unique(array_column($list, 'fid'));
        $forumMap = [];
        foreach ($fids as $f) {
            $fr = $forumService->getForumById(intval($f));
            if ($fr) $forumMap[$f] = $fr['name'];
        }

        $uids = array_unique(array_column($list, 'uid'));
        $userMap = [];
        foreach ($uids as $u) {
            $ur = user_read_cache(intval($u));
            if ($ur) {
                user_format($ur);
                $userMap[$u] = [
                    'uid' => $ur['uid'],
                    'username' => $ur['username'],
                    'display_name' => $ur['display_name'] ?? $ur['username'],
                    'avatar_url' => $ur['avatar_url'] ?? '',
                ];
            }
        }

        foreach ($list as &$item) {
            $item['forum_name'] = $forumMap[$item['fid']] ?? '';
            $item['user'] = $userMap[$item['uid']] ?? ['uid' => 0, 'username' => '', 'avatar_url' => ''];
        }
        unset($item);
    }

    ApiResponse::success(['list' => $list ?: [], 'days' => $days]);
} elseif ($seg1 === 'batch') {
    switch ($method) {
        case 'DELETE':
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            if (intval($authUser['gid']) !== 1) {
                ApiResponse::forbidden();
            }
            $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
            $tids = $jsonInput['tids'] ?? [];
            if (empty($tids) || !is_array($tids)) {
                ApiResponse::validationError('tids must be a non-empty array');
            }
            $count = $threadService->batchDelete($tids);
            ApiResponse::success(['deleted' => $count]);
            break;

        case 'PUT':
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            if (intval($authUser['gid']) !== 1) {
                ApiResponse::forbidden();
            }
            $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
            $tids = $jsonInput['tids'] ?? [];
            $update = $jsonInput['update'] ?? [];
            if (empty($tids) || !is_array($tids)) {
                ApiResponse::validationError('tids must be a non-empty array');
            }
            if (empty($update) || !is_array($update)) {
                ApiResponse::validationError('update must be a non-empty object');
            }
            $count = $threadService->batchUpdate($tids, $update);
            ApiResponse::success(['updated' => $count]);
            break;

        default:
            ApiResponse::error(405, 'Method not allowed');
    }
} elseif (is_numeric($seg1) && intval($seg1) > 0) {
    $tid = intval($seg1);

    if (empty($seg2)) {
        switch ($method) {
            case 'GET':
                $thread = $threadService->getThreadById($tid);
                if (!$thread) {
                    ApiResponse::notFound('Thread not found');
                }
                // 非管理员、非作者不可查看未审核通过的帖子
                if (!$threadIsAdmin && intval($thread['audit_status']) !== 1 && intval($thread['uid']) !== intval($threadAuthUser['uid'] ?? 0)) {
                    ApiResponse::notFound('Thread not found');
                }
                $fields = $_GET['fields'] ?? '';
                if (!empty($fields)) {
                    $thread = filterFields($thread, $fields);
                }
                ApiResponse::success($thread);
                break;

            case 'PUT':
                $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                $thread = $threadService->getThreadById($tid);
                if (!$thread) {
                    ApiResponse::notFound('Thread not found');
                }
                if (intval($thread['uid']) !== intval($authUser['uid']) && intval($authUser['gid']) !== 1) {
                    ApiResponse::forbidden();
                }
                $update = [];
                $subject = param('subject', '');
                if (!empty($subject)) $update['subject'] = $subject;
                if (!empty($update)) {
                    $threadService->updateThread($tid, $update);
                }
                ApiResponse::success($threadService->getThreadById($tid));
                break;

            case 'DELETE':
                $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                $thread = $threadService->getThreadById($tid);
                if (!$thread) {
                    ApiResponse::notFound('Thread not found');
                }
                if (intval($thread['uid']) !== intval($authUser['uid']) && intval($authUser['gid']) !== 1) {
                    ApiResponse::forbidden();
                }
                $threadService->deleteThread($tid);
                ApiResponse::success(null, 'Deleted');
                break;

            default:
                ApiResponse::error(405, 'Method not allowed');
        }
    } else {
        switch ($seg2) {
            case 'like':
                switch ($method) {
                    case 'POST':
                        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                        $thread = $threadService->getThreadById($tid);
                        if (!$thread) {
                            ApiResponse::notFound('Thread not found');
                        }
                        $threadService->likeThread($tid, intval($authUser['uid']));
                        ApiResponse::success(['liked' => true]);
                        break;

                    case 'DELETE':
                        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                        $thread = $threadService->getThreadById($tid);
                        if (!$thread) {
                            ApiResponse::notFound('Thread not found');
                        }
                        $threadService->unlikeThread($tid, intval($authUser['uid']));
                        ApiResponse::success(['liked' => false]);
                        break;

                    default:
                        ApiResponse::error(405, 'Method not allowed');
                }
                break;

            case 'favorite':
                switch ($method) {
                    case 'POST':
                        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                        $thread = $threadService->getThreadById($tid);
                        if (!$thread) {
                            ApiResponse::notFound('Thread not found');
                        }
                        $threadService->favoriteThread($tid, intval($authUser['uid']));
                        ApiResponse::success(['favorited' => true]);
                        break;

                    case 'DELETE':
                        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                        $thread = $threadService->getThreadById($tid);
                        if (!$thread) {
                            ApiResponse::notFound('Thread not found');
                        }
                        $threadService->unfavoriteThread($tid, intval($authUser['uid']));
                        ApiResponse::success(['favorited' => false]);
                        break;

                    default:
                        ApiResponse::error(405, 'Method not allowed');
                }
                break;

            case 'report':
                if ($method !== 'POST') {
                    ApiResponse::error(405, 'Method not allowed');
                }
                $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                $thread = $threadService->getThreadById($tid);
                if (!$thread) {
                    ApiResponse::notFound('Thread not found');
                }
                $reason = param('reason', '');
                $threadService->reportThread($tid, intval($authUser['uid']), $reason);
                ApiResponse::success(null, 'Reported');
                break;

            case 'announcement':
                if ($method !== 'POST') {
                    ApiResponse::error(405, 'Method not allowed');
                }
                $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                if (intval($authUser['gid']) !== 1 && intval($authUser['gid']) !== 2) {
                    ApiResponse::forbidden('Admin or moderator required');
                }
                $thread = $threadService->getThreadById($tid);
                if (!$thread) {
                    ApiResponse::notFound('Thread not found');
                }
                $is_announcement = param('is_announcement', 0);
                $announcement_order = param('announcement_order', 0);
                $update = array(
                    'is_announcement' => intval($is_announcement),
                    'announcement_order' => intval($is_announcement) ? intval($announcement_order) : 0,
                );
                $threadService->updateThread($tid, $update);
                ApiResponse::success(['is_announcement' => intval($is_announcement), 'announcement_order' => intval($announcement_order)]);
                break;

            default:
                ApiResponse::notFound('Unknown sub-resource');
        }
    }
} else {
    switch ($method) {
        case 'GET':
            // 支持 ids 参数获取多个帖子
            $idsParam = $_GET['ids'] ?? '';
            if (!empty($idsParam)) {
                $ids = is_string($idsParam) ? array_map('trim', explode(',', $idsParam)) : $idsParam;
                $list = $threadService->getThreadsByIds($ids);
                // 非管理员过滤未审核通过的帖子
                if (!$threadIsAdmin && !empty($list)) {
                    $list = array_filter($list, function($t) use ($threadAuthUser) {
                        return intval($t['audit_status']) === 1 || intval($t['uid']) === intval($threadAuthUser['uid'] ?? 0);
                    });
                    $list = array_values($list);
                }
                $result = ['list' => $list, 'total' => count($list)];
                $fields = $_GET['fields'] ?? '';
                if (!empty($fields)) {
                    $result['list'] = filterFields($result['list'], $fields);
                }
                ApiResponse::success($result);
            }

            $page = intval($_GET['page'] ?? 1);
            $pagesize = intval($_GET['pagesize'] ?? 20);

            $cond = [];
            ApiResponse::filterByAuditStatus($cond, $threadGid, $threadUid);

            $fid = intval($_GET['fid'] ?? 0);
            if ($fid > 0) {
                $cond['fid'] = $fid;
            }

            $uid = intval($_GET['uid'] ?? 0);
            if ($uid > 0) {
                $cond['uid'] = $uid;
            }

            $keyword = $_GET['keyword'] ?? '';
            $searchSql = '';
            if (!empty($keyword)) {
                $kw = $db->quote('%' . $keyword . '%');
                $searchSql = "(subject LIKE '{$kw}' OR message LIKE '{$kw}')";
            }

            $allowedOrderby = ['tid', 'create_date', 'last_date', 'views', 'posts'];
            $orderby = [];
            $orderbyField = $_GET['orderby'] ?? '';
            $order = intval($_GET['order'] ?? -1);
            $order = $order === 1 ? 1 : -1;
            if (!empty($orderbyField) && in_array($orderbyField, $allowedOrderby)) {
                $orderby[$orderbyField] = $order;
            } else {
                $orderby = ['tid' => -1];
            }

            $list = $threadService->getThreadList($cond, $orderby, $page, $pagesize);
            $total = $db->count('thread', $cond);

            if (!empty($searchSql)) {
                $auditSql = $threadIsAdmin ? '' : ' AND audit_status = 1';
                $where = !empty($cond) ? ' AND ' . $searchSql . $auditSql : ' WHERE ' . $searchSql . $auditSql;
                $list = $db->query("SELECT * FROM " . $db->table('thread') . $where . " ORDER BY tid DESC LIMIT " . (($page - 1) * $pagesize) . ",{$pagesize}")->fetchAll();
                $totalRow = $db->query("SELECT COUNT(*) AS total FROM " . $db->table('thread') . $where)->fetchOne();
                $total = $totalRow ? $totalRow['total'] : 0;
            }

            $fields = $_GET['fields'] ?? '';
            if (!empty($fields)) {
                $list = filterFields($list, $fields);
            }

            ApiResponse::success(paginateResult($list, $page, $pagesize, $total));
            break;

        case 'POST':
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            if (empty($authUser)) {
                ApiResponse::unauthorized('Invalid or expired access token');
            }
            $fid = param('fid', 0);
            $subject = param('subject', '');
            $message = param('message', '', false);
            $attach_keys = param('attach_keys', '');
            if (empty($fid) || empty($subject) || empty($message)) {
                ApiResponse::validationError('fid, subject and message are required');
            }
            // 校验版块发帖权限（allowthread）
            if (!forum_access_user(intval($fid), intval($authUser['gid']), 'allowthread')) {
                ApiResponse::forbidden('No permission to create thread in this forum');
            }

            // ===== 验证码能力开关（Task 2.4）=====
            global $apiApp, $apiAppServerAuth, $apiAuth;
            $skipCaptcha = $apiAppServerAuth && $apiAuth->checkAppCapability($apiApp, 'skip_captcha');
            if (!$skipCaptcha) {
                if (!class_exists('CaptchaService')) {
                    include_once APP_PATH . 'lib/security/CaptchaService.php';
                }
                $gid = intval($authUser['gid'] ?? 0);
                if (CaptchaService::is_enabled('post', $gid)) {
                    $captchaCode = param('captcha_code', '', false);
                    if (!CaptchaService::verify('post', $captchaCode, $gid)) {
                        ApiResponse::error(422, lang('captcha_error'));
                    }
                }
            }

            // ===== 审核能力开关（Task 2.5）=====
            $skipAudit = $apiAppServerAuth && $apiAuth->checkAppCapability($apiApp, 'skip_audit');
            $auditStatus = $skipAudit ? 1 : (in_array(intval($authUser['gid']), [1, 2]) ? 1 : 0);

            // 使用 Xiuno 原始的 thread_create 函数
            $arr = [
                'fid' => $fid,
                'uid' => intval($authUser['uid']),
                'subject' => $subject,
                'message' => $message,
                'time' => time(),
                'longip' => ip2long($ip),
                'doctype' => 0,
                'audit_status' => $auditStatus,
            ];
            $pid = 0;
            $tid = thread_create($arr, $pid);
            if ($tid === FALSE || $tid <= 0) {
                ApiResponse::error(500, 'Failed to create thread');
            }

            // 关联附件（如果有 attach_keys）
            $attach_info = array('images' => 0, 'videos' => 0, 'files' => 0);
            if (!empty($attach_keys)) {
                $attach_info = api_attach_assoc_post($pid, $tid, $attach_keys, $message);
                // 如果 message 中的 URL 被替换了，更新 post 的 message
                $original_message = param('message', '', false);
                if ($message !== $original_message) {
                    post__update($pid, array('message' => $message));
                }
            }

            ApiResponse::success([
                'tid' => $tid,
                'pid' => $pid,
                'images' => $attach_info['images'],
                'videos' => $attach_info['videos'],
                'files' => $attach_info['files'],
            ], 'Created');
            break;

        default:
            ApiResponse::error(405, 'Method not allowed');
    }
}
