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
$_lb_tab = isset($_REQUEST['tab']) ? $_REQUEST['tab'] : 'threads';
$_lb_period = isset($_REQUEST['period']) ? $_REQUEST['period'] : 'week';
$_lb_page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;

$_lb_items = array();
$_lb_has_more = false;

if($_lb_tab === 'threads') {
    $_lb_result = $_lbService->getHotThreads($_lb_period, $_lb_page, 20);
    $_lb_items = isset($_lb_result['list']) ? $_lb_result['list'] : array();
    $_lb_has_more = count($_lb_items) >= 20;
} elseif($_lb_tab === 'users') {
    $_lb_result = $_lbService->getActiveUsers($_lb_period, $_lb_page, 20);
    $_lb_items = isset($_lb_result['list']) ? $_lb_result['list'] : array();
    $_lb_has_more = count($_lb_items) >= 20;
} elseif($_lb_tab === 'credits') {
    $_lb_result = $_lbService->getCreditsRanking($_lb_page, 20);
    $_lb_items = isset($_lb_result['list']) ? $_lb_result['list'] : array();
    $_lb_has_more = count($_lb_items) >= 20;
}

// hook rank_end.php

include _include(APP_PATH.'view/htm/rank.htm');

?>
