<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook forum_start.php

// 版块关注/取消关注路由
$action = param(1);
if($action === 'follow' || $action === 'unfollow') {
    include _include(APP_PATH.'service/ForumService.php');
    $forumService = new ForumService($_SERVER['db']);

    if($action === 'follow') {
        // 关注版块
        if(empty($uid)) {
            header('Location: ' . url('user-login'));
            exit;
        }
        $fid = intval($_REQUEST['fid'] ?? 0);
        if(empty($fid)) {
            message(-1, '版块ID不能为空');
        }
        $result = $forumService->followForum($uid, $fid);
        message($result['code'], $result['msg'], $result['data'] ?? []);
    } else {
        // 取消关注版块
        if(empty($uid)) {
            header('Location: ' . url('user-login'));
            exit;
        }
        $fid = intval($_REQUEST['fid'] ?? 0);
        if(empty($fid)) {
            message(-1, '版块ID不能为空');
        }
        $result = $forumService->unfollowForum($uid, $fid);
        message($result['code'], $result['msg'], $result['data'] ?? []);
    }
    exit;
}

// 版块关注状态查询（延迟加载）
if($action === 'follow_status') {
    $fid = intval(param(2, 0));
    if(empty($uid)) {
        // 未登录用户显示登录链接
        header('Content-Type: text/html; charset=utf-8');
        echo '<a href="'.url('user-login').'" class="btn btn-sm btn-primary rounded-pill flex-shrink-0"><i class="ti ti-heart me-1"></i>'.lang('follow').'</a>';
        exit;
    }
    $followed = false;
    if(!empty($fid)) {
        $followed = !empty(forum_follow_read($uid, $fid));
    }
    header('Content-Type: text/html; charset=utf-8');
    if($followed) {
        echo '<button class="btn btn-sm btn-outline-secondary rounded-pill flex-shrink-0" hx-post="'.url('forum-unfollow-'.$fid).'" hx-target="this" hx-swap="outerHTML" hx-optimistic><i class="ti me-1 ti-heart-filled"></i><span>'.lang('followed').'</span></button>';
    } else {
        echo '<button class="btn btn-sm btn-primary rounded-pill flex-shrink-0" hx-post="'.url('forum-follow-'.$fid).'" hx-target="this" hx-swap="outerHTML" hx-optimistic><i class="ti me-1 ti-heart"></i><span>'.lang('follow').'</span></button>';
    }
    exit;
}

$fid = param(1, 0);
$page = param(2, 1);
$orderby = param('orderby');
$extra = array(); // 给插件预留

$active = 'default';
!in_array($orderby, array('tid', 'lastpid', 'posts', 'views', 'digest', 'hot', 'follow')) AND $orderby = 'lastpid';
$extra['orderby'] = $orderby;

// 读取视图模式
$view_mode = param('view', '');
if(empty($view_mode)) $view_mode = isset($_COOKIE['bbs_view_mode']) ? $_COOKIE['bbs_view_mode'] : 'list';
if(!in_array($view_mode, array('list', 'timeline', 'waterfall'))) $view_mode = 'list';

$forum = forum_read($fid);
empty($forum) AND error_page(404, lang('forum_not_exists'));
forum_access_user($fid, $gid, 'allowread') OR message(-1, lang('insufficient_visit_forum_privilege'));
$pagesize = $conf['pagesize'];
$fup_forum = $forum['fup'] ? forum_read($forum['fup']) : array();

// hook forum_top_list_before.php

$toplist = $page == 1 ? thread_top_find($fid) : array();
if(!empty($toplist)) {
	thread_list_access_filter($toplist, $gid);
	if($view_mode != 'list') {
		$toplist = thread_list_enrich($toplist);
	}
}

// 从默认的地方读取主题列表
$thread_list_from_default = 1;

// 精华帖子
if($orderby == 'digest') {
    $digest_cond = array('fid' => $fid, 'digest' => array('>' => 0));
    $totalnum = thread_count($digest_cond);
    $pagination = pagination(url("forum-$fid-{page}", $extra), $totalnum, $page, $pagesize);
    $threadlist = thread_find($digest_cond, array('create_date' => -1), $page, $pagesize);
    $thread_list_from_default = 0;
}

// 热门帖子
if($orderby == 'hot') {
    $threadlist = thread_find(array('fid'=>$fid), array('views'=>-1), $page, $pagesize);
    $thread_list_from_default = 0;
}

// 关注用户的帖子
if($orderby == 'follow') {
    if(empty($uid)) {
        $threadlist = array();
    } else {
        $followuids = user_follow_find_following($uid, 1, 100);
        $follow_uids = array();
        if($followuids) {
            foreach($followuids as $f) { $follow_uids[] = $f['follow_uid']; }
        }
        if(empty($follow_uids)) {
            $threadlist = array();
        } else {
            $threadlist = thread_find(array('fid'=>$fid, 'uid'=>$follow_uids), array('lastpid'=>-1), $page, $pagesize);
        }
    }
    $thread_list_from_default = 0;
}

// hook forum_thread_list_before.php

if($thread_list_from_default) {
	$pagination = pagination(url("forum-$fid-{page}", $extra), $forum['threads'], $page, $pagesize);
	$threadlist = thread_find_by_fid($fid, $page, $pagesize, $orderby);
}

$header['title'] = $forum['seo_title'] ? $forum['seo_title'] : $forum['name'].'-'.$conf['sitename'];
$header['mobile_title'] = $forum['name'];
$header['mobile_link'] = url("forum-$fid");
$header['keywords'] = '';
$header['description'] = $forum['brief'];

$_SESSION['fid'] = $fid;

// 版块关注状态改为延迟加载（AJAX）
$_forum_followed = false; // 默认未关注，通过 htmx 异步获取

// 为朋友圈/瀑布流视图加载内容预览和缩略图
if($view_mode != 'list') {
	$threadlist = thread_list_enrich($threadlist);
}

// hook forum_end.php

include _include(APP_PATH.'view/htm/forum.htm');

?>