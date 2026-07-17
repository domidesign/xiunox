<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * 帖子审核服务 - 三级规则 + 审核队列 + 批量操作
 * 
 * 三级审核规则：
 * 1. 版块级：forum_access.allowthreadaudit / allowpostaudit → 需要审核
 * 2. 用户组级：group.allow_direct_post == 0 → 需要审核
 * 3. 关键词触发级：敏感词过滤命中 → 需要审核
 */
class AuditService {

    const STATUS_PENDING = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;
    // 忽略：不计入未审核数量，内容保持不可见，作者无感知，不通知不积分不计统计
    // 记录仍保留在审核列表中，可后续通过或拒绝
    const STATUS_IGNORED = 3;

    // 重新提交次数上限（含首次发布）
    const MAX_RESUBMIT_COUNT = 5;

    /**
     * 判断是否需要审核（三级规则）
     * @param int $fid 版块ID
     * @param int $gid 用户组ID
     * @param string $subject 标题
     * @param string $message 内容
     * @return bool
     */
    public static function need_audit(int $fid, int $gid, string $subject, string $message): bool {
        // 第一级：版块级发帖审核（检查 forum_access 中的 allowthreadaudit）
        $forum = forum_read($fid);
        if (!empty($forum) && $forum['accesson']) {
            $access = forum_access_read($fid, $gid);
            if (!empty($access) && !empty($access['allowthreadaudit'])) {
                return true;
            }
        } elseif (!empty($forum) && !empty($forum['audit_thread'])) {
            // 兼容旧版：如果未开启 accesson 但 audit_thread=1，也需审核
            return true;
        }

        // 第二级：用户组级审核（统一通过 PermissionService 检查，兼容 group 表旧字段和 group_permission 表新值）
        if (!PermissionService::check('allow_direct_post')) {
            return true;
        }

        // 第三级：关键词触发级（冗余防御层：正常情况下发帖入口已直接拦截敏感词；
        // 此处保留作为防御性检查，防止其他入口绕过）
        include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
        $text = $subject . ' ' . $message;
        $result = SensitiveWordFilter::content_check($text, SensitiveWordFilter::TYPE_SENSITIVE);
        if (!$result['pass']) {
            return true;
        }

        return false;
    }

    /**
     * 判断回帖是否需要审核
     * @param int $fid 版块ID
     * @param int $gid 用户组ID
     * @param string $message 回帖内容
     * @return bool
     */
    public static function need_post_audit(int $fid, int $gid, string $message): bool {
        // 第一级：版块级审核
        $forum = forum_read($fid);
        if (!empty($forum) && !empty($forum['audit_thread'])) {
            return true;
        }

        // 第二级：用户组级回帖审核（统一通过 PermissionService 检查）
        if (!PermissionService::check('allow_direct_reply')) {
            return true;
        }

        // 第三级：关键词触发级（冗余防御层，详见 need_thread_audit 注释）
        include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
        $result = SensitiveWordFilter::content_check($message, SensitiveWordFilter::TYPE_SENSITIVE);
        if (!$result['pass']) {
            return true;
        }

        return false;
    }

    /**
     * 获取待审列表
     * @param string $type 'thread' 或 'post'
     * @param int $page 页码
     * @param int $pagesize 每页数量
     * @return array
     */
    public static function get_pending_list(string $type = 'thread', int $page = 1, int $pagesize = 20): array {
        // 包含待审(0)和已忽略(3)：已忽略的仍留在列表供后续通过/拒绝，但不计入未审核数量
        $cond = ['audit_status' => [self::STATUS_PENDING, self::STATUS_IGNORED]];

        if ($type === 'thread') {
            $list = db_find('thread', $cond, ['tid' => -1], $page, $pagesize);
            if ($list) {
                // 批量收集 uid/fid，消除 N+1 查询
                $uids = array_unique(array_column($list, 'uid'));
                $fids = array_unique(array_filter(array_column($list, 'fid')));
                $users = self::batch_read_users($uids);
                $forums = empty($fids) ? array() : db_find('forum', array('fid' => $fids), array(), 1, count($fids), 'fid');

                foreach ($list as &$item) {
                    $user = $users[$item['uid']] ?? array();
                    $item['username'] = $user['username'] ?? '';
                    $item['avatar_url'] = $user['avatar_url'] ?? '';
                    $forum = $forums[$item['fid']] ?? array();
                    $item['forum_name'] = $forum['name'] ?? '';
                }
                unset($item);
            }
        } else {
            $cond['isfirst'] = 0; // 只查回帖，排除首帖（首帖即主题内容）
            $list = db_find('post', $cond, ['pid' => -1], $page, $pagesize);
            if ($list) {
                // 批量收集 uid/tid，消除 N+1 查询
                $uids = array_unique(array_column($list, 'uid'));
                $tids = array_unique(array_column($list, 'tid'));
                $users = self::batch_read_users($uids);
                $threads = empty($tids) ? array() : db_find('thread', array('tid' => $tids), array(), 1, count($tids), 'tid');

                foreach ($list as &$item) {
                    $user = $users[$item['uid']] ?? array();
                    $item['username'] = $user['username'] ?? '';
                    $item['avatar_url'] = $user['avatar_url'] ?? '';
                    $thread = $threads[$item['tid']] ?? array();
                    $item['subject'] = $thread['subject'] ?? '';
                }
                unset($item);
            }
        }

        return $list ?: [];
    }

    /**
     * 批量读取用户信息（带缓存和格式化），消除 N+1 查询
     * @param array $uids 用户ID数组
     * @return array [uid => user_array]
     */
    private static function batch_read_users(array $uids): array {
        if (empty($uids)) return array();
        global $g_static_users;
        $result = array();
        $missing_uids = array();
        // 先从缓存读取
        foreach ($uids as $uid) {
            $uid = intval($uid);
            if ($uid <= 0) continue;
            if (isset($g_static_users[$uid])) {
                $result[$uid] = $g_static_users[$uid];
            } else {
                $missing_uids[] = $uid;
            }
        }
        // 批量查询未缓存的用户
        if (!empty($missing_uids)) {
            $missing_uids = array_unique($missing_uids);
            $rows = db_find('user', array('uid' => $missing_uids), array(), 1, count($missing_uids), 'uid');
            if ($rows) {
                foreach ($rows as $row) {
                    user_format($row);
                    $g_static_users[$row['uid']] = $row;
                    $result[$row['uid']] = $row;
                }
            }
        }
        return $result;
    }

    /**
     * 获取待审数量
     */
    public static function get_pending_count(string $type = 'thread'): int {
        $cond = ['audit_status' => self::STATUS_PENDING];
        if ($type === 'post') {
            $cond['isfirst'] = 0;
        }
        return db_count($type, $cond);
    }

    /**
     * 审核通过
     */
    public static function approve(string $target_type, int $target_id, int $operator_uid): bool {
        if ($target_type === 'thread') {
            $thread = thread__read($target_id);
            if (empty($thread)) return false;

            $r = db_update('thread', ['tid' => $target_id], ['audit_status' => self::STATUS_APPROVED]);
            if ($r === false) return false;

            // 审核通过时补加帖子统计计数（创建时因待审未计入）
            $thread_uid = intval($thread['uid']);
            $tid = intval($target_id);
            $fid = intval($thread['fid']);
            $thread_uid AND user__update($thread_uid, array('threads+'=>1));
            if($fid > 0) forum__update($fid, array('threads+'=>1, 'todaythreads+'=>1));
            runtime_set('threads+', 1);
            runtime_set('todaythreads+', 1);

            // 审核通过时发放积分（发帖时已跳过）
            self::grantCredits('thread_post', intval($thread['uid']), intval($thread['fid']));

            // 通知作者：包含帖子标题
            $subject_short = mb_substr($thread['subject'], 0, 30);
            notify_create($thread['uid'], $operator_uid, 'audit_approve', $target_id, 0, lang('notify_audit_thread_approve', array('subject' => $subject_short)));

            // 审核通过后补发延迟通知（发帖时因审核未通过而跳过的通知）
            $subject = $thread['subject'];

            // 补发：通知关注该发帖人的用户
            $follow_uids = user_follow_find_following_uids_reverse($thread_uid);
            if(!empty($follow_uids)) {
                foreach($follow_uids as $fuid) {
                    if($fuid == $thread_uid) continue;
                    notify_create($fuid, $thread_uid, 'thread', $tid, 0, $subject);
                }
            }

            // 补发：通知关注该版块的用户（含去重和频次控制）
            $_followers = forum_follow_find_by_fid($fid);
            if($_followers) {
                $_thread_short = mb_substr($subject, 0, 30);
                global $time;

                // 批量收集关注者 uid（排除发帖人），消除 N+1 查询
                $_follow_uids = array();
                foreach($_followers as $_follow) {
                    if($_follow['uid'] == $thread_uid) continue;
                    $_follow_uids[] = intval($_follow['uid']);
                }

                if(!empty($_follow_uids)) {
                    // 批量查询：已存在的 thread 类型通知（用于去重合并）
                    $_existing_list = db_find('notify', array(
                        'uid' => $_follow_uids,
                        'type' => 'thread',
                        'tid' => $tid,
                    ), array('nid' => -1), 1, count($_follow_uids), 'uid');

                    // 批量查询：每个用户最近的 forum_post 通知（用于频次控制）
                    $_recent_list = db_find('notify', array(
                        'uid' => $_follow_uids,
                        'type' => 'forum_post',
                    ), array('nid' => -1), 1, count($_follow_uids), 'uid');

                    foreach($_followers as $_follow) {
                        if($_follow['uid'] == $thread_uid) continue;
                        $_fuid = intval($_follow['uid']);

                        // 去重：检查是否已有该帖的 thread 类型通知（关注了发帖人）
                        $_existing = $_existing_list[$_fuid] ?? null;
                        if($_existing) {
                            // 已有关注用户通知，更新为合并类型
                            notify__update($_existing['nid'], array('type' => 'thread_forum'));
                            continue;
                        }
                        // 频次控制：检查该用户最近30分钟内是否已收到 forum_post 通知
                        $_recent = $_recent_list[$_fuid] ?? null;
                        if($_recent && ($time - $_recent['create_date']) < 1800) continue;
                        notify_create($_fuid, $thread_uid, 'forum_post', $tid, 0, $_thread_short);
                    }
                }
            }

            // 补发：解析@提及并发送通知
            $post = post__read($thread['firstpid']);
            $message = $post ? $post['message'] : '';
            if(!empty($message)) {
                // 纯文本 @username
                preg_match_all('/@([a-zA-Z0-9_\x{4e00}-\x{9fa5}]+)/u', $message, $matches);
                if(!empty($matches[1])) {
                    $mentioned_usernames = array_unique($matches[1]);
                    // 批量查询提及的用户，消除 N+1 查询
                    $mentioned_users = db_find('user', array('username' => $mentioned_usernames), array(), 1, count($mentioned_usernames), 'username');
                    foreach($mentioned_usernames as $musername) {
                        $muser = $mentioned_users[$musername] ?? null;
                        if(!empty($muser) && intval($muser['uid']) != $thread_uid) {
                            notify_create($muser['uid'], $thread_uid, 'mention', $tid, 0, '在帖子中提及了你');
                        }
                    }
                }
                // 富文本 data-type="mention"
                $mentionPattern = '/<span[^>]*data-type="mention"[^>]*data-id="(\d+)"[^>]*>/';
                if(preg_match_all($mentionPattern, $message, $matches)) {
                    $mentionUids = array_unique($matches[1]);
                    $mentionUids = array_filter($mentionUids, function($muid) use ($thread_uid) {
                        return $muid != $thread_uid && $muid > 0;
                    });
                    foreach($mentionUids as $mentionUid) {
                        $mentionUid = intval($mentionUid);
                        notify_create($mentionUid, $thread_uid, 'mention', $tid, 0, '在帖子中提及了你');
                    }
                }
            }
        } else {
            $post = post__read($target_id);
            if (empty($post)) return false;

            $r = db_update('post', ['pid' => $target_id], ['audit_status' => self::STATUS_APPROVED]);
            if ($r === false) return false;

            // 审核通过时补加帖子评论数和用户回帖数（创建时因待审未计入）
            $post_uid = intval($post['uid']);
            $tid = intval($post['tid']);
            thread__update($tid, array('posts+'=>1));
            $post_uid AND user__update($post_uid, array('posts+'=>1));
            runtime_set('posts+', 1);
            runtime_set('todayposts+', 1);
            $thread = thread__read($tid);
            $fid = $thread ? intval($thread['fid']) : 0;
            if($fid > 0) forum__update($fid, array('todayposts+'=>1));

            // 审核通过时发放积分（回帖时已跳过）
            self::grantCredits('reply_post', $post_uid, $fid);
            // 被回复者积分
            if(!empty($thread) && $thread['uid'] != $post['uid']) {
                self::grantCredits('be_commented', intval($thread['uid']), $fid);
            }

            // 通知回帖作者：审核通过
            $subject_short = $thread ? mb_substr($thread['subject'], 0, 30) : '';
            notify_create($post['uid'], $operator_uid, 'audit_approve', $post['tid'], $target_id, lang('notify_audit_post_approve', array('subject' => $subject_short)));

            // 审核通过后发送延迟的通知（回帖时因审核未通过而跳过的通知）
            $post_uid = intval($post['uid']);
            $tid = intval($post['tid']);
            $pid = intval($target_id);
            $message = $post['message'];
            $quotepid = intval($post['quotepid']);

            if($quotepid > 0) {
                // 回复评论：通知被回复者
                $quotepost = post__read($quotepid);
                if(!empty($quotepost) && intval($quotepost['uid']) != $post_uid) {
                    $_reply_content = mb_substr(strip_tags($message), 0, 500);
                    notify_create($quotepost['uid'], $post_uid, 'reply', $tid, $pid, $_reply_content, array(
                        'reply_to_uid' => $quotepost['uid'],
                        'parent_pid' => $quotepid,
                    ));
                }
            } elseif(!empty($thread) && intval($thread['uid']) != $post_uid) {
                // 一级评论：通知帖子作者
                $_reply_content = mb_substr(strip_tags($message), 0, 500);
                notify_create($thread['uid'], $post_uid, 'comment', $tid, $pid, $_reply_content, array(
                    'reply_to_uid' => $thread['uid'],
                    'parent_pid' => $thread['firstpid'],
                ));
            }

            // 解析@提及并发送通知（审核通过后补发）
            if(!empty($message)) {
                // 纯文本 @username
                preg_match_all('/@([a-zA-Z0-9_\x{4e00}-\x{9fa5}]+)/u', $message, $matches);
                if(!empty($matches[1])) {
                    $mentioned_usernames = array_unique($matches[1]);
                    // 批量查询提及的用户，消除 N+1 查询
                    $mentioned_users = db_find('user', array('username' => $mentioned_usernames), array(), 1, count($mentioned_usernames), 'username');
                    foreach($mentioned_usernames as $musername) {
                        $muser = $mentioned_users[$musername] ?? null;
                        if(!empty($muser) && intval($muser['uid']) != $post_uid) {
                            notify_create($muser['uid'], $post_uid, 'mention', $tid, $pid, '在回复中提及了你');
                        }
                    }
                }
                // 富文本 data-type="mention"
                $mentionPattern = '/<span[^>]*data-type="mention"[^>]*data-id="(\d+)"[^>]*>/';
                if(preg_match_all($mentionPattern, $message, $matches)) {
                    $mentionUids = array_unique($matches[1]);
                    $mentionUids = array_filter($mentionUids, function($muid) use ($post_uid) {
                        return $muid != $post_uid && $muid > 0;
                    });
                    foreach($mentionUids as $mentionUid) {
                        $mentionUid = intval($mentionUid);
                        notify_create($mentionUid, $post_uid, 'mention', $tid, $pid, '在回复中提及了你');
                    }
                }
            }
        }

        // 记录日志
        self::log_audit($operator_uid, $target_type, $target_id, 'approve', '');

        // 清除版块帖子列表缓存（审核通过后帖子对非管理员可见，需刷新分页/列表缓存）
        if ($target_type === 'thread' && !empty($fid)) {
            if(function_exists('thread_forum_list_cache_delete')) {
                thread_forum_list_cache_delete($fid);
            }
        } elseif ($target_type === 'post' && !empty($fid)) {
            if(function_exists('thread_forum_list_cache_delete')) {
                thread_forum_list_cache_delete($fid);
            }
            // 回复审核通过后递增回帖列表版本号，使帖子详情页 60s 缓存立即失效
            if(!empty($tid) && function_exists('post_list_cache_bump_version')) {
                post_list_cache_bump_version($tid);
            }
        }
        // 清除首页帖子列表缓存（审核通过后帖子出现在首页，无法按 fid 删除）
        if(function_exists('index_list_cache_delete')) {
            index_list_cache_delete();
        }

        // 统一变量供 audit_approve_end hook 使用（thread/post 两分支变量补齐）
        $pid = isset($pid) ? $pid : ($target_type === 'post' ? intval($target_id) : 0);
        $thread_uid = isset($thread_uid) ? $thread_uid : (isset($thread['uid']) ? intval($thread['uid']) : 0);
        $post_uid = isset($post_uid) ? $post_uid : (isset($post['uid']) ? intval($post['uid']) : 0);

        // 运行时触发 audit_approve_end hook（lib/ 不走 _include 编译期注入，用 plugin_hook 运行时分发）
        // ponytail: 审核通过后补写 feed 等延迟通知，hook 在调用方作用域执行可访问 $target_type/$target_id/$tid 等
        if (function_exists('plugin_hook')) {
            plugin_hook('audit_approve_end.php');
        }

        return true;
    }

    /**
     * 审核驳回
     */
    public static function reject(string $target_type, int $target_id, int $operator_uid, string $reason = ''): bool {
        if ($target_type === 'thread') {
            $thread = thread__read($target_id);
            if (empty($thread)) return false;
            
            $r = db_update('thread', ['tid' => $target_id], [
                'audit_status' => self::STATUS_REJECTED,
                'reject_reason' => $reason,
            ]);
            if ($r === false) return false;
            
            // 通知作者：包含帖子标题和驳回原因
            $subject_short = mb_substr($thread['subject'], 0, 30);
            $content = lang('notify_audit_thread_reject', array('subject' => $subject_short));
            if ($reason) $content .= ' — ' . lang('notify_audit_reject_reason', array('reason' => $reason));
            notify_create($thread['uid'], $operator_uid, 'audit_reject', $target_id, 0, $content);
        } else {
            $post = post__read($target_id);
            if (empty($post)) return false;
            
            $r = db_update('post', ['pid' => $target_id], [
                'audit_status' => self::STATUS_REJECTED,
                'reject_reason' => $reason,
            ]);
            if ($r === false) return false;
            
            // 通知作者：包含帖子标题和驳回原因
            $thread = thread__read($post['tid']);
            $subject_short = $thread ? mb_substr($thread['subject'], 0, 30) : '';
            $content = lang('notify_audit_post_reject', array('subject' => $subject_short));
            if ($reason) $content .= ' — ' . lang('notify_audit_reject_reason', array('reason' => $reason));
            notify_create($post['uid'], $operator_uid, 'audit_reject', $post['tid'], $target_id, $content);
        }
        
        self::log_audit($operator_uid, $target_type, $target_id, 'reject', $reason);

        // 清除受影响版块/首页列表缓存（与 batch_reject 对齐：驳回后帖子状态变化影响列表过滤）
        // ponytail: thread 分支 $thread 在 431 行读取；post 分支 $thread 在 456 行读取，此处统一可用
        $fid = !empty($thread) ? intval($thread['fid']) : 0;
        if ($fid > 0 && function_exists('thread_forum_list_cache_delete')) {
            thread_forum_list_cache_delete($fid);
        }
        if (function_exists('index_list_cache_delete')) {
            index_list_cache_delete();
        }
        return true;
    }

    /**
     * 忽略审核：从不计入未审核数量，内容保持不可见，作者无感知
     * 不发通知、不计统计、不发积分
     * 记录仍保留在审核列表中，可后续通过或拒绝
     */
    public static function ignore(string $target_type, int $target_id, int $operator_uid): bool {
        $table = $target_type === 'thread' ? 'thread' : 'post';
        $pk = $target_type === 'thread' ? 'tid' : 'pid';
        $row = $target_type === 'thread' ? thread__read($target_id) : post__read($target_id);
        if (empty($row)) return false;

        // 仅待审状态可忽略（已通过/已驳回/已忽略的不重复操作）
        if (intval($row['audit_status']) !== self::STATUS_PENDING) return false;

        $r = db_update($table, [$pk => $target_id], ['audit_status' => self::STATUS_IGNORED]);
        if ($r === false) return false;

        self::log_audit($operator_uid, $target_type, $target_id, 'ignore', '');

        // 清除受影响版块/首页列表缓存（被忽略后内容仍不出现在前台，但状态变化影响管理后台统计）
        $fid = 0;
        if ($target_type === 'thread') {
            $fid = intval($row['fid']);
        } else {
            $thread = thread__read(intval($row['tid']));
            $fid = $thread ? intval($thread['fid']) : 0;
            if (!empty($thread) && function_exists('post_list_cache_bump_version')) {
                post_list_cache_bump_version(intval($row['tid']));
            }
        }
        if ($fid > 0 && function_exists('thread_forum_list_cache_delete')) {
            thread_forum_list_cache_delete($fid);
        }
        if (function_exists('index_list_cache_delete')) {
            index_list_cache_delete();
        }
        return true;
    }

    /**
     * 批量忽略
     * @return int 成功处理数
     */
    public static function batch_ignore(string $target_type, array $ids, int $operator_uid): int {
        if (empty($ids)) return 0;
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);
        $ids = array_unique($ids);
        if (empty($ids)) return 0;

        $table = $target_type === 'thread' ? 'thread' : 'post';
        $pk = $target_type === 'thread' ? 'tid' : 'pid';
        $rows = db_find($table, [$pk => $ids], [], 1, count($ids), $pk);
        if (empty($rows)) return 0;

        $valid_ids = array();
        $affected_fids = array();
        $affected_tids = array();
        foreach ($rows as $id => $row) {
            if (intval($row['audit_status']) !== self::STATUS_PENDING) continue;
            $valid_ids[] = intval($id);
            if ($target_type === 'thread') {
                $_fid = intval($row['fid']);
                if ($_fid > 0) $affected_fids[$_fid] = true;
            } else {
                $_tid = intval($row['tid']);
                $affected_tids[$_tid] = true;
            }
        }
        if (empty($valid_ids)) return 0;

        $r = db_update($table, [$pk => $valid_ids], ['audit_status' => self::STATUS_IGNORED]);
        if ($r === false) return 0;

        // 批量记录审核日志
        foreach ($valid_ids as $id) {
            self::log_audit($operator_uid, $target_type, $id, 'ignore', '');
        }

        // 回帖场景需查询关联主题以收集 fid
        if ($target_type === 'post' && !empty($affected_tids)) {
            $tmp_threads = db_find('thread', ['tid' => array_keys($affected_tids)], [], 1, count($affected_tids), 'tid');
            foreach ($tmp_threads as $_t) {
                $_fid = intval($_t['fid']);
                if ($_fid > 0) $affected_fids[$_fid] = true;
            }
        }

        foreach ($affected_fids as $_fid => $_) {
            if (function_exists('thread_forum_list_cache_delete')) {
                thread_forum_list_cache_delete($_fid);
            }
        }
        if ($target_type === 'post') {
            foreach ($affected_tids as $_tid => $_) {
                if (function_exists('post_list_cache_bump_version')) {
                    post_list_cache_bump_version($_tid);
                }
            }
        }
        if (function_exists('index_list_cache_delete')) {
            index_list_cache_delete();
        }
        return count($valid_ids);
    }

    /**
     * 忽略个人资料变更：从资料审核队列移除，不应用变更，不通知用户
     * 头像变更忽略时删除临时待审头像文件（与 reject 一致，避免文件残留）
     */
    public static function ignore_profile(int $audit_id, int $operator_uid): bool {
        $audit = user_profile_audit_read($audit_id);
        if (empty($audit)) return false;
        if ($audit['audit_status'] != self::STATUS_PENDING) return false;

        // 头像忽略时删除临时待审头像文件（与 reject_profile 行为一致）
        if ($audit['field_name'] === 'avatar') {
            global $conf;
            $avatar_dir = substr(sprintf("%09d", $audit['uid']), 0, 3).'/';
            $avatar_path = $conf['upload_path'].'avatar/'.$avatar_dir;
            foreach (array('jpg', 'png') as $_ext) {
                $pending_file = $avatar_path.$audit['uid'].'_pending_'.$audit['new_value'].'.'.$_ext;
                if (is_file($pending_file)) {
                    @unlink($pending_file);
                }
            }
        }

        global $time;
        user_profile_audit_update($audit_id, array(
            'audit_status' => self::STATUS_IGNORED,
            'operator_uid' => $operator_uid,
            'audit_date' => $time,
        ));

        self::log_audit($operator_uid, 'profile', $audit_id, 'ignore', '');
        return true;
    }

    /**
     * 批量忽略个人资料变更
     */
    public static function batch_ignore_profiles(array $ids, int $operator_uid): int {
        $count = 0;
        foreach ($ids as $id) {
            if (self::ignore_profile(intval($id), $operator_uid)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 作者重新提交审核（修改被驳回的帖子/回帖后调用）
     * 重置审核状态为待审，递增重新提交次数
     * @return array ['ok'=>bool, 'message'=>string]
     */
    public static function resubmit(string $target_type, int $target_id, int $operator_uid): array {
        if ($target_type === 'thread') {
            $thread = thread__read($target_id);
            if (empty($thread)) return ['ok'=>false, 'message'=>'帖子不存在'];
            
            // 仅驳回状态可重新提交
            if (intval($thread['audit_status']) !== self::STATUS_REJECTED) {
                return ['ok'=>false, 'message'=>'当前状态不允许重新提交'];
            }
            
            // 检查重新提交次数
            $count = intval($thread['resubmit_count'] ?? 0);
            if ($count >= self::MAX_RESUBMIT_COUNT) {
                return ['ok'=>false, 'message'=>'已达重新提交上限（'.self::MAX_RESUBMIT_COUNT.'次），请联系管理员'];
            }
            
            $r = db_update('thread', ['tid' => $target_id], [
                'audit_status' => self::STATUS_PENDING,
                'resubmit_count' => $count + 1,
                'reject_reason' => '',
            ]);
            if ($r === false) return ['ok'=>false, 'message'=>'更新失败'];

            // 清除受影响版块帖子列表缓存 + 首页缓存（与单条 approve() 对齐）
            $_fid = intval($thread['fid']);
            if($_fid > 0 && function_exists('thread_forum_list_cache_delete')) {
                thread_forum_list_cache_delete($_fid);
            }
            if(function_exists('index_list_cache_delete')) {
                index_list_cache_delete();
            }
        } else {
            $post = post__read($target_id);
            if (empty($post)) return ['ok'=>false, 'message'=>'回帖不存在'];

            if (intval($post['audit_status']) !== self::STATUS_REJECTED) {
                return ['ok'=>false, 'message'=>'当前状态不允许重新提交'];
            }

            $count = intval($post['resubmit_count'] ?? 0);
            if ($count >= self::MAX_RESUBMIT_COUNT) {
                return ['ok'=>false, 'message'=>'已达重新提交上限（'.self::MAX_RESUBMIT_COUNT.'次），请联系管理员'];
            }

            $r = db_update('post', ['pid' => $target_id], [
                'audit_status' => self::STATUS_PENDING,
                'resubmit_count' => $count + 1,
                'reject_reason' => '',
            ]);
            if ($r === false) return ['ok'=>false, 'message'=>'更新失败'];

            // 清除受影响版块帖子列表缓存 + 首页缓存（与单条 approve() 对齐）
            $_tid = intval($post['tid']);
            $_thread = thread__read($_tid);
            $_fid = $_thread ? intval($_thread['fid']) : 0;
            if($_fid > 0 && function_exists('thread_forum_list_cache_delete')) {
                thread_forum_list_cache_delete($_fid);
            }
            if(function_exists('index_list_cache_delete')) {
                index_list_cache_delete();
            }
        }

        return ['ok'=>true, 'message'=>'已重新提交审核'];
    }

    /**
     * 判断用户是否可编辑被驳回的内容
     * @return array ['can_edit'=>bool, 'reason'=>string]
     */
    public static function can_edit_rejected(string $target_type, array $target): array {
        $status = intval($target['audit_status'] ?? 1);
        if ($status !== self::STATUS_REJECTED) {
            return ['can_edit'=>true, 'reason'=>''];
        }
        $count = intval($target['resubmit_count'] ?? 0);
        if ($count >= self::MAX_RESUBMIT_COUNT) {
            return ['can_edit'=>false, 'reason'=>'已达重新提交上限（'.self::MAX_RESUBMIT_COUNT.'次），请联系管理员'];
        }
        return ['can_edit'=>true, 'reason'=>''];
    }

    /**
     * 批量通过
     * 批量更新 audit_status + 批量发送作者通知（notify_create_batch）
     * 补发通知（关注者/版块关注者/@提及）与积分发放涉及复杂逻辑，仍保留循环
     *
     * @param string $target_type 'thread' 或 'post'
     * @param array  $ids         目标ID数组
     * @param int    $operator_uid 操作者UID
     * @return int 成功处理数
     */
    public static function batch_approve(string $target_type, array $ids, int $operator_uid): int {
        // hook audit_batch_approve_start.php
        if(empty($ids)) return 0;

        // 过滤无效ID
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);
        $ids = array_unique($ids);
        if(empty($ids)) return 0;

        global $db, $time;
        if(!$db) return 0;
        $tablepre = $db->tablepre;

        if($target_type === 'thread') {
            // 1. 批量读取主题信息（一次查询，避免 N+1）
            $threads = db_find('thread', array('tid'=>$ids), array(), 1, count($ids), 'tid');
            if(empty($threads)) return 0;

            $valid_tids = array();
            $notify_records = array();
            foreach($threads as $tid=>$thread) {
                if(intval($thread['audit_status']) === self::STATUS_APPROVED) continue;
                $valid_tids[] = intval($tid);

                // 作者审核通过通知
                $subject_short = mb_substr($thread['subject'], 0, 30);
                $notify_records[] = array(
                    'uid' => intval($thread['uid']),
                    'from_uid' => $operator_uid,
                    'type' => 'audit_approve',
                    'tid' => intval($tid),
                    'pid' => 0,
                    'content' => lang('notify_audit_thread_approve', array('subject'=>$subject_short)),
                    'create_date' => $time,
                    'is_read' => 0,
                );
            }

            if(empty($valid_tids)) return 0;

            // 2. 批量更新 thread.audit_status（一次 UPDATE）
            $r = db_update('thread', array('tid'=>$valid_tids), array('audit_status'=>self::STATUS_APPROVED));
            if($r === false) return 0;

            // 3. 批量发送作者通知（一次 SQL，复用 notify_create_batch）
            if(!empty($notify_records)) {
                notify_create_batch($notify_records);
            }

            // 4. 批量发放积分 + 补发延迟通知（关注者/版块关注者/@提及）
            // 这部分逻辑涉及各自的关注关系查询、@提及解析、防抖去重，难以完全合并为单条 SQL
            // 仍按 tid 循环处理，但 audit_status 已批量更新，主要 N+1 UPDATE 已消除
            $affected_fids = array();
            foreach($valid_tids as $tid) {
                $thread = $threads[$tid];

                // 审核通过时补加帖子统计计数（创建时因待审未计入）
                $thread_uid = intval($thread['uid']);
                $fid = intval($thread['fid']);
                $thread_uid AND user__update($thread_uid, array('threads+'=>1));
                if($fid > 0) forum__update($fid, array('threads+'=>1, 'todaythreads+'=>1));
                runtime_set('threads+', 1);
                runtime_set('todaythreads+', 1);

                // 审核通过时发放积分（发帖时已跳过）
                self::grantCredits('thread_post', $thread_uid, $fid);

                // 补发延迟通知（关注该发帖人的用户 / 关注该版块的用户 / @提及）
                self::sendDelayedNotificationsForThread($thread, $operator_uid);

                // 收集受影响版块用于缓存清理
                if($fid > 0) $affected_fids[$fid] = true;
            }

            // 5. 批量记录审核日志
            foreach($valid_tids as $tid) {
                self::log_audit($operator_uid, 'thread', $tid, 'approve', '');
            }

            // 6. 清除受影响版块帖子列表缓存 + 首页缓存（审核通过后帖子对非管理员可见）
            foreach($affected_fids as $_fid => $_) {
                if(function_exists('thread_forum_list_cache_delete')) {
                    thread_forum_list_cache_delete($_fid);
                }
            }
            if(function_exists('index_list_cache_delete')) {
                index_list_cache_delete();
            }

            return count($valid_tids);

        } else {
            // post 类型
            $posts = db_find('post', array('pid'=>$ids), array(), 1, count($ids), 'pid');
            if(empty($posts)) return 0;

            $valid_pids = array();
            $notify_records = array();
            $post_threads = array();  // pid => thread
            foreach($posts as $pid=>$post) {
                if(intval($post['audit_status']) === self::STATUS_APPROVED) continue;
                $valid_pids[] = intval($pid);

                // 关联主题（批量查询避免 N+1）
                $tid = intval($post['tid']);
                if(!isset($post_threads[$pid])) $post_threads[$pid] = null;
                $_tmp_tids[$tid] = true;
            }

            // 批量查询关联主题
            $tmp_tids = empty($_tmp_tids) ? array() : array_keys($_tmp_tids);
            $tmp_threads = empty($tmp_tids) ? array() : db_find('thread', array('tid'=>$tmp_tids), array(), 1, count($tmp_tids), 'tid');
            foreach($posts as $pid=>$post) {
                $tid = intval($post['tid']);
                $post_threads[$pid] = isset($tmp_threads[$tid]) ? $tmp_threads[$tid] : null;
                $thread = $post_threads[$pid];
                $subject_short = $thread ? mb_substr($thread['subject'], 0, 30) : '';
                $notify_records[] = array(
                    'uid' => intval($post['uid']),
                    'from_uid' => $operator_uid,
                    'type' => 'audit_approve',
                    'tid' => intval($post['tid']),
                    'pid' => intval($pid),
                    'content' => lang('notify_audit_post_approve', array('subject'=>$subject_short)),
                    'create_date' => $time,
                    'is_read' => 0,
                );
            }

            if(empty($valid_pids)) return 0;

            // 批量更新 post.audit_status
            $r = db_update('post', array('pid'=>$valid_pids), array('audit_status'=>self::STATUS_APPROVED));
            if($r === false) return 0;

            // 批量发送作者通知
            if(!empty($notify_records)) {
                notify_create_batch($notify_records);
            }

            // 补加帖子评论数和用户回帖数（创建时因待审未计入）+ 积分发放 + 补发延迟通知（仍按 pid 循环）
            $affected_fids = array();
            $affected_tids = array();
            foreach($valid_pids as $pid) {
                $post = $posts[$pid];
                $thread = $post_threads[$pid];
                $fid = $thread ? intval($thread['fid']) : 0;

                // 补加 posts 计数
                thread__update(intval($post['tid']), array('posts+'=>1));
                $post_uid = intval($post['uid']);
                $post_uid AND user__update($post_uid, array('posts+'=>1));
                runtime_set('posts+', 1);
                runtime_set('todayposts+', 1);
                if($fid > 0) forum__update($fid, array('todayposts+'=>1));

                // 回帖积分 + 被回复者积分
                self::grantCredits('reply_post', intval($post['uid']), $fid);
                if(!empty($thread) && $thread['uid'] != $post['uid']) {
                    self::grantCredits('be_commented', intval($thread['uid']), $fid);
                }

                // 补发延迟通知（回复/提及）
                self::sendDelayedNotificationsForPost($post, $thread, $operator_uid);

                // 收集受影响版块和主题用于缓存清理
                if($fid > 0) $affected_fids[$fid] = true;
                $affected_tids[intval($post['tid'])] = true;
            }

            // 批量记录审核日志
            foreach($valid_pids as $pid) {
                self::log_audit($operator_uid, 'post', $pid, 'approve', '');
            }

            // 清除受影响版块帖子列表缓存 + 首页缓存（审核通过后回帖对非管理员可见）
            foreach($affected_fids as $_fid => $_) {
                if(function_exists('thread_forum_list_cache_delete')) {
                    thread_forum_list_cache_delete($_fid);
                }
            }
            // 递增回帖列表版本号，使帖子详情页 60s 缓存立即失效
            foreach($affected_tids as $_tid => $_) {
                if(function_exists('post_list_cache_bump_version')) {
                    post_list_cache_bump_version($_tid);
                }
            }
            if(function_exists('index_list_cache_delete')) {
                index_list_cache_delete();
            }

            return count($valid_pids);
        }
    }

    /**
     * 批量驳回
     * 批量更新 audit_status + reject_reason + 批量发送作者通知
     *
     * @param string $target_type 'thread' 或 'post'
     * @param array  $ids         目标ID数组
     * @param int    $operator_uid 操作者UID
     * @param string $reason      驳回原因
     * @return int 成功处理数
     */
    public static function batch_reject(string $target_type, array $ids, int $operator_uid, string $reason = ''): int {
        // hook audit_batch_reject_start.php
        if(empty($ids)) return 0;

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);
        $ids = array_unique($ids);
        if(empty($ids)) return 0;

        global $time;

        if($target_type === 'thread') {
            // 1. 批量读取主题信息（一次查询）
            $threads = db_find('thread', array('tid'=>$ids), array(), 1, count($ids), 'tid');
            if(empty($threads)) return 0;

            $valid_tids = array();
            $notify_records = array();
            $affected_fids = array();
            foreach($threads as $tid=>$thread) {
                if(intval($thread['audit_status']) === self::STATUS_REJECTED) continue;
                $valid_tids[] = intval($tid);

                $subject_short = mb_substr($thread['subject'], 0, 30);
                $content = lang('notify_audit_thread_reject', array('subject'=>$subject_short));
                if($reason) $content .= ' — ' . lang('notify_audit_reject_reason', array('reason'=>$reason));
                $notify_records[] = array(
                    'uid' => intval($thread['uid']),
                    'from_uid' => $operator_uid,
                    'type' => 'audit_reject',
                    'tid' => intval($tid),
                    'pid' => 0,
                    'content' => $content,
                    'create_date' => $time,
                    'is_read' => 0,
                );

                // 收集受影响版块用于缓存清理
                $_fid = intval($thread['fid']);
                if($_fid > 0) $affected_fids[$_fid] = true;
            }

            if(empty($valid_tids)) return 0;

            // 2. 批量更新 thread.audit_status + reject_reason（一次 UPDATE）
            $r = db_update('thread', array('tid'=>$valid_tids), array(
                'audit_status' => self::STATUS_REJECTED,
                'reject_reason' => $reason,
            ));
            if($r === false) return 0;

            // 3. 批量发送作者通知
            if(!empty($notify_records)) {
                notify_create_batch($notify_records);
            }

            // 4. 批量记录审核日志
            foreach($valid_tids as $tid) {
                self::log_audit($operator_uid, 'thread', $tid, 'reject', $reason);
            }

            // 5. 清除受影响版块帖子列表缓存 + 首页缓存（驳回后帖子状态变化影响列表）
            foreach($affected_fids as $_fid => $_) {
                if(function_exists('thread_forum_list_cache_delete')) {
                    thread_forum_list_cache_delete($_fid);
                }
            }
            if(function_exists('index_list_cache_delete')) {
                index_list_cache_delete();
            }

            return count($valid_tids);

        } else {
            // post 类型
            $posts = db_find('post', array('pid'=>$ids), array(), 1, count($ids), 'pid');
            if(empty($posts)) return 0;

            $valid_pids = array();
            $notify_records = array();
            $_tmp_tids = array();
            foreach($posts as $pid=>$post) {
                if(intval($post['audit_status']) === self::STATUS_REJECTED) continue;
                $valid_pids[] = intval($pid);
                $_tmp_tids[intval($post['tid'])] = true;
            }
            if(empty($valid_pids)) return 0;

            // 批量查询关联主题
            $tmp_tids = array_keys($_tmp_tids);
            $tmp_threads = empty($tmp_tids) ? array() : db_find('thread', array('tid'=>$tmp_tids), array(), 1, count($tmp_tids), 'tid');

            foreach($posts as $pid=>$post) {
                if(!in_array(intval($pid), $valid_pids)) continue;
                $tid = intval($post['tid']);
                $thread = isset($tmp_threads[$tid]) ? $tmp_threads[$tid] : null;
                $subject_short = $thread ? mb_substr($thread['subject'], 0, 30) : '';
                $content = lang('notify_audit_post_reject', array('subject'=>$subject_short));
                if($reason) $content .= ' — ' . lang('notify_audit_reject_reason', array('reason'=>$reason));
                $notify_records[] = array(
                    'uid' => intval($post['uid']),
                    'from_uid' => $operator_uid,
                    'type' => 'audit_reject',
                    'tid' => intval($post['tid']),
                    'pid' => intval($pid),
                    'content' => $content,
                    'create_date' => $time,
                    'is_read' => 0,
                );
            }

            // 批量更新 post.audit_status + reject_reason
            $r = db_update('post', array('pid'=>$valid_pids), array(
                'audit_status' => self::STATUS_REJECTED,
                'reject_reason' => $reason,
            ));
            if($r === false) return 0;

            // 批量发送作者通知
            if(!empty($notify_records)) {
                notify_create_batch($notify_records);
            }

            // 批量记录审核日志
            foreach($valid_pids as $pid) {
                self::log_audit($operator_uid, 'post', $pid, 'reject', $reason);
            }

            // 清除受影响版块帖子列表缓存 + 首页缓存（驳回后回帖状态变化影响列表）
            $affected_fids = array();
            foreach($posts as $pid=>$post) {
                if(!in_array(intval($pid), $valid_pids)) continue;
                $tid = intval($post['tid']);
                if(isset($tmp_threads[$tid])) {
                    $_fid = intval($tmp_threads[$tid]['fid']);
                    if($_fid > 0) $affected_fids[$_fid] = true;
                }
            }
            foreach($affected_fids as $_fid => $_) {
                if(function_exists('thread_forum_list_cache_delete')) {
                    thread_forum_list_cache_delete($_fid);
                }
            }
            if(function_exists('index_list_cache_delete')) {
                index_list_cache_delete();
            }

            return count($valid_pids);
        }
    }

    /**
     * 审核通过后补发延迟通知（thread 类型）：关注用户/关注版块/@提及
     * 从 approve() 抽取，供 batch_approve 复用
     */
    private static function sendDelayedNotificationsForThread(array $thread, int $operator_uid): void {
        $thread_uid = intval($thread['uid']);
        $tid = intval($thread['tid']);
        $fid = intval($thread['fid']);
        $subject = $thread['subject'];

        // 补发：通知关注该发帖人的用户
        $follow_uids = user_follow_find_following_uids_reverse($thread_uid);
        if(!empty($follow_uids)) {
            foreach($follow_uids as $fuid) {
                if($fuid == $thread_uid) continue;
                notify_create($fuid, $thread_uid, 'thread', $tid, 0, $subject);
            }
        }

        // 补发：通知关注该版块的用户（含去重和频次控制）
        $_followers = forum_follow_find_by_fid($fid);
        if($_followers) {
            $_thread_short = mb_substr($subject, 0, 30);
            global $time;

            $_follow_uids = array();
            foreach($_followers as $_follow) {
                if($_follow['uid'] == $thread_uid) continue;
                $_follow_uids[] = intval($_follow['uid']);
            }

            if(!empty($_follow_uids)) {
                $_existing_list = db_find('notify', array(
                    'uid' => $_follow_uids,
                    'type' => 'thread',
                    'tid' => $tid,
                ), array('nid' => -1), 1, count($_follow_uids), 'uid');

                $_recent_list = db_find('notify', array(
                    'uid' => $_follow_uids,
                    'type' => 'forum_post',
                ), array('nid' => -1), 1, count($_follow_uids), 'uid');

                foreach($_followers as $_follow) {
                    if($_follow['uid'] == $thread_uid) continue;
                    $_fuid = intval($_follow['uid']);

                    $_existing = $_existing_list[$_fuid] ?? null;
                    if($_existing) {
                        notify__update($_existing['nid'], array('type' => 'thread_forum'));
                        continue;
                    }
                    $_recent = $_recent_list[$_fuid] ?? null;
                    if($_recent && ($time - $_recent['create_date']) < 1800) continue;
                    notify_create($_fuid, $thread_uid, 'forum_post', $tid, 0, $_thread_short);
                }
            }
        }

        // 补发：解析@提及并发送通知
        $post = post__read($thread['firstpid']);
        $message = $post ? $post['message'] : '';
        if(!empty($message)) {
            self::parseAndNotifyMentions($message, $thread_uid, $tid, 0, 'thread');
        }
    }

    /**
     * 审核通过后补发延迟通知（post 类型）：回复/被回复者/@提及
     * 从 approve() 抽取，供 batch_approve 复用
     */
    private static function sendDelayedNotificationsForPost(array $post, ?array $thread, int $operator_uid): void {
        $post_uid = intval($post['uid']);
        $tid = intval($post['tid']);
        $pid = intval($post['pid']);
        $message = $post['message'];
        $quotepid = intval($post['quotepid']);

        if($quotepid > 0) {
            $quotepost = post__read($quotepid);
            if(!empty($quotepost) && intval($quotepost['uid']) != $post_uid) {
                $_reply_content = mb_substr(strip_tags($message), 0, 500);
                notify_create($quotepost['uid'], $post_uid, 'reply', $tid, $pid, $_reply_content, array(
                    'reply_to_uid' => $quotepost['uid'],
                    'parent_pid' => $quotepid,
                ));
            }
        } elseif(!empty($thread) && intval($thread['uid']) != $post_uid) {
            $_reply_content = mb_substr(strip_tags($message), 0, 500);
            notify_create($thread['uid'], $post_uid, 'comment', $tid, $pid, $_reply_content, array(
                'reply_to_uid' => $thread['uid'],
                'parent_pid' => $thread['firstpid'],
            ));
        }

        // 解析@提及并发送通知
        if(!empty($message)) {
            self::parseAndNotifyMentions($message, $post_uid, $tid, $pid, 'post');
        }
    }

    /**
     * 解析 @提及并发送通知（审核通过后补发）
     * @param string $message    帖子/回帖内容
     * @param int    $author_uid 作者UID（用于排除自己）
     * @param int    $tid        主题ID
     * @param int    $pid        回帖ID（thread 时为 0）
     * @param string $context    'thread' 或 'post'，决定通知文案
     */
    private static function parseAndNotifyMentions(string $message, int $author_uid, int $tid, int $pid, string $context): void {
        // 纯文本 @username
        preg_match_all('/@([a-zA-Z0-9_\x{4e00}-\x{9fa5}]+)/u', $message, $matches);
        if(!empty($matches[1])) {
            $mentioned_usernames = array_unique($matches[1]);
            $mentioned_users = db_find('user', array('username' => $mentioned_usernames), array(), 1, count($mentioned_usernames), 'username');
            $notify_text = $context === 'thread' ? '在帖子中提及了你' : '在回复中提及了你';
            foreach($mentioned_usernames as $musername) {
                $muser = $mentioned_users[$musername] ?? null;
                if(!empty($muser) && intval($muser['uid']) != $author_uid) {
                    notify_create($muser['uid'], $author_uid, 'mention', $tid, $pid, $notify_text);
                }
            }
        }
        // 富文本 data-type="mention"
        $mentionPattern = '/<span[^>]*data-type="mention"[^>]*data-id="(\d+)"[^>]*>/';
        if(preg_match_all($mentionPattern, $message, $matches)) {
            $mentionUids = array_unique($matches[1]);
            $mentionUids = array_filter($mentionUids, function($muid) use ($author_uid) {
                return $muid != $author_uid && $muid > 0;
            });
            $notify_text = $context === 'thread' ? '在帖子中提及了你' : '在回复中提及了你';
            foreach($mentionUids as $mentionUid) {
                $mentionUid = intval($mentionUid);
                notify_create($mentionUid, $author_uid, 'mention', $tid, $pid, $notify_text);
            }
        }
    }

    /**
     * 审核通过个人资料变更
     */
    public static function approve_profile(int $audit_id, int $operator_uid): bool {
        $audit = user_profile_audit_read($audit_id);
        if (empty($audit)) return false;
        // 允许对待审(0)和已忽略(3)的记录执行通过操作
        if (!in_array(intval($audit['audit_status']), [self::STATUS_PENDING, self::STATUS_IGNORED], true)) return false;

        // 应用变更到用户表
        $uid = $audit['uid'];
        $field_name = $audit['field_name'];
        $new_value = $audit['new_value'];

        if ($field_name === 'avatar') {
            // 将临时头像文件移动到正式位置
            global $conf;
            $avatar_dir = substr(sprintf("%09d", $uid), 0, 3).'/';
            $avatar_path = $conf['upload_path'].'avatar/'.$avatar_dir;
            // 查找待审头像文件（兼容 jpg/png 两种格式）
            $pending_file = '';
            foreach(array('jpg', 'png') as $_ext) {
                $_pf = $avatar_path.$uid.'_pending_'.$new_value.'.'.$_ext;
                if(is_file($_pf)) {
                    $pending_file = $_pf;
                    break;
                }
            }
            $final_file = $avatar_path.$uid.'.jpg';
            if($pending_file) {
                // 清理旧格式的正式头像文件
                foreach(array('png', 'webp') as $_old_ext) {
                    $_old_file = $avatar_path.$uid.'.'.$_old_ext;
                    if(is_file($_old_file)) @unlink($_old_file);
                }
                @rename($pending_file, $final_file);
                user_update($uid, array('avatar' => intval($new_value)));
            }
            // ponytail: 临时文件不存在时（被忽略时已清理）跳过头像更新，审核状态仍标记为已通过
        } elseif ($field_name === 'signature') {
            user_update($uid, array('signature' => $new_value));
        } elseif ($field_name === 'nickname') {
            user_update($uid, array('nickname' => $new_value));
        }

        // 更新审核状态
        global $time;
        user_profile_audit_update($audit_id, array(
            'audit_status' => self::STATUS_APPROVED,
            'operator_uid' => $operator_uid,
            'audit_date' => $time,
        ));

        // 通知用户
        $field_labels = array('avatar'=>lang('admin_field_avatar'), 'signature'=>lang('admin_field_signature'), 'nickname'=>lang('admin_field_nickname'));
        $field_label = $field_labels[$field_name] ?? $field_name;
        // 昵称和签名包含新值，头像不包含
        if ($field_name === 'nickname') {
            $content = lang('notify_audit_profile_approve_with_value', array('field' => $field_label, 'value' => $new_value));
        } elseif ($field_name === 'signature') {
            $sig_short = mb_strlen($new_value) > 20 ? mb_substr($new_value, 0, 20) . '...' : $new_value;
            $content = lang('notify_audit_profile_approve_with_value', array('field' => $field_label, 'value' => $sig_short));
        } else {
            $content = lang('notify_audit_profile_approve', array('field' => $field_label));
        }
        notify_create($uid, $operator_uid, 'audit_approve', 0, 0, $content);

        // 记录日志
        self::log_audit($operator_uid, 'profile', $audit_id, 'approve', '');
        return true;
    }

    /**
     * 审核驳回个人资料变更
     */
    public static function reject_profile(int $audit_id, int $operator_uid, string $reason = ''): bool {
        $audit = user_profile_audit_read($audit_id);
        if (empty($audit)) return false;
        // 允许对待审(0)和已忽略(3)的记录执行驳回操作
        if (!in_array(intval($audit['audit_status']), [self::STATUS_PENDING, self::STATUS_IGNORED], true)) return false;

        // 头像审核驳回时删除临时头像文件
        if ($audit['field_name'] === 'avatar') {
            global $conf;
            $avatar_dir = substr(sprintf("%09d", $audit['uid']), 0, 3).'/';
            $avatar_path = $conf['upload_path'].'avatar/'.$avatar_dir;
            // 兼容 jpg/png 两种格式的待审文件
            foreach(array('jpg', 'png') as $_ext) {
                $pending_file = $avatar_path.$audit['uid'].'_pending_'.$audit['new_value'].'.'.$_ext;
                if(is_file($pending_file)) {
                    @unlink($pending_file);
                }
            }
        }

        // 更新审核状态
        global $time;
        user_profile_audit_update($audit_id, array(
            'audit_status' => self::STATUS_REJECTED,
            'operator_uid' => $operator_uid,
            'reason' => $reason,
            'audit_date' => $time,
        ));

        // 通知用户
        $field_labels = array('avatar'=>lang('admin_field_avatar'), 'signature'=>lang('admin_field_signature'), 'nickname'=>lang('admin_field_nickname'));
        $field_label = $field_labels[$audit['field_name']] ?? $audit['field_name'];
        $reject_msg = lang('notify_audit_profile_reject', array('field' => $field_label));
        if($reason) $reject_msg .= ' — ' . lang('notify_audit_reject_reason', array('reason' => $reason));
        notify_create($audit['uid'], $operator_uid, 'audit_reject', 0, 0, $reject_msg);

        self::log_audit($operator_uid, 'profile', $audit_id, 'reject', $reason);
        return true;
    }

    /**
     * 批量通过个人资料变更
     */
    public static function batch_approve_profiles(array $ids, int $operator_uid): int {
        $count = 0;
        foreach ($ids as $id) {
            if (self::approve_profile(intval($id), $operator_uid)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 获取待审个人资料列表
     */
    public static function get_pending_profile_list(int $page = 1, int $pagesize = 20): array {
        return user_profile_audit_find_pending($page, $pagesize);
    }

    /**
     * 获取待审个人资料数量
     */
    public static function get_pending_profile_count(): int {
        return user_profile_audit_count(array('audit_status' => self::STATUS_PENDING));
    }

    /**
     * 获取审核日志
     */
    public static function get_audit_logs(int $page = 1, int $pagesize = 20): array {
        $list = db_find('audit_log', [], ['create_date' => -1], $page, $pagesize);
        if ($list) {
            // 批量收集 uid，消除 N+1 查询
            $uids = array_unique(array_column($list, 'uid'));
            $users = self::batch_read_users($uids);
            foreach ($list as &$item) {
                $user = $users[$item['uid']] ?? array();
                $item['username'] = $user['username'] ?? '';
                $item['create_date_fmt'] = date('Y-m-d H:i', $item['create_date']);
            }
            unset($item);
        }
        return $list ?: [];
    }

    /**
     * 记录审核日志（同时写入 audit_log 和 admin_log）
     */
    public static function log_audit(int $uid, string $target_type, int $target_id, string $action, string $reason = ''): bool {
        global $time, $longip;

        // 写入审核日志
        $r = false;
        if(db_check_table_exists('audit_log')) {
            $arr = [
                'uid' => $uid,
                'target_type' => $target_type,
                'target_id' => $target_id,
                'action' => $action,
                'reason' => $reason,
                'create_date' => $time,
            ];
            // 兼容旧版表结构（有 operator_uid 字段）
            if(db_check_column_exists('audit_log', 'operator_uid')) {
                $arr['operator_uid'] = $uid;
            }
            $r = db_create('audit_log', $arr);
        }

        // 同时写入管理员操作日志
        $action_labels = array('approve' => '审核通过', 'reject' => '审核驳回', 'ignore' => '审核忽略');
        $action_label = $action_labels[$action] ?? '审核' . $action;
        $detail = $action_label . ' ' . $target_type . ':' . $target_id . ($reason ? ' 原因：' . $reason : '');
        admin_log_create('audit_' . $action, $target_type, strval($target_id), $detail);

        return $r !== false;
    }

    /**
     * 审核通过时补发奖励积分（仅正值部分，扣除部分已在发帖时执行）
     * @param string $event 积分事件：thread_post / reply_post / be_commented
     * @param int $uid 用户ID
     * @param int $fid 版块ID
     */
    private static function grantCredits(string $event, int $uid, int $fid): void {
        if ($uid <= 0) return;
        if (!class_exists('CreditsRuleService')) {
            include_once APP_PATH . 'service/CreditsRuleService.php';
        }
        CreditsRuleService::applyRewardOnly($event, $uid, $fid);
    }
}
