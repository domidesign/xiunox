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
$postAuthToken = ApiAuthService::getBearerToken();
$postAuthUser = $postAuthToken ? $apiAuth->validateAccessToken($postAuthToken) : null;
$postIsAdmin = $postAuthUser && in_array(intval($postAuthUser['gid']), [1, 2], true);
$postGid = $postAuthUser ? intval($postAuthUser['gid']) : 0;
$postUid = $postAuthUser ? intval($postAuthUser['uid']) : 0;

$id = intval($segments[1] ?? 0);
$isBatch = ($segments[1] ?? '') === 'batch';

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

switch ($method) {
    case 'GET':
        if ($id > 0) {
            $post = $postService->getPostById($id);
            if (!$post) {
                ApiResponse::notFound('Post not found');
            }
            // 校验版块读权限（post 表无 fid，通过 tid 读取 thread 获取 fid）
            $_thread = thread__read(intval($post['tid']));
            $_fid = $_thread ? intval($_thread['fid']) : 0;
            if ($_fid > 0 && !forum_access_user($_fid, $postGid, 'allowread')) {
                ApiResponse::forbidden('No permission to access this forum');
            }
            // 非管理员、非作者不可查看未审核通过的回帖
            if (!$postIsAdmin && intval($post['audit_status']) !== 1 && intval($post['uid']) !== intval($postAuthUser['uid'] ?? 0)) {
                ApiResponse::notFound('Post not found');
            }
            $fields = $_GET['fields'] ?? '';
            $result = filterFields($post, $fields);
            ApiResponse::success($result);
        } else {
            $tid = intval($_GET['tid'] ?? 0);
            $uid = intval($_GET['uid'] ?? 0);
            $page = intval($_GET['page'] ?? 1);
            $pagesize = intval($_GET['pagesize'] ?? 20);
            $fields = $_GET['fields'] ?? '';

            if ($tid > 0) {
                // 校验版块读权限（tid 查询返回特定版块的帖子）
                $_thread = thread__read($tid);
                if (empty($_thread)) {
                    ApiResponse::notFound('Thread not found');
                }
                if (!forum_access_user(intval($_thread['fid']), $postGid, 'allowread')) {
                    ApiResponse::forbidden('No permission to access this forum');
                }
                $cond = ['tid' => $tid];
                ApiResponse::filterByAuditStatus($cond, $postGid, $postUid);
                $list = $db->find('post', $cond, ['pid' => 1], $page, $pagesize, 'pid');
                $total = $db->count('post', $cond);
            } else {
                // uid/无条件查询：非管理员按版块读权限过滤（post 表无 fid，JOIN thread）
                if ($postIsAdmin) {
                    $cond = $uid > 0 ? ['uid' => $uid] : [];
                    ApiResponse::filterByAuditStatus($cond, $postGid, $postUid);
                    $list = $db->find('post', $cond, [], $page, $pagesize, 'pid');
                    $total = $db->count('post', $cond);
                } else {
                    $accessible_fids = array_keys(forum_list_access_filter($GLOBALS['forumlist'], $postGid, 'allowread'));
                    if (empty($accessible_fids)) {
                        $list = [];
                        $total = 0;
                    } else {
                        $fid_in = implode(',', array_map('intval', $accessible_fids));
                        $offset = ($page - 1) * $pagesize;
                        $uid_cond = $uid > 0 ? ' AND p.uid=' . intval($uid) : '';
                        // 联表查询，db_find 不支持 JOIN，保留 db_sql_find
                        $sql = "SELECT p.* FROM " . $db->table('post') . " p INNER JOIN " . $db->table('thread') . " t ON p.tid=t.tid WHERE p.audit_status=1 AND t.fid IN ({$fid_in}){$uid_cond} ORDER BY p.pid DESC LIMIT {$offset},{$pagesize}";
                        $list = db_sql_find($sql) ?: [];
                        $count_sql = "SELECT COUNT(*) AS cnt FROM " . $db->table('post') . " p INNER JOIN " . $db->table('thread') . " t ON p.tid=t.tid WHERE p.audit_status=1 AND t.fid IN ({$fid_in}){$uid_cond}";
                        $count_row = db_sql_find_one($count_sql);
                        $total = $count_row ? intval($count_row['cnt']) : 0;
                    }
                }
            }

            if (!empty($fields)) {
                $list = filterFields($list, $fields);
            }

            ApiResponse::success(paginateResult($list, $page, $pagesize, $total));
        }
        break;

    case 'POST':
        // 点赞：POST /api/v1/post/{pid}/like
        if ($id > 0 && ($segments[2] ?? '') === 'like') {
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            $post = post__read($id);
            if (!$post) {
                ApiResponse::notFound('Post not found');
            }
            $uid = intval($authUser['uid']);
            $tid = intval($post['tid']);
            // 检查是否已点赞
            $existing = post_like_read($uid, $id);
            if ($existing) {
                ApiResponse::error(409, 'Already liked');
            }
            post_like_create($uid, $tid, $id);
            // 重新读取帖子获取最新点赞数
            $post = post__read($id);
            ApiResponse::success(['liked' => true, 'count' => intval($post['likes'] ?? 0)]);
        }

        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
        if (!$authUser) {
            ApiResponse::unauthorized();
        }
        $tid = param('tid', 0);
        $message = param('message', '', false);
        $attach_keys = param('attach_keys', '');
        if (empty($tid) || empty($message)) {
            ApiResponse::validationError('tid and message are required');
        }
        $thread = $threadService->getThreadById($tid);
        if (!$thread) {
            ApiResponse::notFound('Thread not found');
        }
        // 校验版块回帖权限（allowpost）
        if (!forum_access_user(intval($thread['fid']), intval($authUser['gid']), 'allowpost')) {
            ApiResponse::forbidden('No permission to post in this forum');
        }

        // ===== 验证码能力开关（Task 2.4）=====
        global $apiApp, $apiAppServerAuth, $apiAuth;
        $skipCaptcha = $apiAppServerAuth && $apiAuth->checkAppCapability($apiApp, 'skip_captcha');
        if (!$skipCaptcha) {
            if (!class_exists('CaptchaService')) {
                include_once APP_PATH . 'lib/security/CaptchaService.php';
            }
            $gid = intval($authUser['gid'] ?? 0);
            if (CaptchaService::is_enabled('reply', $gid)) {
                $captchaCode = param('captcha_code', '', false);
                if (!CaptchaService::verify('reply', $captchaCode, $gid)) {
                    ApiResponse::error(422, lang('captcha_error'));
                }
            }
        }

        // ===== 审核能力开关（Task 2.5）=====
        $skipAudit = $apiAppServerAuth && $apiAuth->checkAppCapability($apiApp, 'skip_audit');
        $auditStatus = $skipAudit ? 1 : (in_array(intval($authUser['gid']), [1, 2]) ? 1 : 0);

        // ponytail: 改用核心 post_create()，复用 post_message_fmt() 生成 message_fmt、
        // 楼中楼 post_quote() 引用拼接、帖子/用户计数更新、缓存失效等完整逻辑。
        // PostService::createPost() 只做 db_insert，跳过上述全部处理导致内容不显示。
        if (!function_exists('post_create')) {
            include_once APP_PATH . 'model/post.func.php';
        }
        $pid = post_create([
            'tid' => $tid,
            'uid' => intval($authUser['uid']),
            'isfirst' => 0,
            'create_date' => time(),
            'userip' => ip2long($ip),
            'message' => $message,
            'doctype' => param('doctype', 1),
            'quotepid' => param('quotepid', 0),
            'audit_status' => $auditStatus,
        ], intval($thread['fid']), intval($authUser['gid']), ['skip_attach_assoc' => true]);
        if ($pid <= 0) {
            ApiResponse::error(500, 'Failed to create post');
        }

        // 关联附件（如果有 attach_keys）
        $attach_info = array('images' => 0, 'videos' => 0, 'files' => 0);
        if (!empty($attach_keys)) {
            $attach_info = api_attach_assoc_post($pid, $tid, $attach_keys, $message);
            $original_message = param('message', '', false);
            if ($message !== $original_message) {
                post__update($pid, array('message' => $message));
            }
        }

        ApiResponse::success([
            'pid' => $pid,
            'images' => $attach_info['images'],
            'videos' => $attach_info['videos'],
            'files' => $attach_info['files'],
        ], 'Created');
        break;

    case 'PUT':
        if ($id <= 0) {
            ApiResponse::validationError('Post ID is required');
        }
        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
        if (!$authUser) {
            ApiResponse::unauthorized();
        }
        $post = $postService->getPostById($id);
        if (!$post) {
            ApiResponse::notFound('Post not found');
        }
        if (intval($post['uid']) !== intval($authUser['uid']) && intval($authUser['gid']) !== 1) {
            ApiResponse::forbidden();
        }
        $update = [];
        $message = param('message', '', false);
        if (!empty($message)) {
            $update['message'] = $message;
            $update['doctype'] = param('doctype', 0);
        }
        if (!empty($update)) {
            // ponytail: 改用核心 post_update()，内部调 post_message_fmt() 重新生成 message_fmt +
            // 失效回帖列表缓存。PostService::updatePost() 只做 db_update 不处理格式转换。
            if (!function_exists('post_update')) {
                include_once APP_PATH . 'model/post.func.php';
            }
            post_update($id, $update);
        }
        ApiResponse::success($postService->getPostById($id));
        break;

    case 'DELETE':
        // 取消点赞：DELETE /api/v1/post/{pid}/like
        if (!$isBatch && $id > 0 && ($segments[2] ?? '') === 'like') {
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            $post = post__read($id);
            if (!$post) {
                ApiResponse::notFound('Post not found');
            }
            $uid = intval($authUser['uid']);
            $tid = intval($post['tid']);
            // 检查是否已点赞
            $existing = post_like_read($uid, $id);
            if (!$existing) {
                ApiResponse::error(409, 'Not liked yet');
            }
            post_like_delete($uid, $tid, $id);
            // 重新读取帖子获取最新点赞数
            $post = post__read($id);
            ApiResponse::success(['liked' => false, 'count' => intval($post['likes'] ?? 0)]);
        }

        if ($isBatch) {
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            if (intval($authUser['gid']) !== 1) {
                ApiResponse::forbidden();
            }
            $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
            $pids = $jsonInput['pids'] ?? [];
            if (!is_array($pids) || empty($pids)) {
                ApiResponse::validationError('pids must be a non-empty array');
            }
            $deleted = $postService->batchDelete($pids);
            ApiResponse::success(['deleted' => $deleted]);
        } else {
            if ($id <= 0) {
                ApiResponse::validationError('Post ID is required');
            }
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            $post = $postService->getPostById($id);
            if (!$post) {
                ApiResponse::notFound('Post not found');
            }
            if (intval($post['uid']) !== intval($authUser['uid']) && intval($authUser['gid']) !== 1) {
                ApiResponse::forbidden();
            }
            $postService->deletePost($id);
            ApiResponse::success(null, 'Deleted');
        }
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
