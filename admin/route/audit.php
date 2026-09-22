<?php

!defined('DEBUG') AND exit('Access Denied.');

include_once APP_PATH . 'lib/security/AuditService.php';

$action = param(1, 'index');

// hook admin_audit_start.php

if($method == 'GET') {
    $pending_threads = AuditService::get_pending_list('thread', 1, 20);
    $pending_posts = AuditService::get_pending_list('post', 1, 20);
    $pending_thread_count = AuditService::get_pending_count('thread');
    $pending_post_count = AuditService::get_pending_count('post');
    $pending_profiles = AuditService::get_pending_profile_list(1, 20);
    $pending_profile_count = AuditService::get_pending_profile_count();
    // 通知设置（参考 plugin-notice：三类待审内容 × 站内消息/邮件/红点 + 提醒邮箱）
    $audit_notify_config = AuditService::notify_config();
    global $user;
    $audit_notify_admin_email = isset($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL) ? $user['email'] : '';
    $header['title'] = lang('admin_content_audit');
    $header['mobile_title'] = lang('admin_content_audit');
    include _include(ADMIN_PATH.'view/htm/audit.htm');
} else {
    CsrfService::check();
    $audit_action = param('audit_action', '');
    global $user;
    $operator_uid = intval($user['uid'] ?? 0);

    // ---- 通知设置：发送测试邮件（共用逻辑 plugin_notify_send_test）----
    if($audit_action == 'notify_test') {
        $_an_test = plugin_notify_send_test(trim(strval(param('test_email', ''))));
        $_an_test['ok'] ? message(0, $_an_test['message']) : message(-1, $_an_test['message']);
    } elseif($audit_action == 'notify_save') {
        // 覆盖式构建完整键集合（9 开关 + email_to），checkbox 未勾选=键缺失=0
        $_an_config = array();
        foreach(array('thread', 'post', 'profile') as $_an_type) {
            foreach(array('system', 'email', 'badge') as $_an_ch) {
                $_an_config[$_an_type.'_'.$_an_ch] = param($_an_type.'_'.$_an_ch, 0) ? 1 : 0;
            }
        }
        $_an_email_to = trim(strval(param('email_to', '')));
        if($_an_email_to !== '') {
            foreach(preg_split('/[\s,;]+/', $_an_email_to) as $_an_em) {
                if($_an_em === '') continue;
                if(filter_var($_an_em, FILTER_VALIDATE_EMAIL) === FALSE) {
                    message(-1, lang('admin_plugin_notice_email_invalid')." [$_an_em]");
                }
            }
        }
        $_an_config['email_to'] = $_an_email_to;
        plugin_notify_config_save('core_audit', $_an_config);
        plugin_notice_flush();
        message(0, lang('admin_audit_notify_saved'));
    } elseif($audit_action == 'approve') {
        $target_type = param('target_type', '', FALSE);
        $target_id = param('target_id', 0);
        if(empty($target_type) || empty($target_id)) message(-1, lang('admin_param_error'));
        $r = AuditService::approve($target_type, $target_id, $operator_uid);
        $r ? message(0, lang('admin_audit_approved')) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'reject') {
        $target_type = param('target_type', '', FALSE);
        $target_id = param('target_id', 0);
        $reason = param('reason', '', FALSE);
        if(empty($target_type) || empty($target_id)) message(-1, lang('admin_param_error'));
        $r = AuditService::reject($target_type, $target_id, $operator_uid, $reason);
        $r ? message(0, lang('admin_audit_rejected')) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'batch_approve') {
        $target_type = param('target_type', '', FALSE);
        $ids = param('ids', array());
        if(empty($target_type) || empty($ids)) message(-1, lang('admin_param_error'));
        $count = AuditService::batch_approve($target_type, $ids, $operator_uid);
        $count > 0 ? message(0, lang('admin_audit_batch_approved', array('n'=>$count))) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'batch_reject') {
        $target_type = param('target_type', '', FALSE);
        $ids = param('ids', array());
        $reason = param('reason', '', FALSE);
        if(empty($target_type) || empty($ids)) message(-1, lang('admin_param_error'));
        $count = AuditService::batch_reject($target_type, $ids, $operator_uid);
        $count > 0 ? message(0, lang('admin_audit_batch_rejected', array('n'=>$count))) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'ignore') {
        $target_type = param('target_type', '', FALSE);
        $target_id = param('target_id', 0);
        if(empty($target_type) || empty($target_id)) message(-1, lang('admin_param_error'));
        $r = AuditService::ignore($target_type, $target_id, $operator_uid);
        $r ? message(0, lang('admin_audit_ignored')) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'batch_ignore') {
        $target_type = param('target_type', '', FALSE);
        $ids = param('ids', array());
        if(empty($target_type) || empty($ids)) message(-1, lang('admin_param_error'));
        $count = AuditService::batch_ignore($target_type, $ids, $operator_uid);
        $count > 0 ? message(0, lang('admin_audit_batch_ignored', array('n'=>$count))) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'profile_approve') {
        $audit_id = param('audit_id', 0);
        if(empty($audit_id)) message(-1, lang('admin_param_error'));
        $r = AuditService::approve_profile($audit_id, $operator_uid);
        $r ? message(0, lang('admin_audit_approved')) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'profile_reject') {
        $audit_id = param('audit_id', 0);
        $reason = param('reason', '', FALSE);
        if(empty($audit_id)) message(-1, lang('admin_param_error'));
        $r = AuditService::reject_profile($audit_id, $operator_uid, $reason);
        $r ? message(0, lang('admin_audit_rejected')) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'profile_batch_approve') {
        $ids = param('ids', array());
        if(empty($ids)) message(-1, lang('admin_param_error'));
        $r = AuditService::batch_approve_profiles($ids, $operator_uid);
        $r ? message(0, lang('admin_audit_batch_approve_success')) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'profile_ignore') {
        $audit_id = param('audit_id', 0);
        if(empty($audit_id)) message(-1, lang('admin_param_error'));
        $r = AuditService::ignore_profile($audit_id, $operator_uid);
        $r ? message(0, lang('admin_audit_ignored')) : message(-1, lang('admin_op_failed'));
    } elseif($audit_action == 'profile_batch_ignore') {
        $ids = param('ids', array());
        if(empty($ids)) message(-1, lang('admin_param_error'));
        $count = AuditService::batch_ignore_profiles($ids, $operator_uid);
        $count > 0 ? message(0, lang('admin_audit_batch_ignored', array('n'=>$count))) : message(-1, lang('admin_op_failed'));
    } else {
        message(-1, lang('admin_unknown_action'));
    }
}

// hook admin_audit_end.php

?>
