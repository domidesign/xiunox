<?php

// hook model_notify_start.php

function notify__create($arr) {
	$r = db_insert('notify', $arr);
	return $r;
}

function notify__update($nid, $arr) {
	$r = db_update('notify', array('nid'=>$nid), $arr);
	return $r;
}

function notify__read($nid) {
	$notify = db_find_one('notify', array('nid'=>$nid));
	return $notify;
}

function notify__delete($nid) {
	$r = db_delete('notify', array('nid'=>$nid));
	return $r;
}

/**
 * 创建通知（合并后的统一接口，支持原 notice 和 notify 所有功能）
 * @param int $uid 接收者UID
 * @param int $from_uid 发送者UID（0=系统）
 * @param string $type 通知类型（like/reply/favorite/follow/thread/forum_post/mention/audit_xxx/report_xxx/announcement/system/pm/other）
 * @param int $tid 关联帖子ID
 * @param int $pid 关联回帖ID
 * @param string $content 内容摘要（纯文本）
 * @param array $extra 扩展字段：message(富文本)/icon/url/reply_to_uid/parent_pid
 * @return mixed
 */
function notify_create($uid, $from_uid, $type, $tid = 0, $pid = 0, $content = '', $extra = array()) {
	global $time, $db, $conf;
	$uid = intval($uid);
	// 校验接收者是否存在（uid=0 为系统/全局公告通知，跳过校验）
	if($uid > 0) {
		$_notify_recv = user__read($uid);
		if(empty($_notify_recv)) return FALSE;
	}
	// 系统通知（announcement/system）允许自己通知自己；其他类型不允许
	if($uid == $from_uid && !in_array($type, array('announcement', 'system', 'audit_pending'))) return TRUE;

	// 防抖：like/favorite/reply 类型，30秒内同用户同类型同帖子不重复发送
	$debounce_types = array('like', 'favorite', 'reply');
	if(in_array($type, $debounce_types)) {
		$recent = db_find_one('notify', array(
			'uid'=>$uid,
			'from_uid'=>$from_uid,
			'type'=>$type,
			'tid'=>$tid,
			'is_read'=>0,
		), array('nid'=>-1));
		if(!empty($recent) && ($time - $recent['create_date']) < 30) {
			return TRUE;
		}
	}

	$arr = array(
		'uid' => $uid,
		'from_uid' => $from_uid,
		'type' => $type,
		'tid' => $tid,
		'pid' => $pid,
		'content' => $content,
		'message' => isset($extra['message']) ? $extra['message'] : '',
		'icon' => isset($extra['icon']) ? $extra['icon'] : '',
		'url' => isset($extra['url']) ? $extra['url'] : '',
		'reply_to_uid' => isset($extra['reply_to_uid']) ? $extra['reply_to_uid'] : 0,
		'parent_pid' => isset($extra['parent_pid']) ? $extra['parent_pid'] : 0,
		'create_date' => $time,
		'is_read' => 0,
	);
	$r = notify__create($arr);

	// 更新 user.unread_notices 计数器
	if($r && !empty($db)) {
		$tablepre = $db->tablepre;
		db_exec("UPDATE {$tablepre}user SET unread_notices = unread_notices + 1 WHERE uid = '$uid'");
		// 直接 SQL 更新绕过了 user_update()，需手动清理 user 缓存，否则 user_read_cache() 仍读到旧 unread_notices
		!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");
	}
	return $r;
}

/**
 * 批量创建通知（单次 SQL 批量 INSERT，消除 N+1 INSERT 性能问题）
 *
 * 保留 notify_create 的防抖逻辑：对 like/favorite/reply 类型，30 秒内同 uid+from_uid+type+tid
 * 不重复发送（批量查询已存在的记录后过滤）。
 * 批量更新 user.unread_notices 计数器：用 CASE WHEN 单次 UPDATE，正确处理同一 uid 多条通知。
 *
 * @param array $records 关联数组数组，每条记录包含 notify 表字段
 *                       （uid, from_uid, type, tid, pid, content, message, icon, url, reply_to_uid, parent_pid）
 *                       create_date / is_read 可选，默认为当前时间 / 0
 * @return int 成功插入的记录数
 */
function notify_create_batch($records) {
	global $time, $db, $conf;
	if(empty($records) || !is_array($records)) return 0;
	if(empty($db)) return 0;
	$tablepre = $db->tablepre;

	// 系统通知类型允许自己通知自己；其他类型不允许
	$system_types = array('announcement', 'system', 'audit_pending');
	// 需要防抖的类型：30秒内同 uid+from_uid+type+tid 不重复
	$debounce_types = array('like', 'favorite', 'reply');

	// ===== 第一步：过滤自己通知自己（系统类型除外）和无效 uid =====
	$filtered = array();
	foreach($records as $rec) {
		$rec_uid = isset($rec['uid']) ? intval($rec['uid']) : 0;
		$rec_from_uid = isset($rec['from_uid']) ? intval($rec['from_uid']) : 0;
		$rec_type = isset($rec['type']) ? $rec['type'] : '';
		if(empty($rec_uid)) continue;
		if($rec_uid == $rec_from_uid && !in_array($rec_type, $system_types)) continue;
		$filtered[] = $rec;
	}
	if(empty($filtered)) return 0;

	// ===== 第二步：分离防抖类型与非防抖类型 =====
	$to_insert = array();
	$need_debounce = array();
	foreach($filtered as $rec) {
		if(in_array($rec['type'], $debounce_types)) {
			$need_debounce[] = $rec;
		} else {
			$to_insert[] = $rec;
		}
	}

	// ===== 第三步：防抖过滤（对 like/favorite/reply 类型） =====
	// 批量查询 30 秒内已存在的防抖类型未读通知，按 uid+from_uid+type+tid 去重
	if(!empty($need_debounce)) {
		$debounce_uids = array_unique(arrlist_values($need_debounce, 'uid'));
		// 只查 30 秒内的记录，减少数据量；按 nid 倒序保证取到最新
		$existing = db_find('notify', array(
			'uid' => $debounce_uids,
			'type' => $debounce_types,
			'is_read' => 0,
			'create_date>=' => $time - 30,
		), array('nid' => -1), 1, count($debounce_uids) * 10, 'nid');

		// 构建 (uid|from_uid|type|tid) => 最新记录 映射
		$debounce_map = array();
		if(!empty($existing)) {
			foreach($existing as $e) {
				$key = $e['uid'].'|'.$e['from_uid'].'|'.$e['type'].'|'.$e['tid'];
				if(!isset($debounce_map[$key]) || $e['nid'] > $debounce_map[$key]['nid']) {
					$debounce_map[$key] = $e;
				}
			}
		}

		foreach($need_debounce as $rec) {
			$key = $rec['uid'].'|'.$rec['from_uid'].'|'.$rec['type'].'|'.$rec['tid'];
			if(isset($debounce_map[$key]) && ($time - $debounce_map[$key]['create_date']) < 30) {
				// 30 秒内已有相同通知，跳过
				continue;
			}
			$to_insert[] = $rec;
		}
	}

	if(empty($to_insert)) return 0;

	// ===== 第四步：循环 db_insert() 插入（PDO 预处理防注入） =====
	// notify 表字段（与 notify_create 保持一致）
	$fields = array('uid', 'from_uid', 'type', 'tid', 'pid', 'content', 'message', 'icon', 'url', 'reply_to_uid', 'parent_pid', 'create_date', 'is_read');

	$inserted = 0;
	foreach($to_insert as $rec) {
		$row = array();
		foreach($fields as $f) {
			$v = isset($rec[$f]) ? $rec[$f] : '';
			if($f === 'create_date' && empty($v)) $v = $time;
			if($f === 'is_read' && empty($v)) $v = 0;
			$row[$f] = $v;
		}
		$r = db_insert('notify', $row);
		if($r !== FALSE) $inserted++;
	}

	if($inserted == 0) return 0;

	// ===== 第五步：批量更新 user.unread_notices 计数器 =====
	// 统计每个 uid 在本批次中的通知数（同一 uid 可能有多条通知）
	$uid_counts = array();
	foreach($to_insert as $rec) {
		$u = intval($rec['uid']);
		if(!isset($uid_counts[$u])) $uid_counts[$u] = 0;
		$uid_counts[$u]++;
	}

	if(!empty($uid_counts)) {
		// 用 CASE WHEN 单次 UPDATE，正确处理同一 uid 多条通知的计数累加
		$case_parts = array();
		$uid_list = array();
		foreach($uid_counts as $u => $cnt) {
			$case_parts[] = "WHEN ".intval($u)." THEN ".intval($cnt);
			$uid_list[] = intval($u);
		}
		$uid_in = implode(',', $uid_list);
		$case_sql = "CASE uid ".implode(' ', $case_parts)." ELSE 0 END";
		db_exec("UPDATE {$tablepre}user SET unread_notices = unread_notices + $case_sql WHERE uid IN ($uid_in)");
		// 直接 SQL 更新绕过了 user_update()，需为每个受影响 uid 清理 user 缓存
		if(!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql'))) {
			foreach($uid_counts as $u => $cnt) {
				cache_delete("user-".intval($u));
			}
		}
	}

	return $inserted;
}

function notify_read($nid) {
	$notify = notify__read($nid);
	if(!empty($notify)) {
		notify_format($notify);
	}
	return $notify;
}

/**
 * 批量预加载通知关联数据（用户/帖子/回帖），消除 notify_format 中的 N+1 查询
 * @param array $notifylist 通知列表
 * @return array 预加载数据数组，传入 notify_format 的 $prefetched 参数
 */
function notify_preload($notifylist) {
	if(empty($notifylist)) return array();
	// 批量预加载用户数据（填充 $g_static_users 缓存，user_read_cache 会命中）
	$from_uids = array_unique(array_filter(arrlist_values($notifylist, 'from_uid')));
	if(!empty($from_uids)) {
		user_preload($from_uids);
	}
	// 批量预加载 thread 数据
	$tids = array_unique(array_filter(arrlist_values($notifylist, 'tid')));
	$threads = array();
	if(!empty($tids)) {
		$threads = thread_find_by_tids($tids);
		if(empty($threads)) $threads = array();
	}
	// 批量预加载 post 数据（parent_pid）
	$parent_pids = array_unique(array_filter(arrlist_values($notifylist, 'parent_pid')));
	$posts = array();
	if(!empty($parent_pids)) {
		$posts = db_find('post', array('pid'=>$parent_pids), array(), 1, count($parent_pids), 'pid');
		if(empty($posts)) $posts = array();
	}
	return array(
		'threads' => $threads,
		'posts' => $posts,
	);
}

function notify_find_by_uid($uid, $page = 1, $pagesize = 20) {
	$notifylist = db_find('notify', array('uid'=>$uid), array('nid'=>-1), $page, $pagesize, 'nid');
	if($notifylist) {
		$prefetched = notify_preload($notifylist);
		foreach($notifylist as &$notify) notify_format($notify, $prefetched);
	}
	return $notifylist;
}

/**
 * 按类型查询用户通知
 */
function notify_find_by_uid_type($uid, $type, $page = 1, $pagesize = 20) {
	$cond = array('uid'=>$uid);
	if($type && $type !== 'all') {
		$cond['type'] = $type;
	}
	$notifylist = db_find('notify', $cond, array('nid'=>-1), $page, $pagesize, 'nid');
	if($notifylist) {
		$prefetched = notify_preload($notifylist);
		foreach($notifylist as &$notify) notify_format($notify, $prefetched);
	}
	return $notifylist;
}

/**
 * 查询用户最新N条通知（下拉菜单用）
 */
function notify_find_latest($uid, $pagesize = 8) {
	$notifylist = db_find('notify', array('uid'=>$uid), array('nid'=>-1), 1, $pagesize, 'nid');
	if($notifylist) {
		$prefetched = notify_preload($notifylist);
		foreach($notifylist as &$notify) notify_format($notify, $prefetched);
	}
	return $notifylist;
}

/**
 * 查询全局公告（uid=0 的 announcement 类型）
 */
function notify_find_announcements($pagesize = 3) {
	$notifylist = db_find('notify', array('uid'=>0, 'type'=>'announcement'), array('nid'=>-1), 1, $pagesize, 'nid');
	if($notifylist) {
		$prefetched = notify_preload($notifylist);
		foreach($notifylist as &$notify) notify_format($notify, $prefetched);
	}
	return $notifylist;
}

function notify_count_unread($uid) {
	$n = db_count('notify', array('uid'=>$uid, 'is_read'=>0));
	return $n;
}

/**
 * 统计用户通知总数
 */
function notify_count_by_uid($uid) {
	return db_count('notify', array('uid'=>$uid));
}

function notify_mark_read($nid) {
	global $db, $conf;
	$tablepre = $db->tablepre;
	// 先读取通知获取 uid
	$notify = notify__read($nid);
	$r = notify__update($nid, array('is_read'=>1));
	// 更新 user.unread_notices 计数器
	if($r && !empty($notify)) {
		$uid = intval($notify['uid']);
		db_exec("UPDATE {$tablepre}user SET unread_notices = GREATEST(0, unread_notices - 1) WHERE uid = '$uid' AND unread_notices > 0");
		// 直接 SQL 更新绕过了 user_update()，需手动清理 user 缓存
		!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");
	}
	return $r;
}

function notify_mark_all_read($uid) {
	global $db, $conf;
	$uid = intval($uid);
	$tablepre = $db->tablepre;
	db_exec("UPDATE {$tablepre}notify SET is_read=1 WHERE uid='$uid' AND is_read=0");
	// 重置 user.unread_notices 计数器
	db_exec("UPDATE {$tablepre}user SET unread_notices=0 WHERE uid='$uid'");
	// 直接 SQL 更新绕过了 user_update()，需手动清理 user 缓存
	!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");
	return TRUE;
}

function notify_delete_by_uid($uid) {
	$r = db_delete('notify', array('uid'=>$uid));
	return $r;
}

function notify_delete_by_tid($tid) {
	$r = db_delete('notify', array('tid'=>$tid));
	return $r;
}

function notify_delete($nid) {
	global $db, $conf;
	$tablepre = $db->tablepre;
	// 先读取通知获取 uid
	$notify = notify__read($nid);
	$r = notify__delete($nid);
	// 更新 user.unread_notices 计数器
	if($r && !empty($notify) && empty($notify['is_read'])) {
		$uid = intval($notify['uid']);
		db_exec("UPDATE {$tablepre}user SET unread_notices = GREATEST(0, unread_notices - 1) WHERE uid = '$uid' AND unread_notices > 0");
		// 直接 SQL 更新绕过了 user_update()，需手动清理 user 缓存
		!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");
	}
	return $r;
}

function notify_format(&$notify, $prefetched = array()) {
	if(empty($notify)) return;
	include_once APP_PATH . 'lib/NotifyTypeRegistry.php';
	global $conf, $forumlist;
	$notify['create_date_fmt'] = humandate($notify['create_date']);
	// from_uid=0 表示系统通知，user_read_cache(0) 会返回 user_guest()（非空），导致 from_username 误取“游客”，这里直接短路
	if(intval($notify['from_uid']) === 0) {
		$notify['from_username'] = lang('system');
		$notify['from_avatar_url'] = default_avatar_url();
		$notify['from_is_system'] = TRUE;
	} else {
		// 用户数据：user_preload 已填充 $g_static_users 缓存，user_read_cache 会命中
		$from_user = user_read_cache($notify['from_uid']);
		$notify['from_username'] = $from_user ? (!empty($from_user['display_name']) ? $from_user['display_name'] : $from_user['username']) : lang('system');
		$notify['from_avatar_url'] = $from_user ? $from_user['avatar_url'] : default_avatar_url();
		$notify['from_is_system'] = FALSE;
	}

	// ponytail: tid>0 时用帖子链接覆盖；tid=0 时保留 notify 表中存储的 url（如 audit_pending 的应用详情链接）。
	// 原代码无条件 `$notify['url'] = ''` 会清空 audit_pending/report_xxx 等无 tid 通知的自定义 url。
	if($notify['tid'] > 0) {
		$notify['url'] = url('thread-'.$notify['tid']);
	}
	// 防御 javascript:/data:/vbscript: 等危险协议（XSS 防护）
	if($notify['url'] !== '' && preg_match('/^\s*(javascript|data|vbscript):/i', $notify['url'])) {
		$notify['url'] = '';
	}

	// 统一预加载 thread 数据（原代码多处调用 thread_read_cache 同一 tid，合并为一次）
	$_thread = null;
	if($notify['tid'] > 0) {
		if(isset($prefetched['threads']) && isset($prefetched['threads'][$notify['tid']])) {
			$_thread = $prefetched['threads'][$notify['tid']];
		} else {
			$_thread = thread_read_cache($notify['tid']);
		}
	}

	// 根据 tid 获取帖子标题和链接
	$thread_subject = '';
	$thread_url = '';
	if($notify['tid'] > 0) {
		if(!empty($_thread)) {
			$thread_subject = $_thread['subject'];
		}
		$thread_url = url('thread-'.$notify['tid']);
	}

	// 如果 message 字段有值（原 notice 系统的数据），优先使用
	$has_message = !empty($notify['message']);

	$notify['summary'] = '';
	$notify['type_label'] = '';
	// 类型标签：通过注册中心获取（核心 19 种 type 已在 init() 时注册，未知 type 兜底为 "通知"）
	NotifyTypeRegistry::init();
	$notify['type_label'] = NotifyTypeRegistry::get_label($notify['type']);

	// 帖子标题链接（截断超长标题）
	$subject_short = $thread_subject ? (mb_strlen($thread_subject) > 30 ? mb_substr($thread_subject, 0, 30).'...' : $thread_subject) : '';
	$subject_link = ($subject_short && $thread_url) ? '<a href="'.$thread_url.'">'.htmlspecialchars($subject_short).'</a>' : '';

	// 填充模板字段：thread_subject（原帖标题）和 forum_name（版块名，用于 thread/forum_post/thread_forum）
	$notify['thread_subject'] = $thread_subject;
	$notify['subject_link'] = $subject_link;
	if(!empty($_thread) && !empty($_thread['fid'])) {
		$_fid = intval($_thread['fid']);
		if(isset($forumlist[$_fid])) {
			$notify['forum_name'] = isset($forumlist[$_fid]['name']) ? $forumlist[$_fid]['name'] : '';
		} elseif(function_exists('forum_read')) {
			$_forum = forum_read($_fid);
			$notify['forum_name'] = !empty($_forum) && isset($_forum['name']) ? $_forum['name'] : '';
		} else {
			$notify['forum_name'] = '';
		}
	} else {
		$notify['forum_name'] = '';
	}

	// 填充 quote_content：reply 类型时为被回复的评论内容（来自 parent_pid）
	$notify['quote_content'] = '';
	if($notify['type'] === 'reply' && !empty($notify['parent_pid'])) {
		$_quoted_post = array();
		if(isset($prefetched['posts']) && isset($prefetched['posts'][$notify['parent_pid']])) {
			$_quoted_post = $prefetched['posts'][$notify['parent_pid']];
		} else {
			$_quoted_post = post_read_cache($notify['parent_pid']);
		}
		if(!empty($_quoted_post) && isset($_quoted_post['message'])) {
			$_quoted_text = strip_tags($_quoted_post['message']);
			$notify['quote_content'] = mb_strlen($_quoted_text) > 20 ? mb_substr($_quoted_text, 0, 20).'...' : $_quoted_text;
		}
	}

	// 如果有 message 字段（原 notice 数据），直接使用
	if($has_message && in_array($notify['type'], array('announcement', 'system', 'pm', 'other'))) {
		$notify['summary'] = $notify['type_label'];
		$notify['message'] = $notify['message'];
		return;
	}

	// 优先使用注册中心的 message_callback（核心 type 已注册回调，闭包内封装了原 switch case 逻辑）
	$_callback = NotifyTypeRegistry::get_message_callback($notify['type']);
	if($_callback !== null) {
		$_result = call_user_func($_callback, $notify, $prefetched);
		if(isset($_result['summary'])) $notify['summary'] = $_result['summary'];
		if(isset($_result['message'])) $notify['message'] = $_result['message'];
	} else {
		// 默认分支：announcement/system/pm/other 或未知 type（无 message_callback 的 type）
		$notify['summary'] = $notify['type_label'];
		$notify['message'] = $notify['message'] ? $notify['message'] : ($notify['content'] ? $notify['content'] : '');
	}
}

// hook model_notify_end.php

?>
