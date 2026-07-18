# XIUNOX 插件开发文档

XIUNOX（XIUNO BBS X 重构版）插件开发规范与速查文档。文档面向 AI（Trae Skill）和人类开发者双重读者。

## 文档结构

### 本目录（Skill 精简版）

| 文件 | 内容 | 何时读 |
|---|---|---|
| [SKILL.md](SKILL.md) | **主文档**：核心架构 + 硬规则 + 工作流 + 进阶用法 | 完整理解插件机制时通读 |
| [references/ai-rules.md](references/ai-rules.md) | AI 代码生成对照流程（7 阶段检查清单） | 写完一段代码后对照检查 |
| [references/api-cheatsheet.md](references/api-cheatsheet.md) | 基础 API 速查（DB / 输入输出 / 安全 / 缓存 / 全局变量） | 忘记函数签名时查 |
| [references/hooks-catalog.md](references/hooks-catalog.md) | Hook 点全量目录（按页面分类） | 找注入点时查 |

### 配套完整手册（plugindev/，深入细节时查）

> **下载地址**：https://github.com/domidesign/xiunox/tree/main/docs/plugindev
> 本项目 `docs/plugindev/` 目录不存在时，从该 GitHub 仓库下载后放到 `docs/plugindev/` 即可使用全部交叉引用。未下载时，本目录 `references/` 下 3 个速查文档仍可独立使用，覆盖 80% 日常开发需求。

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

### 何时读哪个

- **写代码时对照检查** → 本目录 [references/ai-rules.md](references/ai-rules.md)
- **查 API 签名** → 本目录 [references/api-cheatsheet.md](references/api-cheatsheet.md) 或 plugindev/04（完整版）
- **查 hook 注入点** → 本目录 [references/hooks-catalog.md](references/hooks-catalog.md) 或 plugindev/03（完整版）
- **理解架构原理** → plugindev/01
- **查 conf.json 字段** → plugindev/02
- **不确定某条规则** → plugindev/06（规则详情）
- **前端/htmx/安全** → plugindev/05

## 5 分钟快速上手

XIUNOX 插件 = `plugin/<dir>/` 下的文件集合 + 编译期合并到核心代码。

### 核心概念

1. **编译时合并**：把 hook 文件放到 `plugin/<dir>/hook/<hook名>` 就等于注册了 hook，没有 `add_hook()` 函数。`_include()` 把 hook 内容物理拼进源文件，缓存到 `tmp/`。
2. **模型三层命名**：`__` 原始层（纯 DB）→ 单 `_` 业务层（缓存/计数/通知）→ `_format` 装饰层。**插件永远调单下划线业务层。**
3. **修改源文件后必须清 tmp**：`_include()` 不比较 mtime，批量清理 `rm -f tmp/route_*.php tmp/model_*.php tmp/view_htm_*.htm`。

### 最小插件骨架

```
plugin/my_plugin/
├── conf.json              # 元信息（name/version/hooks_rank/...）
├── install.php            # 建表 + 默认配置 + 清 model.min.php
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

- 2026-07-18（第三次）：
  - **补充 plugindev 完整手册的 GitHub 下载地址**：https://github.com/domidesign/xiunox/tree/main/docs/plugindev
  - SKILL.md 顶部、底部「何时读 References / plugindev」表前，README.md「配套完整手册」章节均加上下载地址与使用说明
  - 明确「未下载 plugindev 时，本目录 references/ 下 3 个速查文档仍可独立使用，覆盖 80% 日常开发需求」，避免文档缺失导致 AI 无法工作
- 2026-07-18（第二次）：
  - **修复 SKILL.md conf.json 示例字段类型错误**：`overwrites_rank` 从 `[]`（数组）改为 `{}`（对象）；`dependencies` 从 `[]`（数组）改为 `{}`（对象）；`version` 从 `"1.0"` 改为 `"1.0.0"`（三位制）；`bbs_version` 从 `"1.1"` 改为 `"1.0"`（两位制）；补充缺失字段 `capabilities` / `type` / `author` / `id`；新增「字段类型陷阱」表
  - **建立 plugindev 完整手册指针**：SKILL.md 顶部添加配套手册说明，底部「何时读 References / plugindev」表新增 8 个分册的导航，避免 AI 在精简版找不到细节时反复摸索
  - README.md 新增「配套完整手册」章节，列出 plugindev/ 下 8 个分册的内容与何时读哪个的决策树
- 2026-07-18（第一次）：补充 hook return 规则（覆盖 model 层，新增 07-18 pid 丢失事故案例）；精简 SKILL.md「快速 API 参考」章节（下沉到 references/api-cheatsheet.md，去重 100+ 行）；重写 references/ai-rules.md 为对照检查流程（避免与 SKILL.md 重复维护）；新增 README.md 文档导航
