<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * 举报服务 - 举报管理 + 自动审核触发 + 通知
 * 配置通过 kv_get/kv_set 直接存取，不依赖插件 common.php
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

    // 默认配置
    const DEFAULT_CONFIG = [
        'auto_audit_count' => 3,    // 自动审核触发用户数（不同用户），0=关闭
        'daily_limit' => 20,        // 每人每日举报上限
        'cooldown' => 0,            // 举报冷却时间（秒），0=不限制；同一用户对同一内容只能举报一次为内置规则
    ];

    /**
     * 获取举报插件配置（内置，不依赖插件 common.php）
     */
    public static function get_config(): array {
        $config = kv_get('xnx_report_config');
        if ($config === NULL) {
            return self::DEFAULT_CONFIG;
        }
        return array_merge(self::DEFAULT_CONFIG, $config);
    }

    /**
     * 保存举报插件配置
     */
    public static function save_config(array $config): bool {
        return kv_set('xnx_report_config', $config);
    }

    /**
     * 获取单个配置值
     */
    public static function get_setting(string $key, $default = null) {
        $config = self::get_config();
        return $config[$key] ?? $default;
    }

    /**
     * 创建举报
     */
    public static function create_report(int $uid, string $target_type, int $target_id, string $reason_type, string $reason_text = ''): array {
        global $time, $longip;

        // 调试日志：确认入参（文件名含 error 才会在生产环境写入）
        xn_log("create_report 入参: uid={$uid}, target_type={$target_type}, target_id={$target_id}, reason_type={$reason_type}", 'report_error');

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
            xn_log("create_report 拦截-重复举报: uid={$uid}, target_type={$target_type}, target_id={$target_id}", 'report_error');
            return ['code' => -1, 'message' => '您已举报过该内容，同一内容只能举报一次'];
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
            xn_log("create_report 拦截-目标不存在: target_type={$target_type}, target_id={$target_id}", 'report_error');
            return ['code' => -1, 'message' => '举报目标不存在'];
        }

        // 不能举报自己
        if (self::is_self($uid, $target_type, $target_id)) {
            return ['code' => -1, 'message' => '不能举报自己'];
        }

        // 获取目标作者 UID（用于后续封禁），在删除前保存
        $target_uid = self::get_target_uid($target_type, $target_id);

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
            'target_uid' => $target_uid,
        ]);

        if ($r === false) {
            xn_log("create_report 失败-db_create 返回 false: uid={$uid}, target_type={$target_type}, target_id={$target_id}", 'report_error');
            return ['code' => -1, 'message' => '举报失败，请稍后重试'];
        }

        xn_log("create_report 成功: uid={$uid}, target_type={$target_type}, target_id={$target_id}, reportid={$r}", 'report_error');

        // 每次举报都通知管理员
        self::notify_admins_new($target_type, $target_id, $reason_type, $reason_text, $uid);

        // 检查是否达到自动审核阈值
        $audited = self::handle_auto_audit($target_type, $target_id);
        xn_log("handle_auto_audit 结果: audited=" . ($audited ? '1' : '0') . ", target_type={$target_type}, target_id={$target_id}", 'report_error');

        return ['code' => 0, 'message' => '举报成功，我们会尽快处理'];
    }

    /**
     * 获取目标作者 UID
     */
    public static function get_target_uid(string $target_type, int $target_id): int {
        if ($target_type === 'user') {
            return $target_id;
        } elseif ($target_type === 'thread') {
            $thread = thread_read($target_id);
            return !empty($thread) ? intval($thread['uid']) : 0;
        } elseif ($target_type === 'post') {
            $post = post_read($target_id);
            return !empty($post) ? intval($post['uid']) : 0;
        }
        return 0;
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
        xn_log("check_duplicate: uid={$uid}, target_type={$target_type}, target_id={$target_id}, count={$count}", 'report_error');
        return $count > 0;
    }

    /**
     * 检查每日举报上限
     */
    public static function check_daily_limit(int $uid): array {
        $limit = self::get_setting('daily_limit', 20);
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
        $cooldown = self::get_setting('cooldown', 60);
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
        $threshold = self::get_setting('auto_audit_count', 3);
        if ($threshold <= 0) {
            xn_log("handle_auto_audit 跳过: threshold<=0 (threshold={$threshold})", 'report_error');
            return false;
        }

        $count = self::get_report_count($target_type, $target_id);
        xn_log("handle_auto_audit: target_type={$target_type}, target_id={$target_id}, count={$count}, threshold={$threshold}", 'report_error');
        if ($count < $threshold) return false;

        // 检查是否已经处于待审核状态，避免重复操作
        if ($target_type === 'thread') {
            $thread = thread_read($target_id);
            if (!empty($thread) && $thread['audit_status'] == 0) {
                xn_log("handle_auto_audit 跳过: thread 已处于待审状态 tid={$target_id}", 'report_error');
                return false;
            }

            db_update('thread', ['tid' => $target_id], ['audit_status' => 0]);
            xn_log("handle_auto_audit 触发: thread tid={$target_id} 已设为待审", 'report_error');
            self::notify_admins('thread', $target_id, $count);
        } elseif ($target_type === 'post') {
            $post = post_read($target_id);
            if (!empty($post) && isset($post['audit_status']) && $post['audit_status'] == 0) {
                xn_log("handle_auto_audit 跳过: post 已处于待审状态 pid={$target_id}", 'report_error');
                return false;
            }

            db_update('post', ['pid' => $target_id], ['audit_status' => 0]);
            xn_log("handle_auto_audit 触发: post pid={$target_id} 已设为待审", 'report_error');
            self::notify_admins('post', $target_id, $count);
        } elseif ($target_type === 'user') {
            self::notify_admins('user', $target_id, $count);
        }

        // 触发审核后通知管理员（站内通知 + 邮件，AdminNotifyService 内置 24h 防抖）
        self::notifyAuditAdmins($target_type, $target_id, $count);

        return true;
    }

    /**
     * 通知管理员审核待办（委托 AdminNotifyService，自动防抖）
     * AdminNotifyService 内部从 setting_get('xnx_report') 读 admin_notify_enabled / admin_notify_uids
     * 语言键 report_notify_admin_subject/body 由插件 lang/{locale}.php 提供
     */
    private static function notifyAuditAdmins(string $target_type, int $target_id, int $count): void {
        if (!class_exists('AdminNotifyService', false)) {
            include_once APP_PATH . 'lib/AdminNotifyService.php';
        }
        if (!class_exists('AdminNotifyService')) return;

        // 取最新一条举报记录的 reason 作为通知正文（含分类与补充说明）
        $latest = db_find_one('report', array('target_type' => $target_type, 'target_id' => $target_id), array('reportid' => -1));
        $reason = '';
        if (!empty($latest)) {
            $reason = self::REASON_TYPES[$latest['reason_type']] ?? $latest['reason_type'];
            if (!empty($latest['reason_text'])) {
                $reason .= '：' . $latest['reason_text'];
            }
        }

        AdminNotifyService::audit(
            'xnx_report',
            'report_trigger',
            lang('report_notify_admin_subject'),
            lang('report_notify_admin_body', array('count' => $count, 'reason' => $reason)),
            url('plugin-setting-xnx_report-list-pending')
        );
    }

    /**
     * 处理完举报后检查 pending 是否清零，清零则清除审核通知防抖标记
     * 让下次新待办能再次发送（避免连续防抖导致漏通知）
     */
    private static function maybeClearAuditDebounce(): void {
        $pending = db_count('report', array('status' => self::STATUS_PENDING));
        if ($pending > 0) return;
        if (!class_exists('AdminNotifyService', false)) {
            include_once APP_PATH . 'lib/AdminNotifyService.php';
        }
        if (class_exists('AdminNotifyService')) {
            AdminNotifyService::clearDebounce('xnx_report', 'report_trigger');
        }
    }

    /**
     * 通知管理员（带防重复：同一目标只通知一次）
     */
    private static function notify_admins(string $target_type, int $target_id, int $count): void {
        global $time, $db;

        // 防重复：检查最近60秒内是否已向管理员发送过该目标的自动审核通知
        $notify_key = 'report_auto_audit_' . $target_type . '_' . $target_id;
        $cache = cache_get($notify_key);
        if (!empty($cache)) return;
        cache_set($notify_key, 1, 60);

        $admins = db_find('user', ['gid' => [1, 2]], [], 1, 10);
        if (empty($admins)) return;

        $type_text = ['thread' => '帖子', 'post' => '评论', 'user' => '用户'];
        $message = "{$type_text[$target_type]}被{$count}人举报，已自动进入审核";

        $admin_uids = array();
        foreach ($admins as $admin) {
            $admin_uids[] = intval($admin['uid']);
        }
        if (empty($admin_uids)) return;

        // 使用 notify_create 发送通知（确保兼容性）
        foreach ($admin_uids as $auid) {
            if (function_exists('notify_create')) {
                notify_create($auid, 0, 'report_auto_audit', $target_id, 0, $message);
            }
        }
    }

    /**
     * 每次举报都通知管理员（新增）
     */
    private static function notify_admins_new(string $target_type, int $target_id, string $reason_type, string $reason_text, int $reporter_uid): void {
        global $time;

        // 防刷：同一目标5秒内不重复通知
        $notify_key = 'report_new_' . $target_type . '_' . $target_id;
        $cache = cache_get($notify_key);
        if (!empty($cache)) return;
        cache_set($notify_key, 1, 5);

        // 查询管理员和超级版主（gid=1,2）
        $admins = db_find('user', ['gid' => [1, 2]], [], 1, 10);
        if (empty($admins)) return;

        $type_text = ['thread' => '帖子', 'post' => '评论', 'user' => '用户'];
        $reason_text_short = self::REASON_TYPES[$reason_type] ?? $reason_type;
        $message = "新举报：{$type_text[$target_type]}，原因：{$reason_text_short}";

        foreach ($admins as $admin) {
            $auid = intval($admin['uid']);
            if ($auid === $reporter_uid) continue; // 不通知举报人自己
            if (function_exists('notify_create')) {
                notify_create($auid, $reporter_uid, 'report_new', $target_id, 0, $message);
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
            // 批量收集所有需要查询的 ID，消除 N+1 查询
            $reporter_uids = array_unique(array_column($list, 'uid'));
            $handler_uids = array_unique(array_filter(array_column($list, 'handler_uid')));
            $thread_ids = array();
            $post_ids = array();
            $user_target_ids = array();
            foreach ($list as $item) {
                if ($item['target_type'] === 'thread') {
                    $thread_ids[] = intval($item['target_id']);
                } elseif ($item['target_type'] === 'post') {
                    $post_ids[] = intval($item['target_id']);
                } elseif ($item['target_type'] === 'user') {
                    $user_target_ids[] = intval($item['target_id']);
                }
            }

            $all_uids = array_unique(array_merge($reporter_uids, $handler_uids, $user_target_ids));
            $users = self::batch_read_users($all_uids);

            $threads = empty($thread_ids) ? array() : db_find('thread', array('tid' => $thread_ids), array(), 1, count($thread_ids), 'tid');
            $posts = empty($post_ids) ? array() : db_find('post', array('pid' => $post_ids), array(), 1, count($post_ids), 'pid');

            // 收集 thread/post 目标的作者 uid，补充查询
            $target_author_uids = array();
            foreach ($threads as $t) {
                if (!empty($t['uid'])) $target_author_uids[] = intval($t['uid']);
            }
            foreach ($posts as $p) {
                if (!empty($p['uid'])) $target_author_uids[] = intval($p['uid']);
            }
            $target_author_uids = array_diff(array_unique($target_author_uids), $all_uids);
            if (!empty($target_author_uids)) {
                $extra_users = self::batch_read_users($target_author_uids);
                $users = $users + $extra_users;
            }

            // 内存拼装
            foreach ($list as &$item) {
                $reporter = $users[$item['uid']] ?? array();
                $item['username'] = $reporter['display_name'] ?? $reporter['username'] ?? '';
                $item['avatar_url'] = $reporter['avatar_url'] ?? '';
                $item['reason_type_text'] = self::REASON_TYPES[$item['reason_type']] ?? '未知';
                $item['create_date_fmt'] = date('Y-m-d H:i', $item['create_date']);
                // IP 格式化
                $item['ip_fmt'] = long2ip($item['ip']);

                // 获取被举报目标的信息
                if ($item['target_type'] === 'thread') {
                    $target = $threads[$item['target_id']] ?? array();
                    $item['target_title'] = $target['subject'] ?? '已删除';
                    $target_user = !empty($target) ? ($users[$target['uid']] ?? array()) : array();
                    $item['target_username'] = $target_user['display_name'] ?? $target_user['username'] ?? '';
                } elseif ($item['target_type'] === 'post') {
                    $target = $posts[$item['target_id']] ?? array();
                    $item['target_title'] = mb_substr($target['message'] ?? '已删除', 0, 50);
                    // post 需要关联的 tid 用于生成链接
                    $item['target_tid'] = $target['tid'] ?? 0;
                    $target_user = !empty($target) ? ($users[$target['uid']] ?? array()) : array();
                    $item['target_username'] = $target_user['display_name'] ?? $target_user['username'] ?? '';
                } elseif ($item['target_type'] === 'user') {
                    $target = $users[$item['target_id']] ?? array();
                    $item['target_title'] = $target['display_name'] ?? $target['username'] ?? '已删除';
                    $item['target_username'] = $target['display_name'] ?? $target['username'] ?? '';
                }

                // 处理人信息
                if ($item['handler_uid'] > 0) {
                    $handler = $users[$item['handler_uid']] ?? array();
                    $item['handler_username'] = $handler['display_name'] ?? $handler['username'] ?? '';
                } else {
                    $item['handler_username'] = '';
                }
            }
            unset($item);
        }
        return $list ?: [];
    }

    /**
     * 批量读取用户信息（带缓存和格式化），消除 N+1 查询
     */
    private static function batch_read_users(array $uids): array {
        if (empty($uids)) return array();
        global $g_static_users;
        $result = array();
        $missing_uids = array();
        foreach ($uids as $uid) {
            $uid = intval($uid);
            if ($uid <= 0) continue;
            if (isset($g_static_users[$uid])) {
                $result[$uid] = $g_static_users[$uid];
            } else {
                $missing_uids[] = $uid;
            }
        }
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
     * 获取举报数量
     */
    public static function get_count(array $cond = []): int {
        return db_count('report', $cond);
    }

    /**
     * 处理举报
     * @param int $reportid 举报ID
     * @param int $handler_uid 处理人UID
     * @param string $action dismiss/delete/ban/delete_ban
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

        $target_type = $report['target_type'];
        $target_id = $report['target_id'];
        // 优先从举报记录获取 target_uid，否则从目标读取
        $target_uid = !empty($report['target_uid']) ? intval($report['target_uid']) : self::get_target_uid($target_type, $target_id);

        // 执行处理动作
        if ($action === 'delete') {
            if ($target_type === 'thread') {
                thread_delete($target_id);
            } elseif ($target_type === 'post') {
                post_delete($target_id);
            }
        } elseif ($action === 'ban') {
            // 封禁用户：调用 UserBanService（默认禁言7天），原因取自举报内容
            $ban_result = self::execute_ban($target_uid, $report, $handler_uid);
            if ($ban_result['code'] !== 0) {
                // 封禁失败（如被举报人是管理员组），回滚举报状态为待处理
                db_update('report', ['reportid' => $reportid], [
                    'status' => self::STATUS_PENDING,
                    'handler_uid' => 0,
                    'handle_result' => '',
                    'handle_date' => 0,
                ]);
                return ['code' => -1, 'message' => $ban_result['message']];
            }
        } elseif ($action === 'delete_ban') {
            // 组合操作：删除内容 + 封禁作者
            if ($target_type === 'thread') {
                thread_delete($target_id);
            } elseif ($target_type === 'post') {
                post_delete($target_id);
            }
            // 封禁用户：调用 UserBanService（默认禁言7天）
            $ban_result = self::execute_ban($target_uid, $report, $handler_uid);
            if ($ban_result['code'] !== 0) {
                // 内容已删除不可恢复，封禁失败时举报状态保持已处理，提示管理员
                self::maybeClearAuditDebounce();
                return ['code' => 0, 'message' => '内容已删除，但封禁失败：' . $ban_result['message']];
            }
        }
        // dismiss 不执行任何操作

        // 通知举报人
        $action_text = [
            'dismiss' => '举报被驳回',
            'delete' => '违规内容已删除',
            'ban' => '违规用户已封禁',
            'delete_ban' => '违规内容已删除且用户已封禁',
        ];
        $type_label = ['thread' => '帖子', 'post' => '评论', 'user' => '用户'];
        $notify_msg = '您举报的' . ($type_label[$target_type] ?? '内容') . '已处理：' . ($action_text[$action] ?? '');
        if (function_exists('notify_create')) {
            notify_create($report['uid'], $handler_uid, 'report_result', $target_id, 0, $notify_msg);
        }

        // 通知被举报人（驳回不通知）
        if ($action !== 'dismiss') {
            if ($target_uid > 0 && function_exists('notify_create')) {
                notify_create($target_uid, $handler_uid, 'report_penalty', $target_id, 0, '您的内容因违反社区规范已被处理');
            }
        }

        self::maybeClearAuditDebounce();
        return ['code' => 0, 'message' => '处理成功'];
    }

    /**
     * 批量处理举报（合并通知，减少 N 条通知）
     */
    public static function batch_handle(array $reportids, int $handler_uid, string $action, string $reason = ''): array {
        global $time;

        // 先批量读取所有举报记录
        $reports = db_find('report', array('reportid' => $reportids), array(), 1, count($reportids), 'reportid');
        if (empty($reports)) {
            return ['code' => -1, 'message' => '未找到举报记录'];
        }

        $success = 0;
        $fail = 0;
        // 收集需要通知的 UID（去重）
        $notify_reporter_uids = array();
        $notify_target_uids = array();
        $action_text = [
            'dismiss' => '举报被驳回',
            'delete' => '违规内容已删除',
            'ban' => '违规用户已封禁',
            'delete_ban' => '违规内容已删除且用户已封禁',
        ];
        $type_label = ['thread' => '帖子', 'post' => '评论', 'user' => '用户'];

        foreach ($reports as $report) {
            if ($report['status'] != self::STATUS_PENDING) {
                $fail++;
                continue;
            }

            // 更新状态
            $status = ($action === 'dismiss') ? self::STATUS_DISMISSED : self::STATUS_HANDLED;
            db_update('report', ['reportid' => $report['reportid']], [
                'status' => $status,
                'handler_uid' => $handler_uid,
                'handle_result' => $action,
                'handle_date' => $time,
            ]);

            $target_type = $report['target_type'];
            $target_id = $report['target_id'];
            $target_uid = !empty($report['target_uid']) ? intval($report['target_uid']) : self::get_target_uid($target_type, $target_id);

            // 执行动作
            if ($action === 'delete') {
                if ($target_type === 'thread') thread_delete($target_id);
                elseif ($target_type === 'post') post_delete($target_id);
            } elseif ($action === 'ban') {
                // 封禁用户：调用 UserBanService（默认禁言7天）
                $ban_result = self::execute_ban($target_uid, $report, $handler_uid);
                if ($ban_result['code'] !== 0) {
                    // 封禁失败，回滚该条举报状态为待处理，计入失败
                    db_update('report', ['reportid' => $report['reportid']], [
                        'status' => self::STATUS_PENDING,
                        'handler_uid' => 0,
                        'handle_result' => '',
                        'handle_date' => 0,
                    ]);
                    $fail++;
                    continue;
                }
            } elseif ($action === 'delete_ban') {
                if ($target_type === 'thread') thread_delete($target_id);
                elseif ($target_type === 'post') post_delete($target_id);
                // 封禁用户：调用 UserBanService（默认禁言7天）
                $ban_result = self::execute_ban($target_uid, $report, $handler_uid);
                if ($ban_result['code'] !== 0) {
                    // 内容已删除，封禁失败计入成功（内容已处理），不回滚
                }
            }

            // 收集通知对象
            $notify_reporter_uids[intval($report['uid'])] = $target_type;
            if ($action !== 'dismiss' && $target_uid > 0) {
                $notify_target_uids[$target_uid] = true;
            }

            $success++;
        }

        // 批量发送通知（去重后每人只发一条）
        if (function_exists('notify_create')) {
            $notify_msg = '您举报的内容已处理：' . ($action_text[$action] ?? '');
            foreach ($notify_reporter_uids as $ruid => $ttype) {
                notify_create($ruid, $handler_uid, 'report_result', 0, 0, $notify_msg);
            }
            foreach ($notify_target_uids as $tuid => $_) {
                notify_create($tuid, $handler_uid, 'report_penalty', 0, 0, '您的内容因违反社区规范已被处理');
            }
        }

        self::maybeClearAuditDebounce();
        return ['code' => 0, 'message' => "处理完成：成功{$success}条，失败{$fail}条"];
    }

    /**
     * 执行封禁（调用 UserBanService，默认禁言7天）
     * 封禁原因取自举报内容
     *
     * @param int $target_uid 被举报用户 uid
     * @param array $report 举报记录
     * @param int $handler_uid 处理管理员 uid
     * @return array ['code'=>0 成功, 'message'=>失败原因]
     */
    private static function execute_ban(int $target_uid, array $report, int $handler_uid): array {
        if ($target_uid <= 0) {
            return ['code' => 1, 'message' => '被举报用户不存在'];
        }
        if (!class_exists('UserBanService')) {
            include_once APP_PATH . 'lib/UserBanService.php';
        }
        $ban_reason = self::build_ban_reason($report);
        return UserBanService::ban(
            $target_uid,
            UserBanService::BAN_TYPE_SILENCE,
            604800, // 7天
            $ban_reason,
            $handler_uid
        );
    }

    /**
     * 根据举报记录构造封禁原因
     */
    private static function build_ban_reason(array $report): string {
        $reason_type_text = self::REASON_TYPES[$report['reason_type']] ?? $report['reason_type'];
        $ban_reason = '举报：' . $reason_type_text;
        if (!empty($report['reason_text'])) {
            $ban_reason .= ' - ' . $report['reason_text'];
        }
        if (mb_strlen($ban_reason) > 200) {
            $ban_reason = mb_substr($ban_reason, 0, 200);
        }
        return $ban_reason;
    }
}
