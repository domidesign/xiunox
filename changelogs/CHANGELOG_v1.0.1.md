# v1.0.1 - 2026-06-16

> **版本说明**: 搭建后台管理系统框架，引入 HTMLPurifier 安全过滤与路由机制

## Added
- 后台入口：`admin/.htaccess` 后台目录访问控制
- 后台路由：19 个 `admin/route/*.php` 后台路由控制器
- 后台视图：58 个 `admin/view/htm/*.htm` 后台管理界面模板
- HTMLPurifier 库：131 个 `lib/HTMLPurifier` 文件，提供 XSS 过滤与 HTML 安全净化
- 前端路由：19 个 `route/*.php` 前端路由控制器
- 新增模型：24 个 `model` 数据模型文件

## Changed
- 重构路由分发机制，统一前后台 MVC 架构
- 优化视图模板引擎，支持更灵活的渲染逻辑
- 更新 73 个已有文件

## Removed
- 清理 5 个废弃文件

## Security
- 集成 HTMLPurifier 库，防止 XSS 跨站脚本攻击
- 配置 `admin/.htaccess` 访问控制，保护后台入口

## 统计
- 文件总数：567（新增 489，修改 73，删除 5）
- 提交哈希：2089c2c → 8040f07