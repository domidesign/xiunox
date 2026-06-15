<?php

!defined('DEBUG') AND exit('Access Denied.');

include_once APP_PATH . 'lib/CacheService.php';

$action = param(1, 'cache');

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
				$conf['password'] = param('redis_password');
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
		$config['auto_warmup'] = param('auto_warmup') ? 1 : 0;

		// 驱动配置
		$config['file'] = array('cachepre' => 'bbs_', 'cache_dir' => '');
		$config['redis'] = array(
			'host' => param('redis_host', '127.0.0.1'),
			'port' => intval(param('redis_port', 6379)),
			'password' => param('redis_password', ''),
			'database' => intval(param('redis_database', 0)),
			'cachepre' => 'bbs_',
		);
		$config['memcached'] = array(
			'host' => param('memcached_host', '127.0.0.1'),
			'port' => intval(param('memcached_port', 11211)),
			'cachepre' => 'bbs_',
		);
		$config['mysql'] = array('cachepre' => 'bbs_');

		CacheService::saveConfig($config);
		message(0, lang('admin_cache_setting_saved'));
	}
}

elseif($action == 'icon_preview') {
	include _include(ADMIN_PATH.'view/htm/icon_preview.htm');
}

// hook admin_other_end.php

?>
