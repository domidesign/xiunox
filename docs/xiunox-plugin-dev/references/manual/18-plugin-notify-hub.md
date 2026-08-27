# 插件通知聚合中心（Notify Hub）

> 适用于 XIUNOX（核心含 `model/plugin_notify.func.php`，v1.1.5+）
> 相关源码：`model/plugin_notify.func.php`（核心服务）、`admin/route/plugin.php` notice 动作（设置页）、`lib/AdminNotifyService.php`（通道执行）
> 相关 Hook：`plugin_notice_count.php`（红点数据源，插件提供）
> 已接入插件参考：xnx_verify 1.3.1、xnx_quest 1.4.19、xnx_medal 1.0.9、xnx_ad_selfbuy 1.3.4、xnx_appcenter 1.15.36、xnx_report 1.0.3、xnx_friendlink 1.1.3

---

## 设计背景

插件产生管理员待办（新申请 / 新举报 / 新订单）时，传统做法是插件自己写通知逻辑：站内消息查管理员逐个 `notify_create`、邮件拼 SMTP、后台红点无从谈起。每个插件各写一套，站长要在每个插件设置页单独配通知开关和邮箱，且大多数插件只有站内消息一个通道。

通知聚合中心把这整套能力收敛为**核心服务 + 统一设置页**：

1. **开发者写一次代码**：调用 `plugin_notify_fire()` + 提供 `plugin_notice_count.php` hook，三通道全部接入；
2. **站长只在一处配置**：后台 → 插件 → 通知设置（`/admin/?plugin-notice.htm`），全局默认邮箱 + 每插件三通道开关 + 插件专属提醒邮箱；
3. **插件设置页禁止再提供任何通知开关/邮箱控件**（避免两处配置打架，见第 8 节迁移规范）。

## 架构总览

```
插件业务代码（新待办产生）
    │
    ├─ plugin_notify_fire($plugin, $event, $payload)     推送式：站内消息 + 邮件（同一次调用）
    │      │
    │      ├─ 读统一配置 plugin_notify_config_get($plugin)（通道开关 + 插件专属邮箱）
    │      ├─ 邮箱解析三层合并：payload.email_to > 插件配置 > 全局默认（逗号/分号/空格/换行分隔，自动去重校验）
    │      ├─ throttle 节流（同 plugin+event，高频事件防刷屏）
    │      └─ 默认顺带失效红点缓存（badge_flush 参数）
    │
    └─ hook/plugin_notice_count.php                        拉取式：红点数据源
           │
           ├─ 核心插件列表页/侧边栏渲染时 → plugin_notice_count_all() 收集全部启用插件
           │    （单插件 hook 报错被 try/catch 隔离计 0，不影响其他插件）
           └─ 结果聚合存单键缓存 core_plugin_notice（TTL 60s，后台缓存 TTL 配置页可调）

管理员处理完待办（审核通过/拒绝）
    │
    └─ plugin_notice_flush() 主动失效红点缓存（实时消失，不等 60s TTL）
       + 待办清零时 cache_delete('core_plugin_notify_throttle_{plugin}_{event}')（下次新待办立即再推送）
```

**两机制独立工作**：邮件被节流跳过时红点依然准确；红点数量永远以 hook 里的实时查询为准。

---

## 1. 三通道能力总览与选型建议

| 通道 | 触发方式 | 管理员可配置 | 适用场景 |
|------|---------|-------------|---------|
| 站内消息 | **推送式**：插件调 `plugin_notify_fire()` 时立即发 | 开/关 | 需要即时知晓的事件（新申请、新举报） |
| 邮件提醒 | **推送式**：与站内消息同一次调用发出 | 开/关 + 插件专属邮箱 | 管理员不常登录后台、事件重要需离线触达 |
| 后台红点 | **拉取式**：插件提供 count hook，核心定期聚合查询 | 开/关 | 待办类事件的持续可见提醒（插件列表页角标、侧边栏徽章） |

**选型建议**：绝大多数插件只需要一个事件（如"新待办"），三个通道全开即可——`plugin_notify_fire()` 一次调用全部搞定。红点通道需要额外提供 count hook（见第 2 节），不提供则红点自动缺席，不影响另外两通道。

## 2. 红点 hook 协议（plugin_notice_count.php）

在插件目录新建 `hook/plugin_notice_count.php`：

```php
<?php exit;
// 待审核 XX 计数（插件通知聚合红点数据源）
// 由核心 plugin_notice_count_all() 读取并隔离执行（错误计 0 不影响其他插件）
// 协议：写回 $data['count']（待处理数）与 $data['url']（后台待处理页地址）
// 规范：体内禁 exit/die/return；注释禁 hook 占位符格式
$_my_pending = db_count('your_table', array('status' => 0));
$data['count'] = intval($_my_pending);
$data['url'] = function_exists('admin_url') ? admin_url('plugin-setting-your_plugin-pending') : '';
```

核心约定：

- **文件头必须写 `<?php exit;`**：核心读取文件后剥离该头部再隔离执行，防直接访问，是项目标准写法；
- `$data['count']` 为 0 或未写时该插件不显示红点；
- `$data['url']` 是红点/角标点击后的跳转地址，必须用 `admin_url()`（从前台/任意上下文生成带 `admin/` 前缀的后台 URL），并加 `function_exists` 守卫；
- **执行环境在函数作用域内**：只能使用全局函数与 `$data` 变量，不要引用外部局部变量；类常量不可直接用（类不保证已加载，表名/状态值写字面值）；
- 单插件 hook 报错被核心 try/catch 隔离（该插件计 0），不会影响其他插件。

### hook 文件编写规范（三条禁区）

1. **体内禁止 `exit` / `die`**：hook 是被 `eval` 执行的代码片段，`exit` 会直接终止整个页面请求（白屏）；
2. **体内禁止 `return`**：`return` 会从宿主函数提前返回，截断后续所有插件的收集；
3. **注释禁止 `// hook xxx` 格式**：会被框架 hook 编译器正则误匹配为 hook 占位符导致重复拼接崩溃，改用 `// hook: xxx`（冒号分隔）或纯文字注释。

同时在 `conf.json` 的 `hooks_rank` 登记 `"plugin_notice_count.php": 10`，并递增插件 `version`。

### 双审核流插件（badge 计数合计）

插件有多条审核流时（如 xnx_appcenter：应用上架 + 开发者申请），count hook 返回两表待审数**合计**，URL 指向主审核 Tab；fire 时各审核流用**不同事件名**（`new_pending` / `dev_new_pending`）独立节流，同一审核流的多个触发点（如 createApp/updateApp）用**同一事件名**共享节流。

## 3. plugin_notify_fire() 参数表与最小示例

```php
plugin_notify_fire($plugin, $event, $payload);
```

| 参数 | 类型 | 说明 |
|------|------|------|
| `$plugin` | string | 插件目录名，如 `'xnx_verify'` |
| `$event` | string | 事件名（如 `'new_pending'`），用作节流键，只允许字母数字下划线连字符 |
| `$payload` | array | 见下表 |

`$payload` 支持的键：

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| `title` | string | 必填其一 | 标题（站内消息摘要、邮件主题） |
| `content` | string | 必填其一 | 正文（纯文本） |
| `url` | string | `''` | 跳转链接，相对路径自动转绝对 |
| `uid` / `uids` | int / array | 管理员 | 站内消息接收者，缺省=gid 1,2 全体管理员 |
| `email_to` | string | 配置链 | 收件邮箱覆盖（支持逗号分隔多个），解析顺序：payload > 插件配置 > 全局默认，**三层合并发送**（非只取一层） |
| `channels` | array | 全部 | 限定通道，如 `array('system','badge')` |
| `badge_flush` | bool | `true` | 事件后是否失效红点缓存 |
| `throttle` | int | `0` | 同 plugin+event 节流秒数（高频事件建议 300-1800） |

返回值：`array('system'=>..., 'email'=>..., 'badge'=>...)`，各通道值为 `'off'`（关闭）/ `'sent:N'` / `'skipped'` / `'throttled'` / `'error:xxx'` / `'flushed'`。

### 最小示例

```php
// 用户提交新申请后通知管理员（三通道，5 分钟节流）
try {
    if (function_exists('plugin_notify_fire')) {
        plugin_notify_fire('your_plugin', 'new_pending', array(
            'title'    => '有新的 XX 申请待审核',
            'content'  => '用户 ' . $username . ' 提交了新申请，请前往后台处理。',
            'url'      => function_exists('admin_url') ? admin_url('plugin-setting-your_plugin-pending') : '',
            'throttle' => 300,
        ));
    }
} catch (\Throwable $e) {
    error_log('[your_plugin] notify exception: ' . $e->getMessage());
}
```

**务必 try/catch 包裹**：通知是副作用，异常不能影响业务主流程。`function_exists` 守卫用于兼容旧核心（无此函数时回退 `AdminNotifyService::audit()`，回退调用加第 6 参 `array('ignore_enabled' => true)`——旧通知键删除后 audit 缺键默认静默关闭，而旧核心下已无通知配置入口）。

### 审核处理后刷新红点

管理员处理完待办（通过/拒绝/删除）后，主动失效红点缓存，让角标实时消失：

```php
if (function_exists('plugin_notice_flush')) {
    plugin_notice_flush();
}
// 待办清零时同时清节流键，让下次新待办立即再推送（键名规则：core_plugin_notify_throttle_{plugin}_{event}）
if (function_exists('cache_delete')) {
    cache_delete('core_plugin_notify_throttle_your_plugin_new_pending');
}
```

## 4. 管理员设置页说明

后台 → 插件 → **通知设置**（`/admin/?plugin-notice.htm`）：

- **全局默认提醒邮箱**：所有插件未单独设置邮箱时的邮件收件地址；
- **每插件一行**：待处理数 + 三通道开关（站内消息/邮件/红点）+ 插件专属提醒邮箱；
- **测试邮件**：预填当前管理员邮箱，验证 SMTP 通道；
- 未配置时三通道默认全开，邮箱回退 AdminNotifyService 兜底链（插件自有 `admin_notify_emails` 设置 > 管理员账号邮箱）。

**多邮箱支持**：全局默认邮箱与插件专属邮箱均支持填写多个，逗号、分号、空格、换行分隔均可，逐个格式校验（非法丢弃不影响其他），自动去重；插件专属邮箱与全局默认邮箱**合并发送**（例：插件行填 `a@x.com`，全局填 `b@x.com`，则 a、b 都收）。

插件自身设置页**无需**再提供任何通知邮箱/开关配置——统一收敛到通知设置页，避免两处配置打架。

## 5. 插件 count 查询的缓存建议

红点聚合本身有 60s 缓存（`core_plugin_notice`），你的 hook 执行频率已被限制：

- **简单 COUNT 查询（走索引，<10ms）**：不必再加缓存，直接查；
- **插件已有 pendingCount() 方法（与后台审核列表共用口径）**：优先复用它（如 xnx_quest 复用 `QuestService::getPendingCount()`，与审核 Tab badge 同口径同缓存）；
- **重查询**（多表 JOIN / 大表扫描）：在插件侧加短 TTL 缓存（建议 30s），注意两层缓存叠加后红点最大延迟 = 60 + 30 = 90s，属可接受范围。

```php
// 重查询示例：插件侧 30s 短缓存
$_my_pending = CacheHelper::remember('pending_count', 30, function() {
    return heavy_pending_query();
}, 'your_plugin');
```

## 6. 真实接入范例：xnx_verify

**hook/plugin_notice_count.php**（红点数据源）：

```php
<?php exit;
// 待审核认证申请计数（插件通知聚合红点数据源）
// 由核心 plugin_notice_count_all() 读取并隔离执行（错误计 0 不影响其他插件）
// 协议：写回 $data['count']（待处理数）与 $data['url']（后台待处理页地址）
// 规范：体内禁 exit/die/return；注释禁 hook 占位符格式
$_verify_notice_pending = db_count('xnx_verify_apply', array('status' => 0));
$data['count'] = intval($_verify_notice_pending);
$data['url'] = function_exists('admin_url') ? admin_url('plugin-setting-xnx_verify-pending') : '';
```

**提交申请处**（VerifyService::submitApply，节流 300s）：

```php
try {
    if (function_exists('plugin_notify_fire')) {
        plugin_notify_fire('xnx_verify', 'new_pending', array(
            'title'    => lang('xnx_verify_notify_admin_subject'),
            'content'  => lang('xnx_verify_notify_admin_body', array('username' => $_apply_username)),
            'url'      => $_verify_admin_url,
            'throttle' => 300,
        ));
    } elseif (class_exists('AdminNotifyService')) {
        // 兼容旧核心：回退 AdminNotifyService::audit（ignore_enabled：旧键已删，audit 缺键默认关闭）
        AdminNotifyService::audit('xnx_verify', 'verify_apply',
            lang('xnx_verify_notify_admin_subject'),
            lang('xnx_verify_notify_admin_body', array('username' => $_apply_username)),
            $_verify_admin_url,
            array('ignore_enabled' => true));
    }
} catch (\Throwable $e) {
    error_log('[xnx_verify] submitApply notify exception: ' . $e->getMessage());
}
```

**审核通过/拒绝处**（approveApply / rejectApply，flush + 清节流键）：

```php
if (function_exists('plugin_notice_flush')) {
    plugin_notice_flush();
}
$_pending_after = db_count('xnx_verify_apply', array('status' => 0));
if ($_pending_after == 0) {
    if (function_exists('cache_delete')) {
        cache_delete('core_plugin_notify_throttle_xnx_verify_new_pending');
    }
}
```

**conf.json**：`version` 递增，`hooks_rank` 登记 `"plugin_notice_count.php": 10`。

## 7. 接入检查清单（四件套）

- [ ] `hook/plugin_notice_count.php`：头部 `<?php exit;`，写回 count（intval）与 url（admin_url + 守卫），体内无 exit/die/return，注释无 `// hook xxx` 格式
- [ ] `conf.json`：version 递增 + hooks_rank 登记
- [ ] 新待办产生处：`plugin_notify_fire()` 包 try/catch + function_exists 守卫，throttle≥300；旧核心回退 audit + `ignore_enabled`
- [ ] 审核通过/拒绝处：`plugin_notice_flush()`；待办清零清 `core_plugin_notify_throttle_{plugin}_{event}` 节流键
- [ ] 插件设置页无任何通知开关/邮箱控件（已有旧区块按第 8 节迁移）

## 8. 旧通知配置迁移规范（存量插件改造）

已有旧式通知配置（`admin_notify_enabled` / `admin_notify_emails` / `notify_admin_on_apply` 等自有开关+邮箱）的插件，接入时必须做**四处清理 + 幂等迁移**，否则会出现"新装站点通知被静默关闭"或"两处配置打架"：

### 8.1 setting.php / admin 路由保存分支

移除旧字段 `param()` 接收（`setting_set()` 是覆盖式保存，模板删了控件后接收不存在的字段会写空值）。

### 8.2 setting.htm（或 admin 模板）

旧"管理员通知"区块（开关+邮箱 textarea+SMTP 状态）整体替换为跳转提示卡片（纯 div+a，不含 form 控件）：

```php
<div class="card x-card">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="text-body-secondary small"><?php echo lang('your_plugin_admin_notify_moved_tip');?></div>
        <a class="btn btn-outline-primary btn-sm rounded-pill" href="<?php echo function_exists('admin_url') ? admin_url('plugin-notice') : './?plugin-notice.htm';?>">
            <?php echo lang('your_plugin_admin_notify_moved_btn');?>
        </a>
    </div>
</div>
```

语言键 `moved_tip`（"通知开关与提醒邮箱已迁移到统一通知设置，请前往「插件 → 通知设置」配置（站内消息 / 邮件 / 红点三通道）。"）/ `moved_btn`（"前往通知设置"）三语同步。

### 8.3 install.php

**不得再写入旧键**。否则新装站点插件 setting 里的 `admin_notify_enabled=0` 会被 `plugin_notify_fire()` 的兼容规则读为关闭态，拦截统一配置的默认全开——通知永久静默关闭（该坑在 xnx_verify v1.3.1 实测发现）。

### 8.4 upgrade.php 幂等迁移

```php
// v1.x.x: 管理员通知配置迁移到统一通知设置中心（后台-插件-通知设置）
// 幂等：只在统一配置对应项未设置时迁移（不覆盖站长已在统一设置页保存的新配置），迁移后删除旧键
if (isset($cfg['admin_notify_enabled']) || isset($cfg['admin_notify_emails'])) {
    $notify_cfg = setting_get('plugin_notify_config');
    if (!is_array($notify_cfg)) $notify_cfg = array();
    $item = isset($notify_cfg['your_plugin']) && is_array($notify_cfg['your_plugin']) ? $notify_cfg['your_plugin'] : array();
    $notify_changed = false;
    if (isset($cfg['admin_notify_enabled']) && !isset($item['system'])) {
        $item['system'] = intval($cfg['admin_notify_enabled']);
        $item['email']  = intval($cfg['admin_notify_enabled']);
        $notify_changed = true;
    }
    if (isset($cfg['admin_notify_emails']) && !empty($cfg['admin_notify_emails']) && !isset($item['email_to'])) {
        $item['email_to'] = $cfg['admin_notify_emails'];
        $notify_changed = true;
    }
    if ($notify_changed) {
        $notify_cfg['your_plugin'] = $item;
        setting_set('plugin_notify_config', $notify_cfg);
    }
    unset($cfg['admin_notify_enabled'], $cfg['admin_notify_emails']);
    $changed = true;  // 按插件既有持久化机制保存（如 $_xxx_cfg_changed）
}
```

关键点：

- **同时删除 upgrade.php 里的旧键补齐逻辑**（`if (!isset($cfg['admin_notify_enabled'])) $cfg[...]=0;`）——它与迁移逻辑互斥，保留会在迁移删键后下次升级又补回 `enabled=0`；
- 布尔型开关（如 xnx_friendlink 的 `notify_admin_on_apply`）：**显式为 0 才迁移 system/email=0**，值为 1 或未设置不迁移（走统一配置默认全开）；
- 旧键可能存在多处存储（kv 配置 + setting 表双写，如 xnx_report），迁移时从两处读旧值、迁移后从两处删除；
- `admin_notify_uids`（更早的历史键）保留不动，避免降级丢配置。

### 8.5 旧核心回退分支加 ignore_enabled

旧通知键删除后，`AdminNotifyService::audit()` 在缺键时默认 `$enabled=0` 静默关闭，而旧核心下插件已无通知配置入口——回退调用必须传 `array('ignore_enabled' => true)` 保持通知发出。

## 9. 注意事项

- **错误隔离**：单个插件的 fire() 异常或 hook 报错均被核心捕获（记 `plugin_error` 日志），不影响业务与其他插件；但业务代码仍应自行 try/catch，保证主流程不受通知拖累；
- **性能**：聚合缓存单键（`core_plugin_notice`，TTL 60s，后台缓存 TTL 配置页可调），每次后台页面加载最多一次 cache_get + 重建，红点成本与接入插件数量无关；
- **卸载自愈**：插件禁用/卸载后 hook 不再被收集，TTL 过期红点自然消失；核心卸载流程会调 `plugin_notify_config_delete()` 清理通知配置，插件无需在 uninstall.php 里额外处理（自定义 setting 键仍按原有规范自行清理）；
- **邮件同步延迟**：邮件由 SMTP 同步发送，高频事件务必设 `throttle`（建议 ≥300s），否则批量操作（如脚本灌入申请）会刷爆邮箱；
- **红点 URL 必须指向后台**：用 `admin_url()` 而非 `url()`（后者从前台上下文调用时不带 `admin/` 前缀，会跳到前台 404）；
- **旧插件兼容**：插件自身 setting 中已有的 `admin_notify_enabled=0` 会被继续尊重（推送通道整体关闭）；已有 `admin_notify_emails` 设置在统一配置未设邮箱时仍作兜底，存量行为不破坏。
