# Hook 点全量目录（精简分类版）

> 完整版见项目 `docs/03-hooks-catalog.md`。此为 AI 快速查表。

---

## 全局/布局

| Hook | 用途 |
|---|---|
| `header_start.htm` | `<head>` 开始 |
| `header_meta_before/after.htm` | Meta 标签 |
| `header_link_before/after.htm` | ✅ CSS 注入 |
| `header_body_start.htm` | `<body>` 后 |
| `body_start/end.htm` | 全局首尾 |
| `footer_js_before/after.htm` | ✅ JS 注入 |
| `footer_start/end.htm` | 页脚 |

## 首页

| Hook | 用途 |
|---|---|
| `index_start/end.htm` | 首页首尾 |
| `index_site_brief_before/after.htm` | ✅ 站点简介组件 |
| `index_threadlist_before/after.htm` | 帖子列表首尾 |

## 帖子详情（thread）

| Hook | 用途 |
|---|---|
| `thread_start/end.htm` | 页面首尾 |
| `thread_subject_before/after.htm` | ✅ 标题后（标签/徽章） |
| `thread_action_before/after.htm` | 操作按钮 |
| `thread_message_before/after.htm` | 楼主消息区 |

## 帖子列表（4 种视图：inc/masonry/timeline/card）

| Hook | 用途 |
|---|---|
| `thread_list_{view}_subject_before/after.htm` | ✅ 标题后（每种视图都要注册） |
| `thread_list_{view}_message_before/after.htm` | 摘要 |
| `thread_list_{view}_date_before/after.htm` | 日期 |
| `thread_list_{view}_user_before/after.htm` | 用户信息 |
| `thread_list_{view}_forum_before/after.htm` | 板块 |

## 发帖/回帖（post）

| Hook | 用途 |
|---|---|
| `post_start/end.htm` | 页面首尾 |
| `post_start_init.htm` | ✅ 编辑器数据注入 |
| `post_subject_before/after.htm` | ✅ 标题输入后（标签框） |
| `post_message_before/after.htm` | 编辑器 |
| `post_action_before/after.htm` | 提交按钮 |

## 楼层（post_list）

| Hook | 用途 |
|---|---|
| `post_list_inc_start/end.htm` | 楼层首尾 |
| `post_subject_before/after.htm` | ✅ 楼层标题后 |
| `post_message_before/after.htm` | 消息后（签名档） |
| `post_user_before/after.htm` | ✅ 用户旁（勋章/等级） |
| `post_action_before/after.htm` | 操作按钮 |

## 板块（forum）

| Hook | 用途 |
|---|---|
| `forum_start/end.htm` | 页面首尾 |
| `forum_index_before/after.htm` | 板块内容 |
| `forum_threadlist_before/after.htm` | 帖子列表 |

## 用户（user）

| Hook | 用途 |
|---|---|
| `user_create_form_before/after.htm` | 注册表单 |
| `user_create_submit_before/after.htm` | 注册提交（第三方登录） |
| `user_login_form_before/after.htm` | 登录表单 |
| `user_login_submit_before/after.htm` | 登录提交（第三方登录） |
| `user_profile_start/end.htm` | 资料页 |
| `model_user_format_end.php` | ✅ 格式化后（自定义字段） |
| `model_user_create_end.php` | 注册后处理 |
| `model_user_login_after.php` | 登录后处理 |

## 个人中心（my）

| Hook | 用途 |
|---|---|
| `my_start/end.htm` | 首尾 |
| `my_nav_before/after.htm` | ✅ 额外导航 |
| `my_profile_before/after.htm` | ✅ 额外资料区 |

## 后台（admin）

| Hook | 用途 |
|---|---|
| `admin_header_start.htm` / `admin_body_start.htm` | Admin 头部 |
| `admin_footer_js_before/after.htm` | Admin JS |
| `admin_index_start/end.htm` | 首页 |
| `admin_setting_before/after.htm` | 设置 |
| `admin_forum_before/after.htm` | 版块管理 |
| `admin_user_before/after.htm` | 用户管理 |
| `admin_thread_before/after.htm` | 帖子管理 |
| `admin_plugin_before/after.htm` | 插件管理 |

## 模型层 PHP（Model）

| Hook | 用途 |
|---|---|
| `model_inc_file.php` | ✅ 注册 Service |
| `model_thread_create_end.php` | 发帖后 |
| `model_thread_delete_end.php` | ✅ 删帖级联 |
| `model_thread_format_end.php` | 格式化后 |
| `model_post_format_end.php` | 回帖格式化后 |
| `model_user_format_end.php` | 用户格式化后 |
| `model_forum_format_end.php` | 板块格式化后 |
| `model_user_create_end.php` | 注册后 |
| `model_user_login_after.php` | 登录后 |

## 特殊固定 Hook

| Hook | 用途 |
|---|---|
| `model_inc_file.php` | 拼进 model.inc.php（每行逗号结尾的路径） |
| `index_route_case_end.php` | 拼进 index.inc.php switch（`case 'xxx': include ...`） |
| `admin_index_route_case_end.php` | 拼进 admin switch（注册 admin 路由） |
| `lang_zh_cn_bbs.php` | 语言扩展（严格 `$lang['key'] = value;` 格式） |

## 其它

| Hook | 用途 |
|---|---|
| `search_start/end.htm` | 搜索页 |
| `error_start/end.htm` | 错误页 |
| `message_start/end.htm` | 消息页 |
| `pagination_before/after.htm` | 分页组件 |
| `sidebar_left/right_before/after.htm` | 侧边栏 |
| `sidebar_hot_before/after.htm` | ✅ 热门组件 |
