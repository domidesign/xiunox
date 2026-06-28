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
			setcookie('bbs_admin_token', '', admin_cookie_options(0));
			//message(-1, lang('admin_token_error'));
			message(-1, lang('admin_token_expiry'));
		}
		list($_ip, $_time) = explode("\t", $s);
		
		// 后台超过 3600 自动退出。
		// Background / more than 3600 automatic withdrawal.
		//if($_ip != $longip || $time - $_time > 3600) {
		if((XN_ADMIN_BIND_IP && $_ip != $longip || !XN_ADMIN_BIND_IP) && $time - $_time > 3600) {
			setcookie('bbs_admin_token', '', admin_cookie_options(0));
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

// 获取后台 Cookie 安全选项
function admin_cookie_options($expires = 0) {
	global $conf;
	$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

	// Cookie Secure
	if(isset($conf['security_cookie_secure']) && intval($conf['security_cookie_secure']) > 0) {
		$cookie_secure = true;
	} elseif(isset($conf['cookie_secure'])) {
		$cookie_secure = intval($conf['cookie_secure']) > 0;
	} else {
		$cookie_secure = $is_https;
	}

	// Cookie HttpOnly
	$cookie_httponly = true;
	if(isset($conf['security_cookie_httponly'])) {
		$cookie_httponly = intval($conf['security_cookie_httponly']) > 0;
	}

	// Cookie SameSite
	if(isset($conf['security_cookie_samesite']) && in_array($conf['security_cookie_samesite'], array('Lax', 'Strict', 'None'), true)) {
		$samesite = $conf['security_cookie_samesite'];
	} else {
		$samesite = 'Lax';
	}

	return array(
		'expires' => $expires,
		'path' => '',
		'domain' => '',
		'secure' => $cookie_secure,
		'httponly' => $cookie_httponly,
		'samesite' => $samesite,
	);
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
	setcookie('bbs_admin_token', $admin_token, admin_cookie_options($time + 3600));

	// hook admin_token_set_end.php
}

function admin_token_clean() {
	global $time;
	setcookie('bbs_admin_token', '', admin_cookie_options($time - 86400));

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