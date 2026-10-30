# XIUNOX 插件开发 Skill

> XIUNO BBS X（XIUNOX 现代化分支）插件开发专家 Skill。
> 基于真实源码逐条核对，所有 API 签名、hook 名称、约定均来自代码本身。

本仓库是 **XIUNOX 插件开发的 AI 协作 Skill 包**，供 AI 开发者（ZCode / Trae / Claude 等）与人类开发者共同使用，覆盖插件架构、hook 注册、API 调用、前端集成、安全规范、安装/卸载/升级脚本、Service 类、路由扩展等全部插件开发场景。

`SKILL.md` 遵循 [ZCode 技能规范](https://zcode.z.ai/cn/docs/skill) 与 [Trae 技能编写最佳实践](https://docs.trae.cn/ide_best-practice-for-how-to-write-a-good-skill)：

- **frontmatter 元数据**：`name` / `description`（≤1024 字符，含触发词，注入对话预算按 250 字符摘要）为必填，另含 `display_name` / `description_zh` / `description_en` / `category` / `version` / `author`。
- **渐进式披露**：SKILL.md 只做入口 + 硬规则 + 工作流（< 500 行），细节全部下沉 `references/`，一层引用不做链式跳转。
- **结构五标准**：边界明确（When to Use 正负向）、输入输出结构化、步骤可执行、失败策略完备、职责单一。

***

## 快速入口

| 想做什么 | 看这里 |
|---|---|
| **AI 直接使用本 Skill** | [SKILL.md](SKILL.md)（frontmatter 元数据 + 硬规则速查表 + 工作流 + 失败策略 + 交付检查表） |
| **人类通读完整手册** | [references/manual-index.md](references/manual-index.md)（19 编号分册 + 专题指南） |
| **纯速查（单文件）** | [references/](references/)（hooks / api / frontend / admin / ui / security / notify / user-nav / ai-rules） |

## 文档结构

```
xiunox-plugin-dev/
├── SKILL.md                 # Skill 入口：frontmatter / When to Use / 输入输出 / 硬规则 / 工作流 / 失败策略 / 检查表
├── README.md                # 本文件：仓库门面 + 导航
└── references/              # 两级结构（根目录/二级目录/文件），兼容 ZCode Skill 包目录层级限制
    ├── hooks-catalog.md     # Hook 点速查（均已核对源码）
    ├── api-cheatsheet.md    # API 速查
    ├── frontend-patterns.md # 前端模式 / htmx 4 / XN.*
    ├── admin-patterns.md    # 后台 UI 模式
    ├── ui-patterns.md       # UI 组件模式 / Card / Tab
    ├── security-patterns.md # 并发安全 / 积分防刷
    ├── notify-patterns.md   # 通知聚合中心模式
    ├── user-nav-patterns.md # 个人中心导航模式
    ├── ai-rules.md          # AI 协作硬规则 / 扫描器分级
    ├── manual-index.md      # 完整手册入口（导航页）
    ├── 01~19-*.md           # 手册编号分册（01-architecture ~ 19-user-nav）
    ├── plugin-mutex-guide.md# 插件互斥专题
    └── plans/               # 历史功能设计稿（发布包中剔除）
```

- **`SKILL.md`** — 给 AI 的精简入口：YAML frontmatter 元数据（name / description / version 等）、核心硬规则速查表、开发工作流、失败策略与交付检查表。
- **`references/*.md`** — 速查（无编号前缀）与完整手册分册（编号前缀）平铺同层：速查面向高频查速、顶部带目录（TOC）；分册可按编号顺序读，也可单查，`manual-index.md` 提供全量导航。

## 快速开始

1. AI：直接加载 [SKILL.md](SKILL.md)，按 When to Use 判断边界，写代码时对照硬规则与工作流。
2. 人：先读 [references/manual-index.md](references/manual-index.md) 了解插件怎么跑起来。
3. 卡住了查 [references/](references/) 单文件速查，或手册对应编号章节。

> 手册与源码不一致时以源码为准（`model/plugin.func.php`、`xiunophp/*.func.php`、`model/*.func.php`、`lib/*.php`、`view/htm/*.htm`），本 Skill 不是规范源头。
