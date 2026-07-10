<?php

!defined('DEBUG') AND exit('Access Denied.');

include _include(XIUNOPHP_PATH.'xn_send_mail.func.php');

$action = param(1);

// 路径风格伪静态（url_rewrite_on=2/3/5）下，多段路由名（如 notify_read、notify_mark_read）
// 的连字符被 url() 替换为斜杠，导致 xn_url_parse 解析时 param(1) 只取到第一段（如 'notify'），
// param(2) 变成子路由名（如 'read'），nid 等参数偏移到 param(3)。
// 此处将 $action 修正为完整的路由名，使后续 elseif 分支正确匹配；
// 各分支内部再单独处理 nid 等参数的偏移取值。
if($action === 'notify') {
    $_sub = param(2, '');
    $_notify_sub_routes = array('read', 'mark_read', 'unread', 'dropdown', 'list', 'unread_count');
    if(in_array($_sub, $_notify_sub_routes)) {
        $action = 'notify_' . $_sub;
    }
}

// hook my_start.php

$user = user_read($uid);

// HTMX 轮询请求在 session 过期时不应触发重定向
$is_htmx = is_htmx_request();
if($is_htmx && empty($user) && in_array($action, array('notify_unread', 'notify_dropdown'))) {
	exit('');
}

user_login_check();

$header['mobile_title'] = $user['display_name'] ?? $user['username'];
$header['mobile_linke'] = my_url();

is_numeric($action) AND $action = '';

$active = $action;

// DDL 检查：仅首次执行，成功后写入标记文件，后续跳过
$_schema_marker = APP_PATH . 'tmp/my_schema_initialized.php';
if(!is_file($_schema_marker)) {

	if(db_check_column_exists('user', 'signature') === FALSE) {
		db_exec("ALTER TABLE `{$db->tablepre}user` ADD COLUMN `signature` varchar(255) NOT NULL DEFAULT '' COMMENT '个性签名' AFTER `username`");
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

	// 确保通知系统字段存在（notice 表已废弃合并到 notify，仅补齐 user 计数字段和 notify 扩展字段）
	if(db_check_column_exists('user', 'notices') === FALSE) {
		db_exec("ALTER TABLE `{$db->tablepre}user` ADD COLUMN `notices` mediumint(8) unsigned NOT NULL DEFAULT '0'");
	}
	if(db_check_column_exists('user', 'unread_notices') === FALSE) {
		db_exec("ALTER TABLE `{$db->tablepre}user` ADD COLUMN `unread_notices` mediumint(8) unsigned NOT NULL DEFAULT '0'");
	}

	// 确保 notify 表有扩展字段（message/icon/url/reply_to_uid/parent_pid）
	// notify_merge 升级步骤会做同样的事，这里作为安全保障
	if(db_check_table_exists('notify')) {
		if(db_check_column_exists('notify', 'message') === FALSE) {
			db_exec("ALTER TABLE `{$db->tablepre}notify` ADD COLUMN `message` LONGTEXT AFTER `content`");
		}
		if(db_check_column_exists('notify', 'icon') === FALSE) {
			db_exec("ALTER TABLE `{$db->tablepre}notify` ADD COLUMN `icon` VARCHAR(64) DEFAULT '' AFTER `message`");
		}
		if(db_check_column_exists('notify', 'url') === FALSE) {
			db_exec("ALTER TABLE `{$db->tablepre}notify` ADD COLUMN `url` VARCHAR(255) DEFAULT '' AFTER `icon`");
		}
		if(db_check_column_exists('notify', 'reply_to_uid') === FALSE) {
			db_exec("ALTER TABLE `{$db->tablepre}notify` ADD COLUMN `reply_to_uid` INT(11) UNSIGNED DEFAULT 0 AFTER `pid`");
		}
		if(db_check_column_exists('notify', 'parent_pid') === FALSE) {
			db_exec("ALTER TABLE `{$db->tablepre}notify` ADD COLUMN `parent_pid` INT(11) UNSIGNED DEFAULT 0 AFTER `reply_to_uid`");
		}
	}

	// DDL 执行完成，写入标记文件
	file_put_contents($_schema_marker, '<?php // schema initialized at ' . date('Y-m-d H:i:s'));

}

// hook my_action_before.php

if(empty($action)) {

	// 默认跳转到基本资料页
	http_location(my_profile_url());

} elseif($action == 'profile') {

	$active_tab = 'profile';

	if($method == 'POST') {

		CsrfService::check();

		// hook my_profile_post_start.php

		$nickname = param('nickname');
		// 签名支持HTML：第三参数FALSE取消基础htmlspecialchars转义，由xn_signature_purify统一净化
		$signature = param('signature', '', FALSE);

		// 签名HTML净化：仅允许基础排版标签，过滤危险HTML
		if ($signature !== '') {
			$signature = xn_signature_purify($signature);
			// 净化后长度检查（HTML标签占用字符，允许稍长）
			if (mb_strlen(strip_tags($signature)) > 255) {
				message('signature', lang('signature_length_too_long'));
			}
		}

		!empty($nickname) AND mb_strlen($nickname) > 32 AND message('nickname', lang('nickname_length_too_long'));

		// 检查个人资料审核权限
		$need_profile_audit = !PermissionService::check('allow_direct_profile');

		$update = array();
		if(!empty($nickname) && $nickname != $user['nickname']) {
			// 昵称保留词检查（使用 reserved 词库，防止冒充管理员等）
		include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
		$nickname_check = SensitiveWordFilter::content_check($nickname, SensitiveWordFilter::TYPE_RESERVED);
		if (!$nickname_check['pass']) {
			$hit_words = implode('、', $nickname_check['matched_keywords']);
			message('nickname', lang('nickname_contains_reserved_word', array('words'=>$hit_words)));
		}
			// 昵称全局唯一性检查
			$exists = db_find_one('user', array('nickname'=>$nickname));
			if(!empty($exists) && $exists['uid'] != $uid) {
				message('nickname', lang('nickname_is_in_use'));
			}
			// 昵称修改频率限制：读取后台配置，30天内最多修改N次
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$nickname_change_limit = intval(SecurityConfigService::get('security_nickname_change_limit', 1));
		if($nickname_change_limit > 0 && db_check_column_exists('user', 'nickname') && db_check_table_exists('nickname_change_log')) {
			// 用 db_count + 时间条件替代 db_find 100 条记录
			$thirty_days_ago = $time - 30 * 86400;
			$recent_changes = db_count('nickname_change_log', array('uid'=>$uid, 'change_time'=>array('>'=>$thirty_days_ago)));
			if($recent_changes >= $nickname_change_limit) {
				// 仅在被限制时查询最近一次修改时间，用于计算剩余天数
				$last_log = db_find_one('nickname_change_log', array('uid'=>$uid, 'change_time'=>array('>'=>$thirty_days_ago)), array('change_time'=>-1));
				$last_change_time = $last_log ? $last_log['change_time'] : $time;
				$remain_days = 30 - intval((time() - $last_change_time) / 86400);
				$remain_days = $remain_days > 0 ? $remain_days : 1;
				message('nickname', lang('nickname_change_too_frequent', array('days'=>$remain_days)));
			}
		}
			$update['nickname'] = $nickname;
		}
		if(db_check_column_exists('user', 'signature') && $signature != $user['signature']) {
			// 签名内容敏感词检查（拦截并提示具体违规词）
		include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
		$sig_check = SensitiveWordFilter::content_check($signature, SensitiveWordFilter::TYPE_SENSITIVE);
		if (!$sig_check['pass']) {
			$hit_words = implode('、', $sig_check['matched_keywords']);
			message('signature', lang('signature_contains_sensitive_word_with_words', array('words'=>$hit_words)));
		}
			// 签名修改频率限制：读取后台配置，30天内最多修改N次
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$signature_change_limit = intval(SecurityConfigService::get('security_signature_change_limit', 3));
		if($signature_change_limit > 0 && db_check_table_exists('signature_change_log')) {
			// 用 db_count + 时间条件替代 db_find 100 条记录
			$thirty_days_ago = $time - 30 * 86400;
			$recent_changes = db_count('signature_change_log', array('uid'=>$uid, 'change_time'=>array('>'=>$thirty_days_ago)));
			if($recent_changes >= $signature_change_limit) {
				// 仅在被限制时查询最近一次修改时间，用于计算剩余天数
				$last_log = db_find_one('signature_change_log', array('uid'=>$uid, 'change_time'=>array('>'=>$thirty_days_ago)), array('change_time'=>-1));
				$last_change_time = $last_log ? $last_log['change_time'] : $time;
				$remain_days = 30 - intval((time() - $last_change_time) / 86400);
				$remain_days = $remain_days > 0 ? $remain_days : 1;
				message('signature', lang('signature_change_too_frequent', array('days'=>$remain_days)));
			}
		}
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
				// 记录昵称修改日志
			if(isset($update['nickname']) && db_check_table_exists('nickname_change_log')) {
				db_insert('nickname_change_log', array(
					'uid' => $uid,
					'old_nickname' => $user['nickname'],
					'new_nickname' => $update['nickname'],
					'change_time' => $time,
					'ip' => $longip,
				));
			}
			// 记录签名修改日志
			if(isset($update['signature']) && db_check_table_exists('signature_change_log')) {
				db_insert('signature_change_log', array(
					'uid' => $uid,
					'old_signature' => $user['signature'],
					'new_signature' => $update['signature'],
					'change_time' => $time,
					'ip' => $longip,
				));
			}
			message(0, lang('update_successfully'));
			}
		} else {
			message(0, lang('update_successfully'));
		}

		// hook my_profile_post_end.php
	}

	// 查询当前用户待审资料状态
	$pending_profile_fields = array();
	$pending_audits = db_find('user_profile_audit', array('uid'=>$uid, 'audit_status'=>0), array(), 1, 10);
	if($pending_audits) {
		foreach($pending_audits as $pa) {
			$pending_profile_fields[] = $pa['field_name'];
		}
	}

	// 计算昵称/签名剩余可修改次数（30天周期）
	include_once APP_PATH . 'lib/security/SecurityConfigService.php';
	$nickname_change_limit = intval(SecurityConfigService::get('security_nickname_change_limit', 1));
	$signature_change_limit = intval(SecurityConfigService::get('security_signature_change_limit', 3));
	$nickname_remaining = $nickname_change_limit;
	$signature_remaining = $signature_change_limit;
	$thirty_days_ago = $time - 30 * 86400;
	if($nickname_change_limit > 0 && db_check_table_exists('nickname_change_log')) {
		// 用 db_count + 时间条件替代 db_find 100 条记录
		$recent = db_count('nickname_change_log', array('uid'=>$uid, 'change_time'=>array('>'=>$thirty_days_ago)));
		$nickname_remaining = max(0, $nickname_change_limit - $recent);
	}
	if($signature_change_limit > 0 && db_check_table_exists('signature_change_log')) {
		// 用 db_count + 时间条件替代 db_find 100 条记录
		$recent = db_count('signature_change_log', array('uid'=>$uid, 'change_time'=>array('>'=>$thirty_days_ago)));
		$signature_remaining = max(0, $signature_change_limit - $recent);
	}

	include _include(APP_PATH.'view/htm/my_profile.htm');

} elseif($action == 'security') {

	$active_tab = 'security';
	include _include(APP_PATH.'view/htm/my_security.htm');

} elseif($action == 'password') {

	// 改密前检查封禁状态（锁定用户禁止改密，管理员组 gid=1,2 豁免）
	if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
	if(!in_array(intval($gid), UserBanService::ADMIN_GIDS, true)) {
		$ban_check = UserBanService::checkBanByScene($uid, 'password');
		// hook user_ban_check.php
		if(!$ban_check['allowed']) {
			message(-1, $ban_check['message']);
		}
	}

	if($method == 'POST') {

		CsrfService::check();

		// hook my_password_post_start.php

		$password_old = param('password_old', '', FALSE);
		$password_new = param('password_new', '', FALSE);
		$password_new_repeat = param('password_new_repeat', '', FALSE);
		!hash_equals($password_new, $password_new_repeat) AND message(-1, lang('repeat_password_incorrect'));

		// 密码策略校验（最小长度 + 复杂度，读取后台安全配置）
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$policy_err = SecurityConfigService::checkPasswordPolicy($password_new);
		$policy_err AND message('password_new', $policy_err);

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

		// 新邮箱与当前邮箱一致
		if($email_new == $user['email']) {
			message('email_new', lang('email_same_as_current'));
		}

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

		// 邮箱域名白名单检查
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$allowed_domains = SecurityConfigService::get('security_allowed_email_domains', '');
		if (!empty($allowed_domains)) {
			$email_domain = strtolower(substr(strrchr($email, '@'), 1));
			$allowed_list = array_map('trim', explode(',', strtolower($allowed_domains)));
			$allowed_list = array_filter($allowed_list);
			if (!empty($allowed_list) && !in_array($email_domain, $allowed_list)) {
				message(-1, '该邮箱域名不允许使用，仅支持：' . implode('、', $allowed_list));
			}
		}

		// 新邮箱与当前邮箱一致
		if($email == $user['email']) {
			message(-1, lang('email_same_as_current'));
		}

		$exists = user_read_by_email($email);
		if(!empty($exists) && $exists['uid'] != $uid) {
			message(-1, lang('email_is_in_use'));
		}

		$code = random_int(100000, 999999);
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

		$interval = class_exists('SecurityConfigService') ? intval(SecurityConfigService::get('security_email_code_interval', 60)) : 60;
		if($is_htmx) {
			htmx_trigger('codeSent', array('message' => lang('send_code_successfully')));
		}
		message(0, lang('send_code_successfully'), array('wait' => $interval));
	}

} elseif($action == 'avatar') {

	if($method == 'GET') {

		$active_tab = 'avatar';

		// 检查上传附件权限
		$allowattach = PermissionService::check('allowattach');

		// 查询头像审核状态
		$pending_profile_fields = array();
		$pending_audits = db_find('user_profile_audit', array('uid'=>$uid, 'audit_status'=>0), array(), 1, 10);
		if($pending_audits) {
			foreach($pending_audits as $pa) {
				$pending_profile_fields[] = $pa['field_name'];
			}
		}

		// 头像上传限制信息（用于前端展示）
		include_once APP_PATH . 'lib/security/SecurityConfigService.php';
		$avatar_upload_limit = intval(SecurityConfigService::get('security_avatar_upload_limit', 3));
		$avatar_max_size = intval(SecurityConfigService::get('security_avatar_max_size', 512));
		$avatar_used_count = 0;
		if($avatar_upload_limit > 0 && $uid > 0) {
			$_avatar_count_key = 'security_avatar_upload_count_' . $uid;
			$_saved = kv_get($_avatar_count_key);
			$avatar_used_count = ($_saved === null || $_saved === false) ? 0 : intval($_saved);
		}
		$avatar_remaining = $avatar_upload_limit > 0 ? max(0, $avatar_upload_limit - $avatar_used_count) : -1; // -1 表示不限制

		include _include(APP_PATH.'view/htm/my_avatar_page.htm');

	} else {

		CsrfService::check();

		// hook my_avatar_post_start.php

		// 检查上传附件权限
		!PermissionService::check('allowattach') AND message(-1, lang('user_group_insufficient_privilege'));

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

		$dir = substr(sprintf("%09d", $uid), 0, 3).'/';
		$path = $conf['upload_path'].'avatar/'.$dir;
		!is_dir($path) AND (mkdir($path, 0777, TRUE) OR message(-2, lang('directory_create_failed')));

		// 优先处理 FormData 文件上传（UploadService 方式）
		if(!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
			$file = $_FILES['file'];
			// 校验文件类型
			$allowed = array('jpg', 'jpeg', 'png', 'gif', 'bmp');
			// WebP 仅在 GD 真正支持时允许（function_exists 不够，需验证实际可用）
			if(function_exists('imagecreatefromwebp')) {
				$gd_info = gd_info();
				if(!empty($gd_info['WebP Support'])) {
					$allowed[] = 'webp';
				}
			}
			// 再保险：生成 1x1 WebP 测试 GD 是否真的能读写（某些面板 GD 声明支持但实际失败）
			if(in_array('webp', $allowed)) {
				$webp_test = @imagecreatetruecolor(1, 1);
				if($webp_test) {
					ob_start();
					@imagewebp($webp_test);
					$webp_data = ob_get_clean();
					if(empty($webp_data) || !@imagecreatefromstring($webp_data)) {
						$allowed = array_diff($allowed, array('webp'));
					}
				}
			}
			$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
			!in_array($ext, $allowed) AND message(-1, lang('file_type_not_allowed'));
			// 真实 MIME 校验，防止伪造扩展名上传恶意文件（参考 route/attach.php）
			// finfo 不可用或无法识别文件类型时拒绝上传，不静默降级
			$avatar_mimes = array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/bmp', 'image/x-ms-bmp');
			if(in_array('webp', $allowed)) {
				$avatar_mimes[] = 'image/webp';
			}
			if(!function_exists('finfo_open')) {
				message(-1, lang('file_mime_not_allowed'));
			}
			$finfo = @finfo_open(FILEINFO_MIME_TYPE);
			if(!$finfo) {
				message(-1, lang('file_mime_not_allowed'));
			}
			$real_mime = @finfo_file($finfo, $file['tmp_name']);
			// PHP 8.0+ finfo 是对象，finfo_close 是 no-op，PHP 8.5 已 deprecated
			if(PHP_VERSION_ID < 80000) finfo_close($finfo);
			if($real_mime === false || !in_array($real_mime, $avatar_mimes)) {
				message(-1, lang('file_mime_not_allowed'));
			}
			// 校验文件大小（使用后台配置，0=不限制）
			$_avatar_max_bytes = $max_size > 0 ? $max_size * 1024 : 0;
			if($_avatar_max_bytes > 0 && $file['size'] > $_avatar_max_bytes) {
				message(-1, lang('filesize_too_large', array('maxsize'=>$max_size.'KB', 'size'=>intval($file['size']/1024).'KB')));
			}
			$tmpfile = $file['tmp_name'];
			
			// 头像统一保存为 jpg，GD 库兼容性最好
			$avatar_ext = 'jpg';
			$filename = "$uid.$avatar_ext";
			$url = $conf['upload_url'].'avatar/'.$dir.$filename;
			$destfile = $path.$filename;

			// hook my_avatar_post_save_before.php
			// 需要审核时保存到临时文件，避免覆盖当前头像
			$save_destfile = $need_profile_audit ? ($path.$uid.'_pending_'.$time.'.'.$avatar_ext) : $destfile;
			// 使用和帖子上传一样的方式：等比缩放到 256x256
			$thumb_result = image_thumb($tmpfile, $save_destfile, 256, 256);
			@unlink($tmpfile);
			empty($thumb_result['filesize']) AND message(-1, lang('image_process_failed'));
			if($need_profile_audit) {
				// 需要审核：记录到审核表，暂不更新头像，不删除当前头像文件
				user_profile_audit_create(array(
					'uid' => $uid,
					'field_name' => 'avatar',
					'old_value' => strval($user['avatar']),
					'new_value' => strval($time),
					'audit_status' => 0,
					'create_date' => $time,
				));
				header('Location: '.my_avatar_url());
				exit;
			}
			user_update($uid, array('avatar'=>$time));
			// 清理旧的其他格式头像文件，避免 URL 指向旧文件
			foreach(array('png', 'webp') as $_old_ext) {
				$_old_file = $path.$uid.'.'.$_old_ext;
				if(is_file($_old_file)) @unlink($_old_file);
			}
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
			header('Location: '.my_avatar_url());
			exit;
		}

		// 兼容旧版 base64 上传方式
		$data = param('data', '', FALSE);
		empty($data) AND message(-1, lang('data_is_empty'));
		$data = base64_decode_file_data($data);
		$size = strlen($data);
		$size > 40000 AND message(-1, lang('filesize_too_large', array('maxsize'=>'40K', 'size'=>$size)));

		// hook my_avatar_post_save_before.php
		// base64 数据先写入临时文件，再走 image_thumb 重新编码（剥离图片马）
		$tmpfile_b64 = $path.$uid.'_tmp_'.$time.'.jpg';
		file_put_contents($tmpfile_b64, $data) OR message(-1, lang('write_to_file_failed'));

		// 需要审核时保存到临时文件，避免覆盖当前头像
		$save_destfile_b64 = $need_profile_audit ? ($path.$uid.'_pending_'.$time.'.jpg') : $destfile;
		$thumb_result_b64 = image_thumb($tmpfile_b64, $save_destfile_b64, 256, 256);
		@unlink($tmpfile_b64);
		empty($thumb_result_b64['filesize']) AND message(-1, lang('image_process_failed'));

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
			header('Location: '.my_avatar_url());
			exit;
		}

		user_update($uid, array('avatar'=>$time));

		// hook my_avatar_post_end.php

		header('Location: '.my_avatar_url());
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
			header('Location: '.my_avatar_url());
			exit;
		}

		$preset_list = avatar_preset_files();
		empty($preset_list) AND message(-1, lang('no_preset_avatar'));
		!isset($preset_list[$avatar_index]) AND message(-1, lang('invalid_avatar_index'));

		user_update($uid, array('avatar'=>-$avatar_index));

		// hook my_avatar_preset_end.php

		header('Location: '.my_avatar_url());
		exit;

	} elseif($method == 'GET') {

		// 返回预设头像列表 JSON
		$preset_list = avatar_preset_files();
		message(0, '', array('list'=>array_values($preset_list)));

	}

} elseif($action == 'ai') {

	$active_tab = 'ai';

	// 初始化 AIService
	if (!class_exists('AIService')) include_once APP_PATH . 'lib/AIService.php';
	$aiService = new AIService($db, $conf);

	// 从后台配置读取 AI 提供商列表
	$ai_providers = !empty($conf['ai']['providers']) ? $conf['ai']['providers'] : [];

	// 取所有需要用户配置的功能（mode 为 user_key 或 both 的）
	// mode=global 的功能由系统提供，用户无需填写，不传给前台
	$ai_features_for_user = [];
	$conf_features = !empty($conf['ai']['features']) ? $conf['ai']['features'] : [];
	$has_global_feature = false;  // 是否存在系统提供的功能（用于前台提示）
	foreach ($conf_features as $key => $feature) {
		$mode = isset($feature['mode']) ? $feature['mode'] : 'user_key';
		if ($mode === 'user_key' || $mode === 'both') {
			$ai_features_for_user[$key] = $feature;
		} elseif ($mode === 'global') {
			$has_global_feature = true;
		}
	}
	// 仅在后台完全没有 features 配置时才 fallback 显示 editor（首次安装未配置场景）
	// 不能因为所有功能都是 global 就 fallback，否则用户在后台选了 global 后前台仍被要求填 key
	if (empty($ai_features_for_user) && empty($conf_features)) {
		$ai_features_for_user = ['editor' => ['name' => 'AIeditor', 'mode' => 'user_key', 'call_method' => 'frontend']];
	}

	// 读取用户配置（含旧数据自动迁移）
	$user_ai_config = [];
	if (!empty($user['ai_config'])) {
		$raw = json_decode($user['ai_config'], true);
		if (is_array($raw)) $user_ai_config = $raw;
		// 旧版单层结构检测：有顶层 provider_name 但没有 editor 等功能 key
		if (!empty($raw['provider_name']) && !isset($raw['editor'])) {
			$user_ai_config = ['editor' => $raw];
		}
	}

	if($method == 'GET') {

		include _include(APP_PATH.'view/htm/my_ai.htm');

	} elseif($method == 'POST') {

		CsrfService::check();

		// hook my_ai_setting_post_start.php

		// 遍历所有需要用户配置的功能，收集表单数据（字段 name 加功能 key 前缀）
		$new_config = $user_ai_config;  // 保留其他功能配置
		foreach ($ai_features_for_user as $key => $feature) {
			$mode = isset($feature['mode']) ? $feature['mode'] : 'user_key';

			// both 模式：检查用户是否选「用系统默认」
			if ($mode === 'both') {
				$use_default = param($key . '_use_default', 0);
				if ($use_default) {
					// 用系统默认，清空该功能的用户配置
					unset($new_config[$key]);
					continue;
				}
			}

			// 收集该功能的配置（apiKey/prompt 关闭 htmlspecialchars）
			$provider_name = param($key . '_ai_provider');
			$apikey = param($key . '_ai_apikey', '', FALSE);
			$model = param($key . '_ai_model');
			$prompt_continue = param($key . '_ai_prompt_continue', '', FALSE);
			$prompt_improve = param($key . '_ai_prompt_improve', '', FALSE);

			// user_key 模式必填校验
			if ($mode === 'user_key') {
				empty($provider_name) AND message(-1, lang('please_select_ai_provider'));
				empty($apikey) AND message(-1, lang('please_input_ai_apikey'));
			}

			// 根据 provider_name 查 url
			$url = '';
			if (!empty($ai_providers) && is_array($ai_providers)) {
				foreach ($ai_providers as $provider) {
					if (!empty($provider['name']) && $provider['name'] == $provider_name) {
						$url = isset($provider['url']) ? $provider['url'] : '';
						break;
					}
				}
			}

			$feature_config = [
				'provider_name' => $provider_name,
				'apiKey' => $apikey,
				'model' => $model,
				'url' => $url,
			];
			if (!empty($prompt_continue)) $feature_config['promptContinue'] = $prompt_continue;
			if (!empty($prompt_improve)) $feature_config['promptImprove'] = $prompt_improve;

			$new_config[$key] = $feature_config;
		}

		user_update($uid, ['ai_config' => json_encode($new_config)]);

		// hook my_ai_setting_post_end.php

		message(0, lang('ai_save_success'));
	}

} elseif($action == 'credits') {

	$active_tab = 'credits';

	// 获取积分记录（按操作分组，每页10条）
	include_once APP_PATH . 'lib/CreditsService.php';
	$creditsService = new CreditsService($db, $conf);
	$page = param(2, 1);
	$pagesize = 10;
	$creditsLogResult = $creditsService->logGrouped($uid, $page, $pagesize);
	$credits_loglist = !empty($creditsLogResult['ok']) ? $creditsLogResult['logs'] : array();

	// 分页
	$_total = !empty($creditsLogResult['ok']) ? intval($creditsLogResult['count']) : 0;
	$pagination = pagination(route_url('my_credits_page'), $_total, $page, $pagesize);

	include _include(APP_PATH.'view/htm/credits.htm');

} elseif($action == 'credits_rules') {

	// 积分规则独立页面
	$active_tab = 'credits_rules';
	include_once APP_PATH . 'service/CreditsRuleService.php';
	$fid = param('fid', 0);

	if($fid > 0) {
		$credits_rules = CreditsRuleService::getForumRules($fid);
	} else {
		$credits_rules = CreditsRuleService::getAllGlobalRules();
	}

	// 前端只显示已启用的规则
	if(!empty($credits_rules)) {
		$credits_rules = array_filter($credits_rules, function($rule) {
			return !empty($rule['enabled']);
		});
		$credits_rules = array_values($credits_rules);
	}

	// htmx 请求只返回规则表格片段
	if(is_htmx_request()) {
		include _include(APP_PATH.'view/htm/credits_rules_table.inc.htm');
		return;
	}

	$credits_rules_global = $credits_rules;
	$forumlist = forum_list_cache();

	include _include(APP_PATH.'view/htm/credits_rules.htm');

} elseif($action == 'level') {

	// 我的等级：基于用户组 creditsfrom/creditsto 阈值展示等级与进度
	$active_tab = 'level';

	// 获取所有可升级用户组（gid >= 100），按 creditsfrom 升序排列
	global $grouplist;
	if(!isset($grouplist)) $grouplist = group_list_cache();
	$level_groups = array();
	foreach($grouplist as $g) {
		if($g['gid'] >= 100) {
			$level_groups[] = $g;
		}
	}
	// 按 creditsfrom 升序排序
	usort($level_groups, function($a, $b) {
		return $a['creditsfrom'] - $b['creditsfrom'];
	});

	// 当前用户是否为可升级组（gid >= 100）
	$user_credits = intval($user['credits']);
	$is_upgrade_group = ($user['gid'] >= 100);

	// 当前等级信息 & 下一等级信息
	$current_level = null;
	$next_level = null;
	if($is_upgrade_group && !empty($level_groups)) {
		$level_count = count($level_groups);
		for($i = 0; $i < $level_count; $i++) {
			if($user['gid'] == $level_groups[$i]['gid']) {
				$current_level = $level_groups[$i];
				$current_level['level_index'] = $i + 1;
				if(isset($level_groups[$i + 1])) {
					$next_level = $level_groups[$i + 1];
					$next_level['level_index'] = $i + 2;
				}
				break;
			}
		}
		// 兜底：gid >= 100 但未精确匹配（积分跨区间），按积分重新定位
		if($current_level === null) {
			for($i = 0; $i < $level_count; $i++) {
				if($user_credits >= $level_groups[$i]['creditsfrom'] && $user_credits < $level_groups[$i]['creditsto']) {
					$current_level = $level_groups[$i];
					$current_level['level_index'] = $i + 1;
					if(isset($level_groups[$i + 1])) {
						$next_level = $level_groups[$i + 1];
						$next_level['level_index'] = $i + 2;
					}
					break;
				}
			}
		}
	}

	// 计算进度条
	$progress_percent = 0;
	$credits_to_next = 0;
	if($current_level && $next_level) {
		$range = $current_level['creditsto'] - $current_level['creditsfrom'];
		if($range > 0) {
			$progress_percent = min(100, max(0, round(($user_credits - $current_level['creditsfrom']) / $range * 100)));
		}
		$credits_to_next = max(0, $next_level['creditsfrom'] - $user_credits);
	} elseif($current_level && !$next_level) {
		// 已达最高级
		$progress_percent = 100;
	}

	$header['title'] = lang('my_level');
	$header['mobile_title'] = lang('my_level');
	include _include(APP_PATH.'view/htm/my_level.htm');

} elseif($action == 'follow_users') {

    // 返回关注用户列表（用于@提及）
    !$uid AND message(-1, lang('please_login'));
    $followlist = user_follow_find_following($uid, 1, 50);
    $users = array();
    if($followlist) {
        // 批量查询用户数据，避免循环内 user_read_cache 的 N+1 查询
        $follow_uids = arrlist_values($followlist, 'follow_uid');
        $userlist = user_find(array('uid'=>$follow_uids), array(), 1, count($follow_uids));
        if($userlist) {
            // 以 uid 为 key 重新组织，便于按 followlist 顺序输出
            $user_map = array();
            foreach($userlist as $u) {
                $user_map[$u['uid']] = $u;
            }
            foreach($followlist as $f) {
                $_uid = $f['follow_uid'];
                if(isset($user_map[$_uid])) {
                    $u = $user_map[$_uid];
                    $users[] = array(
                        'uid' => $u['uid'],
                        'username' => $u['display_name'] ?? $u['username'],
                        'avatar_url' => !empty($u['avatar_url']) ? $u['avatar_url'] : default_avatar_url(),
                    );
                }
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo xn_json_encode(array('code' => 0, 'data' => $users));
    exit;

} elseif($action == 'thread') {

	// hook my_thread_start.php

	// 整合页：我的主题 / 我的回复 / 我的点赞 / 我的收藏
	$active_tab = 'thread';
	$subtab = param('tab', 'thread');
	$allowed_subtabs = array('thread', 'post', 'like', 'favorite');
	if(!in_array($subtab, $allowed_subtabs)) {
		$subtab = 'thread';
	}
	$page = param(2, 1);
	$pagesize = 10;
	$threadlist = array();
	$postlist = array();
	$is_user_post_list = FALSE;

	if($subtab == 'thread') {
		// 我的主题：直接查 thread 表，按审核状态过滤
		$thread_cond = array('uid' => $uid);
		if($gid == 0 || $gid > 2) {
			$totalnum = thread_count(array('uid' => $uid, 'audit_status' => 1));
			$thread_cond['audit_status'] = 1;
		} else {
			$totalnum = $user['threads'];
		}
		$threadlist = thread_find($thread_cond, array('tid' => -1), $page, $pagesize);
		thread_list_access_filter($threadlist, $gid);
	} elseif($subtab == 'post') {
		// 我的回复
		$totalnum = $user['posts'];
		$is_user_post_list = TRUE;
		if(function_exists('post_find_by_uid')) {
			$postlist = post_find_by_uid($uid, $page, $pagesize);
		} else {
			$postlist = post_find(array('uid'=>$uid, 'isfirst'=>0), array('pid'=>-1), $page, $pagesize);
		}
		post_list_access_filter($postlist, $gid);
		// 为回帖添加帖子标题信息（批量查询，消除 N+1）
		if($postlist) {
			$_post_tids = array_unique(array_column($postlist, 'tid'));
			$_post_threads = empty($_post_tids) ? array() : db_find('thread', array('tid'=>$_post_tids), array(), 1, count($_post_tids), 'tid');
			foreach($postlist as &$_p) {
				if(isset($_post_threads[$_p['tid']])) {
					$_p['thread_subject'] = $_post_threads[$_p['tid']]['subject'];
				}
			}
			unset($_p);
		}
	} elseif($subtab == 'like') {
		// 我的点赞：按帖子去重查询，JOIN thread 表过滤已删除帖子
		global $db;
		$tablepre = $db->tablepre;
		$offset = ($page - 1) * $pagesize;
		// 联表查询，db_find 不支持 JOIN，保留 db_sql_find
		$sql = "SELECT pl.tid, MAX(pl.create_date) AS last_like_time FROM {$tablepre}post_like pl INNER JOIN {$tablepre}thread t ON pl.tid=t.tid WHERE pl.uid='$uid' GROUP BY pl.tid ORDER BY last_like_time DESC LIMIT $offset, $pagesize";
		$tid_rows = db_sql_find($sql);
		if($tid_rows) {
			$like_tids = array_column($tid_rows, 'tid');
			$threadlist = thread_find_by_tids($like_tids);
		}
		// 去重后的总数（仅统计帖子仍存在的），保留 db_sql_find_one
		$totalnum = db_sql_find_one("SELECT COUNT(DISTINCT pl.tid) AS cnt FROM {$tablepre}post_like pl INNER JOIN {$tablepre}thread t ON pl.tid=t.tid WHERE pl.uid='$uid'");
		$totalnum = !empty($totalnum['cnt']) ? intval($totalnum['cnt']) : 0;
		thread_list_access_filter($threadlist, $gid);
	} elseif($subtab == 'favorite') {
		// 我的收藏
		$pagesize = 20;
		$favlist = thread_favorite_find_by_uid($uid, $page, $pagesize);
		$totalnum = $user['favorites'];
		if($favlist) {
			$fav_tids = array_column($favlist, 'tid');
			$threadlist = thread_find_by_tids($fav_tids);
		}
		// 过滤待审帖子（收藏的他人待审帖子不可见）
		thread_list_access_filter($threadlist, $gid);
	}

	$pagination = pagination(route_url('my_thread_page', [], array('tab' => $subtab)), $totalnum, $page, $pagesize);

	// hook my_thread_end.php

	$header['title'] = lang('my_thread');
	include _include(APP_PATH.'view/htm/my_thread.htm');


} elseif($action == 'favorite') {

	$active_tab = 'favorite';
	$page = param(2, 1);
	$pagesize = 20;
	$favlist = thread_favorite_find_by_uid($uid, $page, $pagesize);
	$totalnum = $user['favorites'];
	$pagination = pagination(route_url('my_favorite_page'), $totalnum, $page, $pagesize);

	$threadlist = array();
	if($favlist) {
		// 批量查询帖子，消除 N+1 查询
		$fav_tids = array_column($favlist, 'tid');
		$threadlist = thread_find_by_tids($fav_tids);
	}
	// 过滤待审帖子（收藏的他人待审帖子不可见）
	thread_list_access_filter($threadlist, $gid);

	$header['title'] = lang('my_favorite');
	include _include(APP_PATH.'view/htm/my_favorite.htm');

} elseif($action == 'post') {

	// 我的回复列表（复制自 user.php post action，固定查当前登录用户）
	$active_tab = 'post';
	$page = param(2, 1);
	$pagesize = 10;
	$totalnum = $user['posts'];
	$pagination = pagination(route_url('my_post_page'), $totalnum, $page, $pagesize);

	$is_user_post_list = TRUE; // 标记为回帖列表，用于截断内容

	if(function_exists('post_find_by_uid')) {
		$postlist = post_find_by_uid($uid, $page, $pagesize);
	} else {
		$postlist = post_find(array('uid'=>$uid, 'isfirst'=>0), array('pid'=>-1), $page, $pagesize);
	}
	post_list_access_filter($postlist, $gid);

	// 为回帖添加帖子标题信息（批量查询，消除 N+1）
	if($postlist) {
		$_post_tids = array_unique(array_column($postlist, 'tid'));
		$_post_threads = empty($_post_tids) ? array() : db_find('thread', array('tid'=>$_post_tids), array(), 1, count($_post_tids), 'tid');
		foreach($postlist as &$_p) {
			if(isset($_post_threads[$_p['tid']])) {
				$_p['thread_subject'] = $_post_threads[$_p['tid']]['subject'];
			}
		}
		unset($_p);
	}

	$header['title'] = lang('my_post');
	include _include(APP_PATH.'view/htm/my_post.htm');

} elseif($action == 'like') {

	// 我的点赞列表（复制自 user.php like action，按帖子去重）
	$active_tab = 'like';
	$page = param(2, 1);
	$pagesize = 10;

	global $db;
	$tablepre = $db->tablepre;
	$offset = ($page - 1) * $pagesize;

	// 按帖子去重查询点赞列表，JOIN thread 表过滤已删除帖子
	$sql = "SELECT pl.tid, MAX(pl.create_date) AS last_like_time FROM {$tablepre}post_like pl INNER JOIN {$tablepre}thread t ON pl.tid=t.tid WHERE pl.uid='$uid' GROUP BY pl.tid ORDER BY last_like_time DESC LIMIT $offset, $pagesize";
	$tid_rows = db_sql_find($sql);
	$threadlist = array();
	if($tid_rows) {
		$like_tids = array_column($tid_rows, 'tid');
		$threadlist = thread_find_by_tids($like_tids);
	}

	// 去重后的总数（仅统计帖子仍存在的）
	$totalnum = db_sql_find_one("SELECT COUNT(DISTINCT pl.tid) AS cnt FROM {$tablepre}post_like pl INNER JOIN {$tablepre}thread t ON pl.tid=t.tid WHERE pl.uid='$uid'");
	$totalnum = !empty($totalnum['cnt']) ? intval($totalnum['cnt']) : 0;

	$pagination = pagination(route_url('my_like_page'), $totalnum, $page, $pagesize);
	thread_list_access_filter($threadlist, $gid);

	$header['title'] = lang('my_like');
	include _include(APP_PATH.'view/htm/my_like.htm');

} elseif($action == 'following') {

	// 整合页：我关注的用户 / 我关注的版块 / 关注我的粉丝
	$active_tab = 'following';
	$subtab = param('tab', 'user');
	$allowed_subtabs = array('user', 'forum', 'follower');
	if(!in_array($subtab, $allowed_subtabs)) {
		$subtab = 'user';
	}
	$page = param(2, 1);
	$pagesize = 10;
	$userlist = array();

	if($subtab == 'user') {
		// 我关注的用户
		$followlist = user_follow_find_following($uid, $page, $pagesize);
		if($followlist) {
			$_follow_uids = array();
			foreach($followlist as $f) { $_follow_uids[] = $f['follow_uid']; }
			// 批量查询关注状态，消除 N+1
			$_follow_status = !empty($uid) ? user_follow_read_batch($uid, $_follow_uids) : array();
			foreach($followlist as $f) {
				$u = user_read_cache($f['follow_uid']);
				if(!empty($u)) {
					$u['is_followed'] = !empty($_follow_status[$u['uid']]);
					$userlist[$u['uid']] = $u;
				}
			}
		}
		$totalnum = $user['follows'];
	} elseif($subtab == 'forum') {
		// 我关注的版块
		$followlist = forum_follow_find_by_uid($uid, $page, $pagesize);
		$forumlist = array();
		if($followlist) {
			$_follow_fids = array();
			foreach($followlist as $f) { $_follow_fids[] = $f['fid']; }
			$_forums = db_find('forum', array('fid'=>$_follow_fids), array(), 1, count($_follow_fids), 'fid');
			foreach($followlist as $f) {
				if(!empty($_forums[$f['fid']])) {
					$forumlist[$f['fid']] = $_forums[$f['fid']];
					$forumlist[$f['fid']]['follow_create_date'] = $f['create_date'];
				}
			}
		}
		// 计算总关注版块数
		$totalnum = db_count('forum_follow', array('uid'=>$uid));
	} elseif($subtab == 'follower') {
		// 关注我的粉丝
		$followlist = user_follow_find_followers($uid, $page, $pagesize);
		if($followlist) {
			$_follower_uids = array();
			foreach($followlist as $f) { $_follower_uids[] = $f['uid']; }
			// 批量查询关注状态，消除 N+1
			$_follow_status = !empty($uid) ? user_follow_read_batch($uid, $_follower_uids) : array();
			foreach($followlist as $f) {
				$u = user_read_cache($f['uid']);
				if(!empty($u)) {
					$u['is_followed'] = !empty($_follow_status[$u['uid']]);
					$userlist[$u['uid']] = $u;
				}
			}
		}
		$totalnum = $user['followeds'];
	}

	$pagination = pagination(route_url('my_following_page', [], array('tab' => $subtab)), $totalnum, $page, $pagesize);

	$header['title'] = lang('i_following');
	include _include(APP_PATH.'view/htm/my_following.htm');

} elseif($action == 'followers') {

	// 关注我的人（复制自 user.php followers action，固定查当前登录用户）
	$active_tab = 'followers';
	$page = param(2, 1);
	$pagesize = 10;
	$followlist = user_follow_find_followers($uid, $page, $pagesize);
	$userlist = array();
	if($followlist) {
		$_follower_uids = array();
		foreach($followlist as $f) { $_follower_uids[] = $f['uid']; }
		// 批量查询关注状态，消除 N+1
		$_follow_status = !empty($uid) ? user_follow_read_batch($uid, $_follower_uids) : array();
		foreach($followlist as $f) {
			$u = user_read_cache($f['uid']);
			if(!empty($u)) {
				$u['is_followed'] = !empty($_follow_status[$u['uid']]);
				$userlist[$u['uid']] = $u;
			}
		}
	}
	$totalnum = $user['followeds'];
	$pagination = pagination(route_url('my_followers_page'), $totalnum, $page, $pagesize);

	$header['title'] = lang('follow_me');
	include _include(APP_PATH.'view/htm/my_followers.htm');

} elseif($action == 'feed') {

	http_location(index_url(array('order' => 'follow')));

} elseif($action == 'notify_unread') {

	!$uid AND exit('');
	// 通知系统已合并，notice 数据已迁移到 notify 表
	$total = notify_count_unread($uid);
	if($total > 0) {
		echo '<span class="position-absolute top-0 start-100 translate-middle badge rounded-circle bg-danger p-1" style="width:0.5rem;height:0.5rem"></span>';
	}
	exit;

} elseif($action == 'notify_unread_count') {

	// 通知未读数 API（AJAX 调用）
	!$uid AND exit(json_encode(array('code' => -1, 'message' => '未登录')));
	$total = notify_count_unread($uid);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('code' => 0, 'data' => array('total' => $total)));
	exit;

} elseif($action == 'notify_dropdown') {

	!$uid AND exit('');

	// 通知系统已合并，仅查询 notify 表
	$notifylist = notify_find_latest($uid, 5);
	if(empty($notifylist)) {
		echo '<div class="text-center text-body-secondary small py-4"><i class="ti ti-bell-off fs-4 d-block mb-1 opacity-50"></i>暂无通知</div>';
		exit;
	}

	// 类型标签从注册中心获取（统一来源，支持插件扩展）
	include_once APP_PATH . 'lib/NotifyTypeRegistry.php';
	NotifyTypeRegistry::init();

	$html = '';
	foreach($notifylist as $item) {
		$_is_audit = in_array($item['type'], array('audit_pending', 'audit_approve', 'audit_reject'));
		$_is_report = in_array($item['type'], array('report_auto_audit', 'report_result', 'report_penalty'));
		$typeLabel = NotifyTypeRegistry::get_label($item['type']);
		$unreadClass = empty($item['is_read']) ? ' notice-unread' : '';
		$unreadDot = empty($item['is_read']) ? '<span class="badge bg-primary rounded-pill flex-shrink-0" style="font-size:0.5rem;padding:2px 5px;">新</span>' : '';
		$href = !empty($item['url']) ? $item['url'] : my_notify_url();

		$html .= '<a href="' . htmlspecialchars($href) . '" class="dropdown-item d-flex align-items-center gap-2 px-3 py-2 notice-dropdown-item' . $unreadClass . '" data-nid="' . $item['nid'] . '" data-source="notify" hx-boost="false">';
		if($_is_audit) {
			$html .= '<span class="rounded-circle flex-shrink-0 bg-info bg-opacity-10 d-flex align-items-center justify-content-center" style="width:24px;height:24px;"><i class="ti ti-shield-check text-info" style="font-size:0.8rem;"></i></span>';
			$html .= '<span class="fw-semibold" style="font-size:0.8rem;">审核通知</span>';
		} elseif($_is_report) {
			$html .= '<span class="rounded-circle flex-shrink-0 bg-warning bg-opacity-10 d-flex align-items-center justify-content-center" style="width:24px;height:24px;"><i class="ti ti-flag text-warning" style="font-size:0.8rem;"></i></span>';
			$html .= '<span class="fw-semibold" style="font-size:0.8rem;">举报通知</span>';
		} else {
		$username = isset($item['from_username']) ? $item['from_username'] : '系统';
		$_from_uid = isset($item['from_uid']) ? intval($item['from_uid']) : 0;
		if($_from_uid == 0) {
			// 系统通知：用铃铛图标替代默认头像
			$html .= '<span class="rounded-circle flex-shrink-0 bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:24px;height:24px;"><i class="ti ti-bell text-primary" style="font-size:0.8rem;"></i></span>';
		} else {
			$avatar_url = isset($item['from_avatar_url']) ? $item['from_avatar_url'] : default_avatar_url();
			$html .= '<img class="rounded-circle flex-shrink-0" src="' . htmlspecialchars($avatar_url) . '" alt="" style="width:24px;height:24px;object-fit:cover;" onerror="this.onerror=null;this.src=\''.default_avatar_url().'\'">';
		}
		$html .= '<span class="fw-semibold" style="font-size:0.8rem;">' . htmlspecialchars($username) . '</span>';
	}
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
	$pagination = pagination(route_url('my_notify_list_page', array('type'=>$type)), $totalnum, $page, $pagesize);
	
	message(0, array('notifylist' => array_values($notifylist), 'total' => $totalnum));

} elseif($action == 'notify_mark_read') {

	if($method != 'POST') {
		if(is_htmx_request()) {
			header('HTTP/1.1 405 Method Not Allowed');
			exit;
		}
		message(-1, 'Method Error.');
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
	// 通知系统已合并，仅操作 notify 表
	notify_mark_all_read($uid);
	$total_unread = notify_count_unread($uid);

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

	if($method != 'POST') {
		if(is_htmx_request()) {
			header('HTTP/1.1 405 Method Not Allowed');
			exit;
		}
		message(-1, 'Method Error.');
	}
	CsrfService::check();

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
	// 路径风格伪静态下 nid 在 param(3)，否则在 param(2)
	$nid = param(2, 0);
	if(!is_numeric($nid)) {
		$nid = param(3, 0);
	}
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
	$total_unread = notify_count_unread($uid);

	if(is_htmx_request()) {
		// htmx: 返回已读状态的 notify 卡片 HTML（OOB 替换）+ HX-Trigger 更新顶部导航未读数
		notify_format($notify);
		$notify['is_read'] = 1;
		include_once APP_PATH . 'lib/NotifyTypeRegistry.php';
		NotifyTypeRegistry::init();
		$icon_name = NotifyTypeRegistry::get_icon($notify['type']);
		$type_label = isset($notify['type_label']) ? $notify['type_label'] : lang('notify_type_label_notice_other');

		// 触发前端更新未读徽章
		$trigger_data = array('noticeReadUpdated' => array('unread_count' => intval($total_unread)));
		header('HX-Trigger: ' . json_encode($trigger_data, JSON_UNESCAPED_UNICODE));

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
elseif($action == 'notify') {

	if($method == 'GET') {

		// 初始化通知类型注册中心（幂等），用于动态构建菜单与查询条件
		include_once APP_PATH . 'lib/NotifyTypeRegistry.php';
		NotifyTypeRegistry::init();

		$page = param(3, 1);
		$pagesize = 20;
		$active = 'notice';
		$type = param(2, '');

		// 修复分页：URL my-notify-{page}.htm 中 page 是纯数字，会被误解析为 type
		// 当 type 为纯数字时，实际是"全部"类型的第 N 页
		if($type !== '' && ctype_digit((string)$type)) {
			$page = intval($type);
			$type = '';
		}

		global $notice_menu;
		// 通知类型筛选菜单：通过注册中心动态构建（支持插件扩展 tab 与「其他」兜底 tab）
		$notice_menu = array();
		$_tabs = NotifyTypeRegistry::get_all_tabs();
		foreach($_tabs as $_tab_key => $_tab_info) {
			if($_tab_key === 0 || $_tab_key === '0') {
				$_tab_url = my_notify_url();
			} else {
				$_tab_url = my_notify_list_url($_tab_key);
			}
			$notice_menu[$_tab_key] = array(
				'url' => $_tab_url,
				'name' => $_tab_info['name'],
				'class' => $_tab_info['class'],
				'icon' => $_tab_info['icon'],
			);
		}

		// 通知系统已合并，仅查询 notify 表
		$is_all = ($type === '' || $type === '0');

		// 构建查询条件
		$cond = array('uid'=>$uid);
		if(!$is_all) {
			// 通过注册中心获取 tab 下的所有 type（支持插件扩展 type 与「其他」tab 动态归集）
			$_tab_types = NotifyTypeRegistry::get_types_by_tab($type);
			if(!empty($_tab_types)) {
				if(count($_tab_types) == 1) {
					$cond['type'] = $_tab_types[0];
				} else {
					$cond['type'] = $_tab_types;
				}
			} else {
				// 未知 tab，查询空结果
				$cond['type'] = '__unknown_tab__';
			}
		}

		// 先获取总数，再分页查询
		$totalnum = db_count('notify', $cond);
		$notifylist = db_find('notify', $cond, array('nid'=>-1), $page, $pagesize, 'nid');

		$noticelist = array();
	if($notifylist) {
		// 收集所有需要批量查询的 ID，消除 N+1 查询
		$_batch_tids = array();
		$_batch_pids = array();
		$_batch_uids = array();
		foreach($notifylist as $_n) {
			if(!empty($_n['tid'])) $_batch_tids[] = intval($_n['tid']);
			if(!empty($_n['parent_pid'])) $_batch_pids[] = intval($_n['parent_pid']);
			if(!empty($_n['from_uid'])) $_batch_uids[] = intval($_n['from_uid']);
		}
		$_batch_tids = array_unique($_batch_tids);
		$_batch_pids = array_unique($_batch_pids);
		$_batch_uids = array_unique($_batch_uids);

		// 批量查询 thread、post、user，替代 foreach 内的逐条查询
		$_batch_threads = empty($_batch_tids) ? array() : db_find('thread', array('tid'=>$_batch_tids), array(), 1, count($_batch_tids), 'tid');
		$_batch_posts = empty($_batch_pids) ? array() : db_find('post', array('pid'=>$_batch_pids), array(), 1, count($_batch_pids), 'pid');
		$_batch_users = empty($_batch_uids) ? array() : user_find_by_uids(implode(',', $_batch_uids));

		// forum 从全局缓存取
		global $forumlist;
		if(!isset($forumlist)) forum_list_cache();

		foreach($notifylist as &$n) {
		// 传入预加载数据，避免 notify_format 内部重复单条查询
		notify_format($n, array(
			'threads' => &$_batch_threads,
			'posts' => &$_batch_posts,
		));
			// 获取帖子标题（从批量查询结果取）
			$_thread_subject = '';
			$_thread_fid = 0;
			if($n['tid'] > 0 && isset($_batch_threads[$n['tid']])) {
				$_thread_subject = $_batch_threads[$n['tid']]['subject'];
				$_thread_fid = $_batch_threads[$n['tid']]['fid'];
			}
			// 获取原评论内容（回复场景：parent_pid 对应的 post，即被回复的那条）
			$_quote_content = '';
			if($n['type'] == 'reply' && !empty($n['parent_pid']) && isset($_batch_posts[$n['parent_pid']])) {
				$_quote_content = strip_tags($_batch_posts[$n['parent_pid']]['message']);
			}
			// 获取版块名（forum_post/thread_forum 类型用于显示来源，从全局缓存取）
			$_forum_name = '';
			if(in_array($n['type'], array('forum_post', 'thread_forum')) && $_thread_fid > 0) {
				if(isset($forumlist[$_thread_fid])) {
					$_forum_name = $forumlist[$_thread_fid]['name'];
				}
			}
			// 获取 from_user 的 gid（从批量查询结果取）
			$_from_gid = 0;
			if(!empty($n['from_uid']) && isset($_batch_users[$n['from_uid']])) {
				$_from_gid = intval($_batch_users[$n['from_uid']]['gid']);
			}
			$noticelist[] = array(
				'source' => 'notify',
				'nid' => $n['nid'],
				'is_read' => empty($n['is_read']) ? 0 : 1,
				'from_uid' => $n['from_uid'],
				'from_gid' => $_from_gid,
				'from_user_avatar_url' => isset($n['from_avatar_url']) ? $n['from_avatar_url'] : default_avatar_url(),
				'from_username' => isset($n['from_username']) ? $n['from_username'] : '',
				'type' => $n['type'],
				'type_label' => isset($n['type_label']) ? $n['type_label'] : lang('notify_type_label_notice_other'),
				'name' => isset($n['summary']) ? $n['summary'] : lang('notify_summary_notice'),
				'summary' => isset($n['summary']) ? $n['summary'] : lang('notify_summary_notice'),
				'message' => isset($n['message']) ? $n['message'] : '',
				'content' => isset($n['content']) ? $n['content'] : '',
				'quote_content' => $_quote_content,
				'forum_name' => $_forum_name,
				'tid' => $n['tid'],
				'pid' => $n['pid'],
				'thread_subject' => $_thread_subject,
				'url' => isset($n['url']) ? $n['url'] : '',
				'create_date_fmt' => isset($n['create_date_fmt']) ? $n['create_date_fmt'] : '',
				'create_date' => isset($n['create_date']) ? $n['create_date'] : 0,
			);
		}
	}

		$pagination_url = $is_all ? route_url('my_notify_page') : route_url('my_notify_list_page', array('type'=>$type));
		$pagination = pagination($pagination_url, $totalnum, $page, $pagesize);

		$header['title'] = lang('notice');
		$header['mobile_title'] = lang('notice');

		include _include(APP_PATH.'view/htm/my_notify.htm');

	} elseif($method == 'POST') {
		CsrfService::check();
		$act = param('act');
		if($act == 'readall') {
			$recvuid = param('uid', 0);
			$recvuid != $uid AND message(-1, lang('notice_my_error'));

			// 通知系统已合并，仅操作 notify 表
			notify_mark_all_read($uid);

			$unread_count = notify_count_unread($uid);

			if(is_htmx_request()) {
				// htmx: 返回 HX-Trigger 事件，前端监听后刷新通知列表
				header('HX-Trigger: {"noticeMarkAllRead": {"unread_count": ' . intval($unread_count) . '}}');
				header('HTTP/1.1 204 No Content');
				exit;
			}

			message(0, array('a' => lang('notice_my_update_readed'),'b' => lang('notice_my_update_allread'), 'unread_count' => $unread_count));

		} elseif($act == 'readone') {
			$nid = param('nid', 0);
			$notify = notify__read($nid);
			empty($notify) AND message(-1, lang('not_exists'));
			$notify['uid'] != $uid AND message(-1, lang('notice_my_error'));

			$is_read = isset($notify['is_read']) ? $notify['is_read'] : 0;
			$is_read == 1 AND message(-1, lang('notice_my_update_readed'));

			$r = notify_mark_read($nid);
			$r === FALSE AND message(-1, lang('notice_my_update_failed'));

			$unread_count = notify_count_unread($uid);

			if(is_htmx_request()) {
				// htmx: 返回已读状态的 notify 卡片 HTML（OOB 替换）
				notify_format($notify);
				include_once APP_PATH . 'lib/NotifyTypeRegistry.php';
				NotifyTypeRegistry::init();
				$icon_name = NotifyTypeRegistry::get_icon($notify['type']);
				$type_label = isset($notify['type_label']) ? $notify['type_label'] : lang('notify_type_label_notice_other');

				// 触发前端更新未读徽章
				$trigger_data = array('noticeReadUpdated' => array('unread_count' => intval($unread_count)));
				header('HX-Trigger: ' . json_encode($trigger_data, JSON_UNESCAPED_UNICODE));

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
				echo '    <div class="notice-actions"></div>';
				echo '  </div>';
				echo '</div>';
				exit;
			}

			message(0, lang('notice_my_update_readed'), array('unread_count' => $unread_count));

		} elseif($act == 'delete') {
			$nid = param('nid', 0);
			$notify = notify__read($nid);
			$notify['uid'] != $uid AND message(-1, lang('notice_my_error'));

			$_was_unread = empty($notify['is_read']) ? 1 : 0;
			$r = notify_delete($nid);
			$r === FALSE AND message(-1, lang('notice_my_update_failed'));

			if(is_htmx_request()) {
				// htmx: 返回 HX-Trigger 事件，前端移除已删除的卡片 + 更新未读数
				$_del_unread = $_was_unread ? notify_count_unread($uid) : 0;
				$_del_trigger = array('noticeDeleted' => array('nid' => intval($nid)));
				if($_was_unread) {
					$_del_trigger['noticeReadUpdated'] = array('unread_count' => intval($_del_unread));
				}
				header('HX-Trigger: ' . json_encode($_del_trigger, JSON_UNESCAPED_UNICODE));
				header('HTTP/1.1 204 No Content');
				exit;
			}

			message(0, lang('notice_my_update_sucessfully'));

		} else {
			message(-1, lang('notice_my_error'));
		}
	}
}

// 积分预检查 API（前端发帖/点赞/收藏前查询积分消耗）
elseif($action == 'credits_check') {
	$event = param('event', '');
	$fid = param('fid', 0);
	if(empty($event) || $uid <= 0) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('code' => -1, 'message' => '参数错误'));
		exit;
	}
	include_once APP_PATH . 'service/CreditsRuleService.php';
	$result = CreditsRuleService::applyRule($event, $uid, $fid, true);

	// 有扣减时附带用户当前余额，供前端弹窗显示
	if(!empty($result['deduct_desc'])) {
		$_user = user_read_cache($uid);
		if(!empty($_user)) {
			$result['balances'] = array(
				'credits' => intval($_user['credits'] ?? 0),
				'golds' => intval($_user['golds'] ?? 0),
				'rmbs' => intval($_user['rmbs'] ?? 0),
			);
		}
	}

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array(
		'code' => $result['ok'] ? 0 : -1,
		'message' => $result['message'],
		'data' => $result,
	), JSON_UNESCAPED_UNICODE);
	exit;
} else {
	// hook my_end.php

	// 未匹配的 action 返回 404，避免 AJAX 请求收到空 body
	http_404();
}

?>
