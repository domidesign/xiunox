# XIUNOX v1.0.8 更新日志 - 2026-07-09

> **版本说明**: 大规模瘦身与重构 —— 移除 4 个非中英语言包与 docs 文档目录（-11,628 行），后台/API/路由/模型全面优化，Cookie 安全策略支持自动 HTTPS 检测

## 🔧 重构与优化

### 大规模清理（瘦身）
- **移除语言包**：删除 ja-jp（日语）、ko-kr（韩语）、ru-ru（俄语）、th-th（泰语）4 个语言包
- **移除 docs/ 文档目录**：v1.0.3 新增的 12 篇文档全部移除（后续在 v1.1.1 以新结构重建）
- **前端 JS 精简**：bbs.js / xiuno.js 等脚本瘦身

### 后台与核心优化
- **Cookie Secure 策略升级**（`ffce50e`）：`security_cookie_secure` 支持 0=自动检测 HTTPS、>0=强制 Secure，后台/前台 cookie 统一
- **后台重构**：版块、帖子、插件、设置路由与视图优化
- **API 重构**：auth / attach / post / thread / my / bootstrap 端点改进
- **模型优化**：forum / notify / post / thread / user 等更新
- **前端优化**：header、侧边栏、帖子列表、底部导航重构
- **移动端修复**：后台管理侧边栏展开按钮在移动端无效的问题（`970de9f`）
- **新增 AGENTS.md**：项目 AI 协作规则文件（`4d34481`）
- **核心服务**：EditorService / UpgradeService / NavService / CaptchaService 优化

## 📊 统计
- 文件总数：125（新增 1，修改 92，删除 32）
- 代码量：+1,081 行 / -11,628 行
- 提交范围：`5d7668a` → `f4201b8`（6 个提交）
