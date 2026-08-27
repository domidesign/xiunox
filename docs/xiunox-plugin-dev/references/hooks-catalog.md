# Hook 点速查表

> 本文件为 Hook 点速查，完整目录见 [plugindev/03-hooks-catalog.md](manual/03-hooks-catalog.md)

> ⚠️ 本文件中的 hook 名均已核对源码真实存在。如发现不存在的 hook，请报告。

**命名约定**：`.htm` 后缀 = 模板 hook（输出 HTML）；`.php` 后缀 = 源码 hook（执行 PHP）。

---

## 目录

- [1. 全局与布局](#1-全局与布局)
- [2. 首页 Index](#2-首页-index)
- [3. 帖子详情 Thread](#3-帖子详情-thread)
- [4. 帖子列表 thread_list](#4-帖子列表-thread_list)
- [5. 发帖回帖 Post](#5-发帖回帖-post)
- [6. 楼层 post_list](#6-楼层-post_list)
- [7. 板块 Forum](#7-板块-forum)
- [8. 用户 User](#8-用户-user)
- [9. 个人中心 My](#9-个人中心-my)
- [10. 后台管理 Admin](#10-后台管理-admin)
- [11. 侧边栏 Sidebar](#11-侧边栏-sidebar)
- [12. 模型层 Model PHP](#12-模型层-model-php)
- [13. 编辑器工具栏](#13-编辑器工具栏)
- [14. 头像组件](#14-头像组件)
- [15. 其它页面](#15-其它页面)
- [16. 入口与路由](#16-入口与路由-indexincphp)
- [17. 快速决策树](#17-快速决策树)

---

## 1. 全局与布局

### Header（`view/htm/header.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `header_start.htm` | `<head>` 最开始 | 注入 `<base>` / 额外 `<meta>` |
| `header_meta_before.htm` | `<meta charset>` 前 | 全局 SEO meta |
| `header_link_before.htm` | CSS `<link>` 前 | CSS 预加载 |
| `header_bootstrap_before.htm` / `header_bootstrap_after.htm` | Bootstrap CSS 前/后 | 替换/覆盖 Bootstrap |
| `header_bootstrap_bbs_before.htm` / `header_bootstrap_bbs_after.htm` | bootstrap-bbs.css 前/后 | BBS 主题色覆盖 |
| `header_link_after.htm` | CSS `<link>` 后、`<script>` 前 | ✅ **推荐**：注入全局插件 CSS |
| `header_body_start.htm` | `<body>` 开始后 | ✅ 全局顶部横幅/公告条 |
| `body_start.htm` | header_nav 后、`<main>` 内 container 开始 | 页面级全局组件 |

### Header 导航栏（`view/htm/header_nav.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `header_nav_start.htm` | `<nav>` 开始 | 导航栏顶部组件 |
| `header_nav_logo_before.htm` / `header_nav_logo_after.htm` | Logo 前/后 | 更换 Logo / Logo 旁标注 |
| `header_nav_custom_before.htm` / `header_nav_custom_after.htm` | 自定义导航菜单前/后 | 额外导航项 |
| `header_nav_search_before.htm` / `header_nav_search_after.htm` | 搜索框前/后 | 搜索增强 |
| `header_nav_user_menu_before.htm` / `header_nav_user_menu_after.htm` | 用户菜单前/后 | 用户菜单扩展 |
| `header_nav_admin_page_before.htm` / `header_nav_admin_page_after.htm` | 管理入口前/后 | 管理入口扩展 |
| `header_nav_end.htm` | `</nav>` 结束 | 导航栏底部组件 |

### Footer（`view/htm/footer.inc.htm` / `footer_nav.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `footer_start.htm` | footer 区域开始 | 页脚组件 |
| `footer_nav_before.htm` / `footer_nav_after.htm` | 页脚导航前/后 | |
| `footer_nav_start.htm` / `footer_nav_end.htm` | 底部导航栏开始/结束（footer_nav.inc.htm） | |
| `footer_js_before.htm` / `footer_js_after.htm` | JS 加载前/后 | ✅ `footer_js_after.htm` **推荐**：注入全局插件 JS |
| `footer_body_after.htm` | `</body>` 前 | ✅ **常用**：全局底部组件（统计代码/弹窗） |
| `footer_end.htm` | 页脚最末 | 备案号 |

---

## 2. 首页 Index

### 页面级（`view/htm/index.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `index_start.htm` | 首页内容最前 | 首页顶部组件 |
| `index_main_start.htm` | `<main>` 开始 | 主区域顶部 |
| `index_thread_list_top_item_before.htm` / `index_thread_list_top_item_after.htm` | 置顶帖列表项前/后 | |
| `index_thread_list_nav_item_after.htm` | 列表导航项后 | 额外筛选/排序 |
| `index_threadlist_before.htm` / `index_threadlist_after.htm` | 帖子列表前/后 | 列表工具栏/加载更多 |
| `index_page_before.htm` / `index_page_end.htm` | 分页前/后 | |
| `index_end.htm` | 首页内容结束 | 首页底部组件 |
| `index_js.htm` | 首页 JS 区 | 首页专属 JS |

### 路由级（`route/index.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `index_start.php` | route/index.php 开头 | 首页数据预处理 |
| `index_thread_list_before.php` | 帖子列表查询前 | 修改查询条件 |
| `thread_find_by_fids_before.php` | 按 fid 查帖子前 | 修改查询 |
| `index_end.php` | route/index.php 结束 | 首页后处理 |

---

## 3. 帖子详情 Thread

### 页面级（`view/htm/thread.htm`）

| Hook | 触发位置 |
|---|---|
| `thread_start.htm` | 帖子页内容开始 |
| `thread_end.htm` | 帖子页内容结束 |

### 主体区（`view/htm/thread_main.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_status_alert_after.htm` | 状态提示后 | 额外提示 |
| `thread_subject_before.htm` / `thread_subject_start.htm` / `thread_subject_end.htm` / `thread_subject_after.htm` | 主题标题各位置 | |
| `thread_subject_badge_after.htm` | 标题徽章后 | ✅ **常用**：标签徽章 |
| `thread_username_before.htm` | 发帖用户名前 | |
| `thread_info_end.htm` | 帖子元信息结束 | |
| `thread_views_after.htm` | 浏览量后 | 额外统计 |
| `thread_update_before.htm` / `thread_delete_after.htm` | 编辑/删除按钮 | 额外操作按钮 |
| `thread_message_before.htm` / `thread_message_after.htm` | 楼主消息区前/后 | |
| `thread_filelist_after.htm` | 附件列表后 | 额外附件展示 |
| `thread_message_more_before.htm` / `thread_message_more_after.htm` | "展开更多"前/后 | |
| `thread_message_actions_before.htm` / `thread_message_actions_after.htm` / `thread_message_actions_end.htm` | 互动操作区前/后/结束 | ✅ 举报/分享 |
| `thread_plugin_before.htm` / `thread_plugin_body.htm` / `thread_plugin_after.htm` | 插件区前/主体/后 | ✅ **常用**：插件内容注入 |
| `thread_postlist_before.htm` / `thread_postlist_after.htm` | 回帖列表前/后 | |
| `thread_post_list_title_middle.htm` / `thread_post_list_title_right.htm` | 回帖列表标题位置 | |
| `thread_quick_reply_message_before.htm` / `thread_quick_reply_message_after.htm` | 快速回复编辑器前/后 | |
| `thread_quick_reply_left_start.htm` / `thread_quick_reply_left_end.htm` | 快速回复左侧 | |
| `thread_quick_reply_right_start.htm` / `thread_quick_reply_right_end.htm` | 快速回复右侧 | |
| `thread_quick_reply_submit_after.htm` | 快速回复提交按钮后 | |
| `thread_page_after.htm` | 分页后 | 额外分页控件 |

### 左侧操作栏（`view/htm/thread_left.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_action_bar_top.htm` | 操作栏顶部 | ✅ **常用**：点赞/收藏按钮（PC） |
| `thread_action_bar_body.htm` | 操作栏主体 | |
| `thread_action_bar_bottom.htm` | 操作栏底部 | |

### 右侧用户卡（`view/htm/thread_right.inc.htm`）

| Hook | 触发位置 |
|---|---|
| `thread_author_card_username_after.htm` | 楼主用户名后（用户徽章） |
| `thread_user_after.htm` | 用户卡结束 |

### 路由级（`route/thread.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_start.php` | route/thread.php 开头 | |
| `thread_like_end.php` | 点赞后 | |
| `thread_favorite_end.php` | 收藏后 | |
| `thread_create_get_post.php` / `thread_create_get_start.php` / `thread_create_get_end.php` | 发帖 GET 分发/加载前/后 | |
| `thread_create_thread_start.php` / `thread_create_thread_before.php` / `thread_create_thread_end.php` | 发帖业务前/前/后 | ✅ `thread_create_thread_end.php` **常用**：发帖后处理 |
| `thread_info_start.php` / `thread_info_end.php` | 帖子详情加载前/后 | |
| `thread_end.php` | route/thread.php 结束 | |

---

## 4. 帖子列表 thread_list

> 本项目只有一种列表视图（`inc`），不存在 masonry/timeline/card 变体。

### 列表项（`view/htm/thread_list.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_list_inc_start.htm` / `thread_list_inc_end.htm` | 列表项开始/结束 | |
| `thread_list_inc_avatar_after.htm` | 头像后 | |
| `thread_list_inc_top_icon_before.htm` / `thread_list_inc_subject_top_after.htm` | 置顶标记 | |
| `thread_list_inc_subject_before.htm` / `thread_list_inc_subject_after.htm` | 标题前/后 | ✅ `thread_list_inc_subject_after.htm` **常用**：标签徽章 |
| `thread_list_inc_filetype_icon_before.htm` / `thread_list_inc_filetype_icon_after.htm` | 文件类型图标 | |
| `thread_list_inc_lock_icon_before.htm` / `thread_list_inc_lock_icon_after.htm` | 锁定图标 | |

### 管理模式（`view/htm/thread_list_mod.inc.htm`）

| Hook | 触发位置 |
|---|---|
| `thread_list_mod_delete_before.htm` / `thread_list_mod_delete_after.htm` | 删除按钮前/后 |
| `thread_list_mod_top_before.htm` / `thread_list_mod_top_after.htm` | 置顶按钮前/后 |
| `thread_list_mod_close_after.htm` | 关闭按钮后 |

---

## 5. 发帖回帖 Post

### 页面级（`view/htm/post.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `post_start_init.htm` | PHP 初始化时 | ✅ **常用**：注入 JS 变量/编辑器扩展 |
| `post_start.htm` / `post_end.htm` | 发帖页内容开始/结束 | |
| `post_fid_before.htm` / `post_fid_select_before.htm` / `post_fid_select_after.htm` | 版块选择 | |
| `post_subject_before.htm` / `post_subject_after.htm` | 标题输入前/后 | ✅ `post_subject_after.htm`：标签输入框 |
| `post_message_after.htm` | 内容输入后 | 编辑器下方工具 |
| `post_ref_thread_after.htm` | 引用帖子区后 | |
| `post_bottom_right.htm` / `post_bottom_left.htm` | 表单底部右/左 | |
| `post_submit_after.htm` | 提交按钮后 | 额外操作按钮 |
| `post_sidebar_top.htm` / `post_sidebar_bottom.htm` | 侧边栏顶/底 | |
| `post_js.htm` | 发帖页 JS 区 | |

### 路由级（`route/post.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `post_start.php` / `post_end.php` | route/post.php 开头/结束 | |
| `post_get_post.php` / `post_get_start.php` | GET 分发/加载前 | |
| `post_post_start.php` / `post_post_end.php` | 发帖提交前/后 | ✅ **常用**：发帖前/后处理 |
| `post_create_htmx_reply_end.php` | htmx 回帖结束 | |
| `post_update_get_post.php` / `post_update_get_start.php` / `post_update_get_end.php` | 编辑 GET 分发/加载 | |
| `post_update_post_start.php` / `post_update_post_end.php` | 编辑提交前/后 | ✅ `post_update_post_start.php` **常用**：编辑后处理 |
| `post_delete_start.php` / `post_delete_middle.php` / `post_delete_end.php` | 删除回帖前/中/后 | |

---

## 6. 楼层 post_list

在 `view/htm/post_list.inc.htm` 中（被 thread.htm / post.htm 循环 include）。

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `post_list_inc_start.htm` / `post_list_inc_end.htm` | 楼层开始/结束 | 楼层级组件 |
| `post_list_inc_avatar_after.htm` | 头像后 | |
| `post_list_inc_username_before.htm` / `post_list_inc_username_after.htm` | 用户名前/后 | ✅ `post_list_inc_username_after.htm`：用户徽章/等级 |
| `post_list_inc_subject_before.htm` / `post_list_inc_subject_after.htm` | 楼层标题前/后 | 标签/等级 |
| `post_list_inc_message_before.htm` / `post_list_inc_message_after.htm` | 楼层消息前/后 | 签名档/广告 |
| `post_list_inc_filelist_before.htm` / `post_list_inc_filelist_after.htm` | 附件列表前/后 | |
| `post_list_inc_create_date_before.htm` / `post_list_inc_create_date_after.htm` | 发帖时间前/后 | |
| `post_list_inc_quote_before.htm` / `post_list_inc_quote_after.htm` | 引用区前/后 | |
| `post_list_inc_update_before.htm` / `post_list_inc_update_after.htm` | 编辑记录前/后 | |
| `post_list_inc_delete_before.htm` / `post_list_inc_delete_after.htm` | 删除按钮前/后 | |
| `post_list_inc_floor_before.htm` / `post_list_inc_floor_after.htm` | 楼层号前/后 | |
| `post_list_inc_reply_delete_after.htm` | 回复删除后 | |

---

## 7. 板块 Forum

### 板块页（`view/htm/forum.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `forum_start.htm` / `forum_end.htm` | 板块页开始/结束 | 板块顶部/底部组件 |
| `forum_thread_list_nav_item_after.htm` | 列表导航项后 | 额外筛选/排序 |
| `forum_threadlist_before.htm` / `forum_threadlist_after.htm` | 帖子列表前/后 | |
| `forum_page_before.htm` / `forum_page_after.htm` | 分页前/后 | |
| `forum_mod_before.htm` / `forum_mod_after.htm` | 管理操作区前/后 | |
| `forum_js.htm` | 板块页 JS 区 | |

### 板块首页（`view/htm/forum_index.htm`）

| Hook | 触发位置 |
|---|---|
| `forum_index_forum_after.htm` | 板块列表后 |
| `forum_index_footer.htm` | 页脚区 |
| `forum_index_end.htm` | 板块首页结束 |
| `forum_index_js.htm` | 板块首页 JS 区 |

### 板块关注者（`view/htm/forum_followers.htm`）

| Hook | 触发位置 |
|---|---|
| `forum_followers_start.htm` / `forum_followers_end.htm` | 关注者页开始/结束 |

### 路由级（`route/forum.php`、`route/forum_index.php`）

| Hook | 触发位置 |
|---|---|
| `forum_start.php` / `forum_end.php` | route/forum.php 开头/结束 |
| `forum_top_list_before.php` / `forum_thread_list_before.php` | 置顶/帖子列表查询前 |
| `forum_index_start.php` / `forum_index_end.php` | route/forum_index.php 开头/结束 |

---

## 8. 用户 User

### 注册（`view/htm/user_create.htm`）

| Hook | 触发位置 |
|---|---|
| `user_create_start.htm` / `user_create_end.htm` | 注册页开始/结束 |
| `user_create_card_before.htm` / `user_create_card_after.htm` | 注册卡片前/后 |
| `user_create_title_after.htm` | 标题后 |
| `user_create_email_after.htm` / `user_create_username_after.htm` / `user_create_password_after.htm` | 各输入框后 |
| `user_create_submit_before.htm` / `user_create_submit_after.htm` | 提交按钮前/后 |
| `user_create_form_footer_right_start.htm` / `user_create_form_footer_right_end.htm` | 表单底部右侧 |

### 登录（`view/htm/user_login.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_login_start.htm` / `user_login_end.htm` | 登录页开始/结束 | |
| `user_login_title_after.htm` | 标题后 | |
| `user_login_email_after.htm` / `user_login_password_after.htm` | 邮箱/密码输入后 | |
| `user_login_submit_after.htm` | 提交按钮后 | |
| `user_login_form_footer_right_start.htm` / `user_login_form_footer_right_end.htm` | 表单底部右侧 | 第三方登录 |
| `user_login_card_after.htm` | 登录卡片后 | |

### 用户主页/公共模板

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_nav_profile_before.htm` / `user_nav_profile_after.htm` | 资料导航前/后 | |
| `user_nav_end.htm` | 用户导航结束 | ✅ 多个用户子页面共用（user_post/user_favorite/user_like/user_following/user_followers） |
| `user_index_start.htm` / `user_index_end.htm` | 用户首页开始/结束 | |
| `user_nav_before.htm` / `user_nav_after.htm` | 用户导航前/后 | |
| `user_profile_username_after.htm` | 用户名后（user_info_card.inc.htm） | 用户徽章 |

### 重置密码（`view/htm/user_resetpw.htm`）

| Hook | 触发位置 |
|---|---|
| `user_resetpw_email_before.htm` / `user_resetpw_email_after.htm` | 邮箱前/后 |
| `user_resetpw_verify_code_before.htm` / `user_resetpw_verify_code_after.htm` | 验证码前/后 |

### 路由级（`route/user.php`）

| Hook | 触发位置 |
|---|---|
| `user_start.php` / `user_end.php` | route/user.php 开头/结束 |
| `user_index_start.php` / `user_index_end.php` | 用户主页加载前/后 |
| `user_thread_start.php` / `user_thread_end.php` | 用户帖子列表前/后 |
| `user_login_get_post.php` / `user_login_get_start.php` / `user_login_get_end.php` | 登录 GET 分发/加载 |
| `user_login_post_start.php` / `user_login_post_password_check_after.php` / `user_login_post_end.php` | 登录提交前/校验后/后 |
| `user_create_get_post.php` / `user_create_get_start.php` / `user_create_get_end.php` | 注册 GET 分发/加载 |
| `user_create_post_start.php` / `user_create_post_end.php` | 注册提交前/后 |
| `user_logout_start.php` / `user_logout_end.php` | 退出登录前/后 |
| `user_resetpw_get_post.php` / `user_resetpw_get_start.php` / `user_resetpw_get_end.php` | 重置密码 GET 分发/加载 |
| `user_resetpw_post_start.php` / `user_resetpw_post_end.php` | 重置密码提交前/后 |
| `user_sendcode_start.php` / `user_send_code_before.php` / `user_send_code_after.php` | 发送验证码 |
| `user_post_start.php` / `user_post_end.php` | 用户帖子列表前/后 |
| `user_favorite_start.php` / `user_favorite_end.php` | 用户收藏前/后 |
| `user_like_start.php` / `user_like_end.php` | 用户点赞前/后 |

---

## 9. 个人中心 My

### 侧边导航（`view/htm/my.layout.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `my_sidebar_nav_before.htm` / `my_sidebar_nav_after.htm` | 侧边导航前/后 | ✅ `my_sidebar_nav_after.htm`：额外导航项 |
| `my_sidebar_nav_item_after.htm` | 每个导航项后（多处出现） | ✅ **常用**：添加额外导航项 |
| `my_sidebar_nav_profile_before.htm` / `my_sidebar_nav_security_before.htm` / `my_sidebar_nav_avatar_before.htm` / `my_sidebar_nav_ai_before.htm` / `my_sidebar_nav_credits_before.htm` / `my_sidebar_nav_threads_before.htm` | 各分类导航项前 | |
| `my_profile_username_after.htm` | 资料页用户名后 | |

### 公共模板（`view/htm/my.common.template.htm`）

| Hook | 触发位置 |
|---|---|
| `my_common_start.htm` / `my_common_end.htm` / `my_common_js.htm` | 个人中心开始/结束/JS 区 |
| `my_profile_start.htm` / `my_profile_end.htm` | 资料区开始/结束 |
| `my_security_start.htm` / `my_security_end.htm` | 安全区开始/结束 |
| `my_avatar_start.htm` / `my_avatar_end.htm` | 头像区开始/结束 |
| `my_ai_start.htm` / `my_ai_end.htm` | AI 区开始/结束 |

### 子页面导航

| Hook | 文件 | 触发位置 |
|---|---|---|
| `my_nav_favorite_before.htm` / `my_nav_favorite_after.htm` | my_favorite.template.htm | 收藏导航 |
| `my_nav_thread_before.htm` / `my_nav_thread_after.htm` | my_thread.template.htm | 帖子导航 |
| `my_nav_feed_before.htm` / `my_nav_feed_after.htm` | my_feed.template.htm | 动态导航 |
| `my_nav_notice_before.htm` / `my_nav_notice_after.htm` | my_notify.htm | 通知导航 |

### 积分页 / 通知页

| Hook | 触发位置 |
|---|---|
| `my_credits_page_start.htm` | 积分页开始（credits.htm） |
| `my_credits_rules_page_start.htm` | 积分规则页开始（credits_rules.htm） |
| `my_notify_js.htm` | 通知页 JS 区（my_notify.htm） |

### 路由级（`route/my.php`）

| Hook | 触发位置 |
|---|---|
| `my_start.php` / `my_end.php` | route/my.php 开头/结束 |
| `my_action_before.php` | action 分发前 |
| `my_profile_post_start.php` / `my_profile_post_end.php` | 资料提交前/后 |
| `my_password_post_start.php` / `my_password_post_end.php` | 密码修改前/后 |
| `my_email_post_start.php` / `my_email_post_end.php` | 邮箱修改前/后 |
| `my_send_email_code_start.php` / `my_send_email_code_end.php` | 发送邮箱验证码前/后 |
| `my_avatar_post_start.php` / `my_avatar_post_save_before.php` / `my_avatar_post_end.php` | 头像上传前/保存前/后 |
| `my_avatar_preset_end.php` | 预设头像后 |
| `my_ai_setting_post_start.php` / `my_ai_setting_post_end.php` | AI 设置提交前/后 |
| `my_thread_start.php` / `my_thread_end.php` | 我的帖子前/后 |

---

## 10. 后台管理 Admin

所有 admin hook 在 `admin/view/htm/*.htm` 和 `admin/route/*.php` 中，统一 `admin_` 前缀。

### Admin Header / Footer / Sidebar

| Hook | 触发位置 |
|---|---|
| `admin_header_meta_before.htm` | admin `<head>` 内 meta 前 |
| `admin_header_bootstrap_before.htm` / `admin_header_bootstrap_after.htm` | Bootstrap CSS 前/后 |
| `admin_header_bootstrap_bbs_before.htm` / `admin_header_bootstrap_bbs_after.htm` | bootstrap-bbs.css 前/后 |
| `admin_header_admin_css_before.htm` / `admin_header_admin_css_after.htm` | admin CSS 前/后 |
| `admin_header_css_after.htm` | 所有 CSS 后 |
| `admin_header_body_start.htm` | admin `<body>` 开始后 |
| `admin_header_nav_start.htm` / `admin_header_nav_footer.htm` | 后台导航栏开始/页脚 |
| `admin_footer_start.htm` / `admin_footer_end.htm` | admin footer 开始/结束 |
| `admin_sidebar_start.htm` / `admin_sidebar_end.htm` | 后台侧边栏开始/结束 |

### Admin 路由级（PHP）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `admin_index_route_case_start.php` | admin switch 最前 | 优先路由 |
| `admin_index_route_case_end.php` | ✅ **固定**：admin switch 最后 | 注册新的 admin 路由 |
| `admin_setting_upload_driver_register.php` | ✅ 上传设置页 GET+POST 双调用 | 注册存储驱动选项（插件扩展云存储） |

> 各模块（setting/forum/user/thread/group/log/attach/banned_ip/banned_user/credits_rule/ai/plugin_scanner/upgrade/online_upgrade/health/audit）的路由 hook 遵循 `admin_{模块}_start.php` / `admin_{模块}_end.php` 包裹，各 action 子模块遵循 `admin_{模块}_{action}_get_post.php` / `_get_start.php` / `_get_end.php` / `_post_start.php` / `_post_end.php` 五件套模式。

### Admin 模板 hook 命名规律

每个页面有 `admin_{页面}_start.htm` / `admin_{页面}_end.htm` / `admin_{页面}_js.htm` 三件套，部分页面在表单字段间有 `_xxx_after.htm` 中间注入点。

常见页面：`admin_index`、`admin_index_login`、`admin_message`、`admin_setting_base/smtp/upload/nav/credits/permalink/display/email_template/footer`、`admin_forum_list/create/update`、`admin_user_list/create/update/ban_log`、`admin_thread_list/thread_found/thread_recycle`、`admin_group_list/update`、`admin_credits_rule`、`admin_log_credits/login/operation/audit/attach`、`admin_banned_ip_list/banned_user_list`、`admin_attach_manage`、`admin_plugin_scanner`、`admin_upgrade/online_upgrade`、`admin_health`、`admin_api_doc/api_debug/api_settings`、`admin_ai_providers/features/editor/logs`、`admin_other_cache/cache_setting`。

---

## 11. 侧边栏 Sidebar

### 左侧边栏（`view/htm/sidebar_left.inc.htm`）

| Hook | 触发位置 |
|---|---|
| `sidebar_left_start.htm` / `sidebar_left_end.htm` | 左侧边栏开始/结束 |
| `sidebar_left_quick_before.htm` / `sidebar_left_quick_after.htm` | 快捷操作前/后 |
| `sidebar_left_forum_before.htm` / `sidebar_left_forum_after.htm` | 板块列表前/后 |

### 右侧边栏（`view/htm/sidebar_right.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `sidebar_right_start.htm` / `sidebar_right_end.htm` | 右侧边栏开始/结束 | |
| `index_site_brief_before.htm` / `index_site_brief_start.htm` / `index_site_brief_end.htm` / `index_site_brief_after.htm` | 站点简介各位置 | ✅ `index_site_brief_after.htm` **常用**：热标签/统计组件 |
| `index_site_brief_post_after.htm` / `index_site_brief_stats_after.htm` | 最新帖子后/统计数字后 | 额外统计项 |
| `sidebar_hot_before.htm` / `sidebar_hot_after.htm` | 热门帖子前/后 | ✅ 额外侧边栏组件 |
| `sidebar_friendlink_before.htm` / `sidebar_friendlink_after.htm` | 友情链接前/后 | |

### 用户卡片（`view/htm/sidebar_user_card.inc.htm`）

| Hook | 触发位置 |
|---|---|
| `sidebar_user_card_after.htm` | 用户卡结束后 |

---

## 12. 模型层 Model PHP

这些 hook 在 `model/*.func.php` 中，是纯 PHP 逻辑注入点。**hook 文件名用 `.php`**。

### 命名规律

每个模型文件以 `model_{模型}_start.php` / `model_{模型}_end.php` 包裹；各操作遵循 `model_{模型}_{操作}_start.php` / `model_{模型}_{操作}_end.php` 模式；原始层（`_` 前缀）和业务层均有 start/end 对。

### Thread（`model/thread.func.php`）

| Hook | 典型用途 |
|---|---|
| `model_thread_start.php` / `model_thread_end.php` | 文件开始/结束 |
| `model_thread__create_start.php` / `model_thread__create_end.php` | 原始层创建 |
| `model_thread__update_start.php` / `model_thread__update_end.php` | 原始层更新 |
| `model_thread__read_start.php` / `model_thread__read_end.php` | 原始层读取 |
| `model_thread__delete_start.php` / `model_thread__delete_end.php` | 原始层删除 |
| `model_thread__find_start.php` / `model_thread__find_end.php` | 原始层查找 |
| `model_thread_create_start.php` / `model_thread_create_end.php` | 业务层创建前/后 | ✅ `model_thread_create_end.php` **常用**：发帖后业务处理 |
| `model_thread__create_before.php` | db_insert 前 |
| `model_thread_update_start.php` / `model_thread_update_end.php` | 更新 |
| `model_thread_inc_views_start.php` / `model_thread_inc_views_end.php` | 浏览量+1 |
| `model_thread_read_start.php` / `model_thread_read_end.php` / `model_thread_read_cache_start.php` / `model_thread_read_cache_end.php` | 读取/缓存读取 |
| `model_thread_delete_start.php` / `model_thread_delete_end.php` | ✅ **常用**：删除后级联清理 |
| `model_thread_delete_batch_start.php` / `model_thread_delete_batch_end.php` | 批量删除 |
| `model_thread_soft_delete_start.php` / `model_thread_soft_delete_end.php` | 软删除 |
| `model_thread_soft_delete_batch_start.php` / `model_thread_soft_delete_batch_end.php` | 批量软删除 |
| `model_thread_restore_start.php` / `model_thread_restore_end.php` | 恢复 |
| `model_thread_restore_batch_start.php` / `model_thread_restore_batch_end.php` | 批量恢复 |
| `model_thread_find_start.php` / `model_thread_find_end.php` | 查找 |
| `model_thread_find_by_fid_start.php` / `model_thread_find_by_fid_middle.php` / `model_thread_find_by_fid_end.php` | 按 fid 查找 |
| `model_thread_find_by_fids_start.php` / `model_thread_find_by_fids_end.php` | 按 fids 批量查找 |
| `model_thread_find_by_tids_start.php` / `model_thread_find_by_tids_end.php` | 按 tids 查找 |
| `model_thread_find_by_keyword_start.php` / `model_thread_find_by_keyword_end.php` | 按关键词查找 |
| `model_thread_find_deleted_start.php` / `model_thread_find_deleted_end.php` | 查找已删除 |
| `model_thread_format_start.php` / `model_thread_format_end.php` | ✅ **常用**：格式化后添加字段 |
| `model_thread_format_last_date_start.php` / `model_thread_format_last_date_end.php` | 最后日期格式化 |
| `model_thread_count_start.php` / `model_thread_count_end.php` | 计数 |
| `model_thread_maxid_start.php` / `model_thread_maxid_end.php` | 最大 ID |
| `model_thread_safe_info_start.php` / `model_thread_safe_info_end.php` | 安全信息 |
| `model_thread_get_level_start.php` / `model_thread_get_level_end.php` | 获取层级 |
| `model_thread_list_access_filter_start.php` / `model_thread_list_access_filter_end.php` | 列表权限过滤 |

### Post（`model/post.func.php`）

`model_post_start.php` / `model_post_end.php` 包裹；原始层（`_` 前缀）和业务层各操作的 start/end 对：`_create/_update/_read/_delete/_find`、`create/update/read/delete/soft_delete/restore/delete_by_tid/delete_by_uid/find/find_by_tid/find_by_pids/find_by_uid_with_forum_access/find_quote_chain/find_deleted/count/maxid/format/safe_info/list_access_filter/list_cache_delete`。

| 关键 Hook | 典型用途 |
|---|---|
| `model_post_format_start.php` / `model_post_format_end.php` | ✅ **常用**：格式化后添加字段 |
| `model_post_delete_start.php` / `model_post_delete_end.php` | 删除后级联清理 |
| `model_post__create_start.php` / `model_post__create_insert_before.php` / `model_post__create_end.php` | 原始层创建 |
| `model_post_create_post__create_before.php` | 创建前 |
| `model_post_find_quote_chain_start.php` / `model_post_find_quote_chain_end.php` | 引用链查找 |
| `model_post_highlight_keyword_start.php` / `model_post_highlight_keyword_end.php` | 关键词高亮 |
| `model_post_file_list_html_start.php` / `model_post_file_list_html_end.php` | 附件列表 HTML |
| `post_message_fmt_start.php` / `post_message_fmt_end.php` | 消息格式化 |
| `post_brief_start.php` / `post_brief_end.php` | 摘要 |
| `post_quote_start.php` / `post_quote_end.php` | 引用 |

### User（`model/user.func.php`）

`model_user_start.php` / `model_user_end.php` 包裹；原始层和业务层各操作 start/end 对：`_create/_update/_read/_delete/_find`、`create/update/read/read_cache/delete/find/find_by_uids/find_by_usernames/read_by_email/read_by_username/count/maxid/format/safe_info`。

| 关键 Hook | 典型用途 |
|---|---|
| `model_user_format_start.php` / `model_user_format_end.php` | ✅ **常用**：格式化后（头像框/等级/勋章） |
| `model_user_format_avatar_url_before.php` | 头像 URL 格式化前 |
| `model_user_create_start.php` / `model_user_create_end.php` | 用户创建后初始化（欢迎消息） |
| `model_user_delete_start.php` / `model_user_delete_end.php` | 删除后级联清理 |
| `model_user_guest_start.php` / `model_user_guest_end.php` | 游客 |
| `model_user_update_group_start.php` / `model_user_update_group_end.php` | 用户组更新 |
| `model_user_token_get_start.php` / `model_user_token_get_end.php` / `model_user_token_get_do_start.php` / `model_user_token_get_do_end.php` | Token 获取/执行 |
| `model_user_token_set_end.php` / `model_user_token_clear_end.php` / `model_user_token_gen_start.php` / `model_user_token_gen_end.php` | Token 设置/清除/生成 |
| `model_user_login_check_start.php` / `model_user_login_check_end.php` | 登录校验 |
| `user_http_referer_start.php` / `user_http_referer_end.php` | HTTP Referer |
| `user_auth_check_start.php` / `user_auth_check_end.php` | 权限校验 |
| `model_user_change_password_start.php` / `model_user_change_password_end.php` | 密码修改 |
| `model_user_change_group_start.php` / `model_user_change_group_end.php` | 用户组变更 |

### Forum / Forum Access / Group / Attach / 其他模型

| 模型 | 文件 | 关键 Hook 模式 |
|---|---|---|
| Forum | `model/forum.func.php` | `model_forum_{start/end/__create/__update/__read/__delete/__find/create/update/read/delete/find/format/count/maxid/list_cache/list_cache_delete/list_access_filter/safe_info}` |
| Forum Access | `model/forum_access.func.php` | 原始层 + 业务层各操作 start/end；`forum_is_mod_start.php` / `forum_is_mod_end.php`（版主检测） |
| Group | `model/group.func.php` | 原始层 + 业务层各操作 start/end + `format/maxid/list_cache/list_cache_delete` |
| Attach | `model/attach.func.php` | 原始层 + 业务层各操作 start/end；`attach_assoc_post_start.php` / `attach_assoc_post_end.php` |
| Post Like | `model/post_like.func.php` | `model_post_like_{start/end/__create/__delete/read/create/delete/find_by_uid/count_by_pid}` |
| Thread Favorite | `model/thread_favorite.func.php` | `model_thread_favorite_start.php` / `model_thread_favorite_end.php` |
| Thread Top | `model/thread_top.func.php` | `model_thread_top_{start/end/change/change_batch/delete/find/find_cache/cache_delete/update_by_tid}` |
| Thread Digest | `model/thread_digest.func.php` | `model_thread_digest_change_batch_start.php` / `model_thread_digest_change_batch_end.php` |
| MyThread | `model/mythread.func.php` | `model_mythread_{start/end/create/read/delete/delete_by_uid/delete_by_fid/delete_by_tid/find/find_by_uid}` |
| Modlog | `model/modlog.func.php` | 原始层 + 业务层各操作 start/end |
| SMTP | `model/smtp.func.php` | `model_smtp_{start/end/create/update/read/delete/save/find/count/maxid}` |
| Runtime | `model/runtime.func.php` | `model_runtime_{start/end/init/get/set/delete/save/truncate}` |
| Check | `model/check.func.php` | `model_check_start.php` / `model_check_end.php`；`model_is_word_start.php`；`model_is_mobile/is_email/is_username` 各 start/end |
| Cron | `model/cron.func.php` | ✅ `model_cron_5_minutes_end.php`（5 分钟级）；✅ `model_cron_daily_end.php`（每日）；`model_cron_run_start.php` / `model_cron_run_end.php` |
| Banned IP | `model/banned_ip.func.php` | `model_banned_ip_start.php` / `model_banned_ip_end.php` |
| User Profile Audit | `model/user_profile_audit.func.php` | `model_user_profile_audit_start.php` / `model_user_profile_audit_end.php` |
| Ban Log | `model/ban_log.func.php` | `model_ban_log_start.php` / `model_ban_log_end.php` |
| Admin Log | `model/admin_log.func.php` | `model_admin_log_end.php` |
| User Follow | `model/user_follow.func.php` | `model_user_follow_start.php` / `model_user_follow_end.php` |
| Notify | `model/notify.func.php` | `model_notify_start.php` / `model_notify_end.php` |
| Plugin Notify（通知聚合中心） | `model/plugin_notify.func.php` | ✅ `plugin_notice_count.php` **协议 hook**：管理员通知红点数据源，核心 `plugin_notice_count_all()` 主动读取隔离执行（非编译 hook），写回 `$data['count']`/`$data['url']`，详见 [notify-patterns.md](notify-patterns.md) |
| Table Day | `model/table_day.func.php` | `model_table_day_{start/end/read/maxid/cron/rebuild}` |
| Misc | `model/misc.func.php` | `model_misc_start.php` / `model_misc_end.php`；✅ `model_url_start.php` / `model_url_end.php`（自定义 URL）；`model_check_runlevel_start.php` / `model_check_runlevel_end.php`；`model_message_start.php` / `model_message_end.php`；`model_xn_html_safe_{start/end/new_before/parse_before}` |
| Route | `model/route.func.php` | ✅ `model_route_table_end.php` **常用**：扩展路由表，注册插件自定义路由；`model_route_start.php` / `model_route_func_end.php` / `model_route_end.php` |

---

## 13. 编辑器工具栏

> `lib/EditorService.php` 的 `renderEditorHtml()` 方法内。完整教程见 [plugindev/11-editor-toolbar-integration.md](manual/11-editor-toolbar-integration.md)。

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `editor_custom_btns_end.php` | AIEditor `toolbarKeys` 配置组装时（`plugin_hook` 第二参数 `$data` 传按钮配置数组引用） | ✅ **常用**：插件向编辑器工具栏注入自定义按钮（隐藏内容、投票等），插件禁用/卸载后按钮自动消失 |

**hook 数据传递**：核心调用 `plugin_hook('editor_custom_btns_end.php', $customBtns)`，hook 内用 `$data` 变量追加按钮配置：

```php
<?php exit;
if (!isset($data) || !is_array($data)) $data = array();
$data[] = array(
    'btn_var'    => 'myBtn',
    'js_def'     => "var myBtn = { icon: '...', onClick: fn, tip: '...' };",
    'first_only' => true,
);
```

---

## 14. 头像组件

> 来源：`lib/avatar_component.php` 的 `avatar_component_from_data()` 函数内的 `plugin_hook()` 调用。完整教程见 [plugindev/12-avatar-component.md](manual/12-avatar-component.md)。

| Hook | 注入位置 | 模式 | 典型用途 |
|---|---|---|---|
| `avatar_component_badges.php` | L2 内，`avatar-group-icon` 之后 | 累加（`$data['badges_html'] .=`） | 认证对勾、勋章图标、在线状态 |
| `avatar_component_frame.php` | L1 内，L2 之后 | 覆盖（`$data['frame_html'] =`） | 装饰性头像框（节日/VIP） |

**`$data` 字段**：`uid` / `gid` / `size` / `avatar_url` / `badge_position`（仅 badges）/ `badges_html`（仅 badges）/ `frame_html`（仅 frame）

---

## 15. 其它页面

### 搜索（`view/htm/search.htm`、`route/search.php`）

| Hook | 触发位置 |
|---|---|
| `search_start.htm` / `search_end.htm` | 搜索页开始/结束 |
| `search_main_start.htm` / `search_main_end.htm` | 搜索主区开始/结束 |
| `search_threadlist_before.htm` / `search_threadlist_after.htm` | 搜索结果列表前/后 |
| `search_js.htm` | 搜索页 JS 区 |
| `search_start.php` / `search_end.php` | route/search.php 开头/结束 |
| `search_keyword_after.php` | 关键词解析后 |

### 错误页（`view/htm/error.htm`）

| Hook | 触发位置 |
|---|---|
| `error_start.htm` / `error_end.htm` | 错误页开始/结束 |
| `error_icon_before.htm` / `error_icon_after.htm` | 图标前/后 |
| `error_number_before.htm` / `error_number_after.htm` | 错误号前/后 |
| `error_title_before.htm` / `error_title_after.htm` | 标题前/后 |
| `error_message_before.htm` / `error_message_after.htm` | 消息前/后 |
| `error_buttons_before.htm` / `error_buttons_after.htm` | 按钮前/后 |
| `error_js.htm` | 错误页 JS 区 |

### 消息页 / 分页 / 排行榜 / 主题

| Hook | 触发位置 |
|---|---|
| `message_start.htm` / `message_end.htm` / `message_js.htm` | 消息页开始/结束/JS |
| `pagination_before.htm` / `pagination_after.htm` | 普通分页前/后（`pagination.inc.htm`） |
| `pagination_infinite_before.htm` / `pagination_infinite_after.htm` | 无限滚动分页前/后（`pagination_infinite.inc.htm`） |
| `rank_start.htm` / `rank_header.htm` / `rank_footer.htm` / `rank_end.htm` | 排行榜各位置（`rank.htm`） |
| `rank_start.php` / `rank_end.php` | route/rank.php 开头/结束 |
| `theme_start.htm` / `theme_end.htm` | 主题页开始/结束 |
| `theme_start.php` / `theme_end.php` | route/theme.php 开头/结束 |

### 封禁列表（`view/htm/banned.htm`、`route/banned.php`）

| Hook | 触发位置 |
|---|---|
| `banned_start.htm` / `banned_end.htm` / `banned_js.htm` | 封禁页开始/结束/JS |
| `banned_left_before.htm` | 左侧前 |
| `banned_tab_item_after.htm` | 标签项后 |
| `banned_current_body_before.htm` / `banned_current_body_after.htm` | 当前封禁实体前/后 |
| `banned_current_item_start.htm` / `banned_current_item_end.htm` | 当前封禁项 |
| `banned_recent_body_before.htm` / `banned_recent_body_after.htm` | 近期封禁实体前/后 |
| `banned_recent_item_start.htm` / `banned_recent_item_end.htm` | 近期封禁项 |
| `banned_bottom.htm` | 封禁页底部 |
| `banned_start.php` / `banned_end.php` | route/banned.php 开头/结束 |
| `banned_list_display.php` | 列表显示 |

### 封禁通知（`view/htm/banned_notice.htm`）

| Hook | 触发位置 |
|---|---|
| `banned_notice_start.htm` / `banned_notice_end.htm` / `banned_notice_js.htm` | 通知页开始/结束/JS |
| `banned_notice_icon_before.htm` / `banned_notice_icon_after.htm` | 图标前/后 |
| `banned_notice_title_before.htm` / `banned_notice_title_after.htm` | 标题前/后 |
| `banned_notice_badge_before.htm` / `banned_notice_badge_after.htm` | 徽章前/后 |
| `banned_notice_reason_before.htm` / `banned_notice_reason_after.htm` | 原因前/后 |
| `banned_notice_expire_before.htm` / `banned_notice_expire_after.htm` | 到期前/后 |
| `banned_notice_countdown_before.htm` / `banned_notice_countdown_after.htm` | 倒计前/后 |
| `banned_notice_appeal_before.htm` / `banned_notice_appeal_after.htm` | 申诉前/后 |
| `banned_notice_actions_before.htm` / `banned_notice_actions_after.htm` | 操作前/后 |

### 更多页 / Sitemap / AI / Attach

| Hook | 触发位置 |
|---|---|
| `more_start.htm` / `more_end.htm` | 更多页开始/结束（`more.htm`） |
| `more_discover_items_after.htm` | 发现项后 |
| `sitemap_end.php` | sitemap 生成结束（`route/sitemap.php`） |
| `route_ai_start.php` | route/ai.php 开头 |
| `route_ai_chat_start.php` | AI 对话开始 |
| `attach_start.php` / `attach_end.php` | route/attach.php 开头/结束 |
| `attach_create_start.php` / `attach_create_save_before.php` / `attach_create_end.php` | 上传开始/保存前/结束 |
| `attach_delete_start.php` / `attach_delete_end.php` | 删除 |
| `attach_read_start.php` / `attach_read_output_before.php` | 读取开始/输出前 |
| `attach_download_start.php` / `attach_download_readfile_before.php` / `attach_download_location_before.php` | 下载开始/读文件前/定位前 |
| `attach_output_before.php` | 输出前 |
| `storage_save.php` | ✅ 云存储驱动：`attach_assoc_post()` 中 `xn_copy()` 后 | 插件上传文件到云端（`upload_driver != 'local'` 时触发） |
| `storage_serve.php` | ✅ 云存储驱动：`read`/`download`/`fetch` 的 `readfile()` 前 | 插件重定向到云端 URL 并 exit（`upload_driver != 'local'` 时触发） |
| `storage_delete.php` | ✅ 云存储驱动：`attach_delete` / `attach_delete_by_pid` / `attach_delete_by_uid` 中 `unlink()` 前 | 插件删除云端文件（`upload_driver != 'local'` 时触发） |

---

## 16. 入口与路由 index.inc.php

在 `index.inc.php` 中，是全局路由分发入口。

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `index_inc_start.php` | ✅ **常用**：入口最开始（session/Service 加载后） | 全局预处理 |
| `user_ban_check.php` | ✅ **常用**：用户封禁检查（多处复用：index.inc.php/route/user.php/route/post.php/route/thread.php/route/my.php） | 拦截封禁用户 |
| `banned_ip_check.php` | IP 封禁检查（route/thread.php） | 拦截封禁 IP |
| `index_inc_route_before.php` | 路由分发前 | |
| `index_route_case_start.php` | switch 最前 | 优先路由拦截 |
| `index_route_case_end.php` | ✅ **固定**：switch 最后 | 注册新路由（`case 'xxx': include ...`） |
| `index_route_case_default.php` | switch `default:` 分支内、`http_404()` 前（index.inc.php:504） | 自定义未匹配路由的逻辑 |
| `index_inc_end.php` | 入口结束 | 全局后处理 |

> **同一 hook 名在多处触发的语义**：部分 hook 名（如 `user_ban_check.php`、`banned_ip_check.php`）会在多个源码文件中重复出现。**同一 hook 名只需注册一次**——编译时该 hook 文件内容会被物理拼进所有触发点执行。插件作者需注意：hook 逻辑会在每个触发点都运行一次，应避免重复计数、重复写入。

---

## 17. 快速决策树

```
你想做什么？
├─ 在所有页面头部/尾部注入 CSS/JS
│   ├─ header_link_after.htm（CSS）
│   └─ footer_js_after.htm（JS）
├─ 在首页加组件
│   ├─ index_start.htm / index_end.htm（全宽）
│   └─ index_site_brief_after.htm（侧边栏）
├─ 在帖子标题后加东西（标签/徽章）
│   ├─ thread_subject_badge_after.htm（详情页）
│   └─ thread_list_inc_subject_after.htm（列表页）
├─ 在楼层用户名旁加东西（勋章/等级）
│   └─ post_list_inc_username_after.htm
├─ 在发帖/编辑后做业务处理
│   ├─ thread_create_thread_end.php（发主题帖）
│   ├─ post_post_end.php（回帖）
│   └─ post_update_post_start.php（编辑）
├─ 添加新的路由/URL
│   └─ index_route_case_end.php
├─ 注册插件路由表
│   └─ model_route_table_end.php
├─ 添加定时任务
│   ├─ model_cron_5_minutes_end.php（5 分钟级）
│   └─ model_cron_daily_end.php（每日）
├─ 删除帖子时级联清理
│   └─ model_thread_delete_end.php
├─ 格式化后添加字段
│   ├─ model_thread_format_end.php（帖子）
│   ├─ model_post_format_end.php（回帖）
│   └─ model_user_format_end.php（用户）
├─ 自定义 URL 格式
│   └─ model_url_start.php / model_url_end.php
├─ 编辑器工具栏加按钮（隐藏内容/投票/附件等）
│   └─ editor_custom_btns_end.php
├─ 头像加角标/头像框
│   ├─ avatar_component_badges.php（角标累加）
│   └─ avatar_component_frame.php（头像框覆盖）
└─ 在后台加管理页
    ├─ admin_index_route_case_end.php（注册 admin 路由）
    └─ admin_*_after.htm（各后台页注入）
```

---

> **提示**：本文档基于源码 `// hook` 标记扫描生成。模板中的 `<!--{hook xxx}-->` 会在编译时被 `preg_replace` 归一化为 `// hook xxx`（见 `model/plugin.func.php`）。如需查找特定 hook，可在 `view/htm/`、`admin/view/htm/`、`route/`、`admin/route/`、`model/`、`lib/` 目录中搜索 `hook` 关键词。
