<?php

!defined('DEBUG') AND exit('Access Denied.');

include _include(XIUNOPHP_PATH.'xn_send_mail.func.php');
include _include(APP_PATH.'lib/LoginSecurityService.php');

$action = param(1);

is_numeric($action) AND $action = '';

// hook user_start.php

if(empty($action)) {

        // hook user_index_start.php

        $_uid = param(1, 0);
        empty($_uid) AND $_uid = $uid;
        $_user = user_read($_uid);

	empty($_user) AND error_page(404, lang('user_not_exists'));

	// 设置当前查看用户的关注状态
	$_user['is_followed'] = !empty($uid) && $uid != $_uid ? user_follow_read($uid, $_uid) : false;

        $header['title'] = $_user['display_name'] ?? $_user['username'];
        $header['mobile_title'] = $_user['display_name'] ?? $_user['username'];

	$page = param(2, 1);
	$pagesize = 10;

	// 只加载帖子 tab，其他 tab 由 htmx 按需请求
	// 直接查 thread 表（与 user-thread 页一致），避免 mythread 表不同步
	// 版块权限过滤必须在 SQL 层完成，否则 thread_list_access_filter 在分页后删除无权限版块帖子，会导致每页条数不一致
	$thread_cond = array('uid' => $_uid);
	$threadlist = NULL; // 标记是否已查询，避免无可见版块时仍执行 thread_find
	if($gid == 0 || ($gid > 2 && $uid != $_uid)) {
		$forumlist_tmp = forum_list_cache();
		$accessible_fids = array_keys(forum_list_access_filter($forumlist_tmp, $gid));
		if(empty($accessible_fids)) {
			// 无可见版块：直接置空，跳过 thread_find 避免 fid IN() 无效 SQL
			$totalnum = 0;
			$threadlist = array();
		} else {
			$thread_cond['audit_status'] = 1;
			$thread_cond['fid'] = $accessible_fids; // db_find 展开为 fid=v1 OR fid=v2 ... 等价 IN
			$totalnum = thread_count($thread_cond);
		}
	} else {
		$totalnum = $_user['threads'];
	}
	if($threadlist === NULL) {
		$threadlist = thread_find($thread_cond, array('tid' => -1), $page, $pagesize);
	}
	thread_list_access_filter($threadlist, $gid);
	// 加载版块列表，仅供管理工具栏（移动帖子）使用，普通用户（gid>=5）无需加载
	$forumlist = array();
	if($gid > 0 && $gid < 5) {
		$forumlist = forum_list_cache();
	}
	// 管理员组强制显示勾选框
	if($gid > 0 && $gid < 5) {
		$force_show_checkbox = TRUE;
	}
	$pagination = pagination(url("user-$_uid-{page}"), $totalnum, $page, $pagesize);

	// 其他 tab 数据不加载
	$postlist = array();
	$following_userlist = array();
	$followers_userlist = array();
	$fav_threadlist = array();
	$like_threadlist = array();

        // hook user_index_end.php

	// 登录安全提示：在用户个人页显示上次登录信息
	if (!empty($uid) && !empty($_uid) && $uid == $_uid) {
	    include_once APP_PATH . 'lib/security/SecurityService.php';
	    $security_notice = SecurityService::get_login_security_notice($uid);
	}

	include _include(APP_PATH.'view/htm/user.htm');

// === Tab 按需加载 API ===

// 回帖 tab
} elseif($action == 'tab_posts') {

	$_uid = param(2, $uid);
	$page = param(3, 1);
	$pagesize = 10;
	$is_user_post_list = TRUE; // 标记为用户中心回帖列表，用于截断内容

	// 版块权限过滤必须在 SQL 层完成，避免 post_list_access_filter 分页后删除无权限版塔回帖导致每页条数不一致
	if(!isset($forumlist)) $forumlist = forum_list_cache();
	$accessible_fids = array_keys(forum_list_access_filter($forumlist, $gid));
	// 非管理员看他人回帖只看 audit_status=1；管理员/自己看所有
	$audit_only = ($gid == 0 || ($gid > 2 && $uid != $_uid));
	list($_total, $postlist) = post_find_by_uid_with_forum_access($_uid, $accessible_fids, $audit_only, $page, $pagesize);
	post_list_access_filter($postlist, $gid);

	// 为回帖添加帖子标题信息
	if($postlist) {
		// 批量查询帖子标题，消除 N+1 查询
		$_post_tids = array_unique(array_column($postlist, 'tid'));
		$_post_threads = empty($_post_tids) ? array() : db_find('thread', array('tid'=>$_post_tids), array(), 1, count($_post_tids), 'tid');
		foreach($postlist as &$_p) {
			if(isset($_post_threads[$_p['tid']])) {
				$_p['thread_subject'] = $_post_threads[$_p['tid']]['subject'];
			}
		}
		unset($_p);
	}

	header('Content-Type: text/html; charset=utf-8');
	if(!empty($postlist)) {
		include _include(APP_PATH.'view/htm/post_list.inc.htm');
	} else {
		echo '<div class="text-center text-body-secondary py-5">' . lang('no_reply') . '</div>';
	}
	exit;

// 关注 tab
} elseif($action == 'tab_following') {

	$_uid = param(2, $uid);
	$page = 1;
	$pagesize = 10;

	$followlist = user_follow_find_following($_uid, $page, $pagesize);
	$following_userlist = array();
	if($followlist) {
		$follow_uids = array();
		foreach($followlist as $f) { $follow_uids[] = $f['follow_uid']; }
		// 批量查询关注状态，消除 N+1
		$follow_status = !empty($uid) ? user_follow_read_batch($uid, $follow_uids) : array();
		foreach($followlist as $f) {
			$u = user_read_cache($f['follow_uid']);
			if(!empty($u)) {
				$u['is_followed'] = !empty($follow_status[$u['uid']]);
				$following_userlist[$u['uid']] = $u;
			}
		}
	}

	header('Content-Type: text/html; charset=utf-8');
	if(!empty($following_userlist)) { foreach($following_userlist as $u) { ?>
	<div class="d-flex align-items-center gap-3 p-2 rounded">
		<?php echo avatar_component_from_data($u['avatar_url'], 'md', isset($u['group_icon_class']) ? $u['group_icon_class'] : '', isset($u['group_color']) ? $u['group_color'] : '', isset($u['gid']) ? $u['gid'] : 0);?>
		<div class="flex-fill">
			<a href="<?php echo user_url($u['uid']);?>" class="fw-medium text-decoration-none"><?php echo esc_html($u['display_name'] ?? $u['username']);?></a>
		</div>
		<?php if(!empty($uid) && $uid != $u['uid']) {
			$_u_is_followed = !empty($u['is_followed']);
		?>
		<?php if($_u_is_followed) { ?>
		<button class="btn btn-sm btn-outline-secondary user-follow-btn"
			hx-post="<?php echo user_follow_url($u['uid']);?>"
			hx-target="this"
			hx-swap="outerHTML"
			hx-optimistic>
			<i class="ti ti-user-minus"></i>
			<span><?php echo lang('unfollow');?></span>
		</button>
		<?php } else { ?>
		<button class="btn btn-sm btn-primary user-follow-btn"
			hx-post="<?php echo user_follow_url($u['uid']);?>"
			hx-target="this"
			hx-swap="outerHTML"
			hx-optimistic>
			<i class="ti ti-user-plus"></i>
			<span><?php echo lang('follow');?></span>
		</button>
		<?php } ?>
		<?php } ?>
	</div>
	<?php }} else { ?>
	<div class="text-center text-body-secondary py-5"><?php echo lang('no_following');?></div>
	<?php }
	exit;

// 粉丝 tab
} elseif($action == 'tab_followers') {

	$_uid = param(2, $uid);
	$page = 1;
	$pagesize = 10;

	$followerlist = user_follow_find_followers($_uid, $page, $pagesize);
	$followers_userlist = array();
	if($followerlist) {
		$follower_uids = array();
		foreach($followerlist as $f) { $follower_uids[] = $f['uid']; }
		// 批量查询关注状态，消除 N+1
		$follow_status = !empty($uid) ? user_follow_read_batch($uid, $follower_uids) : array();
		foreach($followerlist as $f) {
			$u = user_read_cache($f['uid']);
			if(!empty($u)) {
				$u['is_followed'] = !empty($follow_status[$u['uid']]);
				$followers_userlist[$u['uid']] = $u;
			}
		}
	}

	header('Content-Type: text/html; charset=utf-8');
	if(!empty($followers_userlist)) { foreach($followers_userlist as $u) { ?>
	<div class="d-flex align-items-center gap-3 p-2 rounded">
		<?php echo avatar_component_from_data($u['avatar_url'], 'md', isset($u['group_icon_class']) ? $u['group_icon_class'] : '', isset($u['group_color']) ? $u['group_color'] : '', isset($u['gid']) ? $u['gid'] : 0);?>
		<div class="flex-fill">
			<a href="<?php echo user_url($u['uid']);?>" class="fw-medium text-decoration-none"><?php echo esc_html($u['display_name'] ?? $u['username']);?></a>
		</div>
		<?php if(!empty($uid) && $uid != $u['uid']) {
			$_u_is_followed = !empty($u['is_followed']);
		?>
		<?php if($_u_is_followed) { ?>
		<button class="btn btn-sm btn-outline-secondary user-follow-btn"
			hx-post="<?php echo user_follow_url($u['uid']);?>"
			hx-target="this"
			hx-swap="outerHTML"
			hx-optimistic>
			<i class="ti ti-user-minus"></i>
			<span><?php echo lang('unfollow');?></span>
		</button>
		<?php } else { ?>
		<button class="btn btn-sm btn-primary user-follow-btn"
			hx-post="<?php echo user_follow_url($u['uid']);?>"
			hx-target="this"
			hx-swap="outerHTML"
			hx-optimistic>
			<i class="ti ti-user-plus"></i>
			<span><?php echo lang('follow');?></span>
		</button>
		<?php } ?>
		<?php } ?>
	</div>
	<?php }} else { ?>
	<div class="text-center text-body-secondary py-5"><?php echo lang('no_followers');?></div>
	<?php }
	exit;

// 收藏 tab
} elseif($action == 'tab_favorites') {

	$_uid = param(2, $uid);
	$pagesize = 10;

	$fav_threadlist = array();
	if(!empty($uid) && $uid == $_uid) {
		$favlist = thread_favorite_find_by_uid($_uid, 1, $pagesize);
		if($favlist) {
			// 批量查询帖子，消除 N+1 查询
			$fav_tids = array_column($favlist, 'tid');
			$fav_threadlist = thread_find_by_tids($fav_tids);
		}
		thread_list_access_filter($fav_threadlist, $gid);
	}

	header('Content-Type: text/html; charset=utf-8');
	if(!empty($fav_threadlist)) {
		$threadlist = $fav_threadlist;
		include _include(APP_PATH.'view/htm/thread_list.inc.htm');
	} else {
		echo '<div class="text-center text-body-secondary py-5">' . lang('no_favorite') . '</div>';
	}
	exit;

// 点赞 tab
} elseif($action == 'tab_likes') {

	$_uid = param(2, $uid);
	$pagesize = 10;

	$like_threadlist = array();
	if(!empty($uid) && $uid == $_uid) {
		$likelist = post_like_find_by_uid($_uid, 1, $pagesize);
		if($likelist) {
			// 批量查询帖子，消除 N+1 查询（thread_find_by_tids 用 tid 作 key 天然去重）
			$like_tids = array_column($likelist, 'tid');
			$like_tids = array_unique($like_tids);
			$like_threadlist = thread_find_by_tids($like_tids);
		}
		thread_list_access_filter($like_threadlist, $gid);
	}

	header('Content-Type: text/html; charset=utf-8');
	if(!empty($like_threadlist)) {
		$threadlist = $like_threadlist;
		include _include(APP_PATH.'view/htm/thread_list.inc.htm');
	} else {
		echo '<div class="text-center text-body-secondary py-5">' . lang('no_like') . '</div>';
	}
	exit;

} elseif($action == 'thread') {

        // hook user_thread_start.php

        $_uid = param(2, 0);
        empty($_uid) AND $_uid = $uid;
        $_user = user_read($_uid);

        empty($_user) AND error_page(404, lang('user_not_exists'));
        $header['title'] = $_user['display_name'] ?? $_user['username'];
        $header['mobile_title'] = $_user['display_name'] ?? $_user['username'];

        $page = param(3, 1);
        $pagesize = 10;
        // 非管理员查看他人帖子时，只显示审核通过且版块可见的帖子
        // 版块权限过滤必须在 SQL 层完成，否则 thread_list_access_filter 在分页后删除无权限版块帖子，会导致每页条数不一致
        $thread_cond = array('uid' => $_uid);
        $threadlist = NULL; // 标记是否已查询，避免无可见版块时仍执行 thread_find
        if($gid == 0 || ($gid > 2 && $uid != $_uid)) {
            if(!isset($forumlist)) $forumlist = forum_list_cache();
            $accessible_fids = array_keys(forum_list_access_filter($forumlist, $gid));
            if(empty($accessible_fids)) {
                // 无可见版块：直接置空，跳过 thread_find 避免 fid IN() 无效 SQL
                $totalnum = 0;
                $threadlist = array();
            } else {
                $thread_cond['audit_status'] = 1;
                $thread_cond['fid'] = $accessible_fids; // db_find 展开为 fid=v1 OR fid=v2 ... 等价 IN
                $totalnum = thread_count($thread_cond);
            }
        } else {
            $totalnum = $_user['threads'];
        }
        $pagination = pagination(route_url('user_thread_page', array('uid'=>$_uid)), $totalnum, $page, $pagesize);
        // 直接用 thread_find 查询（含 audit_status + 版块权限过滤），避免 mythread 表无法过滤待审帖子
        if($threadlist === NULL) {
            $threadlist = thread_find($thread_cond, array('tid' => -1), $page, $pagesize);
        }
	thread_list_access_filter($threadlist, $gid);
	// 加载版块列表，供管理工具栏（移动帖子）使用
	$forumlist = forum_list_cache();

        // hook user_thread_end.php

	if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
		include _include(APP_PATH.'view/htm/thread_list.inc.htm');
		return;
	}

	include _include(APP_PATH.'view/htm/user_thread.htm');

} elseif($action == 'login') {

	// hook user_login_get_post.php

	// 已登录用户不允许访问登录页
	if(!empty($uid)) {
		message(0, lang('user_login_successfully'), array('redirect_url' => user_url($uid)));
	}

	if($method == 'GET') {

		// hook user_login_get_start.php

		$referer = user_http_referer();

		$header['title'] = lang('user_login');

		// hook user_login_get_end.php

		include _include(APP_PATH.'view/htm/user_login.htm');

	} else if($method == 'POST') {

		CsrfService::check();

		// hook user_login_post_start.php

		// IP 黑名单检查（在密码验证前拒绝，避免泄露用户是否存在）
		if(!isset($ip)) { $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''; }
		include_once APP_PATH.'model/banned_ip.func.php';
		// hook banned_ip_check.php
		if(banned_ip_check($ip)) {
			message(-1, lang('user_ban_ip_banned'));
		}

		// 登录验证码检查
		include_once APP_PATH . 'lib/security/CaptchaService.php';
		if (CaptchaService::is_enabled('login', $gid)) {
		    $captcha_input = param('captcha');
		    if (empty($captcha_input)) {
		        message(-1001, lang('please_input_captcha'));
		    }
		    if (!CaptchaService::verify('login', $captcha_input, $gid)) {
		        message(-1001, lang('captcha_error'));
		    }
		}

		$email = param('email');			// 邮箱或者手机号 / email or mobile
		$password = param('password', '', FALSE);
		empty($email) AND message('email', lang('email_is_empty'));

		// IP 维度限流检查（防止用不存在的用户名枚举绕过 uid 维度限流）
		LoginSecurityService::checkIpBan($longip);

		if(is_email($email, $err)) {
			$_user = user_read_by_email($email);
		} else {
			$_user = user_read_by_username($email);
		}

		// 用户不存在时统一返回"用户名或密码错误"，避免用户名枚举
		// 同时记录 IP 维度失败尝试（uid=0），纳入 IP 限流统计
		if(empty($_user)) {
			LoginSecurityService::recordIpAttempt($longip, FALSE, $_SERVER['HTTP_USER_AGENT']);
			message('email', lang('login_user_or_password_error'));
		}

		LoginSecurityService::checkBan($_user['uid']);

		$check = user_login_verify($password, $_user);
		// hook user_login_post_password_check_after.php
		!$check AND LoginSecurityService::recordAttempt($_user['uid'], FALSE, $longip, $_SERVER['HTTP_USER_AGENT']);
		// recordAttempt 已写 user_login_log（含 ip 字段），IP 维度限流可直接统计，无需再调 recordIpAttempt
		!$check AND message('password', lang('login_user_or_password_error'));

		// 封禁检查：禁止访问/锁定用户不能登录（管理员组豁免）
		if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
		if(!in_array(intval($_user['gid']), UserBanService::ADMIN_GIDS, true)) {
			// hook user_ban_check.php
			$ban_check = UserBanService::checkBanByScene($_user['uid'], 'login');
			if(!$ban_check['allowed']) {
				message(-1, $ban_check['message']);
			}
		}

		// 更新登录时间和次数
		// update login times
		user_update($_user['uid'], array('login_ip'=>$longip, 'login_date' =>$time , 'logins+'=>1));

		// 全局变量 $uid 会在结束后，在函数 register_shutdown_function() 中存入 session (文件: model/session.func.php)
		// global variable $uid will save to session in register_shutdown_function() (file: model/session.func.php)
		$uid = $_user['uid'];

		// 防止 Session 固定攻击
		session_regenerate_id(true);

		$_SESSION['uid'] = $uid;

		LoginSecurityService::recordAttempt($_user['uid'], TRUE, $longip, $_SERVER['HTTP_USER_AGENT']);

		user_token_set($_user['uid']);

		// hook user_login_post_end.php

		// 设置 token，下次自动登陆。

		$referer = user_http_referer();
		message(0, lang('user_login_successfully'), array('redirect_url' => $referer ?: user_url($uid)));

	}

} elseif($action == 'create') {

	// hook user_create_get_post.php

	empty($conf['user_create_on']) AND message(-1, lang('user_create_not_on'));

	// 已登录用户不允许访问注册页
	if(!empty($uid)) {
		message(0, lang('user_login_successfully'), array('redirect_url' => user_url($uid)));
	}

	if($method == 'GET') {

		// hook user_create_get_start.php

		$referer = user_http_referer();
		$header['title'] = lang('create_user');

		// hook user_create_get_end.php

		include _include(APP_PATH.'view/htm/user_create.htm');

	} else if($method == 'POST') {

		CsrfService::check();

		// hook user_create_post_start.php

		// IP 黑名单检查
		if(!isset($ip)) { $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''; }
		include_once APP_PATH.'model/banned_ip.func.php';
		// hook banned_ip_check.php
		if(banned_ip_check($ip)) {
			message(-1, lang('user_ban_ip_banned'));
		}

		// 注册验证码检查
		include_once APP_PATH . 'lib/security/CaptchaService.php';
		if (CaptchaService::is_enabled('register', $gid)) {
		    $captcha_input = param('captcha');
		    if (empty($captcha_input)) {
		        message(-1001, lang('please_input_captcha'));
		    }
		    if (!CaptchaService::verify('register', $captcha_input, $gid)) {
		        message(-1001, lang('captcha_error'));
		    }
		}

		// 同一IP注册间隔检查
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$ip_register_interval = SecurityConfigService::get('security_ip_register_interval', 24);
		if ($ip_register_interval > 0) {
		    $ip_reg_key = 'security_ip_register_' . $longip;
		    $last_reg = kv_get($ip_reg_key);
		    if (!empty($last_reg) && ($time - intval($last_reg)) < $ip_register_interval * 3600) {
		        $remaining = ceil(($ip_register_interval * 3600 - ($time - intval($last_reg))) / 3600);
		        message(-1002, lang('register_interval_short', array('hours'=>$remaining)), array('wait'=>$remaining * 3600));
		    }
		}

		// 邮箱域名白名单检查
		$allowed_domains = SecurityConfigService::get('security_allowed_email_domains', '');
		if (!empty($allowed_domains)) {
		    $email = param('email', '', FALSE);
		    if (!empty($email)) {
		        $email_domain = strtolower(substr(strrchr($email, '@'), 1));
		        $allowed_list = array_map('trim', explode(',', strtolower($allowed_domains)));
		        $allowed_list = array_filter($allowed_list);
		        if (!empty($allowed_list) && !in_array($email_domain, $allowed_list)) {
		            message(-1, '该邮箱域名不允许注册，仅支持：' . implode('、', $allowed_list));
		        }
		    }
		}

		$email = param('email');
		$username = param('username');
		$password = param('password', '', FALSE);
		$code = param('code');
		empty($email) AND message('email', lang('please_input_email'));
		empty($username) AND message('username', lang('please_input_username'));
		empty($password) AND message('password', lang('please_input_password'));

		if($conf['user_create_email_on']) {
			$sess_email = _SESSION('user_create_email');
			$sess_code = _SESSION('user_create_code');
			empty($sess_code) AND message('code', lang('click_to_get_verify_code'));
			empty($sess_email) AND message('code', lang('click_to_get_verify_code'));
			$email != $sess_email AND message('code', lang('verify_code_incorrect'));
			$code != $sess_code AND message('code', lang('verify_code_incorrect'));
		}

		!is_email($email, $err) AND message('email', $err);
		$_user = user_read_by_email($email);
		$_user AND message('email', lang('email_is_in_use'));

		!is_username($username, $err) AND message('username', $err);
		$_user = user_read_by_username($username);
		$_user AND message('username', lang('username_is_in_use'));

		// 用户名保留词检查（拦截，不允许注册；使用 reserved 词库防止冒充管理员等）
		include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
		$reserved_check = SensitiveWordFilter::content_check($username, SensitiveWordFilter::TYPE_RESERVED);
		if (!$reserved_check['pass']) {
			$hit_words = implode('、', $reserved_check['matched_keywords']);
			message('username', lang('username_contains_reserved_word', array('words'=>$hit_words)));
		}

		!is_password($password, $err) AND message('password', $err);

		// 密码策略校验（最小长度 + 复杂度，读取后台安全配置）
		$policy_err = SecurityConfigService::checkPasswordPolicy($password);
		$policy_err AND message('password', $policy_err);

		$gid = 101;
		$_user = array (
			'username' => $username,
			'nickname' => $username,
			'email' => $email,
			'password' => '',
			'salt' => '',
			'gid' => $gid,
			'create_ip' => $longip,
			'create_date' => $time,
			'logins' => 1,
			'login_date' => $time,
			'login_ip' => $longip,
		);
		if(db_check_column_exists('user', 'password_hash')) {
			$_user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
		}
		$uid = user_create($_user);
		$uid === FALSE AND message('email', lang('user_create_failed'));
		$user = user_read($uid);

		// 记录 IP 注册时间，用于同IP注册间隔检查
		kv_set('security_ip_register_' . $longip, $time);

		// 更新 session

		unset($_SESSION['user_create_email']);
		unset($_SESSION['user_create_code']);
		$_SESSION['uid'] = $uid;
		user_token_set($uid);

		$extra = array('token'=>user_token_gen($uid));

		// hook user_create_post_end.php

		$extra['redirect_url'] = my_url();
		message(0, lang('user_create_sucessfully'), $extra);
	}

} elseif($action == 'logout') {

	// hook user_logout_start.php

	// 退出前清除用户缓存（cache 类型为 mysql/pdo_mysql 时由 DB 层处理，与 user_update 一致）
	// 必须在 $uid = 0 之前执行，否则会误清 user-0 缓存
	!empty($uid) AND !in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");

	$uid = 0;
	$_SESSION['uid'] = $uid;
	// 防止 Session 固定攻击：销毁旧 session ID，生成新 session
	session_regenerate_id(true);
	user_token_clear();

	// hook user_logout_end.php

	message(0, lang('logout_successfully'), array('redirect_url' => http_referer() ?: '/'));
	//message(0, jump('退出成功', './', 1));

// 重设密码第 1 步 | reset password first step
} elseif($action == 'resetpw') {

	// hook user_resetpw_get_post.php

	!$conf['user_resetpw_on'] AND message(-1, '未开启密码找回功能！');

	if($method == 'GET') {

		// hook user_resetpw_get_start.php

		$header['title'] = lang('resetpw');

		// hook user_resetpw_get_end.php

		include _include(APP_PATH.'view/htm/user_resetpw.htm');

	} else if($method == 'POST') {

		CsrfService::check();

		// hook user_resetpw_post_start.php

		// 找回密码验证码检查
		include_once APP_PATH . 'lib/security/CaptchaService.php';
		if (CaptchaService::is_enabled('resetpw', $gid)) {
		    $captcha_input = param('captcha');
		    if (empty($captcha_input)) {
		        message(-1001, lang('please_input_captcha'));
		    }
		    if (!CaptchaService::verify('resetpw', $captcha_input, $gid)) {
		        message(-1001, lang('captcha_error'));
		    }
		}

		// 密码策略检查
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$password = param('password', '', FALSE);
		$password_new = param('password_new', '', FALSE);
		$check_password = !empty($password_new) ? $password_new : $password;

		if (!empty($check_password)) {
		    $min_length = SecurityConfigService::get('security_password_min_length', 6);
		    if (mb_strlen($check_password, 'UTF-8') < $min_length) {
		        message(-1, '密码长度不能少于' . $min_length . '个字符');
		    }

		    $complexity = SecurityConfigService::get('security_password_complexity', 'none');
		    if ($complexity === 'number' && !preg_match('/[0-9]/', $check_password)) {
		        message(-1, '密码必须包含数字');
		    } elseif ($complexity === 'mixed') {
		        if (!preg_match('/[a-z]/', $check_password) || !preg_match('/[A-Z]/', $check_password)) {
		            message(-1, '密码必须包含大小写字母');
		        }
		    } elseif ($complexity === 'special') {
		        if (!preg_match('/[a-z]/', $check_password) || !preg_match('/[A-Z]/', $check_password) || !preg_match('/[0-9]/', $check_password) || !preg_match('/[^a-zA-Z0-9]/', $check_password)) {
		            message(-1, '密码必须包含大小写字母、数字和特殊字符');
		        }
		    }
		}

		$email = param('email');
		empty($email) AND message('email', lang('please_input_email'));
		!is_email($email, $err) AND message('email', $err);

		$_user = user_read_by_email($email);
		!$_user AND message('email', lang('email_is_not_in_use'));

		// 封禁检查：锁定用户不能找密（管理员组豁免）
		if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
		if(!in_array(intval($_user['gid']), UserBanService::ADMIN_GIDS, true)) {
			// hook user_ban_check.php
			$ban_check = UserBanService::checkBanByScene($_user['uid'], 'password');
			if(!$ban_check['allowed']) {
				message(-1, $ban_check['message']);
			}
		}

		$code = param('code');
		empty($code) AND message('code', lang('please_input_verify_code'));

		$sess_email = _SESSION('user_resetpw_email');
		$sess_code = _SESSION('user_resetpw_code');
		empty($sess_code) AND message('code', lang('click_to_get_verify_code'));
		empty($sess_email) AND message('code', lang('click_to_get_verify_code'));
		$email != $sess_email AND message('code', lang('verify_code_incorrect'));
		$code != $sess_code AND message('code', lang('verify_code_incorrect'));

		$_SESSION['resetpw_verify_email'] = $sess_email;

		// hook user_resetpw_post_end.php

		message(0, lang('check_ok_to_next_step'), array('redirect_url' => user_resetpw_complete_url()));
	}

// 重设密码第 3 步 | reset password step 3
} elseif($action == 'resetpw_complete') {

	// hook user_resetpw_get_post.php

	// 校验数据
	$email = _SESSION('user_resetpw_email');
	$resetpw_verify_email = _SESSION('resetpw_verify_email');
	(empty($email) || empty($resetpw_verify_email)) AND message(-1, lang('data_empty_to_last_step'));

	($resetpw_verify_email != $email) AND message(-1, lang('data_empty_to_last_step'));

	$_user = user_read_by_email($email);
	empty($_user) AND message(-1, lang('email_not_exists'));
	$_uid = $_user['uid'];

	if($method == 'GET') {

		// hook user_resetpw_get_start.php

		$header['title'] = lang('resetpw');

		// hook user_resetpw_get_end.php

		include _include(APP_PATH.'view/htm/user_resetpw_complete.htm');

	} else if($method == 'POST') {

		CsrfService::check();

		// hook user_resetpw_post_start.php

		$password = param('password', '', FALSE);
		empty($password) AND message('password', lang('please_input_password'));

		// 密码策略校验（读取后台 security-account 配置，与 resetpw 第 1 步、my/password 保持一致）
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$policy_err = SecurityConfigService::checkPasswordPolicy($password);
		$policy_err AND message('password', $policy_err);

		$update = array(
			'password' => '',
			'salt' => '',
		);
		if(db_check_column_exists('user', 'password_hash')) {
			$update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
		}

		!is_password($password, $err) AND message('password', $err);
		// 找回密码已通过邮箱验证码验证身份，直接调用 user__update 绕过保护字段过滤
		$r = user__update($_uid, $update);
		$r === FALSE AND message(-1, lang('update_error'));

		unset($_SESSION['user_resetpw_email']);
		unset($_SESSION['user_resetpw_code']);
		unset($_SESSION['resetpw_verify_ok']);

		// hook user_resetpw_post_end.php

		message(0, lang('modify_successfully'), array('redirect_url' => user_login_url()));

	}

// 发送验证码
} elseif($action == 'send_code') {

	$method != 'POST' AND message(-1, lang('method_error'));

	CsrfService::check();

	// hook user_sendcode_start.php

	$action2 = param(2);

	// 创建用户
	if($action2 == 'user_create') {

		$email = param('email');

		empty($email) AND message('email', lang('please_input_email'));
		!is_email($email, $err) AND message('email', $err);
		empty($conf['user_create_email_on']) AND message(-1, lang('email_verify_not_on'));
		$_user = user_read_by_email($email);
		!empty($_user) AND message('email', lang('email_is_in_use'));

		// 邮箱域名白名单检查（发送验证码时也检查，避免不在白名单的邮箱浪费发送配额）
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$allowed_domains = SecurityConfigService::get('security_allowed_email_domains', '');
		if (!empty($allowed_domains)) {
			$email_domain = strtolower(substr(strrchr($email, '@'), 1));
			$allowed_list = array_map('trim', explode(',', strtolower($allowed_domains)));
			$allowed_list = array_filter($allowed_list);
			if (!empty($allowed_list) && !in_array($email_domain, $allowed_list)) {
				message(-1, '该邮箱域名不允许注册，仅支持：' . implode('、', $allowed_list));
			}
		}

		$code = random_int(100000, 999999);
		$_SESSION['user_create_email'] = $email;
		$_SESSION['user_create_code'] = $code;


	// 重置密码，往老地址发送
	} elseif($action2 == 'user_resetpw') {

		$email = param('email');

		empty($email) AND message('email', lang('please_input_email'));
		!is_email($email, $err) AND message('email', $err);
		$_user = user_read_by_email($email);
		empty($_user) AND message('email', lang('email_is_not_in_use'));

		empty($conf['user_resetpw_on']) AND message(-1, lang('resetpw_not_on'));

		$code = random_int(100000, 999999);
		$_SESSION['user_resetpw_email'] = $email;
		$_SESSION['user_resetpw_code'] = $code;

	} else {
		message(-1, 'action2 error');
	}


	// 使用邮件模板
	$template_key = ($action2 == 'user_create') ? 'user_create_code' : 'user_resetpw_code';
	$template = xn_email_template($template_key, array('code'=>$code, 'sitename'=>$conf['sitename']));
	$subject = $template['subject'];
	$message = $template['body'];

	$smtp = xn_smtp_get();
	if(empty($smtp)) {
		message(-1, '邮件发送未配置，请联系管理员');
	}

	// hook user_send_code_before.php

	// 频率限制检查
	$rate_check = xn_email_rate_check($email, $longip);
	if($rate_check !== TRUE) {
		message(-1, $rate_check);
	}

	$r = xn_send_mail($smtp, $conf['sitename'], $email, $subject, $message, array('is_html'=>TRUE));
	// hook user_send_code_after.php

	if($r === TRUE) {
		xn_email_rate_record($email, $longip);
		$interval = class_exists('SecurityConfigService') ? intval(SecurityConfigService::get('security_email_code_interval', 60)) : 60;
		message(0, lang('send_successfully'), array('wait' => $interval));
	} else {
		// xn_send_mail 失败时返回错误字符串，$r 与全局 $errstr 内容一致
		$err_detail = is_string($r) ? $r : (isset($errstr) ? $errstr : '邮件发送失败');
		xn_log($err_detail, 'send_mail_error');
		message(-1, $err_detail);
	}

// 简单的同步登陆实现：| sync login implement simply
/*
	将用户信息通过 token 传递给其他系统 | send user information to other system by token
	两边系统将 auth_key 设置为一致，用 xn_encrypt() xn_decrypt() 加密解密。all subsystem set auth_key to correct by xn_encrypt() xn_decrypt()
*/
} elseif($action == 'synlogin') {

	// 检查过来的 token | check token
	$token = param('token');
	$return_url = param('return_url');

	$s = xn_decrypt($token);
	!$s AND message(-1, lang('unauthorized_access'));
	list($_time, $_useragent) = explode("\t", $s);
	$useragent != $_useragent AND message(-1, lang('authorized_get_failed'));

	empty($_SESSION['return_url']) AND $_SESSION['return_url'] = $return_url;
	if(!$uid) {
		http_location(user_login_url());
	} else {
		$return_url = _SESSION('return_url');

		empty($return_url) AND message(-1, lang('request_synlogin_again'));
		unset($_SESSION['return_url']);

		$arr = array(
			'uid'=>$user['uid'],
			'gid'=>$user['gid'],
			'username'=>$user['username'],
			'avatar_url'=>$user['avatar_url'],
			'email'=>$user['email'],
			'mobile'=>$user['mobile'],
		);
		$s = xn_json_encode($arr);
		$s = xn_encrypt($s);

		// 将 token 附加到 URL，跳转回去 | add token into URL, jump back
		$url = xn_urldecode($return_url).'?token='.$s;
		//$url = xn_url_add_arg($return_url, 'token', $s);
		// synlogin 跨站登录回跳，return_url 为外部应用地址，显式放行
		http_location($url, TRUE);
	}

} elseif($action == 'follow') {
	// 安全修复：follow 动作为写操作，强制 POST + CSRF 校验，防止 CSRF 攻击
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		message(-1, lang('method_not_allowed'));
	}
	CsrfService::check();
	$follow_uid = param(2, 0);
	if(!$uid) {
		message(-1, lang('please_login'));
	}
	$follow_user = user_read($follow_uid);
	if(empty($follow_user)) {
		message(-1, lang('user_not_exists'));
	}
	if($uid == $follow_uid) {
		message(-1, lang('cannot_follow_self'));
	}

	$exists = user_follow_read($uid, $follow_uid);
	if(!empty($exists)) {
		user_follow_delete($uid, $follow_uid);
	} else {
		user_follow_create($uid, $follow_uid);
	}

	$follow_action = empty($exists) ? 'follow' : 'unfollow';
	$data = array('action' => $follow_action, 'uid' => $follow_uid);
	if(is_htmx_request()) {
		// htmx 请求：返回更新后的按钮 HTML
		$is_followed = empty($exists) ? true : false;
		header('Content-Type: text/html; charset=utf-8');
		echo '<button class="btn btn-sm user-follow-btn ' . ($is_followed ? 'btn-outline-secondary' : 'btn-primary') . '"
			hx-post="' . user_follow_url($follow_uid) . '"
			hx-target="this"
			hx-swap="outerHTML"
			hx-optimistic>
			<i class="ti ' . ($is_followed ? 'ti-user-minus' : 'ti-user-plus') . '"></i>
			<span>' . ($is_followed ? lang('unfollow') : lang('follow')) . '</span>
		</button>';
		exit;
	}
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'message' => '操作成功', 'data' => $data), JSON_UNESCAPED_UNICODE);
	exit;

} elseif($action == 'following') {
	$_uid = param(2, $uid);
	$_user = user_read($_uid);
	empty($_user) AND error_page(404, lang('user_not_exists'));
	$_user['is_followed'] = !empty($uid) && $uid != $_uid ? user_follow_read($uid, $_uid) : false;
	$page = param(3, 1);
	$pagesize = 10;
	$followlist = user_follow_find_following($_uid, $page, $pagesize);
	$userlist = array();
	if($followlist) {
		$_follow_uids = array();
		foreach($followlist as $f) { $_follow_uids[] = $f['follow_uid']; }
		// 批量查询关注状态，消除 N+1
		$_follow_status = !empty($uid) ? user_follow_read_batch($uid, $_follow_uids) : array();
		foreach($followlist as $f) {
			$u = user_read_cache($f['follow_uid']);
			if(!empty($u)) {
				$u['is_followed'] = !empty($_follow_status[$u['uid']]);
				$userlist[$u['uid']] = $u;
			}
		}
	}
	$totalnum = $_user['follows'];
	$pagination = pagination(route_url('user_following_page', array('uid'=>$_uid)), $totalnum, $page, $pagesize);
	$header['title'] = lang('following');

	if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
		if(!empty($userlist)) { foreach($userlist as $u) {
			$u_is_followed = !empty($u['is_followed']);
		?>
		<div class="d-flex align-items-center gap-3 p-2 hover-bg-body-secondary rounded">
			<img class="avatar-md" src="<?php echo $u['avatar_url'];?>" alt="" onerror="this.onerror=null;this.src='<?php echo default_avatar_url();?>'">
			<div class="flex-fill">
				<a href="<?php echo user_url($u['uid']);?>" class="fw-medium text-decoration-none"><?php echo esc_html($u['display_name'] ?? $u['username']);?></a>
				<div class="small text-body-secondary"><?php echo $u['groupname'];?></div>
			</div>
			<?php if(!empty($uid) && $uid != $u['uid']) { ?>
			<button class="btn btn-sm user-follow-btn <?php echo $u_is_followed ? 'btn-outline-secondary' : 'btn-primary';?>"
				hx-post="<?php echo user_follow_url($u['uid']);?>"
				hx-target="this"
				hx-swap="outerHTML"
				hx-optimistic>
				<i class="ti <?php echo $u_is_followed ? 'ti-user-minus' : 'ti-user-plus';?>"></i>
				<span><?php echo $u_is_followed ? lang('unfollow') : lang('follow');?></span>
			</button>
			<?php } ?>
		</div>
		<?php }} else { ?>
		<div class="text-center text-body-secondary py-5"><?php echo lang('no_following');?></div>
		<?php }
		if($pagination) { ?>
		<nav><ul class="pagination my-4 justify-content-center flex-wrap"><?php echo $pagination; ?></ul></nav>
		<?php }
		return;
	}

	include _include(APP_PATH.'view/htm/user_following.htm');

} elseif($action == 'followers') {
	$_uid = param(2, $uid);
	$_user = user_read($_uid);
	empty($_user) AND error_page(404, lang('user_not_exists'));
	$_user['is_followed'] = !empty($uid) && $uid != $_uid ? user_follow_read($uid, $_uid) : false;
	$page = param(3, 1);
	$pagesize = 10;
	$followlist = user_follow_find_followers($_uid, $page, $pagesize);
	$userlist = array();
	if($followlist) {
		$_follower_uids = array();
		foreach($followlist as $f) { $_follower_uids[] = $f['uid']; }
		// 批量查询关注状态，消除 N+1
		$_follow_status = !empty($uid) ? user_follow_read_batch($uid, $_follower_uids) : array();
		foreach($followlist as $f) {
			$u = user_read_cache($f['uid']);
			if(!empty($u)) {
				$u['is_followed'] = !empty($_follow_status[$u['uid']]);
				$userlist[$u['uid']] = $u;
			}
		}
	}
	$totalnum = $_user['followeds'];
	$pagination = pagination(route_url('user_followers_page', array('uid'=>$_uid)), $totalnum, $page, $pagesize);
	$header['title'] = lang('followers');

	if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
		if(!empty($userlist)) { foreach($userlist as $u) {
			$u_is_followed = !empty($u['is_followed']);
		?>
		<div class="d-flex align-items-center gap-3 p-2 hover-bg-body-secondary rounded">
			<img class="avatar-md" src="<?php echo $u['avatar_url'];?>" alt="" onerror="this.onerror=null;this.src='<?php echo default_avatar_url();?>'">
			<div class="flex-fill">
				<a href="<?php echo user_url($u['uid']);?>" class="fw-medium text-decoration-none"><?php echo esc_html($u['display_name'] ?? $u['username']);?></a>
				<div class="small text-body-secondary"><?php echo $u['groupname'];?></div>
			</div>
			<?php if(!empty($uid) && $uid != $u['uid']) { ?>
			<button class="btn btn-sm user-follow-btn <?php echo $u_is_followed ? 'btn-outline-secondary' : 'btn-primary';?>"
				hx-post="<?php echo user_follow_url($u['uid']);?>"
				hx-target="this"
				hx-swap="outerHTML"
				hx-optimistic>
				<i class="ti <?php echo $u_is_followed ? 'ti-user-minus' : 'ti-user-plus';?>"></i>
				<span><?php echo $u_is_followed ? lang('unfollow') : lang('follow');?></span>
			</button>
			<?php } ?>
		</div>
		<?php }} else { ?>
		<div class="text-center text-body-secondary py-5"><?php echo lang('no_followers');?></div>
		<?php }
		if($pagination) { ?>
		<nav><ul class="pagination my-4 justify-content-center flex-wrap"><?php echo $pagination; ?></ul></nav>
		<?php }
		return;
	}

	include _include(APP_PATH.'view/htm/user_followers.htm');

} elseif($action == 'post') {

	// hook user_post_start.php

	$_uid = param(2, 0);
	empty($_uid) AND $_uid = $uid;
	$_user = user_read($_uid);

	empty($_user) AND error_page(404, lang('user_not_exists'));

	// 设置当前查看用户的关注状态
	$_user['is_followed'] = !empty($uid) && $uid != $_uid ? user_follow_read($uid, $_uid) : false;
	$header['title'] = $_user['display_name'] ?? $_user['username'];
	$header['mobile_title'] = $_user['display_name'] ?? $_user['username'];

	$page = param(3, 1);
	$pagesize = 10;
	// 版块权限过滤必须在 SQL 层完成，避免 post_list_access_filter 分页后删除无权限版塔回帖导致每页条数不一致
	if(!isset($forumlist)) $forumlist = forum_list_cache();
	$accessible_fids = array_keys(forum_list_access_filter($forumlist, $gid));
	// 非管理员看他人回帖只看 audit_status=1；管理员/自己看所有
	$audit_only = ($gid == 0 || ($gid > 2 && $uid != $_uid));
	list($totalnum, $postlist) = post_find_by_uid_with_forum_access($_uid, $accessible_fids, $audit_only, $page, $pagesize);
	$pagination = pagination(route_url('user_post_page', array('uid'=>$_uid)), $totalnum, $page, $pagesize);

	$is_user_post_list = TRUE; // 标记为用户中心回帖列表，用于截断内容
	post_list_access_filter($postlist, $gid);

	// 为回帖添加帖子标题信息
	if($postlist) {
		// 批量查询帖子标题，消除 N+1 查询
		$_post_tids = array_unique(array_column($postlist, 'tid'));
		$_post_threads = empty($_post_tids) ? array() : db_find('thread', array('tid'=>$_post_tids), array(), 1, count($_post_tids), 'tid');
		foreach($postlist as &$_p) {
			if(isset($_post_threads[$_p['tid']])) {
				$_p['thread_subject'] = $_post_threads[$_p['tid']]['subject'];
			}
		}
		unset($_p);
	}

	// hook user_post_end.php

	if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
		include _include(APP_PATH.'view/htm/post_list.inc.htm');
		return;
	}

	include _include(APP_PATH.'view/htm/user_post.htm');

} elseif($action == 'favorite') {

	// hook user_favorite_start.php

	$_uid = param(2, 0);
	empty($_uid) AND $_uid = $uid;
	$_user = user_read($_uid);

	empty($_user) AND error_page(404, lang('user_not_exists'));
	$_user['is_followed'] = !empty($uid) && $uid != $_uid ? user_follow_read($uid, $_uid) : false;
	$header['title'] = $_user['display_name'] ?? $_user['username'];
	$header['mobile_title'] = $_user['display_name'] ?? $_user['username'];

	$page = param(3, 1);
	$pagesize = 10;

	// hook user_favorite_start.php
	$favlist = thread_favorite_find_by_uid($_uid, $page, $pagesize);
	$totalnum = $_user['favorites'];
	$pagination = pagination(route_url('user_favorite_page', array('uid'=>$_uid)), $totalnum, $page, $pagesize);

	$threadlist = array();
	if($favlist) {
		// 批量查询帖子，消除 N+1 查询
		$fav_tids = array_column($favlist, 'tid');
		$threadlist = thread_find_by_tids($fav_tids);
	}

	thread_list_access_filter($threadlist, $gid);

	// hook user_favorite_end.php

	if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
		include _include(APP_PATH.'view/htm/thread_list.inc.htm');
		return;
	}

	include _include(APP_PATH.'view/htm/user_favorite.htm');

} elseif($action == 'like') {

	// hook user_like_start.php

	$_uid = param(2, 0);
	empty($_uid) AND $_uid = $uid;
	$_user = user_read($_uid);

	empty($_user) AND error_page(404, lang('user_not_exists'));
	$_user['is_followed'] = !empty($uid) && $uid != $_uid ? user_follow_read($uid, $_uid) : false;
	$header['title'] = $_user['display_name'] ?? $_user['username'];
	$header['mobile_title'] = $_user['display_name'] ?? $_user['username'];

	$page = param(3, 1);
	$pagesize = 10;

	// 按帖子去重查询点赞列表，避免同一帖子多条点赞导致分页不准
	global $db;
	$tablepre = $db->tablepre;
	$offset = ($page - 1) * $pagesize;

	// 按帖子去重查询点赞列表，JOIN thread 表过滤已删除帖子
	$sql = "SELECT pl.tid, MAX(pl.create_date) AS last_like_time FROM {$tablepre}post_like pl INNER JOIN {$tablepre}thread t ON pl.tid=t.tid WHERE pl.uid='$_uid' GROUP BY pl.tid ORDER BY last_like_time DESC LIMIT $offset, $pagesize";
	$tid_rows = db_sql_find($sql);
	$threadlist = array();
	if($tid_rows) {
		// 批量查询帖子，消除 N+1 查询
		$like_tids = array_column($tid_rows, 'tid');
		$threadlist = thread_find_by_tids($like_tids);
	}

	// 去重后的总数（仅统计帖子仍存在的）
	$totalnum = db_sql_find_one("SELECT COUNT(DISTINCT pl.tid) AS cnt FROM {$tablepre}post_like pl INNER JOIN {$tablepre}thread t ON pl.tid=t.tid WHERE pl.uid='$_uid'");
	$totalnum = !empty($totalnum['cnt']) ? intval($totalnum['cnt']) : 0;

	$pagination = pagination(route_url('user_like_page', array('uid'=>$_uid)), $totalnum, $page, $pagesize);

	thread_list_access_filter($threadlist, $gid);

	// hook user_like_end.php

	if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
		include _include(APP_PATH.'view/htm/thread_list.inc.htm');
		return;
	}

	include _include(APP_PATH.'view/htm/user_like.htm');

} elseif($action == 'search') {

    // 用户搜索（用于@提及）
    $keyword = param('keyword');
    empty($keyword) AND message(-1, lang('data_is_empty'));
    mb_strlen($keyword) < 1 AND message(-1, '关键词太短');

    $userlist = db_find('user', array('username'=>array('LIKE'=>$keyword.'%')), array('uid'=>-1), 1, 10, 'uid');
    $users = array();
    if($userlist) {
        foreach($userlist as $u) {
            $users[] = array(
                'uid' => $u['uid'],
                'username' => $u['username'],
                'display_name' => $u['display_name'] ?? $u['username'],
                'avatar_url' => !empty($u['avatar_url']) ? $u['avatar_url'] : default_avatar_url(),
            );
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo xn_json_encode(array('code' => 0, 'data' => $users));
    exit;

} elseif($action == 'ai_setting') {

	// 已迁移到 my-ai 路由，重定向
	http_location(my_ai_url());

} else {

}

// hook user_end.php


?>
