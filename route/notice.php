<?php

!defined('DEBUG') AND exit('Access Denied.');

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
		$r = notice_update_by_recvuid($uid);
		if($r === FALSE) {
			if(is_htmx_request()) {
				header('HTTP/1.1 500 Internal Server Error');
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '-1', 'message' => lang('notice_my_update_failed')));
			exit;
		}

		$unread_count = notice_count_unread($uid);
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
		$notice = notice__read($notice_id);
		if(empty($notice)) {
			if(is_htmx_request()) {
				header('HTTP/1.1 404 Not Found');
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '-1', 'message' => lang('not_exists')));
			exit;
		}
		if($notice['recvuid'] != $uid) {
			if(is_htmx_request()) {
				header('HTTP/1.1 403 Forbidden');
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '-1', 'message' => lang('insufficient_privilege')));
			exit;
		}

		$is_read = isset($notice['is_read']) ? $notice['is_read'] : $notice['isread'];
		if($is_read == 1) {
			$unread_count = notice_count_unread($uid);
			if(is_htmx_request()) {
				// 已读状态，返回已读卡片 HTML（OOB 替换）
				$icon_map = array(1=>'speakerphone', 2=>'message', 3=>'file-text');
				$icon_name = isset($icon_map[$notice['type']]) ? $icon_map[$notice['type']] : 'bell';
				$notice_menu = array(
					0 => array('name'=>'其他'),
					1 => array('name'=>'公告'),
					2 => array('name'=>'评论'),
					3 => array('name'=>'系统'),
					99 => array('name'=>'其他'),
				);
				$notice_label_map = array(1=>lang('notify_type_label_notice_announcement'), 3=>lang('notify_type_label_notice_system'));
				$type_label = isset($notice_label_map[$notice['type']]) ? $notice_label_map[$notice['type']] : lang('notify_type_label_notice_other');
				$type_name = isset($notice_menu[$notice['type']]) ? $notice_menu[$notice['type']]['name'] : lang('notify_summary_notice');

				header('Content-Type: text/html; charset=utf-8');
				echo '<div class="notice-card p-3" id="notice-nid-' . $notice_id . '" data-nid="' . $notice_id . '" data-source="notice" hx-swap-oob="true">';
				echo '  <div class="notice-row-top mb-1">';
				echo '    <span class="notice-type-badge">';
				echo '      <i class="ti ti-' . $icon_name . '"></i> ' . htmlspecialchars($type_label);
				echo '    </span>';
				echo '    <span class="notice-time">' . $notice['create_date_fmt'] . '</span>';
				echo '  </div>';
				echo '  <div class="d-flex justify-content-between align-items-start gap-3">';
				echo '    <div class="notice-detail flex-fill" style="min-width:0">' . htmlspecialchars($notice['message']) . '</div>';
				echo '    <div class="notice-actions"></div>';
				echo '  </div>';
				echo '</div>';
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '0', 'message' => lang('notice_my_update_readed'), 'unread_count' => $unread_count));
			exit;
		}

		$r = notice_update($notice_id);
		if($r === FALSE) {
			if(is_htmx_request()) {
				header('HTTP/1.1 500 Internal Server Error');
				exit;
			}
			header('Content-Type: application/json; charset=utf-8');
			echo xn_json_encode(array('code' => '-1', 'message' => lang('notice_my_update_failed')));
			exit;
		}

		$unread_count = notice_count_unread($uid);
		if(is_htmx_request()) {
			// htmx: 返回已读状态的 notice 卡片 HTML（OOB 替换）
			$icon_map = array(1=>'speakerphone', 2=>'message', 3=>'file-text');
			$icon_name = isset($icon_map[$notice['type']]) ? $icon_map[$notice['type']] : 'bell';
			$notice_menu = array(
				0 => array('name'=>'其他'),
				1 => array('name'=>'公告'),
				2 => array('name'=>'评论'),
				3 => array('name'=>'系统'),
				99 => array('name'=>'其他'),
			);
			$notice_label_map = array(1=>lang('notify_type_label_notice_announcement'), 3=>lang('notify_type_label_notice_system'));
			$type_label = isset($notice_label_map[$notice['type']]) ? $notice_label_map[$notice['type']] : lang('notify_type_label_notice_other');
			$type_name = isset($notice_menu[$notice['type']]) ? $notice_menu[$notice['type']]['name'] : lang('notify_summary_notice');

			header('Content-Type: text/html; charset=utf-8');
			echo '<div class="notice-card p-3" id="notice-nid-' . $notice_id . '" data-nid="' . $notice_id . '" data-source="notice" hx-swap-oob="true">';
			echo '  <div class="notice-row-top mb-1">';
			echo '    <span class="notice-type-badge">';
			echo '      <i class="ti ti-' . $icon_name . '"></i> ' . htmlspecialchars($type_label);
			echo '    </span>';
			echo '    <span class="notice-time">' . $notice['create_date_fmt'] . '</span>';
			echo '  </div>';
			echo '  <div class="d-flex justify-content-between align-items-start gap-3">';
			echo '    <div class="notice-detail flex-fill" style="min-width:0">' . htmlspecialchars($notice['message']) . '</div>';
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
	$count = notice_count_unread($uid);
	echo $count;
	exit;

// ---- 顶部下拉通知列表（AJAX） ----
} elseif($action == 'dropdown') {

	!$uid AND exit('');

	$notice_menu = array(
		0 => array('name'=>'其他', 'class'=>'info', 'icon'=>''),
		1 => array('name'=>'公告', 'class'=>'info', 'icon'=>''),
		2 => array('name'=>'评论', 'class'=>'primary', 'icon'=>''),
		3 => array('name'=>'系统', 'class'=>'danger', 'icon'=>''),
		99 => array('name'=>'其他', 'class'=>'success', 'icon'=>'bell'),
	);

	$notifylist = notice_find_latest_by_recvuid($uid, 5);
	if(empty($notifylist)) {
		echo '<div class="text-center text-body-secondary small py-4"><i class="ti ti-bell-off fs-4 d-block mb-1 opacity-50"></i>暂无消息</div>';
		exit;
	}
	$html = '';
	foreach($notifylist as $item) {
		$typeLabel = '通知';
		if($item['type'] == 1) $typeLabel = '发布了公告';
		elseif($item['type'] == 2) $typeLabel = '评论了';
		elseif($item['type'] == 3) $typeLabel = '系统通知';

		$unreadClass = empty($item['is_read']) ? ' notice-unread' : '';
		$unreadDot = empty($item['is_read']) ? ' <span class="badge bg-primary rounded-pill flex-shrink-0" style="font-size:0.5rem;padding:2px 5px;">新</span>' : '';
		$avatar_url = isset($item['from_user_avatar_url']) ? $item['from_user_avatar_url'] : '/view/img/avatar.png';
		$username = isset($item['from_username']) ? $item['from_username'] : '系统';

		$html .= '<a href="' . url("my-notice") . '" class="dropdown-item d-flex align-items-center gap-2 px-3 py-2 notice-dropdown-item' . $unreadClass . '" data-nid="' . $item['nid'] . '" data-source="notice">';
		$html .= '<img class="rounded-circle flex-shrink-0" src="' . htmlspecialchars($avatar_url) . '" alt="" style="width:24px;height:24px;object-fit:cover;" onerror="this.src=\'/view/img/avatar.png\'">';
		$html .= '<span class="fw-semibold" style="font-size:0.8rem;">' . htmlspecialchars($username) . '</span>';
		$html .= '<span class="text-body-secondary flex-shrink-0" style="font-size:0.75rem;">' . $item['create_date_fmt'] . '</span>';
		$html .= '<span class="text-truncate" style="font-size:0.8rem;min-width:0;">' . htmlspecialchars($typeLabel) . '</span>';
		$html .= $unreadDot;
		$html .= '</a>';
	}
	echo $html;
	exit;

// ---- 管理后台路由 ----
} elseif($action == 'create') {

	// 管理后台发送通知
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

		$nid = notice_send($uid, $recvuid, $message_text, 1);
		if($nid === FALSE) {
			// 更具体的错误信息
			if(empty($uid)) {
				message(-1, lang('please_login'));
			}
			message(-1, lang('notice_admin_send_notice_failed'));
		}
		message(0, lang('notice_admin_send_notice_sucessfully'));
	}

} elseif($action == 'list') {

	if($gid != 1) message(-1, lang('insufficient_privilege'));

	$page = param(2, 1);
	$pagesize = 20;
	$active = 'default';
	$notices = notice_count();

	$notice_menu = array(
		0 => array('url'=>url('notice-list'), 'name'=>'全部', 'class'=>'info', 'icon'=>''),
		1 => array('url'=>url('notice-list-1'), 'name'=>'公告', 'class'=>'info', 'icon'=>''),
		2 => array('url'=>url('notice-list-2'), 'name'=>'评论', 'class'=>'primary', 'icon'=>''),
		3 => array('url'=>url('notice-list-3'), 'name'=>'系统', 'class'=>'danger', 'icon'=>''),
		99 => array('url'=>url('notice-list-99'), 'name'=>'其他', 'class'=>'success', 'icon'=>'bell'),
	);

	$cond = array();
	$noticelist = notice_find($cond, $page, $pagesize);
	$pagination = pagination(url("notice-list-{page}"), $notices, $page, $pagesize);

	$header['title'] = lang('notice_admin_notice_list');
	$header['mobile_title'] = lang('notice_admin_notice_list');
	include _include(ADMIN_PATH.'view/htm/admin_notice_list.htm');

} elseif($action == 'publish') {

	// 管理后台发布公告（全局公告）
	if($gid != 1) message(-1, lang('insufficient_privilege'));

	if($method == 'POST') {
		CsrfService::check();

		$icon = param('icon');
		$message_text = param('message', '', FALSE);
		$url = param('url');

		empty($message_text) AND message(-1, '公告内容不能为空');

		// 确保 icon 和 url 列存在
		if(!db_check_column_exists('notice', 'icon')) {
			db_exec("ALTER TABLE ".$db->tablepre."notice ADD COLUMN icon varchar(64) NOT NULL DEFAULT '' AFTER is_read");
		}
		if(!db_check_column_exists('notice', 'url')) {
			db_exec("ALTER TABLE ".$db->tablepre."notice ADD COLUMN url varchar(255) NOT NULL DEFAULT '' AFTER icon");
		}

		$arr = array(
			'fromuid' => $uid,
			'recvuid' => 0, // 0 = 全局公告
			'create_date' => $time,
			'isread' => 0,
			'is_read' => 0,
			'type' => 1, // 公告类型
			'message' => $message_text,
			'icon' => $icon,
			'url' => $url,
		);

		$r = notice__create($arr);
		$r === FALSE AND message(-1, '发布失败');

		message(0, '公告发布成功');
	}

} elseif($action == 'announcements') {

	// 前台获取最新公告（全局 recvuid=0 的公告）
	// 确保 icon 和 url 列存在
	$has_icon = db_check_column_exists('notice', 'icon');
	$has_url = db_check_column_exists('notice', 'url');

	$announcements = db_find('notice', array('type'=>1, 'recvuid'=>0), array('nid'=>-1), 1, 3, 'nid');
	$list = array();
	if($announcements) {
		foreach($announcements as $a) {
			$list[] = array(
				'nid' => $a['nid'],
				'message' => $a['message'],
				'url' => $has_url ? $a['url'] : '',
				'icon' => $has_icon ? $a['icon'] : 'ti-speakerphone',
				'create_date' => $a['create_date'],
			);
		}
	}
	message(0, $list);

} elseif($action == 'announcement_list') {

	// 管理后台获取全局公告列表（HTML 片段，供 AJAX 加载）
	if($gid != 1) message(-1, lang('insufficient_privilege'));

	$has_icon = db_check_column_exists('notice', 'icon');
	$has_url = db_check_column_exists('notice', 'url');

	$announcements = db_find('notice', array('type'=>1, 'recvuid'=>0), array('nid'=>-1), 1, 50, 'nid');

	header('Content-Type: text/html; charset=utf-8');
	if($announcements) {
		foreach($announcements as $a) {
			$icon_class = $has_icon && $a['icon'] ? $a['icon'] : 'ti-speakerphone';
			$url_link = $has_url && $a['url'] ? $a['url'] : '';
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

	if($gid != 1) message(-1, lang('insufficient_privilege'));

	$nid = param('nid');
	$r = notice_delete($nid);
	$r === FALSE AND message(-1, lang('notice_delete_notice_failed'));
	message(0, lang('notice_delete_notice_sucessfully'));
}

?>
