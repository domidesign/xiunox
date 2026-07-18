<?php

!defined('DEBUG') AND exit('Access Denied.');

// 发现页面（宫格应用展示）
$header['title'] = lang('discover') . ' - ' . $conf['sitename'];
$header['keywords'] = lang('discover');
$header['description'] = $conf['sitebrief'];
$_SESSION['fid'] = 0;

// 从配置读取发现导航项（手动添加的）
$_discover_items = isset($conf['discover_items']) ? $conf['discover_items'] : array();

// 收集插件注册的发现项
// ponytail: 路由层显式加载 NavService，不依赖 header.inc.htm 视图层兜底（_include 不比较 mtime，旧 tmp 缓存会跳过兜底加载行）
include APP_PATH . 'lib/NavService.php';
include APP_PATH . 'lib/DiscoverService.php';
$plugin_items = DiscoverService::getPluginDiscoverItems();
$_discover_items = array_merge($_discover_items, $plugin_items);

// 按 rank 排序
usort($_discover_items, function($a, $b) {
    return intval($a['rank'] ?? 0) - intval($b['rank'] ?? 0);
});

include _include(APP_PATH.'view/htm/more.htm');

?>
