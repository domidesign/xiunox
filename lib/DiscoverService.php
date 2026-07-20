<?php
!defined('DEBUG') AND exit('Access Denied');

/**
 * 发现页插件注册服务
 * 管理插件在发现页的显示配置
 */
class DiscoverService {

    // 插件发现页注册表：插件ID => 默认配置
    // 每个有独立前台页面的插件都在此注册
    // name 使用语言键，运行时通过 lang() 解析
    private static $registry = array(
        'xnx_checkin' => array(
            'url' => 'xnx_checkin',
            'icon' => 'ti-calendar-check',
            'name_lang' => 'discover_plugin_name_checkin',
            'rank' => 10,
        ),
        'xnx_medal' => array(
            'url' => 'medals',
            'icon' => 'ti-award',
            'name_lang' => 'discover_plugin_name_medal',
            'rank' => 20,
        ),
        'xnx_invite' => array(
            'url' => 'xnx_invite-center',
            'icon' => 'ti-user-plus',
            'name_lang' => 'discover_plugin_name_invite',
            'rank' => 30,
        ),
        'xnx_friendlink' => array(
            'url' => 'links',
            'icon' => 'ti-link',
            'name_lang' => 'discover_plugin_name_friendlink',
            'rank' => 40,
        ),
        'xnx_magic' => array(
            'url' => 'magic',
            'icon' => 'ti-sparkles',
            'name_lang' => 'discover_plugin_name_magic',
            'rank' => 50,
        ),
        'xnx_tag' => array(
            'url' => 'topic',
            'icon' => 'ti-tags',
            'name_lang' => 'discover_plugin_name_tag',
            'rank' => 60,
        ),
        'xnx_dice' => array(
            'url' => 'dice',
            'icon' => 'ti-dice',
            'name_lang' => 'discover_plugin_name_dice',
            'rank' => 70,
        ),
        'xnx_duel' => array(
            'url' => 'duel',
            'icon' => 'ti-swords',
            'name_lang' => 'discover_plugin_name_duel',
            'rank' => 80,
        ),
        'xnx_verify' => array(
            'url' => 'verify',
            'icon' => 'ti-certificate',
            'name_lang' => 'discover_plugin_name_verify',
            'rank' => 100,
        ),
        'xnx_icon' => array(
            'url' => 'icon',
            'icon' => 'ti-icons',
            'name_lang' => 'discover_plugin_name_icon',
            'rank' => 110,
        ),
    );

    /**
     * 获取所有已启用的插件发现项（用于 more.php 展示）
     * @param bool $for_admin 后台调用时传 true：URL 用前台固定链接格式，附加 source/slug 字段
     * @return array
     */
    public static function getPluginDiscoverItems($for_admin = false) {
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
     * 获取注册表信息（用于后台模板显示默认值）
     * @param string $plugin_id
     * @return array|null
     */
    public static function getRegistryInfo($plugin_id) {
        return isset(self::$registry[$plugin_id]) ? self::$registry[$plugin_id] : null;
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
