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
$forumAuthToken = ApiAuthService::getBearerToken();
$forumAuthUser = $forumAuthToken ? $apiAuth->validateAccessToken($forumAuthToken) : null;
$forumIsAdmin = $forumAuthUser && in_array(intval($forumAuthUser['gid']), [1, 2], true);
$forumGid = $forumAuthUser ? intval($forumAuthUser['gid']) : 0;

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$pagesize = isset($_GET['pagesize']) ? max(1, min(100, intval($_GET['pagesize']))) : 20;
$fields = isset($_GET['fields']) ? $_GET['fields'] : '';

switch ($method) {
    case 'GET':
        // 版块树形结构
        if (isset($segments[1]) && $segments[1] === 'tree') {
            $tree = $forumService->getForumTree();
            // 非管理员过滤无权限版块
            if (!$forumIsAdmin && function_exists('forum_access_user') && !empty($tree)) {
                $tree = array_filter($tree, function($f) use ($forumGid) {
                    $fid = intval($f['fid'] ?? 0);
                    if (empty($f['accesson'])) return true;
                    return forum_access_user($fid, $forumGid, 'allowread');
                });
                $tree = array_values($tree);
            }
            ApiResponse::success($tree);
            break;
        }
        // 支持 ids 参数获取多个版块
        $idsParam = $_GET['ids'] ?? '';
        if (!empty($idsParam)) {
            $ids = is_string($idsParam) ? array_map('trim', explode(',', $idsParam)) : $idsParam;
            $list = $forumService->getForumsByIds($ids);
            // 非管理员过滤无权限版块
            if (!$forumIsAdmin && function_exists('forum_access_user') && !empty($list)) {
                $list = array_filter($list, function($f) use ($forumGid) {
                    $fid = intval($f['fid'] ?? 0);
                    if (empty($f['accesson'])) return true;
                    return forum_access_user($fid, $forumGid, 'allowread');
                });
                $list = array_values($list);
            }
            $result = ['list' => $list, 'total' => count($list)];
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        } elseif (!isset($segments[1])) {
            $list = $forumService->getForumList();
            // 非管理员过滤无权限版块
            if (!$forumIsAdmin && function_exists('forum_access_user') && !empty($list)) {
                $list = array_filter($list, function($f) use ($forumGid) {
                    $fid = intval($f['fid'] ?? 0);
                    if (empty($f['accesson'])) return true;
                    return forum_access_user($fid, $forumGid, 'allowread');
                });
                $list = array_values($list);
            }
            $total = count($list);
            $offset = ($page - 1) * $pagesize;
            $paginated = array_slice($list, $offset, $pagesize);
            $result = paginateResult($paginated, $page, $pagesize, $total);
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && !isset($segments[2])) {
            $fid = intval($segments[1]);
            $forum = $forumService->getForumById($fid);
            if (!$forum) {
                ApiResponse::notFound('Forum not found');
            }
            // 非管理员检查版块访问权限
            if (!$forumIsAdmin && !empty($forum['accesson']) && function_exists('forum_access_user') && !forum_access_user($fid, $forumGid, 'allowread')) {
                ApiResponse::forbidden('No access to this forum');
            }
            if (!empty($fields)) {
                $forum = filterFields($forum, $fields);
            }
            ApiResponse::success($forum);
        } elseif (isset($segments[2]) && $segments[2] === 'threads') {
            $fid = intval($segments[1]);
            // 非管理员检查版块访问权限
            $forum = $forumService->getForumById($fid);
            if (!$forum) {
                ApiResponse::notFound('Forum not found');
            }
            if (!$forumIsAdmin && !empty($forum['accesson']) && function_exists('forum_access_user') && !forum_access_user($fid, $forumGid, 'allowread')) {
                ApiResponse::forbidden('No access to this forum');
            }
            // 构建审核条件
            $forumAuditCond = $forumIsAdmin ? [] : ['audit_status' => 1];
            $cond = array_merge(['fid' => $fid], $forumAuditCond);
            // ponytail: getThreadList 签名为 (cond, orderby=array, page, pagesize)
            // orderby 为关联数组 [字段 => 1升序/-1降序]，与 thread.php:329 调用方式一致
            $orderDir = (isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC') ? 1 : -1;
            $orderby = ['tid' => $orderDir];
            $list = $threadService->getThreadList($cond, $orderby, $page, $pagesize);
            $total = $db->count('thread', $cond);
            $result = paginateResult($list, $page, $pagesize, $total);
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        } else {
            ApiResponse::notFound('Endpoint not found');
        }
        break;

    case 'POST':
        // 关注版块
        if (isset($segments[1]) && $segments[1] === 'follow') {
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            $fid = intval($_POST['fid'] ?? 0);
            if (empty($fid)) {
                ApiResponse::error(400, '版块ID不能为空');
            }
            $result = $forumService->followForum(intval($authUser['uid']), $fid);
            if ($result['code'] !== 0) {
                ApiResponse::error(400, $result['msg']);
            }
            ApiResponse::success($result['data']);
            break;
        }

        // 取消关注版块
        if (isset($segments[1]) && $segments[1] === 'unfollow') {
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            $fid = intval($_POST['fid'] ?? 0);
            if (empty($fid)) {
                ApiResponse::error(400, '版块ID不能为空');
            }
            $result = $forumService->unfollowForum(intval($authUser['uid']), $fid);
            if ($result['code'] !== 0) {
                ApiResponse::error(400, $result['msg']);
            }
            ApiResponse::success($result['data']);
            break;
        }

        ApiResponse::notFound('Endpoint not found');
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
