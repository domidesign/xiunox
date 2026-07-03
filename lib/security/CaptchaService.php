<?php
!defined('DEBUG') AND exit('Access Denied.');

// 确保 kv_get/kv_set 函数可用
if (!function_exists('kv_get')) {
	include_once APP_PATH . 'model/kv.func.php';
}

/**
 * 验证码服务 - 可插拔验证码，内置 GD 图片验证码实现
 *
 * 场景：login, register, post, reply, resetpw
 * 配置存储在 bbs_kv 表，键名 security_captcha_config
 *
 * 配置格式：
 * [
 *   'types' => [                   // 每个场景的验证码类型
 *     'login'    => 'gd_image',
 *     'register' => 'gd_math',
 *     'post'     => 'gd_image',
 *     'reply'    => 'gd_image',
 *     'resetpw'  => 'gd_image',
 *   ],
 *   'gids' => [                    // 每个场景需要验证码的用户组ID列表
 *     'login'    => [0],           // 登录/注册/找回密码：只有游客(0)
 *     'register' => [0],
 *     'post'     => [0,5,6,...],   // 发帖/回帖：按用户组配置
 *     'reply'    => [0,5,6,...],
 *     'resetpw'  => [0],
 *   ]
 * ]
 */
class CaptchaService {

    // 支持的场景
    const SCENES = ['login', 'register', 'post', 'reply', 'resetpw'];

    // 登录前场景（用户未登录，只有游客gid=0）
    const PRE_AUTH_SCENES = ['login', 'register', 'resetpw'];

    // 已注册的自定义场景（供插件使用）
    // 格式：['scene_name' => '显示名称']
    private static $custom_scenes = [];

    /**
     * 注册自定义场景（供插件使用）
     * 插件注册的场景不检查 is_enabled，由插件自己控制是否需要验证码
     * @param string $scene 场景名（只能包含字母、数字、下划线）
     * @param string $label 显示名称（可选，用于后台/日志）
     */
    public static function register_scene(string $scene, string $label = ''): void {
        // 场景名只能包含字母、数字、下划线，防止注入
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $scene)) {
            return;
        }
        // 不允许覆盖标准场景
        if (in_array($scene, self::SCENES)) {
            return;
        }
        self::$custom_scenes[$scene] = $label ?: $scene;
    }

    /**
     * 获取所有已注册的自定义场景
     * @return array ['scene_name' => '显示名称']
     */
    public static function get_custom_scenes(): array {
        return self::$custom_scenes;
    }

    /**
     * 检查是否为已注册的自定义场景
     */
    public static function is_custom_scene(string $scene): bool {
        return isset(self::$custom_scenes[$scene]);
    }

    // 默认配置
    const DEFAULT_CONFIG = [
        'types' => [
            'login' => 'gd_image',
            'register' => 'gd_image',
            'post' => 'gd_image',
            'reply' => 'gd_image',
            'resetpw' => 'gd_image',
        ],
        'gids' => [
            'login' => [],
            'register' => [],
            'post' => [],
            'reply' => [],
            'resetpw' => [],
        ],
    ];

    /**
     * 检查场景+用户组是否需要验证码
     * @param string $scene 场景名
     * @param int $gid 用户组ID（默认0=游客）
     */
    public static function is_enabled(string $scene, int $gid = 0): bool {
        $config = self::get_config();
        $gids = $config['gids'][$scene] ?? [];
        return in_array($gid, $gids);
    }

    /**
     * 检查场景是否有任何用户组开启验证码（用于前端判断是否加载验证码组件）
     */
    public static function is_scene_active(string $scene): bool {
        $config = self::get_config();
        $gids = $config['gids'][$scene] ?? [];
        return !empty($gids);
    }

    /**
     * 生成验证码
     * @param string $scene 场景名
     * @param bool $force 强制生成（跳过场景开启检查，用于后台登录按失败次数触发验证码等场景）
     * @return array|false ['key'=>string, 'image'=>string(base64)] 或 false（场景未开启）
     */
    public static function generate(string $scene, bool $force = false) {
        // 标准场景：检查是否有用户组开启
        if (in_array($scene, self::SCENES)) {
            if (!$force && !self::is_scene_active($scene)) {
                return false;
            }
        }
        // 自定义场景（插件注册）：不检查 is_enabled，由插件自己控制是否需要验证码

        // hook: 插件可覆盖验证码生成
        $hook_func = 'security_captcha_generate_' . $scene;
        if (function_exists($hook_func)) {
            $result = $hook_func($scene);
            if ($result !== null) {
                return $result;
            }
        }

        $config = self::get_config();
        $type = $config['types'][$scene] ?? 'gd_image';

        if ($type === 'gd_math') {
            return self::generate_gd_math($scene);
        }

        return self::generate_gd_image($scene);
    }

    /**
     * 验证验证码
     * @param string $scene 场景名
     * @param string $input 用户输入
     * @param int $gid 用户组ID（默认0=游客，仅对标准场景生效）
     * @return bool
     */
    public static function verify(string $scene, string $input, int $gid = 0): bool {
        // 标准场景：按用户组判断是否需要验证
        if (in_array($scene, self::SCENES)) {
            if (!self::is_enabled($scene, $gid)) {
                return true; // 该用户组无需验证码，直接通过
            }
        }
        // 自定义场景（插件注册）：始终验证，由插件自己控制是否需要验证码

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

        if (empty($stored) || $input === '') {
            return false;
        }

        // 清除已用验证码（一次性）
        unset($_SESSION[$session_key]);

        // 不区分大小写
        return strtolower($input) === strtolower($stored);
    }

    /**
     * 获取配置
     * 兼容旧格式自动迁移
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
        // 兼容旧格式1：scene=>{enabled,type}（最旧格式）
        if (!isset($config['gids'])) {
            $new_config = ['types' => self::DEFAULT_CONFIG['types'], 'gids' => []];
            foreach (self::SCENES as $scene) {
                if (!empty($config[$scene]['enabled'])) {
                    global $grouplist;
                    $all_gids = [0];
                    if (!empty($grouplist)) {
                        foreach ($grouplist as $g) {
                            $all_gids[] = intval($g['gid']);
                        }
                    }
                    $new_config['gids'][$scene] = $all_gids;
                } else {
                    $new_config['gids'][$scene] = [];
                }
                if (!empty($config[$scene]['type'])) {
                    $new_config['types'][$scene] = $config[$scene]['type'];
                }
            }
            $config = $new_config;
            self::save_config($config);
        }
        // 兼容旧格式2：type（全局类型）→ types（每场景独立类型）
        if (isset($config['type']) && !isset($config['types'])) {
            $global_type = in_array($config['type'], ['gd_image', 'gd_math']) ? $config['type'] : 'gd_image';
            $config['types'] = [];
            foreach (self::SCENES as $scene) {
                $config['types'][$scene] = $global_type;
            }
            unset($config['type']);
            self::save_config($config);
        }
        // 确保每个场景都有 types 和 gids
        foreach (self::SCENES as $scene) {
            if (!isset($config['types'][$scene])) {
                $config['types'][$scene] = 'gd_image';
            }
            if (!isset($config['gids'][$scene])) {
                $config['gids'][$scene] = [];
            }
        }
        return $config;
    }

    /**
     * 保存配置
     */
    public static function save_config(array $config): bool {
        $clean = [
            'types' => [],
            'gids' => [],
        ];
        foreach (self::SCENES as $scene) {
            $type = $config['types'][$scene] ?? 'gd_image';
            $clean['types'][$scene] = in_array($type, ['gd_image', 'gd_math']) ? $type : 'gd_image';
            $gids = $config['gids'][$scene] ?? [];
            // 不能用默认 array_filter()，否则会过滤掉 gid=0（游客组）
            $filtered = [];
            foreach ((array)$gids as $gid) {
                if ($gid !== '' && $gid !== null) {
                    $filtered[] = intval($gid);
                }
            }
            $clean['gids'][$scene] = $filtered;
        }
        $r = kv_set('security_captcha_config', $clean);
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
        if ($op === '-') {
            // 确保结果为正数（不为0）
            if ($a <= $b) {
                $tmp = $a; $a = $b + 1; $b = $tmp;
            }
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
