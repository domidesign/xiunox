<?php

!defined('DEBUG') AND exit('Access Denied');

// hook theme_start.php

if($method == 'POST') {

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
