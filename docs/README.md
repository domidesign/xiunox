# Xiunox 项目结构文档

## 项目简介
Xiuno BBS 4.0.4 —— 基于 PHP 的轻量级论坛系统。本目录提供项目自有代码的目录结构与文件用途说明，便于新接手者快速建立项目全貌认知。

## 顶层目录速查表

| 目录 | 用途 | 详细文档 |
| --- | --- | --- |
| 根目录 | 项目入口、公共初始化、协议声明、Web 服务器规则 | [root.md](root.md) |
| `admin/` | 后台管理系统：路由、视图模板、菜单配置、辅助函数 | [admin.md](admin.md) |
| `api/` | RESTful API 接口（v1 版本），含鉴权、附件、积分、搜索等端点 | [api.md](api.md) |
| `conf/` | 默认配置文件（站点、附件、邮件、SMTP） | [conf.md](conf.md) |
| `config/` | 安全配置（敏感词、安全策略） | [conf.md](conf.md) |
| `install/` | 安装向导：环境检测、数据库初始化、安装页模板 | [install.md](install.md) |
| `lib/`（自有） | 基础工具服务类：API 鉴权、缓存、积分、CSRF、错误处理等 11 个类 | [lib.md](lib.md) |
| `model/` | 数据访问层：31 个 `*.func.php` 文件，对应各数据库表的 CRUD 操作 | [model.md](model.md) |
| `route/` | 前台页面路由：17 个 `.php` 文件，对应论坛用户访问的页面 | [route.md](route.md) |
| `service/` | 业务服务层：8 个 `*Service.php` 业务领域服务类，封装跨表/跨实体业务逻辑 | [service.md](service.md) |
| `view/` | 前台视图与静态资源：HTML 模板、CSS、JS、图片 | [view.md](view.md) |
| `xiunophp/` | 框架核心库：自研轻量 PHP 框架，提供数组、缓存、数据库、图像、加密、邮件、ZIP 等基础能力（23 个文件） | [xiunophp.md](xiunophp.md) |

## 分层关系速记

```
请求 → index.php (入口) → route/* (前台路由) / admin/route/* (后台路由) / api/v1/* (API)
                              ↓
                         service/* (业务服务层) ← 跨实体业务逻辑
                              ↓
                         model/* (数据访问层) ← 单表 CRUD
                              ↓
                         xiunophp/* (框架核心库) ← db/cache 基础能力
                              ↓
                         view/* (视图模板)
```

- `lib/` 与 `service/` 的区别：`lib/` 是基础工具服务（CSRF、缓存、API 鉴权等基础设施），`service/` 是业务领域服务（针对具体业务实体如帖子、用户、积分的业务逻辑）。

## 不覆盖范围声明

以下目录不属于自有代码，本文档集不在分目录文档中展开：

### 后端第三方库
- **`lib/HTMLPurifier/`**：第三方 HTML 净化库（[ezyang/htmlpurifier](https://github.com/ezyang/htmlpurifier)），用于过滤用户提交的富文本，防止 XSS。入口文件为 `lib/HTMLPurifier/HTMLPurifier.php`。本项目未对其做修改，使用时直接 `require`。

### 前端第三方库
- **`view/vendor/`**：第三方前端库目录，含：
  - `animejs/` — 动画库
  - `bootstrap/` — CSS 框架
  - `chartjs/` — 图表库
  - `cropperjs/` — 图片裁剪
  - `highlightjs/` — 代码高亮
  - `htmx/`（含 `ext/` 扩展）— AJAX 库
  - `qrcodejs/` — 二维码生成
  - `tabler-icons/` — 图标字体
- **`view/js/aieditor/`**：第三方 AI 编辑器组件。
- **`view/js/jquery-3.7.1.min.js`** 与 **`view/js/jquery.qrcode.min.js`**：第三方 jQuery 库。

### 多语言包
- **`lang/`**：多语言包目录，结构如下：
  - 每种语言一个子目录，命名遵循 `语言-地区` 格式（如 `zh-cn`、`zh-tw`、`en-us`、`ja-jp`、`ko-kr`、`ru-ru`、`th-th`）。
  - 每个语言目录下包含 5 类文件：
    - `bbs.js`：前端 JavaScript 文案
    - `bbs.php`：前台主程序文案
    - `bbs_admin.php`：后台管理文案
    - `bbs_common.php`：通用文案（前后台共用）
    - `bbs_install.php`：安装向导文案
  - 新增语言只需复制一个语言目录并翻译对应文件即可。

## 占位目录声明

以下目录仅含 `.gitkeep` 占位文件，用于在 Git 中保留空目录结构，运行时由系统写入：

- **`log/`**：运行时日志目录，由系统记录运行日志。
- **`plugin/`**：插件目录，由后台插件管理功能写入插件文件。
- **`tmp/`**：临时文件目录，运行时缓存/编译临时文件。
- **`upload/`**：用户上传文件目录，含 `forum/`（版块附件）与 `tmp/`（临时上传）两个子目录占位，运行时由附件上传功能写入。

这些目录不应纳入版本控制的实际内容（仅 `.gitkeep` 入库），部署时应确保 Web 服务器对其有写入权限。

## 文档使用建议

1. 第一次接手项目：先读本文件，再按 `admin/ → api/ → route/ → model/ → service/ → conf/ → lib/ → xiunophp/` 顺序浏览。
2. 查询某个 API 的实现：直接看 [api.md](api.md)。
3. 查询某个前台页面入口：看 [route.md](route.md)。
4. 查询某个后台功能的入口：看 [admin.md](admin.md) 的 `route/` 部分。
5. 查询某张表的 CRUD 操作：看 [model.md](model.md)。
6. 查询跨实体业务逻辑：看 [service.md](service.md)。
7. 修改富文本过滤策略：直接查阅 `lib/HTMLPurifier/HTMLPurifier.php`（不在本文档集覆盖）。
8. 理解 db/cache 底层实现：看 [xiunophp.md](xiunophp.md)。
