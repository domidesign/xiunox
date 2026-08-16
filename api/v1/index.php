<?php

/**
 * Xiuno BBS API v1 入口文件
 *
 * 当请求直接到达 /api/v1/ 目录时（而非通过根 index.php 路由），
 * 此文件负责完整的引导流程。
 */

!defined('API_MODE') AND define('API_MODE', true);

!defined('DEBUG') AND define('DEBUG', 1);
!defined('APP_PATH') AND define('APP_PATH', dirname(__DIR__, 2) . '/');
!defined('XIUNOPHP_PATH') AND define('XIUNOPHP_PATH', APP_PATH . 'xiunophp/');

// 版本号唯一来源
include APP_PATH . 'version.php';

// 加载配置文件
$conf = (@include APP_PATH . 'conf/conf.php') OR exit(json_encode(['code' => 500, 'msg' => 'Config not found', 'data' => null]));

// 兼容配置项
!isset($conf['user_create_on']) AND $conf['user_create_on'] = 1;
!isset($conf['cache_disable']) AND $conf['cache_disable'] = 0;
$conf['version'] = XIUNOX_VERSION;

// 转换为绝对路径
substr($conf['log_path'], 0, 2) == './' AND $conf['log_path'] = APP_PATH . $conf['log_path'];
substr($conf['tmp_path'], 0, 2) == './' AND $conf['tmp_path'] = APP_PATH . $conf['tmp_path'];
substr($conf['upload_path'], 0, 2) == './' AND $conf['upload_path'] = APP_PATH . $conf['upload_path'];

$_SERVER['conf'] = $conf;

include APP_PATH . 'xiunophp/xiunophp.php';

// 加载模型函数
include APP_PATH . 'model/plugin.func.php';
include _include(APP_PATH . 'model.inc.php');

// 兜底：确保已启用插件的 Service 类被加载（防止 tmp 缓存不一致导致 Class not found）
// ponytail: 与 index.php 同步，model.inc.php 的 hook 注入可能因 tmp 缓存陈旧而丢失
// XIUNOX 规范：plugin/<dir>/model/ 只放 Service 类（类名=文件名），禁止 *.func.php（xiuno 原版写法，由 hook 注入加载）
$_plugin_paths_fallback = plugin_paths_enabled();
foreach ($_plugin_paths_fallback as $_path_fb => $_pconf_fb) {
	$_model_dir_fb = $_path_fb . '/model';
	if (!is_dir($_model_dir_fb)) continue;
	foreach (glob($_model_dir_fb . '/*.php') as $_service_file_fb) {
		// 跳过 xiuno 原版 *.func.php 函数库文件：由 model_inc_file hook 注入加载，不自动扫描
		if (substr($_service_file_fb, -9) === '.func.php') continue;
		$_class_name_fb = ucfirst(basename($_service_file_fb, '.php'));
		if (!class_exists($_class_name_fb, false)) {
			include_once $_service_file_fb;
		}
	}
}
unset($_plugin_paths_fallback, $_path_fb, $_pconf_fb, $_model_dir_fb, $_service_file_fb, $_class_name_fb);

// 引导 API 路由
include __DIR__ . '/bootstrap.php';
