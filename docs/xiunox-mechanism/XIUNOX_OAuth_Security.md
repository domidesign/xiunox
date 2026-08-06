# XIUNOX OAuth 登录安全机制

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-06

## 概述

Xiuno X 的 OAuth 登录安全机制旨在解决第三方登录场景下的账号接管风险。当用户通过 QQ、微信、GitHub、Google 等第三方平台登录时，系统会自动检测账号的密码和邮箱完整性，强制要求用户补全缺失的安全信息，形成"授权登录 → 强制补全 → 完整账号"的安全闭环。

核心目标：防止纯 OAuth 无密码账号被会话劫持后，攻击者直接改绑邮箱、再通过"忘记密码"流程接管账号。

## 安全威胁模型

### 风险场景

| 场景 | 攻击路径 | 危害 |
|------|---------|------|
| 会话劫持 | 窃取 OAuth 登录后的 Session Cookie | 攻击者进入用户账号 |
| 邮箱改绑 | 劫持会话后修改账号邮箱 | 攻击者控制通信渠道 |
| 密码重置 | 通过新绑定的邮箱走"忘记密码"流程 | 攻击者设置新密码，永久接管账号 |

### 防护策略

```
风险链路：Session 劫持 → 改绑邮箱 → 忘记密码 → 账号接管
    ↓
防护措施：强制补全密码 → 改绑邮箱需密码验证（已具备）
                         ↑
                  前提：账号必须有密码
```

**核心思路**：强制每个 OAuth 用户必须设置本地密码，这样即使会话被劫持，攻击者在改绑邮箱时也需要验证密码，而无法直接走通接管链路。

## 文档结构

本文档包含以下核心章节：

| 章节 | 内容 | 面向读者 |
|------|------|---------|
| [安全威胁模型](#安全威胁模型) | 账号接管风险分析与防护策略 | 全体 |
| [三层防护体系](#三层防护体系) | 登录拦截、全局拦截、补全页验证 | 全体 |
| [核心 API](#核心-api) | OAuthService 静态方法与路由说明 | 开发者 |
| [补全页交互流程](#补全页交互流程) | 补全页 UI、防重提交、验证码流程 | 开发者 |
| [账号安全设置流程优化](#账号安全设置流程优化) | 邮箱/密码修改流程、旧邮箱通知、API 同步 | 全体 |
| [配置与扩展](#配置与扩展) | 密码策略、邮箱白名单、邮件配置 | 站长/开发者 |
| [常见问题](#常见问题) | 10 个 FAQ | 全体 |
| [相关文件](#相关文件) | 代码/配置/依赖文件索引 | 开发者 |
| [安全最佳实践](#安全最佳实践) | 站长配置建议、开发者注意事项 | 全体 |

## 三层防护体系

### 第一层：登录后即时拦截

OAuth 回调成功后，系统立即检查账号的密码和邮箱完整性：

```
用户发起 OAuth 登录
    ↓
第三方授权回调 → handleCallback()
    ↓
登录/绑定/合并成功
    ↓
needsProfileCompletion($uid) 检查
    ↓
┌─────────────────────────────────────┐
│ 缺密码或缺邮箱？                      │
│   是 → 设置 session 标记             │
│         跳转到 oauth-perfect 补全页   │
│   否 → 正常跳转个人中心               │
└─────────────────────────────────────┘
```

**关键代码位置**：
- [OAuthService::needsProfileCompletion()](file:///Users/hfbi/Desktop/2026/xiuno/xiunobbs-master/plugin/xnx_oauth/model/OAuthService.php#L847-L863) - 判断逻辑
- [route/oauth.php callback 分支](file:///Users/hfbi/Desktop/2026/xiuno/xiunobbs-master/plugin/xnx_oauth/route/oauth.php#L92-L102) - 拦截与跳转

### 第二层：全局路由强制拦截

即使补全流程被跳过，全局 Hook 也会强制拦截未完成的用户：

```php
// plugin/xnx_oauth/hook/index_inc_route_before.php
// 在每次请求分发前执行
if (class_exists('OAuthService', false)) {
    $_oauth_perfect_uid = intval($uid);
    if ($_oauth_perfect_uid > 0 && OAuthService::isOAuthUser($_oauth_perfect_uid)) {
        $_oauth_perfect_state = OAuthService::needsProfileCompletion($_oauth_perfect_uid);
        if (!$_oauth_perfect_state['complete']) {
            // 仅放行：补全页、OAuth 子路由、登录/注册/退出、验证码/附件/API
            if (!$_oauth_allow) {
                http_location(url('oauth-perfect'));
            }
        }
    }
}
```

**放行规则**（防止用户被锁死）：
- `oauth` 全部子路由（补全页自身）
- `user` 路由下的 `login`/`logout`/`create`（登录、退出、注册）
- `captcha`/`lang`/`attach`/`api`（资源和 API 路由）

### 第三层：补全页安全验证

补全页包含完整的安全验证机制：

#### 密码设置
- **复杂度校验**：通过 `SecurityConfigService::checkPasswordPolicy()` 校验密码强度
- **两次输入确认**：防止输入错误
- **防重提交**：`xnx_form_guard()` 防止表单重复提交

#### 邮箱绑定
- **邮箱格式校验**：`filter_var($email, FILTER_VALIDATE_EMAIL)`
- **域名白名单**：管理员配置允许的邮箱域名
- **唯一性检查**：邮箱未被其他用户使用
- **验证码验证**：通过邮件发送 6 位随机验证码
- **频率限制**：`xn_email_rate_check()` + `xn_email_rate_record()` 防止轰炸

## 核心 API

### OAuthService 静态方法

#### isOAuthUser($uid)

判断用户是否为 OAuth 绑定用户（任一第三方有绑定记录即算）。

```php
use OAuthService;

// 检查 uid=123 是否为 OAuth 用户
$isOAuth = OAuthService::isOAuthUser(123);
// 返回：bool
```

**使用场景**：
- 全局拦截器判断是否需要强制补全
- 个人中心显示"绑定账号"入口
- 管理员审计 OAuth 用户比例

#### needsProfileCompletion($uid)

检查用户是否需要补全账号信息（密码/邮箱）。

```php
// 获取补全状态
$state = OAuthService::needsProfileCompletion(123);
// 返回：array
// array(
//     'need_password' => bool,  // 是否需要设置密码
//     'need_email'    => bool,  // 是否需要绑定邮箱
//     'complete'      => bool,  // 是否已完成补全
// )
```

**状态说明**：

| need_password | need_email | complete | 说明 |
|---------------|-----------|----------|------|
| true | true | false | 新 OAuth 用户，需设置密码和邮箱 |
| true | false | false | 有邮箱但无密码（罕见） |
| false | true | false | 有密码但无邮箱（需绑定邮箱） |
| false | false | true | 已完成补全，正常用户 |

### 路由说明

| 路由 | 方法 | 说明 |
|------|------|------|
| `oauth-perfect` | GET | 渲染补全页 |
| `oauth-perfect` | POST | 保存密码和邮箱 |
| `oauth-perfect-sendcode` | POST | 发送邮箱验证码 |

### Session 标记

系统使用以下 Session 变量管理补全流程：

| Session 键 | 类型 | 说明 |
|------------|------|------|
| `oauth_perfect_required` | int | 需要补全的用户 UID |
| `email_change_code` | string | 当前邮箱验证码 |
| `email_change_target` | string | 验证码对应的邮箱地址 |

## 补全页交互流程

```
┌─────────────────────────────────────────────────────────────┐
│                    完善账号信息                              │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  [头像]                                                     │
│  [用户名]                                                   │
│  为保障账号安全，请设置登录密码并绑定邮箱                    │
│                                                             │
│  ────────────────────────────────────────────────            │
│                                                             │
│  新密码:    [_________________________]                     │
│  确认密码:  [_________________________]                     │
│                                                             │
│  新邮箱:    [_________________________]                     │
│  验证码:    [___________] [发送验证码] 60s倒计时              │
│                                                             │
│  [保存并继续]                                                │
│                                                             │
│  ────────────────────────────────────────────────            │
│                                                             │
│  [退出登录]                                                  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 表单防重提交

```javascript
// 防止快速双击提交
function xnx_form_guard(formId, btnId) {
    // 1. capture 阶段同步拦截（最快）
    form.addEventListener('submit', function(evt) {
        if(busy) { evt.preventDefault(); return; }
        busy = true;
        btn.disabled = true;
    }, true);
    
    // 2. 请求期间禁用按钮 + 显示 loading
    form.addEventListener('htmx:before:request', () => { ... });
    form.addEventListener('htmx:after:request', restore);
    form.addEventListener('htmx:response:error', restore);
    
    // 3. 30 秒超时兜底
    setTimeout(restore, 30000);
}
```

### 验证码发送流程

```
用户点击"发送验证码"
    ↓
前端校验邮箱格式
    ↓
POST oauth-perfect-sendcode
    ↓
┌─────────────────────────────────┐
│ 1. CsrfService::check()         │
│ 2. 检查 need_email 状态          │
│ 3. 邮箱格式验证                   │
│ 4. 域名白名单检查                 │
│ 5. 邮箱唯一性检查                 │
│ 6. 生成 6 位随机验证码            │
│ 7. 存入 Session                   │
│ 8. 检查频率限制                   │
│ 9. 发送邮件                       │
│ 10. 返回成功 + 倒计时秒数         │
└─────────────────────────────────┘
    ↓
前端显示 60 秒倒计时
    ↓
倒计时结束后可重新发送
```

## 配置与扩展

### 密码策略配置

密码复杂度通过 `SecurityConfigService` 统一管理：

```php
// 后台路径：系统设置 → 安全 → 账号安全

// 读取当前配置
$minLength = SecurityConfigService::get('security_password_min_length', 6);
$complexity = SecurityConfigService::get('security_password_complexity', 'none');

// 复杂度档位：
// - none: 仅最小长度
// - number: 必须包含数字
// - mixed: 必须包含大小写字母
// - special: 必须包含大小写字母、数字、特殊字符
```

### 邮箱域名白名单

```php
// 允许的邮箱域名列表
$allowedDomains = SecurityConfigService::get('security_allowed_email_domains', '');
// 格式：gmail.com,qq.com,163.com
// 空值表示不限制
```

### 邮件发送配置

OAuth 补全使用与系统相同的邮件发送机制：

- **SMTP 配置**：后台"邮件设置"
- **邮件模板**：`email_change_code`（验证码邮件）
- **频率限制**：每邮箱每小时最大发送次数

## 常见问题

### Q1：新老 OAuth 用户都会被强制补全吗？

**是的**。只要是 OAuth 绑定用户（`isOAuthUser($uid)` 返回 true），且账号缺密码或邮箱，下次登录都会被拦截。这是预期行为，确保所有 OAuth 用户都有完整的账号信息。

### Q2：普通注册用户会被强制补全吗？

**不会**。补全机制仅对 OAuth 用户生效。普通注册用户即使缺邮箱也不会被拦截，因为他们有本地密码作为第二身份验证。

### Q3：补全页能跳过吗？

**不能**。三层防护确保无法绕过：
1. OAuth 回调成功后直接跳转到补全页
2. 全局路由拦截所有非放行路由
3. 完成前 session 标记持续有效

### Q4：补全期间用户能退出登录吗？

**可以**。补全页底部提供"退出登录"链接，用户可在任何时候退出。

### Q5：已有密码的 OAuth 用户需要重新设置密码吗？

**不需要**。`needsProfileCompletion()` 只检查是否"缺"密码，已有密码的用户跳过密码设置步骤。

### Q6：补全完成后还能解绑所有 OAuth 登录方式吗？

**不能**。系统会检查是否有其他登录方式：
- 有密码 → 可解绑所有 OAuth
- 有其他 OAuth 绑定 → 可解绑当前 provider
- 无密码且仅有一个 OAuth → 不允许解绑

### Q7：邮箱验证码有效期多久？

验证码存储在 Session 中，有效期与 Session 相同（默认 1 小时）。验证成功后立即清除。

### Q8：能自定义补全页吗？

**可以**。补全页模板位于：
```
plugin/xnx_oauth/view/htm/oauth_perfect.htm
```

可通过模板继承或插件 Hook 修改显示内容，但核心验证逻辑不应修改。

### Q9：如何手动触发补全检查？

```php
// 在任意位置检查用户状态
$state = OAuthService::needsProfileCompletion($uid);
if (!$state['complete']) {
    // 跳转到补全页
    header('Location: ' . url('oauth-perfect'));
    exit;
}
```

### Q10：API 登录的 OAuth 用户如何补全？

API 场景下 OAuth 补全通过专用接口处理：
1. 获取当前补全状态：`GET /api/oauth-perfect`
2. 发送邮箱验证码：`POST /api/oauth-perfect-sendcode`
3. 提交补全信息：`POST /api/oauth-perfect`

## 相关文件

### 核心代码

| 文件 | 说明 |
|------|------|
| `plugin/xnx_oauth/model/OAuthService.php` | OAuth 服务类（含补全判断方法） |
| `plugin/xnx_oauth/route/oauth.php` | OAuth 路由处理（含补全分支） |
| `plugin/xnx_oauth/hook/index_inc_route_before.php` | 全局强制拦截 Hook |
| `plugin/xnx_oauth/view/htm/oauth_perfect.htm` | 补全页模板 |

### 配置与语言

| 文件 | 说明 |
|------|------|
| `plugin/xnx_oauth/conf.json` | 插件配置（版本号 1.1.1） |
| `plugin/xnx_oauth/hook/lang_zh_cn_bbs.php` | 简体中文语言包 |
| `plugin/xnx_oauth/hook/lang_zh_tw_bbs.php` | 繁体中文语言包 |
| `plugin/xnx_oauth/hook/lang_en_us_bbs.php` | 英文语言包 |

### 依赖服务

| 文件 | 说明 |
|------|------|
| `lib/security/SecurityConfigService.php` | 安全配置服务（密码策略） |
| `lib/CsrfService.php` | CSRF 令牌验证 |
| `xiunophp/xn_send_mail.func.php` | 邮件发送函数 |
| `conf/email_templates.conf.php` | 邮件模板配置 |

## 账号安全设置流程优化

### 修改邮箱流程

修改邮箱属于账号敏感操作，采用"当前密码 + 新邮箱验证码"双重验证机制：

```
用户提交修改邮箱请求
    ↓
┌─────────────────────────────────────┐
│ 步骤 1：密码验证（仅对有密码用户）    │
│   - 验证当前登录密码                  │
│   - 防止会话劫持后直接改绑邮箱         │
└─────────────────────────────────────┘
    ↓
┌─────────────────────────────────────┐
│ 步骤 2：验证码验证                    │
│   - 新邮箱必须通过验证码验证           │
│   - 格式校验 + 域名白名单检查          │
│   - 唯一性检查（邮箱未被他人使用）     │
│   - 频率限制（防止短信轰炸）           │
└─────────────────────────────────────┘
    ↓
┌─────────────────────────────────────┐
│ 步骤 3：执行修改                      │
│   - 更新 user 表 email 字段           │
│   - 发送通知邮件到旧邮箱               │
└─────────────────────────────────────┘
    ↓
跳转回安全设置页，显示新邮箱
```

**关键设计点**：

1. **密码验证条件判断**
```php
// 仅对有密码用户要求验证当前密码
$has_password = !empty($user['password']) || !empty($user['password_hash']);
if ($has_password) {
    // 验证当前密码
    $verified = user_login_verify($password_current, $user);
    if (!$verified) {
        message(-1, lang('password_incorrect'));
    }
}
// 无密码用户（纯 OAuth 绑定账号）由新邮箱验证码 + 旧邮箱通知兜底
```

2. **旧邮箱通知机制**
```php
// 修改成功后立即通知旧邮箱
$old_email = $user['email'];
$template = xn_email_template('email_change_notify', array(
    'sitename' => $conf['sitename'],
    'username' => esc_html($user['username']),
    'old_email' => $old_email,
    'new_email' => $email_new,
    'time' => date('Y-m-d H:i:s', $time),
));
xn_send_mail($smtp, $conf['sitename'], $old_email, $template['subject'], $template['body']);
```

**旧邮箱通知的安全意义**：
- 防撞库后静默改绑：如果攻击者通过撞库获取密码，修改邮箱后旧邮箱会收到通知
- 用户可第一时间察觉：即使账号被劫持，原主人能通过通知邮件发现异常
- 成本极低：仅增加一封邮件，无额外安全成本

### 修改密码流程

修改密码采用"旧密码验证 + 新密码强度校验 + 通知邮件"机制：

```
用户提交修改密码请求
    ↓
┌─────────────────────────────────────┐
│ 步骤 1：旧密码验证                    │
│   - 验证当前密码是否正确               │
│   - 防止会话被劫持后直接修改密码       │
└─────────────────────────────────────┘
    ↓
┌─────────────────────────────────────┐
│ 步骤 2：新密码校验                    │
│   - 两次输入一致性检查                 │
│   - 密码强度策略校验                   │
│     · 最小长度（默认 6 位）            │
│     - 复杂度要求（数字/大小写/特殊字符）│
└─────────────────────────────────────┘
    ↓
┌─────────────────────────────────────┐
│ 步骤 3：执行修改                      │
│   - 更新密码哈希                      │
│   - 清除 Session Token（强制重新登录） │
│   - 发送通知邮件到当前邮箱             │
└─────────────────────────────────────┘
    ↓
跳转到登录页，要求用户重新登录
```

**关键设计点**：

1. **强制重新登录**
```php
// 修改成功后清除 token，强制用户重新登录
user_token_clear($uid);
message(0, lang('password_modify_successfully'), array('redirect_url' => url('user-login')));
```

2. **安全通知邮件**
```php
// 通知邮件使用 password_change_notify 模板
$template = xn_email_template('password_change_notify', array(
    'sitename' => $conf['sitename'],
    'username' => esc_html($user['username']),
    'time' => date('Y-m-d H:i:s', $time),
));
```

**强制重新登录的安全意义**：
- 使所有现有会话失效，防止攻击者利用旧会话
- 用户需用新密码重新登录，确认修改是本人操作
- 避免"改密后攻击者仍在操作"的风险

### 表单防重复提交

账号安全设置页面采用统一的防重复提交机制 `xnx_form_guard()`：

```javascript
function xnx_form_guard(formId, btnId) {
    var form = document.getElementById(formId);
    var btn = document.getElementById(btnId);
    var busy = false;
    var originalText = btn.textContent;

    // 1. submit capture 阶段同步拦截（最快响应）
    form.addEventListener('submit', function(evt) {
        if (busy) {
            evt.preventDefault();
            evt.stopPropagation();
            return;
        }
        busy = true;
        btn.disabled = true;
        btn.textContent = btn.getAttribute('data-loading-text') || '提交中...';
    }, true);

    // 2. 请求期间保持禁用状态
    form.addEventListener('htmx:before:request', function() {
        // 设置 30 秒超时兜底
        setTimeout(restore, 30000);
    });

    // 3. 请求完成后恢复（无论成功/失败）
    form.addEventListener('htmx:after:request', restore);

    // 4. 网络错误立即恢复
    form.addEventListener('htmx:response:error', function() {
        restore();
        XN.toast('网络请求失败，请重试', 'danger');
    });

    function restore() {
        busy = false;
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
```

**防重机制的四层保障**：

| 层级 | 时机 | 作用 |
|------|------|------|
| 1 | submit capture 阶段 | 同步拦截快速双击，防止重复提交 |
| 2 | htmx:before:request | 请求期间禁用按钮，显示 loading 状态 |
| 3 | htmx:after:request | 请求完成后立即恢复按钮状态 |
| 4 | htmx:response:error / 30s 超时 | 网络错误或超时时兜底恢复 |

### OAuth 无密码用户的特殊处理

纯 OAuth 绑定账号（无密码）在修改邮箱时采用不同的安全策略：

```php
// 判断用户是否有密码
$has_password = !empty($user['password']) || !empty($user['password_hash']);

if ($has_password) {
    // 有密码：当前密码 + 新邮箱验证码
    // 双重验证，防止会话劫持
} else {
    // 无密码：新邮箱验证码 + 旧邮箱通知
    // 验证码验证新邮箱归属
    // 旧邮箱通知用户修改行为
}
```

**无密码用户的安全兜底**：
- **验证码验证**：确保新邮箱归当前用户所有
- **旧邮箱通知**：修改成功后立即通知旧邮箱，用户可第一时间察觉异常
- **无密码账号补全**：登录时强制要求设置密码（见第三章）

### 邮件模板说明

系统使用以下邮件模板保障账号安全：

| 模板键 | 用途 | 触发时机 |
|--------|------|---------|
| `email_change_code` | 邮箱验证码 | 发送新邮箱验证码时 |
| `email_change_notify` | 改邮箱通知 | 修改邮箱成功后，发送到旧邮箱 |
| `password_change_notify` | 改密通知 | 修改密码成功后，发送到当前邮箱 |

**模板变量**：

```php
// email_change_code
array('code' => $code, 'sitename' => $sitename)

// email_change_notify
array(
    'sitename' => $sitename,
    'username' => $username,      // 已 esc_html 转义
    'old_email' => $old_email,
    'new_email' => $new_email,
    'time' => $modify_time
)

// password_change_notify
array(
    'sitename' => $sitename,
    'username' => $username,      // 已 esc_html 转义
    'time' => $modify_time
)
```

**安全提示**：邮件模板中的 `username` 变量必须使用 `esc_html()` 转义，防止 HTML 注入攻击。

### API 端同步

Web 端和 API 端的安全逻辑保持一致：

| 端点 | 方法 | 说明 |
|------|------|------|
| `my-security.html` | GET/POST | Web 端安全设置页 |
| `api/v1/my.php` | PUT | API 端修改邮箱/密码 |

API 端同样执行：
- 当前密码验证（有密码用户）
- 邮箱验证码验证
- 密码强度校验
- 旧邮箱/改密通知邮件

## 安全最佳实践

### 站长配置建议

1. **设置合理的密码复杂度**：推荐 `mixed` 或 `special` 级别
2. **配置邮箱域名白名单**：限制为企业域名或常用邮箱服务商
3. **启用邮件频率限制**：防止验证码被滥用
4. **定期审计 OAuth 用户**：检查是否有异常绑定

### 插件开发者注意事项

1. **不要绕过全局拦截**：自定义 OAuth 插件应复用 `OAuthService::needsProfileCompletion()`
2. **保持 Session 标记同步**：自定义补全流程时需维护 `oauth_perfect_required`
3. **使用标准验证函数**：密码校验走 `SecurityConfigService::checkPasswordPolicy()`
4. **记录补全日志**：补全成功/失败记录到安全日志

## 版本历史

| 版本 | 日期 | 变更内容 |
|------|------|---------|
| 1.1.1 | 2026-08-06 | 新增 OAuth 登录后强制补全机制（三层防护） |
| 1.1.0 | 2026-08-05 | OAuth 2.0 统一登录基础功能 |

## 相关章节

- [XIUNOX_Security.md](XIUNOX_Security.md) — 系统安全机制总览
- [08-login-security.md](../plugindev/08-login-security.md) — 登录安全专题（含自定义登录插件安全要求）
- [XIUNOX_Plugin.md](XIUNOX_Plugin.md) — 插件开发机制
