---
name: xiunox-plugin-dev
description: >
  XIUNOX 插件开发专家。
  当用户要求写/改/调试 XIUNOX 插件、加 hook、加后台设置页、加路由、
  加签到/标签/勋章/举报/收藏等新功能插件、创建 Service 类、写 install/uninstall 脚本、
  调试插件不生效、做插件架构设计时触发。
  也适用于检查现有插件是否符合规范、修复扫描器拦截的 fatal/warning、
  将 jQuery/Alpine.js 旧插件迁移到 htmx 4 架构。
---

# XIUNOX 插件开发 Skill

> **配套完整手册**：`plugindev/` 目录有 8 个分册的完整开发手册（架构/结构/hooks/API/前端/AI 协作/运行时安全/登录安全），本 Skill 是精简版，**深入细节查 plugindev**，**写代码对照本文件**。
>
> **完整手册下载地址**：https://github.com/domidesign/xiunox/tree/main/docs/plugindev
> （本项目不自带 `plugindev/` 目录时，从该 GitHub 仓库下载后放到 `docs/plugindev/` 即可使用本 Skill 的全部交叉引用）

## 核心架构（必须先理解）

**插件是编译时合并，不是运行时分发。** 把 hook 文件放到 `plugin/<dir>/hook/<hook名>` 就等于注册了 hook，没有 `add_hook()` 函数。`_include()` 编译时把 hook 内容物理拼进源文件，缓存到 `tmp/`。

**hook 文件名（含扩展名）必须和源标记一模一样：**
- PHP 源码标记：`// hook thread_create_thread_end.php`
- 模板标记：`<!--{hook thread_subject_after.htm}-->`

**模型三层命名：** `__` 原始层（纯DB）→ 单 `_` 业务层（缓存/计数/通知）→ `_format` 装饰层。**插件永远调单下划线业务层。**

---

## 硬规则（不可违反）

### 禁止

- ❌ jQuery（`$()`、`.on()`、`.ajax()`）
  - **07-17 起项目内置 jQuery 兼容 shim**（`view/js/xiuno-modern.js` 暴露 `window.jQuery = $`），让 20 个存量插件 JS 和 .htm 模板内联 `$` 代码继续工作。**新插件/新代码强制用 XN API**（`XN.toast()` / `XN.ajax()` / `XN.confirm()` / `XN.$()`），存量 `$` 代码暂不强制重写、留待后续阶段逐个迁移
  - **shim 已覆盖**（存量代码可继续用）：`$()` 选择器链式、`$.fn.*`（BS5 桥接 modal/dropdown/tooltip/tab/collapse + loading/checked/serializeObject 等 17 个扩展）、`$.ajax`/`$.get`/`$.post`/`$.getJSON`、`$.alert`/`$.confirm`/`$.ajax_modal`、`$.Event`、`xn.*` PHP 函数库（intval/json_encode/url 等 30+）
  - **shim 不支持**（写了会静默失败或报错，必须改用 `XN.ajax()` + `async/await` 或原生 `fetch`）：`jqXHR.done/.fail/.always` 链式回调、同步 xhr（`async: false`）、`jsonp`/`script` dataType、`$.when()` 多请求编排、`.animate()` 队列动画
- ❌ Alpine.js（`x-data`、`x-show`、`x-bind`、`x-model` 等）
- ❌ idiomorph / alpine-morph 扩展
- ❌ `htmlspecialchars` 裸写 → 用 `esc_html()` / `esc_attr()` / `esc_js()`
- ❌ POST 表单不带 `CsrfService::input()` + `CsrfService::check()`
- ❌ 裸 `include` → 用 `_include()`
- ❌ `window.__xxxData` → 状态放 DOM（`data-*`、hidden input）
- ❌ 后台（admin/）用 htmx
- ❌ **htmx 4 的 `htmx:config:request` 事件中修改 DOM 元素** → 该事件触发时 FormData 已构建完成，修改 DOM（如动态添加 input）不会影响请求体。必须直接修改 `evt.detail.ctx.request.body`（FormData 对象）来追加参数。
  - ❌ 错误写法：在 `htmx:config:request` 中 `document.createElement('input')` + `appendChild`
  - ✅ 正确写法：`evt.detail.ctx.request.body.append('key', 'value')`
- ❌ 用 `APP_PATH` 生成 `<script src>` / `<link href>`（`APP_PATH` 是文件系统绝对路径，浏览器无法访问）
- ❌ 把 JS/CSS 放在 `view/htm/` 目录（应放 `static/js/`、`static/css/`）
- ❌ **`static/*.js` 文件中写 PHP 代码**（`<?php echo lang('xxx'); ?>` 等）→ `.js` 是纯静态文件不会被 PHP 解析，浏览器看到 `'<?php echo lang('` 单引号闭合后，`xxx` 变成裸标识符触发 `Uncaught SyntaxError: Unexpected identifier`。翻译字符串必须通过引入该 JS 的 hook 模板（hook 是 `.htm` 会被 `_include()` 编译执行）注入到 `window.XXX_I18N` 全局对象，JS 文件改用 `var I18N = window.XXX_I18N || {fallback};` 读取。⚠️ 已违反 1 次（xnx_poll poll_post.js 3 处 + poll.js 7 处，导致发帖页开启投票后选项列表不显示 + 帖子详情页投票卡按钮失效）
  - ✅ 正确范式（hook 模板注入 + JS 读取）：
    ```php
    // hook/post_js.htm（.htm 会被 PHP 解析）
    echo '<script>window.XNX_POLL_I18N_POST = ' . xn_json_encode(array(
        'options'      => lang('xnx_poll_options'),
        'min_options'  => lang('xnx_poll_min_options'),
    )) . ';</script>';
    echo '<script src="' . $conf['view_url'] . '../plugin/xnx_poll/static/js/poll_post.js?v=' . $v . '"></script>';
    ```
    ```javascript
    // static/js/poll_post.js（纯静态）
    var I18N = window.XNX_POLL_I18N_POST || { options: '选项', min_options: '至少需要 2 个选项' };
    XN.toast(I18N.min_options, 'warning');
    ```
  - 💡 判断规则：只要文件扩展名是 `.js`（非 `.htm`/`.php`），就当作纯静态文件，禁止写任何 `<?php ?>` 代码
- ❌ **后台插件设置页/管理页模板（`view/htm/setting.htm`、`view/htm/xxx_admin.htm`）不 include 后台 header/footer** → 这些模板是独立页面，必须首尾分别 `include _include(ADMIN_PATH . 'view/htm/header.inc.htm')` 和 `footer.inc.htm`，否则页面没有后台顶部导航栏和侧边栏（光秃秃只有表单）。前台插件页面则应 include `APP_PATH . 'view/htm/header.inc.htm'`（非 ADMIN_PATH）。⚠️ 已违反 1 次（xnx_poll setting.htm + poll_admin.htm 两个模板都漏 include）
  - ✅ 正确范式：
    ```php
    <?php include _include(ADMIN_PATH . 'view/htm/header.inc.htm'); ?>
    <div class="x-card card">...</div>
    <?php include _include(ADMIN_PATH . 'view/htm/footer.inc.htm'); ?>
    ```
  - 💡 参考：`xnx_related/view/htm/setting.htm`、`xnx_appcenter/view/htm/setting.htm` 已有正确 include
- ❌ `.htm` 模板 hook 文件以 `<?php exit;` 开头（会白屏！只能用 `<?php`）
- ❌ `.htm` 模板 hook 文件用 `.php` 扩展名（文件名必须和源码标记一模一样）
- ❌ 数据库表用 `utf8`（必须 `utf8mb4` 支持 emoji）
- ❌ 后台 setting.php 缺少 `$gid != 1 && $gid != 2 AND message(-1, '无权限');` 权限检查
- ❌ SQL 拼接用户输入（必须 `intval()` 转义）
- ❌ 裸用 `db_sql_find` / `db_sql_find_one` / `db_exec` 做普通查询 → 优先用 `db_find` / `db_find_one` / `db_count` / `db_find_group` / `db_find_one_group`（防 SQL 注入）
- ❌ 保留复杂 SQL（JOIN/系统表/复杂 DML）时不加注释 → 必须加包含 `保留 db_sql_find` 或 `保留 db_exec` 关键字的注释（扫描器据此跳过报告）
- ❌ 帖子列表页 hook 中 `new Service()` + 查库 N 次（用静态缓存）
- ❌ `db_insert` 后不检查返回值就 `message(0, '成功')`（字段不存在会静默失败）
- ❌ **卸载脚本用 `unstall.php`（旧拼写）** → 必须用 `uninstall.php`（标准拼写），核心已兼容回退但新插件禁止用旧拼写
- ❌ **数据库结构变更在 install.php/setting.php 加字段自愈代码** → 必须走 upgrade.php 幂等迁移 + 递增 conf.json.version
- ❌ `esc_attr(json_encode(array(...)))` 嵌套括号写一行（易出错，先存变量再转义）
- ❌ 硬编码 URL 后缀（如 `/thread-{tid}.htm`、`/forum-{fid}.htm`）→ 必须用 `url()` 函数适配伪静态格式（url_rewrite_on 支持 0~5 六种模式）
- ❌ 拼接 `$site_url . '/xxx.htm'` 做缓存刷新/跳转等 → 必须用 `$site_url . url("xxx")` 适配伪静态
- ❌ 单行注释 `//` 和 `#` 中包含 `?>`：PHP 会把单行注释中的 `?>` 当作 PHP 代码块结束标签，导致后续代码被当作纯文本输出，触发「headers already sent」错误，页面直接显示代码
  - 错误示例：`// 模板中调用：<?php echo thread_url($tid);?>`
  - 正确写法：`// 模板中调用示例：echo thread_url($tid);`（去掉 `<?php` 和 `?>`）
  - 块注释 `/* */` 中可以包含 `?>`，不受影响
- ❌ 模板里硬编码 `url("thread-$tid")` 字符串拼接 → 必须用命名快捷函数（`thread_url($tid)`）或 `route_url('thread', ['tid'=>$tid])`
- ❌ 插件自定义路由直接写 `url("myplugin-$id")` → 必须通过 `hook/model_route_table_end.php` 注册到路由表
- ❌ 插件直接调用 `route_table()` 修改返回值（会破坏静态缓存）→ 只能通过 `model_route_table_end.php` hook 修改局部 `$routes` 变量
- ❌ 跨插件共享配置时，保存和读取用不同的存储键（如存到 `setting_set('xnx_checkin', ...)` 但从 `setting_get('plugin_discover_items')` 读取）→ **保存和读取必须用同一个 key**
- ❌ `setting_set('key', xn_json_encode($arr))` 然后 `setting_get()` 后 `xn_json_decode()` → **`setting_set/get` 原生支持数组存取，不需要 JSON 中转**
- ❌ 模板中 `<script src="../view/js/xxx.js">` 相对路径 → 必须用 `$conf['view_url']js/xxx.js`
- ❌ 重复造轮子（自建图标选择弹窗、日期选择器等）→ **开发前先检索项目中已有组件**（如 `TablerIconPicker`、`bootstrap.Modal` 等）
- ❌ 注册表/默认配置中硬编码中文文本 → 必须用 `lang()` 多语言键，运行时解析
- ❌ 手写 `if(cache_get())...else{compute;cache_set()}` 缓存样板代码 → 用 `CacheHelper::remember($key, $ttl, $callback, $plugin)` 一行搞定
- ❌ 枚举所有 limit 值逐个 `cache_delete()` 清除缓存 → 用 `CacheHelper::pluginDeletePrefix($plugin)` 一键清除
- ❌ 裸用 `cache_get('xnx_plugin_key')` 全局键名 → 必须用 `CacheHelper::pluginKey()` 生成带 `p_{plugin}_` 前缀的键名
- ❌ 插件数据缓存不注册缓存键 → Service 类构造函数必须调用 `CacheHelper::registerKeys()` 注册（后台统计面板依赖此注册）
- ❌ 密码/Token 等敏感配置用 `param()` 默认行为 → `param()` 默认做 htmlspecialchars 转义会破坏密码，必须传第 3 参数 `FALSE`（如 `param('redis_password', '', FALSE)`）
- ❌ 插件 Service 调用 `new CreditsService()` 等核心 Service 前不 `include_once` → 生产环境（DEBUG=0）走 `tmp/model.min.php` 合并加载，类加载顺序不可预测，不 include 会抛 `Class not found`；开发环境（DEBUG=1）因其他插件先执行而"碰巧能用"
- ❌ **前台代码用 `global $plugins; $plugins[$dir]['enable']` 判断插件是否启用** → 前台 `$plugins` 全局变量未初始化（`plugin_init()` 仅在 admin/upgrade 调用），始终为空数组，判断永远为 false。必须改用 `plugin_paths_enabled()`（读 conf.json，前台兼容）或直接读 `plugin/<dir>/conf.json` 的 `enable`+`installed` 字段
- ❌ **PHP 端把自定义按钮名作为字符串下发给 JS 的 `toolbarKeys`** → AIEditor 的 `toolbarKeys` 把字符串当内置按钮ID处理（如 `'bold'`），自定义按钮必须以**对象**形式传入。注意 PHP `implode(',', ['a'])` 生成无引号 `a`，`json_encode(['a'])` 生成带引号 `["a"]`，两种方式 JS 端得到的完全不同（前者是变量引用，后者是字符串数组），修改前必须确认 PHP 实际输出格式
- ❌ **同一类常量被多处入口引用时，先访问静态属性/常量再 `include_once`** → 例如 `if(!in_array($gid, UserBanService::ADMIN_GIDS, true)) { include_once APP_PATH.'lib/UserBanService.php'; ... }` 会先访问 `ADMIN_GIDS` 触发类加载，若类未加载就崩溃。必须改成 `if(!class_exists('UserBanService')) { include_once ... } if(!in_array(..., UserBanService::ADMIN_GIDS, true)) {...}` 顺序，所有调用点保持一致（生产环境 DEBUG=0 走 min.php 合并加载，类加载顺序不可预测） ⚠️ 已违反 1 次（route/user.php login/resetpw 两处）
- ❌ **新增入口级安全检查（IP 黑名单/封禁检查）只补主入口** → 必须用 Grep 全局搜索所有「用户可发表内容/可登录」入口（login/create/resetpw 发帖回帖改密找密）逐个补齐。Spec checklist 验证阶段比单元测试更能发现这类遗漏 ⚠️ 已违反 1 次（route/thread.php create 缺 IP 黑名单检查）
- ❌ **用户未指定插件前缀时使用 `xn_` 或 `xnx_` 前缀** → 这两个是官方预留前缀（如 `xn_url`、`xnx_checkin` 等核心/官方插件已占用），第三方插件禁止使用。应改用插件目录名或其缩写作为前缀（如插件目录 `my_plugin` 则用 `my_` 前缀）。即使用户指定了目录名，若该目录名以 `xn_` 或 `xnx_` 开头，也应提醒用户更换为其他前缀
- ❌ **插件层用 `db_find('user', ...)` 获取用户信息后直接取 `username` 字段显示** → `db_find('user')` 绕过核心层，返回的数据**不含 `display_name` 字段**（`display_name` 由核心层 `model/user.func.php` 的 `user_format()` 生成：`nickname` 优先，为空 fallback 到 `username`）。直接取 `username` 会显示登录用户名而非用户可修改的昵称。必须改用 `user_find_by_uids(implode(',', $uids))` / `user_read($uid)` / `user_read_cache($uid)` 等核心函数（自动调用 `user_format()`），模板显示时取 `$user['display_name']`。⚠️ 已违反 1 次，影响 5 个插件（xnx_duel / xnx_dice / xnx_invite / xnx_attach_access / xnx_landing），根因是 `$u['display_name'] ?? $u['username']` 这种 fallback 写法在 `db_find` 结果上无效（`display_name` 键根本不存在，始终 fallback 到 `username`）
- ❌ **所有 hook 文件（路由层 + model 层 + view 层）禁止使用 `return;`**：hook 是编译期内联到宿主函数/路由文件/模板中的，hook 内的 `return;` 会从被内联的整个宿主返回（不是从 hook 返回），导致宿主后续逻辑被跳过。**已违反 4 次，影响范围逐次扩大**：
  - 路由层：xnx_feeds `thread_create_thread_end`（审核场景无提示无跳转）/ xnx_feeds `user_create_post_end`（注册后无提示）/ xnx_hidden `post_create_htmx_reply_end`（回复后 htmx 响应污染）
  - **model 层**：xnx_feeds 6 个 model_*_end hook（待审回帖 pid 丢失事故，`post__create` 返回 int(754) 但 `post_create()` 因 hook return 提前退出，返回 NULL）/ xnx_hidden `model_post_create_end`（同上）
  - **model 层严重 bug**：xnx_maintenance `model_check_runlevel_start.php` 中 5 个 return 会跳过原 `check_runlevel()` 函数的 runlevel 1/2/3/4 拦截逻辑，导致插件启用后即使维护模式关闭，站点关闭/限访功能也全部失效
  - 违规写法：`if (!class_exists('XxxService', false)) return;` / `if (empty($list)) return;` / `if ($audit_status != 1) return;`
  - 正确写法：用 `if (条件) { ...全部逻辑... }` 包裹整个 hook 内容
  - **例外（允许 `exit;`）**：终止性操作（API 503 响应、页面渲染、302 重定向、维护页输出）后必须 `exit;` 终止请求，但必须用 if 包裹整个拦截逻辑且加 `// ponytail:` 注释说明 exit 的正当性
  - **闭包回调内部的 `return` 只作用于闭包本身**，合法（如 `array_filter` 的 lambda）
  - 判断 hook 是否在顶层作用域：Grep 核心文件中 `// hook xxx.php` 标记所在位置是否在任何 `function`/`method` 外
  - ✅ 正确范式（参考 `xnx_fields/hook/thread_create_thread_end.php`）：
    ```php
    <?php exit;
    // 注意：此 hook 位于 route 文件顶层作用域，禁止使用 return；用 if 包裹整个逻辑
    if (!empty($tid) && !empty($fid)) {
        if (!class_exists('XxxService', false)) { include_once APP_PATH.'plugin/.../XxxService.php'; }
        if (class_exists('XxxService', false) && (empty($audit_status) || $audit_status == 1)) {
            XxxService::doSomething($tid);
        }
    }
    ```
  - ❌ 错误写法（裸 return 退出整个路由）：
    ```php
    <?php exit;
    if (!class_exists('XxxService', false)) return;     // ← 退出整个 route 文件
    if ($audit_status != 1) return;                      // ← 退出整个 route 文件
    XxxService::doSomething($tid);
    ```
- ❌ **发帖/回复共用 `post.htm` 模板的 hook 不区分场景** → `view/htm/post.htm` 同时用于**发帖页**（`$route == 'thread' && $action == 'create'`）和**高级回复页**（`$route == 'post' && $action == 'create'`）。插件功能若只需发帖场景（如隐藏内容、投票、抽奖等主题级功能），hook 必须加 `if ($route == 'thread' && $action == 'create') { ... }` 判断，否则回复页也会加载不需要的卡片/Modal/JS，造成界面污染和资源浪费。涉及场景区分的常见 hook：`post_ref_thread_after.htm`（右侧卡片）、`post_ref_thread_after_mobile.htm`（手机端卡片）、`post_end.htm`（底部 Modal）、`post_js.htm`（底部 JS）。⚠️ 已违反 1 次（xnx_hidden 4 个 hook 未区分，回复页右侧显示隐藏内容模块）
  - ✅ 正确写法：
    ```php
    <?php
    // 仅发帖页加载，回复页不需要
    if ($route == 'thread' && $action == 'create') {
        $settings = XxxService::getSettings();
        if (!empty($settings['enabled'])) {
            // ... 渲染卡片/Modal ...
        }
    }
    ?>
    ```
  - 💡 判断变量来源：`$route` 和 `$action` 是 `index.inc.php` 解析路由时设置的全局变量，`post.htm` 模板顶部已用 `($route == 'thread' && $action == 'create')` 区分发帖/回复/编辑三种场景

### 必须

- ✅ htmx 4 属性（`hx-get`/`hx-post`/`hx-target`/`hx-optimistic`/`hx-live`）
- ✅ `XN.toast()` / `XN.ajax()` / `XN.confirm()`（`xiuno-modern.js`）
- ✅ Bootstrap 5.3 + Tabler Icons（`<i class="ti ti-xxx"></i>`）
- ✅ `esc_html()` / `esc_attr()` / `esc_js()`
- ✅ 所有命名带插件前缀（表/变量/语言键/JS/CSS/setting）
- ✅ 插件静态资源（JS/CSS）放在 `plugin/<dir>/static/js/` 和 `plugin/<dir>/static/css/`
- ✅ 引用插件静态资源用 `$conf['view_url'] . '../plugin/<dir>/static/js/xxx.js'`
- ✅ 引用核心静态资源用 `$conf['view_url']js/xxx.js`（非相对路径 `../view/js/`）
- ✅ **新插件 JS 放 `plugin/<dir>/static/js/`，CSS 放 `plugin/<dir>/static/css/`**，禁止放 `view/htm/`（会被 `_include()` 当模板编译）
- ✅ **JS 代码量约束**：单文件 <50 行可内联到 `.htm` hook（如 `post_js.htm`），≥50 行必须独立 `.js` 文件用 `<script src>` 引用（便于浏览器缓存 + 静态版本控制）
- ✅ 跨插件共享配置时，统一使用一个存储键读写（如 `setting_get/set('plugin_discover_items', $arr)`），不要存到各自插件 key 再从另一个 key 读取
- ✅ `setting_set/get` 原生支持数组存取，不需要 `xn_json_encode/decode` 中转
- ✅ 开发新组件前先检索项目已有组件（Grep 搜索 `TablerIconPicker`、`bootstrap.Modal` 等），复用优先于自建
- ✅ 注册表/默认配置中的文本用 `lang()` 多语言键，不硬编码中文
- ✅ 后台 setting.php 开头加 `$gid != 1 && $gid != 2 AND message(-1, '无权限');`
- ✅ 优先使用 `db_find` / `db_find_one` / `db_count` / `db_find_group` / `db_find_one_group` 替代 `db_sql_find` / `db_sql_find_one`（防 SQL 注入）
- ✅ 保留复杂 SQL（JOIN/系统表/GREATEST/子查询/INSERT IGNORE SELECT）时加注释 `// 保留 db_sql_find` 或 `// 保留 db_exec`（扫描器据此跳过，10 行抑制区间）
- ✅ install.php 所有表用 `DEFAULT CHARSET=utf8mb4`
- ✅ install.php 字段升级用 `SHOW COLUMNS` + `ALTER TABLE` 幂等逻辑
- ✅ **install.php 末尾必须清理 `tmp/model.min.php`**：生产环境（DEBUG=0）走 `model.min.php` 合并加载，该文件只在不存在时才重新生成。插件文件升级后未重新启用时，旧 `model.min.php` 不含新插件的 Service 类，导致 `class_exists` 返回 false → fatal error 白屏。正确写法：`if (isset($conf['tmp_path']) && function_exists('xn_unlink')) { @xn_unlink($conf['tmp_path'].'model.min.php'); }`。后台启用/禁用插件已由 `plugin_enable()`/`plugin_disable()` 自动清理，但 install.php 执行场景（覆盖代码部署后）不会触发启用流程，必须手动清理。⚠️ 已违反 1 次（xnx_hidden install.php 未清理，导致 admin 后台统计页空白）
- ✅ 帖子列表页 hook 用 `Service::getCached($uid)` 静态缓存避免 N 次查询
- ✅ `getUserMedals()` 用 JOIN 一次查询避免 N+1
- ✅ `esc_attr(json_encode(...))` 先存临时变量再转义
- ✅ 新增功能同时在导航/个人中心添加入口 hook
- ✅ conf.json 的 hooks_rank 键名必须和 hook 文件名（含扩展名）完全一致
- ✅ URL 生成优先级：**命名快捷函数**（`thread_url($tid)`）> `route_url()` 通用入口 > 底层 `url()` 函数
- ✅ 插件自定义路由必须通过 `hook/model_route_table_end.php` 注册到 `$routes` 数组
- ✅ 所有 URL 生成必须用 `url()` 函数（适配伪静态格式），禁止硬编码 `.htm` / `.html` 后缀
- ✅ 缓存刷新/跳转等需拼接完整 URL 时用 `$site_url . url("xxx")`，禁止 `$site_url . '/xxx.htm'`
- ✅ 插件数据缓存用 `CacheHelper::remember($key, $ttl, $callback, $plugin)` 代替裸 `cache_get/cache_set`
- ✅ 清除插件缓存用 `CacheHelper::pluginDeletePrefix($plugin)` 一键清除（不枚举 limit 值）
- ✅ 缓存键通过 `CacheHelper::pluginKey($key, $plugin)` 生成带 `p_{plugin}_` 前缀的键名
- ✅ Service 类构造函数调用 `CacheHelper::registerKeys($plugin, $keys)` 注册缓存键清单
- ✅ 列表类缓存用版本号机制（`CacheHelper::remember('list_v_'.$id, 86400, ...)` + `CacheHelper::set(pluginKey(...), $v+1, 86400)`）
- ✅ 敏感配置参数（密码/Token/Secret）用 `param($name, $default, FALSE)` 关闭 htmlspecialchars
- ✅ 插件 Service 调用核心 Service（CreditsService 等）前必须 `include_once` 对应文件：`if (!class_exists('CreditsService')) { include_once APP_PATH . 'lib/CreditsService.php'; }` 再 `new CreditsService($db, $conf)`
- ✅ **前台代码判断插件是否启用**用 `plugin_paths_enabled()`（返回已启用插件的 conf.json 数组，前台兼容）或直接读 `plugin/<dir>/conf.json` 的 `enable`+`installed` 字段，禁止用 `global $plugins`（前台未初始化）
- ✅ **PHP 端向 JS 传递自定义按钮配置**时，优先直接传对象 JSON（`json_encode([$btnObj])`），若传变量名字符串用 `implode` 生成无引号格式让 JS 当变量引用，修改前用 `echo` 确认实际输出格式
- ✅ **Card 组件必须加 `x-card` class**：项目统一卡片样式（无 border + 阴影 + 圆角，dark mode 才加 border，定义在 `view/css/theme.css` 的 `.x-card`），禁止裸用 `border` / `border-*` 工具类给卡片加边框，列表项之间也禁止用 `border-bottom` 临时分隔
  - ✅ 正确：`<div class="card x-card">...</div>` / `<li class="py-3">`（纯留白分隔）
  - ❌ 错误：`<div class="card border">` / `<li class="border-bottom py-3">` / `<div class="card border-start">`
  - **所有 border 都尽量不用**：卡片用 `x-card` 自带阴影分隔，列表项之间用纯 `py-*` 留白分隔（不加 border-bottom），需要视觉分隔时优先用 `py-*` / `mb-*` / `mt-*` / `gap-*` 等间距工具类，或用 `<hr>`（已有 `.thread-list-divider hr` 样式可复用）
- ✅ **发帖页新增功能放右侧栏（PC）并使用"引用帖子"卡片样式**：发帖辅助功能（投票/话题/附件权限/楼主可见/抽奖等）必须挂到 `post_ref_thread_after.htm`（PC 右侧栏）+ `post_ref_thread_after_mobile.htm`（手机端表单内），禁止挂到 `post_subject_after.htm`（标题下方）或 `post_message_after.htm`（编辑器下方）。卡片结构必须与"引用帖子"卡片完全一致：`x-card card` + `card-header bg-transparent border-bottom-0 py-2` + `<h6 class="mb-0 fw-semibold"><i class="ti ti-xxx me-1"></i>标题</h6>` + `card-body pt-0`。手机端不渲染 card-header。⚠️ 已遵循：xnx_tag/xnx_fields/xnx_attach_access/xnx_private_reply/xnx_lottery；已违反 1 次（xnx_private_reply 初版挂到 post_subject_after.htm，已迁移）
- ✅ **插件层获取用户信息用于显示时，用核心函数 `user_find_by_uids(implode(',', $uids))` / `user_read($uid)` / `user_read_cache($uid)`**（自动调用 `user_format()` 生成 `display_name`：`nickname` 优先，为空 fallback 到 `username`），禁止用 `db_find('user', ...)` 绕过核心层。模板显示用户名统一取 `$user['display_name']`，禁止直接取 `$user['username']`（`username` 是登录名，`nickname` 才是用户可修改的显示名）。已存在 `db_find('user')` 场景如需保留，必须在结果上手动 `user_format($user)` 补 `display_name` 字段

---

## 插件开发工作流

```
1. conf.json → 2. install.php → 3. uninstall.php → 3.5. upgrade.php（结构变更用） → 4. Service 类
→ 5. hook/ 注册文件 → 6. route/ 路由 → 7. view/htm/ 模板
→ 8. setting.php 后台 → 9. lang hook → 10. 清 tmp/ 测试
```

### Step 1: conf.json

> 完整字段表与 zip 打包规范见 [plugindev/02-plugin-structure.md](../../plugindev/02-plugin-structure.md)。本节只列核心字段与高频错误。

```json
{
    "name": "插件名称",
    "brief": "插件简介，支持 HTML",
    "version": "1.0.0",
    "bbs_version": "1.0",
    "installed": 0,
    "enable": 0,
    "hooks_rank": {
        "model_inc_file.php": 10,
        "thread_subject_after.htm": 10
    },
    "overwrites_rank": {
        "view/htm/header.inc.htm": 10
    },
    "dependencies": {
        "xn_search": "1.0"
    },
    "capabilities": [],
    "type": "plugin",
    "author": "作者",
    "id": "my_plugin"
}
```

**字段类型陷阱（高频写错）：**

| 字段 | 类型 | 错误写法 | 正确写法 |
|---|---|---|---|
| `version` | string | `"1.0"` | `"1.0.0"`（三位制 X.Y.Z） |
| `bbs_version` | string | `"1.0.0"` / `"4.5"` / `"2.0"` | `"1.0"` / `"1.1"`（两位制 X.Y，**当前 XIUNOX 核心为 1.x 系列，禁止写 4.5 或其他版本号**） |
| `hooks_rank` | object | `[]` / `{}` 空对象不展示用法 | `{"hook_name.php": 10}`（值大先执行，默认 0） |
| `overwrites_rank` | object | `[]` **（常见错误：写成数组）** | `{"view/htm/header.inc.htm": 10}`（值最大的覆盖文件生效，赢家通吃） |
| `dependencies` | object | `[]` **（常见错误：写成数组）** | `{"xn_search": "1.0"}`（key=依赖插件目录名，value=最低版本约束，支持 npm 风格 `>=`/`^`/`~`/`*`） |
| `capabilities` | array | `"lowercase.dots"` 字符串 | `["lowercase.dots"]`（权限沙箱声明，扫描器强制校验 `lowercase.dots` 格式） |

> ⚠️ **`bbs_version` 误判高发区**：XIUNOX 是基于 Xiuno BBS 4.x 的现代化分支，但 **`bbs_version` 字段值跟随 XIUNOX 核心版本号（1.x 系列），不是 Xiuno BBS 4.5**。合法取值示例：`"1.0"`、`"1.1"`。写成 `"4.5"` 会被核心兼容性校验拒绝。审查插件时遇到 `bbs_version: "1.x"` 是正确的，不要标记为错误。

**必需字段：** `name` / `brief` / `version` / `bbs_version` / `installed` / `enable`（后两个系统维护，勿手填）
**可选字段：** `hooks_rank` / `overwrites_rank` / `dependencies` / `capabilities` / `type`（默认 `"plugin"`）/ `author` / `id`

### Step 2: install.php（建表 + 默认设置）

```php
<?php
!defined('DEBUG') AND exit('Access Denied');
global $db;

db_exec("CREATE TABLE IF NOT EXISTS {$db->tablepre}my_plugin (
    id int unsigned NOT NULL AUTO_INCREMENT,
    uid int unsigned NOT NULL DEFAULT 0,
    content text NOT NULL,
    created int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

setting_set('my_plugin', [
    'enabled' => 1,
    'max_count' => 10,
]);

// 字段升级示例（幂等：检查字段是否存在再添加）
$cols = db_sql_find("SHOW COLUMNS FROM {$db->tablepre}my_plugin");
$has_new_field = false;
if ($cols) {
    foreach ($cols as $col) {
        if ($col['Field'] == 'new_field') $has_new_field = true;
    }
}
if (!$has_new_field) {
    db_exec("ALTER TABLE {$db->tablepre}my_plugin ADD COLUMN new_field varchar(255) NOT NULL DEFAULT '' AFTER content");
}

// 清理废弃字段（幂等）
if ($has_old_field) {
    db_exec("ALTER TABLE {$db->tablepre}my_plugin DROP COLUMN old_field");
}

// 清理 model.min.php 编译缓存，确保本插件 Service 类被加载
// 场景：插件文件升级后未重新启用，旧 model.min.php 不含本插件 model → fatal error 白屏
if (isset($conf['tmp_path']) && function_exists('xn_unlink')) {
    @xn_unlink($conf['tmp_path'].'model.min.php');
}
```

> ⚠️ **install.php 只负责首次安装的建表 + 默认配置**。数据库结构变更（加字段/改字段）必须走 `upgrade.php` 机制，禁止在 install.php 或 setting.php 加字段自愈代码。原因：存量用户覆盖代码后不重新安装时，install.php 不会执行，字段不会补齐导致 INSERT 失败。

### Step 3: uninstall.php（镜像清理）

```php
<?php
!defined('DEBUG') AND exit('Access Denied');
global $db;
db_exec("DROP TABLE IF EXISTS {$db->tablepre}my_plugin");
kv_delete('my_plugin');
```

> ⚠️ **卸载脚本文件名必须用 `uninstall.php`（标准拼写）**，禁止用 `unstall.php`（旧拼写）。核心 `admin/route/plugin.php` 卸载入口已改为优先找 `uninstall.php`，回退 `unstall.php`（向后兼容第三方旧插件）。用错文件名会导致卸载时不执行脚本（表不删、配置不清）。

### Step 3.5: upgrade.php（数据库结构迁移，幂等）

数据库结构变更（加字段/改字段/加索引）**必须**走 upgrade.php 机制，配合 conf.json.version 版本号递增，触发核心层「需升级」提示。流程：

1. 创建 `upgrade.php` 包含幂等字段迁移逻辑
2. 递增 `conf.json.version`（如 1.0 → 1.1）
3. 核心层 `plugin_list.htm` 对比 `conf.json.version` 与 `bbs_plugin.version` 不一致时显示「需升级」徽章+红色按钮
4. 用户点升级后执行 `upgrade.php` 并同步 `db.version` 为新版本

```php
<?php
!defined('DEBUG') AND exit('Access Denied');
global $db;

// upgrade.php 示例：为已安装用户补齐新字段（幂等，可重复执行）
$cols = db_sql_find("SHOW COLUMNS FROM {$db->tablepre}my_plugin");
if ($cols) {
    $col_names = array();
    foreach ($cols as $col) {
        $col_names[$col['Field']] = 1;
    }
    if (!isset($col_names['new_field'])) {
        db_exec("ALTER TABLE {$db->tablepre}my_plugin ADD COLUMN new_field varchar(255) NOT NULL DEFAULT '' AFTER content");
    }
    if (!isset($col_names['status'])) {
        db_exec("ALTER TABLE {$db->tablepre}my_plugin ADD COLUMN status tinyint(1) NOT NULL DEFAULT 0 AFTER new_field");
    }
}

// 可选：清理废弃字段（幂等）
if (isset($col_names) && isset($col_names['deprecated_field'])) {
    db_exec("ALTER TABLE {$db->tablepre}my_plugin DROP COLUMN deprecated_field");
}

// 清理 tmp 缓存（确保新代码生效）
plugin_clear_tmp_dir();
```

**注意事项：**
- ⚠️ upgrade.php 必须幂等（可重复执行不报错），用 `SHOW COLUMNS` 检查字段是否存在再 ALTER
- ⚠️ 每次结构变更都要递增 `conf.json.version`，否则存量用户看不到「需升级」提示
- ⚠️ 禁止在 install.php 或 setting.php 加字段自愈代码，统一走 upgrade.php
- ⚠️ 核心 `plugin_init()` 已幂等检测 `bbs_plugin.version` 字段并自动 ALTER 补齐，插件无需关心该字段是否存在

### Step 4: Service 类（静态方法模式）

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

class MyPluginService {
    public static function getSettings() {
        return setting_get('my_plugin');
    }

    public static function saveSettings($settings) {
        setting_set('my_plugin', $settings);
    }

    public static function create($uid, $content) {
        return db_insert('my_plugin', [
            'uid' => $uid,
            'content' => $content,
            'created' => time(),
        ]);
    }

    public static function findByUid($uid, $page = 1, $pagesize = 20) {
        $cond = ['uid' => $uid];
        $total = db_count('my_plugin', $cond);
        $list = db_find('my_plugin', $cond, ['created' => -1], $page, $pagesize, 'id');
        return ['list' => $list, 'total' => $total];
    }

    public static function delete($id) {
        return db_delete('my_plugin', ['id' => $id]);
    }
}
```

注册到 `hook/model_inc_file.php`：
```php
<?php exit;
APP_PATH.'plugin/my_plugin/model/MyPluginService.php',
```

### Step 5: Hook 文件（两种类型，开头写法不同，写错会白屏！）

| 类型 | 源码标记 | 文件扩展名 | 开头写法 | 原因 |
|---|---|---|---|---|
| PHP hook | `// hook xxx.php` | `.php` | `<?php exit;` | 防止直接访问 |
| 模板 hook | `<!--{hook xxx.htm}-->` | `.htm` | `<?php` | 编译拼进模板执行，`exit` 会白屏 |

**PHP hook（源码注入点，如 model/route/lang）：**
```php
<?php exit;
// 在帖子创建后处理
MyPluginService::doSomething($thread['tid']);
```

**HTM hook（模板注入点，编译拼进 .htm 模板执行）：**
```php
<?php
// 在帖子标题后注入内容（禁止用 <?php exit; 否则白屏！）
if (!empty($thread)) {
    $data = MyPluginService::getData($thread['tid']);
    if (!empty($data)) {
        echo '<span class="badge bg-secondary ms-2">' . esc_html($data['label']) . '</span>';
    }
}
?>
```

**路由 hook（PHP 类型）：**
```php
<?php exit;
case 'myplugin': include APP_PATH.'plugin/my_plugin/route/my_plugin.php'; break;
```

**语言 hook（PHP 类型）：**
```php
<?php
$lang['my_plugin_title'] = '我的插件';
$lang['my_plugin_save_ok'] = '保存成功';
```

### Step 6: route 文件

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

$action = param(1, 'list');
if($action == 'list') {
    $page = param(2, 1);
    $result = MyPluginService::findAll($page, 20);
    // 分页用 route_url()（带 {page} 占位符给 pagination() 函数替换）
    $pagination = pagination(route_url('myplugin_list', array()), $result['total'], $page, 20);
    // ... 设置变量后 include 模板
    include _include(APP_PATH.'plugin/my_plugin/view/htm/my_plugin_list.htm');
}
```

**注意：** 路由 `'myplugin_list'` 必须先通过 `hook/model_route_table_end.php` 注册到路由表（详见下方「路由表扩展」章节）。

### Step 7: setting.php（后台）

```php
<?php
!defined('DEBUG') AND exit('Access Denied');
$gid != 1 && $gid != 2 AND message(-1, '需要管理员权限');

if($method == 'POST' && !empty($_POST)) {
    CsrfService::check();
    $action = param('my_plugin_action', '');
    if($action == 'save_settings') {
        $settings = [
            'enabled' => intval(param('enabled', 1)),
            'max_count' => intval(param('max_count', 10)),
        ];
        MyPluginService::saveSettings($settings);
        // 优先用命名函数：admin_plugin_setting_url('my_plugin')
        // 或通用入口：route_url('admin_plugin_setting', ['dir' => 'my_plugin'])
        message(0, '设置已保存', ['redirect_url' => admin_plugin_setting_url('my_plugin')]);
    }
}

$settings = MyPluginService::getSettings();
include _include(APP_PATH.'plugin/my_plugin/view/htm/setting.htm');
```

### Step 8: setting.htm 表单

```html
<form method="post">
    <?php echo CsrfService::input();?>
    <input type="hidden" name="my_plugin_action" value="save_settings">
    <div class="mb-3">
        <label class="form-label">启用</label>
        <input type="number" name="enabled" class="form-control" value="<?php echo intval($settings['enabled']);?>">
    </div>
    <div class="mb-3">
        <label class="form-label">最大数量</label>
        <input type="number" name="max_count" class="form-control" value="<?php echo intval($settings['max_count']);?>">
    </div>
    <button type="submit" class="btn btn-primary">保存</button>
</form>
```

---

## 路由表扩展（核心路由系统）

XIUNOX 引入了集中式路由表 + 命名快捷函数架构。路由表定义在 `model/route.func.php` 中，模板和插件代码通过命名函数（`thread_url($tid)`）或 `route_url()` 通用入口生成 URL，最终调用底层 `url()` 处理伪静态格式。

### 调用链

```
thread_url($tid)            [命名快捷函数，优先用]
    ↓
route_url('thread', ['tid'=>$tid])   [通用入口]
    ↓
url('thread-' . $tid)       [底层 url()，处理伪静态格式]
```

### 路由表结构

路由表为 `key => 模板字符串` 的关联数组，模板字符串中用 `{xxx}` 占位符表示参数：

```php
'thread'         => 'thread-{tid}',
'thread_page'    => 'thread-{tid}-{page}',
'thread_like'    => 'thread-like-{tid}-{pid}',
'user_thread'    => 'user-thread-{uid}',
```

### 4 个 Hook 点

| Hook 文件 | 位置 | 用途 |
|---|---|---|
| `hook/model_route_start.php` | 路由文件开头 | 初始化路由相关变量 |
| `hook/model_route_table_end.php` | 路由表末尾 | **插件扩展路由主入口**（修改/新增 `$routes` 数组） |
| `hook/model_route_func_end.php` | 命名函数定义后 | 动态注册命名函数（不推荐，PHP 函数不能重复定义） |
| `hook/model_route_end.php` | 路由文件末尾 | 路由系统整体扩展 |

### 插件扩展路由示例

**新增插件路由：**
```php
// plugin/my_plugin/hook/model_route_table_end.php
<?php
$routes['myplugin']             = 'myplugin-{id}';
$routes['myplugin_list']        = 'myplugin-list';
$routes['myplugin_list_page']   = 'myplugin-list-{page}';
$routes['myplugin_detail']      = 'myplugin-{id}';
$routes['myplugin_create']      = 'myplugin-create';
```

**修改核心路由格式（SEO 优化示例）：**
```php
// plugin/seo/hook/model_route_table_end.php
<?php
// 将帖子 URL 从 thread-{tid} 改为 t-{tid}（更短更 SEO）
$routes['thread']      = 't/{tid}';
$routes['thread_page'] = 't/{tid}/{page}';
// 注意：修改核心路由格式需同步修改 .htaccess / nginx 规则
```

**注意事项：**
- ⚠️ 修改核心路由格式必须同步更新伪静态规则（`.htaccess` / nginx 配置）
- ⚠️ 路由表用静态缓存，hook 只在首次访问时执行，修改后需清 `tmp/` 缓存
- ⚠️ 不要在 hook 中调用 `route_table()`（会触发递归），直接修改 `$routes` 变量
- ⚠️ 不要在 hook 中使用 `route_url()` 或命名函数（同样会触发递归）

### 在模板和路由中使用

**模板中（推荐用命名函数）：**
```php
<!-- ✅ 推荐 -->
<a href="<?php echo thread_url($tid);?>">帖子标题</a>
<a href="<?php echo user_url($uid);?>">用户名</a>
<a href="<?php echo forum_url($fid, array('orderby'=>'lastpid'));?>">版块名</a>

<!-- ✅ 通用入口（路由表有但无命名函数时用） -->
<a href="<?php echo route_url('user_thread_page', ['uid'=>$uid, 'page'=>2]);?>">第 2 页</a>

<!-- ❌ 禁止：硬编码字符串拼接 -->
<a href="<?php echo url("thread-$tid");?>">帖子标题</a>
```

**路由 PHP 中：**
```php
// ✅ 推荐
message(0, '成功', ['redirect_url' => thread_url($tid)]);
$pagination = pagination(route_url('myplugin_list_page', []), $total, $page, 20);

// ✅ 保留 url() 的特殊场景
$pagination = pagination(url("thread-$tid-{page}$keywordurl"), $total, $page, $pagesize);
//  ↑ 复杂分页带额外变量，命名函数无法表达
```

### 何时保留 url() 调用

以下场景**保留 `url()` 调用**，不替换为命名函数：

1. **分页模板字符串带额外变量**：`url("thread-$tid-{page}$keywordurl")`（`$keywordurl` 为运行时变量）
2. **动态路由变量**：`url("$route", ...)`、`url("$route-{page}", ...)`（`$route` 为运行时变量）
3. **JS 字符串拼接**：`url("post-create") + '?tid=' + tid`（PHP 字符串里的 JS 拼接）
4. **后台预览示例**：`url('thread-123.htm')`（仅显示用，不参与路由）
5. **插件未注册到路由表的自定义路由**：未通过 hook 注册的路由直接调 `url()`

### 已注册的核心命名函数（部分）

| 函数 | 路由键 | 模板 |
|---|---|---|
| `thread_url($tid)` | thread | `thread-{tid}` |
| `thread_page_url($tid, $page)` | thread_page | `thread-{tid}-{page}` |
| `thread_create_url($fid=null)` | thread_create | `thread-create` / `thread-create-{fid}` |
| `thread_like_url($tid, $pid)` | thread_like | `thread-like-{tid}-{pid}` |
| `user_url($uid)` | user | `user-{uid}` |
| `forum_url($fid, $query=[])` | forum | `forum-{fid}` |
| `forum_page_url($fid, $page)` | forum_page | `forum-{fid}-{page}` |
| `post_create_url($tid, $page=null)` | post_create | `post-create-{tid}` |
| `admin_plugin_setting_url($dir)` | admin_plugin_setting | `plugin-setting-{dir}` |
| `admin_setting_url($section, $query=[])` | admin_setting | `setting-{section}` |

完整列表见 `model/route.func.php`（共 100+ 命名函数）。

---

## 快速 API 参考

> 基础 API（DB CRUD / 输入输出 / 安全 / 缓存设置 / 全局变量）见 [references/api-cheatsheet.md](references/api-cheatsheet.md)。本节只列**项目特有的进阶用法**。

### DB API 优先级（防 SQL 注入）

| 场景 | 首选 API | 备选（需加保留注释） |
|---|---|---|
| 单条/多条记录查询 | `db_find_one()` / `db_find()` | - |
| 聚合查询（GROUP BY + COUNT/SUM/MAX） | `db_find_one_group()` / `db_find_group()` | - |
| 计数 | `db_count()` | - |
| 插入/更新/删除 | `db_insert()` / `db_update()` / `db_delete()` | - |
| JOIN 查询 | - | `db_sql_find()` + 保留注释 |
| 系统表（INFORMATION_SCHEMA） | - | `db_sql_find_one()` + 保留注释 |
| 复杂 DML（GREATEST/子查询/INSERT IGNORE SELECT） | - | `db_exec()` + 保留注释 |

### 聚合查询示例（替代 `db_sql_find("SELECT uid, COUNT(*) ... GROUP BY ...")`）

```php
// 单条聚合：每个用户的勋章数
$row = db_find_one_group(
    'xo_user_medal',                 // 表名（不含前缀）
    [],                              // WHERE 条件
    ['uid'],                         // GROUP BY 字段
    ['cnt' => ['>' => 5]],           // HAVING 条件（格式同 WHERE）
    [],                              // ORDER BY
    ['uid', 'COUNT(*) as cnt']       // SELECT 字段（聚合字段必须用别名）
);

// 多条聚合：各版块发帖数 Top 10
$rows = db_find_group(
    'thread', [], ['fid'], [], ['cnt' => -1], 1, 10, 'fid', ['fid', 'COUNT(*) as cnt']
);
```

### 保留复杂 SQL 的注释规范

无法用 `db_find*` 替代的 JOIN/系统表/复杂 DML，必须在代码中添加包含 `保留 db_sql_find` 或 `保留 db_exec` 关键字的注释，扫描器会自动跳过后续 10 行的 direct_db 报告：

```php
// 联表查询，db_find 不支持 JOIN，保留 db_sql_find
$sql = "SELECT a.*, b.name FROM a LEFT JOIN b ON a.id=b.id";
$rows = db_sql_find($sql);  // 此行不会被扫描器报告
```

支持的关键字格式（正则 `/(保留|@suppress).*db_(?:sql_find|sql_find_one|exec)/`）：
- `// 保留 db_sql_find` / `// 保留 db_sql_find_one` / `// 保留 db_exec`
- `// @suppress db_sql_find`（英文 @suppress 标记）

> 注释必须放在 `db_*` 调用上方 10 行以内，覆盖多行 SQL 拼接。无保留注释的 `db_sql_find` / `db_exec` 会被扫描器报告为 info 级别问题。

### 缓存版本号机制（列表类缓存推荐）

```php
// 版本号机制（适用于列表类缓存，数据变更时递增版本号使旧缓存自动失效）
$version = CacheHelper::remember('list_v_' . $id, 86400, function() { return 1; });
$list = CacheHelper::remember('list_' . $id . '_v' . $version, 60, function() use ($id) {
    return db_find('my_table', array('pid'=>$id));
}, 'myplugin');

// 数据变更时递增版本号
CacheHelper::set(CacheHelper::pluginKey('list_v_' . $id), $version + 1, 86400);
```

### 保留 url() 的特殊场景

以下场景**保留 `url()` 调用**，不替换为命名函数：

1. **分页模板字符串带额外变量**：`url("thread-$tid-{page}$keywordurl")`（`$keywordurl` 为运行时变量）
2. **动态路由变量**：`url("$route", ...)`、`url("$route-{page}", ...)`（`$route` 为运行时变量）
3. **JS 字符串拼接**：`url("post-create") + '?tid=' + tid`（PHP 字符串里的 JS 拼接）
4. **后台预览示例**：`url('thread-123.htm')`（仅显示用，不参与路由）
5. **插件未注册到路由表的自定义路由**：未通过 hook 注册的路由直接调 `url()`

---

## Hook 选择速查

| 需求 | Hook |
|---|---|
| 全局 CSS | `header_link_after.htm` |
| 全局 JS | `footer_js_after.htm` |
| 帖子标题后 | `thread_subject_after.htm` |
| 列表标题后（4种视图） | `thread_list_inc_subject_after.htm` + masonry + timeline + card |
| 楼层用户旁 | `post_user_after.htm` |
| 发帖后处理 | `thread_create_thread_end.php` |
| 编辑后处理 | `post_update_post_start.php` |
| 删帖级联 | `model_thread_delete_end.php` |
| 注册 Service | `model_inc_file.php` |
| 注册路由 | `index_route_case_end.php` |
| 首页组件 | `index_site_brief_after.htm` |
| 语言扩展 | `lang_zh_cn_bbs.php` |
| 后台路由 | `admin_index_route_case_end.php` |
| 顶部导航用户菜单前 | `header_nav_user_menu_before.htm` |
| 个人中心导航末尾 | `user_nav_end.htm` |
| 帖子主题用户名后 | `thread_info_end.htm` |
| 回帖用户名后 | `post_list_inc_username_after.htm` |
| **发布页功能区块**（引用帖子后） | `post_ref_thread_after.htm` |

## 用户封禁系统（UserBanService + XnEvent）

XIUNOX 核心内置用户封禁系统，提供 4 档状态（正常/禁言/禁止访问/锁定）+ 场景化检查 + IP 黑名单 + 版主权限分级 + 事件扩展。完整文档见项目 `xiunobbs-master/doc/user-ban-system.md`。

### 核心 API（lib/UserBanService.php，静态调用）

```php
// 必须先 include_once（生产环境 min.php 类加载顺序不可预测）
if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }

// 封禁用户（禁言 7 天）
$result = UserBanService::ban($uid, UserBanService::BAN_TYPE_SILENCE, 86400*7, '广告灌水', $adminUid);
// 返回 ['code'=>0 成功, 'message'=>错误信息]

// 解封
UserBanService::unban($uid, $adminUid, '申诉通过');

// 检查状态（含到期自动解封）
$status = UserBanService::checkBan($uid);  // ['banned'=>bool, 'ban_type'=>int, ...]

// 按场景检查（入口拦截用）
$check = UserBanService::checkBanByScene($uid, 'post');  // login/browse/post/password
if(!$check['allowed']) { message(-1, $check['message']); }

// 清空用户内容（保留账号）
UserBanService::clearContent($uid, $adminUid);

// 获取格式化状态（前端显示）
$status = UserBanService::getBanStatus($uid);  // 含 status_label/status_color
```

### 常量

```php
UserBanService::BAN_TYPE_NORMAL     = 0;  // 正常
UserBanService::BAN_TYPE_SILENCE     = 1;  // 禁言（可浏览，不能发帖回帖）
UserBanService::BAN_TYPE_BAN_ACCESS  = 2;  // 禁止访问（不能登录、不能浏览）
UserBanService::BAN_TYPE_LOCK        = 3;  // 锁定（不能登录、不能改密找密）
UserBanService::PERMANENT_BAN        = 9999999999;  // 永久封禁时间戳（约2286年，避免32位溢出）
UserBanService::ADMIN_GIDS           = [1, 2];      // 管理员组（不可被封禁）
```

### XnEvent 事件机制（lib/XnEvent.php）

轻量事件机制，静态调用，回调异常不中断主流程。事件名约定 `ClassName.methodName`，回调签名 `function(&$args)`（参数引用，可修改后传递给主流程）。

```php
// 注册监听器（建议在 plugin/<dir>/hook/model_inc_start.php 注册，最早的 hook）
XnEvent::on('UserBanService.beforeBan', 'my_plugin', function(&$args) {
    // $args 含 uid/banType/duration/reason/adminUid（引用，可修改）
    if($args['duration'] > 0 && $args['duration'] < 86400) {
        $args['duration'] = 86400 * 7;  // 自动延长至 7 天
    }
});

// 触发（核心代码已内置，插件不需要主动触发）
// XnEvent::trigger('UserBanService.beforeBan', $args);

// 卸载时清理（uninstall.php）
XnEvent::off(null, 'my_plugin');
```

### UserBanService 触发的 7 个事件

| 事件名 | 触发时机 | 可修改参数 |
|---|---|---|
| `UserBanService.beforeBan` | 封禁前 | `banType` / `duration` / `reason` |
| `UserBanService.afterBan` | 封禁后 | 只读，含 `bannedUntil` |
| `UserBanService.beforeUnban` | 解封前 | `reason` |
| `UserBanService.afterUnban` | 解封后 | 只读 |
| `UserBanService.beforeClearContent` | 清空前 | `uid` / `adminUid` |
| `UserBanService.afterClearContent` | 清空后 | 只读 |
| `UserBanService.bannedListDisplay` | 公示页渲染时 | `current_list` / `recent_list` |

### PHP hook 点（模板/路由级扩展）

| Hook 文件 | 位置 | 用途 |
|---|---|---|
| `user_ban_check.php` | `route/user.php` / `route/thread.php` / `route/post.php` / `route/my.php` / `index.inc.php` | 自定义封禁检查（如第三方风控判定） |
| `banned_ip_check.php` | `route/user.php` login/create / `route/thread.php` create | 自定义 IP 检查（如外接 IP 信誉库） |
| `banned_list_display.php` | `route/banned.php` | 修改封禁公示页列表数据 |

### 插件接入规范

1. **调用 UserBanService 必须 `if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }` 守卫前置**，访问静态属性（如 `ADMIN_GIDS`）之前必须先确保类已加载。生产环境 DEBUG=0 走 `tmp/model.min.php` 合并加载，类加载顺序不可预测，不 include 会抛 `Class not found`
2. **注册事件监听器用 `model_inc_start.php` hook**（最早的 hook 点，确保在 UserBanService 调用前已注册）
3. **卸载时必须 `XnEvent::off(null, 'my_plugin')` 清理监听器**，否则卸载后回调仍会被触发
4. **修改 `ban_type` 等字段用 `user_update()` 而非 `user__update()`**：这些字段不在 `USER_UPDATE_PROTECTED_FIELDS` 中，前者会自动 cache_delete + 触发 hook
5. **永久封禁时间戳用 `9999999999`**（约 2286 年），禁用 `PHP_INT_MAX`（32 位系统溢出）
6. **不可封禁管理员组（gid=1,2）和封禁操作者自己**（UserBanService 已内置校验，插件无需重复）
7. **封禁原因等字符串参数 `param()` 第 3 参数传 `FALSE`** 关闭 htmlspecialchars
8. **IP 字段写入用 `ip2long()` + `sprintf('%u', ...)` 转整型**，禁用 `intval("ip字符串")`（只返回第一段）
9. **IP 黑名单检查新代码用 `IpBlacklistService::is_blacklisted($ip)`**（位于 `lib/security/IpBlacklistService.php`），`banned_ip_check()` 函数已废弃仅保留兼容
10. **场景化检查不要重复实现**：用 `UserBanService::checkBanByScene($uid, $scene)`，scene 取值 `login`/`browse`/`post`/`password`

### 典型场景示例

```php
// 场景 1：风控插件自动延长高风险用户封禁时长
XnEvent::on('UserBanService.beforeBan', 'risk_control', function(&$args) {
    if(RiskControlService::getRiskLevel($args['uid']) === 'high') {
        $args['duration'] = max($args['duration'], 86400 * 30);
        $args['reason'] .= '（风控自动延长）';
    }
});

// 场景 2：解封后清理插件自身数据
XnEvent::on('UserBanService.afterUnban', 'my_plugin', function(&$args) {
    db_delete('my_plugin_ban_data', array('uid' => $args['uid']));
});

// 场景 3：插件中调用封禁服务
if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
$result = UserBanService::ban($uid, UserBanService::BAN_TYPE_SILENCE, 86400*3, $reason, $adminUid);
if($result['code'] != 0) { message(-1, $result['message']); }

// 场景 4：自定义 IP 检查（外接 IP 信誉库）
// plugin/ip_reputation/hook/banned_ip_check.php
// 此 hook 在 banned_ip_check($ip) 落地后调用
$reputation = IpReputationService::query($ip);
if($reputation['score'] < -50) {
    if(!class_exists('IpBlacklistService')) { include_once APP_PATH.'lib/security/IpBlacklistService.php'; }
    IpBlacklistService::add($ip, $ip, 'IP信誉库自动拦截', 0, 86400*7);
    message(-1, lang('user_ban_ip_banned'));
}
```

### 交付检查表（封禁系统相关）

- [ ] 调用 UserBanService 前已 `if(!class_exists(...)) { include_once ... }` 守卫
- [ ] 访问静态属性/常量（如 `ADMIN_GIDS`）之前已确保类加载
- [ ] 注册的 XnEvent 监听器在 uninstall.php 中用 `XnEvent::off(null, '插件名')` 清理
- [ ] IP 字段写入用 `ip2long()` 转整型，不用 `intval("ip")`
- [ ] IP / 原因等参数 `param()` 第 3 参数传 `FALSE`
- [ ] 新增入口级安全检查时用 Grep 全局搜索所有同类入口逐个补齐
- [ ] 永久封禁用 `9999999999` 不用 `PHP_INT_MAX`
- [ ] 修改 `ban_type` 等非保护字段用 `user_update()` 不用 `user__update()`

---

### 发布页 Hook 布局说明

发帖/回帖页（post.htm）的侧边栏区域有以下固定区块顺序：

```
┌─ 桌面端右侧栏 ──────────────┐  ┌─ 手机端（编辑器下） ────────┐
│ 积分消耗提示（仅主帖）       │  │ 积分消耗提示（仅主帖）     │
│ 引用帖子（仅主帖）           │  │ 引用帖子（仅主帖）         │
│ ← post_ref_thread_after.htm →│  │ ← post_ref_thread_after.htm→│
│ post_sidebar_bottom.htm      │  │                            │
└──────────────────────────────┘  └────────────────────────────┘
```

**`post_ref_thread_after.htm`** 是发布页插件功能的首选 hook 点：
- 位于引用帖子卡片之后，桌面端和手机端共用同一个 hook 名
- 适合插入：话题标签、投票、附件选项、楼主可见等发帖辅助功能
- **必须使用与"引用帖子"卡片一致的样式**（PC 端），禁止自创卡片结构：
  ```html
  <div class="x-card card">
      <div class="card-header bg-transparent border-bottom-0 py-2">
          <h6 class="mb-0 fw-semibold"><i class="ti ti-xxx me-1"></i><?php echo lang('xxx_title');?></h6>
      </div>
      <div class="card-body pt-0">
          <!-- 功能内容 -->
      </div>
  </div>
  ```
- 手机端用 `post_ref_thread_after_mobile.htm`（表单内，`d-xl-none` 容器），不渲染 card-header，仅保留 card-body（手机端已在表单流中，无需卡片标题）
- 桌面/手机端共用同一个 htm 模板时，用全局计数器 `$GLOBALS['xxx_hook_idx']` 区分实例（0=mobile 先执行，1=desktop 后执行），通过 `$_suffix === 'd'` 判断是否渲染 header
- 已遵循此规范的插件：xnx_tag、xnx_fields、xnx_attach_access、xnx_private_reply、xnx_lottery

**完整 Hook 目录** → 读 `references/hooks-catalog.md`

---

## AIEditor 富文本编辑器集成

### 编辑器实例

AIEditor 初始化后存储在 `window.aiEditorInstance`，提供以下常用 API：

```javascript
var editor = window.aiEditorInstance;
editor.getHtml();          // 获取 HTML 内容
editor.getSelectedText();  // 获取选中文本（纯文本）
editor.insert(htmlString); // 在光标处插入 HTML（替换选区）
editor.focus();            // 聚焦编辑器
editor.setContent(html);   // 替换全部内容
```

### 在工具栏添加按钮

AIEditor 工具栏在初始化时通过 `toolbarKeys` 数组配置（**字段名必须是 `toolbarKeys`，不是 `toolbar`！**），**没有 `addToolbarButton` 方法**。AIEditor 源码 `tf` 类的 `onCreate` 读取 `r.toolbarKeys || sR`——写成 `toolbar` 会被忽略，整个自定义配置不生效，AIEditor 会回退到默认配置 `sR`，所有自定义按钮都不会被创建。

#### 方式 1：CustomMenu 配置法（推荐，初始化时配置）

在 EditorService.php 初始化 AIEditor 时把自定义按钮对象直接放入 `toolbarKeys` 数组：

```javascript
// 自定义按钮对象（CustomMenu）字段
var myBtn = {
    icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M2.9918 21C2.44405 21 2 20.5551 2 20.0066V3.9934C2 3.44476 2.45531 3 2.9918 3H21.0082C21.556 3 22 3.44495 22 3.9934V20.0066C22 20.5552 21.5447 21 21.0082 21H2.9918Z"></path></svg>',
    onClick: function(event, editor) {
        editor.focus();
        editor.insert('<img src="..." style="max-width:100%">');
    },
    tip: '提示文字',
    name: 'my-btn'
};

// ✅ 正确：toolbarKeys 数组中放对象
new AiEditor({
    toolbarKeys: ['bold','italic','image', myBtn, 'code'],
    // ❌ 错误：写成 toolbar 会导致所有自定义按钮不创建
});

// html 字段优先级高于 icon（设置后会整体覆盖 icon）
var htmlBtn = { html: '<div style="height:16px">@</div>', onClick: fn };
```

#### SVG 图标规范（关键！）

AIEditor 的 CSS 规则会**强制覆盖 SVG 的 fill 属性**：
```css
.aie-container aie-header .aie-menu-item svg {
    fill: var(--aie-menus-svg-color);
    width: 16px;
    height: 16px;
}
```

- ✅ **优先用 fill 模式 SVG**：`<path d="...">` 不带 `fill="none"`，path 自带填充语义，被 CSS 覆盖 fill 后仍正常显示
- ❌ **禁用 stroke 模式 SVG**：`fill="none" stroke="currentColor"` 的 SVG 被 CSS 强制设置 fill 后，会变成实心色块导致图标完全不可见
- 可参考 AIEditor 内置 image 按钮的 SVG（viewBox="0 0 24 24"，path 不带 `fill="none"`），fill 模式典范

#### 方式 2：DOM 注入法（备选，运行时动态添加）

等编辑器渲染后手动在 `.aieditor-toolbar` 容器中插入按钮元素（不推荐，复杂场景才用）：

```javascript
// DOM 注入法示例
function addCustomBtn() {
    if (document.getElementById('my-btn')) return;
    var toolbar = document.querySelector('.aieditor-toolbar');
    if (!toolbar) { setTimeout(addCustomBtn, 300); return; }

    var btn = document.createElement('div');
    btn.id = 'my-btn';
    btn.className = 'aieditor-toolbar-item';
    btn.style.cursor = 'pointer';
    btn.innerHTML = '<i class="ti ti-icon-name"></i>';
    btn.addEventListener('click', function() { /* 处理逻辑 */ });
    toolbar.appendChild(btn);
}
setTimeout(addCustomBtn, 800); // 等编辑器初始化完成

// 用 MutationObserver 监听编辑器重新初始化
var observer = new MutationObserver(function() {
    if (document.querySelector('.aieditor-toolbar') && !document.getElementById('my-btn')) {
        addCustomBtn();
    }
});
var wrap = document.getElementById('aieditor-container');
if (wrap) observer.observe(wrap, { childList: true, subtree: true });
```

#### 调试工具栏按钮不显示（必须）

光看代码无法确认按钮是否真的被创建，**必须用 agent-browser 实际访问页面 eval DOM**：

```bash
# 1. 确认自定义按钮是否被创建（aie-custom 元素数量）
agent-browser eval "document.querySelectorAll('aie-custom').length"

# 2. 检查 toolbarKeys 配置是否生效（undefined 说明字段名写错）
agent-browser eval "window.aiEditorInstance.options.toolbarKeys"

# 3. 检查 SVG 计算样式（确认是否被 CSS 覆盖成实心块）
agent-browser eval "const s=document.querySelector('aie-custom svg'); JSON.stringify({size: s.getBoundingClientRect(), fill: getComputedStyle(s).fill})"

# 4. 检查元素 innerHTML 长度（确认是否有内容）
agent-browser eval "[...document.querySelectorAll('aie-custom')].map(e => ({tip: e.getAttribute('data-title'), html_len: e.innerHTML.length, has_svg: e.querySelector('svg') !== null}))"
```

**典型故障排查顺序**：
1. `aie-custom` 数量为 0 → 字段名写错（`toolbar` 应为 `toolbarKeys`），或按钮对象未放入数组
2. `aie-custom` 数量正确但 SVG 不可见 → SVG 用了 stroke 模式，被 CSS `fill` 覆盖成实心块，改用 fill 模式
3. 按钮可见但 tooltip 不显示 → `tip` 字段未生效，检查 AIEditor 版本是否支持

### 插入自定义内容到编辑器

富文本编辑器中插入内容必须用 HTML 格式，不能用 BBCode 或纯文本：

```javascript
// 正确：插入 HTML
editor.focus();
editor.insert('<div class="my-block" data-type="xxx">内容</div>');

// 同步到隐藏 input（表单提交用）
var hiddenInput = document.getElementById('message');
hiddenInput.value = editor.getHtml();
```

### 后端解析自定义 HTML 标签

如果插件在编辑器中插入了带 `data-*` 属性的自定义 HTML，后端解析时需要：

1. **HTMLPurifier 白名单**：在 `xiunophp/xn_html_safe.func.php` 的 `HTML.Allowed` 配置中为对应标签添加 `data-*` 属性
   - 默认配置：`div[class|style]`（不允许 data-* 属性）
   - 添加 data 属性：`div[class|style|data-type|data-params]`
2. **解析正则**：用 `preg_match_all` 匹配自定义 HTML 标签，提取 `data-*` 属性和内容

### 发帖页 Hook 选择

| 需求 | Hook | 说明 |
|------|------|------|
| 工具栏按钮 JS | `post_js.htm` | 页面底部 JS 区域，用 `if` 包裹不能用 `return` |
| 模态框 HTML | `post_end.htm` | 页面底部 HTML 区域 |
| 侧边栏功能 | `post_ref_thread_after.htm` | 引用帖子卡片下方 |
| 数据准备 | `post_start_init.htm` | PHP 代码块中，不能用 `return`/`?>` |

> ⚠️ **发帖/回复场景区分**：以上 4 个 hook 共用 `view/htm/post.htm` 模板，**同时编译进发帖页（`thread-create`）和高级回复页（`post-create`）**。若功能只需发帖场景，hook 必须加 `if ($route == 'thread' && $action == 'create') { ... }` 判断，否则回复页也会加载不需要的模块（已违反 1 次：xnx_hidden 回复页右侧显示隐藏内容卡片）。判断变量 `$route`/`$action` 由 `index.inc.php` 路由解析时设置，`post.htm` 顶部已用此组合区分发帖/回复/编辑三种场景。

---

## 交付检查表

- [ ] conf.json 必填字段完整
- [ ] install.php 有 `IF NOT EXISTS` 幂等
- [ ] **卸载脚本文件名用 `uninstall.php`（标准拼写），禁止 `unstall.php`（旧拼写）**
- [ ] uninstall.php 删表 + 删 KV
- [ ] **数据库结构变更走 upgrade.php 幂等迁移（不在 install.php/setting.php 加字段自愈代码）**
- [ ] **结构变更后递增 conf.json.version 版本号，触发核心层「需升级」提示**
- [ ] **upgrade.php 用 SHOW COLUMNS + ALTER TABLE 幂等逻辑（可重复执行不报错）**
- [ ] PHP hook 有 `<?php exit;`
- [ ] hook 文件名含扩展名匹配
- [ ] 所有 POST 有 `CsrfService::input()` + `CsrfService::check()`
- [ ] 所有输出用 `esc_html()` / `esc_attr()`
- [ ] 无 jQuery / Alpine.js
- [ ] 命名全带前缀
- [ ] 列表 4 种视图都注册了（如需）
- [ ] 删帖有级联清理
- [ ] JS/CSS 放在 `static/` 目录（非 `view/htm/`）
- [ ] `<script src>` / `<link href>` 用 `$conf['view_url']` 而非 `APP_PATH` 或相对路径 `../`
- [ ] 跨插件共享配置的保存和读取使用同一个存储键
- [ ] `setting_set/get` 直接存取数组，不用 `xn_json_encode/decode` 中转
- [ ] 开发前检索项目已有组件（如 TablerIconPicker），复用优先于自建
- [ ] 注册表/默认配置中文本用 `lang()` 多语言键，不硬编码中文
- [ ] **`.htm` hook 文件以 `<?php` 开头（不是 `<?php exit;`）**
- [ ] **hook 文件扩展名和源码标记一致**（`.htm` 模板 / `.php` 源码）
- [ ] **conf.json hooks_rank 键名和 hook 文件名完全一致**（含扩展名）
- [ ] 数据库表用 `utf8mb4`（非 `utf8`）
- [ ] install.php 字段升级幂等（`SHOW COLUMNS` + `ALTER TABLE`）
- [ ] **install.php 末尾清理 `tmp/model.min.php`（防 Service 类未加载导致白屏）**
- [ ] setting.php 有管理员权限检查
- [ ] SQL 中用户输入已 `intval()` 转义
- [ ] **优先使用 `db_find` / `db_find_group` 替代 `db_sql_find`**（普通查询和聚合查询都有封装 API）
- [ ] **保留的复杂 SQL（JOIN/系统表/复杂 DML）已加 `// 保留 db_sql_find` 或 `// 保留 db_exec` 注释**（扫描器据此跳过，10 行抑制区间）
- [ ] 帖子列表页 hook 用静态缓存（非每次 new + 查库）
- [ ] `db_insert` 后检查返回值或确认字段存在
- [ ] 新功能已添加导航/个人中心入口 hook
- [ ] `esc_attr(json_encode(...))` 拆成两步写
- [ ] **PHP 单行注释（`//`、`#`）中无 `?>`**：会触发 headers already sent，页面直接显示代码
- [ ] 无硬编码 URL 后缀（`.htm`/`.html`），所有 URL 用 `url()` 函数生成
- [ ] 缓存刷新/跳转等用 `$site_url . url("xxx")` 而非 `$site_url . '/xxx.htm'`
- [ ] **模板中 URL 用命名快捷函数（`thread_url()`）或 `route_url()`，禁止 `url("thread-$tid")` 字符串拼接**
- [ ] **插件自定义路由通过 `hook/model_route_table_end.php` 注册到 `$routes` 数组**
- [ ] **分页 URL 用 `route_url('xxx_page', [])`（保留 `{page}` 占位符给 `pagination()` 函数）**
- [ ] **修改核心路由格式后同步更新 `.htaccess` / nginx 伪静态规则，并清 `tmp/` 缓存**
- [ ] **插件数据缓存用 `CacheHelper::remember()` 而非裸 `cache_get/cache_set`**
- [ ] **清除插件缓存用 `CacheHelper::pluginDeletePrefix()` 而非枚举 limit 值逐个 `cache_delete()`**
- [ ] **缓存键通过 `CacheHelper::pluginKey()` 生成带 `p_{plugin}_` 前缀的键名**
- [ ] **Service 类构造函数调用 `CacheHelper::registerKeys()` 注册缓存键清单**
- [ ] **密码/Token 等敏感配置 `param()` 传第 3 参数 `FALSE` 关闭 htmlspecialchars**
- [ ] **列表类缓存用版本号机制（数据变更时递增版本号使旧缓存自动失效）**
- [ ] **插件 Service 调用核心 Service（CreditsService 等）前 `include_once` 对应文件（生产环境 min.php 类加载顺序不可预测）**
- [ ] **前台代码判断插件启用状态用 `plugin_paths_enabled()` 或读 `plugin/<dir>/conf.json`，禁止 `global $plugins`（前台未初始化）**
- [ ] **PHP 向 JS 传递 AIEditor 自定义按钮配置时，确认 implode/json_encode 实际输出格式（前者无引号为变量引用，后者带引号为字符串数组），自定义按钮必须以对象形式进入 toolbarKeys**
- [ ] **AIEditor 工具栏配置用 `toolbarKeys`（不是 `toolbar`），写成 `toolbar` 会导致所有自定义按钮不创建**
- [ ] **AIEditor 自定义按钮 SVG 用 fill 模式（path 不带 `fill="none"`），禁用 stroke 模式（会被 CSS `fill` 覆盖成实心块不可见）**
- [ ] **AIEditor 按钮不显示时用 agent-browser eval 检查 `document.querySelectorAll('aie-custom').length` 确认按钮是否被创建，检查 `window.aiEditorInstance.options.toolbarKeys` 是否为 undefined**
- [ ] **Card 组件加 `x-card` class，禁止裸用 `border` / `border-*` 工具类（包括列表项 border-bottom）；所有 border 尽量不用，分隔改用 `py-*`/`mb-*`/`mt-*`/`gap-*` 等间距工具类**
- [ ] **插件获取用户信息用于显示时用 `user_find_by_uids()` / `user_read()` / `user_read_cache()` 等核心函数（自动生成 `display_name`），禁止用 `db_find('user', ...)` 绕过核心层后直接取 `username` 字段；模板显示用户名统一取 `display_name` 字段**
- [ ] **改动 `plugin/<dir>/static/js|css/` 下文件后若 `static_version` 未递增**，必测时提示用户硬刷新（Ctrl+F5 / Cmd+Shift+R），否则浏览器加载 HTTP 缓存的旧 JS 导致修改不生效（07-17 已违反 1 次：shim 修复后不刷新不生效）
- [ ] **改动 `.htm` 模板后清 `tmp/view_htm_*.htm` 编译缓存**：`_include()` 不比较源文件 mtime，只检查 tmpfile 是否存在，源文件改了不清缓存则修改不生效。批量清理：`rm -f tmp/view_htm_*.htm tmp/admin_view_htm_*.htm`（已违反多次）
- [ ] **所有 hook 文件禁止 `return;`（路由层 + model 层 + view 层全适用）**：hook 编译期内联到宿主，`return` 会从宿主返回，跳过后续逻辑。已违反 4 次（路由层 3 次 + model 层 1 次 pid 丢失 + model 层 runlevel 拦截失效）。例外：终止性操作允许 `exit;` 但必须 if 包裹 + `// ponytail:` 注释说明
- [ ] **发帖/回复共用 `post.htm` 的 hook 按场景区分**：`post_ref_thread_after.htm`/`post_ref_thread_after_mobile.htm`/`post_end.htm`/`post_js.htm` 同时编译进发帖页和回复页，只需发帖场景的功能必须加 `if ($route == 'thread' && $action == 'create')` 判断，避免回复页加载不需要的模块
- [ ] **发帖页新增功能挂到 `post_ref_thread_after.htm`（PC 右侧栏）+ `post_ref_thread_after_mobile.htm`（手机端）**：禁止挂到 `post_subject_after.htm`（标题下方）或 `post_message_after.htm`（编辑器下方）。卡片样式必须与"引用帖子"卡片一致：`x-card card` + `card-header bg-transparent border-bottom-0 py-2` + `<h6 class="mb-0 fw-semibold"><i class="ti ti-xxx me-1"></i>标题</h6>` + `card-body pt-0`。手机端不渲染 card-header，仅 card-body
- [ ] **所有 `static/*.js` 文件中无 `<?php` 代码**（用 Grep 检查 `<\?php|lang\(` 确认，JS 文件是纯静态不会被 PHP 解析，翻译字符串通过引入 JS 的 hook 模板注入 `window.XXX_I18N` 全局对象）
- [ ] **后台插件模板（setting.htm / xxx_admin.htm）首尾 include `_include(ADMIN_PATH . 'view/htm/header.inc.htm')` 和 `footer.inc.htm`**（前台插件模板用 `APP_PATH . 'view/htm/header.inc.htm'`）
- [ ] **后台表单提交用 fetch 拦截 + 按钮 loading spinner + toast 反馈**（避免页面跳转，下拉框只有 1 个选项时直接删除）

---

## 何时读 References / plugindev

> **`plugindev/` 完整手册下载地址**：https://github.com/domidesign/xiunox/tree/main/docs/plugindev
> 若本项目 `docs/plugindev/` 目录不存在，从该 GitHub 仓库下载后放到 `docs/plugindev/` 即可使用下表全部交叉引用。未下载时，本目录 [references/](references/) 下 3 个速查文档仍可独立使用，覆盖 80% 日常开发需求。

| 情况 | 读 |
|---|---|
| 需要找精确的 hook 注入点 | [references/hooks-catalog.md](references/hooks-catalog.md) 或 [plugindev/03-hooks-catalog.md](../../plugindev/03-hooks-catalog.md)（完整版） |
| 需要查 API 签名/参数细节 | [references/api-cheatsheet.md](references/api-cheatsheet.md) 或 [plugindev/04-api-cheatsheet.md](../../plugindev/04-api-cheatsheet.md)（完整版） |
| 不确定某条规则是否正确 | [references/ai-rules.md](references/ai-rules.md)（检查流程）或 [plugindev/06-ai-collaboration.md](../../plugindev/06-ai-collaboration.md)（规则详情） |
| 查所有已注册的命名快捷函数 | `model/route.func.php` 源码 |
| 需要理解插件架构原理 | [plugindev/01-architecture.md](../../plugindev/01-architecture.md) |
| 需要查 conf.json 完整字段表 / zip 打包规范 | [plugindev/02-plugin-structure.md](../../plugindev/02-plugin-structure.md) |
| 需要查前端 / 安全 / htmx 4 事件名 | [plugindev/05-frontend-security.md](../../plugindev/05-frontend-security.md) |
| 需要查运行时安全 / 崩溃自动禁用 | [plugindev/07-runtime-safety.md](../../plugindev/07-runtime-safety.md) |
| 需要查登录安全 / 账号锁定 | [plugindev/08-login-security.md](../../plugindev/08-login-security.md) |
| 需要完整手册入口 | [plugindev/README.md](../../plugindev/README.md) |
