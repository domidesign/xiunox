<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * CreditsService 积分服务
 * 管理用户积分的增加、扣减、查询和日志记录
 * @since 1.0.2
 */
class CreditsService {
    private DatabaseInterface $db;
    private array $conf;

    public function __construct(DatabaseInterface $db, array $conf) {
        $this->db = $db;
        $this->conf = $conf;
    }

    /**
     * 增加积分
     * @param int $uid 用户ID
     * @param string $type 积分类型: credits/golds/rmbs
     * @param int $amount 增加金额（必须>0）
     * @param string $reason 变动原因
     * @param int $dailyLimit 规则级每日限制次数，0使用全局设置
     * @param bool $reasonIsRaw true 表示 reason 是管理员/外部手动输入的自由文本，写入时加 raw: 前缀，显示时原样返回不翻译
     * @return array ['ok'=>bool, 'message'=>string, 'balance'=>int]
     */
    public function add(int $uid, string $type, int $amount, string $reason = '', int $dailyLimit = 0, bool $reasonIsRaw = false): array {
        // 1. 参数校验
        if ($uid <= 0) return ['ok' => false, 'message' => '无效的用户ID'];
        if ($amount <= 0) return ['ok' => false, 'message' => '增加金额必须大于0'];
        if (!$this->isValidType($type)) return ['ok' => false, 'message' => '无效的积分类型'];

        // raw reason 加前缀，显示时原样返回不翻译（管理员/外部手动输入场景）
        if ($reasonIsRaw && $reason !== '') {
            $reason = 'raw:' . $reason;
        }

        // 2. 防刷检查
        $limitCheck = $this->checkDailyLimit($uid, $reason, $dailyLimit);
        if (!$limitCheck['ok']) return $limitCheck;

        // 3. credits_before_change 钩子
        // ponytail: add() 也用 abs() 强制转正，与 sub() 对称 —— 钩子只能调整量的大小，不能翻转方向（负值变奖励）
        $hookResult = $this->fireBeforeChange($uid, $type, $amount, $reason);
        if ($hookResult === false) return ['ok' => false, 'message' => '操作被钩子阻止'];
        if (is_array($hookResult) && isset($hookResult['amount'])) $amount = abs(intval($hookResult['amount']));

        // 4. 行锁 + 事务
        try {
            $this->beginTransaction();

            // SELECT FOR UPDATE 行锁
            $user = $this->lockUserRow($uid);
            if (empty($user)) {
                $this->rollback();
                return ['ok' => false, 'message' => '用户不存在'];
            }

            $oldBalance = intval($user[$type]);
            $newBalance = $oldBalance + $amount;

            // 更新用户积分
            $this->updateUserCredits($uid, $type, $newBalance);

            // 写入日志
            $this->insertLog($uid, $type, $amount, $newBalance, $reason);

            $this->commit();

            // 积分变动后检查用户组升级（仅 credits 类型）
            if ($type === 'credits' && function_exists('user_update_group')) {
                user_update_group($uid);
            }

            // 5. credits_after_change 钩子
            $this->fireAfterChange($uid, $type, $amount, $newBalance, $reason);

            return ['ok' => true, 'message' => '积分增加成功', 'balance' => $newBalance, 'change' => $amount];

        } catch (Exception $e) {
            $this->rollback();
            return ['ok' => false, 'message' => '操作失败: ' . $e->getMessage()];
        }
    }

    /**
     * 扣减积分
     * @param int $uid 用户ID
     * @param string $type 积分类型
     * @param int $amount 扣减金额（必须>0）
     * @param string $reason 变动原因
     * @param int $dailyLimit 规则级每日限制次数，0使用全局设置
     * @param bool $reasonIsRaw true 表示 reason 是管理员/外部手动输入的自由文本，写入时加 raw: 前缀，显示时原样返回不翻译
     * @return array
     */
    public function sub(int $uid, string $type, int $amount, string $reason = '', int $dailyLimit = 0, bool $reasonIsRaw = false): array {
        if ($uid <= 0) return ['ok' => false, 'message' => '无效的用户ID'];
        if ($amount <= 0) return ['ok' => false, 'message' => '扣减金额必须大于0'];
        if (!$this->isValidType($type)) return ['ok' => false, 'message' => '无效的积分类型'];

        // raw reason 加前缀，显示时原样返回不翻译（管理员/外部手动输入场景）
        if ($reasonIsRaw && $reason !== '') {
            $reason = 'raw:' . $reason;
        }

        // 防刷检查
        $limitCheck = $this->checkDailyLimit($uid, $reason, $dailyLimit);
        if (!$limitCheck['ok']) return $limitCheck;

        // credits_before_change 钩子
        $hookResult = $this->fireBeforeChange($uid, $type, -$amount, $reason);
        if ($hookResult === false) return ['ok' => false, 'message' => '操作被钩子阻止'];
        if (is_array($hookResult) && isset($hookResult['amount'])) $amount = abs(intval($hookResult['amount']));

        try {
            $this->beginTransaction();

            $user = $this->lockUserRow($uid);
            if (empty($user)) {
                $this->rollback();
                return ['ok' => false, 'message' => '用户不存在'];
            }

            $oldBalance = intval($user[$type]);

            // 禁止负分检查
            if ($oldBalance < $amount) {
                $this->rollback();
                return ['ok' => false, 'message' => '余额不足，当前余额: ' . $oldBalance];
            }

            $newBalance = $oldBalance - $amount;

            $this->updateUserCredits($uid, $type, $newBalance);
            $this->insertLog($uid, $type, -$amount, $newBalance, $reason);

            $this->commit();

            // 积分变动后检查用户组升级（仅 credits 类型）
            if ($type === 'credits' && function_exists('user_update_group')) {
                user_update_group($uid);
            }

            $this->fireAfterChange($uid, $type, -$amount, $newBalance, $reason);

            return ['ok' => true, 'message' => '积分扣减成功', 'balance' => $newBalance, 'change' => -$amount];

        } catch (Exception $e) {
            $this->rollback();
            return ['ok' => false, 'message' => '操作失败: ' . $e->getMessage()];
        }
    }

    /**
     * 查询积分余额
     */
    public function get(int $uid, string $type = ''): array {
        if ($uid <= 0) return ['ok' => false, 'message' => '无效的用户ID'];

        $user = $this->db->findOne('user', ['uid' => $uid]);
        if (empty($user)) return ['ok' => false, 'message' => '用户不存在'];

        $types = $this->conf['credits_types'] ?? ['credits', 'golds', 'rmbs'];

        if (!empty($type)) {
            if (!$this->isValidType($type)) return ['ok' => false, 'message' => '无效的积分类型'];
            return ['ok' => true, 'type' => $type, 'balance' => intval($user[$type])];
        }

        $balances = [];
        foreach ($types as $t) {
            $balances[$t] = intval($user[$t] ?? 0);
        }
        return ['ok' => true, 'balances' => $balances];
    }

    /**
     * 查询积分日志
     */
    public function log(int $uid, int $page = 1, int $pagesize = 20, string $type = ''): array {
        if ($uid <= 0) return ['ok' => false, 'message' => '无效的用户ID'];

        $cond = ['uid' => $uid];
        if (!empty($type) && $this->isValidType($type)) {
            $cond['type'] = $type;
        }

        $logs = $this->db->find('credits_log', $cond, ['logid' => -1], $page, $pagesize);
        $count = $this->db->count('credits_log', $cond);

        // 格式化
        if ($logs) {
            foreach ($logs as &$log) {
                $log['ip_fmt'] = long2ip(intval($log['ip']));
                $log['create_date_fmt'] = date('Y-m-d H:i:s', $log['create_date']);
            }
        }

        return [
            'ok' => true,
            'logs' => $logs ?: [],
            'count' => $count,
            'page' => $page,
            'pagesize' => $pagesize,
        ];
    }

    /**
     * 按操作分组的积分记录（一次操作可能产生 credits/golds/rmbs 多条记录，合并为一条显示）
     * 分页基于分组后的条目数
     */
    public function logGrouped(int $uid, int $page = 1, int $pagesize = 10): array {
        if ($uid <= 0) return ['ok' => false, 'message' => '无效的用户ID'];

        $page = max(1, $page);
        $offset = ($page - 1) * $pagesize;
        $tableName = $this->db->table('credits_log');
        $uid = intval($uid);

        // 使用 SQL GROUP BY 分页查询分组（按 create_date + reason 作为一次操作的唯一标识）
        // 先获取分组总数
        $sqlCount = "SELECT COUNT(*) AS c FROM (
                        SELECT 1 FROM `{$tableName}`
                        WHERE uid = {$uid}
                        GROUP BY create_date, reason
                     ) t";
        $countRow = $this->db->sqlFindOne($sqlCount);
        $total = $countRow ? intval($countRow['c']) : 0;

        if ($total <= 0) {
            return ['ok' => true, 'logs' => [], 'count' => 0, 'page' => $page, 'pagesize' => $pagesize];
        }

        // 获取当前页分组（使用 GROUP_CONCAT 聚合 type/change，避免 PHP 内二次查询）
        // 注意：change 是 MySQL 保留字，需用反引号
        $sql = "SELECT create_date, reason,
                       GROUP_CONCAT(type ORDER BY logid) AS types,
                       GROUP_CONCAT(`change` ORDER BY logid) AS changes
                FROM `{$tableName}`
                WHERE uid = {$uid}
                GROUP BY create_date, reason
                ORDER BY create_date DESC
                LIMIT {$offset}, {$pagesize}";
        $groups = $this->db->sqlFind($sql);

        $typeNames = ['credits' => '积分', 'golds' => '金币', 'rmbs' => 'RMB'];
        $result = [];
        if ($groups) {
            foreach ($groups as $g) {
                $changes = [];
                $types = explode(',', $g['types'] ?? '');
                $changeValues = explode(',', $g['changes'] ?? '');
                $count = count($types);
                for ($i = 0; $i < $count; $i++) {
                    $tname = $typeNames[$types[$i]] ?? $types[$i];
                    $change = intval($changeValues[$i] ?? 0);
                    $changes[] = $tname . ($change > 0 ? ' +' : ' ') . $change;
                }
                $result[] = [
                    'reason' => $g['reason'],
                    'create_date' => intval($g['create_date']),
                    'create_date_fmt' => date('Y-m-d H:i:s', intval($g['create_date'])),
                    'changes' => $changes,
                ];
            }
        }

        return [
            'ok' => true,
            'logs' => $result,
            'count' => $total,
            'page' => $page,
            'pagesize' => $pagesize,
        ];
    }

    /**
     * 检查余额是否足够
     */
    public function checkNegative(int $uid, string $type, int $amount): array {
        if ($uid <= 0) return ['ok' => false, 'message' => '无效的用户ID', 'sufficient' => false];
        if (!$this->isValidType($type)) return ['ok' => false, 'message' => '无效的积分类型', 'sufficient' => false];

        $user = $this->db->findOne('user', ['uid' => $uid]);
        if (empty($user)) return ['ok' => false, 'message' => '用户不存在', 'sufficient' => false];

        $balance = intval($user[$type]);
        $sufficient = $balance >= $amount;

        return [
            'ok' => true,
            'sufficient' => $sufficient,
            'balance' => $balance,
            'amount' => $amount,
            'type' => $type,
        ];
    }

    // ---- 私有方法 ----

    /**
     * 验证积分类型
     */
    private function isValidType(string $type): bool {
        $types = $this->conf['credits_types'] ?? ['credits', 'golds', 'rmbs'];
        return in_array($type, $types);
    }

    /**
     * 防刷检查：同一 reason+uid 每日限制
     * @param int $uid 用户ID
     * @param string $reason 变动原因
     * @param int $ruleDailyLimit 规则级每日限制，0使用全局设置，-1表示不限制
     */
    private function checkDailyLimit(int $uid, string $reason, int $ruleDailyLimit = 0): array {
        if (empty($reason)) return ['ok' => true]; // 无 reason 不限制

        // 优先使用规则级限制，0 表示使用全局设置，-1 表示不限制
        if ($ruleDailyLimit === -1) return ['ok' => true]; // 规则明确设为不限制
        $limit = $ruleDailyLimit > 0 ? $ruleDailyLimit : intval($this->conf['credits_daily_limit'] ?? 10);
        if ($limit <= 0) return ['ok' => true]; // 全局设为 0 也不限制

        // 计算今日起始时间戳
        $todayStart = strtotime(date('Y-m-d'));

        // 按 reason 统计今日操作次数
        // 一次操作可能写多条不同 type 的日志（credits/golds/rmbs），需按 create_date 去重
        // 同一秒内同一 reason 的日志视为同一次操作
        // 注意：tablepre 是 db 对象的属性，不是全局变量；quote() 返回去掉首尾引号的转义字符串
        $tableName = $this->db->table('credits_log');
        $sql = "SELECT COUNT(*) as cnt FROM (
                    SELECT 1 FROM `{$tableName}`
                    WHERE uid = " . intval($uid) . "
                    AND reason = '" . $this->db->quote($reason) . "'
                    AND create_date > " . intval($todayStart) . "
                    GROUP BY create_date
                ) t";
        $row = $this->db->sqlFindOne($sql);
        $count = $row ? intval($row['cnt']) : 0;

        if ($count >= $limit) {
            return ['ok' => false, 'message' => "每日操作次数已达上限({$limit}次)"];
        }

        return ['ok' => true];
    }

    /**
     * 公开的防刷检查方法，供 CreditsRuleService 调用
     */
    public function checkDailyLimitPublic(int $uid, string $reason, int $ruleDailyLimit = 0): array {
        return $this->checkDailyLimit($uid, $reason, $ruleDailyLimit);
    }

    /**
     * 行锁读取用户行
     */
    private function lockUserRow(int $uid): ?array {
        $tableName = $this->db->table('user');
        $sql = "SELECT * FROM `{$tableName}` WHERE uid = {$uid} FOR UPDATE";
        return $this->db->sqlFindOne($sql);
    }

    /**
     * 更新用户积分
     */
    private function updateUserCredits(int $uid, string $type, int $newBalance): void {
        $this->db->update('user', ['uid' => $uid], [$type => $newBalance]);

        // 修复：清理用户缓存，确保后续 user_update_group 能读到最新积分
        // CreditsService 绕过 user_update()，需手动清理两层缓存
        global $conf, $g_static_users;
        if (!in_array($conf['cache']['type'] ?? '', array('mysql', 'pdo_mysql'))) {
            cache_delete("user-$uid");
        }
        if (isset($g_static_users[$uid])) {
            $g_static_users[$uid][$type] = $newBalance;
        }
    }

    /**
     * 写入积分日志
     */
    private function insertLog(int $uid, string $type, int $change, int $balance, string $reason): void {
        // 使用 $longip（已通过 ip2long 转换的整型），避免对字符串 IP 误用 intval() 导致只保留第一段
        global $longip;
        $this->db->insert('credits_log', [
            'uid' => $uid,
            'type' => $type,
            'change' => $change,
            'balance' => $balance,
            'reason' => $reason,
            'ip' => intval($longip ?? 0),
            'create_date' => time(),
        ]);
    }

    /**
     * credits_before_change 钩子
     * 通过全局变量 $g_credits_hooks 支持插件注册钩子
     * 返回 false 阻止操作，返回 ['amount' => N] 修改变动值
     */
    private function fireBeforeChange(int $uid, string $type, int $amount, string $reason) {
        global $g_credits_hooks;
        if (empty($g_credits_hooks['credits_before_change'])) return null;

        foreach ($g_credits_hooks['credits_before_change'] as $callback) {
            if (!is_callable($callback)) continue;
            $result = call_user_func($callback, $uid, $type, $amount, $reason);
            if ($result === false) return false;
            if (is_array($result) && isset($result['amount'])) $amount = intval($result['amount']);
        }
        return ['amount' => $amount];
    }

    /**
     * credits_after_change 钩子
     */
    private function fireAfterChange(int $uid, string $type, int $change, int $balance, string $reason): void {
        global $g_credits_hooks;
        if (empty($g_credits_hooks['credits_after_change'])) return;

        foreach ($g_credits_hooks['credits_after_change'] as $callback) {
            if (!is_callable($callback)) continue;
            call_user_func($callback, $uid, $type, $change, $balance, $reason);
        }
    }

    /**
     * 注册钩子
     */
    public static function registerHook(string $hookName, callable $callback): void {
        global $g_credits_hooks;
        if (!isset($g_credits_hooks)) $g_credits_hooks = [];
        if (!isset($g_credits_hooks[$hookName])) $g_credits_hooks[$hookName] = [];
        $g_credits_hooks[$hookName][] = $callback;
    }

    // ---- 事务方法 ----

    private function beginTransaction(): void {
        $this->db->exec("START TRANSACTION");
    }

    private function commit(): void {
        $this->db->exec("COMMIT");
    }

    private function rollback(): void {
        $this->db->exec("ROLLBACK");
    }
}
