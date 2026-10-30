# 出错了怎么办？怎么反馈 Bug？

> 给站长和普通用户的实用操作手册。**不讲原理，只讲怎么做。**
>
> 只需要会两件事：① 用宝塔面板看服务器文件；② 在浏览器按 F12 看控制台报错。

---

## 先学会两个基本功

### A. 用宝塔面板查看服务器上的日志

XIUNOX 把所有错误写到 `log/` 目录下，按月份分文件夹。

**操作步骤：**

1. 登录宝塔面板
2. 左侧菜单点 **文件**
3. 进入你的网站根目录（宝塔默认路径通常是 `/www/wwwroot/你的域名/`）
4. 找到 `log` 文件夹双击进入
5. 看到按月份命名的子文件夹（如 `202608` 是 2026 年 8 月的日志），双击当月文件夹
6. 里面是一堆 `.php` 结尾的文件，**常见的就是 `error.php`**
7. 点文件名右键 → **查看**（或双击），宝塔会用文本编辑器打开

**看到的日志行格式：**

```
<?php exit;?>  [ERROR]  2026-08-18 10:23:11  1.2.3.4  /index.htm  uid=1  RuntimeException: xxx in /path/file.php on line 123
```

> 看最后一段 `RuntimeException: ... on line 123` 就是真正的错误原因。

> 不要在浏览器里输 `https://你的域名/log/error.php` 访问！文件开头有 `<?php exit;?>` 保护，会立刻退出，看不到内容。**必须用宝塔的"查看"功能。**

### B. 用 F12 看浏览器报错和 URL 请求

浏览器自带开发者工具，按 `F12`（Mac 用 `Cmd + Option + I`）打开，最常用两个标签：

**Console（控制台）** — 看 JS 报错

1. 按 F12 打开
2. 切到 **Console**（控制台）标签
3. 红色的就是错误，左边是文件名行号，点开能看到调用栈
4. 左上角有个 🚫（清除）按钮，刷新页面再看新错误更干净

**Network（网络）** — 看 URL 请求成功还是失败

1. 按 F12 打开
2. 切到 **Network**（网络）标签
3. **先把列表清空**（左上角 🚫 按钮）
4. 复现你的操作（点按钮、提交表单等）
5. 列表里每行是一个请求，看 **Status** 列：
   - `200` = 正常
   - `500` = 服务器报错（PHP fatal 多半是这种）
   - `502` = PHP 崩了（fatal error）
   - `404` = URL 错了
   - `403` = 没权限
6. 点失败的请求 → 右边切到 **Response** 标签 → 看服务器返回的错误内容
7. **Response 里如果有完整错误信息，整段复制下来**，反馈 Bug 时贴上去

---

## 场景速查

| 你遇到的情况 | 怎么办 |
|---|---|
| 页面白屏 / 500 / 502 | [场景 1](#场景-1页面白屏500502) |
| 页面提示"插件 xxx 已自动禁用" | [场景 2](#场景-2提示插件已自动禁用) |
| 改了代码 / 装了插件没生效 | [场景 3](#场景-3改了代码没生效) |
| AJAX 报 "CSRF token verification failed" | [场景 4](#场景-4ajax-报-csrf-失败) |
| 想看后台各种操作日志 | [后台日志入口](#后台日志入口) |
| 准备好反馈 Bug 给官方 | [反馈 Bug](#反馈-bug) |

---

## 场景 1：页面白屏 / 500 / 502

**第一步：用 F12 看是哪种错误**

按 F12 → 切到 **Network** 标签 → 清空列表 → 刷新页面，看那条失败请求的 Status：

- `500` / `502` → PHP 报错或崩溃，看 [第二步](#第二步去宝塔看-errorphp)
- `404` → URL 路由不对，检查链接和伪静态配置
- `403` → 没权限，看用户组、版块权限

**第二步：去宝塔看 error.php**

按 [A. 用宝塔面板查看服务器上的日志](#a-用宝塔面板查看服务器上的日志) 打开 `log/当月文件夹/error.php`，翻到最底下，最后几行就是最新错误。看 `RuntimeException: xxx in /path/file.php on line 123` 这一段，**这就是错误的文件、行号和原因**。

**第三步：临时开调试模式看完整错误**

有时候日志里的原因太笼统，需要浏览器直接显示完整堆栈。

打开 [index.php](../index.php) 第 37 行，把 `0` 改成 `1`：

```php
!defined('DEBUG') AND define('DEBUG', 1);  // 临时改为 1
```

刷新页面，浏览器会直接显示完整错误（文件路径 + 行号 + 调用栈）。

> **修好后必须改回 `0`**，否则会向访客泄露服务器路径等敏感信息。

**第四步：还是 502？**

502 多半是 PHP fatal（致命错误），常见原因：服务器禁用了 `chmod` / `mkdir` 等函数。到宝塔 → 软件商店 → PHP → 禁用函数，看是不是禁用了上面这些。

---

## 场景 2：提示"插件已自动禁用"

某插件代码反复崩溃（1 小时内 ≥ 3 次），系统会自动禁用它避免拖垮整站。

**怎么办：**

1. **看是哪个插件崩了** — 去宝塔看 `log/当月/plugin_crash_error.php` 最后几行
2. **看具体崩溃原因** — 去 `log/当月/error.php` 最后几行
3. **三个选择**：
   - 自己能修 → 改完后台重新启用插件，并清 `tmp/` 缓存（见 [场景 3](#场景-3改了代码没生效)）
   - 第三方插件 → 把上面日志贴给插件作者
   - 暂时不用 → 在后台插件列表禁用或卸载
4. **验证是否真的禁用了**：后台显示"已禁用"但实际没禁用？去宝塔 → 数据库 → 找到 `bbs_plugin` 表，看 `enable` 字段是不是 0

---

## 场景 3：改了代码没生效

**按顺序排查：**

1. **清编译缓存** — 宝塔 → 文件 → 进入网站根目录，把 `tmp` 文件夹整个删掉（系统会自动重建）
2. **清 OPcache** — 后台访问 `?other-cache.htm` → 点清除 OPcache（PHP 8 下必须做，否则旧代码还在跑）
3. **浏览器硬刷新** — `Cmd/Ctrl + Shift + R`
4. **看 Network 是不是还在请求旧文件** — F12 → Network → 清空 → 刷新，看那个 JS/CSS 请求的 Response，内容是不是新的

**改了 JS/CSS 还不生效**：递增 [conf.php](../conf.php) 里的 `static_version`，强制浏览器重新下载静态资源。

**改了插件代码**：递增插件 `conf.json` 的 `version` 字段，否则后台不会显示"升级"按钮，浏览器也不会刷新插件资源。

---

## 场景 4：AJAX 报 CSRF 失败

JS 发起的 POST 请求必须带 CSRF token，否则 Network 里看到请求返回 "CSRF token verification failed"。

**JS 里这样拿 token**：

```javascript
var token = document.querySelector('meta[name="csrf-token"]').content;
```

**两种携带方式任选其一**：

```javascript
// 方式 1：放请求头
fetch(url, {
  headers: { 'X-CSRF-Token': token },
  // ...
});

// 方式 2：放 body
formData.append('csrf_token', token);
```

模板里渲染表单时直接用：

```php
<?php echo CsrfService::input(); ?>
```

会自动输出 `<input type="hidden" name="csrf_token" value="...">`。

---

## 后台日志入口

后台 → 顶部菜单 **日志**，或直接访问对应 URL：

| 想看什么 | URL |
|---|---|
| 积分变动 | `?log-credits.htm` |
| 用户登录 | `?log-login.htm` |
| 管理员操作 | `?log-operation.htm` |
| 内容审核 | `?log-audit.htm` |
| 附件上传 | `?log-attach.htm` |
| API 调用 | `?api-log.htm` |
| AI 调用 | `?ai-logs.htm` |
| 邮件发送 | `?setting-email_log.htm` |
| 站点健康检查 | `?health.htm` |
| 缓存管理 | `?other-cache.htm` |

> PHP 报错日志（白屏 / 500 / fatal）**没有后台 UI**，必须用宝塔看 `log/当月文件夹/` 目录下的文件，详见 [A. 用宝塔面板查看服务器上的日志](#a-用宝塔面板查看服务器上的日志)。

---

## 服务器日志文件清单

都在 `log/当月文件夹/`（如 `log/202608/`）下，按月分目录。用宝塔进来看：

| 文件名 | 看什么 |
|---|---|
| `error.php` | **最常看**：PHP 错误 / 异常 / 致命错误 |
| `plugin_crash_error.php` | 插件崩溃自动禁用记录 |
| `security.php` | 安全审计 |
| `cache_error.php` | 缓存损坏 |
| `template_security_error.php` | 模板里检测到危险函数（`eval` / `system` 等） |
| `plugin_syntax_error.php` | 插件语言包语法错误 |

每行格式：

```
<?php exit;?>  [级别]  时间  IP  URL  uid  错误内容
```

---

## 反馈 Bug

### 唯一官方渠道：GitHub Issue

- **GitHub 仓库**：https://github.com/domidesign/xiunox
- **Gitee 镜像**：https://gitee.com/poisonkid/xiunox
- **官网**：https://xiunox.org

> 没有 `/feedback` 入口，没有工单系统，没有官方社区论坛，**所有 Bug 反馈走 GitHub Issue**。

### 反馈前先做这些事

- [ ] 已升级到最新版（后台首页看版本号）
- [ ] 已清 `tmp/` 缓存 + OPcache
- [ ] 已禁用所有第三方插件，确认问题不是插件触发的
- [ ] 已临时开 `DEBUG=1` 复现，拿到完整错误
- [ ] 已在 GitHub Issue 列表搜过类似问题（避免重复提交）

### 提交 Issue 要填什么

GitHub Issue 模板（[.github/ISSUE_TEMPLATE/bug_report.yml](../.github/ISSUE_TEMPLATE/bug_report.yml)）必填：

| 字段 | 示例 |
|---|---|
| Bug 描述 | 后台插件列表页点"升级"白屏 |
| 复现步骤 | 1. 进后台 → 插件管理<br>2. 点 xnx_demo 的"升级"<br>3. 白屏 |
| 期望行为 | 跳转到升级流程 |
| 实际行为 | 白屏，Network 报 500 |
| PHP 版本 | 8.2.0（后台首页可见） |
| MySQL 版本 | 8.0.35（后台首页可见） |
| XiunoX 版本 | 1.0.8（后台首页可见） |
| 浏览器 | Chrome 130 |
| 相关日志 | `error.php` 最后几行的内容 |

### 拿错误信息的两个方法

**方法 1：临时开 DEBUG=1，浏览器直接复制完整错误**

按 [场景 1 第三步](#第三步临时开调试模式看完整错误) 把 DEBUG 改为 1，刷新页面，浏览器会显示完整错误（文件 + 行号 + 调用栈），整段截图或复制。

**方法 2：用宝塔看 error.php 最后几行**

按 [A. 用宝塔面板查看服务器上的日志](#a-用宝塔面板查看服务器上的日志) 打开 `log/当月/error.php`，翻到最底下，把最后几行复制出来。

**AJAX 请求失败**：F12 → Network → 找失败的请求 → Response 标签 → 复制完整响应体（在线升级页有"复制错误信息"按钮，直接点）。

### 提交前必做的脱敏

不要直接粘贴这些内容：

- 数据库密码、`cookie_pre`、`auth_key`
- 真实用户邮箱、IP、手机号
- 服务器路径里的用户名（`/home/zhangsan/...` 改成 `/home/user/...`）

`error.php` 里的 URL 可能含敏感参数，提交前请人工复查。

### 完整反馈示例

```markdown
### Bug 描述
后台插件列表页点击"升级"按钮后页面白屏。

### 复现步骤
1. 登录后台 → 插件管理
2. 选择 xnx_demo 插件，点击"升级"按钮
3. 页面白屏，浏览器控制台报 500

### 期望行为
点击升级后应跳转到升级流程页面。

### 实际行为
白屏，Network 显示 POST /admin/?plugin-upgrade-xnx_demo.htm 返回 500。

### 错误信息（DEBUG=1 复现）
RuntimeException: xxx in /home/user/site/admin/route/plugin.php on line 123
Stack trace:
#0 /home/user/site/admin/index.inc.php(46): include()
...

### 环境
- PHP 版本：8.2.0
- MySQL 版本：8.0.35
- XiunoX 版本：1.0.8
- 浏览器：Chrome 130

### 相关日志
log/202608/error.php 最后 50 行：
[2026-08-18 10:23:11] ERROR 1.2.3.4 /admin/?plugin-upgrade-xnx_demo.htm uid=1 Exception: ...
```
