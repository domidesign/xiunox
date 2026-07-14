## 通用说明

![通用说明](images/16-common-01.png)

### 缓存清理
- 修改模板后需清理 `tmp/` 缓存目录
- 修改 CSS 后需硬刷新浏览器（Ctrl+F5）
- 可在「其他」→「清理缓存」页面批量清理

### 数据库升级
- 改数据库结构需在后台「系统升级」页面（`/admin/?upgrade.htm`）添加升级项
- 通过升级页面执行数据库变更，确保数据安全

### 安全规范
- 所有 POST 表单均包含 CSRF Token 保护
- 前端输出使用 `esc_html()` 防止 XSS
- 数据库操作仅使用 pdo_mysql

### 后台技术说明
- 后台不使用 htmx，使用传统表单提交
- 图标统一使用 Tabler Icons
- UI 框架为 Bootstrap 5.3+
