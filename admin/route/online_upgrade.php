<?php

!defined('DEBUG') AND exit('Access Denied');

$action = param(1, '');

include APP_PATH . 'lib/OnlineUpgradeService.php';
$onlineUpgradeService = new OnlineUpgradeService($db, $conf);

// hook admin_online_upgrade_start.php

if($action == 'check') {
    // 检查最新版本（24 小时频率限制：避免对官方升级服务器频繁请求）
    // ponytail: 双层限制——后端 cache 兜底，前端 localStorage 拦截；上限是 86400 秒一次
    $cacheKey = 'online_upgrade_check_last';
    $cached = cache_get($cacheKey);
    $cacheTtl = 86400; // 24 小时
    if ($cached && isset($cached['timestamp']) && isset($cached['result'])
        && (time() - $cached['timestamp'] < $cacheTtl)) {
        $result = $cached['result'];
        $result['cached'] = true;
        $result['next_check_at'] = $cached['timestamp'] + $cacheTtl;
        $result['seconds_until_next'] = max(0, $cached['timestamp'] + $cacheTtl - time());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = $onlineUpgradeService->checkLatestVersion();

    // 仅在检查成功时写缓存，失败不缓存（允许用户立即重试）
    if (isset($result['ok']) && $result['ok']) {
        cache_set($cacheKey, ['timestamp' => time(), 'result' => $result], $cacheTtl);
        $result['next_check_at'] = time() + $cacheTtl;
        $result['seconds_until_next'] = $cacheTtl;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;

} elseif($action == 'preflight') {
    // 前置检查
    $result = $onlineUpgradeService->preflight();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;

} elseif($action == 'run_step') {
    // 执行单个升级步骤
    $step = param('step', '');
    if(empty($step)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Step required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = ['ok' => false, 'message' => '未知步骤：' . $step];

    switch($step) {
        case 'maintenance_on':
            $result = $onlineUpgradeService->maintenanceOn(intval($uid));
            break;
        case 'download':
            // 从 session 或临时文件获取版本信息
            $versionInfo = $onlineUpgradeService->checkLatestVersion();
            if(!$versionInfo['ok']) {
                $result = $versionInfo;
                break;
            }
            $result = $onlineUpgradeService->download($versionInfo['zip_url'], $versionInfo['latest_version']);
            break;
        case 'extract':
            // 找到已下载的 zip 文件
            $zipFiles = glob(APP_PATH . 'tmp/upgrade_*.zip');
            if(empty($zipFiles)) {
                $result = ['ok' => false, 'message' => '未找到升级包，请先执行下载步骤'];
                break;
            }
            $zipPath = end($zipFiles); // 取最新的
            $result = $onlineUpgradeService->extractAndOverwrite($zipPath);
            break;
        case 'db_upgrade':
            $result = $onlineUpgradeService->runDbUpgrade();
            break;
        case 'cleanup':
            $result = $onlineUpgradeService->cleanup();
            // 升级完成后清除检查频率限制缓存，允许用户立即重新检查版本
            cache_delete('online_upgrade_check_last');
            break;
        case 'maintenance_off':
            $result = $onlineUpgradeService->maintenanceOff();
            break;
        default:
            $result = ['ok' => false, 'message' => '未知步骤：' . $step];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;

} elseif($action == 'reinstall') {
    // 重装当前版本
    $result = $onlineUpgradeService->reinstall();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;

} elseif($action == 'diagnose') {
    // 诊断：排查升级后版本号未变等问题，只读，不修改任何文件
    $result = $onlineUpgradeService->diagnose();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;

} else {
    // 默认页面 - 升级主页
    $header['title'] = lang('admin_online_upgrade_title');
    $header['mobile_title'] = lang('admin_online_upgrade');

    // 获取当前版本和最新版本信息（初始为空，由前端 AJAX 获取）
    $currentVersion = $conf['version'] ?? '0.0.0';
    $latestVersion = '';
    $hasUpdate = false;

    // 步骤列表（已移除 backup 步骤：备份责任由用户在升级前确认 Modal 中手动完成）
    $steps = array(
        array('id' => 'maintenance_on', 'name' => lang('admin_online_upgrade_step_maintenance_on'), 'description' => lang('admin_online_upgrade_step_maintenance_on_desc')),
        array('id' => 'download', 'name' => lang('admin_online_upgrade_step_download'), 'description' => lang('admin_online_upgrade_step_download_desc')),
        array('id' => 'extract', 'name' => lang('admin_online_upgrade_step_extract'), 'description' => lang('admin_online_upgrade_step_extract_desc')),
        array('id' => 'db_upgrade', 'name' => lang('admin_online_upgrade_step_db_upgrade'), 'description' => lang('admin_online_upgrade_step_db_upgrade_desc')),
        array('id' => 'cleanup', 'name' => lang('admin_online_upgrade_step_cleanup'), 'description' => lang('admin_online_upgrade_step_cleanup_desc')),
        array('id' => 'maintenance_off', 'name' => lang('admin_online_upgrade_step_maintenance_off'), 'description' => lang('admin_online_upgrade_step_maintenance_off_desc')),
    );

    include _include(ADMIN_PATH.'view/htm/online_upgrade.htm');
}

// hook admin_online_upgrade_end.php

?>
