<?php

define('DEBUG', 2);
define('APP_PATH', realpath(dirname(__FILE__).'/../').'/');
define('INSTALL_PATH', dirname(__FILE__).'/');

define('MESSAGE_HTM_PATH', INSTALL_PATH.'view/htm/message.htm');

// 切换到上一级目录，操作很方便。

$conf = (include APP_PATH.'conf/conf.default.php');
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
						$r = $link->exec("CREATE DATABASE `$name`");
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
						$r = $link->exec("CREATE DATABASE `$name`");
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

		// 二次确认：检测数据库中是否已有指定前缀的表
		if(empty($force)) {
			$safe_tablepre_for_like = addslashes($tablepre);
			$tables = db_sql_find("SHOW TABLES LIKE '{$safe_tablepre_for_like}%'");
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
		$safe_username = addslashes($adminuser);
		$safe_email = addslashes($adminemail);
		$safe_password_hash = addslashes($password_hash);
		db_exec("UPDATE `{$tablepre}user` SET username='$safe_username', email='$safe_email', `password`='', salt='', `password_hash`='$safe_password_hash', create_date='$time', create_ip='$longip' WHERE uid=1");

		$replace = array();
		$replace['db'] = $conf['db'];
		$replace['auth_key'] = xn_rand(64);
		$replace['attach_sign_key'] = bin2hex(random_bytes(16));
		$replace['installed'] = 1;

		// 创建默认 API 应用
		$default_appid = bin2hex(random_bytes(8));
		$default_secret = bin2hex(random_bytes(16));
		$default_app_scope = 'full';
		$default_app_time = $time;
		$safe_appid = addslashes($default_appid);
		$safe_secret = addslashes($default_secret);
		db_exec("INSERT INTO `{$tablepre}api_app` (appid, secret, name, description, scope, is_enabled, uid, rate_limit, created_at) VALUES ('$safe_appid', '$safe_secret', '默认应用', '系统自动创建的默认应用，用于前台页面', '$default_app_scope', 1, 0, 0, '$default_app_time')");
		$replace['api_default_appid'] = $default_appid;
		$replace['api_default_secret'] = $default_secret;

		file_replace_var(APP_PATH.'conf/conf.php', $replace);

		// 保存默认缓存配置（直接写入 kv 表，避免依赖 setting_set）
		$cache_config = array(
			'enable' => 1,
			'type' => 'mysql',
			'default_ttl' => 3600,
			'auto_warmup' => 0,
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
