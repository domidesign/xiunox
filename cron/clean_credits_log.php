<?php
// 积分日志清理脚本
// 用法: php cron/clean_credits_log.php
// 删除超过保留天数的积分日志记录

if(php_sapi_name() !== 'cli') {
    exit('This script must be run from CLI.');
}

define('APP_PATH', dirname(__DIR__) . '/');
define('DEBUG', 1);
define('XIUNOPHP_PATH', APP_PATH . 'xiunophp/');

$conf = @include APP_PATH . 'conf/conf.php';
if(!$conf) {
    exit("Error: conf/conf.php not found.\n");
}
$_SERVER['conf'] = $conf;

include XIUNOPHP_PATH . 'xiunophp.php';

// 从配置读取积分日志保留天数，默认 90 天
$retentionDays = isset($conf['credits_log_retention_days']) ? intval($conf['credits_log_retention_days']) : 90;

// 计算截止时间戳
$cutoff = time() - $retentionDays * 86400;

// 获取表前缀
$tablepre = $conf['db'][$conf['db']['type']]['master']['tablepre'] ?? 'bbs_';

// 先统计将要删除的记录数
$countSql = "SELECT COUNT(*) as cnt FROM `{$tablepre}credits_log` WHERE create_date < $cutoff";
$countResult = db_find_one($countSql);
$deleteCount = intval($countResult['cnt'] ?? 0);

if ($deleteCount > 0) {
    // 执行删除
    $sql = "DELETE FROM `{$tablepre}credits_log` WHERE create_date < $cutoff";
    db_exec($sql);
    echo "已清理 {$deleteCount} 条过期积分日志（保留最近 {$retentionDays} 天）\n";
} else {
    echo "没有需要清理的积分日志\n";
}
