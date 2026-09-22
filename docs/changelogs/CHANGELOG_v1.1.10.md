# v1.1.10 更新日志 - 2026-09-12

> **版本说明**: 审核待审通知系统上线 + API 封禁检查补全 + 帖子锚点精确楼层定位 + 楼中楼回复归位 + 三栏布局左栏自动补位 —— 后台审核页新增通知设置（三类待审内容 × 站内消息/邮件/红点 + 提醒邮箱），新主题/回帖/资料变更进入待审队列时自动推送管理员；API 路径补全封禁检查（login/browse/post/password 场景），与 Web 端 UserBanService 对齐；帖子详情页通知链接精确到具体楼层（楼中楼回溯），#pid 锚点自动展开折叠楼中楼并滚动高亮；hx-swap 追加楼中楼回复归位到父评论容器而非列表末尾；三栏布局 sidebar 关闭时左栏自动隐藏、中栏扩宽。

## 🆕 新功能

### 审核待审通知系统（全新）

- **后台通知设置** `admin/route/audit.php` + `admin/view/htm/audit.htm`：审核页新增通知设置卡片，三类待审内容（主题/回帖/资料变更）× 三通道（站内消息/邮件/红点）开关 + 提醒邮箱（留空回退全局默认邮箱 → 管理员账号邮箱）+ 测试邮件按钮

- **统一门面** `lib/security/AuditService.php` 新增 `notify_new_pending()`：三通道一次接入，同类型 5 分钟 throttle 防刷屏，仅该类型三通道全部关闭时跳过（省去计数查询）

- **触发点**：新主题 `route/thread.php::create`、新回帖 `route/post.php::create`、资料变更 `route/my.php::edit/profile_avatar/profile_preset`、审核重新进入待审队列 `AuditService::resubmit`

- **配置持久化**：复用 `plugin_notify_config_save('core_audit', ...)` 统一入口，`plugin_notice_flush()` 清缓存

### 测试邮件逻辑提取复用

- `model/plugin_notify.func.php` 新增 `plugin_notify_send_test()`：后台通知设置页共用逻辑，`admin/route/plugin.php::local` 原本内联的 20+ 行测试邮件代码改为调用此函数

### 帖子详情页 HTTP 缓存控制

- `route/thread.php::display` 新增 `header('Cache-Control: no-cache, private')`：回帖后普通导航/刷新必须拿到最新回复列表，不破坏 bfcache 返回键体验

## 🐛 修复

### API 封禁检查补全（高危）

- **辅助函数** `api/v1/bootstrap.php` 新增 `api_check_ban_scene($uid, $gid, $scene)`：按场景拦截（login/browse/post/password），与 Web 端 `UserBanService::checkBanByScene` 规则对齐，管理员组豁免

- **全局 browse 场景检查**：有效 token 用户也需过 browse 封禁（禁止访问/锁定用户不能使用任何 API）

- **login 场景** `api/v1/auth.php::login` + `api/v1/auth.php::refresh`：禁止访问/锁定用户不能登录、不能通过 refresh\_token 续期换新 token

- **post 场景** `api/v1/post.php::create/edit` + `api/v1/thread.php::create/edit/hot`：禁言/禁止访问/锁定用户不能发帖、回帖、编辑

- **password 场景** `api/v1/user.php::edit`：锁定(ban\_type=3)用户不能改密

### 通知链接精确楼层定位

- `model/notify.func.php` 新增 `notify_thread_post_url($tid, $pid)`：计算页码并追加 `#pid` 锚点，楼中楼沿引用链回溯到所属一级楼层（上限 10 层防死循环）

- `notify_format()` 链接生成逻辑升级：pid>0 时先尝试精确锚点链接，失败回退帖子根链接

### 帖子锚点滚动与折叠展开

- `view/htm/thread_js.inc.htm` 新增 pid 锚点定位脚本：`#pidNNN` 自动展开折叠的楼中楼、滚动到目标楼层、1.5s 黄色高亮提示，bfcache `pageshow` 事件也会重新定位

- CSS：`[id^="pid"]` 预留 64px 吸顶导航偏移；JS 动态测量实际吸顶/固定导航底边兼容不同主题

- `view/htm/post_list.inc.htm` + `view/htm/thread_main.inc.htm`：每个楼层 `<li>` 加 `id="pidXXX"` 锚点

### 回复成功自动滚动到新回复

- `thread_js.inc.htm` hx-swap 成功回调：回复成功后 `scrollIntoView({behavior:'smooth', block:'center'})`，避免长列表/视口不在列表末尾时用户感知为"回复后没显示"

### 楼中楼回复归位

- `thread_js.inc.htm` OOB 更新：hx-swap 追加新评论时，楼中楼回复（`data-parent-pid`）归位到父评论容器 `.replies-container` 而非列表末尾，自动展开折叠容器并移除展开按钮；找不到父评论时降级保留在列表末尾（刷新后由后端重新渲染）

### 收藏/取消收藏积分门控优化

- `route/thread.php::favorite`：以数据层真实返回值判断——`thread_favorite_create` 返回 1=新增/0=已存在/FALSE=失败；`thread_favorite_delete` 返回 rowCount>0=真删除/0=被并发抢先删除/FALSE=未删除。对齐点赞分支范式，修复并发双击败者场景重复扣积分

## 🏗️ 架构/重构

### 三栏布局左栏自动补位

- `view/htm/layout_three_column.inc.htm`：新增 `$col_left` 参数（默认 2），左栏变窄时中栏自动补位（差值 = 2 - col\_left）；`sidebar_left.inc.htm` 受后台"左侧导航"开关关闭时，左栏不渲染栅格列、中栏自动扩宽占满剩余空间

- `view/htm/thread.htm`：`$col_left = 1`，左侧操作栏窄列，中栏自动补位到 col-xl-8

- 右栏同理：右侧栏文件为空时右栏也不渲染栅格列

### 后台审核红点按通知设置累计

- `admin/view/htm/sidebar.inc.htm`：审核菜单红点从"三类全加"改为按 `notify_config` 分类型 badge 开关累计，未配置时默认全开向后兼容

### 多语言覆盖

- 中文/繁中/英文语言包新增审核通知相关文案（标题/描述/测试邮件/提醒邮箱/行名等）

## 🎨 UI/UX

### Tabler 图标光学对齐

- `view/css/theme.css` 新增 `.ti { vertical-align: -0.125em; }`：Tabler 字形无下伸部、几乎占满 em 框上部，统一下沉 0.125em 实现光学居中，小按钮/操作行里最明显

### Footer 类名重命名

- `.bbs-footer-copy` → `.bbs-footer-site-name`：语义更准确

### 收藏按钮间距

- 移除 `.bb-favorite-btn` 的 `margin-left: 90px`：避免非预期位移

### 新增 Hook 点

- `view/htm/footer.inc.htm`：`footer_nav_links_item_start.htm`、`footer_nav_links_item_after.htm`、`footer_nav_powered_start.htm`、`footer_nav_powered_after.htm`、`footer_nav_info_before.htm`、`footer_nav_info_after.htm`

- `view/htm/sidebar_right.inc.htm`：`index_site_brief_user_after.htm`、`index_site_brief_signature_after.htm`、`index_site_brief_user_nav_after.htm`、`index_site_brief_guest_brief_after.htm`、`index_site_brief_guest_stats_after.htm`

### post\_list 作者信息区 flex 布局

- `view/htm/post_list.inc.htm`：作者信息区改 `d-flex flex-wrap align-items-center` + `gap:4px 6px`，标签/徽章与用户名对齐更整齐

## 📦 升级说明

- 无需手动处理文件删除，无 Breaking Changes
- 升级后后台审核页新增通知设置功能，默认全开（向后兼容）
- API 封禁检查补全后，此前绕过封禁的封禁用户将被正确拦截
- 通知链接带精确楼层锚点，后端需确保 post 表 pid 字段可用（无 schema 变更）

## 🔧 后续修复补充（2026-09-22）

### 搜索高级筛选（版块 / 作者 / 日期范围）

- **后端解析** `route/search.php`：新增 `fid`（版块）、`author`（用户名或 UID，纯数字视为 UID，否则精确匹配 username/nickname）、`ds`（开始日期 Y-m-d）、`et`（结束日期 Y-m-d）四个高级筛选参数

- **SQL 拼接安全**：筛选值均 intval / 时间戳严格校验（开始当天 0 点、结束当天 23:59:59），内联拼接无注入面；指定作者但未匹配到用户时恒空结果（`AND 1=0`）；开始晚于结束视为未设置；版块经 `forum_list_access_filter` 权限过滤，防止 URL 枚举无权版块

- **前端 UI** `view/htm/search.htm`：搜索框下方新增折叠筛选面板（版块下拉分组 / 作者输入 / 日期范围 / 一键重置），已有筛选时默认展开并显示"筛选已生效"标识

- **参数透传**：分页 URL、排序按钮 URL 均保留关键词 + 筛选参数，翻页/切换排序不丢失筛选条件

- **语言包**：zh-cn/zh-tw/en-us 新增筛选相关文案（search\_filter / search\_filter\_active / search\_author / search\_date\_range / search\_filter\_reset 等）

### 标题双重转义修复

- **model 层移除** `model/thread.func.php`：`thread_format()` 不再对 subject 调 `esc_html`。标题接收时已 `strip_tags` 无 HTML 标签、DB 存原样，此前 model 层 + 模板层各转义一次造成双重转义，`& " ' < >` 显示为 `&amp;/&quot;/&#039;` 等实体字面量；现统一交给模板层单次 esc\_html / esc\_attr

- **参数配合** `api/v1/thread.php` + `route/thread.php`：`param('subject', '', FALSE)` 取消 param 层转义，避免与模板层重复转义

### 版块管理排序提示

- **后台版块列表** `admin/view/htm/forum_list.htm`：列表顶部新增 info 提示条，说明分区/子版块排序交互，文案走 `admin_forum_sort_tip` 语言包

### 插件开发文档 manual 目录清理

- **冗余副本移除** `docs/xiunox-plugin-dev/references/manual/`：22 篇文档（01 架构 ~ 19 用户导航 + README + plans + plugin-mutex-guide）删除，references 根下同名文档为唯一权威源

- **链接更新** `references/` 下 9 篇速查文档：`manual/xx.md` → `xx.md` 平级引用

### Footer hooks 目录补全

- `docs/plugindev/03-hooks-catalog.md`：Footer 章节补全 `footer_main_end`、`footer_js_config_after`、`footer_nav_logo_before`、`footer_nav_links_item_start/after`、`footer_nav_powered_*`、`footer_nav_info_*` 等 hook 点，新增移动端底部导航 `bottom_nav_end` hook 说明
