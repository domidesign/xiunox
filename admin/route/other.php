<?php

!defined('DEBUG') AND exit('Access Denied.');

include_once APP_PATH . 'lib/CacheService.php';

// URL 解析用 - 分割路径段，other-cache-warmup 拆为 param(0)=other, param(1)=cache, param(2)=warmup
// 拼接后续段以支持多段 action（如 cache-warmup、cache-clear-plugin）
$action = param(1, 'cache');
$action_parts = array();
for($i = 2; $i <= 5; $i++) {
    $seg = param($i, '');
    if($seg === '') break;
    $action_parts[] = $seg;
}
if(!empty($action_parts)) $action = $action . '-' . implode('-', $action_parts);

// hook admin_other_start.php

if($action == 'cache') {

	// hook admin_other_cache_get_post.php

	if($method == 'GET') {

		// hook admin_other_cache_get_end.php

		$input = array();
		$input['clear_cache'] = form_checkbox('clear_cache', 1);
		$input['clear_tmp'] = form_checkbox('clear_tmp', 1);
		$input['clear_opcache'] = form_checkbox('clear_opcache', 1);
		include _include(ADMIN_PATH.'view/htm/other_cache.htm');

	} else {

		CsrfService::check();

		$types = array();
		if(param('clear_cache')) $types[] = 'data';
		if(param('clear_tmp')) $types[] = 'tmp';
		if(param('clear_opcache')) $types[] = 'opcache';

		if(empty($types)) {
			message(-1, lang('admin_cache_select_type'));
		}

		$cleared = CacheService::clearByType($types);

		// hook cache_clear_after.php

		// 记录管理员操作日志
		admin_log_create('cache_clear', 'cache', '', '清理缓存：' . implode(',', $types));

		$msg = lang('admin_clear_successfully');
		if(!empty($cleared)) {
			$msg .= '：' . implode('、', $cleared);
		}

		message(0, $msg);
	}
}

elseif($action == 'cache_setting') {

	if($method == 'GET') {
		$config = CacheService::getConfig();
		$status = CacheService::getStatus();
		$drivers = CacheService::getAvailableDrivers();
		$opcacheStatus = CacheService::getOpcacheStatus();
		// 核心缓存键清单和用户自定义 TTL 配置（后台 TTL 配置区块用）
		$coreTtlKeys = class_exists('CacheHelper', false) ? CacheHelper::getCoreTtlKeys() : array();
		$ttlConfig = class_exists('CacheHelper', false) ? CacheHelper::getTtlConfig() : array();
		// 当前缓存前缀（从当前驱动配置读取）
		$currentCachepre = isset($config[$config['type']]['cachepre']) ? $config[$config['type']]['cachepre'] : 'bbs_';
		include _include(ADMIN_PATH.'view/htm/other_cache_setting.htm');

	} else {
		CsrfService::check();

		$act = param('act');

		// 测试连接
		if($act == 'test_connection') {
			$type = param('type');
			$conf = array();
			if($type == 'redis') {
				$conf['host'] = param('redis_host');
				$conf['port'] = param('redis_port');
				// 密码关闭 htmlspecialchars，避免含 & < > " ' 的密码被转义导致认证失败
				$conf['password'] = param('redis_password', '', FALSE);
				$conf['database'] = param('redis_database');
			} elseif($type == 'memcached') {
				$conf['host'] = param('memcached_host');
				$conf['port'] = param('memcached_port');
			}
			$result = CacheService::testConnection($type, $conf);
			message($result['success'] ? 0 : -1, $result['message']);
		}

		// 保存配置
		$config = array();
		$config['enable'] = param('enable') ? 1 : 0;
		$config['type'] = param('type');
		$config['default_ttl'] = max(60, intval(param('default_ttl', 3600)));

		// 缓存前缀：只允许字母、数字、下划线、短横线，1-32 字符
		// 用于和其他系统共享 Redis/Memcached 时区分键名空间
		$cachepre = trim(param('cachepre', 'bbs_', FALSE));
		if($cachepre === '' || !preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $cachepre)) {
			message(-1, lang('admin_cache_prefix_invalid'));
		}

		// 读取旧配置，密码留空时保留旧密码
		$oldConfig = CacheService::getConfig();
		$oldCachepre = isset($oldConfig[$config['type']]['cachepre']) ? $oldConfig[$config['type']]['cachepre'] : 'bbs_';

		// cachepre 变化时，先用旧前缀清空数据缓存，避免旧键成为孤儿（新前缀删不掉）
		if($cachepre !== $oldCachepre && function_exists('cache_delete_prefix') && !empty($_SERVER['cache'])) {
			cache_delete_prefix('');
		}

		// 驱动配置（统一用用户输入的 cachepre）
		$config['file'] = array('cachepre' => $cachepre, 'cache_dir' => '');

		$newPassword = param('redis_password', '', FALSE);
		$config['redis'] = array(
			'host' => param('redis_host', '127.0.0.1'),
			'port' => intval(param('redis_port', 6379)),
			// 密码关闭 htmlspecialchars，避免含 & < > " ' 的密码被转义导致认证失败
			// 密码留空表示不修改，保留旧密码
			'password' => $newPassword !== '' ? $newPassword : (isset($oldConfig['redis']['password']) ? $oldConfig['redis']['password'] : ''),
			'database' => intval(param('redis_database', 0)),
			'cachepre' => $cachepre,
		);
		$config['memcached'] = array(
			'host' => param('memcached_host', '127.0.0.1'),
			'port' => intval(param('memcached_port', 11211)),
			'cachepre' => $cachepre,
		);
		$config['mysql'] = array('cachepre' => $cachepre);

		// 保存前验证连接：缓存启用时必须验证驱动连接可用，避免错误密码/地址保存后网站白屏
		if(!empty($config['enable']) && in_array($config['type'], array('redis', 'memcached'))) {
			$driverConf = isset($config[$config['type']]) ? $config[$config['type']] : array();
			$testResult = CacheService::testConnection($config['type'], $driverConf);
			if(!$testResult['success']) {
				message(-1, lang('admin_cache_connection_failed') . '：' . $testResult['message']);
			}
		}

		CacheService::saveConfig($config);

		// 保存核心缓存键的自定义 TTL 配置
		$ttlConfig = param('ttl_config', array());
		if(is_array($ttlConfig) && class_exists('CacheHelper', false)) {
			CacheHelper::saveTtlConfig($ttlConfig);
		}

		// 保存缓存配置后，必须清除 setting 缓存（bbs_cache 表中的 bbs_setting 记录）
		// 否则下次请求 setting_get('cache_config') 会读到旧的缓存值（type=file），
		// 而不是从 bbs_kv 表读取最新值（type=memcached/redis）
		// 同时重置 $g_setting 全局变量，避免同请求内读到旧值
		global $g_setting;
		$g_setting = FALSE;
		cache_delete('setting');

		// 保存配置后清理 tmp 编译缓存，确保下次请求重新初始化缓存驱动
		// 避免改了 Redis 密码/host 后旧实例仍被复用
		$tmp_path = isset($conf['tmp_path']) ? $conf['tmp_path'] : './tmp/';
		if(is_dir($tmp_path)) {
			$files = glob($tmp_path . '*');
			if($files) {
				foreach($files as $f) {
					if(basename($f) === 'cache') continue;
					if(is_file($f)) {
						@unlink($f);
					} elseif(is_dir($f)) {
						rmdir_recusive($f, 0);
					}
				}
			}
		}

		message(0, lang('admin_cache_setting_saved'));
	}
}

elseif($action == 'icon_preview') {
	include _include(ADMIN_PATH.'view/htm/icon_preview.htm');
}

// 缓存预热
elseif($action == 'cache-warmup') {
	if($method == 'GET') {
		// GET 请求重定向到缓存设置页
		http_location(url('other-cache_setting'));
	} elseif($method == 'POST') {
		CsrfService::check();
		$target = param('target', 'all');
		$result = CacheService::warmupCache($target);

		// 记录管理员操作日志
		admin_log_create('cache_warmup', 'cache', '', '缓存预热：' . $target);

		$msg = '缓存预热完成';
		if(!empty($result['details'])) {
			$msg .= '（成功 ' . $result['success'] . ' 项，失败 ' . $result['fail'] . ' 项）：' . implode('；', $result['details']);
		}
		message(0, $msg);
	}
}

// 清除单个插件缓存
elseif($action == 'cache-clear-plugin') {
	if($method == 'POST') {
		CsrfService::check();
		$plugin = param('plugin');
		if(empty($plugin)) {
			message(-1, '插件名不能为空');
		}

		$deleted = CacheService::clearPluginCache($plugin);

		// 记录管理员操作日志
		admin_log_create('cache_clear_plugin', 'cache', '', '清除插件缓存：' . $plugin . '（' . $deleted . ' 个键）');

		message(0, '已清除插件 ' . $plugin . ' 的 ' . $deleted . ' 个缓存键');
	}
}

// hook admin_other_end.php

?>
