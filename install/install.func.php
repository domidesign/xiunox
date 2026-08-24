<?php

function get_env(&$env, &$write) {
	$env['os']['name'] = lang('os');
	$env['os']['must'] = FALSE;
	$env['os']['current'] = PHP_OS;
	$env['os']['need'] = lang('unix_like');
	// Windows 不推荐：DIRECTORY_SEPARATOR 为 '\' 时为 Windows
	$is_windows = (DIRECTORY_SEPARATOR === '\\');
	$env['os']['status'] = $is_windows ? 2 : 1;
	if($is_windows) {
		$env['os']['current'] = PHP_OS . ' (' . lang('os_windows_not_recommended') . ')';
	}

	// 子目录部署检测：安装向导位于 /install/index.php
	// SCRIPT_NAME 去掉 /install/... 后缀即为 base path，非空且非 / 则为子目录
	$script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : (isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');
	$base_path = preg_replace('#/install/.*$#', '', $script_name);
	$is_subdir = $base_path !== '' && $base_path !== '/';
	$env['deploy_path']['name'] = lang('deploy_path');
	$env['deploy_path']['must'] = FALSE;
	$env['deploy_path']['current'] = $is_subdir ? lang('subdir_detected') : lang('root_dir');
	$env['deploy_path']['need'] = lang('root_dir');
	$env['deploy_path']['status'] = $is_subdir ? 2 : 1;

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

	// MySQL 服务端版本要求（实际版本在下一步数据库连接后检测）
	$env['mysql_version']['name'] = lang('mysql_version');
	$env['mysql_version']['must'] = TRUE;
	$env['mysql_version']['current'] = lang('mysql_version_pending');
	$env['mysql_version']['need'] = '5.7+';
	$env['mysql_version']['status'] = 2; // 建议项，连接数据库后再实际校验

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

function install_sql_file($sqlfile, $tablepre = 'bbs_') {
	global $errno, $errstr;
	$s = file_get_contents($sqlfile);
	$s = str_replace(";\r\n", ";\n", $s);
	// 替换表前缀：install.sql 中所有 bbs_ 表名前缀替换为用户指定前缀
	if ($tablepre !== 'bbs_') {
		$s = str_replace('bbs_', $tablepre, $s);
	}
	//$s = preg_replace('/#(.*?)\r\n/i', "", $s);
	$arr = explode(";\n", $s);
	foreach ($arr as $sql) {
		$sql = trim($sql);
		if(empty($sql)) continue;
		// FULLTEXT_TOLERANT 标记：标记后紧邻的 SQL 失败不中断安装
		// 必须逐行剥离注释行——explode 按 ";\n" 分割时注释行与 SQL 语句落在同一段（如文件头注释+DROP TABLE、标记行+CREATE FULLTEXT），
		// 段级判断会整段跳过导致语句漏执行（此前 FULLTEXT 索引从未在新装时创建，靠搜索页运行时兜底）
		$tolerant = false;
		$lines = array();
		foreach (explode("\n", $sql) as $line) {
			$t = ltrim($line);
			if($t !== '' && $t[0] === '#') {
				if(stripos($t, 'FULLTEXT_TOLERANT') !== false) $tolerant = true;
				continue;
			}
			$lines[] = $line;
		}
		$sql = trim(implode("\n", $lines));
		if($sql === '') {
			// 纯注释段：无 SQL 可执行
			continue;
		}
		$r = db_exec($sql);
		if($r === FALSE && !$tolerant) {
			message(-1, "sql: $sql, errno: $errno, errstr: $errstr");
		}
	}
}



?>
