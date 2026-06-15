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

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$pagesize = isset($_GET['pagesize']) ? max(1, min(100, intval($_GET['pagesize']))) : 20;
$fields = isset($_GET['fields']) ? $_GET['fields'] : '';

switch ($method) {
    case 'GET':
        // 版块树形结构
        if (isset($segments[1]) && $segments[1] === 'tree') {
            $tree = $forumService->getForumTree();
            ApiResponse::success($tree);
            break;
        }
        // 支持 ids 参数获取多个版块
        $idsParam = $_GET['ids'] ?? '';
        if (!empty($idsParam)) {
            $ids = is_string($idsParam) ? array_map('trim', explode(',', $idsParam)) : $idsParam;
            $list = $forumService->getForumsByIds($ids);
            $result = ['list' => $list, 'total' => count($list)];
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        } elseif (!isset($segments[1])) {
            $list = $forumService->getForumList();
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
            if (!empty($fields)) {
                $forum = filterFields($forum, $fields);
            }
            ApiResponse::success($forum);
        } elseif (isset($segments[2]) && $segments[2] === 'threads') {
            $fid = intval($segments[1]);
            $orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'tid';
            $order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
            $keyword = isset($_GET['keyword']) ? $_GET['keyword'] : '';
            $cond = ['fid' => $fid];
            $list = $threadService->getThreadList($cond, $page, $pagesize, $orderby, $order, $keyword);
            $total = $threadService->getThreadCount($cond, $keyword);
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
                ApiResponse::error('版块ID不能为空');
            }
            $result = $forumService->followForum(intval($authUser['uid']), $fid);
            if ($result['code'] !== 0) {
                ApiResponse::error($result['msg']);
            }
            ApiResponse::success($result['data']);
            break;
        }

        // 取消关注版块
        if (isset($segments[1]) && $segments[1] === 'unfollow') {
            $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
            $fid = intval($_POST['fid'] ?? 0);
            if (empty($fid)) {
                ApiResponse::error('版块ID不能为空');
            }
            $result = $forumService->unfollowForum(intval($authUser['uid']), $fid);
            if ($result['code'] !== 0) {
                ApiResponse::error($result['msg']);
            }
            ApiResponse::success($result['data']);
            break;
        }

        ApiResponse::notFound('Endpoint not found');
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
