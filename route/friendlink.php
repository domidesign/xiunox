<?php

!defined('DEBUG') AND exit('Access Denied');

// 前台友情链接申请
$action = param(1, '');

if($action == 'apply') {

	if($method == 'POST') {
		CsrfService::check();

		$name = param('name', '');
		$url = param('url', '', false);

		if(empty($name) || empty($url)) {
			message(-1, lang('data_is_empty'));
		}

		$arr = array(
			'type' => 1, // 待审核
			'name' => $name,
			'url' => $url,
			'favicon' => '',
			'rank' => 0,
			'create_date' => time(),
		);
		$r = db_create('friendlink', $arr);
		if($r === FALSE) {
			message(-1, lang('error'));
		}

		message(0, lang('friendlink_apply_success'));
	}

	message(-1, lang('illegal_request'));
}

http_404();

?>
