# 安装程序（install/）

> 安装向导：环境检测、数据库初始化、安装页面模板

## 目录结构概览

```
install/
├── index.php              # 安装入口与流程控制器
├── install.func.php       # 安装辅助函数
├── install.sql            # 数据库初始化 SQL（建表+初始数据）
├── install.lock           # 安装锁文件（安装完成后生成，防止重复安装）
└── view/
    └── htm/
        ├── header.inc.htm   # 通用页头
        ├── footer.inc.htm   # 通用页脚
        ├── message.htm      # 通用消息提示页
        ├── index.htm        # 语言选择页
        ├── license.htm      # 协议确认页
        ├── env.htm          # 环境检测页
        ├── db.htm           # 数据库配置页
        └── success.htm      # 安装成功页
```

## 文件用途说明

### install/index.php
- **用途**：安装向导入口与流程控制器，按 `action` 参数分派到语言选择、协议、环境检测、数据库配置与建表等各步骤。
- **关键流程**：
  - 加载框架 `xiunophp.php`、模型函数与语言包
  - 安装锁检测：若 `conf/conf.php` 或 `install.lock` 已存在则拒绝重装
  - `action=license` — 展示协议页
  - `action=env` — 调用 `get_env()` 检测环境
  - `action=db` (GET) — 展示数据库配置表单
  - `action=db` (POST) — 校验数据库连接，必要时自动建库，调用 `install_sql_file()` 执行建表 SQL，写入 `conf.php`、初始化管理员账号、生成 API 应用、写入缓存配置与帖子状态标签、写入 `install.lock`
  - `action=success` — 安装成功页（放行至锁检测之前）

### install/install.func.php
- **用途**：安装过程的辅助函数，提供环境检测与 SQL 批量执行能力。
- **关键函数**：
  - `get_env()` — 采集运行环境信息（PHP 版本、pdo_mysql、GD、HTTPS、可写目录）并填充到 `$env`、`$write` 数组供模板渲染
  - `install_sql_file()` — 读取 SQL 文件按 `;\n` 拆分逐条执行，遇 `FULLTEXT_TOLERANT` 标记的语句失败时跳过不中断（用于可选的全文索引）

### install/install.sql
- **用途**：数据库初始化脚本，包含全部业务表结构与初始数据（默认管理员、用户组、版块、积分规则、权限等）。

### install/view/htm/ 模板

按用途分组汇总：

- **通用组件**
  - `header.inc.htm` — 页面头部（DOCTYPE、CSS 引入、步骤导航）
  - `footer.inc.htm` — 页面尾部（版权信息、Bootstrap JS）
  - `message.htm` — 通用消息提示页（成功/错误提示跳转）

- **语言选择页**
  - `index.htm` — 第 1 步，选择安装语言并提交

- **协议确认页**
  - `license.htm` — 第 2 步，展示 LICENSE 内容并要求滚动到底确认

- **环境检测页**
  - `env.htm` — 第 3 步，表格展示 PHP 版本、扩展、目录可写性检测结果

- **数据库配置页**
  - `db.htm` — 第 4 步，填写数据库连接信息与管理员账号表单

- **安装成功页**
  - `success.htm` — 展示成功标识与安全提示（删除 install/ 目录、修改管理员密码、配置 HTTPS 等），提供访问站点入口

### install.sql 表结构

按业务分组列出：

- **用户与权限**
  - `bbs_user` — 用户主表（账号、密码哈希、统计、AI 配置等）
  - `bbs_group` — 用户组（管理员/版主/普通等，含权限位与图标颜色）
  - `bbs_group_permission` — 用户组细粒度权限键值对
  - `bbs_user_login_log` — 用户登录日志（成功/失败、IP、UA）
  - `bbs_nickname_change_log` — 昵称修改日志
  - `bbs_signature_change_log` — 签名修改日志
  - `bbs_user_profile_audit` — 个人资料审核记录（头像/签名变更待审）

- **版块**
  - `bbs_forum` — 版块/分区主表（名称、排序、统计、公告、SEO）
  - `bbs_forum_access` — 版块按用户组的访问权限规则
  - `bbs_forum_follow` — 用户关注版块关系

- **主题与帖子**
  - `bbs_thread` — 主题主表（标题、统计、置顶/精华/审核状态）
  - `bbs_thread_top` — 置顶主题索引（按版块/全局级别）
  - `bbs_thread_digest` — 精华主题索引
  - `bbs_post` — 帖子内容表（含 message 与 message_fmt）
  - `bbs_mythread` — 用户主题关系表（我的发帖）
  - `bbs_mypost` — 用户回帖关系表（我的回帖）
  - `bbs_attach` — 附件表（图片/文件/视频、存储驱动）
  - `bbs_thread_like` — 主题点赞（API v1）
  - `bbs_post_like` — 帖子点赞
  - `bbs_thread_favorite` — 主题收藏
  - `bbs_thread_report` — 帖子举报

- **用户社交**
  - `bbs_user_follow` — 用户关注关系
  - `bbs_notify` — 站内通知（回复/点赞/收藏/关注）

- **会话**
  - `bbs_session` — 在线会话表（sid、uid、ip、ua）
  - `bbs_session_data` — 会话超大数据存储

- **版主与审核**
  - `bbs_modlog` — 版主操作日志（置顶/删除等）
  - `bbs_audit_log` — 审核操作日志（通过/驳回）

- **积分**
  - `bbs_credits_log` — 积分变动日志
  - `bbs_credits_rule_global` — 全局积分规则（发帖/回复/加精等事件）
  - `bbs_credits_rule_forum` — 版块级积分规则覆盖

- **API 与安全**
  - `bbs_api_token` — API 令牌（access/refresh）
  - `bbs_api_app` — API 应用认证（appid/secret/scope）
  - `bbs_api_log` — API 访问日志
  - `bbs_ip_blacklist` — IP 黑白名单
  - `bbs_email_blacklist` — 邮箱域名黑名单
  - `bbs_admin_log` — 管理员操作日志
  - `bbs_email_log` — 邮件发送日志

- **插件与系统**
  - `bbs_plugin` — 插件/模板管理表（安装、启用状态）
  - `bbs_kv` — 持久键值存储（setting、thread_status_labels 等配置）
  - `bbs_cache` — 临时缓存表
  - `bbs_queue` — 临时队列
  - `bbs_table_day` — 大表按日统计最大 ID 与计数（用于冷热数据过滤）

- **可选全文索引**
  - `ft_subject`（`bbs_thread.subject`）与 `ft_message`（`bbs_post.message`）— 使用 ngram parser 的 FULLTEXT 索引，通过 `FULLTEXT_TOLERANT` 标记声明为可选，低版本 MySQL 创建失败不中断安装
