<?php
// 管理员操作日志

// 写入管理员操作日志
function admin_log_create($action, $target_type, $target_ids = '', $detail = '') {
    global $uid, $longip, $time;

    // 检查表是否存在，避免升级未完成时写入失败
    if(!db_check_table_exists('admin_log')) return false;

    $arr = array(
        'uid' => intval($uid),
        'action' => $action,
        'target_type' => $target_type,
        'target_ids' => is_array($target_ids) ? implode(',', $target_ids) : strval($target_ids),
        'detail' => $detail,
        'ip' => intval($longip),
        'create_date' => $time,
    );

    return db_create('admin_log', $arr) !== false;
}

// hook model_admin_log_end.php
?>
