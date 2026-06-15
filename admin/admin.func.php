<?php

// hook admin_func_start.php

// 有部分用户
define('XN_ADMIN_BIND_IP', array_value($conf, 'admin_bind_ip'));

function admin_token_check() {
	global $longip, $time, $useragent, $conf;
	$useragent_md5 = md5($useragent);
	
	//$key = md5($longip.$useragent_md5.$conf['auth_key']); // 有些环境是动态 IP
	$key = md5((XN_ADMIN_BIND_IP ? $longip : '').$useragent_md5.xn_key());
	
	// hook admin_token_check_start.php
	
	$admin_token = param('bbs_admin_token');
	if(empty($admin_token)) {
		$_REQUEST[0] = 'index';
		$_REQUEST[1] = 'login';
	} else {
		$s = xn_decrypt($admin_token, $key);
		if(empty($s)) {
			$cookie_secure = isset($conf['cookie_secure']) ? $conf['cookie_secure'] : false;
			setcookie('bbs_admin_token', '', array('expires' => 0, 'path' => '', 'domain' => '', 'secure' => $cookie_secure, 'httponly' => true, 'samesite' => 'Lax'));
			//message(-1, lang('admin_token_error'));
			message(-1, lang('admin_token_expiry'));
		}
		list($_ip, $_time) = explode("\t", $s);
		
		// 后台超过 3600 自动退出。
		// Background / more than 3600 automatic withdrawal.
		//if($_ip != $longip || $time - $_time > 3600) {
		if((XN_ADMIN_BIND_IP && $_ip != $longip || !XN_ADMIN_BIND_IP) && $time - $_time > 3600) {
			$cookie_secure = isset($conf['cookie_secure']) ? $conf['cookie_secure'] : false;
			setcookie('bbs_admin_token', '', array('expires' => 0, 'path' => '', 'domain' => '', 'secure' => $cookie_secure, 'httponly' => true, 'samesite' => 'Lax'));
			message(-1, lang('admin_token_expiry'));
		}
		
		// 超过半小时，重新发新令牌，防止过期
		// More than half an hour, reset a new token, prevent expired
		if($time - $_time > 1800) {
			admin_token_set();
		}
	}
	// hook admin_token_check_end.php
}

function admin_token_set() {
	global $longip, $time, $useragent, $conf;
	$useragent_md5 = md5($useragent);
	//$key = md5($longip.$useragent_md5.$conf['auth_key']);
	$key = md5((XN_ADMIN_BIND_IP ? $longip : '').$useragent_md5.xn_key());
	
	// hook admin_token_set_start.php
	
	$admin_token = param('bbs_admin_token');
	$s = "$longip	$time";
	
	$admin_token = xn_encrypt($s, $key);
	$cookie_secure = isset($conf['cookie_secure']) ? $conf['cookie_secure'] : false;
	$cookie_options = array(
		'expires' => $time + 3600,
		'path' => '',
		'domain' => '',
		'secure' => $cookie_secure,
		'httponly' => true,
		'samesite' => 'Lax',
	);
	setcookie('bbs_admin_token', $admin_token, $cookie_options);

	// hook admin_token_set_end.php
}

function admin_token_clean() {
	global $time, $conf;
	$cookie_secure = isset($conf['cookie_secure']) ? $conf['cookie_secure'] : false;
	$cookie_options = array(
		'expires' => $time - 86400,
		'path' => '',
		'domain' => '',
		'secure' => $cookie_secure,
		'httponly' => true,
		'samesite' => 'Lax',
	);
	setcookie('bbs_admin_token', '', $cookie_options);

	// hook admin_token_clean_start.php
}

// bootstrap style
function admin_tab_active($arr, $active) {
	// hook admin_tab_active_start.php
	$s = '<ul class="nav nav-tabs gap-2  ">';
	foreach ($arr as $k=>$v) {
		$s .= '<li class="nav-item"><a class="nav-link '.($active == $k ? ' active' : '').'" href="'.$v['url'].'">'.$v['text'].'</a></li>';
	}
	$s .= '</ul>';
	// hook admin_tab_active_end.php
	return $s;
}

// hook admin_func_end.php

?>