<?php

!defined('DEBUG') AND exit('Access Denied.');

include XIUNOPHP_PATH.'xn_zip.func.php';
include APP_PATH . 'lib/OfficialPluginService.php';

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

		// 检测是否需要升级：已安装 + conf.json.version 与 db.version 不一致
		// ponytail: 不要求 has_upgrade_file——纯 JS/CSS/语言包/模板修改的插件没有 upgrade.php，
		//   递增版本号也应显示升级按钮，让用户点击后同步 db 版本号 + 清缓存（强制刷新静态资源）
		//   违反 project_rules"任何插件修改必须递增 version 触发需升级提示"会导致版本号形同虚设
		$plugin['need_upgrade'] = 0;
		if (!empty($plugin['installed'])) {
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
	
	// forceBlocked 不可被 force 跳过（conf_version/conf_required_fields 等强制阻断分类）
	// ponytail: force=1 时仅放行非 forceBlocked 的 fatal/error，forceBlocked 仍阻止；force=0 时全部阻止
	$hardBlocked = !empty($scanResult['force_blocked']);
	$softBlocked = !$scanResult['can_install'];
	if($hardBlocked || ($softBlocked && !$force)) {
		// 有致命问题，阻止安装，返回扫描结果
		plugin_lock_end();
		$blockedList = [];
		$shown = [];
		// force=1 时只显示 force_blocked；force=0 时显示 fatal + force_blocked + error（去重）
		$keys = $force ? array('force_blocked') : array('fatal', 'force_blocked', 'error');
		foreach($keys as $key) {
			if(empty($scanResult[$key])) continue;
			foreach($scanResult[$key] as $f) {
				$k = $f['file'].':'.$f['line'].':'.$f['category'];
				if(isset($shown[$k])) continue;
				$shown[$k] = true;
				$blockedList[] = $f['file'].':'.$f['line'].' '.$f['suggestion'];
			}
		}
		$msg = lang('plugin_install_blocked', array('name'=>$name, 'issues'=>implode("\n", $blockedList)));
		message(-1, $msg);
	}
	
	// 检查目录可写 / check directory writable
	//plugin_check_dir_is_writable();
	
	// 插件依赖检查 / check plugin dependency
	plugin_check_dependency($dir, 'install');
	
	// 安装插件 / install plugin
	plugin_install($dir);

	// 同步作者信息：优先读 6h 缓存（无远程延迟），缓存命中时遍历 manifest 写入 author_name/author_homepage
	// ponytail: FTP/压缩包安装不走 OfficialPluginService，需在此补同步；manifest 无此插件则保持空值
	try {
		$_sync_service = new OfficialPluginService();
		$_sync_service->syncInstalledPluginsAuthorInfo(false);
	} catch (\Throwable $e) {
		xn_log('plugin_install sync author error: ' . $e->getMessage(), 'plugin_install_error');
	}

	$installfile = APP_PATH."plugin/$dir/install.php";
	if(is_file($installfile)) {
		// 注入安全 IO 包装，限制插件文件操作范围
		require_once APP_PATH . 'lib/xn_safe_io.php';
		$plugin_dir = $dir;
		include _include($installfile);
	}
	
	plugin_lock_end();

	admin_log_create('plugin_install', 'plugin', $dir, '安装插件：' . $name);

	// 同类插件互斥：禁用其他已安装的同类插件（保留配置便于切换）。
// 之前的实现用 plugin_unstall() 会执行 uninstall.php 清掉数据，过于激进；
// 现统一改为 plugin_disable() 仅禁用，主题类与非主题类逻辑一致。
// 匹配规则见 plugin_find_conflicts()：主题（第二段theme）全部互斥；基础功能（两段）按第二段互斥；扩展（三段+）不互斥。
$conflicts = plugin_find_conflicts($dir);
if(!empty($conflicts)) {
	foreach($conflicts as $c) {
		// 仅禁用处于启用状态的冲突插件，已禁用的跳过（避免无谓的 tmp 清理和重复日志）
		if(!empty($c['enable'])) {
			plugin_disable($c['dir']);
			admin_log_create('plugin_disable', 'plugin', $c['dir'], '安装同类插件 ' . $name . ' 自动禁用：' . $c['name']);
		}
	}
}
	
	$msg = lang('plugin_install_sucessfully', array('name'=>$name));

	// 递增 static_version，强制浏览器刷新 JS/CSS 缓存
	if (function_exists('conf_bump_static_version')) {
		conf_bump_static_version();
	}

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

// 同类插件互斥：启用插件时自动禁用其他已启用的同类插件（保留配置，方便切换）
// 与 install 分支使用相同的 plugin_find_conflicts() 规则：主题全部互斥；基础功能按第二段互斥；扩展不互斥
// 之前的实现仅对 _theme_ 字符串包含判断做主题互斥，遗漏了非主题类同类插件的启用冲突
$conflicts_on_enable = plugin_find_conflicts($dir);
if(!empty($conflicts_on_enable)) {
	foreach($conflicts_on_enable as $c) {
		if(!empty($c['enable'])) {
			plugin_disable($c['dir']);
			admin_log_create('plugin_disable', 'plugin', $c['dir'], '启用同类插件 ' . $name . ' 自动禁用：' . $c['name']);
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

		// 同步作者信息：优先读 6h 缓存（无远程延迟），缓存命中时遍历 manifest 写入 author_name/author_homepage
		// ponytail: FTP/压缩包升级不走 OfficialPluginService，需在此补同步
		try {
			$_sync_service = new OfficialPluginService();
			$_sync_service->syncInstalledPluginsAuthorInfo(false);
		} catch (\Throwable $e) {
			xn_log('plugin_upgrade sync author error: ' . $e->getMessage(), 'plugin_upgrade_error');
		}

		$upgradefile = APP_PATH."plugin/$dir/upgrade.php";
		if(is_file($upgradefile)) {
			// 注入安全 IO 包装，限制插件文件操作范围
			require_once APP_PATH . 'lib/xn_safe_io.php';
			$plugin_dir = $dir;
			include _include($upgradefile);
		}

		plugin_lock_end();

		admin_log_create('plugin_upgrade', 'plugin', $dir, '升级插件：' . $name);

		// 递增 static_version，强制浏览器刷新 JS/CSS 缓存
		if (function_exists('conf_bump_static_version')) {
			conf_bump_static_version();
		}

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
	// 检查插件是否已启用且已安装：未启用插件的 Service 类不会被加载，
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
	// ponytail: 不静默吞错——细分 4 种失败原因给用户独立文案，避免「报错但不知为何」
	$confData = null;
	$pluginDir = '';
	$srcDir = '';

	// 先扫顶层结构，过滤 macOS Finder 生成的 __MACOSX 干扰目录
	$topEntries = glob($tmpDir.'*', GLOB_ONLYDIR);
	$realSubDirs = array();
	$hasMacosx = false;
	if(is_array($topEntries)) {
		foreach($topEntries as $d) {
			$base = basename($d);
			if($base === '__MACOSX') { $hasMacosx = true; continue; }
			// ponytail: macOS Finder 压缩还会给每个文件生成 ._ 前缀的资源 forks，目录形态下不会进 GLOB_ONLYDIR，glob 仅此一处过滤即可
			$realSubDirs[] = $d;
		}
	}

	$rootConf = $tmpDir.'conf.json';
	if(is_file($rootConf)) {
		// 结构 A：conf.json 在 zip 根目录
		$pluginDir = preg_replace('/\.zip$/i', '', $uploadName);
		$srcDir = $tmpDir;
		$confRaw = file_get_contents($rootConf);
		$confData = json_decode($confRaw, true);
	} elseif(count($realSubDirs) === 1 && is_file($realSubDirs[0].'/conf.json')) {
		// 结构 B：zip 内有一层目录
		$pluginDir = basename($realSubDirs[0]);
		$srcDir = $realSubDirs[0];
		$confRaw = file_get_contents($realSubDirs[0].'/conf.json');
		$confData = json_decode($confRaw, true);
	} elseif(count($realSubDirs) > 1) {
		// 失败原因 1：多个顶层目录，无法判断哪个是插件根
		rmdir_recusive($tmpDir);
		plugin_lock_end();
		message(-1, lang('plugin_upload_conf_multi_top', array('dirs'=>implode(', ', array_map('basename', $realSubDirs)))));
	} else {
		// 失败原因 2：根目录和唯一子目录都没有 conf.json
		rmdir_recusive($tmpDir);
		plugin_lock_end();
		$hint = $hasMacosx ? lang('plugin_upload_conf_macosx_hint') : '';
		message(-1, lang('plugin_upload_conf_missing') . $hint);
	}

	// 失败原因 3：json_decode 返回 null —— 多半是 conf.json 带 UTF-8 BOM
	// ponytail: 核心代码用 xn_json_decode 会剥 BOM，这里用裸 json_decode 不兼容 BOM，单独提示
	if($confData === null) {
		$first3 = substr(isset($confRaw) ? $confRaw : '', 0, 3);
		$isBom = ($first3 === "\xEF\xBB\xBF");
		rmdir_recusive($tmpDir);
		plugin_lock_end();
		$hint = $isBom ? lang('plugin_upload_conf_bom_hint') : lang('plugin_upload_conf_json_error');
		message(-1, lang('plugin_upload_conf_invalid') . $hint);
	}
	// 失败原因 4：json_decode 返回了非 null 的空结构（空数组/空对象），说明 conf.json 是个空文件或空 JSON
	if(!is_array($confData) || empty($confData)) {
		rmdir_recusive($tmpDir);
		plugin_lock_end();
		message(-1, lang('plugin_upload_conf_invalid') . lang('plugin_upload_conf_empty'));
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
			// 合并 fatal + force_blocked + error 去重显示（原代码只显示 fatal，error 级问题不可见）
			$blockedList = array();
			$shown = array();
			foreach(array('fatal', 'force_blocked', 'error') as $key) {
				if(empty($scanResult[$key])) continue;
				foreach($scanResult[$key] as $f) {
					$k = $f['file'].':'.$f['line'].':'.$f['category'];
					if(isset($shown[$k])) continue;
					$shown[$k] = true;
					$blockedList[] = $f['file'].':'.$f['line'].' '.$f['suggestion'];
				}
			}
			$msg = lang('plugin_install_blocked', array('name'=>$pluginName, 'issues'=>implode("\n", $blockedList)));
			message(-1, $msg);
		}

		// 依赖检查
		plugin_check_dependency($pluginDir, 'install');

		// 安装（写 conf.json + 数据库 + 清缓存，不执行 install.php）
		plugin_install($pluginDir);

		// 同步作者信息：优先读 6h 缓存（无远程延迟），manifest 无此插件则保持空值
		try {
			$_sync_service = new OfficialPluginService();
			$_sync_service->syncInstalledPluginsAuthorInfo(false);
		} catch (\Throwable $e) {
			xn_log('plugin_upload_install sync author error: ' . $e->getMessage(), 'plugin_install_error');
		}

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

		// 备份旧版本到 plugin/{dir}.bak/
		$bakPath = APP_PATH.'plugin/'.$pluginDir.'.bak/';
		if(is_dir($bakPath)) {
			rmdir_recusive($bakPath); // 清理残留的旧备份
		}
		rename($pluginPath, $bakPath);

		// 移入新版本
		if(!rmove_dir($srcDir, $pluginPath)) {
			// 移动失败，回滚（此时插件仍是 enabled 状态，无需 re-enable）
			if(is_dir($pluginPath)) rmdir_recusive($pluginPath);
			rename($bakPath, $pluginPath);
			if(is_dir($tmpDir)) rmdir_recusive($tmpDir);
			plugin_lock_end();
			message(-1, lang('plugin_upload_move_failed'));
		}

		// 清理临时目录
		if(is_dir($tmpDir)) rmdir_recusive($tmpDir);

		// 升级前自动禁用，防止 upgrade.php 执行时被运行代码冲突
		// ponytail: 必须在 rmove_dir 之后调用——plugin_disable 内部的 plugin_clear_tmp_dir()
		// 会清空整个 tmp/，若在 rmove_dir 之前调用会把刚解压的 $srcDir 一起删掉，
		// 导致 rmove_dir 因源目录不存在而失败，触发"文件移动失败"误报（已违反 1 次）
		if($wasEnabled) {
			plugin_disable($pluginDir);
		}

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

		// 同步作者信息：优先读 6h 缓存（无远程延迟），manifest 无此插件则保持空值
		try {
			$_sync_service = new OfficialPluginService();
			$_sync_service->syncInstalledPluginsAuthorInfo(false);
		} catch (\Throwable $e) {
			xn_log('plugin_upload_upgrade sync author error: ' . $e->getMessage(), 'plugin_upgrade_error');
		}

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

	// 递增 static_version，强制浏览器刷新 JS/CSS 缓存
	if (function_exists('conf_bump_static_version')) {
		conf_bump_static_version();
	}

	$msg = lang('plugin_upload_upgrade_success', array('name'=>$pluginName, 'version'=>$newVersion));
	message(0, $msg, array('redirect_url' => admin_plugin_url()));
	}

} elseif($action == 'scanner') {

	include _include(ADMIN_PATH.'route/plugin_scanner.php');

} elseif($action == 'official') {

	// 官方插件市场列表 / official plugin marketplace list
	$service = new OfficialPluginService();
	$force = param('force', 0);
	$result = $service->fetchManifest($force == 1);

	$official_plugins = array();
	$fetch_error = '';
	$last_update = 0;
	$is_stale = false;

	if (!empty($result['ok'])) {
		$manifest = $result['data'];
		$last_update = isset($manifest['fetched_at']) ? intval($manifest['fetched_at']) : 0;
		$is_stale = !empty($result['stale']);

		// 比对本地版本 / compare with local plugins
		$official_plugins = $service->compareWithLocal($manifest, $plugins);

		// 同步已安装插件的作者信息到 bbs_plugin（manifest 已在内存中，无额外远程调用）
		// ponytail: 避免远程查询延迟——清单已在手，直接写 db，本地列表页只读 db
		$service->syncAuthorInfoFromManifest($manifest);
	} else {
		$fetch_error = isset($result['message']) ? $result['message'] : 'Unknown error';
	}

	// 筛选/搜索参数 / filter and search params
	$filter_free = param('free', 0); // 0:全部 1:免费 2:付费
	$keyword = trim(param('keyword', ''));

	// 对 official_plugins 执行过滤（原代码只接收参数未实际过滤，导致搜索无效）
	if (!empty($official_plugins) && ($filter_free || $keyword !== '')) {
		$filtered = array();
		foreach ($official_plugins as $dir => $info) {
			$p = $info['manifest'];
			// 类型筛选：1=免费 2=付费
			if ($filter_free == 1 && empty($p['free'])) continue;
			if ($filter_free == 2 && !empty($p['free'])) continue;
			// 关键词搜索：匹配 name / dir / brief / author
			if ($keyword !== '') {
				$haystack = implode(' ', array(
					isset($p['name']) ? $p['name'] : '',
					isset($p['dir']) ? $p['dir'] : '',
					isset($p['brief']) ? $p['brief'] : '',
					isset($p['author']) ? $p['author'] : '',
				));
				if (stripos($haystack, $keyword) === false) continue;
			}
			$filtered[$dir] = $info;
		}
		$official_plugins = $filtered;
	}

	// 传递给模板 / pass to template
	$input['free'] = $filter_free;
	$input['keyword'] = $keyword;

	$header['title'] = lang('admin_plugin_marketplace');
	$header['mobile_title'] = lang('admin_plugin_marketplace');

	include _include(ADMIN_PATH."view/htm/plugin_official_list.htm");

} elseif($action == 'official_install') {

	// 下载并安装免费插件 / download and install free plugin
	// CSRF 校验 / CSRF check
	CsrfService::check();

	$dir = param_word(2);
	$version = param(3);

	if (empty($dir) || empty($version)) {
		message(-1, lang('plugin_marketplace_download_failed'));
	}

	$service = new OfficialPluginService();
	$result = $service->downloadAndInstall($dir, $version);

	if (!empty($result['ok'])) {
		$name = isset($plugins[$dir]['name']) ? $plugins[$dir]['name'] : $dir;
		admin_log_create('plugin_install', 'plugin', $dir, '安装官方插件：' . $name);
		// 递增 static_version，强制浏览器刷新 JS/CSS 缓存
		if (function_exists('conf_bump_static_version')) {
			conf_bump_static_version();
		}
		message(0, lang('plugin_marketplace_install_success', array('name' => $name)));
	} else {
		message(-1, isset($result['message']) ? $result['message'] : lang('plugin_marketplace_download_failed'));
	}

} elseif($action == 'official_upgrade') {

	// 下载并升级插件（启用/禁用均可）/ download and upgrade plugin (enabled or disabled)
	// CSRF 校验 / CSRF check
	CsrfService::check();

	$dir = param_word(2);
	$version = param(3);

	if (empty($dir) || empty($version)) {
		message(-1, lang('plugin_marketplace_download_failed'));
	}

	$service = new OfficialPluginService();
	$result = $service->downloadAndUpgrade($dir, $version);

	if (!empty($result['ok'])) {
		$name = isset($plugins[$dir]['name']) ? $plugins[$dir]['name'] : $dir;
		admin_log_create('plugin_upgrade', 'plugin', $dir, '升级官方插件：' . $name);
		// 递增 static_version，强制浏览器刷新 JS/CSS 缓存
		if (function_exists('conf_bump_static_version')) {
			conf_bump_static_version();
		}
		message(0, lang('plugin_marketplace_upgrade_success', array('name' => $name, 'version' => $version)));
	} else {
		$msg = isset($result['message']) ? $result['message'] : lang('plugin_marketplace_download_failed');
		message(-1, $msg);
	}

} elseif($action == 'official_refresh') {

	// 强制刷新清单缓存 + jsdelivr CDN 缓存 / force refresh manifest + jsdelivr CDN cache
	// CSRF 校验（支持 GET，用 CsrfService::check() 即可）
	CsrfService::check();

	$service = new OfficialPluginService();
	$result = $service->forceRefresh();

	if (!empty($result['ok'])) {
		// 拼接 CDN 刷新结果信息
		$purgeMsg = '';
		if (isset($result['purge_status'])) {
			$ps = $result['purge_status'];
			$purged = isset($ps['purged']) ? intval($ps['purged']) : 0;
			$failed = isset($ps['failed']) ? intval($ps['failed']) : 0;
			if ($purged > 0) {
				$purgeMsg = '（CDN 已刷新 ' . $purged . ' 个文件';
				if ($failed > 0) {
					$purgeMsg .= '，' . $failed . ' 个失败';
				}
				$purgeMsg .= '）';
			}
		}
		message(0, lang('plugin_marketplace_refreshed') . $purgeMsg);
	} else {
		message(-1, isset($result['message']) ? $result['message'] : lang('plugin_marketplace_fetch_failed'));
	}

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