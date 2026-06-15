<?php

// hook model_post_like_start.php

function post_like__create($arr) {
	// hook model_post_like__create_start.php
	$r = db_insert('post_like', $arr);
	// hook model_post_like__create_end.php
	return $r;
}

function post_like__delete($uid, $pid) {
	// hook model_post_like__delete_start.php
	$r = db_delete('post_like', array('uid'=>$uid, 'pid'=>$pid));
	// hook model_post_like__delete_end.php
	return $r;
}

function post_like_read($uid, $pid) {
	// hook model_post_like_read_start.php
	$like = db_find_one('post_like', array('uid'=>$uid, 'pid'=>$pid));
	// hook model_post_like_read_end.php
	return $like;
}

function post_like_create($uid, $tid, $pid) {
	global $time, $db;
	// hook model_post_like_create_start.php
	$exists = post_like_read($uid, $pid);
	if(!empty($exists)) return FALSE;
	$r = post_like__create(array(
		'tid' => $tid,
		'pid' => $pid,
		'uid' => $uid,
		'create_date' => $time,
	));
	if($r !== FALSE) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE `{$tablepre}post` SET likes=likes+1 WHERE pid='$pid'");
		db_exec("UPDATE `{$tablepre}thread` SET likes=likes+1 WHERE tid='$tid'");
		$post = db_find_one('post', array('pid'=>$pid));
		if(!empty($post) && $post['uid'] != $uid) {
			notify_create($post['uid'], $uid, 'like', $tid, $pid);
		}
	}
	// hook model_post_like_create_end.php
	return $r;
}

function post_like_delete($uid, $tid, $pid) {
	global $db;
	// hook model_post_like_delete_start.php
	$exists = post_like_read($uid, $pid);
	if(empty($exists)) return FALSE;
	$r = post_like__delete($uid, $pid);
	if($r !== FALSE) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE `{$tablepre}post` SET likes=IF(likes>0,likes-1,0) WHERE pid='$pid'");
		db_exec("UPDATE `{$tablepre}thread` SET likes=IF(likes>0,likes-1,0) WHERE tid='$tid'");
	}
	// hook model_post_like_delete_end.php
	return $r;
}

function post_like_find_by_uid($uid, $page = 1, $pagesize = 20) {
	// hook model_post_like_find_by_uid_start.php
	$likelist = db_find('post_like', array('uid'=>$uid), array('pid'=>-1), $page, $pagesize);
	// hook model_post_like_find_by_uid_end.php
	return $likelist;
}

function post_like_count_by_pid($pid) {
	// hook model_post_like_count_by_pid_start.php
	$n = db_count('post_like', array('pid'=>$pid));
	// hook model_post_like_count_by_pid_end.php
	return $n;
}

// hook model_post_like_end.php

?>
