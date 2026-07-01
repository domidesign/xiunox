# 根目录文件（/）

> 项目入口、公共初始化、协议声明与 Web 服务器规则

## 目录结构概览

```
xiunox-main/
├── index.php              # 项目入口
├── index.inc.php          # 公共初始化
├── install.md             # 安装说明文档
├── README.md              # 项目说明
├── README.txt             # 框架理念说明文本版
├── LICENSE                # MIT 许可证 + 附加免责声明
├── LICENSE 4.0.4.txt      # 4.0.4 版本 MIT 许可证
├── LICENSE.txt            # MIT 许可证文本
├── .htaccess              # Apache 重写规则
└── .gitignore             # Git 忽略规则
```

## 文件用途说明

### index.php
- **用途**：项目统一入口文件，负责定义路径常量、加载配置、引入核心框架与模型层，并最终加载公共初始化逻辑。
- **关键函数/规则**：
  - `define('DEBUG', 0)` — 设定运行模式（0 线上 / 1 调试 / 2 插件开发），决定加载 xiunophp.min.php 还是 xiunophp.php
  - `define('APP_PATH' ...)` — 定义应用根路径、ADMIN_PATH、XIUNOPHP_PATH 路径常量
  - `include APP_PATH.'conf/conf.php'` — 加载站点配置，失败则跳转安装向导
  - `$conf['user_create_on']` 等默认值补齐 — 兼容 4.0.3 旧配置文件
  - 路径绝对化处理 — 将 log_path/tmp_path/upload_path/view_url/logo 等相对路径转为绝对路径
  - `include XIUNOPHP_PATH.'xiunophp.php'` — 根据 DEBUG 模式加载对应核心框架文件
  - `include _include(APP_PATH.'model.inc.php')` — 通过 _include 加载模型层，支持插件 hook 注入
  - `ErrorHandler::register()` — 注册自定义错误处理器
  - `include _include(APP_PATH.'index.inc.php')` — 加载公共初始化逻辑

### index.inc.php
- **用途**：公共初始化脚本，完成会话、语言、用户、版块、CSRF、运行级别校验、URL 重写重定向与路由分发。
- **关键函数/规则**：
  - `sess_start()` — 启动会话，获取 sid
  - `CsrfService::generate()` — 生成 CSRF token 注入 header
  - 用户级语言切换逻辑 — 按 cookie > 后台默认 > 浏览器 Accept-Language 顺序选择语言
  - `group_list_cache()` — 获取用户组列表缓存
  - `user_token_get()` — Token 登录机制，支持 REST 接口与 session 双重登录
  - `user_read($uid)` — 读取用户信息
  - `forum_list_cache()` — 获取版块列表缓存
  - `forum_list_access_filter()` — 按用户组权限过滤可见版块
  - `runtime_init()` — 初始化运行时数据（缓存统计）
  - `SecurityConfigService::get()` — 读取安全配置（如搜索是否需登录）
  - `check_runlevel()` — 检测站点运行级别，控制站点访问权限
  - 旧格式 URL 301 重定向 — 将 `/?user-1.htm` 等旧格式按 url_rewrite_on 模式重定向到新格式
  - 跨格式 301 重定向 — 在 .htm / .html / 路径风格间互转
  - 严格 URL 路径校验 — 禁止末尾斜杠、多级前缀、.html 后追加字符，命中则 `http_404()`
  - `param(0, 'index')` — 获取路由参数
  - `http_location()` — 搜索关键词自动跳转搜索页
  - API 路由识别 — 支持 `/api/v1/xxx` 与 `?api-v1-xxx.htm` 两种格式，命中后 `define('SKIP_ROUTE', true)` 并引入 bootstrap.php
  - `switch ($route)` — 按频次排序分发到 route/ 下对应控制器文件
  - `url()` — 在重定向前根据 url_rewrite_on 生成新格式 URL

### install.md
- **用途**：XIUNOX 安装教程文档，涵盖环境要求、安装步骤、Web 服务器配置、安全加固与常见问题排查。

### README.md
- **用途**：项目总说明，介绍 XIUNOX 技术栈、核心特性、目录结构、多语言支持与授权协议（基于 Xiuno BBS 4.0.4 的现代化重构版本）。

### README.txt
- **用途**：XiunoPHP 4 框架开发理念说明文本版，阐述函数式封装、避免 OO、利于 opcode 缓存等设计原则。

### LICENSE
- **用途**：MIT 开源许可证，附加免责声明与中国法律使用限制条款（禁止违法、危害国家安全、侵害他人权益等用途）。

### LICENSE 4.0.4.txt
- **用途**：Xiuno BBS 4.0.4 版本原始 MIT 许可证文本，版权归属 axiuno@gmail.com。

### LICENSE.txt
- **用途**：标准 MIT 许可证文本，与 LICENSE 4.0.4.txt 内容一致，OSI 认证版本。

### .htaccess
- **用途**：Apache Web 服务器伪静态与安全规则配置文件。
- **关键函数/规则**：
  - `RewriteEngine On` — 开启 URL 重写引擎
  - `RewriteCond %{REQUEST_FILENAME} !-f` — 请求不是已存在的文件
  - `RewriteCond %{REQUEST_FILENAME} !-d` — 请求不是已存在的目录
  - `RewriteRule ^(.*)$ index.php [L,QSA]` — 将非静态请求转发到 index.php 入口
  - `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` — 传递 Authorization 请求头到 CGI/FastCGI，支持 API Bearer Token
  - `<FilesMatch "^\."> Deny from all` — 禁止访问以点开头的隐藏文件
  - `Options -Indexes` — 禁止目录浏览

### .gitignore
- **用途**：Git 版本控制忽略规则，排除系统文件、运行时缓存、敏感配置、凭据与上传内容。
- **关键规则**：
  - `.DS_Store` — macOS 系统文件
  - `tmp/*` / `log/*`（保留 .gitkeep）— 运行时缓存与日志
  - `conf/conf.php` / `conf/smtp.conf.php` — 含敏感信息的配置文件
  - `.env` / `*.pem` / `*.key` / `credentials.*` — 凭据与密钥
  - `upload/attach/*` / `upload/avatar/*` — 用户上传的附件与头像
