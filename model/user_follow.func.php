<?php

// hook model_user_follow_start.php

function user_follow__create($arr) {
	$r = db_insert('user_follow', $arr);
	return $r;
}

function user_follow__delete($uid, $follow_uid) {
	$r = db_delete('user_follow', array('uid'=>$uid, 'follow_uid'=>$follow_uid));
	return $r;
}

function user_follow_read($uid, $follow_uid) {
	$follow = db_find_one('user_follow', array('uid'=>$uid, 'follow_uid'=>$follow_uid));
	return $follow;
}

function user_follow_create($uid, $follow_uid) {
	global $time, $db;
	if($uid == $follow_uid) return FALSE;
	$exists = user_follow_read($uid, $follow_uid);
	if(!empty($exists)) return FALSE;
	$r = user_follow__create(array(
		'uid' => $uid,
		'follow_uid' => $follow_uid,
		'create_date' => $time,
	));
	if($r !== FALSE) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}user SET follows=follows+1 WHERE uid='$uid'");
		db_exec("UPDATE {$tablepre}user SET followeds=followeds+1 WHERE uid='$follow_uid'");
		notify_create($follow_uid, $uid, 'follow');
	}
	return $r;
}

function user_follow_delete($uid, $follow_uid) {
	global $db;
	$exists = user_follow_read($uid, $follow_uid);
	if(empty($exists)) return FALSE;
	$r = user_follow__delete($uid, $follow_uid);
	if($r !== FALSE) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}user SET follows=IF(follows>0,follows-1,0) WHERE uid='$uid'");
		db_exec("UPDATE {$tablepre}user SET followeds=IF(followeds>0,followeds-1,0) WHERE uid='$follow_uid'");
	}
	return $r;
}

function user_follow_find_following($uid, $page = 1, $pagesize = 20) {
	$followlist = db_find('user_follow', array('uid'=>$uid), array('follow_uid'=>-1), $page, $pagesize);
	return $followlist;
}

function user_follow_find_followers($uid, $page = 1, $pagesize = 20) {
	$followlist = db_find('user_follow', array('follow_uid'=>$uid), array('uid'=>-1), $page, $pagesize);
	return $followlist;
}

function user_follow_find_following_uids($uid) {
	$followlist = db_find('user_follow', array('uid'=>$uid), array(), 1, 1000, 'follow_uid');
	if(empty($followlist)) return array();
	return arrlist_values($followlist, 'follow_uid');
}

function user_follow_find_following_uids_reverse($follow_uid) {
	$followlist = db_find('user_follow', array('follow_uid'=>$follow_uid), array(), 1, 1000, 'uid');
	if(empty($followlist)) return array();
	return arrlist_values($followlist, 'uid');
}

// 批量查询关注状态：当前用户 $uid 是否关注了 $target_uids 中的用户
function user_follow_read_batch($uid, $target_uids) {
    if(empty($uid) || empty($target_uids)) return array();
    $target_uids = array_map('intval', $target_uids);
    $follows = db_find('user_follow', array('uid'=>$uid, 'follow_uid'=>$target_uids), array(), 1, count($target_uids), 'follow_uid');
    $result = array();
    foreach($target_uids as $tid) {
        $result[$tid] = isset($follows[$tid]) ? $follows[$tid] : array();
    }
    return $result;
}

function user_follow_delete_by_uid($uid) {
	global $db;
	$tablepre = $db->tablepre;
	$following = db_find('user_follow', array('uid'=>$uid), array(), 1, 10000);
	if($following) {
		foreach($following as $f) {
			db_exec("UPDATE {$tablepre}user SET followeds=IF(followeds>0,followeds-1,0) WHERE uid='".$f['follow_uid']."'");
		}
	}
	$followers = db_find('user_follow', array('follow_uid'=>$uid), array(), 1, 10000);
	if($followers) {
		foreach($followers as $f) {
			db_exec("UPDATE {$tablepre}user SET follows=IF(follows>0,follows-1,0) WHERE uid='".$f['uid']."'");
		}
	}
	db_delete('user_follow', array('uid'=>$uid));
	db_delete('user_follow', array('follow_uid'=>$uid));
}

// hook model_user_follow_end.php

?>
