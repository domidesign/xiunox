<?php


define('ADMIN_PATH', dirname(__FILE__).'/'); // __DIR__
define('MESSAGE_HTM_PATH', ADMIN_PATH.'view/htm/message.htm');

define('SKIP_ROUTE', TRUE);
include '../index.php';

$_r = include _include(APP_PATH."lang/$conf[lang]/bbs_admin.php");
$lang += is_array($_r) ? $_r : array();

// 积分类型名称动态覆盖
if(isset($conf['credits_name']) && $conf['credits_name']) {
    $lang['credits_label'] = $conf['credits_name'];
    $lang['admin_credits_type_credits'] = $conf['credits_name'];
    $lang['admin_credits_rule_credits_change'] = $conf['credits_name'] . '变化';
}
if(isset($conf['golds_name']) && $conf['golds_name']) {
    $lang['golds_label'] = $conf['golds_name'];
    $lang['admin_credits_type_golds'] = $conf['golds_name'];
    $lang['admin_credits_rule_golds_change'] = $conf['golds_name'] . '变化';
}
if(isset($conf['rmbs_name']) && $conf['rmbs_name']) {
    $lang['admin_credits_type_rmbs'] = $conf['rmbs_name'];
    $lang['admin_credits_rule_rmbs_change'] = $conf['rmbs_name'] . '变化';
}

$_SERVER['lang'] = $lang;

include _include(ADMIN_PATH."admin.func.php");
$menu = include _include(ADMIN_PATH.'menu.conf.php');
include _include(ADMIN_PATH.'index.inc.php');

?>
