<?php
// 封禁历史记录模型

// hook model_ban_log_start.php

/**
 * 创建封禁历史记录
 * @param array $data 记录数据（uid/admin_uid/action/ban_type/reason/duration/create_time）
 * @return int|false 返回id或失败返回false
 */
function ban_log_create($data) {
    // 补充 create_time
    $data['create_time'] = empty($data['create_time']) ? time() : intval($data['create_time']);
    return db_insert('user_ban_log', $data);
}

/**
 * 按uid查询封禁历史（倒序）
 * @param int $uid 用户uid
 * @param int $page 页码
 * @param int $pagesize 每页条数
 * @return array 记录数组
 */
function ban_log_find_by_uid($uid, $page = 1, $pagesize = 20) {
    $uid = intval($uid);
    return db_find('user_ban_log', array('uid' => $uid), array('id' => -1), $page, $pagesize);
}

/**
 * 按uid统计封禁历史条数
 * @param int $uid
 * @return int
 */
function ban_log_count_by_uid($uid) {
    return db_count('user_ban_log', array('uid' => intval($uid)));
}

/**
 * 查询所有封禁历史（后台管理用，倒序）
 * @param array $cond 条件
 * @param int $page
 * @param int $pagesize
 * @return array
 */
function ban_log_find_all($cond = array(), $page = 1, $pagesize = 50) {
    return db_find('user_ban_log', $cond, array('id' => -1), $page, $pagesize);
}

/**
 * 删除某用户所有封禁历史（用户被彻底删除时调用）
 * @param int $uid
 * @return int 受影响行数
 */
function ban_log_delete_by_uid($uid) {
    return db_delete('user_ban_log', array('uid' => intval($uid)));
}

/**
 * 查询近期解封记录（用于封禁公示页）
 * @param int $days 近多少天
 * @param int $limit 返回条数
 * @return array
 */
function ban_log_find_recent_unbanned($days = 30, $limit = 20) {
    $start_time = time() - $days * 86400;
    return db_find('user_ban_log',
        array('action' => 'unban', 'create_time' => array('>=' => $start_time)),
        array('id' => -1), 1, $limit
    );
}

// hook model_ban_log_end.php
