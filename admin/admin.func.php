<?php

// hook admin_func_start.php

// 有部分用户
define('XN_ADMIN_BIND_IP', array_value($conf, 'admin_bind_ip'));

// 获取当前 admin 请求的相对 URL（?xxx.htm 格式），用于登录后返回原页面
// 排除登录/登出页本身，避免循环跳转
function admin_current_url() {
	$qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
	if($qs === '' || strpos($qs, 'index-login') !== FALSE || strpos($qs, 'index-logout') !== FALSE) {
		return '';
	}
	return '?' . $qs;
}

// 获取后台登录返回 URL（登录成功后跳转目标）
// 三层 fallback：param('return_url') → param('next') → 空（调用方兜底仪表盘）
// 安全校验：仅允许 admin 站内相对路径（?xxx 或 ./?xxx），禁止任何绝对 URL 防开放重定向
function admin_http_referer() {
	// hook admin_http_referer_start.php
	$referer = param('return_url', '', FALSE);
	empty($referer) AND $referer = param('next', '', FALSE);

	if(empty($referer)) return '';

	// 过滤特殊字符 strip special chars
	$referer = str_replace(array('\"', '"', '<', '>', ' ', '*', "\t", "\r", "\n"), '', $referer);

	// 防循环：排除登录/登出页
	if(strpos($referer, 'index-login') !== FALSE || strpos($referer, 'index-logout') !== FALSE) {
		return '';
	}

	// 防开放重定向：admin 均为站内相对路径，仅允许 ?xxx 或 ./?xxx 格式
	if(!preg_match('#^(\.\/)?\?#', $referer)) {
		return '';
	}

	// hook admin_http_referer_end.php
	return $referer;
}

// 令牌失效：flash cookie 传递 toast + 跳转登录页（替代整页 message.htm）
function admin_token_expiry_redirect() {
	$msg = lang('admin_token_expiry');
	// PHP setcookie 内部自动 urlencode，前端 decodeURIComponent 一次即可解码；
	// 此处禁止再 rawurlencode，否则双重编码导致前端显示 %E7%AE%A1... 乱码
	setcookie('flash_msg', $msg, time() + 10, '/');
	setcookie('flash_type', 'danger', time() + 10, '/');
	$login_url = url('index-login');
	// 附加 return_url 供登录后返回原页面
	$return_url = admin_current_url();
	if($return_url) {
		$login_url .= (strpos($login_url, '?') !== FALSE ? '&' : '?') . 'return_url=' . rawurlencode($return_url);
	}
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
		// 改写路由到登录页前，记录原始 URL 供登录后返回（QUERY_STRING 此时仍是原始请求的）
		$return_url = admin_current_url();
		if($return_url) {
			$_REQUEST['return_url'] = $return_url;
		}
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

		// 后台超过管理员登录时效（分钟，默认 60）自动退出。
		// Background / more than admin login expire auto logout.
		$admin_expire = isset($conf['security_admin_login_expire']) ? intval($conf['security_admin_login_expire']) : 60;
		$admin_expire = max(1, $admin_expire) * 60;
		//if($_ip != $longip || $time - $_time > 3600) {
		if((XN_ADMIN_BIND_IP && $_ip != $longip || !XN_ADMIN_BIND_IP) && $time - $_time > $admin_expire) {
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
	
	// 管理员登录时效（分钟），默认 60（1 小时），与 admin_token_check 校验一致
	$admin_expire = isset($conf['security_admin_login_expire']) ? intval($conf['security_admin_login_expire']) : 60;
	$admin_expire = max(1, $admin_expire) * 60;
	
	$admin_token = xn_encrypt($s, $key);
	setcookie('bbs_admin_token', $admin_token, admin_cookie_options($time + $admin_expire));

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