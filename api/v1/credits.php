<?php

// 积分系统 API 路由

include APP_PATH . 'lib/CreditsService.php';
$creditsService = new CreditsService($db, $conf);

// 鉴权：从 Bearer token 获取用户
$authUser = null;
$token = ApiAuthService::getBearerToken();
if ($token) {
    $authUser = $apiAuth->validateAccessToken($token);
}

// 判断子路由
$subResource = $segments[1] ?? '';

if ($subResource === 'log') {
    // GET /api/v1/credits/log — 查询积分日志
    if ($method !== 'GET') ApiResponse::error(405, 'Method Not Allowed');
    if (!$authUser) ApiResponse::unauthorized('需要登录');

    $page = max(1, intval($_GET['page'] ?? 1));
    $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));
    $type = $_GET['type'] ?? '';

    $result = $creditsService->log(intval($authUser['uid']), $page, $pagesize, $type);
    ApiResponse::success($result);

} elseif ($subResource === 'add') {
    // POST /api/v1/credits/add — 增加积分
    if ($method !== 'POST') ApiResponse::error(405, 'Method Not Allowed');
    if (!$authUser) ApiResponse::unauthorized('需要登录');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $uid = intval($input['uid'] ?? $authUser['uid']);
    $type = $input['type'] ?? 'credits';
    $amount = intval($input['amount'] ?? 0);
    $reason = $input['reason'] ?? '';

    if ($amount <= 0) {
        ApiResponse::validationError('amount 必须大于 0');
    }
    if (empty($reason)) {
        ApiResponse::validationError('reason 不能为空');
    }

    // 权限检查：管理员可操作任意用户，普通用户只能操作自己
    $isAdmin = intval($authUser['gid'] ?? 0) === 1;
    if (!$isAdmin && $uid !== intval($authUser['uid'])) {
        ApiResponse::forbidden('无权操作他人积分');
    }

    $result = $creditsService->add($uid, $type, $amount, $reason);
    if (!$result['ok']) {
        ApiResponse::error(400, $result['message']);
    }
    ApiResponse::success($result);

} elseif ($subResource === 'sub') {
    // POST /api/v1/credits/sub — 扣减积分
    if ($method !== 'POST') ApiResponse::error(405, 'Method Not Allowed');
    if (!$authUser) ApiResponse::unauthorized('需要登录');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $uid = intval($input['uid'] ?? $authUser['uid']);
    $type = $input['type'] ?? 'credits';
    $amount = intval($input['amount'] ?? 0);
    $reason = $input['reason'] ?? '';

    if ($amount <= 0) {
        ApiResponse::validationError('amount 必须大于 0');
    }
    if (empty($reason)) {
        ApiResponse::validationError('reason 不能为空');
    }

    // 权限检查：管理员可操作任意用户，普通用户只能操作自己
    $isAdmin = intval($authUser['gid'] ?? 0) === 1;
    if (!$isAdmin && $uid !== intval($authUser['uid'])) {
        ApiResponse::forbidden('无权操作他人积分');
    }

    $result = $creditsService->sub($uid, $type, $amount, $reason);
    if (!$result['ok']) {
        ApiResponse::error(400, $result['message']);
    }
    ApiResponse::success($result);

} else {
    // GET /api/v1/credits — 查询当前用户积分余额
    if ($method !== 'GET') ApiResponse::error(405, 'Method Not Allowed');
    if (!$authUser) ApiResponse::unauthorized('需要登录');

    $type = $_GET['type'] ?? '';
    $result = $creditsService->get(intval($authUser['uid']), $type);
    ApiResponse::success($result);
}
