<?php
// PHP 8.0+ 硬阻断：低于 8.0 直接终止，避免后续诡异报错
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
	header('HTTP/1.1 500 Internal Server Error');
	header('Content-Type: text/html; charset=utf-8');
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PHP 版本过低</title>';
	echo '<style>body{font-family:-apple-system,sans-serif;padding:40px;line-height:1.6;color:#333;max-width:720px;margin:0 auto}h1{color:#dc3545;margin-top:0}code{background:#f8f9fa;padding:2px 6px;border-radius:3px;color:#c7254e}</style>';
	echo '</head><body>';
	echo '<h1>PHP 版本过低，无法安装</h1>';
	echo '<p>XiunoX 要求 PHP <strong>8.0</strong> 及以上版本。</p>';
	echo '<p>当前 PHP 版本：<code>' . PHP_VERSION . '</code></p>';
	echo '<p>请升级 PHP 至 8.0+ 后再运行安装程序。</p>';
	echo '</body></html>';
	exit;
}

define('DEBUG', 2);
define('APP_PATH', realpath(dirname(__FILE__).'/../').'/');
define('INSTALL_PATH', dirname(__FILE__).'/');

// 版本号唯一来源
include APP_PATH . 'version.php';

define('MESSAGE_HTM_PATH', INSTALL_PATH.'view/htm/message.htm');

// 切换到上一级目录，操作很方便。

$conf = (include APP_PATH.'conf/conf.default.php');
$conf['version'] = XIUNOX_VERSION;	// 版本号统一从 version.php 读取
$conf['log_path'] = APP_PATH.$conf['log_path'];
$conf['tmp_path'] = APP_PATH.$conf['tmp_path'];

include APP_PATH.'xiunophp/xiunophp.php';
include APP_PATH.'model/misc.func.php';
include APP_PATH.'model/plugin.func.php';

// 从 cookie 中获取用户选择的语言，默认使用配置中的语言
$_lang = isset($_COOKIE['lang']) ? $_COOKIE['lang'] : $conf['lang'];
if(!in_array($_lang, array('zh-cn', 'zh-tw', 'en-us', 'ru-ru', 'th-th', 'ja-jp', 'ko-kr'))) {
    $_lang = $conf['lang'];
}
$conf['lang'] = $_lang;

// 语言文件需要在 plugin.func.php 之后加载
$lang = include APP_PATH."lang/$conf[lang]/bbs.php";
$lang += include APP_PATH."lang/$conf[lang]/bbs_install.php";
$_SERVER['lang'] = $lang;
$_SERVER['conf'] = $conf;

include APP_PATH.'model/user.func.php';
include APP_PATH.'model/group.func.php';
include APP_PATH.'model/form.func.php';
include APP_PATH.'model/forum.func.php';
include INSTALL_PATH.'install.func.php';

$action = param('action');

// 安装成功页需要在锁检测之前放行，否则写完 install.lock 后前端跳转会直接跳到首页
if($action == 'success') {
	include INSTALL_PATH."view/htm/success.htm";
	exit;
}

// 安装初始化检测,放这里
is_file(APP_PATH.'conf/conf.php') AND message(0, jump(lang('installed_tips'), '../'));
// 安装锁检测：锁文件存在则拒绝运行安装程序
is_file(INSTALL_PATH.'install.lock') AND message(0, jump(lang('installed_tips'), '../'));

// 第一步，阅读
if(empty($action)) {

	if($method == 'GET') {
		$input = array();
		$input['lang'] = form_select('lang', array('zh-cn'=>'简体中文', 'zh-tw'=>'正體中文', 'en-us'=>'English', 'ru-ru'=>'Русский', 'th-th'=>'ไทย', 'ja-jp'=>'日本語', 'ko-kr'=>'한국어'), $conf['lang']);

		// 修改 conf.php
		include INSTALL_PATH."view/htm/index.htm";
	} else {
		$_lang = param('lang');
		!in_array($_lang, array('zh-cn', 'zh-tw', 'en-us', 'ru-ru', 'th-th', 'ja-jp', 'ko-kr')) AND $_lang = 'zh-cn';
		setcookie('lang', $_lang, array('expires' => time() + 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'));

		//$conf['lang'] = $_lang;
		//xn_copy(APP_PATH.'./conf/conf.default.php', APP_PATH.'./conf/conf.backup.php');
		//$r = file_replace_var(APP_PATH.'conf/conf.default.php', array('lang'=>$_lang));
		//$r === FALSE AND message(-1, jump(lang('please_set_conf_file_writable'), ''));

		http_location('index.php?action=license');
	}

} elseif($action == 'license') {


	// 设置到 cookie

	include INSTALL_PATH."view/htm/license.htm";

} elseif($action == 'env') {

	if($method == 'GET') {
		$succeed = 1;
		$env = $write = array();
		get_env($env, $write);
		include INSTALL_PATH."view/htm/env.htm";
	} else {

	}

} elseif($action == 'db') {

	if($method == 'GET') {

		$succeed = 1;
		$pdo_mysql_support = extension_loaded('pdo_mysql');

		(!$pdo_mysql_support) AND message(-1, lang('evn_not_support_php_mysql'));

		include INSTALL_PATH."view/htm/db.htm";

	} else {

		$type = 'pdo_mysql';
		$engine = param('engine');
		$host = param('host');
		$name = param('name');
		$user = param('user');
		$password = param('password', '', FALSE);
		$force = param('force');
		$tablepre = param('tablepre');

		$adminemail = param('adminemail');
		$adminuser = param('adminuser');
		$adminpass = param('adminpass');

		// 表前缀校验：只允许字母、数字、下划线，必须以字母开头
		if (empty($tablepre)) {
			$tablepre = 'bbs_';
		}
		if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*_$/', $tablepre)) {
			message('tablepre', lang('db_tablepre_invalid'));
		}

		empty($host) AND message('host', lang('db_host_is_empty'));
		empty($name) AND message('name', lang('dbname_is_empty'));
		empty($user) AND message('user', lang('dbuser_is_empty'));
		empty($adminuser) AND message('adminuser', lang('admin_username_is_empty'));
		empty($adminpass) AND message('adminpass', lang('admin_password_too_short'));
		mb_strlen($adminpass) < 6 AND message('adminpass', lang('admin_password_too_short'));
		empty($adminemail) AND message('adminemail', lang('admin_email_is_empty'));
		!filter_var($adminemail, FILTER_VALIDATE_EMAIL) AND message('adminemail', lang('admin_email_invalid'));

		// 白名单校验，防 SQL 注入和 DSN 注入
		// $host 允许冒号以兼容 host:port 格式，禁止分号防 DSN 注入
		$valid_pattern = '/^[a-zA-Z0-9_\-\.]+$/';
		$host_pattern = '/^[a-zA-Z0-9_\-\.:]+$/';
		if (!preg_match($valid_pattern, $name)) {
			message(-1, '数据库名只能包含字母、数字、下划线、连字符和点');
		}
		if (!preg_match($host_pattern, $host)) {
			message(-1, '数据库主机地址只能包含字母、数字、下划线、连字符、点和冒号');
		}
		if (!preg_match($valid_pattern, $user)) {
			message(-1, '数据库用户名只能包含字母、数字、下划线、连字符和点');
		}



		// 设置超时尽量短一些
		//set_time_limit(60);
		ini_set('mysql.connect_timeout',  5);
		ini_set('default_socket_timeout', 5);

		$conf['db']['type'] = $type;
		$conf['db']['mysql']['master']['host'] = $host;
		$conf['db']['mysql']['master']['name'] = $name;
		$conf['db']['mysql']['master']['user'] = $user;
		$conf['db']['mysql']['master']['password'] = $password;
		$conf['db']['mysql']['master']['engine'] = $engine;
		$conf['db']['mysql']['master']['tablepre'] = $tablepre;
		$conf['db']['pdo_mysql']['master']['host'] = $host;
		$conf['db']['pdo_mysql']['master']['name'] = $name;
		$conf['db']['pdo_mysql']['master']['user'] = $user;
		$conf['db']['pdo_mysql']['master']['password'] = $password;
		$conf['db']['pdo_mysql']['master']['engine'] = $engine;
		$conf['db']['pdo_mysql']['master']['tablepre'] = $tablepre;

		$_SERVER['db'] = $db = db_new($conf['db']);
		// 此处可能报错
		$r = db_connect($db);
		if($r === FALSE) {
			if($errno == 1049 || $errno == 1045) {
				if($type == 'mysql') {
					if(strpos(':', $host) !== FALSE) {
						$arr = explode(':', $host);
						$host = $arr[0];
						$port = $arr[1];
					} else {
						$port = 3306;
					}
					try {
						$attr = array(
							PDO::ATTR_TIMEOUT => 5,
						);
						$link = new PDO("mysql:host=$host;port=$port", $user, $password, $attr);
						$r = $link->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
						if($r === FALSE) {
							$error = $link->errorInfo();
							$errno = $error[1];
							$errstr = $error[2];
						}
					} catch (PDOException $e) {
						$errno = $e->getCode();
						$errstr = $e->getMessage();
					}
					$r = db_connect($db);
				} elseif($type == 'pdo_mysql') {
					if(strpos(':', $host) !== FALSE) {
						$arr = explode(':', $host);
						$host = $arr[0];
						$port = $arr[1];
					} else {
						//$host = $host;
						$port = 3306;
					}
					try {
						$attr = array(
							PDO::ATTR_TIMEOUT => 5,
							//PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
						);
						$link = new PDO("mysql:host=$host;port=$port", $user, $password, $attr);
						$r = $link->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
						if($r === FALSE) {
							$error = $link->errorInfo();
							$errno = $error[1];
							$errstr = $error[2];
						}
					} catch (PDOException $e) {
						$errno = $e->getCode();
						$errstr = $e->getMessage();
					}
				}
			}
			if($r === FALSE) {
			// 根据错误码给出友好提示
			if($errno == 1045) {
				message(-1, lang('db_connect_denied'));
			} elseif($errno == 1049) {
				message(-1, lang('db_not_found'));
			} elseif($errno == 2002) {
				message(-1, lang('db_host_unreachable'));
			} else {
				message(-1, lang('db_connect_failed') . " ($errstr, errno: $errno)");
			}
		}
	}

		// MySQL 服务端版本检测：要求 5.7+（5.7.6+ 才支持 ngram parser，FULLTEXT 中文分词需要）
		// MariaDB 10.2+ 视为兼容（版本号 ≥ 10.2）
		// 保留 db_sql_find_one：SELECT VERSION() 无表名，db_find_one 不支持
		$mysql_version = db_sql_find_one('SELECT VERSION() AS v');
		$mysql_ver = isset($mysql_version['v']) ? $mysql_version['v'] : '';
		// MariaDB 版本号形如 "10.2.44-MariaDB"，MySQL 版本号形如 "5.7.43" / "8.0.35"
		$is_mariadb = stripos($mysql_ver, 'mariadb') !== false;
		$ver_num = preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $mysql_ver);
		$version_ok = false;
		if ($is_mariadb) {
			// MariaDB 10.2+ 兼容 MySQL 5.7 特性
			$version_ok = version_compare($ver_num, '10.2.0', '>=');
			$need_version = '10.2（MariaDB）';
		} else {
			$version_ok = version_compare($ver_num, '5.7.0', '>=');
			$need_version = '5.7';
		}
		if (!$version_ok) {
			message(-1, lang('mysql_version_too_low') . ' 当前: ' . $mysql_ver . '，需要: MySQL ' . $need_version . '+');
		}

		// 二次确认：检测数据库中是否已有指定前缀的表
		if(empty($force)) {
			// 保留 db_sql_find_prepared：SHOW TABLES LIKE 为元数据查询，无表名，db_find 不支持
			$tables = db_sql_find_prepared("SHOW TABLES LIKE ?", array($tablepre.'%'));
			if(!empty($tables)) {
				header('Content-Type: application/json; charset=utf-8');
				echo xn_json_encode(array('code'=>1, 'message'=>lang('db_already_exists_confirm')));
				exit;
			}
		}

		include_once APP_PATH.'lib/CacheService.php';
		$_SERVER['cache'] = $cache = CacheService::earlyInit();

		// 设置引擎的类型
		if($engine == 'innodb') {
			$db->innodb_first = TRUE;
		} else {
			$db->innodb_first = FALSE;
		}

		// 连接成功以后，开始建表，导数据。

		install_sql_file(INSTALL_PATH.'install.sql', $tablepre);
		
		// 初始化
		copy(APP_PATH.'conf/conf.default.php', APP_PATH.'conf/conf.php');

		// 管理员密码（直接用 bcrypt(明文)，不经过 md5 预处理）
		$password_hash = password_hash($adminpass, PASSWORD_DEFAULT);
		db_update('user', array('uid'=>1), array(
			'username' => $adminuser,
			'email' => $adminemail,
			'password' => '',
			'salt' => '',
			'password_hash' => $password_hash,
			'create_date' => $time,
			'create_ip' => $longip,
		));

		$replace = array();
		$replace['db'] = $conf['db'];
		$replace['auth_key'] = xn_rand(64);
		$replace['attach_sign_key'] = bin2hex(random_bytes(16));
		$replace['installed'] = 1;
		$replace['version'] = XIUNOX_VERSION;

		// 创建默认 API 应用
		$default_appid = bin2hex(random_bytes(8));
		$default_secret = bin2hex(random_bytes(16));
		$default_app_scope = 'full';
		$default_app_time = $time;
		db_insert('api_app', array(
			'appid' => $default_appid,
			'secret' => $default_secret,
			'name' => '默认应用',
			'description' => '系统自动创建的默认应用，用于前台页面',
			'scope' => $default_app_scope,
			'is_enabled' => 1,
			'uid' => 0,
			'rate_limit' => 0,
			'created_at' => $default_app_time,
		));
		$replace['api_default_appid'] = $default_appid;
		$replace['api_default_secret'] = $default_secret;

		file_replace_var(APP_PATH.'conf/conf.php', $replace);

		// 保存默认缓存配置（直接写入 kv 表，避免依赖 setting_set）
		$cache_config = array(
			'enable' => 1,
			'type' => 'mysql',
			'default_ttl' => 3600,
		);
		$existing = db_find_one('kv', array('k'=>'setting'));
		if($existing) {
			$setting = $existing['v'] ? xn_json_decode($existing['v']) : array();
		} else {
			$setting = array();
		}
		$setting['cache_config'] = $cache_config;
		db_replace('kv', array('k'=>'setting', 'v'=>xn_json_encode($setting)));

		// 写入帖子状态标签默认配置（图标使用 ti ti-xxx 完整格式，与 TablerIconPicker 返回值对齐）
		$status_labels = array(
			'top' => array('icon' => 'ti ti-pin-filled', 'text' => '', 'color' => '#0d6efd', 'text_color' => '#ffffff', 'rank' => 1),
			'digest' => array('icon' => 'ti ti-star-filled', 'text' => '', 'color' => '#ffc107', 'text_color' => '#000000', 'rank' => 2),
			'closed' => array('icon' => 'ti ti-lock', 'text' => '', 'color' => '#6c757d', 'text_color' => '#ffffff', 'rank' => 3),
			'image' => array('icon' => 'ti ti-photo', 'text' => '', 'color' => '#198754', 'text_color' => '#ffffff', 'rank' => 4),
			'video' => array('icon' => 'ti ti-video', 'text' => '', 'color' => '#0dcaf0', 'text_color' => '#000000', 'rank' => 5),
			'attachment' => array('icon' => 'ti ti-paperclip', 'text' => '', 'color' => '#6c757d', 'text_color' => '#ffffff', 'rank' => 6),
		);
		db_replace('kv', array('k'=>'thread_status_labels', 'v'=>xn_json_encode($status_labels)));

		// 处理语言包
		group_update(0, array('name'=>lang('group_0')));
		group_update(1, array('name'=>lang('group_1')));
		group_update(2, array('name'=>lang('group_2')));
		group_update(4, array('name'=>lang('group_4')));
		group_update(5, array('name'=>lang('group_5')));
		group_update(6, array('name'=>lang('group_6')));
		group_update(7, array('name'=>lang('group_7')));
		group_update(101, array('name'=>lang('group_101')));
		group_update(102, array('name'=>lang('group_102')));
		group_update(103, array('name'=>lang('group_103')));
		group_update(104, array('name'=>lang('group_104')));
		group_update(105, array('name'=>lang('group_105')));

		forum_update(1, array('name'=>lang('default_forum_name'), 'brief'=>lang('default_forum_brief')));

		xn_mkdir(APP_PATH.'upload/tmp', 0777);
		xn_mkdir(APP_PATH.'upload/attach', 0777);
		xn_mkdir(APP_PATH.'upload/avatar', 0777);
		xn_mkdir(APP_PATH.'upload/forum', 0777);

		// 写入安装锁文件，防止重复安装
		file_put_contents(INSTALL_PATH.'install.lock', date('Y-m-d H:i:s'));

		// 返回 JSON，前端跳转到成功页面
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code'=>0, 'message'=>lang('install_success'), 'redirect'=>'index.php?action=success'));
		exit;
	}

}


?>
