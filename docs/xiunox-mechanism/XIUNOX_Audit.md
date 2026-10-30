# XIUNOX_Audit 审核机制

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 的审核机制基于**三级审核规则**和**四状态状态机**构建，覆盖帖子（thread）、回帖（post）以及个人资料变更（profile）三类内容的全生命周期管理。

三级审核规则按优先级依次触发：**版块级**（`forum_access.allowthreadaudit` / `forum_access.allowpostaudit`）→ **用户组级**（`PermissionService::check('allow_direct_post')`）→ **关键词触发级**（敏感词过滤器命中）。任一命中即进入待审队列。内容发布时若需审核，系统跳过计数增加、积分发放和通知推送，待审核通过后统一补发，确保数据一致性。

状态机包含四种状态：`pending`（0，待审）、`approved`（1，通过）、`rejected`（2，驳回）、`ignored`（3，忽略）。其中 `ignored` 为管理员暂放功能，不计入未审核数量，内容对用户保持不可见，作者无感知，记录仍保留可后续处理。

## 站长指南

### 配置入口

| 配置项 | 路径 | 说明 |
|--------|------|------|
| 版块级审核 | 后台 → 版块管理 → 编辑版块 → 权限设置 | 按用户组精细控制发帖/回帖是否需要审核 |
| 用户组级审核 | 后台 → 用户组管理 → 权限 → `allow_direct_post` / `allow_direct_reply` | 关闭后该组所有用户发帖/回帖进入审核 |
| 关键词审核 | 后台 → 安全中心 → 敏感词管理 | 命中敏感词的内容自动进入审核 |
| 举报自动审核 | 后台 → 插件 → 举报 → 配置 | 同一内容被 `auto_audit_count` 个不同用户举报后自动触发审核 |

### 配置项说明

**版块级审核**支持两种模式：
- **权限矩阵模式**（`accesson=1`）：通过 `forum_access` 表为每个用户组独立设置 `allowthreadaudit` 和 `allowpostaudit`；
- **兼容模式**（`audit_thread=1`）：旧版单字段开关，开启后该版块所有发帖/回帖均需审核。

**积分延迟发放**机制：内容创建时若需审核，系统跳过积分发放；审核通过后通过 `CreditsRuleService::applyRewardOnly()` 补发 `thread_post`、`reply_post`、`be_commented` 事件的积分。

**通知延迟发送**机制：审核通过后补发三类通知——关注该用户的动态推送、关注该版块的动态推送（含 30 分钟频次控制）、@提及通知（支持纯文本 `@username` 和富文本 `data-type="mention"` 两种格式）。

### 使用场景

1. **新注册用户限制**：关闭游客和新用户组的 `allow_direct_post`，所有新用户发帖需人工审核；
2. **特殊版块管控**：交易、广告等版块开启 `allowthreadaudit`，确保每条帖子人工复核；
3. **敏感时期应急**：调整敏感词过滤规则，利用关键词触发级快速拦截敏感内容；
4. **举报自动处置**：设置 `auto_audit_count=3`，热门帖子被 3 人举报后自动下线进入审核队列。

### 注意事项

- 审核队列中的内容对普通用户不可见，仅管理员在后台审核页面可见；
- **忽略（ignored）状态**的内容不计入未审核数量，管理员可通过"全部待审"视图查看；
- 作者重新提交被驳回的内容最多允许 5 次（`MAX_RESUBMIT_COUNT=5`），超限需联系管理员；
- 批量操作通过 `notify_create_batch` 批量发送通知，比逐条处理显著减少数据库写入；
- 缓存自动清理：审核操作会同步清理版块列表缓存、首页缓存和帖子详情缓存，确保前台数据即时更新。

## 开发者指南

### 核心服务类

| 类名 | 文件路径 | 职责 |
|------|----------|------|
| `AuditService` | `lib/security/AuditService.php` | 审核主服务：规则判定、状态流转、队列管理、积分/通知补发 |
| `ContentModerationService` | `lib/security/ContentModerationService.php` | 内容审核扩展点：支持插件覆盖审核逻辑，结果映射到 `audit_status` |
| `ReportService` | `lib/security/ReportService.php` | 举报服务：举报创建、自动审核触发、管理员通知 |

### 审核操作权限校验

所有审核操作（`approve`、`reject`、`ignore`、`batch_approve`、`batch_reject`、`batch_ignore`）均已增加权限校验，通过 `checkAuditPermission()` 方法统一验证操作者是否具备对应内容类型的审核权限。

```php
// 审核权限校验（内部自动调用）
AuditService::checkAuditPermission($operatorUid, 'thread');
// 校验逻辑：超级管理员 > 版块版主 > 用户组权限（allowtop/allowdelete 等）

// approve() 方法已重构为约 96 行，核心逻辑委托给以下子方法：
AuditService::buildApproveNotifications($thread, $post, $operatorUid);
AuditService::clearApproveCaches($fid, $tid);
AuditService::sendDelayedNotificationsForThread($thread, $operatorUid);
AuditService::sendDelayedNotificationsForPost($post, $operatorUid);

// 批量通知优化：@提及和关注者通知改用 notify_create_batch() 批量插入
// 替代逐条 insert，显著减少数据库 IO 开销
```

### 钩子点

| 钩子名 | 触发时机 | 用途 |
|--------|----------|------|
| `audit_approve_end.php` | 单条审核通过后 | 传递 `target_type`、`target_id`、`tid`、`fid`、`thread`、`post` 等上下文 |
| `audit_batch_approve_start.php` | 批量审核通过前 | 可用于批量操作前的前置校验或日志记录 |
| `audit_batch_reject_start.php` | 批量审核驳回前 | 可用于批量操作前的前置校验 |
| `security_moderation_check` | `ContentModerationService::moderate()` 调用时 | 插件实现自定义审核逻辑，返回 `'pass'`/`'review'`/`'block'` |

### 扩展方式

**方式一：实现自定义审核服务**

在插件中定义函数 `security_moderation_check($type, $content, $scene)`，返回标准审核结果。该函数会被 `ContentModerationService::moderate()` 自动调用，返回值映射关系为：`pass` → 直接通过（`audit_status=1`）、`review` → 进入审核队列（`audit_status=0`）、`block` → 直接驳回（`audit_status=2`）。

**方式二：审核完成后扩展处理**

通过 `plugin_hook('audit_approve_end.php', $data)` 在审核通过后触发自定义逻辑，例如同步第三方内容平台、触发自动化工作流等。

### 代码示例

**示例 1：判断内容是否需要审核**

```php
use AuditService;

// 判断发帖是否需要审核
$needAudit = AuditService::need_audit($fid, $gid, $subject, $message);
if ($needAudit) {
    // 内容进入审核队列，作者提示"内容待审核后可见"
}
```

**示例 2：审核通过单条内容**

```php
use AuditService;

// 审核通过指定帖子
$result = AuditService::approve('thread', $tid, $operator_uid);
// 审核通过后自动：补发积分、发送作者通知、推送关注者通知、清理缓存
```

**示例 3：批量审核操作**

```php
use AuditService;

// 批量通过多条内容
$successCount = AuditService::batch_approve('post', $ids, $operator_uid);

// 批量驳回多条内容并附带原因
$successCount = AuditService::batch_reject('thread', $tids, $operator_uid, '违反社区规范');
```

**示例 4：集成第三方内容审核 API**

```php
// 在插件中实现 security_moderation_check 函数
function security_moderation_check($type, $content, $scene) {
    // 调用第三方审核 API
    $response = ThirdPartyModeration::check($content, $type);
    switch ($response->status) {
        case 'approved': return 'pass';
        case 'review':   return 'review';
        case 'blocked':  return 'block';
        default:         return 'pass';
    }
}
```

**示例 5：获取审核队列和日志**

```php
use AuditService;

// 获取待审列表（含 ignored 状态）
$pendingList = AuditService::get_pending_list('thread', $page, $pagesize);

// 获取待审数量（仅 pending 状态）
$pendingCount = AuditService::get_pending_count('thread');

// 获取审核日志
$logs = AuditService::get_audit_logs($page, $pagesize);
```

## 常见问题

1. **Q：审核通过后内容多久对用户可见？**  
   A：审核通过后系统立即清理版块缓存、首页缓存和帖子详情缓存，内容即时对普通用户可见，无需等待。

2. **Q：为什么内容被忽略了而不是驳回？**  
   A：`ignored` 状态用于管理员暂不确定或需进一步观察的内容。它不计入未审核数量（减少对管理员的干扰），但内容保持不可见。管理员可随时在"全部待审"视图中将其改为通过或驳回。

3. **Q：积分延迟发放如何保证不重复？**  
   A：`grantCredits()` 在审核通过时才调用，且调用 `CreditsRuleService::applyRewardOnly()` 仅执行加分逻辑（不含扣分）。内容创建时因待审已跳过所有积分操作，不会产生重复发放。

4. **Q：三级审核规则的优先级是什么？**  
   A：版块级优先级最高（命中即审核），其次是用户组级，最后是关键词触发级。三级之间为"或"关系，任一命中即进入审核。实际场景中通常只需配置其中一级即可。

5. **Q：批量审核时部分内容处理失败怎么办？**  
   A：`batch_approve`/`batch_reject` 返回成功处理的记录数。批量操作已过滤已处理状态的内容，仅对仍处于 `pending` 的记录生效。失败通常由数据库异常或记录不存在导致，可通过返回值判断并手动处理剩余项。
