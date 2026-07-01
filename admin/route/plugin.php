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
	
	plugin_lock_start();
	
	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];
	
	// 安装前兼容性预扫描
	$force = param('force', 0);
	include APP_PATH . 'lib/PluginScannerRules.php';
	include APP_PATH . 'lib/PluginScannerSuggestion.php';
	include APP_PATH . 'lib/PluginScannerAlpine.php';
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
	message(0, $msg, array('redirect_url' => http_referer()));
	
} elseif($action == 'unstall') {
	
	plugin_lock_start();
	
	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];
	
	// 检查目录可写
	// plugin_check_dir_is_writable();
	
	// 插件依赖检查
	plugin_check_dependency($dir, 'unstall');
	
	// 卸载插件
	plugin_unstall($dir);
	
	$unstallfile = APP_PATH."plugin/$dir/unstall.php";
	if(is_file($unstallfile)) {
		// 注入安全 IO 包装，限制插件文件操作范围
		require_once APP_PATH . 'lib/xn_safe_io.php';
		$plugin_dir = $dir;
		include _include($unstallfile);
	}
	
	// 删除插件
	//!DEBUG && rmdir_recusive("../plugin/$dir");
	
	plugin_lock_end();

	admin_log_create('plugin_uninstall', 'plugin', $dir, '卸载插件：' . $name);

	$msg = lang('plugin_unstall_sucessfully', array('name'=>$name, 'dir'=>"plugin/$dir"));
	message(0, $msg, array('redirect_url' => http_referer()));
	
} elseif($action == 'enable') {
	
	plugin_lock_start();
	
	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];
	
	// 检查目录可写
	//plugin_check_dir_is_writable();
	
	// 插件依赖检查
	plugin_check_dependency($dir, 'install');
	
	// 启用插件
	plugin_enable($dir);
	
	plugin_lock_end();

	admin_log_create('plugin_enable', 'plugin', $dir, '启用插件：' . $name);

	$msg = lang('plugin_enable_sucessfully', array('name'=>$name));
	message(0, $msg, array('redirect_url' => http_referer()));
	
} elseif($action == 'disable') {
	
	plugin_lock_start();
	
	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];
	
	// 检查目录可写
	//plugin_check_dir_is_writable();
	
	// 插件依赖检查
	plugin_check_dependency($dir, 'unstall');
	
	// 禁用插件
	plugin_disable($dir);
	
	plugin_lock_end();

	admin_log_create('plugin_disable', 'plugin', $dir, '禁用插件：' . $name);

	$msg = lang('plugin_disable_sucessfully', array('name'=>$name));
	message(0, $msg, array('redirect_url' => http_referer()));
	
} elseif($action == 'upgrade') {
	
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
	message(0, $msg, array('redirect_url' => http_referer()));
	
} elseif($action == 'setting') {
	
	$dir = param_word(2);
	plugin_check_exists($dir);
	$name = $plugins[$dir]['name'];
	
	// 对插件设置的 POST 数据进行 XSS 过滤
	if($method == 'POST' && !empty($_POST)) {
		sanitize_plugin_setting($_POST);
	}
	
	// 注入安全 IO 包装，限制插件文件操作范围
	require_once APP_PATH . 'lib/xn_safe_io.php';
	$plugin_dir = $dir;
	include _include(APP_PATH."plugin/$dir/setting.php");

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

?>