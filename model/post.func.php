<?php

// hook model_post_start.php

// ------------> 最原生的 CURD，无关联其他数据。

// 只用传 message, message_fmt 自动生成
function post__create($arr, $gid) {
	// hook model_post__create_start.php
	
	post_message_fmt($arr, $gid);
	
	// hook model_post__create_insert_before.php
	
	$r = db_insert('post', $arr);
	// hook model_post__create_end.php
	return $r;
}

function post__update($pid, $arr) {
	// hook model_post__update_start.php
	$r = db_update('post', array('pid'=>$pid), $arr);
	// 清除 post__read 的请求级缓存
	global $g_post_read_cache;
	unset($g_post_read_cache[$pid]);
	// hook model_post__update_end.php
	return $r;
}

function post__read($pid) {
	// hook model_post__read_start.php
	global $g_post_read_cache;
	if (!is_array($g_post_read_cache)) $g_post_read_cache = array();
	if (isset($g_post_read_cache[$pid])) return $g_post_read_cache[$pid];
	$post = db_find_one('post', array('pid'=>$pid));
	$g_post_read_cache[$pid] = $post;
	// hook model_post__read_end.php
	return $post;
}

function post__delete($pid) {
	// hook model_post__delete_start.php
	$r = db_delete('post', array('pid'=>$pid));
	// 清除 post__read 的请求级缓存
	global $g_post_read_cache;
	unset($g_post_read_cache[$pid]);
	// hook model_post__delete_end.php
	return $r;
}

function post__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook model_post__find_start.php
	// 软删除过滤：自动排除已删除回帖
	if(!isset($cond['is_deleted'])) {
		$cond['is_deleted'] = 0;
	}
	$postlist = db_find('post', $cond, $orderby, $page, $pagesize, 'pid');
	// hook model_post__find_end.php
	return $postlist;
}

// ------------> 关联 CURD，主要是强相关的数据，比如缓存。弱相关的大量数据需要另外处理。

// 回帖
function post_create($arr, $fid, $gid) {
	global $conf, $time;
	
	// hook model_post_create_start.php
	
	$pid = post__create($arr, $gid);
	if(!$pid) return $pid;
	
	$tid = $arr['tid'];
	$uid = $arr['uid'];

	// 回帖
	if($tid > 0) {
		// 待审评论不计入帖子和用户的 posts 计数，审核通过后由 AuditService::approve 补加
		$audit_status = isset($arr['audit_status']) ? intval($arr['audit_status']) : 1;
		if($audit_status == 1) {
			// todo: 如果是老帖，不更新 lastpid
			thread__update($tid, array('posts+'=>1, 'lastpid'=>$pid, 'lastuid'=>$uid, 'last_date'=>$time));
			$uid AND user__update($uid, array('posts+'=>1));
			runtime_set('posts+', 1);
			runtime_set('todayposts+', 1);
			forum__update($fid, array('todayposts+'=>1));
		} else {
			// 待审评论仅更新 lastpid，不增加 posts 计数
			thread__update($tid, array('lastpid'=>$pid, 'lastuid'=>$uid, 'last_date'=>$time));
		}
	}
	
	//post_list_cache_delete($tid);

	// 失效该帖子的回帖列表缓存（递增版本号，使旧缓存自动失效）
	post_list_cache_bump_version($tid);

	// 更新板块信息。
	forum_list_cache_delete();
	// 清除首页帖子列表缓存（新回复更新 lastpid 影响首页排序，60s 内可见）
	if(function_exists('index_list_cache_delete')) {
		index_list_cache_delete();
	}
	
	// 关联附件
	$message = $arr['message'];
	attach_assoc_post($pid);
	
	// 更新用户的用户组
	user_update_group($uid);
	
	// hook model_post_create_end.php
	
	return $pid;
}

// 编辑回帖
function post_update($pid, $arr, $tid = 0) {
	global $conf, $user, $gid;

	$post = post__read($pid);
	if(empty($post)) return FALSE;
	$tid = $post['tid'];
	$uid = $post['uid'];
	$isfirst = $post['isfirst'];
	
	// hook model_post_update_start.php

	
	post_message_fmt($arr, $gid);
	
	// hook model_post_create_post__create_before.php
	
	$r = post__update($pid, $arr);

	// 失效该帖子的回帖列表缓存（编辑后立即生效，与 post_create/post_delete 对称）
	post_list_cache_bump_version($tid);

	attach_assoc_post($pid);

	// hook model_post_update_end.php
	return $r;
}

function post_read($pid) {
	// hook model_post_read_start.php
	$post = post__read($pid);
	// 软删除过滤：已删除回帖对前台等同于不存在
	if(!empty($post) && !empty($post['is_deleted'])) {
		return array();
	}
	post_format($post);
	// hook model_post_read_end.php
	return $post;
}

// 从缓存中读取，避免重复从数据库取数据，主要用来前端显示，可能有延迟。重要业务逻辑不要调用此函数，数据可能不准确，因为并没有清理缓存，针对 request 生命周期有效。
function post_read_cache($pid) {
	// hook model_post_read_cache_start.php
	static $cache = array(); // 用静态变量只能在当前 request 生命周期缓存，要跨进程，可以再加一层缓存： memcached/xcache/apc/
	if(isset($cache[$pid])) return $cache[$pid];
	$cache[$pid] = post_read($pid);
	// hook model_post_read_cache_end.php
	return $cache[$pid];
}

// $tid 用来清理缓存
function post_delete($pid) {
	global $conf;
	$post = post_read_cache($pid);
	if(empty($post)) return TRUE; // 已经不存在了。
	
	$tid = $post['tid'];
	$uid = $post['uid'];
	$thread = thread_read_cache($tid);
	$fid = $thread['fid'];
	
	// hook model_post_delete_start.php
	
	if(!$post['isfirst']) {
		// 待审评论创建时未计入 posts，删除时也不应减少
		$audit_status = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
		if($audit_status == 1) {
			thread_dec($tid, 'posts', 1);
			$uid AND user_dec($uid, 'posts', 1);
			runtime_set('posts-', 1);
		}
	} else {
		//post_list_cache_delete($tid);
	}
	
	($post['images'] || $post['files']) AND attach_delete_by_pid($pid);

	$r = post__delete($pid);

	// 更新最后的 lastpid
	if($r && !$post['isfirst'] && $pid == $thread['lastpid']) {
		thread_update_last($tid);
	}

	// 失效回帖列表缓存
	$r AND post_list_cache_bump_version($tid);

	// hook model_post_delete_end.php
	return $r;
}

// 软删除单个回帖（标记 is_deleted=1）
function post_soft_delete($pid, $deleted_by) {
	global $time;
	$post = post__read($pid);
	if(empty($post) || intval($post['is_deleted']) == 1) return TRUE;

	$tid = $post['tid'];
	$uid = $post['uid'];
	$isfirst = $post['isfirst'];

	// hook model_post_soft_delete_start.php

	// 标记回帖为已删除
	post__update($pid, array('is_deleted'=>1, 'deleted_date'=>$time, 'deleted_by'=>intval($deleted_by)));

	// 减计统计（仅审核通过的非首帖）
	if(!$isfirst) {
		$audit_status = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
		if($audit_status == 1) {
			thread_dec($tid, 'posts', 1);
			$uid AND user_dec($uid, 'posts', 1);
			runtime_set('posts-', 1);
		}
	}

	// 如果该 post 是 thread 的 lastpid，重算
	$thread = thread__read($tid);
	if(!empty($thread) && $pid == $thread['lastpid']) {
		thread_update_last($tid);
	}

	// 失效回帖列表缓存
	post_list_cache_bump_version($tid);

	// hook model_post_soft_delete_end.php
	return TRUE;
}

// 恢复单个已软删除的回帖
function post_restore($pid) {
	$post = post__read($pid);
	if(empty($post) || intval($post['is_deleted']) == 0) return TRUE;

	$tid = $post['tid'];
	$uid = $post['uid'];
	$isfirst = $post['isfirst'];

	// hook model_post_restore_start.php

	// 恢复回帖
	post__update($pid, array('is_deleted'=>0, 'deleted_date'=>0, 'deleted_by'=>0));

	// 补计统计（仅审核通过的非首帖）
	if(!$isfirst) {
		$audit_status = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
		if($audit_status == 1) {
			thread__update($tid, array('posts+'=>1));
			$uid AND user__update($uid, array('posts+'=>1));
			runtime_set('posts+', 1);
		}
	}

	// 重算 lastpid
	thread_update_last($tid);

	// 失效回帖列表缓存
	post_list_cache_bump_version($tid);

	// hook model_post_restore_end.php
	return TRUE;
}

// 递增 thread_pl_v_{tid} 版本号，使回帖列表 60s 短缓存立即失效
// post_create / post_soft_delete / post_restore / post_delete 等改变回帖列表的操作都应调用
function post_list_cache_bump_version($tid) {
	$tid = intval($tid);
	if($tid <= 0) return;
	$_pl_v_key = 'thread_pl_v_' . $tid;
	$_old_v = class_exists('CacheHelper', false) ? CacheHelper::get(CacheHelper::pluginKey($_pl_v_key)) : NULL;
	$_new_v = ($_old_v === NULL || $_old_v === FALSE) ? 1 : intval($_old_v) + 1;
	if(class_exists('CacheHelper', false)) {
		CacheHelper::set(CacheHelper::pluginKey($_pl_v_key), $_new_v, 86400);
	}
}

// 此处有可能会超时
function post_delete_by_tid($tid) {
	// hook model_post_delete_by_tid_start.php
	// 使用 post__find 避免触发 post_format 的 N+1 查询，获取所有回帖
	$postlist = post__find(array('tid'=>$tid), array('pid'=>1), 1, 1000000);
	if(empty($postlist)) return 0;

	// 批量收集需要删除附件的 pid
	$pids_with_attach = array();
	foreach($postlist as $post) {
		if(($post['images'] || $post['files']) && $post['pid']) {
			$pids_with_attach[] = $post['pid'];
		}
	}

	// 批量删除附件（物理文件 + 数据库记录）
	if(!empty($pids_with_attach)) {
		global $conf;
		$attachlist = db_find('attach', array('pid'=>$pids_with_attach), array(), 1, count($pids_with_attach) * 100);
		if($attachlist) {
			foreach($attachlist as $attach) {
				$path = $conf['upload_path'].'attach/'.$attach['filename'];
				file_exists($path) AND unlink($path);
				$thumb_path = attach_thumb_path($attach['filename']);
				if($thumb_path) {
					$full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
					file_exists($full_thumb_path) AND unlink($full_thumb_path);
				}
			}
			db_delete('attach', array('pid'=>$pids_with_attach));
		}
	}

	// 批量删除回帖
	$n = db_delete('post', array('tid'=>$tid));

	// 更新用户 posts 统计（仅审核通过的非首帖，待审评论创建时未计入）
	$user_post_count = array();
	$non_first_count = 0;
	foreach($postlist as $post) {
		if($post['isfirst']) continue;
		$_audit = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
		if($_audit != 1) continue; // 待审评论不计入
		$non_first_count++;
		if($post['uid']) {
			if(!isset($user_post_count[$post['uid']])) $user_post_count[$post['uid']] = 0;
			$user_post_count[$post['uid']]++;
		}
	}
	foreach($user_post_count as $_uid => $cnt) {
		user_dec($_uid, 'posts', $cnt);
	}

	// 更新全站统计
	$non_first_count AND runtime_set('posts-', $non_first_count);

	// hook model_post_delete_by_tid_end.php
	return $n;
}

// 批量删除多个主题的所有回帖，合并查询消除 N+1（用于 thread_delete_batch）
// 内部逻辑与 post_delete_by_tid 一致，但将 find attach / delete post / 统计更新全部合并为单次查询
function post_delete_by_tids_batch($tids) {
	// hook model_post_delete_by_tids_batch_start.php
	if(empty($tids) || !is_array($tids)) return 0;

	$tids = array_map('intval', $tids);
	$tids = array_unique($tids);
	$tids = array_filter($tids);
	if(empty($tids)) return 0;

	// 一次查询获取所有回帖
	$postlist = db_find('post', array('tid'=>$tids), array(), 1, 1000000, 'pid');
	if(empty($postlist)) return 0;

	// 批量收集需要删除附件的 pid
	$pids_with_attach = array();
	foreach($postlist as $post) {
		if(($post['images'] || $post['files']) && $post['pid']) {
			$pids_with_attach[] = $post['pid'];
		}
	}

	// 批量删除附件（物理文件 + 数据库记录，一次查询）
	if(!empty($pids_with_attach)) {
		global $conf;
		$attachlist = db_find('attach', array('pid'=>$pids_with_attach), array(), 1, count($pids_with_attach) * 100);
		if($attachlist) {
			foreach($attachlist as $attach) {
				$path = $conf['upload_path'].'attach/'.$attach['filename'];
				file_exists($path) AND unlink($path);
				$thumb_path = attach_thumb_path($attach['filename']);
				if($thumb_path) {
					$full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
					file_exists($full_thumb_path) AND unlink($full_thumb_path);
				}
			}
			db_delete('attach', array('pid'=>$pids_with_attach));
		}
	}

	// 批量删除所有回帖（一次 DELETE）
	$n = db_delete('post', array('tid'=>$tids));

	// 汇总用户 posts 统计（仅审核通过的非首帖，待审评论创建时未计入）
	$user_post_count = array();
	$non_first_count = 0;
	foreach($postlist as $post) {
		if($post['isfirst']) continue;
		$_audit = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
		if($_audit != 1) continue; // 待审评论不计入
		$non_first_count++;
		if($post['uid']) {
			if(!isset($user_post_count[$post['uid']])) $user_post_count[$post['uid']] = 0;
			$user_post_count[$post['uid']]++;
		}
	}
	foreach($user_post_count as $_uid => $cnt) {
		user_dec($_uid, 'posts', $cnt);
	}

	// 更新全站统计
	$non_first_count AND runtime_set('posts-', $non_first_count);

	// hook model_post_delete_by_tids_batch_end.php
	return $n;
}

// 此处有可能会超时，并且导致统计不准确，需要重建统计数
// ponytail: 原实现仅 db_delete 不维护 user.posts / runtime.posts 统计，
// 被 UserBanService::clearUserContent 和 user_delete() 调用，导致 runtime.posts 永久偏高、user.posts 与实际脱节
// 现在删除前先汇总该用户「审核通过且未软删的非首帖」数量，删除后用 user_dec / runtime_set 递减
function post_delete_by_uid($uid) {
	// hook model_post_delete_by_uid_start.php
	$uid = intval($uid);
	if($uid <= 0) return 0;

	// 先统计待删除的审核通过且未软删的非首帖数（首帖不计入 posts；待审/已软删不计入）
	$non_first_count = 0;
	$postlist = post__find(array('uid'=>$uid), array(), 1, 1000000);
	if($postlist) {
		foreach($postlist as $post) {
			if($post['isfirst']) continue;
			$_audit = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
			if($_audit != 1) continue;
			$_is_deleted = isset($post['is_deleted']) ? intval($post['is_deleted']) : 0;
			if($_is_deleted) continue;
			$non_first_count++;
		}
	}

	$r = db_delete('post', array('uid'=>$uid));

	// 递减统计（GREATEST 下限保护 + runtime_set max 保护）
	if($non_first_count > 0) {
		user_dec($uid, 'posts', $non_first_count);
		runtime_set('posts-', $non_first_count);
	}

	// hook model_post_delete_by_uid_end.php
	return $r;
}

function post_find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	global $uid, $g_preloaded_post_likes;
	// hook model_post_find_start.php
	$postlist = post__find($cond, $orderby, $page, $pagesize);
	$floor = 1;
	if($postlist) {
		// 批量预加载用户数据，消除 N+1 查询
		$uids = arrlist_values($postlist, 'uid');
		user_preload($uids);

		// 批量预加载 thread 数据，消除 post_format 中 thread_read_cache 的隐藏查询
		// 参考 post_find_by_tid 的做法：调用 thread_read_cache 预填充 static 缓存
		// 去重后每个 tid 只查询一次，后续 post_format 内的 thread_read_cache 命中缓存
		$tids = arrlist_values($postlist, 'tid');
		$tids = array_unique($tids);
		foreach($tids as $tid) {
			thread_read_cache($tid);
		}

		// 批量预加载点赞状态
		if(!empty($uid) && !isset($g_preloaded_post_likes)) {
			$pids = arrlist_values($postlist, 'pid');
			$g_preloaded_post_likes = post_like_read_batch($uid, $pids);
		}

		foreach($postlist as &$post) {
			$post['floor'] = $floor++;
			post_format($post);
		}
	}
	// hook model_post_find_end.php
	return $postlist;
}

// 此处有缓存，是否有必要？
function post_find_by_tid($tid, $page = 1, $pagesize = 50, $orderby = array('pid'=>1)) {
	global $conf, $uid, $g_preloaded_post_likes;

	// hook model_post_find_by_tid_start.php

	$postlist = post__find(array('tid'=>$tid), $orderby, $page, $pagesize);

	if($postlist) {
		// 批量预加载用户数据，消除 N+1 查询
		$uids = arrlist_values($postlist, 'uid');
		user_preload($uids);

		// 预加载 thread 数据（同一 tid 所有回帖共享一个 thread）
		$thread = thread_read_cache($tid);

		// 批量预加载点赞状态
		if(!empty($uid) && !isset($g_preloaded_post_likes)) {
			$pids = arrlist_values($postlist, 'pid');
			$g_preloaded_post_likes = post_like_read_batch($uid, $pids);
		}

		$floor = ($page - 1)* $pagesize + 1;
		foreach($postlist as &$post) {
			$post['floor'] = $floor++;
			post_format($post);
		}
	}

	// hook model_post_find_by_tid_end.php
	return $postlist;
}

// 查找帖子下已删除回复（回收站用）
function post_find_deleted_by_tid($tid, $page = 1, $pagesize = 50) {
	// hook model_post_find_deleted_by_tid_start.php
	$postlist = post__find(array('tid'=>$tid, 'is_deleted'=>1), array('pid'=>1), $page, $pagesize);
	if($postlist) {
		$uids = arrlist_values($postlist, 'uid');
		user_preload($uids);
		foreach($postlist as &$post) {
			post_format($post);
		}
	}
	// hook model_post_find_deleted_by_tid_end.php
	return $postlist;
}

// 查找全站已软删除回复（后台回收站用，支持按 fid/keyword 筛选）
// post 表无 fid 字段，需 LEFT JOIN thread 获取 fid/subject；keyword 匹配 post.message 或 thread.subject
// 保留 db_sql_find：JOIN 查询 db_find 不支持
function post_find_deleted($cond = array(), $orderby = array('pid'=>-1), $page = 1, $pagesize = 20) {
	global $db;
	// hook model_post_find_deleted_start.php

	$tablepre = $db->tablepre;

	$where = array('p.is_deleted'=>1);
	$join_args = array();

	$filter_fid = isset($cond['fid']) ? intval($cond['fid']) : 0;
	$filter_keyword = isset($cond['keyword']) ? trim($cond['keyword']) : '';
	$filter_tid = isset($cond['tid']) ? intval($cond['tid']) : 0;

	if($filter_fid > 0) {
		$where['t.fid'] = $filter_fid;
	}
	if($filter_tid > 0) {
		$where['p.tid'] = $filter_tid;
	}

	$sql = "SELECT p.pid, p.tid, p.uid, p.isfirst, p.create_date, p.deleted_date, p.deleted_by, p.message, t.fid, t.subject FROM {$tablepre}post p LEFT JOIN {$tablepre}thread t ON p.tid=t.tid WHERE ";
	$cond_parts = array();
	foreach($where as $k=>$v) {
		$cond_parts[] = "$k=" . intval($v);
	}
	if($filter_keyword !== '') {
		$cond_parts[] = "(p.message LIKE '%" . addcslashes($filter_keyword, "'%_\\") . "%' OR t.subject LIKE '%" . addcslashes($filter_keyword, "'%_\\") . "%')";
	}
	$sql .= implode(' AND ', $cond_parts);

	$orderby_field = 'p.pid';
	if(!empty($orderby)) {
		$_f = array_keys($orderby);
		$_f = $_f[0];
		$_d = intval($orderby[$_f]);
		if(in_array($_f, array('pid', 'deleted_date', 'create_date'))) {
			$orderby_field = $_f;
		}
		$orderby_dir = $_d < 0 ? 'DESC' : 'ASC';
	} else {
		$orderby_dir = 'DESC';
	}
	$sql .= " ORDER BY $orderby_field $orderby_dir";

	$offset = max(0, ($page - 1) * $pagesize);
	$sql .= " LIMIT $offset, $pagesize";

	$postlist = db_sql_find($sql);

	if($postlist) {
		$uids = arrlist_values($postlist, 'uid');
		$deleted_by_uids = arrlist_values($postlist, 'deleted_by');
		$uids = array_unique(array_merge($uids, $deleted_by_uids));
		user_preload($uids);
		foreach($postlist as &$post) {
			// 仅取 message 前 200 字符作为预览，避免列表过长
			$post['message_preview'] = xn_substr(trim(strip_tags($post['message'])), 0, 200);
			unset($post['message']);
		}
		unset($post);
	}

	// hook model_post_find_deleted_end.php
	return $postlist;
}

// 统计全站已软删除回复数量（带筛选）
function post_count_deleted($cond = array()) {
	global $db;
	// hook model_post_count_deleted_start.php

	$tablepre = $db->tablepre;

	$where = array('p.is_deleted'=>1);
	$filter_fid = isset($cond['fid']) ? intval($cond['fid']) : 0;
	$filter_keyword = isset($cond['keyword']) ? trim($cond['keyword']) : '';
	$filter_tid = isset($cond['tid']) ? intval($cond['tid']) : 0;

	if($filter_fid > 0) {
		$where['t.fid'] = $filter_fid;
	}
	if($filter_tid > 0) {
		$where['p.tid'] = $filter_tid;
	}

	$sql = "SELECT COUNT(*) as cnt FROM {$tablepre}post p LEFT JOIN {$tablepre}thread t ON p.tid=t.tid WHERE ";
	$cond_parts = array();
	foreach($where as $k=>$v) {
		$cond_parts[] = "$k=" . intval($v);
	}
	if($filter_keyword !== '') {
		$cond_parts[] = "(p.message LIKE '%" . addcslashes($filter_keyword, "'%_\\") . "%' OR t.subject LIKE '%" . addcslashes($filter_keyword, "'%_\\") . "%')";
	}
	$sql .= implode(' AND ', $cond_parts);

	$row = db_sql_find_one($sql);
	// hook model_post_count_deleted_end.php
	return !empty($row['cnt']) ? intval($row['cnt']) : 0;
}

// 批量恢复已软删除回帖
function post_restore_batch($pids) {
	// hook model_post_restore_batch_start.php
	if(empty($pids) || !is_array($pids)) return 0;
	$pids = array_map('intval', $pids);
	$pids = array_unique($pids);
	$pids = array_filter($pids);
	if(empty($pids)) return 0;

	$success = 0;
	foreach($pids as $pid) {
		if(post_restore($pid)) {
			$success++;
		}
	}
	// hook model_post_restore_batch_end.php
	return $success;
}

// 批量彻底删除回帖（物理删除，含附件清理）
function post_hard_delete_batch($pids) {
	// hook model_post_hard_delete_batch_start.php
	if(empty($pids) || !is_array($pids)) return 0;
	$pids = array_map('intval', $pids);
	$pids = array_unique($pids);
	$pids = array_filter($pids);
	if(empty($pids)) return 0;

	$success = 0;
	foreach($pids as $pid) {
		if(post_hard_delete($pid)) {
			$success++;
		}
	}
	// hook model_post_hard_delete_batch_end.php
	return $success;
}

// 物理删除回帖（不经过 post_read_cache 的 is_deleted 过滤，可直接删除已软删除的 post）
// 与 post_delete 区别：post_delete 用 post_read_cache（前台过滤 is_deleted），本函数用 post__read（原始读取）
// 注意：如果 post 已软删除，统计已经在 post_soft_delete 中减过，此处不再减统计
function post_hard_delete($pid) {
	global $conf;
	$post = post__read($pid);
	if(empty($post)) return TRUE;

	$tid = $post['tid'];
	$uid = $post['uid'];
	$is_deleted = intval(isset($post['is_deleted']) ? $post['is_deleted'] : 0);

	// hook model_post_hard_delete_start.php

	// 清理附件（物理文件 + 数据库记录）
	if($post['images'] || $post['files']) {
		attach_delete_by_pid($pid);
	}

	$r = post__delete($pid);

	// 仅当 post 未软删除时才减统计（已软删除的统计在 post_soft_delete 中已减）
	if($r && !$is_deleted) {
		if(!$post['isfirst']) {
			$audit_status = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
			if($audit_status == 1) {
				thread_dec($tid, 'posts', 1);
				$uid AND user_dec($uid, 'posts', 1);
				runtime_set('posts-', 1);
			}
		}
		// 更新 lastpid
		$thread = thread__read($tid);
		if(!empty($thread) && $pid == $thread['lastpid']) {
			thread_update_last($tid);
		}
	}

	// 失效回帖列表缓存
	$r AND post_list_cache_bump_version($tid);

	// hook model_post_hard_delete_end.php
	return $r;
}

// <img src="/view/img/face/1.gif"/>
// <blockquote class="blockquote">
function user_post_message_format(&$s) {
	if(xn_strlen($s) < 100) return;
	$s = preg_replace('#<blockquote\s+class="blockquote"[^>]*>.*?</blockquote>#is', '', $s);
	$s = str_ireplace(array('<br>', '<br />', '<br/>', '</p>', '</tr>', '</div>', '</li>', '</dd>'. '</dt>'), "\r\n", $s);
	$s = str_ireplace(array('&nbsp;'), " ", $s);
	$s = strip_tags($s);
	$s = preg_replace('#[\r\n]+#', "\n", $s);
	$s = xn_substr(trim($s), 0, 100);
	$s = str_replace("\n", '<br>', $s);
}


/*
function post_list_cache_delete($tid) {
	// hook model_post_list_cache_delete_start.php
	global $conf;
	$r = cache_delete("postlist_$tid");
	// hook model_post_list_cache_delete_end.php
	return $r;
}*/

// ------------> 其他方法

function post_count($cond = array()) {
	// hook model_post_count_start.php
	$n = db_count('post', $cond);
	// hook model_post_count_end.php
	return $n;
}

function post_maxid() {
	// hook model_post_maxid_start.php
	$n = db_maxid('post', 'pid');
	// hook model_post_maxid_end.php
	return $n;
}

function post_safe_info($post) {
	// hook model_post_safe_info_start.php
	unset($post['userip']);
	if(!empty($post['user'])) {
		$post['user'] = user_safe_info($post['user']);
	}
	// hook model_post_safe_info_end.php
	return $post;
}

function post_find_by_pids($pids, $order = array('pid'=>-1)) {
	global $uid, $g_preloaded_post_likes;
	// hook model_post_find_by_pids_start.php
	if(!$pids) return array();
	$postlist = db_find('post', array('pid'=>$pids), $order, 1, 1000, 'pid');
	if($postlist) {
		// 批量预加载用户数据，消除 N+1 查询
		$uids = arrlist_values($postlist, 'uid');
		user_preload($uids);

		// 批量预加载点赞状态
		if(!empty($uid) && !isset($g_preloaded_post_likes)) {
			$pidlist = arrlist_values($postlist, 'pid');
			$g_preloaded_post_likes = post_like_read_batch($uid, $pidlist);
		}

		foreach($postlist as &$post) post_format($post);
	}
	// hook model_post_find_by_pids_end.php
	return $postlist;
}

/**
 * 按用户 id 分页查询回帖（带版块读权限过滤，SQL 层完成，避免分页后过滤导致每页条数不一致）
 * post 表无 fid 字段，需 JOIN thread 表才能按版块权限过滤，故无法用 post_count/post_find 封装
 *
 * @param int $uid 被查看的用户 id
 * @param array $accessible_fids 可读版块 fid 列表；为 NULL 表示不做版块过滤（管理员/自己）
 * @param bool $audit_only 是否只查 audit_status=1（非管理员看他人回帖时为 TRUE）
 * @param int $page 页码
 * @param int $pagesize 每页条数
 * @return array [totalnum, postlist]
 */
function post_find_by_uid_with_forum_access($uid, $accessible_fids, $audit_only, $page, $pagesize) {
	global $db;
	// hook model_post_find_by_uid_with_forum_access_start.php
	$tablepre = $db->tablepre;
	$uid = intval($uid);
	$page = max(1, intval($page));
	$pagesize = max(1, intval($pagesize));
	$offset = ($page - 1) * $pagesize;

	$where = "p.uid={$uid} AND p.isfirst=0 AND p.is_deleted=0";
	if($audit_only) $where .= " AND p.audit_status=1";

	// 无可见版块：直接返回空，避免 fid IN() 无效 SQL
	if(is_array($accessible_fids)) {
		if(empty($accessible_fids)) return array(0, array());
		$fid_in = implode(',', array_map('intval', $accessible_fids));
		$where .= " AND t.fid IN ({$fid_in})";
	}

	// JOIN thread 查询，db_find 不支持 JOIN，保留 db_sql_find
	$count_sql = "SELECT COUNT(*) AS cnt FROM {$tablepre}post p INNER JOIN {$tablepre}thread t ON p.tid=t.tid WHERE {$where}";
	$row = db_sql_find_one($count_sql);
	$totalnum = empty($row['cnt']) ? 0 : intval($row['cnt']);
	if($totalnum == 0) return array(0, array());

	// JOIN thread 查询，db_find 不支持 JOIN，保留 db_sql_find
	$pids_sql = "SELECT p.pid FROM {$tablepre}post p INNER JOIN {$tablepre}thread t ON p.tid=t.tid WHERE {$where} ORDER BY p.pid DESC LIMIT {$offset}, {$pagesize}";
	$rows = db_sql_find($pids_sql, 'pid');
	if(empty($rows)) return array($totalnum, array());
	$pids = array_keys($rows);
	$postlist = post_find_by_pids($pids);
	return array($totalnum, $postlist);
}

/**
 * 批量查找引用链
 * 沿着 quotepid 向上查找，返回引用链上的 post 数组
 * 使用 static 缓存避免同一请求内重复查询同一 pid（如循环引用或多次调用），并防止循环引用导致的死循环
 *
 * @param int $quotepid 起始引用的 pid
 * @param int $max_depth 最大深度，默认 10
 * @return array 引用链上的 post 数组（以 pid 为 key，按沿链向上顺序：从直接引用 $quotepid 到最上层）
 */
function post_find_quote_chain($quotepid, $max_depth = 10) {
	// hook model_post_find_quote_chain_start.php
	$chain = array();
	if(empty($quotepid)) return $chain;

	// static 缓存：避免同一请求内重复查询同一 pid（循环引用时也会命中缓存）
	static $cache = array();

	$_current_pid = intval($quotepid);
	$_depth = 0;
	$visited = array(); // 已访问的 pid，防止循环引用

	while($_depth < $max_depth && $_current_pid > 0) {
		// 防止循环引用：遇到已访问的 pid 立即停止
		if(isset($visited[$_current_pid])) break;
		$visited[$_current_pid] = true;

		// 走 static 缓存，避免重复查询
		if(!isset($cache[$_current_pid])) {
			$cache[$_current_pid] = post__read($_current_pid);
		}
		$post = $cache[$_current_pid];

		if(empty($post)) break;
		$chain[$_current_pid] = $post;

		// 没有更上层引用，结束
		if(empty($post['quotepid'])) break;
		$_current_pid = intval($post['quotepid']);
		$_depth++;
	}

	// hook model_post_find_quote_chain_end.php
	return $chain;
}


function post_highlight_keyword($str, $k) {
	// hook model_post_highlight_keyword_start.php
	$r = str_ireplace($k, '<span class="red">'.$k.'</span>', $str);
	// hook model_post_highlight_keyword_end.php
	return $r;
}

// 公用的附件模板，采用函数，效率比 include 高。
function post_file_list_html($filelist, $include_delete = FALSE, $imagelist = array(), $videolist = array()) {
    // 图片和视频已插入到 message 中内联显示，此处不再重复渲染 imagelist/videolist
    // 仅渲染普通附件（filelist）的下载卡片
    if(empty($filelist)) return '';

    global $conf, $time;

    // hook model_post_file_list_html_start

    $s = '';

    // 文件附件：卡片列表（不暴露真实下载 URL，通过 JS AJAX 下载）
    if(!empty($filelist)) {
        $types = include APP_PATH.'conf/attach.conf.php';
        $sign_key = array_value($conf, 'attach_sign_key', '');
        // token 有效期 1 小时
        $expires = $time + 3600;

        $s .= '<div class="attach-filelist mb-3">'."\r\n";
        foreach($filelist as $attach) {
            $filetype = attach_type($attach['orgfilename'], $types);
            $icon = 'ti-file';
            if(in_array($filetype, array('text', 'pdf'))) {
                $icon = 'ti-file-text';
            } elseif($filetype == 'zip') {
                $icon = 'ti-file-zip';
            } elseif(in_array($filetype, array('office'))) {
                $icon = 'ti-file-text';
            } elseif(in_array($filetype, array('c', 'cpp', 'cc'))) {
                $icon = 'ti-file-code';
            } elseif($filetype == 'code') {
                $icon = 'ti-file-code';
            }
            // 格式化文件大小
            $filesize = $attach['filesize'];
            if($filesize >= 1048576) {
                $size_fmt = sprintf('%.1fMB', $filesize / 1048576);
            } elseif($filesize >= 1024) {
                $size_fmt = sprintf('%.1fKB', $filesize / 1024);
            } else {
                $size_fmt = $filesize.'B';
            }
            $aid = $attach['aid'];
            $orgfilename = esc_html($attach['orgfilename']);
            // 生成签名 token（与 attach-fetch 路由验证逻辑一致）
            $token = md5($aid . $expires . $sign_key);
            $fetch_url = url("attach-fetch-{$aid}-{$token}-{$expires}");
            $s .= '    <div class="border rounded-3 p-2 mb-1 d-flex align-items-center" aid="'.$aid.'">'."\r\n";
            $s .= '        <i class="ti '.$icon.' text-body-secondary me-2" style="font-size:1.25rem;"></i>'."\r\n";
            $s .= '        <div class="flex-fill" style="min-width:0">'."\r\n";
            $s .= '            <span class="text-body text-truncate d-block small" title="'.$orgfilename.'">'.$orgfilename.'</span>'."\r\n";
            $s .= '            <span class="text-body-secondary" style="font-size:0.75rem;">'.$size_fmt.'</span>'."\r\n";
            $s .= '        </div>'."\r\n";
            // 下载按钮：通过 JS AJAX 下载，URL 存在 data-url，JS 读取后 fetch 并清空
            $s .= '        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 ms-2 flex-shrink-0 attach-fetch-btn" data-url="'.htmlspecialchars($fetch_url).'" data-name="'.htmlspecialchars($attach['orgfilename']).'" title="'.lang('download').'"><i class="ti ti-download"></i></button>'."\r\n";
            if($include_delete) {
                $s .= '        <a href="javascript:void(0)" class="attach-delete attach-delete-btn text-danger text-decoration-none ms-2" aid="'.$aid.'" onclick="deleteAttach(this, \''.$aid.'\')"><i class="ti ti-trash"></i></a>'."\r\n";
            }
            $s .= '    </div>'."\r\n";
        }
        $s .= '</div>'."\r\n";
    }

    // hook model_post_file_list_html_end.php

    return $s;
}

function post_format(&$post) {
	global $conf, $uid, $sid, $gid, $longip;
	if(empty($post)) return;
	$post['create_date_fmt'] = humandate($post['create_date']);
	
	$user = user_read_cache($post['uid']);
	
	// hook model_post_format_start.php
	
	$post['username'] = array_value($user, 'display_name') ? array_value($user, 'display_name') : array_value($user, 'username');
	$post['user_avatar_url'] = array_value($user, 'avatar_url');
	$post['group_icon_class'] = array_value($user, 'group_icon_class', '');
	$post['group_color'] = array_value($user, 'group_color', '');
	$post['group_name'] = array_value($user, 'groupname', '');
	$post['gid'] = array_value($user, 'gid', 0);
	$post['user'] = $user ? $user : user_guest();
	!isset($post['floor']) AND  $post['floor'] = '';
	
	$thread = thread_read_cache($post['tid']);
	
	// 权限判断
	$post['allowupdate'] = ($uid == $post['uid']) || forum_access_mod($thread['fid'], $gid, 'allowupdate');
	$post['allowdelete'] = ($uid == $post['uid']) || forum_access_mod($thread['fid'], $gid, 'allowdelete');
	
	$post['user_url'] = url("user-$post[uid]".($post['uid'] ? '' : "-$post[pid]"));
	
	if($post['files'] > 0) {
		// 静态缓存附件数据，避免同一 pid 重复查询
		static $attach_cache = array();
		if(!isset($attach_cache[$post['pid']])) {
			$attach_cache[$post['pid']] = attach_find_by_pid($post['pid']);
		}
		list($attachlist, $imagelist, $filelist) = $attach_cache[$post['pid']];
		// 分离视频附件：视频不作为附件显示，单独在播放器中展示
		$post['videolist'] = array();
		$post['filelist'] = array();
		foreach($attachlist as $attach) {
			if($attach['filetype'] == 'video') {
				$post['videolist'][] = $attach;
			} elseif(!$attach['isimage']) {
				$post['filelist'][] = $attach;
			}
		}
		$post['imagelist'] = $imagelist;
	} else {
		$post['filelist'] = array();
		$post['imagelist'] = array();
		$post['videolist'] = array();
	}

	$post['classname'] = 'post';

	$post['is_liked'] = 0;
	if(!empty($uid)) {
		// 优先从批量预加载的点赞状态缓存读取，避免 N+1 查询
		global $g_preloaded_post_likes;
		if(isset($g_preloaded_post_likes[$post['pid']])) {
			$post['is_liked'] = $g_preloaded_post_likes[$post['pid']];
		} else {
			$is_liked = post_like_read($uid, $post['pid']);
			$post['is_liked'] = !empty($is_liked) ? 1 : 0;
		}
	}

	// XSS 防护：转义用户可控的文本字段
	$post['username'] = esc_html($post['username'] ?? '');
	$post['group_name'] = esc_html($post['group_name'] ?? '');
	$post['group_icon_class'] = esc_attr($post['group_icon_class'] ?? '');
	$post['user_avatar_url'] = esc_attr($post['user_avatar_url'] ?? '');

	// 引用块检查：如果被引用的帖子已被删除，替换引用块为"该评论已被删除"
	if(!empty($post['quotepid']) && $post['quotepid'] > 0 && strpos($post['message_fmt'], 'blockquote') !== false) {
		static $_quote_deleted_cache = array();
		$_qpid = intval($post['quotepid']);
		if(!isset($_quote_deleted_cache[$_qpid])) {
			$_quoted_post = post__read($_qpid);
			$_quote_deleted_cache[$_qpid] = empty($_quoted_post);
		}
		if($_quote_deleted_cache[$_qpid]) {
			$post['message_fmt'] = preg_replace(
				'#<blockquote\s+class="blockquote"[^>]*>.*?</blockquote>#is',
				'<blockquote class="blockquote text-body-secondary"><em>'.lang('quote_deleted').'</em></blockquote>',
				$post['message_fmt']
			);
		}
	}

	// hook model_post_format_end.php

}

// 写入时格式化
function post_message_fmt(&$arr, $gid) {

	// hook post_message_fmt_start.php

	// 如果没有 message 字段（如仅更新 is_top 等元数据），跳过格式化
	if(!isset($arr['message'])) return;

	// 超长内容截取
	$arr['message'] = xn_substr($arr['message'], 0, 2028000);

	// 格式转换: 类型，0: html, 1: txt; 2: markdown; 3: ubb
	$arr['message_fmt'] = htmlspecialchars($arr['message']);

	// 入库的时候进行转换，编辑的时候，自行调取 message, 或者 message_fmt
	$arr['doctype'] == 0 && $arr['message_fmt'] = xn_html_purify($arr['message']);
	$arr['doctype'] == 1 && $arr['message_fmt'] = xn_txt_to_html($arr['message']);

	// 将 @提及 span 转换为可点击链接（在 message_fmt 上操作，不影响原始 message）
	// AIEditor 生成格式：<span class="mention" data-type="mention" data-id="UID" data-label="USERNAME">@USERNAME</span>
	// 注意：HTMLPurifier 会移除 data-* 属性，所以这里用 class="mention" 匹配
	if(strpos($arr['message_fmt'], 'class="mention"') !== false) {
		$arr['message_fmt'] = preg_replace_callback(
			'/<span\s+class="mention"(?:\s+data-type="mention")?(?:\s+data-id="(\d+)")?(?:\s+data-label="([^"]*)")?>([^<]*)<\/span>/',
			function($m) {
				$uid = intval($m[1]);
				$username = !empty($m[2]) ? $m[2] : ltrim($m[3], '@');
				$userUrl = url('user-' . $uid);
				return '<a href="' . $userUrl . '" class="mention">' . $m[3] . '</a>';
			},
			$arr['message_fmt']
		);
	}
	
	// hook post_message_fmt_end.php
	
	// 对引用进行处理
	!empty($arr['quotepid']) && $arr['quotepid'] > 0 && $arr['message_fmt'] = post_quote($arr['quotepid']).$arr['message_fmt'];
}

// 获取内容的简介 0: html, 1: txt; 2: markdown; 3: ubb
function post_brief($s, $len = 100) {
	// hook post_brief_start.php
	$s = strip_tags($s);
	$s = htmlspecialchars($s);
	$more = xn_strlen($s) > $len ? ' ... ' : '';
	$s = xn_substr($s, 0, $len).$more;
	// hook post_brief_end.php
	return $s;
}

// 对内容进行引用
function post_quote($quotepid) {
	global $conf;
	$quotepost = post__read($quotepid);
	if(empty($quotepost)) return '<blockquote class="blockquote text-body-secondary"><em>'.lang('quote_deleted').'</em></blockquote>';
	$uid = $quotepost['uid'];
	$s = $quotepost['message'];
	
	// hook post_quote_start.php
	
	$s = post_brief($s, 100);
	$userhref = url("user-$uid");
	$user = user_read_cache($uid);
	$r = '<blockquote class="blockquote" data-quotepid="'.$quotepid.'">
		<a href="'.$userhref.'" class="d-inline-flex align-items-center gap-1 text-body-secondary small user">
			<img class="avatar-sm rounded-circle" src="'.$user['avatar_url'].'" onerror="this.onerror=null;this.src=\''.$conf['view_url'].'img/avatar.png\'">
			'.$user['display_name'].'
		</a>
		'.$s.'
		</blockquote>';
	// hook post_quote_end.php
	return $r;
}


// 对 $threadlist 权限过滤
function post_list_access_filter(&$postlist, $gid) {
	global $conf, $forumlist, $uid;
	if(empty($postlist)) return;

	// hook model_post_list_access_filter_start.php

	// 批量收集 tids 并查询 thread，消除 N+1 查询
	$tids = array();
	foreach($postlist as $post) {
		$tids[] = $post['tid'];
	}
	$tids = array_unique($tids);
	$threads = empty($tids) ? array() : db_find('thread', array('tid'=>$tids), array(), 1, count($tids), 'tid');

	foreach($postlist as $pid=>$post) {
		$thread = isset($threads[$post['tid']]) ? $threads[$post['tid']] : array();
		if(empty($thread)) continue;
		$fid = $thread['fid'];
		if(empty($forumlist[$fid]['accesson'])) continue;
		if($thread['top'] > 0) continue;
		if(!forum_access_user($fid, $gid, 'allowread')) {
			unset($postlist[$pid]);
		}
	}

	// 待审/驳回内容过滤：非管理员(gid!=1,2)不可见 audit_status!=1 的他人回帖，作者自己可见待审和驳回回帖
	if($gid == 0 || $gid > 2) {
		foreach($postlist as $pid=>$post) {
			if(isset($post['audit_status']) && $post['audit_status'] != 1) {
				// 待审/驳回回帖仅作者可见
				if($post['uid'] != $uid) {
					unset($postlist[$pid]);
				}
			}
		}
	}

	// hook model_post_list_access_filter_end.php
}

// hook model_post_end.php

?>