# v1.1.5 更新日志 - 2026-07-31

> **版本说明**: 官方插件市场 + SEO 设置中心 —— 内置插件市场（CDN 分发、一键安装/升级/回滚）、后台 SEO 设置页与健康检查、全站 confirm 统一为 XN.confirm、积分规则正负语义统一

## 🆕 新功能

### 官方插件市场（全新）
- **OfficialPluginService**（`lib/OfficialPluginService.php` 新增）+ 后台插件市场页（`plugin_official_list.htm`）
  - 清单缓存 + jsdelivr CDN 分发 + 强制刷新
  - **版本比对 + 一键安装/升级**（含备份回滚）
  - 免费/付费筛选、关键词搜索
- 后台菜单新增「插件市场」入口
- 新增插件市场文档：`13-official-plugins-repo.md`（仓库规范）/ `14-plugin-admin-ui.md`（后台 UI 模式）

### 后台 SEO 设置中心
- **SEO 设置页**（`setting_seo.htm` 新增）：site_keywords / site_description / 站点副标题 / sitemap 开关与限制 / OG / JSON-LD / canonical 开关 / llms.txt 在线编辑
- **SEO 健康检查面板**：keywords / description / sitemap / robots / llms / og / jsonld / canonical / permalink 共 9 项自动检测
- **首页 SEO 升级**：title 拼接副标题、keywords 读配置、description 优先 site_description
- **sitemap 可配置**：开关控制（关闭返回 404）、thread_limit 与 cache_ttl 后台可调
- **OG / JSON-LD / canonical 输出开关化**：header 模板按配置输出
- 各路由移除硬编码 keywords/description，统一走全局默认值

## 🔧 重构与优化

- **全站 confirm() → XN.confirm()**：后台 16 个页面 + 前台模板统一替换，交互一致
- **积分规则语义统一**（`21c64d6`）：
  - CreditsRuleService 移除 $subEvents 硬编码反转逻辑，统一按数值正负处理（正值=奖励 add、负值=扣减 sub），与后台提示文案一致
  - CreditsService::add() 用 abs() 强制转正，与 sub() 对称，防止钩子翻转方向
- **搜索结果 UI 升级**：展示头像/作者/版块/时间，与帖子列表一致
- **导航合并**：「我的主页」与「用户中心」入口合并
- **权限不足提示优化**：游客显示登录/注册按钮；已登录显示当前用户组名 + 升级入口
- **插件初始化修复**：plugin_init 同步 conf.json.version 到 db.version；plugin_dependencies 兼容两种 conf.json 写法

## 🐛 问题修复

- **后台登录验证码**：适配 CaptchaService 新格式存储（数组含 code/expires），修复 PHP 8+ strtolower 传数组触发 TypeError fatal
- **编辑器 video 残留样式**：syncEditorContent 清理 inline height/aspect-ratio（AIEditor 拖动 resize 残留 height:0 导致手机端视频高度为 0）
- **通知帖子链接**：改用 `frontend_thread_url()`，修复后台通知列表中帖子链接被解析为 `/admin/?thread-xxx.htm` 的 bug
- **编辑帖子**：移除 hx-confirm 并跳过积分预检查
- **字数超限提示**：明确「含 HTML 格式化标签」
- **清理临时 DEBUG 日志**（api/v1/auth.php + bootstrap.php）

## 📊 统计
- 文件总数：83（新增 7，修改 76，删除 0）
- 代码量：+4,532 行 / -433 行
- 提交范围：`6d14d03` → `21c64d6`（4 个提交）
