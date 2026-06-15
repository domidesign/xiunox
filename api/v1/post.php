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

$id = intval($segments[1] ?? 0);
$isBatch = ($segments[1] ?? '') === 'batch';

switch ($method) {
    case 'GET':
        if ($id > 0) {
            $post = $postService->getPostById($id);
            if (!$post) {
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
                $list = $postService->getPostListByTid($tid, $page, $pagesize);
                $total = $db->count('post', ['tid' => $tid]);
            } elseif ($uid > 0) {
                $list = $postService->getPostListByUid($uid, $page, $pagesize);
                $total = $db->count('post', ['uid' => $uid]);
            } else {
                $list = $postService->getPostList($page, $pagesize);
                $total = $db->count('post');
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
        $pid = $postService->createPost([
            'tid' => $tid,
            'uid' => intval($authUser['uid']),
            'isfirst' => 0,
            'create_date' => time(),
            'userip' => ip2long($ip),
            'message' => $message,
            'doctype' => param('doctype', 1),
            'quotepid' => param('quotepid', 0),
        ]);
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
        if (!empty($message)) $update['message'] = $message;
        if (!empty($update)) {
            $postService->updatePost($id, $update);
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
