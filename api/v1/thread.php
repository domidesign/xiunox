<?php
include_once APP_PATH . 'lib/ApiContentService.php';

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
    $sql = "SELECT tid, fid, uid, subject, views, posts, likes, create_date, is_deleted, audit_status FROM " . $db->table('thread') . " WHERE create_date >= {$since} AND closed = 0{$auditWhere} ORDER BY views DESC, posts DESC LIMIT " . ($pagesize * 3);
    $list = db_sql_find($sql);

    // 安全: 热门接口同样不能越过版块读权限（accesson/付费版块），过滤后截取请求的条数
    if (!empty($list)) {
        $list = array_values(array_filter($list, function ($row) use ($threadUid, $threadGid) {
            return ApiContentService::canReadThread($row, $threadUid, $threadGid);
        }));
        $list = array_slice($list, 0, $pagesize);
        foreach ($list as &$item) unset($item['is_deleted'], $item['audit_status']);
        unset($item);
    }

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
                if (!$thread || !ApiContentService::canReadThread($thread, $threadUid, $threadGid)) {
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
                // 封禁检查：禁言/禁止访问/锁定用户不能编辑主题（与 Web 编辑回帖前检查对齐）
                api_check_ban_scene(intval($authUser['uid']), intval($authUser['gid']), 'post');
                $update = [];
                // 安全: 标题只允许纯文本，与 Web route/thread.php 相同必须 strip_tags，
                // 模板层对 subject 是裸输出约定，API 存原始 HTML 会形成全站存储型 XSS
                $subject = trim(strip_tags(param('subject', '', FALSE)));
                if (!empty($subject) && $subject !== (string)$thread['subject']) {
                    // 安全: 与 Web 编辑(route/post.php)对齐——长度 ≤80、过敏感词检查，防止经 API 改成敏感标题仍保持过审
                    if (mb_strlen($subject, 'UTF-8') > 80) {
                        ApiResponse::validationError('subject_max_length: 80');
                    }
                    if (!class_exists('SensitiveWordFilter')) {
                        include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
                    }
                    $subject_check = SensitiveWordFilter::content_check($subject, SensitiveWordFilter::TYPE_SENSITIVE);
                    if (!$subject_check['pass']) {
                        ApiResponse::validationError('subject contains sensitive word: ' . implode('、', $subject_check['matched_keywords']));
                    }
                    $update['subject'] = $subject;
                }
                if (!empty($update)) {
                    $threadService->updateThread($tid, $update);
                    // 与 Web 编辑一致：被驳回的帖子编辑后重新进入待审队列
                    if (intval($thread['audit_status']) === 2) {
                        if (!class_exists('AuditService')) {
                            include_once APP_PATH . 'lib/security/AuditService.php';
                        }
                        $resubmit = AuditService::resubmit('thread', $tid, intval($authUser['uid']));
                        if (empty($resubmit['ok'])) {
                            ApiResponse::error(409, !empty($resubmit['message']) ? $resubmit['message'] : 'resubmit failed');
                        }
                    }
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
                        // 安全: 无版块读权限的帖子不可互动（点赞），与单条读取同一权限口径
                        if (!$thread || !ApiContentService::canReadThread($thread, $threadUid, $threadGid)) {
                            ApiResponse::notFound('Thread not found');
                        }
                        $threadService->likeThread($tid, intval($authUser['uid']));
                        ApiResponse::success(['liked' => true]);
                        break;

                    case 'DELETE':
                        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                        $thread = $threadService->getThreadById($tid);
                        if (!$thread || !ApiContentService::canReadThread($thread, $threadUid, $threadGid)) {
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
                        // 安全: 无版块读权限的帖子不可收藏，防止经收藏列表回流受限帖
                        if (!$thread || !ApiContentService::canReadThread($thread, $threadUid, $threadGid)) {
                            ApiResponse::notFound('Thread not found');
                        }
                        $threadService->favoriteThread($tid, intval($authUser['uid']));
                        ApiResponse::success(['favorited' => true]);
                        break;

                    case 'DELETE':
                        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
                        $thread = $threadService->getThreadById($tid);
                        if (!$thread || !ApiContentService::canReadThread($thread, $threadUid, $threadGid)) {
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
                // 安全: 举报目标同样要求可读（对受限版块帖子无需提供举报入口）
                if (!$thread || !ApiContentService::canReadThread($thread, $threadUid, $threadGid)) {
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
                $list = array_filter($list, static function ($row) use ($threadUid, $threadGid) {
                    return ApiContentService::canReadThread($row, $threadUid, $threadGid);
                });
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

            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

            $cond = ['is_deleted' => 0];
            if (!$threadIsAdmin) {
                $allowedFids = array_keys(forum_list_access_filter($GLOBALS['forumlist'], $threadGid, 'allowread'));
                if (empty($allowedFids)) ApiResponse::success(paginateResult([], $page, $pagesize, 0));
                $cond['fid'] = array_map('intval', $allowedFids);
            }
            ApiResponse::filterByAuditStatus($cond, $threadGid, $threadUid);

            $fid = intval($_GET['fid'] ?? 0);
            if ($fid > 0) {
                if (!forum_access_user($fid, $threadGid, 'allowread')) ApiResponse::forbidden('No permission to access this forum');
                $cond['fid'] = $fid;
            }

            $uid = intval($_GET['uid'] ?? 0);
            if ($uid > 0) {
                $cond['uid'] = $uid;
            }

            $keyword = trim((string)($_GET['keyword'] ?? ''));
            if ($keyword !== '') $cond['subject'] = ['LIKE' => '%' . xn_like_escape($keyword) . '%'];

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
            // 封禁检查：禁言/禁止访问/锁定用户不能发帖（与 Web route/thread.php 对齐）
            api_check_ban_scene(intval($authUser['uid']), intval($authUser['gid']), 'post');
            $fid = param('fid', 0);
            // 安全: 与 Web route/thread.php 对齐，标题 strip_tags 后只存纯文本（见 PUT 分支同注释）
            $subject = trim(strip_tags(param('subject', '', FALSE)));
            $message = param('message', '', false);
            $attach_keys = param('attach_keys', '');
            if (empty($fid) || empty($subject) || empty($message)) {
                ApiResponse::validationError('fid, subject and message are required');
            }
            // 与 Web 创建对齐：标题长度上下限走后台安全配置（防超长被 char(128) 静默截断切断多字节）
            if (!class_exists('SecurityConfigService')) {
                include_once APP_PATH . 'lib/security/SecurityConfigService.php';
            }
            $_subject_min = intval(SecurityConfigService::get('security_subject_min_length', 2));
            $_subject_max = intval(SecurityConfigService::get('security_subject_max_length', 128));
            $_subject_len = mb_strlen($subject, 'UTF-8');
            if ($_subject_min > 0 && $_subject_len < $_subject_min) {
                ApiResponse::validationError("subject too short, min {$_subject_min}");
            }
            if ($_subject_max > 0 && $_subject_len > $_subject_max) {
                ApiResponse::validationError("subject too long, max {$_subject_max}");
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
            if ($skipAudit) {
                $auditStatus = 1;
            } else {
                // 使用 AuditService 三级审核规则（版块级 + 用户组级 + 敏感词）
                // 设置全局 $gid 供 PermissionService 使用
                $GLOBALS['gid'] = intval($authUser['gid']);
                if (!class_exists('AuditService')) {
                    include_once APP_PATH . 'lib/security/AuditService.php';
                }
                if (!class_exists('PermissionService')) {
                    include_once APP_PATH . 'lib/PermissionService.php';
                }
                $needAudit = AuditService::need_audit($fid, intval($authUser['gid']), $subject, $message);
                $auditStatus = $needAudit ? 0 : 1;
            }

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
            // ponytail: API 发帖是程序构造内容场景，传 skip_attach_assoc=true 跳过 thread_create 内部的 attach_assoc_post
            // （内部调 sess_save 会触发 session_encode 警告，且 API 模式 session 可能未启动；附件由下方 api_attach_assoc_post 处理）
            $tid = thread_create($arr, $pid, array('skip_attach_assoc' => true));
            if ($tid === FALSE || $tid <= 0) {
                ApiResponse::error(500, 'Failed to create thread');
            }

            // 关联附件（如果有 attach_keys）
            // ponytail: api_attach_assoc_post 内部已同步更新 message/message_fmt/images/videos/files
            $attach_info = array('images' => 0, 'videos' => 0, 'files' => 0);
            if (!empty($attach_keys)) {
                $attach_info = api_attach_assoc_post($pid, $tid, $attach_keys, $message);
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
