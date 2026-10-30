<?php

// hook model_post_like_start.php

function post_like__create($arr) {
	// hook model_post_like__create_start.php
	global $db;
	$tablepre = $db->tablepre;
	// 使用 INSERT IGNORE 避免主键冲突；PDO 预处理防注入
	list($sqladd, $params) = db_array_to_insert_sqladd($arr);
	if(!$sqladd) return 0;
	$sql = "INSERT IGNORE INTO {$tablepre}post_like $sqladd";
	$stmt = $db->prepare($sql, $params);
	if(!$stmt) return 0;
	// 直接获取受影响行数（1=新增成功，0=已存在）
	$r = intval($stmt->rowCount());
	$stmt->closeCursor();
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
	$uid = intval($uid);
	$tid = intval($tid);
	$pid = intval($pid);
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
	// 失效回帖列表缓存（点赞后 likes 数和 is_liked 状态需立即更新）
	post_list_cache_bump_version($tid);
	// hook model_post_like_create_end.php
	return $r;
}

function post_like_delete($uid, $tid, $pid) {
	global $db;
	// hook model_post_like_delete_start.php
	$uid = intval($uid);
	$tid = intval($tid);
	$pid = intval($pid);
	// 直接 DELETE，通过返回值判断是否真正删除
	$r = post_like__delete($uid, $pid);
	// $r 为受影响行数：1=删除成功，0=原本不存在
	if($r && $r > 0) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE `{$tablepre}post` SET likes=IF(likes>0,likes-1,0) WHERE pid='$pid'");
		db_exec("UPDATE `{$tablepre}thread` SET likes=IF(likes>0,likes-1,0) WHERE tid='$tid'");
	}
	// 失效回帖列表缓存（取消点赞后 likes 数和 is_liked 状态需立即更新）
	post_list_cache_bump_version($tid);
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

// 整帖删除时按 tid 清理点赞行（thread 行同删，thread.likes 随行消失，仅清孤儿数据）
function post_like_delete_by_tid($tid) {
	// hook model_post_like_delete_by_tid_start.php
	$tid = intval($tid);
	if($tid <= 0) return 0;
	// hook model_post_like_delete_by_tid_end.php
	return db_delete('post_like', array('tid'=>$tid));
}

// 硬删回帖时按 pid 清理点赞行，并按 tid 聚合递减 thread.likes（GREATEST 下限保护）
// ponytail: 此前所有硬删路径（楼层删除/回收站彻底删除/清空用户数据）都不清理点赞行，
// thread.likes 永久虚高且列表页可见（心形计数）。软删/恢复不走此函数：软删保留点赞行，
// 恢复后点赞原样可见，天然对称。首帖点赞即主题点赞，首帖被清时同样适用。
function post_like_delete_by_pids($pids) {
	// hook model_post_like_delete_by_pids_start.php
	if(empty($pids) || !is_array($pids)) return 0;
	$pids = array_map('intval', $pids);
	$pids = array_values(array_unique(array_filter($pids)));
	if(empty($pids)) return 0;
	// 按 tid 聚合将被清理的点赞数（删行后就查不到了）；pid 分片防 IN 子句超长
	$thread_likes_dec = array();
	$r = 0;
	foreach(array_chunk($pids, 500) as $_pid_chunk) {
		global $db;
		$tablepre = $db->tablepre;
		$pid_in = implode(',', $_pid_chunk);
		$rows = db_sql_find("SELECT tid, COUNT(*) AS c FROM {$tablepre}post_like WHERE pid IN ($pid_in) GROUP BY tid");
		if($rows) {
			foreach($rows as $row) {
				$_tid = intval($row['tid']);
				if($_tid > 0) {
					if(!isset($thread_likes_dec[$_tid])) $thread_likes_dec[$_tid] = 0;
					$thread_likes_dec[$_tid] += intval($row['c']);
				}
			}
		}
		$r += db_delete('post_like', array('pid'=>$_pid_chunk));
	}
	foreach($thread_likes_dec as $_tid => $cnt) {
		thread_dec($_tid, 'likes', $cnt);
	}
	// hook model_post_like_delete_by_pids_end.php
	return $r;
}

// hook model_post_like_end.php

?>
