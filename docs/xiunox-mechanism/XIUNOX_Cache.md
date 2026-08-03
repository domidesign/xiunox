# XIUNOX_Cache 缓存系统

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 缓存系统采用多级缓存架构，核心围绕 `CacheService` 和 `CacheHelper` 两个类构建。系统支持 **File（文件）**、**Redis**、**Memcached**、**MySQL** 四种驱动，可在后台自由切换，并具备自动降级机制——当 Redis 或 Memcached 连接失败时，系统会自动降级到 MySQL 缓存，确保站点不中断。

核心功能包括：驱动管理与配置、按类型缓存清理（数据缓存/编译缓存/OPcache）、缓存预热、命中率监控、插件缓存键注册与批量清理、OPcache 状态可视化，以及一套简洁的 `remember()` API 供开发者快速接入。

---

## 站长指南

### 配置入口

登录后台 → **系统设置 → 缓存管理**，可在此完成所有缓存配置和运维操作。

### 配置项说明

| 配置项 | 类型 | 说明 |
|---|---|---|
| `enable` | 开关 | 是否启用缓存（0/1） |
| `type` | 下拉 | 驱动类型：`file`、`redis`、`memcached`、`mysql` |
| `default_ttl` | 数字 | 默认缓存有效期（秒），默认 3600 |
| `file.cachepre` | 字符串 | 文件缓存键前缀，默认 `bbs_` |
| `redis.host` | 字符串 | Redis 服务器地址，默认 `127.0.0.1` |
| `redis.port` | 数字 | Redis 端口，默认 `6379` |
| `redis.password` | 字符串 | Redis 密码 |
| `redis.database` | 数字 | Redis 数据库编号，默认 `0` |
| `memcached.host` | 字符串 | Memcached 地址，默认 `127.0.0.1` |
| `memcached.port` | 数字 | Memcached 端口，默认 `11211` |

### 使用场景

- **File 驱动**：适合单机小型站点，无需额外服务，配置简单。
- **Redis 驱动**：适合中大型站点，高性能内存缓存，支持原子操作和自动重连。
- **Memcached 驱动**：适合分布式场景，支持 Memcache 或 Memcached PHP 扩展。
- **MySQL 驱动**：无额外依赖，与数据库共用连接，适合资源有限的环境。

### 注意事项

1. **Redis 密码认证**：后台修改 Redis 密码后，系统会验证连接，失败将自动降级到 MySQL。
2. **缓存清理**：清理数据缓存不会影响 Session（Session 使用独立前缀 `session_`）。
3. **OPcache**：建议在生产环境开启 OPcache 以提升 PHP 性能，后台可查看命中率和内存使用。
4. **TTL 配置**：核心缓存键支持通配符匹配（如 `core_index_tl_*`），可在后台为不同类型的缓存设置不同的过期时间。
5. **降级机制**：Redis/Memcached 连接失败时会写入 `error_log`，请关注服务器日志。

---

## 开发者指南

### 核心服务类

#### CacheService

缓存服务管理器，负责驱动初始化、配置读写、连接测试、状态监控。

```php
// 获取当前缓存配置（合并用户配置与默认值）
$config = CacheService::getConfig();

// 测试指定驱动的连接
$result = CacheService::testConnection('redis', array(
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => '',
));
// 返回: ['success' => true/false, 'message' => '...']

// 初始化驱动配置时，init() 使用 compareDriverConfig() 统一比较逻辑
// 仅当配置实际变更时才重建连接，避免不必要的连接开销
CacheService::init($force = false);

// 获取缓存状态（驱动类型、连接状态、命中率、内存等）
// 内部通过 getConnection() 复用 Redis 连接，消除双重连接开销
$status = CacheService::getStatus();

// 按类型清理缓存
CacheService::clearByType(array('data', 'tmp', 'opcache'));

// 缓存预热
CacheService::warmupCache('all');      // 全部预热
CacheService::warmupCache('core');     // 仅核心缓存

// 获取插件缓存统计
$stats = CacheService::getPluginStats();

// 获取 OPcache 状态
$opcache = CacheService::getOpcacheStatus();
```

#### CacheHelper

缓存辅助类，提供优雅的 `remember()` API 和插件缓存管理。

```php
// 消除样板代码：读取缓存，未命中则计算并写入
$data = CacheHelper::remember('core_index_tl_new_1_0_xxx', 60, function() {
    return thread_find_by_fids($fids, 1, 20, 'new', FALSE);
});

// 指定插件命名空间（自动加 p_{plugin}_ 前缀）
$data = CacheHelper::remember('rank_total', 300, function() {
    return db_find(...);
}, 'checkin');

// 生成带前缀的缓存键
$key = CacheHelper::pluginKey('forumlist');       // 输出: core_forumlist
$key = CacheHelper::pluginKey('rank_total', 'checkin'); // 输出: p_checkin_rank_total

// 清除指定插件的所有缓存
CacheHelper::pluginDeletePrefix('checkin');

// 插件注册缓存键（用于后台展示和批量清理）
CacheHelper::registerKeys('checkin', array(
    'rank_total'  => array(300, '签到总排行'),
    'today_stats' => array(60,  '今日签到统计'),
));

// 获取运行时统计（命中率、键级统计）
$stats = CacheHelper::getStats();
```

### 钩子点

| 钩子 | 触发时机 | 用途 |
|---|---|---|
| `cache_clear_after.php` | 缓存清理完成后 | 插件执行清理后的附加操作 |
| `cache_warmup_after.php` | 缓存预热完成后 | 插件注册自定义预热逻辑 |

### 扩展方式

1. **实现自定义驱动**：创建 `xiunophp/cache_{type}.class.php`，实现 `get/set/delete/truncate/deleteByPrefix` 方法。
2. **插件缓存注册**：在插件初始化时调用 `CacheHelper::registerKeys()` 声明缓存键。
3. **自定义 TTL**：通过后台配置或 `setting_set('cache_ttl_config', $config)` 自定义键的过期时间，支持通配符。

### 代码示例

```php
// 示例：在插件中使用缓存
class PluginCheckin {
    public function init() {
        CacheHelper::registerKeys('checkin', array(
            'rank_total'  => array(300, '签到总排行'),
            'today_stats' => array(60,  '今日签到统计'),
        ));
    }

    public function getRank() {
        return CacheHelper::remember('rank_total', 300, function() {
            return db_find('checkin_rank', ...);
        }, 'checkin');
    }

    public function clearCache() {
        CacheHelper::pluginDeletePrefix('checkin');
    }
}
```

---

## 常见问题

### 1. 切换 Redis 驱动后页面白屏怎么办？

系统会自动降级到 MySQL 缓存。请检查 PHP 是否安装了 Redis 扩展（`php -m | grep Redis`），以及 Redis 服务是否正常运行。降级信息会记录在 `error_log` 中。

### 2. 缓存过期时间如何设置？

可以在后台「缓存管理 → TTL 配置」页面为不同的缓存键设置过期时间。支持通配符匹配，例如 `core_index_tl_*` 可以匹配所有首页帖子列表缓存。

### 3. 清理数据缓存会删除 Session 吗？

不会。Session 使用独立的 `session_` 前缀，数据缓存清理仅删除 `bbs_` 前缀的键，互不影响。

### 4. OPcache 命中率低怎么办？

- 确认 `opcache.enable` 已开启
- 设置合理的 `opcache.revalidate_freq`（建议 60 秒）
- 查看内存占用率，若超过 90% 可增大 `opcache.memory_consumption`

### 5. 如何监控缓存命中率？

后台「缓存管理」页面实时显示缓存命中率。Redis 和 Memcached 驱动显示服务端统计，File 驱动显示文件数量和目录大小，MySQL 驱动显示缓存表信息。
