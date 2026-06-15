<?php

define('APP_PATH', dirname(__FILE__).'/');
define('DEBUG', 1);
define('XIUNOPHP_PATH', APP_PATH.'xiunophp/');

$conf = @include APP_PATH.'conf/conf.php';
if(!$conf) {
	die('<p>conf/conf.php not found.</p>');
}

$_SERVER['conf'] = $conf;
$_SERVER['time'] = time();
$_SERVER['longip'] = isset($_SERVER['REMOTE_ADDR']) ? ip2long($_SERVER['REMOTE_ADDR']) : ip2long('127.0.0.1');

include XIUNOPHP_PATH.'xiunophp.php';

$targetVersion = '1.0.1';
$currentVersion = isset($conf['version']) ? $conf['version'] : '0.0.0';
$tmpPath = isset($conf['tmp_path']) ? $conf['tmp_path'] : APP_PATH.'tmp/';
substr($tmpPath, 0, 2) == './' AND $tmpPath = APP_PATH.$tmpPath;
$logPath = isset($conf['log_path']) ? $conf['log_path'] : APP_PATH.'log/';
substr($logPath, 0, 2) == './' AND $logPath = APP_PATH.$logPath;
$confFile = APP_PATH.'conf/conf.php';

$db = $_SERVER['db'];
if(!$db) {
	$dbconf = $conf['db']['pdo_mysql'];
	$db = new db_pdo_mysql($dbconf);
	$db->connect();
	$_SERVER['db'] = $db;
}
$tablepre = $db->tablepre;

$action = isset($_GET['action']) ? $_GET['action'] : '';

if($action == 'run') {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(do_upgrade($conf, $db, $tablepre, $tmpPath, $logPath, $confFile, $currentVersion, $targetVersion));
	exit;
}

if($action == 'status') {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(check_status($db, $tablepre, $currentVersion, $targetVersion));
	exit;
}

render_page($currentVersion, $targetVersion, $conf, $db, $tablepre);

// ============================================================

function check_status($db, $tablepre, $currentVersion, $targetVersion) {
	$items = array();

	$columns = array('password_hash', 'login_attempts', 'banned_until', 'last_login_ip', 'last_login_time');
	foreach($columns as $col) {
		$items[] = array(
			'name' => $col,
			'type' => 'column',
			'exists' => db_check_column_exists('user', $col),
		);
	}

	$items[] = array(
		'name' => 'user_login_log',
		'type' => 'table',
		'exists' => db_check_table_exists('user_login_log'),
	);

	$needUpgrade = version_compare($currentVersion, $targetVersion, '<');

	return array('ok' => true, 'need_upgrade' => $needUpgrade, 'items' => $items, 'current_version' => $currentVersion, 'target_version' => $targetVersion);
}

function do_upgrade($conf, $db, $tablepre, $tmpPath, $logPath, $confFile, $currentVersion, $targetVersion) {
	$report = array();
	$startTime = microtime(true);

	$checks = array();
	$checks['php'] = version_compare(PHP_VERSION, '8.0.0', '>=');
	$checks['pdo_mysql'] = extension_loaded('pdo_mysql');
	$checks['password_hash'] = function_exists('password_hash');
	$checks['disk'] = disk_free_space(APP_PATH) > 50 * 1024 * 1024;
	$checks['conf_writable'] = is_writable($confFile);
	$checks['tmp_writable'] = is_dir($tmpPath) && is_writable($tmpPath);

	if(in_array(false, $checks, true)) {
		return array('ok' => false, 'step' => 'check', 'message' => 'Environment check failed', 'checks' => $checks);
	}
	$report['checks'] = $checks;

	$backupDir = $tmpPath.'upgrade_backup_'.date('Ymd_His').'/';
	if(!mkdir($backupDir, 0755, true)) {
		return array('ok' => false, 'step' => 'backup', 'message' => 'Failed to create backup directory');
	}

	$backupFiles = array();
	if(copy($confFile, $backupDir.'conf.php')) {
		$backupFiles[] = 'conf/conf.php';
	}
	$modelInc = APP_PATH.'model.inc.php';
	if(file_exists($modelInc) && copy($modelInc, $backupDir.'model.inc.php')) {
		$backupFiles[] = 'model.inc.php';
	}

	$dumpTables = array('user', 'group', 'forum', 'forum_access', 'thread', 'thread_top', 'post', 'attach', 'mythread', 'mypost', 'session', 'session_data', 'modlog', 'kv', 'cache', 'queue', 'table_day');
	$sqlDump = "-- Xiuno BBS Upgrade Backup\n-- Date: ".date('Y-m-d H:i:s')."\n-- Table prefix: {$tablepre}\n\n";
	foreach($dumpTables as $table) {
		$fullTable = $tablepre.$table;
		$createArr = db_sql_find_one("SHOW CREATE TABLE `$fullTable`");
		if(empty($createArr)) continue;
		$createSql = isset($createArr['Create Table']) ? $createArr['Create Table'] : '';
		$sqlDump .= "DROP TABLE IF EXISTS `$fullTable`;\n".$createSql.";\n\n";
		$rows = db_sql_find("SELECT * FROM `$fullTable`", NULL);
		if(!empty($rows)) {
			foreach($rows as $row) {
				$cols = array(); $vals = array();
				foreach($row as $k => $v) {
					$cols[] = '`'.addslashes($k).'`';
					$vals[] = "'".addslashes((string)$v)."'";
				}
				$sqlDump .= "INSERT INTO `$fullTable` (".implode(', ', $cols).") VALUES (".implode(', ', $vals).");\n";
			}
			$sqlDump .= "\n";
		}
	}
	$sqlBackup = $backupDir.'database.sql';
	file_put_contents($sqlBackup, $sqlDump);
	$backupFiles[] = 'database.sql';
	$report['backup'] = array('dir' => $backupDir, 'files' => $backupFiles);

	$sqlFile = APP_PATH.'sql/upgrade.sql';
	if(!file_exists($sqlFile)) {
		return array('ok' => false, 'step' => 'sql', 'message' => 'sql/upgrade.sql not found');
	}
	$sqlContent = file_get_contents($sqlFile);
	$sqlContent = str_replace('{tablepre}', $tablepre, $sqlContent);
	$sqlContent = preg_replace('/--.*$/m', '', $sqlContent);
	$sqlStatements = array_filter(array_map('trim', explode(";\n", $sqlContent)), function($s) { return !empty($s); });

	$sqlResults = array();
	$sqlOk = true;
	foreach($sqlStatements as $sql) {
		if(empty(trim($sql))) continue;
		$shortSql = strlen($sql) > 80 ? substr($sql, 0, 77).'...' : $sql;
		$r = db_exec($sql);
		if($r !== false) {
			$sqlResults[] = array('sql' => $shortSql, 'ok' => true);
		} else {
			$errno = $db->errno;
			if($errno == 1060 || $errno == 1050) {
				$sqlResults[] = array('sql' => $shortSql, 'ok' => true, 'skip' => true);
			} else {
				$sqlResults[] = array('sql' => $shortSql, 'ok' => false, 'errno' => $errno, 'errstr' => $db->errstr);
				$sqlOk = false;
			}
		}
	}
	$report['sql'] = array(
		'total' => count($sqlResults),
		'success' => count(array_filter($sqlResults, function($r) { return $r['ok']; })),
		'failed' => count(array_filter($sqlResults, function($r) { return !$r['ok']; })),
		'results' => $sqlResults,
	);

	include APP_PATH.'model/user.func.php';
	$hasPasswordHash = db_check_column_exists('user', 'password_hash');
	if($hasPasswordHash) {
		$totalUsers = db_count('user');
		$legacyUsers = db_count('user', array('password_hash'=>'', 'password!='=>''));
		$bcryptUsers = db_count('user', array('password_hash!='=>''));
		$report['password'] = array('total' => $totalUsers, 'legacy' => $legacyUsers, 'bcrypt' => $bcryptUsers);
	} else {
		$report['password'] = array('total' => 0, 'legacy' => 0, 'bcrypt' => 0);
	}

	$cacheCleared = 0;
	$modelMinFile = $tmpPath.'model.min.php';
	if(file_exists($modelMinFile)) { unlink($modelMinFile); $cacheCleared++; }
	$tmpFiles = glob($tmpPath.'*.php');
	if($tmpFiles) {
		foreach($tmpFiles as $f) {
			if(basename($f) === 'index.html') continue;
			if(strpos(basename($f), 'upgrade_backup_') === 0) continue;
			if(unlink($f)) $cacheCleared++;
		}
	}
	$cacheType = isset($conf['cache']['type']) ? $conf['cache']['type'] : 'mysql';
	if(in_array($cacheType, array('mysql', 'pdo_mysql'))) {
		db_exec("DELETE FROM `{$tablepre}cache`");
		$cacheCleared++;
	}
	$report['cache'] = array('files_cleared' => $cacheCleared);

	$configUpdated = false;
	if(is_writable($confFile)) {
		$confContent = file_get_contents($confFile);
		$confContent = preg_replace("/'version'\s*=>\s*'[^']*'/", "'version' => '$targetVersion'", $confContent);
		if(!isset($conf['login_max_attempts'])) {
			$confContent = preg_replace(
				"/'version'\s*=>\s*'$targetVersion'/",
				"'login_max_attempts' => 5,\n\t'login_ban_duration' => 900,\n\t'version' => '$targetVersion'",
				$confContent
			);
		}
		if(file_put_contents($confFile, $confContent) !== false) {
			$configUpdated = true;
		}
	}
	$report['config'] = array('updated' => $configUpdated, 'target_version' => $targetVersion);

	$report['ok'] = $sqlOk;
	$report['elapsed'] = round(microtime(true) - $startTime, 2);
	$report['backup_dir'] = $backupDir;
	return $report;
}

function render_page($currentVersion, $targetVersion, $conf, $db, $tablepre) {
	$status = check_status($db, $tablepre, $currentVersion, $targetVersion);
	$needUpgrade = $status['need_upgrade'];
	$items = $status['items'];
	$allExists = true;
	foreach($items as $item) {
		if(!$item['exists']) { $allExists = false; break; }
	}

	$sqlFile = APP_PATH.'sql/upgrade.sql';
	$manualSql = '';
	if(file_exists($sqlFile)) {
		$manualSql = file_get_contents($sqlFile);
		$manualSql = str_replace('{tablepre}', $tablepre, $manualSql);
	}
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Xiuno BBS 升级</title>
<link href="view/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="view/vendor/tabler-icons/tabler-icons.min.css">
<style>
body { background: #f5f5f5; }
.upgrade-wrap { max-width: 720px; margin: 2rem auto; }
.log-line { font-family: monospace; font-size: 0.85rem; padding: 2px 0; }
.log-ok { color: #22c55e; }
.log-fail { color: #ef4444; }
.log-warn { color: #eab308; }
.log-info { color: #3b82f6; }
pre.sql-box { max-height: 300px; overflow-y: auto; font-size: 0.8rem; background: #e9ecef; }
</style>
</head>
<body>
<div class="upgrade-wrap">

<div class="x-card  cardrounded-3 mb-3">
	<div class="card-body border-bottom">
		<h2 class="fw-bold">Xiuno BBS 升级</h2>
		<p class="text-body-secondary"><?php echo $currentVersion; ?> &rarr; <?php echo $targetVersion; ?></p>
	</div>
	<div class="card-body">

		<?php if(!$needUpgrade && $allExists) { ?>
		<div class="alert alert-success d-flex align-items-center gap-2">
			<i class="ti ti-circle-check fs-4"></i>
			<span>已是最新版本，所有数据库字段和表均已就绪。</span>
		</div>
		<?php } else { ?>

		<h3 class="fw-bold mb-2">升级内容</h3>
		<ul class="mb-4 text-sm text-body-secondary">
			<li><strong>password_hash</strong> &mdash; bcrypt 密码哈希存储（更安全）</li>
			<li><strong>login_attempts</strong> &mdash; 登录失败次数统计</li>
			<li><strong>banned_until</strong> &mdash; 账户临时锁定</li>
			<li><strong>last_login_ip / last_login_time</strong> &mdash; 最后登录记录</li>
			<li><strong>user_login_log</strong> &mdash; 登录日志表</li>
		</ul>

		<h3 class="fw-bold mb-2">当前状态</h3>
		<table class="table table-sm mb-4">
			<thead><tr><th>项目</th><th>类型</th><th>状态</th></tr></thead>
			<tbody>
			<?php foreach($items as $item) { ?>
			<tr>
				<td><code><?php echo $item['name']; ?></code></td>
				<td><?php echo $item['type'] == 'column' ? '字段' : '表'; ?></td>
				<td><?php echo $item['exists'] ? '<span class="text-success"><i class="ti ti-check"></i> 已存在</span>' : '<span class="text-warning"><i class="ti ti-x"></i> 缺失</span>'; ?></td>
			</tr>
			<?php } ?>
			</tbody>
		</table>

		<div class="alert alert-info mb-4 text-sm d-flex align-items-center gap-2">
			<i class="ti ti-info-circle fs-5"></i>
			<span>升级会自动备份数据库和配置文件，检测已存在的字段/表后安全跳过，可重复执行。</span>
		</div>

		<button type="button" class="btn btn-primary  w-100" id="btn-upgrade">
			<i class="ti ti-circle-arrow-up"></i> 执行升级
		</button>

		<div id="upgrade-log" class="mt-4" style="display:none;">
			<h3 class="fw-bold mb-2">升级日志</h3>
			<div class="bg-body-secondary rounded p-3" id="log-output"></div>
		</div>

		<div id="upgrade-result" class="mt-4" style="display:none;"></div>

		<?php } ?>

	</div>
</div>

<?php if(!$needUpgrade && $allExists) { ?>
<div class="text-center mt-4">
	<a href="./" class="btn btn-outline-secondary "><i class="ti ti-home"></i> 返回首页</a>
</div>
<?php } ?>

<div class="x-card  cardrounded-3 mb-3">
	<div class="card-body border-bottom">
		<h3 class="fw-bold">手动 SQL</h3>
	</div>
	<div class="card-body">
		<p class="text-sm text-body-secondary mb-2">如果自动升级失败，可复制以下 SQL 到 phpMyAdmin 手动执行：</p>
		<button type="button" class="btn btn-sm btn-outline-secondary  mb-2" id="btn-copy"><i class="ti ti-clipboard"></i> 复制 SQL</button>
		<pre class="sql-box rounded p-3"><code id="sql-content"><?php echo htmlspecialchars($manualSql); ?></code></pre>
	</div>
</div>

</div>

<script src="view/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
var btn = document.getElementById('btn-upgrade');
var logDiv = document.getElementById('upgrade-log');
var logOutput = document.getElementById('log-output');
var resultDiv = document.getElementById('upgrade-result');

if(btn) {
	btn.addEventListener('click', function() {
		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> 升级中...';
		logDiv.style.display = 'block';
		logOutput.innerHTML = '';
		resultDiv.style.display = 'none';

		var xhr = new XMLHttpRequest();
		xhr.open('GET', '?action=run', true);
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.onreadystatechange = function() {
			if(xhr.readyState === 4) {
				btn.disabled = false;
				btn.innerHTML = '<i class="ti ti-circle-arrow-up"></i> 执行升级';
				try {
					var data = JSON.parse(xhr.responseText);
					renderResult(data);
				} catch(e) {
					resultDiv.style.display = 'block';
					resultDiv.innerHTML = '<div class="alert alert-danger">解析响应失败</div>';
				}
			}
		};
		xhr.send();
	});
}

function addLog(msg, cls) {
	var d = document.createElement('div');
	d.className = 'log-line ' + (cls || '');
	d.textContent = msg;
	logOutput.appendChild(d);
}

function renderResult(data) {
	if(!data.ok && data.step) {
		addLog('[' + data.step + '] ' + data.message, 'log-fail');
		resultDiv.style.display = 'block';
		resultDiv.innerHTML = '<div class="alert alert-danger">升级失败：' + data.message + '</div>';
		return;
	}

	if(data.checks) {
		addLog('== 环境检查 ==', 'log-info');
		for(var k in data.checks) {
			addLog('  ' + k + ': ' + (data.checks[k] ? 'PASS' : 'FAIL'), data.checks[k] ? 'log-ok' : 'log-fail');
		}
	}

	if(data.backup) {
		addLog('== 备份 ==', 'log-info');
		addLog('  目录: ' + data.backup.dir, 'log-ok');
		addLog('  文件: ' + data.backup.files.join(', '), 'log-ok');
	}

	if(data.sql) {
		addLog('== SQL 升级 ==', 'log-info');
		data.sql.results.forEach(function(r) {
			if(r.ok) {
				addLog('  ' + (r.skip ? 'SKIP' : 'OK') + ': ' + r.sql, r.skip ? 'log-warn' : 'log-ok');
			} else {
				addLog('  FAIL: ' + r.sql + ' [' + r.errno + '] ' + r.errstr, 'log-fail');
			}
		});
		addLog('  结果: ' + data.sql.success + '/' + data.sql.total + ' OK', data.sql.failed == 0 ? 'log-ok' : 'log-fail');
	}

	if(data.password) {
		addLog('== 密码迁移 ==', 'log-info');
		addLog('  总用户: ' + data.password.total, 'log-info');
		addLog('  MD5 旧密码: ' + data.password.legacy + ' (登录时自动升级)', 'log-warn');
		addLog('  bcrypt: ' + data.password.bcrypt, 'log-ok');
	}

	if(data.cache) {
		addLog('== 缓存清理 ==', 'log-info');
		addLog('  清理 ' + data.cache.files_cleared + ' 项', 'log-ok');
	}

	if(data.config) {
		addLog('== 配置更新 ==', 'log-info');
		addLog('  版本号: ' + (data.config.updated ? data.config.target_version : '手动更新'), data.config.updated ? 'log-ok' : 'log-warn');
	}

	addLog('耗时: ' + data.elapsed + 's', 'log-info');

	var html = '';
	if(data.ok) {
		html = '<div class="alert alert-success mt-2 d-flex align-items-center gap-2"><i class="ti ti-circle-check fs-4"></i><span>升级完成！<br>备份目录: ' + data.backup_dir + '</span></div>';
	} else {
		html = '<div class="alert alert-danger mt-2 d-flex align-items-center gap-2"><i class="ti ti-circle-x fs-4"></i><span>升级过程中有错误，请检查日志。<br>备份目录: ' + data.backup_dir + '</span></div>';
	}
	resultDiv.style.display = 'block';
	resultDiv.innerHTML = html;
}

document.getElementById('btn-copy').addEventListener('click', function() {
	var sql = document.getElementById('sql-content').textContent;
	navigator.clipboard.writeText(sql).then(function() {
		var btn = document.getElementById('btn-copy');
		btn.innerHTML = '<i class="ti ti-check"></i> 已复制';
		setTimeout(function() { btn.innerHTML = '<i class="ti ti-clipboard"></i> 复制 SQL'; }, 2000);
	});
});
</script>
</body>
</html>
<?php
}
