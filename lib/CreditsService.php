<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * CreditsService 积分服务
 * 管理用户积分的增加、扣减、查询和日志记录
 * @since 4.5.0
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
     * @return array ['ok'=>bool, 'message'=>string, 'balance'=>int]
     */
    public function add(int $uid, string $type, int $amount, string $reason = ''): array {
        // 1. 参数校验
        if ($uid <= 0) return ['ok' => false, 'message' => '无效的用户ID'];
        if ($amount <= 0) return ['ok' => false, 'message' => '增加金额必须大于0'];
        if (!$this->isValidType($type)) return ['ok' => false, 'message' => '无效的积分类型'];

        // 2. 防刷检查
        $limitCheck = $this->checkDailyLimit($uid, $reason);
        if (!$limitCheck['ok']) return $limitCheck;

        // 3. credits_before_change 钩子
        $hookResult = $this->fireBeforeChange($uid, $type, $amount, $reason);
        if ($hookResult === false) return ['ok' => false, 'message' => '操作被钩子阻止'];
        if (is_array($hookResult) && isset($hookResult['amount'])) $amount = intval($hookResult['amount']);

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
     * @return array
     */
    public function sub(int $uid, string $type, int $amount, string $reason = ''): array {
        if ($uid <= 0) return ['ok' => false, 'message' => '无效的用户ID'];
        if ($amount <= 0) return ['ok' => false, 'message' => '扣减金额必须大于0'];
        if (!$this->isValidType($type)) return ['ok' => false, 'message' => '无效的积分类型'];

        // 防刷检查
        $limitCheck = $this->checkDailyLimit($uid, $reason);
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
     */
    private function checkDailyLimit(int $uid, string $reason): array {
        if (empty($reason)) return ['ok' => true]; // 无 reason 不限制

        $limit = intval($this->conf['credits_daily_limit'] ?? 10);
        if ($limit <= 0) return ['ok' => true]; // 0 表示不限制

        // 计算今日起始时间戳
        $todayStart = strtotime(date('Y-m-d'));

        $count = $this->db->count('credits_log', [
            'uid' => $uid,
            'reason' => $reason,
            'create_date>' => $todayStart,
        ]);

        if ($count >= $limit) {
            return ['ok' => false, 'message' => "每日操作次数已达上限({$limit}次)"];
        }

        return ['ok' => true];
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
    }

    /**
     * 写入积分日志
     */
    private function insertLog(int $uid, string $type, int $change, int $balance, string $reason): void {
        global $ip;
        $this->db->insert('credits_log', [
            'uid' => $uid,
            'type' => $type,
            'change' => $change,
            'balance' => $balance,
            'reason' => $reason,
            'ip' => intval($ip ?? 0),
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
