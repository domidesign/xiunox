<?php

/*
* Copyright (C) 2015 xiuno.com
*/

!defined('DEBUG') AND exit('Access Denied.');

// hook index_start.php

$page = param(1, 1);
$order = $conf['order_default'];
$order != 'tid' AND $order = 'lastpid';
$order_param = param('order', '');
if($order_param == 'lastpid') $order = 'lastpid';
if($order_param == 'tid') $order = 'tid';
if($order_param == 'hot') $order = 'hot';
if($order_param == 'digest') $order = 'digest';
if($order_param == 'follow') {
    if(empty($uid)) {
        $order = $conf['order_default'];
    } else {
        $order = 'follow';
    }
}
$pagesize = $conf['pagesize'];
$active = 'default';

// 读取视图模式
$view_mode = param('view', '');
if(empty($view_mode)) $view_mode = isset($_COOKIE['bbs_view_mode']) ? $_COOKIE['bbs_view_mode'] : 'list';
if(!in_array($view_mode, array('list', 'timeline', 'waterfall'))) $view_mode = 'list';

// 从默认的地方读取主题列表
$thread_list_from_default = 1;

// 关注用户的帖子
if($order == 'follow' && !empty($uid)) {
    $following_uids = user_follow_find_following_uids($uid);
    if(!empty($following_uids)) {
        $totalnum = thread_count(array('uid' => $following_uids));
        $pagination = pagination(url("$route-{page}", array('order' => 'follow')), $totalnum, $page, $pagesize);
        $threadlist = thread_find(array('uid' => $following_uids), array('tid' => -1), $page, $pagesize);
        if($threadlist) foreach($threadlist as &$thread) thread_format($thread);
        unset($thread);
        // 过滤待审帖子（普通用户不可见他人待审帖子）
        thread_list_access_filter($threadlist, $gid);
    } else {
        $threadlist = array();
        $totalnum = 0;
        $pagination = pagination(url("$route-{page}", array('order' => 'follow')), 0, $page, $pagesize);
    }
    $thread_list_from_default = 0;
}

// 精华帖子
if($order == 'digest') {
    $fids = arrlist_values($forumlist_show, 'fid');
    $digest_cond = array('fid' => $fids, 'digest' => array('>' => 0));
    $totalnum = thread_count($digest_cond);
    $pagination = pagination(url("$route-{page}", array('order' => 'digest')), $totalnum, $page, $pagesize);
    $threadlist = thread_find($digest_cond, array('create_date' => -1), $page, $pagesize);
    if($threadlist) foreach($threadlist as &$thread) thread_format($thread);
    unset($thread);
    thread_list_access_filter($threadlist, $gid);
    $thread_list_from_default = 0;
}

// hook index_thread_list_before.php
if($thread_list_from_default) {
	$fids = arrlist_values($forumlist_show, 'fid');
	$threads = arrlist_sum($forumlist_show, 'threads');
	$pagination = pagination(url("$route-{page}", array('order' => $order)), $threads, $page, $pagesize);
	
	// hook thread_find_by_fids_before.php
	// 最热排序：数据库没有hot字段，先用lastpid取数据再PHP排序
	$db_order = ($order == 'hot') ? 'lastpid' : $order;
	$threadlist = thread_find_by_fids($fids, $page, $pagesize, $db_order, $threads);
	if($order == 'hot') {
		usort($threadlist, function($a, $b) {
			$score_a = intval($a['views']) + intval($a['posts']) * 5;
			$score_b = intval($b['views']) + intval($b['posts']) * 5;
			return $score_b - $score_a;
		});
	}
}

// 查找置顶帖（在主要浏览排序的第一页显示，排除精华/关注等过滤视图）
$toplist = array();
if($page == 1 && !in_array($order, array('digest', 'follow'))) {
	$toplist = thread_top_find(0);
	thread_list_access_filter($toplist, $gid);
	if($view_mode != 'list') {
		$toplist = thread_list_enrich($toplist);
	}
}

// 过滤没有权限访问的主题 / filter no permission thread
thread_list_access_filter($threadlist, $gid);

// 为朋友圈/瀑布流视图加载内容预览和缩略图
if($view_mode != 'list') {
	$threadlist = thread_list_enrich($threadlist);
}

// SEO
$header['title'] = $conf['sitename']; 				// site title
$header['keywords'] = ''; 					// site keyword
$header['description'] = $conf['sitebrief']; 			// site description
$_SESSION['fid'] = 0;

// hook index_end.php

include _include(APP_PATH.'view/htm/index.htm');

?>