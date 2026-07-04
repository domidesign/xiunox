<?php

!defined('DEBUG') AND exit('Forbidden');

function thread_digest_delete($tid, $uid, $fid) {
	$r = db_delete('thread_digest', array('tid'=>$tid));
	if($r !== FALSE) {
		user_update($uid, array('digests-'=>1));
		forum_update($fid, array('digests-'=>1));
		runtime_set('digests-', 1);
	}
	return $r;
}

function thread_digest_create($tid, $digest, $uid, $fid) {
	$r = db_create('thread_digest', array('fid'=>$fid, 'tid'=>$tid, 'uid'=>$uid, 'digest'=>$digest));
	if($r !== FALSE) {
		user_update($uid, array('digests+'=>1));
		forum_update($fid, array('digests+'=>1));
		runtime_set('digests+', 1);
	}
	return $r;
}

function thread_digest_read($tid) {
	$arr = db_read('thread_digest', array('tid'=>$tid));
	return $arr;
}

function thread_digest_update($tid, $arr) {
	$r = db_update('thread_digest', array('tid'=>$tid), $arr);
	return $r;
}

function thread_digest_change($tid, $digest, $uid, $fid) {
	$arr = thread_digest_read($tid);

	if($digest == 0) {
		if($arr) {
			thread_digest_delete($tid, $uid, $fid);
		}
	} else {
		if($arr) {
			thread_digest_update($tid, array('digest'=>$digest));
		} else {
			thread_digest_create($tid, $digest, $uid, $fid);
		}
	}
	thread_update($tid, array('digest'=>$digest));
	// 清除版块帖子列表缓存和 forumlist 缓存：thread_update 仅在 fid 变化时清缓存，digest 变化需在此补充
	thread_forum_list_cache_delete($fid);
	forum_list_cache_delete();
}

/**
 * 批量设置/取消主题精华
 * 合并 thread_digest 的查询/插入/更新/删除、thread.digest 字段更新、user.digests/forum.digests/runtime 统计更新
 * 消除 N+1 UPDATE/INSERT/DELETE
 *
 * @param array $tids       主题ID数组
 * @param array $threadlist 主题信息数组（key 为 tid，需包含 uid/fid/digest 字段）
 * @param int   $digest     精华级别：0=取消，1/2/3=精华级别
 * @return int 受影响的主题数
 */
function thread_digest_change_batch($tids, $threadlist, $digest) {
	// hook model_thread_digest_change_batch_start.php
	if(empty($tids) || !is_array($tids)) return 0;
	if(empty($threadlist) || !is_array($threadlist)) return 0;

	global $db, $time;
	if(!$db) return 0;
	$tablepre = $db->tablepre;

	// 1. 过滤出需要变更的主题（跳过 digest 值相同的）
	$valid_tids = array();
	$valid_threads = array();
	foreach($tids as $_tid) {
		$_tid = intval($_tid);
		if($_tid <= 0) continue;
		if(!isset($threadlist[$_tid])) continue;
		$_thread = $threadlist[$_tid];
		if(intval($_thread['digest']) === intval($digest)) continue;
		$valid_tids[] = $_tid;
		$valid_threads[$_tid] = $_thread;
	}
	if(empty($valid_tids)) return 0;

	// 2. 批量查询现有 thread_digest 记录（一次查询）
	$existing_digests = db_find('thread_digest', array('tid'=>$valid_tids), array(), 1, count($valid_tids), 'tid');
	if(empty($existing_digests)) $existing_digests = array();

	// 3. 分类处理：取消精华 vs 设置精华
	$delete_tids = array();        // 需要从 thread_digest 删除的 tid
	$insert_rows = array();        // 需要插入 thread_digest 的新记录
	$update_tids = array();        // 已存在记录但需更新 digest 级别的 tid
	foreach($valid_threads as $_tid => $_thread) {
		$_has_digest = isset($existing_digests[$_tid]);
		if($digest == 0) {
			// 取消精华：仅当存在记录时删除
			if($_has_digest) {
				$delete_tids[] = $_tid;
			}
		} else {
			if($_has_digest) {
				// 已存在：更新 digest 级别
				$update_tids[] = $_tid;
			} else {
				// 新增：插入
				$insert_rows[] = array(
					'fid' => intval($_thread['fid']),
					'tid' => $_tid,
					'uid' => intval($_thread['uid']),
					'digest' => intval($digest),
				);
			}
		}
	}

	// 4. 批量执行 SQL
	// 4.1 批量删除 thread_digest
	if(!empty($delete_tids)) {
		db_delete('thread_digest', array('tid'=>$delete_tids));
	}
	// 4.2 批量插入 thread_digest（一次 SQL）
	if(!empty($insert_rows)) {
		$values_parts = array();
		foreach($insert_rows as $row) {
			$values_parts[] = '('.intval($row['fid']).','.intval($row['tid']).','.intval($row['uid']).','.intval($row['digest']).')';
		}
		$sql = "INSERT INTO {$tablepre}thread_digest (fid,tid,uid,digest) VALUES ".implode(',', $values_parts);
		db_exec($sql);
	}
	// 4.3 批量更新已有 thread_digest 的 digest 级别（一次 UPDATE）
	if(!empty($update_tids)) {
		db_update('thread_digest', array('tid'=>$update_tids), array('digest'=>$digest));
	}
	// 4.4 批量更新 thread.digest 字段（一次 UPDATE）
	db_update('thread', array('tid'=>$valid_tids), array('digest'=>$digest));

	// 5. 按 uid 分组统计 digests 增量（取消=减1，设置=加1）
	// 同时按 fid 分组统计 forum.digests 增量
	$uid_digests_inc = array();   // uid => 增量（+1 或 -1）
	$fid_digests_inc = array();   // fid => 增量
	$total_inc = 0;               // runtime 总增量
	foreach($valid_threads as $_tid => $_thread) {
		$_uid = intval($_thread['uid']);
		$_fid = intval($_thread['fid']);
		// 判断本次操作是新增精华还是取消精华
		// 注意：仅当从无精华→有精华 或 有精华→无精华 时才影响 user/forum 统计
		// 同级别调整（如 digest 1→2）不影响计数；但本批处理已过滤掉相同 digest 的，所以只需判断目标 digest
		$_old_digest = intval($_thread['digest']);
		$_new_digest = intval($digest);
		if($_old_digest == 0 && $_new_digest > 0) {
			// 新增精华
			if($_uid > 0) {
				if(!isset($uid_digests_inc[$_uid])) $uid_digests_inc[$_uid] = 0;
				$uid_digests_inc[$_uid]++;
			}
			if($_fid > 0) {
				if(!isset($fid_digests_inc[$_fid])) $fid_digests_inc[$_fid] = 0;
				$fid_digests_inc[$_fid]++;
			}
			$total_inc++;
		} elseif($_old_digest > 0 && $_new_digest == 0) {
			// 取消精华
			if($_uid > 0) {
				if(!isset($uid_digests_inc[$_uid])) $uid_digests_inc[$_uid] = 0;
				$uid_digests_inc[$_uid]--;
			}
			if($_fid > 0) {
				if(!isset($fid_digests_inc[$_fid])) $fid_digests_inc[$_fid] = 0;
				$fid_digests_inc[$_fid]--;
			}
			$total_inc--;
		}
		// 同级别调整（如 1→2、2→1）不影响 user/forum 统计，无需处理
	}

	// 6. 批量更新 user.digests（每个 uid 一次 UPDATE，使用 CASE WHEN 合并）
	if(!empty($uid_digests_inc)) {
		$case_parts = array();
		$uid_list = array();
		foreach($uid_digests_inc as $_uid => $inc) {
			$inc = intval($inc);
			if($inc == 0) continue;
			// 使用 GREATEST 防止负数
			$case_parts[] = "WHEN ".intval($_uid)." THEN GREATEST(digests + (".intval($inc)."), 0)";
			$uid_list[] = intval($_uid);
		}
		if(!empty($case_parts)) {
			$uid_in = implode(',', $uid_list);
			$case_sql = "CASE uid ".implode(' ', $case_parts)." ELSE digests END";
			db_exec("UPDATE {$tablepre}user SET digests = $case_sql WHERE uid IN ($uid_in)");
		}
	}

	// 7. 批量更新 forum.digests
	if(!empty($fid_digests_inc)) {
		$case_parts = array();
		$fid_list = array();
		foreach($fid_digests_inc as $_fid => $inc) {
			$inc = intval($inc);
			if($inc == 0) continue;
			$case_parts[] = "WHEN ".intval($_fid)." THEN GREATEST(digests + (".intval($inc)."), 0)";
			$fid_list[] = intval($_fid);
		}
		if(!empty($case_parts)) {
			$fid_in = implode(',', $fid_list);
			$case_sql = "CASE fid ".implode(' ', $case_parts)." ELSE digests END";
			db_exec("UPDATE {$tablepre}forum SET digests = $case_sql WHERE fid IN ($fid_in)");
		}
	}

	// 8. 更新 runtime 统计
	if($total_inc > 0) {
		runtime_set('digests+', $total_inc);
	} elseif($total_inc < 0) {
		runtime_set('digests-', abs($total_inc));
	}

	// 9. 清除受影响版块的帖子列表缓存和 forumlist 缓存
	// 从 $valid_threads 提取去重 fid，避免额外 db 查询；确保版块列表页精华标识和 forum.digests 统计及时刷新
	$affected_fids = array();
	foreach($valid_threads as $_tid => $_thread) {
		$_fid = intval($_thread['fid']);
		if($_fid > 0) $affected_fids[$_fid] = $_fid;
	}
	foreach($affected_fids as $_fid) {
		thread_forum_list_cache_delete($_fid);
	}
	forum_list_cache_delete();

	// hook model_thread_digest_change_batch_end.php
	return count($valid_tids);
}

function thread_digest_find_by_fid($fid = 0, $page = 1, $pagesize = 20) {
	if($fid == 0) {
		$threadlist = db_find('thread_digest', array(), array('tid'=>-1), $page, $pagesize, 'tid');
	} else {
		$threadlist = db_find('thread_digest', array('fid'=>$fid), array('tid'=>-1), $page, $pagesize, 'tid');
	}
	$tids = arrlist_values($threadlist, 'tid');
	$threadlist = thread_find_by_tids($tids);
	return $threadlist;
}

function thread_digest_find_by_uid($uid, $page = 1, $pagesize = 20) {
	$threadlist = db_find('thread_digest', array('uid'=>$uid), array('tid'=>-1), $page, $pagesize, 'tid');
	$tids = arrlist_values($threadlist, 'tid');
	$threadlist = thread_find_by_tids($tids);
	return $threadlist;
}

function thread_digest_count($fid = 0) {
	global $forumlist;
	if($fid == 0) {
		return db_count('thread_digest');
	} else {
		return $forumlist[$fid]['digests'];
	}
}

?>
