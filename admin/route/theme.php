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
        $valid_brands = array('blue', 'green', 'purple', 'red', 'orange', 'pink', 'teal', 'indigo', 'cyan', 'lime');
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

        // 保存主题色
        $theme_brand = param('theme_brand', 'blue');
        $valid_brands = array('blue', 'green', 'purple', 'red', 'orange', 'pink', 'teal', 'indigo', 'cyan', 'lime');
        if(!in_array($theme_brand, $valid_brands)) {
            $theme_brand = 'blue';
        }

        $replace = array(
            'enabled_themes' => $all_themes,
            'default_theme' => $default_theme,
            'theme_brand' => $theme_brand,
        );
        file_replace_var(APP_PATH.'conf/conf.php', $replace);

        admin_log_create('theme_switch', 'theme', '', '切换主题：' . $default_theme . ' 主题色：' . $theme_brand);

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

} elseif($action == 'brand') {

    CsrfService::check();

    $theme_brand = param('theme_brand', 'blue');
    $valid_brands = array('blue', 'green', 'purple', 'red', 'orange', 'pink', 'teal', 'indigo', 'cyan', 'lime');
    if(!in_array($theme_brand, $valid_brands)) {
        message(1, '无效的主题色');
    }

    $replace = array(
        'theme_brand' => $theme_brand,
    );
    file_replace_var(APP_PATH.'conf/conf.php', $replace);

    admin_log_create('theme_switch', 'theme', '', '设置默认主题色：' . $theme_brand);

    message(0, '已设为默认主题色');
}
?>
