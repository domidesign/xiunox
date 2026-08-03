# XIUNOX_Credits 积分系统

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

XIUNOX_Credits 是 Xiuno X 论坛的原生积分系统，采用 **规则引擎 + 服务层** 的分层架构设计。系统支持三种货币并行管理：**积分（credits）** 用于用户等级与权限判断，**金币（golds）** 用于虚拟资产流通，**人民币（rmbs）** 用于真实货币记录。

规则引擎分为 **全局规则**（适用于整个站点）和 **版块规则**（可按版块差异化配置，优先级高于全局），覆盖发帖、回帖、被点赞、收藏、置顶、审核等常见事件。底层通过 MySQL `SELECT FOR UPDATE` 行锁 + 事务保证余额一致性，通过 `GET_LOCK/RELEASE_LOCK` 应用级锁防止点赞类事件的并发重复发放，并内置每日操作次数限制与日志溯源机制，确保积分变动的安全性与可追溯性。

---

## 站长指南

### 配置入口

后台 → 运营 → **积分规则**（全局配置）| 版块 → 编辑版块 → **版块积分规则**（按版块差异化覆盖）

### 配置项说明

| 配置项 | 说明 |
|--------|------|
| 事件（event） | 触发积分变动的行为标识，如 `thread_create`、`post_create`、`be_liked`、`be_favorited`、`thread_top`、`thread_delete` 等 |
| 积分变动（credits_change） | 正值奖励、负值扣除，取值范围 -999 ~ 999 |
| 金币变动（golds_change） | 同上 |
| 人民币变动（rmbs_change） | 同上 |
| 启用状态（enabled） | 关闭后该事件不触发任何积分变动 |
| 每日限制（daily_limit） | 单用户每日最多触发次数，0 使用全局默认值，-1 表示不限制 |

### 使用场景

1. **激励发帖**：为 `thread_create` 设置 credits_change = 5，鼓励用户发帖
2. **优质内容奖励**：为 `be_liked` 设置 credits_change = 2、golds_change = 1，被点赞者获得积分和金币
3. **违规惩罚**：为 `thread_delete` 设置 credits_change = -10，发布违规内容被删帖时扣除积分
4. **审核场景**：通过 `applyRuleDeductOnly` 先执行扣减部分，审核通过后由 `applyRewardOnly` 补发奖励

### 注意事项

- 版块规则优先级高于全局规则，配置了版块规则的版块将完全覆盖对应事件的全局设置
- 变动值范围限制在 -999 ~ 999，超出范围的值会被自动截断
- 每日限制按"用户 + 事件原因"统计，不同事件的限制互不影响
- `be_liked` 和 `be_favorited` 事件已内置防重复机制，同一用户对同一帖子只会触发一次奖励

---

## 开发者指南

### 核心服务类

#### CreditsService（`lib/CreditsService.php`）

积分操作的底层服务类，负责原子化的积分增减与日志记录。

| 方法 | 说明 |
|------|------|
| `add(int $uid, string $type, int $amount, string $reason, int $dailyLimit = 0, bool $reasonIsRaw = false): array` | 增加积分。`$type` 为 `credits`/`golds`/`rmbs`，`$reason` 用于日志溯源与防刷统计 |
| `sub(int $uid, string $type, int $amount, string $reason, int $dailyLimit = 0, bool $reasonIsRaw = false): array` | 扣减积分。余额不足时自动回滚 |
| `get(int $uid, string $type = ''): array` | 查询余额。不传 `$type` 则返回三种货币的余额 |
| `log(int $uid, int $page, int $pagesize, string $type = ''): array` | 查询积分日志（平铺模式） |
| `logGrouped(int $uid, int $page, int $pagesize): array` | 按操作分组的积分日志（一次操作的多类型变动合并显示） |
| `checkNegative(int $uid, string $type, int $amount): array` | 检查余额是否充足，用于前端预扣验证 |

#### CreditsRuleService（`service/CreditsRuleService.php`）

积分规则引擎服务，负责规则查询、应用与扩展。

| 方法 | 说明 |
|------|------|
| `getRule(string $event, int $fid = 0, int $uid = 0, string $source = ''): array` | 获取指定事件的积分规则，支持版块规则覆盖全局规则，含 N+1 查询缓存 |
| `applyRule(string $event, int $uid, int $fid = 0, bool $checkOnly = false, string $source = ''): array` | 应用积分规则（核心便捷方法）。内置事务、行锁、防刷、日志全流程 |
| `applyRuleBatch(string $event, array $uid_fid_pairs): array` | 批量应用规则，预加载规则缓存消除 N+1 查询 |
| `applyRuleDeductOnly(string $event, int $uid, int $fid = 0): array` | 仅执行扣减部分（审核前置扣除） |
| `applyRewardOnly(string $event, int $uid, int $fid = 0): array` | 仅执行奖励部分（审核通过后补发） |
| `saveGlobalRules(array $rules): array` | 批量保存全局规则 |
| `saveForumRules(int $fid, array $rules): array` | 批量保存指定版块的规则 |
| `clearRuleCache(): void` | 清空规则缓存。`saveGlobalRules()` 和 `saveForumRules()` 成功后自动调用，也可手动触发以强制刷新缓存 |

#### RankService（`service/RankService.php`）

排行榜服务类，提供积分排行、热帖排行、活跃用户排行。

| 方法 | 说明 |
|------|------|
| `getCreditsRanking(int $page, int $pageSize): array` | 积分排行榜 |
| `getHotThreads(string $period, int $page, int $pageSize, bool $isAdmin = false): array` | 热帖排行（按浏览量 + 回复数） |
| `getActiveUsers(string $period, int $page, int $pageSize): array` | 活跃用户排行 |

### 钩子点

系统提供了丰富的插件钩子，供第三方扩展：

| 钩子名称 | 触发位置 | 用途 |
|----------|----------|------|
| `credits_rule_get_before` | `CreditsRuleService::getRule()` 规则查询前 | 插件可接管规则查询，返回 `['handled'=>true, ...]` 或 `['enabled'=>false]` |
| `credits_before_change` | `CreditsService::add()`/`sub()` 执行前 | 可阻止操作（返回 `false`）或修改变动值（返回 `['amount'=>N]`） |
| `credits_after_change` | `CreditsService::add()`/`sub()` 执行后 | 积分变动完成后触发，可用于通知、统计等 |

**注册钩子示例：**
```php
// 在插件或主题的 init 阶段注册钩子
CreditsService::registerHook('credits_after_change', function($uid, $type, $change, $balance, $reason) {
    // 积分变动后发送通知
    if ($type === 'credits' && $change > 0) {
        // 发送站内信或推送通知
    }
});
```

### 扩展方式

1. **自定义事件规则**：通过 `CreditsRuleService::saveGlobalRules()` 或 `saveForumRules()` 编程式写入新事件的规则
2. **接管规则引擎**：注册 `credits_rule_get_before` 钩子，完全由插件决定某事件的积分规则
3. **拦截积分操作**：通过 `credits_before_change` 实现条件性拦截（如新手保护期禁止高金额扣减）
4. **联动外部系统**：通过 `credits_after_change` 将积分变动同步到外部系统（如CRM、短信通知）

**规则缓存管理示例**：
```php
// 保存规则后自动清理缓存
CreditsRuleService::saveGlobalRules($rules);   // 内部自动调用 clearRuleCache()
CreditsRuleService::saveForumRules(3, $rules);  // 内部自动调用 clearRuleCache()

// 手动清理缓存（如需强制刷新）
CreditsRuleService::clearRuleCache();
```

### 代码示例

**示例 1：为用户增加积分**
```php
$creditsService = new CreditsService($db, $conf);
$result = $creditsService->add($uid, 'credits', 10, 'manual_reward');
// $result: ['ok'=>true, 'balance'=>150, 'change'=>10]
```

**示例 2：应用发帖规则**
```php
$result = CreditsRuleService::applyRule('thread_create', $uid, $fid);
// 自动完成：查规则 → 事务 → 行锁 → 积分增加 → 写日志 → 返回变动详情
```

**示例 3：前端预检查扣减余额**
```php
$check = CreditsRuleService::applyRule('thread_buy', $uid, $fid, true);
if ($check['ok'] && empty($check['daily_limit_reached'])) {
    // 余额充足，可以发起正式操作
}
```

**示例 4：审核流程**
```php
// 提交审核时扣除保证金
CreditsRuleService::applyRuleDeductOnly('audit_post', $uid, $fid);
// 审核通过后发放奖励
CreditsRuleService::applyRewardOnly('audit_post', $uid, $fid);
```

---

## 常见问题

1. **积分规则的全局和版块规则优先级如何？**
   版块规则优先级高于全局规则。当指定版块配置了某事件的规则时，系统优先使用版块规则；未配置时回退到全局规则。

2. **如何防止用户通过重复点赞刷积分？**
   系统内置了两层防刷机制：一是 `be_liked`/`be_favorited` 事件通过日志表检查同一用户对同一帖子只发放一次奖励；二是所有事件均支持每日次数限制（`daily_limit`），超限后不再发放。

3. **积分操作的并发安全性如何保证？**
   底层使用 MySQL `SELECT FOR UPDATE` 行锁 + 事务保证余额原子性；对点赞类高并发场景额外使用 `GET_LOCK` 应用级锁，防止并发请求同时处理同一事件导致重复发放。

4. **积分日志为什么要按操作分组显示？**
   一次操作可能同时变动三种货币（如发帖奖励 credits +5、golds +2），底层会产生三条日志记录。`logGrouped()` 方法按 `create_date + reason` 将同一次操作的多条记录合并为一条，便于用户理解和对账。

5. **钩子返回值如何影响积分操作？**
   `credits_before_change` 返回 `false` 可完全阻止操作；返回 `['amount'=>N]` 可动态修改变动数值。`credits_rule_get_before` 返回 `['handled'=>true, ...]` 可接管规则查询，返回 `['enabled'=>false]` 可强制禁用指定事件的积分规则。