<?php
!defined('DEBUG') AND exit('Access Denied');

/**
 * 导航插件注册服务
 * 管理插件在顶部导航/左侧导航/手机导航的显示配置
 *
 * v2 设计（去核心化）：
 * - 注册表 $registry 初始为空，由各插件通过自己的 nav_register.php 调用 register() 自注册
 * - NavService 不再硬编码任何插件条目，符合开闭原则（与 DiscoverService 保持一致）
 * - lazy 加载：第一次被使用时扫描所有启用插件的 nav_register.php
 * - 插件启用即在前台展示，无需后台单独启用/禁用
 * - 后台导航设置页中插件项为纯展示（不可编辑/拖动），URL 提供复制按钮
 */
class NavService {

    // 插件导航注册表：插件ID => 默认配置（由插件 nav_register.php 自注册）
    // position: 数组，支持 'top'/'side'/'mobile' 多位置（discover 由 DiscoverService 管理）
    // name_lang: 语言键，运行时通过 lang() 解析
    private static $registry = array();

    // 是否已扫描启用插件加载自注册文件
    private static $registered = false;

    /**
     * 注册插件到导航（由插件 nav_register.php 调用）
     * @param string $plugin_id 插件ID（如 'xnx_medal'）
     * @param array $defaults 默认配置：position/url/icon/name_lang/rank
     */
    public static function register($plugin_id, array $defaults) {
        if (!isset(self::$registry[$plugin_id])) {
            self::$registry[$plugin_id] = $defaults;
        }
    }

    /**
     * 扫描所有启用插件，加载其 nav_register.php（如果存在）
     * 该文件内部应调用 NavService::register(...) 完成自注册
     * lazy + 单次执行：第一次访问注册表前触发
     */
    private static function ensureRegistered() {
        if (self::$registered) return;
        self::$registered = true;

        // ponytail: plugin_paths_enabled 在 model/plugin.func.php，若极早期加载场景不可用则跳过
        if (!function_exists('plugin_paths_enabled')) return;

        foreach (plugin_paths_enabled() as $_path => $_pconf) {
            $_reg_file = $_path . '/nav_register.php';
            if (is_file($_reg_file)) {
                include $_reg_file;
            }
        }
    }

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
     * 规范化导航 URL（后台保存时用）：把任意输入转为路由名格式
     * - 外链（http(s)://、//）原样返回
     * - 空、'/'、'' → '/'
     * - '?xxx.htm' / 'xxx.htm' / 'xxx.html' / '/xxx.htm' / '/xxx' → 'xxx'
     * - 其他（'xxx'、'thread-create-0'）原样返回
     * ponytail: 不处理 mailto:/tel:/javascript: 等冷门协议，留给 href() 兜底
     */
    public static function normalize($url) {
        if($url === null) return '';
        $url = trim($url);
        if($url === '') return '';
        // 外链原样返回
        if(strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || strpos($url, '//') === 0) return $url;
        // 首页
        if($url === '/') return '/';
        // 剥离前导 ?
        if(strpos($url, '?') === 0) $url = substr($url, 1);
        // 剥离前导 /
        $url = ltrim($url, '/');
        // 剥离 .htm / .html 后缀
        $url = preg_replace('/\.(html?)$/', '', $url);
        return $url;
    }

    /**
     * 渲染导航链接 href（前台模板用）：返回经 url() 转换后的 URL
     * 兼容数据库历史存量数据（?xxx.htm、xxx.htm、/xxx 等格式）
     * - 外链、# 锚点 → 原样返回
     * - 空 → '#'
     * - '/' → index_url()
     * - '?xxx.htm' / 'xxx.htm' / '/xxx.htm' / '/xxx' / 'xxx' → url(路由名)
     */
    public static function href($url) {
        if($url === null) return '#';
        $url = trim($url);
        if($url === '' || $url === '#') return '#';
        // 外链
        if(strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || strpos($url, '//') === 0) return $url;
        // 锚点
        if(strpos($url, '#') === 0) return $url;
        // mailto/tel/javascript 等带协议的
        if(strpos($url, '://') !== false) return $url;
        // 首页
        if($url === '/') return index_url();
        // 规范化为路由名后走 url()
        $route = self::normalize($url);
        if($route === '' || $route === '/') return index_url();
        return url($route);
    }

    /**
     * 判断导航项 URL 是否匹配当前请求路由（用于服务端渲染 active 类）
     * ponytail: 匹配规则 - 规范化后精确匹配或前缀匹配（处理 forum-1 vs forum-1-2 分页）
     * 已知 ceiling: 无法处理完全自定义的 URL 结构（如外链锚点跳转），外链直接返回 false
     * @param string $url 导航项原始 URL（nav_items 配置中的 url 字段）
     * @return bool
     */
    public static function isActive($url) {
        if ($url === null) return false;
        $url = trim($url);
        if ($url === '' || $url === '#') return false;
        // 外链 / 锚点 / mailto 等不参与 active 匹配
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) return false;
        if (strpos($url, '//') === 0) return false;
        if (strpos($url, '://') !== false) return false;
        if (strpos($url, '#') === 0) return false;

        // 当前请求的规范化路由：用全局 $route 拼接 param(1) 得到如 forum-1、thread-15
        // ponytail: route 在 index.inc.php 路由解析后设置，header.inc.htm 渲染时已可用
        static $_cur_route = null;
        if ($_cur_route === null) {
            $_cur_route = '';
            $cur_route = isset($GLOBALS['route']) ? $GLOBALS['route'] : '';
            if ($cur_route !== '' && function_exists('param')) {
                $id_seg = param(1);
                if ($id_seg !== '' && $id_seg !== null) {
                    $_cur_route = $cur_route . '-' . $id_seg;
                } else {
                    $_cur_route = $cur_route;
                }
            }
        }

        // nav_item 规范化
        $nav_route = self::normalize($url);

        // 首页匹配：nav_item url 为空 / / 或 index 时，匹配当前路由 index 或空
        if ($nav_route === '' || $nav_route === '/' || $nav_route === 'index') {
            return $_cur_route === 'index' || $_cur_route === '';
        }

        // 精确匹配（如 nav_url='rank' vs cur='rank'）
        if ($_cur_route === $nav_route) return true;

        // 前缀匹配（如 nav_url='forum-1' vs cur='forum-1-2' 分页 / forum-1.htm/2 等）
        if (strpos($_cur_route, $nav_route . '-') === 0) return true;

        return false;
    }

    /**
     * 获取指定位置所有已启用插件的导航项（用于前台展示和后台混入主列表）
     * 插件启用即展示，无需后台单独控制
     * @param string $position 'top' / 'side' / 'mobile' / 'discover'
     * @return array 每项含 type/icon/name/slug/url/rank/source
     */
    public static function getPluginNavItems($position = 'top') {
        self::ensureRegistered();

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
                'class' => isset($defaults['class']) ? $defaults['class'] : '',
                'rank' => intval($defaults['rank']),
                'source' => 'plugin_' . $plugin_id,
            );
        }
        return $items;
    }
}
