<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, '');

include APP_PATH . 'lib/UpgradeService.php';
if(!class_exists('SecurityConfigService')) include APP_PATH . 'lib/security/SecurityConfigService.php';
$upgradeService = new UpgradeService($db, $conf);

// hook admin_upgrade_start.php

if($action == 'do') {

	$step = param('step', '');
	if(empty($step)) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['ok' => false, 'message' => 'Step required'], JSON_UNESCAPED_UNICODE);
		exit;
	}

	$result = $upgradeService->executeStep($step);

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($result, JSON_UNESCAPED_UNICODE);
	exit;

} elseif($action == 'status') {

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'version' => $upgradeService->getInstalledVersion(),
		'target_version' => XIUNOX_VERSION,
		'php_version' => PHP_VERSION,
		'db_type' => $conf['db']['type'] ?? 'unknown',
		'plugins_count' => count(glob(APP_PATH . 'plugin/*', GLOB_ONLYDIR)),
	], JSON_UNESCAPED_UNICODE);
	exit;

} else {

	// hook admin_upgrade_get_start.php

	$header['title'] = '一键升级';
	$header['mobile_title'] = '升级';

	$prerequisites = $upgradeService->checkPrerequisites();
	$steps = $upgradeService->getSteps();
	// 已安装版本（来自 kv 持久化存储，与代码版本 XIUNOX_VERSION 区分）
	$installedVersion = $upgradeService->getInstalledVersion();

	include _include(ADMIN_PATH.'view/htm/upgrade.htm');
}

// hook admin_upgrade_end.php

?>
