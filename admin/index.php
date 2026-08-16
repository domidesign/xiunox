<?php


define('ADMIN_PATH', dirname(__FILE__).'/'); // __DIR__
define('MESSAGE_HTM_PATH', ADMIN_PATH.'view/htm/message.htm');

define('SKIP_ROUTE', TRUE);
include '../index.php';

$_r = include _include(APP_PATH."lang/$conf[lang]/bbs_admin.php");
$lang += is_array($_r) ? $_r : array();

// 兜底加载插件 admin 语言包
// 替代有语法冲突的 lang hook（bbs_admin.php 的 hook 注入点在 return array() 内部，
// 与校验器要求的 $lang['key']='value'; 语句格式不兼容）
// 加 try/catch 隔离：单个插件语言包语法错误不让整个 admin 白屏
foreach(plugin_paths_enabled() as $_path => $_pconf) {
	$_plugin_lang_file = $_path."/lang/$conf[lang]/bbs_admin.php";
	if(is_file($_plugin_lang_file)) {
		try {
			$_pr = include $_plugin_lang_file;
			if(is_array($_pr)) $lang += $_pr;
		} catch(\Throwable $_e) {
			xn_log("Plugin admin lang file error, skipped: $_plugin_lang_file - ".$_e->getMessage(), 'plugin_syntax_error');
		}
	}
}
unset($_pr, $_plugin_lang_file, $_path, $_pconf);

// 积分类型名称动态覆盖
if(isset($conf['credits_name']) && $conf['credits_name']) {
    $lang['credits_label'] = $conf['credits_name'];
    $lang['admin_credits_type_credits'] = $conf['credits_name'];
    $lang['admin_credits_rule_credits_change'] = $conf['credits_name'] . lang('admin_change_suffix');
}
if(isset($conf['golds_name']) && $conf['golds_name']) {
    $lang['golds_label'] = $conf['golds_name'];
    $lang['admin_credits_type_golds'] = $conf['golds_name'];
    $lang['admin_credits_rule_golds_change'] = $conf['golds_name'] . lang('admin_change_suffix');
}
if(isset($conf['rmbs_name']) && $conf['rmbs_name']) {
    $lang['rmb_label'] = $conf['rmbs_name'];
    $lang['admin_credits_type_rmbs'] = $conf['rmbs_name'];
    $lang['admin_credits_rule_rmbs_change'] = $conf['rmbs_name'] . lang('admin_change_suffix');
}

$_SERVER['lang'] = $lang;

include _include(ADMIN_PATH."admin.func.php");
$menu = include _include(ADMIN_PATH.'menu.conf.php');
include _include(ADMIN_PATH.'index.inc.php');

?>
