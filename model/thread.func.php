<?php

// hook model_thread_start.php

// 清除版块帖子列表缓存（forum_tc_ 帖子总数 + forum_tl_ 帖子列表）
// 在帖子创建/删除/移动/审核状态变更时调用，避免 60s 短缓存导致分页错乱
function thread_forum_list_cache_delete($fid) {
	if(empty($fid) || !class_exists('CacheHelper', false)) return;
	$fid = intval($fid);
	// 用前缀删除清除该版块所有 gid/orderby/page 组合的缓存
	CacheHelper::deleteByPrefix('core_forum_tc_' . $fid . '_');
	CacheHelper::deleteByPrefix('core_forum_tl_' . $fid . '_');
}

// 带下限保护的计数器递减：GREATEST(field-N, 0)，防止负数
// ponytail: thread__update(array('posts-'=>N)) 走 db_array_to_update_sqladd 无保护，统一改用本函数
function thread_dec($tid, $field, $n = 1) {
	$tid = intval($tid);
	$n = intval($n);
	if($tid <= 0 || $n <= 0) return FALSE;
	if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) return FALSE;
	global $db;
	$tablepre = $db->tablepre;
	return db_exec("UPDATE `{$tablepre}thread` SET `$field` = GREATEST(`$field` - $n, 0) WHERE tid = '$tid'");
}

// 清除首页帖子列表缓存（core_index_tl_ 列表 + core_index_thread_count_ 总数）
// 首页列表/计数缓存使用版本号机制：递增版本号使旧缓存键自然失效
// 原因：cache_set 对 >32 字符的键做 md5 哈希（bbs_cache.k 是 char(32)），而
// deleteByPrefix 用原始前缀匹配不到哈希后的键，导致首页缓存无法主动清理
// 版本号机制不依赖 deleteByPrefix，通过改变键名使旧缓存自然不匹配（参考 post_list_cache_bump_version）
// 在帖子创建/回复/删除/软删除/移动时调用，确保新内容在首页 60s 内可见
function index_list_cache_delete() {
	if(!function_exists('cache_get') || !function_exists('cache_set') || empty($_SERVER['cache'])) return;
	$v = cache_get('core_index_v');
	$v = ($v === NULL || $v === FALSE) ? 1 : intval($v) + 1;
	cache_set('core_index_v', $v, 0); // 0 = 永不过期
}

// ------------> 积分事件中文名称映射

function credits_event_name($event) {
    // raw: 前缀表示管理员/外部手动输入的自由文本，原样返回不翻译
    if(substr($event, 0, 4) === 'raw:') {
        return substr($event, 4);
    }
    // 如果已经是中文，直接返回
    if(preg_match('/[\x{4e00}-\x{9fa5}]/u', $event)) {
        return $event;
    }
    // 去掉 source 后缀（如 be_liked:90 → be_liked），用于查找语言包
    $baseEvent = strpos($event, ':') !== false ? substr($event, 0, strpos($event, ':')) : $event;
    $key = 'credits_event_' . $baseEvent;
    $name = lang($key);
    // lang() 找不到时返回 'lang[key]' 格式，此时回退到原始值
    $notFoundMarker = 'lang[' . $key . ']';
    return ($name !== $notFoundMarker) ? $name : $event;
}

// ------------> 帖子状态标签配置

function thread_status_labels() {
	$labels = kv_get('thread_status_labels');
	if(empty($labels)) {
		$labels = array(
			'top' => array('icon' => 'ti ti-pin-filled', 'text' => '', 'color' => '#0d6efd', 'text_color' => '#ffffff', 'rank' => 1),
			'digest' => array('icon' => 'ti ti-star-filled', 'text' => '', 'color' => '#ffc107', 'text_color' => '#000000', 'rank' => 2),
			'closed' => array('icon' => 'ti ti-lock', 'text' => '', 'color' => '#6c757d', 'text_color' => '#ffffff', 'rank' => 3),
			'image' => array('icon' => 'ti ti-photo', 'text' => '', 'color' => '#198754', 'text_color' => '#ffffff', 'rank' => 4),
			'video' => array('icon' => 'ti ti-video', 'text' => '', 'color' => '#0dcaf0', 'text_color' => '#000000', 'rank' => 5),
			'attachment' => array('icon' => 'ti ti-paperclip', 'text' => '', 'color' => '#6c757d', 'text_color' => '#ffffff', 'rank' => 6),
		);
	} else {
		if(is_string($labels)) {
			$labels = xn_json_decode($labels);
		}
		if(!is_array($labels)) {
			$labels = array();
		}
	}
	return $labels;
}

function thread_status_label_html($type, $labels) {
	if(empty($labels[$type])) return '';
	$label = $labels[$type];
	$icon = isset($label['icon']) ? trim($label['icon']) : '';
	$text = isset($label['text']) ? trim($label['text']) : '';
	$color = isset($label['color']) ? $label['color'] : '#6c757d';
	$text_color = isset($label['text_color']) ? $label['text_color'] : '#ffffff';
	$badge_class = isset($label['badge_class']) ? trim($label['badge_class']) : 'badge';
	if(empty($badge_class)) $badge_class = 'badge';
	$badge_font_size = isset($label['badge_font_size']) ? trim($label['badge_font_size']) : '0.7em';
	$show_icon = isset($label['show_icon']) ? $label['show_icon'] : true;
	$show_text = isset($label['show_text']) ? $label['show_text'] : true;

	// 根据开关过滤
	if(!$show_icon) $icon = '';
	if(!$show_text) $text = '';

	// 图标和文字都为空时不显示
	if(empty($icon) && empty($text)) return '';

	// 构建 style（font-size 为空时不输出）
	$style = 'background-color:' . $color . ';color:' . $text_color;
	if($badge_font_size !== '') {
		$style .= ';font-size:' . $badge_font_size;
	}

	$html = '<span class="' . $badge_class . '" style="' . $style . '">';
	if($icon) $html .= '<i class="' . $icon . '"></i>';
	if($icon && $text) $html .= ' ';
	if($text) $html .= $text;
	$html .= '</span>';
	return $html;
}

// ------------> 最原生的 CURD，无关联其他数据。

function thread__create($arr) {
	// hook model_thread__create_start.php
	$r = db_insert('thread', $arr);
	// hook model_thread__create_end.php
	return $r;
}

function thread__update($tid, $arr) {
	// hook model_thread__update_start.php
	$r = db_update('thread', array('tid'=>$tid), $arr);
	// hook model_thread__update_end.php
	return $r;
}

function thread__read($tid) {
	// hook model_thread__read_start.php
	$thread = db_find_one('thread', array('tid'=>$tid));
	// hook model_thread__read_end.php
	return $thread;
}

function thread__delete($tid) {
	// hook model_thread__delete_start.php
	$r = db_delete('thread', array('tid'=>$tid));
	// hook model_thread__delete_end.php
	return $r;
}

function thread__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook model_thread__find_start.php

	// 软删除过滤：自动排除已删除帖子
	if(!isset($cond['is_deleted'])) {
		$cond['is_deleted'] = 0;
	}

	// 合并原两次查询：原逻辑先查 tid 列表再按 tid IN 查完整数据
	// 由于两次查询同表、同条件、同排序、同分页，直接一次查询获取全部字段
	$threadlist = db_find('thread', $cond, $orderby, $page, $pagesize, 'tid');

	// hook model_thread__find_end.php
	return $threadlist;
}

function thread_create($arr, &$pid) {
	global $conf, $gid;
	$fid = $arr['fid'];
	$uid = $arr['uid'];
	$subject = $arr['subject'];
	$message = $arr['message'];
	$time = $arr['time'];
	$longip = $arr['longip'];
	$doctype = $arr['doctype'];
	
	# 论坛帖子数据，一页显示，不分页。
	$post = array(
		'tid'=>0,
		'isfirst'=>1,
		'uid'=>$uid,
		'create_date'=>$time,
		'userip'=>$longip,
		'message'=>$message,
		'doctype'=>$doctype,
		'audit_status'=>isset($arr['audit_status']) ? $arr['audit_status'] : 1,
	);
	
	// hook model_thread_create_start.php
	
	$pid = post__create($post, $gid);
	if($pid === FALSE) return FALSE;
	
	// 创建主题
	$thread = array (
		'fid'=>$fid,
		'subject'=>$subject,
		'uid'=>$uid,
		'create_date'=>$time,
		'last_date'=>$time,
		'firstpid'=>$pid,
		'lastpid'=>$pid,
		'userip'=>$longip,
		'audit_status'=>isset($arr['audit_status']) ? $arr['audit_status'] : 1,
	);
	
	// hook model_thread__create_before.php
	
	$tid = thread__create($thread);
	if($tid === FALSE) {
		post__delete($pid);
		return FALSE;
	}
	// 板块总数+1, 用户发帖+1
	
	// 更新统计数据（待审帖子不计入，审核通过后由 AuditService::approve 补加）
	$audit_status = isset($arr['audit_status']) ? intval($arr['audit_status']) : 1;
	if($audit_status == 1) {
		$uid AND user__update($uid, array('threads+'=>1));
		forum__update($fid, array('threads+'=>1, 'todaythreads+'=>1));
		runtime_set('threads+', 1);
		runtime_set('todaythreads+', 1);
	}

	// 关联
	post__update($pid, array('tid'=>$tid), $tid);

	// 我参与的发帖
	$uid AND mythread_create($uid, $tid);

	// 关联附件
	attach_assoc_post($pid);
	
	// 更新板块信息。
	forum_list_cache_delete();
	thread_forum_list_cache_delete($fid);
	// 清除首页帖子列表缓存（首页含多个版块聚合，无法按 fid 删除）
	index_list_cache_delete();

	// 仅审核通过的帖子才通知关注者（待审帖子审核通过后在 AuditService::approve 中补发）
	if($audit_status == 1) {
		$follow_uids = user_follow_find_following_uids_reverse($uid);
		if(!empty($follow_uids)) {
			foreach($follow_uids as $fuid) {
				notify_create($fuid, $uid, 'thread', $tid, 0, $subject);
			}
		}
	}

	// hook model_thread_create_end.php
	
	return $tid;
}

// 不要在大循环里调用此函数！比较耗费资源。
function thread_update($tid, $arr) {
	global $conf;
	$thread = thread__read($tid);
	
	// hook model_thread_update_start.php
	
	if(isset($arr['subject']) && $arr['subject'] != $thread['subject']) {
		$thread['top'] > 0 AND thread_top_cache_delete();
	}
	
	// 更改 fid, 移动主题，相关资源也需要更新
	if(isset($arr['fid']) && $arr['fid'] != $thread['fid']) {
		forum__update($arr['fid'], array('threads+'=>1));
		forum_dec($thread['fid'], 'threads', 1);
		thread_top_update_by_tid($tid, $arr['fid']);
		// 清除新旧版块的帖子列表缓存
		thread_forum_list_cache_delete($arr['fid']);
		thread_forum_list_cache_delete($thread['fid']);
		// 清除首页帖子列表缓存（首页含多个版块聚合，无法按 fid 删除）
		index_list_cache_delete();
		// 清除全局置顶帖列表缓存（移动置顶帖时 thread_top 表 fid 已更新，旧缓存仍指向原版块）
		thread_top_cache_delete();
	}
	
	if(!$arr) return TRUE;
	
	$r = thread__update($tid, $arr);
	
	// hook model_thread_update_end.php
	return $r;
}

// views + 1
function thread_inc_views($tid, $n = 1) {
	// hook model_thread_inc_views_start.php
	global $conf, $db;
	$tid = intval($tid);
	$n = intval($n);
	$tablepre = $db->tablepre;
	if(!$conf['update_views_on']) return TRUE;
	$sqladd = !in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) ? '' : ' LOW_PRIORITY';
	$r = db_exec("UPDATE$sqladd `{$tablepre}thread` SET views=views+$n WHERE tid='$tid'");
	// hook model_thread_inc_views_end.php
	return $r;
}

function thread_read($tid) {
	// hook model_thread_read_start.php
	$thread = thread__read($tid);
	// 软删除过滤：已删除帖子对前台等同于不存在
	if(!empty($thread) && !empty($thread['is_deleted'])) {
		return array();
	}
	thread_format($thread);
	// hook model_thread_read_end.php
	return $thread;
}

// 从缓存中读取，避免重复从数据库取数据，主要用来前端显示，可能有延迟。重要业务逻辑不要调用此函数，数据可能不准确，因为并没有清理缓存，针对 request 生命周期有效。
function thread_read_cache($tid) {
	// hook model_thread_read_cache_start.php
	static $cache = array(); // 用静态变量只能在当前 request 生命周期缓存，要跨进程，可以再加一层缓存： memcached/xcache/apc/
	if(isset($cache[$tid])) return $cache[$tid];
	$cache[$tid] = thread_read($tid);
	// hook model_thread_read_cache_end.php
	return $cache[$tid];
}

// 删除主题
function thread_delete($tid) {
	global $conf;
	$thread = thread__read($tid);
	if(empty($thread)) return TRUE;
	$fid = $thread['fid'];
	$uid = $thread['uid'];
	
	// hook model_thread_delete_start.php
	
	// 删除所有回帖，同时更新 posts 统计数
	$n = post_delete_by_tid($tid);
	
	// 删除我的主题
	$uid AND mythread_delete($uid, $tid);
	thread_favorite_delete_by_tid($tid);

	// 清除相关缓存
	forum_list_cache_delete();
	thread_forum_list_cache_delete($fid);
	// 清除首页帖子列表缓存（首页含多个版块聚合，无法按 fid 删除）
	index_list_cache_delete();

	$r = thread__delete($tid);
	if($r === FALSE) return FALSE;

	// 更新统计（待审帖子创建时未计入 threads，删除时也不应减少）
	// 已软删除的帖子在软删除时已减过统计，彻底删除时不应再减
	$is_deleted = isset($thread['is_deleted']) ? intval($thread['is_deleted']) : 0;
	$audit_status = isset($thread['audit_status']) ? intval($thread['audit_status']) : 1;
	if($audit_status == 1 && $is_deleted == 0) {
		forum_dec($fid, 'threads', 1);
		user_dec($uid, 'threads', 1);
		runtime_set('threads-', 1);
	}

	// hook model_thread_delete_end.php

	return $r;
}

// 批量删除多个主题，合并查询消除 N+1 DELETE/UPDATE
// 保持与 thread_delete 相同的数据一致性：post/attach/mythread/favorite/thread 全部清理，
// forum.threads / user.threads / runtime.threads 按 fid/uid 分组汇总后批量更新
function thread_delete_batch($tids) {
	// hook model_thread_delete_batch_start.php

	if(empty($tids) || !is_array($tids)) return 0;

	$tids = array_map('intval', $tids);
	$tids = array_unique($tids);
	$tids = array_filter($tids);
	if(empty($tids)) return 0;

	// 1. 批量读取所有 thread（一次查询）
	$threadlist = db_find('thread', array('tid'=>$tids), array(), 1, count($tids), 'tid');
	if(empty($threadlist)) return 0;

	$valid_tids = arrlist_values($threadlist, 'tid');

	// 2. 批量删除所有回帖及附件（合并 post_delete_by_tid 逻辑，一次 find / 一次 delete）
	post_delete_by_tids_batch($valid_tids);

	// 3. 批量删除 mythread（一次 DELETE，按 tid）
	db_delete('mythread', array('tid'=>$valid_tids));

	// 4. 批量删除 thread_favorite（合并 thread_favorite_delete_by_tid 逻辑）
	thread_favorite_delete_by_tids_batch($valid_tids);

	// 5. 清除相关缓存
	forum_list_cache_delete();

	// 6. 批量删除 thread（一次 DELETE）
	$r = db_delete('thread', array('tid'=>$valid_tids));
	if($r === FALSE) return FALSE;

	// 7. 汇总 forum 统计：按 fid 分组累计 threads 减量（待审/已软删帖子不计入）
	$forum_threads_dec = array();
	$approved_count = 0;
	foreach($threadlist as $thread) {
		$_audit = isset($thread['audit_status']) ? intval($thread['audit_status']) : 1;
		$_is_deleted = isset($thread['is_deleted']) ? intval($thread['is_deleted']) : 0;
		if($_audit != 1) continue; // 待审帖子创建时未计入，删除时也不减少
		if($_is_deleted == 1) continue; // 已软删帖子在软删时已减过统计，彻底删除时不再减
		$approved_count++;
		$fid = intval($thread['fid']);
		if($fid == 0) continue;
		if(!isset($forum_threads_dec[$fid])) $forum_threads_dec[$fid] = 0;
		$forum_threads_dec[$fid]++;
	}
	foreach($forum_threads_dec as $fid => $dec) {
		forum_dec($fid, 'threads', $dec);
	}

	// 8. 汇总 user 统计：按 uid 分组累计 threads 减量（待审/已软删帖子不计入）
	$user_threads_dec = array();
	foreach($threadlist as $thread) {
		$_audit = isset($thread['audit_status']) ? intval($thread['audit_status']) : 1;
		$_is_deleted = isset($thread['is_deleted']) ? intval($thread['is_deleted']) : 0;
		if($_audit != 1) continue;
		if($_is_deleted == 1) continue;
		$u = intval($thread['uid']);
		if($u == 0) continue;
		if(!isset($user_threads_dec[$u])) $user_threads_dec[$u] = 0;
		$user_threads_dec[$u]++;
	}
	foreach($user_threads_dec as $u => $dec) {
		user_dec($u, 'threads', $dec);
	}

	// 9. 全站统计（仅计审核通过且非软删的）
	runtime_set('threads-', $approved_count);

	// 10. 清除所有受影响版块的帖子列表缓存
	foreach($forum_threads_dec as $_fid => $_dec) {
		thread_forum_list_cache_delete($_fid);
	}
	// 清除首页帖子列表缓存（首页含多个版块聚合，无法按 fid 删除）
	index_list_cache_delete();

	// hook model_thread_delete_batch_end.php

	return count($valid_tids);
}

// 软删除单个主题（标记 is_deleted=1，同时标记该主题下所有回帖）
function thread_soft_delete($tid, $deleted_by) {
	global $time;
	$thread = thread__read($tid);
	if(empty($thread) || intval($thread['is_deleted']) == 1) return TRUE;

	$fid = $thread['fid'];
	$uid = $thread['uid'];

	// hook model_thread_soft_delete_start.php

	// 标记主题为已删除
	thread__update($tid, array('is_deleted'=>1, 'deleted_date'=>$time, 'deleted_by'=>intval($deleted_by)));

	// 标记该主题下所有回帖为已删除
	db_update('post', array('tid'=>$tid), array('is_deleted'=>1, 'deleted_date'=>$time, 'deleted_by'=>intval($deleted_by)));

	// 删除 mythread 记录
	$uid > 0 AND mythread_delete($uid, $tid);

	// 减计统计（仅审核通过的帖子）
	$audit_status = isset($thread['audit_status']) ? intval($thread['audit_status']) : 1;
	if($audit_status == 1) {
		forum_dec($fid, 'threads', 1);
		user_dec($uid, 'threads', 1);
		runtime_set('threads-', 1);
	}

	// 统计该主题下审核通过的非首帖数量，减计 posts
	$postlist = post__find(array('tid'=>$tid), array(), 1, 1000000);
	$non_first_count = 0;
	$user_post_count = array();
	if($postlist) {
		foreach($postlist as $post) {
			if($post['isfirst']) continue;
			$_audit = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
			if($_audit != 1) continue;
			$non_first_count++;
			if($post['uid']) {
				if(!isset($user_post_count[$post['uid']])) $user_post_count[$post['uid']] = 0;
				$user_post_count[$post['uid']]++;
			}
		}
	}
	$non_first_count AND runtime_set('posts-', $non_first_count);
	foreach($user_post_count as $_uid => $cnt) {
		user_dec($_uid, 'posts', $cnt);
	}

	// 清除 forum_list 缓存
	forum_list_cache_delete();
	thread_forum_list_cache_delete($fid);
	// 清除首页帖子列表缓存（首页含多个版块聚合，无法按 fid 删除）
	index_list_cache_delete();

	// hook model_thread_soft_delete_end.php
	return TRUE;
}

// 批量软删除多个主题
function thread_soft_delete_batch($tids, $deleted_by) {
	global $time;

	// hook model_thread_soft_delete_batch_start.php

	if(empty($tids) || !is_array($tids)) return 0;

	$tids = array_map('intval', $tids);
	$tids = array_unique($tids);
	$tids = array_filter($tids);
	if(empty($tids)) return 0;

	// 1. 一次查询所有 thread
	$threadlist = db_find('thread', array('tid'=>$tids), array(), 1, count($tids), 'tid');
	if(empty($threadlist)) return 0;

	$valid_tids = arrlist_values($threadlist, 'tid');

	// 2. 对每个有效的 tid 设置软删除标记
	foreach($valid_tids as $tid) {
		thread__update($tid, array('is_deleted'=>1, 'deleted_date'=>$time, 'deleted_by'=>intval($deleted_by)));
	}

	// 3. 批量标记所有 post 为已删除
	db_update('post', array('tid'=>$valid_tids), array('is_deleted'=>1, 'deleted_date'=>$time, 'deleted_by'=>intval($deleted_by)));

	// 4. 批量删除 mythread
	db_delete('mythread', array('tid'=>$valid_tids));

	// 5. 汇总统计更新（按 fid/uid 分组累加）
	$forum_threads_dec = array();
	$user_threads_dec = array();
	$approved_count = 0;
	foreach($threadlist as $thread) {
		$_audit = isset($thread['audit_status']) ? intval($thread['audit_status']) : 1;
		if($_audit != 1) continue;
		$approved_count++;
		$fid = intval($thread['fid']);
		if($fid > 0) {
			if(!isset($forum_threads_dec[$fid])) $forum_threads_dec[$fid] = 0;
			$forum_threads_dec[$fid]++;
		}
		$u = intval($thread['uid']);
		if($u > 0) {
			if(!isset($user_threads_dec[$u])) $user_threads_dec[$u] = 0;
			$user_threads_dec[$u]++;
		}
	}
	foreach($forum_threads_dec as $fid => $dec) {
		forum_dec($fid, 'threads', $dec);
	}
	foreach($user_threads_dec as $u => $dec) {
		user_dec($u, 'threads', $dec);
	}
	runtime_set('threads-', $approved_count);

	// 6. 汇总 posts 统计（批量查询所有 post，按 uid 分组）
	$postlist = db_find('post', array('tid'=>$valid_tids), array(), 1, 1000000, 'pid');
	$non_first_count = 0;
	$user_post_count = array();
	if($postlist) {
		foreach($postlist as $post) {
			if($post['isfirst']) continue;
			$_audit = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
			if($_audit != 1) continue;
			$non_first_count++;
			if($post['uid']) {
				if(!isset($user_post_count[$post['uid']])) $user_post_count[$post['uid']] = 0;
				$user_post_count[$post['uid']]++;
			}
		}
	}
	$non_first_count AND runtime_set('posts-', $non_first_count);
	foreach($user_post_count as $_uid => $cnt) {
		user_dec($_uid, 'posts', $cnt);
	}

	// 7. 清除 forum_list 缓存
	forum_list_cache_delete();
	// 清除所有受影响版块的帖子列表缓存
	foreach($forum_threads_dec as $_fid => $_dec) {
		thread_forum_list_cache_delete($_fid);
	}
	// 清除首页帖子列表缓存（首页含多个版块聚合，无法按 fid 删除）
	index_list_cache_delete();

	// hook model_thread_soft_delete_batch_end.php
	return count($valid_tids);
}

// 恢复单个已软删除的主题
function thread_restore($tid) {
	$thread = thread__read($tid);
	if(empty($thread) || intval($thread['is_deleted']) == 0) return TRUE;

	$fid = $thread['fid'];
	$uid = $thread['uid'];

	// hook model_thread_restore_start.php

	// 恢复主题
	thread__update($tid, array('is_deleted'=>0, 'deleted_date'=>0, 'deleted_by'=>0));

	// 恢复该主题下所有回帖
	db_update('post', array('tid'=>$tid), array('is_deleted'=>0, 'deleted_date'=>0, 'deleted_by'=>0));

	// 重建 mythread 记录
	$uid > 0 AND mythread_create($uid, $tid);

	// 补计统计（仅审核通过的帖子）
	$audit_status = isset($thread['audit_status']) ? intval($thread['audit_status']) : 1;
	if($audit_status == 1) {
		forum__update($fid, array('threads+'=>1));
		user__update($uid, array('threads+'=>1));
		runtime_set('threads+', 1);
	}

	// 统计该主题下审核通过的非首帖数量（从已恢复的 post 中统计），补计 posts
	$postlist = post__find(array('tid'=>$tid), array(), 1, 1000000);
	$non_first_count = 0;
	$user_post_count = array();
	if($postlist) {
		foreach($postlist as $post) {
			if($post['isfirst']) continue;
			$_audit = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
			if($_audit != 1) continue;
			$non_first_count++;
			if($post['uid']) {
				if(!isset($user_post_count[$post['uid']])) $user_post_count[$post['uid']] = 0;
				$user_post_count[$post['uid']]++;
			}
		}
	}
	$non_first_count AND runtime_set('posts+', $non_first_count);
	foreach($user_post_count as $_uid => $cnt) {
		user__update($_uid, array('posts+'=>$cnt));
	}

	// 清除 forum_list 缓存
	forum_list_cache_delete();
	thread_forum_list_cache_delete($fid);
	// 清除首页帖子列表缓存（首页含多个版块聚合，无法按 fid 删除）
	index_list_cache_delete();

	// hook model_thread_restore_end.php
	return TRUE;
}

// 批量恢复多个已软删除的主题
function thread_restore_batch($tids) {
	// hook model_thread_restore_batch_start.php

	if(empty($tids) || !is_array($tids)) return 0;

	$tids = array_map('intval', $tids);
	$tids = array_unique($tids);
	$tids = array_filter($tids);
	if(empty($tids)) return 0;

	// 1. 一次查询所有 thread
	$threadlist = db_find('thread', array('tid'=>$tids), array(), 1, count($tids), 'tid');
	if(empty($threadlist)) return 0;

	$valid_tids = arrlist_values($threadlist, 'tid');

	// 2. 对每个有效的 tid 恢复
	foreach($valid_tids as $tid) {
		thread__update($tid, array('is_deleted'=>0, 'deleted_date'=>0, 'deleted_by'=>0));
	}

	// 3. 批量恢复所有 post
	db_update('post', array('tid'=>$valid_tids), array('is_deleted'=>0, 'deleted_date'=>0, 'deleted_by'=>0));

	// 4. 重建 mythread 记录
	foreach($threadlist as $thread) {
		$uid = intval($thread['uid']);
		$tid = intval($thread['tid']);
		if($uid > 0) {
			mythread_create($uid, $tid);
		}
	}

	// 5. 汇总统计更新（按 fid/uid 分组累加）
	$forum_threads_inc = array();
	$user_threads_inc = array();
	$approved_count = 0;
	foreach($threadlist as $thread) {
		$_audit = isset($thread['audit_status']) ? intval($thread['audit_status']) : 1;
		if($_audit != 1) continue;
		$approved_count++;
		$fid = intval($thread['fid']);
		if($fid > 0) {
			if(!isset($forum_threads_inc[$fid])) $forum_threads_inc[$fid] = 0;
			$forum_threads_inc[$fid]++;
		}
		$u = intval($thread['uid']);
		if($u > 0) {
			if(!isset($user_threads_inc[$u])) $user_threads_inc[$u] = 0;
			$user_threads_inc[$u]++;
		}
	}
	foreach($forum_threads_inc as $fid => $inc) {
		forum__update($fid, array('threads+'=>$inc));
	}
	foreach($user_threads_inc as $u => $inc) {
		user__update($u, array('threads+'=>$inc));
	}
	runtime_set('threads+', $approved_count);

	// 6. 汇总 posts 统计（批量查询所有 post，按 uid 分组）
	$postlist = db_find('post', array('tid'=>$valid_tids), array(), 1, 1000000, 'pid');
	$non_first_count = 0;
	$user_post_count = array();
	if($postlist) {
		foreach($postlist as $post) {
			if($post['isfirst']) continue;
			$_audit = isset($post['audit_status']) ? intval($post['audit_status']) : 1;
			if($_audit != 1) continue;
			$non_first_count++;
			if($post['uid']) {
				if(!isset($user_post_count[$post['uid']])) $user_post_count[$post['uid']] = 0;
				$user_post_count[$post['uid']]++;
			}
		}
	}
	$non_first_count AND runtime_set('posts+', $non_first_count);
	foreach($user_post_count as $_uid => $cnt) {
		user__update($_uid, array('posts+'=>$cnt));
	}

	// 7. 清除 forum_list 缓存
	forum_list_cache_delete();
	foreach($forum_threads_inc as $_fid => $_inc) {
		thread_forum_list_cache_delete($_fid);
	}
	// 清除首页帖子列表缓存（首页含多个版块聚合，无法按 fid 删除）
	index_list_cache_delete();

	// hook model_thread_restore_batch_end.php
	return count($valid_tids);
}

function thread_find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook model_thread_find_start.php
	$threadlist = thread__find($cond, $orderby, $page, $pagesize);
	if($threadlist) {
		// 批量预加载用户数据，消除 N+1 查询
		$uids = arrlist_values($threadlist, 'uid');
		$lastuids = arrlist_values($threadlist, 'lastuid');
		user_preload(array_merge($uids, $lastuids));

		// hook model_thread_find_preload.php
		foreach ($threadlist as &$thread) thread_format($thread);
	}
	// hook model_thread_find_end.php
	return $threadlist;
}

// $order: tid/lastpid
// 按照: 发帖时间/最后回复时间 倒序，不包含置顶帖
function thread__find_by_fid($fid, $page = 1, $pagesize = 20, $order = 'lastpid') {
	global $conf, $forumlist, $runtime, $gid;

	// 排序字段白名单验证，防止 SQL 注入
	$allow_orders = array('tid', 'lastpid', 'create_date', 'last_date');
	if(!in_array($order, $allow_orders)) {
		$order = 'lastpid';
	}

	$forum = $fid ? $forumlist[$fid] : array();
	$threads = empty($forum) ? $runtime['threads'] : $forum['threads'];

	// hook model__thread_find_by_fid_start.php

	$cond = array();
	$cond['is_deleted'] = 0;
	$cond['top'] = 0; // 排除置顶帖，置顶帖由 thread_top_find 独立获取
	$fid AND $cond['fid'] = $fid;
	// 非管理员查询时排除待审帖子，避免获取后过滤导致每页数量不足
	if($gid == 0 || $gid > 2) {
		$cond['audit_status'] = array('!=' => 0);
	}

	$desc = TRUE;
	$limitpage = 50000; // 如果需要防止 CC 攻击，可以调整为 5000
	if($page > 100) {
		$totalpage = ceil($threads / $pagesize);
		$halfpage = ceil($totalpage / 2);
		if($halfpage > $limitpage && $page < ($totalpage - $limitpage)) {
			$page = $limitpage;
		}
		if($page > $halfpage) {
			$page = max(1, $totalpage - $page + 1) ;
			$threadlist = thread_find($cond, array($order=>1), $page, $pagesize);
			$threadlist = array_reverse($threadlist, TRUE);
			$desc = FALSE;
		}
	}
	if($desc) {
		$orderby = array($order=>-1);
		$threadlist = thread_find($cond, $orderby, $page, $pagesize);
	}
	
	// hook model__thread_find_by_fid_end.php
	
	return $threadlist;
}

// $order: tid/lastpid
// 按照: 发帖时间/最后回复时间 倒序，不包含置顶帖（置顶帖由调用方通过 thread_top_find 独立获取）
function thread_find_by_fid($fid, $page = 1, $pagesize = 20, $order = 'lastpid') {
	global $conf, $forumlist, $runtime;

	// 排序字段白名单验证，防止 SQL 注入
	$allow_orders = array('tid', 'lastpid', 'create_date', 'last_date');
	if(!in_array($order, $allow_orders)) {
		$order = 'lastpid';
	}

	// hook model_thread_find_by_fid_start.php

	$threadlist = thread__find_by_fid($fid, $page, $pagesize, $order);

	// hook model_thread_find_by_fid_middle.php
	// hook model_thread_find_by_fid_end.php
	return $threadlist;
}

// 从多个版块获取列表数据
function thread_find_by_fids($fids, $page = 1, $pagesize = 20, $order = 'lastpid', $threads = FALSE) {

	global $gid, $runtime;

	// 排序字段白名单验证，防止 SQL 注入
	$allow_orders = array('tid', 'lastpid', 'create_date', 'last_date', 'views');
	if(!in_array($order, $allow_orders)) {
		$order = 'lastpid';
	}

	// hook model_thread_find_by_fids_start.php

	$cond = array('fid'=>$fids);
	$cond['is_deleted'] = 0;
	$cond['top'] = 0; // 排除置顶帖，置顶帖由调用方通过 thread_top_find 独立获取
	// 非管理员查询时排除待审帖子，避免获取后过滤导致每页数量不足
	if($gid == 0 || $gid > 2) {
		$cond['audit_status'] = array('!=' => 0);
	}

	// 深分页优化：参照 thread__find_by_fid，当 page > 100 时从尾部反向查询
	$desc = TRUE;
	$limitpage = 50000;
	$total_threads = $runtime['threads'];
	if($page > 100 && $total_threads > 0) {
		$totalpage = ceil($total_threads / $pagesize);
		$halfpage = ceil($totalpage / 2);
		if($halfpage > $limitpage && $page < ($totalpage - $limitpage)) {
			$page = $limitpage;
		}
		if($page > $halfpage) {
			$page = max(1, $totalpage - $page + 1);
			$threadlist = thread_find($cond, array($order=>1), $page, $pagesize);
			$threadlist = array_reverse($threadlist, TRUE);
			$desc = FALSE;
		}
	}
	if($desc) {
		$threadlist = thread_find($cond, array($order=>-1), $page, $pagesize);
	}

	// hook model_thread_find_by_fids_end.php

	return $threadlist;
}

// 默认搜索标题
function thread_find_by_keyword($keyword) {
	// hook model_thread_find_by_keyword_start.php
	$threadlist = db_find('thread', array('is_deleted'=>0, 'subject'=>array('LIKE'=>$keyword)), array(), 1, 60);
	$threadlist = arrlist_multisort($threadlist, 'tid', FALSE); // 用 PHP 排序，mysql 排序消耗太大。
	if($threadlist) {
		// 批量预加载用户数据，消除 N+1 查询
		$uids = arrlist_values($threadlist, 'uid');
		$lastuids = arrlist_values($threadlist, 'lastuid');
		user_preload(array_merge($uids, $lastuids));

		foreach ($threadlist as &$thread) {
			thread_format($thread);
			$thread['subject'] = post_highlight_keyword($thread['subject'], $keyword);
		}
	}
	// hook model_thread_find_by_keyword_end.php
	return $threadlist;
}


function thread_format(&$thread) {
	
	global $conf, $forumlist, $uid;
	if(empty($thread)) return;
	
	// hook model_thread_format_start.php
	
	$thread['create_date_fmt'] = humandate($thread['create_date']);
	$thread['last_date_fmt'] = humandate($thread['last_date']);
	
	$user = user_read_cache($thread['uid']);
	$thread['username'] = isset($user['display_name']) ? $user['display_name'] : (isset($user['username']) ? $user['username'] : lang('guest'));
	$thread['user_avatar_url'] = !empty($user['avatar_url']) ? $user['avatar_url'] : default_avatar_url();
	$thread['group_icon_class'] = isset($user['group_icon_class']) ? $user['group_icon_class'] : '';
	$thread['group_color'] = isset($user['group_color']) ? $user['group_color'] : '';
	$thread['group_name'] = isset($user['groupname']) ? $user['groupname'] : '';
	$thread['gid'] = isset($user['gid']) ? $user['gid'] : 0;
	$thread['user'] = $user;
	
	$forum = isset($forumlist[$thread['fid']]) ? $forumlist[$thread['fid']] : array('name'=>'');
	$thread['forumname'] = $forum['name'];
	
	if($thread['last_date'] == $thread['create_date']) {
		//$thread['last_date'] = 0;
		$thread['last_date_fmt'] = '';
		$thread['lastuid'] = 0;
		$thread['lastusername'] = '';
	} else {
		$lastuser = $thread['lastuid'] ? user_read_cache($thread['lastuid']) : array();
		$thread['lastusername'] = $thread['lastuid'] ? (isset($lastuser['display_name']) ? $lastuser['display_name'] : (isset($lastuser['username']) ? $lastuser['username'] : lang('guest'))) : lang('guest');
	}
	
	$thread['url'] = url("thread-$thread[tid]");
	$thread['user_url'] = url("user-$thread[uid]".($thread['uid'] ? '' : "-$thread[firstpid]"));
	
	$thread['top_class'] = $thread['top'] ? 'top_'.$thread['top'] : '';

	// 分页基于一级评论数，但列表页无法精确计算，用总回帖数估算（详情页单独精确计算）
	$thread['pages'] = ceil($thread['posts'] / max(1, $conf['postlist_pagesize']));

	// is_liked 和 is_favorited 不在列表页查询，改为详情页单独查询
	$thread['is_liked'] = 0;
	$thread['is_favorited'] = 0;

	// XSS 防护：转义用户可控的文本字段
	$thread['subject'] = esc_html($thread['subject']);
	$thread['username'] = esc_html($thread['username']);
	$thread['forumname'] = esc_html($thread['forumname']);
	$thread['lastusername'] = esc_html($thread['lastusername'] ?? '');
	$thread['group_name'] = esc_html($thread['group_name'] ?? '');
	$thread['top_class'] = esc_attr($thread['top_class'] ?? '');
	$thread['group_icon_class'] = esc_attr($thread['group_icon_class'] ?? '');
	$thread['user_avatar_url'] = esc_attr($thread['user_avatar_url'] ?? '');

	// hook model_thread_format_end.php
}

function thread_format_last_date(&$thread) {
	// hook model_thread_format_last_date_start.php
	if($thread['last_date'] != $thread['create_date']) {
		$thread['last_date_fmt'] = humandate($thread['last_date']);
	} else {
		$thread['create_date_fmt'] = humandate($thread['create_date']);
	}
	// hook model_thread_format_last_date_end.php
}

function thread_count($cond = array()) {
	// hook model_thread_count_start.php
	$n = db_count('thread', $cond);
	// hook model_thread_count_end.php
	return $n;
}

function thread_maxid() {
	// hook model_thread_maxid_start.php
	$n = db_maxid('thread', 'tid');
	// hook model_thread_maxid_end.php
	return $n;
}

function thread_safe_info($thread) {
	// hook model_thread_safe_info_start.php
	unset($thread['userip']);
	if(!empty($thread['user'])) {
		$thread['user'] = user_safe_info($thread['user']);
	}
	// hook model_thread_safe_info_end.php
	return $thread;
}

function thread_get_level($n, $levelarr) {
	// hook model_thread_get_level_start.php
	foreach($levelarr as $k=>$level) {
		if($n <= $level) return $k;
	}
	// hook model_thread_get_level_end.php
	return $k;
}


// 对 $threadlist 权限过滤
function thread_list_access_filter(&$threadlist, $gid) {
	global $conf, $forumlist, $uid;
	if(empty($threadlist)) return;
	
	// hook model_thread_list_access_filter_start.php
	
	foreach($threadlist as $tid=>$thread) {
		if(empty($thread) || !is_array($thread)) { unset($threadlist[$tid]); continue; }
		if(empty($forumlist[$thread['fid']]) || empty($forumlist[$thread['fid']]['accesson'])) continue;
		if($thread['top'] > 0) continue;
		if(!forum_access_user($thread['fid'], $gid, 'allowread')) {
			unset($threadlist[$tid]);
		}
	}

	// 待审/驳回内容过滤：非管理员(gid!=1,2)不可见 audit_status!=1 的他人帖子，作者自己可见待审和驳回帖子
	if($gid == 0 || $gid > 2) {
		foreach($threadlist as $tid=>$thread) {
			if(isset($thread['audit_status']) && $thread['audit_status'] != 1) {
				// 待审/驳回帖子仅作者可见
				if($thread['uid'] != $uid) {
					unset($threadlist[$tid]);
				}
			}
		}
	}

	// hook model_thread_list_access_filter_end.php
}

function thread_find_by_tids($tids, $order = array()) {
	// hook model_thread_find_by_tids_start.php
	//$start = ($page - 1) * $pagesize;
	//$tids = array_slice($tids, $start, $pagesize);
	if(!$tids) return array();
	$cond = array('tid'=>$tids, 'is_deleted'=>0);
	$threadlist = db_find('thread', $cond, $order, 1, 1000, 'tid');
	if($threadlist) {
		// 批量预加载用户数据，消除 N+1 查询
		$uids = arrlist_values($threadlist, 'uid');
		$lastuids = arrlist_values($threadlist, 'lastuid');
		user_preload(array_merge($uids, $lastuids));

		foreach($threadlist as &$thread) thread_format($thread);
	}
	// hook model_thread_find_by_tids_end.php
	return $threadlist;
}

// 查找已删除帖子（回收站用）
function thread_find_deleted($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook model_thread_find_deleted_start.php
	$cond['is_deleted'] = 1;
	if(empty($orderby)) {
		$orderby = array('deleted_date'=>-1);
	}
	$threadlist = db_find('thread', $cond, $orderby, $page, $pagesize, 'tid');
	if($threadlist) {
		// 批量预加载用户数据
		$uids = arrlist_values($threadlist, 'uid');
		$deleted_by_uids = arrlist_values($threadlist, 'deleted_by');
		user_preload(array_merge($uids, $deleted_by_uids));

		foreach ($threadlist as &$thread) thread_format($thread);
	}
	// hook model_thread_find_deleted_end.php
	return $threadlist;
}

// 查找 lastpid
function thread_find_lastpid($tid) {
	$arr = db_find_one("post", array('tid'=>$tid), array('pid'=>-1), array('pid'));
	$lastpid = empty($arr) ? 0 : $arr['pid'];
	return $lastpid;
}

// 更新最后的 uid pid
function thread_update_last($tid) {
	$lastpid = thread_find_lastpid($tid);
	if(empty($lastpid)) return;
	
	$lastpost = post__read($lastpid);
	if(empty($lastpost)) return;
	
	$r = thread__update($tid, array('lastpid'=>$lastpid, 'lastuid'=>$lastpost['uid'], 'last_date'=>$lastpost['create_date']));

	return $r;
}

// hook model_thread_end.php

?>