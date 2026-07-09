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

// 用户信息白名单字段（未认证可获取）
function getUserPublicFields(): array {
    return ['uid', 'username', 'nickname', 'display_name', 'avatar_url', 'gid', 'groupname', 'threads', 'posts', 'follows', 'followeds', 'fans'];
}

// 扩展字段（认证后额外返回，不含 email/mobile）
function getUserExtendedFields(): array {
    return ['signature', 'create_date', 'login_date', 'credits', 'golds', 'rmbs', 'favorites', 'avatar'];
}

// 根据认证状态过滤用户信息
function sanitizeUserData(array $user, ?array $authUser = null, bool $isSelf = false, bool $isAdmin = false): array {
    global $conf;
    // 始终移除的字段
    unset($user['password'], $user['salt'], $user['password_hash'], $user['login_attempts'], $user['banned_until'], $user['last_login_ip'], $user['last_login_time'], $user['ai_config']);

    if ($authUser === null) {
        // 未认证：只返回白名单字段
        $publicKeys = array_flip(getUserPublicFields());
        // 保留 uid（即使不在白名单中也要保留）
        $result = array_intersect_key($user, $publicKeys);
        // 确保 avatar_url 存在（如果没有则从 avatar 字段生成）
        if (!isset($result['avatar_url']) && isset($user['avatar'])) {
            $result['avatar_url'] = $user['avatar'] > 0
                ? $conf['upload_url'] . 'avatar/' . substr(sprintf("%09d", $user['uid']), 0, 3) . '/' . $user['uid'] . '.png?' . $user['avatar']
                : '/view/img/avatar.png';
        }
        return $result;
    }

    // 已认证：返回白名单 + 扩展字段
    $allowedKeys = array_flip(array_merge(getUserPublicFields(), getUserExtendedFields()));

    // 本人可看 email
    if ($isSelf) {
        $allowedKeys['email'] = true;
    }

    // 管理员额外看 create_ip
    if ($isAdmin) {
        $allowedKeys['create_ip'] = true;
    }

    return array_intersect_key($user, $allowedKeys);
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
            // 批量获取用户需认证
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            $ids = is_string($idsParam) ? array_map('trim', explode(',', $idsParam)) : $idsParam;
            $users = $userService->getUsersByIds($ids);
            foreach ($users as &$u) {
                $isSelf = intval($u['uid']) === intval($authUser['uid']);
                $isAdmin = intval($authUser['gid']) === 1;
                $u = sanitizeUserData($u, $authUser, $isSelf, $isAdmin);
            }
            unset($u);
            if (!empty($fields)) {
                $users = filterFields($users, $fields);
            }
            ApiResponse::success(['list' => $users, 'total' => count($users)]);
        } elseif ($seg1 === '') {
            // 用户列表需认证
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            if (!$authUser) {
                ApiResponse::unauthorized();
            }
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));
            $users = $userService->getUserList($page, $pagesize);
            $total = $userService->getUserCount();
            foreach ($users as &$u) {
                $isSelf = intval($u['uid']) === intval($authUser['uid']);
                $isAdmin = intval($authUser['gid']) === 1;
                $u = sanitizeUserData($u, $authUser, $isSelf, $isAdmin);
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
            // /user/me/permissions - 返回当前用户在各版块的权限矩阵
            if ($seg2 === 'permissions') {
                // model.inc.php 已通过 _include() 加载过编译版本，此处仅兜底
                if (!function_exists('forum_access_user')) {
                    include_once APP_PATH . 'model/forum_access.func.php';
                }
                if (!function_exists('group_list_cache')) {
                    include_once APP_PATH . 'model/group.func.php';
                }
                if (!function_exists('forum_list_cache')) {
                    include_once APP_PATH . 'model/forum.func.php';
                }
                // forum_access_user / forum_access_mod 依赖全局变量
                $GLOBALS['uid'] = intval($authUser['uid']);
                try {
                    $GLOBALS['grouplist'] = group_list_cache();
                    $GLOBALS['forumlist'] = forum_list_cache();
                    $gid = intval($authUser['gid']);
                    $userPerms = ['allowread', 'allowthread', 'allowpost', 'allowattach', 'allowdown'];
                    $modPerms = ['allowtop', 'allowupdate', 'allowdelete', 'allowmove', 'allowbanuser', 'allowdeleteuser', 'allowviewip'];
                    $permissions = [];
                    foreach ($GLOBALS['forumlist'] as $f) {
                        $fid = intval($f['fid']);
                        $perms = [];
                        foreach ($userPerms as $key) {
                            $perms[$key] = forum_access_user($fid, $gid, $key);
                        }
                        foreach ($modPerms as $key) {
                            $perms[$key] = forum_access_mod($fid, $gid, $key);
                        }
                        $permissions[] = [
                            'fid' => $fid,
                            'name' => $f['name'] ?? '',
                            'permissions' => $perms,
                        ];
                    }
                    ApiResponse::success(['uid' => intval($authUser['uid']), 'permissions' => $permissions]);
                } catch (\Throwable $e) {
                    // forum_access 相关函数可能依赖未加载的模型，返回空权限矩阵而不是 500
                    ApiResponse::success(['uid' => intval($authUser['uid']), 'permissions' => [], 'error' => $e->getMessage()]);
                }
            }
            $authUser = sanitizeUserData($authUser, $authUser, true, intval($authUser['gid']) === 1);
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
            // 根据认证状态脱敏
            $token = ApiAuthService::getBearerToken();
            $authUser = $token ? $apiAuth->validateAccessToken($token) : null;
            $isSelf = $authUser && intval($authUser['uid']) === $uid;
            $isAdmin = $authUser && intval($authUser['gid']) === 1;
            $user = sanitizeUserData($user, $authUser, $isSelf, $isAdmin);
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
            $total = $threadService->getFavoriteCount($uid);
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
                    $u = sanitizeUserData($u, null); // 未认证级别脱敏，following是Public端点
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
                    $u = sanitizeUserData($u, null); // 未认证级别脱敏，followers是Public端点
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
        if (!empty($username)) {
            if (!is_username($username, $err)) {
                ApiResponse::validationError($err ?: 'Username format invalid');
            }
            $update['username'] = $username;
        }
        $email = param('email', '');
        if (!empty($email)) {
            if (!is_email($email, $err)) {
                ApiResponse::validationError($err ?: 'Email format invalid');
            }
            $update['email'] = $email;
        }
        $avatar = param('avatar', 0);
        if ($avatar > 0) $update['avatar'] = intval($avatar);
        $password = param('password', '', FALSE);
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
        $freshUser = sanitizeUserData($freshUser, $authUser, true, intval($authUser['gid']) === 1);
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
            include_once APP_PATH . 'lib/PermissionService.php';
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
