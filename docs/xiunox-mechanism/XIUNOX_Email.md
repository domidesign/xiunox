# XIUNOX_Email 邮件服务

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

XIUNOX_Email 是 Xiuno X 论坛系统的核心邮件服务模块，基于 **PHPMailer 7.x** 实现，支持多 SMTP 服务器配置、异步发送和邮件模板渲染。服务采用双通道投递机制——站内通知与邮件并行发送，确保管理员审核消息不遗漏。

SMTP 配置存储在 `conf/smtp.conf.php` 中，支持配置多个服务器节点，发送时随机选择有效节点实现负载均衡。异步机制通过 `register_shutdown_function()` + `fastcgi_finish_request()` 实现，在 HTTP 响应返回浏览器后再执行 SMTP 发送，不阻塞主流程的响应时间。

## 站长指南

### 配置入口

登录后台 → **设置 → 邮件设置**，填写 SMTP 账户信息。配置保存后自动写入 `conf/smtp.conf.php`。

### 配置项说明

| 字段 | 说明 | 示例 |
|------|------|------|
| email | 发件邮箱地址 | noreply@yourdomain.com |
| host | SMTP 服务器地址 | smtp.qq.com |
| port | SMTP 端口 | 465（SSL）/ 587（TLS） |
| user | SMTP 用户名 | 通常与邮箱相同 |
| pass | SMTP 授权码 | 邮箱服务商提供的授权码 |
| ssl | 加密方式 | 0=无 / 1=SSL / 2=TLS |

**多服务器配置**：如需配置多个 SMTP 节点，直接在后台添加多条记录，系统将随机选择有效节点发送。

### 使用场景

1. **管理员审核通知**：在插件设置页（如实名认证、广告审核）填写管理员通知邮箱，审核产生新待办时自动发送邮件
2. **用户注册验证码**：邮箱验证功能自动调用邮件服务
3. **密码找回**：重置密码链接通过邮件发送
4. **系统通知**：站点重要通知可通过邮件群发

### 注意事项

- 同一邮箱 60 秒内只能发送 1 次，每日上限 5 次
- 同一 IP 每小时发送上限 10 次
- 异步发送模式下，发送结果需查看后台「邮件日志」确认
- SMTP 密码请使用邮箱服务商提供的**授权码**，而非登录密码
- 邮件中所有链接自动转为绝对 URL，确保邮件客户端可正确跳转

## 开发者指南

### 核心服务类

**AdminNotifyService** (`lib/AdminNotifyService.php`)

管理员通知服务，封装站内通知 + 邮件双通道投递。

```php
// 发送审核通知给管理员
$result = AdminNotifyService::audit(
    'xnx_verify',       // 插件目录名
    'verify_apply',     // 审核类型
    '新的实名认证申请',   // 邮件主题
    '<p>用户 {username} 提交了实名认证</p>', // 通知正文（支持 HTML）
    'user/verify.htm',  // 跳转 URL
    array(
        'admin_emails' => ['admin@site.com', 'editor@site.com'],
        'skip_debounce' => true,
    )
);
// 返回：['ok'=>bool, 'sent_notify'=>int, 'sent_mail'=>int, 'reason'=>string]

// 审核完毕后清除防抖标记
AdminNotifyService::clearDebounce('xnx_verify', 'verify_apply');

// 给用户发送审核结果
AdminNotifyService::notifyUser($uid, '审核结果', '<p>您的认证已通过</p>', 'user.htm');
```

### 钩子点

| 钩子 | 触发时机 | 说明 |
|------|----------|------|
| `AdminNotifyService::audit()` | 插件产生待审核项 | 自动通知所有 gid=1,2 的管理员 |
| `AdminNotifyService::clearDebounce()` | 审核完成、待办清零 | 清除防抖标记，允许下次通知 |
| `xn_send_mail_async()` | 需要异步发送邮件 | 不阻塞主流程，发送结果查 email_log |

### 扩展方式

**1. 自定义管理员邮箱**

在插件设置中保存 `admin_notify_emails` 字段（逗号或换行分隔），`AdminNotifyService` 会自动读取：

```php
setting_set('your_plugin', array(
    'admin_notify_enabled' => 1,
    'admin_notify_emails' => 'admin@site.com,ops@site.com',
));
```

**2. 使用邮件模板**

```php
// 在 conf/email_templates.conf.php 中定义模板
$tpl = xn_email_template('user_create_code', array(
    'code' => '123456',
    'sitename' => $conf['sitename'],
));
// 返回：['subject'=>'【站点名】您的验证码是 123456', 'body'=>'...']
```

**3. 直接调用发送函数**

```php
// 同步发送（阻塞主流程）
xn_send_mail($smtp, '站点名称', 'user@example.com', '主题', '<p>HTML正文</p>', array('is_html'=>true));

// 异步发送（推荐，不阻塞）
xn_send_mail_async($smtp, '站点名称', 'user@example.com', '主题', '<p>HTML正文</p>');
```

### 代码示例

**完整的插件通知集成示例**：

```php
// 在插件的审核处理逻辑中集成
if ($need_audit) {
    AdminNotifyService::audit(
        $plugin_dir,
        'content_audit',
        '新的内容待审核',
        '<p>用户 <strong>' . $username . '</strong> 发布了新内容</p>',
        'admin/content/list.htm',
        array('skip_debounce' => true)
    );
}

// 审核完成时
if ($audit_done) {
    AdminNotifyService::clearDebounce($plugin_dir, 'content_audit');
    AdminNotifyService::notifyUser($uid, '审核结果通知', '<p>您的内容已审核通过</p>', 'user/content.htm');
}
```

## 常见问题

1. **邮件发送失败，日志显示"SMTP connect() failed"？**
   检查 SMTP 主机和端口是否正确，服务器是否开放对应端口。若使用 SSL 加密，确认服务器支持 SSL 连接。可尝试切换 `ssl` 配置值（1=SSL, 2=TLS）。

2. **管理员收不到邮件通知？**
   首先确认后台「邮件设置」已正确配置。其次检查插件设置中 `admin_notify_emails` 是否填写了有效邮箱。若使用默认配置，系统会读取管理员用户的 `email` 字段，请确保管理员账号已绑定邮箱。

3. **邮件发送频率限制是多少？**
   同一邮箱 60 秒内限发 1 次、每日限发 5 次，同一 IP 每小时限发 10 次。这些限制可通过 `SecurityConfigService` 在后台安全设置中调整。

4. **异步发送如何确认结果？**
   异步函数立即返回 TRUE，实际发送结果需在后台「邮件日志」页面查看（表：`bbs_email_log`），包含收件人、主题、SMTP 主机、成功/失败状态及错误信息。

5. **SMTP 配置文件在哪里？**
   配置文件路径为 `conf/smtp.conf.php`，格式为 PHP 数组。系统也会自动读取 `conf/smtp.conf.default.php` 作为模板参考，但实际生效的配置需写入 `smtp.conf.php`。
