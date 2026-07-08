<?php

!defined('DEBUG') AND exit('Access Denied');

$lang_part1 = param(1, '');
$lang_part2 = param(2, '');
$lang_code = $lang_part2 ? $lang_part1.'-'.$lang_part2 : $lang_part1;
$refer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : './';

$supported = array('zh-cn', 'zh-tw', 'en-us');

if (in_array($lang_code, $supported) && is_dir(APP_PATH."lang/$lang_code")) {
    setcookie('lang', $lang_code, time() + 86400 * 365, '/');
} else {
    setcookie('lang', '', time() - 1, '/');
}

http_location($refer);

?>
