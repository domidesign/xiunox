<?php

!defined('DEBUG') AND exit('Access Denied.');

include _include(XIUNOPHP_PATH.'xn_send_mail.func.php');

$action = param(1);

// hook my_start.php

$user = user_read($uid);

// HTMX 轮询请求在 session 过期时不应触发重定向
$is_htmx = is_htmx_request();
if($is_htmx && empty($user) && in_array($action, array('notify_unread', 'notify_dropdown'))) {
	exit('');
}

user_login_check();

$header['mobile_title'] = $user['username'];
$header['mobile_linke'] = url("my");

is_numeric($action) AND $action = '';

$active = $action;

// DDL 检查：仅首次执行，成功后写入标记文件，后续跳过
$_schema_marker = APP_PATH . 'tmp/my_schema_initialized.php';
if(!is_file($_schema_marker)) {

	if(db_check_column_exists('user', 'signature') === FALSE) {
		db_exec("ALTER TABLE `bbs_user` ADD COLUMN `signature` varchar(255) NOT NULL DEFAULT '' COMMENT '个性签名' AFTER `username`");
	}

	// 修复 avatar 字段：原 unsigned 无法存储预设头像的负数标识
	$db = $_SERVER['db'];
	if($db) {
		$_col_info = @db_sql_find_one("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$db->tablepre}user' AND COLUMN_NAME = 'avatar'");
		if(!empty($_col_info) && strpos($_col_info['COLUMN_TYPE'], 'unsigned') !== FALSE) {
			db_exec("ALTER TABLE `{$db->tablepre}user` MODIFY COLUMN `avatar` int(11) NOT NULL DEFAULT '0' COMMENT '头像: 0=默认, >0=上传时间戳, <0=预设头像索引'");
		}
		unset($_col_info);
	}

	// 确保通知系统表和字段存在（不依赖插件安装）
	if(!db_check_table_exists('notice')) {
		db_exec("CREATE TABLE IF NOT EXISTS `{$db->tablepre}notice` (
			`nid` int(11) unsigned NOT NULL auto_increment,
			`fromuid` int(11) unsigned NOT NULL default '0',
			`recvuid` int(11) unsigned NOT NULL default '0',
			`create_date` int(11) unsigned NOT NULL default '0',
			`isread` tinyint(3) unsigned NOT NULL default '0',
			`is_read` tinyint(1) unsigned NOT NULL default '0',
			`type` tinyint(3) unsigned NOT NULL default '0',
			`message` longtext NOT NULL,
			`icon` varchar(64) NOT NULL DEFAULT '' COMMENT '公告图标类名',
			`url` varchar(255) NOT NULL DEFAULT '' COMMENT '公告跳转链接',
			PRIMARY KEY (`nid`),
			KEY (`fromuid`, `type`),
			KEY (`recvuid`, `type`),
			KEY (`recvuid`, `is_read`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
	}
	if(db_check_column_exists('user', 'notices') === FALSE) {
		db_exec("ALTER TABLE `{$db->tablepre}user` ADD COLUMN `notices` mediumint(8) unsigned NOT NULL DEFAULT '0'");
	}
	if(db_check_column_exists('user', 'unread_notices') === FALSE) {
		db_exec("ALTER TABLE `{$db->tablepre}user` ADD COLUMN `unread_notices` mediumint(8) unsigned NOT NULL DEFAULT '0'");
	}

	// DDL 执行完成，写入标记文件
	file_put_contents($_schema_marker, '<?php // schema initialized at ' . date('Y-m-d H:i:s'));

}

// hook my_action_before.php

if(empty($action)) {

	$header['title'] = lang('my_home');

	$active_tab = param('tab', 'profile');

	$ai_config = [];
	if(!empty($user['ai_config'])) {
		$ai_config = json_decode($user['ai_config'], true);
		if(!is_array($ai_config)) $ai_config = [];
	}

	// 积分记录数据
	include_once APP_PATH . 'lib/CreditsService.php';
	include_once APP_PATH . 'service/CreditsRuleService.php';
	$creditsService = new CreditsService($db, $conf);
	$credits_rules_global = CreditsRuleService::getAllGlobalRules();
	$creditsLogResult = $creditsService->log($uid, 1, 20);
	$credits_loglist = !empty($creditsLogResult['ok']) ? $creditsLogResult['logs'] : array();

	// 版块列表（积分规则版块选择器）
	$forumlist = forum_list_cache();

	include _include(APP_PATH.'view/htm/my.htm');

} elseif($action == 'profile') {

	if($method == 'POST') {

		CsrfService::check();

		// hook my_profile_post_start.php

		$username = param('username');
		$signature = param('signature');

		!empty($username) AND mb_strlen($username) > 32 AND message('username', lang('username_length_too_long'));

		// 检查个人资料审核权限
		$need_profile_audit = !PermissionService::check('allow_direct_profile');

		$update = array();
		if(!empty($username) && $username != $user['username']) {
			$exists = user_read_by_username($username);
			if(!empty($exists) && $exists['uid'] != $uid) {
				message('username', lang('username_is_in_use'));
			}
			$update['username'] = $username;
		}
		if(db_check_column_exists('user', 'signature')) {
			$update['signature'] = $signature;
		}

		if(!empty($update)) {
			if($need_profile_audit) {
				// 需要审核：将变更存入审核表
				foreach($update as $field_name => $new_value) {
					$old_value = isset($user[$field_name]) ? $user[$field_name] : '';
					user_profile_audit_create(array(
						'uid' => $uid,
						'field_name' => $field_name,
						'old_value' => $old_value,
						'new_value' => $new_value,
						'audit_status' => 0,
						'create_date' => $time,
					));
				}
				message(0, '资料已提交，等待审核');
			} else {
				$r = user_update($uid, $update);
				$r === FALSE AND message(-1, lang('update_error'));
				message(0, lang('update_successfully'));
			}
		} else {
			message(0, lang('update_successfully'));
		}

		// hook my_profile_post_end.php
	}

} elseif($action == 'password') {

	if($method == 'GET') {

		$active_tab = 'security';

		$ai_config = [];
		if(!empty($user['ai_config'])) {
			$ai_config = json_decode($user['ai_config'], true);
			if(!is_array($ai_config)) $ai_config = [];
		}

		// 积分记录数据
		include_once APP_PATH . 'lib/CreditsService.php';
		include_once APP_PATH . 'service/CreditsRuleService.php';
		$creditsService = new CreditsService($db, $conf);
		$credits_rules_global = CreditsRuleService::getAllGlobalRules();
		$creditsLogResult = $creditsService->log($uid, 1, 20);
		$credits_loglist = !empty($creditsLogResult['ok']) ? $creditsLogResult['logs'] : array();
		$forumlist = forum_list_cache();

		include _include(APP_PATH.'view/htm/my.htm');

	} elseif($method == 'POST') {

		CsrfService::check();

		// hook my_password_post_start.php

		$password_old = param('password_old');
		$password_new = param('password_new');
		$password_new_repeat = param('password_new_repeat');
		password_md5($password_old);
		password_md5($password_new);
		password_md5($password_new_repeat);
		$password_new_repeat != $password_new AND message(-1, lang('repeat_password_incorrect'));
		
		$r = user_change_password($uid, $password_new, $password_old);
		$r === FALSE AND message('password_old', lang('old_password_incorrect'));

		// hook my_password_post_end.php
		message(0, lang('password_modify_successfully'));

	}

} elseif($action == 'email') {

	if($method == 'POST') {

		CsrfService::check();

		// hook my_email_post_start.php

		$email_new = param('email_new');
		$email_code = param('email_code');

		empty($email_new) AND message('email_new', lang('please_input_email'));
		!filter_var($email_new, FILTER_VALIDATE_EMAIL) AND message('email_new', lang('email_format_mismatch'));

		$session_code = isset($_SESSION['email_change_code']) ? $_SESSION['email_change_code'] : '';
		$session_email = isset($_SESSION['email_change_target']) ? $_SESSION['email_change_target'] : '';

		empty($session_code) AND message('email_code', '请先发送验证码');
		$session_email != $email_new AND message('email_new', '邮箱与验证码不匹配');
		$session_code != $email_code AND message('email_code', '验证码不正确');

		$exists = user_read_by_email($email_new);
		if(!empty($exists) && $exists['uid'] != $uid) {
			message('email_new', lang('email_is_in_use'));
		}

		$r = user_update($uid, array('email' => $email_new));
		$r === FALSE AND message(-1, lang('modify_failed'));

		unset($_SESSION['email_change_code']);
		unset($_SESSION['email_change_target']);

		// hook my_email_post_end.php

		message(0, lang('modify_successfully'));
	}

} elseif($action == 'send_email_code') {

	if($method == 'POST') {

		CsrfService::check();

		// hook my_send_email_code_start.php

		$email = param('email');

		empty($email) AND message(-1, lang('please_input_email'));
		!filter_var($email, FILTER_VALIDATE_EMAIL) AND message(-1, lang('email_format_mismatch'));

		$exists = user_read_by_email($email);
		if(!empty($exists) && $exists['uid'] != $uid) {
			message(-1, lang('email_is_in_use'));
		}

		$code = rand(100000, 999999);
		$_SESSION['email_change_code'] = $code;
		$_SESSION['email_change_target'] = $email;

		// 使用邮件模板
		$template = xn_email_template('email_change_code', array('code'=>$code, 'sitename'=>$conf['sitename']));
		$subject = $template['subject'];
		$message = $template['body'];

		$smtp = xn_smtp_get();
		if(empty($smtp)) {
			message(-1, '邮件发送未配置，请联系管理员');
		}

		// 频率限制检查
		$rate_check = xn_email_rate_check($email, $longip);
		if($rate_check !== TRUE) {
			message(-1, $rate_check);
		}

		$r = xn_send_mail($smtp, $conf['sitename'], $email, $subject, $message, array('is_html'=>TRUE));

		if($r === FALSE) {
			message(-1, '邮件发送失败，请检查邮箱配置');
		}

		xn_email_rate_record($email, $longip);

		// hook my_send_email_code_end.php

		if($is_htmx) {
			htmx_trigger('codeSent', array('message' => lang('send_code_successfully')));
		}
		message(0, lang('send_code_successfully'));
	}

} elseif($action == 'avatar') {

	if($method == 'GET') {

		$active_tab = 'avatar';

		$ai_config = [];
		if(!empty($user['ai_config'])) {
			$ai_config = json_decode($user['ai_config'], true);
			if(!is_array($ai_config)) $ai_config = [];
		}

		// 积分记录数据
		include_once APP_PATH . 'lib/CreditsService.php';
		include_once APP_PATH . 'service/CreditsRuleService.php';
		$creditsService = new CreditsService($db, $conf);
		$credits_rules_global = CreditsRuleService::getAllGlobalRules();
		$creditsLogResult = $creditsService->log($uid, 1, 20);
		$credits_loglist = !empty($creditsLogResult['ok']) ? $creditsLogResult['logs'] : array();
		$forumlist = forum_list_cache();

		include _include(APP_PATH.'view/htm/my.htm');

	} else {

		CsrfService::check();

		// hook my_avatar_post_start.php

		// 头像上传限制检查
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		if ($uid > 0) {
		    // 上传次数限制
		    $upload_limit = SecurityConfigService::get('security_avatar_upload_limit', 3);
		    if ($upload_limit > 0) {
		        $upload_count_key = 'security_avatar_upload_count_' . $uid;
		        $upload_count = kv_get($upload_count_key);
		        if ($upload_count === null || $upload_count === false) {
		            $upload_count = 0;
		        }
		        if (intval($upload_count) >= $upload_limit) {
		            message(-1, '头像上传次数已达上限（' . $upload_limit . '次）');
		        }
		    }

		    // 文件大小限制
		    $max_size = SecurityConfigService::get('security_avatar_max_size', 512);
		    if ($max_size > 0 && !empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
		        $file_size_kb = intval($_FILES['file']['size'] / 1024);
		        if ($file_size_kb > $max_size) {
		            message(-1, '头像文件大小超过限制（最大' . $max_size . 'KB）');
		        }
		    }
		}

		// 检查个人资料审核权限
		$need_profile_audit = !PermissionService::check('allow_direct_profile');

		$filename = "$uid.png";
		$dir = substr(sprintf("%09d", $uid), 0, 3).'/';
		$path = $conf['upload_path'].'avatar/'.$dir;
		$url = $conf['upload_url'].'avatar/'.$dir.$filename;
		!is_dir($path) AND (mkdir($path, 0777, TRUE) OR message(-2, lang('directory_create_failed')));
		$destfile = $path.$filename;

		// 优先处理 FormData 文件上传（UploadService 方式）
		if(!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
			$file = $_FILES['file'];
			// 校验文件类型
			$allowed = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp');
			$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
			!in_array($ext, $allowed) AND message(-1, lang('file_type_not_allowed'));
			// 校验文件大小（最大 5MB）
			$file['size'] > 5242880 AND message(-1, lang('filesize_too_large', array('maxsize'=>'5MB', 'size'=>$file['size'])));
			$tmpfile = $file['tmp_name'];
			// hook my_avatar_post_save_before.php
			$n = image_clip_thumb($tmpfile, $destfile, 256, 256);
			@unlink($tmpfile);
			$n <= 0 AND message(-1, lang('image_process_failed'));
			if($need_profile_audit) {
				// 需要审核：记录到审核表，暂不更新头像
				user_profile_audit_create(array(
					'uid' => $uid,
					'field_name' => 'avatar',
					'old_value' => strval($user['avatar']),
					'new_value' => strval($time),
					'audit_status' => 0,
					'create_date' => $time,
				));
				header('Location: '.url('my-avatar'));
				exit;
			}
			user_update($uid, array('avatar'=>$time));
			// hook my_avatar_post_end.php
			// 头像上传成功后累加上传计数
			include_once APP_PATH . 'lib/security/SecurityConfigService.php';
			$upload_limit = SecurityConfigService::get('security_avatar_upload_limit', 3);
			if ($upload_limit > 0 && $uid > 0) {
			    $upload_count_key = 'security_avatar_upload_count_' . $uid;
			    $upload_count = kv_get($upload_count_key);
			    if ($upload_count === null || $upload_count === false) {
			        $upload_count = 0;
			    }
			    kv_set($upload_count_key, intval($upload_count) + 1);
			}
			header('Location: '.url('my-avatar'));
			exit;
		}

		// 兼容旧版 base64 上传方式
		$data = param('data', '', FALSE);
		empty($data) AND message(-1, lang('data_is_empty'));
		$data = base64_decode_file_data($data);
		$size = strlen($data);
		$size > 40000 AND message(-1, lang('filesize_too_large', array('maxsize'=>'40K', 'size'=>$size)));

		// hook my_avatar_post_save_before.php
		file_put_contents($destfile, $data) OR message(-1, lang('write_to_file_failed'));

		if($need_profile_audit) {
			// 需要审核：记录到审核表，暂不更新头像
			user_profile_audit_create(array(
				'uid' => $uid,
				'field_name' => 'avatar',
				'old_value' => strval($user['avatar']),
				'new_value' => strval($time),
				'audit_status' => 0,
				'create_date' => $time,
			));
			header('Location: '.url('my-avatar'));
			exit;
		}

		user_update($uid, array('avatar'=>$time));

		// hook my_avatar_post_end.php

		header('Location: '.url('my-avatar'));
		exit;

	}

} elseif($action == 'avatar_preset') {

	if($method == 'POST') {

		CsrfService::check();

		$avatar_index = param('avatar_index');
		$avatar_index = intval($avatar_index);

		// avatar_index = 0 表示恢复默认头像
		if($avatar_index === 0) {
			user_update($uid, array('avatar'=>0));
			if($is_htmx) {
				$user = user_read($uid);
				$preset_list = avatar_preset_files();
				include _include(APP_PATH.'view/htm/my_avatar.htm');
				exit;
			}
			header('Location: '.url('my-avatar'));
			exit;
		}

		$preset_list = avatar_preset_files();
		empty($preset_list) AND message(-1, lang('no_preset_avatar'));
		!isset($preset_list[$avatar_index]) AND message(-1, lang('invalid_avatar_index'));

		user_update($uid, array('avatar'=>-$avatar_index));

		// hook my_avatar_preset_end.php

		if($is_htmx) {
			// HTMX 请求：重新读取用户数据，直接输出 avatar section fragment
			$user = user_read($uid);
			$preset_list = avatar_preset_files();
			include _include(APP_PATH.'view/htm/my_avatar.htm');
			// 只输出 avatar-section 的内容，不是整页
			exit;
		}

		header('Location: '.url('my-avatar'));
		exit;

	} elseif($method == 'GET') {

		// 返回预设头像列表 JSON
		$preset_list = avatar_preset_files();
		message(0, '', array('list'=>array_values($preset_list)));

	}

} elseif($action == 'ai_setting') {

	if($method == 'POST') {

		CsrfService::check();

		// hook my_ai_setting_post_start.php

		$ai_provider = param('ai_provider');
		$ai_apikey = param('ai_apikey');
		$ai_endpoint = param('ai_endpoint');
		$ai_model = param('ai_model');

		$ai_config = [];
		if(!empty($ai_provider) && !empty($ai_apikey)) {
			$model_config = [
				'apiKey' => $ai_apikey,
			];
			if(!empty($ai_endpoint)) {
				if ($ai_provider === 'custom') {
					$model_config['url'] = $ai_endpoint;
				} else {
					$model_config['endpoint'] = $ai_endpoint;
				}
			}
			if(!empty($ai_model)) {
				$model_config['model'] = $ai_model;
			}
			$ai_config['models'] = [
				$ai_provider => $model_config,
			];
			$ai_config['bubblePanelEnable'] = true;
			$ai_config['bubblePanelModel'] = $ai_provider;
		}

		user_update($uid, ['ai_config' => json_encode($ai_config)]);

		// hook my_ai_setting_post_end.php

		message(0, '保存成功');
	}

} elseif($action == 'credits') {

	$active_tab = 'credits';

	$ai_config = [];
	if(!empty($user['ai_config'])) {
		$ai_config = json_decode($user['ai_config'], true);
		if(!is_array($ai_config)) $ai_config = [];
	}

	// 获取积分规则
	include_once APP_PATH . 'lib/CreditsService.php';
	include_once APP_PATH . 'service/CreditsRuleService.php';
	$creditsService = new CreditsService($db, $conf);
	$credits_rules_global = CreditsRuleService::getAllGlobalRules();

	// 获取积分记录
	$page = param(2, 1);
	$pagesize = 20;
	$creditsLogResult = $creditsService->log($uid, $page, $pagesize);
	$credits_loglist = !empty($creditsLogResult['ok']) ? $creditsLogResult['logs'] : array();

	// 版块列表
	$forumlist = forum_list_cache();

	include _include(APP_PATH.'view/htm/my.htm');

} elseif($action == 'credits_rules') {

	// 积分规则按版块加载 API
	$fid = param('fid', 0);
	include_once APP_PATH . 'service/CreditsRuleService.php';

	if($fid > 0) {
		$rules = CreditsRuleService::getForumRules($fid);
	} else {
		$rules = CreditsRuleService::getAllGlobalRules();
	}

	if(is_htmx_request()) {
		header('Content-Type: text/html; charset=utf-8');
		if(!empty($rules)) {
			echo '<table class="table table-sm table-hover"><thead><tr><th>事项名称</th><th>积分</th><th>金币</th><th>RMB</th></tr></thead><tbody>';
			foreach($rules as $rule) {
				$event_name = credits_event_name($rule['event']);
				$credits_change = intval($rule['credits_change'] ?? 0);
				$golds_change = intval($rule['golds_change'] ?? 0);
				$rmbs_change = intval($rule['rmbs_change'] ?? 0);
				echo '<tr><td>' . esc_html($event_name) . '</td>';
				echo '<td class="' . ($credits_change > 0 ? 'text-success' : ($credits_change < 0 ? 'text-danger' : '')) . '">';
				echo ($credits_change > 0 ? '+' : '') . $credits_change . '</td>';
				echo '<td class="' . ($golds_change > 0 ? 'text-success' : ($golds_change < 0 ? 'text-danger' : '')) . '">';
				echo ($golds_change > 0 ? '+' : '') . $golds_change . '</td>';
				echo '<td class="' . ($rmbs_change > 0 ? 'text-success' : ($rmbs_change < 0 ? 'text-danger' : '')) . '">';
				echo ($rmbs_change > 0 ? '+' : '') . $rmbs_change . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<div class="text-center text-body-secondary py-4">暂无积分规则</div>';
		}
		exit;
	}
	message(0, $rules);

} elseif($action == 'follow_users') {

    // 返回关注用户列表（用于@提及）
    !$uid AND message(-1, lang('please_login'));
    $followlist = user_follow_find_following($uid, 1, 50);
    $users = array();
    if($followlist) {
        foreach($followlist as $f) {
            $u = user_read_cache($f['follow_uid']);
            if(!empty($u)) {
                $users[] = array(
                    'uid' => $u['uid'],
                    'username' => $u['username'],
                    'avatar_url' => !empty($u['avatar_url']) ? $u['avatar_url'] : '/view/img/avatar.png',
                );
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo xn_json_encode(array('code' => 0, 'data' => $users));
    exit;

} elseif($action == 'thread') {

	// hook my_thread_start.php

	$page = param(2, 1);
	$pagesize = 20;
	$totalnum = $user['threads'];

	// hook my_profile_thread_list_before.php

	$pagination = pagination(url('my-thread-{page}'), $totalnum, $page, $pagesize);
	$threadlist = mythread_find_by_uid($uid, $page, $pagesize);

	// hook my_thread_end.php

	include _include(APP_PATH.'view/htm/my_thread.htm');


} elseif($action == 'favorite') {

	$page = param(2, 1);
	$pagesize = 20;
	$favlist = thread_favorite_find_by_uid($uid, $page, $pagesize);
	$totalnum = $user['favorites'];
	$pagination = pagination(url('my-favorite-{page}'), $totalnum, $page, $pagesize);

	$threadlist = array();
	if($favlist) {
		foreach($favlist as $fav) {
			$t = thread_read($fav['tid']);
			if(!empty($t)) $threadlist[$fav['tid']] = $t;
		}
	}
	// 过滤待审帖子（收藏的他人待审帖子不可见）
	thread_list_access_filter($threadlist, $gid);

	$header['title'] = lang('my_favorite');
	include _include(APP_PATH.'view/htm/my_favorite.htm');

} elseif($action == 'feed') {

	http_location(url('index', array('order' => 'follow')));

} elseif($action == 'notify') {

	$page = param(2, 1);
	$pagesize = 50;
	$notifylist = notify_find_by_uid($uid, $page, $pagesize);
	$totalnum = db_count('notify', array('uid'=>$uid));
	$pagination = pagination(url('my-notify-{page}'), $totalnum, $page, $pagesize);

	// 不再自动标记已读，用户需要手动点击"全部标记已读"
	// 通知列表渲染后再标记，保证页面能正确显示已读/未读状态

	$header['title'] = lang('my_notify');
	include _include(APP_PATH.'view/htm/my_notify.htm');

} elseif($action == 'notify_unread') {

	!$uid AND exit('');
	$notify_count = notify_count_unread($uid);
	// 合并 notice 系统未读数
	$notice_count = notice_count_unread($uid);
	$total = $notify_count + $notice_count;
	if($total > 0) {
		echo '<span class="position-absolute top-0 start-100 translate-middle badge rounded-circle bg-danger p-1" style="width:0.5rem;height:0.5rem"></span>';
	}
	exit;

} elseif($action == 'notify_unread_count') {

	// 通知未读数 API（AJAX 调用）
	!$uid AND exit(json_encode(array('code' => -1, 'message' => '未登录')));
	$notify_unread = function_exists('notify_count_unread') ? notify_count_unread($uid) : 0;
	$notice_unread = function_exists('notice_count_unread') ? notice_count_unread($uid) : 0;
	$total = intval($notify_unread) + intval($notice_unread);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'data' => array('total' => $total, 'notify' => $notify_unread, 'notice' => $notice_unread)));
	exit;

} elseif($action == 'notify_dropdown') {

	!$uid AND exit('');

	// notice_format() 依赖 global $notice_menu，此处必须定义
	global $notice_menu;
	$notice_menu = array(
		0 => array('name'=>'其他', 'class'=>'info', 'icon'=>''),
		1 => array('name'=>'公告', 'class'=>'info', 'icon'=>''),
		3 => array('name'=>'系统', 'class'=>'danger', 'icon'=>''),
		99 => array('name'=>'其他', 'class'=>'success', 'icon'=>'bell'),
	);

	// 合并 notify 和 notice 两个系统的通知
	$all_items = array();

	// 互动通知 (notify)
	$notifylist = db_find('notify', array('uid'=>$uid), array('nid'=>-1), 1, 8, 'nid');
	if($notifylist) {
		foreach($notifylist as &$n) {
			notify_format($n);
			$all_items[] = array(
				'source' => 'notify',
				'nid' => $n['nid'],
				'message' => $n['message'],
				'is_read' => empty($n['is_read']) ? 0 : 1,
				'type' => $n['type'],
				'url' => isset($n['url']) ? $n['url'] : '',
				'avatar_url' => isset($n['from_avatar_url']) ? $n['from_avatar_url'] : '/view/img/avatar.png',
				'username' => isset($n['from_username']) ? $n['from_username'] : '',
				'create_date' => $n['create_date'],
				'create_date_fmt' => isset($n['create_date_fmt']) ? $n['create_date_fmt'] : '',
				'fid' => isset($n['fid']) ? $n['fid'] : 0,
			);
		}
	}

	// 消息通知 (notice)
	$noticelist = notice_find_latest_by_recvuid($uid, 8);
	if($noticelist) {
		foreach($noticelist as $n) {
			$all_items[] = array(
				'source' => 'notice',
				'nid' => $n['nid'],
				'message' => strip_tags($n['message']),
				'is_read' => empty($n['is_read']) ? 0 : 1,
				'type' => $n['type'],
				'url' => url('my-notice'),
				'avatar_url' => isset($n['from_user_avatar_url']) ? $n['from_user_avatar_url'] : '/view/img/avatar.png',
				'username' => isset($n['from_username']) ? $n['from_username'] : '',
				'create_date' => $n['create_date'],
				'create_date_fmt' => $n['create_date_fmt'],
				'fid' => 0,
			);
		}
	}

	// 按时间排序，取最新5条
	usort($all_items, function($a, $b) { return $b['create_date'] - $a['create_date']; });
	$all_items = array_slice($all_items, 0, 5);

	if(empty($all_items)) {
		echo '<div class="text-center text-body-secondary small py-4"><i class="ti ti-bell-off fs-4 d-block mb-1 opacity-50"></i>暂无通知</div>';
		exit;
	}

	$html = '';
	foreach($all_items as $item) {
		$typeLabel = '';
		if($item['source'] == 'notify') {
			if($item['type'] == 'like') { $typeLabel = '赞了你的帖子'; }
			elseif($item['type'] == 'favorite') { $typeLabel = '收藏了你的帖子'; }
			elseif($item['type'] == 'follow') { $typeLabel = '关注了你'; }
			elseif($item['type'] == 'reply') { $typeLabel = '回复了你的评论'; }
			elseif($item['type'] == 'thread') { $typeLabel = '发布了新帖'; }
			elseif($item['type'] == 'forum_post') { $typeLabel = '发布了新帖'; }
			elseif($item['type'] == 'mention') { $typeLabel = '提及了你'; }
			else { $typeLabel = '通知了你'; }
		} else {
			if($item['type'] == 1) { $typeLabel = '发布了公告'; }
			elseif($item['type'] == 2) { $typeLabel = '评论了'; }
			elseif($item['type'] == 3) { $typeLabel = '系统通知'; }
			else { $typeLabel = '通知'; }
		}

		$unreadClass = empty($item['is_read']) ? ' notice-unread' : '';
		$unreadDot = empty($item['is_read']) ? '<span class="badge bg-primary rounded-pill flex-shrink-0" style="font-size:0.5rem;padding:2px 5px;">新</span>' : '';
		$href = $item['url'] ?: url('my-notice');

		// 单行紧凑格式：头像 用户名 时间 操作
		$html .= '<a href="' . htmlspecialchars($href) . '" class="dropdown-item d-flex align-items-center gap-2 px-3 py-2 notice-dropdown-item' . $unreadClass . '" data-nid="' . $item['nid'] . '" data-source="' . $item['source'] . '" hx-boost="false">';
		$html .= '<img class="rounded-circle flex-shrink-0" src="' . htmlspecialchars($item['avatar_url']) . '" alt="" style="width:24px;height:24px;object-fit:cover;" onerror="this.src=\'/view/img/avatar.png\'">';
		$html .= '<span class="fw-semibold" style="font-size:0.8rem;">' . htmlspecialchars($item['username']) . '</span>';
		$html .= '<span class="text-body-secondary flex-shrink-0" style="font-size:0.75rem;">' . $item['create_date_fmt'] . '</span>';
		$html .= '<span class="text-truncate" style="font-size:0.8rem;min-width:0;">' . htmlspecialchars($typeLabel) . '</span>';
		$html .= $unreadDot;
		$html .= '</a>';
	}
	echo $html;
	exit;

} elseif($action == 'notify_list') {

	// 按类型返回通知列表
	!$uid AND message(-1, lang('please_login'));
	$type = param('type', '');
	$page = param('page', 1);
	$pagesize = 20;
	
	$condition = array('uid'=>$uid);
	if(!empty($type) && $type != 'all') {
		$condition['type'] = $type;
	}
	
	$notifylist = db_find('notify', $condition, array('nid'=>-1), $page, $pagesize, 'nid');
	if($notifylist) {
		foreach($notifylist as &$notify) {
			notify_format($notify);
		}
	}
	$totalnum = db_count('notify', $condition);
	$pagination = pagination(url('my-notify_list', array('type'=>$type)).'-{page}', $totalnum, $page, $pagesize);
	
	message(0, array('notifylist' => array_values($notifylist), 'total' => $totalnum));

} elseif($action == 'notify_mark_read') {

	if(!$uid) {
		if(is_htmx_request()) {
			header('HTTP/1.1 401 Unauthorized');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code' => '-1', 'message' => lang('please_login')));
		exit;
	}
	notify_mark_all_read($uid);
	// 同时标记 notice 全部已读
	notice_update_by_recvuid($uid);
	$notify_unread = notify_count_unread($uid);
	$notice_unread = notice_count_unread($uid);
	$total_unread = $notify_unread + $notice_unread;

	if(is_htmx_request()) {
		// htmx: 返回 HX-Trigger 事件，前端监听后刷新通知列表
		header('HX-Trigger: {"noticeMarkAllRead": {"unread_count": ' . intval($total_unread) . '}}');
		header('HTTP/1.1 204 No Content');
		exit;
	}

	header('Content-Type: application/json; charset=utf-8');
	echo xn_json_encode(array('code' => '0', 'message' => lang('operate_successfully'), 'unread_count' => $total_unread));
	exit;

} elseif($action == 'notify_read') {

	// 单条通知标记已读
	if(!$uid) {
		if(is_htmx_request()) {
			header('HTTP/1.1 401 Unauthorized');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code' => '-1', 'message' => lang('please_login')));
		exit;
	}
	$nid = param(2, 0);
	if(empty($nid)) {
		if(is_htmx_request()) {
			header('HTTP/1.1 400 Bad Request');
			exit;
		}
		header('Content-Type: application/json; charset=utf-8');
		echo xn_json_encode(array('code' => '-1', 'message' => lang('parameters_error')));
		exit;
	}
	$notify = notify__read($nid);
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
	notify_mark_read($nid);
	$notify_unread = notify_count_unread($uid);
	$notice_unread = notice_count_unread($uid);
	$total_unread = $notify_unread + $notice_unread;

	if(is_htmx_request()) {
		// htmx: 返回已读状态的 notify 卡片 HTML（OOB 替换）
		notify_format($notify);
		$notify['is_read'] = 1;
		$icon_map = array('like'=>'heart-filled', 'reply'=>'message', 'follow'=>'user-plus', 'favorite'=>'star-filled', 'thread'=>'file-text', 'forum_post'=>'news', 'mention'=>'at');
		$icon_name = isset($icon_map[$notify['type']]) ? $icon_map[$notify['type']] : 'bell';
		$type_label = isset($notify['type_label']) ? $notify['type_label'] : lang('notify_type_label_notice_other');
		$from_gid = isset($notify['from_gid']) ? $notify['from_gid'] : 0;

		header('Content-Type: text/html; charset=utf-8');
		echo '<div class="notice-card p-3" id="notice-nid-' . $nid . '" data-nid="' . $nid . '" data-source="notify" hx-swap-oob="true">';
		echo '  <div class="notice-row-top mb-1">';
		echo '    <span class="notice-type-badge">';
		echo '      <i class="ti ti-' . $icon_name . '"></i> ' . htmlspecialchars($type_label);
		echo '    </span>';
		echo '    <span class="notice-time">' . $notify['create_date_fmt'] . '</span>';
		echo '  </div>';
		echo '  <div class="d-flex justify-content-between align-items-start gap-3">';
		echo '    <div class="notice-detail flex-fill" style="min-width:0">' . htmlspecialchars($notify['message']) . '</div>';
		echo '    <div class="notice-actions">';
		if(!empty($notify['url'])) {
			echo '      <a href="' . htmlspecialchars($notify['url']) . '" class="btn btn-sm btn-primary rounded-pill notice-view-detail" data-nid="' . $nid . '" data-source="notify" data-url="' . htmlspecialchars($notify['url']) . '">';
			echo '        <i class="ti ti-external-link me-1"></i>' . lang('notify_btn_view_detail');
			echo '      </a>';
		}
		echo '    </div>';
		echo '  </div>';
		echo '</div>';
		exit;
	}

	header('Content-Type: application/json; charset=utf-8');
	echo xn_json_encode(array('code' => '0', 'message' => lang('operate_successfully'), 'unread_count' => $total_unread));
	exit;

}

// 通知系统路由（核心功能）
elseif($action == 'notice') {

	if($method == 'GET') {

		$page = param(3, 1);
		$pagesize = 20;
		$active = 'notice';
		$type = param(2, '');

		global $notice_menu;
		$notice_menu = array(
			0 => array('url'=>url('my-notice'), 'name'=>'全部', 'class'=>'info', 'icon'=>''),
			'like' => array('url'=>url('my-notice-like'), 'name'=>'点赞', 'class'=>'danger', 'icon'=>'heart'),
			'reply' => array('url'=>url('my-notice-reply'), 'name'=>'评论', 'class'=>'primary', 'icon'=>'message'),
			'favorite' => array('url'=>url('my-notice-favorite'), 'name'=>'收藏', 'class'=>'warning', 'icon'=>'star'),
			'mention' => array('url'=>url('my-notice-mention'), 'name'=>'@', 'class'=>'info', 'icon'=>'at'),
			'follow' => array('url'=>url('my-notice-follow'), 'name'=>'关注', 'class'=>'success', 'icon'=>'user-plus'),
			'thread' => array('url'=>url('my-notice-thread'), 'name'=>'帖子', 'class'=>'primary', 'icon'=>'file-text'),
			1 => array('url'=>url('my-notice-1'), 'name'=>'公告', 'class'=>'info', 'icon'=>'speakerphone'),
			3 => array('url'=>url('my-notice-3'), 'name'=>'系统', 'class'=>'danger', 'icon'=>'file-text'),
		);

		// 合并 notify（互动通知）和 notice（系统消息）两个来源
		$merged_items = array();

		// 支持的 notify 类型列表
		$notify_types = array('like', 'reply', 'favorite', 'mention', 'follow', 'thread', 'forum_post');

		// 判断是否为"全部"模式
		$is_all = ($type === '' || $type === '0');

		// Notify items: include when type is empty (全部) or type is a specific notify type
		if($is_all || in_array($type, $notify_types)) {
			$notify_condition = array('uid'=>$uid);
			if(in_array($type, $notify_types)) {
				$notify_condition['type'] = $type;
			}
			$notifylist = db_find('notify', $notify_condition, array('nid'=>-1), 1, 200, 'nid');
			if($notifylist) {
				foreach($notifylist as &$n) {
					notify_format($n);
					// 获取帖子标题
					$_thread_subject = '';
					if($n['tid'] > 0) {
						$_thread = thread_read_cache($n['tid']);
						if(!empty($_thread)) $_thread_subject = $_thread['subject'];
					}
					// 获取原评论内容（回复场景：pid 对应的 post）
					$_quote_content = '';
					if($n['type'] == 'reply' && $n['pid'] > 0) {
						$_post = post_read_cache($n['pid']);
						if(!empty($_post)) $_quote_content = strip_tags($_post['message']);
					}
					// 获取 from_user 的 gid
					$_from_user = user_read_cache($n['from_uid']);
					$_from_gid = $_from_user ? intval($_from_user['gid']) : 0;
					$merged_items[] = array(
						'source' => 'notify',
						'nid' => $n['nid'],
						'is_read' => empty($n['is_read']) ? 0 : 1,
						'from_uid' => $n['from_uid'],
						'from_gid' => $_from_gid,
						'from_user_avatar_url' => isset($n['from_avatar_url']) ? $n['from_avatar_url'] : '/view/img/avatar.png',
						'from_username' => isset($n['from_username']) ? $n['from_username'] : '',
						'type' => $n['type'],
						'type_label' => isset($n['type_label']) ? $n['type_label'] : lang('notify_type_label_notice_other'),
						'name' => isset($n['summary']) ? $n['summary'] : lang('notify_summary_notice'),
						'summary' => isset($n['summary']) ? $n['summary'] : lang('notify_summary_notice'),
						'message' => isset($n['message']) ? $n['message'] : '',
						'content' => isset($n['content']) ? $n['content'] : '',
						'quote_content' => $_quote_content,
						'tid' => $n['tid'],
						'pid' => $n['pid'],
						'thread_subject' => $_thread_subject,
						'url' => isset($n['url']) ? $n['url'] : '',
						'create_date_fmt' => isset($n['create_date_fmt']) ? $n['create_date_fmt'] : '',
						'create_date' => isset($n['create_date']) ? $n['create_date'] : 0,
					);
				}
			}
		}

		// Notice items: include when type is empty (全部) or type is numeric and > 0
		if($is_all || (is_numeric($type) && intval($type) > 0)) {
			$notice_type = (is_numeric($type) && intval($type) > 0) ? intval($type) : 0;
			$noticelist_raw = notice_find_by_recvuid($uid, 1, 200, $notice_type);
			if($noticelist_raw) {
				foreach($noticelist_raw as $n) {
					$type_name = isset($notice_menu[$n['type']]) ? $notice_menu[$n['type']]['name'] : lang('notify_summary_notice');
					// 通知类型标签
					$notice_label_map = array(1=>lang('notify_type_label_notice_announcement'), 3=>lang('notify_type_label_notice_system'));
					$type_label = isset($notice_label_map[$n['type']]) ? $notice_label_map[$n['type']] : lang('notify_type_label_notice_other');
					$merged_items[] = array(
						'source' => 'notice',
						'nid' => $n['nid'],
						'is_read' => empty($n['is_read']) ? 0 : 1,
						'from_user_avatar_url' => isset($n['from_user_avatar_url']) ? $n['from_user_avatar_url'] : '/view/img/avatar.png',
						'from_username' => isset($n['from_username']) ? $n['from_username'] : '',
						'type' => $n['type'],
						'type_label' => $type_label,
						'name' => $type_name,
						'summary' => lang('notify_summary_notice'),
						'message' => $n['message'],
						'url' => '',
						'create_date_fmt' => $n['create_date_fmt'],
						'create_date' => $n['create_date'],
					);
				}
			}
		}

		// 按时间倒序排列
		usort($merged_items, function($a, $b) {
			return $b['create_date'] - $a['create_date'];
		});

		// 总数和分页
		$totalnum = count($merged_items);
		$offset = ($page - 1) * $pagesize;
		$noticelist = array_slice($merged_items, $offset, $pagesize);

		$pagination_url = $is_all ? url("my-notice-{page}") : url("my-notice-$type-{page}");
		$pagination = pagination($pagination_url, $totalnum, $page, $pagesize);

		$header['title'] = lang('notice');
		$header['mobile_title'] = lang('notice');

		include _include(APP_PATH.'view/htm/my_notice.htm');

	} elseif($method == 'POST') {
		CsrfService::check();
		$act = param('act');
		if($act == 'readall') {
			$recvuid = param('uid');
			$recvuid != $uid AND message(-1, lang('notice_my_error'));

			// 同时标记两个系统已读
			notify_mark_all_read($uid);
			$r = notice_update_by_recvuid($recvuid);
			$r === FALSE AND message(-1, lang('notice_my_update_failed'));

			$unread_count = notify_count_unread($uid) + notice_count_unread($uid);

			if(is_htmx_request()) {
				// htmx: 返回 HX-Trigger 事件，前端监听后刷新通知列表
				header('HX-Trigger: {"noticeMarkAllRead": {"unread_count": ' . intval($unread_count) . '}}');
				header('HTTP/1.1 204 No Content');
				exit;
			}

			message(0, array('a' => lang('notice_my_update_readed'),'b' => lang('notice_my_update_allread'), 'unread_count' => $unread_count));

		} elseif($act == 'readone') {
			$nid = param('nid');
			$notice = notice__read($nid);
			empty($notice) AND message(-1, lang('not_exists'));
			$notice['recvuid'] != $uid AND message(-1, lang('notice_my_error'));

			$is_read = isset($notice['is_read']) ? $notice['is_read'] : $notice['isread'];
			$is_read == 1 AND message(-1, lang('notice_my_update_readed'));

			$r = notice_update($nid);
			$r === FALSE AND message(-1, lang('notice_my_update_failed'));

			$unread_count = notice_count_unread($uid);

			if(is_htmx_request()) {
				// htmx: 返回已读状态的 notice 卡片 HTML（OOB 替换）
				$icon_map = array(1=>'speakerphone', 2=>'message', 3=>'file-text');
				$icon_name = isset($icon_map[$notice['type']]) ? $icon_map[$notice['type']] : 'bell';
				$notice_label_map = array(1=>lang('notify_type_label_notice_announcement'), 3=>lang('notify_type_label_notice_system'));
				$type_label = isset($notice_label_map[$notice['type']]) ? $notice_label_map[$notice['type']] : lang('notify_type_label_notice_other');

				header('Content-Type: text/html; charset=utf-8');
				echo '<div class="notice-card p-3" id="notice-nid-' . $nid . '" data-nid="' . $nid . '" data-source="notice" hx-swap-oob="true">';
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

			message(0, lang('notice_my_update_readed'), array('unread_count' => $unread_count));

		} elseif($act == 'delete') {
			$nid = param('nid');
			$notice = notice__read($nid);
			$notice['recvuid'] != $uid AND message(-1, lang('notice_my_error'));

			$r = notice_delete($nid);
			$r === FALSE AND message(-1, lang('notice_my_update_failed'));

			if(is_htmx_request()) {
				// htmx: 返回 HX-Trigger 事件，前端移除已删除的卡片
				header('HX-Trigger: {"noticeDeleted": {"nid": ' . intval($nid) . '}}');
				header('HTTP/1.1 204 No Content');
				exit;
			}

			message(0, lang('notice_my_update_sucessfully'));

		} else {
			message(-1, lang('notice_my_error'));
		}
	}
}

// hook my_end.php

?>
