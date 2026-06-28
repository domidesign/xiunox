<?php

// hook model_post_like_start.php

function post_like__create($arr) {
	// hook model_post_like__create_start.php
	global $db;
	$tablepre = $db->tablepre;
	// 使用 INSERT IGNORE 避免主键冲突
	$keys = array();
	$values = array();
	foreach($arr as $k=>$v) {
		$keys[] = '`'.addslashes($k).'`';
		$values[] = "'".addslashes((string)$v)."'";
	}
	$sql = "INSERT IGNORE INTO {$tablepre}post_like (".implode(',', $keys).") VALUES (".implode(',', $values).")";
	// 直接调用 PDO exec 获取受影响行数（db_exec 对 INSERT 会返回 lastInsertId，不适用此场景）
	$r = 0;
	error_log("[post_like__create DEBUG] sql=$sql wlink_exists=" . (isset($db->wlink) && $db->wlink ? 'yes' : 'no'));
	if($db && isset($db->wlink) && $db->wlink) {
		$r = $db->wlink->exec($sql);
		error_log("[post_like__create DEBUG] exec_result=" . var_export($r, true) . " errorInfo=" . json_encode($db->wlink->errorInfo()));
	}
	// hook model_post_like__create_end.php
	return $r; // 1=新增成功，0=已存在
}

function post_like__delete($uid, $pid) {
	// hook model_post_like__delete_start.php
	global $db;
	$tablepre = $db->tablepre;
	// 直接调用 PDO exec 获取受影响行数
	$sql = "DELETE FROM {$tablepre}post_like WHERE uid='".intval($uid)."' AND pid='".intval($pid)."'";
	$r = 0;
	if($db && $db->wlink) {
		try {
			$r = $db->wlink->exec($sql);
		} catch(Exception $e) {
			$r = 0;
		}
	}
	// hook model_post_like__delete_end.php
	return $r; // 1=删除成功，0=原本不存在
}

function post_like_read($uid, $pid) {
	// hook model_post_like_read_start.php
	$like = db_find_one('post_like', array('uid'=>$uid, 'pid'=>$pid));
	// hook model_post_like_read_end.php
	return $like;
}

/**
 * 批量查询当前用户对多个帖子的点赞状态，消除 N+1 查询
 * @param int $uid 当前用户 uid
 * @param array $pids 需要查询的 pid 列表
 * @return array 以 pid 为键的点赞状态数组
 */
function post_like_read_batch($uid, $pids) {
	if(empty($uid) || empty($pids)) return array();
	$likes = db_find('post_like', array('uid'=>$uid, 'pid'=>$pids), array(), 1, count($pids), 'pid');
	$result = array();
	foreach($pids as $pid) {
		$result[$pid] = !empty($likes[$pid]) ? 1 : 0;
	}
	return $result;
}

function post_like_create($uid, $tid, $pid) {
	global $time, $db;
	// hook model_post_like_create_start.php
	// 使用 INSERT IGNORE，无需先 SELECT 检查，避免主键冲突和竞争
	$r = post_like__create(array(
		'tid' => $tid,
		'pid' => $pid,
		'uid' => $uid,
		'create_date' => $time,
	));
	// $r 为受影响行数：1=新增成功，0=已存在
	if($r == 1) {
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
	// 直接 DELETE，通过返回值判断是否真正删除
	$r = post_like__delete($uid, $pid);
	// $r 为受影响行数：1=删除成功，0=原本不存在
	if($r && $r > 0) {
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
