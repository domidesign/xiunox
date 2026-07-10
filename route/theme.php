<?php

!defined('DEBUG') AND exit('Access Denied');

// hook theme_start.php

if($method == 'POST') {

	// CSRF 校验（中央化校验已兜底，此处显式声明）
	CsrfService::check();

	// 修改站点全局配置需管理员权限
	if($gid != 1) {
		message(1, lang('insufficient_admin_privilege'));
	}

	$theme = param('theme');

	if(!in_array($theme, array('light', 'dark'))) {
		message(1, lang('theme_invalid'));
	}

	$replace = array('site_theme' => $theme);
	$conf['site_theme'] = $theme;
	file_replace_var(APP_PATH.'conf/conf.php', $replace);

	message(0, lang('theme_saved'));
}

// hook theme_end.php

?>
