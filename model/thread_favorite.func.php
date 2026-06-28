<?php

// hook model_thread_favorite_start.php

function thread_favorite__create($arr) {
	global $db;
	$tablepre = $db->tablepre;
	// 使用 INSERT IGNORE 避免主键冲突
	$keys = array();
	$values = array();
	foreach($arr as $k=>$v) {
		$keys[] = '`'.addslashes($k).'`';
		$values[] = "'".addslashes((string)$v)."'";
	}
	$sql = "INSERT IGNORE INTO {$tablepre}thread_favorite (".implode(',', $keys).") VALUES (".implode(',', $values).")";
	$r = db_exec($sql);
	return $r; // 1=新增成功，0=已存在，FALSE=失败
}

function thread_favorite__delete($uid, $tid) {
	$r = db_delete('thread_favorite', array('uid'=>$uid, 'tid'=>$tid));
	return $r;
}

function thread_favorite_read($uid, $tid) {
	$fav = db_find_one('thread_favorite', array('uid'=>$uid, 'tid'=>$tid));
	return $fav;
}

function thread_favorite_create($uid, $tid) {
	global $time, $db;
	// 使用 INSERT IGNORE，无需先 SELECT 检查，避免主键冲突和竞争
	$r = thread_favorite__create(array(
		'tid' => $tid,
		'uid' => $uid,
		'create_date' => $time,
	));
	// $r 为受影响行数：1=新增成功，0=已存在
	if($r == 1) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}thread SET favorites=favorites+1 WHERE tid='$tid'");
		db_exec("UPDATE {$tablepre}user SET favorites=favorites+1 WHERE uid='$uid'");
		$thread = thread__read($tid);
		if(!empty($thread) && $thread['uid'] != $uid) {
			notify_create($thread['uid'], $uid, 'favorite', $tid);
		}
	}
	return $r;
}

function thread_favorite_delete($uid, $tid) {
	global $db;
	$exists = thread_favorite_read($uid, $tid);
	if(empty($exists)) return FALSE;
	$r = thread_favorite__delete($uid, $tid);
	if($r !== FALSE) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}thread SET favorites=IF(favorites>0,favorites-1,0) WHERE tid='$tid'");
		db_exec("UPDATE {$tablepre}user SET favorites=IF(favorites>0,favorites-1,0) WHERE uid='$uid'");
	}
	return $r;
}

function thread_favorite_find_by_uid($uid, $page = 1, $pagesize = 20) {
	$favlist = db_find('thread_favorite', array('uid'=>$uid), array('tid'=>-1), $page, $pagesize);
	return $favlist;
}

function thread_favorite_delete_by_tid($tid) {
	global $db;
	$tablepre = $db->tablepre;
	$favlist = db_find('thread_favorite', array('tid'=>$tid), array(), 1, 10000);
	if($favlist) {
		// 批量更新用户的 favorites 计数（替代逐个 UPDATE 的 N+1 查询）
		$fav_uids = arrlist_values($favlist, 'uid');
		if(!empty($fav_uids)) {
			$uid_list = implode(',', array_map('intval', $fav_uids));
			db_exec("UPDATE {$tablepre}user SET favorites=IF(favorites>0,favorites-1,0) WHERE uid IN ($uid_list)");
		}
	}
	$r = db_delete('thread_favorite', array('tid'=>$tid));
	return $r;
}

// 批量删除多个主题的收藏记录，合并查询消除 N+1（用于 thread_delete_batch）
// 同一 uid 可能收藏了多个被删主题，按 uid 聚合减量后批量更新
function thread_favorite_delete_by_tids_batch($tids) {
	global $db;
	$tablepre = $db->tablepre;

	if(empty($tids) || !is_array($tids)) return 0;

	$tids = array_map('intval', $tids);
	$tids = array_unique($tids);
	$tids = array_filter($tids);
	if(empty($tids)) return 0;

	// 一次查询获取所有收藏记录
	$favlist = db_find('thread_favorite', array('tid'=>$tids), array(), 1, 1000000);
	if(empty($favlist)) return 0;

	// 按 uid 聚合收藏数（同一 uid 收藏多个被删主题时需累计减量）
	$fav_count_by_uid = array();
	foreach($favlist as $fav) {
		$u = intval($fav['uid']);
		if($u == 0) continue;
		if(!isset($fav_count_by_uid[$u])) $fav_count_by_uid[$u] = 0;
		$fav_count_by_uid[$u]++;
	}

	// 批量更新用户 favorites 计数（每 uid 一次 UPDATE，按实际收藏数减量）
	foreach($fav_count_by_uid as $u => $cnt) {
		db_exec("UPDATE {$tablepre}user SET favorites=GREATEST(favorites-$cnt, 0) WHERE uid='$u'");
	}

	// 批量删除收藏记录（一次 DELETE）
	$r = db_delete('thread_favorite', array('tid'=>$tids));
	return $r;
}

// hook model_thread_favorite_end.php

?>
