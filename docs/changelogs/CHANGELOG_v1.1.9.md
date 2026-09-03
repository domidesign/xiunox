# v1.1.9 更新日志 - 2026-08-29

> **版本说明**: 用户导航可后台自定义 + robots.txt 动态化 + Tabler Icons 异步加载瘦身 + 全文索引 MariaDB 兼容 —— 新增用户导航后台自定义编辑（新增/删除/排序，conf 落库默认 4 项），robots.txt 改动态生成修复 Google Sitemap 校验报错，Tabler Icons 移除 filled 变体并改异步加载，搜索全文索引 ngram 失败自动回退普通 FULLTEXT 兼容 MariaDB，JSON-LD 结构化数据增强，插件卡片栅格响应式优化，用户统计口径前后台统一

> **⚠️ Breaking Changes**
>
> - **robots.txt 动态化**：静态文件 `robots.txt` 删除，改为 `route/robots.php` 由 index.php 早期拦截动态生成（Sitemap 行为完整 URL）。升级时需手动删除根目录旧 `robots.txt`，否则会被动态路由覆盖前的静态文件影响（见删除清单）
>
> - **Tabler Icons 移除 filled 变体**：删除 `tabler-icons-filled.min.css` 与 `tabler-icons-filled.woff2`，仅保留标准版。依赖 `ti-*` filled 图标的页面需改用标准版图标类
>
> - **升级需删除的旧文件**：`robots.txt`、`view/vendor/tabler-icons/fonts/tabler-icons-filled.woff2`、`view/vendor/tabler-icons/tabler-icons-filled.min.css`

## 🆕 新功能

### 用户导航后台自定义（全新）

- **后台自定义编辑** `admin/route/setting.php` + `admin/view/htm/setting_nav.htm`：首页右侧栏用户信息卡片下方两列宫格导航支持后台新增/删除/排序，配置落库 `user_nav_items`

- **默认 4 项** `conf/conf.default.php`：资料（ti-user）、积分（ti-coins）、主题（ti-message）、关注（ti-heart），各带 icon/name\_lang/slug/url/rank 字段

- **服务层** `lib/UserNavService.php`（新增）：用户导航项统一加载、排序与渲染逻辑

- **前台渲染** `view/htm/footer_nav.inc.htm` + `view/htm/sidebar_right.inc.htm`：宫格导航接入新服务，侧边栏同步重构

### robots.txt 动态化（全新）

- **动态生成** `route/robots.php`（新增）+ `index.php` 早期拦截：`/robots.txt` 请求实时生成，Sitemap 行强制输出完整 URL（含协议+域名），修复 Google Search Console 报 "Invalid sitemap URL"

- **SEO 检测同步** `admin/route/setting.php`：robots 健康检查改查 `route/robots.php` 存在性（不再校验根目录静态文件恒不存在的场景）

- **钩子支持**：保留 robots\_start/robots\_body hook，插件可扩展内容

### Tabler Icons 异步加载

- **异步加载** `view/htm/header.inc.htm` + `install/view/htm/header.inc.htm` + `admin/view/htm/header.inc.htm`：`media="print" onload="this.media='all'"` 技巧移出关键渲染路径，noscript 兜底保证无 JS 场景可用

- **瘦身**：删除 filled 变体 css+字体，标准版 woff2 同步更新，体积显著下降

### JSON-LD 结构化数据增强

- **用户页** `route/user.php`：新增 `Person` mainEntity（name/url），利于个人主页搜索增强

- **主题页** `route/thread.php`：DiscussionForumPosting 补充 `text` 字段（与 description 一致），满足富媒体摘要提取

## 🔧 重构与优化

### 用户统计口径统一

- **前后台一致** `model/runtime.func.php`：首页统计用户数改传 `cond(uid > 0)` 触发 COUNT(\*) 精确统计，避免 InnoDB 空 cond 走 information\_schema 估算值导致前后台数字不一致

### 插件卡片栅格响应式优化

- **本地插件列表** `admin/view/htm/plugin_list.htm`：栅格 `col-lg-4 col-xl-3` → `col-xl-4 col-xxl-3`，中屏（≥1200px）每行 3 张更舒展，卡片加 overflow-hidden 防内容溢出

- **官方插件列表** `admin/view/htm/plugin_official_list.htm`：栅格同步优化

### 搜索全文索引 MariaDB 兼容

- **升级流程** `lib/UpgradeService.php`：`search_indexes` 步骤 ngram 创建失败自动回退普通 FULLTEXT（MariaDB 10.0.5+ InnoDB 内建 CJK bigram 分词），不再 fail-fast 阻断后续升级步骤（digest 字段/缓存配置/重编译等）

- **全新安装** `install/install.sql`：FULLTEXT 索引加 TOLERANT 容错双语句（MySQL ngram / MariaDB 普通），两种数据库均可完成中文全文索引

- **行为一致**：与 `route/search.php` `search_ensure_fulltext()` 运行时回退逻辑对齐

### 其他优化

- **升级探测** `lib/DiscoverService.php`：探测逻辑调整

- **前端** `view/js/bbs.js` + `view/css/bootstrap-bbs.css` + `view/css/theme.css`：配合侧边栏/导航重构

- **语言包**：zh-cn/zh-tw/en-us 的 bbs\_admin/bbs\_common 同步新增导航相关文案

## 🐛 问题修复

- **前台 header 破损 HTML** `view/htm/header.inc.htm`：修复 link 与 noscript 标签意外合并导致的 href 无效 + noscript 兜底失效问题，异步加载结构恢复为两个独立元素

- **用户创建撞唯一约束** `model/user.func.php`：`user_create()` 漏传 nickname 时第二个空串撞 UNIQUE 索引报 `Duplicate entry ''`，现自动回退 username 兜底

- **升级中断阻断** `lib/UpgradeService.php`：MariaDB 环境 search\_indexes 步骤 ngram 报错导致整个升级流程中断，已修复为回退创建

## 📚 文档

- **xiunox-plugin-dev 文档重编**：新增 `references/manual/` 目录（01 架构 \~ 19 用户导航 + README + plans + plugin-mutex-guide 共 22 篇），references 下 10 篇更新，SKILL.md 刷新

- **用户导航开发文档** `docs/plugindev/19-user-nav.md`

## 🗑️ 移除

- `robots.txt` — 静态 robots 文件（改为 route/robots.php 动态生成）

- `view/vendor/tabler-icons/fonts/tabler-icons-filled.woff2` — filled 图标字体

- `view/vendor/tabler-icons/tabler-icons-filled.min.css` — filled 图标样式

## 🔧 后续修复补充（2026-09-03）

### 主题插件判定重构（plugin\_is\_theme 权威源）

- **conf.json type 字段为唯一权威源** `model/plugin.func.php`：`plugin_is_theme()` 废弃目录名/插件名关键词猜测（theme/template/skin/风格/模板），「主题编辑器」「模板管理」类功能插件不再被误判为主题插件

- **type 自愈同步** `plugin_db_init()`：conf.json 显式声明 type 且与 db 不一致时自动更新 db，作者改 conf.json 立即生效；未声明时不触碰 db 存量分类

- **读取优先级** `plugin_read_by_dir_with_db()`：conf.json 显式声明（权威）→ db 字段（存量回退）

### 移动端底部导航体验修复

- **页脚遮挡** `view/htm/bottom_nav.inc.htm` + `view/css/bootstrap-bbs.css`：导航实际高度写入 CSS 变量 `--bottom-nav-h`（resize / htmx:after:swap 同步），页脚按 max(导航高度, 安全区) 预留底部空间；导航隐藏（线程页）时不预留

- **惯性滚动掉帧** `view/css/bootstrap-bbs.css`：底部导航 `transform: translateZ(0)` 强制独立合成层，修复真机 fixed 导航跟随页面滑动滞后

- **PWA 顶栏遮挡** `view/css/bootstrap-bbs.css`：`#header` 顶部加 `env(safe-area-inset-top)`，全面屏/ standalone 模式不被状态栏遮挡

### 编辑器资源加载修复

- **URL 双问号** `view/htm/post.htm`：editor\_assets 部分 URL 已自带 `?v=`（filemtime 版本号），static\_version 拼接时自动将 `?` 转 `&`

- **脚本加载失败守卫** `view/htm/post.htm`：UploadService 加载失败（如 522）时跳过初始化，避免 ReferenceError 中断后续顶层逻辑（版块联动/引用搜索）

### 图标库扩充

- **tabler-icons 更新** `view/vendor/tabler-icons/`：新增约 350 个图标（filled 变体回归主 CSS、brand 品牌图标、file-type 文件类型等）；header 引用补 `$static_version` 版本号

## 📊 统计

- 文件：84（新增 \~27，修改 \~52，删除 \~5），代码 +11,396 / -4,757

- 提交范围：`c0c7451` → `caebc8c`（6 个实质提交：623bc73、7b26343、93d5127、a38f229、a6aaaf1、caebc8c）

- 版本号：1.1.8 → 1.1.9

