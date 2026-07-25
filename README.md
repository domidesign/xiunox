# XIUNOX

> **当前版本：v1.1.0**

基于 [Xiuno BBS 4.0.4](xiunobbs4.0.4.md) 原版打造的现代化重构版本 XIUNOX。
（项目前期曾使用 Xiuno BBS v4.5+、Xiuno Next 作为项目名称，现已更名为 XIUNOX）

全面适配 PHP 8 + MySQL 8，采用 Bootstrap 5.3 与 HTMX 4 构建现代无刷新 UI，
安全与可扩展性大幅提升，原生支持多语言、RESTful API，让轻量论坛重获新生。

## 技术栈

- **后端**：PHP 8.0+ / MySQL 8（pdo_mysql）
- **前端**：Bootstrap 5.3 / htmx 4.1（含 hx-live、hx-optimistic 扩展）/ Tabler Icons 2.1
- **编辑器**：AIEditor（内置 AI 续写/优化能力，对接 AIService）
- **动画**：anime.js
- **架构**：htmx 纯净架构（服务端渲染 + 乐观更新 + morph DOM 保留）
- **安全**：CSRF 防护 / XSS 防护（EscapeService）/ 参数化查询 / 登录限速 / 操作审计
- **缓存**：MySQL / Redis / Memcached / Yac
- **API**：v1 版本 RESTful API，支持 Token 认证

## 核心特性

- 响应式布局，自适应手机、平板、PC
- htmx 4 驱动交互：局部刷新、乐观更新、morph DOM 保留，无刷新发帖/回复/操作
- 插件机制：hook + overwrite，方便二次开发；内置插件扫描器与崩溃自动禁用
- 多语言支持：简体中文、繁体中文、英文
- 附件管理：图片缩略、视频信息获取（ffprobe 可选）、图片裁剪
- 积分系统：多类型积分、每日限额、日志审计
- 安全防护：验证码、IP 黑名单、敏感词过滤、登录限速、操作审计
- AI 服务：内置 AIService 抽象，支持多 Provider 接入；AIEditor 提供 AI 续写/优化
- 代码高亮：highlight.js（仅帖子详情页加载）
- 数据可视化：Chart.js
- 二维码：qrcode.js
- RESTful API：v1 版本 API，支持 Token 认证
- 后台管理：用户 / 版块 / 插件 / 主题 / 安全 / AI 等完整管理功能

## 目录结构

```
xiunobbs/
├── admin/          # 后台管理（route + view + 菜单配置）
├── api/v1/         # RESTful API v1
├── conf/           # 配置文件（conf / smtp / attach / email 模板）
├── config/         # 安全配置（敏感词、保留词）
├── cli/            # 命令行脚本（密码迁移、积分同步）
├── cron/           # 定时任务
├── docs/           # 文档
│   ├── userguide/  # 前后台使用指南
│   └── plugindev/  # 插件开发手册（多文件版）
├── install/        # 安装程序
├── lang/           # 多语言包（zh-cn / zh-tw / en-us）
├── lib/            # 核心类库（Service、Security、PHPMailer、HTMLPurifier 等）
├── model/          # 数据模型（func.php 函数库）
├── plugin/         # 插件目录
├── route/          # 前台路由
├── service/        # 业务 Service 层
├── view/           # 前台资源
│   ├── htm/        # HTM 模板文件
│   ├── js/         # 自研 JS（xiuno-modern.js、bbs.js、aieditor 等）
│   ├── css/        # 样式（bootstrap-bbs.css、theme.css）
│   ├── img/        # 静态图片
│   └── vendor/     # 前端第三方库（bootstrap / htmx / animejs / chartjs 等）
├── xiunophp/       # 核心框架（db / cache / image / 加密 / 邮件等基础函数）
├── upload/         # 上传文件
└── tmp/            # 编译缓存（_include 编译产物 + cache 数据缓存）
```

## 快速开始

详见 [安装教程](install.md)。

## 使用文档

- [前台操作指南](docs/userguide/frontend-guide.md)
- [后台操作指南](docs/userguide/backend-guide.md)

## 插件开发

详见 [插件开发手册](docs/plugindev/README.md)（多文件版，推荐）；
旧版 [plugindev.md](plugindev.md) 保留作为历史参考。

## 多语言支持

| 语言 | 代码 |
|------|------|
| 简体中文 | zh-cn |
| 繁体中文 | zh-tw |
| 英文 | en-us |

## 授权协议

MIT 协议，附加版权保留要求条款。可自由修改、派生版本、商用，但**必须保留前后台版权标识**：

- **前台页脚**的 "Powered by XIUNOX" 标识必须完整保留，不得删除、隐藏或篡改
- **后台页脚**的 "Powered by XIUNOX Based on Xiuno BBS" 标识必须完整保留
- 不得通过 CSS 隐藏、配置关闭或任何技术手段移除上述版权标识

详见 [LICENSE](LICENSE)。安装时将在协议页显示完整许可文本。

## 使用的库

| 库 | 版本 | 说明 |
|----|------|------|
| Bootstrap | 5.2.3 | UI 框架 |
| htmx |4.0.0| 无刷新交互（含 hx-live / hx-optimistic 扩展） |
| Tabler Icons | v3.45.0 | 图标库 |
| AIEditor | - | 富文本编辑器，内置 AI 能力 |
| anime.js | - | 轻量动画库 |
| Chart.js | - | 数据可视化图表 |
| Cropper.js | - | 图片裁剪 |
| highlight.js | - | 代码高亮（仅帖子页加载） |
| qrcode.js | - | 二维码生成 |
| jQuery | 3.7.1 | 兼容遗留（1.1.4 版本开始弃用） |
| PHPMailer | - | 邮件发送（内置 `lib/`） |
| HTMLPurifier | - | HTML 净化（内置 `lib/`） |

## Based on

Xiuno BBS 4.0.4 — https://github.com/xiuno/xiunobbs
