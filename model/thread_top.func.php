<?php

// hook model_thread_top_start.php

// 置顶主题

function thread_top_change($tid, $top = 0) {
	// hook model_thread_top_change_start.php
	$thread = thread__read($tid);
	if(empty($thread)) return FALSE;
	if($top != $thread['top']) {
		thread__update($tid, array('top'=>$top));
		$fid = $thread['fid'];
		$tid = $thread['tid'];
		thread_top_cache_delete();

		$arr = array('fid'=>$fid, 'tid'=>$tid, 'top'=>$top);
		$r = db_replace('thread_top', $arr);
		return $r;
	}
	// hook model_thread_top_change_end.php
	return FALSE;
}

/**
 * 批量置顶/取消置顶多个主题
 * 单次 SQL 批量更新 thread.top_type + REPLACE INTO thread_top，消除 N+1 UPDATE/REPLACE
 *
 * @param array $tids       主题ID数组
 * @param array $threadlist 主题信息数组（key 为 tid，需包含 fid/top 字段），用于避免重复查询
 * @param int   $top        置顶级别：0=取消，1=版块置顶，3=全局置顶
 * @return int 受影响的主题数
 */
function thread_top_change_batch($tids, $threadlist, $top = 0) {
	// hook model_thread_top_change_batch_start.php
	if(empty($tids) || !is_array($tids)) return 0;
	if(empty($threadlist) || !is_array($threadlist)) return 0;

	global $db;
	if(!$db) return 0;
	$tablepre = $db->tablepre;

	// 1. 过滤出 top 值真正变化的主题（避免无谓 UPDATE）
	$valid_tids = array();
	$replace_rows = array();
	foreach($tids as $_tid) {
		$_tid = intval($_tid);
		if($_tid <= 0) continue;
		if(!isset($threadlist[$_tid])) continue;
		$_thread = $threadlist[$_tid];
		if(intval($_thread['top']) === intval($top)) continue;
		$valid_tids[] = $_tid;
		$replace_rows[] = array(
			'fid' => intval($_thread['fid']),
			'tid' => $_tid,
			'top' => intval($top),
		);
	}
	if(empty($valid_tids)) return 0;

	// 2. 批量更新 thread.top 字段（一次 UPDATE）
	db_update('thread', array('tid'=>$valid_tids), array('top'=>$top));

	// 3. 批量 REPLACE INTO thread_top（一次 SQL，避免 N+1 REPLACE）
	if($top == 0) {
		// 取消置顶：批量 DELETE
		db_delete('thread_top', array('tid'=>$valid_tids));
	} else {
		// 置顶：REPLACE INTO 单次批量插入
		$values_parts = array();
		foreach($replace_rows as $row) {
			$values_parts[] = '('.intval($row['fid']).','.intval($row['tid']).','.intval($row['top']).')';
		}
		$sql = "REPLACE INTO {$tablepre}thread_top (fid,tid,top) VALUES ".implode(',', $values_parts);
		db_exec($sql);
	}

	// 4. 清理置顶缓存（一次）
	thread_top_cache_delete();

	// hook model_thread_top_change_batch_end.php
	return count($valid_tids);
}

function thread_top_delete($tid) {
	// hook model_thread_top_delete_start.php
	$r = db_delete('thread_top', array('tid'=>$tid));
	// hook model_thread_top_delete_end.php
	return $r;
}

function thread_top_find($fid = 0) {
	// hook model_thread_top_find_start.php
	static $cache = array();
	$cache_key = intval($fid);
	if(isset($cache[$cache_key])) return $cache[$cache_key];

	if($fid == 0) {
		$threadlist = db_find('thread_top', array('top'=>3), array('tid'=>-1), 1, 100, 'tid');
	} else {
		$threadlist = db_find('thread_top', array('fid'=>$fid, 'top'=>1), array('tid'=>-1), 1, 100, 'tid');
	}
	$tids = arrlist_values($threadlist, 'tid');
	$threadlist = thread_find_by_tids($tids);
	$cache[$cache_key] = $threadlist;
	// hook model_thread_top_find_end.php
	return $threadlist;
}

function thread_top_find_cache() {
	// hook model_thread_top_find_cache_start.php
	global $conf;
	$threadlist = cache_get('thread_top_list');
	if($threadlist === NULL) {
		$threadlist = thread_top_find();
		// 缓存 300 秒（5 分钟），置顶变化时由 thread_top_cache_delete() 主动失效
		cache_set('thread_top_list', $threadlist, 300);
	} else {
		// 重新格式化时间
		foreach($threadlist as &$thread) {
			thread_format_last_date($thread);
		}
	}
	// hook model_thread_top_find_cache_end.php
	return $threadlist;
}

function thread_top_cache_delete() {
	// hook model_thread_top_cache_delete_start.php
	global $conf;
	static $deleted = FALSE;
	if($deleted) return;
	cache_delete('thread_top_list');
	$deleted = TRUE;
	// hook model_thread_top_cache_delete_end.php
}

function thread_top_update_by_tid($tid, $newfid) {
	// hook model_thread_top_update_by_tid_start.php
	$r = db_update('thread_top', array('tid'=>$tid), array('fid'=>$newfid));
	// hook model_thread_top_update_by_tid_end.php
	return $r;
}


// hook model_thread_top_end.php

?>