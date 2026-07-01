# 自有服务类库（lib/）

> 项目自有服务类：API 鉴权、缓存、积分、CSRF、错误处理等 11 个类
>
> 注：lib/HTMLPurifier/ 为第三方 HTML 净化库，不在本文档展开，详见 [README.md](README.md) 的不覆盖范围声明。

## 目录结构概览

```
lib/
├── ApiAuthService.php       # API 鉴权服务（Token、应用凭据）
├── ApiDocService.php        # API 文档与 OpenAPI 规范生成
├── ApiResponse.php          # API 统一 JSON 响应封装
├── CacheService.php         # 缓存驱动管理与状态查询
├── CreditsService.php       # 用户积分增减与日志
├── CsrfService.php          # CSRF Token 生成与校验
├── DatabaseInterface.php    # 数据库访问抽象接口
├── DiscoverService.php      # 发现页插件注册与配置
├── EditorService.php        # AiEditor 富文本编辑器渲染
├── ErrorHandler.php         # 全局错误/异常/致命错误捕获
├── EscapeService.php        # HTML/属性/JS 转义工具函数
└── HTMLPurifier/            # 第三方 HTML 净化库（不展开）
```

## 文件用途说明

### ApiAuthService.php
- **用途**：`ApiAuthService` 类负责 API 双 Token（access/refresh）生命周期管理与应用凭据（appid/secret）认证、速率限制。
- **关键方法**：
  - `generateToken($uid)` — 【已废弃】生成单一 Token
  - `validateToken($token)` — 【已废弃】验证单一 Token 并返回用户
  - `refreshToken($token)` — 【已废弃】刷新单一 Token 有效期
  - `revokeToken($token)` — 【已废弃】撤销单一 Token
  - `generateTokens($uid)` — 生成 access + refresh 双 Token 及关联关系
  - `validateAccessToken($token)` — 验证 access token，返回用户信息
  - `validateRefreshToken($token)` — 验证 refresh token，返回 token 行
  - `refreshTokens($refreshToken)` — 用 refresh token 换取新一对 access/refresh token
  - `revokeTokens($refreshToken)` — 撤销 refresh token 及关联的 access token
  - `::getBearerToken()` — 从 Authorization 请求头提取 Bearer token
  - `validateApp($appid, $secret)` — 校验应用凭据（appid + secret）
  - `validateAppPublic($appid)` — 仅校验 appid（客户端模式，不验证 secret）
  - `checkAppPublicRateLimit($appid)` — 客户端模式更严格的速率限制检查
  - `createApp($name, $description, $scope, $uid)` — 创建新应用并返回凭据
  - `updateApp($id, $data)` — 更新应用字段（白名单）
  - `deleteApp($id)` — 删除应用
  - `regenerateSecret($id)` — 重置应用密钥
  - `checkAppScope($app, $method)` — 检查应用权限范围是否允许当前 HTTP 方法
  - `checkAppRateLimit($app)` — 检查应用级速率限制
  - `listApps()` — 获取所有应用列表
  - `getAppById($id)` — 根据 ID 获取单个应用

### ApiDocService.php
- **用途**：`ApiDocService` 类以静态方式集中提供 API 端点元数据、OpenAPI 3.0 规范与错误码定义，供接口文档页面与 Swagger 类工具消费。
- **关键方法**：
  - `::getEndpoints()` — 返回按模块分组的全部 API 端点元数据（含参数、示例、错误码）
  - `::getOpenApiSpec()` — 基于 `getEndpoints()` 生成 OpenAPI 3.0 规范数组
  - `::getErrorCodes()` — 返回标准错误码列表及描述

### ApiResponse.php
- **用途**：`ApiResponse` 类统一封装 API JSON 响应输出与输入净化，所有 API 控制器通过它返回结果。
- **关键方法**：
  - `::success($data, $msg)` — 输出 code=0 的成功响应
  - `::error($code, $msg, $data, $errors)` — 输出指定错误码的失败响应
  - `::unauthorized($msg)` — 输出 401 未授权响应
  - `::forbidden($msg)` — 输出 403 禁止访问响应
  - `::notFound($msg)` — 输出 404 资源不存在响应
  - `::validationError($msg, $errors)` — 输出 422 参数验证失败响应
  - `::tooManyRequests($msg)` — 输出 429 请求过多响应
  - `::conflict($msg)` — 输出 409 资源冲突响应
  - `::sanitizeInput($value)` — 对字符串进行 HTML 实体转义
  - `::sanitizeArray($data)` — 递归对数组中的字符串进行转义

### CacheService.php
- **用途**：`CacheService` 类管理 file/redis/memcached/mysql 多驱动的初始化、降级、配置与状态查询。
- **关键方法**：
  - `::earlyInit()` — 早期初始化（xiunophp 阶段，使用 `$conf['cache']`）
  - `::init()` — 完整初始化（model 加载后，从 `setting_get` 读取配置并按需重建驱动）
  - `::getConfig()` — 获取当前合并后的缓存配置
  - `::saveConfig($config)` — 保存缓存配置到 setting
  - `::testConnection($type, $conf)` — 测试指定驱动连接是否可用
  - `::getStatus()` — 获取缓存运行状态（启用、连接、命中率等）
  - `::clearByType($types)` — 按类型清除缓存（data/tmp/opcache）
  - `::getOpcacheStatus()` — 获取 OPcache 内存与命中率状态
  - `::getTypeLabel($type)` — 获取驱动类型的中文标签
  - `::getAvailableDrivers()` — 获取当前环境可用的驱动列表

### CreditsService.php
- **用途**：`CreditsService` 类负责用户积分（credits/golds/rmbs）的增减、查询、日志记录，含行锁、事务、防刷与钩子机制。
- **关键方法**：
  - `add($uid, $type, $amount, $reason, $dailyLimit)` — 增加积分（行锁 + 事务 + 日志）
  - `sub($uid, $type, $amount, $reason, $dailyLimit)` — 扣减积分（含余额不足校验）
  - `get($uid, $type)` — 查询用户积分余额
  - `log($uid, $page, $pagesize, $type)` — 分页查询积分变动日志
  - `logGrouped($uid, $page, $pagesize)` — 按操作分组合并显示积分日志
  - `checkNegative($uid, $type, $amount)` — 检查余额是否足够
  - `checkDailyLimitPublic($uid, $reason, $ruleDailyLimit)` — 公开防刷检查（供规则服务调用）
  - `::registerHook($hookName, $callback)` — 注册积分变动前/后钩子

### CsrfService.php
- **用途**：`CsrfService` 类基于 session 提供 CSRF token 的生成、校验与表单隐藏字段输出。
- **关键方法**：
  - `::generate()` — 生成并缓存 CSRF token 到 session
  - `::check()` — 校验 POST/header 中的 CSRF token，失败时按 htmx/JSON 分别响应
  - `::input()` — 输出包含 CSRF token 的隐藏表单字段 HTML
  - `::getToken()` — 获取当前 session 中的 CSRF token

### DatabaseInterface.php
- **用途**：`DatabaseInterface` 接口定义项目数据库访问层的统一契约，所有 DB 实现需满足此接口。
- **关键方法**：
  - `connect()` — 连接数据库
  - `close()` — 关闭数据库连接
  - `find($table, $cond, $orderby, $page, $pagesize, $key, $col)` — 分页查询多行
  - `findOne($table, $cond, $orderby, $col)` — 查询单行
  - `find_group($table, $cond, $groupby, $having, $orderby, $page, $pagesize, $key, $col)` — 带 GROUP BY 的聚合查询（多行）
  - `find_one_group($table, $cond, $groupby, $having, $orderby, $col)` — 带 GROUP BY 的聚合查询（单行）
  - `exec($sql)` — 执行原生 SQL（INSERT/UPDATE/DELETE 等）
  - `insert($table, $data)` — 插入数据并返回插入 ID
  - `update($table, $cond, $data)` — 按条件更新数据
  - `delete($table, $cond)` — 按条件删除数据
  - `count($table, $cond)` — 统计符合条件的行数
  - `maxid($table, $field, $cond)` — 获取指定字段的最大值
  - `lastInsertId()` — 获取最后插入行的 ID
  - `quote($value)` — 转义字符串值
  - `sqlFindOne($sql)` — 执行原生 SQL 返回单行
  - `sqlFind($sql, $key)` — 执行原生 SQL 返回多行
  - `table($table)` — 获取带前缀的完整表名
  - `truncate($table)` — 清空指定表

### DiscoverService.php
- **用途**：`DiscoverService` 类管理发现页（more.php）的插件注册表与显示配置，支持各插件独立开启/排序。
- **关键方法**：
  - `::getPluginDiscoverItems()` — 获取所有已启用插件的发现页展示项
  - `::getPluginDiscoverConfig($plugin_id)` — 获取单个插件的发现页配置
  - `::savePluginDiscoverConfig($plugin_id, $data)` — 保存单个插件的发现页配置
  - `::getRegistryInfo($plugin_id)` — 获取插件在注册表中的默认信息

### EditorService.php
- **用途**：`EditorService` 类负责渲染 AiEditor 富文本编辑器，集成上传、AI 配置、@提及与移动端键盘适配。
- **关键方法**：
  - `getEditorAssets()` — 返回编辑器所需的 CSS/JS 资源列表
  - `renderEditorHtml($textareaId)` — 输出编辑器初始化所需的 HTML + JS 内联脚本

### ErrorHandler.php
- **用途**：`ErrorHandler` 类统一注册并处理 PHP 常规错误、未捕获异常与致命错误，按调试模式决定展示内容。
- **关键方法**：
  - `::register()` — 注册全局 error/exception/shutdown 处理函数
  - `::handleError($errno, $errstr, $errfile, $errline)` — 处理 PHP 常规错误（记录日志，开发模式委托原处理器）
  - `::handleException($exception)` — 处理未捕获异常（记录栈日志并展示错误页）
  - `::handleShutdown()` — 处理致命错误（在关闭阶段回调，含防递归保护）

### EscapeService.php
- **用途**：`EscapeService` 文件以过程式函数提供 HTML 输出转义工具，用于视图层防 XSS。
- **关键方法**：
  - `esc_html($var)` — 转义字符串用于 HTML 文本节点输出
  - `esc_attr($var)` — 转义字符串用于 HTML 属性输出
  - `esc_js($var)` — 转义字符串用于 JavaScript 字符串上下文输出
