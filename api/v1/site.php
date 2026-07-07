<?php

$subResource = $segments[1] ?? '';

switch ($method) {
    case 'GET':
        if ($subResource === 'health') {
            // 健康检查 - 公开端点，不需要鉴权
            include APP_PATH . 'lib/HealthCheckService.php';
            $full = HealthCheckService::runAll();
            // 公开端点仅返回模块级状态汇总，避免暴露表前缀/SMTP/默认账号等敏感细节
            $modules = ['environment', 'config', 'database', 'performance', 'security', 'third_party'];
            $moduleStatus = [];
            foreach ($modules as $m) {
                if (!isset($full[$m])) continue;
                $mFail = $mWarn = $mPass = 0;
                foreach ($full[$m] as $item) {
                    if (!is_array($item) || !isset($item['status'])) continue;
                    switch ($item['status']) {
                        case 'pass': $mPass++; break;
                        case 'warn': $mWarn++; break;
                        case 'fail': $mFail++; break;
                    }
                }
                $moduleStatus[$m] = [
                    'status' => $mFail > 0 ? 'error' : ($mWarn > 0 ? 'warn' : 'ok'),
                    'pass' => $mPass,
                    'warn' => $mWarn,
                    'fail' => $mFail,
                ];
            }
            ApiResponse::success([
                'status' => $full['fail_count'] > 0 ? 'error' : ($full['warn_count'] > 0 ? 'warn' : 'ok'),
                'timestamp' => $full['checked_at'],
                'score' => $full['score'],
                'grade' => $full['grade'],
                'modules' => $moduleStatus,
                'summary' => [
                    'total' => $full['total_checks'],
                    'pass' => $full['pass_count'],
                    'warn' => $full['warn_count'],
                    'fail' => $full['fail_count'],
                    'skip' => $full['skip_count'],
                ],
            ]);
        } elseif ($subResource === 'stats') {
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
