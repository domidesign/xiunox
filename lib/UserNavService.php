<?php
!defined('DEBUG') AND exit('Access Denied');

/**
 * 用户导航插件注册服务
 * 管理插件在首页右侧栏用户信息卡片下方的快捷入口（两列宫格）
 *
 * 设计对齐 DiscoverService（v2 去核心化）：
 * - 注册表 $registry 初始为空，由各插件通过自己的 user_nav_register.php 调用 register() 自注册
 * - UserNavService 不硬编码任何插件条目，符合开闭原则
 * - lazy 加载：第一次被使用时扫描所有启用插件的 user_nav_register.php
 * - 默认启用：setting 中无记录视为启用，站长可在后台（设置-导航-用户导航）关闭/排序
 */
class UserNavService {

    // 插件用户导航注册表：插件ID => 默认配置（由插件 user_nav_register.php 自注册）
    // name 使用语言键，运行时通过 lang() 解析
    private static $registry = array();

    // 是否已扫描启用插件加载自注册文件
    private static $registered = false;

    // 配置缓存（save 后置 null 强制下次重读，避免同请求内读到旧值）
    private static $configCache = null;

    /**
     * 注册插件到用户导航（由插件 user_nav_register.php 调用）
     * @param string $plugin_id 插件ID（如 'xnx_quest'）
     * @param array $defaults 默认配置：url/icon/name_lang/rank
     */
    public static function register($plugin_id, array $defaults) {
        if (!isset(self::$registry[$plugin_id])) {
            self::$registry[$plugin_id] = $defaults;
        }
    }

    /**
     * 扫描所有启用插件，加载其 user_nav_register.php（如果存在）
     * lazy + 单次执行：第一次访问注册表前触发
     */
    private static function ensureRegistered() {
        if (self::$registered) return;
        self::$registered = true;

        if (!function_exists('plugin_paths_enabled')) return;

        foreach (plugin_paths_enabled() as $_path => $_pconf) {
            $_reg_file = $_path . '/user_nav_register.php';
            if (is_file($_reg_file)) {
                include $_reg_file;
            }
        }
    }

    /**
     * 获取插件用户导航项
     * @param bool $for_admin 后台调用时传 true：URL 用前台固定链接格式，附加 source 字段
     * @param bool $include_disabled 后台管理传 true：含禁用项（前台默认只返回启用项）
     * @return array
     */
    public static function getPluginUserNavItems($for_admin = false, $include_disabled = false) {
        self::ensureRegistered();

        $items = array();
        $config = self::getAllConfig();

        foreach (self::$registry as $plugin_id => $defaults) {
            $pc = isset($config[$plugin_id]) ? $config[$plugin_id] : array();
            // 默认启用：仅显式配置 enabled=0 才禁用
            $enabled = isset($pc['enabled']) ? intval($pc['enabled']) : 1;
            if (!$include_disabled && empty($enabled)) continue;

            // 后台调用时用 NavService::url_frontend 生成前台固定链接格式（绕过 admin 强制 ?xxx.htm）
            // 前台调用时保留原始路由名（如 'my-quest'），由模板的 NavService::href() 统一转换
            $url = ($for_admin && class_exists('NavService', false))
                ? NavService::url_frontend($defaults['url'])
                : $defaults['url'];

            $item = array(
                'icon' => !empty($pc['icon']) ? $pc['icon'] : $defaults['icon'],
                'name' => !empty($pc['name']) ? $pc['name'] : lang($defaults['name_lang']),
                'url'  => $url,
                'rank' => intval(isset($pc['rank']) ? $pc['rank'] : $defaults['rank']),
                'enabled' => $enabled,
            );
            if ($for_admin) {
                $item['source'] = 'plugin_' . $plugin_id;
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * 保存单个插件的用户导航配置（merge 语义：只覆盖传入的字段，未传字段保留原值）
     * @param string $plugin_id 插件ID
     * @param array $data 配置数据 (enabled, icon, name, rank)
     */
    public static function savePluginUserNavConfig($plugin_id, array $data) {
        $config = self::getAllConfig();
        $old = isset($config[$plugin_id]) ? $config[$plugin_id] : array();
        $config[$plugin_id] = array_merge($old, $data);
        setting_set('plugin_user_nav_items', $config);
        self::$configCache = $config;
    }

    /**
     * 读取所有插件用户导航配置
     * @return array
     */
    private static function getAllConfig() {
        if (self::$configCache === null) {
            $raw = setting_get('plugin_user_nav_items');
            self::$configCache = !empty($raw) ? $raw : array();
            if (!is_array(self::$configCache)) self::$configCache = array();
        }
        return self::$configCache;
    }
}
