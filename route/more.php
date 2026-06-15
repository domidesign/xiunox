<?php

!defined('DEBUG') AND exit('Access Denied.');

// 发现页面（宫格应用展示）
$header['title'] = lang('discover') . ' - ' . $conf['sitename'];
$header['keywords'] = lang('discover');
$header['description'] = $conf['sitebrief'];
$_SESSION['fid'] = 0;

// 从配置读取发现导航项
$_discover_items = isset($conf['discover_items']) ? $conf['discover_items'] : array();

// 按 rank 排序
usort($_discover_items, function($a, $b) {
    return intval($a['rank'] ?? 0) - intval($b['rank'] ?? 0);
});

include _include(APP_PATH.'view/htm/more.htm');

?>
