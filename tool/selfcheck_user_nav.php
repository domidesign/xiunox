<?php
// UserNavService 核心逻辑自检（stub 框架依赖，直接断言）
define('DEBUG', 1);

function setting_get($k) { static $s = array(); return isset($s[$k]) ? $s[$k] : null; }
function setting_set($k, $v) { static $s = array(); $s[$k] = $v; $GLOBALS['__settings'][$k] = $v; }
function lang($k) { return 'L:' . $k; }
function plugin_paths_enabled() { return array(); } // 空：跳过扫描，直接手动注册

include __DIR__ . '/../lib/UserNavService.php';

// 1. 注册 + 默认启用
UserNavService::register('p1', array('url' => 'my-quest', 'icon' => 'ti-checklist', 'name_lang' => 'quest', 'rank' => 10));
UserNavService::register('p2', array('url' => 'duel-my', 'icon' => 'ti-swords', 'name_lang' => 'duel', 'rank' => 20));
$items = UserNavService::getPluginUserNavItems();
assert(count($items) === 2, '默认启用：未配置时全部显示');
assert($items[0]['enabled'] === 1, 'enabled 默认 1');
assert($items[0]['name'] === 'L:quest', 'name 走 lang() 解析');

// 2. 禁用 p1 后前台过滤、后台含禁用
UserNavService::savePluginUserNavConfig('p1', array('enabled' => 0));
$front = UserNavService::getPluginUserNavItems();
assert(count($front) === 1 && $front[0]['name'] === 'L:duel', '前台过滤禁用项');
$admin = UserNavService::getPluginUserNavItems(false, true);
assert(count($admin) === 2 && $admin[0]['enabled'] === 0, '后台含禁用项且 enabled=0');

// 3. merge 保存：改 rank 不丢 enabled
UserNavService::savePluginUserNavConfig('p1', array('rank' => 5));
$admin = UserNavService::getPluginUserNavItems(false, true);
assert($admin[0]['enabled'] === 0 && $admin[0]['rank'] === 5, 'merge 语义：rank 更新且 enabled 保留');

// 4. 重新启用 + rank 排序生效（p1 rank=5 排在 p2 rank=20 前）
UserNavService::savePluginUserNavConfig('p1', array('enabled' => 1));
$front = UserNavService::getPluginUserNavItems();
assert($front[0]['name'] === 'L:quest' && $front[0]['rank'] === 5, 'rank 排序正确');

// 5. save 后同请求读回新值（configCache 同步）
assert(count($front) === 2, '缓存同步：save 后立即读回新配置');

echo "UserNavService self-check: ALL PASS\n";
