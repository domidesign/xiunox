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

function stripSensitiveFields(array &$user): void {
    unset($user['password'], $user['salt'], $user['password_hash']);
}

$seg1 = $segments[1] ?? '';
$seg2 = $segments[2] ?? '';
$seg3 = $segments[3] ?? '';
$uid = is_numeric($seg1) ? intval($seg1) : 0;
$fields = $_GET['fields'] ?? '';

switch ($method) {
    case 'GET':
        // 支持 ids 参数获取多个用户
        $idsParam = $_GET['ids'] ?? '';
        if (!empty($idsParam)) {
            $ids = is_string($idsParam) ? array_map('trim', explode(',', $idsParam)) : $idsParam;
            $users = $userService->getUsersByIds($ids);
            foreach ($users as &$u) {
                stripSensitiveFields($u);
            }
            unset($u);
            if (!empty($fields)) {
                $users = filterFields($users, $fields);
            }
            ApiResponse::success(['list' => $users, 'total' => count($users)]);
        } elseif ($seg1 === '') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));
            $users = $userService->getUserList($page, $pagesize);
            $total = $userService->getUserCount();
            foreach ($users as &$u) {
                stripSensitiveFields($u);
            }
            unset($u);
            $result = paginateResult($users, $page, $pagesize, $total);
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        }

        if ($seg1 === 'me') {
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            stripSensitiveFields($authUser);
            if (!empty($fields)) {
                $authUser = filterFields($authUser, $fields);
            }
            ApiResponse::success($authUser);
        }

        if ($uid > 0 && $seg2 === '') {
            $user = $userService->getUserById($uid);
            if (!$user) {
                ApiResponse::notFound('User not found');
            }
            stripSensitiveFields($user);
            if (!empty($fields)) {
                $user = filterFields($user, $fields);
            }
            ApiResponse::success($user);
        }

        if ($uid > 0 && $seg2 === 'threads') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));
            $threads = $threadService->getThreadsByUid($uid, $page, $pagesize);
            $total = $threadService->getThreadCountByUid($uid);
            $result = paginateResult($threads, $page, $pagesize, $total);
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        }

        if ($uid > 0 && $seg2 === 'posts') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));
            $posts = $postService->getPostListByUid($uid, $page, $pagesize);
            $total = $postService->getPostCountByUid($uid);
            $result = paginateResult($posts, $page, $pagesize, $total);
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        }

        if ($uid > 0 && $seg2 === 'favorites') {
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            if (intval($authUser['uid']) !== $uid) {
                ApiResponse::forbidden();
            }
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));
            $favorites = $threadService->getFavoritesByUid($uid, $page, $pagesize);
            $total = $threadService->getFavoriteCountByUid($uid);
            $result = paginateResult($favorites, $page, $pagesize, $total);
            ApiResponse::success($result);
        }

        // 获取用户关注列表
        if ($uid > 0 && $seg2 === 'following') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));
            $following = user_follow_find_following($uid, $page, $pagesize);
            $list = [];
            foreach ($following as $fuid) {
                $u = user_read_cache($fuid);
                if ($u) {
                    stripSensitiveFields($u);
                    $list[] = $u;
                }
            }
            // 关注总数取用户字段
            $targetUser = $userService->getUserById($uid);
            $total = $targetUser ? intval($targetUser['follows'] ?? 0) : 0;
            $result = paginateResult($list, $page, $pagesize, $total);
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        }

        // 获取用户粉丝列表
        if ($uid > 0 && $seg2 === 'followers') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));
            $followers = user_follow_find_followers($uid, $page, $pagesize);
            $list = [];
            foreach ($followers as $fuid) {
                $u = user_read_cache($fuid);
                if ($u) {
                    stripSensitiveFields($u);
                    $list[] = $u;
                }
            }
            // 粉丝总数取用户字段
            $targetUser = $userService->getUserById($uid);
            $total = $targetUser ? intval($targetUser['fans'] ?? 0) : 0;
            $result = paginateResult($list, $page, $pagesize, $total);
            if (!empty($fields)) {
                $result['list'] = filterFields($result['list'], $fields);
            }
            ApiResponse::success($result);
        }

        // 获取用户 AI 配置
        if ($uid > 0 && $seg2 === 'ai-config') {
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            if (intval($authUser['uid']) !== $uid && intval($authUser['gid']) !== 1) {
                ApiResponse::forbidden();
            }
            $user = $userService->getUserById($uid);
            if (!$user) {
                ApiResponse::notFound('User not found');
            }
            $ai_config = [];
            if (!empty($user['ai_config'])) {
                $ai_config = json_decode($user['ai_config'], true);
                if (!is_array($ai_config)) $ai_config = [];
            }
            ApiResponse::success($ai_config);
        }

        // 获取预设头像列表
        if ($uid > 0 && $seg2 === 'avatar' && $seg3 === 'presets') {
            $preset_list = [];
            if (function_exists('avatar_preset_files')) {
                $preset_list = avatar_preset_files();
            }
            ApiResponse::success(['list' => array_values($preset_list), 'total' => count($preset_list)]);
        }

        ApiResponse::notFound();
        break;

    case 'PUT':
        // 更新用户 AI 配置
        if ($uid > 0 && $seg2 === 'ai-config') {
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            if (intval($authUser['uid']) !== $uid && intval($authUser['gid']) !== 1) {
                ApiResponse::forbidden();
            }
            $user = $userService->getUserById($uid);
            if (!$user) {
                ApiResponse::notFound('User not found');
            }
            $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
            $ai_provider = $jsonInput['ai_provider'] ?? param('ai_provider', '');
            $ai_apikey = $jsonInput['ai_apikey'] ?? param('ai_apikey', '');
            $ai_endpoint = $jsonInput['ai_endpoint'] ?? param('ai_endpoint', '');
            $ai_model = $jsonInput['ai_model'] ?? param('ai_model', '');
            // 构建 ai_config JSON
            $ai_config = [];
            if (!empty($user['ai_config'])) {
                $ai_config = json_decode($user['ai_config'], true);
                if (!is_array($ai_config)) $ai_config = [];
            }
            if (!isset($ai_config['models'])) $ai_config['models'] = [];
            if (!isset($ai_config['models']['custom'])) $ai_config['models']['custom'] = [];
            $custom = &$ai_config['models']['custom'];
            if (!empty($ai_apikey)) $custom['apiKey'] = $ai_apikey;
            if (!empty($ai_endpoint)) $custom['endpoint'] = $ai_endpoint;
            if (!empty($ai_model)) $custom['model'] = $ai_model;
            unset($custom);
            if (!empty($ai_provider)) $ai_config['bubblePanelModel'] = $ai_provider;
            $update = ['ai_config' => json_encode($ai_config, JSON_UNESCAPED_UNICODE)];
            $userService->updateUser($uid, $update);
            $freshUser = $userService->getUserById($uid);
            $result = [];
            if (!empty($freshUser['ai_config'])) {
                $result = json_decode($freshUser['ai_config'], true);
                if (!is_array($result)) $result = [];
            }
            ApiResponse::success($result);
        }

        if ($uid <= 0) {
            ApiResponse::validationError('User ID is required');
        }
        $token = ApiAuthService::getBearerToken();
        $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
        if (!$authUser) {
            ApiResponse::unauthorized();
        }
        if (intval($authUser['uid']) !== $uid && intval($authUser['gid']) !== 1) {
            ApiResponse::forbidden();
        }
        $user = $userService->getUserById($uid);
        if (!$user) {
            ApiResponse::notFound('User not found');
        }
        $update = [];
        $username = param('username', '');
        if (!empty($username)) $update['username'] = $username;
        $email = param('email', '');
        if (!empty($email)) $update['email'] = $email;
        $avatar = param('avatar', 0);
        if ($avatar > 0) $update['avatar'] = intval($avatar);
        $password = param('password', '');
        if (!empty($password)) {
            $salt = xn_rand(16);
            $update['password'] = md5($password . $salt);
            $update['salt'] = $salt;
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        // 支持签名字段（兼容表单和 JSON 输入）
        $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
        $signature = param('signature', '');
        if (isset($_POST['signature']) || isset($jsonInput['signature'])) {
            $update['signature'] = $signature !== '' ? $signature : ($jsonInput['signature'] ?? '');
        }
        if (!empty($update)) {
            $userService->updateUser($uid, $update);
        }
        $freshUser = $userService->getUserById($uid);
        stripSensitiveFields($freshUser);
        ApiResponse::success($freshUser);
        break;

    case 'POST':
        // 关注用户
        if ($uid > 0 && $seg2 === 'follow') {
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            $myUid = intval($authUser['uid']);
            if ($myUid === $uid) {
                ApiResponse::validationError('不能关注自己');
            }
            $targetUser = $userService->getUserById($uid);
            if (!$targetUser) {
                ApiResponse::notFound('User not found');
            }
            // 检查是否已关注
            $exists = user_follow_read($myUid, $uid);
            if ($exists) {
                ApiResponse::error(409, '已经关注了该用户');
            }
            user_follow_create($myUid, $uid);
            ApiResponse::success(['followed' => true]);
        }

        // 上传头像
        if ($uid > 0 && $seg2 === 'avatar' && $seg3 === '') {
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            if (intval($authUser['uid']) !== $uid && intval($authUser['gid']) !== 1) {
                ApiResponse::forbidden();
            }
            $user = $userService->getUserById($uid);
            if (!$user) {
                ApiResponse::notFound('User not found');
            }
            // 检查上传附件权限
            include_once APP_PATH . 'lib/security/PermissionService.php';
            if (!PermissionService::check('allowattach')) {
                ApiResponse::forbidden('您无权上传');
            }
            // 校验文件上传
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                ApiResponse::validationError('请上传头像文件');
            }
            $file = $_FILES['file'];
            // 校验文件类型
            $allowed = array('jpg', 'jpeg', 'png', 'gif', 'bmp');
            // WebP 仅在 GD 真正支持时允许
            if (function_exists('imagecreatefromwebp')) {
                $gd_info = gd_info();
                if (!empty($gd_info['WebP Support'])) $allowed[] = 'webp';
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                ApiResponse::validationError('不支持的文件类型');
            }
            // 校验文件大小（最大 5MB）
            if ($file['size'] > 5242880) {
                ApiResponse::validationError('文件大小超过限制（最大5MB）');
            }
            // 处理头像上传
            $time = $_SERVER['time'] ?? time();
            $filename = "$uid.png";
            $dir = substr(sprintf("%09d", $uid), 0, 3).'/';
            $path = $conf['upload_path'].'avatar/'.$dir;
            $url = $conf['upload_url'].'avatar/'.$dir.$filename;
            !is_dir($path) && mkdir($path, 0777, true);
            $destfile = $path.$filename;
            $tmpfile = $file['tmp_name'];
            $n = image_clip_thumb($tmpfile, $destfile, 256, 256);
            @unlink($tmpfile);
            if ($n <= 0) {
                ApiResponse::error(500, '图片处理失败');
            }
            user_update($uid, array('avatar' => $time));
            ApiResponse::success(['avatar_url' => $url.'?'.$time, 'avatar' => $time]);
        }

        // 选择预设头像
        if ($uid > 0 && $seg2 === 'avatar' && $seg3 === 'preset') {
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            if (intval($authUser['uid']) !== $uid && intval($authUser['gid']) !== 1) {
                ApiResponse::forbidden();
            }
            $user = $userService->getUserById($uid);
            if (!$user) {
                ApiResponse::notFound('User not found');
            }
            $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
            $avatar_index = intval($jsonInput['avatar_index'] ?? param('avatar_index', 0));
            // avatar_index = 0 表示恢复默认头像
            if ($avatar_index === 0) {
                user_update($uid, array('avatar' => 0));
                ApiResponse::success(['avatar' => 0, 'avatar_url' => '/view/img/avatar.png']);
            }
            // 校验预设头像索引
            $preset_list = [];
            if (function_exists('avatar_preset_files')) {
                $preset_list = avatar_preset_files();
            }
            if (empty($preset_list) || !isset($preset_list[$avatar_index])) {
                ApiResponse::validationError('无效的预设头像索引');
            }
            user_update($uid, array('avatar' => -$avatar_index));
            ApiResponse::success([
                'avatar' => -$avatar_index,
                'avatar_url' => $preset_list[$avatar_index]['url'],
            ]);
        }

        ApiResponse::notFound();
        break;

    case 'DELETE':
        // 取消关注用户
        if ($uid > 0 && $seg2 === 'follow') {
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            $myUid = intval($authUser['uid']);
            if ($myUid === $uid) {
                ApiResponse::validationError('不能取消关注自己');
            }
            // 检查是否已关注
            $exists = user_follow_read($myUid, $uid);
            if (!$exists) {
                ApiResponse::error(409, '尚未关注该用户');
            }
            user_follow_delete($myUid, $uid);
            ApiResponse::success(['followed' => false]);
        }
        ApiResponse::notFound();
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
