# Xiuno X 系统机制与服务文档

> **版本**：Beta
> **最后更新**：2026-08-02
> **适用人群**：站长 / 开发者

## 文档导航

### P0 - 核心系统
| 文档 | 说明 | 适用人群 |
|------|------|----------|
| [XIUNOX_Security.md](XIUNOX_Security.md) | 安全机制（认证、CSRF、XSS、Cookie、上传安全） | 站长 + 开发者 |
| [XIUNOX_Cache.md](XIUNOX_Cache.md) | 缓存系统（4种驱动、多级缓存、清理机制） | 站长 + 开发者 |
| [XIUNOX_Permission.md](XIUNOX_Permission.md) | 权限系统（用户组、权限节点、权限检查） | 站长 + 开发者 |
| [XIUNOX_Credits.md](XIUNOX_Credits.md) | 积分系统（三种货币、规则引擎、事务保证） | 站长 + 开发者 |

### P1 - 功能模块
| 文档 | 说明 | 适用人群 |
|------|------|----------|
| [XIUNOX_Navigation.md](XIUNOX_Navigation.md) | 导航系统（菜单结构、插件扩展、面包屑） | 站长 + 开发者 |
| [XIUNOX_SEO.md](XIUNOX_SEO.md) | SEO 机制（伪静态、Meta标签、Sitemap、llms.txt） | 站长 + 开发者 |
| [XIUNOX_Email.md](XIUNOX_Email.md) | 邮件服务（SMTP配置、异步发送、管理员通知） | 站长 + 开发者 |
| [XIUNOX_Notification.md](XIUNOX_Notification.md) | 通知系统（站内通知、已读未读、通知类型） | 站长 + 开发者 |
| [XIUNOX_AI.md](XIUNOX_AI.md) | AI 集成（多Provider、轮询策略、功能注册） | 站长 + 开发者 |
| [XIUNOX_API.md](XIUNOX_API.md) | API 机制（RESTful路由、中间件、认证、限流） | 站长 + 开发者 |
| [XIUNOX_Audit.md](XIUNOX_Audit.md) | 审核机制（三级规则、状态机、审核队列） | 站长 + 开发者 |

### P2 - 扩展机制
| 文档 | 说明 | 适用人群 |
|------|------|----------|
| [XIUNOX_Language.md](XIUNOX_Language.md) | 多语言（语言包结构、翻译函数、新增语言） | 站长 + 开发者 |
| [XIUNOX_Plugin.md](XIUNOX_Plugin.md) | 插件机制（Hook、Overwrite、Service类、路由注册） | 开发者 |
| [XIUNOX_Event_Hook.md](XIUNOX_Event_Hook.md) | 事件与钩子系统（XnEvent、Hook文件机制、API参考） | 开发者 |
| [XIUNOX_Database.md](XIUNOX_Database.md) | 数据库抽象层（接口规范、查询构建、事务支持） | 开发者 |
| [XIUNOX_Attachment.md](XIUNOX_Attachment.md) | 附件管理（上传安全、存储机制、权限控制） | 站长 + 开发者 |

### 附加文档
| 文档 | 说明 |
|------|------|
| [XIUNOX_Code_Review.md](XIUNOX_Code_Review.md) | 代码问题与优化建议（生成过程中发现的潜在问题） |

## 快速开始

### 站长快速上手
1. 阅读 [XIUNOX_Security.md](XIUNOX_Security.md) 了解安全配置
2. 阅读 [XIUNOX_Cache.md](XIUNOX_Cache.md) 选择缓存驱动
3. 阅读 [XIUNOX_Permission.md](XIUNOX_Permission.md) 配置用户权限
4. 根据需要阅读其他模块文档

### 开发者快速上手
1. 阅读 [XIUNOX_Event_Hook.md](XIUNOX_Event_Hook.md) 了解扩展机制
2. 阅读 [XIUNOX_Plugin.md](XIUNOX_Plugin.md) 学习插件开发规范
3. 阅读 [XIUNOX_Database.md](XIUNOX_Database.md) 了解数据访问层
4. 根据需要阅读各模块的开发者指南

## 技术支持
如有疑问，请查看各文档的「常见问题」部分。
