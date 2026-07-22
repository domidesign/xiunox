<?php
!defined('DEBUG') AND exit('Access Denied');

/**
 * 发现页插件注册服务
 * 管理插件在发现页的显示配置
 *
 * v2 设计（去核心化）：
 * - 注册表 $registry 初始为空，由各插件通过自己的 discover_register.php 调用 register() 自注册
 * - DiscoverService 不再硬编码任何插件条目，符合开闭原则
 * - lazy 加载：第一次被使用时扫描所有启用插件的 discover_register.php
 * - 兜底兼容：plugin_paths_enabled 不可用时（极早期加载场景）跳过自动注册
 */
class DiscoverService {

    // 插件发现页注册表：插件ID => 默认配置（由插件 discover_register.php 自注册）
    // name 使用语言键，运行时通过 lang() 解析
    private static $registry = array();

    // 是否已扫描启用插件加载自注册文件
    private static $registered = false;

    /**
     * 注册插件到发现页（由插件 discover_register.php 调用）
     * @param string $plugin_id 插件ID（如 'xnx_medal'）
     * @param array $defaults 默认配置：url/icon/name_lang/rank
     */
    public static function register($plugin_id, array $defaults) {
        if (!isset(self::$registry[$plugin_id])) {
            self::$registry[$plugin_id] = $defaults;
        }
    }

    /**
     * 扫描所有启用插件，加载其 discover_register.php（如果存在）
     * 该文件内部应调用 DiscoverService::register(...) 完成自注册
     * lazy + 单次执行：第一次访问注册表前触发
     */
    private static function ensureRegistered() {
        if (self::$registered) return;
        self::$registered = true;

        // ponytail: plugin_paths_enabled 在 model/plugin.func.php，若极早期加载场景不可用则跳过
        // （DiscoverService 主要在路由层调用，此时 plugin.func.php 必然已加载，此兜底仅为防御）
        if (!function_exists('plugin_paths_enabled')) return;

        foreach (plugin_paths_enabled() as $_path => $_pconf) {
            $_reg_file = $_path . '/discover_register.php';
            if (is_file($_reg_file)) {
                include $_reg_file;
            }
        }
    }

    /**
     * 获取所有已启用的插件发现项（用于 more.php 展示）
     * @param bool $for_admin 后台调用时传 true：URL 用前台固定链接格式，附加 source/slug 字段
     * @return array
     */
    public static function getPluginDiscoverItems($for_admin = false) {
        self::ensureRegistered();

        $items = array();
        $config = self::getAllConfig();

        foreach (self::$registry as $plugin_id => $defaults) {
            if (empty($config[$plugin_id]['enabled'])) continue;

            // 后台调用时用 NavService::url_frontend 生成前台固定链接格式（绕过 admin 强制 ?xxx.htm）
            $url = ($for_admin && class_exists('NavService', false))
                ? NavService::url_frontend($defaults['url'])
                : url($defaults['url']);

            $item = array(
                'icon' => !empty($config[$plugin_id]['icon']) ? $config[$plugin_id]['icon'] : $defaults['icon'],
                'name' => !empty($config[$plugin_id]['name']) ? $config[$plugin_id]['name'] : lang($defaults['name_lang']),
                'url'  => $url,
                'class' => isset($defaults['class']) ? $defaults['class'] : '',
                'rank' => intval(isset($config[$plugin_id]['rank']) ? $config[$plugin_id]['rank'] : $defaults['rank']),
            );
            if ($for_admin) {
                $item['source'] = 'plugin_' . $plugin_id;
                $item['slug'] = 'plugin-' . $plugin_id;
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * 获取单个插件的发现页配置
     * @param string $plugin_id 插件ID
     * @return array
     */
    public static function getPluginDiscoverConfig($plugin_id) {
        self::ensureRegistered();

        if (!isset(self::$registry[$plugin_id])) {
            return array('enabled' => 0, 'icon' => '', 'name' => '', 'rank' => 0);
        }
        $defaults = self::$registry[$plugin_id];
        $config = self::getAllConfig();

        $pc = isset($config[$plugin_id]) ? $config[$plugin_id] : array();
        return array(
            'enabled' => isset($pc['enabled']) ? intval($pc['enabled']) : 0,
            'icon'    => !empty($pc['icon']) ? $pc['icon'] : $defaults['icon'],
            'name'    => !empty($pc['name']) ? $pc['name'] : lang($defaults['name_lang']),
            'rank'    => isset($pc['rank']) ? intval($pc['rank']) : intval($defaults['rank']),
        );
    }

    /**
     * 保存单个插件的发现页配置
     * @param string $plugin_id 插件ID
     * @param array $data 配置数据 (enabled, icon, name, rank)
     */
    public static function savePluginDiscoverConfig($plugin_id, $data) {
        $config = self::getAllConfig();
        $config[$plugin_id] = array(
            'enabled' => intval($data['enabled'] ?? 0),
            'icon'    => trim($data['icon'] ?? ''),
            'name'    => trim($data['name'] ?? ''),
            'rank'    => intval($data['rank'] ?? 0),
        );
        setting_set('plugin_discover_items', $config);
    }

    /**
     * 读取所有插件发现页配置
     * @return array
     */
    private static function getAllConfig() {
        static $config = null;
        if ($config === null) {
            $raw = setting_get('plugin_discover_items');
            $config = !empty($raw) ? $raw : array();
            if (!is_array($config)) $config = array();
        }
        return $config;
    }
}
