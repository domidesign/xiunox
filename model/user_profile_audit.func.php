<?php

// hook model_user_profile_audit_start.php

// ------------> 最原生的 CURD

function user_profile_audit__create($arr) {
    $r = db_create('user_profile_audit', $arr);
    return $r;
}

function user_profile_audit__update($id, $arr) {
    $r = db_update('user_profile_audit', array('id'=>$id), $arr);
    return $r;
}

function user_profile_audit__read($id) {
    $audit = db_find_one('user_profile_audit', array('id'=>$id));
    return $audit;
}

function user_profile_audit__delete($id) {
    $r = db_delete('user_profile_audit', array('id'=>$id));
    return $r;
}

function user_profile_audit__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
    $auditlist = db_find('user_profile_audit', $cond, $orderby, $page, $pagesize, 'id');
    return $auditlist;
}

// ------------> 关联 CURD

function user_profile_audit_create($arr) {
    $r = user_profile_audit__create($arr);
    return $r;
}

function user_profile_audit_update($id, $arr) {
    $r = user_profile_audit__update($id, $arr);
    return $r;
}

function user_profile_audit_read($id) {
    $audit = user_profile_audit__read($id);
    return $audit;
}

function user_profile_audit_delete($id) {
    $r = user_profile_audit__delete($id);
    return $r;
}

function user_profile_audit_find($cond = array(), $orderby = array('id'=>-1), $page = 1, $pagesize = 20) {
    $auditlist = user_profile_audit__find($cond, $orderby, $page, $pagesize);
    return $auditlist;
}

function user_profile_audit_count($cond = array()) {
    $n = db_count('user_profile_audit', $cond);
    return $n;
}

// 获取用户待审资料
function user_profile_audit_find_by_uid($uid, $audit_status = 0) {
    $cond = array('uid'=>$uid, 'audit_status'=>$audit_status);
    $auditlist = db_find('user_profile_audit', $cond, array('id'=>-1), 1, 100, 'id');
    return $auditlist;
}

// 获取待审资料列表
function user_profile_audit_find_pending($page = 1, $pagesize = 20) {
    $cond = array('audit_status'=>0);
    $auditlist = db_find('user_profile_audit', $cond, array('id'=>-1), $page, $pagesize, 'id');
    if($auditlist) {
        foreach($auditlist as &$item) {
            $user = user_read_cache($item['uid']);
            $item['username'] = $user['username'] ?? '';
            $item['avatar_url'] = $user['avatar_url'] ?? '';
        }
        unset($item);
    }
    return $auditlist;
}

// hook model_user_profile_audit_end.php

?>
