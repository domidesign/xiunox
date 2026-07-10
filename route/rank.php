<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook rank_start.php

// 排行榜页面
$header['title'] = '排行榜' . ' - ' . $conf['sitename'];
$header['keywords'] = '排行榜,热门帖子,活跃用户';
$header['description'] = $conf['sitebrief'];
$_SESSION['fid'] = 0;

// 获取排行榜数据
include _include(APP_PATH.'service/RankService.php');
$_lbService = new RankService($_SERVER['db']);

// 默认展示热门帖子（本周）
// 用 $_GET 而非 $_REQUEST，避免 COOKIE 覆盖 GET 参数（$_REQUEST 顺序受 request_order 影响）
$_lb_tab = isset($_GET['tab']) ? $_GET['tab'] : 'threads';
$_lb_period = isset($_GET['period']) ? $_GET['period'] : 'week';
$_lb_page = isset($_GET['page']) ? intval($_GET['page']) : 1;

// 白名单校验：参数值不在白名单内则 301 重定向到默认值（防止 query 参数污染显示内容）
// 解决问题：如 /rank.html?period=week递四方速递 被拼接垃圾内容后仍显示排行榜
$_lb_valid_tabs = array('threads', 'users', 'credits');
$_lb_valid_periods = array('week', 'month', 'total');
$_lb_need_redirect = false;
if(!in_array($_lb_tab, $_lb_valid_tabs, true)) {
    $_lb_tab = 'threads';
    $_lb_need_redirect = true;
}
if(!in_array($_lb_period, $_lb_valid_periods, true)) {
    $_lb_period = 'week';
    $_lb_need_redirect = true;
}
if($_lb_need_redirect) {
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: " . rank_url(array('tab' => $_lb_tab, 'period' => $_lb_period)));
    exit;
}

$_lb_items = array();

if($_lb_tab === 'threads') {
    $_lb_result = $_lbService->getHotThreads($_lb_period, 1, 10);
    $_lb_items = isset($_lb_result['list']) ? $_lb_result['list'] : array();
} elseif($_lb_tab === 'users') {
    $_lb_result = $_lbService->getActiveUsers($_lb_period, 1, 10);
    $_lb_items = isset($_lb_result['list']) ? $_lb_result['list'] : array();
} elseif($_lb_tab === 'credits') {
    $_lb_result = $_lbService->getCreditsRanking(1, 10);
    $_lb_items = isset($_lb_result['list']) ? $_lb_result['list'] : array();
}

// 批量预加载用户数据，消除模板内 avatar_component() 的 N+1 查询
// avatar_component() 内部调用 user_read_cache()，逐条查库；改为预加载后由模板使用 avatar_component_from_data()
if(!empty($_lb_items)) {
    $_lb_uids = array();
    foreach($_lb_items as $_it) {
        if(!empty($_it['uid'])) $_lb_uids[] = intval($_it['uid']);
    }
    if(!empty($_lb_uids) && function_exists('user_preload')) {
        user_preload($_lb_uids);
        global $g_static_users;
        foreach($_lb_items as &$_it) {
            $_u = isset($g_static_users[$_it['uid']]) ? $g_static_users[$_it['uid']] : null;
            if(!empty($_u)) {
                $_it['avatar_url'] = !empty($_u['avatar_url']) ? $_u['avatar_url'] : default_avatar_url();
                $_it['group_icon_class'] = !empty($_u['group_icon_class']) ? $_u['group_icon_class'] : '';
                $_it['group_color'] = !empty($_u['group_color']) ? $_u['group_color'] : '';
                $_it['gid'] = !empty($_u['gid']) ? $_u['gid'] : (isset($_it['gid']) ? $_it['gid'] : 0);
            } else {
                $_it['avatar_url'] = default_avatar_url();
                $_it['group_icon_class'] = '';
                $_it['group_color'] = '';
                $_it['gid'] = isset($_it['gid']) ? $_it['gid'] : 0;
            }
        }
        unset($_it);
    }
}

// hook rank_end.php

include _include(APP_PATH.'view/htm/rank.htm');

?>
