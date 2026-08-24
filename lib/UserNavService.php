<?php
!defined('DEBUG') AND exit('Access Denied');

/**
 * 用户导航服务
 * 管理首页右侧栏用户信息卡片下方的快捷入口（两列宫格）
 *
 * 与发现导航（DiscoverService）同构，数据分两层：
 * - 自定义项：conf['user_nav_items']（后台可编辑/新增/删除；未配置时回退内置默认项）
 *   新装站点由 conf/conf.default.php 播种内置 4 项；老站点升级后首次进入后台保存即落库
 * - 插件项：注册表 $registry 初始为空，由各插件通过 user_nav_register.php 自注册（lazy 加载）
 *   站长可在后台（设置-导航-用户导航）关闭/排序，配置存 setting 键 plugin_user_nav_items
 */
class UserNavService {

    // 内置默认项（conf['user_nav_items'] 未配置时的回退源；新装站点的同款种子见 conf/conf.default.php）
    private static $builtins = array(
        '_profile'   => array('url' => 'my-profile',   'icon' => 'ti-user',    'name_lang' => 'user_nav_profile',   'rank' => 0),
        '_credits'   => array('url' => 'my-credits',   'icon' => 'ti-coins',   'name_lang' => 'user_nav_credits',   'rank' => 1),
        '_thread'    => array('url' => 'my-thread',    'icon' => 'ti-message', 'name_lang' => 'user_nav_thread',    'rank' => 2),
        '_following' => array('url' => 'my-following', 'icon' => 'ti-heart',   'name_lang' => 'user_nav_following', 'rank' => 3),
    );

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
     * 获取全部用户导航项（自定义项 + 插件注册项，按 rank 排序）
     * @param bool $for_admin 后台调用时传 true：插件项 URL 用前台固定链接格式，附加 source 字段
     * @param bool $include_disabled 后台管理传 true：含禁用插件项（前台默认只返回启用项）
     * @return array
     */
    public static function getUserNavItems($for_admin = false, $include_disabled = false) {
        $items = self::getCustomUserNavItems($for_admin);
        $items = array_merge($items, self::getPluginUserNavItems($for_admin, $include_disabled));
        // 按 rank 升序（PHP 8 usort 稳定排序，同 rank 自定义项在前）
        usort($items, function($a, $b) { return $a['rank'] - $b['rank']; });
        return $items;
    }

    /**
     * 获取自定义用户导航项（conf['user_nav_items']；未配置时回退内置默认项）
     * name 为空且带 name_lang 时运行时解析语言键（站长一旦编辑保存即为具体名称）
     * @param bool $for_admin 后台调用时传 true：附加 source 字段
     * @return array
     */
    public static function getCustomUserNavItems($for_admin = false) {
        $conf = _SERVER('conf');
        $items = array();
        if (isset($conf['user_nav_items']) && is_array($conf['user_nav_items'])) {
            $items = $conf['user_nav_items'];
        } else {
            // conf 未配置（老站点升级/从未保存过）：回退内置默认项
            foreach (self::$builtins as $b) {
                $items[] = array(
                    'icon' => $b['icon'],
                    'name' => '',
                    'name_lang' => $b['name_lang'],
                    'slug' => 'user-nav-' . str_replace('_', '-', ltrim(array_search($b, self::$builtins, true), '_')),
                    'url' => $b['url'],
                    'class' => '',
                    'rank' => $b['rank'],
                );
            }
        }

        $out = array();
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name = !empty($it['name']) ? $it['name'] : (!empty($it['name_lang']) ? lang($it['name_lang']) : '');
            $item = array(
                'icon' => isset($it['icon']) ? $it['icon'] : '',
                'name' => $name,
                'slug' => isset($it['slug']) ? $it['slug'] : '',
                'url'  => isset($it['url']) ? $it['url'] : '',
                'class' => isset($it['class']) ? $it['class'] : '',
                'rank' => intval(isset($it['rank']) ? $it['rank'] : 0),
            );
            if ($for_admin) {
                $item['source'] = 'custom';
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * 获取插件注册的用户导航项（仅插件项，不含自定义项）
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
