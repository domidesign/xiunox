# v1.1.3 - 2026-07-23

> **版本说明**: API 日志与调试台上线，服务自注册架构重构，灯箱效果增强

## Added
- API 日志页：`api_log.htm` 后台 API 调用日志查看器
- API 调试台：`api_debug.htm` 后台 API 接口调试工具
- 编辑器服务：`EditorService` 富文本编辑器服务
- 导航服务：`NavService` 导航管理服务
- 服务自注册架构（Service Self-Registration）
- Lightbox 灯箱效果增强

## Changed
- 更新 22 个 `admin/view/htm` 后台模板（API 日志/调试页面）
- 更新 15 个 `view/htm` 前端模板
- 更新 12 个 `model` 数据模型
- 更新 9 个 `lib` 核心库文件
- 引入服务定位器模式，各 Service 自注册到容器

## Removed
- 清理 2 个废弃文件

## 统计
- 文件总数：113（新增 7，修改 104，删除 2）
- 提交哈希：551e27a → 8100e15