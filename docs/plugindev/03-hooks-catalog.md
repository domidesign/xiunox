# 03 Hook 点全量目录

> 来源：Grep 扫描 `view/htm/*.htm`（`<!--{hook ...}-->` 模板标记，编译时归一化为 `// hook`）+ `route/*.php`、`model/*.func.php`、`index.inc.php`（`// hook` 标记），基于源码实际内容整理。
> 每个 .htm hook 输出 HTML；每个 .php hook 执行 PHP 逻辑。
> 命名约定：`.htm` 后缀 = 模板 hook（输出 HTML），`.php` 后缀 = 源码 hook（执行 PHP）。

---

## 快速查找

| 我想在……插入代码 | 跳到 |
|---|---|
| 页面头部/尾部/全局 | [全局与布局](#1-全局与布局-global--layout) |
| 首页（index） | [首页](#2-首页-index) |
| 帖子详情页（thread） | [帖子详情](#3-帖子详情-thread) |
| 帖子列表（thread_list） | [帖子列表](#4-帖子列表-thread_list) |
| 发帖/回帖页（post） | [发帖回帖](#5-发帖回帖-post) |
| 回帖楼层（post_list） | [楼层](#6-楼层-post_list) |
| 板块（forum） | [板块](#7-板块-forum) |
| 用户（user） | [用户](#8-用户-user) |
| 个人中心（my） | [个人中心](#9-个人中心-my) |
| 后台管理（admin） | [后台管理](#10-后台管理-admin) |
| 侧边栏/导航 | [侧边栏与导航](#11-侧边栏与导航-sidebar) |
| 模型/业务层（PHP） | [模型层 PHP](#12-模型层-php-model) |
| 搜索/错误/消息/分页/封禁 | [其它页面](#13-其它页面) |
| 路由分发/入口 | [入口与路由](#14-入口与路由-indexincphp) |

---

## 1. 全局与布局（Global / Layout）

所有页面通过 `header.inc.htm` / `header_nav.inc.htm` / `footer.inc.htm` / `footer_nav.inc.htm` 触发。

### Header 区域（`view/htm/header.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `header_start.htm` | `<html>` 前、`<head>` 最开始 | 注入 `<base>`/额外 `<meta>` |
| `header_meta_before.htm` | `<meta charset>` 前 | 全局 SEO meta |
| `header_link_before.htm` | CSS `<link>`（favicon）前 | 全局 CSS 预加载 |
| `header_bootstrap_before.htm` | Bootstrap CSS 前 | 替换 Bootstrap |
| `header_bootstrap_after.htm` | Bootstrap CSS + theme.css 后 | Bootstrap 覆盖样式 |
| `header_bootstrap_bbs_before.htm` | bootstrap-bbs.css 前（当前为空标记位） | 覆盖 BBS 主题色 |
| `header_bootstrap_bbs_after.htm` | bootstrap-bbs.css 后（当前为空标记位） | BBS 主题色覆盖 |
| `header_link_after.htm` | CSS `<link>` 后、`<script>` 前 | ✅ **推荐**：注入全局插件 CSS |
| `header_body_start.htm` | `<body>` 开始后、header_nav 前 | ✅ **推荐**：全局顶部横幅/公告条 |
| `body_start.htm` | header_nav 后、`<main>` 内 container 开始 | 页面级全局组件 |

### Header 导航栏（`view/htm/header_nav.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `header_nav_start.htm` | `<nav>` 开始 | 导航栏顶部组件 |
| `header_nav_logo_before.htm` | Logo 前 | 更换 Logo |
| `header_nav_logo_after.htm` | Logo 后 | Logo 旁标注 |
| `header_nav_custom_before.htm` | 自定义导航菜单前 | 额外导航项 |
| `header_nav_custom_after.htm` | 自定义导航菜单后 | 额外菜单项 |
| `header_nav_search_before.htm` | 搜索框前 | 额外搜索入口 |
| `header_nav_search_after.htm` | 搜索框后 | 搜索增强 |
| `header_nav_user_menu_before.htm` | 用户菜单前 | 用户相关组件 |
| `header_nav_admin_page_before.htm` | 管理入口前 | 额外管理链接 |
| `header_nav_admin_page_after.htm` | 管理入口后 | 管理入口扩展 |
| `header_nav_user_menu_after.htm` | 用户菜单后 | 用户菜单扩展 |
| `header_nav_end.htm` | `</nav>` 结束 | 导航栏底部组件 |

### Footer 区域（`view/htm/footer.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `footer_start.htm` | footer 区域开始 | 页脚组件 |
| `footer_nav_before.htm` | 页脚导航前 | |
| `footer_nav_after.htm` | 页脚导航后 | |
| `footer_js_before.htm` | JS 加载前 | 额外 JS 配置 |
| `footer_js_after.htm` | JS 加载后 | ✅ **推荐**：注入全局插件 JS |
| `footer_body_after.htm` | `</body>` 前、footer 结束后 | ✅ **常用**：全局底部组件（统计代码/弹窗） |
| `footer_end.htm` | 页脚最末 | 备案号 |

### Footer 导航（`view/htm/footer_nav.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `footer_nav_start.htm` | 底部导航栏开始 | 底部导航组件 |
| `footer_nav_end.htm` | 底部导航栏结束 | 底部导航扩展 |

---

## 2. 首页（Index）

在 `view/htm/index.htm` 和 `route/index.php` 中。

### 页面级（`view/htm/index.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `index_start.htm` | 首页内容最前 | 首页顶部组件 |
| `index_main_start.htm` | `<main>` 开始 | 主区域顶部 |
| `index_thread_list_top_item_before.htm` | 置顶帖列表项前 | |
| `index_thread_list_top_item_after.htm` | 置顶帖列表项后 | |
| `index_thread_list_nav_item_after.htm` | 列表导航项后 | 额外筛选/排序 |
| `index_threadlist_before.htm` | 帖子列表前 | 列表顶部工具栏 |
| `index_threadlist_after.htm` | 帖子列表后 | 列表底部（加载更多等） |
| `index_page_before.htm` | 分页前 | |
| `index_page_end.htm` | 分页后 | 额外分页控件 |
| `index_end.htm` | 首页内容结束 | 首页底部组件 |
| `index_js.htm` | 首页 JS 区 | 首页专属 JS |

### 路由级（`route/index.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `index_start.php` | route/index.php 开头 | 首页数据预处理 |
| `index_thread_list_before.php` | 帖子列表查询前 | 拦截/修改查询条件 |
| `thread_find_by_fids_before.php` | 按 fid 查帖子前 | 修改查询 |
| `index_end.php` | route/index.php 结束 | 首页后处理 |

---

## 3. 帖子详情（Thread）

在 `view/htm/thread.htm`、`thread_main.inc.htm`、`thread_left.inc.htm`、`thread_right.inc.htm` 和 `route/thread.php` 中。

### 页面级（`view/htm/thread.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_start.htm` | 帖子页内容开始 | 帖子页顶部组件 |
| `thread_end.htm` | 帖子页内容结束 | 帖子页底部组件 |

### 主体区（`view/htm/thread_main.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_status_alert_after.htm` | 状态提示后 | 额外提示 |
| `thread_subject_before.htm` | 主题标题前 | |
| `thread_subject_start.htm` | 标题内容开始 | |
| `thread_subject_badge_after.htm` | 标题徽章后 | ✅ **常用**：标签徽章（如 xnx_tag） |
| `thread_subject_end.htm` | 标题内容结束 | |
| `thread_subject_after.htm` | 主题标题后 | 置顶/精华标签 |
| `thread_username_before.htm` | 发帖用户名前 | |
| `thread_info_end.htm` | 帖子元信息结束 | |
| `thread_views_after.htm` | 浏览量后 | 额外统计 |
| `thread_update_before.htm` | 编辑按钮前 | |
| `thread_delete_after.htm` | 删除按钮后 | 额外操作按钮 |
| `thread_message_before.htm` | 楼主消息区前 | 楼主区顶部 |
| `thread_message_after.htm` | 楼主消息区后 | 楼主区底部 |
| `thread_filelist_after.htm` | 附件列表后 | 额外附件展示 |
| `thread_message_more_before.htm` | "展开更多"前 | |
| `thread_message_more_after.htm` | "展开更多"后 | |
| `thread_message_actions_before.htm` | 互动操作区前（点赞/收藏） | |
| `thread_message_actions_after.htm` | 互动操作区后 | ✅ 额外操作（举报/分享） |
| `thread_message_actions_end.htm` | 互动操作区结束 | |
| `thread_plugin_before.htm` | 插件区前 | |
| `thread_plugin_body.htm` | 插件区主体 | ✅ **常用**：插件内容注入 |
| `thread_plugin_after.htm` | 插件区后 | |
| `thread_postlist_before.htm` | 回帖列表前 | |
| `thread_post_list_title_middle.htm` | 回帖列表标题中间 | |
| `thread_post_list_title_right.htm` | 回帖列表标题右侧 | |
| `thread_quick_reply_message_before.htm` | 快速回复编辑器前 | |
| `thread_quick_reply_message_after.htm` | 快速回复编辑器后 | |
| `thread_quick_reply_left_start.htm` | 快速回复左侧开始 | |
| `thread_quick_reply_left_end.htm` | 快速回复左侧结束 | |
| `thread_quick_reply_right_start.htm` | 快速回复右侧开始 | |
| `thread_quick_reply_right_end.htm` | 快速回复右侧结束 | |
| `thread_quick_reply_submit_after.htm` | 快速回复提交按钮后 | |
| `thread_postlist_after.htm` | 回帖列表后 | 回帖汇总组件 |
| `thread_page_after.htm` | 分页后 | 额外分页控件 |

### 左侧操作栏（`view/htm/thread_left.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_action_bar_top.htm` | 操作栏顶部 | ✅ **常用**：点赞/收藏按钮（PC 端） |
| `thread_action_bar_body.htm` | 操作栏主体 | |
| `thread_action_bar_bottom.htm` | 操作栏底部 | |

### 右侧用户卡（`view/htm/thread_right.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_author_card_username_after.htm` | 楼主用户名后 | 用户徽章 |
| `thread_user_after.htm` | 用户卡结束 | 额外用户信息 |

### 路由级（`route/thread.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_start.php` | route/thread.php 开头 | |
| `thread_like_end.php` | 点赞后 | |
| `thread_favorite_end.php` | 收藏后 | |
| `thread_create_get_post.php` | 发帖 GET/POST 分发 | |
| `thread_create_get_start.php` | 发帖页加载前 | |
| `thread_create_get_end.php` | 发帖页加载后 | |
| `thread_create_thread_start.php` | 发帖业务逻辑前 | 拦截发帖 |
| `thread_create_thread_before.php` | thread__create 前 | |
| `thread_create_thread_end.php` | ✅ **常用**：发帖后处理（如 xnx_tag 同步标签） |
| `thread_info_start.php` | 帖子详情加载前 | |
| `thread_info_end.php` | 帖子详情加载后 | |
| `thread_end.php` | route/thread.php 结束 | |

> `banned_ip_check.php` 和 `user_ban_check.php` 也会在 thread 路由中触发，见[入口与路由](#14-入口与路由-indexincphp)。

---

## 4. 帖子列表（thread_list）

在 `view/htm/thread_list.inc.htm` 和 `view/htm/thread_list_mod.inc.htm` 中。

> ⚠️ 本项目只有一种列表视图（`inc`），不存在 `masonry`/`timeline`/`card` 变体。

### 列表项（`view/htm/thread_list.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_list_inc_start.htm` | 列表项开始 | |
| `thread_list_inc_avatar_after.htm` | 头像后 | |
| `thread_list_inc_top_icon_before.htm` | 置顶图标前 | |
| `thread_list_inc_subject_top_after.htm` | 置顶标记后 | |
| `thread_list_inc_subject_before.htm` | 标题前 | |
| `thread_list_inc_subject_after.htm` | 标题后 | ✅ **常用**：标签徽章 |
| `thread_list_inc_filetype_icon_before.htm` | 文件类型图标前 | |
| `thread_list_inc_filetype_icon_after.htm` | 文件类型图标后 | |
| `thread_list_inc_lock_icon_before.htm` | 锁定图标前 | |
| `thread_list_inc_lock_icon_after.htm` | 锁定图标后 | |
| `thread_list_inc_end.htm` | 列表项结束 | |

### 管理模式（`view/htm/thread_list_mod.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `thread_list_mod_delete_before.htm` | 删除按钮前 | |
| `thread_list_mod_delete_after.htm` | 删除按钮后 | |
| `thread_list_mod_top_before.htm` | 置顶按钮前 | |
| `thread_list_mod_top_after.htm` | 置顶按钮后 | |
| `thread_list_mod_close_after.htm` | 关闭按钮后 | |

---

## 5. 发帖回帖（Post）

在 `view/htm/post.htm` 和 `route/post.php` 中。

### 页面级（`view/htm/post.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `post_start_init.htm` | ✅ **常用**：PHP 初始化时注入 JS 变量/编辑器扩展（如 xnx_tag 注入标签数据） |
| `post_start.htm` | 发帖页内容开始 | 发帖页顶部 |
| `post_fid_before.htm` | 版块选择前 | |
| `post_fid_select_before.htm` | 版块下拉前 | |
| `post_fid_select_after.htm` | 版块下拉后 | |
| `post_subject_before.htm` | 标题输入前 | 额外标题字段 |
| `post_subject_after.htm` | 标题输入后 | ✅ 标签输入框等 |
| `post_message_after.htm` | 内容输入后 | 编辑器下方工具 |
| `post_ref_thread_after.htm` | 引用帖子区后 | |
| `post_bottom_right.htm` | 表单底部右侧 | |
| `post_bottom_left.htm` | 表单底部左侧 | |
| `post_submit_after.htm` | 提交按钮后 | 额外操作按钮 |
| `post_sidebar_top.htm` | 侧边栏顶部 | |
| `post_sidebar_bottom.htm` | 侧边栏底部 | |
| `post_end.htm` | 发帖页内容结束 | 发帖页底部 |
| `post_js.htm` | 发帖页 JS 区 | 发帖页专属 JS |

### 路由级（`route/post.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `post_start.php` | route/post.php 开头 | |
| `post_get_post.php` | GET/POST 分发 | |
| `post_get_start.php` | 加载发帖页前 | |
| `post_post_start.php` | 发帖/回帖提交前 | ✅ **常用**：发帖前处理 |
| `post_post_end.php` | 发帖/回帖提交后 | ✅ **常用**：发帖后处理（如 xnx_tag 同步标签） |
| `post_create_htmx_reply_end.php` | htmx 回帖结束 | |
| `post_update_get_post.php` | 编辑 GET/POST 分发 | |
| `post_update_get_start.php` | 编辑页加载前 | |
| `post_update_get_end.php` | 编辑页加载后 | |
| `post_update_post_start.php` | ✅ **常用**：编辑/回帖后处理（如 xnx_tag 同步标签） |
| `post_update_post_end.php` | 编辑提交后 | |
| `post_delete_start.php` | 删除回帖前 | |
| `post_delete_middle.php` | 删除回帖中间 | |
| `post_delete_end.php` | 删除回帖后 | |
| `post_end.php` | route/post.php 结束 | |

---

## 6. 楼层（post_list）

在 `view/htm/post_list.inc.htm` 中（被 `thread.htm` / `post.htm` 循环 include），约 22 个 hook。

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `post_list_inc_start.htm` | 楼层开始 | 楼层级组件 |
| `post_list_inc_avatar_after.htm` | 头像后 | |
| `post_list_inc_username_before.htm` | 用户名前 | |
| `post_list_inc_username_after.htm` | 用户名后 | ✅ 用户徽章/等级 |
| `post_list_inc_subject_before.htm` | 楼层标题前 | |
| `post_list_inc_subject_after.htm` | 楼层标题后 | 标签/等级 |
| `post_list_inc_message_before.htm` | 楼层消息前 | |
| `post_list_inc_message_after.htm` | 楼层消息后 | 签名档/广告 |
| `post_list_inc_filelist_before.htm` | 附件列表前 | |
| `post_list_inc_filelist_after.htm` | 附件列表后 | |
| `post_list_inc_create_date_before.htm` | 发帖时间前 | |
| `post_list_inc_create_date_after.htm` | 发帖时间后 | |
| `post_list_inc_quote_before.htm` | 引用区前 | |
| `post_list_inc_quote_after.htm` | 引用区后 | |
| `post_list_inc_update_before.htm` | 编辑记录前 | |
| `post_list_inc_update_after.htm` | 编辑记录后 | |
| `post_list_inc_delete_before.htm` | 删除按钮前 | |
| `post_list_inc_delete_after.htm` | 删除按钮后 | |
| `post_list_inc_floor_before.htm` | 楼层号前 | |
| `post_list_inc_floor_after.htm` | 楼层号后 | |
| `post_list_inc_reply_delete_after.htm` | 回复删除后 | |
| `post_list_inc_end.htm` | 楼层结束 | 楼层级底部 |

---

## 7. 板块（Forum）

在 `view/htm/forum.htm`、`forum_index.htm`、`forum_followers.htm` 和 `route/forum.php`、`route/forum_index.php` 中。

### 板块页（`view/htm/forum.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `forum_start.htm` | 板块页开始 | 板块顶部组件 |
| `forum_thread_list_nav_item_after.htm` | 列表导航项后 | 额外筛选/排序 |
| `forum_threadlist_before.htm` | 帖子列表前 | |
| `forum_threadlist_after.htm` | 帖子列表后 | |
| `forum_page_before.htm` | 分页前 | |
| `forum_page_after.htm` | 分页后 | |
| `forum_mod_before.htm` | 管理操作区前 | |
| `forum_mod_after.htm` | 管理操作区后 | |
| `forum_end.htm` | 板块页结束 | 板块底部组件 |
| `forum_js.htm` | 板块页 JS 区 | 板块页专属 JS |

### 板块首页（`view/htm/forum_index.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `forum_index_forum_after.htm` | 板块列表后 | |
| `forum_index_footer.htm` | 页脚区 | |
| `forum_index_end.htm` | 板块首页结束 | |
| `forum_index_js.htm` | 板块首页 JS 区 | |

### 板块关注者（`view/htm/forum_followers.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `forum_followers_start.htm` | 关注者页开始 | |
| `forum_followers_end.htm` | 关注者页结束 | |

### 路由级（`route/forum.php`、`route/forum_index.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `forum_start.php` | route/forum.php 开头 | |
| `forum_top_list_before.php` | 置顶列表查询前 | |
| `forum_thread_list_before.php` | 帖子列表查询前 | |
| `forum_end.php` | route/forum.php 结束 | |
| `forum_index_start.php` | route/forum_index.php 开头 | |
| `forum_index_end.php` | route/forum_index.php 结束 | |

---

## 8. 用户（User）

在 `view/htm/user_create.htm`、`user_login.htm`、`user.htm`、`user.template.htm`、`user.common.template.htm`、`user_resetpw.htm`、`user_info_card.inc.htm` 等中。

### 注册（`view/htm/user_create.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_create_start.htm` | 注册页开始 | 注册页顶部组件 |
| `user_create_card_before.htm` | 注册卡片前 | |
| `user_create_title_after.htm` | 标题后 | |
| `user_create_email_after.htm` | 邮箱输入后 | |
| `user_create_username_after.htm` | 用户名输入后 | |
| `user_create_password_after.htm` | 密码输入后 | |
| `user_create_submit_before.htm` | 提交按钮前 | |
| `user_create_submit_after.htm` | 提交按钮后 | |
| `user_create_form_footer_right_start.htm` | 表单底部右侧开始 | |
| `user_create_form_footer_right_end.htm` | 表单底部右侧结束 | |
| `user_create_card_after.htm` | 注册卡片后 | |
| `user_create_end.htm` | 注册页结束 | 注册页底部 |

### 登录（`view/htm/user_login.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_login_start.htm` | 登录页开始 | 登录页顶部 |
| `user_login_title_after.htm` | 标题后 | |
| `user_login_email_after.htm` | 邮箱输入后 | |
| `user_login_password_after.htm` | 密码输入后 | |
| `user_login_submit_after.htm` | 提交按钮后 | |
| `user_login_form_footer_right_start.htm` | 表单底部右侧开始 | 第三方登录 |
| `user_login_form_footer_right_end.htm` | 表单底部右侧结束 | |
| `user_login_card_after.htm` | 登录卡片后 | |
| `user_login_end.htm` | 登录页结束 | 登录页底部 |

### 用户主页（`view/htm/user.htm`、`user.template.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_nav_profile_before.htm` | 资料导航前 | |
| `user_nav_profile_after.htm` | 资料导航后 | |
| `user_nav_end.htm` | 用户导航结束 | ✅ 多个用户子页面共用（user_post/user_favorite/user_like/user_following/user_followers） |

### 用户公共模板（`view/htm/user.common.template.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_index_start.htm` | 用户首页开始 | |
| `user_nav_before.htm` | 用户导航前 | |
| `user_nav_after.htm` | 用户导航后 | |
| `user_index_end.htm` | 用户首页结束 | |

### 重置密码（`view/htm/user_resetpw.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_resetpw_email_before.htm` | 邮箱前 | |
| `user_resetpw_email_after.htm` | 邮箱后 | |
| `user_resetpw_verify_code_before.htm` | 验证码前 | |
| `user_resetpw_verify_code_after.htm` | 验证码后 | |

### 重置完成（`view/htm/user_resetpw_complete.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_create_card_title.htm` | 卡片标题 | |

### 用户信息卡（`view/htm/user_info_card.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_profile_username_after.htm` | 用户名后 | 用户徽章 |

### 路由级（`route/user.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `user_start.php` | route/user.php 开头 | |
| `user_index_start.php` | 用户主页加载前 | |
| `user_index_end.php` | 用户主页加载后 | |
| `user_thread_start.php` | 用户帖子列表前 | |
| `user_thread_end.php` | 用户帖子列表后 | |
| `user_login_get_post.php` | 登录 GET/POST 分发 | |
| `user_login_get_start.php` | 登录页加载前 | |
| `user_login_get_end.php` | 登录页加载后 | |
| `user_login_post_start.php` | 登录提交前 | |
| `user_login_post_password_check_after.php` | 密码校验后 | |
| `user_login_post_end.php` | 登录提交后 | |
| `user_create_get_post.php` | 注册 GET/POST 分发 | |
| `user_create_get_start.php` | 注册页加载前 | |
| `user_create_get_end.php` | 注册页加载后 | |
| `user_create_post_start.php` | 注册提交前 | |
| `user_create_post_end.php` | 注册提交后 | |
| `user_logout_start.php` | 退出登录前 | |
| `user_logout_end.php` | 退出登录后 | |
| `user_resetpw_get_post.php` | 重置密码 GET/POST 分发 | |
| `user_resetpw_get_start.php` | 重置密码页加载前 | |
| `user_resetpw_get_end.php` | 重置密码页加载后 | |
| `user_resetpw_post_start.php` | 重置密码提交前 | |
| `user_resetpw_post_end.php` | 重置密码提交后 | |
| `user_sendcode_start.php` | 发送验证码前 | |
| `user_send_code_before.php` | 发送验证码前 | |
| `user_send_code_after.php` | 发送验证码后 | |
| `user_post_start.php` | 用户帖子列表前 | |
| `user_post_end.php` | 用户帖子列表后 | |
| `user_favorite_start.php` | 用户收藏前 | |
| `user_favorite_end.php` | 用户收藏后 | |
| `user_like_start.php` | 用户点赞前 | |
| `user_like_end.php` | 用户点赞后 | |
| `user_end.php` | route/user.php 结束 | |

---

## 9. 个人中心（My）

在 `view/htm/my.layout.inc.htm`、`my.common.template.htm`、`my_profile.htm`、`my_*.template.htm`、`my_notify.htm` 和 `route/my.php` 中。

### 侧边导航（`view/htm/my.layout.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `my_sidebar_nav_before.htm` | 侧边导航前 | |
| `my_sidebar_nav_profile_before.htm` | 资料导航项前 | |
| `my_sidebar_nav_item_after.htm` | 每个导航项后（多处出现） | ✅ **常用**：添加额外导航项 |
| `my_sidebar_nav_security_before.htm` | 安全导航项前 | |
| `my_sidebar_nav_avatar_before.htm` | 头像导航项前 | |
| `my_sidebar_nav_ai_before.htm` | AI 导航项前 | |
| `my_sidebar_nav_credits_before.htm` | 积分导航项前 | |
| `my_sidebar_nav_threads_before.htm` | 帖子导航项前 | |
| `my_sidebar_nav_after.htm` | 侧边导航后 | ✅ 额外导航项 |
| `my_profile_username_after.htm` | 资料页用户名后 | |

### 公共模板（`view/htm/my.common.template.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `my_common_start.htm` | 个人中心开始 | |
| `my_profile_start.htm` | 资料区开始 | ✅ 额外资料区 |
| `my_profile_end.htm` | 资料区结束 | |
| `my_security_start.htm` | 安全区开始 | |
| `my_security_end.htm` | 安全区结束 | |
| `my_avatar_start.htm` | 头像区开始 | |
| `my_avatar_end.htm` | 头像区结束 | |
| `my_ai_start.htm` | AI 区开始 | |
| `my_ai_end.htm` | AI 区结束 | |
| `my_common_end.htm` | 个人中心结束 | |
| `my_common_js.htm` | 个人中心 JS 区 | |

### 资料页（`view/htm/my_profile.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `my_profile_start.htm` | 资料页开始 | |
| `my_profile_end.htm` | 资料页结束 | |

### 子页面模板（`my_favorite.template.htm` / `my_thread.template.htm` / `my_feed.template.htm`）

这些模板共用 `my_common_start.htm` / `my_common_end.htm` / `my_common_js.htm`，各有独立导航 hook：

| Hook | 文件 | 典型用途 |
|---|---|---|
| `my_nav_favorite_before.htm` / `my_nav_favorite_after.htm` | my_favorite.template.htm | 收藏导航 |
| `my_nav_thread_before.htm` / `my_nav_thread_after.htm` | my_thread.template.htm | 帖子导航 |
| `my_nav_feed_before.htm` / `my_nav_feed_after.htm` | my_feed.template.htm | 动态导航 |
| `my_nav_notice_before.htm` / `my_nav_notice_after.htm` | my_notify.htm | 通知导航 |

### 积分页

| Hook | 文件 | 触发位置 |
|---|---|---|
| `my_credits_page_start.htm` | credits.htm | 积分页开始 |
| `my_credits_rules_page_start.htm` | credits_rules.htm | 积分规则页开始 |
| `my_notify_js.htm` | my_notify.htm | 通知页 JS 区 |

### 路由级（`route/my.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `my_start.php` | route/my.php 开头 | |
| `my_action_before.php` | action 分发前 | |
| `my_profile_post_start.php` | 资料提交前 | |
| `my_profile_post_end.php` | 资料提交后 | |
| `my_password_post_start.php` | 密码修改前 | |
| `my_password_post_end.php` | 密码修改后 | |
| `my_email_post_start.php` | 邮箱修改前 | |
| `my_email_post_end.php` | 邮箱修改后 | |
| `my_send_email_code_start.php` | 发送邮箱验证码前 | |
| `my_send_email_code_end.php` | 发送邮箱验证码后 | |
| `my_avatar_post_start.php` | 头像上传前 | |
| `my_avatar_post_save_before.php` | 头像保存前 | |
| `my_avatar_post_end.php` | 头像上传后 | |
| `my_avatar_preset_end.php` | 预设头像后 | |
| `my_ai_setting_post_start.php` | AI 设置提交前 | |
| `my_ai_setting_post_end.php` | AI 设置提交后 | |
| `my_thread_start.php` | 我的帖子前 | |
| `my_thread_end.php` | 我的帖子后 | |
| `my_end.php` | route/my.php 结束 | |

---

## 10. 后台管理（Admin）

所有 admin hook 在 `admin/view/htm/*.htm` 和 `admin/route/*.php` 中，统一 `admin_` 前缀。

### Admin Header / Footer

| Hook | 触发位置 |
|---|---|
| `admin_header_start.htm` | admin `<head>` 开始 |
| `admin_header_admin_css_after.htm` | admin CSS 后 |
| `admin_body_start.htm` | admin `<body>` 开始 |
| `admin_footer_start.htm` | admin footer 开始 |
| `admin_footer_end.htm` | admin footer 结束 |
| `admin_footer_js_before.htm` | admin JS 前 |
| `admin_footer_js_after.htm` | admin JS 后 |

### Admin 首页 / 设置 / 版块 / 用户 / 帖子 / 插件

各管理页面有 `admin_{页面}_before.htm` / `admin_{页面}_after.htm` / `admin_{页面}_list_before.htm` / `admin_{页面}_list_after.htm` 等模式。

### Admin 路由级（PHP）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `admin_index_route_case_start.php` | admin switch 最前 | 优先路由 |
| `admin_index_route_case_end.php` | ✅ **固定**：admin switch 最后 | 注册新的 admin 路由 |

#### Admin 路由 hook 全量清单（admin/route/*.php，共 190 个 hook 点）

命名规律：每个路由文件以 `admin_{模块}_start.php` / `admin_{模块}_end.php` 包裹；各 action 子模块遵循 `admin_{模块}_{action}_get_post.php`（GET/POST 分发）/ `_get_start.php` / `_get_end.php` / `_post_start.php` / `_post_end.php` 五件套。

| 所在文件 | Hook 点 | 用途 |
|---|---|---|
| admin/route/index.php | `admin_index_{start/end}.php`；`admin_index_login_{get_post/get_start/post_start/post_end}.php`；`admin_index_logout_start.php`；`admin_index_empty_{start/end}.php` | 后台登录/登出/默认页 |
| admin/route/setting.php | `admin_setting_{start/end}.php`；`admin_setting_{base/smtp/upload/nav/credits/permalink/display}_{get_post/get_start/get_end/post_start/post_end}.php` | 站点设置 7 个子模块 |
| admin/route/forum.php | `admin_forum_{start/end}.php`；`admin_forum_list_{get_post/get_start/get_end/post_start/post_loop_end/add_before/update_before/delete_before/delete_end/post_end}.php`；`admin_forum_create_{get_post/get_start/get_end/post_start/post_end}.php`；`admin_forum_update_{get_post/get_start/get_end/post_start/post_before/post_end}.php`；`admin_forum_getname_{start/end}.php`；`admin_forum_delete_{start/end}.php` | 版块列表/创建/编辑/删除 |
| admin/route/user.php | `admin_user_{start/end}.php`；`admin_user_list_{start/allow_type_after/cond_after/end}.php`；`admin_user_create_{get_post/get_start/get_end/post_start/post_end}.php`；`admin_user_update_{get_post/get_start/get_end/post_start/post_exec_before/post_end}.php`；`admin_user_delete_{start/end}.php` | 用户列表/创建/编辑/删除 |
| admin/route/thread.php | `admin_thread_{start/end}.php`；`admin_thread_list_start.php`；`admin_thread_batch_{start/for/end}.php`；`admin_thread_found_{start/end}.php` | 帖子列表/批量操作/找回 |
| admin/route/group.php | `admin_group_{start/end}.php`；`admin_group_list_{get_post/get_start/get_end/post_start/post_end}.php`；`admin_group_update_{get_post/get_start/get_end/post_start/post_end}.php` | 用户组列表/编辑 |
| admin/route/log.php | `admin_log_{start/end}.php`；`admin_log_{credits/login/operation/audit}_{start/end}.php` | 日志（积分/登录/操作/审计） |
| admin/route/attach.php | `admin_attach_{start/end}.php`；`admin_attach_list_end.php` | 附件管理 |
| admin/route/banned_ip.php | `admin_banned_ip_{start/end}.php`；`admin_banned_ip_list_{start/end}.php`；`admin_banned_ip_create_post_{start/end}.php`；`admin_banned_ip_delete_post_end.php` | IP 黑名单 |
| admin/route/banned_user.php | `admin_banned_user_{start/end}.php`；`admin_banned_user_list_{start/cond_after/end}.php`；`admin_banned_user_unban_post_end.php` | 封禁用户管理 |
| admin/route/credits_rule.php | `admin_credits_rule_{start/end}.php`；`admin_credits_rule_{global/forum}_{post_start/post_end/get_start/get_end}.php` | 积分规则（全局/版块） |
| admin/route/ai.php | `admin_ai_{start/end}.php`；`admin_ai_{providers/features/editor}_{get_post/get_start/get_end/post_start/post_end}.php`；`admin_ai_logs_{get_post/get_end}.php` | AI 配置（服务商/功能/编辑器/日志） |
| admin/route/plugin_scanner.php | `admin_plugin_scanner_{start/do_start/get_start/end}.php` | 插件扫描器 |
| admin/route/upgrade.php | `admin_upgrade_{start/get_start/end}.php` | 升级 |
| admin/route/online_upgrade.php | `admin_online_upgrade_{start/end}.php` | 在线升级 |
| admin/route/health.php | `admin_health_{start/end}.php` | 健康检查 |
| admin/route/audit.php | `admin_audit_{start/end}.php` | 审计 |

#### Admin 模板 hook 全量清单（admin/view/htm/*.htm，共 163 个 hook 点）

命名规律：每个页面有 `admin_{页面}_start.htm` / `_end.htm` / `_js.htm` 三件套，部分页面在表单字段间有 `_xxx_after.htm` 中间注入点。

| 所在文件 | Hook 点 | 用途 |
|---|---|---|
| admin/view/htm/header.inc.htm | `admin_header_{meta/bootstrap/bootstrap_bbs/admin_css}_before.htm`；`admin_header_{bootstrap/bootstrap_bbs/admin_css}_after.htm`；`admin_header_css_after.htm`；`admin_header_body_start.htm`；`admin_body_start.htm` | 后台 head/CSS 注入 |
| admin/view/htm/header_nav.inc.htm | `admin_header_nav_{start/footer}.htm` | 后台导航栏 |
| admin/view/htm/footer.inc.htm | `admin_footer_{start/footer_before/js_after/end}.htm` | 后台页脚 |
| admin/view/htm/sidebar.inc.htm | `admin_sidebar_{start/end}.htm` | 后台侧边栏 |
| admin/view/htm/index.htm | `admin_index_{start/site_stat_before/server_info_before/team_before/team_after/end/js}.htm` | 后台首页/统计/服务器信息/团队 |
| admin/view/htm/index_login.htm | `admin_index_login_{start/end/js}.htm` | 后台登录页 |
| admin/view/htm/message.htm | `admin_message_{start/end/js}.htm` | 后台消息页 |
| admin/view/htm/setting_base.htm | `admin_setting_base_{start/end/js}.htm`；`admin_setting_{sitename_before/sitename_after/sitebrief_after/announcement_after/runlevel_after/base_lang_after}.htm` | 基础设置各字段 |
| admin/view/htm/setting_smtp.htm | `admin_setting_smtp_{start/end/js}.htm` | SMTP 设置 |
| admin/view/htm/setting_upload.htm | `admin_setting_upload_{start/end/js}.htm` | 上传设置 |
| admin/view/htm/setting_nav.htm | `admin_setting_nav_{start/end/js}.htm` | 导航设置 |
| admin/view/htm/setting_credits.htm | `admin_setting_credits_{start/end/js}.htm` | 积分设置 |
| admin/view/htm/setting_permalink.htm | `admin_setting_permalink_{start/end/js}.htm` | 伪静态设置 |
| admin/view/htm/setting_display.htm | `admin_setting_display_{start/end/js}.htm` | 显示设置 |
| admin/view/htm/setting_email_template.htm | `admin_setting_email_template_{start/end}.htm` | 邮件模板 |
| admin/view/htm/setting_footer.htm | `admin_setting_footer_{start/end/js}.htm` | 页脚设置 |
| admin/view/htm/forum_list.htm | `admin_forum_list_{start/end}.htm` | 版块列表 |
| admin/view/htm/forum_create.htm | `admin_forum_create_{start/end}.htm` | 创建版块 |
| admin/view/htm/forum_update.htm | `admin_forum_update_{start/end/js}.htm`；`admin_forum_update_{forum_name/forum_rank/forum_brief/forum_announcement}_after.htm`；`admin_forum_update_privilete_{before/after}.htm`；`admin_forum_update_access_{title/input}_end.htm`；`admin_forum_update_submit_after.htm` | 编辑版块各字段 |
| admin/view/htm/user_list.htm | `admin_user_list_{start/end/js}.htm`；`admin_user_list_{option_create_ip_after/id_td_after}.htm` | 用户列表 |
| admin/view/htm/user_create.htm | `admin_user_create_{start/end/js}.htm` | 创建用户 |
| admin/view/htm/user_update.htm | `admin_user_update_{start/end/js}.htm`；`admin_user_update_group_after.htm` | 编辑用户 |
| admin/view/htm/user_ban_log.htm | `admin_user_ban_log_{start/end}.htm` | 用户封禁日志 |
| admin/view/htm/thread_list.htm | `admin_thread_list_{start/end/js}.htm` | 帖子列表 |
| admin/view/htm/thread_list.inc.htm | `admin_thread_list_inc_{start/end}.htm` | 帖子列表项 |
| admin/view/htm/thread_found.htm | `admin_thread_found_{start/end/js}.htm` | 找回帖子 |
| admin/view/htm/thread_recycle.htm | `admin_thread_recycle_{start/end/js}.htm` | 回收站 |
| admin/view/htm/group_list.htm | `admin_group_list_{start/end/js}.htm` | 用户组列表 |
| admin/view/htm/group_update.htm | `admin_group_update_{start/end/js}.htm` | 编辑用户组 |
| admin/view/htm/credits_rule.htm | `admin_credits_rule_{start/end/js}.htm`；`credits_rule_plugin_panel.htm` | 积分规则（含插件面板） |
| admin/view/htm/log_credits.htm | `admin_log_credits_{start/end}.htm` | 积分日志 |
| admin/view/htm/log_login.htm | `admin_log_login_{start/end}.htm` | 登录日志 |
| admin/view/htm/log_operation.htm | `admin_log_operation_{start/end}.htm` | 操作日志 |
| admin/view/htm/log_audit.htm | `admin_log_audit_{start/end}.htm` | 审计日志 |
| admin/view/htm/log_attach.htm | `admin_log_attach_{start/end}.htm` | 附件日志 |
| admin/view/htm/banned_ip_list.htm | `admin_banned_ip_list_{start/end}.htm` | IP 黑名单列表 |
| admin/view/htm/banned_user_list.htm | `admin_banned_user_list_{start/end}.htm`；`admin_banned_user_item_{start/end}.htm` | 封禁用户列表 |
| admin/view/htm/attach_manage.htm | `admin_attach_manage_{start/end}.htm` | 附件管理 |
| admin/view/htm/plugin_scanner.htm | `admin_plugin_scanner_{start/end}.htm` | 插件扫描器 |
| admin/view/htm/upgrade.htm | `admin_upgrade_{start/end}.htm` | 升级 |
| admin/view/htm/online_upgrade.htm | `admin_online_upgrade_{start/end}.htm` | 在线升级 |
| admin/view/htm/health.htm | `admin_health_{start/end}.htm` | 健康检查 |
| admin/view/htm/api_doc.htm | `admin_api_doc_{start/end}.htm` | API 文档 |
| admin/view/htm/api_debug.htm | `admin_api_debug_{start/end}.htm` | API 调试 |
| admin/view/htm/api_settings.htm | `admin_api_settings_{start/end}.htm` | API 设置 |
| admin/view/htm/ai_providers.htm | `admin_ai_providers_{start/end/js}.htm` | AI 服务商 |
| admin/view/htm/ai_features.htm | `admin_ai_features_{start/end/js}.htm` | AI 功能 |
| admin/view/htm/ai_editor.htm | `admin_ai_editor_{start/end/js}.htm` | AI 编辑器 |
| admin/view/htm/ai_logs.htm | `admin_ai_logs_{start/end}.htm` | AI 日志 |
| admin/view/htm/other_cache.htm | `admin_other_cache_{start/end/js}.htm` | 缓存管理 |
| admin/view/htm/other_cache_setting.htm | `admin_other_cache_setting_{start/end/js}.htm` | 缓存设置 |

---

## 11. 侧边栏与导航（Sidebar）

### 左侧边栏（`view/htm/sidebar_left.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `sidebar_left_start.htm` | 左侧边栏开始 | |
| `sidebar_left_quick_before.htm` | 快捷操作前 | |
| `sidebar_left_quick_after.htm` | 快捷操作后 | |
| `sidebar_left_forum_before.htm` | 板块列表前 | |
| `sidebar_left_forum_after.htm` | 板块列表后 | |
| `sidebar_left_end.htm` | 左侧边栏结束 | |

### 右侧边栏（`view/htm/sidebar_right.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `sidebar_right_start.htm` | 右侧边栏开始 | |
| `index_site_brief_before.htm` | 站点简介前 | |
| `index_site_brief_start.htm` | 站点简介开始 | |
| `index_site_brief_post_after.htm` | 最新帖子后 | |
| `index_site_brief_stats_after.htm` | 统计数字后 | 额外统计项 |
| `index_site_brief_end.htm` | 站点简介结束 | |
| `index_site_brief_after.htm` | 站点简介区后 | ✅ **常用**：热标签/统计组件 |
| `sidebar_hot_before.htm` | 热门帖子前 | |
| `sidebar_hot_after.htm` | 热门帖子后 | ✅ 额外侧边栏组件 |
| `sidebar_friendlink_before.htm` | 友情链接前 | |
| `sidebar_friendlink_after.htm` | 友情链接后 | |
| `sidebar_right_end.htm` | 右侧边栏结束 | |

### 用户卡片（`view/htm/sidebar_user_card.inc.htm`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `sidebar_user_card_after.htm` | 用户卡结束后 | 额外用户信息 |

---

## 12. 模型层 PHP（Model）

这些 hook 在 `model/*.func.php` 中，是纯 PHP 逻辑注入点。**hook 文件名用 `.php`**。

### Thread（帖子）— `model/thread.func.php`

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `model_thread_start.php` | 文件开始 | |
| `model_thread__create_start.php` / `model_thread__create_end.php` | 原始层 thread__create | 拦截底层创建 |
| `model_thread__update_start.php` / `model_thread__update_end.php` | 原始层更新 | |
| `model_thread__read_start.php` / `model_thread__read_end.php` | 原始层读取 | |
| `model_thread__delete_start.php` / `model_thread__delete_end.php` | 原始层删除 | |
| `model_thread__find_start.php` / `model_thread__find_end.php` | 原始层查找 | |
| `model_thread_create_start.php` | 业务层创建前 | 拦截发帖 |
| `model_thread__create_before.php` | db_insert 前 | |
| `model_thread_create_end.php` | ✅ **常用**：发帖后业务处理 |
| `model_thread_update_start.php` / `model_thread_update_end.php` | 更新 | |
| `model_thread_inc_views_start.php` / `model_thread_inc_views_end.php` | 浏览量+1 | |
| `model_thread_read_start.php` / `model_thread_read_end.php` | 读取 | |
| `model_thread_read_cache_start.php` / `model_thread_read_cache_end.php` | 缓存读取 | |
| `model_thread_delete_start.php` / `model_thread_delete_end.php` | ✅ **常用**：删除后级联清理 |
| `model_thread_delete_batch_start.php` / `model_thread_delete_batch_end.php` | 批量删除 | |
| `model_thread_soft_delete_start.php` / `model_thread_soft_delete_end.php` | 软删除 | |
| `model_thread_soft_delete_batch_start.php` / `model_thread_soft_delete_batch_end.php` | 批量软删除 | |
| `model_thread_restore_start.php` / `model_thread_restore_end.php` | 恢复 | |
| `model_thread_restore_batch_start.php` / `model_thread_restore_batch_end.php` | 批量恢复 | |
| `model_thread_find_start.php` / `model_thread_find_end.php` | 查找 | |
| `model_thread_find_by_fid_start.php` / `model_thread_find_by_fid_middle.php` / `model_thread_find_by_fid_end.php` | 按 fid 查找 | |
| `model_thread_find_by_fids_start.php` / `model_thread_find_by_fids_end.php` | 按 fids 批量查找 | |
| `model_thread_find_by_keyword_start.php` / `model_thread_find_by_keyword_end.php` | 按关键词查找 | |
| `model_thread_format_start.php` / `model_thread_format_end.php` | ✅ **常用**：格式化后添加字段 |
| `model_thread_format_last_date_start.php` / `model_thread_format_last_date_end.php` | 最后日期格式化 | |
| `model_thread_count_start.php` / `model_thread_count_end.php` | 计数 | |
| `model_thread_maxid_start.php` / `model_thread_maxid_end.php` | 最大 ID | |
| `model_thread_safe_info_start.php` / `model_thread_safe_info_end.php` | 安全信息 | |
| `model_thread_get_level_start.php` / `model_thread_get_level_end.php` | 获取层级 | |
| `model_thread_list_access_filter_start.php` / `model_thread_list_access_filter_end.php` | 列表权限过滤 | |
| `model_thread_find_by_tids_start.php` / `model_thread_find_by_tids_end.php` | 按 tids 查找 | |
| `model_thread_find_deleted_start.php` / `model_thread_find_deleted_end.php` | 查找已删除 | |
| `model_thread_end.php` | 文件结束 | |

### Post（回帖）— `model/post.func.php`

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `model_post_start.php` | 文件开始 | |
| `model_post__create_start.php` / `model_post__create_insert_before.php` / `model_post__create_end.php` | 原始层创建 | |
| `model_post__update_start.php` / `model_post__update_end.php` | 原始层更新 | |
| `model_post__read_start.php` / `model_post__read_end.php` | 原始层读取 | |
| `model_post__delete_start.php` / `model_post__delete_end.php` | 原始层删除 | |
| `model_post__find_start.php` / `model_post__find_end.php` | 原始层查找 | |
| `model_post_create_start.php` / `model_post_create_end.php` | 业务层创建 | |
| `model_post_update_start.php` / `model_post_update_end.php` | 更新 | |
| `model_post_create_post__create_before.php` | 创建前 | |
| `model_post_read_start.php` / `model_post_read_end.php` | 读取 | |
| `model_post_read_cache_start.php` / `model_post_read_cache_end.php` | 缓存读取 | |
| `model_post_delete_start.php` / `model_post_delete_end.php` | 删除后级联清理 | |
| `model_post_soft_delete_start.php` / `model_post_soft_delete_end.php` | 软删除 | |
| `model_post_restore_start.php` / `model_post_restore_end.php` | 恢复 | |
| `model_post_delete_by_tid_start.php` / `model_post_delete_by_tid_end.php` | 按主题删除 | |
| `model_post_delete_by_tids_batch_start.php` / `model_post_delete_by_tids_batch_end.php` | 批量按主题删除 | |
| `model_post_delete_by_uid_start.php` / `model_post_delete_by_uid_end.php` | 按用户删除 | |
| `model_post_find_start.php` / `model_post_find_end.php` | 查找 | |
| `model_post_find_by_tid_start.php` / `model_post_find_by_tid_end.php` | 按主题查找 | |
| `model_post_find_deleted_by_tid_start.php` / `model_post_find_deleted_by_tid_end.php` | 查找已删除 | |
| `model_post_find_deleted_start.php` / `model_post_find_deleted_end.php` | 查找已删除 | |
| `model_post_count_deleted_start.php` / `model_post_count_deleted_end.php` | 已删除计数 | |
| `model_post_restore_batch_start.php` / `model_post_restore_batch_end.php` | 批量恢复 | |
| `model_post_hard_delete_batch_start.php` / `model_post_hard_delete_batch_end.php` | 批量硬删除 | |
| `model_post_hard_delete_start.php` / `model_post_hard_delete_end.php` | 硬删除 | |
| `model_post_list_cache_delete_start.php` / `model_post_list_cache_delete_end.php` | 列表缓存清理 | |
| `model_post_count_start.php` / `model_post_count_end.php` | 计数 | |
| `model_post_maxid_start.php` / `model_post_maxid_end.php` | 最大 ID | |
| `model_post_safe_info_start.php` / `model_post_safe_info_end.php` | 安全信息 | |
| `model_post_find_by_pids_start.php` / `model_post_find_by_pids_end.php` | 按 pids 查找 | |
| `model_post_find_by_uid_with_forum_access_start.php` | 按用户+权限查找 | |
| `model_post_find_quote_chain_start.php` / `model_post_find_quote_chain_end.php` | 引用链查找 | |
| `model_post_highlight_keyword_start.php` / `model_post_highlight_keyword_end.php` | 关键词高亮 | |
| `model_post_file_list_html_start.php` / `model_post_file_list_html_end.php` | 附件列表 HTML | |
| `model_post_format_start.php` / `model_post_format_end.php` | ✅ **常用**：格式化后添加字段 |
| `post_message_fmt_start.php` / `post_message_fmt_end.php` | 消息格式化 | |
| `post_brief_start.php` / `post_brief_end.php` | 摘要 | |
| `post_quote_start.php` / `post_quote_end.php` | 引用 | |
| `model_post_list_access_filter_start.php` / `model_post_list_access_filter_end.php` | 列表权限过滤 | |
| `model_post_end.php` | 文件结束 | |

### User（用户）— `model/user.func.php`

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `model_user_start.php` | 文件开始 | |
| `model_user__create_start.php` / `model_user__create_end.php` | 原始层创建 | |
| `model_user__update_start.php` / `model_user__update_end.php` | 原始层更新 | |
| `model_user__read_start.php` / `model_user__read_end.php` | 原始层读取 | |
| `model_user__delete_start.php` / `model_user__delete_end.php` | 原始层删除 | |
| `model_user_create_start.php` / `model_user_create_end.php` | 用户创建后初始化（发欢迎消息等） |
| `model_user_update_start.php` / `model_user_update_end.php` | 更新 | |
| `model_user_read_start.php` / `model_user_read_end.php` | 读取 | |
| `model_user_read_cache_start.php` / `model_user_read_cache_end.php` | 缓存读取 | |
| `model_user_delete_start.php` / `model_user_delete_end.php` | 删除后级联清理 | |
| `model_user_find_start.php` / `model_user_find_end.php` | 查找 | |
| `model_user_read_by_email_start.php` / `model_user_read_by_email_end.php` | 按邮箱查找 | |
| `model_user_read_by_username_start.php` / `model_user_read_by_username_end.php` | 按用户名查找 | |
| `model_user_find_by_usernames_start.php` / `model_user_find_by_usernames_end.php` | 批量按用户名查找 | |
| `model_user_count_start.php` / `model_user_count_end.php` | 计数 | |
| `model_user_maxid_start.php` / `model_user_maxid_end.php` | 最大 ID | |
| `model_user_format_start.php` / `model_user_format_end.php` | ✅ **常用**：格式化后（添加头像框/等级/勋章） |
| `model_user_format_avatar_url_before.php` | 头像 URL 格式化前 | |
| `model_user_guest_start.php` / `model_user_guest_end.php` | 游客 | |
| `model_user_update_group_start.php` / `model_user_update_group_end.php` | 用户组更新 | |
| `model_user_update_group_policy_start.php` | 用户组策略 | |
| `model_user_find_by_uids_start.php` / `model_user_find_by_uids_end.php` | 批量查找 | |
| `model_user_safe_info_start.php` / `model_user_safe_info_end.php` | 安全信息 | |
| `model_user_token_get_start.php` / `model_user_token_get_end.php` | Token 获取 | |
| `model_user_token_get_do_start.php` / `model_user_token_get_do_end.php` | Token 执行 | |
| `model_user_token_set_end.php` | Token 设置 | |
| `model_user_token_clear_end.php` | Token 清除 | |
| `model_user_token_gen_start.php` / `model_user_token_gen_end.php` | Token 生成 | |
| `model_user_login_check_start.php` / `model_user_login_check_end.php` | 登录校验 | |
| `user_http_referer_start.php` / `user_http_referer_end.php` | HTTP Referer | |
| `user_auth_check_start.php` / `user_auth_check_end.php` | 权限校验 | |
| `model_user_change_password_start.php` / `model_user_change_password_end.php` | 密码修改 | |
| `model_user_change_group_start.php` / `model_user_change_group_end.php` | 用户组变更 | |
| `model_user_end.php` | 文件结束 | |

### Forum（板块）— `model/forum.func.php`

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `model_forum_start.php` | 文件开始 | |
| `model_forum__create_start.php` / `model_forum__create_end.php` | 原始层创建 | |
| `model_forum__update_start.php` / `model_forum__update_end.php` | 原始层更新 | |
| `model_forum__read_start.php` / `model_forum__read_end.php` | 原始层读取 | |
| `model_forum__delete_start.php` / `model_forum__delete_end.php` | 原始层删除 | |
| `model_forum__find_start.php` / `model_forum__find_end.php` | 原始层查找 | |
| `model_forum_create_start.php` / `model_forum_create_end.php` | 创建 | |
| `model_forum_update_start.php` / `model_forum_update_end.php` | 更新 | |
| `model_forum_read_start.php` / `model_forum_read_end.php` | 读取 | |
| `model_forum_delete_start.php` / `model_forum_delete_end.php` | 删除 | |
| `model_forum_find_start.php` / `model_forum_find_end.php` | 查找 | |
| `model_forum_format_start.php` / `model_forum_format_end.php` | 格式化 | |
| `model_forum_count_start.php` / `model_forum_count_end.php` | 计数 | |
| `model_forum_maxid_start.php` / `model_forum_maxid_end.php` | 最大 ID | |
| `model_forum_list_cache_start.php` / `model_forum_list_cache_end.php` | 列表缓存 | |
| `model_forum_list_cache_delete_start.php` / `model_forum_list_cache_delete_end.php` | 列表缓存清理 | |
| `model_forum_list_access_filter_start.php` / `model_forum_list_access_filter_end.php` | 列表权限过滤 | |
| `model_forum_safe_info_start.php` / `model_forum_safe_info_end.php` | 安全信息 | |
| `model_forum_end.php` | 文件结束 | |

### Forum Access（板块权限）— `model/forum_access.func.php`

原始层（`_` 前缀）和业务层均有 start/end 对：`model_forum_access__create/update/read/delete/find`、`model_forum_access_create/update/replace/padding/read/delete/delete_by_fid/find/find_by_fid/user/mod/format/count`。

| 关键 Hook | 触发位置 |
|---|---|
| `model_forum_access_start.php` / `model_forum_access_end.php` | 文件开始/结束 |
| `forum_is_mod_start.php` / `forum_is_mod_end.php` | 版主检测 |

### Group（用户组）— `model/group.func.php`

`model_group_start.php` / `model_group_end.php`，以及各操作的 start/end 对：`model_group__create/update/read/delete/find`、`model_group_create/update/read/delete/find`、`model_group_format`、`model_group_maxid`、`model_group_list_cache`、`model_group_list_cache_delete`。

### Attach（附件）— `model/attach.func.php`

`model_attach_start.php` / `model_attach_end.php`，以及各操作的 start/end 对：`model_attach__create/update/read/delete/find`、`model_attach_create/update/read/delete/delete_by_pid/delete_by_uid/find/find_by_pid/format/count/type/gc`、`attach_assoc_post_start/end`、`model_attach_admin_count/find/stats`。

### Post Like（点赞）— `model/post_like.func.php`

| Hook | 触发位置 |
|---|---|
| `model_post_like_start.php` / `model_post_like_end.php` | 文件开始/结束 |
| `model_post_like__create_start.php` / `model_post_like__create_end.php` | 原始层创建 |
| `model_post_like__delete_start.php` / `model_post_like__delete_end.php` | 原始层删除 |
| `model_post_like_read_start.php` / `model_post_like_read_end.php` | 读取 |
| `model_post_like_create_start.php` / `model_post_like_create_end.php` | 业务层创建 |
| `model_post_like_delete_start.php` / `model_post_like_delete_end.php` | 业务层删除 |
| `model_post_like_find_by_uid_start.php` / `model_post_like_find_by_uid_end.php` | 按用户查找 |
| `model_post_like_count_by_pid_start.php` / `model_post_like_count_by_pid_end.php` | 按帖子计数 |

### Thread Favorite（收藏）— `model/thread_favorite.func.php`

| Hook | 触发位置 |
|---|---|
| `model_thread_favorite_start.php` | 文件开始 |
| `model_thread_favorite_end.php` | 文件结束 |

### Thread Top（置顶）— `model/thread_top.func.php`

| Hook | 触发位置 |
|---|---|
| `model_thread_top_start.php` / `model_thread_top_end.php` | 文件开始/结束 |
| `model_thread_top_change_start.php` / `model_thread_top_change_end.php` | 置顶变更 |
| `model_thread_top_change_batch_start.php` / `model_thread_top_change_batch_end.php` | 批量置顶 |
| `model_thread_top_delete_start.php` / `model_thread_top_delete_end.php` | 删除 |
| `model_thread_top_find_start.php` / `model_thread_top_find_end.php` | 查找 |
| `model_thread_top_find_cache_start.php` / `model_thread_top_find_cache_end.php` | 缓存查找 |
| `model_thread_top_cache_delete_start.php` / `model_thread_top_cache_delete_end.php` | 缓存清理 |
| `model_thread_top_update_by_tid_start.php` / `model_thread_top_update_by_tid_end.php` | 按主题更新 |

### Thread Digest（精华）— `model/thread_digest.func.php`

| Hook | 触发位置 |
|---|---|
| `model_thread_digest_change_batch_start.php` / `model_thread_digest_change_batch_end.php` | 批量精华变更 |

### MyThread（我的帖子）— `model/mythread.func.php`

`model_mythread_start.php` / `model_mythread_end.php`，以及各操作的 start/end 对：`model_mythread_create/read/delete/delete_by_uid/delete_by_fid/delete_by_tid/find/find_by_uid`。

### Modlog（操作日志）— `model/modlog.func.php`

`model_modlog_start.php` / `model_modlog_end.php`，以及各操作的 start/end 对：`model_modlog__create/update/read/delete/find`、`model_modlog_create/create_batch/update/read/delete/find/format/count/maxid`。

### SMTP — `model/smtp.func.php`

`model_smtp_start.php` / `model_smtp_end.php`，以及各操作的 start/end 对：`model_smtp_create/update/read/delete/save/find/count/maxid`。

### Runtime（运行时统计）— `model/runtime.func.php`

| Hook | 触发位置 |
|---|---|
| `model_runtime_start.php` / `model_runtime_end.php` | 文件开始/结束 |
| `model_runtime_init_start.php` / `model_runtime_init_end.php` | 初始化 |
| `model_runtime_get_start.php` / `model_runtime_get_end.php` | 读取 |
| `model_runtime_set_start.php` / `model_runtime_set_end.php` | 设置 |
| `model_runtime_delete_start.php` / `model_runtime_delete_end.php` | 删除 |
| `model_runtime_save_start.php` / `model_runtime_save_end.php` | 保存 |
| `model_runtime_truncate_start.php` / `model_runtime_truncate_end.php` | 清空 |

### Check（校验）— `model/check.func.php`

| Hook | 触发位置 |
|---|---|
| `model_check_start.php` / `model_check_end.php` | 文件开始/结束 |
| `model_is_word_start.php` | 词校验 |
| `model_is_mobile_start.php` / `model_is_mobile_end.php` | 手机号校验 |
| `model_is_email_start.php` / `model_is_email_end.php` | 邮箱校验 |
| `model_is_username_start.php` / `model_is_username_end.php` | 用户名校验 |

### Cron（定时任务）— `model/cron.func.php`

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `model_cron_start.php` / `model_cron_end.php` | 文件开始/结束 | |
| `model_cron_run_start.php` / `model_cron_run_end.php` | 定时任务执行 | ✅ **常用**：注册定时任务 |
| `model_cron_5_minutes_end.php` | 5 分钟任务 | ✅ **常用**：5 分钟级定时任务 |
| `model_cron_daily_end.php` | 每日任务 | ✅ **常用**：每日定时任务 |

### Banned IP — `model/banned_ip.func.php`

| Hook | 触发位置 |
|---|---|
| `model_banned_ip_start.php` | 文件开始 |
| `model_banned_ip_end.php` | 文件结束 |

### User Profile Audit — `model/user_profile_audit.func.php`

| Hook | 触发位置 |
|---|---|
| `model_user_profile_audit_start.php` | 文件开始 |
| `model_user_profile_audit_end.php` | 文件结束 |

### Ban Log — `model/ban_log.func.php`

| Hook | 触发位置 |
|---|---|
| `model_ban_log_start.php` | 文件开始 |
| `model_ban_log_end.php` | 文件结束 |

### Admin Log — `model/admin_log.func.php`

| Hook | 触发位置 |
|---|---|
| `model_admin_log_end.php` | 文件结束 |

### User Follow — `model/user_follow.func.php`

| Hook | 触发位置 |
|---|---|
| `model_user_follow_start.php` | 文件开始 |
| `model_user_follow_end.php` | 文件结束 |

### Notify — `model/notify.func.php`

| Hook | 触发位置 |
|---|---|
| `model_notify_start.php` | 文件开始 |
| `model_notify_end.php` | 文件结束 |

### Table Day — `model/table_day.func.php`

| Hook | 触发位置 |
|---|---|
| `model_table_day_start.php` / `model_table_day_end.php` | 文件开始/结束 |
| `model_table_day_read_start.php` / `model_table_day_read_end.php` | 读取 |
| `model_table_day_maxid_start.php` / `model_table_day_maxid_end.php` | 最大 ID |
| `model_table_day_cron_start.php` / `model_table_day_cron_end.php` | 定时任务 |
| `model_table_day_rebuild_start.php` / `model_table_day_rebuild_end.php` | 重建 |

### Misc / URL / Message / HTML Safe — `model/misc.func.php`

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `model_misc_start.php` / `model_misc_end.php` | 文件开始/结束 | |
| `model_url_start.php` / `model_url_end.php` | URL 生成 | ✅ **常用**：自定义 URL 格式 |
| `model_check_runlevel_start.php` / `model_check_runlevel_end.php` | 运行级别检查 | |
| `model_message_start.php` / `model_message_end.php` | 消息提示 | |
| `model_xn_html_safe_start.php` / `model_xn_html_safe_end.php` | HTML 安全过滤 | |
| `model_xn_html_safe_new_before.php` | HTML 安全新建前 | |
| `model_xn_html_safe_parse_before.php` | HTML 安全解析前 | |

### Route（路由表）— `model/route.func.php`

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `model_route_start.php` | 文件开始 | |
| `model_route_table_end.php` | ✅ **常用**：路由表数组定义后、`return $routes` 前（model/route.func.php 第 251 行） | 扩展路由表，注册插件自定义路由（xnx_tag 插件用此 hook 注册标签路由） |
| `model_route_func_end.php` | 路由函数结束 | |
| `model_route_end.php` | 文件结束 | |

---

## 13. 其它页面

### 搜索（`view/htm/search.htm`、`route/search.php`）

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `search_start.htm` | 搜索页开始 | |
| `search_main_start.htm` | 搜索主区开始 | |
| `search_threadlist_before.htm` | 搜索结果列表前 | |
| `search_threadlist_after.htm` | 搜索结果列表后 | |
| `search_main_end.htm` | 搜索主区结束 | |
| `search_end.htm` | 搜索页结束 | |
| `search_js.htm` | 搜索页 JS 区 | |
| `search_start.php` | route/search.php 开头 | |
| `search_keyword_after.php` | 关键词解析后 | |
| `search_end.php` | route/search.php 结束 | |

### 错误页（`view/htm/error.htm`）

| Hook | 触发位置 |
|---|---|
| `error_start.htm` | 错误页开始 |
| `error_icon_before.htm` / `error_icon_after.htm` | 图标前/后 |
| `error_number_before.htm` / `error_number_after.htm` | 错误号前/后 |
| `error_title_before.htm` / `error_title_after.htm` | 标题前/后 |
| `error_message_before.htm` / `error_message_after.htm` | 消息前/后 |
| `error_buttons_before.htm` / `error_buttons_after.htm` | 按钮前/后 |
| `error_end.htm` | 错误页结束 |
| `error_js.htm` | 错误页 JS 区 |

### 消息页（`view/htm/message.htm`）

| Hook | 触发位置 |
|---|---|
| `message_start.htm` | 消息页开始 |
| `message_end.htm` | 消息页结束 |
| `message_js.htm` | 消息页 JS 区 |

### 分页（`view/htm/pagination.inc.htm`、`pagination_infinite.inc.htm`）

| Hook | 触发位置 |
|---|---|
| `pagination_before.htm` / `pagination_after.htm` | 普通分页前/后 |
| `pagination_infinite_before.htm` / `pagination_infinite_after.htm` | 无限滚动分页前/后 |

### 排行榜（`view/htm/rank.htm`、`route/rank.php`）

| Hook | 触发位置 |
|---|---|
| `rank_start.htm` | 排行榜页开始 |
| `rank_header.htm` | 排行榜头部 |
| `rank_footer.htm` | 排行榜页脚 |
| `rank_end.htm` | 排行榜页结束 |
| `rank_start.php` | route/rank.php 开头 |
| `rank_end.php` | route/rank.php 结束 |

### 主题切换（`view/htm/theme.htm`、`route/theme.php`）

| Hook | 触发位置 |
|---|---|
| `theme_start.htm` | 主题页开始 |
| `theme_end.htm` | 主题页结束 |
| `theme_start.php` | route/theme.php 开头 |
| `theme_end.php` | route/theme.php 结束 |

### 封禁列表（`view/htm/banned.htm`、`route/banned.php`）

| Hook | 触发位置 |
|---|---|
| `banned_start.htm` | 封禁页开始 |
| `banned_left_before.htm` | 左侧前 |
| `banned_tab_item_after.htm` | 标签项后 |
| `banned_current_body_before.htm` / `banned_current_body_after.htm` | 当前封实体前/后 |
| `banned_current_item_start.htm` / `banned_current_item_end.htm` | 当前封禁项 |
| `banned_recent_body_before.htm` / `banned_recent_body_after.htm` | 近期封实体前/后 |
| `banned_recent_item_start.htm` / `banned_recent_item_end.htm` | 近期封禁项 |
| `banned_bottom.htm` | 封禁页底部 |
| `banned_end.htm` | 封禁页结束 |
| `banned_js.htm` | 封禁页 JS 区 |
| `banned_start.php` | route/banned.php 开头 |
| `banned_list_display.php` | 列表显示 |
| `banned_end.php` | route/banned.php 结束 |

### 封禁通知（`view/htm/banned_notice.htm`）

| Hook | 触发位置 |
|---|---|
| `banned_notice_start.htm` | 通知页开始 |
| `banned_notice_icon_before.htm` / `banned_notice_icon_after.htm` | 图标前/后 |
| `banned_notice_title_before.htm` / `banned_notice_title_after.htm` | 标题前/后 |
| `banned_notice_badge_before.htm` / `banned_notice_badge_after.htm` | 徽章前/后 |
| `banned_notice_reason_before.htm` / `banned_notice_reason_after.htm` | 原因前/后 |
| `banned_notice_expire_before.htm` / `banned_notice_expire_after.htm` | 到期前/后 |
| `banned_notice_countdown_before.htm` / `banned_notice_countdown_after.htm` | 倒计前/后 |
| `banned_notice_appeal_before.htm` / `banned_notice_appeal_after.htm` | 申诉前/后 |
| `banned_notice_actions_before.htm` / `banned_notice_actions_after.htm` | 操作前/后 |
| `banned_notice_end.htm` | 通知页结束 |
| `banned_notice_js.htm` | 通知页 JS 区 |

### 更多页（`view/htm/more.htm`）

| Hook | 触发位置 |
|---|---|
| `more_start.htm` | 更多页开始 |
| `more_discover_items_after.htm` | 发现项后 |
| `more_end.htm` | 更多页结束 |

### Sitemap（`route/sitemap.php`）

| Hook | 触发位置 |
|---|---|
| `sitemap_end.php` | sitemap 生成结束 |

### AI（`route/ai.php`）

| Hook | 触发位置 |
|---|---|
| `route_ai_start.php` | route/ai.php 开头 |
| `route_ai_chat_start.php` | AI 对话开始 |

### Attach（`route/attach.php`）

| Hook | 触发位置 |
|---|---|
| `attach_start.php` | route/attach.php 开头 |
| `attach_create_start.php` | 上传开始 |
| `attach_create_save_before.php` | 保存前 |
| `attach_create_end.php` | 上传结束 |
| `attach_delete_start.php` / `attach_delete_end.php` | 删除 |
| `attach_read_start.php` | 读取开始 |
| `attach_read_output_before.php` | 输出前 |
| `attach_download_start.php` | 下载开始 |
| `attach_output_before.php` | 输出前 |
| `attach_download_readfile_before.php` | 读文件前 |
| `attach_download_location_before.php` | 定位前 |
| `attach_end.php` | route/attach.php 结束 |

---

## 14. 入口与路由（index.inc.php）

在 `index.inc.php` 中，是全局路由分发入口。

| Hook | 触发位置 | 典型用途 |
|---|---|---|
| `index_inc_start.php` | ✅ **常用**：入口最开始（session/Service 加载后） | 全局预处理 |
| `user_ban_check.php` | ✅ **常用**：用户封禁检查（多处复用：index.inc.php/route/user.php/route/post.php/route/thread.php/route/my.php） | 拦截封禁用户 |
| `banned_ip_check.php` | IP 封禁检查（route/thread.php） | 拦截封禁 IP |
| `index_inc_route_before.php` | 路由分发前 | |
| `index_route_case_start.php` | switch 最前 | 优先路由拦截 |
| `index_route_case_end.php` | ✅ **固定**：switch 最后 | 注册新路由（`case 'xxx': include ...`） |
| `index_route_case_default.php` | switch `default:` 分支内、`http_404()` 前（index.inc.php 第 467 行） | 在未匹配任何 case 的路由处理前注入代码，用于自定义未匹配路由的逻辑 |
| `index_inc_end.php` | 入口结束 | 全局后处理 |

---

### 同一 hook 名在多处触发的语义

部分 hook 名会在多个源码文件中重复出现（如 `user_ban_check.php` 在 `index.inc.php`、`route/user.php`、`route/post.php`、`route/thread.php`、`route/my.php` 五处触发；`banned_ip_check.php` 在 `route/user.php` login/create、`route/thread.php` create 等处触发）。**同一 hook 名只需注册一次**——编译时该 hook 文件的内容会被物理拼进所有触发点执行，不是每处独立注册。插件作者需注意此类 hook 的副作用：hook 逻辑会在每个触发点都运行一次，应避免重复计数、重复写入等操作（如 `user_ban_check.php` 会在多个路由检查点执行，封禁判断只需做一次，勿在 hook 内重复累加计数）。

---

## 使用 Hook 的快速决策树

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
└─ 在后台加管理页
    ├─ admin_index_route_case_end.php（注册 admin 路由）
    └─ admin_*_after.htm（各后台页注入）
```

---

> **提示**：本文档基于源码 `// hook` 标记扫描生成。模板中的 `<!--{hook xxx}-->` 会在编译时被 `preg_replace` 归一化为 `// hook xxx`（见 `model/plugin.func.php`）。如需查找特定 hook，可在 `view/htm/`、`route/`、`model/` 目录中搜索 `hook` 关键词。
