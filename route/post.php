<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

user_login_check();

// hook post_start.php

if($action == 'create') {
	
	$tid = param(2, 0);
	$quick = param(3, 0);
	// quotepid 优先读 URL 路径段（兼容旧格式），fallback 读 query 参数（高级回复按钮跳转时携带）
	$quotepid = param(4, 0) ?: param('quotepid', 0);
	
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

	// 回帖前检查封禁状态（管理员组 gid=1,2 豁免）
	if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
	if(!in_array($gid, UserBanService::ADMIN_GIDS, true)) {
		$ban_check = UserBanService::checkBanByScene($uid, 'post');
		// hook user_ban_check.php
		if(!$ban_check['allowed']) {
			message(-1, $ban_check['message']);
		}
	}

	if(($thread['closed'] || (isset($thread['audit_status']) && $thread['audit_status'] != 1)) && ($gid == 0 || $gid > 5)) {
		message(-1, lang('thread_has_already_closed'));
	}
	
	// hook post_get_post.php
	
	if($method == 'GET') {
		
		// hook post_get_start.php
		
		$header['title'] = lang('post_create');
		$header['mobile_title'] = lang('post_create');
		$header['mobile_link'] = thread_url($tid);
		// SEO: 回复创建页禁止索引
		$header['noindex'] = TRUE;
		$header['canonical'] = absolute_url(thread_url($tid));

		include _include(APP_PATH.'view/htm/post.htm');
		
	} else {
		
		CsrfService::check();

		// hook post_post_start.php

		$message = param('message', '', FALSE);

		// ===== 回帖验证码检查 =====
		include_once APP_PATH . 'lib/security/CaptchaService.php';
		if (CaptchaService::is_enabled('reply', $gid)) {
			$captcha_input = param('captcha');
			if (empty($captcha_input)) {
				message(-1001, lang('please_input_captcha'));
			}
			if (!CaptchaService::verify('reply', $captcha_input, $gid)) {
				message(-1001, lang('captcha_error'));
			}
		}

		// ===== 内容敏感词检查（直接拦截，提示具体违规词） =====
	include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
	$content_check = SensitiveWordFilter::content_check($message, SensitiveWordFilter::TYPE_SENSITIVE);
	if (!$content_check['pass']) {
		$hit_words = implode('、', $content_check['matched_keywords']);
		message('message', lang('post_contains_sensitive_word_with_words', array('words'=>$hit_words)));
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

		// ===== 回帖窗口限流（5 分钟内最多 10 帖，防批量刷帖） =====
		if ($uid > 0) {
			include_once APP_PATH . 'lib/RateLimitService.php';
			$_post_rl = new RateLimitService(10, 300);
			$_post_rl_key = 'post_reply_' . $uid;
			if (!$_post_rl->check($_post_rl_key)) {
				$_wait = $_post_rl->getRetryAfter($_post_rl_key);
				message(-1, lang('post_reply_rate_limited', array('seconds'=>$_wait)));
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

		// ===== 积分预检查：扣减类操作余额不足则拒绝 =====
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		$creditsCheck = CreditsRuleService::applyRule('reply_post', $uid, $fid, true);
		if(!$creditsCheck['ok']) {
			message(-1, $creditsCheck['message']);
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

	// 管理员回复待审评论时，自动通过被引用评论
	// 避免引用块（blockquote）在帖子详情页泄露待审内容
	// 管理员回复即代表已审阅，符合论坛惯例
	if($quotepid > 0 && ($gid == 1 || $gid == 2) && !empty($quotepost) && isset($quotepost['audit_status']) && $quotepost['audit_status'] == 0) {
		AuditService::approve('post', intval($quotepid), intval($uid));
	}

	// 管理员回复待审帖子时，自动通过该帖子
	// 管理员在帖子详情页发表一级评论即代表已审阅该帖子，符合论坛惯例
	if(($gid == 1 || $gid == 2) && isset($thread['audit_status']) && $thread['audit_status'] == 0) {
		AuditService::approve('thread', intval($tid), intval($uid));
	}

	if($quotepid > 0) {
		// 复用行 148 的 post__read 结果，避免重复查询
		// 此处仅需 $quotepost['uid']，该字段不受 AuditService::approve 影响
		if(!empty($quotepost) && $quotepost['uid'] != $uid) {
				// 回复评论：通知被回复者，content 存新回复内容
				// 需要审核时延迟通知，审核通过后由 AuditService::approve() 发送
				if(!$need_reply_audit) {
					$_reply_content = mb_substr(strip_tags($message), 0, 500);
					notify_create($quotepost['uid'], $uid, 'reply', $tid, $pid, $_reply_content, array(
						'reply_to_uid' => $quotepost['uid'],
						'parent_pid' => $quotepid,
					));
				}
			}
		} elseif($thread['uid'] != $uid) {
			// 一级评论：通知帖子作者，使用 comment 类型区分
			// 需要审核时延迟通知，审核通过后由 AuditService::approve() 发送
			if(!$need_reply_audit) {
				$_reply_content = mb_substr(strip_tags($message), 0, 500);
				notify_create($thread['uid'], $uid, 'comment', $tid, $pid, $_reply_content, array(
					'reply_to_uid' => $thread['uid'],
					'parent_pid' => $thread['firstpid'],
				));
			}
		}

		// 解析 @提及（需要审核时延迟到审核通过后发送）
	// 合并富文本（data-id=UID）与旧版纯文本（@username）两种来源，
	// 批量查询用户名对应的 UID，收集通知记录后一次性 INSERT，消除 N+1 查询和 N+1 INSERT
	if(!$need_reply_audit) {
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
					'pid' => $pid,
					'content' => '在回复中提及了你',
					'create_date' => $time,
					'is_read' => 0,
				);
			}
			notify_create_batch($_mention_records);
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
			// 批量查询引用链，避免循环内逐条 post__read 导致 N+1 查询
			// post_find_quote_chain 使用 static 缓存并防止循环引用
			$quote_chain = post_find_quote_chain($quotepid, 10);

			// 查找 level-1 父评论：沿链向上第一个 quotepid 为空或等于 firstpid 的 post
			// 链按沿链向上顺序排列（从 $quotepid 到最上层），foreach 直接找第一个满足条件的
			$post['parent_pid'] = 0;
			foreach($quote_chain as $qpid => $quoted) {
				if(empty($quoted['quotepid']) || $quoted['quotepid'] == $thread['firstpid']) {
					$post['parent_pid'] = $quoted['pid'];
					break;
				}
			}

			// 设置 reply_to_username（直接回复的对象，即 $quotepid 对应的 post）
			// 复用引用链查询结果，避免再次 post__read 重复查询
			if(isset($quote_chain[$quotepid])) {
				$_direct_quoted = $quote_chain[$quotepid];
				$_direct_user = user_read_cache($_direct_quoted['uid']);
				$post['reply_to_username'] = isset($_direct_user['display_name']) ? $_direct_user['display_name'] : ($_direct_user['username'] ?? '');
				$post['reply_to_uid'] = $_direct_quoted['uid'];
			}
		}

		$allowpost = forum_access_user($fid, $gid, 'allowpost');
		$allowupdate = forum_access_mod($fid, $gid, 'allowupdate');
		$allowdelete = forum_access_mod($fid, $gid, 'allowdelete');

		// hook post_post_end.php

		// 积分规则：发回复获得积分，被回复者获得积分
		// 需要审核时：扣除部分立即执行，奖励部分延迟到审核通过后发放
		// 不需要审核时：正常执行全部积分变动
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		if($need_reply_audit) {
			$replyCreditsResult = CreditsRuleService::applyRuleDeductOnly('reply_post', $uid, $fid);
		} else {
			$replyCreditsResult = CreditsRuleService::applyRule('reply_post', $uid, $fid);
			if(!empty($thread['uid']) && $thread['uid'] != $uid) {
				CreditsRuleService::applyRule('be_commented', intval($thread['uid']), $fid);
			}
		}

		// 审核通知：如果回帖进入审核，通知作者
		if($need_reply_audit && !empty($pid)) {
			notify_create($uid, 0, 'audit_pending', $tid, $pid, lang('notify_audit_post_pending', array('subject' => mb_substr($thread['subject'], 0, 30))));
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
			<div class="d-flex mb-2 <?php echo $post['classname'];?>" data-pid="<?php echo $post['pid'];?>" data-uid="<?php echo $post['uid'];?>" data-parent-pid="<?php echo intval($post['parent_pid']);?>">
				<div class="position-relative me-2 flex-shrink-0">
					<a href="<?php echo user_url($post['uid']);?>" tabindex="-1">
						<img class="avatar-sm" src="<?php echo $post['user_avatar_url'];?>" alt="" onerror="this.onerror=null;this.src='<?php echo default_avatar_url();?>'">
					</a>
				</div>
				<div class="flex-fill" style="min-width:0">
					<div class="small text-body-secondary">
						<span class="username">
							<a href="<?php echo user_url($post['uid']);?>" class="text-body-secondary fw-semibold small"><?php echo $post['username'];?></a>
						</span>
						<?php if(!empty($thread) && $post['uid'] == $thread['uid']): ?>
						<span class="badge bg-primary ms-1" style="font-size:0.6em">作者</span>
						<?php endif; ?>
						<?php if(!empty($post['reply_to_username'])) { ?>
						<span class="text-body-secondary small"> 回复@<a href="<?php echo user_url(intval($post['reply_to_uid']));?>" class="text-primary"><?php echo esc_html($post['reply_to_username']);?></a>：</span>
						<?php } else { ?>
						<span class="text-body-secondary small">：</span>
						<?php } ?>
					</div>
					<div class="small"><?php echo preg_replace('#<blockquote\s+class="blockquote"[^>]*>.*?</blockquote>#is', '', $post['message_fmt']);?></div>
					<div class="d-flex justify-content-between align-items-center mt-1">
					<div class="d-flex align-items-center">
						<span class="date text-body-secondary" style="font-size:0.75em"><?php echo $post['create_date_fmt'];?></span>
						<span class="post-like-btn cursor-pointer text-body-secondary ms-2" hx-post="<?php echo !empty($post['is_liked']) ? thread_unlike_url($tid, $post['pid']) : thread_like_url($tid, $post['pid']);?>" hx-vals='{"_ctx":"reply"}' hx-target="this" hx-swap="outerHTML" hx-ext="hx-optimistic" hx-optimistic style="font-size:0.8em">
							<i class="ti <?php echo !empty($post['is_liked']) ? 'ti-heart-filled text-danger' : 'ti-heart';?>"></i>
							<span class="like-count"><?php echo intval($post['likes']);?></span>
						</span>
					</div>
					<div class="d-flex align-items-center gap-2">
						<?php if($allowpost) { ?>
						<a href="javascript:void(0)" data-tid="<?php echo $post['tid'];?>" data-pid="<?php echo $post['pid'];?>" data-username="<?php echo $post['username'];?>" class="text-body-secondary post_reply" style="font-size:0.8em"><i class="ti ti-message-2"></i> 回复</a>
						<?php } ?>
						<?php if($allowupdate || $post['allowupdate']) { ?>
						<a href="<?php echo post_update_url($post['pid']);?>" class="text-body-secondary post_update" hx-boost="false" style="font-size:0.8em"><i class="ti ti-pencil"></i></a>
						<?php } ?>
						<?php if($allowdelete || $post['allowdelete']) { ?>
						<a data-href="<?php echo post_delete_url($post['pid']);?>" data-uid="<?php echo intval($post['uid']);?>" data-confirm-text="<?php echo lang('confirm_delete');?>" href="javascript:void(0);" class="text-body-secondary post_delete _confirm" style="font-size:0.8em"><i class="ti ti-trash"></i></a>
						<?php } ?>
					</div>
				</div>
				</div>
			</div>
			<?php
			$post_html = ob_get_clean();
		}

		$audit_message = $need_reply_audit ? '回复已提交，等待审核' : lang('create_post_sucessfully');

		// 积分变动描述
		$change_desc = '';
		if(!empty($replyCreditsResult['ok']) && !empty($replyCreditsResult['change_desc'])) {
			$change_desc = $replyCreditsResult['change_desc'];
		}
		// 每日上限达到：提醒用户本次不发放/扣除积分
		if(!empty($replyCreditsResult['daily_limit_reached'])) {
			$change_desc = $replyCreditsResult['message'] . '，本次回复不发放/扣除积分';
		}

		// htmx 请求：区分快速回复（帖子详情页内）和高级回复（独立页面）
		if(is_htmx_request()) {
			$hx_target = isset($_SERVER['HTTP_HX_TARGET']) ? $_SERVER['HTTP_HX_TARGET'] : '';
			// htmx 4 的 HX-Target 头格式为 "tagName#id"（如 "UL#postlist"），兼容手动 fetch 发送的 "#postlist"
			if($hx_target && strpos($hx_target, '#') !== false) {
				$hx_target = substr($hx_target, strrpos($hx_target, '#'));
			}

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
				// 积分变动提示（OOB toast）
			if($change_desc) {
				echo '<div id="credits-toast" hx-swap-oob="true" data-change-desc="' . htmlspecialchars($change_desc, ENT_QUOTES, 'UTF-8') . '"></div>';
			}
			// hook post_create_htmx_reply_end.php
			exit;
		}

			// 高级回复（独立页面）：走 message() 的 HX-Trigger 跳转逻辑
			if($need_reply_audit) {
				message(0, $audit_message, array('redirect_url' => forum_url($fid), 'change_desc' => $change_desc));
			} else {
				message(0, $audit_message, array('redirect_url' => thread_url($tid), 'change_desc' => $change_desc));
			}
		}

		// 需要审核时不返回回帖HTML（待审回帖不直接显示）
		if($need_reply_audit) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array(
				'code' => 0,
				'message' => $audit_message,
				'redirect_url' => thread_url($tid),
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
			'redirect_url' => thread_url($tid),
			'data' => array(
				'pid' => $pid,
				'tid' => $tid,
				'html' => $post_html,
				'floor' => $post['floor'],
				'parent_pid' => intval($post['parent_pid']),
				'change_desc' => $change_desc,
			)
		), JSON_UNESCAPED_UNICODE);
		exit;
	
	}
	
} elseif($action == 'update') {

	$pid = param(2, 0);

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
	// 游客不能编辑帖子（防止 uid=0 的身份混淆）
	empty($uid) AND message(-1, lang('have_no_privilege_to_update'));
	$allowupdate = forum_access_mod($fid, $gid, 'allowupdate');
	!$allowupdate AND !$post['allowupdate'] AND message(-1, lang('have_no_privilege_to_update'));

	// 编辑前检查封禁状态（管理员组 gid=1,2 豁免）
	if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
	if(!in_array($gid, UserBanService::ADMIN_GIDS, true)) {
		$ban_check = UserBanService::checkBanByScene($uid, 'post');
		// hook user_ban_check.php
		if(!$ban_check['allowed']) {
			message(-1, $ban_check['message']);
		}
	}

	// 引入审核服务
	include_once APP_PATH . 'lib/security/AuditService.php';
	// 判断当前内容是否处于驳回状态（首帖看 thread 表，非首帖看 post 表）
	if($isfirst) {
		$_is_rejected = (isset($thread['audit_status']) && intval($thread['audit_status']) === AuditService::STATUS_REJECTED);
	} else {
		$_is_rejected = (isset($post['audit_status']) && intval($post['audit_status']) === AuditService::STATUS_REJECTED);
	}
	// 驳回状态下，作者编辑不受 closed/audit_status 限制，但仍需检查重提次数
	if(!$_is_rejected) {
		!$allowupdate AND ($thread['closed'] || (isset($thread['audit_status']) && $thread['audit_status'] != 1)) AND message(-1, lang('thread_has_already_closed'));
	} else {
		// 驳回状态：仅作者本人可编辑
		if($post['uid'] != $uid && !$allowupdate) {
			message(-1, lang('have_no_privilege_to_update'));
		}
		// 检查重新提交次数（首帖看 thread，非首帖看 post）
		$_check_target = $isfirst ? $thread : $post;
		$_check = AuditService::can_edit_rejected($isfirst ? 'thread' : 'post', $_check_target);
		if(!$_check['can_edit']) {
			message(-1, $_check['reason']);
		}
	}

	// 非版主：检查安全配置（编辑开关 + 编辑时限），与模板编辑按钮显示逻辑一致
	// 被驳回的帖子编辑不受时间限制（作者可能过了很久才看到驳回通知）
	if(!$allowupdate && !$_is_rejected) {
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$allow_edit = SecurityConfigService::get('security_allow_edit', 1);
		if (empty($allow_edit)) {
			message(-1, '当前不允许修改帖子');
		}
		$edit_time_limit = SecurityConfigService::get('security_edit_time_limit', 60);
		if ($edit_time_limit > 0) {
			$elapsed = $time - $post['create_date'];
			$elapsed_minutes = floor($elapsed / 60);
			if ($elapsed_minutes > $edit_time_limit) {
				message(-1, '帖子修改时间已过，仅允许发布后' . $edit_time_limit . '分钟内修改');
			}
		}
	}
	
	// hook post_update_get_post.php
	
	if($method == 'GET') {
		
		// hook post_update_get_start.php
		
		$forumlist_allowthread = forum_list_access_filter($forumlist, $gid, 'allowthread');
		// 发帖页版块过滤（编辑场景：当前帖子所在版块 fid 始终保留，避免从下拉框消失导致 fid 被误改）
		$forumlist_allowthread = forum_list_post_filter($forumlist_allowthread, $gid, $fid);
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
		
		$subject = trim(strip_tags(param('subject', '', FALSE)));
		$message = param('message', '', FALSE);
		$doctype = param('doctype', 0);
		
		// hook post_update_post_start.php

		empty($message) AND message('message', lang('please_input_message'));
		mb_strlen($message, 'UTF-8') > 2048000 AND message('message', lang('message_too_long'));

		// 编辑帖子时同样进行内容敏感词拦截
		include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
		$update_check = SensitiveWordFilter::content_check($message, SensitiveWordFilter::TYPE_SENSITIVE);
		if (!$update_check['pass']) {
			$hit_words = implode('、', $update_check['matched_keywords']);
			message('message', lang('post_contains_sensitive_word_with_words', array('words'=>$hit_words)));
		}
		
		$arr = array();
		if($isfirst) {
			$newfid = param('fid');
			$forum = forum_read($newfid);
			empty($forum) AND message('fid', lang('forum_not_exists'));
			
			if($fid != $newfid) {
		!forum_access_user($fid, $gid, 'allowthread') AND message(-1, lang('user_group_insufficient_privilege'));
		// 检查目标版块的发帖权限，防止移动到无权限的版块
		!forum_access_user($newfid, $gid, 'allowthread') AND message(-1, lang('user_group_insufficient_privilege'));
		// 发帖版块白名单校验（防止通过移帖绕过 post_forum_ids 限制到受限版块）
		if(!forum_can_post($newfid, $gid)) {
			message(-1, lang('user_group_insufficient_privilege'));
		}
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

	// 被驳回内容编辑后重新提交审核
	if($_is_rejected) {
		// 首帖的审核状态存在 thread 表，非首帖存在 post 表
		$_resubmit_type = $isfirst ? 'thread' : 'post';
		$_resubmit_id = $isfirst ? $tid : $pid;
		$_resubmit = AuditService::resubmit($_resubmit_type, $_resubmit_id, $uid);
		if(!$_resubmit['ok']) {
			message(-1, $_resubmit['message']);
		}
		// 首帖还需同步 post 表的 audit_status
		if($isfirst) {
			post_update($pid, array('audit_status' => AuditService::STATUS_PENDING));
		}
	}

	// hook post_update_post_end.php

	message(0, $_is_rejected ? lang('resubmit_success') : lang('update_successfully'), array('redirect_url' => thread_url($tid)));
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

	// 调试日志：记录删除请求关键信息
	xn_log("post_delete request: pid=$pid, uid=$uid, gid=$gid, method=$method, uri=" . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), 'post_delete_error');

	$post = post_read($pid);

	// 调试日志：记录 post_read 结果
	xn_log("post_delete post_read result: pid=$pid, empty=" . (empty($post) ? '1' : '0'), 'post_delete_error');

	if(empty($post)) {
		// 软删除场景排查：post_read 对前台过滤已删除回帖，但删除请求可能重复提交
		$post_raw = post__read($pid);
		xn_log("post_delete post__read fallback: pid=$pid, exists=" . (!empty($post_raw) ? '1' : '0') . ", is_deleted=" . (isset($post_raw['is_deleted']) ? intval($post_raw['is_deleted']) : 'null'), 'post_delete_error');

		if(!empty($post_raw) && !empty($post_raw['is_deleted'])) {
			// 已软删除，直接返回成功，避免重复扣积分和404错误
			// post 表无 fid 字段，需通过 tid 读取 thread 获取 fid
			$_fallback_tid = intval($post_raw['tid']);
			$_fallback_thread = thread__read($_fallback_tid);
			$_fallback_fid = !empty($_fallback_thread) ? intval($_fallback_thread['fid']) : 0;
			xn_log("post_delete already soft deleted: pid=$pid, tid=$_fallback_tid, fid=$_fallback_fid", 'post_delete_error');
			message(0, lang('delete_successfully'), array('redirect_url' => $_fallback_fid ? forum_url($_fallback_fid) : './'));
		}

		error_page(404, lang('post_not_exists'));
	}

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
	!$allowdelete AND ($thread['closed'] || (isset($thread['audit_status']) && $thread['audit_status'] != 1)) AND message(-1, lang('thread_has_already_closed'));

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

	// 积分预检查：仅普通用户删除时检查余额，管理员/版主删除不拦截（即使作者积分不足也直接删除）
	$deleteCreditsEvent = $isfirst ? 'thread_delete' : 'reply_delete';
	$deleteCreditsUid = $isfirst ? intval($thread['uid']) : intval($post['uid']);
	if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
	if(!$allowdelete) {
		$deleteCreditsCheck = CreditsRuleService::applyRule($deleteCreditsEvent, $deleteCreditsUid, $fid, true);
		if(!$deleteCreditsCheck['ok']) {
			message(-1, $deleteCreditsCheck['message']);
		}
	}

	// 软删除配置检查
	$sec_soft_delete = SecurityConfigService::get('security_soft_delete', 1);
	xn_log("post_delete soft_delete config: pid=$pid, isfirst=$isfirst, sec_soft_delete=" . intval($sec_soft_delete), 'post_delete_error');
	if($sec_soft_delete) {
		if($isfirst) {
			$delete_result = thread_soft_delete($tid, $uid);
			xn_log("post_delete thread_soft_delete: tid=$tid, result=" . ($delete_result ? '1' : '0'), 'post_delete_error');
		} else {
			$delete_result = post_soft_delete($pid, $uid);
			xn_log("post_delete post_soft_delete: pid=$pid, result=" . ($delete_result ? '1' : '0'), 'post_delete_error');
		}
	} else {
		if($isfirst) {
			$delete_result = thread_delete($tid);
			xn_log("post_delete thread_delete: tid=$tid, result=" . ($delete_result ? '1' : '0'), 'post_delete_error');
		} else {
			$delete_result = post_delete($pid);
			xn_log("post_delete post_delete: pid=$pid, result=" . ($delete_result ? '1' : '0'), 'post_delete_error');
			//post_list_cache_delete($tid);
		}
	}

	// 调试：删除后读取数据库确认实际状态
	$post_after_delete = post__read($pid);
	xn_log("post_delete db state after: pid=$pid, exists=" . (!empty($post_after_delete) ? '1' : '0') . ", is_deleted=" . (isset($post_after_delete['is_deleted']) ? intval($post_after_delete['is_deleted']) : 'null'), 'post_delete_error');

	// hook post_delete_end.php

	// 积分规则：删除扣减积分
	$delete_change_desc = '';
	if($deleteCreditsUid > 0) {
		$deleteCreditsResult = CreditsRuleService::applyRule($deleteCreditsEvent, $deleteCreditsUid, $fid);
		if(!empty($deleteCreditsResult['ok']) && !empty($deleteCreditsResult['change_desc'])) {
			$delete_change_desc = $deleteCreditsResult['change_desc'];
		}
		// 每日上限达到：提醒用户本次不扣除积分
		if(!empty($deleteCreditsResult['daily_limit_reached'])) {
			$delete_change_desc = $deleteCreditsResult['message'] . '，本次删除不扣除积分';
		}
	}

	message(0, lang('delete_successfully'), array('redirect_url' => forum_url($fid), 'change_desc' => $delete_change_desc));

}

// hook post_end.php

?>