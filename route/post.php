<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

user_login_check();

// hook post_start.php

if($action == 'create') {
	
	$tid = param(2);
	$quick = param(3);
	$quotepid = param(4);
	
	$thread = thread_read($tid);
	if(empty($thread)) {
		error_page(404, lang('thread_not_exists'));
	}
	
	$fid = $thread['fid'];
	
	$forum = forum_read($fid);
	if(empty($forum)) {
		error_page(404, lang('forum_not_exists'));
	}
	
	$r = forum_access_user($fid, $gid, 'allowpost');
	if(!$r) {
		message(-1, lang('user_group_insufficient_privilege'));
	}
	
	if(($thread['closed'] || (isset($thread['audit_status']) && $thread['audit_status'] == 0)) && ($gid == 0 || $gid > 5)) {
		message(-1, lang('thread_has_already_closed'));
	}
	
	// hook post_get_post.php
	
	if($method == 'GET') {
		
		// hook post_get_start.php
		
		$header['title'] = lang('post_create');
		$header['mobile_title'] = lang('post_create');
		$header['mobile_link'] = url("thread-$tid");

		include _include(APP_PATH.'view/htm/post.htm');
		
	} else {
		
		CsrfService::check();

		// hook post_post_start.php

		$message = param('message', '', FALSE);

		// ===== 回帖验证码检查 =====
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

		// ===== 敏感词过滤 =====
		include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
		$filter_result = SensitiveWordFilter::content_filter($message);
		if (!$filter_result['pass']) {
			message(-1, '内容包含敏感词：' . implode('、', array_slice($filter_result['matched_keywords'], 0, 5)) . '，请修改后重新发布');
		}

		// ===== 内容安全审核 =====
		include_once APP_PATH . 'lib/security/ContentModerationService.php';
		$moderation_result = ContentModerationService::moderate('post', $message, 'create');
		if ($moderation_result === 'block') {
			message(-1, '内容审核未通过，请修改后重新发布');
		}

		// ===== 回帖间隔检查 =====
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$post_reply_interval = SecurityConfigService::get('security_post_reply_interval', 30);
		if ($post_reply_interval > 0 && $uid > 0) {
			$last_post = db_find_one('post', array('uid' => $uid), array('pid' => -1), array('create_date'));
			if (!empty($last_post)) {
				$elapsed = $time - $last_post['create_date'];
				if ($elapsed < $post_reply_interval) {
					$remaining = $post_reply_interval - $elapsed;
					message(-1002, lang('post_reply_interval_short', array('seconds'=>$remaining)), array('wait'=>$remaining));
				}
			}
		}

		// ===== 同主题连续回复间隔检查 =====
		$same_thread_interval = SecurityConfigService::get('security_same_thread_reply_interval', 0);
		if ($same_thread_interval > 0 && $uid > 0) {
			if ($tid > 0) {
				$last_same_thread_post = db_find_one('post', array('uid' => $uid, 'tid' => $tid), array('pid' => -1), array('create_date'));
				if (!empty($last_same_thread_post)) {
					$elapsed = $time - $last_same_thread_post['create_date'];
					if ($elapsed < $same_thread_interval) {
						$remaining = $same_thread_interval - $elapsed;
						message(-1002, lang('post_same_thread_frequent', array('seconds'=>$remaining)), array('wait'=>$remaining));
					}
				}
			}
		}

		// ===== 回帖字数检查 =====
		$reply_min_length = SecurityConfigService::get('security_reply_min_length', 5);
		$post_max_length = SecurityConfigService::get('security_post_max_length', 50000);
		$message_len = mb_strlen($message, 'UTF-8');
		if ($reply_min_length > 0 && $message_len < $reply_min_length) {
			message(-1, '回复内容太短，至少需要' . $reply_min_length . '个字');
		}
		if ($post_max_length > 0 && $message_len > $post_max_length) {
			message(-1, '回复内容太长，最多允许' . $post_max_length . '个字');
		}

		// ===== 内容审核服务触发审核 =====
		if ($moderation_result === 'review') {
			$_SESSION['security_post_needs_audit'] = true;
		}

		if(empty($message)) {
			message('message', lang('please_input_message'));
		}
		
		$doctype = param('doctype', 0);
		if(xn_strlen($message) > 2028000) {
			message('message', lang('message_too_long'));
		}
		
		$thread['top'] > 0 AND thread_top_cache_delete();
		
		$quotepid = param('quotepid', 0);
		$quotepost = post__read($quotepid);
		(!$quotepost || $quotepost['tid'] != $tid) AND $quotepid = 0;
		
		$post = array(
			'tid'=>$tid,
			'uid'=>$uid,
			'create_date'=>$time,
			'userip'=>$longip,
			'isfirst'=>0,
			'doctype'=>$doctype,
			'quotepid'=>$quotepid,
			'message'=>$message,
		);

		// 检查回帖审核权限（AuditService 内部通过 PermissionService 统一检查用户组权限）
		if(!class_exists('AuditService')) include_once APP_PATH . 'lib/security/AuditService.php';
		$need_reply_audit = AuditService::need_post_audit($fid, $gid, $message);
		// 插件可能通过 SESSION 标记需要审核（如内容审核服务等）
		if(!empty($_SESSION['security_post_needs_audit'])) {
			$need_reply_audit = true;
			unset($_SESSION['security_post_needs_audit']);
		}
		$audit_status = $need_reply_audit ? 0 : 1;
		$post['audit_status'] = $audit_status;

		$pid = post_create($post, $fid, $gid);
		if(empty($pid)) {
			message(-1, lang('create_post_failed'));
		}

		if($quotepid > 0) {
			$quotepost = post__read($quotepid);
			if(!empty($quotepost) && $quotepost['uid'] != $uid) {
				$_reply_content = mb_substr(strip_tags($message), 0, 120);
				notify_create($quotepost['uid'], $uid, 'reply', $tid, $pid, $_reply_content);
			}
		} elseif($thread['uid'] != $uid) {
			// 普通评论通知帖子作者（非引用回复时）
			$_reply_content = mb_substr(strip_tags($message), 0, 120);
			notify_create($thread['uid'], $uid, 'reply', $tid, $pid, $_reply_content);
		}

		// 解析@提及
		if(!empty($message)) {
			preg_match_all('/@(\S+)/', $message, $matches);
			if(!empty($matches[1])) {
				$mentioned_usernames = array_unique($matches[1]);
				foreach($mentioned_usernames as $musername) {
					$muser = user_read_by_username($musername);
					if(!empty($muser) && $muser['uid'] != $uid) {
						notify_create($muser['uid'], $uid, 'mention', $tid, $pid, '在回复中提及了你');
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
				notify_create($mentionUid, $uid, 'mention', $tid, $pid, '在回复中提及了你');
			}
		}

		// thread_top_create($fid, $tid);

		$post = post_read($pid);
		$post['floor'] = $thread['posts'] + 2;

		// 计算两级评论结构信息
		$post['parent_pid'] = 0;
		$post['reply_to_username'] = '';
		$post['reply_to_uid'] = 0;
		if(!empty($quotepid) && $quotepid != $thread['firstpid']) {
			// 递归查找 level-1 父评论
			$_current_pid = $quotepid;
			$_depth = 0;
			while($_depth < 10) {
				$_quoted = post__read($_current_pid);
				if(empty($_quoted)) break;
				if(empty($_quoted['quotepid']) || $_quoted['quotepid'] == $thread['firstpid']) {
					// 找到 level-1 父评论
					$post['parent_pid'] = $_quoted['pid'];
					break;
				}
				$_current_pid = $_quoted['quotepid'];
				$_depth++;
			}
			// 设置 reply_to_username（直接回复的对象）
			$_direct_quoted = post__read($quotepid);
			if(!empty($_direct_quoted)) {
				$_direct_user = user_read_cache($_direct_quoted['uid']);
				$post['reply_to_username'] = $_direct_user['username'] ?? '';
				$post['reply_to_uid'] = $_direct_quoted['uid'];
			}
		}

		$allowpost = forum_access_user($fid, $gid, 'allowpost');
		$allowupdate = forum_access_mod($fid, $gid, 'allowupdate');
		$allowdelete = forum_access_mod($fid, $gid, 'allowdelete');

		// hook post_post_end.php

		// 积分规则：发回复获得积分，被回复者获得积分
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		CreditsRuleService::applyRule('reply_post', $uid, $fid);
		if(!empty($thread['uid']) && $thread['uid'] != $uid) {
			CreditsRuleService::applyRule('be_commented', intval($thread['uid']), $fid);
		}

		// 审核通知：如果回帖进入审核，通知作者
		if(!empty($_SESSION['security_post_needs_audit'])) {
			unset($_SESSION['security_post_needs_audit']);
			if(!empty($pid)) {
				notify_create($uid, 0, 'audit_pending', $tid, $pid, lang('notify_audit_post_pending', array('subject' => mb_substr($thread['subject'], 0, 30))));
			}
		}

		// 统一返回 JSON，包含渲染后的 HTML 片段
		$post_html = '';
		if($post['parent_pid'] > 0) {
			// Level-2 回复：只渲染回复部分
			$reply_map = array($post['parent_pid'] => array($post));
			$postlist = array();
		} else {
			// Level-1 主评论
			$reply_map = array();
			$postlist = array($post);
		}
		ob_start();
		include _include(APP_PATH.'view/htm/post_list.inc.htm');
		$post_html = ob_get_clean();

		// Level-2 回复需要单独渲染回复 HTML（模板中 postlist 为空时不输出内容）
		if($post['parent_pid'] > 0) {
			ob_start();
			?>
			<div class="d-flex mb-2 <?php echo $post['classname'];?>" data-pid="<?php echo $post['pid'];?>" data-uid="<?php echo $post['uid'];?>">
				<div class="position-relative me-2 flex-shrink-0">
					<a href="<?php echo url("user-$post[uid]");?>" tabindex="-1">
						<img class="avatar-sm" src="<?php echo $post['user_avatar_url'];?>" alt="" onerror="this.src='/view/img/avatar.png'">
					</a>
				</div>
				<div class="flex-fill" style="min-width:0">
					<div class="small text-body-secondary">
						<span class="username">
							<a href="<?php echo url("user-$post[uid]");?>" class="text-body-secondary fw-semibold small"><?php echo $post['username'];?></a>
						</span>
						<?php if(!empty($post['reply_to_username'])) { ?>
						<span class="text-body-secondary small"> 回复@<a href="<?php echo url("user-" . intval($post['reply_to_uid']));?>" class="text-primary"><?php echo esc_html($post['reply_to_username']);?></a>：</span>
						<?php } else { ?>
						<span class="text-body-secondary small">：</span>
						<?php } ?>
					</div>
					<div class="small"><?php echo preg_replace('#<blockquote\s+class="blockquote">.*?</blockquote>#is', '', $post['message_fmt']);?></div>
					<div class="d-flex justify-content-between align-items-center mt-1">
						<span class="date text-body-secondary" style="font-size:0.75em"><?php echo $post['create_date_fmt'];?></span>
						<div class="d-flex align-items-center gap-2">
							<?php if($allowpost) { ?>
							<a href="javascript:void(0)" data-tid="<?php echo $post['tid'];?>" data-pid="<?php echo $post['pid'];?>" data-username="<?php echo $post['username'];?>" class="text-body-secondary post_reply" style="font-size:0.8em"><i class="ti ti-message-2"></i> 回复</a>
							<?php } ?>
							<?php if($allowupdate || $post['allowupdate']) { ?>
							<a href="<?php echo url("post-update-$post[pid]");?>" class="text-body-secondary post_update" hx-boost="false" style="font-size:0.8em"><i class="ti ti-pencil"></i></a>
							<?php } ?>
							<?php if($allowdelete || $post['allowdelete']) { ?>
							<a data-href="<?php echo url("post-delete-$post[pid]");?>" data-confirm-text="<?php echo lang('confirm_delete');?>" href="javascript:void(0);" class="text-body-secondary post_delete _confirm" style="font-size:0.8em"><i class="ti ti-trash"></i></a>
							<?php } ?>
							<span class="post-like-btn cursor-pointer text-body-secondary" hx-post="<?php echo url('thread-like-'.$tid.'-'.$post['pid']);?>" hx-vals='{"_ctx":"reply"}' hx-target="this" hx-swap="outerHTML" hx-ext="hx-optimistic" hx-optimistic style="font-size:0.8em">
								<i class="ti <?php echo !empty($post['is_liked']) ? 'ti-heart-filled text-danger' : 'ti-heart';?>"></i>
								<span class="like-count"><?php echo intval($post['likes']);?></span>
							</span>
						</div>
					</div>
				</div>
			</div>
			<?php
			$post_html = ob_get_clean();
		}

		$audit_message = $need_reply_audit ? '回复已提交，等待审核' : lang('create_post_sucessfully');

		// htmx 请求：区分快速回复（帖子详情页内）和高级回复（独立页面）
		if(is_htmx_request()) {
			$hx_target = isset($_SERVER['HTTP_HX_TARGET']) ? $_SERVER['HTTP_HX_TARGET'] : '';

			// 快速回复：target 为 #postlist，返回 HTML 片段 + OOB 更新
			if($hx_target === '#postlist') {
				header('Content-Type: text/html; charset=utf-8');

				// 需要审核时不返回回帖HTML，仅返回提示 + OOB 更新
				if($need_reply_audit) {
					echo '<div class="alert alert-warning py-2 small mb-2">' . htmlspecialchars($audit_message, ENT_QUOTES, 'UTF-8') . '</div>';
					exit;
				}

				// 返回新回帖 HTML + OOB 更新评论数和楼层
				$new_posts_count = $thread['posts'] + 1;
				$new_floor = $post['floor'] + 1;

				echo $post_html;
				echo '<span class="posts" id="posts-count" hx-swap-oob="true">' . intval($new_posts_count) . '</span>';
				echo '<span id="newfloor" hx-swap-oob="true">' . intval($new_floor) . '</span>';
				exit;
			}

			// 高级回复（独立页面）：走 message() 的 HX-Trigger 跳转逻辑
			if($need_reply_audit) {
				message(0, $audit_message, array('redirect_url' => url("forum-$fid")));
			} else {
				message(0, $audit_message, array('redirect_url' => url("thread-$tid")));
			}
		}

		// 需要审核时不返回回帖HTML（待审回帖不直接显示）
		if($need_reply_audit) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array(
				'code' => 0,
				'message' => $audit_message,
				'redirect_url' => url("thread-$tid"),
				'data' => array(
					'pid' => $pid,
					'tid' => $tid,
					'html' => '',
					'floor' => $post['floor'],
					'parent_pid' => intval($post['parent_pid']),
					'need_audit' => true,
				)
			), JSON_UNESCAPED_UNICODE);
			exit;
		}

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array(
			'code' => 0,
			'message' => $audit_message,
			'redirect_url' => url("thread-$tid"),
			'data' => array(
				'pid' => $pid,
				'tid' => $tid,
				'html' => $post_html,
				'floor' => $post['floor'],
				'parent_pid' => intval($post['parent_pid']),
			)
		), JSON_UNESCAPED_UNICODE);
		exit;
	
	}
	
} elseif($action == 'update') {

	$pid = param(2);
	$post = post_read($pid);
	empty($post) AND error_page(404, lang('post_not_exists'));

	$tid = $post['tid'];
	$thread = thread_read($tid);
	empty($thread) AND error_page(404, lang('thread_not_exists'));

	$fid = $thread['fid'];
	$forum = forum_read($fid);
	empty($forum) AND error_page(404, lang('forum_not_exists'));

	$isfirst = $post['isfirst'];

	!forum_access_user($fid, $gid, 'allowpost') AND message(-1, lang('user_group_insufficient_privilege'));
	$allowupdate = forum_access_mod($fid, $gid, 'allowupdate');
	!$allowupdate AND !$post['allowupdate'] AND message(-1, lang('have_no_privilege_to_update'));
	!$allowupdate AND ($thread['closed'] || (isset($thread['audit_status']) && $thread['audit_status'] == 0)) AND message(-1, lang('thread_has_already_closed'));
	
	// hook post_update_get_post.php
	
	if($method == 'GET') {
		
		// hook post_update_get_start.php
		
		$forumlist_allowthread = forum_list_access_filter($forumlist, $gid, 'allowthread');
		$forumarr = xn_json_encode(arrlist_key_values($forumlist_allowthread, 'fid', 'name'));
		
		// 如果为数据库减肥，则 message 可能会被设置为空。
		// if lost weight for the database, set the message field empty.
		$post['message'] = htmlspecialchars($post['message'] ? $post['message'] : $post['message_fmt']);
		
		($uid != $post['uid']) AND $post['message'] = xn_html_safe($post['message']);
		
		$attachlist = $imagelist = $filelist = array();
		if($post['files']) {
			list($attachlist, $imagelist, $filelist) = attach_find_by_pid($pid);
		}
		
		// hook post_update_get_end.php
		
		include _include(APP_PATH.'view/htm/post.htm');
		
	} elseif($method == 'POST') {
		
		CsrfService::check();
		
		$subject = htmlspecialchars(param('subject', '', FALSE));
		$message = param('message', '', FALSE);
		$doctype = param('doctype', 0);
		
		// hook post_update_post_start.php

		// ===== 帖子修改权限检查 =====
		// 安全配置仅约束普通用户，版主/管理员不受限制
		if(!$allowupdate) {
			include_once APP_PATH . 'lib/security/SecurityConfigService.php';
			$allow_edit = SecurityConfigService::get('security_allow_edit', 1);
			if (empty($allow_edit)) {
				message(-1, '当前不允许修改帖子');
			}
			$edit_time_limit = SecurityConfigService::get('security_edit_time_limit', 60);
			if ($edit_time_limit > 0) {
				$_pid = param('pid', 0);
				if ($_pid > 0) {
					$_post = post_read($_pid);
					if (!empty($_post) && $_post['uid'] == $uid) {
						$elapsed = $time - $_post['create_date'];
						$elapsed_minutes = floor($elapsed / 60);
						if ($elapsed_minutes > $edit_time_limit) {
							message(-1, '帖子修改时间已过，仅允许发布后' . $edit_time_limit . '分钟内修改');
						}
					}
				}
			}
		}

		empty($message) AND message('message', lang('please_input_message'));
		mb_strlen($message, 'UTF-8') > 2048000 AND message('message', lang('message_too_long'));
		
		$arr = array();
		if($isfirst) {
			$newfid = param('fid');
			$forum = forum_read($newfid);
			empty($forum) AND message('fid', lang('forum_not_exists'));
			
			if($fid != $newfid) {
				!forum_access_user($fid, $gid, 'allowthread') AND message(-1, lang('user_group_insufficient_privilege'));
				$post['uid'] != $uid AND !forum_access_mod($fid, $gid, 'allowupdate') AND message(-1, lang('user_group_insufficient_privilege'));
				$arr['fid'] = $newfid;
			}
			if($subject != $thread['subject']) {
				mb_strlen($subject, 'UTF-8') > 80 AND message('subject', lang('subject_max_length', array('max'=>80)));
				$arr['subject'] = $subject;
			}
			$arr AND thread_update($tid, $arr) === FALSE AND message(-1, lang('update_thread_failed'));
		}
		$r = post_update($pid, array('doctype'=>$doctype, 'message'=>$message));
		$r === FALSE AND message(-1, lang('update_post_failed'));
		
		// hook post_update_post_end.php
		
		message(0, lang('update_successfully'), array('redirect_url' => url("thread-$tid")));
		//message(0, array('pid'=>$pid, 'subject'=>$subject, 'message'=>$message));
	}
	
} elseif($action == 'delete') {

	$pid = param(2, 0);
	
	// hook post_delete_start.php

	// ===== 帖子删除权限检查 =====
	// 安全配置仅约束普通用户，版主/管理员不受限制
	include_once APP_PATH . 'lib/security/SecurityConfigService.php';

	if($method != 'POST') message(-1, lang('method_error'));
	
	CsrfService::check();

	$post = post_read($pid);
	empty($post) AND error_page(404, lang('post_not_exists'));

	$tid = $post['tid'];
	$thread = thread_read($tid);
	empty($thread) AND error_page(404, lang('thread_not_exists'));

	$fid = $thread['fid'];
	$forum = forum_read($fid);
	empty($forum) AND error_page(404, lang('forum_not_exists'));

	$isfirst = $post['isfirst'];

	!forum_access_user($fid, $gid, 'allowpost') AND message(-1, lang('user_group_insufficient_privilege'));
	$allowdelete = forum_access_mod($fid, $gid, 'allowdelete');
	!$allowdelete AND !$post['allowdelete'] AND message(-1, lang('insufficient_delete_privilege'));
	!$allowdelete AND ($thread['closed'] || (isset($thread['audit_status']) && $thread['audit_status'] == 0)) AND message(-1, lang('thread_has_already_closed'));

	// 安全配置约束：仅对普通用户生效，版主/管理员不受限制
	if(!$allowdelete) {
		if($isfirst) {
			$sec_allow_delete = SecurityConfigService::get('security_allow_delete', 1);
			empty($sec_allow_delete) AND message(-1, '当前不允许删除帖子');
			$sec_delete_time_limit = SecurityConfigService::get('security_delete_time_limit', 0);
			if($sec_delete_time_limit > 0) {
				$elapsed = $time - $thread['create_date'];
				$elapsed_minutes = floor($elapsed / 60);
				if($elapsed_minutes > $sec_delete_time_limit) {
					message(-1, '帖子删除时间已过，仅允许发布后' . $sec_delete_time_limit . '分钟内删除');
				}
			}
		} else {
			$sec_allow_delete_reply = SecurityConfigService::get('security_allow_delete_reply', 1);
			empty($sec_allow_delete_reply) AND message(-1, '当前不允许删除回复');
		}
	}
	
	// hook post_delete_middle.php

	if($isfirst) {
		thread_delete($tid);
	} else {
		post_delete($pid);
		//post_list_cache_delete($tid);
	}
	
	// hook post_delete_end.php

	// 积分规则：删除扣减积分
	if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
	if($isfirst) {
		// 删主题：扣除帖子作者积分
		if(!empty($thread['uid'])) {
			CreditsRuleService::applyRule('thread_delete', intval($thread['uid']), $fid);
		}
	} else {
		// 删除回复：扣除回复者积分
		if(!empty($post['uid'])) {
			CreditsRuleService::applyRule('reply_delete', intval($post['uid']), $fid);
		}
	}

	message(0, lang('delete_successfully'), array('redirect_url' => url("forum-$fid")));

}

// hook post_end.php

?>