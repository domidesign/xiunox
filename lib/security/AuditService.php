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

        // 第三级：关键词触发级
        include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
        $text = $subject . ' ' . $message;
        $result = SensitiveWordFilter::content_filter($text);
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

        // 第三级：关键词触发级
        include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
        $result = SensitiveWordFilter::content_filter($message);
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
        $cond = ['audit_status' => self::STATUS_PENDING];
        
        if ($type === 'thread') {
            $list = db_find('thread', $cond, ['tid' => -1], $page, $pagesize);
            if ($list) {
                foreach ($list as &$item) {
                    $user = user_read_cache($item['uid']);
                    $item['username'] = $user['username'] ?? '';
                    $item['avatar_url'] = $user['avatar_url'] ?? '';
                    $forum = forum_read($item['fid']);
                    $item['forum_name'] = $forum['name'] ?? '';
                }
                unset($item);
            }
        } else {
            $cond['isfirst'] = 0; // 只查回帖，排除首帖（首帖即主题内容）
            $list = db_find('post', $cond, ['pid' => -1], $page, $pagesize);
            if ($list) {
                foreach ($list as &$item) {
                    $user = user_read_cache($item['uid']);
                    $item['username'] = $user['username'] ?? '';
                    $item['avatar_url'] = $user['avatar_url'] ?? '';
                    $thread = thread_read($item['tid']);
                    $item['subject'] = $thread['subject'] ?? '';
                }
                unset($item);
            }
        }
        
        return $list ?: [];
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
            $thread = thread_read($target_id);
            if (empty($thread)) return false;
            
            $r = db_update('thread', ['tid' => $target_id], ['audit_status' => self::STATUS_APPROVED]);
            if ($r === false) return false;
            
            // 通知作者：包含帖子标题
            $subject_short = mb_substr($thread['subject'], 0, 30);
            notify_create($thread['uid'], $operator_uid, 'audit_approve', $target_id, 0, lang('notify_audit_thread_approve', array('subject' => $subject_short)));
        } else {
            $post = post_read($target_id);
            if (empty($post)) return false;
            
            $r = db_update('post', ['pid' => $target_id], ['audit_status' => self::STATUS_APPROVED]);
            if ($r === false) return false;
            
            // 通知作者：包含帖子标题
            $thread = thread_read($post['tid']);
            $subject_short = $thread ? mb_substr($thread['subject'], 0, 30) : '';
            notify_create($post['uid'], $operator_uid, 'audit_approve', $post['tid'], $target_id, lang('notify_audit_post_approve', array('subject' => $subject_short)));
        }
        
        // 记录日志
        self::log_audit($operator_uid, $target_type, $target_id, 'approve', '');
        return true;
    }

    /**
     * 审核驳回
     */
    public static function reject(string $target_type, int $target_id, int $operator_uid, string $reason = ''): bool {
        if ($target_type === 'thread') {
            $thread = thread_read($target_id);
            if (empty($thread)) return false;
            
            $r = db_update('thread', ['tid' => $target_id], ['audit_status' => self::STATUS_REJECTED]);
            if ($r === false) return false;
            
            // 通知作者：包含帖子标题和驳回原因
            $subject_short = mb_substr($thread['subject'], 0, 30);
            $content = lang('notify_audit_thread_reject', array('subject' => $subject_short));
            if ($reason) $content .= ' — ' . lang('notify_audit_reject_reason', array('reason' => $reason));
            notify_create($thread['uid'], $operator_uid, 'audit_reject', $target_id, 0, $content);
        } else {
            $post = post_read($target_id);
            if (empty($post)) return false;
            
            $r = db_update('post', ['pid' => $target_id], ['audit_status' => self::STATUS_REJECTED]);
            if ($r === false) return false;
            
            // 通知作者：包含帖子标题和驳回原因
            $thread = thread_read($post['tid']);
            $subject_short = $thread ? mb_substr($thread['subject'], 0, 30) : '';
            $content = lang('notify_audit_post_reject', array('subject' => $subject_short));
            if ($reason) $content .= ' — ' . lang('notify_audit_reject_reason', array('reason' => $reason));
            notify_create($post['uid'], $operator_uid, 'audit_reject', $post['tid'], $target_id, $content);
        }
        
        self::log_audit($operator_uid, $target_type, $target_id, 'reject', $reason);
        return true;
    }

    /**
     * 批量通过
     */
    public static function batch_approve(string $target_type, array $ids, int $operator_uid): int {
        $count = 0;
        foreach ($ids as $id) {
            if (self::approve($target_type, intval($id), $operator_uid)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 批量驳回
     */
    public static function batch_reject(string $target_type, array $ids, int $operator_uid, string $reason = ''): int {
        $count = 0;
        foreach ($ids as $id) {
            if (self::reject($target_type, intval($id), $operator_uid, $reason)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 审核通过个人资料变更
     */
    public static function approve_profile(int $audit_id, int $operator_uid): bool {
        $audit = user_profile_audit_read($audit_id);
        if (empty($audit)) return false;
        if ($audit['audit_status'] != self::STATUS_PENDING) return false;

        // 应用变更到用户表
        $uid = $audit['uid'];
        $field_name = $audit['field_name'];
        $new_value = $audit['new_value'];

        if ($field_name === 'avatar') {
            user_update($uid, array('avatar' => intval($new_value)));
        } elseif ($field_name === 'signature') {
            user_update($uid, array('signature' => $new_value));
        } elseif ($field_name === 'username') {
            user_update($uid, array('username' => $new_value));
        }

        // 更新审核状态
        global $time;
        user_profile_audit_update($audit_id, array(
            'audit_status' => self::STATUS_APPROVED,
            'operator_uid' => $operator_uid,
            'audit_date' => $time,
        ));

        // 通知用户
        notify_create($uid, $operator_uid, 'audit_approve', 0, 0, '个人资料审核通过');

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
        if ($audit['audit_status'] != self::STATUS_PENDING) return false;

        // 更新审核状态
        global $time;
        user_profile_audit_update($audit_id, array(
            'audit_status' => self::STATUS_REJECTED,
            'operator_uid' => $operator_uid,
            'reason' => $reason,
            'audit_date' => $time,
        ));

        // 通知用户
        notify_create($audit['uid'], $operator_uid, 'audit_reject', 0, 0, '个人资料审核未通过' . ($reason ? '：' . $reason : ''));

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
            foreach ($list as &$item) {
                $user = user_read_cache($item['uid']);
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
        $action_label = $action === 'approve' ? '审核通过' : '审核驳回';
        $detail = $action_label . ' ' . $target_type . ':' . $target_id . ($reason ? ' 原因：' . $reason : '');
        admin_log_create('audit_' . $action, $target_type, strval($target_id), $detail);

        return $r !== false;
    }
}
