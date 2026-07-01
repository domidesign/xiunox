<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook admin_index_start.php

if($action == 'login') {

	// hook admin_index_login_get_post.php
	
	if($method == 'GET') {

		// hook admin_index_login_get_start.php
		
		$header['title'] = lang('admin_login');
		
		include _include(ADMIN_PATH."view/htm/index_login.htm");

	} else if($method == 'POST') {

		// hook admin_index_login_post_start.php
		
		$password = param('password');

		if(!user_login_verify($password, $user)) {
			xn_log('password error. uid:'.$user['uid'], 'admin_login_error');
			message('password', lang('password_incorrect'));
		}

		admin_token_set();

		xn_log('login successed. uid:'.$user['uid'], 'admin_login');

		// hook admin_index_login_post_end.php
		
		message(0, jump(lang('login_successfully'), '.'));

	}

} elseif ($action == 'logout') {

	// hook admin_index_logout_start.php
	
	admin_token_clean();
	
	message(0, jump(lang('logout_successfully'), './'));

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
	$stat['threads'] = thread_count();
	$stat['posts'] = post_count();
	$stat['users'] = user_count();
	$stat['attachs'] = attach_count();
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
