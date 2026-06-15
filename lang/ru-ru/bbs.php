<?php

$lang = array();

$_lang_dir = APP_PATH . "lang/{$conf['lang']}";

$_r = include "$_lang_dir/bbs_common.php"; $lang += is_array($_r) ? $_r : array();

// hook lang_ru_ru_bbs.php

return $lang;

?>
