# 数据访问层（model/）

> 31 个 `*.func.php` 文件，每个文件对应一张数据库表或一个业务实体的 CRUD 操作

## 目录结构概览

```
model/
├── admin_log.func.php            # 管理员操作日志
├── attach.func.php               # 帖子附件
├── check.func.php                # 数据格式校验工具
├── cron.func.php                 # 计划任务调度
├── email_log.func.php            # 邮件发送日志
├── form.func.php                 # 表单 HTML 生成工具
├── forum.func.php                # 版块/分区
├── forum_access.func.php         # 版块访问权限
├── forum_follow.func.php         # 版块关注
├── group.func.php                # 用户组
├── kv.func.php                   # 通用键值存储与站点设置
├── misc.func.php                 # 通用工具（URL/消息/HTML安全）
├── modlog.func.php               # 版主操作日志
├── mythread.func.php             # 用户参与主题
├── notify.func.php               # 统一通知系统
├── plugin.func.php               # 插件本地与数据库管理
├── post.func.php                 # 帖子楼层/回帖
├── post_like.func.php            # 回帖点赞
├── queue.func.php                # MySQL 模拟队列
├── route.func.php                 # 集中式路由表与命名快捷函数
├── runtime.func.php              # 全站运行时统计
├── session.func.php              # 自定义 Session 处理器
├── smtp.func.php                 # SMTP 配置
├── table_day.func.php            # 每日最大ID统计
├── thread.func.php               # 主题
├── thread_digest.func.php        # 主题精华
├── thread_favorite.func.php      # 主题收藏
├── thread_top.func.php           # 主题置顶
├── user.func.php                 # 用户
├── user_follow.func.php          # 用户关注关系
└── user_profile_audit.func.php   # 用户资料审核
```

## 文件用途说明

### admin_log.func.php
- **用途**：操作 `admin_log` 表，记录后台管理员的操作日志
- **关键函数**：
  - `admin_log_create($action, $target_type, $target_ids, $detail)` — 写入一条管理员操作日志

### attach.func.php
- **用途**：操作 `attach` 表，管理帖子附件的 CRUD、物理文件清理及孤儿附件回收
- **关键函数**：
  - `attach_create($arr)` — 新增附件记录
  - `attach_update($aid, $arr)` — 更新附件
  - `attach_read($aid)` — 读取单条附件（含格式化）
  - `attach_delete($aid)` — 删除附件（同时删除物理文件和缩略图）
  - `attach_delete_by_pid($pid)` — 删除某帖的所有附件
  - `attach_delete_by_uid($uid)` — 分批删除某用户的所有附件
  - `attach_find($cond, $orderby, $page, $pagesize)` — 查询附件列表
  - `attach_find_by_pid($pid)` — 获取帖子附件（区分图片/文件列表）
  - `attach_count($cond)` — 统计附件数量
  - `attach_assoc_post($pid)` — 将 session 临时文件关联到帖子并清理孤儿
  - `attach_gc()` — 清理 tmp 目录过期临时文件
  - `attach_admin_count($filter)` / `attach_admin_find($filter, $orderby, $page, $pagesize)` — 后台筛选查询
  - `attach_admin_stats()` — 附件统计（总数、占用空间、孤儿数等）
  - `attach_admin_orphan_ids($page, $pagesize)` — 获取孤儿附件 ID
  - `attach_admin_delete_orphans()` — 批量删除孤儿附件
  - `attach_admin_force_delete($aid)` — 强制删除单个附件
  - `attach_type($name, $types)` — 根据文件名推导附件类型
  - `attach_thumb_path($filename)` — 推导缩略图相对路径
  - `format_filesize($bytes)` — 格式化文件大小

### check.func.php
- **用途**：数据格式校验工具，校验用户名/密码/邮箱/手机号等输入合法性
- **关键函数**：
  - `is_word($s)` — 校验是否仅含 word 字符
  - `is_mobile($mobile, &$err)` — 校验手机号格式
  - `is_email($email, &$err)` — 校验邮箱格式
  - `is_username($username, &$err)` — 校验用户名格式（支持中朝文）
  - `is_password($password, &$err)` — 校验密码长度
  - `password_md5(&$password)` — 已废弃函数（保留签名以兼容第三方插件）

### cron.func.php
- **用途**：计划任务调度，按 5 分钟/每日两个周期触发统计重置、垃圾清理等任务
- **关键函数**：
  - `cron_run($force)` — 执行计划任务（5 分钟任务 + 每日 0 点任务）

### email_log.func.php
- **用途**：操作 `email_log` 表，记录邮件发送结果日志
- **关键函数**：
  - `email_log_create($arr)` — 创建邮件日志（自动补 create_date/ip）
  - `email_log_read($logid)` — 读取单条邮件日志
  - `email_log_find($cond, $orderby, $page, $pagesize)` — 查询邮件日志列表
  - `email_log_count($cond)` — 统计邮件日志数量
  - `email_log_delete($logid)` — 删除单条邮件日志
  - `email_log_clean($days)` — 清理指定天数前的日志

### form.func.php
- **用途**：表单 HTML 生成工具，用于后台渲染 radio/checkbox/select/input 等控件
- **关键函数**：
  - `form_radio_yes_no($name, $checked)` — 渲染是/否单选
  - `form_radio($name, $arr, $checked)` — 渲染单选按钮组
  - `form_checkbox($name, $checked, $txt, $val)` — 渲染单个复选框
  - `form_multi_checkbox($name, $arr, $checked)` — 渲染多选复选框组
  - `form_select($name, $arr, $checked, $id)` — 渲染下拉选择框
  - `form_options($arr, $checked)` — 渲染 option 列表
  - `form_text($name, $value, $width, $holdplacer)` — 渲染文本输入框
  - `form_hidden($name, $value)` — 渲染隐藏域
  - `form_textarea($name, $value, $width, $height)` — 渲染多行文本框
  - `form_password($name, $value, $width)` — 渲染密码输入框
  - `form_time($name, $value, $width)` — 渲染时间输入框

### forum.func.php
- **用途**：操作 `forum` 表，管理版块/分区 CRUD 及版块列表缓存
- **关键函数**：
  - `forum_create($arr)` — 新增版块
  - `forum_update($fid, $arr)` — 更新版块
  - `forum_read($fid)` — 读取版块（优先读缓存）
  - `forum_delete($fid)` — 删除版块（含子版块解绑、主题清理、权限删除）
  - `forum_find($cond, $orderby, $page, $pagesize)` — 查询版块列表
  - `forum_count($cond)` — 统计版块数量
  - `forum_maxid()` — 获取版块最大 ID
  - `forum_list_cache()` — 获取版块列表缓存（含权限批量加载）
  - `forum_list_cache_delete()` — 失效版块列表缓存
  - `forum_list_access_filter($forumlist, $gid, $allow)` — 按权限过滤版块列表
  - `forum_filter_moduid($moduids)` — 过滤版主 UID（仅保留真实版主）
  - `forum_safe_info($forum)` — 版块信息脱敏
  - `forum_find_categories()` — 查询所有分区
  - `forum_find_by_fup($fup)` — 查询某分区下的版块

### forum_access.func.php
- **用途**：操作 `forum_access` 表，管理各用户组对版块的访问/发帖/下载等权限
- **关键函数**：
  - `forum_access_create($arr)` — 新增版块权限
  - `forum_access_update($fid, $gid, $arr)` — 更新版块权限
  - `forum_access_replace($fid, $gid, $arr)` — 不存在则创建，存在则更新
  - `forum_access_padding($gid, $fill)` — 按用户组对所有启用权限的版块补建/删除
  - `forum_access_read($fid, $gid)` — 读取单条权限
  - `forum_access_delete($fid, $gid)` — 删除单条权限
  - `forum_access_delete_by_fid($fid)` — 删除某版块的所有权限
  - `forum_access_find($cond, $orderby, $page, $pagesize)` — 查询权限列表
  - `forum_access_find_by_fid($fid)` — 按版块查询权限（带静态缓存）
  - `forum_access_user($fid, $gid, $access)` — 普通用户权限判断
  - `forum_access_mod($fid, $gid, $access)` — 版主权限判断
  - `forum_is_mod($fid, $gid, $uid)` — 判断是否为版主
  - `forum_access_count($cond)` — 统计权限数量

### forum_follow.func.php
- **用途**：操作 `forum_follow` 表，管理用户对版块的关注关系
- **关键函数**：
  - `forum_follow_create($uid, $fid)` — 关注版块（INSERT IGNORE + 计数校准）
  - `forum_follow_delete($uid, $fid)` — 取消关注
  - `forum_follow_read($uid, $fid)` — 查询是否已关注
  - `forum_follow_count($fid)` — 统计版块关注数
  - `forum_follow_find_by_uid($uid, $page, $pagesize)` — 查询用户关注的版块
  - `forum_follow_find_by_fid($fid, $page, $pagesize)` — 查询关注某版块的用户
  - `forum_follow_check_batch($uid, $fids)` — 批量检查关注状态

### group.func.php
- **用途**：操作 `group` 表，管理用户组及用户组列表缓存
- **关键函数**：
  - `group_create($arr)` — 新增用户组（自动填充版块权限）
  - `group_update($gid, $arr)` — 更新用户组
  - `group_read($gid)` — 读取用户组
  - `group_delete($gid)` — 删除用户组（同步删除版块权限）
  - `group_find($cond, $orderby, $page, $pagesize)` — 查询用户组列表
  - `group_count($cond)` — 统计用户组数量
  - `group_maxid()` — 获取用户组最大 ID
  - `group_name($gid)` — 获取用户组名
  - `group_icon($group)` — 获取用户组图标类名
  - `group_list_cache()` — 获取用户组列表缓存
  - `group_list_cache_delete()` — 失效用户组列表缓存

### kv.func.php
- **用途**：操作 `kv` 表，提供通用持久化键值存储及站点设置（setting）封装
- **关键函数**：
  - `kv_get($k)` — 读取键值（带内存缓存，超长 key 自动 md5）
  - `kv_set($k, $v, $life)` — 写入键值（replace 语义）
  - `kv_delete($k)` — 删除键值
  - `kv_cache_get($k)` — 优先读缓存，回退到 kv 表
  - `kv_cache_set($k, $v, $life)` — 同时写缓存和 kv 表
  - `kv_cache_delete($k)` — 同时清缓存和 kv 表
  - `setting_get($k)` — 读取站点设置项
  - `setting_set($k, $v)` — 写入站点设置项
  - `setting_delete($k)` — 删除站点设置项

### misc.func.php
- **用途**：通用工具函数集，包含 URL 生成、消息响应、HTML 安全过滤、文件锁等
- **关键函数**：
  - `url($url, $extra)` — 根据 `url_rewrite_on` 配置生成不同风格的 URL
  - `check_runlevel()` — 检测站点运行级别并拦截访问
  - `htmx_trigger($event_name, $data)` — 触发 htmx HX-Trigger 事件
  - `is_htmx_request()` — 检测是否为 htmx 请求
  - `message($code, $message, $extra)` — 统一消息响应（支持 htmx/API/PRG/普通）
  - `error_page($code, $message)` — 渲染错误页面（404/403/500）
  - `xn_lock_start($lockname, $life)` — 文件锁上锁
  - `xn_lock_end($lockname)` — 释放文件锁
  - `xn_html_safe($doc, $arg)` — HTML 安全过滤（白名单标签/属性/CSS）

### modlog.func.php
- **用途**：操作 `modlog` 表，记录版主的删帖/置顶/移动等操作日志
- **关键函数**：
  - `modlog_create($arr)` — 写入版主操作日志
  - `modlog_create_batch($records)` — 批量写入日志（单条 SQL 多行 INSERT）
  - `modlog_update($logid, $arr)` — 更新日志
  - `modlog_read($logid)` — 读取单条日志
  - `modlog_delete($logid)` — 删除单条日志
  - `modlog_find($cond, $orderby, $page, $pagesize)` — 查询日志列表
  - `modlog_count($cond)` — 统计日志数量
  - `modlog_maxid()` — 获取日志最大 ID

### mythread.func.php
- **用途**：操作 `mythread` 表，记录用户参与（发帖/回帖）的主题关系
- **关键函数**：
  - `mythread_create($uid, $tid)` — 新增用户主题记录（INSERT IGNORE，匿名跳过）
  - `mythread_read($uid, $tid)` — 查询是否参与
  - `mythread_delete($uid, $tid)` — 删除单条
  - `mythread_delete_by_uid($uid)` — 按用户删除
  - `mythread_delete_by_fid($fid)` — 按版块删除
  - `mythread_delete_by_tid($tid)` — 按主题删除
  - `mythread_find($cond, $orderby, $page, $pagesize)` — 查询列表
  - `mythread_find_by_uid($uid, $page, $pagesize)` — 查询用户参与的主题（批量查 thread）

### notify.func.php
- **用途**：操作 `notify` 表，统一通知系统（点赞/回复/收藏/关注/系统/公告/审核等）
- **关键函数**：
  - `notify_create($uid, $from_uid, $type, $tid, $pid, $content, $extra)` — 创建通知（含 30 秒防抖）
  - `notify_create_batch($records)` — 批量创建通知（单 SQL + 批量更新计数）
  - `notify_read($nid)` — 读取单条通知
  - `notify_find_by_uid($uid, $page, $pagesize)` — 查询用户通知列表
  - `notify_find_by_uid_type($uid, $type, $page, $pagesize)` — 按类型查询通知
  - `notify_find_latest($uid, $pagesize)` — 查询最新 N 条通知（下拉菜单用）
  - `notify_find_announcements($pagesize)` — 查询全局公告
  - `notify_count_unread($uid)` — 统计未读数
  - `notify_count_by_uid($uid)` — 统计通知总数
  - `notify_mark_read($nid)` — 标记单条已读
  - `notify_mark_all_read($uid)` — 全部标记已读
  - `notify_delete($nid)` — 删除单条
  - `notify_delete_by_uid($uid)` — 按用户删除
  - `notify_delete_by_tid($tid)` — 按主题删除
  - `notify_preload($notifylist)` — 批量预加载通知关联的用户/帖子/回帖数据

### plugin.func.php
- **用途**：插件本地文件 + `plugin` 数据库表的管理，含安装/启用/钩子编译等
- **关键函数**：
  - `plugin_init()` — 初始化本地插件列表
  - `plugin_dependencies($dir)` — 检查插件依赖（返回缺失依赖）
  - `plugin_by_dependencies($dir)` — 返回被依赖的插件列表
  - `plugin_enable($dir)` / `plugin_disable($dir)` — 启用/禁用插件
  - `plugin_install($dir)` / `plugin_unstall($dir)` — 安装/卸载插件
  - `plugin_install_all()` / `plugin_unstall_all()` — 批量安装/卸载
  - `plugin_paths_enabled()` — 获取已启用插件路径列表
  - `plugin_read_by_dir($dir)` — 读取插件本地信息（含 conf.json）
  - `plugin_is_theme($dir, $conf)` — 判断是否为模板/风格插件
  - `plugin_db_get($dir)` — 获取插件数据库记录
  - `plugin_db_get_all()` — 获取所有插件数据库记录
  - `plugin_db_init($dir, $conf)` — 初始化插件数据库记录
  - `plugin_db_set_installed($dir, $installed)` — 更新安装状态
  - `plugin_db_set_enable($dir, $enable)` — 更新启用状态
  - `plugin_db_init_all()` — 初始化所有插件数据（升级用）
  - `plugin_read_by_dir_with_db($dir)` — 合并数据库和 conf.json 数据
  - `plugin_clear_tmp_dir()` — 清空插件临时目录

### post.func.php
- **用途**：操作 `post` 表，管理帖子楼层/回帖 CRUD、统计、格式化、引用、附件关联
- **关键函数**：
  - `post_create($arr, $fid, $gid)` — 创建回帖（含统计更新、附件关联、用户组更新）
  - `post_update($pid, $arr, $tid)` — 编辑回帖
  - `post_read($pid)` — 读取单条回帖（含格式化）
  - `post_read_cache($pid)` — 静态缓存读取
  - `post_delete($pid)` — 删除单条回帖（含统计回退、附件清理）
  - `post_delete_by_tid($tid)` — 删除某主题所有回帖
  - `post_delete_by_tids_batch($tids)` — 批量删除多主题回帖（合并查询）
  - `post_delete_by_uid($uid)` — 删除某用户所有回帖
  - `post_find($cond, $orderby, $page, $pagesize)` — 查询回帖列表
  - `post_find_by_tid($tid, $page, $pagesize, $orderby)` — 按主题分页查询
  - `post_find_by_pids($pids, $order)` — 按 pid 批量查询
  - `post_find_quote_chain($quotepid, $max_depth)` — 查找引用链（防循环）
  - `post_count($cond)` — 统计回帖数量
  - `post_maxid()` — 获取回帖最大 ID
  - `post_safe_info($post)` — 回帖脱敏
  - `post_message_fmt(&$arr, $gid)` — 写入时格式化 message
  - `post_brief($s, $len)` — 内容简介
  - `post_quote($quotepid)` — 生成引用块 HTML
  - `post_list_access_filter(&$postlist, $gid)` — 按权限过滤回帖列表
  - `post_highlight_keyword($str, $k)` — 关键词高亮
  - `post_file_list_html($filelist, $include_delete, $imagelist, $videolist)` — 附件下载卡片 HTML
  - `user_post_message_format(&$s)` — 用户回帖内容摘要

### post_like.func.php
- **用途**：操作 `post_like` 表，管理用户对回帖的点赞关系
- **关键函数**：
  - `post_like_create($uid, $tid, $pid)` — 点赞（INSERT IGNORE + 计数 + 通知）
  - `post_like_delete($uid, $tid, $pid)` — 取消点赞
  - `post_like_read($uid, $pid)` — 查询点赞状态
  - `post_like_read_batch($uid, $pids)` — 批量查询点赞状态
  - `post_like_find_by_uid($uid, $page, $pagesize)` — 查询用户点赞列表
  - `post_like_count_by_pid($pid)` — 统计某帖点赞数

### queue.func.php
- **用途**：操作 `queue` 表，基于 MySQL 模拟的简单队列（顺序不严格保证）
- **关键函数**：
  - `queue_find($queueid, $page, $pagesize)` — 提取队列全部值
  - `queue_push($queueid, $v, $expiry)` — 入队
  - `queue_pop($queueid)` — 出队并删除
  - `queue_delete($queueid, $v)` — 删除队列中的某个值
  - `queue_destory($queueid)` — 销毁整个队列
  - `queue_count($queueid)` — 队列长度
  - `queue_gc()` — 清理过期数据

### route.func.php
- **用途**：集中式路由表与命名快捷函数，替代模板中硬编码的 `url("xxx-$id")` 写法
- **关键函数**：
  - `route_table()` — 获取路由表（带静态缓存，可通过 hook 扩展）
  - `route_url($name, $args, $query)` — 通用路由 URL 生成
  - 命名快捷函数（按模块分组）：
    - 帖子：`thread_url/thread_page_url/thread_create_url/thread_update_url/thread_delete_url/thread_like_url/thread_unlike_url/thread_favorite_url/thread_announcement_url`
    - 楼层：`post_create_url/post_update_url/post_delete_url`
    - 用户：`user_url/user_thread_url/user_post_url/user_following_url/user_followers_url/user_favorite_url/user_like_url/user_follow_url/user_login_url/user_create_url/user_logout_url/user_resetpw_url/user_resetpw_complete_url/user_ai_setting_url/user_send_code_url`
    - 版块：`forum_url/forum_page_url/forum_create_url/forum_follow_url/forum_unfollow_url/forum_followers_url/forum_follow_status_url/forum_members_block_url`
    - 个人中心：`my_url/my_thread_url/my_post_url/my_favorite_url/my_like_url/my_following_url/my_followers_url/my_feed_url/my_notify_url/my_notify_list_url/my_notify_read_url/my_notify_dropdown_url/my_notify_mark_read_url/my_notify_unread_count_url/my_profile_url/my_password_url/my_email_url/my_avatar_url/my_avatar_preset_url/my_security_url/my_ai_url/my_credits_url/my_credits_rules_url/my_credits_check_url/my_send_email_code_url`
    - 通知：`notice_list_url/notice_mark_read_url/notice_announcements_url`
    - 模块操作：`mod_delete_url/mod_move_url/mod_top_url/mod_top_post_url/mod_close_url/mod_digest_url/mod_announcement_url/mod_audit_url/mod_audit_post_url`
    - 全局：`index_url/forums_url/more_url/search_url/search_page_url/rank_url/browser_url/captcha_url/lang_url/theme_url`
    - 后台：`admin_plugin_*/admin_forum_*/admin_user_*/admin_group_*/admin_setting_*/admin_security_*/admin_log_url/admin_credits_rule_url/admin_notice_*/admin_thread_*/admin_attach_*/admin_api_*/admin_cache_url/admin_cache_setting_url/admin_upgrade_url/admin_health_url/admin_phpinfo_url/admin_logout_url/admin_login_url/admin_audit_url/admin_theme_default_url/admin_theme_brand_url`
    - 后台跳前台：`frontend_thread_url/frontend_user_url/frontend_forum_url`

### runtime.func.php
- **用途**：全站运行时统计（缓存于 cache `runtime`），记录用户/帖子/主题等实时计数
- **关键函数**：
  - `runtime_init()` — 初始化运行时数据（首次访问时统计）
  - `runtime_get($k)` — 读取运行时字段
  - `runtime_set($k, $v)` — 设置字段（支持 `xxx+`/`xxx-` 增量语法）
  - `runtime_delete($k)` — 删除字段
  - `runtime_save()` — 保存到缓存（注册为 shutdown 函数）
  - `runtime_truncate()` — 清空运行时数据

### session.func.php
- **用途**：自定义 Session 处理器，操作 `session` + `session_data` 表，替代 PHP 文件 session
- **关键函数**：
  - `sess_start()` — 启动 session（设置 cookie 安全属性、注册 handler）
  - `sess_read($sid)` — 读取 session 数据
  - `sess_write($sid, $data)` — 写入 session（支持大字段分离、延迟更新）
  - `sess_destroy($sid)` — 销毁 session
  - `sess_gc($maxlifetime)` — 回收过期 session
  - `sess_new($sid)` — 创建新 session 记录
  - `sess_save()` / `sess_restart()` — 保存/重启 session
  - `online_count()` — 当前在线人数
  - `online_find_cache()` — 在线用户快照（近 1 小时）
  - `online_list_cache()` — 在线用户列表缓存

### smtp.func.php
- **用途**：SMTP 配置管理，保存于 `conf/smtp.conf.php` 文件（非数据库表）
- **关键函数**：
  - `smtp_create($arr)` — 新增 SMTP 配置
  - `smtp_update($id, $arr)` — 更新 SMTP 配置
  - `smtp_read($id)` — 读取单条 SMTP 配置
  - `smtp_delete($id)` — 删除 SMTP 配置
  - `smtp_save()` — 保存到配置文件
  - `smtp_init($confile)` — 初始化默认配置
  - `smtp_find()` — 获取全部 SMTP 列表
  - `smtp_count()` / `smtp_maxid()` — 统计

### table_day.func.php
- **用途**：操作 `table_day` 表，统计 thread/post/user 表每日最大 ID 用于加速查询
- **关键函数**：
  - `table_day_read($table, $year, $month, $day)` — 读取某天的统计
  - `table_day_maxid($table, $date)` — 获取某天的最大 ID（支持日期字符串或时间戳）
  - `table_day_cron($crontime)` — 每日定时统计三大表的最大 ID 和总数
  - `table_day_rebuild()` — 重建所有历史统计数据

### thread.func.php
- **用途**：操作 `thread` 表，管理主题 CRUD、统计、搜索、权限过滤、状态标签
- **关键函数**：
  - `thread_create($arr, &$pid)` — 创建主题（含首帖、统计、通知关注者）
  - `thread_update($tid, $arr)` — 更新主题（含版块迁移处理）
  - `thread_inc_views($tid, $n)` — 浏览数 +n
  - `thread_read($tid)` — 读取主题
  - `thread_read_cache($tid)` — 静态缓存读取
  - `thread_delete($tid)` — 删除主题（含回帖、附件、收藏清理）
  - `thread_delete_batch($tids)` — 批量删除主题（合并查询）
  - `thread_find($cond, $orderby, $page, $pagesize)` — 查询主题列表
  - `thread_find_by_fid($fid, $page, $pagesize, $order)` — 按版块查询（含置顶帖）
  - `thread_find_by_fids($fids, $page, $pagesize, $order, $threads)` — 多版块查询
  - `thread_find_by_keyword($keyword)` — 关键词搜索主题标题
  - `thread_find_by_tids($tids, $order)` — 按 tid 批量查询
  - `thread_find_lastpid($tid)` — 查询最后回帖 pid
  - `thread_update_last($tid)` — 更新最后回帖信息
  - `thread_count($cond)` — 统计主题数量
  - `thread_maxid()` — 获取主题最大 ID
  - `thread_safe_info($thread)` — 主题脱敏
  - `thread_get_level($n, $levelarr)` — 根据数值获取等级
  - `thread_list_access_filter(&$threadlist, $gid)` — 按权限过滤主题列表
  - `thread_status_labels()` — 获取主题状态标签配置
  - `thread_status_label_html($type, $labels)` — 渲染状态标签 HTML
  - `credits_event_name($event)` — 积分事件中文名映射

### thread_digest.func.php
- **用途**：操作 `thread_digest` 表，管理主题精华及统计联动
- **关键函数**：
  - `thread_digest_create($tid, $digest, $uid, $fid)` — 设置精华（含计数）
  - `thread_digest_delete($tid, $uid, $fid)` — 取消精华（含计数回退）
  - `thread_digest_read($tid)` — 查询精华记录
  - `thread_digest_update($tid, $arr)` — 更新精华记录
  - `thread_digest_change($tid, $digest, $uid, $fid)` — 切换精华状态
  - `thread_digest_change_batch($tids, $threadlist, $digest)` — 批量切换精华（合并 SQL）
  - `thread_digest_find_by_fid($fid, $page, $pagesize)` — 按版块查询精华
  - `thread_digest_find_by_uid($uid, $page, $pagesize)` — 按用户查询精华
  - `thread_digest_count($fid)` — 统计精华数

### thread_favorite.func.php
- **用途**：操作 `thread_favorite` 表，管理用户对主题的收藏关系
- **关键函数**：
  - `thread_favorite_create($uid, $tid)` — 收藏主题（INSERT IGNORE + 计数 + 通知）
  - `thread_favorite_delete($uid, $tid)` — 取消收藏
  - `thread_favorite_read($uid, $tid)` — 查询是否已收藏
  - `thread_favorite_find_by_uid($uid, $page, $pagesize)` — 查询用户收藏列表
  - `thread_favorite_delete_by_tid($tid)` — 按主题删除收藏（批量更新计数）
  - `thread_favorite_delete_by_tids_batch($tids)` — 批量删除多主题收藏（按 uid 聚合）

### thread_top.func.php
- **用途**：操作 `thread_top` 表，管理主题置顶（版块置顶/全局置顶）
- **关键函数**：
  - `thread_top_change($tid, $top)` — 设置/取消置顶
  - `thread_top_change_batch($tids, $threadlist, $top)` — 批量置顶（合并 SQL）
  - `thread_top_delete($tid)` — 删除单条置顶记录
  - `thread_top_find($fid)` — 查询置顶列表（fid=0 查全局）
  - `thread_top_find_cache()` — 缓存读取全局置顶列表
  - `thread_top_cache_delete()` — 失效置顶缓存
  - `thread_top_update_by_tid($tid, $newfid)` — 移动主题时同步更新 fid

### user.func.php
- **用途**：操作 `user` 表，管理用户 CRUD、登录验证、token、密码/用户组安全修改
- **关键函数**：
  - `user_preload($uids, $preload_follow)` — 批量预加载用户数据到静态缓存
  - `user_create($arr)` — 新增用户（含全站统计）
  - `user_update($uid, $arr)` — 更新用户（过滤 password/salt/gid 等受保护字段）
  - `user_read($uid)` — 读取用户
  - `user_read_cache($uid)` — 缓存读取用户
  - `user_delete($uid)` — 删除用户（含主题/回帖/附件/关注/头像清理）
  - `user_find($cond, $orderby, $page, $pagesize)` — 查询用户列表
  - `user_read_by_email($email)` — 按邮箱查询
  - `user_read_by_username($username)` — 按用户名查询
  - `user_find_by_usernames($usernames)` — 批量按用户名查询
  - `user_find_by_uids($uids)` — 按 uid 字符串（逗号分隔）查询
  - `user_count($cond)` — 统计用户数量
  - `user_maxid()` — 获取用户最大 ID
  - `user_login_verify($password, $user)` — 登录密码验证（bcrypt + 兼容旧 md5 格式）
  - `user_change_password($uid, $new_password, $old_password, $is_admin)` — 安全修改密码
  - `user_change_group($uid, $new_gid)` — 安全修改用户组（管理员专用）
  - `user_update_group($uid)` — 按积分自动调整用户组
  - `user_token_get()` / `user_token_set($uid)` / `user_token_clear()` / `user_token_gen($uid)` — 用户 token 管理
  - `user_login_check()` — 前台登录验证
  - `user_auth_check($token)` — 邮件链接 auth 验证
  - `user_http_referer()` — 获取并清洗来路 URL
  - `user_safe_info($user)` — 用户脱敏
  - `user_guest()` — 游客信息
  - `avatar_preset_files()` — 预设头像文件列表

### user_follow.func.php
- **用途**：操作 `user_follow` 表，管理用户之间的关注/粉丝关系
- **关键函数**：
  - `user_follow_create($uid, $follow_uid)` — 关注用户（INSERT IGNORE + 计数 + 通知）
  - `user_follow_delete($uid, $follow_uid)` — 取消关注
  - `user_follow_read($uid, $follow_uid)` — 查询是否已关注
  - `user_follow_read_batch($uid, $target_uids)` — 批量查询关注状态
  - `user_follow_find_following($uid, $page, $pagesize)` — 查询关注列表
  - `user_follow_find_followers($uid, $page, $pagesize)` — 查询粉丝列表
  - `user_follow_find_following_uids($uid)` — 获取关注的 UID 列表
  - `user_follow_find_following_uids_reverse($follow_uid)` — 获取反向粉丝 UID 列表
  - `user_follow_delete_by_uid($uid)` — 按用户删除所有关注关系（批量更新计数）

### user_profile_audit.func.php
- **用途**：操作 `user_profile_audit` 表，管理用户资料变更（如头像）的审核流程
- **关键函数**：
  - `user_profile_audit_create($arr)` — 新增审核记录
  - `user_profile_audit_update($id, $arr)` — 更新审核记录
  - `user_profile_audit_read($id)` — 读取单条审核记录
  - `user_profile_audit_delete($id)` — 删除单条审核记录
  - `user_profile_audit_find($cond, $orderby, $page, $pagesize)` — 查询审核列表
  - `user_profile_audit_count($cond)` — 统计审核数量
  - `user_profile_audit_find_by_uid($uid, $audit_status)` — 按用户查询待审资料
  - `user_profile_audit_find_pending($page, $pagesize)` — 查询待审列表（含用户信息和头像 URL）
