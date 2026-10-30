<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);

// 不允许删除的版块 / system keeped forum
$system_forum = array(1);

// hook admin_forum_start.php

if(empty($action) || $action == 'list') {
	
	// hook admin_forum_list_get_post.php
	
	if($method == 'GET') {
		
		// hook admin_forum_list_get_start.php
		
		$header['title']        = lang('forum_admin');
		$header['mobile_title'] = lang('forum_admin');
	
		$maxfid = forum_maxid();
		
		// hook admin_forum_list_get_end.php
		
		include _include(ADMIN_PATH."view/htm/forum_list.htm");
	
	} elseif($method == 'POST') {
		
		CsrfService::check();
		
		$fidarr = param('fid', array(0));
		$namearr = param('name', array(''));
		$rankarr = param('rank', array(0));
		$iconarr = param('icon', array(''));
		$typearr = param('type', array(0));
		$fuparr = param('fup', array(0));
		
		// hook admin_forum_list_post_start.php
		
		$arrlist = array();
		foreach($fidarr as $k=>$v) {
			$type_val = array_value($typearr, $k, 0);
			$fup_val = $type_val == 1 ? 0 : array_value($fuparr, $k, 0);
			// icon - 存储 Tabler Icon 类名，合并到主更新数组，避免二次更新
			$icon_val = array_value($iconarr, $k, '');
			$arr = array(
				'fid'=>$k,
				'name'=>array_value($namearr, $k),
				'rank'=>array_value($rankarr, $k),
				'type'=>$type_val,
				'fup'=>$fup_val,
				'icon'=>$icon_val,
			);
			
			if(!isset($forumlist[$k])) {
				// hook admin_forum_list_add_before.php
				forum_create($arr);
			} else {
				// hook admin_forum_list_update_before.php
				forum_update($k, $arr);
			}
			
			// hook admin_forum_list_post_loop_end.php
		}
		
		// 删除 / delete
		$deletearr = array_diff_key($forumlist, $fidarr);
		foreach($deletearr as $k=>$v) {
			if(in_array($k, $system_forum)) continue;
			// hook admin_forum_list_delete_before.php
			forum_delete($k);
			// hook admin_forum_list_delete_end.php
		}
		
		forum_list_cache_delete();

		admin_log_create('forum_update', 'forum', '', lang('admin_log_forum_batch_update'));

		// hook admin_forum_list_post_end.php



		message(0, lang('save_successfully'));
	}

} elseif($action == 'create') {

	// hook admin_forum_create_get_post.php

	if($method == 'GET') {

		$header['title']        = lang('admin_add_forum');
		$header['mobile_title'] = lang('admin_add_forum');

		// hook admin_forum_create_get_start.php

		// 获取分区列表用于上级版块下拉
		$categories = forum_find_categories();

		$input = array();
		$input['name'] = form_text('name', '');
		$input['rank'] = form_text('rank', 0);
		$input['brief'] = form_textarea('brief', '', '100%', 80);
		$input['announcement'] = form_textarea('announcement', '', '100%', 80);

		$type_options = array(0=>lang('admin_forum'), 1=>lang('admin_category'));
		$input['type'] = form_select('type', $type_options, 0);

		$category_options = array(0=>lang('admin_option_none'));
		foreach($categories as $cat) {
			$category_options[$cat['fid']] = $cat['name'];
		}
		$input['fup'] = form_select('fup', $category_options, 0);

		// hook admin_forum_create_get_end.php

		include _include(ADMIN_PATH."view/htm/forum_create.htm");

	} elseif($method == 'POST') {

		CsrfService::check();

		$name = param('name');
		$type = param('type', 0);
		$fup = param('fup', 0);
		$rank = param('rank', 0);
		$brief = param('brief', '', FALSE);
		$announcement = param('announcement', '', FALSE);

		// 分区类型无上级
		if($type == 1) $fup = 0;

		// 版块类型必须归属分区，避免产生无分类版块（孤儿）
		if($type == 0 && $fup == 0) {
			message(-1, lang('admin_forum_parent_required'));
		}

		// hook admin_forum_create_post_start.php

		$arr = array(
			'name' => $name,
			'type' => $type,
			'fup' => $fup,
			'rank' => $rank,
			'brief' => $brief,
			'announcement' => $announcement,
			'icon' => '',
		);

		// 先创建版块获取 fid
		$r = forum_create($arr);
		if($r === FALSE) {
			message(-1, lang('create_failed'));
		}

		// 获取新创建的 fid
		$fid = forum_maxid();

		// 处理图标上传
		if(isset($_FILES['icon']) && $_FILES['icon']['error'] == 0) {
			$icon_file = $_FILES['icon'];
			$allowed_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp');
			$max_size = 2 * 1024 * 1024; // 2MB

			// 验证文件大小
			if($icon_file['size'] > $max_size) {
				message(-1, lang('forum_icon_too_large'));
			}

			// 验证文件类型
			$ext = strtolower(pathinfo($icon_file['name'], PATHINFO_EXTENSION));
			if(!in_array($ext, $allowed_exts)) {
				message(-1, lang('forum_icon_format_unsupported'));
			}

			// 真实 MIME 校验，行为受 security_upload_strict_mime 控制
			$icon_mimes = array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp');
			if(!class_exists('AttachmentService')) {
				include APP_PATH.'service/AttachmentService.php';
			}
			if(!AttachmentService::verifyUploadMime($icon_file['tmp_name'], $icon_mimes, true)) {
				message(-1, lang('file_mime_not_allowed'));
			}

			// 创建上传目录
			$upload_dir = APP_PATH.'upload/forum/';
			if(!is_dir($upload_dir)) {
				mkdir($upload_dir, 0777, TRUE);
			}

			// 保存图标文件
			$icon_path = 'upload/forum/'.$fid.'.'.$ext;
			$save_path = APP_PATH.$icon_path;
			if(move_uploaded_file($icon_file['tmp_name'], $save_path)) {
				forum_update($fid, array('icon' => $icon_path));
			}
		}

		forum_list_cache_delete();

		admin_log_create('forum_create', 'forum', strval($fid), lang('admin_log_forum_create') . $name);

		// hook admin_forum_create_post_end.php

		message(0, lang('create_successfully'));
	}

} elseif($action == 'update') {

	$_fid = param(2, 0);
	$_forum = forum_read($_fid);
	empty($_forum) AND message(-1, lang('forum_not_exists'));

	// hook admin_forum_update_get_post.php
	
	if($method == 'GET') {
		
		$header['title']        = lang('forum_edit');
		$header['mobile_title'] = lang('forum_edit');
	
		// hook admin_forum_update_get_start.php
		
		$accesslist = forum_access_find_by_fid($_fid);
		
		if(empty($accesslist)) {
			foreach($grouplist as $group) {
				$accesslist[$group['gid']] = $group; // 字段名相同，直接覆盖。 / same field, directly overwrite
			}
		} else {
			foreach($accesslist as &$access) {
				$access['name'] = $grouplist[$access['gid']]['name']; // 字段名相同，直接覆盖。 / same field, directly overwrite
			}
		}
		array_htmlspecialchars($_forum);
		
		$input = array();
		$input['name'] = form_text('name', $_forum['name']);
		$input['rank'] = form_text('rank', $_forum['rank']);
		$input['brief'] = form_textarea('brief', $_forum['brief'], '100%', 80);
		$input['announcement'] = form_textarea('announcement', $_forum['announcement'], '100%', 80);
		$input['accesson'] = form_checkbox('accesson', $_forum['accesson']);
		$input['modnames'] = form_text('modnames', user_ids_to_names($_forum['moduids']));
		
		$type_options = array(0=>lang('admin_forum'), 1=>lang('admin_category'));
		$input['type'] = form_select('type', $type_options, isset($_forum['type']) ? $_forum['type'] : 0);
		
		$category_options = array(0=>lang('admin_none'));
		$categories = forum_find_categories();
		foreach($categories as $cat) {
			if($cat['fid'] != $_fid) {
				$category_options[$cat['fid']] = $cat['name'];
			}
		}
		$input['fup'] = form_select('fup', $category_options, isset($_forum['fup']) ? $_forum['fup'] : 0);
		$input['icon'] = form_text('icon', isset($_forum['icon']) ? $_forum['icon'] : '');

		// hook admin_forum_update_get_end.php
		
		
		include _include(ADMIN_PATH."view/htm/forum_update.htm");
	
	} elseif($method == 'POST') {	
		
		CsrfService::check();
		
		$name = param('name');
		$rank = param('rank', 0);
		$brief = param('brief', '', FALSE);
		$announcement = param('announcement', '', FALSE);
		$modnames = param('modnames');
		$accesson = param('accesson', 1);
		$moduids = user_names_to_ids($modnames);
		$type = param('type', 0);
		$fup = param('fup', 0);

		if($type == 1) $fup = 0;

		// 版块类型必须归属分区，避免产生无分类版块（孤儿）
		if($type == 0 && $fup == 0) {
			message(-1, lang('admin_forum_parent_required'));
		}

		// hook admin_forum_update_post_start.php

		$arr = array (
			'name' => $name,
			'rank' => $rank,
			'brief' => $brief,
			'announcement' => $announcement,
			'moduids' => $moduids,
			'accesson' => $accesson,
			'type' => $type,
			'fup' => $fup,
		);

		// 处理图标上传：仅在成功上传时才更新 icon 字段，避免空字符串覆盖已有图标
		if(isset($_FILES['icon']) && $_FILES['icon']['error'] == 0) {
			$icon_file = $_FILES['icon'];
			$allowed_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp');
			$max_size = 2 * 1024 * 1024; // 2MB

			// 验证文件大小
			if($icon_file['size'] <= $max_size) {
				// 验证文件类型
				$ext = strtolower(pathinfo($icon_file['name'], PATHINFO_EXTENSION));
				if(in_array($ext, $allowed_exts)) {
					// 真实 MIME 校验，行为受 security_upload_strict_mime 控制
				$icon_mimes = array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp');
				if(!class_exists('AttachmentService')) {
					include APP_PATH.'service/AttachmentService.php';
				}
				if(!AttachmentService::verifyUploadMime($icon_file['tmp_name'], $icon_mimes, true)) {
					message(-1, lang('file_mime_not_allowed'));
				}

					// 创建上传目录
					$upload_dir = APP_PATH.'upload/forum/';
					if(!is_dir($upload_dir)) {
						mkdir($upload_dir, 0777, TRUE);
					}

					// 保存图标文件
					$icon_path = 'upload/forum/'.$_fid.'.'.$ext;
					$save_path = APP_PATH.$icon_path;
					if(move_uploaded_file($icon_file['tmp_name'], $save_path)) {
						$arr['icon'] = $icon_path;
					}
				}
			}
		}

		// hook admin_forum_update_post_before.php
		
		forum_update($_fid, $arr);
		
		// 权限默认开启，始终保存权限设置 - 批量替换，避免 N+1
		$allowread = param('allowread', array(0));
		$allowthread = param('allowthread', array(0));
		$allowpost = param('allowpost', array(0));
		$allowattach = param('allowattach', array(0));
		$allowdown = param('allowdown', array(0));
		$allowthreadaudit = param('allowthreadaudit', array(0));
		$allowpostaudit = param('allowpostaudit', array(0));
		$values = array();
		foreach($grouplist as $_gid=>$v) {
			$values[] = "("
				. intval($_fid) . "," . intval($_gid) . ","
				. intval(array_value($allowread, $_gid, 0)) . ","
				. intval(array_value($allowthread, $_gid, 0)) . ","
				. intval(array_value($allowpost, $_gid, 0)) . ","
				. intval(array_value($allowattach, $_gid, 0)) . ","
				. intval(array_value($allowdown, $_gid, 0)) . ","
				. intval(array_value($allowthreadaudit, $_gid, 0)) . ","
				. intval(array_value($allowpostaudit, $_gid, 0))
				. ")";
		}
		if(!empty($values)) {
			global $db;
			$sql = "INSERT INTO {$db->tablepre}forum_access
				(fid, gid, allowread, allowthread, allowpost, allowattach, allowdown, allowthreadaudit, allowpostaudit)
				VALUES " . implode(',', $values) . "
				ON DUPLICATE KEY UPDATE
				allowread = VALUES(allowread),
				allowthread = VALUES(allowthread),
				allowpost = VALUES(allowpost),
				allowattach = VALUES(allowattach),
				allowdown = VALUES(allowdown),
				allowthreadaudit = VALUES(allowthreadaudit),
				allowpostaudit = VALUES(allowpostaudit)";
			db_exec($sql);
		}
		
		
		
		// hook admin_forum_update_post_end.php
		
		forum_list_cache_delete();

		admin_log_create('forum_update', 'forum', strval($_fid), lang('admin_log_forum_update') . $name);

		message(0, lang('edit_sucessfully'));	
	}

// 废弃
} elseif($action == 'getname') {

	$uids = xn_urldecode(param(2));
	$arr = explode(',', $uids);
	$names = array();
	$err = '';

	// hook admin_forum_getname_start.php

	// 批量查询用户，避免 N+1
	$uid_list = array();
	foreach($arr as $_uid) {
		$_uid = intval($_uid);
		if(empty($_uid)) continue;
		$uid_list[] = $_uid;
	}
	$users = array();
	if(!empty($uid_list)) {
		$userlist = db_find('user', array('uid'=>$uid_list), array(), 1, count($uid_list), 'uid');
		if($userlist) {
			$users = $userlist;
		}
	}
	foreach($uid_list as $_uid) {
		$_user = $users[$_uid] ?? array();
		if(empty($_user)) { $err .= lang('item_not_exists', array('item'=>$_uid)); continue; }
		if($_user['gid'] > 4) { $err .= lang('item_not_moderator', array('item'=>$_uid));  continue; }
		$names[] = $_user['username'];
	}
	$s = implode(',', $names);
	$err AND message(-1, $err);

	// hook admin_forum_getname_end.php

	message(0, $s);

} elseif($action == 'delete') {

	if($method != 'POST') message(-1, 'Method Error.');

	CsrfService::check();

	$_fid = param(2, 0);
	$_forum = forum_read($_fid);
	empty($_forum) AND message(-1, lang('forum_not_exists'));
	
	in_array($_fid, $system_forum) AND message(-1, 'Not allowed');;

	// hook admin_forum_delete_start.php

	// forum_delete 已处理级联删除（子版块 fup 清零、帖子删除、权限记录删除）
	forum_delete($_fid);
	
	forum_list_cache_delete();

	admin_log_create('forum_delete', 'forum', strval($_fid), lang('admin_log_forum_delete', array('name'=>$_forum['name'])));

	// hook admin_forum_delete_end.php

	message(0, lang('forum_delete_successfully'));
	
} elseif($action == 'recount') {

	if($method != 'POST') message(-1, 'Method Error.');

	CsrfService::check();

	// hook admin_forum_recount_start.php

	// 按计入口径（审核通过且未软删）实时重算各版块 threads，校准历史漂移；
	// todaythreads 每日定时清零自愈，无需重建（cron.func.php）
	$sql = "UPDATE {$db->tablepre}forum SET threads = (
		SELECT COUNT(*) FROM {$db->tablepre}thread
		WHERE {$db->tablepre}thread.fid = {$db->tablepre}forum.fid AND audit_status = 1 AND is_deleted = 0
	)";
	db_exec($sql);

	// 同步全站统计（口径与建/删/审链路一致：审核通过且未软删；回帖只计非首帖）
	$_recount_total = db_count('thread', array('audit_status'=>1, 'is_deleted'=>0));
	runtime_set('threads', $_recount_total);
	$_recount_posts = db_count('post', array('isfirst'=>0, 'audit_status'=>1, 'is_deleted'=>0));
	runtime_set('posts', $_recount_posts);
	if(function_exists('user_count_real')) {
		runtime_set('users', user_count_real());
	}

	// 校准用户级冗余计数（user.threads / user.posts）：
	// 排行榜（RankService）与用户主页直接消费这两个字段，历史 bug（批量删除二次扣减等）可致漂移且此前无重建入口
	// 用户表为 UPDATE 主表、子查询查 thread/post，不同表，MySQL/MariaDB 均允许
	db_exec("UPDATE {$db->tablepre}user u SET u.threads = (
		SELECT COUNT(*) FROM {$db->tablepre}thread t WHERE t.uid = u.uid AND t.audit_status = 1 AND t.is_deleted = 0
	)");
	db_exec("UPDATE {$db->tablepre}user u SET u.posts = (
		SELECT COUNT(*) FROM {$db->tablepre}post p WHERE p.uid = u.uid AND p.isfirst = 0 AND p.audit_status = 1 AND p.is_deleted = 0
	)");

	// 重建主题回帖数：列表页展示的回复数、thread_format 的 pages 分页、发帖楼层均取 thread.posts，
	// 与 user.posts 共用同一批增减路径，历史漂移需一并校准
	db_exec("UPDATE {$db->tablepre}thread t SET t.posts = (
		SELECT COUNT(*) FROM {$db->tablepre}post p WHERE p.tid = t.tid AND p.isfirst = 0 AND p.audit_status = 1 AND p.is_deleted = 0
	)");

	// 首帖审核状态与主题对齐：历史版本主题通过时未同步 post.audit_status，
	// 而帖子详情按 post.audit_status 过滤，会导致已通过主题的楼主正文对普通用户隐藏
	db_exec("UPDATE {$db->tablepre}post p INNER JOIN {$db->tablepre}thread t ON t.firstpid = p.pid
		SET p.audit_status = 1 WHERE t.audit_status = 1 AND t.is_deleted = 0 AND p.audit_status <> 1");

	// 校准每帖点赞/收藏计数（对账行数）：此前硬删回帖不清 post_like、彻底删除用户不清点赞/收藏行，
	// 均造成 thread.likes / thread.favorites 残留虚高（第三轮全量审计 2026-10-30）
	// thread.likes 对账全部点赞行（软删回帖的点赞行与计数按设计保留，口径天然一致）
	db_exec("UPDATE {$db->tablepre}thread t SET t.likes = (
		SELECT COUNT(*) FROM {$db->tablepre}post_like pl WHERE pl.tid = t.tid
	)");
	db_exec("UPDATE {$db->tablepre}thread t SET t.favorites = (
		SELECT COUNT(*) FROM {$db->tablepre}thread_favorite tf WHERE tf.tid = t.tid
	)");
	// 校准每楼点赞计数（与 thread.likes 同源的历史漂移：硬删回帖不清点赞行的遗留数据）
	db_exec("UPDATE {$db->tablepre}post p SET p.likes = (
		SELECT COUNT(*) FROM {$db->tablepre}post_like pl WHERE pl.pid = p.pid
	)");

	// 校准精华计数（对账"可见精华主题"：审核通过且未软删，与软删递减/恢复回补口径一致）
	db_exec("UPDATE {$db->tablepre}user u SET u.digests = (
		SELECT COUNT(*) FROM {$db->tablepre}thread t WHERE t.uid = u.uid AND t.digest > 0 AND t.audit_status = 1 AND t.is_deleted = 0
	)");
	db_exec("UPDATE {$db->tablepre}forum f SET f.digests = (
		SELECT COUNT(*) FROM {$db->tablepre}thread t WHERE t.fid = f.fid AND t.digest > 0 AND t.audit_status = 1 AND t.is_deleted = 0
	)");
	runtime_set('digests', db_count('thread', array('digest'=>array('>'=>0), 'audit_status'=>1, 'is_deleted'=>0)));

	// 失效 user 缓存（user_read_cache 对非 mysql 驱动持久缓存 "user-$uid"，裸 SQL 更新不会走 user_update 的缓存删除）
	// cache_delete_prefix 仅 Redis 驱动生效；file 驱动的残留计数展示会在该用户下次 user_update 时自愈
	if(!empty($_SERVER['cache']) && function_exists('cache_delete_prefix')) {
		cache_delete_prefix('user-');
	}

	forum_list_cache_delete();
	index_list_cache_delete();
	// 活跃用户榜按 user.threads+user.posts 排序并缓存 5 分钟，重算后必须失效，否则仍需等待自然过期
	if(function_exists('rank_cache_delete')) {
		rank_cache_delete();
	}

	admin_log_create('forum_recount', 'forum', '', lang('admin_log_forum_recount'));

	// hook admin_forum_recount_end.php

	message(0, lang('forum_recount_successfully'));
}

function user_names_to_ids($names, $sep = ',') {
	$namearr = array_filter(array_map('trim', explode($sep, $names)));
	if(empty($namearr)) return '';
	// 批量查询用户，避免 N+1
	$userlist = db_find('user', array('username'=>$namearr), array(), 1, count($namearr), 'username');
	$r = array();
	foreach($namearr as $name) {
		if(isset($userlist[$name])) {
			$r[] = $userlist[$name]['uid'];
		}
	}
	return implode($sep, $r);
}

function user_ids_to_names($ids, $sep = ',') {
	$idarr = array_filter(array_map('intval', explode($sep, $ids)));
	if(empty($idarr)) return '';
	// 批量查询用户，避免 N+1
	$userlist = db_find('user', array('uid'=>$idarr), array(), 1, count($idarr), 'uid');
	$r = array();
	foreach($idarr as $id) {
		if(isset($userlist[$id])) {
			$r[] = $userlist[$id]['username'];
		}
	}
	return implode($sep, $r);
}

// hook admin_forum_end.php

?>