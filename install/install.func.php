<?php

function get_env(&$env, &$write) {
	$env['os']['name'] = lang('os');
	$env['os']['must'] = TRUE;
	$env['os']['current'] = PHP_OS;
	$env['os']['need'] = lang('unix_like');
	$env['os']['status'] = 1;
	// glob gzip
	//$env['os']['disable'] = 1;

	$env['php_version']['name'] = lang('php_version');
	$env['php_version']['must'] = TRUE;
	$env['php_version']['current'] = PHP_VERSION;
	$env['php_version']['need'] = '8.0';
	$env['php_version']['status'] = version_compare(PHP_VERSION, '8.0.0', '>=');

	$env['pdo_mysql']['name'] = 'pdo_mysql';
	$env['pdo_mysql']['must'] = TRUE;
	$env['pdo_mysql']['current'] = extension_loaded('pdo_mysql') ? lang('supported') : lang('not_supported');
	$env['pdo_mysql']['need'] = lang('required');
	$env['pdo_mysql']['status'] = extension_loaded('pdo_mysql') ? 1 : 0;

	$env['gd']['name'] = 'GD';
	$env['gd']['must'] = FALSE;
	$env['gd']['current'] = extension_loaded('gd') ? lang('supported') : lang('not_supported');
	$env['gd']['need'] = lang('recommended');
	$env['gd']['status'] = extension_loaded('gd') ? 1 : 2;

	// HTTPS 检测：建议使用 HTTPS 以保障数据传输安全
	$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
		|| (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
	$env['https']['name'] = 'HTTPS';
	$env['https']['must'] = FALSE;
	$env['https']['current'] = $is_https ? lang('supported') : lang('not_supported');
	$env['https']['need'] = lang('recommended');
	$env['https']['status'] = $is_https ? 1 : 2;

	// 目录可写
	$writedir = array(
		'../conf/',
		'../log/',
		'../tmp/',
		'../upload/',
		'../plugin/'
	);

	$write = array();
	foreach($writedir as &$dir) {
		$write[$dir] = xn_is_writable('./'.$dir);
	}
}

function install_sql_file($sqlfile) {
	global $errno, $errstr;
	$s = file_get_contents($sqlfile);
	$s = str_replace(";\r\n", ";\n", $s);
	//$s = preg_replace('/#(.*?)\r\n/i', "", $s);
	$arr = explode(";\n", $s);
	foreach ($arr as $sql) {
		$sql = trim($sql);
		if(empty($sql)) continue;
		db_exec($sql) === FALSE AND message(-1, "sql: $sql, errno: $errno, errstr: $errstr");
	}
}



?>
