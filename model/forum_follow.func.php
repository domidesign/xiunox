<?php

// 版块关注模型

function forum_follow__create($arr) {
	$r = db_insert('forum_follow', $arr);
	return $r;
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
	$exists = forum_follow_read($uid, $fid);
	if(!empty($exists)) return FALSE;
	$r = forum_follow__create(array(
		'uid' => $uid,
		'fid' => $fid,
		'create_date' => $time,
	));
	if($r !== FALSE) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}forum SET follows=follows+1 WHERE fid='$fid'");
	}
	return $r;
}

function forum_follow_delete($uid, $fid) {
	global $db;
	$r = forum_follow__delete($uid, $fid);
	if($r !== FALSE) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}forum SET follows=follows-1 WHERE fid='$fid' AND follows>0");
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
	$result = array();
	foreach($fids as $fid) {
		$followed = forum_follow_read($uid, $fid);
		$result[$fid] = !empty($followed);
	}
	return $result;
}

// 获取关注某版块的所有用户
function forum_follow_find_by_fid($fid, $page = 1, $pagesize = 100) {
	$followlist = db_find('forum_follow', array('fid'=>$fid), array('create_date'=>-1), $page, $pagesize);
	return $followlist;
}

?>
