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
			include_once APP_PATH . 'lib/security/SecurityConfigService.php';
			$sec_soft_delete = SecurityConfigService::get('security_soft_delete', 1);
			if($sec_soft_delete) {
				thread_soft_delete_batch($tids, $uid);
			} else {
				// 删除涉及级联，保持循环
				foreach($tids as $tid) {
					thread_delete($tid);
				}
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
			include_once APP_PATH . 'lib/security/SecurityConfigService.php';
			$sec_soft_delete = SecurityConfigService::get('security_soft_delete', 1);
			if($sec_soft_delete) {
				thread_soft_delete_batch($valid_tids, $uid);
				$success_count = count($valid_tids);
			} else {
				// 删除涉及级联，保持循环
				foreach($valid_tids as $tid) {
					thread_delete($tid);
					$success_count++;
				}
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

// 回收站
} elseif($action == 'recycle') {

	$header['title'] = lang('admin_recycle_bin');
	$header['mobile_title'] = lang('admin_recycle_bin');

	// 回收站类型：thread(帖子) / post(评论)
	$type = param('type', 'thread');
	if($type !== 'post') $type = 'thread';

	// 顶部 tab 切换（外层导航用纯链接跳转，不用 Bootstrap Tab 系统）
	$recycle_tabs = array(
		'thread' => array('url'=>admin_thread_recycle_url(array('type'=>'thread')), 'text'=>lang('admin_thread_recycle_tab')),
		'post'   => array('url'=>admin_thread_recycle_url(array('type'=>'post')),   'text'=>lang('admin_post_recycle_tab')),
	);

	if($method == 'GET') {
		// 筛选条件
		$filter_fid = param('fid', 0);
		$filter_keyword = param('keyword', '');
		$page = param('page', 1);
		$pagesize = 20;

		$cond = array();
		$filter_fid AND $cond['fid'] = $filter_fid;
		$filter_keyword AND $cond['keyword'] = $filter_keyword;

		if($type == 'post') {
			// 评论回收站（用 post_find_deleted 走 JOIN 查询，支持按 fid/keyword 筛选）
			$postlist = post_find_deleted($cond, array('deleted_date'=>-1), $page, $pagesize);
			$total = post_count_deleted($cond);
			$pagination = pagination(admin_thread_recycle_url(array('type'=>'post', 'fid'=>$filter_fid, 'keyword'=>$filter_keyword, 'page'=>'{page}')), $total, $page, $pagesize);

			if($postlist) {
				// 批量预加载 thread 获取 fid 和 subject
				$_tids = array_unique(arrlist_values($postlist, 'tid'));
				$_threads_map = empty($_tids) ? array() : db_find('thread', array('tid'=>$_tids), array(), 1, count($_tids), 'tid');

				foreach($postlist as &$_post) {
					// 作者名
					$_user = user_read_cache($_post['uid']);
					$_post['username'] = isset($_user['display_name']) ? $_user['display_name'] : (isset($_user['username']) ? $_user['username'] : '');
					// 删除者名
					$_post['deleted_by_name'] = '';
					if(!empty($_post['deleted_by'])) {
						$_duser = user_read_cache($_post['deleted_by']);
						$_post['deleted_by_name'] = isset($_duser['display_name']) ? $_duser['display_name'] : (isset($_duser['username']) ? $_duser['username'] : '');
					}
					$_post['deleted_date_fmt'] = !empty($_post['deleted_date']) ? humandate($_post['deleted_date']) : '';
					// 主题标题和版块名
					$_thread = isset($_threads_map[$_post['tid']]) ? $_threads_map[$_post['tid']] : array();
					$_post['thread_subject'] = isset($_thread['subject']) ? $_thread['subject'] : '';
					$_post['fid'] = isset($_thread['fid']) ? intval($_thread['fid']) : 0;
					$_post['forumname'] = isset($forumlist[$_post['fid']]) ? $forumlist[$_post['fid']]['name'] : '';
				}
				unset($_post);
			}
			$threadlist = array();
		} else {
			// 帖子回收站
			$_thread_cond = $cond;
			$filter_keyword AND $_thread_cond['subject'] = array('LIKE' => $filter_keyword);
			unset($_thread_cond['keyword']);

			$threadlist = thread_find_deleted($_thread_cond, array('deleted_date'=>-1), $page, $pagesize);
			$total = db_count('thread', array_merge($_thread_cond, array('is_deleted'=>1)));
			$pagination = pagination(admin_thread_recycle_url(array('type'=>'thread', 'fid'=>$filter_fid, 'keyword'=>$filter_keyword, 'page'=>'{page}')), $total, $page, $pagesize);

			if($threadlist) {
				foreach($threadlist as &$thread) {
					$thread['deleted_by_name'] = '';
					if(!empty($thread['deleted_by'])) {
						$deleted_user = user_read_cache($thread['deleted_by']);
						$thread['deleted_by_name'] = isset($deleted_user['display_name']) ? $deleted_user['display_name'] : (isset($deleted_user['username']) ? $deleted_user['username'] : '');
					}
					$thread['deleted_date_fmt'] = !empty($thread['deleted_date']) ? humandate($thread['deleted_date']) : '';
				}
			}
			$postlist = array();
		}

		include _include(ADMIN_PATH."view/htm/thread_recycle.htm");
	} else {
		CsrfService::check();

		$op = param('op');

		if($type == 'post') {
			// 评论回收站操作
			$pids_str = param('pids');
			$pids = $pids_str ? explode(',', $pids_str) : array();

			if(empty($pids)) {
				message(-1, lang('admin_thread_no_selection'));
			}

			$valid_pids = array();
			foreach($pids as $pid) {
				$pid = intval($pid);
				if($pid > 0) $valid_pids[] = $pid;
			}

			if(empty($valid_pids)) {
				message(-1, lang('admin_thread_no_selection'));
			}

			$success_count = 0;
			if($op == 'restore') {
				$success_count = post_restore_batch($valid_pids);
				admin_log_create('post_restore', 'post', $valid_pids, '恢复评论 ' . $success_count . ' 条');
			} elseif($op == 'hard_delete') {
				$success_count = post_hard_delete_batch($valid_pids);
				admin_log_create('post_hard_delete', 'post', $valid_pids, '彻底删除评论 ' . $success_count . ' 条');
			} else {
				message(-1, '未知操作');
			}

			message(0, lang('admin_recycle_post_' . ($op == 'restore' ? 'restore' : 'hard_delete') . '_successfully', array('count'=>$success_count)));
		} else {
			// 帖子回收站操作
			$tids_str = param('tids');
			$tids = $tids_str ? explode(',', $tids_str) : array();

			if(empty($tids)) {
				message(-1, lang('admin_thread_no_selection'));
			}

			$valid_tids = array();
			foreach($tids as $tid) {
				$tid = intval($tid);
				if($tid > 0) $valid_tids[] = $tid;
			}

			if(empty($valid_tids)) {
				message(-1, lang('admin_thread_no_selection'));
			}

			$success_count = 0;
			if($op == 'restore') {
				thread_restore_batch($valid_tids);
				$success_count = count($valid_tids);
				admin_log_create('thread_restore', 'thread', $valid_tids, '恢复主题 ' . $success_count . ' 篇');
			} elseif($op == 'hard_delete') {
				foreach($valid_tids as $tid) {
					thread_delete($tid);
					$success_count++;
				}
				admin_log_create('thread_hard_delete', 'thread', $valid_tids, '彻底删除主题 ' . $success_count . ' 篇');
			} else {
				message(-1, '未知操作');
			}

			message(0, lang('admin_recycle_' . ($op == 'restore' ? 'restore' : 'hard_delete') . '_successfully', array('count'=>$success_count)));
		}
	}
}

// hook admin_thread_start.php

?>
