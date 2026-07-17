<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'list');

// hook admin_attach_start.php

if($action == 'list') {
    // 附件列表页面

    $page = param('page', 1);
    $pagesize = param('pagesize', 20);
    if(!in_array($pagesize, array(20, 50, 100))) $pagesize = 20;

    // 筛选参数
    $filter_type = param('type_category', '');
    $filter_orphan = param('orphan', '');
    $filter_keyword = param('keyword', '');
    $sort = param('sort', 'date_desc');

    // 构建筛选条件
    $filter = array();
    if(!empty($filter_type)) $filter['type_category'] = $filter_type;
    if($filter_orphan !== '') $filter['orphan'] = intval($filter_orphan);
    if(!empty($filter_keyword)) $filter['keyword'] = $filter_keyword;

    // 排序
    $orderby = array('aid' => -1); // 默认按 aid 降序
    if($sort == 'size_asc') $orderby = array('filesize' => 1);
    elseif($sort == 'size_desc') $orderby = array('filesize' => -1);
    elseif($sort == 'date_asc') $orderby = array('create_date' => 1);
    elseif($sort == 'date_desc') $orderby = array('create_date' => -1);

    // 获取统计数据
    $stats = attach_admin_stats();

    // 获取附件列表
    $attachlist = attach_admin_find($filter, $orderby, $page, $pagesize);

    // 获取总数用于分页
    if(!empty($filter_type) || $filter_orphan !== '' || !empty($filter_keyword)) {
        // 有筛选条件时，使用筛选后的总数（修复全表 count bug）
        $count = attach_admin_count($filter);
    } else {
        $count = $stats['total'];
    }

    // 批量获取用户名
    $uids = array();
    if($attachlist) {
        foreach($attachlist as $attach) {
            $uids[] = $attach['uid'];
        }
        $uids = array_unique(array_filter($uids));
    }
    $users = array();
    if(!empty($uids)) {
        // 批量查询用户，避免 N+1
        $userlist = db_find('user', array('uid'=>$uids), array(), 1, count($uids), 'uid');
        if($userlist) {
            foreach($userlist as $_uid => $_u) {
                $users[$_uid] = $_u['username'];
            }
        }
    }

    // 批量获取关联帖子信息（用于 pid>0 时查 tid）
    $pids = array();
    $tid_from_pid = array();
    if($attachlist) {
        foreach($attachlist as $attach) {
            if($attach['pid'] > 0) {
                $pids[] = $attach['pid'];
            }
        }
        if(!empty($pids)) {
            $pids = array_unique($pids);
            // 批量查询 post，避免 N+1
            $postlist = db_find('post', array('pid'=>$pids), array(), 1, count($pids), 'pid');
            if($postlist) {
                foreach($postlist as $_pid => $_post) {
                    $tid_from_pid[$_pid] = $_post['tid'];
                }
            }
        }
    }

    // 类型选项
    $type_options = array(
        '' => lang('admin_attach_filter_type'),
        'image' => lang('admin_attach_type_image'),
        'video' => lang('admin_attach_type_video'),
        'audio' => lang('admin_attach_type_audio'),
        'document' => lang('admin_attach_type_document'),
        'archive' => lang('admin_attach_type_archive'),
        'other' => lang('admin_attach_type_other'),
    );

    $header['title'] = lang('admin_attach_manage');
    $header['mobile_title'] = lang('admin_attach_manage');

    // hook admin_attach_list_end.php

    include _include(ADMIN_PATH.'view/htm/attach_manage.htm');

} elseif($action == 'delete') {
    // 单个附件删除
    CsrfService::check();

    $aid = param('aid', 0);
    if(empty($aid)) message(-1, lang('data_empty'));

    $attach = attach_read($aid);
    if(empty($attach)) message(-1, lang('item_not_exists', array('item'=>'Attach')));

    $force = param('force', 0);

    if($force) {
        // 强制删除
        $r = attach_admin_force_delete($aid);
    } else {
        // 仅允许删除孤儿附件
        if(!attach_admin_check_orphan($attach)) {
            message(-1, lang('admin_attach_force_delete_confirm'));
        }
        // 删除孤儿附件
        $filename = $attach['orgfilename'];
        attach_delete($aid);
        attach_admin_log('attach_delete', 'attach', strval($aid), $filename);
    }

    message(0, lang('admin_attach_delete_success'));

} elseif($action == 'batch_delete') {
    // 批量清理孤儿附件
    CsrfService::check();

    $deleted = attach_admin_delete_orphans();

    if($deleted == 0) {
        message(-1, lang('admin_attach_no_orphan'));
    }

    message(0, str_replace('{n}', $deleted, lang('admin_attach_clean_success')));

} elseif($action == 'stats') {
    // AJAX 获取统计数据
    $stats = attach_admin_stats();
    message(0, $stats);
}

// hook admin_attach_end.php

?>
