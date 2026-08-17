<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook ip_start.php

// IP 归属地查询页（数据来源：ip9.com.cn 免费接口，限 60 次/分钟/IP）
$header['title'] = lang('ip_page') . ' - ' . $conf['sitename'];
$header['canonical'] = absolute_url(url('ip'));
$header['og_type'] = 'website';
$_SESSION['fid'] = 0;

// 读取要查询的 IP（用 $_GET 原始值，param() 的 htmlspecialchars 会干扰 IP 字符）
$_ip_input = isset($_GET['ip']) ? trim((string)$_GET['ip']) : '';
// 未传 IP 时默认查询访客自身 IP
if($_ip_input === '') {
	$_ip_input = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

// 输入校验：仅接受合法 IPv4/IPv6，非法值直接提示错误不请求接口
if($_ip_input === '' || !filter_var($_ip_input, FILTER_VALIDATE_IP)) {
	$_ip_error = lang('ip_invalid');
	$_ip_info = array();
} else {
	$_ip_error = '';
	// 10 分钟短缓存：同一 IP 重复查询不重复请求外部接口，规避免费额度限制
	$_ip_cache_key = 'ip_query_' . md5($_ip_input);
	$_ip_info = CacheHelper::remember($_ip_cache_key, 600, function() use ($_ip_input) {
		$_raw = http_get('https://ip9.com.cn/get?ip=' . rawurlencode($_ip_input), '', 10, 2);
		if(is_string($_raw) && $_raw !== '') {
			$_json = json_decode($_raw, true);
			if(is_array($_json) && intval(isset($_json['ret']) ? $_json['ret'] : 0) == 200 && !empty($_json['data']) && is_array($_json['data'])) {
				return $_json['data'];
			}
		}
		return array();
	});
	if(empty($_ip_info)) {
		$_ip_error = lang('ip_query_failed');
	}
}

// hook ip_end.php

include _include(APP_PATH.'view/htm/ip.htm');

?>
