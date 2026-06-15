<?php

// hook model_forum_start.php

// ------------> 最原生的 CURD，无关联其他数据。

function forum__create($arr) {
	// hook model_forum__create_start.php
	$r = db_create('forum', $arr);
	// hook model_forum__create_end.php
	return $r;
}

function forum__update($fid, $arr) {
	// hook model_forum__update_start.php
	$r = db_update('forum', array('fid'=>$fid), $arr);
	// hook model_forum__update_end.php
	return $r;
}

function forum__read($fid) {
	// hook model_forum__read_start.php
	$forum = db_find_one('forum', array('fid'=>$fid));
	// hook model_forum__read_end.php
	return $forum;
}

function forum__delete($fid) {
	// hook model_forum__delete_start.php
	$r = db_delete('forum', array('fid'=>$fid));
	// hook model_forum__delete_end.php
	return $r;
}

function forum__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 1000) {
	// hook model_forum__find_start.php
	$forumlist = db_find('forum', $cond, $orderby, $page, $pagesize, 'fid');
	// hook model_forum__find_end.php
	return $forumlist;
}

// ------------> 关联 CURD，主要是强相关的数据，比如缓存。弱相关的大量数据需要另外处理。

function forum_create($arr) {
	// hook model_forum_create_start.php
	$r = forum__create($arr);
	forum_list_cache_delete();
	// hook model_forum_create_end.php
	return $r;
}

function forum_update($fid, $arr) {
	// hook model_forum_update_start.php
	$r = forum__update($fid, $arr);
	forum_list_cache_delete();
	// hook model_forum_update_end.php
	return $r;
}

function forum_read($fid) {
	// hook model_forum_read_start.php
	global $conf, $forumlist;
	if($conf['cache']['enable']) {
		return empty($forumlist[$fid]) ? array() : $forumlist[$fid];
	} else {
		$forum = forum__read($fid);
		forum_format($forum);
		return $forum;
	}
	// hook model_forum_read_end.php
}

// 关联数据删除
function forum_delete($fid) {
	$forum = forum_read($fid);
	$cond = array('fid'=>$fid);
	$threadlist = db_find('thread', $cond, array(), 1, 1000000, '', array('tid', 'uid'));
	
	// hook model_forum_delete_start.php
	
	if(!empty($forum) && isset($forum['type']) && $forum['type'] == 1) {
		$sub_forums = forum_find(array('fup'=>$fid));
		foreach($sub_forums as $sub) {
			forum__update($sub['fid'], array('fup'=>0));
		}
	}
	
	foreach ($threadlist as $thread) {
		thread_delete($thread['tid']);
	}
	
	$r = forum__delete($fid);
	
	forum_access_delete_by_fid($fid);
	
	forum_list_cache_delete();
	// hook model_forum_delete_end.php
	return $r;
}

function forum_find($cond = array(), $orderby = array('rank'=>-1), $page = 1, $pagesize = 1000) {
	// hook model_forum_find_start.php
	$forumlist = forum__find($cond, $orderby, $page, $pagesize);
	if($forumlist) foreach ($forumlist as &$forum) forum_format($forum);
	// hook model_forum_find_end.php
	return $forumlist;
}

// ------------> 其他方法

function forum_format(&$forum) {
	global $conf;
	if(empty($forum)) return;
	
	// hook model_forum_format_start.php
	
	$forum['create_date_fmt'] = date('Y-n-j', $forum['create_date']);
	// 图标逻辑：icon 包含 . 或 / 视为图片路径，否则视为 Tabler Icon 类名
	if (!empty($forum['icon']) && (strpos($forum['icon'], '.') !== false || strpos($forum['icon'], '/') !== false)) {
		// 确保图片路径为绝对路径，避免在 /admin/ 下解析错误
		$icon = $forum['icon'];
		if($icon[0] !== '/' && strpos($icon, '://') === FALSE && strpos($icon, '//') !== 0) {
			$icon = '/' . $icon;
		}
		$forum['icon_url'] = $icon;
		$forum['icon_class'] = '';
	} else {
		$forum['icon_url'] = '/view/img/forum.png';
		$forum['icon_class'] = !empty($forum['icon']) ? $forum['icon'] : '';
	}
	// accesslist 已在 forum_list_cache() 中批量加载，此处仅作回退查询
	if(!isset($forum['accesslist'])) {
		$forum['accesslist'] = $forum['accesson'] ? forum_access_find_by_fid($forum['fid']) : array();
	}
	$forum['modlist'] = array();
	if($forum['moduids']) {
		$modlist = user_find_by_uids($forum['moduids']);
		foreach($modlist as &$mod) $mod = user_safe_info($mod);
		$forum['modlist'] = $modlist;
	}
	$forum['type_name'] = isset($forum['type']) && $forum['type'] == 1 ? '分区' : '版块';
	if(!empty($forum['fup'])) {
		// 从缓存取父版块名称，避免额外查库
		global $forumlist;
		$forum['fup_name'] = isset($forumlist[$forum['fup']]) ? $forumlist[$forum['fup']]['name'] : '';
		// 缓存未命中时回退查库
		if(empty($forum['fup_name'])) {
			$fup_forum = forum__read($forum['fup']);
			$forum['fup_name'] = $fup_forum ? $fup_forum['name'] : '';
		}
	} else {
		$forum['fup_name'] = '';
	}
	// hook model_forum_format_end.php
}

function forum_count($cond = array()) {
	// hook model_forum_count_start.php
	$n = db_count('forum', $cond);
	// hook model_forum_count_end.php
	return $n;
}

function forum_maxid() {
	// hook model_forum_maxid_start.php
	$n = db_maxid('forum', 'fid');
	// hook model_forum_maxid_end.php
	return $n;
}

// 从缓存中读取 forum_list 数据x
function forum_list_cache() {
	global $conf, $forumlist;
	$forumlist = cache_get('forumlist');

	// hook model_forum_list_cache_start.php

	if($forumlist === NULL) {
		$forumlist = forum_find();
		// 批量查询所有版块权限数据，替代 forum_format 中逐版块查询
		// 注意：不能以 gid 为key，因为同一gid在不同版块有多条记录，会导致覆盖丢失
		$all_access = db_find('forum_access', array(), array('fid'=>1, 'gid'=>1), 1, 10000);
		$access_by_fid = array();
		if($all_access) {
			foreach($all_access as $a) {
				if(!isset($access_by_fid[$a['fid']])) {
					$access_by_fid[$a['fid']] = array();
				}
				$access_by_fid[$a['fid']][$a['gid']] = $a;
			}
		}
		// 设置 accesslist 和 fup_name，覆盖 forum_format 中的逐条查询结果
		foreach($forumlist as $fid=>&$forum) {
			$forum['accesslist'] = !empty($forum['accesson']) && isset($access_by_fid[$fid]) ? $access_by_fid[$fid] : array();
			// 从已构建的 forumlist 中取父版块名称，避免额外查库
			if(!empty($forum['fup']) && isset($forumlist[$forum['fup']])) {
				$forum['fup_name'] = $forumlist[$forum['fup']]['name'];
			}
		}
		unset($forum);
		cache_set('forumlist', $forumlist, 60);
	}
	// hook model_forum_list_cache_end.php
	return $forumlist;
}

// 更新 forumlist 缓存
function forum_list_cache_delete() {
	global $conf;
	static $deleted = FALSE;
	if($deleted) return;
	
	// hook model_forum_list_cache_delete_start.php
	
	cache_delete('forumlist');
	$deleted = TRUE;
	// hook model_forum_list_cache_delete_end.php
}

// 对 $forumlist 权限过滤，查看权限没有，则隐藏
function forum_list_access_filter($forumlist, $gid, $allow = 'allowread') {
	global $conf, $grouplist;
	if(empty($forumlist)) return array();
	if($gid == 1 || $gid == 2) return $forumlist;
	$forumlist_filter = $forumlist;
	$group = $grouplist[$gid];
	
	// hook model_forum_list_access_filter_start.php
	
	foreach($forumlist_filter as $fid=>$forum) {
		if(empty($forum['accesson']) && empty($group[$allow]) || !empty($forum['accesson']) && empty($forum['accesslist'][$gid][$allow])) {
			unset($forumlist_filter[$fid]);
			unset($forumlist_filter[$fid]['modlist']);
		}
		unset($forumlist_filter[$fid]['accesslist']);
	}
	// hook model_forum_list_access_filter_end.php
	return $forumlist_filter;
}

function forum_filter_moduid($moduids) {
	$moduids = trim($moduids);
	if(empty($moduids)) return '';
	$arr = explode(',', $moduids);
	$r = array();
	foreach($arr as $_uid) {
		$_uid = intval($_uid);
		$_user = user_read($_uid);
		if(empty($_user)) continue;
		if($_user['gid'] > 4) continue;
		$r[] = $_uid;
	}
	return implode(',', $r);
}


function forum_safe_info($forum) {
	// hook model_forum_safe_info_start.php
	//unset($forum['moduids']);
	// hook model_forum_safe_info_end.php
	return $forum;
}

function forum_find_categories() {
	$categories = forum_find(array('type'=>1), array('rank'=>-1));
	return $categories;
}

function forum_find_by_fup($fup) {
	$forums = forum_find(array('fup'=>$fup, 'type'=>0), array('rank'=>-1));
	return $forums;
}

// hook model_forum_end.php

?>