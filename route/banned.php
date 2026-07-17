<?php
// 封禁公示页

!defined('DEBUG') AND exit('Access Denied.');

// hook banned_start.php

// 检查是否开启公示功能：默认开启，仅在 conf.php 显式设为 0 时关闭
// 注意：Xiuno 只加载 conf/conf.php 不合并 conf.default.php，旧 conf.php 可能无此项
if(isset($conf['ban_show_public_list']) && empty($conf['ban_show_public_list'])) {
	http_404();
}

// 读取 tab 参数：current（默认）/ recent，白名单校验
// URL 形式：/banned-current 或 /banned-recent，默认 /banned 等价于 /banned-current
$active_tab = param(1, 'current');
if(!in_array($active_tab, array('current', 'recent'), true)) {
	$active_tab = 'current';
}

// 加载依赖（生产环境走 tmp/model.min.php 合并加载，类加载顺序不可预测）
if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
if(!function_exists('ban_log_find_recent_unbanned')) { include_once APP_PATH.'model/ban_log.func.php'; }
if(!function_exists('user_find')) { include_once APP_PATH.'model/user.func.php'; }

// 获取当前封禁中的用户（ban_type > 0），按封禁时间倒序，最多 50 条
// 使用 user_find 而非裸 db_sql_find：内部调用 db_find 并填充静态缓存 + user_format（添加 display_name/avatar_url 等）
$banlist = user_find(array('ban_type' => array('>' => 0)), array('ban_time' => -1), 1, 50);

// 为每个用户格式化封禁状态
// 直接从 db_find 已返回的 ban_type/banned_until/ban_reason/ban_time 格式化，避免 getBanStatus 循环内重复查库
foreach($banlist as &$_user) {
	$_banType = intval($_user['ban_type']);
	$_bannedUntil = intval($_user['banned_until']);
	$_label = UserBanService::getBanTypeLabel($_banType);
	$_user['ban_status'] = array(
		'ban_type'        => $_banType,
		'ban_reason'      => isset($_user['ban_reason']) ? (string)$_user['ban_reason'] : '',
		'ban_time'        => intval($_user['ban_time']),
		'banned_until'    => $_bannedUntil,
		'expire_formatted'=> UserBanService::formatExpireTime($_bannedUntil),
		'status_label'    => $_label['label'],
		'status_color'    => $_label['color'],
	);
}
unset($_user);

// 获取近期解封的用户（30天内，最多 20 条）
$recent_unbanned = ban_log_find_recent_unbanned(30, 20);

// 批量预加载用户数据，消除模板内 user_read_cache 的 N+1 查询
if(!empty($recent_unbanned) && function_exists('user_preload')) {
	$_recent_uids = array();
	foreach($recent_unbanned as $_log) {
		$_recent_uids[] = intval($_log['uid']);
	}
	user_preload($_recent_uids);
}

// hook banned_list_display.php
// 触发 bannedListDisplay 事件，允许插件修改展示数据（参数引用，可修改 banlist/recent_unbanned）
$hook_data = array(
	'banlist'         => &$banlist,
	'recent_unbanned' => &$recent_unbanned,
);
XnEvent::trigger('UserBanService.bannedListDisplay', $hook_data);

// 设置页面标题
$header['title'] = lang('user_ban_public_list_title');
$header['keywords'] = '';
$header['description'] = lang('user_ban_public_list_title');
// SEO: 封禁列表页 canonical + noindex（动态内容，价值低，禁止索引）
$header['canonical'] = absolute_url(url('banned'));
$header['noindex'] = TRUE;
$_SESSION['fid'] = 0;

// hook banned_end.php

include _include(APP_PATH.'view/htm/banned.htm');

?>
