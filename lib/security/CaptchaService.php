<?php
!defined('DEBUG') AND exit('Access Denied.');

// 确保 kv_get/kv_set 函数可用
if (!function_exists('kv_get')) {
	include_once APP_PATH . 'model/kv.func.php';
}

/**
 * 验证码服务 - 可插拔验证码，内置 GD 图片验证码实现
 *
 * 场景：login, register, post, resetpw
 * 配置存储在 bbs_kv 表，键名 security_captcha_config
 *
 * 接口：
 * - captcha_generate($scene): 生成验证码，返回 ['key'=>string, 'image'=>string(base64)] 或 false
 * - captcha_verify($scene, $input): 验证用户输入，返回 bool
 * - captcha_is_enabled($scene): 检查场景是否开启验证码
 * - captcha_get_config(): 获取配置
 * - captcha_save_config($config): 保存配置
 */
class CaptchaService {

    // 支持的场景
    const SCENES = ['login', 'register', 'post', 'resetpw'];

    // 默认配置
    const DEFAULT_CONFIG = [
        'login' => ['enabled' => 0, 'type' => 'gd_image'],
        'register' => ['enabled' => 0, 'type' => 'gd_image'],
        'post' => ['enabled' => 0, 'type' => 'gd_image'],
        'resetpw' => ['enabled' => 0, 'type' => 'gd_image'],
    ];

    /**
     * 检查场景是否开启验证码
     */
    public static function is_enabled(string $scene): bool {
        $config = self::get_config();
        return !empty($config[$scene]['enabled']);
    }

    /**
     * 生成验证码
     * @param string $scene 场景名
     * @return array|false ['key'=>string, 'image'=>string(base64)] 或 false（场景未开启）
     */
    public static function generate(string $scene) {
        if (!self::is_enabled($scene)) {
            return false;
        }

        // hook: 插件可覆盖验证码生成
        // Xiuno 使用模板 hook 机制，PHP 层通过全局函数扩展
        // 插件可定义 security_captcha_generate_{scene} 函数来覆盖生成逻辑
        $hook_func = 'security_captcha_generate_' . $scene;
        if (function_exists($hook_func)) {
            $result = $hook_func($scene);
            if ($result !== null) {
                return $result;
            }
        }

        $config = self::get_config();
        $type = $config[$scene]['type'] ?? 'gd_image';

        if ($type === 'gd_math') {
            return self::generate_gd_math($scene);
        }

        return self::generate_gd_image($scene);
    }

    /**
     * 验证验证码
     * @param string $scene 场景名
     * @param string $input 用户输入
     * @return bool
     */
    public static function verify(string $scene, string $input): bool {
        if (!self::is_enabled($scene)) {
            return true; // 场景未开启，直接通过
        }

        // hook: 插件可覆盖验证码验证
        $hook_func = 'security_captcha_verify_' . $scene;
        if (function_exists($hook_func)) {
            $result = $hook_func($scene, $input);
            if ($result !== null) {
                return (bool) $result;
            }
        }

        $session_key = 'captcha_' . $scene;
        $stored = $_SESSION[$session_key] ?? '';

        if (empty($stored) || empty($input)) {
            return false;
        }

        // 清除已用验证码（一次性）
        unset($_SESSION[$session_key]);

        // 不区分大小写
        return strtolower($input) === strtolower($stored);
    }

    /**
     * 获取配置
     * kv_get/kv_set 内部已处理 JSON 编解码，无需二次 json_encode/json_decode
     */
    public static function get_config(): array {
        static $config = null;
        if ($config !== null) {
            return $config;
        }
        $config = kv_get('security_captcha_config');
        if (empty($config) || !is_array($config)) {
            $config = self::DEFAULT_CONFIG;
        }
        return $config;
    }

    /**
     * 保存配置
     * kv_set 内部已处理 JSON 编解码，直接传入数组
     */
    public static function save_config(array $config): bool {
        // 确保只保留合法场景
        $clean = [];
        foreach (self::SCENES as $scene) {
            $clean[$scene] = [
                'enabled' => !empty($config[$scene]['enabled']) ? 1 : 0,
                'type' => in_array($config[$scene]['type'] ?? '', ['gd_image', 'gd_math'])
                    ? $config[$scene]['type'] : 'gd_image',
            ];
        }
        $r = kv_set('security_captcha_config', $clean);
        // kv_set 返回 db_replace 的结果（int），0 表示 REPLACE 成功但无自增ID
        // 只有 FALSE 才表示真正失败
        return $r !== FALSE;
    }

    /**
     * GD 图片验证码 - 字母数字混合
     */
    private static function generate_gd_image(string $scene): array {
        $width = 120;
        $height = 40;
        $length = 4;
        $chars = '23456789abcdefghjkmnpqrstuvwxyz'; // 去除易混淆字符
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // 存入 session
        $_SESSION['captcha_' . $scene] = $code;

        // 生成图片
        $im = imagecreatetruecolor($width, $height);
        $bg_color = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $bg_color);

        // 干扰线
        for ($i = 0; $i < 4; $i++) {
            $line_color = imagecolorallocate($im, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imageline($im, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $line_color);
        }

        // 干扰点
        for ($i = 0; $i < 50; $i++) {
            $point_color = imagecolorallocate($im, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imagesetpixel($im, random_int(0, $width), random_int(0, $height), $point_color);
        }

        // 文字
        for ($i = 0; $i < $length; $i++) {
            $text_color = imagecolorallocate($im, random_int(0, 100), random_int(0, 100), random_int(0, 100));
            $font_size = random_int(16, 22);
            $x = 10 + $i * 26;
            $y = random_int(22, 32);
            imagestring($im, $font_size, $x, $y - 10, $code[$i], $text_color);
        }

        ob_start();
        imagepng($im);
        $image_data = ob_get_clean();
        // imagedestroy() 在 PHP 8.0+ 已无效果，8.5 已废弃，不再调用

        $base64 = base64_encode($image_data);

        return [
            'key' => $scene,
            'image' => 'data:image/png;base64,' . $base64,
        ];
    }

    /**
     * GD 算术验证码
     */
    private static function generate_gd_math(string $scene): array {
        $a = random_int(1, 20);
        $b = random_int(1, 20);
        $op = random_int(0, 1) ? '+' : '-';
        if ($op === '-' && $a < $b) {
            // 确保结果非负
            $tmp = $a; $a = $b; $b = $tmp;
        }
        $answer = $op === '+' ? $a + $b : $a - $b;
        $code_str = "$a $op $b = ?";

        // 存入 session
        $_SESSION['captcha_' . $scene] = (string) $answer;

        // 生成图片
        $width = 120;
        $height = 40;
        $im = imagecreatetruecolor($width, $height);
        $bg_color = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $bg_color);

        // 干扰线
        for ($i = 0; $i < 3; $i++) {
            $line_color = imagecolorallocate($im, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imageline($im, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $line_color);
        }

        $text_color = imagecolorallocate($im, 0, 0, 0);
        imagestring($im, 5, 15, 12, $code_str, $text_color);

        ob_start();
        imagepng($im);
        $image_data = ob_get_clean();
        // imagedestroy() 在 PHP 8.0+ 已无效果，8.5 已废弃，不再调用

        $base64 = base64_encode($image_data);

        return [
            'key' => $scene,
            'image' => 'data:image/png;base64,' . $base64,
        ];
    }
}
