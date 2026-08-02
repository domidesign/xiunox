# v1.1.0 - 2026-07-13

> **版本说明**: 插件管理体系优化，建立插件 API 注册机制与缓存辅助

## Added
- `PluginApiRegistry` 插件 API 注册表，统一管理插件接口注册与调用
- `CacheHelper` 缓存辅助类，优化插件相关缓存操作

## Changed
- 更新 6 个 `lib` 核心库文件（插件 API 注册、缓存机制重构）
- 更新 4 个 `admin/view/htm` 后台模板（插件管理界面优化）
- 更新 3 个 `view/htm` 前端模板
- 更新 2 个 `model` 数据模型
- 重构 `audit` 审计模块与 `plugin.func` 插件核心函数
- 后台首页插件管理链接优化

## 统计
- 文件总数：32（修改 32）
- 提交哈希：e7a6d3e → a6bca3e