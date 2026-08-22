# 插件通知聚合中心速查

> 本文件为通知聚合中心（Notify Hub）速查，详细说明见 [../plugindev/18-plugin-notify-hub.md](../../plugindev/18-plugin-notify-hub.md)

## 目录

- [1. 三通道一览](#1-三通道一览)
- [2. 红点 hook 最小代码](#2-红点-hook-最小代码)
- [3. plugin_notify_fire 最小代码](#3-plugin_notify_fire-最小代码)
- [4. 审核后刷新红点](#4-审核后刷新红点)
- [5. 三条禁区](#5-三条禁区)
- [6. 旧通知配置迁移（存量插件）](#6-旧通知配置迁移存量插件)
- [7. 接入检查清单](#7-接入检查清单)

---

## 1. 三通道一览

| 通道 | 触发方式 | 一次调用覆盖 |
|---|---|---|
| 站内消息 | 推送式（`plugin_notify_fire()`） | ✅ |
| 邮件提醒 | 推送式（同上，同一次调用） | ✅ |
| 后台红点 | 拉取式（`plugin_notice_count.php` hook） | 需额外提供 hook |

站长统一在 后台 → 插件 → 通知设置 配置（全局邮箱 + 每插件开关/专属邮箱）。**插件设置页禁止再提供通知开关/邮箱控件**。

## 2. 红点 hook 最小代码

`hook/plugin_notice_count.php`：

```php
<?php exit;
// 待审核 XX 计数（插件通知聚合红点数据源）
// 协议：写回 $data['count']（待处理数）与 $data['url']（后台待处理页地址）
$_my_pending = db_count('your_table', array('status' => 0));
$data['count'] = intval($_my_pending);
$data['url'] = function_exists('admin_url') ? admin_url('plugin-setting-your_plugin-pending') : '';
```

- 函数作用域 eval 执行：只用全局函数与 `$data`，**类常量不可用**（表名/状态值写字面值）
- URL 必须 `admin_url()`（`url()` 从前台调用不带 `admin/` 前缀 → 404）
- `conf.json`：`hooks_rank` 登记 `"plugin_notice_count.php": 10` + version 递增
- 双审核流插件：count 返回两表合计；fire 各流用不同事件名独立节流，同流多点用同事件名共享节流

## 3. plugin_notify_fire 最小代码

```php
// 新待办产生处（提交申请/下单/举报...）
try {
    if (function_exists('plugin_notify_fire')) {
        plugin_notify_fire('your_plugin', 'new_pending', array(
            'title'    => '有新的 XX 申请待审核',
            'content'  => '用户 ' . $username . ' 提交了新申请，请前往后台处理。',
            'url'      => function_exists('admin_url') ? admin_url('plugin-setting-your_plugin-pending') : '',
            'throttle' => 300,   // 同 plugin+event 节流秒数，高频事件必设（≥300）
        ));
    } elseif (class_exists('AdminNotifyService')) {
        // 旧核心回退：ignore_enabled 必传（旧键删除后 audit 缺键默认静默关闭）
        AdminNotifyService::audit('your_plugin', 'audit_type', $title, $content, $url,
            array('ignore_enabled' => true));
    }
} catch (\Throwable $e) {
    error_log('[your_plugin] notify exception: ' . $e->getMessage());
}
```

payload 常用键：`title`/`content`（必填其一）、`url`、`uid`/`uids`（缺省 gid 1,2 全体管理员）、`email_to`（收件覆盖，逗号分隔多个，与插件配置/全局默认**三层合并发送**）、`channels`（限定通道）、`badge_flush`（默认 true）、`throttle`。

多邮箱：全局/插件专属邮箱支持逗号、分号、空格、换行分隔，自动校验去重。

## 4. 审核后刷新红点

```php
// 审核通过/拒绝/删除完成后
if (function_exists('plugin_notice_flush')) {
    plugin_notice_flush();   // 红点实时消失，不等 60s TTL
}
// 待办清零时清节流键，下次新待办立即再推送
if (function_exists('cache_delete')) {
    cache_delete('core_plugin_notify_throttle_your_plugin_new_pending');  // 键名规则：{plugin}_{event}
}
```

## 5. 三条禁区

1. hook 体内禁 `exit`/`die`（eval 片段会终止整页请求）；
2. hook 体内禁 `return`（从宿主函数提前返回，截断其他插件收集）；
3. 注释禁 `// hook xxx` 格式（编译器正则误匹配 → 重复拼接崩溃），用 `// hook: xxx` 或纯文字。

## 6. 旧通知配置迁移（存量插件）

已有 `admin_notify_enabled`/`admin_notify_emails`/`notify_admin_on_apply` 等自有配置的插件，四处清理：

| 位置 | 操作 |
|---|---|
| setting.php 保存分支 | 删旧字段 `param()` 接收（覆盖式保存会写空值） |
| setting.htm | 旧区块替换为跳转提示卡片（纯 div+a → `admin_url('plugin-notice')`，语言键 moved_tip/moved_btn 三语） |
| install.php | **删旧键写入**（否则新装 `enabled=0` 拦截统一配置默认全开 → 通知永久静默） |
| upgrade.php | 幂等迁移：旧值 → `plugin_notify_config`（仅统一配置未设置时迁移，不覆盖站长新配置；迁移后 unset 旧键；**同步删除旧键补齐逻辑**——与迁移互斥会打架） |

布尔型开关（如 notify_admin_on_apply）：显式为 0 才迁移关闭，为 1 或未设置不迁移（默认全开）。旧键多处存储（kv+setting 双写）时两处都读/都删。`admin_notify_uids` 历史键保留不动。

## 7. 接入检查清单

- [ ] hook 头部 `<?php exit;`，count 为 intval，url 带 admin_url 守卫
- [ ] conf.json version 递增 + hooks_rank 登记
- [ ] fire 包 try/catch + function_exists 守卫，throttle≥300，旧核心回退带 ignore_enabled
- [ ] 审核动作 flush；待办清零清节流键
- [ ] 插件设置页无任何通知开关/邮箱控件
- [ ] php -l + 三语 key 一致 + 清 tmp/

> 完整规范（含 xnx_verify 真实范例、缓存建议、双审核流模式）见 [../plugindev/18-plugin-notify-hub.md](../../plugindev/18-plugin-notify-hub.md)
