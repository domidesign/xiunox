# API 接口（api/）

> RESTful API 接口目录，v1 版本，提供鉴权、附件、积分、搜索、帖子、用户等端点

## 目录结构概览

```
api/
├── .htaccess              # Apache 重写规则，将 /api/v1/* 转发到 index.php
└── v1/
    ├── index.php          # API v1 入口文件
    ├── bootstrap.php      # 引导文件：鉴权、限流、CORS、路由分发
    ├── admin.php          # 管理后台 API（安全配置、审核、敏感词、日志）
    ├── attach.php         # 附件上传、查询、删除
    ├── auth.php           # 认证（登录、注册、刷新、登出）
    ├── captcha.php        # 验证码生成与校验
    ├── credits.php        # 积分查询、增减、日志
    ├── forum.php          # 版块列表、树形结构、关注
    ├── mod.php            # 版主操作（置顶、关闭、移动、删除）
    ├── notify.php         # 通知列表、未读数、标记已读
    ├── openapi.php        # OpenAPI 规范文档输出
    ├── post.php           # 回帖 CRUD、点赞、批量删除
    ├── rank.php           # 排行榜（热门帖子、活跃用户）
    ├── search.php         # 搜索（帖子、回帖、用户）
    ├── site.php           # 站点信息与统计
    ├── test.php           # API 端点自测脚本（开发用）
    ├── thread.php        # 主题 CRUD、点赞、收藏、举报、公告、批量操作
    └── user.php           # 用户信息、关注、头像、AI 配置
```

## 文件用途说明

### api/.htaccess
- **用途**：Apache URL 重写规则，将 `/api/v1/*` 请求转发到 `api/v1/index.php`，并对 CORS 预检（OPTIONS）直接返回 204。
- **关键路由**：
  - 无业务路由，仅负责请求转发

### api/v1/index.php
- **用途**：API v1 直接访问入口文件，加载配置、xiunophp 框架及模型函数后引入 bootstrap.php 完成引导。
- **关键路由**：
  - `GET /api/v1/` — 返回版本号与可用端点列表（在 bootstrap.php 中处理）

### api/v1/bootstrap.php
- **用途**：API 引导核心，负责应用凭据校验（X-App-Id / X-App-Secret 双 key 模式）、应用 scope 与速率限制、全局限流、CORS、JSON 请求体解析及资源路由分发。
- **关键路由**：
  - `GET /api/v1/` — 返回版本号与可用端点列表
  - `OPTIONS /api/v1/*` — CORS 预检返回 204
  - 根据首段资源名分发到对应 xxx.php 文件

### api/v1/admin.php
- **用途**：管理后台 API，所有端点要求管理员权限（gid=1），覆盖安全配置、验证码配置、审核管理、敏感词管理与日志查询。
- **关键路由**：
  - `GET /api/v1/admin/security` — 读取安全配置
  - `PUT /api/v1/admin/security` — 更新 security_* 安全配置
  - `GET /api/v1/admin/security/captcha` — 读取验证码配置
  - `PUT /api/v1/admin/security/captcha` — 更新验证码配置
  - `GET /api/v1/admin/audit/pending` — 待审列表（thread/post/profile）
  - `POST /api/v1/admin/audit/approve` — 审核通过单条
  - `POST /api/v1/admin/audit/reject` — 审核驳回单条
  - `POST /api/v1/admin/audit/batch-approve` — 批量审核通过
  - `POST /api/v1/admin/audit/batch-reject` — 批量审核驳回
  - `GET /api/v1/admin/sensitive-words` — 敏感词列表
  - `POST /api/v1/admin/sensitive-words` — 添加单个敏感词
  - `DELETE /api/v1/admin/sensitive-words` — 清空所有敏感词
  - `POST /api/v1/admin/sensitive-words/import` — 批量导入敏感词
  - `DELETE /api/v1/admin/sensitive-words/{word}` — 删除指定敏感词
  - `GET /api/v1/admin/log/credits` — 积分日志查询
  - `GET /api/v1/admin/log/login` — 登录日志查询

### api/v1/attach.php
- **用途**：附件管理，支持上传附件到临时目录（生成 key 与缩略图）、按 ID 查询附件元数据、删除附件。
- **关键路由**：
  - `GET /api/v1/attach/{id}` — 获取附件详情
  - `POST /api/v1/attach` — 上传附件（multipart/form-data，字段名 file）
  - `DELETE /api/v1/attach/{id}` — 删除附件（本人或管理员）

### api/v1/auth.php
- **用途**：用户认证端点，提供邮箱+密码登录、注册、基于 refresh_token 的令牌刷新与登出。
- **关键路由**：
  - `POST /api/v1/auth/login` — 用户登录，返回 access_token / refresh_token
  - `POST /api/v1/auth/register` — 用户注册并签发令牌
  - `POST /api/v1/auth/refresh` — 刷新访问令牌
  - `POST /api/v1/auth/logout` — 登出并吊销令牌

### api/v1/captcha.php
- **用途**：验证码 API，按场景生成验证码图片（base64）并校验输入，受 CaptchaService 控制。
- **关键路由**：
  - `GET /api/v1/captcha/{scene}` — 生成指定场景的验证码图片
  - `POST /api/v1/captcha/{scene}/verify` — 校验验证码输入

### api/v1/credits.php
- **用途**：积分系统 API，支持查询当前用户积分余额、积分日志、增加与扣减积分（管理员可操作他人）。
- **关键路由**：
  - `GET /api/v1/credits` — 查询当前用户积分余额
  - `GET /api/v1/credits/log` — 查询积分变动日志
  - `POST /api/v1/credits/add` — 增加积分
  - `POST /api/v1/credits/sub` — 扣减积分

### api/v1/forum.php
- **用途**：版块 API，提供版块列表、树形结构、批量获取、版块详情、版块下帖子列表及关注/取消关注。
- **关键路由**：
  - `GET /api/v1/forum` — 版块分页列表
  - `GET /api/v1/forum?ids=1,2` — 按 ID 批量获取版块
  - `GET /api/v1/forum/tree` — 版块树形结构
  - `GET /api/v1/forum/{fid}` — 版块详情
  - `GET /api/v1/forum/{fid}/threads` — 版块下主题列表
  - `POST /api/v1/forum/follow` — 关注版块
  - `POST /api/v1/forum/unfollow` — 取消关注版块

### api/v1/mod.php
- **用途**：版主操作 API，仅允许 POST，对一组 tid 执行置顶、关闭、移动或删除操作并记录 modlog。
- **关键路由**：
  - `POST /api/v1/mod/top` — 置顶/取消置顶（top: 0~3）
  - `POST /api/v1/mod/close` — 关闭/打开帖子（close: 0/1）
  - `POST /api/v1/mod/move` — 移动帖子到目标版块（newfid）
  - `POST /api/v1/mod/delete` — 删除帖子

### api/v1/notify.php
- **用途**：通知 API，提供当前用户的通知列表、未读数、全部已读与单条标记已读。
- **关键路由**：
  - `GET /api/v1/notify` — 通知列表（支持 type 筛选）
  - `GET /api/v1/notify/unread` — 未读通知数
  - `PUT /api/v1/notify/read-all` — 全部标记已读
  - `PUT /api/v1/notify/{id}/read` — 标记单条已读

### api/v1/openapi.php
- **用途**：输出 OpenAPI 规范 JSON 文档，由 ApiDocService 生成，用于接口文档与客户端生成。
- **关键路由**：
  - `GET /api/v1/openapi.json` — 输出 OpenAPI 规范文档

### api/v1/post.php
- **用途**：回帖 API，提供回帖列表、详情、创建、更新、删除、点赞/取消点赞及管理员批量删除，并支持 attach_keys 关联附件。
- **关键路由**：
  - `GET /api/v1/post` — 回帖列表（支持 tid/uid 过滤）
  - `GET /api/v1/post/{pid}` — 回帖详情
  - `POST /api/v1/post` — 创建回帖
  - `PUT /api/v1/post/{pid}` — 更新回帖内容
  - `DELETE /api/v1/post/{pid}` — 删除单条回帖
  - `POST /api/v1/post/{pid}/like` — 点赞回帖
  - `DELETE /api/v1/post/{pid}/like` — 取消点赞回帖
  - `DELETE /api/v1/post/batch` — 管理员批量删除回帖

### api/v1/rank.php
- **用途**：排行榜 API，按 week/month/all 周期返回热门帖子与活跃用户排行。
- **关键路由**：
  - `GET /api/v1/rank` — 排行榜端点概览
  - `GET /api/v1/rank/threads` — 热门帖子排行
  - `GET /api/v1/rank/users` — 活跃用户排行

### api/v1/search.php
- **用途**：搜索 API，按关键词搜索主题、回帖或用户（用户搜索需登录），非管理员仅检索已审核内容。
- **关键路由**：
  - `GET /api/v1/search?q=&type=thread` — 搜索主题
  - `GET /api/v1/search?q=&type=post` — 搜索回帖
  - `GET /api/v1/search?q=&type=user` — 搜索用户（需登录）

### api/v1/site.php
- **用途**：站点信息 API，返回站点基本信息（名称、版本、语言等）与统计（帖子/用户/版块/今日数据）。
- **关键路由**：
  - `GET /api/v1/site` — 站点基本信息
  - `GET /api/v1/site/stats` — 站点统计数据

### api/v1/test.php
- **用途**：API 自测脚本（开发用），自动遍历全部端点并输出 JSON 或 HTML 报告，生产环境应删除或禁用。
- **关键路由**：
  - `GET /api/v1/test.php` — 运行全部端点测试（JSON）
  - `GET /api/v1/test.php?format=html` — 输出 HTML 测试报告
  - `GET /api/v1/test.php?admin_email=xx&admin_password=xx` — 指定管理员凭据测试

### api/v1/thread.php
- **用途**：主题 API，提供主题列表、详情、创建、更新、删除、点赞、收藏、举报、公告设置、热门主题及批量操作，并支持 attach_keys 关联附件。
- **关键路由**：
  - `GET /api/v1/thread` — 主题列表（支持 fid/uid/keyword 过滤）
  - `GET /api/v1/thread?ids=1,2` — 按 ID 批量获取主题
  - `GET /api/v1/thread/hot` — 近期热门主题
  - `GET /api/v1/thread/{tid}` — 主题详情
  - `POST /api/v1/thread` — 创建主题
  - `PUT /api/v1/thread/{tid}` — 更新主题
  - `DELETE /api/v1/thread/{tid}` — 删除主题
  - `POST /api/v1/thread/{tid}/like` — 点赞主题
  - `DELETE /api/v1/thread/{tid}/like` — 取消点赞
  - `POST /api/v1/thread/{tid}/favorite` — 收藏主题
  - `DELETE /api/v1/thread/{tid}/favorite` — 取消收藏
  - `POST /api/v1/thread/{tid}/report` — 举报主题
  - `POST /api/v1/thread/{tid}/announcement` — 设置/取消公告（管理员或版主）
  - `PUT /api/v1/thread/batch` — 管理员批量更新主题
  - `DELETE /api/v1/thread/batch` — 管理员批量删除主题

### api/v1/user.php
- **用途**：用户 API，提供用户列表、详情、当前用户、用户主题/回帖/收藏/关注/粉丝、AI 配置、预设头像及关注、头像上传等操作，按认证状态对用户字段脱敏。
- **关键路由**：
  - `GET /api/v1/user` — 用户列表（需登录）
  - `GET /api/v1/user?ids=1,2` — 按 ID 批量获取用户（需登录）
  - `GET /api/v1/user/me` — 获取当前登录用户
  - `GET /api/v1/user/{uid}` — 用户详情（按认证状态脱敏）
  - `GET /api/v1/user/{uid}/threads` — 用户主题列表
  - `GET /api/v1/user/{uid}/posts` — 用户回帖列表
  - `GET /api/v1/user/{uid}/favorites` — 用户收藏列表（仅本人）
  - `GET /api/v1/user/{uid}/following` — 用户关注列表
  - `GET /api/v1/user/{uid}/followers` — 用户粉丝列表
  - `GET /api/v1/user/{uid}/ai-config` — 获取用户 AI 配置（本人或管理员）
  - `GET /api/v1/user/{uid}/avatar/presets` — 预设头像列表
  - `PUT /api/v1/user/{uid}` — 更新用户资料（用户名/邮箱/密码/签名/头像）
  - `PUT /api/v1/user/{uid}/ai-config` — 更新用户 AI 配置
  - `POST /api/v1/user/{uid}/follow` — 关注用户
  - `DELETE /api/v1/user/{uid}/follow` — 取消关注用户
  - `POST /api/v1/user/{uid}/avatar` — 上传用户头像
  - `POST /api/v1/user/{uid}/avatar/preset` — 选择预设头像
