# v1.0.2 - 2026-06-30

> **版本说明**: 功能完善与安全加固，升级 PHP 8.0+ 支持，重构 API 认证体系

## Added
- API 接口层：17 个 `api/v1/*.php` RESTful API 接口文件
- 新增后台路由与控制器扩展
- 新增模型与数据访问层文件

## Changed
- **Breaking Change**: 放弃 PHP 5.x 兼容性，强制要求 PHP 8.0+
- **Breaking Change**: 重构 API 认证体系（`api_key` → 新认证机制）
- **Breaking Change**: 移除 `friendlink`（友情链接）和 `notice`（公告）模块
- **Breaking Change**: 前端代码高亮从 PrismJS 替换为 highlight.js
- 重写 `admin/.htaccess` 后台访问控制规则
- 全面更新 `admin/route/*.php` 后台路由（20 个文件）
- 更新 76 个 `view/htm` 前端模板
- 更新 64 个 `admin/view/htm` 后台模板
- 更新 23 个模型文件

## Removed
- 删除 46 个废弃文件

## Security
- 强化后台访问控制
- API 认证机制全面重构，提升接口安全性

## 统计
- 文件总数：464（新增 207，修改 211，删除 46）
- 提交哈希：8040f07 → 13828f4