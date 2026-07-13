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
        if(empty($target_type) || empty($target_id)) message(-1, '参数错误');
        $r = AuditService::approve($target_type, $target_id, $operator_uid);
        $r ? message(0, '审核通过') : message(-1, '操作失败');
    } elseif($audit_action == 'reject') {
        $target_type = param('target_type', '', FALSE);
        $target_id = param('target_id', 0);
        $reason = param('reason', '', FALSE);
        if(empty($target_type) || empty($target_id)) message(-1, '参数错误');
        $r = AuditService::reject($target_type, $target_id, $operator_uid, $reason);
        $r ? message(0, '已驳回') : message(-1, '操作失败');
    } elseif($audit_action == 'batch_approve') {
        $target_type = param('target_type', '', FALSE);
        $ids = param('ids', array());
        if(empty($target_type) || empty($ids)) message(-1, '参数错误');
        $count = AuditService::batch_approve($target_type, $ids, $operator_uid);
        $count > 0 ? message(0, '成功通过 ' . $count . ' 项') : message(-1, '操作失败');
    } elseif($audit_action == 'batch_reject') {
        $target_type = param('target_type', '', FALSE);
        $ids = param('ids', array());
        $reason = param('reason', '', FALSE);
        if(empty($target_type) || empty($ids)) message(-1, '参数错误');
        $count = AuditService::batch_reject($target_type, $ids, $operator_uid);
        $count > 0 ? message(0, '成功驳回 ' . $count . ' 项') : message(-1, '操作失败');
    } elseif($audit_action == 'ignore') {
        $target_type = param('target_type', '', FALSE);
        $target_id = param('target_id', 0);
        if(empty($target_type) || empty($target_id)) message(-1, '参数错误');
        $r = AuditService::ignore($target_type, $target_id, $operator_uid);
        $r ? message(0, '已忽略') : message(-1, '操作失败');
    } elseif($audit_action == 'batch_ignore') {
        $target_type = param('target_type', '', FALSE);
        $ids = param('ids', array());
        if(empty($target_type) || empty($ids)) message(-1, '参数错误');
        $count = AuditService::batch_ignore($target_type, $ids, $operator_uid);
        $count > 0 ? message(0, '成功忽略 ' . $count . ' 项') : message(-1, '操作失败');
    } elseif($audit_action == 'profile_approve') {
        $audit_id = param('audit_id', 0);
        if(empty($audit_id)) message(-1, '参数错误');
        $r = AuditService::approve_profile($audit_id, $operator_uid);
        $r ? message(0, '审核通过') : message(-1, '操作失败');
    } elseif($audit_action == 'profile_reject') {
        $audit_id = param('audit_id', 0);
        $reason = param('reason', '', FALSE);
        if(empty($audit_id)) message(-1, '参数错误');
        $r = AuditService::reject_profile($audit_id, $operator_uid, $reason);
        $r ? message(0, '已驳回') : message(-1, '操作失败');
    } elseif($audit_action == 'profile_batch_approve') {
        $ids = param('ids', array());
        if(empty($ids)) message(-1, '参数错误');
        $r = AuditService::batch_approve_profiles($ids, $operator_uid);
        $r ? message(0, '批量通过成功') : message(-1, '操作失败');
    } elseif($audit_action == 'profile_ignore') {
        $audit_id = param('audit_id', 0);
        if(empty($audit_id)) message(-1, '参数错误');
        $r = AuditService::ignore_profile($audit_id, $operator_uid);
        $r ? message(0, '已忽略') : message(-1, '操作失败');
    } elseif($audit_action == 'profile_batch_ignore') {
        $ids = param('ids', array());
        if(empty($ids)) message(-1, '参数错误');
        $count = AuditService::batch_ignore_profiles($ids, $operator_uid);
        $count > 0 ? message(0, '成功忽略 ' . $count . ' 项') : message(-1, '操作失败');
    } else {
        message(-1, '未知操作');
    }
}

// hook admin_audit_end.php

?>
