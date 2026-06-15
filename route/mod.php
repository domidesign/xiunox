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
		
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if($top == 3 && ($gid != 1 && $gid != 2)) {
				continue;
			}
			if(forum_access_mod($fid, $gid, 'allowtop')) {
				thread_top_change($tid, $top);
				$arr = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'top',
				);
				
				// hook mod_top_log_create_before.php
				modlog_create($arr);
				
			}
		}
		
		// hook mod_top_end.php

		// 积分规则：置顶获得积分
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		foreach($threadlist as $_thread) {
			if(!empty($_thread['uid'])) {
				CreditsRuleService::applyRule('thread_top', intval($_thread['uid']), intval($_thread['fid']));
			}
		}

		// hook mod_digest_end.php

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('set_completely'), 'redirect_url' => './'), JSON_UNESCAPED_UNICODE);
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
		
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowtop')) {
				thread_update($tid, array('closed'=>$close));
				$arr = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'close',
				);
				
				// hook mod_close_log_create_before.php
				modlog_create($arr);
			}
		}
		
		// hook mod_close_end.php
		
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('set_completely'), 'redirect_url' => './'), JSON_UNESCAPED_UNICODE);
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
		
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowdelete')) {
				thread_delete($tid);
				$arr = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'delete',
				);
				// hook mod_delete_log_create_before.php
				modlog_create($arr);
			}
		}
		
		// hook mod_delete_end.php

		// 积分规则：删主题扣除作者积分
		if(!class_exists('CreditsRuleService')) include_once APP_PATH . 'service/CreditsRuleService.php';
		foreach($threadlist as $_thread) {
			if(!empty($_thread['uid'])) {
				CreditsRuleService::applyRule('thread_delete', intval($_thread['uid']), intval($_thread['fid']));
			}
		}

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('delete_completely'), 'redirect_url' => './'), JSON_UNESCAPED_UNICODE);
		exit;
	}
	
	
} elseif($action == 'move') {

	if($method == 'GET') {
		$forumarr = arrlist_key_values(forum_list_cache(), 'fid', 'name');
		include _include(APP_PATH.'view/htm/mod_move.htm');
		
	} else {
		
		CsrfService::check();
		
		$tidarr = param('tidarr', array(0));
		empty($tidarr) AND message(-1, lang('please_choose_thread'));
		$threadlist = thread_find_by_tids($tidarr);
			
		$newfid = param('newfid', 0);
		!forum_read($newfid) AND message(1, lang('forum_not_exists'));
		
		// hook mod_move_start.php
		
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowmove')) {
				if($fid == $newfid) continue;
				thread_update($tid, array('fid'=>$newfid));
				$arr = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'move',
				);
				// hook mod_move_log_create_before.php
				modlog_create($arr);
			}
		}
		
		// 清理下缓存
		forum_list_cache_delete();
		
		// hook mod_move_end.php
		
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('move_completely'), 'redirect_url' => './'), JSON_UNESCAPED_UNICODE);
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
	echo json_encode(array('code' => 0, 'message' => lang('delete_successfully'), 'redirect_url' => './'), JSON_UNESCAPED_UNICODE);
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

		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowtop')) {
				thread_digest_change($tid, $digest, $thread['uid'], $thread['fid']);
				$arr = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'digest',
				);
				modlog_create($arr);
			}
		}

		// hook mod_digest_end.php

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => 0, 'message' => lang('set_completely'), 'redirect_url' => './'), JSON_UNESCAPED_UNICODE);
		exit;
	}

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
		foreach($threadlist as &$thread) {
			$fid = $thread['fid'];
			$tid = $thread['tid'];
			if(forum_access_mod($fid, $gid, 'allowtop')) {
				$r = thread_update($tid, array(
					'is_announcement' => $is_announcement,
					'announcement_order' => $is_announcement ? $announcement_order : 0
				));
				if($r) $updated++;
				$arr = array(
					'uid' => $uid,
					'tid' => $thread['tid'],
					'pid' => $thread['firstpid'],
					'subject' => $thread['subject'],
					'comment' => '',
					'create_date' => $time,
					'action' => 'announcement',
				);

				// hook mod_announcement_log_create_before.php
				modlog_create($arr);
			}
		}

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