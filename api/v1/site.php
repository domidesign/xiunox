<?php

$subResource = $segments[1] ?? '';

switch ($method) {
    case 'GET':
        if ($subResource === 'stats') {
            $threadCount = $db->count('thread');
            $postCount = $db->count('post');
            $userCount = $db->count('user');
            $forumCount = $db->count('forum');
            $todayStart = strtotime('today');
            $todayThreads = $db->count('thread', ['create_date' => ['>=' => $todayStart]]);
            $todayPosts = $db->count('post', ['create_date' => ['>=' => $todayStart]]);
            $todayUsers = $db->count('user', ['create_date' => ['>=' => $todayStart]]);

            ApiResponse::success([
                'threads' => $threadCount,
                'posts' => $postCount,
                'users' => $userCount,
                'forums' => $forumCount,
                'today_threads' => $todayThreads,
                'today_posts' => $todayPosts,
                'today_users' => $todayUsers,
            ]);
        } else {
            ApiResponse::success([
                'name' => $conf['sitename'] ?? '',
                'brief' => $conf['sitebrief'] ?? '',
                'url' => http_url_path(),
                'api_version' => '1.0',
                'bbs_version' => $conf['version'] ?? XIUNOX_VERSION,
                'lang' => $conf['lang'] ?? 'zh-cn',
                'timezone' => $conf['timezone'] ?? 'Asia/Shanghai',
            ]);
        }
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
