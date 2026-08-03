# v1.0.3 - 2026-07-02

> **版本说明**: 核心框架深化 —— 引入 version.php 统一版本管理、新增 QueryBuilder 查询构造器与 ServiceRegistry 服务注册中心、加入 BizException 业务异常体系，帖子页改三栏布局，补齐项目文档，全面转入生产模式（DEBUG=0）

## 🆕 新功能

### 核心基础设施
- **version.php 版本号唯一来源**（`0bb49c3`）：所有入口（index / api / install）统一引用 `XIUNOX_VERSION` 常量，禁止硬编码版本号
- **QueryBuilder**（`lib/QueryBuilder.php`）：链式 SQL 查询构造器，简化复杂查询编写
- **ServiceRegistry**（`lib/ServiceRegistry.php`）：服务注册与获取中心，统一服务生命周期管理
- **BizException**（`lib/BizException.php`）：业务异常类，配合 ErrorHandler 统一处理
- **CacheHelper**（`lib/CacheHelper.php`）：缓存键管理与 TTL 配置辅助
- **xn_safe_io**（`lib/xn_safe_io.php`）：文件读写安全封装

### 前端与页面
- **帖子页三栏布局**：拆分为 `thread_left.inc.htm` / `thread_main.inc.htm` / `thread_right.inc.htm`，侧栏内容组件化
- **帖子页 JS 独立**（`thread_js.inc.htm`）：脚本按需加载
- **主题切换**（`route/theme.php` + `theme.htm`）：支持多主题切换
- **回收站管理**（`thread_recycle.htm`）：后台帖子回收站页

### 文档体系（全新，`docs/` 共 12 篇）
- 架构文档：`admin.md` / `api.md` / `conf.md` / `install.md` / `lib.md` / `model.md` / `root.md` / `route.md` / `service.md` / `view.md` / `xiunophp.md`
- 覆盖全部目录结构与核心模块使用说明

## 🔧 重构与优化

- **数据库层重构**：移除 `db_mysql.class.php`，统一走 PDO 驱动（mysql/sqlite）；cache 层（file/memcached/mysql/redis）同步优化
- **PHPMailer 升级**：邮件发送库版本更新
- **服务层深化**：Post/Thread/User/Notification/Attachment 等 8 个 Service 增强
- **模型层重构**：attach/cron/forum/notify/post/thread/user 等模型优化
- **后台管理优化**：版块列表/创建页重写、设置导航重构、用户管理增强
- **安装程序**：SQL schema 更新、许可证增加"非法使用声明"并要求双重确认（`618a3ea`）
- **编辑器服务增强**：新增通知类型注册机制（NotifyTypeRegistry）、`user_create_submit_before` 钩子（`a93b1bd`）
- **API 增强**：bootstrap 初始化与 site 端点优化
- **UI 细节**：安装页宽度与卡片样式、导航链接间距、发帖表单布局微调

## 🛡️ 安全与合规

- **DEBUG 切换为 0**：生产模式关闭调试输出（`0bb49c3`）
- **许可证合规**：明确禁止非法使用声明 + 安装时双重确认

## 📊 统计
- 文件总数：165（新增 23，修改 141，删除 1）
- 代码量：+10,306 行 / -2,943 行
- 提交范围：`13828f4` → `0bb49c3`（11 个提交）
