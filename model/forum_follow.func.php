<?php

// 版块关注模型

function forum_follow__create($arr) {
	global $db;
	$tablepre = $db->tablepre;
	// 使用 INSERT IGNORE 避免主键冲突；PDO 预处理防注入
	list($sqladd, $params) = db_array_to_insert_sqladd($arr);
	if(!$sqladd) return FALSE;
	$sql = "INSERT IGNORE INTO {$tablepre}forum_follow $sqladd";
	$stmt = $db->prepare($sql, $params);
	if(!$stmt) return FALSE;
	$r = intval($stmt->rowCount());
	$stmt->closeCursor();
	return $r; // 1=新增成功，0=已存在，FALSE=失败
}

function forum_follow__delete($uid, $fid) {
	$r = db_delete('forum_follow', array('uid'=>$uid, 'fid'=>$fid));
	return $r;
}

function forum_follow_read($uid, $fid) {
	$follow = db_find_one('forum_follow', array('uid'=>$uid, 'fid'=>$fid));
	return $follow;
}

function forum_follow_create($uid, $fid) {
	global $time, $db;
	if(empty($uid) || empty($fid)) return FALSE;
	$uid = intval($uid);
	$fid = intval($fid);
	// 使用 INSERT IGNORE，无需先 SELECT 检查，避免主键冲突和竞争
	$r = forum_follow__create(array(
		'uid' => $uid,
		'fid' => $fid,
		'create_date' => $time,
	));
	// $r 为受影响行数：1=新增成功，0=已存在
	if($r == 1) {
		// 使用实际 COUNT 校准，避免增量计数与实际不一致
		$real_count = intval(forum_follow_count($fid));
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}forum SET follows='{$real_count}' WHERE fid='$fid'");
	}
	return $r;
}

function forum_follow_delete($uid, $fid) {
	global $db;
	$uid = intval($uid);
	$fid = intval($fid);
	$r = forum_follow__delete($uid, $fid);
	if($r !== FALSE) {
		// 使用实际 COUNT 校准，避免增量计数与实际不一致
		$real_count = intval(forum_follow_count($fid));
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}forum SET follows='{$real_count}' WHERE fid='$fid'");
	}
	return $r;
}

function forum_follow_count($fid) {
	$count = db_count('forum_follow', array('fid'=>$fid));
	return $count;
}

// 获取用户关注的版块列表
function forum_follow_find_by_uid($uid, $page = 1, $pagesize = 20) {
	$followlist = db_find('forum_follow', array('uid'=>$uid), array('create_date'=>-1), $page, $pagesize);
	return $followlist;
}

// 批量检查用户是否关注了多个版块
function forum_follow_check_batch($uid, $fids) {
	if(empty($uid) || empty($fids)) return array();
	// 过滤并去重 fid，防止 IN 列表异常
	$fids = array_values(array_unique(array_filter(array_map('intval', $fids))));
	if(empty($fids)) return array();
	// 批量 IN 查询（替代逐个 forum_follow_read 的 N+1 查询）
	$follows = db_find('forum_follow', array('uid'=>$uid, 'fid'=>$fids), array(), 1, count($fids), 'fid');
	$result = array();
	foreach($fids as $fid) {
		$result[$fid] = isset($follows[$fid]) ? 1 : 0;
	}
	return $result;
}

// 获取关注某版块的所有用户
function forum_follow_find_by_fid($fid, $page = 1, $pagesize = 100) {
	$followlist = db_find('forum_follow', array('fid'=>$fid), array('create_date'=>-1), $page, $pagesize);
	return $followlist;
}

?>
