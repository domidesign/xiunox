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

include_once APP_PATH . 'lib/ApiContentService.php';
if (!function_exists('forum_access_user')) include_once APP_PATH . 'model/forum_access.func.php';
if (empty($GLOBALS['forumlist'])) $GLOBALS['forumlist'] = forum_list_cache();
if (empty($GLOBALS['grouplist'])) $GLOBALS['grouplist'] = group_list_cache();
$searchFids = array_map('intval', array_keys(forum_list_access_filter($GLOBALS['forumlist'], $searchGid, 'allowread')));
if (empty($searchFids) && in_array($type, ['thread', 'post'], true)) ApiResponse::success(paginateResult([], $page, $pagesize, 0));

$like = '%' . xn_like_escape($q) . '%';

switch ($type) {
    case 'thread':
        $cond = ['subject' => ['LIKE' => $like], 'is_deleted' => 0, 'fid' => $searchFids];
        ApiResponse::filterByAuditStatus($cond, $searchGid, $searchUid);
        $list = $db->find('thread', $cond, ['tid' => -1], $page, $pagesize, 'tid');
        $total = $db->count('thread', $cond);
        break;

    case 'post':
        // Search only public body text; hidden posts are excluded before counts/pagination.
        // 保留 db_sql_find: post requires a thread JOIN for visibility and private-body exclusion.
        $fidSql = implode(',', $searchFids);
        $likeSql = $db->quote($like);
        $offset = ($page - 1) * $pagesize;
        $where = "p.audit_status=1 AND p.is_deleted=0 AND t.audit_status=1 AND t.is_deleted=0 AND t.fid IN ($fidSql) AND p.message LIKE '$likeSql' AND LOWER(p.message) NOT LIKE '%hidden%'";
        $list = db_sql_find("SELECT p.* FROM " . $db->table('post') . " p JOIN " . $db->table('thread') . " t ON t.tid=p.tid WHERE $where ORDER BY p.pid DESC LIMIT $offset,$pagesize") ?: [];
        $count = db_sql_find_one("SELECT COUNT(*) AS cnt FROM " . $db->table('post') . " p JOIN " . $db->table('thread') . " t ON t.tid=p.tid WHERE $where");
        $total = intval($count['cnt'] ?? 0);
        $list = array_values(array_filter(array_map(static function ($post) use ($searchUid, $searchGid) {
            return ApiContentService::post($post, $searchUid, $searchGid);
        }, $list)));
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
