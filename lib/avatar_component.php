<?php

// 统一头像组件
// 输出包含头像和用户组图标的 HTML

// 尺寸配置（对齐现有 CSS: avatar-sm/md/lg/xl）
$GLOBALS['avatar_sizes'] = array(
    'sm' => array('css' => 'avatar-sm', 'icon_font' => 7, 'px' => 32),
    'md' => array('css' => 'avatar-md', 'icon_font' => 8, 'px' => 40),
    'lg' => array('css' => 'avatar-lg', 'icon_font' => 12, 'px' => 52),
    'xl' => array('css' => 'avatar-xl', 'icon_font' => 16, 'px' => 96),
);

// 默认用户组图标映射（当 group_icon_class 为空时使用）
$GLOBALS['avatar_group_defaults'] = array(
    0 => array('icon' => 'ti ti-user', 'color' => '#adb5bd'),   // 游客
    1 => array('icon' => 'ti ti-shield', 'color' => '#dc3545'),  // 管理员
    2 => array('icon' => 'ti ti-star', 'color' => '#0d6efd'),    // 超级版主
    4 => array('icon' => 'ti ti-award', 'color' => '#198754'),   // 版主
    5 => array('icon' => 'ti ti-user-check', 'color' => '#6c757d'), // 实习版主
    6 => array('icon' => 'ti ti-user-x', 'color' => '#dc3545'),  // 待验证
    7 => array('icon' => 'ti ti-ban', 'color' => '#6c757d'),     // 禁止用户
);

/**
 * 输出统一头像组件 HTML
 * @param int $uid 用户 UID
 * @param string $size 尺寸：sm/md/lg/xl
 * @param int $gid 用户组 ID（0 时自动从 user_read_cache 获取）
 * @return string HTML
 */
function avatar_component($uid, $size = 'md', $gid = 0) {
    $user = function_exists('user_read_cache') ? user_read_cache($uid) : array();
    if (empty($user)) {
        $user = array('avatar_url' => '/view/img/avatar.png', 'group_icon_class' => '', 'group_color' => '', 'gid' => 0);
    }
    return avatar_component_from_data(
        !empty($user['avatar_url']) ? $user['avatar_url'] : '/view/img/avatar.png',
        $size,
        isset($user['group_icon_class']) ? $user['group_icon_class'] : '',
        isset($user['group_color']) ? $user['group_color'] : '',
        $gid > 0 ? $gid : (isset($user['gid']) ? $user['gid'] : 0)
    );
}

/**
 * 从已有数据输出头像组件 HTML（避免重复查询）
 * @param string $avatar_url 头像 URL
 * @param string $size 尺寸：sm/md/lg/xl
 * @param string $group_icon_class 用户组图标类
 * @param string $group_color 用户组颜色
 * @param int $gid 用户组 ID
 * @return string HTML
 */
function avatar_component_from_data($avatar_url, $size = 'md', $group_icon_class = '', $group_color = '', $gid = 0) {
    $sizes = $GLOBALS['avatar_sizes'];
    if (!isset($sizes[$size])) {
        $size = 'md';
    }
    $css_class = $sizes[$size]['css'];
    $icon_font = $sizes[$size]['icon_font'];
    $px = $sizes[$size]['px'];

    // 确定图标和背景色：仅当有图标时才显示
    $show_icon = false;
    $icon = '';
    $bg = '';
    if (!empty($group_icon_class)) {
        $show_icon = true;
        $icon = $group_icon_class;
        $bg = !empty($group_color) ? $group_color : '#6c757d';
    }

    $s = '<div class="position-relative d-inline-block">';
    $s .= '<img class="' . $css_class . ' rounded-1" src="' . $avatar_url . '" alt="" width="' . $px . '" height="' . $px . '" decoding="async" fetchpriority="low" onerror="this.onerror=null;this.src=\'/view/img/avatar.png\'" loading="lazy">';
    if ($show_icon) {
        $s .= '<span class="avatar-group-icon" style="background-color:' . $bg . ';">';
        $s .= '<i class="' . $icon . '" style="font-size:' . $icon_font . 'px;color:#fff;"></i>';
        $s .= '</span>';
    }
    $s .= '</div>';

    return $s;
}

?>
