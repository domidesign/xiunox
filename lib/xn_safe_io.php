<?php
/**
 * 插件文件 IO 安全包装
 *
 * 限制插件 install.php / uninstall.php / upgrade.php / setting.php 的文件写入/删除/读取范围
 * 仅允许操作：
 * - tmp/ 临时目录
 * - upload/ 上传目录
 * - plugin/{自身插件目录}/ 自身插件目录
 * - log/ 日志目录（仅追加）
 *
 * 用法：在 plugin include 之前 require_once 此文件，插件代码可调用 xn_safe_write 等安全函数
 */

!defined('APP_PATH') AND exit('Access Denied');

/**
 * 校验路径是否在白名单内
 * @param string $path 待校验路径（绝对或相对）
 * @param string $plugin_dir 当前插件目录名（用于 plugin/{自身}/ 白名单）
 * @return bool
 */
function xn_safe_path_check($path, $plugin_dir = '') {
	$path = str_replace('\\', '/', $path);
	$app_path = str_replace('\\', '/', APP_PATH);
	// 剥离 APP_PATH 前缀，得到相对路径
	$path = str_replace($app_path, '', $path);
	$path = ltrim($path, '/');

	// 白名单前缀
	$allowed = array(
		'tmp/',
		'upload/',
		'log/',
	);
	if($plugin_dir) {
		$allowed[] = "plugin/$plugin_dir/";
	}

	foreach($allowed as $prefix) {
		if(strpos($path, $prefix) === 0) return true;
	}

	return false;
}

/**
 * 安全文件写入
 * @param string $path 文件路径
 * @param string $content 内容
 * @param string $plugin_dir 当前插件目录名
 * @return int|false 写入字节数或 false
 */
function xn_safe_write($path, $content, $plugin_dir = '') {
	if(!xn_safe_path_check($path, $plugin_dir)) {
		xn_log("Blocked unsafe write: $path by plugin $plugin_dir", 'plugin_io_blocked_error');
		return false;
	}
	return file_put_contents($path, $content);
}

/**
 * 安全文件删除
 * @param string $path 文件路径
 * @param string $plugin_dir 当前插件目录名
 * @return bool
 */
function xn_safe_unlink($path, $plugin_dir = '') {
	if(!xn_safe_path_check($path, $plugin_dir)) {
		xn_log("Blocked unsafe unlink: $path by plugin $plugin_dir", 'plugin_io_blocked_error');
		return false;
	}
	return unlink($path);
}

/**
 * 安全目录删除（递归）
 * @param string $path 目录路径
 * @param string $plugin_dir 当前插件目录名
 * @return bool
 */
function xn_safe_rmdir($path, $plugin_dir = '') {
	if(!xn_safe_path_check($path, $plugin_dir)) {
		xn_log("Blocked unsafe rmdir: $path by plugin $plugin_dir", 'plugin_io_blocked_error');
		return false;
	}
	if(!is_dir($path)) return false;

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach($files as $file) {
		if($file->isDir()) {
			rmdir($file->getRealPath());
		} else {
			unlink($file->getRealPath());
		}
	}
	return rmdir($path);
}

/**
 * 安全 fopen
 * @param string $path 文件路径
 * @param string $mode 打开模式
 * @param string $plugin_dir 当前插件目录名
 * @return resource|false
 */
function xn_safe_fopen($path, $mode, $plugin_dir = '') {
	// 写模式需校验白名单
	if(strpos($mode, 'w') !== false || strpos($mode, 'a') !== false || strpos($mode, 'x') !== false || strpos($mode, 'c') !== false) {
		if(!xn_safe_path_check($path, $plugin_dir)) {
			xn_log("Blocked unsafe fopen($mode): $path by plugin $plugin_dir", 'plugin_io_blocked_error');
			return false;
		}
	}
	return fopen($path, $mode);
}

?>
