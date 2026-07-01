# 后台管理（admin/）

> 后台管理系统：路由分发、视图模板、菜单配置、辅助函数

## 目录结构概览

```
admin/
├── admin.func.php              # 后台辅助函数（令牌校验、Cookie 选项、Tab 切换）
├── index.inc.php               # 后台公共初始化（权限校验、路由分发）
├── index.php                   # 后台入口（加载函数、菜单、语言）
├── menu.conf.php               # 后台菜单与 Tab 配置
├── route/                      # 路由处理
│   ├── api.php                 # API 文档/调试/令牌/应用管理
│   ├── attach.php              # 附件管理（列表/删除/清理孤儿）
│   ├── audit.php               # 内容审核（帖子/资料审批）
│   ├── credits_rule.php        # 积分规则（全局/版块覆盖）
│   ├── forum.php               # 版块管理（CRUD + 权限）
│   ├── group.php               # 用户组管理（CRUD + 权限）
│   ├── health.php              # 站点健康检查
│   ├── index.php               # 后台首页/登录/登出/phpinfo
│   ├── log.php                 # 日志查询（积分/登录/操作/审核）
│   ├── other.php               # 缓存清理/缓存配置/图标预览
│   ├── plugin.php              # 插件管理（安装/卸载/启用/禁用）
│   ├── plugin_scanner.php      # 插件兼容性扫描
│   ├── security.php            # 安全设置（发帖限制/账号/验证码/黑白名单等）
│   ├── setting.php             # 站点设置（基本/SMTP/上传/导航/积分/永久链接等）
│   ├── theme.php               # 主题与主题色设置
│   ├── thread.php              # 帖子批量管理（删除/置顶/移动等）
│   ├── upgrade.php             # 系统一键升级
│   └── user.php                # 用户管理（CRUD + 积分调整）
└── view/
    ├── css/
    │   └── admin.css           # 后台样式表
    ├── htm/                    # 模板文件（按业务分组）
    └── img/
        ├── avatar.png          # 默认头像
        └── forum.png           # 默认版块图标
```

## 文件用途说明

### admin/ 顶层文件

#### admin.func.php
- **用途**：后台管理辅助函数集合，提供管理员令牌校验、Cookie 安全选项与 Tab 样式生成。
- **关键函数**：
  - `admin_token_check()` — 校验后台令牌有效性，超时（3600s）自动退出，半小时刷新令牌
  - `admin_cookie_options($expires)` — 生成后台 Cookie 安全选项（Secure/HttpOnly/SameSite）
  - `admin_token_set()` — 颁发并设置加密的后台令牌 Cookie（IP+UA+key 加密）
  - `admin_token_clean()` — 清除后台令牌 Cookie（退出登录）
  - `admin_tab_active($arr, $active)` — 生成 Bootstrap 风格的 Tab 导航 HTML

#### index.inc.php
- **用途**：后台公共初始化，校验管理员组权限与令牌，按 `$route` 分发到各路由文件。
- **关键函数**：
  - 通过 `param(0, 'index')` 读取路由名，`switch` 分发到 `route/*.php`
  - `admin_token_check()` 调用做令牌校验
  - 非 DEBUG 模式下 `gid != 1` 强制跳转前台登录
  - 生成 CSRF Token 注入 `$header['csrf_token']`

#### index.php
- **用途**：后台统一入口，定义常量、加载函数/菜单/语言、执行积分类型名称动态覆盖。
- **关键函数**：
  - 定义 `ADMIN_PATH`、`MESSAGE_HTM_PATH` 常量
  - 设置 `SKIP_ROUTE=TRUE` 后 `include '../index.php'` 启动主框架
  - 加载 `bbs_admin.php` 语言包并合并到 `$lang`
  - 根据 `credits_name/golds_name/rmbs_name` 配置动态覆盖积分语言项
  - 串联加载 `admin.func.php` → `menu.conf.php` → `index.inc.php`

#### menu.conf.php
- **用途**：返回后台左侧菜单与子 Tab 配置数组，定义各菜单项的 URL、文案、图标。
- **关键函数**：
  - 返回包含 14 个一级菜单的关联数组：`setting/forum/thread/audit/attach/user/security/log/health/other/notice/icon_preview/api/scanner/plugin/theme`
  - 每项含 `url/text/icon/tab` 字段，`tab` 为二级 Tab 列表

### admin/route/ 路由文件

#### route/api.php
- **用途**：API 文档展示、在线调试、令牌与应用管理。
- **关键路由**：
  - `GET /admin/api-doc.htm` — 展示 API 接口文档与错误码
  - `GET /admin/api-debug.htm` — 展示 API 调试页面与令牌列表
  - `POST /admin/api-debug.htm`（op=generate_token）— 为指定用户生成 API Token
  - `POST /admin/api-debug.htm`（op=revoke_token）— 撤销指定 Token
  - `POST /admin/api-debug.htm`（op=test_api）— 在线调用 API 测试
  - `POST /admin/api-token_delete-{id}.htm` — 删除 API Token
  - `GET /admin/api-settings.htm` — 展示 API 全局设置与应用列表
  - `POST /admin/api-app_create.htm` — 创建 API 应用
  - `POST /admin/api-app_update.htm` — 更新 API 应用
  - `POST /admin/api-app_delete.htm` — 删除 API 应用
  - `POST /admin/api-app_reset_secret.htm` — 重置应用密钥
  - `POST /admin/api-settings_save.htm` — 保存 API 全局设置（启用/限流/CORS/Token 有效期）

#### route/attach.php
- **用途**：附件管理页面，支持筛选、单个删除、孤儿清理、统计。
- **关键路由**：
  - `GET /admin/attach-list.htm` — 附件列表（支持类型/孤儿/关键词筛选与排序）
  - `POST /admin/attach-delete.htm` — 删除附件（孤儿直接删，非孤儿需 force）
  - `POST /admin/attach-batch_delete.htm` — 批量清理孤儿附件
  - `GET /admin/attach-stats.htm` — AJAX 获取附件统计数据

#### route/audit.php
- **用途**：内容审核中心，处理待审帖子/回复/用户资料的审批与驳回。
- **关键路由**：
  - `GET /admin/audit.htm` — 展示待审帖子/回复/资料列表与计数
  - `POST /admin/audit.htm`（audit_action=approve）— 通过单个审核
  - `POST /admin/audit.htm`（audit_action=reject）— 驳回单个审核（带原因）
  - `POST /admin/audit.htm`（audit_action=batch_approve）— 批量通过
  - `POST /admin/audit.htm`（audit_action=batch_reject）— 批量驳回
  - `POST /admin/audit.htm`（audit_action=profile_approve/profile_reject/profile_batch_approve）— 用户资料审核

#### route/credits_rule.php
- **用途**：积分规则管理，支持全局规则与按版块覆盖规则。
- **关键路由**：
  - `GET /admin/credits_rule-global.htm` — 展示全局积分规则编辑页（含版块覆盖 Tab）
  - `POST /admin/credits_rule-global.htm` — 保存全局积分规则（事件/增减/启用/日限）
  - `GET /admin/credits_rule-forum-{fid}.htm` — htmx 局部加载版块规则覆盖表单
  - `POST /admin/credits_rule-forum-{fid}.htm` — 保存版块覆盖规则（或回退全局）

#### route/forum.php
- **用途**：版块（含分区）管理，支持批量编辑、新增、更新、删除及图标上传。
- **关键路由**：
  - `GET /admin/forum-list.htm` — 版块列表页面
  - `POST /admin/forum-list.htm` — 批量新增/更新/删除版块
  - `GET /admin/forum-create.htm` — 新增版块表单
  - `POST /admin/forum-create.htm` — 提交新增版块（支持图标上传）
  - `GET /admin/forum-update-{fid}.htm` — 编辑版块表单（含权限矩阵）
  - `POST /admin/forum-update-{fid}.htm` — 保存版块及各用户组权限
  - `GET /admin/forum-getname-{uids}.htm` — 批量将 uid 转用户名（已废弃）
  - `POST /admin/forum-delete-{fid}.htm` — 删除版块（系统版块禁删）
  - 内部函数 `user_names_to_ids()` / `user_ids_to_names()` — 用户名与 UID 互转

#### route/group.php
- **用途**：用户组管理，支持批量编辑、更新及细粒度权限。
- **关键路由**：
  - `GET /admin/group-list.htm` — 用户组列表页面
  - `POST /admin/group-list.htm` — 批量新增/更新/删除用户组（系统组禁删）
  - `GET /admin/group-update-{gid}.htm` — 编辑用户组表单（含基础权限 + group_permission 表权限）
  - `POST /admin/group-update-{gid}.htm` — 保存用户组信息与扩展权限

#### route/health.php
- **用途**：站点健康检查，展示检测结果与重新检测。
- **关键路由**：
  - `GET /admin/health.htm` — 展示健康检查结果（支持 force=1 跳过缓存）
  - `POST /admin/health.htm` — 强制重新检测并记录日志

#### route/index.php
- **用途**：后台首页、登录、登出、phpinfo，含站点统计与 30 天趋势图。
- **关键路由**：
  - `GET /admin/index-login.htm` — 后台登录页
  - `POST /admin/index-login.htm` — 提交登录密码并颁发令牌
  - `GET /admin/index-logout.htm` — 退出后台（清除令牌）
  - `GET /admin/index-phpinfo.htm` — 输出 phpinfo（清理敏感变量）
  - `GET /admin/index.htm`（默认）— 后台首页（PHP 信息、数据统计、安全统计、30 天趋势图）
  - 内部函数 `get_last_version($stat)` — 每日上报站点版本信息

#### route/log.php
- **用途**：日志查询中心，支持积分日志、登录日志、操作日志、审核日志。
- **关键路由**：
  - `GET /admin/log-credits.htm` — 积分变动日志（支持 uid/用户名/类型/方向/日期/IP 筛选）
  - `GET /admin/log-login.htm` — 用户登录日志（支持成功/失败筛选）
  - `GET /admin/log-operation.htm` — 管理员操作日志（支持 action/target_type 筛选）
  - `GET /admin/log-audit.htm` — 审核操作日志（含关联帖子和资料详情）

#### route/other.php
- **用途**：缓存管理、缓存配置、图标预览。
- **关键路由**：
  - `GET /admin/other-cache.htm` — 缓存清理页面
  - `POST /admin/other-cache.htm` — 清理指定类型缓存（data/tmp/opcache）
  - `GET /admin/other-cache_setting.htm` — 缓存驱动配置页面
  - `POST /admin/other-cache_setting.htm`（act=test_connection）— 测试 Redis/Memcached 连接
  - `POST /admin/other-cache_setting.htm` — 保存缓存配置
  - `GET /admin/other-icon_preview.htm` — 图标预览页面

#### route/plugin.php
- **用途**：插件管理，支持列表、安装、卸载、启用、禁用、升级、设置。
- **关键路由**：
  - `GET /admin/plugin-local.htm` — 本地插件列表（支持类型/状态/关键词筛选与排序）
  - `GET /admin/plugin-install-{dir}.htm` — 安装插件（含安装前兼容性预扫描）
  - `GET /admin/plugin-unstall-{dir}.htm` — 卸载插件
  - `GET /admin/plugin-enable-{dir}.htm` — 启用插件
  - `GET /admin/plugin-disable-{dir}.htm` — 禁用插件
  - `GET /admin/plugin-upgrade-{dir}.htm` — 升级插件
  - `GET/POST /admin/plugin-setting-{dir}.htm` — 插件设置页（POST 时过滤 XSS）
  - 内部函数 `sanitize_plugin_setting()` / `plugin_check_dependency()` / `plugin_lock_start()` / `plugin_lock_end()` 等

#### route/plugin_scanner.php
- **用途**：插件兼容性扫描，扫描 PHP 8 / Alpine 等兼容性问题并支持导出。
- **关键路由**：
  - `GET /admin/plugin_scanner.htm` — 扫描器主页（规则摘要 + 插件列表）
  - `GET /admin/plugin_scanner-do.htm` — 执行扫描（支持单插件 dir 参数）返回 JSON
  - `GET /admin/plugin_scanner-plugins.htm` — 返回插件列表 JSON
  - `GET /admin/plugin_scanner-preinstall.htm` — 安装前预扫描
  - `GET /admin/plugin_scanner-detail-{dir}.htm` — 扫描详情（暂未实现）
  - `GET /admin/plugin_scanner-export.htm` — 导出扫描结果 CSV

#### route/security.php
- **用途**：安全管理中心，涵盖发帖限制、账号安全、内容权限、验证码、敏感词、黑白名单。
- **关键路由**：
  - `GET/POST /admin/security-post_limit.htm` — 发帖间隔/字数限制/新用户审核
  - `GET/POST /admin/security-account.htm` — IP 注册间隔/密码策略/邮件验证码限制
  - `GET/POST /admin/security-content.htm` — 编辑/删除时间限制/软删除
  - `GET/POST /admin/security-other.htm` — 头像/昵称/签名限制/iframe 白名单/Cookie 安全
  - `GET/POST /admin/security-captcha.htm` — 验证码场景与用户组配置
  - `GET/POST /admin/security-words.htm` — 敏感词库（add/delete/import/import_file/clear）
  - `GET/POST /admin/security-blacklist.htm` — IP 黑白名单、邮箱黑名单（add/remove/import）
  - `GET /admin/security-protection.htm` — 兼容旧 URL，重定向到 post_limit

#### route/setting.php
- **用途**：站点核心设置，包含基本、AI、SMTP、上传、导航、积分、永久链接、邮件模板、显示等。
- **关键路由**：
  - `GET/POST /admin/setting-base.htm` — 站点基本设置（站名/简介/运行级别/注册/语言等）
  - `GET/POST /admin/setting-ai.htm` — AI 服务商配置（name/url 列表）
  - `GET/POST /admin/setting-smtp.htm` — SMTP 服务器列表与邮件模板/日志概览
  - `POST /admin/setting-smtp_test.htm` — 发送测试邮件
  - `GET/POST /admin/setting-upload.htm` — 上传设置（大小限制/缩略图/允许类型/驱动）
  - `GET/POST /admin/setting-nav.htm` — 顶部/侧边/发现/手机导航与页脚设置
  - `GET/POST /admin/setting-credits.htm` — 积分类型与名称、日志保留天数
  - `GET /admin/setting-email_log-{page}.htm` — 邮件发送日志（支持状态筛选）
  - `GET/POST /admin/setting-permalink.htm` — 永久链接模式与伪静态规则（含生效检测）
  - `GET/POST /admin/setting-email_template.htm` — 邮件模板编辑
  - `GET/POST /admin/setting-display.htm` — 帖子状态标签与首页版块过滤

#### route/theme.php
- **用途**：主题模式与主题色设置。
- **关键路由**：
  - `GET/POST /admin/theme-list.htm` — 主题设置页（light/dark + 主题色选择）
  - `POST /admin/theme-default.htm` — 设置默认主题
  - `POST /admin/theme-brand.htm` — 设置默认主题色

#### route/thread.php
- **用途**：帖子批量管理，支持扫描、队列操作、批量删除/置顶/移动/加精等。
- **关键路由**：
  - `GET /admin/thread-list.htm` — 帖子管理主页（展示筛选表单与队列初始化）
  - `GET /admin/thread-scan.htm` — AJAX 扫描全表按条件筛选并推入队列
  - `GET /admin/thread-operation-{op}.htm` — 队列批量操作（旧接口）
  - `POST /admin/thread-batch.htm` — 批量操作（delete/close/open/top/digest/announcement/move）
  - `GET /admin/thread-found-{page}.htm` — 搜索结果分页展示

#### route/upgrade.php
- **用途**：系统一键升级，分步骤执行并返回 JSON。
- **关键路由**：
  - `GET /admin/upgrade.htm` — 升级页面（前置检查 + 步骤列表）
  - `POST /admin/upgrade-do.htm`（step=xxx）— 执行单步升级并返回 JSON
  - `GET /admin/upgrade-status.htm` — 返回当前版本/PHP/DB/插件数等状态 JSON

#### route/user.php
- **用途**：用户管理，支持列表搜索、新增、编辑、删除与积分调整。
- **关键路由**：
  - `GET /admin/user-list.htm` — 用户列表（支持 uid/用户名/昵称/邮箱/gid/IP 搜索）
  - `GET /admin/user-list-{srchtype}-{keyword}-{page}.htm` — 带筛选条件的用户列表
  - `GET /admin/user-create.htm` — 新增用户表单
  - `POST /admin/user-create.htm` — 提交新增用户
  - `GET /admin/user-update-{uid}.htm` — 编辑用户表单（含头像/积分调整）
  - `POST /admin/user-update-{uid}.htm` — 保存用户信息（含改密/改组/积分增减）
  - `POST /admin/user-delete.htm` — 删除用户（管理员禁删）

### admin/view/htm/ 模板（按业务分组）

#### 设置类
管理站点基本设置、AI、SMTP、上传、导航、积分、永久链接、邮件模板、显示等。
- 文件：setting_base.htm, setting_credits.htm, setting_display.htm, setting_email_log.htm, setting_email_template.htm, setting_footer.htm, setting_nav.htm, setting_permalink.htm, setting_smtp.htm, setting_upload.htm, setting_ai.htm

#### 版块类
版块列表、新增、编辑表单（含权限矩阵与图标上传）。
- 文件：forum_create.htm, forum_list.htm, forum_update.htm

#### 安全类
发帖限制、账号安全、黑白名单、验证码、内容权限、其他安全、防护、敏感词等安全相关表单。
- 文件：security_account.htm, security_audit.htm, security_blacklist.htm, security_captcha.htm, security_content.htm, security_other.htm, security_post_limit.htm, security_protection.htm, security_words.htm

#### 日志类
积分日志、审核日志、登录日志、操作日志列表与筛选。
- 文件：log_attach.htm, log_audit.htm, log_credits.htm, log_login.htm, log_operation.htm

#### 用户/用户组类
用户列表、新增、编辑，用户组列表与编辑（含权限矩阵）。
- 文件：user_create.htm, user_list.htm, user_update.htm, group_list.htm, group_update.htm

#### 帖子类
帖子批量管理列表与搜索结果展示。
- 文件：thread_list.htm, thread_list.inc.htm, thread_found.htm

#### 公告类
系统公告（站内消息）发送与列表。
- 文件：admin_notice_create.htm, admin_notice_list.htm, admin_notice_list.inc.htm

#### 插件类
本地插件列表与插件设置。
- 文件：plugin_list.htm, plugin_read.htm, plugin_scanner.htm

#### API 调试类
API 文档、在线调试、API 全局设置。
- 文件：api_debug.htm, api_doc.htm, api_settings.htm

#### 附件/审核
附件管理列表与内容审核中心页面。
- 文件：attach_manage.htm, audit.htm

#### 积分规则
全局积分规则与版块覆盖规则（htmx 局部加载）。
- 文件：credits_rule.htm, credits_rule_forum.htm

#### 主题
主题模式与主题色选择。
- 文件：theme.htm

#### 升级
系统一键升级页面。
- 文件：upgrade.htm

#### 健康检查
站点健康检查结果展示。
- 文件：health.htm

#### 图标预览
后台可用图标预览。
- 文件：icon_preview.htm

#### 缓存
缓存清理与缓存驱动配置。
- 文件：other_cache.htm, other_cache_setting.htm

#### 首页/登录/消息
后台首页、登录页、统一消息提示页。
- 文件：index.htm, index_login.htm, message.htm

#### 通用组件
后台公共头部、导航、侧边栏、页脚等布局组件。
- 文件：header.inc.htm, header_nav.inc.htm, footer.inc.htm, sidebar.inc.htm

### admin/view/css/ 与 admin/view/img/

- admin.css — 后台管理界面样式表，定义侧边栏宽度/配色变量、Tab、按钮、表单等 Bootstrap 增强样式
- avatar.png — 用户默认头像图片（用于用户列表/编辑页头像占位）
- forum.png — 版块默认图标（用于无自定义图标时的版块占位图）
