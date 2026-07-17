<?php

!defined('DEBUG') AND exit('Access Denied');

$action = param(2, '');

// hook admin_plugin_scanner_start.php

if($action == 'do') {

	// hook admin_plugin_scanner_do_start.php

	plugin_init();

	include APP_PATH . 'lib/PluginScannerRules.php';
	include APP_PATH . 'lib/PluginScannerSuggestion.php';
	include APP_PATH . 'lib/PluginScanner.php';
	$scanner = new PluginScanner();

	// 支持单个插件扫描
	$dir = param('dir', '');
	if(!empty($dir)) {
		$result = $scanner->scanSingle($dir);
		$results = $result !== null ? [$result] : [];
	} else {
		$results = $scanner->scanAll();
	}

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
	exit;

} elseif($action == 'plugins') {

	// 返回插件列表（用于下拉选择）
	plugin_init();

	include APP_PATH . 'lib/PluginScannerRules.php';
	include APP_PATH . 'lib/PluginScannerSuggestion.php';
	include APP_PATH . 'lib/PluginScanner.php';
	$scanner = new PluginScanner();

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($scanner->getPluginList(), JSON_UNESCAPED_UNICODE);
	exit;

} elseif($action == 'preinstall') {

	// 安装前预扫描
	$dir = param('dir', '');
	if(empty($dir)) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['can_install' => false, 'fatal' => [], 'warning' => [], 'summary' => '缺少插件目录参数'], JSON_UNESCAPED_UNICODE);
		exit;
	}

	plugin_init();

	include APP_PATH . 'lib/PluginScannerRules.php';
	include APP_PATH . 'lib/PluginScannerSuggestion.php';
	include APP_PATH . 'lib/PluginScanner.php';
	$scanner = new PluginScanner();
	$result = $scanner->scanBeforeInstall($dir);

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
	exit;

} elseif($action == 'detail') {

	$dir = param_word(3);
	if(empty($dir)) message(-1, 'Plugin dir required');

	message(0, '详情功能暂不可用', array('redirect_url' => admin_plugin_scanner_url()));

} elseif($action == 'export') {

	$dir = param('dir', '');

	plugin_init();

	include APP_PATH . 'lib/PluginScannerRules.php';
	include APP_PATH . 'lib/PluginScannerSuggestion.php';
	include APP_PATH . 'lib/PluginScanner.php';
	$scanner = new PluginScanner();

	if(!empty($dir)) {
		$result = $scanner->scanSingle($dir);
		$results = $result !== null ? [$result] : [];
	} else {
		$results = $scanner->scanAll();
	}

	$csv = "插件名,版本,文件,行号,类别,匹配内容,严重级别,建议\n";
	foreach($results as $plugin) {
		foreach($plugin['issues'] as $issue) {
			// CSV 保持原始粒度：合并后的 issue 通过 lines 数组展开为多行
			$lines = isset($issue['lines']) && !empty($issue['lines']) ? $issue['lines'] : [$issue['line']];
			foreach($lines as $ln) {
				$csv .= implode(',', [
					'"' . $plugin['dir'] . '"',
					'"' . $plugin['version'] . '"',
					'"' . $issue['file'] . '"',
					$ln,
					'"' . $issue['category'] . '"',
					'"' . str_replace('"', '""', $issue['match']) . '"',
					'"' . $issue['severity'] . '"',
					'"' . str_replace('"', '""', $issue['suggestion']) . '"',
				]) . "\n";
			}
		}
	}

	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename=plugin_scan_' . date('Ymd_His') . '.csv');
	echo $csv;
	exit;

} else {

	// hook admin_plugin_scanner_get_start.php

	plugin_init();

	include APP_PATH . 'lib/PluginScannerRules.php';
	include APP_PATH . 'lib/PluginScannerSuggestion.php';
	include APP_PATH . 'lib/PluginScanner.php';
	$scanner = new PluginScanner();
	$rulesSummary = $scanner->getRulesSummary();
	$pluginList = $scanner->getPluginList();

	$header['title'] = '插件兼容性检查';
	$header['mobile_title'] = '插件检查';

	include _include(ADMIN_PATH.'view/htm/plugin_scanner.htm');
}

// hook admin_plugin_scanner_end.php

?>
