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

$authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());

$subResource = $segments[1] ?? '';

switch ($method) {
    case 'GET':
        if ($subResource === 'unread') {
            $count = $notificationService->getUnreadCount(intval($authUser['uid']));
            ApiResponse::success(['count' => $count]);
        } else {
            $page = intval($_GET['page'] ?? 1);
            $pagesize = intval($_GET['pagesize'] ?? 20);
            $type = $_GET['type'] ?? '';
            $cond = ['uid' => intval($authUser['uid'])];
            if (!empty($type)) {
                $cond['type'] = $type;
            }
            $list = $notificationService->getList(intval($authUser['uid']), $page, $pagesize);
            $total = $db->count('notification', $cond);
            ApiResponse::success(paginateResult($list, $page, $pagesize, $total));
        }
        break;

    case 'PUT':
        if ($subResource === 'read-all') {
            $notificationService->markAllAsRead(intval($authUser['uid']));
            ApiResponse::success(null, 'All marked as read');
        } elseif (isset($segments[2]) && $segments[2] === 'read' && is_numeric($subResource)) {
            $id = intval($subResource);
            $notificationService->markAsRead($id);
            ApiResponse::success(null, 'Marked as read');
        } else {
            ApiResponse::notFound();
        }
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
