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

$q = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'thread';
$page = intval($_GET['page'] ?? 1);
$pagesize = intval($_GET['pagesize'] ?? 20);

if (empty($q)) {
    ApiResponse::validationError('Search query is required (?q=)');
}

if (mb_strlen($q) < 2) {
    ApiResponse::validationError('Search query must be at least 2 characters');
}

$page = max(1, $page);
$pagesize = min(50, max(1, $pagesize));

// 搜索需登录检查
$searchAuthToken = ApiAuthService::getBearerToken();
$searchAuthUser = $searchAuthToken ? $apiAuth->validateAccessToken($searchAuthToken) : null;
if (!empty($conf['security_search_require_login']) && !$searchAuthUser) {
    ApiResponse::unauthorized();
}

// 审核条件：非管理员只搜 audit_status=1
$searchIsAdmin = $searchAuthUser && in_array(intval($searchAuthUser['gid']), [1, 2], true);
$searchGid = $searchAuthUser ? intval($searchAuthUser['gid']) : 0;
$searchUid = $searchAuthUser ? intval($searchAuthUser['uid']) : 0;

$like = '%' . $q . '%';

switch ($type) {
    case 'thread':
        $cond = ['subject' => ['LIKE' => $like]];
        ApiResponse::filterByAuditStatus($cond, $searchGid, $searchUid);
        $list = $db->find('thread', $cond, ['tid' => -1], $page, $pagesize, 'tid');
        $total = $db->count('thread', $cond);
        break;

    case 'post':
        $cond = ['message' => ['LIKE' => $like]];
        ApiResponse::filterByAuditStatus($cond, $searchGid, $searchUid);
        $list = $db->find('post', $cond, ['pid' => -1], $page, $pagesize, 'pid');
        $total = $db->count('post', $cond);
        break;

    case 'user':
        if (!$searchAuthUser) {
            ApiResponse::unauthorized();
        }
        $list = $db->find('user', ['username' => ['LIKE' => $like]], ['uid' => -1], $page, $pagesize, 'uid');
        $total = $db->count('user', ['username' => ['LIKE' => $like]]);
        foreach ($list as &$u) {
            unset($u['password'], $u['salt'], $u['password_hash'], $u['login_attempts'], $u['banned_until'], $u['last_login_ip'], $u['last_login_time'], $u['ai_config'], $u['email'], $u['create_ip']);
        }
        unset($u);
        break;

    default:
        ApiResponse::validationError('Unknown search type. Use thread, post or user');
}

ApiResponse::success(paginateResult($list, $page, $pagesize, $total));
