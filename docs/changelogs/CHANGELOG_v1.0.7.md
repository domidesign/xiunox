# XIUNOX v1.0.7 更新日志 - 2026-07-07

> **版本说明**: API 接口扩展 + SEO 体系初建 —— 新增站点地图（sitemap）、个人中心 API（my.php），新增 NavService 导航服务与 PluginApiRegistry 插件 API 注册表，帖子/版块页引入 canonical/OG/JSON-LD

## 🆕 新功能

### SEO 体系（初建）
- **站点地图**（`route/sitemap.php` 新增）：自动生成 sitemap
- **版块页 SEO 标签**：canonical + Open Graph + JSON-LD BreadcrumbList（面包屑结构化数据）（`6565fc0`）

### API 扩展
- **新增个人中心 API**（`api/v1/my.php`）：我的帖子/收藏/点赞/关注等
- **admin / auth / notify / user 等端点增强**（`b126755`）
- **API 能力配置**（`xn_build_app_capabilities`）：应用权限能力 JSON 构建，非管理员强制关闭 skip_captcha/skip_audit

### 服务与架构
- **NavService**（`lib/NavService.php` 新增）：导航服务，统一前台/后台导航数据结构
- **PluginApiRegistry**（`lib/PluginApiRegistry.php` 新增）：插件 API 注册表
- **DiscoverService**：发现服务增强

## 🔧 重构与优化

- **后台导航设置重构**（`setting_nav.php`）：导航管理能力增强
- **后台 API 设置/文档/调试页优化**（`api_settings/api_doc/api_debug`）
- **前台优化**：头部导航、侧边栏、帖子页、通知页（my_notify）渲染改进
- **AI 日志页**、插件扫描器、安全审计页同步优化
- **新增 llms.txt**：站点 AI 爬虫指引文件
- **多语言同步更新**

## 🛡️ 安全加固

- API 应用能力白名单：非管理员（gid≠1）强制关闭跳过验证码/审核/限流权限

## 📊 统计
- 文件总数：90（新增 5，修改 85，删除 0）
- 代码量：+4,472 行 / -807 行
- 提交范围：`66e33f1` → `5d7668a`（6 个提交）
