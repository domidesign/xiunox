# XIUNOX

基于 [Xiuno BBS 4.0.4](xiunobbs4.0.4.md) 原版打造的现代化重构版本 XIUNOX，
（Xiuno BBS v4.5+，Xiuno Next均为旧项目名称，现已改名 XIUNOX）。
全面适配 PHP 8 + MySQL 8，采用 Bootstrap 5.3 与 HTMX 构建现代无刷新 UI，
安全与可扩展性大幅提升，原生支持多语言、RESTful API，让轻量论坛重获新生。



## 技术栈

- **后端**：PHP 8.0+ / MySQL8（pdo_mysql）
- **前端**：Bootstrap 5.3+ / htmx 4.x / Tabler Icons
- **架构**：htmx 纯净架构（服务端渲染 + 乐观更新 + morph DOM 保留）
- **安全**：CSRF 防护 / XSS 防护（EscapeService）/ 参数化查询 / 登录安全
- **API**：v1 版本 API，支持 Token 认证，60 天缓存

## 核心特性

- 响应式布局，自适应手机、平板、PC
- htmx 4 驱动交互：hx-get / hx-post / hx-live / hx-optimistic
- 插件机制：hook + overwrite，方便二次开发
- 多语言支持：简体中文、繁体中文、英文、日文、韩文、俄文、泰文
- 附件管理：图片缩略、视频信息获取（ffprobe 可选）
- 缓存支持：MySQL / Redis / Memcached / Yac
- 积分系统：多类型积分、每日限额、日志审计
- 安全防护：验证码、IP 黑名单、敏感词过滤、登录限速
- RESTful API：v1 版本 API，支持 Token 认证 
- 后台管理：用户 / 版块 / 插件 / 主题 / 安全等完整管理功能

## 目录结构

```
xiunobbs/
├── admin/          # 后台管理
├── api/            # RESTful API
├── conf/           # 配置文件
├── doc/            # 开发文档
├── install/        # 安装程序
├── lang/           # 多语言包
├── lib/            # 核心类库
├── model/          # 数据模型
├── plugin/         # 插件目录
├── view/           # 前台模板
│   └── htm/        # HTM 模板文件
├── upload/         # 上传文件
└── tmp/            # 编译缓存
```

## 快速开始

详见 [安装教程](install.md)

## 插件开发

详见 [插件开发](plugindev.md)

## 多语言支持

| 语言 | 代码 |
|------|------|
| 简体中文 | zh-cn |
| 繁体中文 | zh-tw |
| 英文 | en-us |
| 日文 | ja-jp |
| 韩文 | ko-kr |
| 俄文 | ru-ru |
| 泰文 | th-th |

## 授权协议

MIT 协议，附加版权保留要求条款。可自由修改、派生版本、商用，但**必须保留前后台版权标识**：

- **前台页脚**的 "Powered by XIUNOX" 标识必须完整保留，不得删除、隐藏或篡改
- **后台页脚**的 "Powered by XIUNOX Based on Xiuno BBS" 标识必须完整保留
- 不得通过 CSS 隐藏、配置关闭或任何技术手段移除上述版权标识
- 如需"去版权"，请联系 XIUNOX 获取商业授权

详见 [LICENSE](LICENSE)。安装时将在协议页显示完整许可文本。


## BASED ON
Xiuno BBS 4.0.4
https://github.com/xiuno/xiunobbs

## 使用的库
- **Bootstrap**：5.3.3
- **htmx**：4.1.1
- **Tabler Icons**：2.1.0
