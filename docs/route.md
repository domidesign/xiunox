# 前台路由（route/）

> 17 个前台页面路由文件，对应论坛用户访问的页面（首页、版块、帖子、用户、搜索等）

## 目录结构概览

```
route/
├── attach.php          # 附件上传/删除/读取/下载
├── browser.php         # 浏览器下载建议与兼容提示
├── captcha.php         # 验证码生成与校验
├── forum.php           # 版块页与版块关注/成员
├── forum_index.php     # 版块总览页
├── index.php           # 首页（最新/精华/关注帖子列表）
├── lang.php            # 多语言切换
├── mod.php             # 版主操作（置顶/关闭/删除/移动/审核等）
├── more.php            # 发现页（宫格导航）
├── my.php              # 我的中心（资料/头像/通知/收藏/积分等）
├── notice.php          # 通知中心（已读/下拉/公告/管理）
├── post.php            # 回帖发表/编辑/删除
├── rank.php            # 排行榜（热门帖子/活跃用户/积分）
├── search.php          # 搜索（关键词/建议/引用搜索）
├── theme.php           # 站点主题切换（亮/暗）
├── thread.php          # 帖子详情/发新主题/点赞/收藏
└── user.php            # 用户主页/登录/注册/关注/搜索
```

## 文件用途说明

### attach.php
- **用途**：处理附件上传（FormData 与 base64 两种方式）、删除、读取、下载与签名 AJAX 下载。
- **关键路由**：
  - `POST /attach-create.htm` — 上传附件（支持 FormData 与 base64，生成临时文件存 session）
  - `POST /attach-delete-{aid}.htm` — 删除附件（aid 形如 `_0` 为临时文件，数字为正式附件）
  - `GET /attach-read-{aid}-{token}.htm` — 通过签名 URL 直接读取附件文件流（含防盗链）
  - `GET /attach-download-{aid}.htm` — 下载附件（PHP 输出流，记录下载日志）
  - `GET /attach-fetch-{aid}-{token}-{expires}.htm` — AJAX 签名下载（需 X-Requested-With 头，校验时效）

### browser.php
- **用途**：旧版浏览器兼容提示页，并提供主流浏览器的下载跳转。
- **关键路由**：
  - `GET /browser.htm` — 显示浏览器不支持提示页
  - `GET /browser-download-chrome.htm` — 跳转到 Chrome 下载
  - `GET /browser-download-firefox.htm` — 跳转到 Firefox 下载
  - `GET /browser-download-ie.htm` — 跳转到 IE/Edge 下载

### captcha.php
- **用途**：基于 GD 库生成验证码图片，支持多场景（login/register/post/reply/resetpw 等）与插件自定义场景。
- **关键路由**：
  - `GET /captcha-generate-{scene}.htm` — 生成验证码图片（返回 JSON，含图片数据）
  - `GET /captcha-verify-{scene}.htm` — 校验用户输入的验证码是否正确

### forum.php
- **用途**：版块主页（主题列表、置顶帖、精华/热门/关注视图），以及版块关注、关注状态查询、版块成员列表。
- **关键路由**：
  - `GET /forum-{fid}-{page}.htm?orderby=lastpid` — 版块主页（支持 tid/lastpid/posts/views/digest/hot/follow 排序）
  - `POST /forum-follow-{fid}.htm` — 关注版块（返回 htmx 按钮片段）
  - `POST /forum-unfollow-{fid}.htm` — 取消关注版块
  - `GET /forum-follow_status-{fid}.htm` — 延迟加载版块关注状态（htmx）
  - `GET /forum-followers-{fid}-{page}.htm` — 版块成员（关注者）列表页

### forum_index.php
- **用途**：版块总览页，展示所有版块的树形结构与用户关注状态。
- **关键路由**：
  - `GET /forum_index.htm` — 显示版块总览页（含父版块与子版块树）

### index.php
- **用途**：论坛首页，展示全站最新主题列表，支持多种排序方式与首页版块过滤。
- **关键路由**：
  - `GET /index-{page}.htm?order=lastpid` — 首页主题列表（支持 lastpid/tid/hot/digest/follow 排序）
  - `GET /index.htm` — 首页第 1 页（默认排序，含置顶帖）

### lang.php
- **用途**：切换界面语言，写入 Cookie 后跳回来源页。
- **关键路由**：
  - `GET /lang-{lang_code}.htm` — 切换语言（支持 zh-cn/zh-tw/en-us/ru-ru/th-th/ja-jp/ko-kr）

### mod.php
- **用途**：版主管理操作，支持批量置顶、关闭、删除、移动、精华、审核、公告、评论置顶与删除用户。
- **关键路由**：
  - `GET /mod-top.htm` — 显示批量置顶表单
  - `POST /mod-top.htm` — 批量置顶/取消置顶（参数 tidarr、top）
  - `GET /mod-close.htm` — 显示批量关闭表单
  - `POST /mod-close.htm` — 批量关闭/开启主题
  - `GET /mod-delete.htm` — 显示批量删除表单
  - `POST /mod-delete.htm` — 批量删除主题
  - `GET /mod-move.htm` — 显示批量移动表单
  - `POST /mod-move.htm` — 批量移动主题到目标版块
  - `POST /mod-deleteuser-{uid}.htm` — 删除用户（管理员权限）
  - `GET /mod-digest.htm` — 显示批量精华表单
  - `POST /mod-digest.htm` — 批量加精/取消精华
  - `GET /mod-audit.htm` — 显示批量审核表单
  - `POST /mod-audit.htm` — 批量审核通过/驳回（audit_status=1 通过，=2 驳回）
  - `POST /mod-audit_post.htm` — 单条回帖审核（通过/驳回，含原因）
  - `POST /mod-top_post.htm` — 评论置顶/取消置顶（管理员或作者）
  - `GET /mod-announcement.htm` — 显示批量公告表单
  - `POST /mod-announcement.htm` — 批量设置/取消公告

### more.php
- **用途**：发现页（宫格应用展示），合并后台配置项与插件注册项，按 rank 排序。
- **关键路由**：
  - `GET /more.htm` — 显示发现页（宫格导航）

### my.php
- **用途**：用户个人中心，包含资料/头像/密码/邮箱/AI 配置/积分/通知/收藏/回帖/点赞/关注等子页与 API。
- **关键路由**：
  - `GET /my.htm` — 默认跳转到 `/my-profile.htm`
  - `GET /my-profile.htm` — 显示个人资料页
  - `POST /my-profile.htm` — 修改昵称/签名（含敏感词、唯一性、频率限制）
  - `GET /my-security.htm` — 安全设置页
  - `POST /my-password.htm` — 修改密码（校验旧密码与策略）
  - `POST /my-email.htm` — 修改邮箱（需邮箱验证码）
  - `POST /my-send_email_code.htm` — 发送邮箱修改验证码
  - `GET /my-avatar.htm` — 显示头像管理页
  - `POST /my-avatar.htm` — 上传头像（FormData 或 base64，含审核与次数限制）
  - `POST /my-avatar_preset.htm` — 选择预设头像
  - `GET /my-avatar_preset.htm` — 获取预设头像列表 JSON
  - `GET /my-ai.htm` — 显示 AI 配置页
  - `POST /my-ai.htm` — 保存 AI 提供商/Key/模型配置
  - `GET /my-credits-{page}.htm` — 积分记录列表
  - `GET /my-credits_rules.htm?fid={fid}` — 积分规则页（htmx 返回表格片段）
  - `GET /my-follow_users.htm` — 关注用户列表 JSON（用于 @提及）
  - `GET /my-thread-{page}.htm` — 我的主题列表
  - `GET /my-favorite-{page}.htm` — 我的收藏列表
  - `GET /my-post-{page}.htm` — 我的回帖列表
  - `GET /my-like-{page}.htm` — 我的点赞列表（按帖子去重）
  - `GET /my-following-{page}.htm` — 我关注的人列表
  - `GET /my-followers-{page}.htm` — 关注我的人列表
  - `GET /my-feed.htm` — 跳转到首页关注动态
  - `GET /my-notify_unread.htm` — 通知未读红点（HTML 片段）
  - `GET /my-notify_unread_count.htm` — 通知未读数 JSON
  - `GET /my-notify_dropdown.htm` — 顶部通知下拉列表（HTML 片段）
  - `GET /my-notify_list.htm?type={type}&page={page}` — 通知列表 JSON
  - `POST /my-notify_mark_read.htm` — 全部已读（htmx 返回 HX-Trigger）
  - `POST /my-notify_read-{nid}.htm` — 单条通知已读（htmx 返回已读卡片）
  - `GET /my-notify.htm` — 通知列表页（全部）
  - `GET /my-notify-{type}-{page}.htm` — 按类型筛选的通知列表页
  - `POST /my-notify.htm?act=readall` — 全部已读（POST 入口）
  - `POST /my-notify.htm?act=readone` — 单条已读（POST 入口）
  - `POST /my-notify.htm?act=delete` — 删除通知（POST 入口）
  - `GET /my-credits_check.htm?event={event}&fid={fid}` — 积分预检查 API（发帖/点赞/收藏前查询）

### notice.php
- **用途**：通知系统前台 API 与后台管理路由，包含已读/未读/下拉/公告发布/列表/删除。
- **关键路由**：
  - `POST /notice-mark_read.htm` — 标记已读（`all=1` 全部已读，`notice_id=N` 单条已读）
  - `GET /notice-unread_count.htm` — 返回未读数量（纯数字）
  - `GET /notice-dropdown.htm` — 顶部下拉通知列表（HTML 片段，AJAX）
  - `GET /notice-create.htm` — 管理员发送通知表单
  - `POST /notice-create.htm` — 管理员向指定 uid 发送系统通知
  - `GET /notice-list-{page}.htm` — 管理后台通知列表
  - `POST /notice-publish.htm` — 发布全局公告（uid=0，type=announcement）
  - `GET /notice-announcements.htm` — 前台获取最新 3 条公告 JSON
  - `GET /notice-announcement_list.htm` — 管理后台公告列表 HTML 片段
  - `POST /notice-delete.htm?nid={nid}` — 删除通知（管理员）

### post.php
- **用途**：回帖发表、编辑、删除，含验证码、敏感词过滤、内容审核、积分扣减与通知。
- **关键路由**：
  - `GET /post-create-{tid}.htm` — 显示回帖表单
  - `POST /post-create-{tid}.htm` — 发表回帖（含审核、@提及、积分、htmx 片段返回）
  - `GET /post-update-{pid}.htm` — 显示编辑帖子表单（首帖/回帖通用）
  - `POST /post-update-{pid}.htm` — 编辑帖子（含移动版块、驳回重提）
  - `POST /post-delete-{pid}.htm` — 删除帖子（首帖删除则删主题，回帖单独删除）

### rank.php
- **用途**：排行榜页面，展示热门帖子、活跃用户、积分排名，支持周/月/总三个时段。
- **关键路由**：
  - `GET /rank.htm?tab=threads&period=week` — 热门帖子排行榜（本周）
  - `GET /rank.htm?tab=users&period=month` — 活跃用户排行榜（本月）
  - `GET /rank.htm?tab=credits&period=total` — 积分排行榜（全部）
  - 注：tab/period 取值非法时 301 重定向到默认值

### search.php
- **用途**：全文搜索（标题+主帖内容），支持 FULLTEXT 优先 LIKE 回退、搜索建议、用户搜索。
- **关键路由**：
  - `GET /search-{keyword}-{page}.htm` — 关键词搜索（路径形式，含高亮摘要）
  - `GET /search.htm?keyword={keyword}&page={page}` — 关键词搜索（query string 形式）
  - `GET /search.htm?suggest=1&keyword={keyword}` — 搜索建议（返回标题前 5 条 HTML）
  - `GET /search.htm?ref_suggest=1&keyword={keyword}` — 编辑器引用搜索（返回 JSON）
  - `GET /search.htm?type=user&keyword={keyword}` — 同时搜索用户（用户名/昵称）

### theme.php
- **用途**：切换站点主题（亮色/暗色），写入 conf.php 持久化。
- **关键路由**：
  - `POST /theme.htm` — 切换主题（参数 theme=light/dark，校验后写入配置文件）

### thread.php
- **用途**：帖子详情页、发表新主题、点赞/取消点赞、收藏/取消收藏、公告设置、版块关注状态。
- **关键路由**：
  - `GET /thread-create-{fid}.htm` — 显示发帖表单
  - `POST /thread-create.htm` — 发表新主题（含验证码、敏感词、审核、@提及、版块通知）
  - `GET /thread-{tid}-{page}-{keyword}.htm?sort=asc` — 帖子详情页（支持 asc/desc/hot 排序）
  - `POST /thread-like-{tid}-{pid}.htm` — 点赞（htmx 返回按钮片段）
  - `POST /thread-unlike-{tid}-{pid}.htm` — 取消点赞
  - `POST /thread-favorite-{tid}.htm` — 收藏/取消收藏（自动切换）
  - `POST /thread-announcement-{tid}.htm` — 设置/取消公告（管理员）
  - `GET /thread-forum_follow_status-{fid}.htm` — 版块关注状态（htmx 延迟加载）

### user.php
- **用途**：用户主页、登录/注册/退出、找回密码、关注/粉丝列表、用户回帖/收藏/点赞、用户搜索。
- **关键路由**：
  - `GET /user-{uid}-{page}.htm` — 用户主页（含主题列表，其他 tab 由 htmx 按需加载）
  - `GET /user-tab_posts-{uid}-{page}.htm` — 回帖 tab（HTML 片段）
  - `GET /user-tab_following-{uid}.htm` — 关注 tab（HTML 片段）
  - `GET /user-tab_followers-{uid}.htm` — 粉丝 tab（HTML 片段）
  - `GET /user-tab_favorites-{uid}.htm` — 收藏 tab（HTML 片段）
  - `GET /user-tab_likes-{uid}.htm` — 点赞 tab（HTML 片段）
  - `GET /user-thread-{uid}-{page}.htm` — 用户主题列表页
  - `GET /user-login.htm` — 显示登录表单
  - `POST /user-login.htm` — 登录（含验证码、登录失败记录）
  - `GET /user-create.htm` — 显示注册表单
  - `POST /user-create.htm` — 注册（含验证码、邮箱白名单、IP 间隔、敏感词）
  - `GET /user-logout.htm` — 退出登录
  - `GET /user-resetpw.htm` — 显示找回密码表单
  - `POST /user-resetpw.htm` — 验证邮箱与验证码后跳转下一步
  - `GET /user-resetpw_complete.htm` — 显示设置新密码表单
  - `POST /user-resetpw_complete.htm` — 设置新密码
  - `POST /user-send_code-user_create.htm` — 发送注册验证码
  - `POST /user-send_code-user_resetpw.htm` — 发送找回密码验证码
  - `GET /user-synlogin.htm?token={token}&return_url={url}` — 同步登录（跨系统 token）
  - `POST /user-follow-{uid}.htm` — 关注/取消关注用户（htmx 返回按钮）
  - `GET /user-following-{uid}-{page}.htm` — 关注列表页
  - `GET /user-followers-{uid}-{page}.htm` — 粉丝列表页
  - `GET /user-post-{uid}-{page}.htm` — 用户回帖列表页
  - `GET /user-favorite-{uid}-{page}.htm` — 用户收藏列表页
  - `GET /user-like-{uid}-{page}.htm` — 用户点赞列表页
  - `GET /user-search.htm?keyword={keyword}` — 用户搜索 JSON（用于 @提及）
