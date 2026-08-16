<?php
!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'generate');

include_once APP_PATH . 'lib/security/CaptchaService.php';

if ($action == 'generate') {
    $scene = param(2, 'login');

    // 场景校验：标准场景或插件注册的自定义场景
    $is_standard = in_array($scene, CaptchaService::SCENES);
    $is_custom = CaptchaService::is_custom_scene($scene);
    if (!$is_standard && !$is_custom) {
        $scene = 'login';
        $is_standard = true;
    }

    // 标准场景：检查当前用户组是否需要验证码
    // 自定义场景：跳过检查，由插件自己控制是否需要验证码
    if ($is_standard) {
        $enabled = CaptchaService::is_enabled($scene, $gid);
        if (!$enabled) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => -1, 'message' => lang('captcha_not_enabled', array('scene'=>$scene)), 'debug' => ['scene' => $scene, 'enabled' => false, 'config' => CaptchaService::get_config()]]);
            exit;
        }
    }

    // 检查 GD 库
    if (!function_exists('imagecreatetruecolor')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'message' => lang('gd_not_installed'), 'debug' => ['gd_loaded' => false, 'gd_info' => function_exists('gd_info') ? gd_info() : 'not available']]);
        exit;
    }

    // 检查 session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'message' => lang('session_not_started'), 'debug' => ['session_status' => session_status()]]);
        exit;
    }

    $result = CaptchaService::generate($scene);
    if ($result === false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'message' => lang('captcha_generate_failed'), 'debug' => ['scene' => $scene, 'result' => $result]]);
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
        message(-1, lang('please_input_captcha'));
    }
    $result = CaptchaService::verify($scene, $input, $gid);
    $result ? message(0, lang('verify_success')) : message(-1, lang('captcha_error'));
}
