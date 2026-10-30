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

// 安全: 待审头像文件名含随机段（{uid}_pending_{time}_{rand8}.{ext}），防止时间戳可枚举直链；
// 读写统一走本函数（glob 兼容无随机段的旧格式文件）
function profile_audit_pending_files($uid, $new_value) {
    global $conf;
    $uid = intval($uid);
    $new_value = intval($new_value);
    $avatar_dir = substr(sprintf("%09d", $uid), 0, 3).'/';
    $path = $conf['upload_path'].'avatar/'.$avatar_dir;
    $found = array();
    foreach (array('jpg', 'png') as $_ext) {
        foreach (glob($path.$uid.'_pending_'.$new_value.'_*.'.$_ext) ?: array() as $_f) $found[] = $_f;
        // 兼容旧格式（无随机段）
        $legacy = $path.$uid.'_pending_'.$new_value.'.'.$_ext;
        if (is_file($legacy)) $found[] = $legacy;
    }
    return $found;
}

function profile_audit_pending_url($uid, $file) {
    global $conf;
    $avatar_dir = substr(sprintf("%09d", intval($uid)), 0, 3).'/';
    return $conf['upload_url'].'avatar/'.$avatar_dir.basename($file);
}

// 获取用户待审资料
function user_profile_audit_find_by_uid($uid, $audit_status = 0) {
    $cond = array('uid'=>$uid, 'audit_status'=>$audit_status);
    $auditlist = db_find('user_profile_audit', $cond, array('id'=>-1), 1, 100, 'id');
    return $auditlist;
}

// 获取待审资料列表
function user_profile_audit_find_pending($page = 1, $pagesize = 20) {
    // 包含待审(0)和已忽略(3)：已忽略的仍留在列表供后续通过/拒绝
    $cond = array('audit_status'=>array(0, 3));
    $auditlist = db_find('user_profile_audit', $cond, array('id'=>-1), $page, $pagesize, 'id');
    if($auditlist) {
        global $conf;
        foreach($auditlist as &$item) {
            $user = user_read_cache($item['uid']);
            $item['username'] = isset($user['display_name']) ? $user['display_name'] : ($user['username'] ?? '');
            $item['avatar_url'] = $user['avatar_url'] ?? '';
            // 头像审核：生成新头像URL（临时文件，兼容 jpg/png 与新旧两种文件名格式）
            if($item['field_name'] === 'avatar' && !empty($item['new_value'])) {
                $pending_files = profile_audit_pending_files($item['uid'], $item['new_value']);
                $item['new_avatar_url'] = !empty($pending_files) ? profile_audit_pending_url($item['uid'], $pending_files[0]) : '';
            }
        }
        unset($item);
    }
    return $auditlist;
}

// hook model_user_profile_audit_end.php

?>
