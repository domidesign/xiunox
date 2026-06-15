<?php

// ------------> 最原生的 CURD，无关联其他数据。

// 创建消息
function notice__create($arr) {

	$r = db_create('notice', $arr);
	return $r;

}

// 更新消息
function notice__update($nid, $arr) {

	$r = db_update('notice', array('nid'=>$nid), $arr);
	return $r;
}

// 读取消息
function notice__read($nid) {

	$post = db_find_one('notice', array('nid'=>$nid));
	return $post;

}

// 删除消息
function notice__delete($nid) {

	$r = db_delete('notice', array('nid'=>$nid));
	return $r;
}


// 查找消息
function notice__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {

	$noticelist = db_find('notice', $cond, $orderby, $page, $pagesize, 'nid');
	return $noticelist;
}

// ------------> 关联 CURD

// 发送信息
function notice_send($fromuid, $recvuid, $message, $type = 99) {

	//global $gid, $time;
	global $time;
	if(empty($fromuid) || empty($recvuid)) return FALSE;
	if($fromuid == $recvuid) return FALSE;
	$type == 0 AND $type = 99;

	$arr = array(
		'fromuid'=>$fromuid,
		'recvuid'=>$recvuid,
		'create_date'=>$time,
		'isread'=>0,
		'type'=>$type,        //0:全部 1:通知 2:评论 3:主题
		'message'=>$message,
	);

	// 兼容：如果 is_read 列存在则写入
	if(db_check_column_exists('notice', 'is_read')) {
		$arr['is_read'] = 0;
	}

	//notice_message_fmt($arr, $gid);

	$nid = notice__create($arr);
	if($nid === FALSE) return FALSE;

	// 更新统计数据
	user__update($recvuid, array('unread_notices+'=>1, 'notices+'=>1));

	return $nid;
}

// 查找用户的消息
function notice_find_by_recvuid($recvuid, $page = 1, $pagesize = 20, $type = 99) {

	$cond = array('recvuid'=>$recvuid, 'type'=>$type);
	$type == 0 AND $cond = array('recvuid'=>$recvuid);

	$noticelist = notice_find($cond, $page, $pagesize);

	return $noticelist;

}

// 更新(用户)所有消息为(已读)
function notice_update_by_recvuid($recvuid, $arr = NULL) {

	if($arr === NULL) {
		$arr = array('isread'=>1);
		if(db_check_column_exists('notice', 'is_read')) {
			$arr['is_read'] = 1;
		}
	}

	$cond = array('recvuid'=>$recvuid);
	if(db_check_column_exists('notice', 'is_read')) {
		$cond['is_read'] = 0;
	} else {
		$cond['isread'] = 0;
	}

	$r = db_update('notice', $cond, $arr);
	if($r === FALSE) return FALSE;

	// 更新统计数据
	user__update($recvuid, array('unread_notices'=>0));

	return $r;

}

// 更新单条消息为(已读)
function notice_update($nid, $arr = NULL) {

	if($arr === NULL) {
		$arr = array('isread'=>1);
		if(db_check_column_exists('notice', 'is_read')) {
			$arr['is_read'] = 1;
		}
	}

	$notice = notice__read($nid);
	if(empty($notice)) return FALSE;

	$recvuid = $notice['recvuid'];

	$r = notice__update($nid, $arr);
	if($r === FALSE) return FALSE;

	// 更新统计数据
	user__update($recvuid, array('unread_notices-'=>1));

	return $r;

}

// 删除单条消息
function notice_delete($nid) {

	$notice = notice__read($nid);
	if(empty($notice)) return TRUE;

	$recvuid = $notice['recvuid'];
	$is_read = isset($notice['is_read']) ? $notice['is_read'] : $notice['isread'];

	$r = notice__delete($nid);
	if($r === FALSE) return FALSE;

	// 更新统计数据
	user__update($recvuid, array('notices-'=>1));

	// 如果信息是未读状态，用户未读-1
	$is_read == 0 AND user__update($recvuid, array('unread_notices-'=>1));


	return $r;

}

// 删除用户所有消息
function notice_delete_by_recvuid($recvuid) {

	$r = db_delete('notice', array('recvuid'=>$recvuid));
	if($r === FALSE) return FALSE;

	// 更新统计数据
	user__update($recvuid, array('unread_notices'=>0, 'notices'=>0));

	return $r;
}

// 获取消息信息(含有用户名和头像)的方法
function notice_find($cond = array(), $page = 1, $pagesize = 20) {

	$noticelist = notice__find($cond, array('nid'=>-1), $page, $pagesize);

	if($noticelist) foreach($noticelist as &$notice) notice_format($notice);

	return $noticelist;
}

// 获取消息前格式化信息
function notice_format(&$notice){

	global $notice_menu;
	if(empty($notice)) return;
	$notice['create_date_fmt'] = humandate($notice['create_date']); //友好的时间
	$fromuser = user_read_cache($notice['fromuid']);
	$recvuser = user_read_cache($notice['recvuid']);

	$notice['from_username'] = $fromuser['username'];
	$notice['from_user_avatar_url'] = $fromuser['avatar_url'];

	$notice['recv_username'] = $recvuser['username'];
	$notice['recv_user_avatar_url'] = $recvuser['avatar_url'];

	// 防御：$notice_menu 未定义时使用默认值，避免 PHP Warning
	if(empty($notice_menu)) {
		$notice_menu = array(
			0 => array('name'=>'其他', 'class'=>'info', 'icon'=>''),
			1 => array('name'=>'公告', 'class'=>'info', 'icon'=>''),
			2 => array('name'=>'评论', 'class'=>'primary', 'icon'=>'message'),
			3 => array('name'=>'系统', 'class'=>'danger', 'icon'=>''),
			99 => array('name'=>'其他', 'class'=>'success', 'icon'=>'bell'),
		);
	}
	!isset($notice_menu[$notice['type']]) AND $notice['type'] = 0;

	$notice['name'] = $notice_menu[$notice['type']]['name'];
	$notice['class'] = $notice_menu[$notice['type']]['class'];
	$notice['icon'] = $notice_menu[$notice['type']]['icon'];

	// 统一 is_read 字段（兼容旧 isread）
	if(isset($notice['is_read'])) {
		// is_read 列存在，同步到 isread
		$notice['isread'] = $notice['is_read'];
	} else {
		// is_read 列不存在，用 isread 代替
		$notice['is_read'] = isset($notice['isread']) ? $notice['isread'] : 0;
	}

}

// ------------> 其他方法

// 发送时格式化关联用户组，暂未使用
function notice_message_fmt(&$arr, $gid) {

	// 截取255字节，管理员发送的信息不截取
	$arr['message'] = ($gid == 1 ? $arr['message'] : xn_html_safe(xn_substr($arr['message'], 0, 255)));

}

// 消息截取
function notice_substr($s, $len = 20, $htmlspe = TRUE){

   	if($htmlspe == FALSE){
   		$s = strip_tags($s);
		$s = htmlspecialchars($s);
   	}
   	$more = xn_strlen($s) > $len ? '...' : '';
	$s = xn_substr($s, 0, $len).$more;

	return $s;
}

// 统计消息列表数量
function notice_count($cond = array()) {

	$n = db_count('notice', $cond);

	return $n;
}

// 统计用户未读消息数量
function notice_count_unread($recvuid) {

	// 兼容：如果 is_read 列不存在，回退到 isread
	if(db_check_column_exists('notice', 'is_read')) {
		$n = db_count('notice', array('recvuid'=>$recvuid, 'is_read'=>0));
	} else {
		$n = db_count('notice', array('recvuid'=>$recvuid, 'isread'=>0));
	}
	return $n;
}

// 获取用户最新N条通知（用于顶部下拉）
function notice_find_latest_by_recvuid($recvuid, $pagesize = 5) {

	$noticelist = notice__find(array('recvuid'=>$recvuid), array('nid'=>-1), 1, $pagesize);
	if($noticelist) foreach($noticelist as &$notice) notice_format($notice);
	return $noticelist;
}

?>
