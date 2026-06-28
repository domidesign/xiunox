<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook admin_thread_start.php

$pagesize = param('pagesize', 100);
if(!in_array($pagesize, array(20, 50, 100, 200))) $pagesize = 100;

if(empty($action) || $action == 'list') {

	$header['title'] = lang('thread_admin');
	$header['mobile_title'] = lang('thread_admin');
		
	// hook admin_thread_list_start.php
	
	// ajax 扫描全表
	$threads = $runtime['threads'];
	$page = 1; // 从第一页开始
	$totalpage = ceil($threads / $pagesize);
	
	$queueid = _SESSION('thread_find_queueid');
	$queueid AND queue_destory($queueid);
	$queueid = $time;
	$_SESSION['thread_find_queueid'] = $queueid;

	$forumlist_simple = array();
	foreach($forumlist as $k=>$v) {
		$forumlist_simple[$k] = array(
			'name'=>$v['name'],
			'threads'=>$v['threads'],
		);
	}
	
	// hook admin_thread_list_end.php
	
	include _include(ADMIN_PATH."view/htm/thread_list.htm");
	
// 全表扫描，每次扫描 1000 条记录
} elseif($action == 'scan') {
	
	$queueid = _SESSION('thread_find_queueid');
	empty($queueid) AND message(-1, lang('thread_queue_not_exists'));
	
	$_uid = param('uid');
	if(!is_numeric($_uid)) {
		$_user = user_read_by_username($_uid);
		$_uid = !empty($_user['uid']) ? $_user['uid'] : 0;
	}
	$fid = param('fid', 0);
	
	$cond = array();
	$cond['fid'] = $fid;
	$cond['create_date_start'] = strtotime(param('create_date_start'));
	$cond['create_date_end'] = strtotime(param('create_date_end'));
	$cond['uid'] = $_uid;
	$userip = param('userip');
	$cond['userip'] = $userip ? sprintf('%u', ip2long($userip)) : 0;
	$cond['keyword'] = param('keyword');
	$cond['page'] = param('page', 1);
	
	$page = $cond['page'];
	$threads = $cond['fid'] ? $forumlist[$fid]['threads'] : $runtime['threads'];
	$totalpage = ceil($threads / $pagesize);
	
	// hook admin_thread_scan_start.php
	$threadlist = thread_find_by_fid($fid, $page, $pagesize);
	
	if($page == 1) $queueid AND queue_destory($queueid);
	
	$tids = array();
	foreach($threadlist as $thread) {
		
		if($cond['fid'] && $thread['fid'] != $cond['fid']) continue; 
		if($cond['create_date_start'] && $thread['create_date'] < $cond['create_date_start']) continue; 
		if($cond['create_date_end'] && $thread['create_date'] > $cond['create_date_end']) continue; 
		if($cond['uid'] && $thread['uid'] != $cond['uid']) continue; 
		if($cond['userip'] && $thread['userip'] != $cond['userip']) continue; 
		if($cond['keyword'] && stripos($thread['subject'], $cond['keyword']) === FALSE) continue; 
		
		// hook admin_thread_scan_for.php
		
		$tids[] = $thread['tid'];
		queue_push($queueid, $thread['tid'], 86400);
	}
	
	// hook admin_thread_scan_end.php
	message(0, $tids);
	
// 队列操作（旧接口，保留兼容）
} elseif($action == 'operation') {

	$queueid = _SESSION('thread_find_queueid');
	empty($queueid) AND message(-1, lang('thread_queue_not_exists'));

	$op = param(2);
	$tids = array();
	// hook admin_thread_operation_start.php
	// 先从队列中取出所有 tid，再批量操作，避免逐条更新
	for($i = 0; $i <= $pagesize; $i++) {
		$tid = queue_pop($queueid);
		if(!$tid) {
			break;
		}
		$tids[] = $tid;
		// hook admin_thread_operation_for.php
	}

	if(!empty($tids)) {
		if($op == 'delete') {
			// 删除涉及级联，保持循环
			foreach($tids as $tid) {
				thread_delete($tid);
			}
		} elseif($op == 'close') {
			// 批量更新
			db_update('thread', array('tid'=>$tids), array('closed'=>1));
		} elseif($op == 'open') {
			db_update('thread', array('tid'=>$tids), array('closed'=>0));
		} elseif($op == 'announcement') {
			db_update('thread', array('tid'=>$tids), array('announcement'=>1));
		}
	}
	// hook admin_thread_operation_end.php
	// 记录操作日志
	if(!empty($tids)) {
		$op_labels = array('delete'=>'删除', 'close'=>'关闭', 'open'=>'开启', 'announcement'=>'设为公告');
		admin_log_create('thread_' . $op, 'thread', $tids, ($op_labels[$op] ?? $op) . '主题 ' . count($tids) . ' 篇');
	}
	message(0, $tids);
	
// 批量操作（新接口，基于选中 tid）
} elseif($action == 'batch') {

	CsrfService::check();

	$op = param('op');
	$tids_str = param('tids');
	$tids = $tids_str ? explode(',', $tids_str) : array();

	if(empty($tids)) {
		message(-1, lang('admin_thread_no_selection'));
	}

	// 过滤有效的 tid
	$valid_tids = array();
	foreach($tids as $tid) {
		$tid = intval($tid);
		if($tid > 0) $valid_tids[] = $tid;
	}

	// hook admin_thread_batch_start.php

	$success_count = 0;
	$target_fid = 0;

	if(!empty($valid_tids)) {
		if($op == 'delete') {
			// 删除涉及级联，保持循环
			foreach($valid_tids as $tid) {
				thread_delete($tid);
				$success_count++;
			}
		} elseif($op == 'close') {
			// 批量更新
			db_update('thread', array('tid'=>$valid_tids), array('closed'=>1));
			$success_count = count($valid_tids);
		} elseif($op == 'open') {
			db_update('thread', array('tid'=>$valid_tids), array('closed'=>0));
			$success_count = count($valid_tids);
		} elseif($op == 'top') {
			db_update('thread', array('tid'=>$valid_tids), array('top'=>1));
			$success_count = count($valid_tids);
		} elseif($op == 'digest') {
			// 批量读取 thread，然后逐条调用 thread_digest_change（涉及积分等复杂逻辑）
			$threadlist = thread_find_by_tids($valid_tids);
			if($threadlist) {
				foreach($valid_tids as $tid) {
					if(isset($threadlist[$tid])) {
						$thread = $threadlist[$tid];
						$new_digest = !empty($thread['digest']) ? 0 : 1;
						thread_digest_change($tid, $new_digest, $thread['uid'], $thread['fid']);
						$success_count++;
					}
				}
			}
		} elseif($op == 'announcement') {
			db_update('thread', array('tid'=>$valid_tids), array('announcement'=>1));
			$success_count = count($valid_tids);
		} elseif($op == 'move') {
			// move 涉及 fid 变更和 forum 计数更新，保持循环
			$target_fid = param('target_fid', 0);
			if($target_fid > 0) {
				foreach($valid_tids as $tid) {
					thread_update($tid, array('fid'=>$target_fid));
					$success_count++;
				}
			}
		}
		// hook admin_thread_batch_for.php
	}

	// hook admin_thread_batch_end.php
	// 记录操作日志
	if($success_count > 0) {
		$op_labels = array('delete'=>'删除', 'close'=>'关闭', 'open'=>'开启', 'top'=>'置顶', 'digest'=>'加精', 'announcement'=>'设为公告', 'move'=>'移动');
		$detail = ($op_labels[$op] ?? $op) . '主题 ' . $success_count . ' 篇';
		if($op == 'move') {
			$target_forum = forum_read($target_fid);
			$detail .= ' → ' . ($target_forum ? $target_forum['name'] : 'fid:' . $target_fid);
		}
		admin_log_create($op == 'delete' ? 'thread_batch_delete' : 'thread_' . $op, 'thread', $tids, $detail);
	}
	message(0, lang('admin_thread_batch_success') . ' (' . $success_count . ')');

// 搜索结果展示
} elseif($action == 'found') {	

	$queueid = _SESSION('thread_find_queueid');
	empty($queueid) AND message(-1, lang('thread_queue_not_exists'));
	
	$page = param(2, 1);
	$total = queue_count($queueid);
	$pagination = pagination(route_url('admin_thread_found'), $total, $page, $pagesize);
	// hook admin_thread_found_start.php
	$tids = queue_find($queueid, $page, $pagesize);
	$threadlist = thread_find_by_tids($tids);
	
	// hook admin_thread_found_end.php
	include _include(ADMIN_PATH."view/htm/thread_found.htm");
}

// hook admin_thread_start.php

?>
