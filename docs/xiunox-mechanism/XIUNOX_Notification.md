# XIUNOX_Notification 通知系统

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 通知系统采用「服务类 + 类型注册中心 + 函数式 API」三层架构，为站内用户提供完整的消息通知能力。系统支持 19 种核心通知类型，覆盖点赞、评论、回复、收藏、@提及、关注、帖子动态、审核状态等场景，并通过 Tab 分类在前端展示不同维度的通知筛选。

通知以「未读/已读」状态管理，未读数量通过 `user.unread_notices` 字段实时维护，顶部导航栏自动显示未读角标。系统内置防抖机制（30 秒内同用户同类型同帖子不重复发送），以及批量创建接口以消除 N+1 INSERT 性能问题。插件可通过 `notify_types_register.php` 钩子注册自定义通知类型，实现无限扩展。

---

## 站长指南

### 配置入口

通知系统无需额外配置，开箱即用。相关管理入口：

- **后台通知管理**：后台 → 用户 → 通知管理（查看/删除全站通知记录）
- **用户中心通知页**：前台用户中心 → 通知（查看个人通知列表、标记已读）
- **通知 Tab 筛选**：通知页面顶部 Tab 栏，支持按类型快速筛选

### 配置项说明

通知系统核心配置位于代码层面，站长主要关注以下运行机制：

| 机制 | 说明 |
|------|------|
| 防抖时间窗口 | 30 秒内同一用户对同一目标重复操作（点赞/收藏/回复），不会重复推送通知 |
| 自己通知自己 | 点赞/回复等互动类型自动过滤自身通知；公告、系统通知、审核中类型允许自发自收 |
| 未读计数 | 通过 `user.unread_notices` 字段维护，无需手动清除缓存 |
| 全局公告 | uid=0 的 announcement 类型通知，所有用户可见 |

### 通知类型说明

系统内置 19 种通知类型，按 Tab 分组：

| Tab | 类型 (type) | 触发场景 |
|-----|-------------|----------|
| 点赞 | `like` | 用户点赞帖子或评论 |
| 评论/回复 | `comment` / `reply` | 用户评论帖子 / 回复评论 |
| 收藏 | `favorite` | 用户收藏帖子 |
| @提及 | `mention` | 帖子/评论/回复中 @ 了某人 |
| 关注 | `follow` | 用户关注了你 |
| 帖子 | `thread` / `thread_forum` / `forum_post` | 关注的用户发帖 / 关注的版块有新帖 |
| 公告 | `announcement` | 管理员发布的全站公告 |
| 系统 | `system` | 系统级通知 |
| 系统 | `audit_pending` / `audit_approve` / `audit_reject` | 内容审核状态变更 |
| 系统 | `digest` | 帖子被设为精华 |
| 系统 | `report_auto_audit` / `report_result` / `report_penalty` | 举报处理相关通知 |
| 其他 | `pm` | 私信通知 |
| 其他 | `other` | 兜底类型，未匹配的通知归入此类 |

### 使用场景

**典型用户流程**：
1. 用户 A 点赞用户 B 的帖子 → B 收到 `like` 通知
2. 用户 C 回复用户 B 的评论 → B 收到 `reply` 通知（含被回复内容摘要）
3. 管理员审核帖子通过/驳回 → 作者收到 `audit_approve` / `audit_reject` 通知
4. 用户 B 在通知中心点击「全部标记为已读」→ 所有未读通知清空

### 注意事项

1. **通知数据清理**：通知记录会持续累积，建议定期清理过期通知（可通过后台管理或自定义脚本）
2. **缓存一致性**：通知创建/标记已读/删除时，系统会自动清理用户缓存，站长无需手动干预
3. **系统公告**：uid=0 的公告对所有用户可见，删除时需谨慎
4. **防抖不可配置**：当前防抖窗口固定为 30 秒，如需调整需二次开发

---

## 开发者指南

### 核心服务类

#### NotificationService

文件位置：`service/NotificationService.php`

| 方法 | 签名 | 说明 |
|------|------|------|
| `send()` | `send(int $uid, string $type, array $data): int` | 发送一条通知，返回插入 ID |
| `getUnreadCount()` | `getUnreadCount(int $uid): int` | 获取用户未读通知数 |
| `markAsRead()` | `markAsRead(int $id): int` | 标记单条通知为已读 |
| `markAllAsRead()` | `markAllAsRead(int $uid): int` | 标记用户所有通知为已读 |
| `getList()` | `getList(int $uid, int $page, int $pagesize): array` | 分页获取通知列表 |

#### NotifyTypeRegistry

文件位置：`lib/NotifyTypeRegistry.php`

| 方法 | 说明 |
|------|------|
| `register($type, $config)` | 注册自定义通知类型 |
| `get_label($type)` | 获取类型的操作描述 |
| `get_icon($type)` | 获取类型的图标 |
| `get_tab($type)` | 获取类型所属 Tab |
| `get_all_tabs()` | 获取所有 Tab 列表（用于前端菜单） |
| `get_types_by_tab($tab)` | 获取指定 Tab 下的所有 type |
| `get_action_text($type, $notify, $prefetched)` | 动态获取操作描述（区分帖子/评论场景） |
| `get_message_callback($type)` | 获取类型的消息格式化回调 |
| `init()` | 初始化（注册核心类型 + 触发插件钩子） |

### 核心函数 API

#### notify_create()

创建单条通知，系统自动处理防抖、自身通知过滤、未读计数更新。

```php
notify_create(
    int $uid,           // 接收者 UID（0=系统公告）
    int $from_uid,      // 发送者 UID（0=系统）
    string $type,       // 通知类型（如 like/reply/audit_approve）
    int $tid = 0,       // 关联帖子 ID
    int $pid = 0,       // 关联回帖 ID
    string $content = '', // 内容摘要（纯文本）
    array $extra = array() // 扩展字段
): int|false
```

**$extra 扩展字段**：
- `message`：富文本消息（用于 announcement/system/pm/other 类型的自定义消息）
- `icon`：自定义图标名
- `url`：自定义跳转链接
- `reply_to_uid`：回复目标用户 UID
- `parent_pid`：被回复的回帖 ID

#### notify_create_batch()

批量创建通知，单次 SQL 插入，支持防抖过滤。

```php
notify_create_batch(array $records): int
```

**$records 格式**：
```php
$records = array(
    array(
        'uid' => 1,
        'from_uid' => 2,
        'type' => 'like',
        'tid' => 100,
        'pid' => 0,
        'content' => '',
    ),
    // ... 更多记录
);
```

#### 其他常用函数

| 函数 | 说明 |
|------|------|
| `notify_read($nid)` | 读取并格式化单条通知 |
| `notify_find_by_uid($uid, $page, $pagesize)` | 分页获取用户通知列表 |
| `notify_find_latest($uid, $pagesize)` | 获取最新 N 条通知（下拉菜单用） |
| `notify_count_unread($uid)` | 获取未读通知数 |
| `notify_mark_read($nid)` | 标记单条已读 |
| `notify_mark_all_read($uid)` | 标记全部已读 |
| `notify_format(&$notify, $prefetched)` | 格式化通知数据（填充用户名、头像、链接、摘要等） |
| `notify_preload($notifylist)` | 批量预加载关联数据，消除 N+1 查询 |

### 钩子点

| 钩子 | 文件位置 | 说明 |
|------|----------|------|
| `notify_types_register.php` | `plugin/{plugin_id}/hook/notify_types_register.php` | 插件注册自定义通知类型 |
| `model_notify_start.php` | `hook/model_notify_start.php` | 通知模型操作前置钩子 |
| `model_notify_end.php` | `hook/model_notify_end.php` | 通知模型操作后置钩子 |

### 扩展方式

**注册自定义通知类型**：

在插件目录创建 `hook/notify_types_register.php`：

```php
<?php
!defined('DEBUG') AND exit('Access Denied.');

NotifyTypeRegistry::register('my_custom_type', array(
    'tab'   => 'other',          // 归属 Tab
    'icon'  => 'bell',           // Tabler Icons 图标名
    'label' => '自定义通知描述',  // 操作描述
    'message_callback' => function($notify, $prefetched = array()) {
        list(, , $subject_link) = NotifyTypeRegistry::compute_subject_context($notify, $prefetched);
        $message = $notify['from_username'] . ' 执行了某个操作';
        if($subject_link) $message .= ' ' . $subject_link;
        return array(
            'summary' => '操作摘要',
            'message' => $message,
        );
    },
));
```

**发送自定义类型通知**：

```php
// 单条
notify_create($uid, $from_uid, 'my_custom_type', $tid, 0, '摘要');

// 批量
notify_create_batch(array(
    array('uid' => $uid1, 'from_uid' => $from_uid, 'type' => 'my_custom_type', 'tid' => $tid),
    array('uid' => $uid2, 'from_uid' => $from_uid, 'type' => 'my_custom_type', 'tid' => $tid),
));
```

### 代码示例

**示例 1：点赞通知**
```php
// 用户点赞帖子后，通知帖子作者
notify_create(
    $thread['uid'],      // 帖子作者 UID
    $user['uid'],        // 当前用户 UID
    'like',              // 通知类型
    $thread['tid'],      // 帖子 ID
    0,                   // 回帖 ID（0 表示帖子级别的操作）
    ''                   // 内容摘要（like 类型通过 message_callback 动态生成）
);
```

**示例 2：审核结果通知**
```php
// 审核通过后，通知内容作者
notify_create(
    $post['uid'],
    0,                   // 系统通知，发送者为 0
    'audit_approve',
    $thread['tid'],
    $post['pid'],
    '您的内容已通过审核'
);
```

**示例 3：批量公告推送**
```php
// 向所有用户推送系统公告
$userlist = db_find('user', array(), array('uid' => 1), 1, 1000, 'uid');
$records = array();
foreach($userlist as $u) {
    $records[] = array(
        'uid' => $u['uid'],
        'from_uid' => 0,
        'type' => 'announcement',
        'tid' => 0,
        'pid' => 0,
        'content' => '系统升级通知',
        'message' => '<p>系统将于今晚维护...</p>',
    );
}
notify_create_batch($records);
```

**示例 4：查询用户未读通知**
```php
// 获取当前用户未读数
$unread = notify_count_unread($uid);

// 获取最新 8 条通知（顶部下拉菜单用）
$latest = notify_find_latest($uid, 8);

// 渲染模板时格式化数据
foreach($latest as &$notify) {
    notify_format($notify);
}
```

---

## 常见问题

1. **Q: 通知的防抖机制是怎样的？**
   A: 系统对 `like`、`favorite`、`reply` 三种类型启用 30 秒防抖：同一用户对同一目标的相同操作，30 秒内只推送一次通知，避免用户快速连点造成的通知轰炸。

2. **Q: 如何让自定义通知类型出现在前端 Tab 筛选中？**
   A: 通过 `NotifyTypeRegistry::register()` 注册类型时指定 `tab` 字段为已有的 Tab 名（like/reply/favorite/mention/follow/thread/announcement/system/other），该类型会自动归入对应 Tab。如需新增 Tab，需修改 `NotifyTypeRegistry::$tabs` 数组。

3. **Q: 系统公告（uid=0）如何让所有用户都能看到？**
   A: 创建通知时将 `uid` 设为 0，类型设为 `announcement`。前端通过 `notify_find_announcements()` 获取全局公告列表。注意 uid=0 的公告对所有用户可见，删除前请确认。

4. **Q: `notify_create()` 和 `notify_create_batch()` 该用哪个？**
   A: 单用户通知用 `notify_create()`；多用户批量推送用 `notify_create_batch()`，后者批量更新未读计数，性能更优且避免 N+1 问题。两者都支持防抖和自身通知过滤。

5. **Q: 已读通知的未读计数如何正确更新？**
   A: 系统在 `notify_mark_read()`（单条）和 `notify_mark_all_read()`（全部）中自动更新 `user.unread_notices` 计数器，并清理用户缓存。开发者只需调用相应函数，无需手动操作数据库。
