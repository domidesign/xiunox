<?php

// 版主操作 API

// ponytail: 用 function_exists 守卫避免与 model.inc.php _include 编译版重复声明（fatal error）
if (!function_exists('modlog_create')) {
    include_once APP_PATH . 'model/modlog.func.php';
}
if (!function_exists('thread_find_by_tids')) {
    include_once APP_PATH . 'model/thread.func.php';
}
if (!function_exists('thread_top_change')) {
    include_once APP_PATH . 'model/thread_top.func.php';
}
if (!function_exists('forum_read')) {
    include_once APP_PATH . 'model/forum.func.php';
}
if (!function_exists('forum_access_mod')) {
    include_once APP_PATH . 'model/forum_access.func.php';
}
if (!function_exists('group_list_cache')) {
    include_once APP_PATH . 'model/group.func.php';
}

// 所有版主操作必须登录
$token = ApiAuthService::getBearerToken();
$authUser = $token ? $apiAuth->validateAccessToken($token) : null;
if (!$authUser) {
    ApiResponse::unauthorized();
}

$uid = intval($authUser['uid']);
$gid = intval($authUser['gid']);

// forum_access_mod 依赖全局变量
$GLOBALS['uid'] = $uid;
$GLOBALS['grouplist'] = group_list_cache();
$GLOBALS['forumlist'] = forum_list_cache();

// 仅允许 POST
if ($method !== 'POST') {
    ApiResponse::error(405, 'Method not allowed');
}

// 解析操作类型
$action = $segments[1] ?? '';
if (!in_array($action, ['top', 'close', 'move', 'delete'], true)) {
    ApiResponse::notFound('Unknown mod action: ' . $action);
}

// 获取公共参数 tidarr
$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$tidarr = $jsonInput['tidarr'] ?? param('tidarr', []);
if (empty($tidarr) || !is_array($tidarr)) {
    ApiResponse::validationError('tidarr must be a non-empty array');
}
$tidarr = array_map('intval', $tidarr);
$tidarr = array_filter($tidarr, function($v) { return $v > 0; });
if (empty($tidarr)) {
    ApiResponse::validationError('tidarr must contain valid thread ids');
}

// 查询所有帖子
$threadlist = thread_find_by_tids($tidarr);
if (empty($threadlist)) {
    ApiResponse::notFound('Threads not found');
}

// 逐帖检查权限并执行操作
$success = [];
$denied = [];
$failed = [];

foreach ($threadlist as $thread) {
    $tid = intval($thread['tid']);
    $fid = intval($thread['fid']);

    switch ($action) {
        case 'top':
            // 置顶/取消置顶
            if (!forum_access_mod($fid, $gid, 'allowtop')) {
                $denied[] = $tid;
                break;
            }
            $top = intval($jsonInput['top'] ?? param('top', 0));
            if (!in_array($top, [0, 1, 2, 3], true)) {
                ApiResponse::validationError('top must be 0, 1, 2 or 3');
            }
            $r = thread_top_change($tid, $top);
            if ($r !== FALSE) {
                modlog_create(array(
                    'uid' => $uid,
                    'tid' => $thread['tid'],
                    'pid' => $thread['firstpid'],
                    'subject' => $thread['subject'],
                    'comment' => '',
                    'create_date' => time(),
                    'action' => 'top',
                ));
                $success[] = $tid;
            } else {
                $failed[] = $tid;
            }
            break;

        case 'close':
            // 关闭/打开帖子
            if (!forum_access_mod($fid, $gid, 'allowupdate')) {
                $denied[] = $tid;
                break;
            }
            $close = intval($jsonInput['close'] ?? param('close', 0));
            if (!in_array($close, [0, 1], true)) {
                ApiResponse::validationError('close must be 0 or 1');
            }
            $r = thread_update($tid, array('closed' => $close));
            if ($r !== FALSE) {
                modlog_create(array(
                    'uid' => $uid,
                    'tid' => $thread['tid'],
                    'pid' => $thread['firstpid'],
                    'subject' => $thread['subject'],
                    'comment' => '',
                    'create_date' => time(),
                    'action' => 'close',
                ));
                $success[] = $tid;
            } else {
                $failed[] = $tid;
            }
            break;

        case 'move':
            // 移动帖子到其他版块
            if (!forum_access_mod($fid, $gid, 'allowmove')) {
                $denied[] = $tid;
                break;
            }
            $newfid = intval($jsonInput['newfid'] ?? param('newfid', 0));
            if ($newfid <= 0) {
                ApiResponse::validationError('newfid is required and must be positive');
            }
            $targetForum = forum_read($newfid);
            if (empty($targetForum)) {
                ApiResponse::validationError('Target forum not found');
            }
            $r = thread_update($tid, array('fid' => $newfid));
            if ($r !== FALSE) {
                modlog_create(array(
                    'uid' => $uid,
                    'tid' => $thread['tid'],
                    'pid' => $thread['firstpid'],
                    'subject' => $thread['subject'],
                    'comment' => '',
                    'create_date' => time(),
                    'action' => 'move',
                ));
                $success[] = $tid;
            } else {
                $failed[] = $tid;
            }
            break;

        case 'delete':
            // 删除帖子
            if (!forum_access_mod($fid, $gid, 'allowdelete')) {
                $denied[] = $tid;
                break;
            }
            $r = thread_delete($tid);
            if ($r !== FALSE) {
                modlog_create(array(
                    'uid' => $uid,
                    'tid' => $thread['tid'],
                    'pid' => $thread['firstpid'],
                    'subject' => $thread['subject'],
                    'comment' => '',
                    'create_date' => time(),
                    'action' => 'delete',
                ));
                $success[] = $tid;
            } else {
                $failed[] = $tid;
            }
            break;
    }
}

ApiResponse::success(array(
    'action' => $action,
    'success' => $success,
    'denied' => $denied,
    'failed' => $failed,
));
