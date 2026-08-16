<?php

!defined('API_MODE') AND define('API_MODE', true);

!defined('DEBUG') AND define('DEBUG', 0);
!defined('APP_PATH') AND define('APP_PATH', dirname(__DIR__, 2) . '/');
!defined('XIUNOPHP_PATH') AND define('XIUNOPHP_PATH', APP_PATH . 'xiunophp/');

// xiunophp.php 加载的是 db_pdo_mysql 类（非 db_mysql）；用 defined('IN_CMD') 判断是否已加载
if (!defined('IN_CMD')) {
    include APP_PATH . 'xiunophp/xiunophp.php';
}
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}
include APP_PATH . 'lib/ApiResponse.php';
// ponytail: API 模式不走 index.inc.php，需显式加载 EscapeService（forum_format/thread_format 等核心函数会调用 esc_attr/esc_html）
// 但 nginx 未配置 /api/v1/ 专用 rewrite 时，请求会经根 index.php → index.inc.php（line 10 已 include EscapeService.php）
// 再进入本文件，因此必须用 function_exists 兜底；class_exists('EscapeService') 永远为 false（该文件只定义全局函数，无类）
// 误用 class_exists 会导致 EscapeService.php 被二次 include → "Cannot redeclare function esc_html()" Fatal（已违反 1 次）
if (!function_exists('esc_html')) {
    include APP_PATH . 'lib/EscapeService.php';
}
if (!class_exists('ApiAuthService')) {
    include APP_PATH . 'lib/ApiAuthService.php';
}
if (!class_exists('PluginApiRegistry')) {
    include APP_PATH . 'lib/PluginApiRegistry.php';
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

$apiAuth = new ApiAuthService($db, intval($conf['api_token_expire'] ?? 30), intval($conf['api_access_token_expire'] ?? 2), intval($conf['api_token_absolute_expire'] ?? 90));
$userService = new UserService($db);
$threadService = new ThreadService($db);
$postService = new PostService($db);
$forumService = new ForumService($db);
$attachmentService = new AttachmentService($db);
$notificationService = new NotificationService($db);

// ponytail: API 模式未走 index.inc.php 的语言加载流程，$_SERVER['lang'] 被 xiunophp.php 预设为空数组，
// 需用 empty() 判断（isset 恒为 true 会跳过加载）；_include() 返回编译缓存文件路径，
// 必须用 include 包裹才能拿到 return $lang 的数组（与 index.inc.php 的 include _include(...) 一致）。
// Service 内部 lang() 调用会返回 'lang[key]' 字面量（如 UserBanService::sendNotice 把字面量存入 notify 表）。
// 统一加载前台语言包（含 bbs_common.php + 插件 lang hook 注入的键），开销可接受。
if (empty($_SERVER['lang'])) {
    $_lang_dir = APP_PATH . "lang/{$conf['lang']}";
    $_lang_file = is_file("$_lang_dir/bbs.php") ? include _include(APP_PATH . "lang/{$conf['lang']}/bbs.php") : null;
    if (is_array($_lang_file)) {
        // 积分类型名称动态覆盖（与 index.inc.php 保持一致）
        if (isset($conf['credits_name']) && $conf['credits_name']) {
            $_lang_file['credits_label'] = $conf['credits_name'];
            $_lang_file['admin_credits_type_credits'] = $conf['credits_name'];
        }
        if (isset($conf['golds_name']) && $conf['golds_name']) {
            $_lang_file['golds_label'] = $conf['golds_name'];
            $_lang_file['admin_credits_type_golds'] = $conf['golds_name'];
        }
        if (isset($conf['rmbs_name']) && $conf['rmbs_name']) {
            $_lang_file['rmb_label'] = $conf['rmbs_name'];
            $_lang_file['admin_credits_type_rmbs'] = $conf['rmbs_name'];
        }
        $_SERVER['lang'] = $_lang_file;
    }
    unset($_lang_dir, $_lang_file);
}

$uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/**
 * CORS 响应头处理
 * - api_cors_origin 为 '*' 或空：发送 Allow-Origin: *，不发送 Allow-Credentials
 * - 单个具体域名：发送 Allow-Origin: <域名> + Allow-Credentials: true
 * - 逗号分隔多域名：请求 Origin 命中列表则反射该域名 + Allow-Credentials: true，未命中不发 Allow-Origin
 */
function handleCors($conf, $requestOrigin) {
    $allowOrigin = $conf['api_cors_origin'] ?? '*';
    if ($allowOrigin === '' || $allowOrigin === '*') {
        header('Access-Control-Allow-Origin: *');
        return;
    }
    if (strpos($allowOrigin, ',') === false) {
        header("Access-Control-Allow-Origin: {$allowOrigin}");
        header('Access-Control-Allow-Credentials: true');
        return;
    }
    $domains = array_map('trim', explode(',', $allowOrigin));
    if ($requestOrigin !== '' && in_array($requestOrigin, $domains, true)) {
        header("Access-Control-Allow-Origin: {$requestOrigin}");
        header('Access-Control-Allow-Credentials: true');
    }
}

// === 中间件层 1：CORS 处理（OPTIONS 预检直接返回，必须在鉴权之前，否则预检请求被 401 拒绝）===
if ($method === 'OPTIONS') {
    http_response_code(204);
    handleCors($conf, $_SERVER['HTTP_ORIGIN'] ?? '');
    $allowMethods = 'GET, POST, PUT, DELETE, OPTIONS';
    $allowHeaders = 'Content-Type, Authorization, X-CSRF-Token, X-App-Id, X-App-Secret';
    header("Access-Control-Allow-Methods: {$allowMethods}");
    header("Access-Control-Allow-Headers: {$allowHeaders}");
    header('Access-Control-Max-Age: 86400');
    exit;
}

handleCors($conf, $_SERVER['HTTP_ORIGIN'] ?? '');

// === 中间件层 2：全局开关检查（api_enabled=0 直接 503）===
if (empty($conf['api_enabled'])) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 503, 'msg' => 'API is disabled', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// === 中间件层 3：应用鉴权（Stripe 双 key 模式）===
// - 服务端调用：X-App-Id + X-App-Secret（完整认证，scope 生效）
// - 浏览器/客户端调用：仅 X-App-Id（公开标识，安全靠 Bearer token，限流更严）
$appId = $_SERVER['HTTP_X_APP_ID'] ?? '';
$appSecret = $_SERVER['HTTP_X_APP_SECRET'] ?? '';

if (empty($appId)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 401, 'msg' => 'X-App-Id header is required', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// 根据是否携带 secret 区分认证模式
$apiAppServerAuth = !empty($appSecret);
if ($apiAppServerAuth) {
    // 服务端模式：验证 appid + secret
    $apiApp = $apiAuth->validateApp($appId, $appSecret);
    if (!$apiApp) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 401, 'msg' => 'Invalid app credentials', 'data' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // 客户端模式：仅验证 appid 存在且启用（secret 不暴露给浏览器）
    $apiApp = $apiAuth->validateAppPublic($appId);
    if (!$apiApp) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 401, 'msg' => 'Invalid app id', 'data' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (empty($apiApp['is_enabled'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 401, 'msg' => 'App is disabled', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// === 中间件层 3.5：IP 白名单校验 ===
if (!$apiAuth->checkAppIpWhitelist($apiApp, $ip)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 403, 'msg' => 'IP not allowed', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// 提前解析路径段，供资源白名单校验和正式路由分发共用（避免后面重复解析）
$uriPath = parse_url($uri, PHP_URL_PATH);
$path = preg_replace('#^/api/v1#', '', $uriPath);
$path = rtrim($path, '/') ?: '/';
$segments = array_values(array_filter(explode('/', $path)));
$resourceName = $segments[0] ?? '';

// 注入当前资源名，供 checkAppScope 的 permissions 矩阵使用
$apiApp['_current_resource'] = $resourceName;

// === 中间件层 4：Scope 校验（服务端/客户端模式均校验，避免客户端模式绕过 scope）===
if (!$apiAuth->checkAppScope($apiApp, $method)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 403, 'msg' => 'Insufficient app scope', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// === 中间件层 4.5：资源白名单校验 ===
if ($resourceName && !$apiAuth->checkAppResourceAccess($apiApp, $resourceName)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 403, 'msg' => 'Resource not allowed for this app', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// === 中间件层 5：限流 ===
// 应用级速率限制（客户端模式无 secret，使用更严格的默认限制）
// skip_rate_limit 能力的服务端应用跳过应用级限流
if ($apiAppServerAuth && !$apiAuth->checkAppCapability($apiApp, 'skip_rate_limit')) {
    $appRateLimitOk = $apiAuth->checkAppRateLimit($apiApp);
} elseif (!$apiAppServerAuth) {
    $appRateLimitOk = $apiAuth->checkAppPublicRateLimit($appId);
} else {
    $appRateLimitOk = true; // skip_rate_limit=true 的服务端应用跳过限流
}
if (!$appRateLimitOk) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 429, 'msg' => 'App rate limit exceeded', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

global $apiApp, $apiAppServerAuth;

$rateLimitEnabled = ($conf['api_rate_limit'] ?? 1) == 1;

// 解析 Bearer Token（无论限流是否开启都解析，供 api_log 记录 uid；API 模式下用户不一定写 session）
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
$apiLogUid = $earlyAuthUser ? intval($earlyAuthUser['uid']) : 0;

// 设置全局 $uid/$gid/$user，与前端路由保持一致
// 许多核心函数（PermissionService::check、CaptchaService::is_enabled 等）依赖 global $gid/$uid
// API 模式下不写 session，必须显式注入这些变量，否则触发 "Undefined variable $gid"
$uid = $earlyAuthUser ? intval($earlyAuthUser['uid']) : 0;
$gid = $earlyAuthUser ? intval($earlyAuthUser['gid'] ?? 0) : 0;
$user = $earlyAuthUser ?: null;
global $uid, $gid, $user;

if ($rateLimitEnabled) {
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

// === 路由分发 ===
// $segments 已在中间件层 3.5 之后提前解析（供 IP/资源白名单校验和 scope 注入使用），此处直接复用

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
    ApiResponse::success(['version' => '1.0', 'endpoints' => ['thread', 'user', 'forum', 'post', 'attach', 'notify', 'search', 'site', 'auth', 'rank', 'credits', 'captcha', 'mod', 'admin', 'my']]);
}

$resource = $segments[0];

$apiStartTime = microtime(true);
$apiLogEnabled = ($conf['api_log'] ?? 0) == 1;

register_shutdown_function(function() use ($db, $resource, $method, $ip, $apiStartTime, $apiLogEnabled, $apiLogUid, $appId) {
    if (!$apiLogEnabled) return;
    $duration = round((microtime(true) - $apiStartTime) * 1000);
    try {
        $db->insert('api_log', [
            'resource' => $resource,
            'method' => $method,
            'uid' => $apiLogUid,
            'appid' => $appId,
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
} elseif ($segments[0] === 'my') {
    $routeFile = 'my.php';
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
            // 核心路由未命中，尝试插件 API 路由
            $pluginRouteFile = PluginApiRegistry::resolve($resource);
            if ($pluginRouteFile && is_file($pluginRouteFile)) {
                // 暴露 $authUser 给插件路由文件（与 $earlyAuthUser 同义，提供标准变量名）
                $authUser = $earlyAuthUser;
                // 插件路由文件可直接访问 $db, $conf, $apiAuth, $apiApp, $apiAppServerAuth,
                // $userService, $threadService, $postService, $forumService, $attachmentService,
                // $notificationService, $segments, $method, $ip, $authUser, $earlyAuthUser
                include $pluginRouteFile;
                return;
            }
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

    // ponytail: 读取 post 的 message_fmt，与 message 同步替换 URL
    // post__create() 已调 post_message_fmt() 生成 message_fmt，其中含 upload/tmp/ 临时 URL
    // 若只更新 message 不更新 message_fmt，前台显示用 message_fmt 会指向已移走的临时文件 → 图片 broken
    $_post = post__read($pid);
    $message_fmt = $_post ? ($_post['message_fmt'] ?? '') : '';

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

        // ponytail: xn_copy 失败保护（与核心 attach_assoc_post 一致）
        // 目标文件不存在时跳过 attach_create，避免创建无物理文件的孤儿记录
        if(!is_file($destfile)) {
            xn_log("api_attach_assoc: skip attach_create due to xn_copy failure (dest not found), key:$key, pid:$pid", 'php_error');
            @unlink($meta_file);
            continue;
        }

        // 复制成功后删除临时文件
        if(filesize($destfile) == filesize($tmpfile)) {
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
                // 替换 message 和 message_fmt 中的缩略图 URL
                $message = str_replace($thumb_url, $thumb_dest_url, $message);
                $message_fmt = str_replace($thumb_url, $thumb_dest_url, $message_fmt);
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

        // 替换 message 和 message_fmt 中的临时 URL 为正式 URL
        $message = str_replace($file['url'], $desturl, $message);
        $message_fmt = str_replace($file['url'], $desturl, $message_fmt);

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

    // ponytail: 同步更新 message 和 message_fmt（URL 已替换为正式地址）
    // 前台显示用 message_fmt，不更新会导致图片/视频指向已移走的 upload/tmp/ 临时文件 → broken
    $post_update = array(
        'message'     => $message,
        'message_fmt' => $message_fmt,
        'images' => $images,
        'videos' => $videos,
        'files'  => $files,
    );
    post__update($pid, $post_update);

    // 如果是首帖，更新 thread 的 images/videos/files
    $post = post__read($pid);
    if(!empty($post) && $post['isfirst']) {
        thread__update($tid, array('images' => $images, 'videos' => $videos, 'files' => $files));
    }

    // ponytail: 孤儿附件清理（与核心 attach_assoc_post 一致）
    // 删除 message 中未引用的图片/视频附件（API 客户端传了 attach_keys 但 message 未引用的场景）
    list($attachlist, , ) = attach_find_by_pid($pid);
    if(!empty($attachlist)) {
        $orphan_aids = array();
        $orphan_attaches = array();
        foreach($attachlist as $attach) {
            $is_image_or_video = !empty($attach['isimage']) || (isset($attach['filetype']) && $attach['filetype'] == 'video');
            if(!$is_image_or_video) continue;
            $attach_url = $conf['upload_url'].'attach/'.$attach['filename'];
            if(strpos($message_fmt, $attach_url) === FALSE && strpos($message, $attach_url) === FALSE) {
                $orphan_aids[] = $attach['aid'];
                $orphan_attaches[] = $attach;
            }
        }
        if(!empty($orphan_aids)) {
            foreach($orphan_attaches as $attach) {
                $_path = $conf['upload_path'].'attach/'.$attach['filename'];
                is_file($_path) AND @unlink($_path);
                $_thumb = attach_thumb_path($attach['filename']);
                if($_thumb) {
                    $_tp = $conf['upload_path'].'attach/'.$_thumb;
                    is_file($_tp) AND @unlink($_tp);
                }
            }
            db_delete('attach', array('aid'=>$orphan_aids));
            // 重新计算计数
            foreach($orphan_attaches as $attach) {
                if(!empty($attach['isimage'])) $images = max(0, $images - 1);
                elseif(isset($attach['filetype']) && $attach['filetype'] == 'video') $videos = max(0, $videos - 1);
                else $files = max(0, $files - 1);
            }
            post__update($pid, array('images'=>$images, 'videos'=>$videos, 'files'=>$files));
            if(!empty($post) && $post['isfirst']) {
                thread__update($tid, array('images'=>$images, 'videos'=>$videos, 'files'=>$files));
            }
        }
    }

    return array('images' => $images, 'videos' => $videos, 'files' => $files);
}
