# XIUNOX_Security 安全机制

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 论坛系统构建了多层次的安全防护体系，涵盖身份认证、数据传输、内容防护和权限控制四大核心维度。系统通过 `SecurityConfigService` 集中管理安全配置，配合 `CsrfService`、`LoginSecurityService`、`UserBanService`、`ContentModerationService` 等专用服务类，形成从前端输入到后端存储的完整安全链路。

核心安全机制包括：基于密码复杂度策略的账号保护、IP 维度的登录限速、Cookie 级 CSRF Token 验证、XSS 转义函数库、文件上传 MIME 校验以及基于用户组的细粒度权限控制。所有安全配置项均以 `security_` 前缀统一命名，存储在 `conf/conf.php` 中，支持后台实时修改与插件扩展。

## 站长指南

### 配置入口

后台路径：**系统设置 → 安全**（`admin-setting-security.htm`），分为账号安全、内容安全、上传安全、Cookie 安全四个模块。

### 配置项说明

| 配置键 | 默认值 | 说明 |
|--------|--------|------|
| `security_password_min_length` | 6 | 密码最小长度 |
| `security_password_complexity` | none | 密码复杂度：none/number/mixed/special |
| `security_password_max_retries` | 5 | 登录失败最大重试次数 |
| `security_lockout_duration` | 15 | 账号锁定时长（分钟） |
| `security_ip_register_interval` | 24 | 同 IP 注册间隔（小时） |
| `security_allowed_email_domains` | 空 | 允许注册的邮箱域名白名单 |
| `security_cookie_secure` | 0 | Cookie Secure 属性（0=自动检测，1=强制） |
| `security_cookie_httponly` | 1 | Cookie HttpOnly 属性 |
| `security_cookie_samesite` | Lax | Cookie SameSite 属性 |
| `security_new_user_audit_count` | 0 | 新用户前 N 帖需审核 |
| `security_upload_strict_mime` | 1 | 上传 MIME 严格校验模式 |

### 使用场景

**密码复杂度强制**：开启 `mixed` 要求密码必须包含大小写字母，适用于高安全要求的社区站点。

**IP 黑名单防控**：利用 `LoginSecurityService::checkIpBan()` 检测单 IP 在锁定窗口内的失败次数，防止攻击者枚举用户名。

**敏感词与内容审核**：通过 `ContentModerationService` 接入第三方审核服务（如阿里云内容安全），对发帖内容进行自动化审核。

**登录安全通知**：用户登录成功后展示上次登录 IP 和时间，帮助发现异常登录行为。

### 注意事项

- `security_password_complexity` 改动仅对新注册用户和修改密码生效，存量账号不受影响
- Cookie 的 `secure` 属性依赖 HTTPS 部署，若为 mixed 内容（HTTP）需谨慎开启
- 上传严格校验模式需 PHP 5.3+ 且启用 fileinfo 扩展，否则降级为兼容模式
- 管理员组（gid=1,2）不受封禁限制，但仍建议定期审计管理员账号

## 开发者指南

### 核心服务类

#### SecurityConfigService（`lib/security/SecurityConfigService.php`）

```php
// 获取全部安全配置
$config = SecurityConfigService::get_config();

// 获取单个配置值
$maxRetries = SecurityConfigService::get('security_password_max_retries', 5);

// 校验密码强度
$error = SecurityConfigService::checkPasswordPolicy($newPassword);
if ($error !== '') {
    // 密码不符合策略
}

// 批量保存配置（含类型校验）
SecurityConfigService::save_config([
    'security_password_complexity' => 'mixed',
    'security_lockout_duration' => 30,
]);
```

#### CsrfService（`lib/CsrfService.php`）

```php
// 生成/获取 CSRF Token（自动设置 Cookie）
$token = CsrfService::generate();

// 输出表单隐藏字段
echo CsrfService::input();

// 验证请求（在 POST 处理入口调用）
CsrfService::check(); // 失败则终止并返回错误

// 获取当前 Token（用于 AJAX 请求头）
$headerToken = CsrfService::getToken();

// graceful 模式验证：旧 Token 仍可通过，但会记录日志
CsrfService::check(true); // 参数为 true 时启用 graceful 模式
```

**CSRF Token 轮换机制**：`CsrfService::check()` 在每次验证成功后自动生成新 Token 并写入 Cookie，旧 Token 立即失效，有效防范 Token 劫持攻击。支持 graceful 模式（传入 `true` 参数），当旧 Token 仍可接受但会记录警告日志，适用于 AJAX 密集型场景中 Token 过期但用户仍在操作的情况。

#### LoginSecurityService（`lib/LoginSecurityService.php`）

```php
// 检查用户是否被锁定
LoginSecurityService::checkBan($uid);

// 记录登录尝试（含 IP 限流）
LoginSecurityService::recordAttempt($uid, $success, $ip, $userAgent);

// 检查 IP 维度限流（防止用户名枚举）
LoginSecurityService::checkIpBan($longip);

// 重置登录失败计数
LoginSecurityService::resetAttempts($uid);
```

#### UserBanService（`lib/UserBanService.php`）

```php
// 封禁用户（4 种类型：禁言/禁止访问/锁定）
UserBanService::ban($uid, UserBanService::BAN_TYPE_SILENCE, 86400*7, '广告垃圾', $adminUid);

// 解封用户
UserBanService::unban($uid, $adminUid, '申诉通过');

// 按场景检查封禁状态
$check = UserBanService::checkBanByScene($uid, 'post');
if (!$check['allowed']) {
    message(-1, $check['message']);
}

// 清空用户内容（保留账号）
UserBanService::clearContent($uid, $adminUid);
```

#### 插件代码安全扫描

系统在插件加载时通过 `_scan_dangerous_functions()` 方法执行 AST 静态扫描，识别并阻止高危函数（如 `eval`、`assert`、`system`、`exec`、`shell_exec`、`passthru`、`popen`、`proc_open`、`include`/`require` 动态路径等）的执行。扫描在插件安装/启用阶段自动触发，发现危险函数时会拒绝启用并返回详细的警告信息。

```php
// 手动触发扫描（管理员调试用）
$result = SecurityConfigService::scanPlugin('my_plugin');
if (!$result['safe']) {
    // $result['details'] 包含危险函数列表和具体位置
}
```

### 钩子点

| 服务类 | 钩子名 | 触发时机 |
|--------|--------|----------|
| UserBanService | `UserBanService.beforeBan` | 封禁前，可修改参数 |
| UserBanService | `UserBanService.afterBan` | 封禁后，用于日志/通知 |
| UserBanService | `UserBanService.beforeUnban` | 解封前 |
| UserBanService | `UserBanService.afterUnban` | 解封后 |
| UserBanService | `UserBanService.beforeClearContent` | 清空内容前 |
| UserBanService | `UserBanService.afterClearContent` | 清空内容后 |
| SecurityService | `security_verify_action` | 敏感操作二次验证 |
| ContentModerationService | `security_moderation_check` | 自定义内容审核 |

### 扩展方式

**自定义内容审核服务**（创建插件 `security-moderation/hook/security_moderation_check.php`）：

```php
<?php
// 接入阿里云内容安全 API
function security_moderation_check($type, $content, $scene) {
    $result = call_aliyun_moderation_api($content);
    if ($result['blocked']) return 'block';
    if ($result['review']) return 'review';
    return 'pass';
}
```

**扩展敏感操作验证**：

```php
<?php
// 新增邮箱验证码二次验证
function security_verify_action($uid, $action) {
    if ($action === 'bind_email') {
        return verify_email_code($uid);
    }
    return false;
}
```

### 代码示例

**在控制器中集成安全检查**：

```php
// 发帖入口安全检查
CsrfService::check();
UserBanService::checkBanByScene($uid, 'post');

// 内容审核
$auditResult = ContentModerationService::moderate('thread', $_POST['message'], 'create');
$auditStatus = ContentModerationService::result_to_audit_status($auditResult);

// 密码修改
$error = SecurityConfigService::checkPasswordPolicy($_POST['new_password']);
if ($error === '') {
    UserBanService::checkBanByScene($uid, 'password');
    // 执行密码修改
}
```

**XSS 防护函数使用**（`lib/EscapeService.php`）：

```php
// HTML 上下文转义
echo esc_html($userInput);

// 属性值转义
echo esc_attr($userInput);

// JavaScript 字符串转义
echo esc_js($userInput);
```

## 常见问题

1. **Q：如何防止暴力破解登录？**
   A：系统采用双重防护——账号维度（`security_password_max_retries`）和 IP 维度（`LoginSecurityService::checkIpBan()`），即使攻击者使用不存在的用户名也会被 IP 限流拦截。

2. **Q：CSRF Token 存储在 Cookie 而非 Session 的原因？**
   A：Session 有生命周期限制（默认 1 小时），用户长时间未操作后提交表单会因 Session GC 导致 Token 失效。Cookie 存储（7 天有效期）结合 `SameSite=Lax` 属性，既避免了跨站攻击，又提升了用户体验。

3. **Q：密码复杂度策略对现有用户生效吗？**
   A：不生效。复杂度校验仅在注册和修改密码时触发。若需强制存量用户升级密码，可通过批量重置密码或"下次登录强制修改"的方式实现。

4. **Q：上传文件的 MIME 校验如何工作？**
   A：`security_upload_strict_mime=1` 时使用 PHP fileinfo 扩展的 `finfo_file()` 获取真实 MIME；设为 0 时图片降级为 `getimagesize()` 校验，非图片仅验证扩展名。建议保持严格模式以防范 MIME 伪造攻击。

5. **Q：如何自定义内容审核规则？**
   A：实现 `security_moderation_check($type, $content, $scene)` 钩子函数即可接管审核逻辑。返回 `'pass'`（通过）、`'review'`（待审）或 `'block'`（驳回），系统会自动映射到对应的 `audit_status` 数据库字段。
