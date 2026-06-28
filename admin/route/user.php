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

	// hook admin_user_list_start.php
	
	$cond = array();
	$allowtype = array('uid', 'username', 'nickname', 'email', 'gid', 'create_ip');
	
	// hook admin_user_list_allow_type_after.php
	
	if($keyword) {
		!in_array($srchtype, $allowtype) AND $srchtype = 'uid';
		$cond[$srchtype] = $srchtype == 'create_ip' ? sprintf('%u', ip2long($keyword)) : $keyword; 
	}

	// hook admin_user_list_cond_after.php
	$n = user_count($cond);
	$userlist = user_find($cond, array('uid'=>-1), $page, $pagesize);
	$pagination = pagination(url("user-list-$srchtype-".urlencode($keyword).'-{page}'), $n, $page, $pagesize);
	$pager = pager(url("user-list-$srchtype-".urlencode($keyword).'-{page}'), $n, $page, $pagesize);

	foreach ($userlist as &$_user) {
		$_user['group'] = array_value($grouplist, $_user['gid'], '');
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
		$password = param('password');
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

		admin_log_create('user_create', 'user', strval($r), '创建用户：' . $username);

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
		$input['avatar_url'] = isset($_user['avatar_url']) ? $_user['avatar_url'] : '/view/img/avatar.png';
		$input['avatar_val'] = intval($_user['avatar']);

		// hook admin_user_update_get_end.php
		
		include _include(ADMIN_PATH."view/htm/user_update.htm");

	} elseif($method == 'POST') {

		CsrfService::check();

		$email = param('email');
		$username = param('username');
		$nickname = param('nickname');
		$password = param('password');
		$_gid = param('_gid');
		$signature = param('signature', '');
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
		if($hasCreditsChange) {
			$reason = $credits_reason ? $credits_reason : 'admin_adjust';
			include_once APP_PATH . 'lib/CreditsService.php';
			// 使用 $_SERVER['db'] 确保获取到 db 对象（避免全局变量作用域问题）
			$_db = $_SERVER['db'];
			$creditsService = new CreditsService($_db, $conf);

			if($credits_action != 0 && $credits_amount > 0) {
				if($credits_action > 0) {
					$creditsService->add($_uid, 'credits', $credits_amount, $reason, -1);
				} else {
					$creditsService->sub($_uid, 'credits', $credits_amount, $reason, -1);
				}
			}
			if($golds_action != 0 && $golds_amount > 0) {
				if($golds_action > 0) {
					$creditsService->add($_uid, 'golds', $golds_amount, $reason, -1);
				} else {
					$creditsService->sub($_uid, 'golds', $golds_amount, $reason, -1);
				}
			}
			if($rmbs_action != 0 && $rmbs_amount > 0) {
				if($rmbs_action > 0) {
					$creditsService->add($_uid, 'rmbs', $rmbs_amount, $reason, -1);
				} else {
					$creditsService->sub($_uid, 'rmbs', $rmbs_amount, $reason, -1);
				}
			}
		}

		admin_log_create('user_update', 'user', strval($_uid), '更新用户信息：' . $old['username']);

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
	($_user['gid'] == 1) AND message(-1, 'admin_cant_be_deleted');

	$r = user_delete($_uid);
	$r === FALSE AND message(-1, lang('delete_failed'));

	admin_log_create('user_delete', 'user', strval($_uid), '删除用户：' . $_user['username']);

	// hook admin_user_delete_end.php

	message(0, lang('delete_successfully'));
	
}

// hook admin_user_end.php

?>