<?php

// hook admin_func_start.php

// 有部分用户
define('XN_ADMIN_BIND_IP', array_value($conf, 'admin_bind_ip'));

// 令牌失效：flash cookie 传递 toast + 跳转登录页（替代整页 message.htm）
function admin_token_expiry_redirect() {
	$msg = lang('admin_token_expiry');
	// PHP setcookie 内部自动 urlencode，前端 decodeURIComponent 一次即可解码；
	// 此处禁止再 rawurlencode，否则双重编码导致前端显示 %E7%AE%A1... 乱码
	setcookie('flash_msg', $msg, time() + 10, '/');
	setcookie('flash_type', 'danger', time() + 10, '/');
	$login_url = url('index-login');
	// HTMX 请求：HX-Redirect 头让 htmx 执行整页跳转
	if(function_exists('is_htmx_request') && is_htmx_request()) {
		header('HX-Redirect: ' . $login_url);
		header('Content-Type: text/html; charset=utf-8');
		echo '<span style="display:none"></span>';
		exit;
	}
	// 普通请求：303 重定向
	http_response_code(303);
	header('Location: ' . $login_url);
	exit;
}

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
		$used_v2 = false;
		$s = xn_decrypt($admin_token, $key, $used_v2);
		if(empty($s)) {
			setcookie('bbs_admin_token', '', admin_cookie_options(0));
			admin_token_expiry_redirect();
		}
		list($_ip, $_time) = explode("\t", $s);

		// 后台超过 3600 自动退出。
		// Background / more than 3600 automatic withdrawal.
		//if($_ip != $longip || $time - $_time > 3600) {
		if((XN_ADMIN_BIND_IP && $_ip != $longip || !XN_ADMIN_BIND_IP) && $time - $_time > 3600) {
			setcookie('bbs_admin_token', '', admin_cookie_options(0));
			admin_token_expiry_redirect();
		}

		// 令牌迁移：若解密回退到 XXTEA（旧格式）立即重签 v2；或超过半小时刷新令牌防过期
		// logout 请求跳过刷新，避免先设置新 cookie 再清除导致清除失效
		if(($_REQUEST[0] ?? '') !== 'index' || ($_REQUEST[1] ?? '') !== 'logout') {
			if(!$used_v2 || $time - $_time > 1800) {
				admin_token_set();
			}
		}
	}
	// hook admin_token_check_end.php
}

// 获取后台 Cookie 安全选项
function admin_cookie_options($expires = 0) {
	global $conf;
	$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

	// Cookie Secure：security_cookie_secure 已设置时 0=自动检测HTTPS，>0=强制Secure
	if(isset($conf['security_cookie_secure'])) {
		$cookie_secure = intval($conf['security_cookie_secure']) > 0 || $is_https;
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
	$s = '<ul class="nav nav-tabs nav-tabs-scroll gap-2">';
	foreach ($arr as $k=>$v) {
		$s .= '<li class="nav-item"><a class="nav-link '.($active == $k ? ' active' : '').'" href="'.$v['url'].'">'.$v['text'].'</a></li>';
	}
	$s .= '</ul>';
	// hook admin_tab_active_end.php
	return $s;
}

// hook admin_func_end.php

?>