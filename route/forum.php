<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook forum_start.php

// 版块关注/取消关注路由
$action = param(1);
if($action === 'follow' || $action === 'unfollow') {
    if($method != 'POST') {
        if(is_htmx_request()) {
            header('HTTP/1.1 405 Method Not Allowed');
            exit;
        }
        message(-1, lang('method_error'));
    }
    CsrfService::check();
    include _include(APP_PATH.'service/ForumService.php');
    $forumService = new ForumService($_SERVER['db']);

    if($action === 'follow') {
        // 关注版块
        if(empty($uid)) {
            header('Location: ' . user_login_url());
            exit;
        }
        $fid = intval(param(2, 0));
        if(empty($fid)) {
            message(-1, lang('forum_id_empty'));
        }
        $result = $forumService->followForum($uid, $fid);
        // 返回纯 HTML 片段供 htmx 替换按钮
        header('Content-Type: text/html; charset=utf-8');
        if($result['code'] == 0) {
            // 关注成功，返回"已关注"按钮（点击可取消关注），并同步更新成员数
            $follows = intval($result['data']['follows'] ?? 0);
            echo '<button class="btn btn-sm btn-outline-secondary rounded-pill flex-shrink-0" hx-post="'.forum_unfollow_url($fid).'" hx-target="this" hx-swap="outerHTML"><i class="ti me-1 ti-heart-filled"></i><span>'.lang('followed').'</span></button>';
            echo '<a id="forum-follows-count" hx-swap-oob="true" href="'.forum_followers_url($fid).'" class="text-decoration-none">'.$follows.'</a>';
        } else {
            // 失败时返回原"关注"按钮
            echo '<button class="btn btn-sm btn-primary rounded-pill flex-shrink-0" hx-post="'.forum_follow_url($fid).'" hx-target="this" hx-swap="outerHTML"><i class="ti me-1 ti-heart"></i><span>'.lang('follow').'</span></button>';
        }
    } else {
        // 取消关注版块
        if(empty($uid)) {
            header('Location: ' . user_login_url());
            exit;
        }
        $fid = intval(param(2, 0));
        if(empty($fid)) {
            message(-1, lang('forum_id_empty'));
        }
        $result = $forumService->unfollowForum($uid, $fid);
        // 返回纯 HTML 片段供 htmx 替换按钮
        header('Content-Type: text/html; charset=utf-8');
        if($result['code'] == 0) {
            // 取消关注成功，返回"关注"按钮（点击可再次关注），并同步更新成员数
            $follows = intval($result['data']['follows'] ?? 0);
            echo '<button class="btn btn-sm btn-primary rounded-pill flex-shrink-0" hx-post="'.forum_follow_url($fid).'" hx-target="this" hx-swap="outerHTML"><i class="ti me-1 ti-heart"></i><span>'.lang('follow').'</span></button>';
            echo '<a id="forum-follows-count" hx-swap-oob="true" href="'.forum_followers_url($fid).'" class="text-decoration-none">'.$follows.'</a>';
        } else {
            // 失败时返回原"已关注"按钮
            echo '<button class="btn btn-sm btn-outline-secondary rounded-pill flex-shrink-0" hx-post="'.forum_unfollow_url($fid).'" hx-target="this" hx-swap="outerHTML"><i class="ti me-1 ti-heart-filled"></i><span>'.lang('followed').'</span></button>';
        }
    }
    exit;
}

// 版块关注状态查询（延迟加载）
if($action === 'follow_status') {
    $fid = intval(param(2, 0));
    if(empty($uid)) {
        // 未登录用户显示登录链接
        header('Content-Type: text/html; charset=utf-8');
        echo '<a href="'.user_login_url().'" class="btn btn-sm btn-primary rounded-pill flex-shrink-0"><i class="ti ti-heart me-1"></i>'.lang('follow').'</a>';
        exit;
    }
    $followed = false;
    if(!empty($fid)) {
        $followed = !empty(forum_follow_read($uid, $fid));
    }
    header('Content-Type: text/html; charset=utf-8');
    if($followed) {
        echo '<button class="btn btn-sm btn-outline-secondary rounded-pill flex-shrink-0" hx-post="'.forum_unfollow_url($fid).'" hx-target="this" hx-swap="outerHTML"><i class="ti me-1 ti-heart-filled"></i><span>'.lang('followed').'</span></button>';
    } else {
        echo '<button class="btn btn-sm btn-primary rounded-pill flex-shrink-0" hx-post="'.forum_follow_url($fid).'" hx-target="this" hx-swap="outerHTML"><i class="ti me-1 ti-heart"></i><span>'.lang('follow').'</span></button>';
    }
    exit;
}

// 版块成员列表页
if($action === 'followers') {
    $fid = intval(param(2, 0));
    $page = param(3, 1);
    $pagesize = 36;

    $forum = forum_read($fid);
    empty($forum) AND error_page(404, lang('forum_not_exists'));
    forum_access_user($fid, $gid, 'allowread') OR message(-1, lang('insufficient_visit_forum_privilege'));

    $totalnum = forum_follow_count($fid);
    $pagination = pagination(route_url('forum_followers_page', array('fid'=>$fid)), $totalnum, $page, $pagesize);

    $_all_members = array();
    $_forum_followers = forum_follow_find_by_fid($fid, $page, $pagesize);
    if($_forum_followers) {
        // 提取 uid 列表，批量查询用户数据，避免循环内逐条 user_read_cache 造成 N+1 查询
        $_follower_uids = array();
        foreach($_forum_followers as $_f) {
            $_uid = intval($_f['uid']);
            if($_uid > 0) {
                $_follower_uids[$_uid] = $_uid;
            }
        }
        $_users_map = user_find_by_uids(implode(',', $_follower_uids));
        foreach($_forum_followers as $_f) {
            $_uid = intval($_f['uid']);
            if(!isset($_users_map[$_uid])) continue;
            $_u = $_users_map[$_uid];
            $_all_members[] = array(
                'uid' => $_u['uid'],
                'username' => $_u['username'],
                'display_name' => !empty($_u['display_name']) ? $_u['display_name'] : $_u['username'],
                'avatar_url' => !empty($_u['avatar_url']) ? $_u['avatar_url'] : default_avatar_url(),
                'threads' => intval($_u['threads']),
                'posts' => intval($_u['posts']),
                'create_date' => intval($_f['create_date']),
            );
        }
    }

    $header['title'] = $forum['name'].' - '.lang('forum_members_title');
    $header['mobile_title'] = $forum['name'];
    $header['mobile_link'] = forum_url($fid);

    include _include(APP_PATH.'view/htm/forum_followers.htm');
    exit;
}

$fid = param(1, 0);
$page = param(2, 1);
$orderby = param('orderby');
$extra = array(); // 给插件预留

$active = 'default';
!in_array($orderby, array('tid', 'lastpid', 'posts', 'views', 'digest', 'hot', 'follow')) AND $orderby = 'lastpid';
$extra['orderby'] = $orderby;

$forum = forum_read($fid);
empty($forum) AND error_page(404, lang('forum_not_exists'));
forum_access_user($fid, $gid, 'allowread') OR message(-1, lang('insufficient_visit_forum_privilege'));

// 校准版块关注成员数：如果 forum.follows 与实际不一致则修正
$real_follows = forum_follow_count($fid);
if(intval($forum['follows']) !== intval($real_follows)) {
    global $db;
    $tablepre = $db->tablepre;
    db_exec("UPDATE {$tablepre}forum SET follows='" . intval($real_follows) . "' WHERE fid='$fid'");
    $forum['follows'] = intval($real_follows);
}
$pagesize = $conf['pagesize'];
// 父版块优先从全局 $forumlist 缓存读取，避免函数调用开销；缓存缺失时回退到 forum_read
global $forumlist;
$fup_forum = $forum['fup'] ? (isset($forumlist[$forum['fup']]) ? $forumlist[$forum['fup']] : forum_read($forum['fup'])) : array();

// hook forum_top_list_before.php

// 置顶区始终显示（不受排序/翻页影响）：全局置顶(top=3) + 版块置顶(top=1)
$toplist3 = thread_top_find(0);              // 全局置顶
$toplist1 = $fid ? thread_top_find($fid) : array(); // 版块置顶
$toplist = $toplist3 + $toplist1;            // PHP + 按 tid 去重合并
if(!empty($toplist)) {
	thread_list_access_filter($toplist, $gid);
}

// 从默认的地方读取主题列表
$thread_list_from_default = 1;

// 精华帖子
if($orderby == 'digest') {
    $digest_cond = array('fid' => $fid, 'digest' => array('>' => 0), 'is_deleted' => 0, 'top' => 0);
    if($gid == 0 || $gid > 2) {
        $digest_cond['audit_status'] = 1;
    }
    $totalnum = thread_count($digest_cond);
    $pagination = pagination(route_url('forum_page', array('fid'=>$fid), $extra), $totalnum, $page, $pagesize);
    $threadlist = thread_find($digest_cond, array('create_date' => -1), $page, $pagesize);
    $thread_list_from_default = 0;
}

// 热门帖子
if($orderby == 'hot') {
    // 与默认分支保持一致的 cond：排除已删除/置顶/待审帖
    $hot_cond = array('fid'=>$fid, 'is_deleted'=>0, 'top'=>0);
    if($gid == 0 || $gid > 2) {
        $hot_cond['audit_status'] = array('!=' => 0);
    }
    $threadlist = thread_find($hot_cond, array('views'=>-1), $page, $pagesize);
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
            $follow_cond = array('fid'=>$fid, 'uid'=>$follow_uids, 'is_deleted'=>0, 'top'=>0);
            if($gid == 0 || $gid > 2) {
                $follow_cond['audit_status'] = array('!=' => 0);
            }
            $threadlist = thread_find($follow_cond, array('lastpid'=>-1), $page, $pagesize);
        }
    }
    $thread_list_from_default = 0;
}

// hook forum_thread_list_before.php

if($thread_list_from_default) {
	// totalnum 排除置顶帖（top=0），与 threadlist 的 cond 保持一致，确保分页数精确
	// 非管理员只显示审核通过的帖子（排除待审和驳回）
	if($gid == 0 || $gid > 2) {
		// 版块普通帖子总数 60s 短缓存，使用 CacheHelper::remember
		// 显式传 is_deleted=0（thread_count 不会自动加，但 thread__find 会自动加，需保持一致）
		$_forum_count_key = 'forum_tc_' . $fid . '_' . $gid;
		$totalnum = CacheHelper::remember($_forum_count_key, 60, function() use ($fid) {
			return thread_count(array('fid' => $fid, 'is_deleted' => 0, 'top' => 0, 'audit_status' => 1));
		});
	} else {
		// 管理员也用 thread_count 排除置顶帖（加 60s 缓存避免每次查库）
		$_forum_count_key = 'forum_tc_' . $fid . '_' . $gid;
		$totalnum = CacheHelper::remember($_forum_count_key, 60, function() use ($fid) {
			return thread_count(array('fid' => $fid, 'is_deleted' => 0, 'top' => 0));
		});
	}

	// page 越界自动跳转最后一页（批量删除/移动后当前页可能超出总页数）
	$totalpage = $totalnum > 0 ? ceil($totalnum / $pagesize) : 1;
	if($page > $totalpage) {
		$last_page = max(1, $totalpage);
		header('Location: ' . forum_page_url($fid, $last_page, $extra));
		exit;
	}

	$pagination = pagination(route_url('forum_page', array('fid'=>$fid), $extra), $totalnum, $page, $pagesize);

	// 版块帖子列表 60s 短缓存，使用 CacheHelper::remember
	// 缓存键包含 fid/orderby/page/gid，确保不同版块/排序/页码/用户组独立缓存
	$_forum_list_key = 'forum_tl_' . $fid . '_' . $orderby . '_' . $page . '_' . $gid;
	$threadlist = CacheHelper::remember($_forum_list_key, 60, function() use ($fid, $page, $pagesize, $orderby) {
		return thread_find_by_fid($fid, $page, $pagesize, $orderby);
	});
	thread_list_access_filter($threadlist, $gid);
}

$header['title'] = $forum['seo_title'] ? $forum['seo_title'] : $forum['name'].'-'.$conf['sitename'];
$header['mobile_title'] = $forum['name'];
$header['mobile_link'] = forum_url($fid);
$header['keywords'] = '';
// SEO: description 清理 HTML/多余空格，截断到 80 字（SERP 显示优化）
$_forum_desc = trim(preg_replace('/\s+/', ' ', strip_tags($forum['brief'])));
$header['description'] = $_forum_desc !== '' ? mb_substr($_forum_desc, 0, 80, 'UTF-8') : $forum['name'];
// SEO: canonical 指向第一页（避免分页重复内容）
// ponytail: 分页页面 forum-1-2.htm 的 canonical 指向 forum-1.htm，权重集中到第一页
$header['canonical'] = absolute_url(forum_url($fid));
$header['og_type'] = 'website';
$header['og_image'] = '';
// SEO: rel=prev/next 分页标记（百度仍支持，Google 已弃用但不报错）
$_total_pages = isset($total) ? max(1, ceil($total / $pagesize)) : 1;
if($page > 1) {
	$header['prev_url'] = absolute_url($page == 2 ? forum_url($fid) : forum_page_url($fid, $page - 1));
}
if($page < $_total_pages) {
	$header['next_url'] = absolute_url(forum_page_url($fid, $page + 1));
}
// SEO: JSON-LD BreadcrumbList（首页 > 版块）
$header['json_ld'] = array(
	'@context' => 'https://schema.org',
	'@type' => 'BreadcrumbList',
	'itemListElement' => array(
		array('@type' => 'ListItem', 'position' => 1, 'name' => $conf['sitename'], 'item' => http_url_path()),
		array('@type' => 'ListItem', 'position' => 2, 'name' => $forum['name'], 'item' => $header['canonical']),
	),
);

$_SESSION['fid'] = $fid;

// 版块关注状态改为延迟加载（AJAX）
$_forum_followed = false; // 默认未关注，通过 htmx 异步获取

// hook forum_end.php

include _include(APP_PATH.'view/htm/forum.htm');

?>