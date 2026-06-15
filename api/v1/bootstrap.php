<?php

!defined('API_MODE') AND define('API_MODE', true);

!defined('DEBUG') AND define('DEBUG', 1);
!defined('APP_PATH') AND define('APP_PATH', dirname(__DIR__, 2) . '/');
!defined('XIUNOPHP_PATH') AND define('XIUNOPHP_PATH', APP_PATH . 'xiunophp/');

if (!class_exists('db_mysql')) {
    include APP_PATH . 'xiunophp/xiunophp.php';
}
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}
include APP_PATH . 'lib/ApiResponse.php';
if (!class_exists('ApiAuthService')) {
    include APP_PATH . 'lib/ApiAuthService.php';
}
include APP_PATH . 'service/UserService.php';
include APP_PATH . 'service/ThreadService.php';
include APP_PATH . 'service/PostService.php';
include APP_PATH . 'service/ForumService.php';
include APP_PATH . 'service/AttachmentService.php';
include APP_PATH . 'service/NotificationService.php';
include APP_PATH . 'service/RankService.php';
include APP_PATH . 'lib/RateLimitService.php';

$db = $_SERVER['db'];
if (!$db) {
    ApiResponse::error(500, 'Database not connected');
}

$apiAuth = new ApiAuthService($db, $conf['api_token_expire'] ?? 30);
$userService = new UserService($db);
$threadService = new ThreadService($db);
$postService = new PostService($db);
$forumService = new ForumService($db);
$attachmentService = new AttachmentService($db);
$notificationService = new NotificationService($db);

$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$rateLimitEnabled = ($conf['api_rate_limit'] ?? 1) == 1;
if ($rateLimitEnabled) {
    $earlyAuthUser = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        try {
            $tokenData = $apiAuth->validateAccessToken($m[1]);
            if ($tokenData && isset($tokenData['uid'])) {
                $earlyAuthUser = $userService->getUserById($tokenData['uid']);
            }
        } catch (Exception $e) {}
    }

    if ($earlyAuthUser && intval($earlyAuthUser['gid'] ?? 0) === 1) {
        $rateLimitEnabled = false;
    } else {
        if ($earlyAuthUser) {
            $maxReq = 120;
        } else {
            $maxReq = intval($conf['api_rate_limit_max'] ?? 60);
        }
        $windowSec = intval($conf['api_rate_limit_window'] ?? 60);
        $rateLimit = new RateLimitService($maxReq, $windowSec);
        $rateKey = RateLimitService::getClientKey();
        $remaining = $rateLimit->getRemaining($rateKey);
        header('X-RateLimit-Limit: ' . $maxReq);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $rateLimit->getResetTime($rateKey));
        if (!$rateLimit->check($rateKey)) {
            http_response_code(429);
            header('Retry-After: ' . $rateLimit->getResetTime($rateKey));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => 429, 'msg' => 'Too Many Requests', 'data' => null], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    $allowOrigin = $conf['api_cors_origin'] ?? '*';
    $allowMethods = 'GET, POST, PUT, DELETE, OPTIONS';
    $allowHeaders = 'Content-Type, Authorization, X-CSRF-Token';
    header("Access-Control-Allow-Origin: {$allowOrigin}");
    header("Access-Control-Allow-Methods: {$allowMethods}");
    header("Access-Control-Allow-Headers: {$allowHeaders}");
    header('Access-Control-Max-Age: 86400');
    exit;
}

$allowOrigin = $conf['api_cors_origin'] ?? '*';
if ($allowOrigin !== '') {
    header("Access-Control-Allow-Origin: {$allowOrigin}");
    header('Access-Control-Allow-Credentials: true');
}

$path = parse_url($uri, PHP_URL_PATH);
$path = preg_replace('#^/api/v1#', '', $path);
$path = rtrim($path, '/') ?: '/';

$segments = array_values(array_filter(explode('/', $path)));

// 解析 JSON 请求体，合并到 $_REQUEST 和 $_POST，使 param() 函数可读取
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (is_array($jsonInput)) {
            $_POST = array_merge($_POST, $jsonInput);
            $_REQUEST = array_merge($_REQUEST, $jsonInput);
        }
    }
}

header('Content-Type: application/json; charset=utf-8');

if (empty($segments)) {
    ApiResponse::success(['version' => '1.0', 'endpoints' => ['thread', 'user', 'forum', 'post', 'attach', 'notify', 'search', 'site', 'auth', 'rank', 'credits', 'captcha', 'mod', 'admin']]);
}

$resource = $segments[0];

$apiStartTime = microtime(true);
$apiLogEnabled = ($conf['api_log'] ?? 0) == 1;

register_shutdown_function(function() use ($db, $resource, $method, $ip, $apiStartTime, $apiLogEnabled) {
    if (!$apiLogEnabled) return;
    $duration = round((microtime(true) - $apiStartTime) * 1000);
    try {
        $db->insert('api_log', [
            'resource' => $resource,
            'method' => $method,
            'uid' => intval($_SESSION['uid'] ?? 0),
            'ip' => ip2long($ip),
            'duration' => $duration,
            'create_date' => time(),
        ]);
    } catch (Exception $e) {}
});

$routeFile = null;

if ($segments[0] === 'auth') {
    $routeFile = 'auth.php';
} elseif ($segments[0] === 'user') {
    $routeFile = 'user.php';
} elseif ($segments[0] === 'thread') {
    if (isset($segments[1]) && $segments[1] === 'batch') {
        $routeFile = 'thread.php';
    } elseif (isset($segments[2]) && in_array($segments[2], ['like', 'favorite', 'report', 'announcement'], true)) {
        $routeFile = 'thread.php';
    } else {
        $routeFile = 'thread.php';
    }
} elseif ($segments[0] === 'post') {
    if (isset($segments[1]) && $segments[1] === 'batch') {
        $routeFile = 'post.php';
    } else {
        $routeFile = 'post.php';
    }
} elseif ($segments[0] === 'forum') {
    $routeFile = 'forum.php';
} elseif ($segments[0] === 'notify') {
    if (isset($segments[1]) && $segments[1] === 'read-all') {
        $routeFile = 'notify.php';
    } elseif (isset($segments[2]) && $segments[2] === 'read') {
        $routeFile = 'notify.php';
    } else {
        $routeFile = 'notify.php';
    }
} elseif ($segments[0] === 'credits') {
    $routeFile = 'credits.php';
} elseif ($segments[0] === 'rank') {
    $routeFile = 'rank.php';
} elseif ($segments[0] === 'captcha') {
    $routeFile = 'captcha.php';
} elseif ($segments[0] === 'mod') {
    $routeFile = 'mod.php';
} elseif ($segments[0] === 'admin') {
    $routeFile = 'admin.php';
} elseif ($segments[0] === 'openapi.json') {
    $routeFile = 'openapi.php';
} else {
    switch ($resource) {
        case 'attach':
            $routeFile = 'attach.php';
            break;
        case 'search':
            $routeFile = 'search.php';
            break;
        case 'site':
            $routeFile = 'site.php';
            break;
        default:
            ApiResponse::notFound('Unknown resource: ' . $resource);
    }
}

if ($routeFile !== null) {
    include __DIR__ . '/' . $routeFile;
}

/**
 * API 附件关联函数
 * 将 upload/tmp/ 中的临时文件关联到帖子，更新 images/videos/files 计数
 * @param int $pid 帖子ID（post表）
 * @param int $tid 主题ID（thread表）
 * @param string $attach_keys 逗号分隔的上传key列表
 * @param string $message 帖子内容（引用传递，会替换URL）
 * @return array [images, videos, files] 计数
 */
function api_attach_assoc_post($pid, $tid, $attach_keys, &$message) {
    global $conf, $uid, $time;

    if(empty($attach_keys)) return array('images' => 0, 'videos' => 0, 'files' => 0);

    // 解析 attach_keys
    if(is_string($attach_keys)) {
        $keys = array_filter(array_map('trim', explode(',', $attach_keys)));
    } elseif(is_array($attach_keys)) {
        $keys = $attach_keys;
    } else {
        return array('images' => 0, 'videos' => 0, 'files' => 0);
    }

    if(empty($keys)) return array('images' => 0, 'videos' => 0, 'files' => 0);

    $attach_dir_save_rule = array_value($conf, 'attach_dir_save_rule', 'Ym');
    $images = 0;
    $videos = 0;
    $files = 0;

    foreach($keys as $key) {
        // 读取元数据文件
        $meta_file = $conf['upload_path'].'tmp/'.$key.'.meta.json';
        if(!is_file($meta_file)) continue;

        $meta_json = file_get_contents($meta_file);
        $file = json_decode($meta_json, true);
        if(empty($file) || !is_array($file)) {
            @unlink($meta_file);
            continue;
        }

        // 检查临时文件是否存在
        $tmpfile = $conf['upload_path'].'tmp/'.$key;
        if(!is_file($tmpfile)) {
            @unlink($meta_file);
            continue;
        }

        // 将文件移动到 upload/attach 目录
        $filename = file_name($file['url']);
        $day = date($attach_dir_save_rule, $time);
        $path = $conf['upload_path'].'attach/'.$day;
        $url = $conf['upload_url'].'attach/'.$day;
        !is_dir($path) AND mkdir($path, 0777, TRUE);

        $destfile = $path.'/'.$filename;
        $desturl = $url.'/'.$filename;
        $r = xn_copy($tmpfile, $destfile);
        !$r AND xn_log("api_attach_assoc: xn_copy($tmpfile, $destfile) failed, pid:$pid, tid:$tid", 'php_error');

        // 复制成功后删除临时文件
        if(is_file($destfile) && filesize($destfile) == filesize($tmpfile)) {
            @unlink($tmpfile);
        }

        // 移动缩略图
        $thumb_url = isset($file['thumb_url']) ? $file['thumb_url'] : '';
        if(!empty($thumb_url)) {
            $thumb_relative = str_replace($conf['upload_url'].'tmp/', '', $thumb_url);
            $thumb_src_path = $conf['upload_path'].'tmp/'.$thumb_relative;
            if(is_file($thumb_src_path)) {
                $thumb_dest_dir = $path.'/thumb';
                !is_dir($thumb_dest_dir) AND mkdir($thumb_dest_dir, 0777, TRUE);
                $thumb_filename = file_name($thumb_url);
                $thumb_dest_path = $thumb_dest_dir.'/'.$thumb_filename;
                $thumb_dest_url = $url.'/thumb/'.$thumb_filename;
                $tr = xn_copy($thumb_src_path, $thumb_dest_path);
                if($tr && is_file($thumb_dest_path) && filesize($thumb_dest_path) == filesize($thumb_src_path)) {
                    @unlink($thumb_src_path);
                }
                // 替换 message 中的缩略图 URL
                $message = str_replace($thumb_url, $thumb_dest_url, $message);
            }
        }

        // 创建附件记录
        $arr = array(
            'tid'         => $tid,
            'pid'         => $pid,
            'uid'         => intval($file['uid']),
            'filesize'    => intval($file['filesize']),
            'width'       => intval($file['width']),
            'height'      => intval($file['height']),
            'filename'    => "$day/$filename",
            'orgfilename' => $file['orgfilename'],
            'filetype'    => $file['filetype'],
            'create_date' => $time,
            'comment'     => '',
            'downloads'   => 0,
            'isimage'     => intval($file['isimage']),
        );
        attach_create($arr);

        // 替换 message 中的临时 URL 为正式 URL
        $message = str_replace($file['url'], $desturl, $message);

        // 计数
        if(!empty($file['isimage'])) {
            $images++;
        } elseif(isset($file['filetype']) && $file['filetype'] == 'video') {
            $videos++;
        } else {
            $files++;
        }

        // 删除元数据文件
        @unlink($meta_file);
    }

    // 更新 post 的 message 和 images/videos/files
    $post_update = array(
        'images' => $images,
        'videos' => $videos,
        'files'  => $files,
    );
    // 如果 message 中有 URL 替换，也更新 message
    post__update($pid, $post_update);

    // 如果是首帖，更新 thread 的 images/videos/files
    $post = post__read($pid);
    if(!empty($post) && $post['isfirst']) {
        thread__update($tid, array('images' => $images, 'videos' => $videos, 'files' => $files));
    }

    return array('images' => $images, 'videos' => $videos, 'files' => $files);
}
