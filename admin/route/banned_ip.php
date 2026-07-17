<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// hook admin_banned_ip_start.php

// 加载 IpBlacklistService（统一 IP 黑名单管理）
if(!class_exists('IpBlacklistService')) {
	include_once APP_PATH.'lib/security/IpBlacklistService.php';
}

if(empty($action) || $action == 'list') {

	// hook admin_banned_ip_list_start.php

	$header['title'] = lang('admin_banned_ip_title');
	$header['mobile_title'] = lang('admin_banned_ip_title');

	$page = param(2, 1);
	$pagesize = 50;

	// 使用 IpBlacklistService API（kv 存储，支持 CIDR/范围）
	$n = IpBlacklistService::count_blacklist();
	$banned_list = IpBlacklistService::get_blacklist_page($page, $pagesize);
	$pagination = pagination(url("banned_ip-list-{page}"), $n, $page, $pagesize);

	// 预计算每条记录的展示字段（兼容旧模板字段名）
	foreach($banned_list as &$_item) {
		// IP 展示（直接用 ip 字段，支持单IP/CIDR/范围）
		$_item['ip_range_fmt'] = $_item['ip'];
		// 时间字段映射
		$_item['create_time'] = isset($_item['create_date']) ? intval($_item['create_date']) : 0;
		$_item['create_time_fmt'] = $_item['create_time'] > 0 ? date('Y-m-d H:i', $_item['create_time']) : '-';
		$_item['expire_time'] = isset($_item['expire_time']) ? intval($_item['expire_time']) : 0;
		$_item['expire_time_fmt'] = ($_item['expire_time'] == 0) ? lang('admin_banned_ip_permanent') : date('Y-m-d H:i', $_item['expire_time']);
		// 操作管理员用户名
		$_admin_uid = isset($_item['admin_uid']) ? intval($_item['admin_uid']) : 0;
		$_admin = $_admin_uid > 0 ? user_read($_admin_uid) : false;
		$_item['admin_username'] = $_admin ? $_admin['username'] : '-';
		// 是否已过期
		$_item['is_expired'] = ($_item['expire_time'] > 0 && $_item['expire_time'] < time());
		// reason 字段映射（模板用 reason，kv 用 remark）
		$_item['reason'] = isset($_item['remark']) ? $_item['remark'] : '';
		// 用 ip 字符串作为唯一标识（kv 无 id）
		$_item['id'] = $_item['ip'];
	}

	// hook admin_banned_ip_list_end.php

	include _include(ADMIN_PATH."view/htm/banned_ip_list.htm");

} elseif($action == 'create') {

	// 新增 IP 黑名单 - POST 处理在 header include 之前
	if($method != 'POST') message(-1, lang('method_error'));

	if(!class_exists('CsrfService')) {
		include_once APP_PATH.'lib/CsrfService.php';
	}
	CsrfService::check();

	// 支持新字段 ip（单IP/CIDR/范围）和旧字段 ip_start/ip_end（兼容旧插件提交）
	$ip = param('ip', '', FALSE);
	$ip_start = param('ip_start', '', FALSE);
	$ip_end = param('ip_end', '', FALSE);
	$reason = param('reason', '', FALSE);
	$duration = intval(param('duration', 0));

	// hook admin_banned_ip_create_post_start.php

	// 智能组装 IP：新字段优先，旧字段兼容
	if(!empty($ip)) {
		// 新字段：用户直接输入单IP/CIDR/范围
		$final_ip = $ip;
	} elseif(!empty($ip_start)) {
		// 兼容旧字段：ip_start + ip_end
		if(empty($ip_end) || $ip_end == $ip_start) {
			$final_ip = $ip_start;
		} else {
			$final_ip = $ip_start.'-'.$ip_end;
		}
	} else {
		$final_ip = '';
	}

	empty($final_ip) AND message('ip', lang('admin_banned_ip_invalid_ip'));

	// 过期时间：0=永久，否则当前时间 + 时长
	$expire_time = ($duration > 0) ? (time() + $duration) : 0;

	global $user;
	$admin_uid = intval($user['uid']);

	$r = IpBlacklistService::add_blacklist_entry($final_ip, $reason, $expire_time, $admin_uid);
	$r === false AND message(-1, lang('admin_banned_ip_add_failed'));

	admin_log_create('banned_ip_create', 'banned_ip', $final_ip, '添加IP黑名单：'.$final_ip);

	// hook admin_banned_ip_create_post_end.php

	message(0, lang('admin_banned_ip_add_success'));

} elseif($action == 'delete') {

	// 删除 IP 黑名单 - POST 处理在 header include 之前
	if($method != 'POST') message(-1, lang('method_error'));

	if(!class_exists('CsrfService')) {
		include_once APP_PATH.'lib/CsrfService.php';
	}
	CsrfService::check();

	// 用 IP 字符串作为标识（kv 存储无自增 id）
	$ip = param('ip', '', FALSE);
	empty($ip) AND message(-1, lang('admin_banned_ip_invalid_id'));

	$r = IpBlacklistService::remove_from_blacklist($ip);
	$r === false AND message(-1, lang('admin_banned_ip_not_exists'));

	admin_log_create('banned_ip_delete', 'banned_ip', $ip, '删除IP黑名单：'.$ip);

	// hook admin_banned_ip_delete_post_end.php

	message(0, lang('admin_banned_ip_delete_success'));

}

// hook admin_banned_ip_end.php

?>
