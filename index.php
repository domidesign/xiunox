<?php
/*
 * Copyright (C) xiuno.com
 */

// PHP 8.0+ 硬阻断：低于 8.0 直接终止，避免运行时各种诡异错误
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
	header('HTTP/1.1 500 Internal Server Error');
	header('Content-Type: text/html; charset=utf-8');
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PHP 版本过低</title>';
	echo '<style>body{font-family:-apple-system,sans-serif;padding:40px;line-height:1.6;color:#333;max-width:720px;margin:0 auto}h1{color:#dc3545;margin-top:0}code{background:#f8f9fa;padding:2px 6px;border-radius:3px;color:#c7254e}</style>';
	echo '</head><body>';
	echo '<h1>PHP 版本过低，无法运行</h1>';
	echo '<p>XiunoX 要求 PHP <strong>8.0</strong> 及以上版本。</p>';
	echo '<p>当前 PHP 版本：<code>' . PHP_VERSION . '</code></p>';
	echo '<p>请升级 PHP 至 8.0+ 后再访问站点。</p>';
	echo '</body></html>';
	exit;
}

// 版本号唯一来源
include dirname(__FILE__) . '/version.php';  // NOCACHE

//xhprof_enable();

//$_SERVER['REQUEST_URI'] = '/?user-login.htm';
//$_SERVER['REQUEST_METHOD'] = 'POST';
//$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
//$_COOKIE['bbs_sid'] = 'e1d8c2790b9dd08267e6ea2595c3bc82';
//$postdata = 'email=admin&password=c4ca4238a0b923820dcc509a6f75849b';
//parse_str($postdata, $_POST);

 

// 0: Production mode; 1: Developer mode; 2: Plugin developement mode;
// 0: 线上模式; 1: 调试模式; 2: 插件开发模式;
!defined('DEBUG') AND define('DEBUG', 0);
define('APP_PATH', dirname(__FILE__).'/'); // __DIR__
!defined('ADMIN_PATH') AND define('ADMIN_PATH', APP_PATH.'admin/');
!defined('XIUNOPHP_PATH') AND define('XIUNOPHP_PATH', APP_PATH.'xiunophp/');

// !ini_get('zlib.output_compression') AND ob_start('ob_gzhandler');

//ob_start('ob_gzhandler');
// conf 加载失败说明未安装，跳转到 install/
// 必须用绝对路径：admin/ 入口会 include 本文件，若用相对路径 "install/"，
// 浏览器会在 /admin/ 下解析成 /admin/install/，再次命中 admin/index.php，形成
// /admin/install/install/install/... 无限循环。
$conf = @include APP_PATH.'conf/conf.php';
if(!$conf) {
	// 根据当前 SCRIPT_NAME 推算站点根 URL，兼容根目录部署/子目录部署/admin 入口
	$_base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
	if (substr($_base, -6) === '/admin') {
		$_base = substr($_base, 0, -5); // admin/ 入口回退到根
	} elseif ($_base === '/' || $_base === '.') {
		$_base = '';
	}
	exit('<script>window.location="' . $_base . '/install/"</script>');
}

// 兼容 4.0.3 的配置文件	
!isset($conf['user_create_on']) AND $conf['user_create_on'] = 1;
!isset($conf['cache_disable']) AND $conf['cache_disable'] = 0;
!isset($conf['logo_mobile_url']) AND $conf['logo_mobile_url'] = 'view/img/logo.png';
!isset($conf['logo_pc_url']) AND $conf['logo_pc_url'] = 'view/img/logo.png';
!isset($conf['logo_water_url']) AND $conf['logo_water_url'] = 'view/img/water-small.png';
$conf['version'] = XIUNOX_VERSION;	// 版本号统一从 version.php 读取，避免手工修改 conf/conf.php

// 转换为绝对路径，防止被包含时出错。
substr($conf['log_path'], 0, 2) == './' AND $conf['log_path'] = APP_PATH.$conf['log_path']; 
substr($conf['tmp_path'], 0, 2) == './' AND $conf['tmp_path'] = APP_PATH.$conf['tmp_path']; 
substr($conf['upload_path'], 0, 2) == './' AND $conf['upload_path'] = APP_PATH.$conf['upload_path']; 

// ===== 子目录安装支持：自动检测 base_path =====
// 从 SCRIPT_NAME 推算安装子目录路径（如 /xiunox），根目录部署为空字符串
// admin 入口（/xiunox/admin/index.php）回退一级到 /xiunox
// 支持 conf.php 手动配置 $conf['base_path'] 覆盖自动检测（反向代理等场景）
if (!isset($conf['base_path']) || $conf['base_path'] === '') {
	$_script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
	if (substr($_script_dir, -6) === '/admin') {
		$_script_dir = substr($_script_dir, 0, -5); // admin/ 入口回退到根
	}
	// 规范化：去掉尾斜杠，根目录为空字符串
	$_script_dir = rtrim($_script_dir, '/');
	$conf['base_path'] = $_script_dir;
}
// base_path 二次规范化：确保无尾斜杠，根目录为空字符串
$conf['base_path'] = rtrim($conf['base_path'], '/');
$_base_path = $conf['base_path']; // 便捷别名，用于下方拼接

// 为 view_url/upload_url/logo 路径添加 base_path 前缀
// 支持：完整 URL（http://cdn.com/view/）不加前缀；相对路径（view/）加 base_path 前缀
foreach(array('view_url', 'upload_url') as $_url_key) {
	if(!empty($conf[$_url_key]) && strpos($conf[$_url_key], '://') === FALSE && strpos($conf[$_url_key], '//') !== 0) {
		// 去掉用户配置可能带的 / 前缀，统一用 base_path 前缀
		$_relative = ltrim($conf[$_url_key], '/');
		$conf[$_url_key] = $_base_path . '/' . $_relative;
	}
}
foreach(array('logo_mobile_url', 'logo_pc_url', 'logo_water_url') as $_logo_key) {
	if(!empty($conf[$_logo_key]) && strpos($conf[$_logo_key], '://') === FALSE && strpos($conf[$_logo_key], '//') !== 0) {
		$_relative = ltrim($conf[$_logo_key], '/');
		$conf[$_logo_key] = $_base_path . '/' . $_relative;
	}
}

$_SERVER['conf'] = $conf;

// 强制 HTTPS 跳转（必须在 conf 加载后、框架初始化前执行）
if(!empty($conf['force_https']) && $conf['force_https'] == 1) {
	$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
	if(!$is_https) {
		$https_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		header('HTTP/1.1 301 Moved Permanently');
		header('Location: ' . $https_url);
		exit;
	}
}

if(DEBUG > 1) {
	include XIUNOPHP_PATH.'xiunophp.php';
} else {
	include XIUNOPHP_PATH.'xiunophp.min.php';
}

// 测试数据库连接 / try to connect database
//db_connect() OR exit($errstr);

include APP_PATH.'model/plugin.func.php';
// model.inc.php 和 index.inc.php 需要走 _include() 以支持插件 hook 注入
// _include() 已修复原子写入，不再有并发截断问题
include _include(APP_PATH.'model.inc.php');
// ErrorHandler 已在 xiunophp.php 启动时注册（lib/ErrorHandler.php），此处不再重复
require_once APP_PATH.'lib/avatar_component.php';
include _include(APP_PATH.'index.inc.php');

//file_put_contents((ini_get('xhprof.output_dir') ? : '/tmp') . '/' . uniqid() . '.xhprof.xhprof', serialize(xhprof_disable()));

?>