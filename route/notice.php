<?php

!defined('DEBUG') AND exit('Access Denied.');

// 通知类型注册中心（集中管理 type -> tab/icon/label 映射，插件可通过 hook 扩展）
include_once APP_PATH . 'lib/NotifyTypeRegistry.php';
NotifyTypeRegistry::init();

$action = param(1);

// ---- 标记已读 ----
if($action == 'mark_read') {

	if($method != 'POST') {
		if(is_htmx_request()) {
			header('HTTP/1.1 405 Method Not Allowed');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code' => '-1', 'message' => 'Method Not Allowed'));
		exit;
	}

	CsrfService::check();

	if(!$uid) {
		if(is_htmx_request()) {
			header('HTTP/1.1 401 Unauthorized');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code' => '-1', 'message' => lang('please_login')));
		exit;
	}

	$all = param('all', 0);
	$notice_id = param('notice_id', 0);

	if($all) {
		// 全部已读：操作 notify 表
		$r = notify_mark_all_read($uid);
		if($r === FALSE) {
			if(is_htmx_request()) {
				header('HTTP/1.1 500 Internal Server Error');
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '-1', 'message' => lang('notice_my_update_failed')));
			exit;
		}

		$unread_count = notify_count_unread($uid);
		if(is_htmx_request()) {
			// htmx: 返回 HX-Trigger 事件，前端监听后刷新通知列表
			header('HX-Trigger: {"noticeMarkAllRead": {"unread_count": ' . intval($unread_count) . '}}');
			header('HTTP/1.1 204 No Content');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code' => '0', 'message' => lang('operate_successfully'), 'unread_count' => $unread_count));
		exit;

	} elseif($notice_id) {
		// 单条已读：操作 notify 表
		$notify = notify__read($notice_id);
		if(empty($notify)) {
			if(is_htmx_request()) {
				header('HTTP/1.1 404 Not Found');
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '-1', 'message' => lang('not_exists')));
			exit;
		}
		if($notify['uid'] != $uid) {
			if(is_htmx_request()) {
				header('HTTP/1.1 403 Forbidden');
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '-1', 'message' => lang('insufficient_privilege')));
			exit;
		}

		$is_read = isset($notify['is_read']) ? $notify['is_read'] : 0;
		if($is_read == 1) {
			$unread_count = notify_count_unread($uid);
			if(is_htmx_request()) {
				// 已读状态，返回已读卡片 HTML（OOB 替换）
				notify_format($notify);
				$icon_name = NotifyTypeRegistry::get_icon($notify['type']);
				$type_label = isset($notify['type_label']) ? $notify['type_label'] : lang('notify_type_label_notice_other');

				header('Content-Type: text/html; charset=utf-8');
				echo '<div class="notice-card p-3" id="notice-nid-' . $notice_id . '" data-nid="' . $notice_id . '" data-source="notify" hx-swap-oob="true">';
				echo '  <div class="notice-row-top mb-1">';
				echo '    <span class="notice-type-badge">';
				echo '      <i class="ti ti-' . $icon_name . '"></i> ' . htmlspecialchars($type_label);
				echo '    </span>';
				echo '    <span class="notice-time">' . $notify['create_date_fmt'] . '</span>';
				echo '  </div>';
				echo '  <div class="d-flex justify-content-between align-items-start gap-3">';
				echo '    <div class="notice-detail flex-fill" style="min-width:0">' . htmlspecialchars($notify['message']) . '</div>';
				echo '    <div class="notice-actions"></div>';
				echo '  </div>';
				echo '</div>';
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '0', 'message' => lang('notice_my_update_readed'), 'unread_count' => $unread_count));
			exit;
		}

		$r = notify_mark_read($notice_id);
		if($r === FALSE) {
			if(is_htmx_request()) {
				header('HTTP/1.1 500 Internal Server Error');
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '-1', 'message' => lang('notice_my_update_failed')));
			exit;
		}

		$unread_count = notify_count_unread($uid);
		if(is_htmx_request()) {
			// htmx: 返回已读状态的 notify 卡片 HTML（OOB 替换）
			notify_format($notify);
			$notify['is_read'] = 1;
			$icon_name = NotifyTypeRegistry::get_icon($notify['type']);
			$type_label = isset($notify['type_label']) ? $notify['type_label'] : lang('notify_type_label_notice_other');

			header('Content-Type: text/html; charset=utf-8');
			echo '<div class="notice-card p-3" id="notice-nid-' . $notice_id . '" data-nid="' . $notice_id . '" data-source="notify" hx-swap-oob="true">';
			echo '  <div class="notice-row-top mb-1">';
			echo '    <span class="notice-type-badge">';
			echo '      <i class="ti ti-' . $icon_name . '"></i> ' . htmlspecialchars($type_label);
			echo '    </span>';
			echo '    <span class="notice-time">' . $notify['create_date_fmt'] . '</span>';
			echo '  </div>';
			echo '  <div class="d-flex justify-content-between align-items-start gap-3">';
			echo '    <div class="notice-detail flex-fill" style="min-width:0">' . htmlspecialchars($notify['message']) . '</div>';
			echo '    <div class="notice-actions"></div>';
			echo '  </div>';
			echo '</div>';
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code' => '0', 'message' => lang('operate_successfully'), 'unread_count' => $unread_count));
		exit;
	} else {
		if(is_htmx_request()) {
			header('HTTP/1.1 400 Bad Request');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code' => '-1', 'message' => lang('parameters_error')));
		exit;
	}

// ---- 未读数量 ----
} elseif($action == 'unread_count') {

	!$uid AND exit('0');
	// 通知系统已合并，仅查询 notify 表
	$count = notify_count_unread($uid);
	echo $count;
	exit;

// ---- 顶部下拉通知列表（AJAX） ----
} elseif($action == 'dropdown') {

	!$uid AND exit('');

	// 通知系统已合并，仅查询 notify 表
	$notifylist = notify_find_latest($uid, 5);
	if(empty($notifylist)) {
		echo '<div class="text-center text-body-secondary small py-4"><i class="ti ti-bell-off fs-4 d-block mb-1 opacity-50"></i>暂无消息</div>';
		exit;
	}

	$html = '';
	foreach($notifylist as $item) {
		$unreadClass = empty($item['is_read']) ? ' notice-unread' : '';
		$unreadDot = empty($item['is_read']) ? ' <span class="badge bg-primary rounded-pill flex-shrink-0" style="font-size:0.5rem;padding:2px 5px;">新</span>' : '';
		$avatar_url = isset($item['from_avatar_url']) ? $item['from_avatar_url'] : default_avatar_url();
		$username = isset($item['from_username']) ? $item['from_username'] : '系统';
		$href = !empty($item['url']) ? $item['url'] : my_notify_url();

		// 提取具体内容摘要：优先用 message（去除 HTML 标签和用户名前缀），其次用 summary，兜底用 typeLabel
		$_msg_text = strip_tags($item['message']);
		// message 中可能包含 "用户名 操作描述" 前缀，下拉列表已单独显示用户名，去掉重复
		$_username_prefix = $username . ' ';
		if(mb_strpos($_msg_text, $_username_prefix) === 0) {
			$_msg_text = mb_substr($_msg_text, mb_strlen($_username_prefix));
		}
		$_msg_text = trim($_msg_text);
		if(empty($_msg_text)) {
			$_msg_text = isset($item['summary']) ? $item['summary'] : NotifyTypeRegistry::get_label($item['type']);
		}
		// 截断过长内容
		if(mb_strlen($_msg_text) > 50) {
			$_msg_text = mb_substr($_msg_text, 0, 50) . '...';
		}

		$html .= '<a href="' . htmlspecialchars($href) . '" class="dropdown-item d-flex align-items-center gap-2 px-3 py-2 notice-dropdown-item' . $unreadClass . '" data-nid="' . $item['nid'] . '" data-source="notify" hx-boost="false">';
		$html .= avatar_component_from_data($avatar_url, 'xs', '', '', 0, array('show_hooks' => false, 'lazy' => false, 'extra_class' => 'flex-shrink-0'));
		$html .= '<span class="fw-semibold" style="font-size:0.8rem;">' . htmlspecialchars($username) . '</span>';
		$html .= '<span class="text-body-secondary flex-shrink-0" style="font-size:0.75rem;">' . $item['create_date_fmt'] . '</span>';
		$html .= '<span class="text-truncate" style="font-size:0.8rem;min-width:0;">' . htmlspecialchars($_msg_text) . '</span>';
		$html .= $unreadDot;
		$html .= '</a>';
	}
	echo $html;
	exit;

// ---- 管理后台路由 ----
} elseif($action == 'create') {

	// 管理后台发送通知（单用户）
	if($gid != 1) message(-1, lang('insufficient_privilege'));

	if($method == 'GET') {
		$header['title'] = lang('notice_admin_send_notice');
		$header['mobile_title'] = lang('notice_admin_send_notice');
		$input = array();
		$input['message'] = form_textarea('message', '', '100%', 150);
		$input['recvuid'] = form_text('recvuid', '', 200, 'uid');
		include _include(ADMIN_PATH.'view/htm/admin_notice_create.htm');
	} else {
		CsrfService::check();
		$message_text = param('message', '', FALSE);
		$recvuid = param('recvuid', 0);

		empty($message_text) AND message('message', lang('notice_admin_send_notice_message_empty'));
		empty($recvuid) AND message('recvuid', lang('notice_admin_send_notice_recvuid_empty'));

		$recvuid_check = user__read($recvuid);
		$recvuid_check === FALSE AND message('recvuid', lang('notice_admin_send_notice_user_empty'));

		// 不能给自己发通知
		$uid == $recvuid AND message('recvuid', lang('notice_admin_send_notice_self'));

		// 通知系统已合并，使用 notify_create 写入 notify 表
		$nid = notify_create($recvuid, $uid, 'system', 0, 0, '', array('message' => $message_text));
		if($nid === FALSE) {
			if(empty($uid)) {
				message(-1, lang('please_login'));
			}
			message(-1, lang('notice_admin_send_notice_failed'));
		}
		message(0, lang('notice_admin_send_notice_sucessfully'));
	}

} elseif($action == 'list') {

	$page = param(2, 1);
	$pagesize = 20;
	$active = 'default';

	$notice_menu = array(
		0 => array('url'=>notice_list_url(), 'name'=>'全部', 'class'=>'info', 'icon'=>''),
		'announcement' => array('url'=>notice_list_url('announcement'), 'name'=>'公告', 'class'=>'info', 'icon'=>'speakerphone'),
		'system' => array('url'=>notice_list_url('system'), 'name'=>'系统', 'class'=>'danger', 'icon'=>'file-text'),
		'like' => array('url'=>notice_list_url('like'), 'name'=>'点赞', 'class'=>'danger', 'icon'=>'heart'),
		'reply' => array('url'=>notice_list_url('reply'), 'name'=>'评论', 'class'=>'primary', 'icon'=>'message'),
		'favorite' => array('url'=>notice_list_url('favorite'), 'name'=>'收藏', 'class'=>'warning', 'icon'=>'star'),
		'follow' => array('url'=>notice_list_url('follow'), 'name'=>'关注', 'class'=>'success', 'icon'=>'user-plus'),
	);

	$cond = array();
	$noticelist = db_find('notify', $cond, array('nid'=>-1), $page, $pagesize, 'nid');
	if($noticelist) foreach($noticelist as &$n) notify_format($n);
	$notices = db_count('notify', $cond);
	$pagination = pagination(route_url('notice_list_page'), $notices, $page, $pagesize);

	$header['title'] = lang('notice_admin_notice_list');
	$header['mobile_title'] = lang('notice_admin_notice_list');
	include _include(ADMIN_PATH.'view/htm/admin_notice_list.htm');

} elseif($action == 'publish') {

	// 管理后台发布公告（全局公告，uid=0）
	if($gid != 1) message(-1, lang('insufficient_privilege'));

	if($method == 'POST') {
		CsrfService::check();

		$icon = param('icon');
		$message_text = param('message', '', FALSE);
		$url = param('url');

		empty($message_text) AND message(-1, '公告内容不能为空');

		// 通知系统已合并，使用 notify_create 写入全局公告（uid=0）
		// from_uid 使用管理员 uid，type='announcement'
		$nid = notify_create(0, $uid, 'announcement', 0, 0, '', array(
			'message' => $message_text,
			'icon' => $icon,
			'url' => $url,
		));
		// notify_create 对 uid==from_uid 会跳过，但 uid=0 != from_uid，所以正常写入
		// 但 notify_create 内部对 uid==0 的情况需要特殊处理：全局公告需要给所有用户可见
		// 这里直接写入 notify__create，绕过 notify_create 的 uid==from_uid 检查
		if($nid === FALSE) {
			// 降级：直接写入
			global $time;
			$arr = array(
				'uid' => 0,
				'from_uid' => $uid,
				'type' => 'announcement',
				'tid' => 0,
				'pid' => 0,
				'content' => '',
				'message' => $message_text,
				'icon' => $icon,
				'url' => $url,
				'reply_to_uid' => 0,
				'parent_pid' => 0,
				'create_date' => $time,
				'is_read' => 0,
			);
			$nid = notify__create($arr);
		}

		$nid === FALSE AND message(-1, '发布失败');

		message(0, '公告发布成功');
	}

} elseif($action == 'announcements') {

	// 前台获取最新公告（全局 uid=0 的 announcement 类型）
	$announcements = db_find('notify', array('uid'=>0, 'type'=>'announcement'), array('nid'=>-1), 1, 3, 'nid');
	$list = array();
	if($announcements) {
		foreach($announcements as $a) {
			$list[] = array(
				'nid' => $a['nid'],
				'message' => $a['message'],
				'url' => isset($a['url']) ? $a['url'] : '',
				'icon' => isset($a['icon']) && $a['icon'] ? $a['icon'] : 'ti-speakerphone',
				'create_date' => $a['create_date'],
			);
		}
	}
	message(0, $list);

} elseif($action == 'announcement_list') {

	// 管理后台获取全局公告列表（HTML 片段，供 AJAX 加载）
	if($gid != 1) message(-1, lang('insufficient_privilege'));

	$announcements = db_find('notify', array('uid'=>0, 'type'=>'announcement'), array('nid'=>-1), 1, 50, 'nid');

	header('Content-Type: text/html; charset=utf-8');
	if($announcements) {
		foreach($announcements as $a) {
			$icon_class = !empty($a['icon']) ? $a['icon'] : 'ti-speakerphone';
			$url_link = !empty($a['url']) ? $a['url'] : '';
			echo '<div class="d-flex align-items-start gap-3 p-3 border-bottom" id="announcement-item-'.$a['nid'].'">';
			echo '<div class="flex-shrink-0"><i class="ti '.$icon_class.' fs-4 text-primary"></i></div>';
			echo '<div class="flex-fill" style="min-width:0">';
			echo '<div class="fw-semibold small mb-1">'.htmlspecialchars($a['message']).'</div>';
			if($url_link) {
				echo '<a href="'.htmlspecialchars($url_link).'" class="text-decoration-none small" target="_blank"><i class="ti ti-external-link me-1"></i>'.htmlspecialchars($url_link).'</a>';
			}
			echo '<div class="text-body-tertiary" style="font-size:.75rem">'.date('Y-m-d H:i', $a['create_date']).'</div>';
			echo '</div>';
			echo '<button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0 confirm" data-nid="'.$a['nid'].'" title="删除"><i class="ti ti-trash"></i></button>';
			echo '</div>';
		}
	} else {
		echo '<div class="text-center text-body-secondary py-4"><i class="ti ti-bell-off fs-4 d-block mb-1 opacity-50"></i>暂无公告</div>';
	}
	exit;

} elseif($action == 'delete') {

if($method != 'POST') message(-1, 'Method Error.');
CsrfService::check();

// 删除通知（操作 notify 表）
if($gid != 1) message(-1, lang('insufficient_privilege'));

	$nid = param('nid');
	$r = notify__delete($nid);
	$r === FALSE AND message(-1, lang('notice_delete_notice_failed'));
	message(0, lang('notice_delete_notice_sucessfully'));
}

?>
