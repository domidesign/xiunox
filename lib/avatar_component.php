<?php

// 统一头像组件（三层嵌套结构）
//
// L1 avatar-wrap            —— 外层容器，承载形状 class、data 属性、头像框（frame hook 注入点）
// L2 position-relative      —— 头像本体容器，承载 img + 角标（badges hook 注入点）
// L3 avatar-group-icon      —— 角标层（用户组图标 / 插件 badges）
//
// Hook 点：
//   1. avatar_component_frame.php  —— 头像框 hook，注入到 L1 内（L2 之后），后注入覆盖先注入（$data['frame_html'] = ...）
//   2. avatar_component_badges.php —— 角标 hook，注入到 L2 内（avatar-group-icon 之后），累加不覆盖（$data['badges_html'] .= ...）
//
// plugin_hook 第二参数为引用传递（model/plugin.func.php:883），hook 中修改 $data['frame_html'] / $data['badges_html'] 即可回传

// 尺寸配置（6 档：xs/sm/md/lg/xl/xxl）
$GLOBALS['avatar_sizes'] = array(
    'xs'  => array('css' => 'avatar-xs',  'icon_font' => 6,  'px' => 24),
    'sm'  => array('css' => 'avatar-sm',  'icon_font' => 7,  'px' => 32),
    'md'  => array('css' => 'avatar-md',  'icon_font' => 8,  'px' => 40),
    'lg'  => array('css' => 'avatar-lg',  'icon_font' => 12, 'px' => 52),
    'xl'  => array('css' => 'avatar-xl',  'icon_font' => 16, 'px' => 96),
    'xxl' => array('css' => 'avatar-xxl', 'icon_font' => 20, 'px' => 128),
);

// 默认用户组图标映射（当 group_icon_class 为空时按 gid 回退）
$GLOBALS['avatar_group_defaults'] = array(
    0 => array('icon' => 'ti ti-user',       'color' => '#adb5bd'), // 游客
    1 => array('icon' => 'ti ti-shield',     'color' => '#dc3545'), // 管理员
    2 => array('icon' => 'ti ti-star',       'color' => '#0d6efd'), // 超级版主
    4 => array('icon' => 'ti ti-award',      'color' => '#198754'), // 版主
    5 => array('icon' => 'ti ti-user-check', 'color' => '#6c757d'), // 实习版主
    6 => array('icon' => 'ti ti-user-x',     'color' => '#dc3545'), // 待验证
    7 => array('icon' => 'ti ti-ban',        'color' => '#6c757d'), // 禁止用户
);

/**
 * 读取头像形状配置（带静态缓存，避免每次调用查 setting）
 * 来源 setting_get('avatar_shape')，合法值 rounded|circle|square，默认 rounded
 * @return string
 */
function avatar_component_get_shape() {
    static $shape = null;
    if ($shape === null) {
        $s = function_exists('setting_get') ? setting_get('avatar_shape') : '';
        $shape = in_array($s, array('rounded', 'circle', 'square'), true) ? $s : 'rounded';
    }
    return $shape;
}

/**
 * 输出统一头像组件 HTML（从 uid 查询用户数据后转调 from_data）
 * @param int    $uid  用户 UID
 * @param string $size 尺寸：xs/sm/md/lg/xl/xxl
 * @param int    $gid  用户组 ID（0 时自动从 user_read_cache 获取）
 * @return string HTML
 */
function avatar_component($uid, $size = 'md', $gid = 0) {
    $user = function_exists('user_read_cache') ? user_read_cache($uid) : array();
    if (empty($user)) {
        $user = array('avatar_url' => '', 'group_icon_class' => '', 'group_color' => '', 'gid' => 0);
    }
    $avatar_url = !empty($user['avatar_url'])
        ? $user['avatar_url']
        : (function_exists('default_avatar_url') ? default_avatar_url() : '/view/img/avatar.png');
    return avatar_component_from_data(
        $avatar_url,
        $size,
        isset($user['group_icon_class']) ? $user['group_icon_class'] : '',
        isset($user['group_color']) ? $user['group_color'] : '',
        $gid > 0 ? $gid : (isset($user['gid']) ? $user['gid'] : 0),
        array('_uid' => intval($uid))
    );
}

/**
 * 从已有数据输出头像组件 HTML（三层嵌套结构）
 *
 * @param string $avatar_url        头像 URL
 * @param string $size              尺寸：xs/sm/md/lg/xl/xxl
 * @param string $group_icon_class  用户组图标类（为空时按 gid 回退到默认映射）
 * @param string $group_color       用户组颜色
 * @param int    $gid               用户组 ID
 * @param array  $options           选项：
 *     - extra_class      string  附加到 L1 的 class（如 'border border-2 border-white'）
 *     - link_uid         int     传 uid 自动包裹 <a href="user-{uid}"> 链接
 *     - show_group_icon  bool    false 时隐藏用户组角标（默认 true）
 *     - show_hooks       bool    false 时跳过两个 hook 点（默认 true，性能敏感场景可关闭）
 *     - lazy             bool    false 时关闭 lazy loading / decoding async（默认 true，首屏可关闭）
 *     - badge_position   string  角标位置 hint，传给 badges hook（top-left/top-right/bottom-left/bottom-right，默认 bottom-right）
 *     - _uid             int     内部用，供 hook 识别头像所属用户（由 avatar_component() 自动传入）
 * @return string HTML
 */
function avatar_component_from_data($avatar_url, $size = 'md', $group_icon_class = '', $group_color = '', $gid = 0, $options = array()) {
    $sizes = isset($GLOBALS['avatar_sizes']) ? $GLOBALS['avatar_sizes'] : array();
    if (!isset($sizes[$size])) {
        $size = 'md';
    }
    $size_conf = isset($sizes[$size]) ? $sizes[$size] : array('css' => 'avatar-md', 'icon_font' => 8, 'px' => 40);
    $css_class = $size_conf['css'];
    $icon_font = $size_conf['icon_font'];
    $px        = $size_conf['px'];

    // 选项解析（isset 兜底，PHP 8.x 兼容）
    $extra_class      = isset($options['extra_class']) ? $options['extra_class'] : '';
    $show_group_icon  = !isset($options['show_group_icon']) || $options['show_group_icon'] !== false;
    $show_hooks       = !isset($options['show_hooks'])      || $options['show_hooks']      !== false;
    $lazy             = !isset($options['lazy'])            || $options['lazy']            !== false;
    $badge_position   = isset($options['badge_position']) ? $options['badge_position'] : 'bottom-right';
    $uid              = isset($options['_uid']) ? intval($options['_uid']) : 0;

    // 头像形状（rounded|circle|square）
    $shape = function_exists('avatar_component_get_shape') ? avatar_component_get_shape() : 'rounded';

    // 默认头像 URL（onerror 兜底）
    $default_avatar = function_exists('default_avatar_url') ? default_avatar_url() : '/view/img/avatar.png';

    // 确定用户组图标和背景色：优先传入参数，为空时按 gid 回退到默认映射
    $show_icon = false;
    $icon = '';
    $bg = '';
    if ($show_group_icon) {
        if (!empty($group_icon_class)) {
            $show_icon = true;
            $icon = $group_icon_class;
            $bg = !empty($group_color) ? $group_color : '#6c757d';
        } else {
            $defaults = isset($GLOBALS['avatar_group_defaults']) ? $GLOBALS['avatar_group_defaults'] : array();
            $gidKey = intval($gid);
            if (isset($defaults[$gidKey])) {
                $show_icon = true;
                $icon = $defaults[$gidKey]['icon'];
                $bg = $defaults[$gidKey]['color'];
            }
        }
    }

    // 角标 hook（badges）—— 注入到 L2 内、avatar-group-icon 之后（累加模式）
    $badges_html = '';
    if ($show_hooks && function_exists('plugin_hook')) {
        $_hook_data = array(
            'uid'            => $uid,
            'gid'            => $gid,
            'size'           => $size,
            'avatar_url'     => $avatar_url,
            'badge_position' => $badge_position,
            'badges_html'    => '',
        );
        plugin_hook('avatar_component_badges.php', $_hook_data);
        $badges_html = isset($_hook_data['badges_html']) ? $_hook_data['badges_html'] : '';
    }

    // 头像框 hook（frame）—— 注入到 L1 内、L2 之后（覆盖模式）
    $frame_html = '';
    if ($show_hooks && function_exists('plugin_hook')) {
        $_hook_data = array(
            'uid'        => $uid,
            'gid'        => $gid,
            'size'       => $size,
            'avatar_url' => $avatar_url,
            'frame_html' => '',
        );
        plugin_hook('avatar_component_frame.php', $_hook_data);
        $frame_html = isset($_hook_data['frame_html']) ? $_hook_data['frame_html'] : '';
    }

    // ===== 构建 HTML =====

    // L1: 外层容器
    $s = '<div class="avatar-wrap avatar-wrap-' . $size . ' avatar-shape-' . $shape;
    if ($extra_class !== '') {
        $s .= ' ' . $extra_class;
    }
    $s .= '" data-size="' . $size . '">';

    // L2: 头像本体容器
    $s .= '<div class="position-relative d-inline-block">';

    // img 标签（移除硬编码 rounded-1，形状由外层 avatar-shape-* 控制）
    $s .= '<img class="' . $css_class . '" src="' . $avatar_url . '" width="' . $px . '" height="' . $px . '"';
    if ($lazy) {
        $s .= ' decoding="async" fetchpriority="low" loading="lazy"';
    }
    $s .= ' onerror="this.onerror=null;this.src=\'' . $default_avatar . '\'">';

    // L3: 用户组角标
    if ($show_icon) {
        $s .= '<span class="avatar-group-icon" style="background-color:' . $bg . ';">';
        $s .= '<i class="' . $icon . '" style="font-size:' . $icon_font . 'px;color:#fff;"></i>';
        $s .= '</span>';
    }

    // L3: badges hook 注入（group-icon 之后）
    $s .= $badges_html;

    // 闭合 L2
    $s .= '</div>';

    // frame hook 注入（L1 内、L2 之后）
    $s .= $frame_html;

    // 闭合 L1
    $s .= '</div>';

    // link_uid 包裹整个 L1
    if (!empty($options['link_uid'])) {
        $link_uid = intval($options['link_uid']);
        if ($link_uid > 0 && function_exists('url')) {
            $s = '<a href="' . url('user-' . $link_uid) . '" class="avatar-link text-decoration-none">' . $s . '</a>';
        }
    }

    return $s;
}

?>
