<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook admin_index_start.php

if($action == 'login') {

	// hook admin_index_login_get_post.php

	// 检查 IP 失败次数，决定是否显示验证码（失败 >= 3 次显示）
	include_once APP_PATH . 'lib/LoginSecurityService.php';
	$admin_captcha_threshold = 3;
	$admin_captcha_window = isset($conf['login_ban_duration']) ? intval($conf['login_ban_duration']) : 900;
	$admin_captcha_cutoff = $time - $admin_captcha_window;
	$admin_show_captcha = FALSE;
	if(function_exists('db_check_table_exists') && db_check_table_exists('user_login_log')) {
		$admin_fail_count = db_count('user_login_log', array('ip'=>intval($longip), 'success'=>0, 'time'=>array('>'=>$admin_captcha_cutoff)));
		if($admin_fail_count >= $admin_captcha_threshold) {
			$admin_show_captcha = TRUE;
		}
	}

	if($method == 'GET') {

		// hook admin_index_login_get_start.php

		// AJAX 刷新验证码
		if(param('captcha_refresh')) {
			include_once APP_PATH . 'lib/security/CaptchaService.php';
			$result = CaptchaService::generate('login', TRUE);
			header('Content-Type: application/json; charset=utf-8');
			if($result) {
				echo json_encode(array('code'=>0, 'image'=>$result['image'], 'expires_in'=>CaptchaService::CAPTCHA_EXPIRE));
			} else {
				echo json_encode(array('code'=>-1, 'message'=>'captcha generate failed'));
			}
			exit;
		}

		$header['title'] = lang('admin_login');

		// 登录后返回原页面：return_url 来自 admin_token_check 注入或 token 失效 303 跳转的 query
		// param 默认 htmlsafe 会破坏 URL 中的 &，关闭 htmlspecialchars 保留原始 URL
		$return_url = param('return_url', '', FALSE);

		// 生成验证码图片（base64），传给模板
		$captcha_image = '';
		$captcha_expires_in = 0;
		if($admin_show_captcha) {
			include_once APP_PATH . 'lib/security/CaptchaService.php';
			$result = CaptchaService::generate('login', TRUE);
			if($result) {
				$captcha_image = $result['image'];
				$captcha_expires_in = CaptchaService::CAPTCHA_EXPIRE;
			}
		}

		include _include(ADMIN_PATH."view/htm/index_login.htm");

	} else if($method == 'POST') {

		// hook admin_index_login_post_start.php

		// CSRF 校验
		CsrfService::check();

		// IP 黑名单检查（banned_ip 表 + IpBlacklistService 双重检查）
		if(function_exists('banned_ip_check') && banned_ip_check($ip)) {
			message(-1, lang('ip_banned'));
		}
		if(!class_exists('IpBlacklistService')) {
			include_once APP_PATH . 'lib/security/IpBlacklistService.php';
		}
		if(IpBlacklistService::is_blacklisted($ip)) {
			message(-1, lang('ip_banned'));
		}

		// 验证码校验（失败次数达阈值时）
		// 直接校验 session，绕过 CaptchaService::verify 的 is_enabled 检查
		// （后台验证码按失败次数触发，不受验证码配置开关控制）
		if($admin_show_captcha) {
			$captcha_input = param('captcha', '', FALSE);
			if(empty($captcha_input)) {
				message('captcha', lang('please_input_captcha'));
			}
			$stored = isset($_SESSION['captcha_login']) ? $_SESSION['captcha_login'] : '';
			unset($_SESSION['captcha_login']);
			if(empty($stored) || strtolower($captcha_input) !== strtolower($stored)) {
				message('captcha', lang('captcha_error'));
			}
		}

		// IP 维度限流 + uid 维度锁定检查（防止后台密码暴破）
		LoginSecurityService::checkIpBan($longip);
		LoginSecurityService::checkBan($user['uid']);

		$password = param('password', '', FALSE);

		if(!user_login_verify($password, $user)) {
			xn_log('password error. uid:'.$user['uid'], 'admin_login_error');
			LoginSecurityService::recordAttempt($user['uid'], FALSE, $longip, $_SERVER['HTTP_USER_AGENT']);
			message('password', lang('username_or_password_incorrect'));
		}

		// 登录成功，清除该用户的失败计数
		LoginSecurityService::recordAttempt($user['uid'], TRUE, $longip, $_SERVER['HTTP_USER_AGENT']);

		// 防止 Session 固定攻击
		session_regenerate_id(true);

		// 写入 session uid（与前台 route/user.php:458 对齐）
		// 缺失此行会导致登录成功后跳转到 admin/ 时 index.inc.php 读不到 session uid，
		// $gid=0 触发 admin/index.inc.php:14 的管理员组检查失败，跳转到前台 user-login
		$_SESSION['uid'] = $user['uid'];

		admin_token_set();

		xn_log('login successed. uid:'.$user['uid'], 'admin_login');

		// hook admin_index_login_post_end.php

		// 登录成功：返回原页面，无 return_url 兜底仪表盘
		$referer = admin_http_referer();
		message(0, lang('login_successfully'), array('redirect_url' => $referer ?: './'));

	}

} elseif ($action == 'logout') {

	// hook admin_index_logout_start.php
	
	admin_token_clean();
	
	$uid = 0;
	$_SESSION['uid'] = $uid;
	
	session_regenerate_id(true);
	
	user_token_clear();
	
	message(0, lang('logout_successfully'), array('redirect_url' => './'));

} elseif ($action == 'phpinfo') {

	// 最小化输出，过滤敏感变量
	unset($_COOKIE, $_ENV, $_SERVER['HTTP_COOKIE']);
	phpinfo(INFO_CONFIGURATION | INFO_LICENSE);
	exit;

} else {

	// hook admin_index_empty_start.php
	
	$header['title'] = lang('admin_page');
	
	$info = array();
	$info['disable_functions'] = ini_get('disable_functions');
	$info['allow_url_fopen'] = ini_get('allow_url_fopen') ? lang('yes') : lang('no');
	$info['safe_mode'] = ini_get('safe_mode') ? lang('yes') : lang('no');
	empty($info['disable_functions']) && $info['disable_functions'] = lang('none');
	$info['upload_max_filesize'] = ini_get('upload_max_filesize');
	$info['post_max_size'] = ini_get('post_max_size');
	$info['memory_limit'] = ini_get('memory_limit');
	$info['max_execution_time'] = ini_get('max_execution_time');
	$info['dbversion'] = $db->version();
	$info['SERVER_SOFTWARE'] = _SERVER('SERVER_SOFTWARE');
	$info['HTTP_X_FORWARDED_FOR'] = _SERVER('HTTP_X_FORWARDED_FOR');
	$info['REMOTE_ADDR'] = _SERVER('REMOTE_ADDR');
	
	
	$stat = array();
	// 后台仪表盘需精确统计：传非空 cond 触发 COUNT(*) 分支，避免 InnoDB 空 cond 走 information_schema 估算值
	// thread/post 排除已软删除，user/attach 传主键>0（无软删除字段）
	$stat['threads'] = thread_count(array('is_deleted' => 0));
	$stat['posts'] = post_count(array('is_deleted' => 0));
	$stat['users'] = user_count(array('uid' => array('>' => 0)));
	$stat['attachs'] = attach_count(array('aid' => array('>' => 0)));
	$stat['disk_free_space'] = function_exists('disk_free_space') ? humansize(disk_free_space(APP_PATH)) : lang('unknown');
	
	// 安全与审核统计
	$security_stat = array();
	include_once APP_PATH . 'lib/security/AuditService.php';
	$security_stat['pending_threads'] = AuditService::get_pending_count('thread');
	$security_stat['pending_posts'] = AuditService::get_pending_count('post');
	$security_stat['pending_profiles'] = AuditService::get_pending_profile_count();
	$security_stat['pending_total'] = $security_stat['pending_threads'] + $security_stat['pending_posts'] + $security_stat['pending_profiles'];
	
	include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
	$all_words = SensitiveWordFilter::get_all_words();
	$security_stat['sensitive_words'] = is_array($all_words) ? count($all_words) : 0;
	
	include_once APP_PATH . 'lib/security/IpBlacklistService.php';
	$ip_blacklist = IpBlacklistService::get_blacklist();
	$security_stat['ip_blacklist'] = is_array($ip_blacklist) ? count($ip_blacklist) : 0;
	
	include_once APP_PATH . 'lib/security/EmailBlacklistService.php';
	$email_blacklist = EmailBlacklistService::get_all_domains();
	$security_stat['email_blacklist'] = is_array($email_blacklist) ? count($email_blacklist) : 0;
	
	// 今日登录失败次数
	$today_start = strtotime('today');
	$security_stat['login_failures_today'] = db_count('user_login_log', array('success'=>0, 'time'=>array('>'=>$today_start)));

	// 今日数据统计
	$today_stat = array();
	$today_stat['threads'] = db_count('thread', array('create_date'=>array('>'=>$today_start)));
	$today_stat['posts'] = db_count('post', array('create_date'=>array('>'=>$today_start), 'isfirst'=>0));
	$today_stat['users'] = db_count('user', array('create_date'=>array('>'=>$today_start)));
	$today_stat['attachs'] = db_count('attach', array('create_date'=>array('>'=>$today_start)));

	// 数据趋势：最近30天每日统计 - 改用聚合查询，4 次查询替代 120 次
	global $db;
	$chart_days = 30;
	$chart_labels = array();
	$chart_threads = array_fill(0, $chart_days, 0);
	$chart_posts = array_fill(0, $chart_days, 0);
	$chart_users = array_fill(0, $chart_days, 0);
	$chart_attachs = array_fill(0, $chart_days, 0);

	// 生成日期标签
	for($i = $chart_days - 1; $i >= 0; $i--) {
		$day_start = strtotime("-{$i} days", $today_start);
		$chart_labels[] = date('m/d', $day_start);
	}

	// 30 天起始时间（今天 0 点往前推 29 天）
	$chart_start = $today_start - ($chart_days - 1) * 86400;

	// 批量查询 thread
	$sql = "SELECT FLOOR((create_date - {$chart_start}) / 86400) AS day_idx, COUNT(*) AS c FROM {$db->tablepre}thread WHERE create_date >= {$chart_start} GROUP BY day_idx";
	$result = db_sql_find($sql);
	if($result) {
		foreach($result as $row) {
			$idx = intval($row['day_idx']);
			if($idx >= 0 && $idx < $chart_days) {
				$chart_threads[$idx] = intval($row['c']);
			}
		}
	}

	// 批量查询 post（isfirst=0）
	$sql = "SELECT FLOOR((create_date - {$chart_start}) / 86400) AS day_idx, COUNT(*) AS c FROM {$db->tablepre}post WHERE create_date >= {$chart_start} AND isfirst=0 GROUP BY day_idx";
	$result = db_sql_find($sql);
	if($result) {
		foreach($result as $row) {
			$idx = intval($row['day_idx']);
			if($idx >= 0 && $idx < $chart_days) {
				$chart_posts[$idx] = intval($row['c']);
			}
		}
	}

	// 批量查询 user
	$sql = "SELECT FLOOR((create_date - {$chart_start}) / 86400) AS day_idx, COUNT(*) AS c FROM {$db->tablepre}user WHERE create_date >= {$chart_start} GROUP BY day_idx";
	$result = db_sql_find($sql);
	if($result) {
		foreach($result as $row) {
			$idx = intval($row['day_idx']);
			if($idx >= 0 && $idx < $chart_days) {
				$chart_users[$idx] = intval($row['c']);
			}
		}
	}

	// 批量查询 attach
	$sql = "SELECT FLOOR((create_date - {$chart_start}) / 86400) AS day_idx, COUNT(*) AS c FROM {$db->tablepre}attach WHERE create_date >= {$chart_start} GROUP BY day_idx";
	$result = db_sql_find($sql);
	if($result) {
		foreach($result as $row) {
			$idx = intval($row['day_idx']);
			if($idx >= 0 && $idx < $chart_days) {
				$chart_attachs[$idx] = intval($row['c']);
			}
		}
	}

	// 检测 install 目录是否存在
	$install_dir_exists = is_dir(APP_PATH.'install');

	$lastversion = get_last_version($stat);

	// hook admin_index_empty_end.php
	
	include _include(ADMIN_PATH.'view/htm/index.htm');

}

// hook admin_index_end.php

function get_last_version($stat) {
	global $conf, $time;
	$last_version = kv_get('last_version');
	if($time - $last_version > 86400) {
		kv_set('last_version', $time);
		$sitename = urlencode($conf['sitename']);
		$sitedomain = urlencode(http_url_path());
		$version = urlencode($conf['version']);
		return '<script src="http://custom.xiuno.com/version.htm?sitename='.$sitename.'&sitedomain='.$sitedomain.'&users='.$stat['users'].'&threads='.$stat['threads'].'&posts='.$stat['posts'].'&version='.$version.'"></script>';
	} else {
		return '';
	}
}

?>
