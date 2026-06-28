<?php
/**
 * 验证码 API
 *
 * GET  /api/v1/captcha/{scene}         — 生成验证码图片（返回 base64）
 * POST /api/v1/captcha/{scene}/verify   — 验证验证码输入
 */

include_once APP_PATH . 'lib/security/CaptchaService.php';

// 解析场景，默认 login
$scene = isset($segments[1]) ? $segments[1] : 'login';

// 校验场景是否合法
if (!in_array($scene, CaptchaService::SCENES, true)) {
    ApiResponse::error(400, '无效的验证码场景');
}

// 判断是否为验证接口
$isVerify = isset($segments[2]) && $segments[2] === 'verify';

if ($isVerify) {
    // POST 验证验证码
    if ($method !== 'POST') {
        ApiResponse::error(405, '请求方法不允许');
    }

    $input = param('captcha', '');

    if ($input === '') {
        ApiResponse::validationError('请输入验证码');
    }

    $result = CaptchaService::verify($scene, $input, $gid);

    if ($result) {
        ApiResponse::success(null, '验证码正确');
    } else {
        ApiResponse::error(400, '验证码错误或已过期');
    }
} else {
    // GET 生成验证码
    if ($method !== 'GET') {
        ApiResponse::error(405, '请求方法不允许');
    }

    // 检查当前用户组是否需要验证码
    if (!CaptchaService::is_enabled($scene, $gid)) {
        ApiResponse::success(['image' => '', 'key' => ''], '该场景未启用验证码');
    }

    $data = CaptchaService::generate($scene);

    ApiResponse::success([
        'image' => $data['image'],
        'key'   => $data['key'],
    ]);
}
