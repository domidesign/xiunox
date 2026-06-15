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

$like = '%' . $q . '%';

switch ($type) {
    case 'thread':
        $list = $db->find('thread', ['subject' => ['LIKE' => $like]], ['tid' => -1], $page, $pagesize, 'tid');
        $total = $db->count('thread', ['subject' => ['LIKE' => $like]]);
        break;

    case 'post':
        $list = $db->find('post', ['message' => ['LIKE' => $like]], ['pid' => -1], $page, $pagesize, 'pid');
        $total = $db->count('post', ['message' => ['LIKE' => $like]]);
        break;

    case 'user':
        $list = $db->find('user', ['username' => ['LIKE' => $like]], ['uid' => -1], $page, $pagesize, 'uid');
        $total = $db->count('user', ['username' => ['LIKE' => $like]]);
        foreach ($list as &$u) {
            unset($u['password'], $u['salt'], $u['password_hash']);
        }
        unset($u);
        break;

    default:
        ApiResponse::validationError('Unknown search type. Use thread, post or user');
}

ApiResponse::success(paginateResult($list, $page, $pagesize, $total));
