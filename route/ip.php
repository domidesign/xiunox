<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook ip_start.php

// IP 归属地查询页（数据来源：ip9.com.cn 免费接口，限 60 次/分钟/IP）
$header['title'] = lang('ip_page') . ' - ' . $conf['sitename'];
$header['canonical'] = absolute_url(url('ip'));
$header['og_type'] = 'website';
$_SESSION['fid'] = 0;

// 读取要查询的 IP
// ponytail: 直接从 REQUEST_URI 正则提取 ip 参数，兼容所有 URL 格式
// （/?ip.htm&ip=xxx, /?ip.htm?ip=xxx, /ip.htm?ip=xxx, /ip?ip=xxx）
// 原因：Xiuno xn_url_parse 在 count($arr3)==count($_GET) 时不覆盖 $_GET，
// 双 ? 格式（/?ip.htm?ip=xxx）下 PHP 原生 $_GET 键为 'ip.htm?ip'，$_GET['ip'] 拿不到值
$_ip_input = '';
if(isset($_SERVER['REQUEST_URI']) && preg_match('/[?&]ip=([^&]+)/', $_SERVER['REQUEST_URI'], $_m)) {
	$_ip_input = trim(urldecode($_m[1]));
}
$_ip_empty = ($_ip_input === '');
$_ip_error = '';
$_ip_info = array();

if(!$_ip_empty) {
	// 输入校验：仅接受合法 IPv4/IPv6，非法值直接提示错误不请求接口
	if(!filter_var($_ip_input, FILTER_VALIDATE_IP)) {
		$_ip_error = lang('ip_invalid');
	} else {
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
}

// hook ip_end.php

include _include(APP_PATH.'view/htm/ip.htm');

?>
