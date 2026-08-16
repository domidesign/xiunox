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
    $header['title'] = lang('admin_content_audit');
    $header['mobile_title'] = lang('admin_content_audit');
    include _include(ADMIN_PATH.'view/htm/audit.htm');
} else {
    CsrfService::check();
    $audit_action = param('audit_action', '');
    global $user;
    $operator_uid = intval($user['uid'] ?? 0);

    if($audit_action == 'approve') {
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
