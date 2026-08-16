<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook admin_thread_start.php

$pagesize = param('pagesize', 100);
if(!in_array($pagesize, array(20, 50, 100, 200))) $pagesize = 100;

// 排序参数白名单，防止 SQL 注入
$orderby_key = param('orderby', 'create_desc');
$allow_orderby = array('tid_desc', 'tid_asc', 'create_desc', 'create_asc');
if(!in_array($orderby_key, $allow_orderby)) $orderby_key = 'create_desc';

// 排序键 -> thread_find 排序数组
function admin_thread_orderby_to_sql($key) {
	switch($key) {
		case 'tid_asc':        return array('tid'=>1);
		case 'create_desc':    return array('create_date'=>-1);
		case 'create_asc':     return array('create_date'=>1);
		default:               return array('tid'=>-1);
	}
}

// 构造搜索条件（list/found 共用）
function admin_thread_build_cond() {
	$_uid = param('uid');
	// 空字符串视为未输入，避免匹配 username='' 的脏数据用户
	if($_uid === '' || $_uid === null) {
		$_uid = 0;
	} elseif(!is_numeric($_uid)) {
		$_user = user_read_by_username($_uid);
		$_uid = !empty($_user['uid']) ? $_user['uid'] : 0;
	} else {
		$_uid = intval($_uid);
	}
	$fid = param('fid', 0);
	$create_date_start = param('create_date_start') ? strtotime(param('create_date_start')) : 0;
	$create_date_end = param('create_date_end') ? strtotime(param('create_date_end')) : 0;
	$userip = param('userip');
	$keyword = trim(param('keyword'));
	$status = param('status', 'all');

	$cond = array('is_deleted'=>0);
	$fid AND $cond['fid'] = $fid;
	$_uid AND $cond['uid'] = $_uid;
	if($create_date_start || $create_date_end) {
		// 日期范围：end 含当天 23:59:59
		$cond['create_date'] = array();
		$create_date_start AND $cond['create_date']['>='] = $create_date_start;
		$create_date_end AND $cond['create_date']['<='] = $create_date_end + 86399;
	}
	if($userip) $cond['userip'] = sprintf('%u', ip2long($userip));
	if($keyword) $cond['subject'] = array('LIKE' => $keyword);
	// 状态过滤：top>0 / digest>0 利用 db_cond_to_sqladd 的比较符后缀解析
	if($status === 'digest') $cond['digest>'] = 0;
	elseif($status === 'top') $cond['top>'] = 0;
	elseif($status === 'announcement') $cond['is_announcement'] = 1;
	elseif($status === 'closed') $cond['closed'] = 1;
	return $cond;
}

if(empty($action) || $action == 'list') {

	$header['title'] = lang('thread_admin');
	$header['mobile_title'] = lang('thread_admin');

	// hook admin_thread_list_start.php

	include _include(ADMIN_PATH."view/htm/thread_list.htm");

// 批量操作（基于选中 tid）
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
		// 清除受影响版块的帖子列表缓存（db_update 绕过模型层缓存清理）
		$_affected_fids = array();
		foreach(thread_find_by_tids($valid_tids) as $_t) { $_affected_fids[$_t['fid']] = 1; }
		foreach($_affected_fids as $_fid => $_) { thread_forum_list_cache_delete($_fid); }
	} elseif($op == 'open') {
		db_update('thread', array('tid'=>$valid_tids), array('closed'=>0));
		$success_count = count($valid_tids);
		// 清除受影响版块的帖子列表缓存（db_update 绕过模型层缓存清理）
		$_affected_fids = array();
		foreach(thread_find_by_tids($valid_tids) as $_t) { $_affected_fids[$_t['fid']] = 1; }
		foreach($_affected_fids as $_fid => $_) { thread_forum_list_cache_delete($_fid); }
	} elseif($op == 'top') {
	// 读取用户选择的置顶级别（0=取消，1=版块置顶，3=全局置顶），默认 1
	$top_value = param('top', 1);
	$top_value = in_array($top_value, array(0, 1, 3), true) ? intval($top_value) : 1;

	// 用 thread_top_change_batch 同步 thread.top + thread_top 表（避免状态不一致）
	$_top_threadlist = thread_find_by_tids($valid_tids);
	$success_count = thread_top_change_batch($valid_tids, $_top_threadlist, $top_value);

	// 清除受影响版块的帖子列表缓存（thread_top_change_batch 已清理置顶缓存）
	$_affected_fids = array();
	foreach($_top_threadlist as $_t) { $_affected_fids[$_t['fid']] = 1; }
	foreach($_affected_fids as $_fid => $_) { thread_forum_list_cache_delete($_fid); }
} elseif($op == 'digest') {
		// 与前台一致：支持 0/1/2/3 四级，前端传 digest 参数指定级别
		$digest_value = param('digest', 1);
		$digest_value = in_array($digest_value, array(0, 1, 2, 3), true) ? intval($digest_value) : 1;
		$threadlist = thread_find_by_tids($valid_tids);
		if($threadlist) {
			// 批量更新精华状态（涉及 thread_digest 表 + thread.digest 字段 + user/forum 统计）
			thread_digest_change_batch($valid_tids, $threadlist, $digest_value);
			$success_count = count($valid_tids);
			// 清除受影响版块的帖子列表缓存和 forumlist 缓存
			$_affected_fids = array();
			foreach($threadlist as $_t) { $_affected_fids[$_t['fid']] = 1; }
			foreach($_affected_fids as $_fid => $_) {
				thread_forum_list_cache_delete($_fid);
				forum_list_cache_delete();
			}
		}
	} elseif($op == 'announcement') {
	// 显式 0/1：与前台 mod.php 一致，前端 dropdown 传 announcement 参数
	$announcement_value = param('announcement', 1);
	$announcement_value = $announcement_value ? 1 : 0;
	$threadlist = thread_find_by_tids($valid_tids);
	if($threadlist) {
		// 批量更新：is_announcement + announcement_order（取消时 order 归零，与前台 mod.php 一致）
		$update_data = array(
			'is_announcement' => $announcement_value,
			'announcement_order' => $announcement_value ? 0 : 0
		);
		foreach($valid_tids as $tid) {
			thread_update($tid, $update_data);
			$success_count++;
		}
	}
	// 清除公告侧边栏缓存和受影响版块的帖子列表缓存
	cache_delete('sidebar_announcements');
	$_affected_fids = array();
	foreach($threadlist as $_t) { $_affected_fids[$_t['fid']] = 1; }
	foreach($_affected_fids as $_fid => $_) { thread_forum_list_cache_delete($_fid); }
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
		$op_labels = array('delete'=>lang('admin_delete'), 'close'=>lang('admin_label_close'), 'open'=>lang('admin_label_open'), 'top'=>lang('admin_status_top'), 'digest'=>lang('admin_label_digest'), 'announcement'=>lang('admin_thread_set_announcement'), 'move'=>lang('admin_label_move'));
		$detail = lang('admin_log_thread_batch_op', array('op'=>($op_labels[$op] ?? $op), 'n'=>$success_count));
		if($op == 'move') {
			$target_forum = forum_read($target_fid);
			$detail .= ' → ' . ($target_forum ? $target_forum['name'] : 'fid:' . $target_fid);
		}
		admin_log_create($op == 'delete' ? 'thread_batch_delete' : 'thread_' . $op, 'thread', $tids, $detail);
	}
	message(0, lang('admin_thread_batch_success') . ' (' . $success_count . ')');

// 搜索结果展示（直接 SELECT 查询，搜索条件转 SQL WHERE，ORDER BY 全局排序，LIMIT 分页）
} elseif($action == 'found') {

	$page = param(2, 1);
	$cond = admin_thread_build_cond();
	$total = db_count('thread', $cond);
	$pagination = pagination(route_url('admin_thread_found'), $total, $page, $pagesize);
	// hook admin_thread_found_start.php
	// 管理员需要管理所有未删除帖子（含待审），gid=1,2 在后台上下文，不过滤 audit_status
	$threadlist = thread_find($cond, admin_thread_orderby_to_sql($orderby_key), $page, $pagesize);

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
				admin_log_create('post_restore', 'post', $valid_pids, lang('admin_log_post_restore', array('n'=>$success_count)));
			} elseif($op == 'hard_delete') {
				$success_count = post_hard_delete_batch($valid_pids);
				admin_log_create('post_hard_delete', 'post', $valid_pids, lang('admin_log_post_hard_delete', array('n'=>$success_count)));
			} else {
				message(-1, lang('admin_unknown_action'));
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
				admin_log_create('thread_restore', 'thread', $valid_tids, lang('admin_log_thread_restore', array('n'=>$success_count)));
			} elseif($op == 'hard_delete') {
				foreach($valid_tids as $tid) {
					thread_delete($tid);
					$success_count++;
				}
				admin_log_create('thread_hard_delete', 'thread', $valid_tids, lang('admin_log_thread_hard_delete', array('n'=>$success_count)));
			} else {
				message(-1, lang('admin_unknown_action'));
			}

			message(0, lang('admin_recycle_' . ($op == 'restore' ? 'restore' : 'hard_delete') . '_successfully', array('count'=>$success_count)));
		}
	}
}

// hook admin_thread_start.php

?>
