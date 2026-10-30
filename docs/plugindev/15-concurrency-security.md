# 15 并发安全与积分防刷

> 关键源码：`lib/security/AuditService.php`、`lib/CreditsService.php`、`plugin/xnx_lottery/model/LotteryService.php`、`plugin/xnx_quest/model/QuestService.php`、`plugin/xnx_duel/model/DuelService.php`、`plugin/xnx_invite/model/InviteService.php`
>
> 本分册基于 2026-08-03 P0+P1 安全审计沉淀，所有模式均来自真实漏洞修复。

---

## 本章覆盖

- **重放攻击防护**：同一请求被重放/N 次执行，导致积分重复发放、库存超发、计数器重复递增
- **并发刷积分防护**：多请求并发绕过每日次数限制、余额检查、库存检查
- **CAS（Compare-And-Swap）模式**：UPDATE 条件化实现原子状态转换，杜绝先读后写竞态
- **幂等性设计**：UNIQUE 约束 + INSERT IGNORE + 幂等返回，保证多次执行结果一致
- **GET_LOCK 串行化**：MySQL 用户级锁串行化同用户操作，防并发绕过限频
- **部分成功回滚**：多步积分发放中部分失败时回滚已成功步骤，避免重复发放

---

## 1. 漏洞形态速查

### 1.1 重放攻击（P0）

**特征**：接口可被重放 N 次，每次都执行发积分/扣库存等副作用。

**典型场景**：
- 抽奖接口无每日次数限制 → 无限抽奖刷积分
- 审核通过接口无状态检查 → 重复调用 grantCredits 发积分、重复递增 threads/posts 计数器
- 任务奖励领取接口无 CAS → 并发请求同时进入发积分分支

**根因**：「先读后写」无原子性保护：
```php
// ❌ 错误：先读状态再判断再更新，并发请求同时读到 PENDING 状态
$row = db_find_one('thread', array('tid' => $tid));
if ($row['audit_status'] === 0) {
    db_update('thread', array('tid' => $tid), array('audit_status' => 1));
    grantCredits(...);  // 并发请求都会走到这里
}
```

### 1.2 并发刷积分（P0）

**特征**：并发请求绕过每日次数限制、余额检查、库存检查。

**典型场景**：
- 决斗接口的 daily_join_limit 检查无 CAS → 并发对不同 duelId 抢答绕过每日次数
- 邀请码使用无 CAS → 同一邀请码被并发用多次（超过 max_use_count）
- 库存扣减无 CAS → 并发请求把库存扣成负数

**根因**：限频检查与实际操作非原子：
```php
// ❌ 错误：检查次数和递增次数不是原子操作
$count = db_count('xnx_duel_log', array('uid' => $uid, 'date' => $today));
if ($count >= $dailyLimit) return false;
// ↑↓ 之间并发请求都能通过检查
db_insert('xnx_duel_log', ...);  // 实际插入 N 条
```

### 1.3 部分成功无回滚（P1）

**特征**：多步积分发放中部分步骤失败，已成功步骤不回滚，导致用户可重复领取。

**典型场景**：
- 任务奖励发放 credits 成功后 golds 失败 → 已发的 credits 不回滚，任务状态回滚 → 用户重新领取又发一次 credits

**根因**：失败分支只回滚业务状态，不回滚已发积分：
```php
// ❌ 错误：失败时只回滚 completed 状态，不回滚已发的 credits
$creditsService->add($uid, 'credits', 10);  // 成功
$creditsService->add($uid, 'golds', 5);     // 失败
db_update('quest_progress', ..., array('completed' => 0));  // 回滚状态
return array('ok' => false);
// 用户重新领取 → credits 又发一次 = 20
```

---

## 2. CAS（Compare-And-Swap）模式

### 2.1 核心 SQL：UPDATE ... WHERE status=旧值

CAS 通过 UPDATE 的 WHERE 条件实现原子状态转换，affected_rows 精确反映是否成功：

```php
// ✅ 正确：CAS 原子转换，affected=0 表示已被并发改状态
$affected = db_update('thread',
    array('tid' => $tid, 'audit_status' => 0),  // WHERE tid=? AND audit_status=0
    array('audit_status' => 1)                   // SET audit_status=1
);
if ($affected === 0) {
    return true;  // 已被审核，幂等返回（不重复发积分）
}
// CAS 成功，安全发积分
grantCredits(...);
```

**关键点**：
- `db_update` 返回 affected_rows（受影响行数），不是 boolean
- `affected === false` 表示 SQL 错误；`$affected === 0` 表示条件不匹配（已被并发改）
- `db_update` 的条件数组支持 `[v1, v2]` 形式的 IN 查询（见 `db_cond_to_sqladd`）

### 2.2 CAS 条件设计：允许的状态转换

CAS 条件必须覆盖所有合法的旧状态。**漏掉某个合法状态会导致语义回归**：

```php
// ❌ 错误：只允许 PENDING→APPROVED，但 IGNORED→APPROVED 也是合法的
// （ignore() 注释说"记录仍保留在审核列表中，可后续通过或拒绝"）
$r = db_update('thread',
    array('tid' => $tid, 'audit_status' => 0),  // 只允许 PENDING
    array('audit_status' => 1)
);

// ✅ 正确：允许 PENDING 和 IGNORED 转为 APPROVED
$r = db_update('thread',
    array('tid' => $tid, 'audit_status' => [0, 3]),  // PENDING 或 IGNORED
    array('audit_status' => 1)
);
```

**状态机设计原则**：
- 列出所有合法的旧状态（如 PENDING + IGNORED → APPROVED）
- 显式拒绝非法转换（如 REJECTED → APPROVED 须先 resubmit）
- 幂等处理（APPROVED → APPROVED 返回 true，不重复发积分）

### 2.3 CAS 前置状态检查

对于需要区分「幂等成功」和「非法转换」的场景，CAS 前加显式状态检查：

```php
// ✅ 正确：前置检查区分幂等和非法转换
$currentStatus = intval($thread['audit_status']);
if ($currentStatus === self::STATUS_APPROVED) return true;   // 已通过，幂等
if ($currentStatus === self::STATUS_REJECTED) return false;  // 驳回态不可直接通过

$r = db_update('thread',
    array('tid' => $tid, 'audit_status' => [self::STATUS_PENDING, self::STATUS_IGNORED]),
    array('audit_status' => self::STATUS_APPROVED)
);
if ($r === 0) return true;  // 并发已被审核，幂等返回
```

### 2.4 批量 CAS：逐条 UPDATE

批量操作无法用单条 UPDATE 精确判断哪些记录实际更新成功（部分可能被并发改状态）。**改为逐条 CAS UPDATE**：

```php
// ❌ 错误：批量 UPDATE 无 CAS 条件，会对 REJECTED 状态的记录重复发积分
$r = db_update('thread', array('tid' => $valid_tids), array('audit_status' => 1));
// $r 是 affected_rows，无法区分哪些记录是本次转换的

// ✅ 正确：逐条 CAS UPDATE，仅对成功的记录发积分
$approved_tids = array();
foreach ($threads as $tid => $thread) {
    $r = db_update('thread',
        array('tid' => intval($tid), 'audit_status' => [0, 3]),
        array('audit_status' => 1)
    );
    if ($r === false || $r === 0) continue;  // REJECTED 或并发已审核，跳过
    $approved_tids[] = intval($tid);
    grantCredits(...);  // 仅对 CAS 成功的记录发积分
}
```

**性能取舍**（`ponytail:` 注释规范）：
- 批量审核一次最多几十条，逐条 UPDATE 性能可接受
- 并发安全性 > 批量性能
- 保留批量通知（`notify_create_batch`）和批量日志优化

### 2.5 CAS 失败的补偿性 refund

CAS 失败时（如抢答被他人抢先），已扣的费用必须退还：

```php
// 扣参与者押注
$sub = $creditsService->sub($challengerUid, $creditsType, $betAmount);
if (empty($sub['ok'])) {
    return array('ok' => false, 'message' => '余额不足');
}

// CAS 抢答
$affected = db_update('xnx_duel',
    array('id' => $duelId, 'status' => 0),  // WHERE status=WAITING
    array('status' => 1, 'challenger_uid' => $challengerUid, ...)
);
if (empty($affected)) {
    // 已被他人抢答，退还参与者押注
    $creditsService->add($challengerUid, $creditsType, $betAmount, 'duel_join_refund');
    return array('ok' => false, 'message' => '手慢了');
}
```

---

## 3. 幂等性设计

### 3.1 UNIQUE 约束 + INSERT IGNORE

**适用场景**：同一业务实体只能有一条记录（如一个被邀请人只能被发奖一次）。

```php
// 建表：UNIQUE KEY 保证唯一性
$sql = "CREATE TABLE IF NOT EXISTS `{$tablepre}xnx_invite_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `inviter_uid` int(11) unsigned NOT NULL DEFAULT '0',
  `invitee_uid` int(11) unsigned NOT NULL DEFAULT '0',
  ...
  PRIMARY KEY (`id`),
  UNIQUE KEY `invitee_uid` (`invitee_uid`),  -- 防并发重复发奖
  KEY `inviter_uid` (`inviter_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// 发奖：INSERT IGNORE 兜底并发
$stmt = $db->prepare("INSERT IGNORE INTO {$tablepre}xnx_invite_log $sqladd", $params);
$inserted = intval($stmt->rowCount());
if ($inserted === 0) {
    // UNIQUE 冲突：已被并发发奖，回滚本次的积分发放
    $creditsService->sub($inviterUid, $inviterType, $inviterAmount, '并发回滚');
    return true;  // 幂等返回 true，避免上层报错
}
```

**upgrade.php 幂等加 UNIQUE**（已安装插件的升级）：

```php
// 检查索引是否存在
$indexes = db_sql_find("SHOW INDEX FROM {$tablepre}xnx_invite_log WHERE Key_name='invitee_uid'");
if (empty($indexes)) {
    // 先去重：保留每个 invitee_uid 的最小 id 记录
    db_exec("DELETE l1 FROM {$tablepre}xnx_invite_log l1
        INNER JOIN {$tablepre}xnx_invite_log l2
        ON l1.invitee_uid=l2.invitee_uid AND l1.id>l2.id");
    db_exec("ALTER TABLE {$tablepre}xnx_invite_log ADD UNIQUE KEY `invitee_uid` (`invitee_uid`)");
}
```

### 3.2 幂等检查 + 返回值

**适用场景**：业务层显式检查是否已处理过。

```php
// 幂等检查：invitee_uid 已发过奖则直接返回
$exists = db_find_one('xnx_invite_log', array('invitee_uid' => $inviteeUid));
if (!empty($exists)) {
    return true;  // 幂等成功
}
```

**返回值约定**：
- 幂等成功返回 `true`（不报错），避免上层 retry
- 真正失败返回 `false` 或 `array('ok' => false)`
- 并发冲突返回 `true`（用户已正确收到首并发奖）

### 3.3 add/sub 返回值检查

`CreditsService::add()` 和 `sub()` 返回 `array('ok' => bool, 'message' => string)`，**必须检查返回值**：

```php
// ❌ 错误：不检查返回值，add 失败时（防刷限制/钩子阻止）仍记录 reward
$creditsService->add($inviterUid, $inviterType, $inviterAmount, '邀请奖励');
$rewardCredits += $inviterAmount;  // 即使 add 失败也累加

// ✅ 正确：检查返回值，仅实际入账时才记录
$r = $creditsService->add($inviterUid, $inviterType, $inviterAmount, '邀请奖励');
if (!empty($r['ok'])) {
    $inviterCredited = true;
    $rewardCredits += $inviterAmount;
}
```

**回滚时只回滚实际入账的积分**（避免对未入账的 add 执行 sub 导致误扣）：

```php
if ($inserted === 0) {
    // UNIQUE 冲突：回滚本次实际入账的积分
    if ($inviterCredited) {
        $r = $creditsService->sub($inviterUid, $inviterType, $inviterAmount, '并发回滚');
        if (empty($r['ok'])) {
            xn_log("xnx_invite 回滚失败 inviter_uid=$inviterUid amount=$inviterAmount", 'error');
        }
    }
}
```

---

## 4. GET_LOCK 串行化

### 4.1 适用场景

**当 CAS 无法覆盖时**（如每日次数限制的 TOCTOU 问题），用 MySQL `GET_LOCK` 串行化同用户的操作：

```php
// ❌ 错误：checkDailyJoinLimit + db_insert 非原子，并发请求都能通过检查
$limitCheck = self::checkDailyJoinLimit($challengerUid);
if (empty($limitCheck['ok'])) return false;
// ↑↓ 之间并发请求都能通过
db_insert('xnx_duel_log', ...);

// ✅ 正确：GET_LOCK 串行化同用户的 joinDuel
$lockKey = 'duel_join_' . intval($challengerUid);
$stmt = $db->wlink->query("SELECT GET_LOCK(" . $db->wlink->quote($lockKey) . ", 5) AS lk");
$lockAcquired = ($stmt->fetchColumn() == 1);
if (!$lockAcquired) {
    return array('ok' => false, 'message' => '系统繁忙');
}
try {
    // 串行化区域：checkDailyJoinLimit + db_insert 现在是原子的
    $limitCheck = self::checkDailyJoinLimit($challengerUid);
    if (empty($limitCheck['ok'])) return false;
    db_insert('xnx_duel_log', ...);
} finally {
    // 必须在 finally 中释放，异常时也能释放
    $db->wlink->query("SELECT RELEASE_LOCK(" . $db->wlink->quote($lockKey) . ")");
}
```

### 4.2 锁设计要点

| 要点 | 说明 |
|---|---|
| 锁名 | 限 64 字符，用 `插件名_操作_uid` 格式，uid 已 intval 防注入 |
| 超时 | 5 秒足够（业务操作通常 < 100ms） |
| 释放 | 必须在 `finally` 块中释放，异常时也能释放 |
| 连接 | 必须在同一个 wlink 连接上 GET_LOCK 和 RELEASE_LOCK |
| 粒度 | 锁用户而非锁资源（如 `duel_join_{uid}` 而非 `duel_{duelId}`） |

### 4.3 ponytail 注释规范

GET_LOCK 是简单可靠的方案，但有性能上限（单 MySQL 实例）。用 `ponytail:` 注释标注：

```php
// 串行化同用户的 joinDuel，防并发绕过 daily_join_limit
// ponytail: GET_LOCK 简单可靠，5 秒超时足够；锁名限 64 字符，uid 已 intval 防注入
```

---

## 5. 部分成功回滚

### 5.1 多步积分发放的回滚

当发放多种积分（credits/golds/rmbs）时，部分失败必须回滚已成功步骤：

```php
// ✅ 正确：记录已成功发放的积分，失败时回滚
$reward_detail = array();
$all_ok = true;

if ($reward_credits > 0) {
    $r = $creditsService->add($uid, 'credits', $reward_credits, $reason);
    if (!empty($r['ok'])) {
        $reward_detail['credits'] = $reward_credits;
    } else {
        $all_ok = false;
    }
}
if ($all_ok && $reward_golds > 0) {
    $r = $creditsService->add($uid, 'golds', $reward_golds, $reason);
    if (!empty($r['ok'])) {
        $reward_detail['golds'] = $reward_golds;
    } else {
        $all_ok = false;
    }
}

if ($all_ok) {
    // 全部成功：写完成记录
    db_insert('xnx_quest_log', ...);
    return array('ok' => true);
}

// 失败：回滚已成功发放的积分
// ponytail: sub 失败时只能记录日志，无法强一致回滚（积分不足/钩子阻止场景）
if (!empty($reward_detail['credits'])) {
    $r = $creditsService->sub($uid, 'credits', $reward_detail['credits'], '发放失败回滚');
    if (empty($r['ok'])) {
        error_log('[xnx_quest] 回滚失败 uid=' . $uid . ' type=credits amount=' . $reward_detail['credits']);
    }
}
// 回滚业务状态
db_update('xnx_quest_progress', ..., array('completed' => 0));
return array('ok' => false, 'message' => '奖励发放失败，请稍后重试');
```

### 5.2 回滚失败的处理

`sub()` 可能因积分不足/钩子阻止而失败，此时**无法强一致回滚**，只能记录日志便于后台介入：

```php
$r = $creditsService->sub($uid, 'credits', $amount, '回滚');
if (empty($r['ok'])) {
    // 记录日志，后台人工补账
    xn_log("回滚失败 uid=$uid type=credits amount=$amount msg=" . ($r['message'] ?? ''), 'error');
    error_log('[plugin] 回滚失败 uid=' . $uid . ' amount=' . $amount);
}
```

** ponytail 注释规范**：
```php
// ponytail: sub 失败时（积分不足/钩子阻止）只能记录日志，无法强一致回滚
```

---

## 6. 路由层频率限制

### 6.1 IP + uid 双维度限频

在路由层对敏感接口加频率限制，防止恶意高频请求：

```php
// route/lottery.php 的 draw action
$ip = isset($longip) ? $longip : ip2long($_SERVER['REMOTE_ADDR']);
$uid = intval($uid);
$cache_key = 'lottery_draw_rate_' . $uid . '_' . $ip;
$last = cache_get($cache_key);
if ($last) {
    $count = intval($last);
    if ($count >= 10) {  // 60 秒内最多 10 次
        message(-1, lang('lottery_rate_limited'));
    }
    cache_set($cache_key, $count + 1, 60);
} else {
    cache_set($cache_key, 1, 60);
}
```

### 6.2 频率限制的位置

频率限制放在路由层（`route/*.php`），不放在 Service 层：
- 路由层负责请求过滤，Service 层负责业务逻辑
- 不同入口（Web/API）可能共享 Service，但限频策略不同
- 频率限制是安全策略，应在最早阶段执行

---

## 7. 安全审计清单

开发涉及积分/库存/计数器的功能时，逐项检查：

### CAS 检查
- [ ] 状态转换用 CAS（`UPDATE ... WHERE status=旧值`），不用「先读后写」
- [ ] CAS 条件覆盖所有合法的旧状态（如 PENDING + IGNORED → APPROVED）
- [ ] CAS 失败（affected=0）有幂等处理（返回 true 不报错）或补偿性 refund
- [ ] 批量操作用逐条 CAS UPDATE，不用无条件的批量 UPDATE
- [ ] CAS 成功后才发积分/递增计数器/发通知

### 幂等检查
- [ ] 业务实体唯一性用 UNIQUE 约束保证（如 `UNIQUE KEY invitee_uid`）
- [ ] INSERT 用 `INSERT IGNORE` 兜底并发冲突
- [ ] 幂等返回 true（不报错），真正失败返回 false
- [ ] `CreditsService::add()/sub()` 返回值被检查
- [ ] 回滚时只回滚实际入账的积分（检查 credited 标志）

### 并发检查
- [ ] 每日次数限制用 GET_LOCK 串行化（CAS 无法覆盖的 TOCTOU 场景）
- [ ] GET_LOCK 在 finally 块中释放
- [ ] 锁名含 uid（锁用户而非锁资源），限 64 字符
- [ ] 库存扣减用 CAS（`WHERE stock>0` + `SET stock-1`）

### 回滚检查
- [ ] 多步积分发放部分失败时回滚已成功步骤
- [ ] sub() 失败时记录 xn_log('error') 便于后台介入
- [ ] 业务状态回滚（如 completed 1→0）放在积分回滚之后

### 频率限制检查
- [ ] 敏感接口（抽奖/决斗/发奖）在路由层加 IP+uid 双维度限频
- [ ] 频率限制放在路由层，不放在 Service 层
- [ ] 限频命中返回友好提示（lang 键），不暴露系统细节

---

## 8. 真实案例

### 8.1 AuditService::approve() 重放刷积分（P1）

**漏洞**：`approve()` 无前置状态检查，重放可重复递增 user.threads/posts、forum.threads、runtime 计数器，重复调用 `grantCredits()` 发放积分。

**修复**（[AuditService.php:208-218](../../lib/security/AuditService.php)）：
```php
// CAS：仅当 audit_status IN (PENDING, IGNORED) 时才更新
$currentStatus = intval($thread['audit_status']);
if ($currentStatus === self::STATUS_APPROVED) return true;   // 幂等
if ($currentStatus === self::STATUS_REJECTED) return false;  // 非法转换
$r = db_update('thread',
    array('tid' => $target_id, 'audit_status' => [self::STATUS_PENDING, self::STATUS_IGNORED]),
    array('audit_status' => self::STATUS_APPROVED)
);
if ($r === 0) return true;  // 并发已被审核，幂等返回
```

### 8.2 xnx_invite 邀请奖励并发重复发放（P1）

**漏洞**：`useCode` 先读 `use_count` 再 +1 写回，无 CAS。`sendRewards` 无幂等检查，`xnx_invite_log` 无 UNIQUE(invitee_uid)。

**修复**（[InviteService.php:137-155](../../plugin/xnx_invite/model/InviteService.php)）：
```php
// CAS：原子递增 use_count，仅在未达上限且 status=1 时成功
$cond = array('id' => $inviteCode['id'], 'status' => 1);
if ($maxUse > 0) {
    $cond['use_count<'] = $maxUse;
}
$affected = db_update('xnx_invite_code', $cond, array('use_count+' => 1, ...));
if (!$affected) return false;
```

### 8.3 xnx_quest grantReward 部分成功无回滚（P1）

**漏洞**：credits 发放成功后 golds 失败，已发的 credits 不回滚，但任务状态回滚了，用户可再次领取导致重复发 credits。

**修复**（[QuestService.php:475-495](../../plugin/xnx_quest/model/QuestService.php)）：
```php
// 失败：回滚已成功发放的积分
if (!empty($reward_detail['credits'])) {
    $r = $creditsService->sub($uid, 'credits', $reward_detail['credits'], '回滚');
    if (empty($r['ok'])) {
        error_log('[xnx_quest] 回滚失败 uid=' . $uid . ' type=credits amount=' . $reward_detail['credits']);
    }
}
// 回滚任务状态
db_update('xnx_quest_progress', ..., array('completed' => 0));
```

### 8.4 xnx_duel joinDuel 并发绕过 daily_join_limit（P0）

**漏洞**：`daily_join_limit` 检查无 CAS，并发对不同 duelId 的抢答可绕过每日次数限制。

**修复**（[DuelService.php:188-202](../../plugin/xnx_duel/model/DuelService.php)）：
```php
$lockKey = 'duel_join_' . intval($challengerUid);
$stmt = $db->wlink->query("SELECT GET_LOCK(" . $db->wlink->quote($lockKey) . ", 5) AS lk");
$lockAcquired = ($stmt->fetchColumn() == 1);
if (!$lockAcquired) return array('ok' => false, 'message' => lang('xnx_duel_system_busy'));
try {
    // 串行化区域：checkDailyJoinLimit + db_insert 现在是原子的
    ...
} finally {
    $db->wlink->query("SELECT RELEASE_LOCK(" . $db->wlink->quote($lockKey) . ")");
}
```

### 8.5 xnx_lottery 库存超发（P0）

**漏洞**：原 `db_update(... 'stock-'=>1)` 无 `stock>0` 条件，并发请求可把库存扣成负数。

**修复**：
```php
// CAS：库存扣减，affected=0 表示已被并发抢光
$affected = db_update('xnx_lottery_sidebar_prize',
    array('id' => $id, 'stock>' => 0),  // WHERE id=? AND stock>0
    array('stock-' => 1)                 // SET stock=stock-1
);
if (empty($affected)) {
    // 库存被并发抢光，退还扣费 + 写未中奖日志
    $creditsService->add($uid, $type, $price, 'lottery_refund');
    return array('ok' => false, 'message' => '奖品已被抢光');
}
```

---

## 9. ponytail 注释规范

安全相关代码必须用 `ponytail:` 注释标注设计取舍：

| 场景 | 注释示例 |
|---|---|
| CAS 条件设计 | `// ponytail: max_use_count=0 表示不限，用 use_count<max_use_count 条件天然过滤` |
| 逐条 UPDATE 取舍 | `// ponytail: 批量审核一次最多几十条，逐条 UPDATE 性能可接受；并发安全性 > 批量性能` |
| GET_LOCK 取舍 | `// ponytail: GET_LOCK 简单可靠，5 秒超时足够；锁名限 64 字符，uid 已 intval 防注入` |
| 回滚失败处理 | `// ponytail: sub 失败时（积分不足/钩子阻止）只能记录日志，无法强一致回滚` |
| INSERT IGNORE 取舍 | `// ponytail: 若插入失败说明已被并发发奖，需回滚刚刚的 add 操作避免重复发钱` |

---

## 相关章节

- [04-api-cheatsheet.md](04-api-cheatsheet.md) —— `db_update` 返回值、`db_cond_to_sqladd` 条件数组语法
- [06-ai-collaboration.md](06-ai-collaboration.md) —— AI 协作规范，含安全检查清单
- [07-runtime-safety.md](07-runtime-safety.md) —— 运行时安全（错误处理、崩溃禁用）
- [08-login-security.md](08-login-security.md) —— 登录安全（账号锁定、密码策略）
