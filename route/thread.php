<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook thread_start.php

if($action == 'like') {
	$tid = param(2, 0);
	$pid = param(3, 0);
	$is_htmx = is_htmx_request();

	if(!$uid) {
		if($is_htmx) {
			// htmx 请求：返回原按钮 HTML + toast 提示
			htmx_trigger('showToast', array('message' => lang('please_login'), 'type' => 'error'));
			$thread = thread__read($tid);
			$post = post__read($pid);
			$is_liked = FALSE;
			$likes_count = intval($post['likes']);
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
	if((!empty($thread['closed']) || (isset($thread['audit_status']) && $thread['audit_status'] == 0)) && $gid > 5) {
		if($is_htmx) {
			htmx_trigger('showToast', array('message' => lang('thread_has_already_closed'), 'type' => 'error'));
			$post = post__read($pid);
			$is_liked = !empty(post_like_read($uid, $pid));
			$likes_count = intval($post['likes']);
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

	$exists = post_like_read($uid, $pid);
	if(!empty($exists)) {
		post_like_delete($uid, $tid, $pid);
	} else {
		post_like_create($uid, $tid, $pid);
		// 点赞时通知帖子作者
		if($post['uid'] != $uid) {
			notify_create($post['uid'], $uid, 'like', $tid, $pid);
		}
	}

	$post = post__read($pid);
	$like_action = empty($exists) ? 'like' : 'unlike';
	$data = array('action' => $like_action, 'count' => intval($post['likes']), 'pid' => $pid, 'tid' => $tid);
	// hook thread_like_end.php

	// 积分规则：被点赞者获得积分，点赞者可选扣分
	if(empty($exists)) {
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		if(!empty($post['uid']) && $post['uid'] != $uid) {
			CreditsRuleService::applyRule('be_liked', intval($post['uid']), intval($thread['fid']));
		}
		CreditsRuleService::applyRule('like', $uid, intval($thread['fid']));
	}

	if($is_htmx) {
		$is_liked = !empty(post_like_read($uid, $pid));
		$likes_count = intval($post['likes']);
		$ctx = param('_ctx', ($pid == $thread['firstpid']) ? 'thread' : 'post');
		header('Content-Type: text/html; charset=utf-8');
		echo _render_like_btn($tid, $pid, $is_liked, $likes_count, $ctx);
		exit;
	}

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'message' => '操作成功', 'data' => $data), JSON_UNESCAPED_UNICODE);
	exit;
}

if($action == 'favorite') {
	$tid = param(2, 0);
	$is_htmx = is_htmx_request();

	if(!$uid) {
		if($is_htmx) {
			// htmx 请求：返回原按钮 HTML + toast 提示
			htmx_trigger('showToast', array('message' => lang('please_login'), 'type' => 'error'));
			$thread = thread__read($tid);
			$is_favorited = FALSE;
			$favorites_count = $thread ? intval($thread['favorites']) : 0;
			header('Content-Type: text/html; charset=utf-8');
			echo _render_favorite_btn($tid, $is_favorited, $favorites_count);
			exit;
		}
		message(-1, lang('please_login'));
	}

	$thread = thread__read($tid);
	if(empty($thread)) {
		message(-1, lang('thread_not_exists'));
	}

	// 关闭/审核中帖子不允许普通用户收藏
	if((!empty($thread['closed']) || (isset($thread['audit_status']) && $thread['audit_status'] == 0)) && $gid > 5) {
		if($is_htmx) {
			htmx_trigger('showToast', array('message' => lang('thread_has_already_closed'), 'type' => 'error'));
			$is_favorited = !empty(thread_favorite_read($uid, $tid));
			$favorites_count = intval($thread['favorites']);
			header('Content-Type: text/html; charset=utf-8');
			echo _render_favorite_btn($tid, $is_favorited, $favorites_count);
			exit;
		}
		message(-1, lang('thread_has_already_closed'));
	}

	$exists = thread_favorite_read($uid, $tid);
	if(!empty($exists)) {
		thread_favorite_delete($uid, $tid);
	} else {
		thread_favorite_create($uid, $tid);
		// 收藏时通知帖子作者
		if($thread['uid'] != $uid) {
			notify_create($thread['uid'], $uid, 'favorite', $tid);
		}
	}

	// hook thread_favorite_end.php

	// 积分规则：被收藏者获得积分，收藏者可选扣分
	if(empty($exists)) {
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		if(!empty($thread['uid']) && $thread['uid'] != $uid) {
			CreditsRuleService::applyRule('be_favorited', intval($thread['uid']), intval($thread['fid']));
		}
		CreditsRuleService::applyRule('favorite', $uid, intval($thread['fid']));
	}

	if($is_htmx) {
		$thread = thread__read($tid);
		$is_favorited = !empty(thread_favorite_read($uid, $tid));
		$favorites_count = intval($thread['favorites']);
		header('Content-Type: text/html; charset=utf-8');
		echo _render_favorite_btn($tid, $is_favorited, $favorites_count);
		exit;
	}

	$fav_action = empty($exists) ? 'favorite' : 'unfavorite';
	$thread = thread__read($tid);
	$data = array('action' => $fav_action, 'count' => $thread['favorites'], 'tid' => $tid);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'message' => '操作成功', 'data' => $data), JSON_UNESCAPED_UNICODE);
	exit;
}

if($action == 'announcement') {
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
		echo '<a href="'.url('user-login').'" class="btn btn-sm btn-primary w-100"><i class="ti ti-star me-1"></i>'.lang('follow').'</a>';
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
		
		$forumlist_allowthread = forum_list_access_filter($forumlist, $gid, 'allowthread');
		foreach($forumlist_allowthread as $k=>$f) {
			if(!empty($f['type'])) unset($forumlist_allowthread[$k]);
		}
		$forumarr = xn_json_encode(arrlist_key_values($forumlist_allowthread, 'fid', 'name'));
		if(empty($forumlist_allowthread)) {
			message(-1, lang('user_group_insufficient_privilege'));
		}
		
		$header['title'] = lang('create_thread');
		$header['mobile_title'] = $fid ? $forum['name'] : '';
		$header['mobile_linke'] = url("forum-$fid");
		
		// hook thread_create_get_end.php
		
		include _include(APP_PATH.'view/htm/post.htm');
		
	} else {
		
		CsrfService::check();
		
		// hook thread_create_thread_start.php

		// ===== 发帖验证码检查 =====
		include_once APP_PATH . 'lib/security/CaptchaService.php';
		if (CaptchaService::is_enabled('post')) {
			$captcha_input = param('captcha');
			if (empty($captcha_input)) {
				message(-1001, lang('please_input_captcha'));
			}
			if (!CaptchaService::verify('post', $captcha_input)) {
				message(-1001, lang('captcha_error'));
			}
		}

		// ===== 提取并验证基本参数 =====
		$fid = param('fid', 0);
		$forum = isset($forumlist[$fid]) ? $forumlist[$fid] : forum_read($fid);
		empty($forum) AND message('fid', lang('forum_not_exists'));

		$r = forum_access_user($fid, $gid, 'allowthread');
		!$r AND message(-1, lang('user_group_insufficient_privilege'));

		$subject = param('subject');
		empty($subject) AND message('subject', lang('please_input_subject'));
		xn_strlen($subject) > 128 AND message('subject', lang('subject_length_over_limit', array('maxlength'=>128)));

		$message = param('message', '', FALSE);
		empty($message) AND message('message', lang('please_input_message'));
		$doctype = param('doctype', 0);
		$doctype > 10 AND message(-1, lang('doc_type_not_supported'));
		xn_strlen($message) > 2028000 AND message('message', lang('message_too_long'));

		// ===== 敏感词过滤 =====
		include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
		$filter_result = SensitiveWordFilter::content_filter($subject . ' ' . $message);
		if (!$filter_result['pass']) {
			message(-1, '内容包含敏感词：' . implode('、', array_slice($filter_result['matched_keywords'], 0, 5)) . '，请修改后重新发布');
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
		// 插件可能通过 SESSION 标记需要审核（如新用户审核、内容审核服务等）
		if(!empty($_SESSION['security_thread_needs_audit'])) {
			$need_audit = true;
			unset($_SESSION['security_thread_needs_audit']);
		}
		$audit_status = $need_audit ? 0 : 1;
		$thread['audit_status'] = $audit_status;

		$tid = thread_create($thread, $pid);
		$pid === FALSE AND message(-1, lang('create_post_failed'));
		$tid === FALSE AND message(-1, lang('create_thread_failed'));
		
		// 通知关注该版块的用户有新帖
		// 频次控制：同一用户每30分钟最多收到1条版块新帖通知（避免关注多个活跃版块时被刷屏）
		$_followers = forum_follow_find_by_fid($fid);
		if($_followers) {
			$_thread_short = mb_substr($subject, 0, 30);
			foreach($_followers as $_follow) {
				if($_follow['uid'] == $uid) continue; // 不通知发帖人自己
				// 频次控制：检查该用户最近30分钟内是否已收到 forum_post 通知
				$_recent = db_find_one('notify', array(
					'uid' => $_follow['uid'],
					'type' => 'forum_post',
				), array('nid' => -1));
				if($_recent && ($time - $_recent['create_date']) < 1800) continue;
				notify_create($_follow['uid'], $uid, 'forum_post', $tid, 0, $_thread_short);
			}
		}

		// 解析@提及
		if(!empty($message)) {
			preg_match_all('/@(\S+)/', $message, $matches);
			if(!empty($matches[1])) {
				$mentioned_usernames = array_unique($matches[1]);
				foreach($mentioned_usernames as $musername) {
					$muser = user_read_by_username($musername);
					if(!empty($muser) && $muser['uid'] != $uid) {
						notify_create($muser['uid'], $uid, 'mention', $tid, 0, '在帖子中提及了你');
					}
				}
			}
		}

		// 解析富文本 @提及 并发送通知
		$mentionPattern = '/<span[^>]*data-type="mention"[^>]*data-id="(\d+)"[^>]*>/';
		if(preg_match_all($mentionPattern, $message, $matches)) {
			$mentionUids = array_unique($matches[1]);
			$mentionUids = array_filter($mentionUids, function($muid) use ($uid) {
				return $muid != $uid && $muid > 0;
			});
			foreach($mentionUids as $mentionUid) {
				$mentionUid = intval($mentionUid);
				notify_create($mentionUid, $uid, 'mention', $tid, 0, '在帖子中提及了你');
			}
		}

		// hook thread_create_thread_end.php

		// 积分规则：发主题获得积分
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		CreditsRuleService::applyRule('thread_post', $uid, $fid);

		// 审核通知：如果帖子进入审核，通知作者
		if(!empty($_SESSION['security_thread_needs_audit'])) {
			unset($_SESSION['security_thread_needs_audit']);
			if(!empty($tid)) {
				notify_create($uid, 0, 'audit_pending', $tid, 0, lang('notify_audit_thread_pending', array('subject' => mb_substr($subject, 0, 30))));
			}
		}
		if($need_audit) {
			message(0, '帖子已提交，等待审核', array('redirect_url' => url("forum-$fid")));
		} else {
			message(0, lang('create_thread_sucessfully'), array('redirect_url' => url("thread-$tid")));
		}
	}
	
// 帖子详情 | post detail
} else {
	
	// thread-{tid}-{page}-{keyword}.htm
	$tid = param(1, 0);
	$page = param(2, 1);
	$keyword = param(3);
	$pagesize = $conf['postlist_pagesize'];
	//$pagesize = 10;
	//$page == 1 AND $pagesize++;
	
	// hook thread_info_start.php
	
	$thread = thread_read($tid);
	empty($thread) AND error_page(404, lang('thread_not_exists'));

	// 待审帖子访问控制：非管理员(gid!=1,2)不能查看他人待审帖子
	if(($gid == 0 || $gid > 2) && isset($thread['audit_status']) && $thread['audit_status'] == 0 && $thread['uid'] != $uid) {
		error_page(404, lang('thread_not_exists'));
	}

	$fid = $thread['fid'];
	$forum = isset($forumlist[$fid]) ? $forumlist[$fid] : forum_read($fid);
	empty($forum) AND error_page(404, lang('forum_not_exists'));

	$postlist = post_find_by_tid($tid, $page, $pagesize);
	empty($postlist) AND error_page(404, lang('post_not_exists'));

	// 待审回帖过滤：非管理员(gid!=1,2)不可见 audit_status=0 的回帖，但作者自己可见
	if(($gid == 0 || $gid > 2) && !empty($postlist)) {
		foreach($postlist as $_pid => $_post) {
			// 首帖不在此过滤（首帖状态跟随 thread）
			if(!empty($_post['isfirst'])) continue;
			if(isset($_post['audit_status']) && $_post['audit_status'] == 0 && $_post['uid'] != $uid) {
				unset($postlist[$_pid]);
			}
		}
	}

	// 先提取首帖（原始 postlist 以 pid 为 key）
	if($page == 1) {
		empty($postlist[$thread['firstpid']]) AND error_page(404, lang('data_malformation'));
		$first = $postlist[$thread['firstpid']];
		unset($postlist[$thread['firstpid']]);
		$attachlist = $imagelist = $filelist = array();
		
		// 如果是大站，可以用单独的点击服务，减少 db 压力
		// if request is huge, separate it from mysql server
		thread_inc_views($tid);
	} else {
		$first = post_read($thread['firstpid']);
	}

	// 查询当前用户对首帖的点赞和收藏状态（从 thread_format 移出，仅详情页需要）
	$thread['is_liked'] = 0;
	$thread['is_favorited'] = 0;
	if(!empty($uid)) {
		$thread['is_liked'] = !empty(post_like_read($uid, $thread['firstpid'])) ? 1 : 0;
		$thread['is_favorited'] = !empty(thread_favorite_read($uid, $tid)) ? 1 : 0;
	}

	// 构建两级评论结构：level-1 主评论 + level-2 回复
	$reply_map = array();
	if(!empty($postlist)) {
		// 构建 pid -> post 查找表，用于解析父级关系
		$pid_map = array();
		foreach($postlist as $_p) {
			$pid_map[$_p['pid']] = $_p;
		}

		// 解析每个评论的 parent_pid 和 reply_to_username
		foreach($postlist as &$_p) {
			$_p['parent_pid'] = 0;
			$_p['reply_to_username'] = '';
			$_p['reply_to_uid'] = 0;

			if(!empty($_p['quotepid']) && $_p['quotepid'] != $thread['firstpid']) {
				// 递归查找 level-1 父评论
				$_current_pid = $_p['quotepid'];
				$_depth = 0;
				while($_depth < 10) {
					if(!isset($pid_map[$_current_pid])) break;
					$_quoted = $pid_map[$_current_pid];
					if(empty($_quoted['quotepid']) || $_quoted['quotepid'] == $thread['firstpid']) {
						// 找到 level-1 父评论
						$_p['parent_pid'] = $_quoted['pid'];
						break;
					}
					$_current_pid = $_quoted['quotepid'];
					$_depth++;
				}

				// 设置 reply_to_username（直接回复的对象）
				if(isset($pid_map[$_p['quotepid']])) {
					$_p['reply_to_username'] = $pid_map[$_p['quotepid']]['username'] ?? '';
					$_p['reply_to_uid'] = $pid_map[$_p['quotepid']]['uid'] ?? 0;
				}
			}
		}
		unset($_p);

		// 分离 level-1 主评论和 level-2 回复
		$main_postlist = array();
		foreach($postlist as $_p) {
			if(!empty($_p['parent_pid']) && $_p['parent_pid'] > 0) {
				if(!isset($reply_map[$_p['parent_pid']])) {
					$reply_map[$_p['parent_pid']] = array();
				}
				$reply_map[$_p['parent_pid']][] = $_p;
			} else {
				$main_postlist[] = $_p;
			}
		}
		$postlist = $main_postlist;
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
	
	$pagination = pagination(url("thread-$tid-{page}$keywordurl"), $thread['posts'] + 1, $page, $pagesize);
	
	$header['title'] = $thread['subject'].'-'.$forum['name'].'-'.$conf['sitename']; 
	//$header['mobile_title'] = lang('thread_detail');
	$header['mobile_title'] = $forum['name'];;
	$header['mobile_link'] = url("forum-$fid");
	$header['keywords'] = ''; 
	$header['description'] = $thread['subject'];
	$_SESSION['fid'] = $fid;
	
	
	
	// hook thread_info_end.php

	// === 右侧边栏数据准备 ===

	// 作者完整数据
	$author = user_read($thread['uid']);
	$author_group = isset($grouplist[$author['gid']]) ? $grouplist[$author['gid']] : group_read($author['gid']);

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
				if(isset($_rt['audit_status']) && $_rt['audit_status'] == 0) {
					unset($author_recent_threads[$_rt_key]);
				}
			}
		}
		foreach($author_recent_threads as &$_rt) {
			$_rt['user_avatar_url'] = $author['avatar_url'];
			$_rt['username'] = $author['username'];
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

	// 帖子完整URL（用于二维码）
	$thread_url = http_url_path() . url("thread-$tid");

	include _include(APP_PATH.'view/htm/thread.htm');
}

// hook thread_end.php

?>