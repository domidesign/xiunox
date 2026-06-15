<?php

// hook model_thread_favorite_start.php

function thread_favorite__create($arr) {
	$r = db_insert('thread_favorite', $arr);
	return $r;
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
	$exists = thread_favorite_read($uid, $tid);
	if(!empty($exists)) return FALSE;
	$r = thread_favorite__create(array(
		'tid' => $tid,
		'uid' => $uid,
		'create_date' => $time,
	));
	if($r !== FALSE) {
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
		foreach($favlist as $fav) {
			db_exec("UPDATE {$tablepre}user SET favorites=IF(favorites>0,favorites-1,0) WHERE uid='".$fav['uid']."'");
		}
	}
	$r = db_delete('thread_favorite', array('tid'=>$tid));
	return $r;
}

// hook model_thread_favorite_end.php

?>
