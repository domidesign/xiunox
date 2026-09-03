# XIUNOX 插件开发 Skill

> XIUNO BBS X（XIUNOX 现代化分支）插件开发专家 Skill。
> 基于真实源码逐条核对，所有 API 签名、hook 名称、约定均来自代码本身。

本仓库是 **XIUNOX 插件开发的 AI 协作 Skill 包**，供 AI 开发者（Claude / Trae 等）与人类开发者共同使用，覆盖插件架构、hook 注册、API 调用、前端集成、安全规范、安装/卸载/升级脚本、Service 类、路由扩展等全部插件开发场景。

***

## 快速入口

| 想做什么               | 看这里                                                                                               |
| ------------------ | ------------------------------------------------------------------------------------------------- |
| **AI 直接使用本 Skill** | [SKILL.md](SKILL.md)（简洁入口：硬规则速查表 + 工作流 + 失败策略）                                                    |
| **人类通读完整手册**       | [references/manual/README.md](references/manual/README.md)（22 篇结构化手册）                             |
| **纯速查（单文件）**       | [references/](references/)（hooks-catalog / api-cheatsheet / frontend-patterns / admin-patterns 等） |

## 文档结构

```
xiunox-plugin-dev/
├── SKILL.md                 # Skill 入口：When to Use / 硬规则 / 工作流 / 失败策略 / 交付检查表
├── README.md                # 本文件：仓库门面 + 导航
└── references/
    ├── manual/              # 完整手册（01-architecture ~ 19-user-nav + README + plugin-mutex-guide）
    │   └── plans/           # 历史功能设计稿
    └── *.md                 # 单文件速查（hooks / api / frontend / admin / notify / security / user-nav ...）
```

- **`SKILL.md`** — 给 AI 的精简入口，含核心硬规则速查表、开发工作流、失败策略与交付检查表。

- **`references/manual/`** — 给人和 AI 一起用的完整多文件手册，编号前缀可按顺序读，也可单查。

- **`references/*.md`** — 面向高频查速的整体模式速查。

## 快速开始

1. 读 [references/manual/README.md](references/manual/README.md) 了解插件怎么跑起来。
2. 写代码时对照 [SKILL.md](SKILL.md) 的硬规则与工作流。
3. 卡住了查 [references/](references/) 单文件速查，或手册对应编号章节。

> 手册与源码不一致时以源码为准（`model/plugin.func.php`、`xiunophp/*.func.php`、`model/*.func.php`、`lib/*.php`、`view/htm/*.htm`），本 Skill 不是规范源头。

