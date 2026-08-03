# XIUNOX_AI AI 集成

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 的 AI 服务由 `lib/AIService.php` 统一调度，配合 `lib/AILogService.php` 完成日志记录。整体架构为「调用中台 + 多 Provider 轮询」模式：所有 AI 功能通过静态注册表 (`registerFeature`) 注册到中台，调用时根据功能配置选择对应的 Provider 执行请求。支持三种调用模式（`global` / `user_key` / `both`）和四种轮询策略（`failover` / `round_robin` / `random` / `concurrent`），可灵活适配单站点集中管理与多用户自带 API Key 两种场景。

## 站长指南

### 配置入口

后台 → 插件 → AI 设置，或直接编辑 `conf.php` 中的 `ai` 配置节。核心配置结构如下：

```php
'ai' => [
    'providers' => [ /* 全局 Provider 库 */ ],
    'features'  => [ /* 功能配置 */ ],
],
```

### 配置项说明

**Provider 管理**（`conf.ai.providers`）：

| 字段 | 说明 |
|------|------|
| `name` | Provider 唯一标识（如 `openai`、`qwen`） |
| `url` | API 端点地址（如 `https://api.openai.com/v1`） |
| `api_key` | 密钥 |
| `models` | 可用模型列表，支持新格式 `[{name, enabled}]` 或旧格式逗号分隔字符串 |
| `type` | Provider 类型：`text` / `image` / `video` / `audio` / `transcription` |

**功能配置**（`conf.ai.features`）：

| 字段 | 说明 |
|------|------|
| `mode` | 调用模式：`global`（使用全局 Provider）/ `user_key`（用户自带 Key）/ `both`（优先用户，回退全局） |
| `call_method` | 调用方式：`proxy`（服务端代理）/ `frontend`（前端直连） |
| `default_provider` | 默认 Provider 名称 |
| `default_model` | 默认模型名称 |
| `allowed_providers` | 允许的 Provider 名称数组 |

### 使用场景

- **集中式部署**：设置 `mode=global`，管理员统一维护 API Key，所有用户共享调用
- **用户自带 Key**：设置 `mode=user_key`，用户在个人中心填写自己的 API Key，互不影响
- **混合模式**：设置 `mode=both`，优先使用用户个人 Key，未配置时自动回退到全局 Provider
- **高可用轮询**：在 `allowed_providers` 中配置多个 Provider，配合 `callWithFailover` 实现故障转移

### 注意事项

1. Provider URL 支持两种格式：`https://api.example.com/v1` 或 `https://api.example.com/v1/chat/completions`，系统会自动拼接路径
2. 离线 Provider 会被标记为离线状态，默认 10 分钟后自动恢复尝试（`recover_threshold` 可调节）
3. 图片生成接口（`/images/generations`）默认超时 60 秒，文本接口默认 30 秒
4. Cloudflare 等反爬服务可能拦截请求，系统会自动携带 `User-Agent: XiunoX-BBS/1.0` 避免 403

## 开发者指南

### 核心服务类

**AIService**（`lib/AIService.php`）：AI 调用中台，统一入口。

**AILogService**（`lib/AILogService.php`）：日志服务，所有 AI 调用自动记录到 `xnx_ai_call_log` 表。

**日志脱敏机制**：`AILogService` 内置 `sanitizeLogContent()` 方法，自动对日志中的敏感信息进行脱敏处理。

```php
// 日志脱敏相关常量
AILogService::MAX_LOG_CONTENT_LENGTH; // 日志内容最大长度（默认 10000 字符）
AILogService::SENSITIVE_PATTERNS;     // 敏感信息正则模式数组

// 脱敏处理（内部自动调用）
$sanitized = AILogService::sanitizeLogContent($rawContent);
// 自动处理：邮箱地址 → ***@domain.com、手机号 → 138****1234、API Key → sk-***
```

脱敏在每次 AI 调用日志写入前自动执行，确保日志中不泄露用户隐私和密钥信息。

### 钩子点

插件可在 `model_inc_start` 钩子中调用 `AIService::registerFeature()` 注册新功能，在调用前完成配置加载。

### 扩展方式

**注册自定义 AI 功能**：

```php
AIService::registerFeature('my_plugin', [
    'name'            => '我的插件',
    'type'            => 'text',
    'mode'            => 'global',
    'call_method'     => 'proxy',
    'default_provider' => 'openai',
    'default_model'   => 'gpt-4o-mini',
    'allowed_providers' => ['openai', 'qwen'],
]);
```

**注册内容后处理过滤器**：

```php
AIService::registerPostFilter('editor', function($content, $context) {
    // 过滤敏感词、截断长度、添加模板等
    return $content;
});
```

**注册自定义图片 API 适配器**：

```php
AIService::registerImageAdapter('my_provider', [
    'detect'      => function($url) { return strpos($url, 'my-provider.com') !== false; },
    'buildBody'   => function($config, $prompt, $options) { return [...]; },
    'parseResponse' => function($data) { return ['image_url' => $data['url']]; },
]);
```

### 代码示例

**基本文本调用**：

```php
$ai = new AIService($db, $conf);
$result = $ai->call('editor', [
    ['role' => 'user', 'content' => '帮我写一段介绍']
], ['uid' => 1]);

if ($result['code'] === 0) {
    echo $result['data']['content'];
    echo '耗时：' . $result['data']['time_ms'] . 'ms';
}
```

**多 Provider 故障转移调用**：

```php
$result = $ai->callWithFailover('editor', $messages, [
    'mode'    => 'failover',    // failover / round_robin / random / concurrent
    'retry'   => 1,             // 每个 Provider 重试 1 次
    'providers' => ['openai', 'qwen', 'deepseek'],
    'timeout' => 30,
]);
```

**图片生成调用**：

```php
$result = $ai->callImage('my_image_feature', '一只可爱的猫咪', [
    'size'   => '1K',
    'ratio'  => '1:1',
    'n'      => 1,
]);

if ($result['code'] === 0) {
    $url = $result['data']['image_url'];
}
```

**测试 Provider 连接**：

```php
$result = $ai->testProvider('openai');
// 返回 ['code'=>0, 'message'=>''] 表示连接成功
// 返回 ['code'=>1, 'message'=>'HTTP 401 ...'] 表示失败
```

**查询 AI 调用日志**：

```php
$logs = AILogService::getLogs(1, 20, [
    'feature' => 'editor',
    'status'  => 1,
    'start_time' => time() - 86400,
]);
```

## 常见问题

1. **Q: 配置了 Provider 但调用返回「AI 配置不完整」？**
   A: 请检查 `allowed_providers` 数组是否包含已配置的 Provider 名称，以及该 Provider 的 `api_key` 和 `models` 字段是否填写完整。

2. **Q: 如何让用户使用自己的 API Key 而不是全局 Key？**
   A: 将功能的 `mode` 设置为 `user_key`，用户在个人中心填写 API Key 后即可独立调用，互不影响。设置为 `both` 则优先使用用户 Key，未配置时自动回退全局。

3. **Q: 图片生成返回「所有 provider 均调用失败」？**
   A: 检查图片类型 Provider 的 URL 是否指向 `/images/generations` 兼容接口。部分服务商使用不同协议，可通过 `registerImageAdapter` 注册自定义适配器。

4. **Q: 如何查看 AI 调用的历史记录和 Token 用量？**
   A: 所有调用自动记录到 `xnx_ai_call_log` 表，可通过 `AILogService::getLogs()` 和 `AILogService::getStatsBySource()` 查询统计数据。

5. **Q: Provider 暂时宕机后如何自动恢复？**
   A: 系统会在调用失败时自动标记 Provider 为离线状态，默认 600 秒后重新尝试。可在 `callWithFailover` 中通过 `recover_threshold` 参数自定义恢复间隔。
