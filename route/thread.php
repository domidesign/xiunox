<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook thread_start.php

if($action == 'like' || $action == 'unlike') {
	if($method != 'POST') {
		if(is_htmx_request()) {
			header('HTTP/1.1 405 Method Not Allowed');
			exit;
		}
		message(-1, 'Method Error.');
	}
	CsrfService::check();
	$tid = param(2, 0);
	$pid = param(3, 0);
	$is_htmx = is_htmx_request();
	$is_like_action = ($action == 'like'); // true=点赞, false=取消点赞

	if(!$uid) {
		if($is_htmx) {
			// htmx 请求：返回原按钮 HTML + toast 提示
			htmx_trigger('showToast', array('message' => lang('please_login'), 'type' => 'error'));
			$thread = thread__read($tid);
			$post = post__read($pid);
			$is_liked = !$is_like_action; // 未登录时返回原始状态
			$likes_count = $post ? intval($post['likes']) : 0;
			$ctx = param('_ctx', ($thread && $pid == $thread['firstpid']) ? 'thread' : 'post');
			header('Content-Type: text/html; charset=utf-8');
			echo _render_like_btn($tid, $pid, $is_liked, $likes_count, $ctx);
			exit;
		}
		message(-1, lang('please_login'));
	}

	$thread = thread__read($tid);
	if(empty($thread)) {
		message(-1, lang('thread_not_exists'));
	}

	// 关闭/审核中帖子不允许普通用户点赞
	if((!empty($thread['closed']) || (isset($thread['audit_status']) && $thread['audit_status'] != 1)) && $gid > 5) {
		if($is_htmx) {
			htmx_trigger('showToast', array('message' => lang('thread_has_already_closed'), 'type' => 'error'));
			$post = post__read($pid);
			$is_liked = !empty(post_like_read($uid, $pid));
			$likes_count = $post ? intval($post['likes']) : 0;
			$ctx = param('_ctx', ($pid == $thread['firstpid']) ? 'thread' : 'post');
			header('Content-Type: text/html; charset=utf-8');
			echo _render_like_btn($tid, $pid, $is_liked, $likes_count, $ctx);
			exit;
		}
		message(-1, lang('thread_has_already_closed'));
	}

	$post = post__read($pid);
	if(empty($post)) {
		message(-1, lang('post_not_exists'));
	}
	// 保存操作前的 likes 值，用于 htmx 响应计算（避免主从延迟）
	$likes_before = intval($post['likes']);

	// 积分变动描述
	$like_change_desc = '';

	if($is_like_action) {
		// ===== 点赞 =====
		// 点赞前检查积分是否充足
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		$creditsCheck = CreditsRuleService::applyRule('like', $uid, intval($thread['fid']), true);
		if(!$creditsCheck['ok']) {
			message(-1, $creditsCheck['message']);
		}

		$create_result = post_like_create($uid, $tid, $pid);
	// $create_result=1 表示真正新增了点赞；=0 表示已存在（幂等，不重复处理）
	if($create_result == 1) {
		// 点赞时通知帖子作者（post_like_create 内部已处理通知，这里不再重复）
		// 积分规则：被点赞者获得积分，点赞者可选扣分
		if(!empty($post['uid']) && $post['uid'] != $uid) {
			$beLikedResult = CreditsRuleService::applyRule('be_liked', intval($post['uid']), intval($thread['fid']), false, strval($pid));
		}
		$likeResult = CreditsRuleService::applyRule('like', $uid, intval($thread['fid']), false, strval($pid));
		if(!empty($likeResult['ok']) && !empty($likeResult['change_desc'])) {
				$like_change_desc = $likeResult['change_desc'];
			}
			// 每日上限达到：提醒用户本次不扣减积分
			if(!empty($likeResult['daily_limit_reached'])) {
				$like_change_desc = $likeResult['message'] . '，本次点赞不扣减积分';
			}
		}
	} else {
		// ===== 取消点赞 =====
		$delete_result = post_like_delete($uid, $tid, $pid);
		// $delete_result>0 表示真正删除了点赞
		if($delete_result && $delete_result > 0) {
			// 取消点赞：应用 unlike 规则（管理员可设置取消时扣减/返还积分）
			if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
			$unlikeResult = CreditsRuleService::applyRule('unlike', $uid, intval($thread['fid']), false, strval($pid));
			if(!empty($unlikeResult['ok']) && !empty($unlikeResult['change_desc'])) {
				$like_change_desc = $unlikeResult['change_desc'];
			}
			// 每日上限达到：提醒用户本次不扣减/返还积分
			if(!empty($unlikeResult['daily_limit_reached'])) {
				$like_change_desc = $unlikeResult['message'] . '，本次取消点赞不扣减/返还积分';
			}
		}
	}

	$post = post__read($pid);
	$like_action = $is_like_action ? 'like' : 'unlike';
	$data = array('action' => $like_action, 'count' => $post ? intval($post['likes']) : 0, 'pid' => $pid, 'tid' => $tid);
	// hook thread_like_end.php

	if($is_htmx) {
		// 直接用操作类型判断点赞状态（幂等操作）
		// likes_count：根据操作前值和操作结果计算，避免主从延迟
		$is_liked = $is_like_action;
		if($is_like_action) {
			$likes_count = $create_result == 1 ? $likes_before + 1 : $likes_before;
		} else {
			$likes_count = $delete_result > 0 ? $likes_before - 1 : $likes_before;
		}
		$ctx = param('_ctx', ($pid == $thread['firstpid']) ? 'thread' : 'post');
		header('Content-Type: text/html; charset=utf-8');
		echo _render_like_btn($tid, $pid, $is_liked, $likes_count, $ctx);
		// 积分变动提示（OOB toast）
		if($like_change_desc) {
			echo '<div id="credits-toast" hx-swap-oob="true" data-change-desc="' . htmlspecialchars($like_change_desc, ENT_QUOTES, 'UTF-8') . '"></div>';
		}
		exit;
	}

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'message' => '操作成功', 'data' => $data), JSON_UNESCAPED_UNICODE);
	exit;
}

if($action == 'favorite') {
	if($method != 'POST') {
		if(is_htmx_request()) {
			header('HTTP/1.1 405 Method Not Allowed');
			exit;
		}
		message(-1, 'Method Error.');
	}
	CsrfService::check();
	$tid = param(2, 0);
	$is_htmx = is_htmx_request();
	// 读取按钮上下文：thread（电脑端左侧栏）/ thread_mobile（手机端postbar）/ 默认thread
	$fav_ctx = param('_ctx', 'thread');

	if(!$uid) {
		if($is_htmx) {
			// htmx 请求：返回原按钮 HTML + toast 提示
			htmx_trigger('showToast', array('message' => lang('please_login'), 'type' => 'error'));
			$thread = thread__read($tid);
			$is_favorited = FALSE;
			$favorites_count = $thread ? intval($thread['favorites']) : 0;
			header('Content-Type: text/html; charset=utf-8');
			echo _render_favorite_btn($tid, $is_favorited, $favorites_count, $fav_ctx);
			exit;
		}
		message(-1, lang('please_login'));
	}

	$thread = thread__read($tid);
	if(empty($thread)) {
		message(-1, lang('thread_not_exists'));
	}

	// 关闭/审核中帖子不允许普通用户收藏
	if((!empty($thread['closed']) || (isset($thread['audit_status']) && $thread['audit_status'] != 1)) && $gid > 5) {
		if($is_htmx) {
			htmx_trigger('showToast', array('message' => lang('thread_has_already_closed'), 'type' => 'error'));
			$is_favorited = !empty(thread_favorite_read($uid, $tid));
			$favorites_count = intval($thread['favorites']);
			header('Content-Type: text/html; charset=utf-8');
			echo _render_favorite_btn($tid, $is_favorited, $favorites_count, $fav_ctx);
			exit;
		}
		message(-1, lang('thread_has_already_closed'));
	}

	$exists = thread_favorite_read($uid, $tid);
	// 保存操作前的收藏数，用于 htmx 响应计算（避免数据库重新读取返回 null）
	$favorites_before = intval($thread['favorites']);
	if(!empty($exists)) {
		$r = thread_favorite_delete($uid, $tid);
	} else {
		// 收藏前检查积分是否充足
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		$creditsCheck = CreditsRuleService::applyRule('favorite', $uid, intval($thread['fid']), true);
		if(!$creditsCheck['ok']) {
			message(-1, $creditsCheck['message']);
		}

		$r = thread_favorite_create($uid, $tid);
		// thread_favorite_create 内部已调用 notify_create，此处不再重复
	}

	// hook thread_favorite_end.php

	// 积分规则：被收藏者获得积分，收藏者可选扣分；取消收藏时反向处理
	$fav_change_desc = '';
	if(empty($exists)) {
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		if(!empty($thread['uid']) && $thread['uid'] != $uid) {
			CreditsRuleService::applyRule('be_favorited', intval($thread['uid']), intval($thread['fid']), false, strval($tid));
		}
		$favResult = CreditsRuleService::applyRule('favorite', $uid, intval($thread['fid']), false, strval($tid));
		if(!empty($favResult['ok']) && !empty($favResult['change_desc'])) {
			$fav_change_desc = $favResult['change_desc'];
		}
		// 每日上限达到：提醒用户本次不扣减积分
		if(!empty($favResult['daily_limit_reached'])) {
			$fav_change_desc = $favResult['message'] . '，本次收藏不扣减积分';
		}
	} else {
		// 取消收藏：应用 unfavorite 规则（管理员可设置取消时扣减/返还积分）
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		$unfavResult = CreditsRuleService::applyRule('unfavorite', $uid, intval($thread['fid']), false, strval($tid));
		if(!empty($unfavResult['ok']) && !empty($unfavResult['change_desc'])) {
			$fav_change_desc = $unfavResult['change_desc'];
		}
		// 每日上限达到：提醒用户本次不扣减/返还积分
		if(!empty($unfavResult['daily_limit_reached'])) {
			$fav_change_desc = $unfavResult['message'] . '，本次取消收藏不扣减/返还积分';
		}
	}

	if($is_htmx) {
		// 根据操作结果计算新状态，避免重新读取数据库（thread__read 可能返回 null）
		$is_favorited = empty($exists); // 之前不存在=已创建（已收藏）；之前存在=已删除（未收藏）
		if(empty($exists)) {
			// 收藏成功：+1
			$favorites_count = ($r !== FALSE) ? $favorites_before + 1 : $favorites_before;
		} else {
			// 取消收藏成功：-1
			$favorites_count = ($r !== FALSE) ? max(0, $favorites_before - 1) : $favorites_before;
		}
		header('Content-Type: text/html; charset=utf-8');
		echo _render_favorite_btn($tid, $is_favorited, $favorites_count, $fav_ctx);
		// 积分变动提示（OOB toast）
		if($fav_change_desc) {
			echo '<div id="credits-toast" hx-swap-oob="true" data-change-desc="' . htmlspecialchars($fav_change_desc, ENT_QUOTES, 'UTF-8') . '"></div>';
		}
		exit;
	}

	$fav_action = empty($exists) ? 'favorite' : 'unfavorite';
	$fav_count = empty($exists) ? $favorites_before + 1 : max(0, $favorites_before - 1);
	$data = array('action' => $fav_action, 'count' => $fav_count, 'tid' => $tid);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'message' => '操作成功', 'data' => $data), JSON_UNESCAPED_UNICODE);
	exit;
}

if($action == 'announcement') {
	if($method != 'POST') message(-1, 'Method Error.');
	CsrfService::check();
	!$uid AND message(-1, lang('please_login'));
	$tid = param(2, 0);
	$thread = thread_read($tid);
	empty($thread) AND message(-1, lang('thread_not_exists'));
	
	!PermissionService::check('allowadmin') AND message(-1, lang('user_group_insufficient_privilege'));
	
	$announcement = !empty($thread['announcement']) ? 0 : 1;
	thread_update($tid, array('announcement'=>$announcement));
	message(0, array('action'=>$announcement ? 'set' : 'cancel', 'announcement'=>$announcement));
}

// 版块关注状态 API（htmx 延迟加载）
if($action == 'forum_follow_status') {
	$fid = param(2, 0);
	$followed = false;
	if(!empty($uid) && !empty($fid)) {
		$followed = !empty(forum_follow_read($uid, $fid));
	}

	header('Content-Type: text/html; charset=utf-8');
	if(empty($uid)) {
		echo '<a href="'.user_login_url().'" class="btn btn-sm btn-primary w-100"><i class="ti ti-star me-1"></i>'.lang('follow').'</a>';
	} elseif($followed) {
		echo '<input type="hidden" name="fid" value="'.$fid.'"><button class="btn btn-sm btn-outline-secondary w-100" hx-post="'.url('forum-unfollow').'" hx-include="[name=fid]" hx-target="this" hx-swap="outerHTML" hx-optimistic><i class="ti ti-star-filled me-1"></i>已关注版块</button>';
	} else {
		echo '<input type="hidden" name="fid" value="'.$fid.'"><button class="btn btn-sm btn-primary w-100" hx-post="'.url('forum-follow').'" hx-include="[name=fid]" hx-target="this" hx-swap="outerHTML" hx-optimistic><i class="ti ti-star me-1"></i>关注版块</button>';
	}
	exit;
}

// 发表主题帖 | create new thread
if($action == 'create') {
	
	// hook thread_create_get_post.php
		
	user_login_check();

	if($method == 'GET') {
		
		// hook thread_create_get_start.php
		
		$fid = param(2, 0);
		$forum = $fid ? (isset($forumlist[$fid]) ? $forumlist[$fid] : forum_read($fid)) : array();

		// 可发帖版块（剔除分区 type=1）
		$forumlist_allowthread = forum_list_access_filter($forumlist, $gid, 'allowthread');
		foreach($forumlist_allowthread as $k=>$f) {
			if(!empty($f['type'])) unset($forumlist_allowthread[$k]);
		}
		$forumarr = xn_json_encode(arrlist_key_values($forumlist_allowthread, 'fid', 'name'));
		if(empty($forumlist_allowthread)) {
			message(-1, lang('user_group_insufficient_privilege'));
		}

		// 两级联动：分区列表 + 按 fup 分组的版块列表
		$forum_categories = array(); // 所有分区（type=1）
		$forum_by_category = array(); // fup => array(版块列表)
		$forum_orphan = array(); // 无父分区的版块
		foreach($forumlist as $f) {
			if(!empty($f['type']) && $f['type'] == 1) {
				$forum_categories[$f['fid']] = $f;
			}
		}
		foreach($forumlist_allowthread as $f) {
			$fup = isset($f['fup']) ? intval($f['fup']) : 0;
			if($fup > 0 && isset($forum_categories[$fup])) {
				$forum_by_category[$fup][] = $f;
			} else {
				$forum_orphan[] = $f;
			}
		}
		// 当前 fid 所属分区（用于回填第一级 select）
		$current_fid_category = 0;
		if($fid && isset($forumlist[$fid]) && empty($forumlist[$fid]['type'])) {
			$current_fid_category = isset($forumlist[$fid]['fup']) ? intval($forumlist[$fid]['fup']) : 0;
		}
		
		$header['title'] = lang('create_thread');
		$header['mobile_title'] = ($fid && !empty($forum['name'])) ? $forum['name'] : '';
		$header['mobile_linke'] = forum_url($fid);
		
		// hook thread_create_get_end.php
		
		include _include(APP_PATH.'view/htm/post.htm');
		
	} else {
		
		CsrfService::check();

		// hook thread_create_thread_start.php

		// ===== 发帖验证码检查 =====
		include_once APP_PATH . 'lib/security/CaptchaService.php';
		if (CaptchaService::is_enabled('post', $gid)) {
			$captcha_input = param('captcha');
			if (empty($captcha_input)) {
				message(-1001, lang('please_input_captcha'));
			}
			if (!CaptchaService::verify('post', $captcha_input, $gid)) {
				message(-1001, lang('captcha_error'));
			}
		}

		// ===== 提取并验证基本参数 =====
		$fid = param('fid', 0);
		$forum = isset($forumlist[$fid]) ? $forumlist[$fid] : forum_read($fid);
		empty($forum) AND message('fid', lang('forum_not_exists'));

		$r = forum_access_user($fid, $gid, 'allowthread');
	!$r AND message(-1, lang('user_group_insufficient_privilege'));

	// 发帖前检查 IP 黑名单（被封 IP 不能发帖）
	include_once APP_PATH.'model/banned_ip.func.php';
	// hook banned_ip_check.php
	if(banned_ip_check($ip)) {
		message(-1, lang('user_ban_ip_banned'));
	}

	// 发帖前检查封禁状态（管理员组 gid=1,2 豁免）
	if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
	if(!in_array($gid, UserBanService::ADMIN_GIDS, true)) {
		$ban_check = UserBanService::checkBanByScene($uid, 'post');
		// hook user_ban_check.php
		if(!$ban_check['allowed']) {
			message(-1, $ban_check['message']);
		}
	}

		$subject = param('subject');
		// 标题只允许纯文本，过滤所有HTML标签
		$subject = strip_tags($subject);
		// 去除首尾空格后再计算字数（空格不计入标题长度）
		$subject = trim($subject);
		empty($subject) AND message('subject', lang('please_input_subject'));

		// ===== 标题字数检查 =====
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$subject_min_length = SecurityConfigService::get('security_subject_min_length', 2);
		$subject_max_length = SecurityConfigService::get('security_subject_max_length', 128);
		$subject_len = mb_strlen($subject, 'UTF-8');
		if ($subject_min_length > 0 && $subject_len < $subject_min_length) {
			message('subject', lang('subject_too_short', array('minlength'=>$subject_min_length)));
		}
		if ($subject_max_length > 0 && $subject_len > $subject_max_length) {
			message('subject', lang('subject_too_long', array('maxlength'=>$subject_max_length)));
		}

		$message = param('message', '', FALSE);
		empty($message) AND message('message', lang('please_input_message'));
		$doctype = param('doctype', 0);
		$doctype > 10 AND message(-1, lang('doc_type_not_supported'));
		xn_strlen($message) > 2028000 AND message('message', lang('message_too_long'));

		// ===== 内容敏感词检查（直接拦截，提示具体违规词） =====
	include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
	$subject_check = SensitiveWordFilter::content_check($subject, SensitiveWordFilter::TYPE_SENSITIVE);
	if (!$subject_check['pass']) {
		$hit_words = implode('、', $subject_check['matched_keywords']);
		message('subject', lang('post_contains_sensitive_word_with_words', array('words'=>$hit_words)));
	}
	$message_check = SensitiveWordFilter::content_check($message, SensitiveWordFilter::TYPE_SENSITIVE);
	if (!$message_check['pass']) {
		$hit_words = implode('、', $message_check['matched_keywords']);
		message('message', lang('post_contains_sensitive_word_with_words', array('words'=>$hit_words)));
	}

		// ===== 内容安全审核 =====
		include_once APP_PATH . 'lib/security/ContentModerationService.php';
		$moderation_result = ContentModerationService::moderate('thread', $subject . ' ' . $message, 'create');
		if ($moderation_result === 'block') {
			message(-1, '内容审核未通过，请修改后重新发布');
		}

		// ===== 发帖间隔检查 =====
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$post_thread_interval = SecurityConfigService::get('security_post_thread_interval', 60);
		if ($post_thread_interval > 0 && $uid > 0) {
			$last_thread = db_find_one('thread', array('uid' => $uid), array('tid' => -1), array('create_date'));
			if (!empty($last_thread)) {
				$elapsed = $time - $last_thread['create_date'];
				if ($elapsed < $post_thread_interval) {
					$remaining = $post_thread_interval - $elapsed;
					message(-1002, lang('thread_create_interval_short', array('seconds'=>$remaining)), array('wait'=>$remaining));
				}
			}
		}

		// ===== 发帖字数检查 =====
		$post_min_length = SecurityConfigService::get('security_post_min_length', 10);
		$post_max_length = SecurityConfigService::get('security_post_max_length', 50000);
		$message_len = mb_strlen($message, 'UTF-8');
		if ($post_min_length > 0 && $message_len < $post_min_length) {
			message(-1, '内容太短，至少需要' . $post_min_length . '个字');
		}
		if ($post_max_length > 0 && $message_len > $post_max_length) {
			message(-1, '内容太长，最多允许' . $post_max_length . '个字');
		}

		// ===== 新用户前N帖需审核 =====
		$new_user_audit_count = SecurityConfigService::get('security_new_user_audit_count', 0);
		if ($new_user_audit_count > 0 && $uid > 0 && $gid > 2) {
			$user_data = user_read($uid);
			if (!empty($user_data)) {
				$user_posts = intval($user_data['threads'] ?? 0);
				if ($user_posts < $new_user_audit_count) {
					$_SESSION['security_thread_needs_audit'] = true;
				}
			}
		}

		// ===== 内容审核服务触发审核 =====
		if ($moderation_result === 'review') {
			$_SESSION['security_thread_needs_audit'] = true;
		}
		
		$thread = array (
			'fid'=>$fid,
			'uid'=>$uid,
			'sid'=>$sid,
			'subject'=>$subject,
			'message'=>$message,
			'time'=>$time,
			'longip'=>$longip,
			'doctype'=>$doctype,
		);
		
		// hook thread_create_thread_before.php

		// 检查用户组审核权限
		if(!class_exists('AuditService')) include_once APP_PATH . 'lib/security/AuditService.php';
		$need_audit = AuditService::need_audit($fid, $gid, $subject, $message);
		// 插件可能通过 SESSION 标记需要审核（如新用户审核、内容审核服务）
		if(!empty($_SESSION['security_thread_needs_audit'])) {
			$need_audit = true;
			unset($_SESSION['security_thread_needs_audit']);
		}
		$audit_status = $need_audit ? 0 : 1;
		$thread['audit_status'] = $audit_status;

		$tid = thread_create($thread, $pid);
		$pid === FALSE AND message(-1, lang('create_post_failed'));
		$tid === FALSE AND message(-1, lang('create_thread_failed'));
		
		// 仅审核通过的帖子才通知关注版块的用户和@提及（待审帖子审核通过后在 AuditService::approve 中补发）
		if(!$need_audit) {
			// 通知关注该版块的用户有新帖
			// 频次控制：同一用户每30分钟最多收到1条版块新帖通知（避免关注多个活跃版块时被刷屏）
			// 去重：如果用户已收到该帖的 thread 类型通知（关注了发帖人），则合并为一条通知
			$_followers = forum_follow_find_by_fid($fid);
			// 限制通知用户数量，避免大量关注用户时性能问题
			$_followers = array_slice($_followers, 0, 1000, true);
			if($_followers) {
				$_thread_short = mb_substr($subject, 0, 30);
				// 收集需要通知的 uid（排除发帖人自己）
				$_follow_uids = array();
				foreach($_followers as $_follow) {
					if($_follow['uid'] != $uid) {
						$_follow_uids[] = $_follow['uid'];
					}
				}

				if(!empty($_follow_uids)) {
				// 批量查询已存在的 thread 类型通知（去重检查），消除 N+1 查询
				$_existing_thread_notifies = db_find('notify', array(
					'uid' => $_follow_uids,
					'type' => 'thread',
					'tid' => $tid,
				), array('nid' => -1), 1, count($_follow_uids), 'uid');

				// 批量查询 forum_post 类型通知（频次控制），消除 N+1 查询
				$_recent_forum_posts = db_find('notify', array(
					'uid' => $_follow_uids,
					'type' => 'forum_post',
				), array('nid' => -1), 1, count($_follow_uids), 'uid');

				// 收集需要批量插入的通知记录，循环结束后一次性插入，消除 N+1 INSERT
				$_batch_notify_records = array();
				foreach($_follow_uids as $_notify_uid) {
					// 去重：已有该帖的 thread 类型通知，更新为合并类型（同时来自关注的用户和版块）
					if(isset($_existing_thread_notifies[$_notify_uid])) {
						notify__update($_existing_thread_notifies[$_notify_uid]['nid'], array('type' => 'thread_forum'));
						continue;
					}
					// 频次控制：30分钟内已收到 forum_post 通知则跳过
					if(isset($_recent_forum_posts[$_notify_uid])
						&& ($time - $_recent_forum_posts[$_notify_uid]['create_date']) < 1800) {
						continue;
					}
					$_batch_notify_records[] = array(
						'uid' => $_notify_uid,
						'from_uid' => $uid,
						'type' => 'forum_post',
						'tid' => $tid,
						'pid' => 0,
						'content' => $_thread_short,
						'create_date' => $time,
						'is_read' => 0,
					);
				}

				// 批量插入通知（单次 INSERT + 单次 UPDATE user.unread_notices）
				if(!empty($_batch_notify_records)) {
					notify_create_batch($_batch_notify_records);
				}
			}
			}

			// 解析 @提及：合并富文本（data-id=UID）与旧版纯文本（@username）两种来源，
		// 批量查询用户名对应的 UID，收集通知记录后一次性 INSERT，消除 N+1 查询和 N+1 INSERT
		$_mention_uid_set = array(); // 用于去重的 UID 集合（key 为 UID）
		$_mention_text_usernames = array(); // 旧版纯文本 @username 待查列表
		if(!empty($message)) {
			// 1) 富文本 span：<span data-type="mention" data-id="UID">
			$mentionPattern = '/<span[^>]*data-type="mention"[^>]*data-id="(\d+)"[^>]*>/';
			if(preg_match_all($mentionPattern, $message, $matches)) {
				foreach($matches[1] as $_muid) {
					$_muid = intval($_muid);
					if($_muid > 0 && $_muid != $uid) {
						$_mention_uid_set[$_muid] = $_muid;
					}
				}
			}
			// 2) 旧版纯文本：@username
			preg_match_all('/@([a-zA-Z0-9_\x{4e00}-\x{9fa5}]+)/u', $message, $matches);
			if(!empty($matches[1])) {
				foreach($matches[1] as $_mname) {
					$_mention_text_usernames[$_mname] = $_mname;
				}
			}
		}
		// 批量查询旧版纯文本 @username 对应的 UID（单次 SQL）
		if(!empty($_mention_text_usernames)) {
			$_mention_users = user_find_by_usernames(array_values($_mention_text_usernames));
			if(!empty($_mention_users)) {
				foreach($_mention_users as $_muser) {
					$_muid = intval($_muser['uid']);
					if($_muid > 0 && $_muid != $uid) {
						$_mention_uid_set[$_muid] = $_muid;
					}
				}
			}
		}
		// 收集通知记录，单次 INSERT（mention 类型不在防抖列表，notify_create_batch 会过滤自己通知自己）
		if(!empty($_mention_uid_set)) {
			$_mention_records = array();
			foreach($_mention_uid_set as $_muid) {
				$_mention_records[] = array(
					'uid' => $_muid,
					'from_uid' => $uid,
					'type' => 'mention',
					'tid' => $tid,
					'pid' => 0,
					'content' => '在帖子中提及了你',
					'create_date' => $time,
					'is_read' => 0,
				);
			}
			notify_create_batch($_mention_records);
		}
		}

		// ===== 积分预检查：扣减类操作余额不足则拒绝 =====
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		$creditsCheck = CreditsRuleService::applyRule('thread_post', $uid, $fid, true);
		if(!$creditsCheck['ok']) {
			message(-1, $creditsCheck['message']);
		}

		// hook thread_create_thread_end.php

		// 积分规则：发主题获得/扣除积分
		// 需要审核时：扣除部分立即执行，奖励部分延迟到审核通过后发放
		// 不需要审核时：正常执行全部积分变动
		if($need_audit) {
			$threadCreditsResult = CreditsRuleService::applyRuleDeductOnly('thread_post', $uid, $fid);
		} else {
			$threadCreditsResult = CreditsRuleService::applyRule('thread_post', $uid, $fid);
		}

		// 审核通知：如果帖子进入审核，通知作者
		if($need_audit && !empty($tid)) {
			notify_create($uid, 0, 'audit_pending', $tid, 0, lang('notify_audit_thread_pending', array('subject' => mb_substr($subject, 0, 30))));
		}

		// 积分变动描述
		$change_desc = '';
		if(!empty($threadCreditsResult['ok']) && !empty($threadCreditsResult['change_desc'])) {
			$change_desc = $threadCreditsResult['change_desc'];
		}
		// 每日上限达到：提醒用户本次不发放/扣除积分
		if(!empty($threadCreditsResult['daily_limit_reached'])) {
			$change_desc = $threadCreditsResult['message'] . '，本次发帖不发放/扣除积分';
		}

	if($need_audit) {
		message(0, lang('create_thread_pending_audit'), array('redirect_url' => thread_url($tid), 'change_desc' => $change_desc));
	} else {
		message(0, lang('create_thread_sucessfully'), array('redirect_url' => thread_url($tid), 'change_desc' => $change_desc));
	}
	}
	
// 帖子详情 | post detail
} else {
	
	// thread-{tid}-{page}-{keyword}.htm
	$tid = param(1, 0);
	$page = param(2, 1);
	$keyword = param(3);
	$pagesize = $conf['postlist_pagesize'];

	// 评论排序：asc(正序) / desc(倒序) / hot(热度)
	$sort = param('sort', '');
	$valid_sorts = array('asc', 'desc', 'hot');
	if(!in_array($sort, $valid_sorts)) $sort = 'asc';

	$thread = thread_read($tid);
	empty($thread) AND error_page(404, lang('thread_not_exists'));

	// 待审/驳回帖子访问控制：非管理员(gid!=1,2)不能查看他人待审帖子，驳回帖子仅作者可见
	if(($gid == 0 || $gid > 2) && isset($thread['audit_status']) && $thread['audit_status'] != 1) {
		// 驳回帖子(audit_status=2)仅作者可见；待审帖子(audit_status=0)仅作者可见
		if($thread['uid'] != $uid) {
			error_page(404, lang('thread_not_exists'));
		}
	}

	// hook thread_info_start.php

	$fid = $thread['fid'];
	$forum = isset($forumlist[$fid]) ? $forumlist[$fid] : forum_read($fid);
	empty($forum) AND error_page(404, lang('forum_not_exists'));

	// 根据 sort 设置排序方式
	switch($sort) {
		case 'desc':
			$_orderby = array('pid'=>-1);
			break;
		case 'hot':
			$_orderby = array('likes'=>-1, 'pid'=>1);
			break;
		default:
			$_orderby = array('pid'=>1);
			break;
	}

	// === 两级评论分页查询 ===
	// 一级评论：quotepid=0 或 quotepid=firstpid（直接回复主题）
	// 二级评论：quotepid 指向其他评论（楼中楼），跟随其一级父评论显示，不占分页计数
	// 回帖列表 60s 短缓存，使用 CacheHelper::remember + 版本号机制
	// post_create 时递增版本号，使旧缓存自动失效
	$_firstpid = $thread['firstpid'];
	$_postlist_version_key = 'thread_pl_v_' . $tid;
	$_pl_version = CacheHelper::remember($_postlist_version_key, 86400, function() {
		return 1;
	});

	// 第一步：查一级评论（分页）
	$_postlist_cache_key = 'thread_pl_' . $tid . '_' . $sort . '_' . $page . '_v' . $_pl_version;
	$postlist = CacheHelper::remember($_postlist_cache_key, 60, function() use ($tid, $_firstpid, $_orderby, $page, $pagesize) {
		return post__find(
			array('tid'=>$tid, 'isfirst'=>0, 'quotepid'=>array(0, $_firstpid)),
			$_orderby, $page, $pagesize
		);
	});

	// 格式化一级评论 + 楼层
	if($postlist) {
		$floor = ($page - 1) * $pagesize + 1;
		foreach($postlist as &$_pf_post) {
			$_pf_post['floor'] = $floor++;
			$_pf_post['parent_pid'] = 0;
			$_pf_post['reply_to_username'] = '';
			$_pf_post['reply_to_uid'] = 0;
			post_format($_pf_post);
		}
		unset($_pf_post);
	}

	// 待审/驳回回帖过滤：非管理员(gid!=1,2)不可见 audit_status!=1 的他人回帖，作者自己可见待审和驳回回帖
	if(($gid == 0 || $gid > 2) && !empty($postlist)) {
		foreach($postlist as $_pid => $_post) {
			if(isset($_post['audit_status']) && $_post['audit_status'] != 1) {
				// 待审/驳回回帖仅作者可见
				if($_post['uid'] != $uid) {
					unset($postlist[$_pid]);
				}
			}
		}
	}

	// 首帖单独获取（不受排序影响，始终显示）
	$first = post_read($thread['firstpid']);
	empty($first) AND error_page(404, lang('data_malformation'));
	post_format($first);
	if($page == 1) {
		thread_inc_views($tid);
	}

	// 查询当前用户对首帖的点赞和收藏状态（从 thread_format 移出，仅详情页需要）
	$thread['is_liked'] = 0;
	$thread['is_favorited'] = 0;
	if(!empty($uid)) {
		$thread['is_liked'] = !empty(post_like_read($uid, $thread['firstpid'])) ? 1 : 0;
		$thread['is_favorited'] = !empty(thread_favorite_read($uid, $tid)) ? 1 : 0;
	}

	// 第二步：查询二级评论（楼中楼），跟随其一级父评论显示
	// 二级评论：quotepid 指向其他评论（非 0、非 firstpid）
	// 递归查询最多 3 轮，覆盖"回复二级评论的二级评论"嵌套情况
	$reply_map = array();
	if(!empty($postlist)) {
		$_main_pids = array();
		foreach($postlist as $_p) $_main_pids[] = $_p['pid'];

		$_replies_cache_key = 'thread_pl_replies_' . $tid . '_' . md5(implode('-', $_main_pids)) . '_v' . $_pl_version;
		$_all_replies = CacheHelper::remember($_replies_cache_key, 60, function() use ($tid, $_main_pids) {
			$result = array();
			$batch = $_main_pids;
			// ponytail: 递归查询嵌套回复，上限20轮（防极端死循环）。正常靠 array_diff 去重 + batch 为空自动退出。
			for($i = 0; $i < 20 && !empty($batch); $i++) {
				$found = post__find(
					array('tid'=>$tid, 'isfirst'=>0, 'quotepid'=>$batch),
					array('pid'=>1), 1, 1000
				);
				if(empty($found)) break;
				// 记录本轮查询前已查到的 pid，用于去重
				$_old_keys = array_keys($result);
				foreach($found as $f) $result[$f['pid']] = $f;
				// 下一轮查询 quotepid 指向本轮新查到的评论（嵌套回复），排除已查过的 pid 避免循环引用
				$batch = array_diff(array_column($found, 'pid'), $_old_keys);
			}
			return array_values($result);
		});

		if(!empty($_all_replies)) {
			// 待审过滤二级评论
			if($gid == 0 || $gid > 2) {
				foreach($_all_replies as $_rid => $_r) {
					if(isset($_r['audit_status']) && $_r['audit_status'] != 1 && $_r['uid'] != $uid) {
						unset($_all_replies[$_rid]);
					}
				}
			}

			// 批量预加载楼中楼回复的点赞状态和隐藏内容，消除 post_format 中的 N+1 查询
			if(!empty($_all_replies)) {
				global $uid, $g_preloaded_post_likes;
				$_reply_pids = array();
				foreach($_all_replies as $_r) $_reply_pids[] = $_r['pid'];

				// 补充预加载点赞状态（合并一级评论已预加载的数据）
				if(!empty($uid) && !empty($_reply_pids)) {
					if(!isset($g_preloaded_post_likes)) $g_preloaded_post_likes = array();
					$_need_like_pids = array();
					foreach($_reply_pids as $_pid) {
						if(!isset($g_preloaded_post_likes[$_pid])) $_need_like_pids[] = $_pid;
					}
					if(!empty($_need_like_pids)) {
						$g_preloaded_post_likes = array_merge($g_preloaded_post_likes, post_like_read_batch($uid, $_need_like_pids));
					}
				}

				// 批量预加载隐藏内容
				if(class_exists('HiddenService', false)) {
					HiddenService::preloadHiddenByPostIds($_reply_pids);
				}
			}

			// 先格式化所有二级评论（补 username/avatar 等），必须在构建 pid_map 之前完成
			// 否则 pid_map 存的是未格式化数据，深层回复取 reply_to_username 时会拿到空值
			foreach($_all_replies as &$_r) {
				post_format($_r);
				$_r['floor'] = 0; // 二级评论无楼层
			}
			unset($_r);

			// 构建 pid_map（包含一级和已格式化的二级评论，用于解析父级关系和 reply_to_username）
			$pid_map = array();
			foreach($postlist as $_p) $pid_map[$_p['pid']] = $_p;
			foreach($_all_replies as $_r) $pid_map[$_r['pid']] = $_r;

			// 递归找一级父评论 + 解析 reply_to_username
			foreach($_all_replies as &$_r) {
				$_r['parent_pid'] = 0;
				$_current = $_r['quotepid'];
				for($d = 0; $d < 10; $d++) {
					if(!isset($pid_map[$_current])) break;
					$_parent = $pid_map[$_current];
					// 一级评论判定：quotepid 为 0 或等于 firstpid
					if(empty($_parent['quotepid']) || $_parent['quotepid'] == $_firstpid) {
						$_r['parent_pid'] = $_parent['pid'];
						break;
					}
					$_current = $_parent['quotepid'];
				}

				// reply_to_username（直接回复的对象）
				$_r['reply_to_username'] = '';
				$_r['reply_to_uid'] = 0;
				if(isset($pid_map[$_r['quotepid']])) {
					$_r['reply_to_username'] = $pid_map[$_r['quotepid']]['username'] ?? '';
					$_r['reply_to_uid'] = $pid_map[$_r['quotepid']]['uid'] ?? 0;
				}
			}
			unset($_r);

			// 归入 reply_map（只归入父评论在当前页的二级评论）
			foreach($_all_replies as $_r) {
				if($_r['parent_pid'] > 0 && in_array($_r['parent_pid'], $_main_pids)) {
					if(!isset($reply_map[$_r['parent_pid']])) {
						$reply_map[$_r['parent_pid']] = array();
					}
					$reply_map[$_r['parent_pid']][] = $_r;
				}
			}
		}
	}

	// 置顶评论排在最前面，非置顶评论保持原顺序（尊重用户选择的排序方式 hot/desc/asc）
	if(!empty($postlist)) {
		$_top_posts = array();
		$_normal_posts = array();
		foreach($postlist as $_p) {
			if(!empty($_p['is_top'])) {
				$_top_posts[] = $_p;
			} else {
				$_normal_posts[] = $_p;
			}
		}
		$postlist = array_merge($_top_posts, $_normal_posts);
	}
	
	$keywordurl = '';
	if($keyword) {
		$thread['subject'] = post_highlight_keyword($thread['subject'], $keyword);
		//$first['message'] = post_highlight_keyword($first['subject']);
		$keywordurl = "-$keyword";
	}
	$allowpost = forum_access_user($fid, $gid, 'allowpost') ? 1 : 0;
	$allowupdate = forum_access_mod($fid, $gid, 'allowupdate') ? 1 : 0;
	$allowdelete = forum_access_mod($fid, $gid, 'allowdelete') ? 1 : 0;
	
	forum_access_user($fid, $gid, 'allowread') OR message(-1, lang('user_group_insufficient_privilege'));

	// 分页：基于一级评论数（不含楼中楼二级回复），加缓存
	// post_count 不自动过滤 is_deleted，需手动指定
	$_main_count_cache_key = 'thread_pl_count_' . $tid . '_v' . $_pl_version;
	$_main_count = CacheHelper::remember($_main_count_cache_key, 300, function() use ($tid, $_firstpid) {
		return post_count(array('tid'=>$tid, 'isfirst'=>0, 'quotepid'=>array(0, $_firstpid), 'is_deleted'=>0));
	});
	$_sort_query = $sort != 'asc' ? '?sort=' . $sort : '';
	$pagination = pagination(url("thread-$tid-{page}$keywordurl") . $_sort_query, $_main_count, $page, $pagesize);

	// SEO: 帖子完整 URL（canonical/og/json-ld 复用，提前定义避免后续使用未初始化变量）
	// 用 absolute_url() 处理 base_path 去重（http_url_path 与 url() 都含 base_path，直接拼接会重复）
	$thread_url = absolute_url(thread_url($tid));

	$header['title'] = $thread['subject'].'-'.$forum['name'].'-'.$conf['sitename'];
//$header['mobile_title'] = lang('thread_detail');
$header['mobile_title'] = $forum['name'];
$header['mobile_link'] = forum_url($fid);
$header['keywords'] = '';
// SEO: description 优先用正文前 120 字摘要，正文为空时回退到标题
// ponytail: strip_tags 后需 html_entity_decode 解码 &nbsp;/&amp; 等，否则 SERP 显示实体字面量
$_seo_desc = isset($first['message']) ? strip_tags($first['message']) : '';
$_seo_desc = html_entity_decode($_seo_desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$_seo_desc = trim(preg_replace('/\s+/', ' ', $_seo_desc));
$header['description'] = $_seo_desc !== '' ? mb_substr($_seo_desc, 0, 120, 'UTF-8') : $thread['subject'];
// SEO: canonical / Open Graph / Twitter Card
// ponytail: canonical 用 thread_url($tid) 不含 page，分页页面权重集中到第一页
$header['canonical'] = $thread_url;
$header['og_type'] = 'article';
// SEO: og:image 取正文第一张图片
$header['og_image'] = '';
if(!empty($first['message']) && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $first['message'], $_m)) {
	$_img = $_m[1];
	if(strpos($_img, 'http') !== 0 && strpos($_img, '//') !== 0) {
		$_img = absolute_url($_img);
	}
	$header['og_image'] = $_img;
}
// SEO: rel=prev/next 分页标记（百度仍支持，Google 已弃用但不报错）
if(isset($_main_count) && $pagesize > 0) {
	$_thread_total_pages = max(1, ceil($_main_count / $pagesize));
	if($page > 1) {
		$header['prev_url'] = absolute_url($page == 2 ? thread_url($tid) : thread_page_url($tid, $page - 1));
	}
	if($page < $_thread_total_pages) {
		$header['next_url'] = absolute_url(thread_page_url($tid, $page + 1));
	}
}
	$_SESSION['fid'] = $fid;
	
	
	
	// hook thread_info_end.php

	// === 右侧边栏数据准备 ===

	// 作者完整数据
	$author = user_read($thread['uid']);
	$author_group = isset($grouplist[$author['gid']]) ? $grouplist[$author['gid']] : group_read($author['gid']);

	// SEO: 作者 Person（含 url，便于 AI 关联作者主页）
	$_author_jsonld = array(
		'@type' => 'Person',
		'name' => $author['display_name'] ?? $author['username'] ?? '',
		'url' => absolute_url(user_url($thread['uid'])),
	);

	// SEO: 智能判断问答型 vs 讨论型
	// 启发式：标题包含 ? 或 ？（全角/半角问号），符合中文发帖习惯
	$_is_qa_thread = strpos($thread['subject'], '?') !== false
		|| strpos($thread['subject'], '？') !== false;

	if($_is_qa_thread) {
		// QAPage：首帖=Question，回帖=SuggestedAnswer/AcceptedAnswer
		$_answers = array();
		if(!empty($postlist)) {
			foreach($postlist as $_p) {
				if(!empty($_p['is_deleted']) || (isset($_p['audit_status']) && $_p['audit_status'] != 1)) continue;
				$_ans = array(
					'@type' => 'Answer',
					'text' => trim(preg_replace('/\s+/', ' ', strip_tags($_p['message_fmt'] ?? $_p['message'] ?? ''))),
					'dateCreated' => date('c', $_p['create_date']),
					'author' => array(
						'@type' => 'Person',
						'name' => $_p['username'] ?? '',
						'url' => absolute_url(user_url($_p['uid'])),
					),
					'upvoteCount' => intval($_p['likes'] ?? 0),
					'url' => $thread_url . '#pid-' . $_p['pid'],
				);
				// 最高赞回帖作为 acceptedAnswer
				if(!isset($_accepted) || intval($_p['likes'] ?? 0) > intval($_accepted['upvoteCount'] ?? 0)) {
					$_accepted = $_ans;
				}
				$_answers[] = $_ans;
			}
		}
		$_qa_main = array(
			'@type' => 'Question',
			'name' => $thread['subject'],
			'text' => $header['description'],
			'dateCreated' => date('c', $thread['create_date']),
			'author' => $_author_jsonld,
			'answerCount' => intval($thread['posts']),
			'url' => $thread_url,
		);
		if(!empty($_accepted)) {
			$_qa_main['acceptedAnswer'] = $_accepted;
		}
		if(!empty($_answers)) {
			$_qa_main['suggestedAnswer'] = $_answers;
		}
		$header['json_ld'] = array(
			'@context' => 'https://schema.org',
			'@type' => 'QAPage',
			'mainEntity' => $_qa_main,
		);
		// SEO: QAPage 加 image（Google 富媒体摘要推荐）
		if(!empty($header['og_image'])) {
			$header['json_ld']['image'] = $header['og_image'];
		}
	} else {
		// DiscussionForumPosting：讨论型
		$header['json_ld'] = array(
			'@context' => 'https://schema.org',
			'@type' => 'DiscussionForumPosting',
			'headline' => $thread['subject'],
			'url' => $thread_url,
			'datePublished' => date('c', $thread['create_date']),
			'dateModified' => date('c', $thread['last_date']),
			'author' => $_author_jsonld,
			'description' => $header['description'],
		);
		// SEO: DiscussionForumPosting 加 image（Google 富媒体摘要推荐）
		if(!empty($header['og_image'])) {
			$header['json_ld']['image'] = $header['og_image'];
		}
	}

	// 作者关注状态 — 直接查询
	$author_followed = false;
	if(!empty($uid) && $uid != $thread['uid']) {
		$author_followed = !empty(user_follow_read($uid, $thread['uid']));
	}

	// 作者统计数据
	$author_threads = intval($author['threads']);
	$author_posts = intval($author['posts']);
	$author_followers = intval($author['followeds']);
	$author_following = intval($author['follows']);

	// 作者近期帖子 — 直接查询
	$author_recent_threads = db_find('thread', array('uid'=>$thread['uid'], 'tid'=>array('!='=>$tid)), array('tid'=>-1), 1, 5, 'tid');
	if(!empty($author_recent_threads)) {
		if(($gid == 0 || $gid > 2) && $thread['uid'] != $uid) {
			foreach($author_recent_threads as $_rt_key => $_rt) {
				// 非管理员查看他人：只显示审核通过的帖子（排除待审和驳回）
				if(isset($_rt['audit_status']) && $_rt['audit_status'] != 1) {
					unset($author_recent_threads[$_rt_key]);
				}
			}
		}
		foreach($author_recent_threads as &$_rt) {
			$_rt['user_avatar_url'] = $author['avatar_url'];
			$_rt['username'] = $author['display_name'] ?? $author['username'];
		}
		unset($_rt);
	}

	// 帖子附件列表
	$sidebar_attachlist = array();
	$sidebar_filelist = array();
	if(!empty($first['files'])) {
		list($sidebar_attachlist, $_, $sidebar_filelist) = attach_find_by_pid($first['pid']);
	}

	// 版块关注状态 — 延迟加载
	$forum_followed = false;

	// 附件下载权限
	$allowdown = forum_access_user($fid, $gid, 'allowdown') ? 1 : 0;

	// $thread_url 已在上方 SEO 区块提前定义（canonical/og/json-ld 复用）

	include _include(APP_PATH.'view/htm/thread.htm');
}

// hook thread_end.php

?>