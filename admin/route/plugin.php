<?php

!defined('DEBUG') AND exit('Access Denied.');

include XIUNOPHP_PATH.'xn_zip.func.php';

// 递归过滤插件设置中的 XSS
function sanitize_plugin_setting(&$value) {
    if(is_array($value)) {
        foreach($value as $k => &$v) {
            sanitize_plugin_setting($v);
        }
    } elseif(is_string($value)) {
        $value = strip_tags($value);
    }
}

$action = param(1);

// 初始化插件变量 / init plugin var
plugin_init();

// 插件依赖的环境检查
plugin_env_check();

empty($action) AND $action = 'local';

if($action == 'local') {
	
	// 本地插件 local plugin list
	$pluginlist = array();
	
	// 获取参数
	$type_filter = param('type', 0); // 0:全部, 1:插件, 2:模板
	$status_filter = param('status', 0); // 0:全部, 1:已启用, 2:已安装, 3:未安装
	$keyword = trim(param('keyword', ''));
	
	// 处理插件列表
	foreach($plugins as $dir => $plugin) {
		// 获取带数据库信息的插件数据
		$plugin = plugin_read_by_dir_with_db($dir);
		$plugin['dir'] = $dir;

		// 检测是否存在 upgrade.php（用于显示升级按钮）
		$plugin['has_upgrade_file'] = is_file(APP_PATH."plugin/$dir/upgrade.php");

		// 检测是否需要升级：已安装 + conf.json.version 与 db.version 不一致 + 存在 upgrade.php
		$plugin['need_upgrade'] = 0;
		if (!empty($plugin['installed']) && !empty($plugin['has_upgrade_file'])) {
			$_code_ver = isset($plugin['version']) ? $plugin['version'] : '';
			$_db_ver = isset($plugin['db_version']) ? $plugin['db_version'] : '';
			if ($_code_ver !== '' && $_code_ver !== $_db_ver) {
				$plugin['need_upgrade'] = 1;
			}
		}

		// 类型过滤
		$plugin_type = $plugin['type'];
		if($type_filter == 1 && $plugin_type == 1) continue; // 只看插件，过滤模板
		if($type_filter == 2 && $plugin_type == 0) continue; // 只看模板，过滤插件
		
		// 状态过滤
		if($status_filter == 1 && empty($plugin['enable'])) continue; // 只看已启用
		if($status_filter == 2 && (empty($plugin['installed']) || !empty($plugin['enable']))) continue; // 只看已安装未启用
		if($status_filter == 3 && !empty($plugin['installed'])) continue; // 只看未安装
		
		// 关键词搜索
		if(!empty($keyword)) {
			$search_fields = array($plugin['name'], $plugin['brief'], $dir);
			$found = false;
			foreach($search_fields as $field) {
				if(stripos($field, $keyword) !== false) {
					$found = true;
					break;
				}
			}
			if(!$found) continue;
		}
		
		$pluginlist[$dir] = $plugin;
	}
	
	// 排序
	$pluginlist_arr = array();
	foreach($pluginlist as $dir => $plugin) {
		$pluginlist_arr[] = $plugin;
	}
	
	usort($pluginlist_arr, function($a, $b) {
		global $time;
		
		// 1. 刚刚安装的排在前面（install_time 24小时内）
		$a_recent_install = ($a['install_time'] > $time - 86400) ? 1 : 0;
		$b_recent_install = ($b['install_time'] > $time - 86400) ? 1 : 0;
		if($a_recent_install != $b_recent_install) {
			return $b_recent_install - $a_recent_install;
		}
		if($a_recent_install && $b_recent_install) {
			return $b['install_time'] - $a['install_time'];
		}
		
		// 2. 刚启用的排在前面（enable_time 24小时内）
		$a_recent_enable = ($a['enable'] && $a['enable_time'] > $time - 86400) ? 1 : 0;
		$b_recent_enable = ($b['enable'] && $b['enable_time'] > $time - 86400) ? 1 : 0;
		if($a_recent_enable != $b_recent_enable) {
			return $b_recent_enable - $a_recent_enable;
		}
		if($a_recent_enable && $b_recent_enable) {
			return $b['enable_time'] - $a['enable_time'];
		}
		
		// 3. 启用中的按 enable_time 倒序
		if($a['enable'] != $b['enable']) {
			return $b['enable'] - $a['enable'];
		}
		if($a['enable'] && $b['enable']) {
			return $b['enable_time'] - $a['enable_time'];
		}
		
		// 4. 已安装未启用按 install_time 倒序
		if($a['installed'] != $b['installed']) {
			return $b['installed'] - $a['installed'];
		}
		if($a['installed'] && $b['installed']) {
			return $b['install_time'] - $a['install_time'];
		}
		
		// 5. 未安装按名称字母排序
		return strcasecmp($a['name'], $b['name']);
	});
	
	// 转回关联数组
	$pluginlist = array();
	foreach($pluginlist_arr as $plugin) {
		$dir = $plugin['dir'];
		$pluginlist[$dir] = $plugin;
	}
	
	$pagination = '';
	$pugin_cate_html = '';
	
	$header['title']    = lang('local_plugin');
	$header['mobile_title'] = lang('local_plugin');
	
	// 传递给模板的参数
	$input['type'] = $type_filter;
	$input['status'] = $status_filter;
	$input['keyword'] = $keyword;
	
	include _include(ADMIN_PATH."view/htm/plugin_list.htm");

} elseif($action == 'install') {
	
	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];
	
	// 安装前兼容性预扫描
	$force = param('force', 0);
	
	if($method == 'POST') {
		// CSRF 校验
		CsrfService::check();
		plugin_lock_start();
		include APP_PATH . 'lib/PluginScannerRules.php';
	include APP_PATH . 'lib/PluginScannerSuggestion.php';
	include APP_PATH . 'lib/PluginScanner.php';
	$scanner = new PluginScanner();
	$scanResult = $scanner->scanBeforeInstall($dir);
	
	if(!$scanResult['can_install'] && !$force) {
		// 有致命问题，阻止安装，返回扫描结果
		plugin_lock_end();
		$fatalList = [];
		foreach($scanResult['fatal'] as $f) {
			$fatalList[] = $f['file'] . ':' . $f['line'] . ' ' . $f['suggestion'];
		}
		$msg = lang('plugin_install_blocked', array('name'=>$name, 'issues'=>implode("\n", $fatalList)));
		message(-1, $msg);
	}
	
	// 检查目录可写 / check directory writable
	//plugin_check_dir_is_writable();
	
	// 插件依赖检查 / check plugin dependency
	plugin_check_dependency($dir, 'install');
	
	// 安装插件 / install plugin
	plugin_install($dir);
	
	$installfile = APP_PATH."plugin/$dir/install.php";
	if(is_file($installfile)) {
		// 注入安全 IO 包装，限制插件文件操作范围
		require_once APP_PATH . 'lib/xn_safe_io.php';
		$plugin_dir = $dir;
		include _include($installfile);
	}
	
	plugin_lock_end();

	admin_log_create('plugin_install', 'plugin', $dir, '安装插件：' . $name);

	// 卸载同类插件，防止安装类似插件。
	// 自动卸载掉其他已经安装的主题 / automatically unstall other theme plugin.
	if(strpos($dir, '_theme_') !== FALSE) {
		foreach($plugins as $_dir => $_plugin) {
			if($dir == $_dir) continue;
			if(strpos($_dir, '_theme_') !== FALSE) {
				plugin_unstall($_dir);
			}
		}
	} else {
		// 卸载掉同类插件
		$suffix = substr($dir, strpos($dir, '_'));
		foreach($plugins as $_dir => $_plugin) {
			if($dir == $_dir) continue;
			$_suffix = substr($_dir, strpos($_dir, '_'));
			if($suffix == $_suffix) {
				plugin_unstall($_dir);
			}
		}
	}
	
	$msg = lang('plugin_install_sucessfully', array('name'=>$name));
	message(0, $msg, array('redirect_url' => admin_plugin_url()));
	} else {
		// GET: 已改为弹窗确认，直接返回列表页
		http_location(admin_plugin_url());
	}

} elseif($action == 'unstall') {
	
	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];
	
	if($method == 'POST') {
		// CSRF 校验
		CsrfService::check();
		plugin_lock_start();
	
	// 检查目录可写
	// plugin_check_dir_is_writable();
	
	// 插件依赖检查
	plugin_check_dependency($dir, 'unstall');
	
	// 卸载插件
	plugin_unstall($dir);

	// 执行卸载脚本：优先标准拼写 uninstall.php，回退旧拼写 unstall.php（向后兼容）
	$uninstallfile = APP_PATH."plugin/$dir/uninstall.php";
	if(!is_file($uninstallfile)) {
		$uninstallfile = APP_PATH."plugin/$dir/unstall.php";
	}
	if(is_file($uninstallfile)) {
		// 注入安全 IO 包装，限制插件文件操作范围
		require_once APP_PATH . 'lib/xn_safe_io.php';
		$plugin_dir = $dir;
		include _include($uninstallfile);
	}
	
	// 删除插件
	//!DEBUG && rmdir_recusive("../plugin/$dir");
	
	plugin_lock_end();

	admin_log_create('plugin_uninstall', 'plugin', $dir, '卸载插件：' . $name);

	$msg = lang('plugin_unstall_sucessfully', array('name'=>$name, 'dir'=>"plugin/$dir"));
	message(0, $msg, array('redirect_url' => admin_plugin_url()));
	} else {
		// GET: 已改为弹窗确认，直接返回列表页
		http_location(admin_plugin_url());
	}

} elseif($action == 'enable') {

	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];

	if($method == 'POST') {
		// CSRF 校验
		CsrfService::check();
		plugin_lock_start();

	// 检查目录可写
	//plugin_check_dir_is_writable();

	// 插件依赖检查
	plugin_check_dependency($dir, 'install');

	// 启用插件
plugin_enable($dir);

// 主题互斥：启用主题时自动禁用其他已启用的主题（保留配置，方便切换）
if(strpos($dir, '_theme_') !== FALSE) {
	foreach($plugins as $_dir => $_plugin) {
		if($dir == $_dir) continue;
		if(strpos($_dir, '_theme_') !== FALSE && !empty($_plugin['enable'])) {
			plugin_disable($_dir);
		}
	}
}

plugin_lock_end();

admin_log_create('plugin_enable', 'plugin', $dir, '启用插件：' . $name);

	$msg = lang('plugin_enable_sucessfully', array('name'=>$name));
	message(0, $msg, array('redirect_url' => admin_plugin_url()));
	} else {
		// GET: 已改为弹窗确认，直接返回列表页
		http_location(admin_plugin_url());
	}

} elseif($action == 'disable') {

	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];

	if($method == 'POST') {
		// CSRF 校验
		CsrfService::check();
		plugin_lock_start();

	// 检查目录可写
	//plugin_check_dir_is_writable();

	// 插件依赖检查
	plugin_check_dependency($dir, 'unstall');

	// 禁用插件
	plugin_disable($dir);

	plugin_lock_end();

	admin_log_create('plugin_disable', 'plugin', $dir, '禁用插件：' . $name);

	$msg = lang('plugin_disable_sucessfully', array('name'=>$name));
	message(0, $msg, array('redirect_url' => admin_plugin_url()));
	} else {
		// GET: 已改为弹窗确认，直接返回列表页
		http_location(admin_plugin_url());
	}

} elseif($action == 'upgrade') {

	if($method == 'POST') {
		// CSRF 校验
		CsrfService::check();

		plugin_lock_start();

		$dir = param_word(2);
		plugin_check_exists($dir);
		$name = $plugins[$dir]['name'];

		plugin_check_dependency($dir, 'install');

		plugin_install($dir);

		$upgradefile = APP_PATH."plugin/$dir/upgrade.php";
		if(is_file($upgradefile)) {
			// 注入安全 IO 包装，限制插件文件操作范围
			require_once APP_PATH . 'lib/xn_safe_io.php';
			$plugin_dir = $dir;
			include _include($upgradefile);
		}

		plugin_lock_end();

		admin_log_create('plugin_upgrade', 'plugin', $dir, '升级插件：' . $name);

		$msg = lang('plugin_upgrade_sucessfully', array('name'=>$name));
	message(0, $msg, array('redirect_url' => admin_plugin_url()));
	} else {
		// GET: 已改为弹窗确认，直接返回列表页
		$dir = param_word(2);
		plugin_check_exists($dir);
		http_location(admin_plugin_url());
	}

} elseif($action == 'setting') {

	$dir = param_word(2);
	plugin_check_exists($dir);
	// 检查插件是否已启用且已安装：未启用插件的 Service 类未合并到 model.min.php，
	// 直接加载 setting.php 会触发 "Class XXXService not found" fatal error
	if (empty($plugins[$dir]['installed']) || empty($plugins[$dir]['enable'])) {
		message(-1, lang('plugin_not_enabled_or_installed'));
	}
	$name = $plugins[$dir]['name'];
	
	// 对插件设置的 POST 数据进行 XSS 过滤
	if($method == 'POST' && !empty($_POST)) {
		// CSRF 校验
		CsrfService::check();
		sanitize_plugin_setting($_POST);
	}
	
	// 注入安全 IO 包装，限制插件文件操作范围
	require_once APP_PATH . 'lib/xn_safe_io.php';
	$plugin_dir = $dir;
	include _include(APP_PATH."plugin/$dir/setting.php");

} elseif($action == 'upload') {

	// 上传 zip 安装/升级插件（安装和升级共用入口，系统自动判断走哪个流程）
	if($method != 'POST') {
		message(-1, 'Method not allowed');
	}

	// CSRF 校验
	CsrfService::check();

	plugin_lock_start();

	// ===== 1. 基础安全校验 =====
	if(empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
		plugin_lock_end();
		message(-1, lang('plugin_upload_no_file'));
	}

	$uploadFile = $_FILES['file']['tmp_name'];
	$uploadName = $_FILES['file']['name'];
	$uploadSize = $_FILES['file']['size'];

	// 大小限制 50MB
	$maxSize = 50 * 1024 * 1024;
	if($uploadSize > $maxSize || $uploadSize == 0) {
		plugin_lock_end();
		message(-1, lang('plugin_upload_too_large'));
	}

	// 检测 plugin/ 目录可写
	if(!is_writable(APP_PATH.'plugin/')) {
		plugin_lock_end();
		message(-1, lang('plugin_upload_dir_not_writable'));
	}

	// ===== 2. ZIP 有效性 + 防目录穿越 =====
	if(!class_exists('ZipArchive')) {
		plugin_lock_end();
		message(-1, lang('plugin_upload_zip_not_supported'));
	}

	$zip = new ZipArchive();
	if($zip->open($uploadFile) !== TRUE) {
		plugin_lock_end();
		message(-1, lang('plugin_upload_invalid_zip'));
	}

	// 防目录穿越：禁止 zip 内文件名包含 ../ 或 ..\
	for($i = 0; $i < $zip->numFiles; $i++) {
		$entryName = $zip->getNameIndex($i);
		if(strpos($entryName, '../') !== false || strpos($entryName, '..\\') !== false) {
			$zip->close();
			plugin_lock_end();
			message(-1, lang('plugin_upload_path_traversal'));
		}
	}

	// ===== 3. 解压到临时目录 =====
	$tmpDir = APP_PATH.'tmp/upload_'.xn_rand(16).'/';
	if(!mkdir($tmpDir, 0755, true)) {
		$zip->close();
		plugin_lock_end();
		message(-1, lang('plugin_upload_mkdir_failed'));
	}

	$zip->extractTo($tmpDir);
	$zip->close();

	// ===== 4. 检测 zip 结构 + 读取 conf.json =====
	// 支持两种 zip 结构：
	//   A) conf.json 在 zip 根目录 → 用上传文件名（去掉 .zip）作为插件目录名
	//   B) zip 内有一层目录，conf.json 在该目录下 → 用该目录名作为插件目录名
	$confData = null;
	$pluginDir = '';
	$srcDir = '';

	if(is_file($tmpDir.'conf.json')) {
		// 结构 A：conf.json 在 zip 根目录
		$pluginDir = preg_replace('/\.zip$/i', '', $uploadName);
		$srcDir = $tmpDir;
		$confData = json_decode(file_get_contents($tmpDir.'conf.json'), true);
	} else {
		// 结构 B：zip 内有一层目录
		$subDirs = glob($tmpDir.'*', GLOB_ONLYDIR);
		if(count($subDirs) === 1 && is_file($subDirs[0].'/conf.json')) {
			$pluginDir = basename($subDirs[0]);
			$srcDir = $subDirs[0];
			$confData = json_decode(file_get_contents($subDirs[0].'/conf.json'), true);
		}
	}

	// 校验 conf.json
	if(empty($confData) || !is_array($confData)) {
		rmdir_recusive($tmpDir);
		plugin_lock_end();
		message(-1, lang('plugin_upload_conf_invalid'));
	}

	// 校验目录名合法性
	if(!is_word($pluginDir)) {
		rmdir_recusive($tmpDir);
		plugin_lock_end();
		message(-1, lang('plugin_upload_name_invalid'));
	}

	$pluginPath = APP_PATH.'plugin/'.$pluginDir.'/';
	$isUpgrade = is_dir($pluginPath);
	$pluginName = isset($confData['name']) ? $confData['name'] : $pluginDir;
	$newVersion = isset($confData['version']) ? $confData['version'] : '1.0';

	// ===== 5. 版本判断（仅升级时）=====
	if($isUpgrade) {
		$oldConfRaw = is_file($pluginPath.'conf.json') ? file_get_contents($pluginPath.'conf.json') : '';
		$oldConf = $oldConfRaw ? json_decode($oldConfRaw, true) : array();
		$oldVersion = isset($oldConf['version']) ? $oldConf['version'] : '1.0';

		if(version_compare($newVersion, $oldVersion, '<=')) {
			rmdir_recusive($tmpDir);
			plugin_lock_end();
			message(-1, lang('plugin_upload_version_lower', array('old'=>$oldVersion, 'new'=>$newVersion)));
		}
	}

	// ===== 6. 执行安装或升级 =====
	if(!$isUpgrade) {
		// ----- 全新安装 -----

		// 把新文件移到 plugin/
		if(!rmove_dir($srcDir, $pluginPath)) {
			rmdir_recusive($tmpDir);
			plugin_lock_end();
			message(-1, lang('plugin_upload_move_failed'));
		}

		// 清理临时目录（rmove_dir 已删 srcDir，但结构 A 时 $tmpDir 本身可能残留）
		if(is_dir($tmpDir)) rmdir_recusive($tmpDir);

		// 重新初始化插件列表
		plugin_init();

		// PluginScanner 预扫描
		include APP_PATH.'lib/PluginScannerRules.php';
		include APP_PATH.'lib/PluginScannerSuggestion.php';
		include APP_PATH.'lib/PluginScanner.php';
		$scanner = new PluginScanner();
		$scanResult = $scanner->scanBeforeInstall($pluginDir);

		if(!$scanResult['can_install']) {
			// 有致命问题，删除刚解压的插件文件
			rmdir_recusive($pluginPath);
			plugin_clear_tmp_dir();
			plugin_lock_end();
			$fatalList = array();
			foreach($scanResult['fatal'] as $f) {
				$fatalList[] = $f['file'].':'.$f['line'].' '.$f['suggestion'];
			}
			$msg = lang('plugin_install_blocked', array('name'=>$pluginName, 'issues'=>implode("\n", $fatalList)));
			message(-1, $msg);
		}

		// 依赖检查
		plugin_check_dependency($pluginDir, 'install');

		// 安装（写 conf.json + 数据库 + 清缓存，不执行 install.php）
		plugin_install($pluginDir);

		// 执行 install.php
		$installFile = APP_PATH."plugin/$pluginDir/install.php";
		if(is_file($installFile)) {
			require_once APP_PATH.'lib/xn_safe_io.php';
			$plugin_dir = $pluginDir;
			include _include($installFile);
		}

		plugin_lock_end();

		admin_log_create('plugin_install', 'plugin', $pluginDir, '上传安装插件：'.$pluginName);

		$msg = lang('plugin_upload_install_success', array('name'=>$pluginName, 'version'=>$newVersion));
		message(0, $msg, array('redirect_url' => admin_plugin_url()));

	} else {
		// ----- 升级 -----

		// 记录原启用状态
		$wasEnabled = !empty($plugins[$pluginDir]['enable']);

		// 升级前自动禁用，防止 upgrade.php 执行时被运行代码冲突
		if($wasEnabled) {
			plugin_disable($pluginDir);
		}

		// 备份旧版本到 plugin/{dir}.bak/
		$bakPath = APP_PATH.'plugin/'.$pluginDir.'.bak/';
		if(is_dir($bakPath)) {
			rmdir_recusive($bakPath); // 清理残留的旧备份
		}
		rename($pluginPath, $bakPath);

		// 移入新版本
		if(!rmove_dir($srcDir, $pluginPath)) {
			// 移动失败，回滚
			if(is_dir($pluginPath)) rmdir_recusive($pluginPath);
			rename($bakPath, $pluginPath);
			if(is_dir($tmpDir)) rmdir_recusive($tmpDir);
			if($wasEnabled) plugin_enable($pluginDir);
			plugin_lock_end();
			message(-1, lang('plugin_upload_move_failed'));
		}

		// 清理临时目录
		if(is_dir($tmpDir)) rmdir_recusive($tmpDir);

		// 重新初始化插件列表
		plugin_init();

		// 设置升级错误捕获
		$upgradeError = false;
		$oldErrorHandler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$upgradeError) {
			if($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) {
				$upgradeError = true;
				xn_log("Plugin upgrade error: [$errno] $errstr at $errfile:$errline", 'plugin_upgrade_error');
			}
			return false;
		});

		try {
			// 写 conf.json + 数据库状态（不执行 install.php）
			plugin_install($pluginDir);

			// 执行 upgrade.php 迁移脚本
			$upgradeFile = APP_PATH."plugin/$pluginDir/upgrade.php";
			if(is_file($upgradeFile)) {
				require_once APP_PATH.'lib/xn_safe_io.php';
				$plugin_dir = $pluginDir;
				include _include($upgradeFile);
			}
		} catch(Throwable $e) {
			$upgradeError = true;
			xn_log("Plugin upgrade exception: ".$e->getMessage(), 'plugin_upgrade_error');
		}

		restore_error_handler();

		if($upgradeError) {
			// 升级失败，自动回滚
			if(is_dir($pluginPath)) rmdir_recusive($pluginPath);
			rename($bakPath, $pluginPath);

			// 重新初始化 + 清缓存
			plugin_init();
			plugin_clear_tmp_dir();

			// 保持禁用状态（不恢复启用），让用户手动确认后启用
			plugin_lock_end();

			admin_log_create('plugin_upgrade', 'plugin', $pluginDir, '上传升级失败已回滚：'.$pluginName);

			$msg = lang('plugin_upload_rollback', array('name'=>$pluginName, 'bak'=>"plugin/$pluginDir.bak"));
			message(-1, $msg);
		}

		// 升级成功，删除备份
		rmdir_recusive($bakPath);

		// 恢复原启用状态
		if($wasEnabled) {
			plugin_enable($pluginDir);
		}

		plugin_clear_tmp_dir();
		plugin_lock_end();

		admin_log_create('plugin_upgrade', 'plugin', $pluginDir, '上传升级插件：'.$pluginName);

		$msg = lang('plugin_upload_upgrade_success', array('name'=>$pluginName, 'version'=>$newVersion));
		message(0, $msg, array('redirect_url' => admin_plugin_url()));
	}

} elseif($action == 'scanner') {

	include _include(ADMIN_PATH.'route/plugin_scanner.php');
}


	

// 检查目录是否可写，插件要求 model view admin 目录文件可写。
/*
function plugin_check_dir_is_writable() {
	// 检测目录和文件可写
	$dirs = array(
		APP_PATH.'model', 
		APP_PATH.'plugin', 
		APP_PATH.'view', 
		APP_PATH.'route', 
		APP_PATH.'view/js', 
		APP_PATH.'view/htm', 
		APP_PATH.'view/css', 
		APP_PATH.'plugin', 
		ADMIN_PATH.'route', 
		ADMIN_PATH.'view/htm');
	$dirarr = array();
	foreach($dirs as $dir) {
		if(!xn_is_writable($dir)) {
			$dirarr[] = $dir;
		}
	}
	$msg = lang('plugin_set_relatied_dir_writable', array('dir'=>implode(', ', $dirarr)));
	!empty($dirarr) AND message(-1, $msg);
}*/

function plugin_check_dependency($dir, $action = 'install') {
	global $plugins;
	$name = $plugins[$dir]['name'];
	if($action == 'install') {
		$arr = plugin_dependencies($dir);
		if(!empty($arr)) {
			$s = plugin_dependency_arr_to_links($arr);
			$msg = lang('plugin_dependency_following', array('name'=>$name, 's'=>$s));
			message(-1, $msg);
		}
	} else {
		$arr = plugin_by_dependencies($dir);
		if(!empty($arr)) {
			$s = plugin_dependency_arr_to_links($arr);
			$msg = lang('plugin_being_dependent_cant_delete', array('name'=>$name, 's'=>$s));
			message(-1, $msg);
		}
	}
}

function plugin_dependency_arr_to_links($arr) {
	global $plugins;
	$s = '';
	foreach($arr as $dir=>$version) {
		$name = isset($plugins[$dir]['name']) ? $plugins[$dir]['name'] : $dir;
		$s .= " 【{$name}】 ";
	}
	return $s;
}


function plugin_is_local($dir) {
	global $plugins;
	return isset($plugins[$dir]) ? TRUE : FALSE;
}

function plugin_check_exists($dir) {
	global $plugins;
	!is_word($dir) AND message(-1, lang('plugin_name_error'));
	!isset($plugins[$dir]) AND message(-1, lang('plugin_not_exists'));
}

function plugin_lock_start() {
	global $route, $action;
	!xn_lock_start($route.'_'.$action) AND message(-1, lang('plugin_task_locked'));
}

function plugin_lock_end() {
	global $route, $action;
	xn_lock_end($route.'_'.$action);
}

// 依赖
function plugin_env_check() {
	//!class_exists('ZipArchive') AND message(-1, 'ZipArchive does not exists! require PHP version > 5.2.0');
}

// 递归移动目录内容到目标目录（用于 zip 解压后移入 plugin/ 目录）
// 成功后源目录会被删除；同一文件系统下走 rename 原子操作，跨设备回退到 copy+unlink
function rmove_dir($src, $dst) {
	if(!is_dir($src)) return false;
	substr($src, -1) != '/' AND $src .= '/';
	substr($dst, -1) != '/' AND $dst .= '/';

	if(!is_dir($dst)) {
		@mkdir($dst, 0755, true);
	}

	$d = dir($src);
	while(false !== ($entry = $d->read())) {
		if($entry == '.' || $entry == '..') continue;
		$srcPath = $src.$entry;
		$dstPath = $dst.$entry;
		if(is_dir($srcPath)) {
			if(!rmove_dir($srcPath, $dstPath)) return false;
		} else {
			if(!@rename($srcPath, $dstPath)) {
				if(!@copy($srcPath, $dstPath)) return false;
				@unlink($srcPath);
			}
		}
	}
	$d->close();
	@rmdir($src);
	return true;
}

?>