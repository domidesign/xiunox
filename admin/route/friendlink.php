<?php

!defined('DEBUG') AND exit('Access Denied');

$action = param(1, 'list');

// hook admin_friendlink_start.php

if($action == 'list') {

	// hook admin_friendlink_list_get_post.php

	if($method == 'GET') {

		$header['title'] = lang('friendlink');
		$header['mobile_title'] = lang('friendlink');

		// 安全查询：表不存在时返回空数组
		$friendlinks = @db_find('friendlink', array('type'=>0), array('rank'=>-1), 1, 100, 'linkid');
		if(!$friendlinks) $friendlinks = array();

		// 待审核申请
		$pending = @db_find('friendlink', array('type'=>1), array('create_date'=>-1), 1, 100, 'linkid');
		if(!$pending) $pending = array();

		// hook admin_friendlink_list_get_end.php

		include _include(ADMIN_PATH."view/htm/friendlink_list.htm");

	} else {

		CsrfService::check();

		$linkidarr = param('linkid', array(0));
		$namearr = param('name', array(''));
		$urlarr = param('url', array(''), false);
		$faviconarr = param('favicon', array(''), false);
		$rankarr = param('rank', array(0));

		// hook admin_friendlink_list_post_start.php

		// 先删除：在新增之前，找出提交中已不存在的 linkid
		$submitted_ids = array_filter(array_map('intval', $linkidarr), function($v) { return $v > 0; });
		$existing = @db_find('friendlink', array('type'=>0), array(), 1, 100, 'linkid');
		if($existing) {
			$existing_ids = array_keys($existing);
			$delete_ids = array_diff($existing_ids, $submitted_ids);
			foreach($delete_ids as $did) {
				db_delete('friendlink', array('linkid'=>$did));
			}
		}

		// 再创建/更新
		foreach($linkidarr as $k=>$v) {
			$arr = array(
				'name' => array_value($namearr, $k, ''),
				'url' => array_value($urlarr, $k, ''),
				'favicon' => array_value($faviconarr, $k, ''),
				'rank' => intval(array_value($rankarr, $k, 0)),
			);

			if(!empty($arr['name']) && !empty($arr['url'])) {
				if(intval($v) == 0) {
					// 新增：linkid 为 0 表示新行
					$arr['create_date'] = time();
					$arr['type'] = 0;
					db_create('friendlink', $arr);
				} else {
					// 更新
					db_update('friendlink', array('linkid'=>intval($v)), $arr);
				}
			}
		}

		// 清除缓存
		cache_delete('sidebar_friendlinks');

		admin_log_create('friendlink_update', 'friendlink', '', '更新友情链接');

		// hook admin_friendlink_list_post_end.php

		message(0, lang('save_successfully'));
	}

}

// 审核操作：通过/拒绝
elseif($action == 'audit') {

	if($method == 'GET') {
		$linkid = param(2, 0);
		$op = param(3, ''); // approve / reject

		if($linkid > 0 && $op) {
			$link = db_read('friendlink', array('linkid'=>$linkid));
			if($link) {
				if($op == 'approve') {
					// 通过：type 改为 0（正式链接）
					db_update('friendlink', array('linkid'=>$linkid), array('type'=>0));
				} elseif($op == 'reject') {
					// 拒绝：删除该记录
					db_delete('friendlink', array('linkid'=>$linkid));
				}
				cache_delete('sidebar_friendlinks');
				admin_log_create('friendlink_update', 'friendlink', strval($linkid), ($op == 'approve' ? '通过' : '拒绝') . '友情链接申请');
			}
		}
		message(0, lang('save_successfully'));
	}

	message(-1, lang('illegal_request'));
}

// hook admin_friendlink_end.php

?>
