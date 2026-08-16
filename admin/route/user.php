<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook admin_user_start.php

if(empty($action) || $action == 'list') {

	$header['title'] = lang('user_admin');
	$header['mobile_title'] = lang('user_admin');
		
	$pagesize = 20;
	$srchtype = param(2);
	$keyword  = trim(xn_urldecode(param(3)));
	$page     = param(4, 1);
	$ban_type_filter = param('ban_type', '');

	// hook admin_user_list_start.php

	// 加载 UserBanService 用于获取用户封禁状态
	if(!class_exists('UserBanService')) {
		include_once APP_PATH.'lib/UserBanService.php';
	}

	$cond = array();
	$allowtype = array('uid', 'username', 'nickname', 'email', 'gid', 'create_ip');

	// hook admin_user_list_allow_type_after.php

	if($keyword) {
		!in_array($srchtype, $allowtype) AND $srchtype = 'uid';
		if($srchtype == 'create_ip') {
			// IP 字段为整型存储，使用精确匹配
			$cond[$srchtype] = sprintf('%u', ip2long($keyword));
		} elseif($srchtype == 'uid' || $srchtype == 'gid') {
			// uid / gid 为数值字段，使用精确匹配
			$cond[$srchtype] = $keyword;
		} else {
			// username / nickname / email 使用 LIKE 模糊匹配
			// 转义 LIKE 通配符 % 和 _，防止用户输入的通配符干扰搜索结果
			$escaped_keyword = str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), $keyword);
			// db_cond_to_sqladd 会将 array('LIKE' => $v) 转为 LIKE '%v%' 并参数化绑定，杜绝 SQL 注入
			$cond[$srchtype] = array('LIKE' => $escaped_keyword);
		}
	}

	// 按封禁状态筛选
	if($ban_type_filter !== '' && function_exists('db_check_column_exists') && db_check_column_exists('user', 'ban_type')) {
		$cond['ban_type'] = intval($ban_type_filter);
	}

	// hook admin_user_list_cond_after.php
// 后台需要精确的用户数用于分页：cond 为空时 db_count 会走 information_schema.TABLE_ROWS（InnoDB 估算值，误差大）
// 强制加 uid>0 条件触发精确 COUNT(*) 分支（uid 从 1 开始，uid>0 等价于全表）
if(empty($cond)) {
	$cond['uid'] = array('>' => 0);
}
$n = user_count($cond);
$userlist = user_find($cond, array('uid'=>-1), $page, $pagesize);
$ban_type_query = ($ban_type_filter !== '') ? '?ban_type='.intval($ban_type_filter) : '';
$pagination = pagination(url("user-list-$srchtype-".urlencode($keyword).'-{page}').$ban_type_query, $n, $page, $pagesize);
$pager = pager(url("user-list-$srchtype-".urlencode($keyword).'-{page}').$ban_type_query, $n, $page, $pagesize);

foreach ($userlist as &$_user) {
		$_user['group'] = array_value($grouplist, $_user['gid'], '');
		$_user['ban_status'] = UserBanService::getBanStatus($_user['uid']);
	}

	// hook admin_user_list_end.php
	
	include _include(ADMIN_PATH."view/htm/user_list.htm");

} elseif($action == 'create') {

	// hook admin_user_create_get_post.php
	
	if($method == 'GET') {

		// hook admin_user_create_get_start.php
		
		$header['title'] = lang('admin_user_create');
		$header['mobile_title'] = lang('admin_user_create');
		
		$input['email'] = form_text('email', '');
		$input['username'] = form_text('username','');
		$input['password'] = form_password('password', '');
		$grouparr = arrlist_key_values($grouplist, 'gid', 'name');
		$input['_gid'] = form_select('_gid', $grouparr, 0);
		
		// hook admin_user_create_get_end.php
		
		include _include(ADMIN_PATH."view/htm/user_create.htm");

	} elseif ($method == 'POST') {

		CsrfService::check();

		$email = param('email');
		$username = param('username');
		$password = param('password', '', FALSE);
		$_gid = param('_gid');
		
		// hook admin_user_create_post_start.php
		
		empty($email) AND message('email', lang('please_input_email'));
		$email AND !is_email($email, $err) AND message('email', $err);
		$username AND !is_username($username, $err) AND message('username', $err);

		$_user = user_read_by_email($email);
		$_user AND message('email', lang('email_is_in_use'));

		$_user = user_read_by_username($username);
		$_user AND message('username', lang('user_already_exists'));

		$salt = xn_rand(16);
		$r = user_create(array(
			'username'=>$username,
			'password'=>md5(md5($password).$salt),
			'salt'=>$salt,
			'gid'=>$_gid,
			'email'=>$email,
			'create_ip'=>$longip,
			'create_date'=>$time
		));
		$r === FALSE AND message(-1, lang('create_failed'));

		admin_log_create('user_create', 'user', strval($r), lang('admin_log_user_create', array('name'=>$username)));

		// hook admin_user_create_post_end.php

		message(0, lang('create_successfully'));

	}

} elseif($action == 'update') {

	$_uid = param(2, 0);
	
	// hook admin_user_update_get_post.php
	
	if($method == 'GET') {

		// hook admin_user_update_get_start.php
		
		$header['title'] = lang('user_edit');
		$header['mobile_title'] = lang('user_edit');
		
		$_user = user_read($_uid);

		$input['email'] = form_text('email', $_user['email']);
		$input['username'] = form_text('username', $_user['username']);
		$input['nickname'] = form_text('nickname', isset($_user['nickname']) ? $_user['nickname'] : $_user['username']);
		$input['password'] = form_password('password', '');
		$grouparr = arrlist_key_values($grouplist, 'gid', 'name');
		$input['_gid'] = form_select('_gid', $grouparr, $_user['gid']);
		$input['credits'] = form_text('credits', $_user['credits']);
		$input['golds'] = form_text('golds', $_user['golds']);
		$input['rmbs'] = form_text('rmbs', $_user['rmbs']);
		$input['signature'] = form_text('signature', isset($_user['signature']) ? $_user['signature'] : '');
		// 头像信息：显示当前头像和重置按钮
		$input['avatar_url'] = isset($_user['avatar_url']) ? $_user['avatar_url'] : default_avatar_url();
		$input['avatar_val'] = intval($_user['avatar']);

		// 加载封禁状态
		if(!class_exists('UserBanService')) {
			include_once APP_PATH.'lib/UserBanService.php';
		}
		$ban_status = UserBanService::getBanStatus($_uid);
		// 封禁操作管理员用户名
		$ban_admin_username = '';
		if($ban_status['ban_type'] != 0 && !empty($_user['ban_admin_uid'])) {
			$_ban_admin = user_read($_user['ban_admin_uid']);
			$ban_admin_username = $_ban_admin ? $_ban_admin['username'] : '';
		}
		$ban_status['admin_username'] = $ban_admin_username;
		$ban_status['ban_time_fmt'] = !empty($ban_status['ban_time']) ? date('Y-m-d H:i:s', $ban_status['ban_time']) : '';

		// hook admin_user_update_get_end.php
		
		include _include(ADMIN_PATH."view/htm/user_update.htm");

	} elseif($method == 'POST') {

		CsrfService::check();

		$email = param('email');
		$username = param('username');
		$nickname = param('nickname');
		$password = param('password', '', FALSE);
		$_gid = param('_gid');
		$signature = param('signature', '', FALSE);
	// 签名支持HTML：使用xn_signature_purify净化
	if ($signature !== '') {
		$signature = xn_signature_purify($signature);
	}
		$reset_avatar = param('reset_avatar', 0);

		// 积分调整参数
		$credits_action = param('credits_action', 0);
		$credits_amount = param('credits_amount', 0);
		$golds_action = param('golds_action', 0);
		$golds_amount = param('golds_amount', 0);
		$rmbs_action = param('rmbs_action', 0);
		$rmbs_amount = param('rmbs_amount', 0);
		$credits_reason = param('credits_reason', '');

		// hook admin_user_update_post_start.php
		
		$old = user_read($_uid);
		empty($old) AND message('username', lang('uid_not_exists'));
		
		$email AND !is_email($email, $err) AND message(2, $err);
		if($email AND $old['email'] != $email) {
			$_user = user_read_by_email($email);
			$_user AND $_user['uid'] != $_uid AND message('email', lang('email_already_exists'));
		}
		if($username AND $old['username'] != $username) {
			$_user = user_read_by_username($username);
			$_user AND $_user['uid'] != $_uid AND message('username', lang('user_already_exists'));
		}
		// 昵称唯一性检查
		if($nickname AND db_check_column_exists('user', 'nickname')) {
			$_nick_user = db_find_one('user', array('nickname'=>$nickname));
			$_nick_user AND $_nick_user['uid'] != $_uid AND message('nickname', lang('nickname_is_in_use'));
		}
		
		// 安全修改密码（使用专用函数，绕过 user_update 白名单）
		if($password) {
			$r = user_change_password($_uid, $password, '', TRUE);
			$r === FALSE AND message(-1, lang('update_failed'));
		}
		
		// 安全修改用户组（使用专用函数，绕过 user_update 白名单）
		if($_gid != $old['gid']) {
			$r = user_change_group($_uid, $_gid);
			$r === FALSE AND message(-1, lang('update_failed'));
		}
		
		$arr = array();
		$arr['email'] = $email;
		$arr['username'] = $username;
		if(db_check_column_exists('user', 'nickname')) {
			$arr['nickname'] = $nickname;
		}
		if(db_check_column_exists('user', 'signature')) {
			$arr['signature'] = $signature;
		}
		// 重置头像为默认
		if($reset_avatar) {
			$arr['avatar'] = 0;
		}
		
		// hook admin_user_update_post_exec_before.php
		
		// 仅仅更新发生变化的部分 / only update changed field
		$update = array_diff_value($arr, $old);
		if(!empty($update)) {
			$r = user_update($_uid, $update);
			$r === FALSE AND message(-1, lang('update_failed'));
		}

		// 积分调整：通过 CreditsService 增减，自动写入日志
	$hasCreditsChange = ($credits_action != 0 && $credits_amount > 0) || ($golds_action != 0 && $golds_amount > 0) || ($rmbs_action != 0 && $rmbs_amount > 0);
	// 调整积分时必须填写调整理由
	if($hasCreditsChange && !$credits_reason) {
		message('credits_reason', lang('admin_credits_reason_required'));
	}
	if($hasCreditsChange) {
		$reason = $credits_reason;
			include_once APP_PATH . 'lib/CreditsService.php';
			// 使用 $_SERVER['db'] 确保获取到 db 对象（避免全局变量作用域问题）
			$_db = $_SERVER['db'];
			$creditsService = new CreditsService($_db, $conf);

			// reason 是管理员手动输入的自由文本，传 reasonIsRaw=true 加 raw: 前缀，显示时原样返回不翻译
			if($credits_action != 0 && $credits_amount > 0) {
				if($credits_action > 0) {
					$creditsService->add($_uid, 'credits', $credits_amount, $reason, -1, true);
				} else {
					$creditsService->sub($_uid, 'credits', $credits_amount, $reason, -1, true);
				}
			}
			if($golds_action != 0 && $golds_amount > 0) {
				if($golds_action > 0) {
					$creditsService->add($_uid, 'golds', $golds_amount, $reason, -1, true);
				} else {
					$creditsService->sub($_uid, 'golds', $golds_amount, $reason, -1, true);
				}
			}
			if($rmbs_action != 0 && $rmbs_amount > 0) {
				if($rmbs_action > 0) {
					$creditsService->add($_uid, 'rmbs', $rmbs_amount, $reason, -1, true);
				} else {
					$creditsService->sub($_uid, 'rmbs', $rmbs_amount, $reason, -1, true);
				}
			}
		}

		admin_log_create('user_update', 'user', strval($_uid), lang('admin_log_user_update', array('name'=>$old['username'])));

		// hook admin_user_update_post_end.php

		message(0, lang('update_successfully'));
	}

} elseif($action == 'delete') {

	if($method != 'POST') message(-1, 'Method Error.');

	CsrfService::check();

	$_uid = param('uid', 0);

	// hook admin_user_delete_start.php

	$_user = user_read($_uid);
	empty($_user) AND message(-1, lang('user_not_exists'));
	($_user['gid'] == 1) AND message(-1, lang('admin_cant_be_deleted'));

	// 默认走匿名化（保留帖子，清身份信息），user_purge 是彻底物理删除
	$r = user_delete($_uid);
	$r === FALSE AND message(-1, lang('delete_failed'));

	admin_log_create('user_anonymize', 'user', strval($_uid), lang('admin_log_user_anonymize', array('name'=>$_user['username'])));

	// hook admin_user_delete_end.php

	message(0, lang('user_anonymize_success'));

} elseif($action == 'purge') {

	// 彻底物理删除用户及其所有内容（不可恢复）
	if($method != 'POST') message(-1, 'Method Error.');

	CsrfService::check();

	$_uid = param('uid', 0);

	// hook admin_user_purge_start.php

	$_user = user_read($_uid);
	empty($_user) AND message(-1, lang('user_not_exists'));
	($_user['gid'] == 1) AND message(-1, lang('admin_cant_be_deleted'));

	$r = user_purge($_uid);
	$r === FALSE AND message(-1, lang('delete_failed'));

	admin_log_create('user_purge', 'user', strval($_uid), lang('admin_log_user_purge', array('name'=>$_user['username'])));

	// hook admin_user_purge_end.php

	message(0, lang('user_purge_success'));

} elseif($action == 'ban') {

	// 封禁用户 - POST 处理在 header include 之前
	if($method != 'POST') message(-1, lang('method_error'));

	if(!class_exists('CsrfService')) {
		include_once APP_PATH.'lib/CsrfService.php';
	}
	CsrfService::check();

	if(!class_exists('UserBanService')) {
		include_once APP_PATH.'lib/UserBanService.php';
	}

	$uid = intval(param('uid', 0));
	$ban_type = intval(param('ban_type', 0));
	$duration = intval(param('duration', 0));
	$reason = param('reason', '');

	global $user;
	$admin_uid = intval($user['uid']);

	$r = UserBanService::ban($uid, $ban_type, $duration, $reason, $admin_uid);
	if($r['code'] !== 0) {
		message(-1, isset($r['message']) ? $r['message'] : lang('user_ban_failed'));
	}

	$_user = user_read($uid);
	$username = isset($_user['username']) ? $_user['username'] : '';
	admin_log_create('user_ban', 'user', strval($uid), lang('admin_user_ban_log_op').$username.' (type:'.$ban_type.',duration:'.$duration.')');

	message(0, lang('user_ban_success'));

} elseif($action == 'unban') {

	// 解封用户 - POST 处理在 header include 之前
	if($method != 'POST') message(-1, lang('method_error'));

	if(!class_exists('CsrfService')) {
		include_once APP_PATH.'lib/CsrfService.php';
	}
	CsrfService::check();

	if(!class_exists('UserBanService')) {
		include_once APP_PATH.'lib/UserBanService.php';
	}

	$uid = intval(param('uid', 0));
	$reason = param('reason', '');

	global $user;
	$admin_uid = intval($user['uid']);

	$r = UserBanService::unban($uid, $admin_uid, $reason);
	if($r['code'] !== 0) {
		message(-1, isset($r['message']) ? $r['message'] : lang('user_unban_failed'));
	}

	$_user = user_read($uid);
	$username = isset($_user['username']) ? $_user['username'] : '';
	admin_log_create('user_unban', 'user', strval($uid), lang('admin_user_unban_log_op').$username);

	message(0, lang('user_unban_success'));

} elseif($action == 'clear_content') {

	// 清空用户内容 - POST 处理在 header include 之前
	if($method != 'POST') message(-1, lang('method_error'));

	if(!class_exists('CsrfService')) {
		include_once APP_PATH.'lib/CsrfService.php';
	}
	CsrfService::check();

	if(!class_exists('UserBanService')) {
		include_once APP_PATH.'lib/UserBanService.php';
	}

	$uid = intval(param('uid', 0));
	$confirm = param('confirm', 0);

	if(!$confirm) {
		message(-1, lang('user_clear_content_confirm'));
	}

	global $user;
	$admin_uid = intval($user['uid']);

	$r = UserBanService::clearContent($uid, $admin_uid);
	if($r['code'] !== 0) {
		message(-1, isset($r['message']) ? $r['message'] : lang('user_clear_content_failed'));
	}

	$_user = user_read($uid);
	$username = isset($_user['username']) ? $_user['username'] : '';
	admin_log_create('user_clear_content', 'user', strval($uid), lang('admin_user_clear_content_log_op').$username);

	message(0, lang('user_clear_content_success'));

} elseif($action == 'ban_log') {

	// 查看封禁历史 - GET only
	if($method != 'GET') message(-1, lang('method_error'));

	$_uid = param(2, 0);
	$page = param(3, 1);
	$pagesize = 20;

	if(!function_exists('ban_log_find_by_uid')) {
		include_once APP_PATH.'model/ban_log.func.php';
	}
	if(!class_exists('UserBanService')) {
		include_once APP_PATH.'lib/UserBanService.php';
	}

	$_user = user_read($_uid);
	empty($_user) AND message(-1, lang('user_not_exists'));

	$ban_logs = ban_log_find_by_uid($_uid, $page, $pagesize);
	$ban_log_total = ban_log_count_by_uid($_uid);
	$pagination = pagination(url("user-ban_log-$_uid-{page}"), $ban_log_total, $page, $pagesize);

	// 预计算每条记录的标签和管理员用户名
	$action_labels = array(
		'ban' => array('label' => lang('admin_user_ban_log_action_ban'), 'color' => 'danger'),
		'unban' => array('label' => lang('admin_user_ban_log_action_unban'), 'color' => 'success'),
		'auto_unban' => array('label' => lang('admin_user_ban_log_action_auto_unban'), 'color' => 'secondary'),
		'clear_content' => array('label' => lang('admin_user_ban_log_action_clear_content'), 'color' => 'warning'),
	);

	foreach($ban_logs as &$_log) {
		$_admin = user_read($_log['admin_uid']);
		$_log['admin_username'] = $_admin ? $_admin['username'] : lang('admin_user_ban_log_system');
		$_log['action_label'] = isset($action_labels[$_log['action']]) ? $action_labels[$_log['action']]['label'] : $_log['action'];
		$_log['action_color'] = isset($action_labels[$_log['action']]) ? $action_labels[$_log['action']]['color'] : 'secondary';
		$_log['type_label'] = UserBanService::getBanTypeLabel($_log['ban_type']);
		$_log['duration_formatted'] = UserBanService::formatDuration($_log['duration']);
		$_log['create_time_fmt'] = date('Y-m-d H:i:s', $_log['create_time']);
	}

	$header['title'] = lang('admin_user_ban_log_title') . ' - ' . $_user['username'];
	$header['mobile_title'] = lang('admin_user_ban_log_title');

	include _include(ADMIN_PATH."view/htm/user_ban_log.htm");

}

// hook admin_user_end.php

?>