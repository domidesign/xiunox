# 限流机制说明

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno BBS API 采用令牌桶 + 滑动窗口限流算法，支持多维度限流策略，保护系统免受滥用和恶意攻击。限流服务优先使用 Redis（Sorted Set 滑动窗口），Redis 不可用时自动降级为文件系统存储。

## 限流规则

### 基础限流配置

在管理后台 → **系统设置** → **API 设置** 中可配置：

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `api_rate_limit` | 限流开关（0=关闭，1=开启） | 1 |
| `api_rate_limit_max` | 匿名请求最大次数 | 60 |
| `api_rate_limit_window` | 限流时间窗口（秒） | 60 |

### 限流等级

系统根据调用方身份采用不同的限流策略：

| 调用模式 | 认证方式 | 限流次数 | 说明 |
|----------|----------|----------|------|
| 匿名请求 | 无认证 | 60 次/分钟 | 基于 IP 地址限流 |
| 客户端模式 | `X-App-Id` + `Bearer Token` | 30 次/分钟 | 更严格的保护策略 |
| 服务端模式 | `X-App-Id` + `X-App-Secret` + `Bearer Token` | 120 次/分钟 | 宽松的服务端策略 |
| 特权应用 | `skip_rate_limit` 能力 | 无限制 | 仅限内部服务使用 |

### 敏感接口限流

部分接口因安全原因采用更严格的限流：

| 接口 | 限流规则 | 说明 |
|------|----------|------|
| `POST /auth/login` | 5 次/IP/分钟 | 防止暴力破解 |
| `POST /auth/register` | 3 次/IP/小时 | 防止批量注册 |
| `POST /auth/refresh` | 10 次/用户/分钟 | 防止 Token 滥用 |
| `POST /my/email/send-code` | 3 次/邮箱/小时 | 防止短信/邮件轰炸 |
| `POST /thread` | 10 次/用户/分钟 | 防止垃圾发帖 |
| `POST /post` | 20 次/用户/分钟 | 防止灌水回复 |

## 限流算法

### 滑动窗口算法

系统采用 Redis Sorted Set 实现高精度滑动窗口限流：

```
时间窗口：60 秒
请求记录：Redis Sorted Set
Key 格式：rate_limit:{key_type}:{identifier}
Member：请求时间戳
Score：请求时间戳（用于范围查询）
```

**工作流程**：

```
1. 请求到达 → 生成限流 Key（基于 IP / UserID / AppID）
2. 移除窗口外的旧记录（ZREMRANGEBYSCORE key 0 now-window）
3. 统计窗口内请求数（ZCARD key）
4. 判断是否超限
   - 未超限：添加当前请求记录（ZADD key now now），放行
   - 已超限：返回 429 错误
5. 设置过期时间（EXPIRE key window+1）
```

### 双驱动模式

| 驱动 | 存储方式 | 适用场景 |
|------|----------|----------|
| Redis | Sorted Set 内存存储 | 生产环境（高性能、高精度） |
| File | 文件系统 JSON 存储 | Redis 不可用时的降级方案 |

**自动降级**：当 Redis 连接失败时，系统自动切换到文件驱动，不影响正常请求。建议生产环境始终启用 Redis。

### 限流 Key 生成

限流 Key 基于请求特征动态生成：

| 限流维度 | Key 格式 | 示例 |
|----------|----------|------|
| IP 维度 | `rate_limit:ip:{ip}` | `rate_limit:ip:192.168.1.1` |
| 用户维度 | `rate_limit:user:{uid}` | `rate_limit:user:1` |
| 应用维度 | `rate_limit:app:{appid}` | `rate_limit:app:abc123` |
| IP + 接口 | `rate_limit:ip:{ip}:{endpoint}` | `rate_limit:ip:192.168.1.1:/auth/login` |
| 用户 + 操作 | `rate_limit:user:{uid}:{action}` | `rate_limit:user:1:send_email` |

## 响应头

触发限流时，响应包含以下 Header 供客户端读取：

```
HTTP/1.1 429 Too Many Requests
Content-Type: application/json
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1725292800
Retry-After: 60

{
  "code": 429,
  "msg": "请求过于频繁，请稍后再试",
  "data": null
}
```

| Header | 类型 | 说明 |
|--------|------|------|
| `X-RateLimit-Limit` | int | 当前时间窗口内允许的最大请求数 |
| `X-RateLimit-Remaining` | int | 当前时间窗口内剩余请求次数 |
| `X-RateLimit-Reset` | int | 限流窗口重置的 Unix 时间戳 |
| `Retry-After` | int | 建议等待秒数后重试 |

正常请求也会返回限流状态 Header：

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1725292860
```

## 客户端处理

### JavaScript 示例

```javascript
class RateLimitHandler {
  constructor() {
    this.resetTime = 0;
    this.remaining = Infinity;
  }

  async fetchWithRateLimit(url, options = {}) {
    // 检查本地限流状态
    if (this.remaining === 0 && Date.now() < this.resetTime * 1000) {
      const waitTime = this.resetTime * 1000 - Date.now();
      console.warn(`Rate limited, waiting ${Math.ceil(waitTime / 1000)}s...`);
      await this.sleep(waitTime);
    }

    const response = await fetch(url, {
      ...options,
      headers: {
        'X-App-Id': this.appId,
        ...options.headers
      }
    });

    // 解析限流信息
    this.parseRateLimitHeaders(response.headers);

    // 处理 429 响应
    if (response.status === 429) {
      const retryAfter = parseInt(response.headers.get('Retry-After') || '60');
      console.warn(`Rate limited, waiting ${retryAfter}s...`);
      await this.sleep(retryAfter * 1000);
      
      // 重试一次
      return this.fetchWithRateLimit(url, options);
    }

    return response;
  }

  parseRateLimitHeaders(headers) {
    const limit = headers.get('X-RateLimit-Limit');
    const remaining = headers.get('X-RateLimit-Remaining');
    const reset = headers.get('X-RateLimit-Reset');

    if (limit) {
      this.limit = parseInt(limit);
    }
    if (remaining !== null) {
      this.remaining = parseInt(remaining);
    }
    if (reset) {
      this.resetTime = parseInt(reset);
    }
  }

  sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }
}
```

### 自动重试机制

```javascript
async function apiRequest(url, options = {}, maxRetries = 3) {
  let lastError;

  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      const response = await fetchWithRateLimit(url, options);
      const data = await response.json();

      if (data.code === 0) {
        return data.data;
      }

      // 429 已由 fetchWithRateLimit 处理
      if (response.status === 429) {
        continue;
      }

      throw new Error(data.msg);
    } catch (error) {
      lastError = error;
      
      // 5xx 错误重试
      if (error.code >= 500 && attempt < maxRetries - 1) {
        const delay = Math.pow(2, attempt) * 1000;
        console.warn(`Server error, retrying in ${delay}ms...`);
        await sleep(delay);
        continue;
      }
      
      throw error;
    }
  }

  throw lastError;
}
```

### 请求队列

```javascript
class RequestQueue {
  constructor(maxConcurrent = 5, rateLimitPerMinute = 60) {
    this.maxConcurrent = maxConcurrent;
    this.rateLimitPerMinute = rateLimitPerMinute;
    this.queue = [];
    this.active = 0;
    this.requestTimes = [];
  }

  async add(requestFn) {
    return new Promise((resolve, reject) => {
      this.queue.push({ requestFn, resolve, reject });
      this.process();
    });
  }

  async process() {
    // 清理过期的请求记录
    const now = Date.now();
    this.requestTimes = this.requestTimes.filter(t => now - t < 60000);

    // 检查并发限制
    if (this.active >= this.maxConcurrent) {
      return;
    }

    // 检查限流
    if (this.requestTimes.length >= this.rateLimitPerMinute) {
      const oldest = this.requestTimes[0];
      const waitTime = 60000 - (now - oldest);
      setTimeout(() => this.process(), waitTime);
      return;
    }

    const item = this.queue.shift();
    if (!item) return;

    this.active++;
    this.requestTimes.push(now);

    try {
      const result = await item.requestFn();
      item.resolve(result);
    } catch (error) {
      item.reject(error);
    } finally {
      this.active--;
      this.process();
    }
  }
}
```

## 服务端配置

### 配置文件

```php
// config/api.php
return [
  // 限流开关
  'rate_limit' => env('API_RATE_LIMIT', true),
  
  // 默认限流配置
  'rate_limit_max' => env('API_RATE_LIMIT_MAX', 60),
  'rate_limit_window' => env('API_RATE_LIMIT_WINDOW', 60),
  
  // 各调用模式限流
  'rate_limit_profiles' => [
    'anonymous' => ['max' => 60, 'window' => 60],
    'client' => ['max' => 30, 'window' => 60],
    'server' => ['max' => 120, 'window' => 60],
  ],
  
  // 敏感接口限流
  'rate_limit_endpoints' => [
    '/auth/login' => ['max' => 5, 'window' => 60],
    '/auth/register' => ['max' => 3, 'window' => 3600],
    '/my/email/send-code' => ['max' => 3, 'window' => 3600],
  ],
  
  // Redis 配置
  'rate_limit_redis' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', 6379),
    'db' => env('REDIS_DB', 0),
    'prefix' => 'rate_limit:',
  ],
  
  // 降级：文件存储路径
  'rate_limit_file_path' => sys_get_temp_dir() . '/xiuno_rate_limit/',
];
```

### Redis 优化

**推荐的 Redis 配置**：

```redis
# 内存限制（根据服务器配置调整）
maxmemory 256mb

# 淘汰策略（限流数据不需要持久化）
maxmemory-policy allkeys-lru

# 禁用持久化以提高性能
save ""
appendonly no
```

**监控 Redis 状态**：

```bash
# 查看内存使用
redis-cli INFO memory

# 查看 Key 数量
redis-cli INFO keyspace

# 查看限流 Key 列表
redis-cli KEYS "rate_limit:*"

# 手动清除限流（应急）
redis-cli KEYS "rate_limit:*" | xargs redis-cli DEL
```

### 应用级限流

为特定应用配置独立的限流策略：

```php
// 在应用管理中配置
$appConfig = [
  'appid' => 'special_app',
  'rate_limit' => [
    'max' => 200,           // 该应用独立的限流上限
    'window' => 60,         // 时间窗口
    'skip_rate_limit' => false,  // 是否跳过限流
  ],
];
```

## 监控与调试

### 查看限流状态

管理员可在后台查看限流相关统计：

- 路径：**系统设置** → **日志管理** → **限流日志**
- 记录内容：触发时间、IP、用户、接口、限流类型

### 禁用限流

紧急情况下可临时关闭限流：

1. 管理后台 → **系统设置** → **API 设置**
2. 将 `api_rate_limit` 设为 `0`
3. 该操作会立即生效，所有限流规则暂停

### 调整限流参数

| 场景 | 建议调整 |
|------|----------|
| 正常业务被误限 | 提高 `api_rate_limit_max` 或延长 `api_rate_limit_window` |
| 恶意攻击 | 降低限流阈值，启用 IP 黑名单 |
| 高峰期 | 临时提高限流阈值 |
| 大数据同步 | 为特定应用启用 `skip_rate_limit` |

### 调试工具

```bash
# 查看实时限流状态
watch -n 1 "redis-cli INFO stats | grep rate_limit"

# 测试限流行为
for i in $(seq 1 70); do
  curl -s https://your-site.com/api/v1/site | jq '.code'
done

# 查看单个 IP 的限流记录
redis-cli KEYS "rate_limit:ip:*" | grep "192.168.1.1"
redis-cli ZRANGEBYSCORE "rate_limit:ip:192.168.1.1" 0 +∞ WITHSCORES
```

## 常见问题

### Q: 为什么 API 返回 429 错误？

**A**: 请求触发了限流规则。常见原因：
- 短时间内大量请求（超过限流阈值）
- 多个客户端共享同一出口 IP（如 NAT 环境）
- 客户端实现了不当的重试逻辑

**解决**：
1. 等待 `Retry-After` 指定的时间后重试
2. 检查应用限流配置，适当提高阈值
3. 为服务端应用启用更宽松的限流策略

### Q: 为什么限流没有生效？

**A**: 可能的原因：
- 限流开关 `api_rate_limit` 被关闭
- Redis 不可用且文件系统降级异常
- 应用启用了 `skip_rate_limit` 能力

**排查**：
1. 检查 `api_rate_limit` 配置值
2. 确认 Redis 连接状态
3. 检查应用的 `skip_rate_limit` 设置

### Q: 如何绕过限流进行测试？

**A**: 推荐的测试方式：
- 为测试应用启用 `skip_rate_limit` 能力
- 在本地开发环境关闭限流
- 使用不同的 IP 进行测试

### Q: 限流数据会持久化吗？

**A**: 不会。限流数据存储在 Redis 内存中（或临时文件中），过期后自动清除。重启 Redis 后限流状态重置。

### Q: 多个实例的限流如何处理？

**A**: 所有实例共享同一个 Redis，限流状态实时同步。如果使用文件驱动，多实例会受限流精度影响，建议生产环境使用 Redis。