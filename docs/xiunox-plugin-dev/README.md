# XIUNOX 插件开发文档

XIUNOX（XIUNO BBS X 重构版）插件开发规范与速查文档。文档面向 AI（Trae Skill）和人类开发者双重读者。

## 文档结构

### 本目录（Skill 精简版）

| 文件 | 内容 | 何时读 |
|---|---|---|
| [SKILL.md](SKILL.md) | **主文档**：YAML frontmatter + When/How/What + 硬规则 + 工作流 + 失败策略 | 完整理解插件机制时通读 |
| [references/ai-rules.md](references/ai-rules.md) | AI 协作规则速查（禁止项/必须项/命名前缀/扫描器分级/交付检查表） | 写代码时对照检查 |
| [references/api-cheatsheet.md](references/api-cheatsheet.md) | API 速查（param/URL/DB CRUD/model/权限/KV/安全/全局变量） | 查函数签名 |
| [references/hooks-catalog.md](references/hooks-catalog.md) | Hook 点速查（280+ hook，按页面分类，已核对源码真实存在） | 查 hook 注入点 |
| [references/frontend-patterns.md](references/frontend-patterns.md) | 前端模式速查（CSS/JS 加载顺序/htmx 4 事件/XN.* API/lightbox/CSRF） | 查前端模式 |
| [references/ui-patterns.md](references/ui-patterns.md) | UI/样式规范速查（静态资源版本号/三栏布局/Card 组件/Toast vs Modal/视频附件显示/Tab 导航/表单交互） | 查 UI 样式规范 |
| [references/admin-patterns.md](references/admin-patterns.md) | 后台 UI 模式速查（Tab 独立页面/后台入口/GET 搜索/分页 URL/命名快捷函数/菜单注册） | 查后台 UI 模式 |
| [references/security-patterns.md](references/security-patterns.md) | 安全模式速查（CAS/幂等设计/GET_LOCK/部分成功回滚/频率限制） | 查并发安全与积分防刷 |

### 配套完整手册（plugindev/，深入细节时查）

> `docs/plugindev/` 是项目自带目录，包含完整开发手册，可直接使用全部交叉引用。所有深入参考请查阅 `../plugindev/` 对应分册。
> GitHub 镜像地址（可选）：https://github.com/domidesign/xiunox/tree/main/docs/plugindev

| 文件 | 内容 |
|---|---|
| [plugindev/README.md](../plugindev/README.md) | 完整手册入口（含核心硬规则速查表 + 分册导航） |
| [plugindev/01-architecture.md](../plugindev/01-architecture.md) | 插件架构原理（编译时合并 / `_include()` / 生命周期 / 依赖） |
| [plugindev/02-plugin-structure.md](../plugindev/02-plugin-structure.md) | **conf.json 完整字段表 + zip 打包规范 + upgrade.php 机制** |
| [plugindev/03-hooks-catalog.md](../plugindev/03-hooks-catalog.md) | Hook 点全量目录（完整版） |
| [plugindev/04-api-cheatsheet.md](../plugindev/04-api-cheatsheet.md) | API 速查（完整版） |
| [plugindev/05-frontend-security.md](../plugindev/05-frontend-security.md) | 前端 / htmx 4 / 安全 |
| [plugindev/06-ai-collaboration.md](../plugindev/06-ai-collaboration.md) | AI 协作规范（规则详情） |
| [plugindev/07-runtime-safety.md](../plugindev/07-runtime-safety.md) | 运行时安全 / 崩溃自动禁用 |
| [plugindev/08-login-security.md](../plugindev/08-login-security.md) | 登录安全 / 账号锁定 |
| [plugindev/09-model-loading-refactor.md](../plugindev/09-model-loading-refactor.md) | 模型加载重构（废弃 model.min.php 合并加载，改为逐文件 include） |
| [plugindev/10-jquery-removal-guide.md](../plugindev/10-jquery-removal-guide.md) | jQuery 移除指南（迁移到原生 JS + htmx 4） |
| [plugindev/11-editor-toolbar-integration.md](../plugindev/11-editor-toolbar-integration.md) | 编辑器工具栏集成 |
| [plugindev/12-avatar-component.md](../plugindev/12-avatar-component.md) | 头像组件（三层嵌套结构 + 2 个 hook 点 + avatar_shape 配置） |
| [plugindev/14-plugin-admin-ui.md](../plugindev/14-plugin-admin-ui.md) | **插件后台与 UI 规范总览**（Tab 独立页面 / x-card / 三栏布局 / 后台入口 / 搜索分页 / 弹窗） |
| [plugindev/16-storage-driver-extension.md](../plugindev/16-storage-driver-extension.md) | 存储驱动扩展机制（动态驱动注册 / 云存储插件开发指南 / OSS 完整示例） |
| [plugindev/plugin-mutex-guide.md](../plugindev/plugin-mutex-guide.md) | 插件互斥指南 |

### 何时读哪个

**速查（本目录 references/，覆盖 80% 日常开发）**：
- **写代码时对照检查** → [references/ai-rules.md](references/ai-rules.md)
- **查 API 签名** → [references/api-cheatsheet.md](references/api-cheatsheet.md)
- **查 hook 注入点** → [references/hooks-catalog.md](references/hooks-catalog.md)
- **前端/htmx 4/安全速查** → [references/frontend-patterns.md](references/frontend-patterns.md)
- **UI/样式规范速查** → [references/ui-patterns.md](references/ui-patterns.md)（静态资源版本号、Card 组件、Toast vs Modal、视频显示等）

**深入细节（plugindev/，速查不够时查）**：
- **理解架构原理** → [plugindev/01-architecture.md](../plugindev/01-architecture.md)
- **查 conf.json 字段 / zip 打包** → [plugindev/02-plugin-structure.md](../plugindev/02-plugin-structure.md)
- **查完整 Hook 目录** → [plugindev/03-hooks-catalog.md](../plugindev/03-hooks-catalog.md)
- **查完整 API 速查** → [plugindev/04-api-cheatsheet.md](../plugindev/04-api-cheatsheet.md)
- **不确定某条规则** → [plugindev/06-ai-collaboration.md](../plugindev/06-ai-collaboration.md)（规则详情）
- **前端/htmx/安全详解** → [plugindev/05-frontend-security.md](../plugindev/05-frontend-security.md)
- **UI/样式/弹窗规范** → [plugindev/14-plugin-admin-ui.md](../plugindev/14-plugin-admin-ui.md)（第 15 节：前端 UI 偏好规范）
- **运行时安全/崩溃自动禁用** → [plugindev/07-runtime-safety.md](../plugindev/07-runtime-safety.md)
- **登录安全/账号锁定** → [plugindev/08-login-security.md](../plugindev/08-login-security.md)
- **头像渲染/头像角标/头像框扩展** → [plugindev/12-avatar-component.md](../plugindev/12-avatar-component.md)
- **jQuery 移除迁移** → [plugindev/10-jquery-removal-guide.md](../plugindev/10-jquery-removal-guide.md)
- **插件互斥/目录命名** → [plugindev/plugin-mutex-guide.md](../plugindev/plugin-mutex-guide.md)
- **存储驱动扩展/云存储插件** → [plugindev/16-storage-driver-extension.md](../plugindev/16-storage-driver-extension.md)

## 5 分钟快速上手

XIUNOX 插件 = `plugin/<dir>/` 下的文件集合 + 编译期合并到核心代码。

### 核心概念

1. **编译时合并**：把 hook 文件放到 `plugin/<dir>/hook/<hook名>` 就等于注册了 hook，没有 `add_hook()` 函数。`_include()` 把 hook 内容物理拼进源文件，缓存到 `tmp/`。
2. **模型三层命名**：`__` 原始层（纯 DB）→ 单 `_` 业务层（缓存/计数/通知）→ `_format` 装饰层。**插件永远调单下划线业务层。**
3. **修改源文件后必须清 tmp**：`_include()` 不比较 mtime，批量清理 `rm -f tmp/route_*.php tmp/model_*.php tmp/view_htm_*.htm`。
4. **插件状态唯一权威源为 db，conf.json 彻底废弃**：`installed`/`enable` 只存在于 `bbs_plugin` 表，conf.json 禁止包含这两个字段，**代码层任何情况下都不读**（`plugin_init()` 在 `xn_json_decode(conf.json)` 后立即 `unset` 丢弃，db 异常时不回退 conf.json）。前台判断插件启用用 `plugin_paths_enabled()`（只读 db）。

### 最小插件骨架

```
plugin/my_plugin/
├── conf.json              # 元信息（name/version/hooks_rank/...）
├── install.php            # 建表 + 默认配置
├── uninstall.php          # 镜像清理（DROP TABLE + kv_delete）
├── upgrade.php            # 结构变更幂等迁移（可选，结构变更时必备）
├── hook/
│   ├── model_inc_file.php # 注册 Service 类
│   └── ...                # 业务 hook（文件名与核心 // hook xxx.php 标记一致）
├── model/
│   └── MyService.php      # 静态方法 Service 类
├── route/
│   └── my_plugin.php      # 自定义路由
├── view/htm/
│   └── *.htm              # 模板
├── static/
│   ├── js/                # JS 资源（禁止放 view/htm/）
│   └── css/               # CSS 资源
└── lang/
    └── zh-cn.php          # 语言包
```

### 三条铁律（违反即白屏/数据错乱）

1. **所有 hook 文件（路由层 + model 层 + view 层）禁止 `return;`**：hook 编译期内联到宿主，`return` 会从宿主返回导致后续逻辑被跳过。已违反 4 次，最严重一次导致 runlevel 1/2/3/4 拦截失效。用 `if (条件) { ... }` 包裹。例外：终止性操作允许 `exit;` 但必须加 `// ponytail:` 注释说明。
2. **PHP hook 文件以 `<?php exit;` 开头**，`.htm` 模板 hook 文件以 `<?php` 开头（不能用 `<?php exit;`，否则白屏）。
3. **所有 POST 表单含 `CsrfService::input()` + handler 首行 `CsrfService::check()`**。

详见 [SKILL.md §硬规则](SKILL.md#硬规则不可违反)。

## AI Skill 调用

本目录同时是 Trae Skill `xiunox-plugin-dev` 的载体，[SKILL.md](SKILL.md) 顶部的 frontmatter 定义了 Skill 的触发条件。当用户要求写/改/调试 XIUNOX 插件、加 hook、加后台设置页、加路由、迁移旧插件到 htmx 4 架构时自动触发。

## 配套资源

- 项目根 `docs/` 目录：完整开发手册（架构/前端/示例）
- `model/route.func.php`：100+ 已注册命名快捷函数源码
- `.trae/rules/bugfix_rules.md`：bug 修复流程规范
- `.trae/rules/project_rules.md`：项目级硬约束

## 更新日志

- 2026-08-16：
  - **完善 model 兜底加载机制的文档说明**（配合 `index.php` 兜底逻辑过滤 `*.func.php` 的代码优化）：
    - [SKILL.md](SKILL.md)：禁止项表格新增「`model/*.func.php` 函数库（xiuno 原版写法）」；Step 2 架构设计第 3 步改为「放 `model/` 目录由 `index.php` 兜底自动加载，`hook/model_inc_file.php` 可选双保险」；Step 3 实现第 6 步标注 hook 为可选；失败策略表新增「函数重声明 fatal」排查项
    - [plugindev/02-plugin-structure.md](../plugindev/02-plugin-structure.md)：`model_inc_file.php` 标题加「（可选）」；补充 v1.1.4+ 自动扫描说明 + 明确禁止 `model/*.func.php`；目录树注释更新为「禁止 *.func.php」
    - [plugindev/09-model-loading-refactor.md](../plugindev/09-model-loading-refactor.md)：新增「3.3 兜底加载逻辑」章节（含完整代码示例、关键规则表、xiuno 原版迁移指南），原「3.3 代码迁移清单」顺延为 3.4
  - **核心代码优化**：`index.php` / `api/v1/index.php` 兜底逻辑新增 `*.func.php` 过滤，避免与 `hook/model_inc_file.php` 注入冲突导致函数重声明 fatal（第三方插件 `lecms_spider` 触发的 500 问题）
- 2026-07-25：
  - **按 Skill 最佳实践重写 SKILL.md**：从 1254 行精简到 263 行，添加 YAML frontmatter（`name`/`description`），明确 When/How/What 结构，补充失败策略表和交付检查表，遵循「渐进式披露」原则将细节下沉到 references/
  - **恢复 references/ 目录**：从 plugindev/ 同步正确内容重建 4 个速查文件，作为 SKILL.md 的渐进式披露参考：
    - [references/ai-rules.md](references/ai-rules.md)：AI 协作规则速查（禁止项/必须项/命名前缀/扫描器分级/交付检查表）
    - [references/api-cheatsheet.md](references/api-cheatsheet.md)：API 速查（param/URL/DB CRUD/model/权限/KV/安全/全局变量）
    - [references/hooks-catalog.md](references/hooks-catalog.md)：Hook 点速查（280+ hook，按页面分类，已核对源码真实存在）
    - [references/frontend-patterns.md](references/frontend-patterns.md)：前端模式速查（CSS/JS 加载顺序/htmx 4 事件/XN.* API/lightbox/CSRF）
  - **修复 references/ 内容错误**：
    - hooks-catalog.md：删除 16+ 个不存在的 hook 名（如 `my_nav_before/after.htm`），全部 hook 名已核对源码
    - api-cheatsheet.md：补全函数签名（`thread_create`/`post_create`/`post_update` 补 `$options`，`http_location` 补 `$allow_external`）
    - frontend-patterns.md：修复 htmx 事件 API（`evt.detail.ctx.request.body` → `evt.detail.parameters`）
  - **更新 README.md 文档结构**：本目录新增 references/ 4 个速查文件列表；「何时读哪个」拆分为速查（references/）和深入（plugindev/）两层
- 2026-07-24（第二次）：
  - **同步插件开发相关新规则到 SKILL.md**：从 `bugfix_rules.md` 高频违规清单和 `project_memory.md` 硬约束中筛选与插件开发直接相关的规则（不同步纯 bug 修复流程类规则），共新增约 30 条到禁止清单 + 32 条到交付检查表
  - **新增规则覆盖范围**：
    - 模板/前端：PC/移动端双模板 id 命名规范、`jform.reset()` 禁止、`post.htm` form 边界、`overwrites_rank` 子目录、`esc_textarea` 不存在、早期 `esc_*` 兜底、PHP 8.x `isset` 兜底、语言键同步 `lang_*_bbs.php`、`static_version` 递增
    - 缓存：`CacheHelper::set()` 哨兵格式、`cache_truncate` 用 `deleteByPrefix`、缓存键长度、写操作后清缓存
    - API：禁用薄封装（用核心 `post_create`/`post_update`）、操作型端点 POST+PUT、`validateAccessToken` nullable、API 加载 `$grouplist`/`$forumlist`、附件关联同步 `message_fmt`
    - 路由/URL：`db_*` 表名不含前缀、`$db->tablepre` 取前缀、`display_name` 虚拟字段、`bbs_user` 无 `status` 字段、`http_url_path()` 上下文敏感、前台生成 admin URL 用 `admin_url()`、分页 URL 分隔符动态决定
    - 数据完整性：`thread_create`/`post_create` 的 `skip_attach_assoc`、`attach_assoc_post` pageToken、统计字段禁相减、`is_deleted=0` 过滤
    - 插件机制：`admin_url()` 加 `function_exists` 守卫、`model_inc_file.php` 重复加载 `class_exists` 守卫、`plugin_hook()` 传 `$data` 数组、插件升级流程顺序、`plugin_clear_tmp_dir` 清 OPcache
  - **修复 references/ 路径错误**：`api-cheatsheet.md` 和 `hooks-catalog.md` 中 `docs/0x-xxx.md` 修正为 `docs/plugindev/0x-xxx.md`
- 2026-07-24：
  - **废弃 model.min.php 合并加载机制，改为逐文件 include**：彻底解决加载顺序不确定、单插件语法错误全站白屏、并发重建文件损坏等稳定性问题。PHP 8 + OPcache 热身后性能无差异。
  - 同步更新所有文档：SKILL.md 中删除「install.php 末尾清 model.min.php」规则，替换为「修改核心文件后清 tmp/ 编译缓存」；所有「生产环境走 min.php」的描述统一改为「项目无 spl_autoload，lib 类不会自动加载」
  - 新增架构变更文档：[plugindev/09-model-loading-refactor.md](../plugindev/09-model-loading-refactor.md)
- 2026-07-18（第三次）：
  - **补充 plugindev 完整手册的 GitHub 下载地址**：https://github.com/domidesign/xiunox/tree/main/docs/plugindev
  - SKILL.md 顶部、底部「何时读 References / plugindev」表前，README.md「配套完整手册」章节均加上下载地址与使用说明
  - 明确「未下载 plugindev 时，本目录 references/ 下 3 个速查文档仍可独立使用，覆盖 80% 日常开发需求」，避免文档缺失导致 AI 无法工作
- 2026-07-18（第二次）：
  - **修复 SKILL.md conf.json 示例字段类型错误**：`overwrites_rank` 从 `[]`（数组）改为 `{}`（对象）；`dependencies` 从 `[]`（数组）改为 `{}`（对象）；`version` 从 `"1.0"` 改为 `"1.0.0"`（三位制）；`bbs_version` 从 `"1.1"` 改为 `"1.0"`（两位制）；补充缺失字段 `capabilities` / `type` / `author` / `id`；新增「字段类型陷阱」表
  - **建立 plugindev 完整手册指针**：SKILL.md 顶部添加配套手册说明，底部「何时读 References / plugindev」表新增 12 个分册的导航，避免 AI 在精简版找不到细节时反复摸索
  - README.md 新增「配套完整手册」章节，列出 plugindev/ 下 12 个分册的内容与何时读哪个的决策树
- 2026-07-18（第一次）：补充 hook return 规则（覆盖 model 层，新增 07-18 pid 丢失事故案例）；精简 SKILL.md「快速 API 参考」章节（下沉到 references/api-cheatsheet.md，去重 100+ 行）；重写 references/ai-rules.md 为对照检查流程（避免与 SKILL.md 重复维护）；新增 README.md 文档导航
