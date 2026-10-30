# XIUNOX v1.0.2 更新日志 - 2026-06-30

> **版本说明**: 功能完善与安全加固 —— 新增完整 API v1 接口体系与 Service 服务层、后台管理界面全面 Bootstrap 现代化、前端资源库升级（htmx/新版 jQuery/Tabler 图标）、强制 PHP 8.0+ 与 MySQL 5.7+、移除 PHP 5.x 兼容代码

## 🆕 新功能

### API v1 接口体系（全新，`api/v1/` 共 18 个端点文件）
- **统一入口**（`bootstrap.php`，343 行）：API 初始化、鉴权、响应封装
- 资源端点：用户（auth/user）、帖子（post/thread）、版块（forum）、附件（attach）、积分（credits）、通知（notify）、管理（admin）、版主（mod）、排行（rank）、搜索（search）、验证码（captcha）、站点信息（site）、开放接口文档（openapi）

### Service 服务层（全新，`service/` 共 8 个）
- **PostService / ThreadService / UserService**：帖子、主题、用户的增删改查与分页查询
- **AttachmentService**：图片/视频/文件三类上传的统一处理
- **NotificationService**：站内通知发送、未读计数、标记已读
- **ForumService / RankService / CreditsRuleService**：版块、排行、积分规则的业务封装

### 后台管理增强
- **新增审计页**（`route/audit.php` + `audit.htm`）：内容审核列表
- **新增健康检查页**（`route/health.php` + `health.htm`）：系统环境自检
- **安全设置扩展**：新增账号安全（`security_account`）、内容安全（`security_content`）、其他安全（`security_other`）、发帖限制（`security_post_limit`）4 个设置页
- **API 设置页**（`api_settings.htm`）：API 应用管理配置
- **发帖页两级版块选择**：先选一级分区再选子版块
- **后台界面全面 Bootstrap 化**：路由与视图重写，统一现代化组件样式

### 前端资源升级
- **引入 htmx**（含 hx-live / hx-optimistic 扩展）：无刷新交互
- **jQuery 3.1.0 → 3.7.1**，移除 vue.js / popper.js / es6-shim 等旧依赖
- **引入 Tabler Icons** 图标库 + 图标选择器（`tabler-icon-picker.js`）
- **引入 highlight.js** 代码高亮（替代 prismjs）、Chart.js 图表、Cropper.js 图片裁剪、anime.js 动画、qrcode.js 二维码
- **新增 AIEditor 富文本编辑器**（`view/js/aieditor/`）
- **新增用户积分页**（`credits.htm` + `credits_rules.htm`）：积分余额与规则展示
- **新增用户关注/粉丝/喜欢/帖子标签页**（`user_following/followers/like/post.htm`）
- **新增用户信息卡片**（`user_info_card.inc.htm`）与 12 个预设头像（webp）

### 运行环境升级
- **强制 PHP 8.0+ / MySQL 5.7+**（`e87011e`），安装程序同步校验
- **移除全部 PHP 5.x 兼容代码**（`735f28e`），含废弃的 `db_mysql.class.php`
- **fid 字段类型统一**为 `smallint(5) unsigned`（`b6d7a21`）

## 🔧 重构与优化

- **HTMLPurifier 升级**：支持 `div[data-type][data-params]` 属性，适配 AIEditor 富文本输出（`9de495e`）
- **EditorService 重构**：编辑器资源注入逻辑优化（`1a6c793`）
- **API 鉴权重构**（`adf363d`）：移除 friendlink/notice 旧接口，统一鉴权流程
- **通知体系收敛**：删除 `model/notice.func.php`，通知统一走 notify 模型
- **移除废弃后台路由**：friendlink（友情链接）、upgrade_phase1（分阶段升级）改为在线升级入口
- **清理工具脚本**：删除 `tool/` 下 dx/dz 转换、密码重置等 10 个一次性脚本
- **移除敏感配置出仓库**：`conf/smtp.conf.php` 改为 `smtp.conf.default.php` 模板，真实配置不入库（`b00f0bc`）
- **帖子管理列表过滤**：后台帖子列表支持按版块过滤，空操作栏自动隐藏（`a59dace`）

## 🛡️ 安全加固

- **Cookie 安全**：Secure 路径、http_referer 校验、登出重定向加固（`6ef5269`）
- **上传目录防护**：`upload/.htaccess` 禁止执行 PHP（随 v1.0.6 进一步强化）
- **版权合规**：强制保留页脚版权标识 + SVG 品牌 Logo 重设计（`52b250d`）

## 📊 统计
- 文件总数：464（新增 207，修改 211，删除 46）
- 代码量：+146,286 行 / -61,327 行
- 提交范围：`8040f07` → `13828f4`（20 个提交）
