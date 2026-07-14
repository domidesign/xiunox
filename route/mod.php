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
		// 批量更新版块
		if(!empty($move_tids)) {
			db_update('thread', array('tid'=>$move_tids), array('fid'=>$newfid));
		}
		// 批量插入版主日志，消除 N+1 INSERT
		!empty($modlog_records) AND modlog_create_batch($modlog_records);

		// 清理下缓存
		forum_list_cache_delete();
		// db_update 绕过模型层，需手动清理原版块/目标版块帖子列表缓存，置顶帖 fid 信息可能错乱
		$_move_old_fid_set = array();
		foreach($threadlist as $_thread) {
			$_move_old_fid_set[intval($_thread['fid'])] = 1;
		}
		unset($_move_old_fid_set[intval($newfid)]);
		foreach(array_keys($_move_old_fid_set) as $_fid) {
			thread_forum_list_cache_delete($_fid);
		}
		thread_forum_list_cache_delete($newfid);
		thread_top_cache_delete();

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
			}
		}

		// hook mod_audit_end.php

		if($updated > 0) {
			$msg = $audit_status == 1 ? lang('audit_approve') : lang('audit_reject');
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
	} else {
		$r = AuditService::reject('post', $pid, $uid, $reason);
	}

	if($r) {
		$msg = $audit_status == 1 ? lang('audit_approve') : lang('audit_reject');
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => $msg . lang('success_label')), JSON_UNESCAPED_UNICODE);
	} else {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => lang('operation_failed')), JSON_UNESCAPED_UNICODE);
	}
	exit;

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