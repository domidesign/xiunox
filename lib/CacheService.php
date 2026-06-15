<?php
class CacheService {

    // 默认缓存配置
    private static $defaultConfig = array(
        'enable' => 1,
        'type' => 'mysql',
        'default_ttl' => 3600,
        'auto_warmup' => 0,
        'file' => array(
            'cache_dir' => '',  // 留空则使用 APP_PATH . 'tmp/cache/'
            'cachepre' => 'bbs_',
        ),
        'redis' => array(
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'cachepre' => 'bbs_',
        ),
        'memcached' => array(
            'host' => '127.0.0.1',
            'port' => 11211,
            'cachepre' => 'bbs_',
        ),
        'mysql' => array(
            'cachepre' => 'bbs_',
        ),
    );

    /**
     * 早期初始化（xiunophp.php 阶段，setting_get 不可用）
     * 仅使用 $conf['cache'] 初始化缓存驱动
     */
    public static function earlyInit() {
        global $conf;

        $cacheConfig = isset($conf['cache']) ? $conf['cache'] : array();
        // 兼容旧配置：如果 type 是 xcache/apc/yac，切换为 mysql
        if(isset($cacheConfig['type']) && in_array($cacheConfig['type'], array('xcache', 'apc', 'yac'))) {
            $cacheConfig['type'] = 'mysql';
        }

        $cacheConfig = self::mergeConfig($cacheConfig);

        if(!empty($cacheConfig['enable'])) {
            $cache = cache_new($cacheConfig);
            // 验证缓存驱动连接，失败时降级到 MySQL
            $cache = self::ensureConnection($cache, $cacheConfig);
        } else {
            $cache = NULL;
        }

        $_SERVER['cache'] = $cache;
        return $cache;
    }

    /**
     * 完整初始化（model 加载后，setting_get 可用）
     * 从 setting_get('cache_config') 读取配置，仅在后台配置与当前驱动不同时重新初始化
     */
    public static function init() {
        global $conf;

        // 如果已经降级过，不再尝试重新连接原驱动
        if(!empty($_SERVER['cache_degraded_from'])) {
            return $_SERVER['cache'];
        }

        // 从 setting 读取缓存配置
        $cacheConfig = function_exists('setting_get') ? setting_get('cache_config') : NULL;

        if($cacheConfig === NULL) {
            // 无后台配置，earlyInit 已用 conf 配置初始化，无需重建
            return $_SERVER['cache'];
        }

        // 兼容旧配置
        if(isset($cacheConfig['type']) && in_array($cacheConfig['type'], array('xcache', 'apc', 'yac'))) {
            $cacheConfig['type'] = 'mysql';
        }

        // 检查是否需要重建：比较后台配置的驱动类型与当前实例
        $currentCache = $_SERVER['cache'];
        $currentType = NULL;
        if($currentCache instanceof cache_file) $currentType = 'file';
        elseif($currentCache instanceof cache_redis) $currentType = 'redis';
        elseif($currentCache instanceof cache_memcached) $currentType = 'memcached';
        elseif($currentCache instanceof cache_mysql) $currentType = 'mysql';

        // 驱动类型相同且启用状态一致，无需重建
        $newEnable = !empty($cacheConfig['enable']);
        $currentEnable = $currentCache !== NULL;
        if($currentType === $cacheConfig['type'] && $currentEnable === $newEnable) {
            return $currentCache;
        }

        // 需要重建：驱动类型或启用状态发生变化
        $cacheConfig = self::mergeConfig($cacheConfig);

        if($newEnable) {
            $cache = cache_new($cacheConfig);
            // 验证缓存驱动连接，失败时降级到 MySQL
            $cache = self::ensureConnection($cache, $cacheConfig);
        } else {
            $cache = NULL;
        }

        $_SERVER['cache'] = $cache;
        return $cache;
    }

    /**
     * 验证缓存驱动连接，失败时降级到 MySQL
     * @param object|null $cache 缓存实例
     * @param array $cacheConfig 缓存配置
     * @return object|null 有效的缓存实例（可能已降级为 MySQL）
     */
    private static function ensureConnection($cache, $cacheConfig) {
        if($cache === NULL) return NULL;

        // MySQL 缓存使用现有数据库连接，无需额外验证
        if($cache instanceof cache_mysql) return $cache;
        // 文件缓存无需验证连接
        if($cache instanceof cache_file) return $cache;

        // 尝试连接并验证
        try {
            if(method_exists($cache, 'connect')) {
                $cache->connect();
            }
            if(method_exists($cache, 'isConnected') && !$cache->isConnected()) {
                return self::fallbackToMysql($cacheConfig);
            }
        } catch(\Throwable $e) {
            return self::fallbackToMysql($cacheConfig);
        }

        return $cache;
    }

    /**
     * 降级到 MySQL 缓存
     * @param array $cacheConfig 原缓存配置
     * @return cache_mysql MySQL 缓存实例
     */
    private static function fallbackToMysql($cacheConfig) {
        $originalType = isset($cacheConfig['type']) ? $cacheConfig['type'] : 'unknown';
        error_log("CacheService: {$originalType} 缓存连接失败，自动降级到 MySQL 缓存");

        // 标记已降级，避免 init() 再次尝试原驱动
        $_SERVER['cache_degraded_from'] = $originalType;

        // 更新配置中的缓存类型为 mysql
        $cacheConfig['type'] = 'mysql';

        // 创建 MySQL 缓存实例
        $mysqlCache = new cache_mysql($cacheConfig['mysql']);
        $_SERVER['cache'] = $mysqlCache;
        return $mysqlCache;
    }

    /**
     * 合并用户配置与默认配置
     */
    private static function mergeConfig($userConfig) {
        $config = self::$defaultConfig;

        // 基本配置
        if(isset($userConfig['enable'])) $config['enable'] = intval($userConfig['enable']);
        if(isset($userConfig['type'])) $config['type'] = $userConfig['type'];
        if(isset($userConfig['default_ttl'])) $config['default_ttl'] = intval($userConfig['default_ttl']);
        if(isset($userConfig['auto_warmup'])) $config['auto_warmup'] = intval($userConfig['auto_warmup']);

        // 驱动配置合并
        foreach(array('file', 'redis', 'memcached', 'mysql') as $driver) {
            if(isset($userConfig[$driver]) && is_array($userConfig[$driver])) {
                $config[$driver] = array_merge($config[$driver], $userConfig[$driver]);
            }
        }

        // mysql 驱动需要传入 $db 实例（与原有逻辑一致）
        global $db;
        if(is_object($db)) {
            $config['mysql']['db'] = $db;
        }

        return $config;
    }

    /**
     * 获取当前缓存配置
     */
    public static function getConfig() {
        $cacheConfig = function_exists('setting_get') ? setting_get('cache_config') : NULL;
        if($cacheConfig === NULL) {
            global $conf;
            $cacheConfig = isset($conf['cache']) ? $conf['cache'] : array();
        }
        return self::mergeConfig($cacheConfig);
    }

    /**
     * 保存缓存配置
     */
    public static function saveConfig($config) {
        // 移除 mysql.db 对象（不可序列化）
        if(isset($config['mysql']['db'])) {
            unset($config['mysql']['db']);
        }
        // 确保 file.cache_dir 为空（使用默认值）
        if(isset($config['file']['cache_dir'])) {
            $config['file']['cache_dir'] = '';
        }
        return setting_set('cache_config', $config);
    }

    /**
     * 测试缓存驱动连接
     * @param string $type 驱动类型
     * @param array $conf 驱动配置
     * @return array ['success' => bool, 'message' => string]
     */
    public static function testConnection($type, $conf = array()) {
        switch($type) {
            case 'redis':
                if(!extension_loaded('Redis')) {
                    return array('success' => false, 'message' => 'Redis 扩展未安装');
                }
                try {
                    $redis = new Redis();
                    $host = isset($conf['host']) ? $conf['host'] : '127.0.0.1';
                    $port = isset($conf['port']) ? intval($conf['port']) : 6379;
                    $r = $redis->connect($host, $port, 2.0); // 2秒超时
                    if(!$r) {
                        return array('success' => false, 'message' => '连接 Redis 失败');
                    }
                    // 如果有密码，尝试认证
                    if(!empty($conf['password'])) {
                        $auth = $redis->auth($conf['password']);
                        if(!$auth) {
                            return array('success' => false, 'message' => 'Redis 认证失败');
                        }
                    }
                    // 选择数据库
                    if(isset($conf['database']) && intval($conf['database']) > 0) {
                        $redis->select(intval($conf['database']));
                    }
                    $redis->close();
                    return array('success' => true, 'message' => 'Redis 连接成功');
                } catch(Exception $e) {
                    return array('success' => false, 'message' => 'Redis 连接异常：' . $e->getMessage());
                }

            case 'memcached':
                if(!extension_loaded('Memcache') && !extension_loaded('Memcached')) {
                    return array('success' => false, 'message' => 'Memcached 扩展未安装');
                }
                try {
                    $host = isset($conf['host']) ? $conf['host'] : '127.0.0.1';
                    $port = isset($conf['port']) ? intval($conf['port']) : 11211;
                    if(extension_loaded('Memcached')) {
                        $mc = new Memcached();
                        $mc->addServer($host, $port);
                        $r = $mc->getVersion();
                        $mc->quit();
                    } else {
                        $mc = new Memcache();
                        $r = $mc->connect($host, $port, 2.0);
                        if(!$r) {
                            return array('success' => false, 'message' => '连接 Memcached 失败');
                        }
                        $mc->close();
                    }
                    return array('success' => true, 'message' => 'Memcached 连接成功');
                } catch(Exception $e) {
                    return array('success' => false, 'message' => 'Memcached 连接异常：' . $e->getMessage());
                }

            case 'file':
                $cache_dir = APP_PATH . 'tmp/cache/';
                if(!is_dir($cache_dir)) {
                    if(!mkdir($cache_dir, 0755, TRUE)) {
                        return array('success' => false, 'message' => '无法创建缓存目录：' . $cache_dir);
                    }
                }
                if(!is_writable($cache_dir)) {
                    return array('success' => false, 'message' => '缓存目录不可写：' . $cache_dir);
                }
                return array('success' => true, 'message' => '文件缓存目录可写');

            case 'mysql':
            case 'pdo_mysql':
                // MySQL 缓存使用现有数据库连接，无需额外测试
                return array('success' => true, 'message' => 'MySQL 缓存使用现有数据库连接');

            default:
                return array('success' => false, 'message' => '不支持的驱动类型：' . $type);
        }
    }

    /**
     * 获取缓存状态信息
     */
    public static function getStatus() {
        $config = self::getConfig();
        $status = array(
            'enabled' => !empty($config['enable']),
            'type' => $config['type'],
            'type_label' => self::getTypeLabel($config['type']),
            'default_ttl' => $config['default_ttl'],
            'auto_warmup' => !empty($config['auto_warmup']),
            'connection_ok' => false,
            'connection_message' => '',
            'stats' => array(),
        );

        // 测试当前驱动连接
        $driverConf = isset($config[$config['type']]) ? $config[$config['type']] : array();
        $test = self::testConnection($config['type'], $driverConf);
        $status['connection_ok'] = $test['success'];
        $status['connection_message'] = $test['message'];

        // 驱动特定状态
        switch($config['type']) {
            case 'redis':
                if($test['success']) {
                    try {
                        $redis = new Redis();
                        $redis->connect($config['redis']['host'], intval($config['redis']['port']), 2.0);
                        if(!empty($config['redis']['password'])) {
                            $redis->auth($config['redis']['password']);
                        }
                        if(isset($config['redis']['database']) && intval($config['redis']['database']) > 0) {
                            $redis->select(intval($config['redis']['database']));
                        }
                        $info = $redis->info();
                        $status['stats']['redis_version'] = isset($info['redis_version']) ? $info['redis_version'] : '';
                        $status['stats']['used_memory_human'] = isset($info['used_memory_human']) ? $info['used_memory_human'] : '';
                        $status['stats']['keyspace_hits'] = isset($info['keyspace_hits']) ? $info['keyspace_hits'] : 0;
                        $status['stats']['keyspace_misses'] = isset($info['keyspace_misses']) ? $info['keyspace_misses'] : 0;
                        $total = intval($status['stats']['keyspace_hits']) + intval($status['stats']['keyspace_misses']);
                        $status['stats']['hit_rate'] = $total > 0 ? round(intval($status['stats']['keyspace_hits']) / $total * 100, 2) . '%' : 'N/A';
                        $status['stats']['connected_clients'] = isset($info['connected_clients']) ? $info['connected_clients'] : '';
                        $redis->close();
                    } catch(Exception $e) {
                        $status['stats']['error'] = $e->getMessage();
                    }
                }
                break;

            case 'memcached':
                if($test['success']) {
                    try {
                        $host = $config['memcached']['host'];
                        $port = intval($config['memcached']['port']);
                        if(extension_loaded('Memcached')) {
                            $mc = new Memcached();
                            $mc->addServer($host, $port);
                            $stats = $mc->getStats();
                            $key = $host . ':' . $port;
                            if(isset($stats[$key])) {
                                $status['stats']['curr_items'] = isset($stats[$key]['curr_items']) ? $stats[$key]['curr_items'] : 0;
                                $status['stats']['bytes'] = isset($stats[$key]['bytes']) ? self::formatBytes($stats[$key]['bytes']) : '0 B';
                                $status['stats']['get_hits'] = isset($stats[$key]['get_hits']) ? $stats[$key]['get_hits'] : 0;
                                $status['stats']['get_misses'] = isset($stats[$key]['get_misses']) ? $stats[$key]['get_misses'] : 0;
                                $total = intval($status['stats']['get_hits']) + intval($status['stats']['get_misses']);
                                $status['stats']['hit_rate'] = $total > 0 ? round(intval($status['stats']['get_hits']) / $total * 100, 2) . '%' : 'N/A';
                            }
                            $mc->quit();
                        } elseif(extension_loaded('Memcache')) {
                            $mc = new Memcache();
                            $mc->connect($host, $port, 2.0);
                            $stats = $mc->getStats();
                            if($stats) {
                                $status['stats']['curr_items'] = isset($stats['curr_items']) ? $stats['curr_items'] : 0;
                                $status['stats']['bytes'] = isset($stats['bytes']) ? self::formatBytes($stats['bytes']) : '0 B';
                                $status['stats']['get_hits'] = isset($stats['get_hits']) ? $stats['get_hits'] : 0;
                                $status['stats']['get_misses'] = isset($stats['get_misses']) ? $stats['get_misses'] : 0;
                                $total = intval($status['stats']['get_hits']) + intval($status['stats']['get_misses']);
                                $status['stats']['hit_rate'] = $total > 0 ? round(intval($status['stats']['get_hits']) / $total * 100, 2) . '%' : 'N/A';
                            }
                            $mc->close();
                        }
                    } catch(Exception $e) {
                        $status['stats']['error'] = $e->getMessage();
                    }
                }
                break;

            case 'file':
                $cache_dir = APP_PATH . 'tmp/cache/';
                $status['stats']['cache_dir'] = $cache_dir;
                $status['stats']['dir_size'] = self::getDirSize($cache_dir);
                $status['stats']['file_count'] = self::getDirFileCount($cache_dir);
                break;

            case 'mysql':
            case 'pdo_mysql':
                $status['stats']['table'] = 'bbs_cache';
                break;
        }

        return $status;
    }

    /**
     * 按类型清除缓存
     * @param array $types 要清除的缓存类型: 'data'(数据缓存), 'tmp'(编译缓存), 'opcache'
     * @return array 清除结果
     */
    public static function clearByType($types) {
        global $conf;
        $cleared = array();

        if(in_array('data', $types)) {
            cache_truncate();
            $runtime = NULL;
            $cleared[] = '数据缓存';
        }

        if(in_array('tmp', $types)) {
            $tmp_path = $conf['tmp_path'];
            $count = 0;
            if(is_dir($tmp_path)) {
                $files = glob($tmp_path . '*');
                if($files) {
                    foreach($files as $f) {
                        // 不删除 cache 子目录（由数据缓存管理）
                        if(basename($f) === 'cache') continue;
                        if(is_file($f)) {
                            @unlink($f);
                            $count++;
                        } elseif(is_dir($f)) {
                            rmdir_recusive($f, 0);
                            $count++;
                        }
                    }
                }
            }
            $cleared[] = "编译缓存（{$count} 个文件）";
        }

        if(in_array('opcache', $types) && function_exists('opcache_reset')) {
            opcache_reset();
            $cleared[] = 'OPcache';
        }

        // 触发钩子
        // hook cache_clear_after.php

        return $cleared;
    }

    /**
     * 获取驱动类型标签
     */
    public static function getTypeLabel($type) {
        $labels = array(
            'file' => '文件缓存',
            'redis' => 'Redis',
            'memcached' => 'Memcached',
            'mysql' => 'MySQL',
            'pdo_mysql' => 'MySQL (PDO)',
        );
        return isset($labels[$type]) ? $labels[$type] : $type;
    }

    /**
     * 获取可用驱动列表
     */
    public static function getAvailableDrivers() {
        $drivers = array();

        // File 驱动始终可用
        $drivers['file'] = array(
            'name' => '文件缓存',
            'available' => true,
            'message' => '',
        );

        // Redis
        $drivers['redis'] = array(
            'name' => 'Redis',
            'available' => extension_loaded('Redis'),
            'message' => extension_loaded('Redis') ? '' : 'Redis 扩展未安装',
        );

        // Memcached
        $drivers['memcached'] = array(
            'name' => 'Memcached',
            'available' => extension_loaded('Memcache') || extension_loaded('Memcached'),
            'message' => (extension_loaded('Memcache') || extension_loaded('Memcached')) ? '' : 'Memcached 扩展未安装',
        );

        // MySQL
        $drivers['mysql'] = array(
            'name' => 'MySQL',
            'available' => true,
            'message' => '',
        );

        return $drivers;
    }

    /**
     * 格式化字节数
     */
    private static function formatBytes($bytes) {
        $bytes = intval($bytes);
        if($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * 获取目录大小
     */
    private static function getDirSize($dir) {
        if(!is_dir($dir)) return '0 B';
        $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file) {
            $size += $file->getSize();
        }
        return self::formatBytes($size);
    }

    /**
     * 获取目录文件数
     */
    private static function getDirFileCount($dir) {
        if(!is_dir($dir)) return 0;
        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file) {
            if($file->isFile()) $count++;
        }
        return $count;
    }
}
