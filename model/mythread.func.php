<?php

// ------------> 关联的 CURD，无关联其他数据。

// hook model_mythread_start.php

function mythread_create($uid, $tid) {
	// hook model_mythread_create_start.php
	if($uid == 0) return TRUE; // 匿名发帖
	global $db;
	$tablepre = $db->tablepre;
	// 使用 INSERT IGNORE，无需先 SELECT 检查，避免主键冲突
	$sql = "INSERT IGNORE INTO {$tablepre}mythread (uid, tid) VALUES ('".intval($uid)."', '".intval($tid)."')";
	$r = db_exec($sql);
	// INSERT IGNORE：1=新增，0=已存在；二者均表示记录已存在，视为成功
	// hook model_mythread_create_end.php
	return $r === FALSE ? FALSE : TRUE;
}

function mythread_read($uid, $tid) {
	// hook model_mythread_read_start.php
	$mythread = db_find_one('mythread', array('uid'=>$uid, 'tid'=>$tid));
	// hook model_mythread_read_end.php
	return $mythread;
}

function mythread_delete($uid, $tid) {
	// hook model_mythread_delete_start.php
	$r = db_delete('mythread', array('uid'=>$uid, 'tid'=>$tid));
	// hook model_mythread_delete_end.php
	return $r;
}

function mythread_delete_by_uid($uid) {
	// hook model_mythread_delete_by_uid_start.php
	$r = db_delete('mythread', array('uid'=>$uid));
	// hook model_mythread_delete_by_uid_end.php
	return $r;
}

function mythread_delete_by_fid($fid) {
	// hook model_mythread_delete_by_fid_start.php
	$r = db_delete('mythread', array('fid'=>$fid));
	// hook model_mythread_delete_by_fid_end.php
	return $r;
}

function mythread_delete_by_tid($tid) {
	// hook model_mythread_delete_by_tid_start.php
	$r = db_delete('mythread', array('tid'=>$tid));
	// hook model_mythread_delete_by_tid_end.php
	return $r;
}

function mythread_find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook model_mythread_find_start.php
	$mythreadlist = db_find('mythread', $cond, $orderby, $page, $pagesize);
	// hook model_mythread_find_end.php
	return $mythreadlist;
}

function mythread_find_by_uid($uid, $page = 1, $pagesize = 20) {
	// hook model_mythread_find_by_uid_start.php
	$mythreadlist = mythread_find(array('uid'=>$uid), array('tid'=>-1), $page, $pagesize);
	if(empty($mythreadlist)) return array();
	// 批量查询 thread（替代逐个 thread_read 的 N+1 查询）
	$tids = arrlist_values($mythreadlist, 'tid');
	$threadlist = empty($tids) ? array() : thread_find_by_tids($tids);
	// hook model_mythread_find_by_uid_end.php
	return $threadlist;
}

// hook model_mythread_end.php

?>