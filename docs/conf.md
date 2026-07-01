# 配置文件（conf/ 与 config/）

> 默认配置（站点、附件、邮件、SMTP）与安全配置（敏感词、安全策略）

## 目录结构概览

```
xiunox-main/
├── conf/                            # Xiuno 默认配置目录
│   ├── .htaccess                    # 拒绝 Web 访问的保护规则
│   ├── attach.conf.php              # 附件类型默认配置
│   ├── conf.default.php             # 站点默认配置（DB/缓存/URL/上传等）
│   ├── email_templates.conf.php     # 邮件模板配置
│   └── smtp.conf.default.php        # SMTP 默认模板（非运行时读取）
└── config/                          # 安全相关配置目录
    ├── security.php                 # 安全与审核系统配置
    └── sensitive_words.txt          # 敏感词库
```

## 文件用途说明

### conf/.htaccess
- **用途**：保护 conf/ 目录，禁止通过 Web 直接访问其中的配置文件，避免敏感信息泄露。
- **关键规则**：
  - `Deny from all` — 拒绝所有客户端对该目录及子目录的 HTTP 访问。

### conf/attach.conf.php
- **用途**：定义附件上传时按类型分组的允许扩展名集合，供附件分类与白名单校验使用。
- **关键配置项**：
  - `'all'` — 所有允许上传的扩展名集合（白名单总集）
  - `'video'` — 视频类扩展名
  - `'music'` — 音乐类扩展名
  - `'exe'` — 可执行文件类扩展名
  - `'flash'` — Flash 类扩展名
  - `'image'` — 图片类扩展名
  - `'office'` — Office 文档类扩展名
  - `'pdf'` — PDF 类扩展名
  - `'text'` — 文本/源码类扩展名
  - `'zip'` — 压缩包类扩展名
  - `'book'` — 电子书类扩展名
  - `'torrent'` — BT 种子类扩展名
  - `'font'` — 字体类扩展名
  - `'other'` — 其他扩展名（默认空）

### conf/conf.default.php
- **用途**：站点核心默认配置，涵盖数据库、缓存、URL、上传、API、安全、积分等全局参数，安装时复制为 conf/conf.php 后生效。
- **关键配置项**：
  - `'db'` — 数据库连接配置（支持 mysql/pdo_mysql 两种驱动，主从分离）
  - `'cache'` — 缓存配置（开关、类型、表前缀）
  - `'tmp_path'` — 临时文件目录路径
  - `'log_path'` — 日志目录路径
  - `'view_url'` — 视图静态资源访问 URL（可配 CDN 域名）
  - `'upload_url'` — 上传文件访问地址
  - `'upload_path'` — 上传文件物理存储路径
  - `'logo_mobile_url'` / `'logo_pc_url'` / `'logo_water_url'` — 移动端/PC 端/水印 LOGO 地址
  - `'sitename'` — 站点名称
  - `'sitebrief'` — 站点简介
  - `'timezone'` — 时区设置
  - `'lang'` — 默认语言
  - `'runlevel'` — 站点运行级别（0 关闭至 5 全员可读写）
  - `'runlevel_reason'` — 站点关闭时的提示文案
  - `'cookie_domain'` / `'cookie_path'` — Cookie 作用域
  - `'auth_key'` — Cookie 加密密钥
  - `'pagesize'` — 列表分页大小
  - `'postlist_pagesize'` — 帖子列表分页大小
  - `'online_hold_time'` — 在线状态保持时长
  - `'upload_image_width'` — 上传图片自动缩略最大宽度
  - `'attach_dir_save_rule'` — 附件按日期目录存放规则
  - `'attach_sign_key'` — 附件签名密钥（生成图片附件签名 URL）
  - `'attach_referer_check'` — 附件防盗链检查开关
  - `'login_max_attempts'` — 登录最大尝试次数
  - `'login_ban_duration'` — 登录失败锁定时长
  - `'admin_bind_ip'` — 后台是否绑定 IP
  - `'cdn_on'` — 是否开启 CDN
  - `'url_rewrite_on'` — URL 伪静态开关（0-5 多种模式）
  - `'url_rewrite_custom'` — 自定义伪静态格式（url_rewrite_on=5 时生效）
  - `'disabled_plugin'` — 是否禁用插件
  - `'cache_disable'` — 开发模式缓存开关
  - `'enabled_themes'` — 启用的主题列表
  - `'default_theme'` — 默认主题
  - `'credits_daily_limit'` — 同一 reason+uid 每日积分操作限制
  - `'credits_types'` — 启用的积分类型
  - `'upload_max_image_size'` / `'upload_max_file_size'` / `'upload_max_video_size'` — 各类上传文件最大尺寸
  - `'upload_thumb_enabled'` / `'upload_thumb_width'` — 缩略图生成开关与宽度
  - `'upload_allowed_image_types'` / `'upload_allowed_video_types'` / `'upload_allowed_file_types'` — 各类上传允许扩展名
  - `'upload_driver'` — 上传存储驱动（local/oss）
  - `'api_enabled'` — API 总开关
  - `'api_token_expire'` — API 令牌过期天数
  - `'api_rate_limit'` / `'api_rate_limit_max'` / `'api_rate_limit_window'` — API 速率限制配置
  - `'api_cors_origin'` — CORS 允许来源
  - `'editor'` — 默认编辑器
  - `'security_password_max_retries'` — 密码最大重试次数
  - `'security_lockout_duration'` — 账号锁定时长
  - `'security_email_code_interval'` — 验证码发送间隔
  - `'home_forum_ids'` — 首页版块过滤
  - `'mobile_nav_enable'` — 手机底部导航开关

### conf/email_templates.conf.php
- **用途**：定义系统发送验证码邮件时使用的主题与正文 HTML 模板，支持 `{sitename}`、`{code}` 占位符替换。
- **关键配置项**：
  - `'user_create_code'` — 注册验证码邮件模板（含 `subject`/`body`）
  - `'user_resetpw_code'` — 密码重置验证码邮件模板
  - `'email_change_code'` — 邮箱变更验证码邮件模板

### conf/smtp.conf.default.php
- **用途**：SMTP 邮件发送参数的默认模板，仅作参考不被程序读取；实际配置由后台写入 conf/smtp.conf.php。
- **关键配置项**：
  - `'email'` — 发件邮箱地址
  - `'host'` — SMTP 服务器地址
  - `'port'` — SMTP 服务器端口
  - `'user'` — SMTP 登录用户名
  - `'pass'` — SMTP 登录密码
  - `'ssl'` — 是否启用 SSL 加密连接

### config/security.php
- **用途**：集中配置验证码、敏感词过滤、帖子审核、内容安全审核与安全增强策略，修改后需清理 tmp/ 缓存。
- **关键配置项**：
  - `'captcha'` — 验证码配置（含 login/register/post/resetpw 各场景开关与 type 类型）
  - `'sensitive_word'` — 敏感词过滤配置
    - `'enabled'` — 是否启用敏感词过滤
    - `'action'` — 命中动作（reject 拒绝 / review 审核 / replace 替换）
    - `'words_file'` — 词库文件路径
  - `'audit'` — 帖子审核配置（含 enabled、credits_on_approve、credits_amount）
  - `'moderation'` — 内容安全审核配置（内置默认关闭，需插件实现）
  - `'security'` — 安全增强配置
    - `'prevent_enumeration'` — 防止用户名枚举
    - `'verify_sensitive_action'` — 敏感操作二次验证
    - `'show_last_login'` — 登录后展示上次登录信息

### config/sensitive_words.txt
- **用途**：敏感词库文件，被 `config/security.php` 中的 `sensitive_word.words_file` 引用，用于发布内容时的关键词过滤。
- **格式说明**：纯文本，每行一个敏感词；以 `#` 开头的行视为注释；修改后自动生效无需重启，建议通过后台管理页面编辑。
