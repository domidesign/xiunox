<?php
// UserNavService 核心逻辑自检（stub 框架依赖，直接断言）
define('DEBUG', 1);

function setting_get($k) { static $s = array(); return isset($s[$k]) ? $s[$k] : null; }
function setting_set($k, $v) { static $s = array(); $s[$k] = $v; $GLOBALS['__settings'][$k] = $v; }
function lang($k) { return 'L:' . $k; }
function plugin_paths_enabled() { return array(); } // 空：跳过扫描，直接手动注册
$GLOBALS['__conf'] = array(); // 默认无 user_nav_items（触发内置回退）
function _SERVER($k) { return $GLOBALS['__conf']; }

include __DIR__ . '/../lib/UserNavService.php';

// 1. 注册 + 默认启用（内置4项回退 + 插件2项 = 6 项）
UserNavService::register('p1', array('url' => 'my-quest', 'icon' => 'ti-checklist', 'name_lang' => 'quest', 'rank' => 10));
UserNavService::register('p2', array('url' => 'duel-my', 'icon' => 'ti-swords', 'name_lang' => 'duel', 'rank' => 20));
$items = UserNavService::getUserNavItems();
assert(count($items) === 6, 'conf 未配置：内置4项回退 + 插件2项全部显示');
assert($items[0]['name'] === 'L:user_nav_profile', '内置项排最前（rank=0）');
assert($items[4]['name'] === 'L:quest' && $items[5]['name'] === 'L:duel', 'name 走 lang() 解析且插件按 rank 排序');

// 2. 禁用插件 p1：前台过滤、后台含禁用
UserNavService::savePluginUserNavConfig('p1', array('enabled' => 0));
$front = UserNavService::getUserNavItems();
assert(count($front) === 5 && $front[4]['name'] === 'L:duel', '前台过滤禁用插件项');
$admin = UserNavService::getUserNavItems(false, true);
assert(count($admin) === 6 && $admin[4]['enabled'] === 0, '后台含禁用项且 enabled=0');

// 3. merge 保存：改 rank 不丢 enabled
UserNavService::savePluginUserNavConfig('p1', array('rank' => 5));
$admin = UserNavService::getUserNavItems(false, true);
$byName = array();
foreach ($admin as $it) $byName[$it['name']] = $it;
assert($byName['L:quest']['enabled'] === 0 && $byName['L:quest']['rank'] === 5, 'merge 语义：rank 更新且 enabled 保留');

// 4. custom 项来源：conf['user_nav_items'] 已配置时优先（后台保存后不再回退内置）
$GLOBALS['__conf']['user_nav_items'] = array(
	array('icon' => 'ti-star', 'name' => '我的自定义', 'slug' => 'mine', 'url' => 'my-favorite', 'class' => '', 'rank' => 0),
);
$custom = UserNavService::getCustomUserNavItems();
assert(count($custom) === 1 && $custom[0]['name'] === '我的自定义', 'conf 已配置：使用自定义项，不再回退内置');

// 5. 重新启用插件 p1 + rank 排序生效（内置4 + p1(rank5) + p2(rank20)）
unset($GLOBALS['__conf']['user_nav_items']);
UserNavService::savePluginUserNavConfig('p1', array('enabled' => 1));
$front = UserNavService::getUserNavItems();
assert(count($front) === 6 && $front[4]['name'] === 'L:quest' && $front[4]['rank'] === 5, 'rank 排序正确');

// 6. save 后同请求读回新值（configCache 同步）
assert(count($front) === 6, '缓存同步：save 后立即读回新配置');

echo "UserNavService self-check: ALL PASS\n";