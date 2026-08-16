---
name: xiunox-plugin-dev
description: XIUNOX (Xiuno BBS X) 插件开发专家。当用户需要为 Xiuno BBS X 开发、调试、修复插件时使用，涵盖插件架构、hook 注册、API 调用、前端集成、安全规范、安装/卸载脚本、Service 类、路由扩展等。涉及创建新插件、修改现有插件、加 hook/路由/后台设置页、写 install/uninstall/upgrade 脚本、排查插件不生效/hook 失效/扫描器拦截、修复 fatal/warning、将旧插件从 jQuery/Alpine.js 迁移到 htmx 4 + 原生 JS 架构时触发。
---

# Xiuno BBS X 插件开发 Skill

> 本 Skill 是精简入口，深入细节查 `references/`（速查）和 `../plugindev/`（完整手册）。写代码时对照本文件的硬规则与工作流。

## When to Use

### ✅ 使用本 Skill

- 用户要求为 Xiuno BBS X（XIUNOX）写新插件、改插件、调试插件、加 hook/路由/后台设置页
- 用户要求加签到/标签/勋章/举报/收藏/投票/抽奖/悬赏等新功能插件
- 用户要求创建 Service 类、写 install/uninstall/upgrade 脚本、配置 conf.json
- 用户报告插件不生效、hook 不触发、修改后无变化、白屏、扫描器拦截 fatal/warning
- 用户要求将旧插件从 jQuery / Alpine.js 迁移到 htmx 4 + 原生 JS
- 用户要求做插件架构设计、查 hook 注入点、查 API 签名、查前端模式
- 用户要求排查插件冲突、互斥规则、目录命名规范问题
- 用户要求接入 DiscoverService / NavService / EditorService / UserBanService 等系统级服务
- 用户要求做主题插件 / 改前台版式 / 换肤样式（仿某 App 风格的主题模板，`type:"theme"`）

### ❌ 不要使用本 Skill

- 普通 PHP 编码问题、与 Xiuno BBS 插件架构无关的 Web 开发任务
- 修改 Xiuno BBS 核心源码（`model/`、`lib/`、`route/`、`xiunophp/`）本身而非通过插件扩展
- 仅查询 Xiuno BBS 使用方法（如后台操作、发帖流程），不涉及代码开发
- 部署运维（nginx 配置、数据库备份、PHP 环境搭建）问题

## 核心架构要点

1. **编译期合并 hook，无运行时事件分发。** 把文件放到 `plugin/<dir>/hook/<hook名>` 即注册 hook，没有 `add_hook()` 函数。`_include()` 编译时把 hook 内容物理拼进源文件，缓存到 `tmp/`。改 hook 后必须清 `tmp/`。
2. **hook 文件名（含扩展名）必须与源标记完全一致。** PHP 源码标记 `// hook thread_create_thread_end.php`；模板标记 `<!--{hook thread_subject_after.htm}-->`。
3. **模型三层命名：** `__` 原始层（纯 DB）→ 单 `_` 业务层（缓存/计数/通知/hook）→ `_format` 装饰层。**插件永远调单下划线业务层。**
4. **db 是插件状态的唯一权威源。** `bbs_plugin` 表存 `installed`/`enable`/`version`；`conf.json` 的 `installed`/`enable`/`id` 已彻底废弃，代码层任何情况下都不读。前台判断启用用 `plugin_paths_enabled()`，禁止 `global $plugins`（前台未初始化）。
5. **双模板系统：** PC 端 + 移动端通过成对 hook（如 `post_ref_thread_after.htm` + `*_mobile.htm`）各渲染一份，CSS 控制显隐。移动端 id 必须加 `-mobile` 后缀。

> 完整架构说明见 [../plugindev/01-architecture.md](../plugindev/01-architecture.md)

## 硬规则

### ❌ 禁止项（违反 = 扫描器拦截或运行异常）

| 禁止 | 正确做法 |
|---|---|
| jQuery（`$()`、`.on()`、`.ajax()`、`$.fn.*`） | htmx 4 属性 + `XN.*` API + 原生 JS（`fetch`/`querySelectorAll`/`addEventListener`） |
| Alpine.js（`x-data`/`x-show`/`x-bind`/`x-model` 等） | htmx 4 + 原生 JS |
| idiomorph / alpine-morph 扩展 | htmx 4 内置 morph |
| 裸 `htmlspecialchars` | `esc_html()` / `esc_attr()` / `esc_js()` |
| POST 表单不带 CSRF | `CsrfService::input()` + `CsrfService::check()` |
| 裸 `include` | `_include()` |
| `window.__xxxData` 全局状态 | 状态放 DOM（`data-*`、hidden input） |
| 后台（admin/）用 htmx | 原生 JS + Bootstrap |
| 关键修复页面依赖 `xiuno-modern.js` | 原生 `fetch` + `confirm` + `addEventListener` |
| `static/*.js` 文件中写 `<?php` 代码 | 翻译串通过 hook 模板注入 `window.XXX_I18N`，JS 用 `window.XXX_I18N \|\| {fallback}` 读取 |
| htmx 2.x 旧事件名（`htmx:configRequest` 等） | 4.x 冒号格式（`htmx:config:request` / `htmx:after:swap`） |
| `htmx:config:request` 中修改 DOM 追加参数 | 直接改 `evt.detail.parameters['key'] = 'value'` |
| hook 文件用 `return;`（路由层 + model 层 + view 层全适用） | 用 `if (条件) { ...全部逻辑... }` 包裹；终止性操作用 `exit;` + `// ponytail:` 注释 |
| `.htm` hook 以 `<?php exit;` 开头（白屏） | `.htm` hook 只用 `<?php`；`.php` hook 才用 `<?php exit;` |
| 覆盖核心路径（`conf/`、`xiunophp/`、`lib/`、`admin/`、`api/`、`model.inc.php` 等） | 通过 hook/overwrite 机制扩展 |
| 单行注释 `//`/`#` 中含 `?>` | 去掉 `<?php`/`?>`，或用块注释 `/* */` |
| `overwrites_rank` 覆盖模板放 `plugin/<dir>/view/htm/` | 必须放 `plugin/<dir>/overwrite/view/htm/` |
| 用 `xn_` / `xnx_` 前缀（官方预留） | 用插件目录名或其缩写作为前缀 |
| 插件目录名单段（不含 `_`） | 至少两段 `作者_功能标识`；主题类第二段必须为 `theme` |
| 卸载脚本用 `unstall.php`（旧拼写） | `uninstall.php`（标准拼写） |
| 直接修改 `lib/` 核心 Service 文件注册表 | 通过插件 `<service>_register.php` 自注册 |
| `model/*.func.php` 函数库（xiuno 原版写法） | `model/XxxService.php` 静态类方法；`model/` 只放 Service 类（类名=文件名），由 `index.php` 兜底自动加载，`hook/model_inc_file.php` 可选 |

### ✅ 必须项

| 必须 | 做法 |
|---|---|
| UI 框架 | Bootstrap 5.3 + Tabler Icons（`<i class="ti ti-xxx"></i>`） |
| 交互 | htmx 4 属性（`hx-get`/`hx-post`/`hx-target`/`hx-optimistic`/`hx-live`） |
| JS API | 非关键页面用 `XN.toast()`/`XN.ajax()`/`XN.confirm()`；关键修复页面用原生 `fetch` |
| 输出转义 | `esc_html()` / `esc_attr()` / `esc_js()`（来自 `lib/EscapeService.php`） |
| 模板加载 | `include _include(APP_PATH.'view/htm/xxx.htm')` |
| 命名前缀 | 表/变量/语言键/JS 全局/setting 键/CSS class 全部带插件前缀 |
| 静态资源位置 | 插件 JS 放 `plugin/<dir>/static/js/`，CSS 放 `plugin/<dir>/static/css/` |
| 静态资源引用 | `$conf['view_url'] . '../plugin/<dir>/static/js/xxx.js'`（非 `APP_PATH`/相对路径） |
| 数据库表字符集 | `DEFAULT CHARSET=utf8mb4`（支持 emoji） |
| 数据库结构变更 | 走 `upgrade.php` 幂等迁移（`SHOW COLUMNS` + `ALTER TABLE`）+ 递增 `conf.json.version`，禁止在 install/setting 自愈 |
| 后台权限检查 | `setting.php` 开头加 `$gid != 1 && $gid != 2 AND message(-1, '无权限');` |
| DB 查询 | 优先 `db_find`/`db_find_one`/`db_count`/`db_find_group`；保留复杂 SQL 加 `// 保留 db_sql_find` 注释 |
| 缓存 | `CacheHelper::remember($key, $ttl, $callback, $plugin)`；键用 `CacheHelper::pluginKey()`；清缓存用 `pluginDeletePrefix()` |
| 前台判断插件启用 | `plugin_paths_enabled()`（只读 db，不回退 conf.json） |
| 用户信息显示 | `user_find_by_uids()`/`user_read()`/`user_read_cache()`（自动生成 `display_name`），禁止 `db_find('user')` 后取 `username` |
| URL 生成 | 命名快捷函数（`thread_url($tid)`）> `route_url()` > `url()`；禁止硬编码 `.htm`/`.html` 后缀 |
| 插件自定义路由 | 通过 `hook/model_route_table_end.php` 注册到 `$routes` 数组 |
| **路由 case 值** | **禁止含 `-`**（`-` 是 URL 参数分隔符，`param(1)` 只取单段）；多段子动作用 `param(2)`/`param(3)` 逐段取 |
| 改核心文件后 | 清 `tmp/` 编译缓存（`_include()` 不比较 mtime） |
| Service 调用核心类 | `if (!class_exists('XxxService')) { include_once APP_PATH.'lib/XxxService.php'; }` 守卫前置 |
| Card 组件 | **必须 `x-card` + `card` 组合**，禁止裸用 `card`/`border`/`border-*`；列表分隔用 `py-*`/`mb-*` 间距 |
| **右侧栏插件模块 card header** | **必须按范本格式**：`<div class="x-card card mt-3"><div class="card-body"><h3 class="card-title small"><i class="ti ti-xxx"></i> 标题</h3></div><div class="card-body">...</div></div>`。**禁止** `card-header`、`h5`/`h6`、`fw-bold`/`fw-semibold`、`me-1`/`me-2`；副标题用 `<small class="text-muted ms-2">副标题</small>` 紧跟主标题。详见 [plugindev/14-plugin-admin-ui.md#3.5](../plugindev/14-plugin-admin-ui.md) |
| 前台布局 | **必须用三栏骨架** `layout_three_column.inc.htm`（`ob_start` + `$main_content` + include）；禁止自行写 `container`/`row`/`col-lg-*`；不需左右栏时设 `$sidebar_*_file=''` |
| 头像渲染 | `avatar_component_from_data()`（非原生 `<img>`） |
| 改 `static/*.js`/`*.css` 后 | 递增 `conf/conf.php` 的 `static_version` |
| **hook 内局部变量** | **加插件前缀**（`$_myplugin_settings`），禁止用 `$settings`/`$conf`/`$user` 等通用名（会污染宿主作用域） |
| **模板 `include header.inc.htm` 后用 `$settings`** | header 中其他插件 hook 会覆盖 `$settings`，需在 include 后重新获取或改用前缀变量名 |
| 静态资源版本 | Hook 文件（`header_link_after.htm`/`footer_js_after.htm`）用 `$static_version`；视图文件用 `$conf['static_version']`；推荐 `filemtime()` 动态版本号（如 `filemtime(APP_PATH.'plugin/xxx/static/js/xxx.js')`） |

> 完整规则清单见 [references/ai-rules.md](references/ai-rules.md)

### 前端 UI 偏好规范

| 偏好 | 规范 |
|---|---|
| Toast vs Modal | 轻提示（成功/失败/信息/警告）用 `XN.toast()`；需要确认（删除/卸载/重置）用 `XN.confirm()`；重要错误/长文本用 `XN.alert()`；需输入文本用 `XN.prompt()` |
| 视频显示 | 视频作为内联播放器显示在正文位置，不出现在附件列表中，不显示下载链接 |
| 附件显示 | 附件列表仅显示图片、文档等非视频附件；视频通过内联 `<video>` 标签直接播放 |
| Card 组件 | **必须 `x-card` + `card` 组合**，禁止裸用 `card`/`border`/`border-*`；列表分隔用 `py-*`/`mb-*` 间距 |
| 前台布局 | **必须用三栏骨架** `layout_three_column.inc.htm`（`ob_start` + `$main_content` + include）；不需左右栏时设 `$sidebar_*_file=''`；禁止自行写 `container`/`row`/`col-lg-*` |
| 右侧边栏 | 放置帖子目录（替代"最新帖子"区域） |
| 个人签名 | 放在统计信息上方，浅色背景，与论坛描述样式一致 |
| 按钮样式 | 禁止在按钮上使用 `w-100` 类；按钮内禁止使用过多样式 |
| Tab 导航 | 每个子 Tab 前加图标；单个 Tab 可点击展开/折叠 |
| 表单提交 | 提交时禁用按钮 + 显示 loading 状态，防重复提交 |

## 开发工作流

### Step 1: 需求评估

1. 确认功能目标、用户角色、触发场景（前台/后台/API）
2. 检索项目中是否已有同类组件可复用（`Grep` 搜 `TablerIconPicker`、`bootstrap.Modal`、`lightbox` 等）
3. 确认插件目录名符合 `作者_功能标识` 两段格式；主题类第二段为 `theme`；前缀不与 `xn_`/`xnx_` 冲突
4. 确认是否依赖其他插件（写入 `conf.json.dependencies`）

### Step 2: 架构设计

1. 选 hook 注入点：查 [references/hooks-catalog.md](references/hooks-catalog.md) 找精确位置
2. 设计数据模型：表名带前缀（`{$db->tablepre}my_plugin`）、字段用 `utf8mb4`、统计字段直接 `db_count` 目标条件（禁止 A-B 相减）
3. 设计 Service 类：静态方法模式，放 `model/` 目录由 `index.php` 兜底自动加载（类名=文件名）；`hook/model_inc_file.php` 可选双保险
4. 设计路由：自定义路由通过 `hook/model_route_table_end.php` 注册到 `$routes`
5. 设计前端：发帖辅助功能挂 `post_ref_thread_after.htm`（PC）+ `*_mobile.htm`（手机端），卡片样式与"引用帖子"一致
6. 确认 `conf.json` 字段：`name`/`brief`/`version`（X.Y.Z 三位制）/`bbs_version`（X.Y 两位制，与当前 `XIUNOX_VERSION` 前两段一致）/`type`（`"plugin"`/`"theme"`），不含 `id`/`installed`/`enable`

### Step 3: 实现

按顺序创建文件（详见 [../plugindev/02-plugin-structure.md](../plugindev/02-plugin-structure.md)）：

1. **`conf.json`** — 必填字段 + `hooks_rank`（键名与 hook 文件名含扩展名完全一致）+ `overwrites_rank`（object，非 array）+ `dependencies`（推荐 object `{"dir":"ver"}`，兼容 array `["dir"]`）
2. **`install.php`** — `CREATE TABLE IF NOT EXISTS` + `setting_set()` 默认配置 + `!defined('DEBUG') AND exit('Access Denied');`
3. **`uninstall.php`** — 标准拼写（非 `unstall.php`）+ `DROP TABLE` + `kv_delete()`/`setting_delete()`
4. **`upgrade.php`**（如需结构变更）— `SHOW COLUMNS` + `ALTER TABLE` 幂等；递增 `conf.json.version`
5. **`model/XxxService.php`** — 静态方法模式；构造函数调 `CacheHelper::registerKeys()`
6. **`hook/model_inc_file.php`**（可选双保险）— 注册 Service：`APP_PATH.'plugin/<dir>/model/XxxService.php',`（每行逗号结尾）；`index.php` 兜底逻辑已自动扫描 `model/*.php`，不写本 hook 也能加载；**禁止** `model/*.func.php` 函数库（xiuno 原版写法，会与自动扫描冲突导致函数重声明 fatal）
7. **`hook/` 其他 hook 文件** — PHP hook 以 `<?php exit;` 开头；`.htm` hook 以 `<?php` 开头；禁止 `return;`
8. **`route/` 路由文件** — `param(1, 'list')` 取 action；`include _include()` 加载模板
9. **`view/htm/` 模板** — 后台模板首尾 include `_include(ADMIN_PATH . 'view/htm/header.inc.htm')`/`footer.inc.htm`；前台用 `APP_PATH`
10. **`setting.php`** — 开头权限检查 + POST 走 `CsrfService::check()`
11. **`hook/lang_zh_cn_bbs.php`**（如需多语言）— 同步 zh-tw/en-us；改后清 `tmp/lang_*_bbs.php`
12. **`static/js/`、`static/css/`** — 纯静态文件，禁止 `<?php`；JS ≥50 行独立文件用 `<script src>` 引用

### Step 4: 测试

1. 清 `tmp/` 编译缓存：`rm -f tmp/route_*.php tmp/model_*.func.php tmp/view_htm_*.htm tmp/lang_*_bbs.php`
2. 若改了 `static/*.js`/`*.css`，递增 `conf/conf.php` 的 `static_version`，硬刷新浏览器（Ctrl+F5）
3. 启用插件，检查扫描器报告（fatal/error 不可跳过，warning/info 可跳过）
4. 验证核心场景：发帖/回帖/编辑/删除/列表/详情/个人中心/后台设置
5. 验证 PC + 移动端双模板（id 加 `-mobile` 后缀，JS 同时更新两端）
6. 验证 hook 不含 `return;`（用 Grep 检查 `hook/` 目录）
7. 验证 `static/*.js` 无 `<?php` 代码（用 Grep 检查）

### Step 5: 交付

1. 跑交付检查表（见下方"失败策略"前的检查清单）
2. 用 Grep 审计常见违规项：
   - `Grep "esc_textarea"` 全局应无结果
   - `Grep "jQuery\|\\$.ajax\|\\$.fn"` 应无结果
   - `Grep "Alpine\|x-data\|x-show"` 应无结果
   - `Grep "return;"` 在 `hook/` 目录下应无结果（闭包内除外）
   - `Grep "<\\?php" plugin/*/static/*.js` 应无结果
3. 确认 `conf.json` 不含 `id`/`installed`/`enable` 字段
4. 打包 zip（保留 `conf.json` 在根目录，详见 [../plugindev/02-plugin-structure.md](../plugindev/02-plugin-structure.md)）

## 速查参考

| 需求 | 参考文档 |
|---|---|
| 查 hook 注入点 | [references/hooks-catalog.md](references/hooks-catalog.md) |
| 查 API 签名 / 参数细节 | [references/api-cheatsheet.md](references/api-cheatsheet.md) |
| 查前端模式 / htmx 4 / XN.* | [references/frontend-patterns.md](references/frontend-patterns.md) |
| 查后台 UI 模式 / Tab 独立页面 / 入口模式 / 搜索分页 | [references/admin-patterns.md](references/admin-patterns.md) |
| 查 AI 协作硬规则 / 扫描器分级 | [references/ai-rules.md](references/ai-rules.md) |
| 查完整插件架构原理 | [../plugindev/01-architecture.md](../plugindev/01-architecture.md) |
| 查 conf.json 完整字段 / zip 打包 | [../plugindev/02-plugin-structure.md](../plugindev/02-plugin-structure.md) |
| 查完整 Hook 全量目录 | [../plugindev/03-hooks-catalog.md](../plugindev/03-hooks-catalog.md) |
| 查完整 API 速查 | [../plugindev/04-api-cheatsheet.md](../plugindev/04-api-cheatsheet.md) |
| 查前端 / 安全 / htmx 4 详解 | [../plugindev/05-frontend-security.md](../plugindev/05-frontend-security.md) |
| 查 AI 协作完整规则 | [../plugindev/06-ai-collaboration.md](../plugindev/06-ai-collaboration.md) |
| 查运行时安全 / 崩溃自动禁用 | [../plugindev/07-runtime-security.md](../plugindev/07-runtime-security.md) |
| 查登录安全 / 账号锁定 | [../plugindev/08-login-security.md](../plugindev/08-login-security.md) |
| 查 model 加载机制重构 | [../plugindev/09-model-loading-refactor.md](../plugindev/09-model-loading-refactor.md) |
| 查 jQuery 移除迁移指南 | [../plugindev/10-jquery-removal-guide.md](../plugindev/10-jquery-removal-guide.md) |
| 查编辑器工具栏按钮集成 | [../plugindev/11-editor-toolbar-integration.md](../plugindev/11-editor-toolbar-integration.md) |
| 查头像组件使用与扩展 | [../plugindev/12-avatar-component.md](../plugindev/12-avatar-component.md) |
| 查后台/前台 UI 规范总览 | [../plugindev/14-plugin-admin-ui.md](../plugindev/14-plugin-admin-ui.md) |
| 查存储驱动扩展 / 云存储插件开发 | [../plugindev/16-storage-driver-extension.md](../plugindev/16-storage-driver-extension.md) |
| 查插件互斥机制 / 目录命名 | [../plugindev/plugin-mutex-guide.md](../plugindev/plugin-mutex-guide.md) |
| 查主题插件开发 / overwrite / 主题色适配 / dark 模式 | [../plugindev/17-theme-plugin-guide.md](../plugindev/17-theme-plugin-guide.md) |
| 查完整手册入口 | [../plugindev/README.md](../plugindev/README.md) |

## Hook 选择速查

| 需求 | Hook |
|---|---|
| 全局 CSS | `header_link_after.htm` |
| 全局 JS | `footer_js_after.htm` |
| 帖子标题后（详情页） | `thread_subject_badge_after.htm` |
| 帖子标题后（列表页） | `thread_list_inc_subject_after.htm` |
| 楼层用户名后 | `post_list_inc_username_after.htm` |
| 发帖后处理 | `thread_create_thread_end.php` |
| 编辑后处理 | `post_update_post_start.php` |
| 删帖级联清理 | `model_thread_delete_end.php` |
| 注册 Service | `model_inc_file.php` |
| 注册前台路由 | `index_route_case_end.php` |
| 注册插件路由表 | `model_route_table_end.php` |
| 注册后台路由 | `admin_index_route_case_end.php` |
| 注册存储驱动 | `admin_setting_upload_driver_register.php` + `storage_save.php` + `storage_serve.php` + `storage_delete.php` |
| 后台侧边栏入口（顶部） | `admin_sidebar_start.htm` |
| 后台侧边栏入口（底部） | `admin_sidebar_end.htm` |
| 首页侧边栏组件 | `index_site_brief_after.htm` |
| 语言扩展 | `lang_zh_cn_bbs.php` |
| 个人中心导航 | `my_sidebar_nav_item_after.htm` |
| 发帖辅助功能（PC 右侧栏） | `post_ref_thread_after.htm` |
| 发帖辅助功能（手机端） | `post_ref_thread_after_mobile.htm` |
| 编辑器工具栏按钮 | `editor_custom_btns_end.php` |
| 头像角标 | `avatar_component_badges.php` |
| 头像框 | `avatar_component_frame.php` |
| 5 分钟定时任务 | `model_cron_5_minutes_end.php` |
| 每日定时任务 | `model_cron_daily_end.php` |
| 用户封禁检查 | `user_ban_check.php` |

> 完整 Hook 目录见 [references/hooks-catalog.md](references/hooks-catalog.md)

## 失败策略

| 失败场景 | 处理方式 |
|---|---|
| hook 不生效 / 未触发 | 1. 检查 hook 文件名（含扩展名）是否与源码标记 `// hook xxx.php` 完全一致 2. 检查 `conf.json.hooks_rank` 键名是否含扩展名 3. 清 `tmp/` 编译缓存（含 OPcache：`CacheService::clearByType(['data','opcache'])`） 4. 检查插件是否启用（db `bbs_plugin` 表） |
| 修改后无变化 | 1. 清 `tmp/` 编译缓存（`_include()` 不比较 mtime） 2. 改了 `static/*.js`/`*.css` 递增 `conf/conf.php` 的 `static_version` 3. 改了语言键清 `tmp/lang_*_bbs.php` 4. 硬刷新浏览器（Ctrl+F5） |
| 白屏 / fatal | 1. 检查 `.htm` hook 是否误用 `<?php exit;`（应只用 `<?php`） 2. 检查 hook 是否用了 `return;`（应用 `if` 包裹） 3. 检查单行注释是否含 `?>` 4. 检查 `esc_textarea()` 调用（不存在，用 `esc_html()`） 5. 检查早期 hook 的 `esc_*` 是否有 `function_exists` 兜底 6. **检查 hook 文件内是否含 `// hook xxx` 注释**（会被编译器多趟循环误匹配为 hook 占位符，第二趟重复拼接引发 ParseError；改用 `// hook: xxx` 或 `// xxx` 格式） |
| 函数重声明 fatal（`Cannot redeclare xxx()`） | 1. 检查 `model/` 目录是否有 `*.func.php` 函数库文件（xiuno 原版写法，与 `index.php` 兜底自动扫描冲突）→ 改为 `XxxService.php` 静态类方法 2. 检查是否同时写了 `hook/model_inc_file.php` 注入 + 兜底自动扫描同一文件（`class_exists` 守卫已去重，但类名与文件名不一致时守卫失效） |
| 扫描器 fatal 拦截安装 | 1. 查 [references/ai-rules.md](references/ai-rules.md) 「扫描器规则分级」定位分类 2. fatal 类（jQuery/Alpine.js/PHP8 语法/危险函数/`hook_htm_header`/`app_path_in_url` 等）不可跳过 3. `conf_version` 拦截：`bbs_version` 必须与当前 `XIUNOX_VERSION` 前两段一致 |
| `Class not found` | Service 调用核心类前未 `include_once`：`if (!class_exists('XxxService')) { include_once APP_PATH.'lib/XxxService.php'; }`；访问静态属性/常量前必须先确保类加载 |
| 表前缀重复 / Table not found | `db_*` 函数 `$table` 参数不含前缀（传 `'my_plugin'` 非 `'bbs_my_plugin'`）；取前缀用 `$db->tablepre`，禁止 `$conf['db']['tablepre']` |
| SQL 报 1054 Unknown column | 1. `display_name` 是 `user_format()` 派生虚拟字段，SQL 中不能 SELECT，用 `user_find_by_uids`/`user_read` 2. `status` 字段不存在，用 `ban_type`（0=正常） 3. `db_find('user')` 绕过核心层不含派生字段 |
| 缓存不失效 / 数据不一致 | 1. 写操作后用 `CacheHelper::pluginDeletePrefix($plugin)` 清插件缓存 2. 列表类缓存用版本号机制（`CacheHelper::set(pluginKey(...), $v+1, 86400)`） 3. `CacheHelper::set()` 写版本号用 `array('__v'=>$value)` 哨兵格式 4. 禁用 `flushdb`，改用 `deleteByPrefix` |
| 后台插件设置页光秃秃（无导航栏） | 后台模板首尾 include `_include(ADMIN_PATH . 'view/htm/header.inc.htm')`/`footer.inc.htm`；前台插件模板用 `APP_PATH . 'view/htm/header.inc.htm'` |
| 发帖页 PC 卡片字段无法提交 | `post.htm` 的 `<form>` 边界必须覆盖整个 `.row`（`<form>` 在 `.row` 前，`</form>` 在 `.row` 闭合后） |
| PC/移动端双模板 JS 更新失效 | 移动端模板 id 加 `-mobile` 后缀，JS 分别获取两端元素同时更新；或全用 class + `querySelectorAll` 遍历 |
| AIEditor 自定义按钮不显示 | 1. 用 Grep 确认 `conf.json.hooks_rank` 键名含 `.php` 2. hook 内用 `$data` 变量追加按钮配置 3. 配置字段名必须是 `toolbarKeys`（不是 `toolbar`） 4. SVG 用 fill 模式（path 不带 `fill="none"`） 5. 调试用 `document.querySelectorAll('aie-custom').length` 确认按钮是否创建 |
| 升级流程删源文件 | 升级顺序：rename 备份旧插件 → `rmove_dir` 移入新版本 → 清 `tmpDir` → `plugin_disable`（顺序颠倒会删源文件） |
| 路由报"参数错误" / 404 | 路由 `case` 值含 `-`（`-` 是 URL 参数分隔符，`param(1)` 只取单段）。改用 `param(1)` 取主动作 + `param(2)` 取子动作的嵌套模式 |

## 交付检查表

交付前必须确认（核心项，完整清单见 [references/ai-rules.md](references/ai-rules.md)）：

- [ ] `conf.json` 必填字段完整（`name`/`brief`/`version`/`bbs_version`/`type`），不含 `id`/`installed`/`enable`
- [ ] `install.php` 用 `IF NOT EXISTS` 幂等；`uninstall.php` 用标准拼写删表 + 删 KV
- [ ] 数据库结构变更走 `upgrade.php` 幂等迁移，递增 `conf.json.version`
- [ ] 所有 PHP hook 以 `<?php exit;` 开头；`.htm` hook 以 `<?php` 开头；hook 文件名与源标记完全匹配
- [ ] hook 文件无 `return;`（路由层 + model 层 + view 层全适用）
- [ ] 所有 POST 表单含 `CsrfService::input()`，POST 处理以 `CsrfService::check()` 开头
- [ ] 所有输出用 `esc_html()`/`esc_attr()`/`esc_js()`，无 `esc_textarea()`
- [ ] 无 jQuery / Alpine.js / idiomorph；无 `htmx:configRequest` 等 2.x 旧事件名
- [ ] 命名全带前缀（表/变量/语言键/JS 全局/setting 键/CSS class）
- [ ] JS/CSS 放 `static/` 目录（非 `view/htm/`）；引用用 `$conf['view_url']`（非 `APP_PATH`/相对路径）
- [ ] `static/*.js` 中无 `<?php` 代码
- [ ] 数据库表用 `utf8mb4`；`db_*` 函数传表名不含前缀
- [ ] 用户信息显示用 `user_find_by_uids`/`user_read`，模板取 `display_name`（非 `username`）
- [ ] URL 用命名快捷函数或 `url()`，禁止硬编码 `.htm`/`.html` 后缀
- [ ] 缓存用 `CacheHelper::remember()`/`pluginKey()`/`pluginDeletePrefix()`
- [ ] Service 调用核心类前 `include_once` 守卫
- [ ] 改核心文件后清 `tmp/`；改静态资源后递增 `static_version`
- [ ] 后台模板 include `ADMIN_PATH` 的 header/footer；前台用 `APP_PATH`
- [ ] Card 组件必须 `x-card` + `card` 组合，禁止裸 `card`/`border`/`border-*`
- [ ] 前台页面用三栏骨架 `layout_three_column.inc.htm`，禁止自行写 `container`/`row`/`col-lg-*`
- [ ] 头像用 `avatar_component_from_data()`，非原生 `<img>`
- [ ] PC/移动端双模板 id 加 `-mobile` 后缀
- [ ] 插件目录名符合 `作者_功能标识` 两段格式；主题类第二段为 `theme`
- [ ] 静态资源引用带版本号（hook 用 `$static_version`，视图用 `$conf['static_version']`）
- [ ] UI 组件符合规范：x-card + card、三栏布局骨架、Toast/Modal 场景区分
- [ ] 视频内联播放、不出现在附件列表中

## 输出要求

完成插件开发任务后，输出应包含：

1. **变更摘要**：列出新增/修改的文件（绝对路径）和每个文件的核心作用
2. **关键决策**：说明选择的 hook 点、数据模型设计、路由命名等关键架构决策及原因
3. **使用说明**：如何启用插件、配置项说明、前台/后台入口 URL
4. **测试结果**：已验证的场景列表（含 PC + 移动端双模板）
5. **遗留风险**：未覆盖的边缘场景、潜在冲突、后续优化建议
6. **审计结果**：Grep 审计命令的执行结果（`esc_textarea`/`jQuery`/`Alpine`/`return;`/`<?php` in `static/*.js` 等均应无结果）

> 详细 API 见 [references/api-cheatsheet.md](references/api-cheatsheet.md)；详细前端模式见 [references/frontend-patterns.md](references/frontend-patterns.md)；完整手册见 [../plugindev/README.md](../plugindev/README.md)。
