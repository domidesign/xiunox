<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook forum_index_start.php

// 版块总览页面
$header['title'] = lang('forum_overview') . ' - ' . $conf['sitename'];
// keywords/description 用全局默认值（site_keywords/site_description），由 index.inc.php 初始化
// 专门的 SEO 插件可通过 hook forum_index_start.php 覆盖
// SEO: canonical
$header['canonical'] = absolute_url(url('forum_index'));
$header['og_type'] = 'website';
$_SESSION['fid'] = 0;

// 获取版块树形数据
include _include(APP_PATH.'service/ForumService.php');
$_forumService = new ForumService($_SERVER['db']);
$_forumTree = $_forumService->getForumTree();

// 获取用户关注状态
$_followStatus = array();
if(!empty($uid)) {
    $_allFids = array();
    foreach($_forumTree as $_forum) {
        $_allFids[] = $_forum['fid'];
        if(!empty($_forum['children'])) {
            foreach($_forum['children'] as $_child) {
                $_allFids[] = $_child['fid'];
            }
        }
    }
    if(!empty($_allFids)) {
        $_followStatus = $_forumService->checkFollowBatch($uid, $_allFids);
    }
}

// hook forum_index_end.php

include _include(APP_PATH.'view/htm/forum_index.htm');

?>
