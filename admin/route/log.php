<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'credits');

// hook admin_log_start.php

if($action == 'credits') {

	// hook admin_log_credits_start.php

	$page = param('page', 1);
	$pagesize = isset($conf['pagesize']) ? intval($conf['pagesize']) : 20;

	// 筛选参数
	$filter_uid = param('uid', 0);
	$filter_username = param('username', '');
	$filter_type = param('type', '');
	$filter_direction = param('direction', '');
	$filter_date_start = param('date_start', '');
	$filter_date_end = param('date_end', '');
	$filter_ip = param('ip', '');

	// 构建查询条件
	$cond = array();
	if($filter_uid > 0) {
		$cond['uid'] = $filter_uid;
	} elseif(!empty($filter_username)) {
		$_user = db_find_one('user', array('username'=>$filter_username));
		if(!empty($_user)) {
			$cond['uid'] = $_user['uid'];
			$filter_uid = $_user['uid'];
		} else {
			$cond['uid'] = -1; // 不存在的用户
		}
	}
	if(!empty($filter_type)) {
		$cond['type'] = $filter_type;
	}
	if($filter_direction == 'income') {
		$cond['change'] = array('>' => 0);
	} elseif($filter_direction == 'expense') {
		$cond['change'] = array('<' => 0);
	}
	if(!empty($filter_date_start)) {
		$cond['create_date'] = array('>' => strtotime($filter_date_start));
	}
	if(!empty($filter_date_end)) {
		if(isset($cond['create_date'])) {
			$cond['create_date']['<'] = strtotime($filter_date_end . ' 23:59:59');
		} else {
			$cond['create_date'] = array('<' => strtotime($filter_date_end . ' 23:59:59'));
		}
	}
	if(!empty($filter_ip)) {
		$cond['ip'] = intval(sprintf('%u', ip2long($filter_ip)));
	}

	$logs = db_find('credits_log', $cond, array('logid'=>-1), $page, $pagesize);
	$count = db_count('credits_log', $cond);

	// 统计概览 - 今日数据（固定展示今日，不受筛选条件影响）
	$today_start = strtotime(date('Y-m-d'));
	$today_cond = array('create_date' => array('>' => $today_start));

	$today_count = db_count('credits_log', $today_cond);

	// 今日收入/支出统计 - 改用 SQL SUM 聚合查询，避免全表扫描
	global $db;
	$today_stats = db_sql_find_one("SELECT 
			SUM(CASE WHEN `change` > 0 THEN `change` ELSE 0 END) AS today_income,
			SUM(CASE WHEN `change` < 0 THEN ABS(`change`) ELSE 0 END) AS today_expense
			FROM {$db->tablepre}credits_log 
			WHERE create_date > " . intval($today_start));
	$today_income = $today_stats['today_income'] ?? 0;
	$today_expense = $today_stats['today_expense'] ?? 0;

	// 大额变动（单笔变动绝对值>=100，基于当前筛选条件）
	$big_change_cond = $cond;
	$big_change_cond['change'] = array('>' => 99);
	$big_change_count = db_count('credits_log', $big_change_cond);
	$big_change_cond2 = $cond;
	$big_change_cond2['change'] = array('<' => -99);
	$big_change_count += db_count('credits_log', $big_change_cond2);

	// 格式化数据
	if($logs) {
		$uids = array();
		foreach($logs as &$log) {
			$uids[] = $log['uid'];
			$log['ip_fmt'] = long2ip(intval($log['ip']));
			$log['create_date_fmt'] = date('Y-m-d H:i:s', $log['create_date']);
		}
		unset($log);

		// 批量获取用户名
		$uids = array_unique($uids);
		$users = array();
		foreach($uids as $uid) {
			$_u = user_read_cache($uid);
			if(!empty($_u)) $users[$uid] = $_u['display_name'] ?? $_u['username'];
		}
	} else {
		$logs = array();
		$users = array();
	}

	// 积分类型选项
	$credits_types = isset($conf['credits_types']) ? $conf['credits_types'] : array('credits', 'golds', 'rmbs');

	$header['title'] = lang('admin_log_credits');
	$header['mobile_title'] = lang('admin_log_credits');

	// hook admin_log_credits_end.php

	include _include(ADMIN_PATH.'view/htm/log_credits.htm');

} elseif($action == 'login') {

	// hook admin_log_login_start.php

	$page = param('page', 1);
	$pagesize = isset($conf['pagesize']) ? intval($conf['pagesize']) : 20;

	// 筛选参数
	$filter_uid = param('uid', 0);
	$filter_username = param('username', '');
	$filter_date_start = param('date_start', '');
	$filter_date_end = param('date_end', '');
	$filter_success = param('success', -1);
	$filter_ip = param('ip', '');

	// 构建查询条件
	$cond = array();
	if($filter_uid > 0) {
		$cond['uid'] = $filter_uid;
	} elseif(!empty($filter_username)) {
		$_user = db_find_one('user', array('username'=>$filter_username));
		if(!empty($_user)) {
			$cond['uid'] = $_user['uid'];
			$filter_uid = $_user['uid'];
		} else {
			$cond['uid'] = -1;
		}
	}
	if($filter_success >= 0) {
		$cond['success'] = intval($filter_success);
	}
	if(!empty($filter_date_start)) {
		$cond['time'] = array('>' => strtotime($filter_date_start));
	}
	if(!empty($filter_date_end)) {
		if(isset($cond['time'])) {
			$cond['time']['<'] = strtotime($filter_date_end . ' 23:59:59');
		} else {
			$cond['time'] = array('<' => strtotime($filter_date_end . ' 23:59:59'));
		}
	}
	if(!empty($filter_ip)) {
		$cond['ip'] = intval(sprintf('%u', ip2long($filter_ip)));
	}

	$logs = db_find('user_login_log', $cond, array('id'=>-1), $page, $pagesize);
	$count = db_count('user_login_log', $cond);

	// 格式化数据
	if($logs) {
		$uids = array();
		foreach($logs as &$log) {
			$uids[] = $log['uid'];
			$log['ip_fmt'] = long2ip(intval($log['ip']));
			$log['time_fmt'] = date('Y-m-d H:i:s', $log['time']);
		}
		unset($log);

		$uids = array_unique($uids);
		$users = array();
		foreach($uids as $uid) {
			$_u = user_read_cache($uid);
			if(!empty($_u)) $users[$uid] = $_u['display_name'] ?? $_u['username'];
		}
	} else {
		$logs = array();
		$users = array();
	}

	$header['title'] = lang('admin_log_login');
	$header['mobile_title'] = lang('admin_log_login');

	// hook admin_log_login_end.php

	include _include(ADMIN_PATH.'view/htm/log_login.htm');

} elseif($action == 'operation') {

	// hook admin_log_operation_start.php

	$page = param('page', 1);
	$pagesize = isset($conf['pagesize']) ? intval($conf['pagesize']) : 20;

	// 筛选参数
	$filter_uid = param('uid', 0);
	$filter_action = param('log_action', '');
	$filter_target_type = param('target_type', '');
	$filter_date_start = param('date_start', '');
	$filter_date_end = param('date_end', '');

	// 构建查询条件
	$cond = array();
	if($filter_uid > 0) {
		$cond['uid'] = $filter_uid;
	}
	if(!empty($filter_action)) {
		$cond['action'] = $filter_action;
	}
	if(!empty($filter_target_type)) {
		$cond['target_type'] = $filter_target_type;
	}
	if(!empty($filter_date_start)) {
		$cond['create_date'] = array('>' => strtotime($filter_date_start));
	}
	if(!empty($filter_date_end)) {
		if(isset($cond['create_date'])) {
			$cond['create_date']['<'] = strtotime($filter_date_end . ' 23:59:59');
		} else {
			$cond['create_date'] = array('<' => strtotime($filter_date_end . ' 23:59:59'));
		}
	}

	$logs = db_find('admin_log', $cond, array('id'=>-1), $page, $pagesize);
	$count = db_count('admin_log', $cond);

	// 格式化数据
	if($logs) {
		$uids = array();
		foreach($logs as &$log) {
			$uids[] = $log['uid'];
			$log['ip_fmt'] = long2ip(intval($log['ip']));
			$log['create_date_fmt'] = date('Y-m-d H:i:s', $log['create_date']);
		}
		unset($log);

		// 批量获取用户名
		$uids = array_unique($uids);
		$users = array();
		foreach($uids as $uid) {
			$_u = user_read_cache($uid);
			if(!empty($_u)) $users[$uid] = $_u['display_name'] ?? $_u['username'];
		}
	} else {
		$logs = array();
		$users = array();
	}

	// 操作类型选项
	$action_options = array(
		'' => lang('admin_log_success_all'),
		// 用户管理
		'user_create' => lang('admin_op_user_create'),
		'user_update' => lang('admin_op_user_update'),
		'user_delete' => lang('admin_op_user_delete'),
		// 帖子管理
		'thread_delete' => lang('admin_op_thread_delete'),
		'thread_batch_delete' => lang('admin_op_thread_batch_delete'),
		'thread_move' => lang('admin_op_thread_move'),
		'thread_top' => lang('admin_op_thread_top'),
		'thread_close' => lang('admin_op_thread_close'),
		'thread_digest' => lang('admin_op_thread_digest'),
		// 版块管理
		'forum_create' => lang('admin_op_forum_create'),
		'forum_update' => lang('admin_op_forum_update'),
		'forum_delete' => lang('admin_op_forum_delete'),
		// 附件管理
		'attach_delete' => lang('admin_op_attach_delete'),
		'attach_batch_delete' => lang('admin_op_attach_batch_delete'),
		'attach_force_delete' => lang('admin_op_attach_force_delete'),
		// 设置修改
		'setting_site' => lang('admin_op_setting_site'),
		'setting_ai' => lang('admin_op_setting_ai'),
		'setting_smtp' => lang('admin_op_setting_smtp'),
		'setting_upload' => lang('admin_op_setting_upload'),
		'setting_nav' => lang('admin_op_setting_nav'),
		'setting_credits' => lang('admin_op_setting_credits'),
		'setting_seo' => lang('admin_op_setting_seo'),
		'setting_email_tpl' => lang('admin_op_setting_email_tpl'),
		'setting_display' => lang('admin_op_setting_display'),
		// 插件管理
		'plugin_install' => lang('admin_op_plugin_install'),
		'plugin_uninstall' => lang('admin_op_plugin_uninstall'),
		'plugin_enable' => lang('admin_op_plugin_enable'),
		'plugin_disable' => lang('admin_op_plugin_disable'),
		'plugin_upgrade' => lang('admin_op_plugin_upgrade'),
		// 安全管理
		'security_protection' => lang('admin_op_security_protection'),
		'security_captcha' => lang('admin_op_security_captcha'),
		'security_badword' => lang('admin_op_security_badword'),
		'security_blacklist' => lang('admin_op_security_blacklist'),
		// 审核
		'audit_approve' => lang('admin_op_audit_approve'),
		'audit_reject' => lang('admin_op_audit_reject'),
		// 其他
		'group_update' => lang('admin_op_group_update'),
		'credits_rule_update' => lang('admin_op_credits_rule_update'),

		'theme_switch' => lang('admin_op_theme_switch'),
		'cache_clear' => lang('admin_op_cache_clear'),
	);

	// 目标类型选项
	$target_type_options = array(
		'' => lang('admin_log_success_all'),
		'user' => lang('admin_op_target_user'),
		'thread' => lang('admin_op_target_thread'),
		'forum' => lang('admin_op_target_forum'),
		'post' => lang('admin_op_target_post'),
		'attach' => lang('admin_op_target_attach'),
		'setting' => lang('admin_op_target_setting'),
		'plugin' => lang('admin_op_target_plugin'),
		'security' => lang('admin_op_target_security'),
		'group' => lang('admin_op_target_group'),
		'credits_rule' => lang('admin_op_target_credits_rule'),

		'theme' => lang('admin_op_target_theme'),
		'cache' => lang('admin_op_target_cache'),
		'profile' => lang('admin_op_target_profile'),
	);

	// 操作类型中文标签映射（用于表格展示）
	$action_labels = $action_options;
	unset($action_labels['']);

	// 目标类型中文标签映射（用于表格展示）
	$target_type_labels = $target_type_options;
	unset($target_type_labels['']);

	$header['title'] = lang('admin_log_operation');
	$header['mobile_title'] = lang('admin_log_operation');

	// hook admin_log_operation_end.php

	include _include(ADMIN_PATH.'view/htm/log_operation.htm');

} elseif($action == 'audit') {

	// hook admin_log_audit_start.php

	$page = param('page', 1);
	$pagesize = isset($conf['pagesize']) ? intval($conf['pagesize']) : 20;

	// 筛选参数
	$filter_uid = param('uid', 0);
	$filter_target_type = param('target_type', '');
	$filter_audit_action = param('audit_action', '');
	$filter_date_start = param('date_start', '');
	$filter_date_end = param('date_end', '');

	// 构建查询条件
	$cond = array();
	if($filter_uid > 0) {
		$cond['uid'] = $filter_uid;
	}
	if(!empty($filter_target_type)) {
		$cond['target_type'] = $filter_target_type;
	}
	if(!empty($filter_audit_action)) {
		$cond['action'] = $filter_audit_action;
	}
	if(!empty($filter_date_start)) {
		$cond['create_date'] = array('>' => strtotime($filter_date_start));
	}
	if(!empty($filter_date_end)) {
		if(isset($cond['create_date'])) {
			$cond['create_date']['<'] = strtotime($filter_date_end . ' 23:59:59');
		} else {
			$cond['create_date'] = array('<' => strtotime($filter_date_end . ' 23:59:59'));
		}
	}

	$logs = db_find('audit_log', $cond, array('create_date'=>-1), $page, $pagesize);
	$count = db_count('audit_log', $cond);

	// 格式化数据
	if($logs) {
		$uids = array();
		$profile_audit_ids = array();
		$thread_ids = array();
		$post_ids = array();
		foreach($logs as &$log) {
			$uids[] = $log['uid'];
			$user = user_read_cache($log['uid']);
			$log['username'] = $user['display_name'] ?? $user['username'] ?? '';
			$log['create_date_fmt'] = date('Y-m-d H:i:s', $log['create_date']);
			// 收集各类型的审核ID，用于关联查询
			if($log['target_type'] == 'profile' && !empty($log['target_id'])) {
				$profile_audit_ids[] = intval($log['target_id']);
			} elseif($log['target_type'] == 'thread' && !empty($log['target_id'])) {
				$thread_ids[] = intval($log['target_id']);
			} elseif($log['target_type'] == 'post' && !empty($log['target_id'])) {
				$post_ids[] = intval($log['target_id']);
			}
		}
		unset($log);

		// 批量查询 thread 信息（标题）
		$threads_info = array();
		if(!empty($thread_ids)) {
			$threads_info = db_find('thread', array('tid'=>$thread_ids), array(), 1, 100, 'tid');
		}

		// 批量查询 post 信息（所属主题ID + 内容摘要）
		$posts_info = array();
		if(!empty($post_ids)) {
			$posts_info = db_find('post', array('pid'=>$post_ids), array(), 1, 100, 'pid');
		}

		// 将 thread/post 信息合并到日志中
		foreach($logs as &$log) {
			if($log['target_type'] == 'thread' && isset($threads_info[$log['target_id']])) {
				$log['thread_subject'] = $threads_info[$log['target_id']]['subject'] ?? '';
			}
			if($log['target_type'] == 'post' && isset($posts_info[$log['target_id']])) {
				$log['post_tid'] = intval($posts_info[$log['target_id']]['tid']);
				$log['post_summary'] = mb_substr($posts_info[$log['target_id']]['message'] ?? '', 0, 60);
			}
		}
		unset($log);

		// 批量查询 profile 审核详情，获取被修改用户、字段名、旧值新值
		$profile_audits = array();
		$target_uids = array();
		if(!empty($profile_audit_ids)) {
			$profile_audits = db_find('user_profile_audit', array('id'=>$profile_audit_ids), array(), 1, 100, 'id');
			if($profile_audits) {
				foreach($profile_audits as $pa) {
					$target_uids[] = intval($pa['uid']);
				}
			}
		}

		// 批量获取被修改用户的用户名
		$target_users = array();
		$target_uids = array_unique($target_uids);
		foreach($target_uids as $tuid) {
			$_u = user_read_cache($tuid);
			if(!empty($_u)) $target_users[$tuid] = $_u['display_name'] ?? $_u['username'];
		}

		// 将 profile 审核详情合并到日志中
		if(!empty($profile_audits)) {
			foreach($logs as &$log) {
				if($log['target_type'] == 'profile' && isset($profile_audits[$log['target_id']])) {
					$pa = $profile_audits[$log['target_id']];
					$log['target_uid'] = intval($pa['uid']);
					$log['target_username'] = $target_users[$pa['uid']] ?? '';
					$log['field_name'] = $pa['field_name'];
					$log['old_value'] = $pa['old_value'];
					$log['new_value'] = $pa['new_value'];
				}
			}
			unset($log);
		}
	} else {
		$logs = array();
	}

	// 目标类型选项
	$audit_target_options = array(
		'' => lang('admin_log_success_all'),
		'thread' => lang('admin_type_thread'),
		'post' => lang('admin_type_post'),
		'profile' => lang('admin_op_target_profile'),
	);

	// 审核动作选项
	$audit_action_options = array(
		'' => lang('admin_log_success_all'),
		'approve' => lang('admin_action_approve'),
		'reject' => lang('admin_action_reject'),
	);

	$header['title'] = lang('admin_log_audit');
	$header['mobile_title'] = lang('admin_log_audit');

	// hook admin_log_audit_end.php

	include _include(ADMIN_PATH.'view/htm/log_audit.htm');

}

// hook admin_log_end.php

?>
