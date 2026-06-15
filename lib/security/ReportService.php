<?php
!defined('DEBUG') AND exit('Access Denied.');

// 加载举报插件配置函数
include_once APP_PATH . 'plugin/xnx_report/common.php';

/**
 * 举报服务 - 举报管理 + 自动审核触发 + 通知
 */
class ReportService {

    const STATUS_PENDING = 0;   // 待处理
    const STATUS_HANDLED = 1;   // 已处理
    const STATUS_DISMISSED = 2; // 已驳回

    // 举报分类
    const REASON_TYPES = [
        'spam' => '垃圾广告',
        'porn' => '色情低俗',
        'illegal' => '违法违规',
        'attack' => '人身攻击',
        'flood' => '恶意刷屏',
        'fake' => '虚假信息',
        'other' => '其他',
    ];

    /**
     * 创建举报
     * @param int $uid 举报人UID
     * @param string $target_type thread/post/user
     * @param int $target_id 目标ID
     * @param string $reason_type 举报分类
     * @param string $reason_text 补充说明
     * @return array ['code' => int, 'message' => string]
     */
    public static function create_report(int $uid, string $target_type, int $target_id, string $reason_type, string $reason_text = ''): array {
        global $time, $longip;

        // 参数校验
        if (!in_array($target_type, ['thread', 'post', 'user'])) {
            return ['code' => -1, 'message' => '无效的举报类型'];
        }
        if (!isset(self::REASON_TYPES[$reason_type])) {
            return ['code' => -1, 'message' => '无效的举报原因'];
        }
        if (mb_strlen($reason_text) > 200) {
            return ['code' => -1, 'message' => '补充说明不能超过200字'];
        }

        // 检查重复举报
        if (self::check_duplicate($uid, $target_type, $target_id)) {
            return ['code' => -1, 'message' => '您已举报过该内容'];
        }

        // 检查每日上限
        $limit_result = self::check_daily_limit($uid);
        if (!$limit_result['pass']) {
            return ['code' => -1, 'message' => $limit_result['message']];
        }

        // 检查冷却时间
        $cooldown_result = self::check_cooldown($uid);
        if (!$cooldown_result['pass']) {
            return ['code' => -1, 'message' => $cooldown_result['message']];
        }

        // 验证目标是否存在
        if (!self::validate_target($target_type, $target_id)) {
            return ['code' => -1, 'message' => '举报目标不存在'];
        }

        // 不能举报自己
        if (self::is_self($uid, $target_type, $target_id)) {
            return ['code' => -1, 'message' => '不能举报自己'];
        }

        // 创建记录
        $r = db_create('report', [
            'uid' => $uid,
            'target_type' => $target_type,
            'target_id' => $target_id,
            'reason_type' => $reason_type,
            'reason_text' => $reason_text,
            'status' => self::STATUS_PENDING,
            'handler_uid' => 0,
            'handle_result' => '',
            'handle_date' => 0,
            'create_date' => $time,
            'ip' => $longip,
        ]);

        if ($r === false) {
            return ['code' => -1, 'message' => '举报失败，请稍后重试'];
        }

        // 检查是否达到自动审核阈值
        self::handle_auto_audit($target_type, $target_id);

        return ['code' => 0, 'message' => '举报成功，我们会尽快处理'];
    }

    /**
     * 检查重复举报
     */
    public static function check_duplicate(int $uid, string $target_type, int $target_id): bool {
        $count = db_count('report', [
            'uid' => $uid,
            'target_type' => $target_type,
            'target_id' => $target_id,
        ]);
        return $count > 0;
    }

    /**
     * 检查每日举报上限
     */
    public static function check_daily_limit(int $uid): array {
        $limit = xnx_report_get('daily_limit', 20);
        if ($limit <= 0) return ['pass' => true];

        global $time;
        $today_start = strtotime('today');
        $count = db_count('report', [
            'uid' => $uid,
            'create_date' => ['>=' => $today_start],
        ]);

        if ($count >= $limit) {
            return ['pass' => false, 'message' => '今日举报次数已达上限'];
        }
        return ['pass' => true];
    }

    /**
     * 检查举报冷却时间
     */
    public static function check_cooldown(int $uid): array {
        $cooldown = xnx_report_get('cooldown', 60);
        if ($cooldown <= 0) return ['pass' => true];

        global $time;
        $last = db_find_one('report', ['uid' => $uid], ['reportid' => -1]);
        if ($last && ($time - $last['create_date']) < $cooldown) {
            $remain = $cooldown - ($time - $last['create_date']);
            return ['pass' => false, 'message' => "举报过于频繁，请{$remain}秒后再试"];
        }
        return ['pass' => true];
    }

    /**
     * 获取内容的举报次数（不同用户）
     */
    public static function get_report_count(string $target_type, int $target_id): int {
        return db_count('report', [
            'target_type' => $target_type,
            'target_id' => $target_id,
        ]);
    }

    /**
     * 自动审核触发 - 达到阈值时将内容标记为待审核
     */
    public static function handle_auto_audit(string $target_type, int $target_id): bool {
        $threshold = xnx_report_get('auto_audit_count', 3);
        if ($threshold <= 0) return false;

        $count = self::get_report_count($target_type, $target_id);
        if ($count < $threshold) return false;

        // 检查是否已经处于待审核状态，避免重复操作
        if ($target_type === 'thread') {
            $thread = thread_read($target_id);
            if (!empty($thread) && $thread['audit_status'] == 0) return false;

            // 设置为待审核
            db_update('thread', ['tid' => $target_id], ['audit_status' => 0]);

            // 通知管理员（使用 notice 系统给管理员组发通知）
            self::notify_admins('thread', $target_id, $count);
        } elseif ($target_type === 'post') {
            $post = post_read($target_id);
            if (!empty($post) && isset($post['audit_status']) && $post['audit_status'] == 0) return false;

            db_update('post', ['pid' => $target_id], ['audit_status' => 0]);
            self::notify_admins('post', $target_id, $count);
        } elseif ($target_type === 'user') {
            // 用户被举报不自动封禁，只通知管理员
            self::notify_admins('user', $target_id, $count);
        }

        return true;
    }

    /**
     * 通知管理员
     */
    private static function notify_admins(string $target_type, int $target_id, int $count): void {
        // 获取管理员列表（gid=1）
        $admins = db_find('user', ['gid' => 1], [], 1, 10);
        if (empty($admins)) return;

        $type_text = ['thread' => '帖子', 'post' => '评论', 'user' => '用户'];
        $message = "{$type_text[$target_type]}被{$count}人举报，已自动进入审核";

        foreach ($admins as $admin) {
            // 使用 notify_create 发送系统通知
            if (function_exists('notify_create')) {
                notify_create($admin['uid'], 0, 'report_auto_audit', $target_id, 0, $message);
            }
        }
    }

    /**
     * 验证举报目标是否存在
     */
    public static function validate_target(string $target_type, int $target_id): bool {
        if ($target_type === 'thread') {
            $thread = thread_read($target_id);
            return !empty($thread);
        } elseif ($target_type === 'post') {
            $post = post_read($target_id);
            return !empty($post);
        } elseif ($target_type === 'user') {
            $user = user_read($target_id);
            return !empty($user);
        }
        return false;
    }

    /**
     * 检查是否举报自己
     */
    private static function is_self(int $uid, string $target_type, int $target_id): bool {
        if ($target_type === 'user') {
            return $uid == $target_id;
        }
        if ($target_type === 'thread') {
            $thread = thread_read($target_id);
            return !empty($thread) && $thread['uid'] == $uid;
        }
        if ($target_type === 'post') {
            $post = post_read($target_id);
            return !empty($post) && $post['uid'] == $uid;
        }
        return false;
    }

    /**
     * 获取举报列表（后台管理用）
     */
    public static function get_list(array $cond = [], int $page = 1, int $pagesize = 20): array {
        $list = db_find('report', $cond, ['reportid' => -1], $page, $pagesize);
        if ($list) {
            foreach ($list as &$item) {
                $reporter = user_read_cache($item['uid']);
                $item['username'] = $reporter['username'] ?? '';
                $item['avatar_url'] = $reporter['avatar_url'] ?? '';
                $item['reason_type_text'] = self::REASON_TYPES[$item['reason_type']] ?? '未知';
                $item['create_date_fmt'] = date('Y-m-d H:i', $item['create_date']);

                // 获取被举报目标的信息
                if ($item['target_type'] === 'thread') {
                    $target = thread_read($item['target_id']);
                    $item['target_title'] = $target['subject'] ?? '已删除';
                    $target_user = !empty($target) ? user_read_cache($target['uid']) : null;
                    $item['target_username'] = $target_user['username'] ?? '';
                } elseif ($item['target_type'] === 'post') {
                    $target = post_read($item['target_id']);
                    $item['target_title'] = mb_substr($target['message'] ?? '已删除', 0, 50);
                    $target_user = !empty($target) ? user_read_cache($target['uid']) : null;
                    $item['target_username'] = $target_user['username'] ?? '';
                } elseif ($item['target_type'] === 'user') {
                    $target = user_read_cache($item['target_id']);
                    $item['target_title'] = $target['username'] ?? '已删除';
                    $item['target_username'] = $target['username'] ?? '';
                }

                // 处理人信息
                if ($item['handler_uid'] > 0) {
                    $handler = user_read_cache($item['handler_uid']);
                    $item['handler_username'] = $handler['username'] ?? '';
                } else {
                    $item['handler_username'] = '';
                }
            }
            unset($item);
        }
        return $list ?: [];
    }

    /**
     * 获取举报数量
     */
    public static function get_count(array $cond = []): int {
        return db_count('report', $cond);
    }

    /**
     * 处理举报
     * @param int $reportid 举报ID
     * @param int $handler_uid 处理人UID
     * @param string $action dismiss/delete/ban
     * @param string $reason 处理原因
     * @return array
     */
    public static function handle_report(int $reportid, int $handler_uid, string $action, string $reason = ''): array {
        global $time;

        $report = db_read('report', ['reportid' => $reportid]);
        if (empty($report)) {
            return ['code' => -1, 'message' => '举报记录不存在'];
        }
        if ($report['status'] != self::STATUS_PENDING) {
            return ['code' => -1, 'message' => '该举报已处理'];
        }

        // 更新举报状态
        $status = ($action === 'dismiss') ? self::STATUS_DISMISSED : self::STATUS_HANDLED;
        db_update('report', ['reportid' => $reportid], [
            'status' => $status,
            'handler_uid' => $handler_uid,
            'handle_result' => $action,
            'handle_date' => $time,
        ]);

        // 执行处理动作
        $target_type = $report['target_type'];
        $target_id = $report['target_id'];

        if ($action === 'delete') {
            // 删除内容
            if ($target_type === 'thread') {
                thread_delete($target_id);
            } elseif ($target_type === 'post') {
                post_delete($target_id);
            }
        } elseif ($action === 'ban') {
            // 封禁用户
            if ($target_type === 'user') {
                user_update($target_id, ['gid' => 0]);
            } else {
                // 获取内容作者并封禁
                if ($target_type === 'thread') {
                    $thread = thread_read($target_id);
                    if (!empty($thread)) user_update($thread['uid'], ['gid' => 0]);
                } elseif ($target_type === 'post') {
                    $post = post_read($target_id);
                    if (!empty($post)) user_update($post['uid'], ['gid' => 0]);
                }
            }
        }
        // dismiss 不执行任何操作

        // 通知举报人
        $action_text = ['dismiss' => '举报被驳回', 'delete' => '违规内容已删除', 'ban' => '违规用户已封禁'];
        $notify_msg = '您举报的' . ['thread' => '帖子', 'post' => '评论', 'user' => '用户'][$target_type] . '已处理：' . ($action_text[$action] ?? '');
        if (function_exists('notify_create')) {
            notify_create($report['uid'], $handler_uid, 'report_result', $target_id, 0, $notify_msg);
        }

        // 通知被举报人（驳回不通知）
        if ($action !== 'dismiss') {
            $target_uid = 0;
            if ($target_type === 'user') {
                $target_uid = $target_id;
            } elseif ($target_type === 'thread') {
                $thread = thread_read($target_id);
                $target_uid = $thread['uid'] ?? 0;
            } elseif ($target_type === 'post') {
                $post = post_read($target_id);
                $target_uid = $post['uid'] ?? 0;
            }
            if ($target_uid > 0 && function_exists('notify_create')) {
                notify_create($target_uid, $handler_uid, 'report_penalty', $target_id, 0, '您的内容因违反社区规范已被处理');
            }
        }

        return ['code' => 0, 'message' => '处理成功'];
    }

    /**
     * 批量处理举报
     */
    public static function batch_handle(array $reportids, int $handler_uid, string $action, string $reason = ''): array {
        $success = 0;
        $fail = 0;
        foreach ($reportids as $id) {
            $result = self::handle_report(intval($id), $handler_uid, $action, $reason);
            if ($result['code'] === 0) {
                $success++;
            } else {
                $fail++;
            }
        }
        return ['code' => 0, 'message' => "处理完成：成功{$success}条，失败{$fail}条"];
    }
}
