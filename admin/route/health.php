<?php

!defined('DEBUG') AND exit('Access Denied.');

include_once APP_PATH . 'lib/HealthCheckService.php';

$action = param(1, 'index');

// hook admin_health_start.php

if($action == 'index') {

	if($method == 'GET') {
		// 读取检测结果（优先使用缓存）
		$force = param('force', 0);
		$result = HealthCheckService::runAll($force ? true : false);

		$header['title'] = lang('admin_health_title');
		$header['mobile_title'] = lang('admin_health_title');

		include _include(ADMIN_PATH.'view/htm/health.htm');
	} else {
		// POST: 重新检测（绕过缓存）
		CsrfService::check();

		$result = HealthCheckService::runAll(true);

		// 记录管理员操作日志
		admin_log_create('health_check', 'health', '', lang('admin_health_recheck_log') . $result['score']);

		message(0, lang('admin_health_check_complete'), array('redirect_url' => admin_health_url()));
	}
}

// hook admin_health_end.php

?>
