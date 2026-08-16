<?php

// ------------> 最原生的 CURD，无关联其他数据。

// 创建邮件日志
function email_log__create($arr) {

	$r = db_create('email_log', $arr);
	return $r;

}

// 读取邮件日志
function email_log__read($logid) {

	$log = db_find_one('email_log', array('logid'=>$logid));
	return $log;

}

// 删除邮件日志
function email_log__delete($logid) {

	$r = db_delete('email_log', array('logid'=>$logid));
	return $r;
}

// 查找邮件日志
function email_log__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {

	$loglist = db_find('email_log', $cond, $orderby, $page, $pagesize, 'logid');
	return $loglist;
}

// ------------> 关联 CURD

// 创建邮件日志记录
function email_log_create($arr) {

	global $time, $ip;

	$arr['create_date'] = isset($arr['create_date']) ? $arr['create_date'] : $time;
	$arr['ip'] = isset($arr['ip']) ? $arr['ip'] : $ip;

	$logid = email_log__create($arr);
	return $logid;
}

// 读取单条邮件日志
function email_log_read($logid) {

	$log = email_log__read($logid);
	if(empty($log)) return array();

	email_log_format($log);

	return $log;
}

// 查询邮件日志列表
function email_log_find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {

	$loglist = email_log__find($cond, $orderby, $page, $pagesize);

	if($loglist) foreach($loglist as &$log) email_log_format($log);

	return $loglist;
}

// 统计邮件日志数量
function email_log_count($cond = array()) {

	$n = db_count('email_log', $cond);
	return $n;
}

// 删除邮件日志
function email_log_delete($logid) {

	$r = email_log__delete($logid);
	return $r;
}

// 清理指定天数之前的日志
function email_log_clean($days) {

	global $time;

	$before = $time - $days * 86400;
	$r = db_delete('email_log', array('create_date<'=>$before));
	return $r;
}

// 格式化邮件日志
function email_log_format(&$log) {

	if(empty($log)) return;

	$log['create_date_fmt'] = humandate($log['create_date']);
	$log['status_name'] = $log['status'] ? lang('success_label') : lang('failed_label');
	$log['ip_fmt'] = long2ip($log['ip']);

}

?>
