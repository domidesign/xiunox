<?php
!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'list');

$all_themes = array('light', 'dark');

if($action == 'list') {

    if($method == 'GET') {
        $default_theme = isset($conf['default_theme']) ? $conf['default_theme'] : 'light';
        if(!in_array($default_theme, $all_themes)) {
            $default_theme = 'light';
        }

        // 获取当前主题色（从 conf 读取，默认 blue）
        $current_brand = isset($conf['theme_brand']) ? $conf['theme_brand'] : 'blue';
        $valid_brands = array('blue', 'green', 'purple', 'red', 'orange');
        if(!in_array($current_brand, $valid_brands)) {
            $current_brand = 'blue';
        }

        $header['title'] = lang('admin_theme_setting');
        $header['mobile_title'] = lang('admin_theme_setting');

        include _include(ADMIN_PATH.'view/htm/theme.htm');
    } else {
        CsrfService::check();

        $default_theme = param('default_theme', 'light');

        if(!in_array($default_theme, $all_themes)) {
            $default_theme = 'light';
        }

        $replace = array(
            'enabled_themes' => $all_themes,
            'default_theme' => $default_theme,
        );
        file_replace_var(APP_PATH.'conf/conf.php', $replace);

        admin_log_create('theme_switch', 'theme', '', '切换主题：' . $default_theme);

        message(0, lang('modify_successfully'));
    }

} elseif($action == 'default') {

    CsrfService::check();

    $theme = param('theme');
    if(!in_array($theme, $all_themes)) {
        message(1, '无效的主题');
    }

    $replace = array(
        'enabled_themes' => $all_themes,
        'default_theme' => $theme,
    );
    file_replace_var(APP_PATH.'conf/conf.php', $replace);

    admin_log_create('theme_switch', 'theme', '', '设置默认主题：' . $theme);

    message(0, '已设为默认');

}
?>
