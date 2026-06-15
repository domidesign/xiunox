<?php
!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'generate');

include_once APP_PATH . 'lib/security/CaptchaService.php';

if ($action == 'generate') {
    $scene = param(2, 'login');
    if (!in_array($scene, CaptchaService::SCENES)) {
        $scene = 'login';
    }

    // 检查是否开启
    $enabled = CaptchaService::is_enabled($scene);
    if (!$enabled) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'message' => '验证码未开启 scene=' . $scene, 'debug' => ['scene' => $scene, 'enabled' => false, 'config' => CaptchaService::get_config()]]);
        exit;
    }

    // 检查 GD 库
    if (!function_exists('imagecreatetruecolor')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'message' => 'GD 库未安装', 'debug' => ['gd_loaded' => false, 'gd_info' => function_exists('gd_info') ? gd_info() : 'not available']]);
        exit;
    }

    // 检查 session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'message' => 'Session 未启动', 'debug' => ['session_status' => session_status()]]);
        exit;
    }

    $result = CaptchaService::generate($scene);
    if ($result === false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'message' => '验证码生成失败', 'debug' => ['scene' => $scene, 'result' => $result]]);
        exit;
    }

    // 成功：返回验证码图片
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'data' => $result]);
    exit;

} elseif ($action == 'verify') {
    $scene = param(2, 'login');
    $input = param('captcha', '', FALSE);
    if (empty($input)) {
        message(-1, '请输入验证码');
    }
    $result = CaptchaService::verify($scene, $input);
    $result ? message(0, '验证成功') : message(-1, '验证码错误');
}
