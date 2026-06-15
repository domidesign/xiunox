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

function notify_create($uid, $from_uid, $type, $tid = 0, $pid = 0, $content = '') {
	global $time;
	if($uid == $from_uid) return TRUE;

	// 点赞/收藏防抖：同一用户对同一帖子的同类型通知，30秒内不重复发送
	if($type === 'like' || $type === 'favorite') {
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
		'create_date' => $time,
		'is_read' => 0,
	);
	$r = notify__create($arr);
	return $r;
}

function notify_read($nid) {
	$notify = notify__read($nid);
	if(!empty($notify)) {
		notify_format($notify);
	}
	return $notify;
}

function notify_find_by_uid($uid, $page = 1, $pagesize = 20) {
	$notifylist = db_find('notify', array('uid'=>$uid), array('nid'=>-1), $page, $pagesize, 'nid');
	if($notifylist) foreach($notifylist as &$notify) notify_format($notify);
	return $notifylist;
}

function notify_count_unread($uid) {
	$n = db_count('notify', array('uid'=>$uid, 'is_read'=>0));
	return $n;
}

function notify_mark_read($nid) {
	$r = notify__update($nid, array('is_read'=>1));
	return $r;
}

function notify_mark_all_read($uid) {
	global $db;
	$tablepre = $db->tablepre;
	db_exec("UPDATE {$tablepre}notify SET is_read=1 WHERE uid='$uid' AND is_read=0");
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

function notify_format(&$notify) {
	if(empty($notify)) return;
	global $conf;
	$notify['create_date_fmt'] = humandate($notify['create_date']);
	$from_user = user_read_cache($notify['from_uid']);
	$notify['from_username'] = $from_user ? $from_user['username'] : lang('guest');
	$notify['from_avatar_url'] = $from_user ? $from_user['avatar_url'] : '/view/img/avatar.png';

	$notify['url'] = '';
	if($notify['tid'] > 0) {
		$notify['url'] = url('thread-'.$notify['tid']);
	}

	// 根据 tid 获取帖子标题和链接
	$thread_subject = '';
	$thread_url = '';
	if($notify['tid'] > 0) {
		$_thread = thread_read_cache($notify['tid']);
		if(!empty($_thread)) {
			$thread_subject = $_thread['subject'];
		}
		$thread_url = url('thread-'.$notify['tid']);
	}

	$notify['message'] = '';
	$notify['summary'] = '';
	$notify['type_label'] = '';
	// 类型标签映射
	$label_map = array(
		'like'=>lang('notify_type_label_like'),
		'reply'=>lang('notify_type_label_reply'),
		'follow'=>lang('notify_type_label_follow'),
		'favorite'=>lang('notify_type_label_favorite'),
		'thread'=>lang('notify_type_label_thread'),
		'forum_post'=>lang('notify_type_label_forum_post'),
		'mention'=>'提及',
		'audit_pending'=>lang('notify_type_label_audit_pending'),
		'audit_approve'=>lang('notify_type_label_audit_approve'),
		'audit_reject'=>lang('notify_type_label_audit_reject'),
		'report_auto_audit'=>'举报审核',
	);
	$notify['type_label'] = isset($label_map[$notify['type']]) ? $label_map[$notify['type']] : lang('notify_type_label_notice_other');

	// 帖子标题链接（截断超长标题）
	$subject_short = $thread_subject ? (mb_strlen($thread_subject) > 30 ? mb_substr($thread_subject, 0, 30).'...' : $thread_subject) : '';
	$subject_link = $subject_short && $thread_url ? '<a href="'.$thread_url.'">'.htmlspecialchars($subject_short).'</a>' : '';

	switch($notify['type']) {
		case 'thread':
			$notify['summary'] = lang('notify_summary_thread');
			$notify['message'] = $notify['from_username'].' '.lang('notify_posted_thread').($subject_link ? ' '.$subject_link : '');
			break;
		case 'like':
			$notify['summary'] = lang('notify_summary_like');
			$notify['message'] = $notify['from_username'].' '.lang('notify_liked_post').($subject_link ? ' '.$subject_link : '');
			break;
		case 'favorite':
			$notify['summary'] = lang('notify_summary_favorite');
			$notify['message'] = $notify['from_username'].' '.lang('notify_favorited_post').($subject_link ? ' '.$subject_link : '');
			break;
		case 'follow':
			$notify['summary'] = lang('notify_summary_follow');
			$notify['message'] = $notify['from_username'].' '.lang('notify_followed_you');
			break;
		case 'reply':
			$notify['summary'] = lang('notify_summary_reply');
			// 回复评论：显示原评论内容 + 回复内容 + 帖子链接
			$_reply_content = $notify['content'] ? strip_tags($notify['content']) : '';
			$_reply_short = $_reply_content ? (mb_strlen($_reply_content) > 50 ? mb_substr($_reply_content, 0, 50).'...' : $_reply_content) : '';
			$notify['message'] = $notify['from_username'].' '.lang('notify_replied_comment');
			if($_reply_short) {
				$notify['message'] .= '：'.$_reply_short;
			}
			if($subject_link) {
				$notify['message'] .= ' — '.$subject_link;
			}
			break;
		case 'forum_post':
			$notify['summary'] = lang('notify_summary_forum_post');
			$notify['message'] = $notify['from_username'].' '.lang('notify_forum_new_post').($subject_link ? ' '.$subject_link : '').': '.$notify['content'];
			break;
		case 'mention':
			$notify['summary'] = '提及了你';
			$notify['message'] = $notify['from_username'].' '.$notify['content'].($subject_link ? ' — '.$subject_link : '');
			break;
		case 'audit_pending':
			$notify['summary'] = lang('notify_summary_audit_pending');
			$notify['message'] = $notify['content'] ? $notify['content'] : lang('notify_audit_pending_default');
			break;
		case 'audit_approve':
			$notify['summary'] = lang('notify_summary_audit_approve');
			$notify['message'] = $notify['content'] ? $notify['content'] : lang('notify_audit_approve_default');
			break;
		case 'audit_reject':
			$notify['summary'] = lang('notify_summary_audit_reject');
			$notify['message'] = $notify['content'] ? $notify['content'] : lang('notify_audit_reject_default');
			break;
		case 'report_auto_audit':
			$notify['summary'] = '举报审核';
			$notify['message'] = $notify['content'] ? $notify['content'] : '举报内容已自动审核处理';
			break;
	}
}

// hook model_notify_end.php

?>
