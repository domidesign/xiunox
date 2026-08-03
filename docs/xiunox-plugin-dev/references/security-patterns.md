# 安全模式速查

> 本文件为并发安全与积分防刷速查，详细说明见 [plugindev/15-concurrency-security.md](../../plugindev/15-concurrency-security.md)
>
> 基于 2026-08-03 P0+P1 安全审计沉淀，所有模式均来自真实漏洞修复。

## 目录

- [一、CAS（Compare-And-Swap）模式](#一cascompare-and-swap模式)
- [二、幂等性设计](#二幂等性设计)
- [三、GET_LOCK 串行化](#三get_lock-串行化)
- [四、部分成功回滚](#四部分成功回滚)
- [五、路由层频率限制](#五路由层频率限制)
- [六、安全审计清单](#六安全审计清单)

---

## 一、CAS（Compare-And-Swap）模式

**核心**：UPDATE 的 WHERE 条件包含状态字段，affected_rows 精确反映是否成功。

```php
// ✅ CAS 原子转换
$affected = db_update('thread',
    array('tid' => $tid, 'audit_status' => 0),  // WHERE audit_status=0
    array('audit_status' => 1)
);
if ($affected === 0) return true;  // 幂等返回
// CAS 成功，安全发积分
```

| 要点 | 说明 |
|---|---|
| `db_update` 返回 affected_rows | `false`=SQL 错误，`0`=条件不匹配，`>0`=成功 |
| 条件数组支持 IN 查询 | `array('audit_status' => [0, 3])` 生成 `audit_status IN (0,3)` |
| CAS 条件覆盖所有合法旧状态 | 漏掉合法状态导致语义回归（如 IGNORED→APPROVED 被拒） |
| 批量操作用逐条 CAS | 批量 UPDATE 无法区分哪些记录实际更新成功 |
| CAS 失败有补偿性 refund | 已扣费用必须退还（如抢答被他人抢先） |

**批量 CAS 示例**：
```php
foreach ($threads as $tid => $thread) {
    $r = db_update('thread',
        array('tid' => intval($tid), 'audit_status' => [0, 3]),
        array('audit_status' => 1)
    );
    if ($r === false || $r === 0) continue;  // 跳过失败
    $approved_tids[] = intval($tid);
    grantCredits(...);  // 仅对成功的记录发积分
}
```

---

## 二、幂等性设计

### UNIQUE 约束 + INSERT IGNORE

```php
// 建表：UNIQUE KEY 保证唯一性
UNIQUE KEY `invitee_uid` (`invitee_uid`)

// 发奖：INSERT IGNORE 兜底并发
$stmt = $db->prepare("INSERT IGNORE INTO {$tablepre}xnx_invite_log $sqladd", $params);
$inserted = intval($stmt->rowCount());
if ($inserted === 0) {
    // UNIQUE 冲突：回滚已发积分
    $creditsService->sub($uid, $type, $amount, '并发回滚');
    return true;  // 幂等返回 true
}
```

### upgrade.php 幂等加 UNIQUE

```php
$indexes = db_sql_find("SHOW INDEX FROM {$tablepre}xnx_invite_log WHERE Key_name='invitee_uid'");
if (empty($indexes)) {
    // 先去重：保留最小 id 记录
    db_exec("DELETE l1 FROM {$tablepre}xnx_invite_log l1
        INNER JOIN {$tablepre}xnx_invite_log l2
        ON l1.invitee_uid=l2.invitee_uid AND l1.id>l2.id");
    db_exec("ALTER TABLE {$tablepre}xnx_invite_log ADD UNIQUE KEY `invitee_uid` (`invitee_uid`)");
}
```

### add/sub 返回值检查

```php
// ❌ 错误：不检查返回值
$creditsService->add($uid, 'credits', $amount, '奖励');
$rewardCredits += $amount;  // add 失败也累加

// ✅ 正确：检查返回值
$r = $creditsService->add($uid, 'credits', $amount, '奖励');
if (!empty($r['ok'])) {
    $credited = true;
    $rewardCredits += $amount;
}
```

**返回值约定**：
- 幂等成功返回 `true`（不报错），避免上层 retry
- 真正失败返回 `false` 或 `array('ok' => false)`
- 回滚时只回滚实际入账的积分（检查 credited 标志）

---

## 三、GET_LOCK 串行化

**适用场景**：CAS 无法覆盖的 TOCTOU 问题（如每日次数限制）。

```php
$lockKey = 'duel_join_' . intval($uid);
$stmt = $db->wlink->query("SELECT GET_LOCK(" . $db->wlink->quote($lockKey) . ", 5) AS lk");
$lockAcquired = ($stmt->fetchColumn() == 1);
if (!$lockAcquired) {
    return array('ok' => false, 'message' => '系统繁忙');
}
try {
    // 串行化区域：checkDailyLimit + db_insert 现在是原子的
    ...
} finally {
    // 必须在 finally 中释放
    $db->wlink->query("SELECT RELEASE_LOCK(" . $db->wlink->quote($lockKey) . ")");
}
```

| 要点 | 说明 |
|---|---|
| 锁名格式 | `插件名_操作_uid`，限 64 字符，uid 已 intval 防注入 |
| 超时 | 5 秒足够（业务操作通常 < 100ms） |
| 释放 | 必须在 `finally` 块中释放，异常时也能释放 |
| 连接 | 同一个 wlink 连接上 GET_LOCK 和 RELEASE_LOCK |
| 粒度 | 锁用户而非锁资源（`duel_join_{uid}` 而非 `duel_{duelId}`） |

---

## 四、部分成功回滚

**多步积分发放部分失败时，回滚已成功步骤**：

```php
$reward_detail = array();
$all_ok = true;

if ($reward_credits > 0) {
    $r = $creditsService->add($uid, 'credits', $reward_credits, $reason);
    if (!empty($r['ok'])) $reward_detail['credits'] = $reward_credits;
    else $all_ok = false;
}
if ($all_ok && $reward_golds > 0) {
    $r = $creditsService->add($uid, 'golds', $reward_golds, $reason);
    if (!empty($r['ok'])) $reward_detail['golds'] = $reward_golds;
    else $all_ok = false;
}

if ($all_ok) {
    db_insert('quest_log', ...);
    return array('ok' => true);
}

// 失败：回滚已成功发放的积分
// ponytail: sub 失败时只能记录日志，无法强一致回滚
if (!empty($reward_detail['credits'])) {
    $r = $creditsService->sub($uid, 'credits', $reward_detail['credits'], '回滚');
    if (empty($r['ok'])) {
        error_log('[plugin] 回滚失败 uid=' . $uid . ' type=credits amount=' . $reward_detail['credits']);
    }
}
db_update('quest_progress', ..., array('completed' => 0));  // 回滚业务状态
return array('ok' => false, 'message' => '奖励发放失败');
```

---

## 五、路由层频率限制

**敏感接口在路由层加 IP+uid 双维度限频**：

```php
// route/lottery.php
$cache_key = 'lottery_draw_rate_' . $uid . '_' . $ip;
$last = cache_get($cache_key);
if ($last) {
    $count = intval($last);
    if ($count >= 10) message(-1, lang('lottery_rate_limited'));
    cache_set($cache_key, $count + 1, 60);
} else {
    cache_set($cache_key, 1, 60);
}
```

**位置**：频率限制放在路由层（`route/*.php`），不放在 Service 层。

---

## 六、安全审计清单

开发涉及积分/库存/计数器的功能时，逐项检查：

### CAS
- [ ] 状态转换用 CAS（`UPDATE ... WHERE status=旧值`），不用「先读后写」
- [ ] CAS 条件覆盖所有合法的旧状态（如 PENDING + IGNORED → APPROVED）
- [ ] CAS 失败（affected=0）有幂等处理或补偿性 refund
- [ ] 批量操作用逐条 CAS UPDATE，不用无条件的批量 UPDATE
- [ ] CAS 成功后才发积分/递增计数器/发通知

### 幂等
- [ ] 业务实体唯一性用 UNIQUE 约束保证
- [ ] INSERT 用 `INSERT IGNORE` 兜底并发冲突
- [ ] 幂等返回 true（不报错），真正失败返回 false
- [ ] `CreditsService::add()/sub()` 返回值被检查
- [ ] 回滚时只回滚实际入账的积分（检查 credited 标志）

### 并发
- [ ] 每日次数限制用 GET_LOCK 串行化（CAS 无法覆盖的 TOCTOU 场景）
- [ ] GET_LOCK 在 finally 块中释放
- [ ] 锁名含 uid（锁用户而非锁资源），限 64 字符
- [ ] 库存扣减用 CAS（`WHERE stock>0` + `SET stock-1`）

### 回滚
- [ ] 多步积分发放部分失败时回滚已成功步骤
- [ ] sub() 失败时记录 xn_log('error') 便于后台介入
- [ ] 业务状态回滚（如 completed 1→0）放在积分回滚之后

### 频率限制
- [ ] 敏感接口（抽奖/决斗/发奖）在路由层加 IP+uid 双维度限频
- [ ] 频率限制放在路由层，不放在 Service 层
- [ ] 限频命中返回友好提示（lang 键），不暴露系统细节

---

## 真实案例速查

| 案例 | 漏洞 | 修复 |
|---|---|---|
| AuditService::approve() | 无 CAS，重放刷积分/计数器 | CAS 条件 `audit_status IN (PENDING, IGNORED)` |
| xnx_invite useCode | 先读后写 use_count | CAS `use_count<max_use_count` + `use_count+1` |
| xnx_invite sendRewards | 无幂等，并发重复发奖 | UNIQUE(invitee_uid) + INSERT IGNORE + 回滚 |
| xnx_quest grantReward | 部分成功无回滚 | 回滚已成功发放的 credits/golds |
| xnx_duel joinDuel | daily_limit TOCTOU | GET_LOCK 串行化同用户操作 |
| xnx_lottery drawSidebar | 库存可扣成负数 | CAS `stock>0` + `stock-1` |

---

## 相关文档

- [plugindev/15-concurrency-security.md](../../plugindev/15-concurrency-security.md) —— 完整版并发安全手册
- [api-cheatsheet.md](api-cheatsheet.md) —— `db_update` 返回值、`db_cond_to_sqladd` 条件数组语法
- [ai-rules.md](ai-rules.md) —— AI 协作规则速查（含安全检查清单）
