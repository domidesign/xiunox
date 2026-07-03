<?php
// IP 黑名单模型

// hook model_banned_ip_start.php

/**
 * 创建 IP 黑名单记录
 * @param string $ip_start 起始IP（字符串格式如 192.168.1.1）
 * @param string $ip_end 结束IP（字符串格式）
 * @param string $reason 原因
 * @param int $admin_uid 管理员uid
 * @param int $expire_time 过期时间戳（0=永久）
 * @return int|false
 */
function banned_ip_create($ip_start, $ip_end, $reason, $admin_uid, $expire_time = 0) {
    $ip_start_long = ip2long($ip_start);
    $ip_end_long = ip2long($ip_end);
    if($ip_start_long === false || $ip_end_long === false) return false;
    // 处理32位系统溢出
    $ip_start_long = sprintf('%u', $ip_start_long);
    $ip_end_long = sprintf('%u', $ip_end_long);
    // 确保 start <= end
    if($ip_start_long > $ip_end_long) {
        $tmp = $ip_start_long; $ip_start_long = $ip_end_long; $ip_end_long = $tmp;
    }
    return db_insert('banned_ip', array(
        'ip_start' => $ip_start_long,
        'ip_end' => $ip_end_long,
        'reason' => $reason,
        'admin_uid' => intval($admin_uid),
        'create_time' => time(),
        'expire_time' => intval($expire_time)
    ));
}

/**
 * 删除 IP 黑名单记录
 * @param int $id
 * @return int
 */
function banned_ip_delete($id) {
    return db_delete('banned_ip', array('id' => intval($id)));
}

/**
 * 查询 IP 黑名单列表
 * @param int $page
 * @param int $pagesize
 * @return array
 */
function banned_ip_find($page = 1, $pagesize = 50) {
    return db_find('banned_ip', array(), array('id' => -1), $page, $pagesize);
}

/**
 * 统计 IP 黑名单条数
 * @return int
 */
function banned_ip_count() {
    return db_count('banned_ip', array());
}

/**
 * 检查某 IP 是否在黑名单中
 * @param string $ip 字符串IP
 * @return bool 命中返回 true，未命中返回 false
 * @deprecated 已废弃，内部转发到 IpBlacklistService，保留兼容；旧插件无需改动
 *             建议新代码直接调用 IpBlacklistService::is_blacklisted()
 *             返回类型从 array|false 改为 bool，所有调用方仅用 if 判断，兼容
 */
function banned_ip_check($ip) {
    if(!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    if(!class_exists('IpBlacklistService')) {
        include_once APP_PATH.'lib/security/IpBlacklistService.php';
    }
    if(!class_exists('IpBlacklistService')) return false;
    return IpBlacklistService::is_blacklisted($ip);
}

/**
 * 根据ID查询单条记录
 * @param int $id
 * @return array|false
 */
function banned_ip_read($id) {
    return db_find_one('banned_ip', array('id' => intval($id)));
}

// hook model_banned_ip_end.php
