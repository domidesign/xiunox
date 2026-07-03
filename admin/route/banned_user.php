<?php
// 黑名单 - 封禁用户管理（与 IP 黑名单并列，统一在「黑名单」菜单下）

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook admin_banned_user_start.php

// 加载依赖（生产环境走 tmp/model.min.php 合并加载，类加载顺序不可预测）
if(!class_exists('UserBanService')) {
	include_once APP_PATH.'lib/UserBanService.php';
}
if(!function_exists('user_find')) {
	include_once APP_PATH.'model/user.func.php';
}
// 后台默认未加载头像组件，模板需调用 avatar_component_from_data()
if(!function_exists('avatar_component_from_data')) {
	include_once APP_PATH.'lib/avatar_component.php';
}

if(empty($action) || $action == 'list') {

	// hook admin_banned_user_list_start.php

	$header['title'] = lang('admin_banned_user_title');
	$header['mobile_title'] = lang('admin_banned_user_title');

	// 搜索参数：兼容 GET query（搜索表单提交）和路径段（分页链接）
	$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : trim(xn_urldecode(param(2)));
	$page = param(3, 1);
	$pagesize = 50;

	// 仅查询当前封禁中的用户（ban_type > 0）
	$cond = array('ban_type' => array('>' => 0));

	if($keyword !== '') {
		if(is_numeric($keyword)) {
			// 数字按 UID 精确匹配
			$cond['uid'] = intval($keyword);
		} else {
			// 用户名模糊匹配，转义 LIKE 通配符 % 和 _
			$escaped = str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), $keyword);
			$cond['username'] = array('LIKE' => $escaped);
		}
	}

	// hook admin_banned_user_list_cond_after.php

	$n = user_count($cond);
	$userlist = user_find($cond, array('ban_time' => -1), $page, $pagesize);
	$pagination = pagination(url("banned_user-list-".urlencode($keyword).'-{page}'), $n, $page, $pagesize);

	// 格式化每条记录的封禁状态展示字段
	foreach($userlist as &$_user) {
		$_banType = intval($_user['ban_type']);
		$_bannedUntil = intval($_user['banned_until']);
		$_label = UserBanService::getBanTypeLabel($_banType);
		$_user['ban_status'] = array(
			'ban_type'         => $_banType,
			'ban_reason'       => isset($_user['ban_reason']) ? (string)$_user['ban_reason'] : '',
			'ban_time'         => intval($_user['ban_time']),
			'banned_until'     => $_bannedUntil,
			'expire_formatted' => UserBanService::formatExpireTime($_bannedUntil),
			'status_label'     => $_label['label'],
			'status_color'     => $_label['color'],
		);
		// 操作管理员用户名（批量预读避免模板内 N+1）
		$_ban_admin_uid = isset($_user['ban_admin_uid']) ? intval($_user['ban_admin_uid']) : 0;
		$_ban_admin = $_ban_admin_uid > 0 ? user_read($_ban_admin_uid) : false;
		$_user['ban_admin_username'] = $_ban_admin ? $_ban_admin['username'] : '-';
	}
	unset($_user);

	// hook admin_banned_user_list_end.php

	include _include(ADMIN_PATH."view/htm/banned_user_list.htm");

} elseif($action == 'unban') {

	// 解封操作 - POST 处理在 header include 之前
	if($method != 'POST') message(-1, lang('method_error'));

	if(!class_exists('CsrfService')) {
		include_once APP_PATH.'lib/CsrfService.php';
	}
	CsrfService::check();

	$uid = intval(param('uid', 0));
	$reason = param('reason', '', FALSE);

	if($uid <= 0) {
		message(-1, lang('user_ban_invalid_uid'));
	}

	global $user;
	$admin_uid = intval($user['uid']);

	$r = UserBanService::unban($uid, $admin_uid, $reason);
	if($r['code'] !== 0) {
		message(-1, isset($r['message']) ? $r['message'] : lang('admin_banned_user_unban_failed'));
	}

	$_u = user_read($uid);
	$_username = isset($_u['username']) ? $_u['username'] : '';
	admin_log_create('admin_op_user_unban', 'user', strval($uid), lang('admin_banned_user_unban_log').$_username);

	// hook admin_banned_user_unban_post_end.php

	message(0, lang('admin_banned_user_unban_success'));
}

// hook admin_banned_user_end.php

?>
