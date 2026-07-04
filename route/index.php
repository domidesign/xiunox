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

// 从默认的地方读取主题列表
$thread_list_from_default = 1;

// 关注用户的帖子
if($order == 'follow' && !empty($uid)) {
    $following_uids = user_follow_find_following_uids($uid);
    if(!empty($following_uids)) {
        $follow_cond = array('uid' => $following_uids);
        // 非管理员只显示审核通过的帖子（排除待审和驳回）
        if($gid == 0 || $gid > 2) {
            $follow_cond['audit_status'] = 1;
        }
        $totalnum = thread_count($follow_cond);
        $pagination = pagination(url("$route-{page}", array('order' => 'follow')), $totalnum, $page, $pagesize);
        $threadlist = thread_find($follow_cond, array('tid' => -1), $page, $pagesize);
        if($threadlist) foreach($threadlist as &$thread) thread_format($thread);
        unset($thread);
        // 过滤没有权限访问的主题
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
    // 非管理员只显示审核通过的帖子（排除待审和驳回）
    if($gid == 0 || $gid > 2) {
        $digest_cond['audit_status'] = 1;
    }
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
	// 首页版块过滤：如果后台设置了 home_forum_ids，则只显示指定版块的帖子
	$_home_forum_ids = isset($conf['home_forum_ids']) ? $conf['home_forum_ids'] : array();
	if(!empty($_home_forum_ids)) {
		$fids = array_intersect($fids, $_home_forum_ids);
		$fids = array_values($fids);
	}
	// 首页普通帖子总数（排除置顶帖 top=0 + 已删除 is_deleted=0）：60s 短缓存
	// 必须与 threadlist 的 cond 保持一致，否则 count 虚高导致分页错乱
	// （thread__find 会自动加 is_deleted=0，但 thread_count 不会，需显式传入）
	$_count_cache_key = 'index_thread_count_' . md5(implode(',', $fids)) . '_' . $gid;
	$totalnum = CacheHelper::remember($_count_cache_key, 60, function() use ($fids, $gid) {
		if($gid == 0 || $gid > 2) {
			return thread_count(array('fid' => $fids, 'is_deleted' => 0, 'top' => 0, 'audit_status' => 1));
		} else {
			return thread_count(array('fid' => $fids, 'is_deleted' => 0, 'top' => 0));
		}
	});

	// page 越界自动跳转最后一页（批量删除/移动后当前页可能超出总页数）
	$totalpage = $totalnum > 0 ? ceil($totalnum / $pagesize) : 1;
	if($page > $totalpage) {
		$last_page = max(1, $totalpage);
		header('Location: ' . str_replace('{page}', $last_page, url("$route-{page}", array('order' => $order))));
		exit;
	}

	$pagination = pagination(url("$route-{page}", array('order' => $order)), $totalnum, $page, $pagesize);

	// hook thread_find_by_fids_before.php
	// 最热排序：直接用 ORDER BY views DESC（与版块页一致），修正之前的语义错误
	$_list_order = ($order == 'hot') ? 'views' : $order;

	// 首页帖子列表 60s 短缓存，避免高并发下频繁查库
	// 使用 CacheHelper::remember 简化缓存读写，核心代码前缀 'core'
	// 缓存键包含 order/page/gid/fids，确保不同用户组/排序/页码独立缓存
	$_list_cache_key = 'index_tl_' . $_list_order . '_' . $page . '_' . $gid . '_' . md5(implode(',', $fids));
	$threadlist = CacheHelper::remember($_list_cache_key, 60, function() use ($fids, $page, $pagesize, $_list_order) {
		return thread_find_by_fids($fids, $page, $pagesize, $_list_order, FALSE);
	});
}

// 置顶区始终显示（不受排序/翻页影响）：首页只显示全局置顶
// 使用缓存版（全站置顶 fid=0，缓存 300 秒，置顶变化时主动失效）
$toplist = thread_top_find_cache();
thread_list_access_filter($toplist, $gid);

// 过滤没有权限访问的主题 / filter no permission thread
thread_list_access_filter($threadlist, $gid);

// SEO
$header['title'] = $conf['sitename']; 				// site title
$header['keywords'] = ''; 					// site keyword
$header['description'] = $conf['sitebrief']; 			// site description
$_SESSION['fid'] = 0;

// hook index_end.php

include _include(APP_PATH.'view/htm/index.htm');

?>