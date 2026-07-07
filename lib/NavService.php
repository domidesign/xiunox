<?php
!defined('DEBUG') AND exit('Access Denied');

/**
 * 导航插件注册服务
 * 管理插件在顶部导航/左侧导航的显示配置
 *
 * 插件通过 $registry 静态声明默认导航项。
 * 插件启用即在前台/后台展示，无需后台单独启用/禁用。
 * 后台导航设置页中插件项为纯展示（不可编辑/拖动），URL 提供复制按钮。
 */
class NavService {

    // 插件导航注册表：插件ID => 默认配置
    // position: 数组，支持 'top'/'side'/'mobile' 多位置（discover 由 DiscoverService 管理）
    // name_lang: 语言键，运行时通过 lang() 解析
    private static $registry = array(
        'xnx_medal' => array(
            'position' => array('top', 'side', 'mobile'),
            'url' => 'medals',
            'icon' => 'ti-award',
            'name_lang' => 'nav_plugin_name_medal',
            'rank' => 100,
        ),
        'xnx_checkin' => array(
            'position' => array('top', 'side', 'mobile'),
            'url' => 'xnx_checkin',
            'icon' => 'ti-calendar-check',
            'name_lang' => 'nav_plugin_name_checkin',
            'rank' => 110,
        ),
        'xnx_invite' => array(
            'position' => array('top', 'side', 'mobile'),
            'url' => 'xnx_invite-center',
            'icon' => 'ti-user-plus',
            'name_lang' => 'nav_plugin_name_invite',
            'rank' => 120,
        ),
        'xnx_friendlink' => array(
            'position' => array('top', 'side', 'mobile'),
            'url' => 'links',
            'icon' => 'ti-link',
            'name_lang' => 'nav_plugin_name_friendlink',
            'rank' => 130,
        ),
        'xnx_magic' => array(
            'position' => array('top', 'side', 'mobile'),
            'url' => 'magic',
            'icon' => 'ti-sparkles',
            'name_lang' => 'nav_plugin_name_magic',
            'rank' => 140,
        ),
        'xnx_tag' => array(
            'position' => array('top', 'side', 'mobile'),
            'url' => 'topic',
            'icon' => 'ti-tags',
            'name_lang' => 'nav_plugin_name_tag',
            'rank' => 150,
        ),
    );

    /**
     * 生成前台固定链接 URL（绕过 admin 路径下 url() 强制 ?xxx.htm 格式）
     * 根据当前 $conf['url_rewrite_on'] 配置生成对应伪静态格式
     * ponytail: 复制 url() 核心逻辑但跳过 admin 检测，已知 ceiling 是逻辑重复，升级 url() 时需同步
     */
    public static function url_frontend($url) {
        $conf = _SERVER('conf');
        $url_rewrite_on = intval(isset($conf['url_rewrite_on']) ? $conf['url_rewrite_on'] : 0);

        $path = $query = '';
        if (strpos($url, '/') !== FALSE) {
            $path = substr($url, 0, strrpos($url, '/') + 1);
            $query = substr($url, strrpos($url, '/') + 1);
        } else {
            $query = $url;
        }
        if ($query === '') {
            return $url_rewrite_on == 0 ? '/?index.htm' : ($url_rewrite_on == 2 ? '/?index' : '/');
        }

        if ($url_rewrite_on == 0) {
            $r = $path . '?' . $query . '.htm';
        } elseif ($url_rewrite_on == 1) {
            $r = $path . $query . '.htm';
        } elseif ($url_rewrite_on == 2) {
            $r = $path . '?' . str_replace('-', '/', $query);
        } elseif ($url_rewrite_on == 3) {
            $r = $path . str_replace('-', '/', $query);
        } elseif ($url_rewrite_on == 4) {
            $r = $path . $query . '.html';
        } elseif ($url_rewrite_on == 5) {
            $r = $path . str_replace('-', '/', $query) . '.html';
        } else {
            $r = $path . '?' . $query . '.htm';
        }
        // 无路径组件时加 / 前缀成绝对路径（前台风格）
        if ($path === '' && $r !== '' && $r[0] !== '/' && strpos($r, 'http') !== 0 && strpos($r, '//') !== 0) {
            $r = '/' . $r;
        }
        return $r;
    }

    /**
     * 获取指定位置所有已启用插件的导航项（用于前台展示和后台混入主列表）
     * 插件启用即展示，无需后台单独控制
     * @param string $position 'top' / 'side' / 'mobile' / 'discover'
     * @return array 每项含 type/icon/name/slug/url/rank/source
     */
    public static function getPluginNavItems($position = 'top') {
        $items = array();
        // plugin_paths_enabled() 直接读 conf.json 检测 enable+installed，无需 plugin_init()
        // ponytail: 曾用 kv_get('plugins')，但项目从未 kv_set 该键，恒返回 NULL 导致插件项全部丢失
        $enabled_paths = plugin_paths_enabled();

        foreach (self::$registry as $plugin_id => $defaults) {
            // position 支持数组（多位置）或字符串（单位置，向后兼容）
            $positions = is_array($defaults['position']) ? $defaults['position'] : array($defaults['position']);
            if (!in_array($position, $positions)) continue;
            // plugin_paths_enabled() key 为完整路径，需转成 dir 再匹配
            $found = false;
            foreach ($enabled_paths as $path => $pconf) {
                if (file_name($path) === $plugin_id) { $found = true; break; }
            }
            if (!$found) continue;

            $items[] = array(
                'type' => 'link',
                'icon' => $defaults['icon'],
                'name' => lang($defaults['name_lang']),
                'slug' => 'plugin-' . $plugin_id,
                'url'  => self::url_frontend($defaults['url']),
                'rank' => intval($defaults['rank']),
                'source' => 'plugin_' . $plugin_id,
            );
        }
        return $items;
    }
}

