# 08 登录安全专题

> 关键源码：`lib/LoginSecurityService.php`、`lib/security/SecurityConfigService.php`、`route/user.php`、`admin/route/index.php`

---

## 本章覆盖

| 主题 | 面向角色 | 对应小节 |
|---|---|---|
| 双维度锁定机制（uid + IP）与解锁运维 | 运维 / 站长 | 1、2、3、4、5、6 |
| `LoginSecurityService` 公共 API 速查 | 插件开发者 | 7 |
| `SecurityConfigService` 配置读写 API | 插件开发者 | 8 |
| 插件自定义登录场景（OAuth/SSO/Token）的安全要求 | 插件开发者 | 9 |
| `user_login_log` 表写入时机与字段说明 | 插件开发者 / 运维 | 10 |
| 常见问题 FAQ | 全部 | 11 |

> 本章由原 `admin-account-lockout-guide.md` 运维指南迁移并扩展为插件开发向文档。前 6 节面向运维，后 5 节面向插件开发者。

---

## 1. 锁定机制总览

XiunoX 登录安全采用 **双维度锁定** 机制，前后台登录共用同一套规则。前台 `route/user.php` 与后台 `admin/route/index.php` 都调用 `LoginSecurityService` 的同一组方法，不存在「前台锁前台、后台锁后台」的隔离。

### 1.1 两个锁定维度

| 维度 | 存储位置 | 触发条件 | 影响 |
|---|---|---|---|
| **uid 维度** | `user` 表 `login_attempts` + `banned_until` 字段 | 某用户密码连续错误达阈值 | 该用户无法登录（前台 + 后台） |
| **IP 维度** | `user_login_log` 表（实时统计，无持久化字段） | 某 IP 在锁定窗口内失败达阈值 | 该 IP 无法登录任何账号（含不存在的用户名） |

> **为什么需要 IP 维度**：攻击者可用不存在的用户名无限枚举绕过 uid 维度限流（uid=0 没有user 表记录可累加）。IP 维度通过 `user_login_log` 表实时统计，堵住这条绕过路径。

### 1.2 关键配置项

后台路径：**管理后台 → 安全 → 账号安全**（`admin/?security-account.htm`）

| 后台字段 | 配置键（security_*） | 默认值 | 同步到（core 键） | 说明 |
|---|---|---|---|---|
| 密码错误重试次数 | `security_password_max_retries` | 5 | `login_max_attempts` | 连续失败多少次后锁定 |
| 锁定时间 | `security_lockout_duration` | 15（分钟） | `login_ban_duration`（×60 转秒） | 锁定持续多少分钟 |

> **单位换算**：`security_lockout_duration` 单位是**分钟**，`login_ban_duration` 单位是**秒**。保存时 `SecurityConfigService::save_config()` 自动 ×60 转换（见 `lib/security/SecurityConfigService.php:224-237`）。

> **两套键名的关系**：后台安全页用 `security_*` 前缀键（语义化、带默认值合并），核心登录路由 `LoginSecurityService` 读 `login_*` 键（历史命名）。`SecurityConfigService::save_config()` 在同一次 `file_replace_var` 调用中同步写入两套键，避免 OPcache 缓存旧文件导致第一次写入被覆盖。

### 1.3 锁定流程

```
用户登录失败
    ↓
LoginSecurityService::recordAttempt($uid, FALSE, $ip, $ua)
    ↓
┌─────────────────────────────────────────┐
│ 写 user_login_log（uid/ip/time/success=0/ua）│
└─────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────┐
│ uid 维度：                                │
│ user.login_attempts += 1                │
│ if(login_attempts >= max_attempts):     │
│   user.banned_until = time + ban_duration │
└─────────────────────────────────────────┘
    ↓
┌─────────────────────────────────────────┐
│ IP 维度（下次登录时 checkIpBan 检查）：     │
│ 统计 user_login_log 中该 IP 在            │
│ ban_duration 窗口内的失败次数             │
│ if(失败次数 >= max_attempts):            │
│   拒绝登录，返回 code=-1003              │
└─────────────────────────────────────────┘
```

### 1.4 自动解锁

- **uid 维度**：`banned_until` 时间戳到期后，下次登录时 `LoginSecurityService::checkBan()` 自动重置 `login_attempts=0, banned_until=0`（`LoginSecurityService.php:23-25`）
- **IP 维度**：锁定窗口（`ban_duration` 秒）过期后，旧的失败记录不再计入统计，自动恢复。锁定起点是第 `maxAttempts` 次失败的时间，之后即使再失败也不刷新锁定时长（`LoginSecurityService.php:102-104`）

---

## 2. 解锁方法

### 方法 1：等待自动解锁（推荐）

最简单的方式。默认配置下等待 15 分钟即可自动解锁。

### 方法 2：直接操作数据库（uid 维度解锁）

适用于管理员账号被锁、无法进入后台的情况。

#### 2.1 查询锁定状态

```sql
-- 查看管理员账号的锁定状态（uid=1 通常是超级管理员）
SELECT uid, username, login_attempts, banned_until,
       FROM_UNIXTIME(banned_until) AS unlock_time,
       CASE WHEN banned_until > UNIX_TIMESTAMP() THEN '已锁定' ELSE '正常' END AS status
FROM bbs_user
WHERE uid = 1;
```

#### 2.2 解锁单个用户

```sql
-- 重置失败次数和锁定时间
UPDATE bbs_user
SET login_attempts = 0, banned_until = 0
WHERE uid = 1;
```

#### 2.3 解锁所有被锁用户

```sql
-- 批量解锁所有被锁账号
UPDATE bbs_user
SET login_attempts = 0, banned_until = 0
WHERE banned_until > 0;
```

### 方法 3：清除 IP 维度锁定记录

适用于某个 IP 被锁（比如管理员自己的固定 IP）。

#### 3.1 查询 IP 失败记录

```sql
-- 查看 IP 失败次数（将 X.X.X.X 替换为实际 IP）
SELECT COUNT(*) AS fail_count
FROM bbs_user_login_log
WHERE ip = INET_ATON('X.X.X.X')
  AND success = 0
  AND time > UNIX_TIMESTAMP() - 900;
```

#### 3.2 清除该 IP 的失败记录

```sql
-- 删除该 IP 在锁定窗口内的失败记录
DELETE FROM bbs_user_login_log
WHERE ip = INET_ATON('X.X.X.X')
  AND success = 0
  AND time > UNIX_TIMESTAMP() - 900;
```

#### 3.3 清除所有失败记录（慎用）

```sql
-- 清空所有登录失败日志（影响所有用户的安全审计）
DELETE FROM bbs_user_login_log WHERE success = 0;
```

### 方法 4：通过 PHP 脚本解锁

如果无法直接操作数据库，可在服务器上创建临时 PHP 文件调用 `LoginSecurityService::resetAttempts()`。

在 `xiunobbs-master/` 目录下创建 `tmp_unlock.php`：

```php
<?php
// 临时解锁脚本，用完立即删除！
// 访问 https://你的域名/tmp_unlock.php?uid=1&token=你的随机密钥 执行

define('APP_PATH', __DIR__ . '/');
include APP_PATH . 'index.php';

// 只允许通过 CLI 或带 token 的 HTTP 访问
if(php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    if($token !== '你的随机密钥') {
        exit('Forbidden');
    }
}

$uid = isset($_GET['uid']) ? intval($_GET['uid']) : 1;

// 调用 LoginSecurityService 重置（清 uid 维度锁定）
LoginSecurityService::resetAttempts($uid);

echo "User $uid unlocked successfully.";
```

访问 `https://你的域名/tmp_unlock.php?uid=1&token=你的随机密钥` 执行，**用完立即删除该文件**。

> ⚠️ `resetAttempts()` 只清 uid 维度（`login_attempts`/`banned_until`），不清 IP 维度。若 IP 也被锁，需配合方法 3 清除 `user_login_log` 记录。

---

## 3. 修改锁定时间

### 方法 1：后台修改（推荐）

1. 登录管理后台
2. 进入 **安全 → 账号安全**（`admin/?security-account.htm`）
3. 修改：
   - **密码错误重试次数**：默认 5 次
   - **锁定时间**：默认 15 分钟
4. 点击保存

> 保存后 `SecurityConfigService::save_config()` 会自动同步到 `conf/conf.php` 的 `login_max_attempts` 和 `login_ban_duration`，无需手动清缓存（`save_config` 内部调用 `_clear_cache()` 并更新当前进程 `$conf`）。

### 方法 2：直接修改配置文件

编辑 `conf/conf.php`：

```php
'login_max_attempts' => 5,      // 最大尝试次数
'login_ban_duration' => 900,    // 锁定时长（秒），900=15分钟，1800=30分钟
```

修改后需清理 `tmp/` 缓存。

### 方法 3：修改默认配置

编辑 `conf/conf.default.php`（影响新安装的默认值）：

```php
'login_max_attempts' => 5,
'login_ban_duration' => 900,
```

---

## 4. 管理员被锁的特殊处理

### 4.1 问题场景

管理员账号被锁后，**无法登录后台**修改安全设置或解锁其他用户。

### 4.2 解决方案

| 场景 | 解决方案 |
|---|---|
| 管理员账号被锁 | 方法 2（数据库解锁 uid=1）或 方法 4（PHP 脚本） |
| 管理员 IP 被锁 | 方法 3（清除 IP 记录）或换网络/IP 访问 |
| 同时被锁（uid+IP） | 先清除 IP 记录，再数据库解锁 uid |
| 无数据库权限 | 联系服务器管理员，或通过 FTP 创建 PHP 解锁脚本 |

### 4.3 紧急关闭登录锁定

如需临时关闭锁定机制（紧急情况），编辑 `conf/conf.php`：

```php
// 设置极大值，实际关闭锁定
'login_max_attempts' => 999999,
'login_ban_duration' => 1,
```

> **警告**：仅用于紧急恢复访问，恢复后应立即改回合理值。

---

## 5. 相关数据表

### 5.1 user 表相关字段

| 字段 | 类型 | 说明 |
|---|---|---|
| `login_attempts` | int(11) | 连续失败次数，成功后重置为 0 |
| `banned_until` | int(11) | 锁定到期时间戳（UNIX），0 表示未锁定 |
| `last_login_ip` | int(11) | 最后登录 IP（ip2long） |
| `last_login_time` | int(11) | 最后登录时间戳 |

> ⚠️ `banned_until` 字段被两个系统复用：`LoginSecurityService` 写入登录失败锁定，`UserBanService::ban` 写入封禁到期。两者无法区分，必须靠 `ban_type` 字段判断来源（`LoginSecurityService.php:13-17`）。`checkBan()` 仅对 `ban_type=0` 的用户检查登录失败锁定；`ban_type>0`（禁言/禁止访问/锁定）的登录拦截由 `UserBanService::checkBanByScene('login')` 处理。

### 5.2 user_login_log 表

| 字段 | 类型 | 说明 |
|---|---|---|
| `uid` | int(11) | 用户 UID（不存在用户则为 0） |
| `ip` | int(11) | 登录 IP（ip2long） |
| `time` | int(11) | 登录时间戳 |
| `success` | tinyint(1) | 1=成功，0=失败 |
| `user_agent` | varchar(255) | 浏览器 UA |

> `user_login_log` 同时承担三个职责：IP 维度限流统计（`checkIpBan`）、后台登录验证码触发阈值（`admin/route/index.php:19-22`，同 IP 失败 ≥3 次显示验证码）、安全审计日志（`admin/route/log.php` 展示）。

---

## 6. 相关代码位置

| 文件 | 说明 |
|---|---|
| `lib/LoginSecurityService.php` | 锁定核心逻辑（checkBan/recordAttempt/checkIpBan/resetAttempts/recordIpAttempt） |
| `lib/security/SecurityConfigService.php` | 安全配置读写，同步 security_* → login_* |
| `admin/route/security.php` | 后台账号安全设置页（account action） |
| `admin/view/htm/security_account.htm` | 后台账号安全设置模板 |
| `admin/route/index.php` | 后台登录路由（调用 checkBan/checkIpBan） |
| `route/user.php` | 前台登录路由（调用 checkBan/checkIpBan） |
| `conf/conf.default.php` | 默认配置（login_max_attempts=5, login_ban_duration=900） |

---

## 7. LoginSecurityService 公共 API 速查

> 源码：`lib/LoginSecurityService.php`。全部为静态方法，无需实例化。

### 7.1 方法清单

| 方法签名 | 用途 | 写库 |
|---|---|---|
| `checkBan($uid)` | 检查 uid 维度是否被锁定，到期自动重置 | 到期时 UPDATE user |
| `checkIpBan($longip)` | 检查 IP 维度是否被锁定（实时统计） | 只读 |
| `recordAttempt($uid, $success, $ip, $ua)` | 记录登录尝试（成功+失败都调） | INSERT user_login_log + UPDATE user |
| `recordIpAttempt($longip, $success, $ua)` | 记录 IP 维度尝试（uid=0，用户名不存在时） | INSERT user_login_log（uid=0） |
| `resetAttempts($uid)` | 手动重置 uid 维度锁定 | UPDATE user |

### 7.2 checkBan($uid)

检查用户是否被登录失败锁定。**仅检查 `ban_type=0` 的用户**（`ban_type>0` 的封禁由 `UserBanService` 处理）。

```php
// 在登录流程中调用，锁定时 message() 终止流程
LoginSecurityService::checkBan($uid);
// 若 banned_until > time()：message(-1003, lang('login_banned', ...)) 终止
// 若 banned_until <= time() 且 > 0：自动重置 login_attempts=0, banned_until=0
// 若 banned_until = 0：直接返回
```

> 该方法内部调用 `message()` 终止流程，无需判断返回值。前置条件：`user` 表存在 `banned_until` 列（`db_check_column_exists` 守卫）。

### 7.3 checkIpBan($longip)

检查 IP 是否被锁定。基于 `user_login_log` 表实时统计该 IP 在 `ban_duration` 窗口内的失败次数，达到 `max_attempts` 则拒绝。

```php
// $longip 是 ip2long 转换后的整型 IP，通常用全局 $longip
LoginSecurityService::checkIpBan($longip);
// 若窗口内失败次数 >= max_attempts 且锁定未到期：message(-1003, ...) 终止
```

> 锁定起点是第 `maxAttempts` 次失败的时间（`end($logs)['time']`），之后即使再失败也不刷新锁定时长，避免攻击者通过持续尝试无限延长锁定。前置条件：`user_login_log` 表存在（`db_check_table_exists` 守卫）。

### 7.4 recordAttempt($uid, $success, $ip, $ua)

记录登录尝试。**成功和失败都要调用**——成功时重置计数，失败时累加并可能在达阈值时设置 `banned_until`。

```php
// 密码错误时
LoginSecurityService::recordAttempt($_user['uid'], FALSE, $longip, $_SERVER['HTTP_USER_AGENT']);
// 登录成功时
LoginSecurityService::recordAttempt($_user['uid'], TRUE, $longip, $_SERVER['HTTP_USER_AGENT']);
```

内部逻辑：
1. 写 `user_login_log`（uid/ip/time/success/ua，ua 截断 255 字符）
2. 若 `success=TRUE`：重置 `login_attempts=0, banned_until=0`，更新 `last_login_ip/last_login_time`
3. 若 `success=FALSE`：`login_attempts += 1`，达 `max_attempts` 时设 `banned_until = time + ban_duration`

> ⚠️ `recordAttempt` 已写 `user_login_log`（含 ip 字段），IP 维度限流可直接统计，**无需再调 `recordIpAttempt`**。仅当用户名不存在（无真实 uid）时才用 `recordIpAttempt`。

### 7.5 recordIpAttempt($longip, $success, $ua)

记录 IP 维度尝试，`uid` 固定为 0。用于用户名/邮箱不存在的场景，让 IP 维度限流能统计到这类失败。

```php
// 用户名不存在时
if(empty($_user)) {
    LoginSecurityService::recordIpAttempt($longip, FALSE, $_SERVER['HTTP_USER_AGENT']);
    message('email', lang('login_user_or_password_error'));
}
```

> 与 `recordAttempt` 区别：`recordAttempt` 针对真实用户，同时更新 `user` 表；`recordIpAttempt` 仅写 `user_login_log`，不依赖真实 uid。

### 7.6 resetAttempts($uid)

手动重置 uid 维度锁定。清零 `login_attempts` 和 `banned_until`，**不清 IP 维度记录**。

```php
// 解锁指定用户
LoginSecurityService::resetAttempts($uid);
```

---

## 8. SecurityConfigService API

> 源码：`lib/security/SecurityConfigService.php`。全部为静态方法。配置项统一使用 `security_` 前缀，存储在 `conf/conf.php`。

### 8.1 方法清单

| 方法签名 | 用途 |
|---|---|
| `get_config(): array` | 获取全部安全配置（与 `DEFAULT_CONFIG` 合并） |
| `get(string $key, $default = null)` | 获取单个配置项 |
| `save_config(array $data): bool` | 保存配置（含 security_* → login_* 同步） |
| `checkPasswordPolicy(string $password): string` | 校验密码是否符合策略 |

### 8.2 get_config(): array

获取全部安全配置，缺失键自动补齐 `DEFAULT_CONFIG` 默认值。直接从 `conf/conf.php` 文件读取，绕过 `$conf` 缓存（安全设置要求实时生效）。

```php
$cfg = SecurityConfigService::get_config();
$maxRetries = $cfg['security_password_max_retries'];  // 默认 5
$lockoutMin = $cfg['security_lockout_duration'];      // 默认 15（分钟）
```

### 8.3 get(string $key, $default = null)

获取单个配置项。优先取 `conf/conf.php` 中的值，其次取传入的 `$default`，最后取 `DEFAULT_CONFIG` 中的默认值。

```php
// 用 DEFAULT_CONFIG 默认值（5）
$maxRetries = SecurityConfigService::get('security_password_max_retries');

// 自定义默认值（10）
$maxRetries = SecurityConfigService::get('security_password_max_retries', 10);
```

### 8.4 save_config(array $data): bool

保存配置到 `conf/conf.php`。仅接受 `DEFAULT_CONFIG` 中已定义的键，做类型校验（数值型强制转 int，枚举型校验合法值）。

**关键：自动同步 `security_*` → `login_*`**（`SecurityConfigService.php:224-237`）：

| security_* 键 | 同步到 core 键 | 换算 |
|---|---|---|
| `security_password_max_retries` | `login_max_attempts` | 直接赋值 |
| `security_lockout_duration` | `login_ban_duration` | ×60（分钟→秒） |

```php
SecurityConfigService::save_config([
    'security_password_max_retries' => 3,
    'security_lockout_duration' => 30,   // 30 分钟
]);
// conf.php 中会同时写入：
// 'security_password_max_retries' => 3,
// 'security_lockout_duration' => 30,
// 'login_max_attempts' => 3,
// 'login_ban_duration' => 1800,   // 30*60
```

> 同步在同一次 `file_replace_var` 调用中完成，避免第二次 include 时 OPcache 缓存旧文件导致第一次写入被覆盖。保存后自动 `_clear_cache()` 并更新当前进程 `$conf`。

### 8.5 checkPasswordPolicy(string $password): string

校验密码是否符合策略。读取 `security_password_min_length` 和 `security_password_complexity` 配置，返回错误消息（空字符串表示通过）。

```php
$err = SecurityConfigService::checkPasswordPolicy($password);
if($err !== '') {
    message('password', $err);
}
```

复杂度档位（`security_password_complexity`）：
- `none`：仅校验最小长度
- `number`：必须包含数字
- `mixed`：必须包含大小写字母
- `special`：必须包含大小写字母、数字和特殊字符

### 8.6 DEFAULT_CONFIG 配置项清单

> `SecurityConfigService::DEFAULT_CONFIG` 常量定义了全部合法配置键及默认值。插件开发者读写配置时只能用这些键。

登录安全相关：

| 键 | 默认值 | 说明 |
|---|---|---|
| `security_password_max_retries` | 5 | 密码错误重试次数 |
| `security_lockout_duration` | 15 | 锁定时间（分钟） |
| `security_password_min_length` | 6 | 密码最小长度 |
| `security_password_complexity` | `none` | 密码复杂度：none/number/mixed/special |
| `security_ip_register_interval` | 24 | 同一 IP 注册间隔（小时） |
| `security_allowed_email_domains` | `''` | 允许注册的邮箱域名后缀，逗号分隔，空=不限制 |

完整清单（含发帖限制、上传限制、Cookie 安全等）见 `lib/security/SecurityConfigService.php:12-67`。

---

## 9. 插件自定义登录场景的安全要求

> 自定义登录插件（OAuth/SSO/Token）必须接入双维度锁定，否则会成为暴力破解的绕过入口。本节给出三类场景的强制检查清单与代码示例。

### 9.1 通用原则

任何自定义登录入口，无论密码校验逻辑如何，都必须在以下时机调用 `LoginSecurityService`：

| 时机 | 必调方法 | 说明 |
|---|---|---|
| 登录前（拿到 uid 后） | `checkBan($uid)` + `checkIpBan($longip)` | 拦截已锁定用户/IP |
| 密码/凭证校验失败 | `recordAttempt($uid, FALSE, $ip, $ua)` | 累加失败计数 + 写日志 |
| 用户名/邮箱不存在 | `recordIpAttempt($longip, FALSE, $ua)` | IP 维度统计（uid=0） |
| 登录成功 | `recordAttempt($uid, TRUE, $ip, $ua)` | 重置失败计数 + 写日志 |

### 9.2 OAuth 登录插件

OAuth 场景下密码校验由第三方完成，但**本地账号绑定后的 uid 仍需受锁定保护**——防止攻击者用 OAuth 接口枚举本地绑定关系。

**强制检查清单**：
- ✅ 拿到本地 uid 后调用 `checkBan($uid)`
- ✅ OAuth 回调失败（第三方拒绝/绑定不存在）时调用 `recordIpAttempt($longip, FALSE, $ua)`
- ✅ 绑定用户但本地拒绝登录时调用 `recordAttempt($uid, FALSE, $longip, $ua)`
- ✅ 登录成功调用 `recordAttempt($uid, TRUE, $longip, $ua)`

```php
// plugin/xnx_oauth/route/oauth.php
public function callback() {
    global $longip, $conf;
    $ua = $_SERVER['HTTP_USER_AGENT'];

    // 1. IP 维度限流（OAuth 回调也可能被刷）
    LoginSecurityService::checkIpBan($longip);

    // 2. 用第三方 code 换 access_token + 用户信息
    $oauth_user = $this->fetchOAuthUser($_GET['code']);
    if(!$oauth_user) {
        LoginSecurityService::recordIpAttempt($longip, FALSE, $ua);
        message(-1, 'OAuth 授权失败');
    }

    // 3. 查本地绑定
    $bind = db_find_one('xnx_oauth_bind', array('provider'=>$this->provider, 'openid'=>$oauth_user['openid']));
    if(empty($bind)) {
        // 未绑定，引导注册/绑定流程（不计入 uid 维度，计 IP 维度）
        LoginSecurityService::recordIpAttempt($longip, FALSE, $ua);
        message(-1, '请先绑定本地账号');
    }

    $uid = $bind['uid'];
    // 4. uid 维度锁定检查
    LoginSecurityService::checkBan($uid);

    // 5. 登录成功
    LoginSecurityService::recordAttempt($uid, TRUE, $longip, $ua);
    $_SESSION['uid'] = $uid;
    user_token_set($uid);
}
```

### 9.3 SSO 单点登录插件

SSO 场景下用户凭证由主站校验，子站接收主站下发的票据。**票据校验失败也必须记 IP 维度**，防止伪造票据枚举。

**强制检查清单**：
- ✅ 调用 `checkBan($uid)` + `checkIpBan($longip)`（双维度都要）
- ✅ 票据校验失败调用 `recordIpAttempt($longip, FALSE, $ua)`
- ✅ 本地账号被禁/锁定调用 `recordAttempt($uid, FALSE, $longip, $ua)`
- ✅ 登录成功调用 `recordAttempt($uid, TRUE, $longip, $ua)`

```php
// plugin/xnx_sso/route/sso.php
public function login() {
    global $longip;
    $ua = $_SERVER['HTTP_USER_AGENT'];

    // 1. 双维度锁定检查（SSO 入口同样受保护）
    LoginSecurityService::checkIpBan($longip);

    // 2. 验证主站下发的 ticket
    $ticket = param('ticket');
    $sso_user = $this->verifyTicket($ticket);
    if(!$sso_user) {
        LoginSecurityService::recordIpAttempt($longip, FALSE, $ua);
        message(-1, 'SSO 票据无效');
    }

    // 3. 查本地账号
    $_user = user_read_by_email($sso_user['email']);
    if(empty($_user)) {
        LoginSecurityService::recordIpAttempt($longip, FALSE, $ua);
        message(-1, '本地账号未同步');
    }

    // 4. uid 维度锁定检查
    LoginSecurityService::checkBan($_user['uid']);

    // 5. 登录成功
    LoginSecurityService::recordAttempt($_user['uid'], TRUE, $longip, $ua);
    $_SESSION['uid'] = $_user['uid'];
    user_token_set($_user['uid']);
}
```

### 9.4 API Token 登录插件

API Token 场景下用户用 token 而非密码登录。**token 校验失败必须记 uid 维度**（token 关联了 uid），否则可绕过 uid 限流暴力枚举 token。

**强制检查清单**：
- ✅ 拿到 uid 后调用 `checkBan($uid)`
- ✅ Token 校验失败调用 `recordAttempt($uid, FALSE, $longip, $ua)`
- ✅ 登录成功调用 `recordAttempt($uid, TRUE, $longip, $ua)`
- ✅ IP 维度限流由 `checkIpBan($longip)` 兜底（可选但推荐）

```php
// plugin/xnx_token/route/api.php
public function tokenLogin() {
    global $longip;
    $ua = $_SERVER['HTTP_USER_AGENT'];

    // 1. IP 维度限流
    LoginSecurityService::checkIpBan($longip);

    // 2. 解析 token 拿 uid
    $token = param('token');
    $uid = $this->parseToken($token);
    if(!$uid) {
        // token 格式无效，无法定位 uid，记 IP 维度
        LoginSecurityService::recordIpAttempt($longip, FALSE, $ua);
        message(-1, 'token 无效');
    }

    // 3. uid 维度锁定检查
    LoginSecurityService::checkBan($uid);

    // 4. 校验 token 有效性
    $valid = $this->verifyToken($uid, $token);
    if(!$valid) {
        LoginSecurityService::recordAttempt($uid, FALSE, $longip, $ua);
        message(-1, 'token 已失效');
    }

    // 5. 登录成功
    LoginSecurityService::recordAttempt($uid, TRUE, $longip, $ua);
    $_SESSION['uid'] = $uid;
}
```

### 9.5 插件自检清单

发布自定义登录插件前，逐项确认：

- [ ] 登录前调用了 `checkBan($uid)`（若有 uid）和 `checkIpBan($longip)`
- [ ] 凭证校验失败调用了 `recordAttempt($uid, FALSE, ...)` 或 `recordIpAttempt($longip, FALSE, ...)`
- [ ] 用户名/邮箱不存在时用 `recordIpAttempt`（uid=0），不漏掉 IP 维度统计
- [ ] 登录成功调用了 `recordAttempt($uid, TRUE, ...)` 重置计数
- [ ] 未直接 `db_insert('user_login_log', ...)`，统一走 `recordAttempt/recordIpAttempt`
- [ ] 锁定提示文案用 `lang('login_banned', array('seconds'=>$remaining))`，与核心一致

---

## 10. user_login_log 表写入时机

### 10.1 谁负责写入

**`LoginSecurityService` 统一负责写入**，路由层不直接 `db_insert`：

| 方法 | 写入时机 | uid | success |
|---|---|---|---|
| `recordAttempt($uid, $success, ...)` | 每次登录尝试（成功+失败） | 真实 uid | 实际结果 |
| `recordIpAttempt($longip, $success, ...)` | 用户名/邮箱不存在时 | 0 | 实际结果 |

> `route/user.php` 和 `admin/route/index.php` 都通过调用 `recordAttempt/recordIpAttempt` 间接写表，**没有直接 `db_insert('user_login_log', ...)`**。这样保证字段格式、ua 截断、uid 填充的一致性。

### 10.2 何时写入

**成功和失败都写**。`recordAttempt` 内部第一步就是 `db_create('user_login_log', ...)`（`LoginSecurityService.php:33-41`），无论 `$success` 真假都执行。

### 10.3 前台登录流程（route/user.php）

```
user_login_htm() 流程：
  1. checkIpBan($longip)                          ← IP 维度限流检查
  2. 若 email 不存在 → recordIpAttempt(FALSE)     ← 写日志（uid=0）
  3. checkBan($uid)                               ← uid 维度锁定检查
  4. 密码校验失败 → recordAttempt(uid, FALSE)     ← 写日志 + 累加 login_attempts
  5. 密码校验成功 → recordAttempt(uid, TRUE)      ← 写日志 + 重置 login_attempts
```

> 源码：`route/user.php:398-444`。步骤 4 的注释明确说明「recordAttempt 已写 user_login_log（含 ip 字段），IP 维度限流可直接统计，无需再调 recordIpAttempt」。

### 10.4 后台登录流程（admin/route/index.php）

```
admin_login() 流程：
  1. 统计 user_login_log 该 IP 失败次数 → 决定是否显示验证码（≥3 次显示）
  2. checkIpBan($longip) + checkBan($uid)         ← 双维度锁定检查
  3. 密码校验失败 → recordAttempt(uid, FALSE)     ← 写日志 + 累加
  4. 密码校验成功 → recordAttempt(uid, TRUE)      ← 写日志 + 重置
```

> 源码：`admin/route/index.php:19-103`。后台登录验证码按 IP 失败次数触发（阈值 3 次），与锁定阈值（默认 5 次）独立。

### 10.5 字段说明

| 字段 | 类型 | 写入方 | 说明 |
|---|---|---|---|
| `uid` | int(11) | `recordAttempt`/`recordIpAttempt` | 真实 uid 或 0（用户不存在） |
| `ip` | int(11) | `recordAttempt`/`recordIpAttempt` | `ip2long` 整型，由路由层传 `$longip` |
| `time` | int(11) | `recordAttempt`/`recordIpAttempt` | 全局 `$time`，登录发生时间戳 |
| `success` | tinyint(1) | `recordAttempt`/`recordIpAttempt` | 1=成功，0=失败 |
| `user_agent` | varchar(255) | `recordAttempt`/`recordIpAttempt` | `$_SERVER['HTTP_USER_AGENT']`，截断 255 字符 |

> `recordAttempt` 写入时 `uid` 取传入参数（真实用户），`recordIpAttempt` 写入时 `uid` 固定为 0。两者的 `ip` 字段都由路由层传入 `$longip`（已 `ip2long`），Service 层不再转换。

---

## 11. 常见问题

### Q1：为什么管理员后台登录页没有验证码？

后台登录验证码按 **IP 失败次数触发**：某 IP 在锁定窗口内失败 ≥ 3 次时才显示验证码（`admin/route/index.php:19-22`）。首次访问不显示，防止正常使用时打扰。阈值 3 与锁定阈值 5 独立。

### Q2：修改锁定时间后已锁定的用户会立即解锁吗？

不会。修改配置只影响**新的锁定**。已锁定用户的 `banned_until` 不会自动更新，需等待原锁定时间到期，或用方法 2 数据库手动解锁。

### Q3：IP 维度锁定为什么没有 banned_until 字段？

IP 维度锁定是**实时计算**的：每次登录时 `checkIpBan()` 查询 `user_login_log` 表中该 IP 在锁定窗口内的失败次数。窗口过期后旧记录自动不再计入，无需持久化字段。锁定起点是第 `maxAttempts` 次失败的时间（`LoginSecurityService.php:103`），不是首次失败时间。

### Q4：前台和后台的锁定是独立的吗？

**不独立**。前后台登录都调用 `LoginSecurityService::checkBan($uid)` 和 `checkIpBan($longip)`，共用 `user` 表的 `banned_until` 字段。管理员在前台被锁，后台也无法登录。

### Q5：如何永久关闭登录锁定？

不推荐。如必须关闭，设置 `conf/conf.php`：

```php
'login_max_attempts' => 999999,
'login_ban_duration' => 1,
```

这会让锁定几乎不触发，但会大幅降低安全性，容易遭受暴力破解。

### Q6：账号被锁后会退出前台吗？

**会**。系统在 `index.inc.php` 中检查 `banned_until`，账号被锁定时：
- 清除 `$_SESSION['uid']`
- 清除 `bbs_token` cookie
- 当前请求以游客身份处理

这样攻击者即使偷了前台 cookie，账号被锁后也无法持前台会话继续操作。管理员偶尔输错 1-2 次密码不受影响（仅达到锁定阈值如 5 次失败时才触发）。

### Q7：锁定后前台会话失效，解锁后能自动恢复吗？

不能。锁定导致前台 cookie 被清除，解锁后需要重新登录前台。这是设计预期，确保锁定期间攻击者无法利用残留会话。

### Q8：自定义登录插件必须接入锁定机制吗？

**必须**。任何自定义登录入口（OAuth/SSO/Token）若不调用 `LoginSecurityService`，会成为暴力破解的绕过入口。见第 9 节的强制检查清单。

### Q9：recordAttempt 和 recordIpAttempt 有什么区别？

| 方法 | 触发场景 | uid | 更新 user 表 | 写 user_login_log |
|---|---|---|---|---|
| `recordAttempt` | 用户存在，密码校验后 | 真实 uid | ✅（累加/重置 login_attempts） | ✅ |
| `recordIpAttempt` | 用户名/邮箱不存在 | 0 | ❌ | ✅ |

`recordAttempt` 已写 `user_login_log`（含 ip 字段），IP 维度限流可直接统计，**无需再调 `recordIpAttempt`**。仅当无真实 uid 时才用 `recordIpAttempt`。

### Q10：banned_until 字段为什么有歧义？

`banned_until` 被 `LoginSecurityService`（登录失败锁定）和 `UserBanService`（管理员封禁）复用，无法仅凭字段值判断来源。`LoginSecurityService::checkBan()` 通过 `ban_type` 字段区分：`ban_type=0` 才检查登录失败锁定，`ban_type>0` 由 `UserBanService::checkBanByScene('login')` 处理（`LoginSecurityService.php:13-17`）。

---

## 相关章节

- [04-api-cheatsheet.md](04-api-cheatsheet.md) —— 核心 API 速查（含 `user_*` 系列函数、`db_*` 系列）
- [06-ai-collaboration.md](06-ai-collaboration.md) —— AI 协作开发规范（含插件发布前自检清单、`PluginScanner` 扫描项）
