<?php

!defined('DEBUG') AND exit('Access Denied.');

include _include(APP_PATH.'model/modlog.func.php');

$action = param(1);



// hook mod_start.php

if($action == 'top') {
	
	if($method == 'GET') {
		
		include _include(APP_PATH.'view/htm/mod_top.htm');
		
	} else {
		
		CsrfService::check();
		
		$top = param('top', 0);
		
		$tidarr = param('tidarr', array(0));
		empty($tidarr) AND message(-1, lang('please_choose_thread'));
		$threadlist = thread_find_by_tids($tidarr);
			
		// hook mod_top_start.php
		
		// 批量置顶：先收集通过权限检查的 tid，再批量更新 thread_top 与 thread.top，消除 N+1 UPDATE/REPLACE
		$top_tids = array();
		$modlog_records = array();
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if($top == 3 && ($gid != 1 && $gid != 2)) {
				continue;
			}
			if(forum_access_mod($fid, $gid, 'allowtop')) {
				$top_tids[] = $tid;
				$modlog_records[] = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'top',
				);

				// hook mod_top_log_create_before.php
			}
		}
		// 批量更新置顶状态
		// 零命中（选中帖均不在管辖版块/无权限）时如实返回失败，避免误导操作者
		empty($top_tids) AND message(-1, lang('mod_no_accessible_thread'));
		!empty($top_tids) AND thread_top_change_batch($top_tids, $threadlist, $top);
		// 批量插入版主日志，消除 N+1 INSERT
		!empty($modlog_records) AND modlog_create_batch($modlog_records);

		// hook mod_top_end.php

		// 积分规则：置顶获得积分（批量预查规则后循环调用，消除规则 N+1 查询）
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		$_top_uid_fid_pairs = array();
		foreach($threadlist as $_thread) {
			if(!empty($_thread['uid'])) {
				$_top_uid_fid_pairs[] = array(intval($_thread['uid']), intval($_thread['fid']));
			}
		}
		CreditsRuleService::applyRuleBatch('thread_top', $_top_uid_fid_pairs);

		// hook mod_digest_end.php

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('set_completely'), 'redirect_url' => index_url()), JSON_UNESCAPED_UNICODE);
		exit;
	}

} elseif($action == 'close') {
		
	if($method == 'GET') {
		
		include _include(APP_PATH.'view/htm/mod_close.htm');
		
	} else {
		
		CsrfService::check();
		
		$close = param('close', 0);
		
		$tidarr = param('tidarr', array(0));
		empty($tidarr) AND message(-1, lang('please_choose_thread'));
		$threadlist = thread_find_by_tids($tidarr);
			
		// hook mod_close_start.php

		// 批量关闭：先收集通过权限检查的 tid，再批量更新，消除 N+1 查询
		$close_tids = array();
		$modlog_records = array();
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowtop')) {
				$close_tids[] = $tid;
				$modlog_records[] = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'close',
				);

				// hook mod_close_log_create_before.php
			}
		}
		// 批量更新关闭状态
		empty($close_tids) AND message(-1, lang('mod_no_accessible_thread'));
		if(!empty($close_tids)) {
			db_update('thread', array('tid'=>$close_tids), array('closed'=>$close));
			// db_update 绕过模型层，手动清理受影响版块的帖子列表缓存（避免 60s 短缓存不刷新）
			$_close_fid_set = array();
			foreach($threadlist as $_thread) {
				$_close_fid_set[intval($_thread['fid'])] = 1;
			}
			foreach(array_keys($_close_fid_set) as $_fid) {
				thread_forum_list_cache_delete($_fid);
			}
		}
		// 批量插入版主日志，消除 N+1 INSERT
		!empty($modlog_records) AND modlog_create_batch($modlog_records);

		// hook mod_close_end.php
		
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('set_completely'), 'redirect_url' => index_url()), JSON_UNESCAPED_UNICODE);
		exit;
	}
	
} elseif($action == 'delete') {
	
	if($method == 'GET') {
		
		include _include(APP_PATH.'view/htm/mod_delete.htm');
		
	} else {
		
		CsrfService::check();
		
		$tidarr = param('tidarr', array(0));
		empty($tidarr) AND message(-1, lang('please_choose_thread'));
		
		$threadlist = thread_find_by_tids($tidarr);
		
		// hook mod_delete_start.php

		// 批量删除：先收集通过权限检查的 tid 与日志记录，再一次性批量删除，消除 N+1 DELETE
		$delete_tids = array();
		$modlog_records = array();
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowdelete')) {
				$delete_tids[] = $tid;
				$modlog_records[] = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'delete',
				);
				// hook mod_delete_log_create_before.php
			}
		}
		// 软删除配置检查
		empty($delete_tids) AND message(-1, lang('mod_no_accessible_thread'));
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$sec_soft_delete = SecurityConfigService::get('security_soft_delete', 1);
		if($sec_soft_delete) {
			!empty($delete_tids) AND thread_soft_delete_batch($delete_tids, $uid);
		} else {
			// 批量删除主题（post/attach/mythread/favorite/thread 全部合并清理）
			!empty($delete_tids) AND thread_delete_batch($delete_tids);
		}
		// 批量插入版主日志，消除 N+1 INSERT
		!empty($modlog_records) AND modlog_create_batch($modlog_records);

		// hook mod_delete_end.php

		// 积分规则：删主题扣除作者积分（批量预查规则后循环调用，消除规则 N+1 查询）
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		$_delete_uid_fid_pairs = array();
		foreach($threadlist as $_thread) {
			if(!empty($_thread['uid'])) {
				$_delete_uid_fid_pairs[] = array(intval($_thread['uid']), intval($_thread['fid']));
			}
		}
		CreditsRuleService::applyRuleBatch('thread_delete', $_delete_uid_fid_pairs);

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('delete_completely'), 'redirect_url' => index_url()), JSON_UNESCAPED_UNICODE);
		exit;
	}
	
	
} elseif($action == 'move') {

	if($method == 'GET') {
		$forumarr = array();
		foreach(forum_list_cache() as $_f) {
			if(!empty($_f['type'])) continue; // 过滤分区，分区不能存放帖子
			// 安全: 下拉只列出本版主有权移入的版块（无管辖权的目标版块服务端也会拒绝）
			if(!forum_access_mod($_f['fid'], $gid, 'allowmove')) continue;
			$forumarr[$_f['fid']] = $_f['name'];
		}
		include _include(APP_PATH.'view/htm/mod_move.htm');
		
	} else {
		
		CsrfService::check();
		
		$tidarr = param('tidarr', array(0));
		empty($tidarr) AND message(-1, lang('please_choose_thread'));
		$threadlist = thread_find_by_tids($tidarr);
			
		$newfid = param('newfid', 0);
		!forum_read($newfid) AND message(1, lang('forum_not_exists'));
		// 安全: 目标版块同样要求版主管辖权限，防止移入无权管理的版块（含 accesson 版块）
		forum_access_mod($newfid, $gid, 'allowmove') OR message(-1, lang('insufficient_privilege'));
		
		// hook mod_move_start.php

		// 批量移动：先收集通过权限检查且目标版块不同的 tid，再批量更新，消除 N+1 查询
		$move_tids = array();
		$modlog_records = array();
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowmove')) {
				if($fid == $newfid) continue;
				$move_tids[] = $tid;
				$modlog_records[] = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'move',
				);
				// hook mod_move_log_create_before.php
			}
		}
		// 零命中（选中帖均不在管辖版块/无权限）时如实返回失败，避免误导操作者
		empty($move_tids) AND message(-1, lang('mod_no_accessible_thread'));
		// 批量移动：逐条走模型层 thread_update，同步 forum.threads 计数与 thread_top.fid（待审帖不计数，口径与建帖/删帖一致）
		foreach($move_tids as $_move_tid) {
			thread_update($_move_tid, array('fid'=>$newfid));
		}
		// 批量插入版主日志，消除 N+1 INSERT
		!empty($modlog_records) AND modlog_create_batch($modlog_records);

		// 清理下缓存（新旧版块帖子列表/置顶/首页/排行榜缓存已由 thread_update 内部清理）
		forum_list_cache_delete();

		// hook mod_move_end.php
		
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('move_completely'), 'redirect_url' => index_url()), JSON_UNESCAPED_UNICODE);
		exit;
		
	}
	
} elseif($action == 'deleteuser') {
	
	$_uid = param(2, 0);
	
	$method != 'POST' AND message(-1, 'Method error');
	
	CsrfService::check();

	!PermissionService::check('allowdeleteuser') AND message(-1, lang('insufficient_delete_user_privilege'));
	
	$u = user_read($_uid);
	empty($u) AND message(-1, lang('user_not_exists_or_deleted'));
	
	$u['gid'] < 6 AND message(-1, lang('cant_delete_admin_group'));
	
	// hook mod_delete_user_start.php
	
	$r = user_delete($_uid);
	$r === FALSE AND message(-1, lang('delete_failed'));

	// hook mod_delete_user_end.php

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'message' => lang('delete_successfully'), 'redirect_url' => index_url()), JSON_UNESCAPED_UNICODE);
	exit;

} elseif($action == 'ban_user') {

	// 封禁用户（版主/管理员）
	$method != 'POST' AND message(-1, 'Method error');

	user_login_check();

	CsrfService::check();

	$ban_uid = intval(param('uid'));
	$ban_type = intval(param('ban_type'));
	$duration = intval(param('duration'));
	$reason = param('reason', '', FALSE);
	$fid = intval(param('fid'));

	// 权限校验：管理员组（gid=1,2）直接放行；版主组需 allowbanuser 权限
	$_is_admin = in_array($gid, array(1, 2));
	if(!$_is_admin) {
		if(!forum_access_mod($fid, $gid, 'allowbanuser')) {
			message(-1, lang('user_ban_no_permission'));
		}
	}

	// 版主权限限制：非管理员仅能禁言(ban_type=1) 1-7天，不能永久
	if(!$_is_admin) {
		if($ban_type != 1) {
			message(-1, lang('user_ban_mod_can_only_silence'));
		}
		if($duration == 0) {
			message(-1, lang('user_ban_mod_no_permanent'));
		}
		if($duration < 86400 || $duration > 604800) {
			message(-1, lang('user_ban_mod_duration_limit'));
		}
	}

	if($ban_uid <= 0) {
		message(-1, lang('data_malformation'));
	}

	// 调用封禁服务（内部校验：不能封禁自己、不能封禁管理员组、uid 有效性）
	if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
	$result = UserBanService::ban($ban_uid, $ban_type, $duration, $reason, $uid);

	if($result['code'] != 0) {
		message(-1, isset($result['message']) ? $result['message'] : lang('operation_failed'));
	}

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'message' => lang('user_ban_success'), 'redirect_url' => index_url()), JSON_UNESCAPED_UNICODE);
	exit;

} elseif($action == 'digest') {

	if($method == 'GET') {

		include _include(APP_PATH.'view/htm/mod_digest.htm');

	} else {

		CsrfService::check();

		$digest = param('digest', 0);

		$tidarr = param('tidarr', array(0));
		empty($tidarr) AND message(-1, lang('please_choose_thread'));
		$threadlist = thread_find_by_tids($tidarr);

		// hook mod_digest_start.php

		// 批量精华：先收集通过权限检查的 tid，再批量更新 thread_digest/thread.digest/user.digests/forum.digests，消除 N+1
		$digest_tids = array();
		$modlog_records = array();
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowtop')) {
				$digest_tids[] = $tid;
				$modlog_records[] = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'digest',
				);
			}
		}
		// 批量更新精华状态
		empty($digest_tids) AND message(-1, lang('mod_no_accessible_thread'));
		!empty($digest_tids) AND thread_digest_change_batch($digest_tids, $threadlist, $digest);
		// 批量插入版主日志，消除 N+1 INSERT
		!empty($modlog_records) AND modlog_create_batch($modlog_records);

		// hook mod_digest_end.php

		// 积分规则：仅设置精华时发放（digest>0），取消精华（digest=0）不扣减，避免反复加精/取消刷积分
		if($digest > 0) {
			if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
			$_digest_uid_fid_pairs = array();
			foreach($threadlist as $_thread) {
				// 仅对实际通过权限检查且本次变更的 tid 发放积分
				if(in_array($_thread['tid'], $digest_tids) && !empty($_thread['uid']) && intval($_thread['digest']) === 0) {
					$_digest_uid_fid_pairs[] = array(intval($_thread['uid']), intval($_thread['fid']));
				}
			}
			!empty($_digest_uid_fid_pairs) AND CreditsRuleService::applyRuleBatch('thread_digest', $_digest_uid_fid_pairs);

			// 通知帖子作者：仅对从无精华变为有精华的帖子发送，避免同级别调整重复通知
			foreach($threadlist as $_thread) {
				if(in_array($_thread['tid'], $digest_tids) && !empty($_thread['uid']) && intval($_thread['digest']) === 0) {
					notify_create($_thread['uid'], $uid, 'digest', $_thread['tid']);
				}
			}
		}

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('set_completely'), 'redirect_url' => index_url()), JSON_UNESCAPED_UNICODE);
		exit;
	}

} elseif($action == 'audit') {

	if($method == 'GET') {

		include _include(APP_PATH.'view/htm/mod_audit.htm');

	} else {

		CsrfService::check();

		$audit_status = param('audit_status', 1);

		$tidarr = param('tidarr', array(0));
		empty($tidarr) AND message(-1, lang('please_choose_thread'));

		// hook mod_audit_start.php

		if(!class_exists('AuditService')) include_once APP_PATH . 'lib/security/AuditService.php';

		$updated = 0;
		// 批量读取帖子，消除 N+1 查询
		$_audit_tidarr = array();
		foreach($tidarr as $_tid) {
			$_tid = intval($_tid);
			if(!empty($_tid)) $_audit_tidarr[] = $_tid;
		}
		$_audit_threads = empty($_audit_tidarr) ? array() : thread_find_by_tids($_audit_tidarr);
		// 先过滤出通过权限检查的 tid，再调用批量审核接口，消除 N+1 UPDATE/notify
		$_audit_valid_tids = array();
		foreach($_audit_tidarr as $_tid) {
			if(!isset($_audit_threads[$_tid])) continue;
			$thread = $_audit_threads[$_tid];
			$fid = $thread['fid'];
			if(!forum_access_mod($fid, $gid, 'allowtop')) continue;
			$_audit_valid_tids[] = $_tid;
		}

		if(!empty($_audit_valid_tids)) {
			if($audit_status == 1) {
				// 批量审核通过
				$updated = AuditService::batch_approve('thread', $_audit_valid_tids, $uid);
			} elseif($audit_status == 2) {
				// 批量审核驳回
				$updated = AuditService::batch_reject('thread', $_audit_valid_tids, $uid);
			} elseif($audit_status == 3) {
				// 批量忽略：内容保持不可见、作者无感知，记录保留供后续处理（前台审核队列用）
				$updated = AuditService::batch_ignore('thread', $_audit_valid_tids, $uid);
			}
		}

		// hook mod_audit_end.php

		if($updated > 0) {
			$_audit_msgs = array(1 => lang('audit_approve'), 2 => lang('audit_reject'), 3 => lang('audit_ignore'));
			$msg = isset($_audit_msgs[$audit_status]) ? $_audit_msgs[$audit_status] : lang('audit_approve');
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('code' => 0, 'message' => $msg . lang('success_label'), 'redirect_url' => index_url()), JSON_UNESCAPED_UNICODE);
		} else {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('code' => -1, 'message' => lang('operation_failed')), JSON_UNESCAPED_UNICODE);
		}
		exit;
	}

} elseif($action == 'audit_post') {

	// 回帖审核（通过/驳回）
	CsrfService::check();

	$pid = param('pid', 0);
	$audit_status = param('audit_status', 1);
	$reason = param('reason', '', FALSE);

	if(empty($pid)) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('data_malformation')), JSON_UNESCAPED_UNICODE);
		exit;
	}

	// 权限检查：管理员/版主
	$post = post_read($pid);
	if(empty($post) || !empty($post['isfirst'])) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('post_not_exists')), JSON_UNESCAPED_UNICODE);
		exit;
	}

	$thread = thread_read($post['tid']);
	if(empty($thread)) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('thread_not_exists')), JSON_UNESCAPED_UNICODE);
		exit;
	}

	$fid = $thread['fid'];
	if(!forum_access_mod($fid, $gid, 'allowtop')) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('user_group_insufficient_privilege')), JSON_UNESCAPED_UNICODE);
		exit;
	}

	if(!class_exists('AuditService')) include_once APP_PATH . 'lib/security/AuditService.php';

	if($audit_status == 1) {
		$r = AuditService::approve('post', $pid, $uid);
	} elseif($audit_status == 3) {
		// 忽略：内容保持不可见、作者无感知（前台审核队列用）
		$r = AuditService::ignore('post', $pid, $uid);
	} else {
		$r = AuditService::reject('post', $pid, $uid, $reason);
	}

	if($r) {
		$_audit_msgs = array(1 => lang('audit_approve'), 2 => lang('audit_reject'), 3 => lang('audit_ignore'));
		$msg = isset($_audit_msgs[$audit_status]) ? $_audit_msgs[$audit_status] : lang('audit_approve');
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => $msg . lang('success_label')), JSON_UNESCAPED_UNICODE);
	} else {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('operation_failed')), JSON_UNESCAPED_UNICODE);
	}
	exit;

} elseif($action == 'auditlist') {

	// 前台审核队列：管理员/超版审全站待审内容，版主仅审管辖版块
	// 审核通知"查看详情"按权限落到本页（entry_url_for_viewer），替代写死的后台 admin/?audit.htm
	// 单条/批量操作复用 mod-audit（主题）与 mod-audit_post（回帖）端点，服务端逐条重新校验 forum_access_mod
	// hook mod_auditlist_start.php

	$_audit_fids = array();
	if(in_array($gid, array(1, 2))) {
		foreach((array)$forumlist as $_f) {
			!empty($_f['fid']) AND $_audit_fids[intval($_f['fid'])] = intval($_f['fid']);
		}
	} else {
		foreach((array)$forumlist as $_f) {
			if(!empty($_f['moduids']) && in_string($uid, $_f['moduids'])) $_audit_fids[intval($_f['fid'])] = intval($_f['fid']);
		}
	}
	empty($_audit_fids) AND message(-1, lang('insufficient_privilege'));

	$page = max(1, intval(param(2, 1)));
	$pagesize = 20;
	$_audit_tab = param('tab', 'thread');
	!in_array($_audit_tab, array('thread', 'post')) AND $_audit_tab = 'thread';

	// 待审主题（仅管辖版块；忽略状态(3)不进前台队列，与"作者无感知"语义一致，后台审核页仍可处理）
	// db_find 为原始层不自动过滤软删除，显式排除 is_deleted=1 的回收站帖子
	$_thread_cond = array('fid' => array_values($_audit_fids), 'audit_status' => 0, 'is_deleted' => 0);
	$thread_total = db_count('thread', $_thread_cond);
	$audit_threadlist = $thread_total > 0 ? db_find('thread', $_thread_cond, array('tid' => -1), $page, $pagesize, 'tid') : array();

	// 待审回帖：post 表无 fid，反查——取全站最近待审回帖（上限 500，待审量通常很小），
	// 批量读主题映射 fid 后按管辖版块过滤（PHP 内过滤，无精确分页；原实现只从"待审主题"的 tids
	// 出发查回帖，漏掉已通过主题下的待审回帖，导致前台回帖 tab 恒空而后台正常）
	$_post_cond = array('isfirst' => 0, 'audit_status' => 0, 'is_deleted' => 0);
	$_post_rows = db_count('post', $_post_cond) > 0 ? db_find('post', $_post_cond, array('pid' => -1), 1, 500, 'pid') : array();
	$audit_postlist = array();
	$_audit_threads = array();
	if(!empty($_post_rows)) {
		$_post_tids = array();
		foreach($_post_rows as $_p) $_post_tids[intval($_p['tid'])] = true;
		$_audit_threads = db_find('thread', array('tid' => array_keys($_post_tids)), array(), 1, count($_post_tids), 'tid');
		foreach($_post_rows as $_p) {
			$_p_fid = isset($_audit_threads[$_p['tid']]) ? intval($_audit_threads[$_p['tid']]['fid']) : 0;
			// 主题不存在/已删除/不在管辖版块内 → 不展示（数据不出服务端，无越权泄露面）
			if($_p_fid > 0 && isset($_audit_fids[$_p_fid])) $audit_postlist[intval($_p['pid'])] = $_p;
		}
	}
	$post_total = count($audit_postlist);

	// 被回帖内容（quotepid>0 的楼中楼）：批量取原评论/首帖内容与作者名，模板中以引用块区分展示
	$_audit_quoted = array();
	$_audit_quotepids = array();
	foreach($audit_postlist as $_p) {
		intval($_p['quotepid']) > 0 AND $_audit_quotepids[intval($_p['quotepid'])] = true;
	}
	if(!empty($_audit_quotepids)) {
		$_quoted_rows = db_find('post', array('pid' => array_keys($_audit_quotepids), 'is_deleted' => 0), array(), 1, count($_audit_quotepids), 'pid');
		$_quoted_uids = array();
		foreach($_quoted_rows as $_q) $_quoted_uids[intval($_q['uid'])] = intval($_q['uid']);
		$_quoted_names = empty($_quoted_uids) ? array() : user_find_by_uids(implode(',', array_keys($_quoted_uids)));
		foreach($_quoted_rows as $_q) {
			$_q_uid = intval($_q['uid']);
			$_audit_quoted[intval($_q['pid'])] = array(
				'name' => isset($_quoted_names[$_q_uid]) ? (!empty($_quoted_names[$_q_uid]['display_name']) ? $_quoted_names[$_q_uid]['display_name'] : $_quoted_names[$_q_uid]['username']) : '',
				'summary' => mb_substr(strip_tags($_q['message']), 0, 80),
			);
		}
	}

	// 批量格式化：作者（display_name）+ 所属主题/版块名，消除 N+1 查询
	$_audit_uids = array();
	foreach($audit_threadlist as $_t) $_audit_uids[intval($_t['uid'])] = intval($_t['uid']);
	foreach($audit_postlist as $_p) $_audit_uids[intval($_p['uid'])] = intval($_p['uid']);
	$_audit_users = empty($_audit_uids) ? array() : user_find_by_uids(implode(',', array_keys($_audit_uids)));

	foreach($audit_threadlist as $_t) { if(!isset($_audit_threads[$_t['tid']])) $_audit_threads[intval($_t['tid'])] = $_t; }

	$thread_pagination = pagination(route_url('mod_auditlist_page'), $thread_total, $page, $pagesize);

	$header['title'] = lang('mod_auditlist_title');
	$header['mobile_title'] = lang('mod_auditlist_title');

	include _include(APP_PATH.'view/htm/mod_auditlist.htm');

} elseif($action == 'top_post') {

	// 评论置顶/取消置顶
	CsrfService::check();

	$pid = param('pid', 0);
	$tid = param('tid', 0);
	$is_top = param('is_top', 0);

	if(empty($pid) || empty($tid)) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('data_malformation')), JSON_UNESCAPED_UNICODE);
		exit;
	}

	// 权限检查：管理员或帖子作者
	$thread = thread_read($tid);
	if(empty($thread)) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('thread_not_exists')), JSON_UNESCAPED_UNICODE);
		exit;
	}

	$fid = $thread['fid'];
	$is_admin = forum_access_mod($fid, $gid, 'allowtop');
	$is_author = ($uid == $thread['uid']);

	if(!$is_admin && !$is_author) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('user_group_insufficient_privilege')), JSON_UNESCAPED_UNICODE);
		exit;
	}

	// 更新置顶状态
	$post = post_read($pid);
	if(empty($post) || $post['tid'] != $tid || !empty($post['isfirst'])) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('post_not_exists')), JSON_UNESCAPED_UNICODE);
		exit;
	}

	$r = post_update($pid, array('is_top' => $is_top ? 1 : 0));
	if($r !== FALSE) {
		$msg = $is_top ? lang('post_top_success') : lang('post_untop_success');
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => $msg), JSON_UNESCAPED_UNICODE);
	} else {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('operation_failed')), JSON_UNESCAPED_UNICODE);
	}
	exit;

} elseif($action == 'announcement') {
	
	if($method == 'GET') {
		
		include _include(APP_PATH.'view/htm/mod_announcement.htm');
		
	} else {
		
		CsrfService::check();
		
		$is_announcement = param('is_announcement', 0);
		$announcement_order = param('announcement_order', 0);
		
		$tidarr = param('tidarr', array(0));
		empty($tidarr) AND message(-1, lang('please_choose_thread'));
		$threadlist = thread_find_by_tids($tidarr);
			
		// hook mod_announcement_start.php
		
		$updated = 0;
		// 批量公告：先收集通过权限检查的 tid，再批量更新，消除 N+1 查询
		$announce_tids = array();
		$modlog_records = array();
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowtop')) {
				$announce_tids[] = $tid;
				$modlog_records[] = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'announcement',
				);

				// hook mod_announcement_log_create_before.php
			}
		}
		// 批量更新公告状态
		if(!empty($announce_tids)) {
			$r = db_update('thread', array('tid'=>$announce_tids), array(
				'is_announcement' => $is_announcement,
				'announcement_order' => $is_announcement ? $announcement_order : 0
			));
			if($r !== FALSE) $updated = count($announce_tids);
		}
		// 批量插入版主日志，消除 N+1 INSERT
		!empty($modlog_records) AND modlog_create_batch($modlog_records);

		// 清理缓存
		cache_delete('sidebar_announcements');

		// hook mod_announcement_end.php

		if($updated > 0) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('code' => 0, 'message' => lang('set_completely')), JSON_UNESCAPED_UNICODE);
		} else {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('code' => -1, 'message' => lang('operation_failed')), JSON_UNESCAPED_UNICODE);
		}
		exit;
	}
}

// hook mod_end.php

?>