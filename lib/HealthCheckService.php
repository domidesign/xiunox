<?php
class HealthCheckService {

    // 核心数据表
    private static $coreTables = array(
        'bbs_user', 'bbs_thread', 'bbs_post', 'bbs_forum',
        'bbs_attach', 'bbs_session', 'bbs_kv', 'bbs_cache',
        'bbs_credits_log', 'bbs_admin_log',
    );

    // 必需扩展
    private static $requiredExtensions = array(
        'pdo' => 'PDO',
        'mysqli' => 'MySQLi',
        'gd' => 'GD',
        'curl' => 'CURL',
        'json' => 'JSON',
        'fileinfo' => 'Fileinfo',
        'openssl' => 'OpenSSL',
    );

    // 可选扩展
    private static $optionalExtensions = array(
        'redis' => 'Redis',
        'memcached' => 'Memcached',
        'opcache' => 'OPCache',
        'imagick' => 'Imagick',
    );

    /**
     * 环境检查
     */
    public static function checkEnvironment() {
        $results = array();

        // PHP 版本
        $phpVersion = PHP_VERSION;
        if(version_compare($phpVersion, '8.0.0', '>=')) {
            $results[] = array('status' => 'pass', 'label' => lang('admin_hc_php_version'), 'value' => $phpVersion, 'message' => '');
        } else {
            $results[] = array('status' => 'fail', 'label' => lang('admin_hc_php_version'), 'value' => $phpVersion, 'message' => lang('admin_hc_php_version_low'));
        }

        // 必需扩展
        foreach(self::$requiredExtensions as $ext => $label) {
            $loaded = extension_loaded($ext);
            $results[] = array(
                'status' => $loaded ? 'pass' : 'fail',
                'label' => $label . ' ' . lang('admin_hc_ext_suffix'),
                'value' => $loaded ? lang('admin_hc_installed') : lang('admin_hc_not_installed'),
                'message' => $loaded ? '' : $label . ' ' . lang('admin_hc_ext_not_installed_restart'),
            );
        }

        // 可选扩展
        foreach(self::$optionalExtensions as $ext => $label) {
            $loaded = extension_loaded($ext);
            // Memcached 特殊处理：Memcache 或 Memcached 都算
            if($ext === 'memcached') {
                $loaded = extension_loaded('Memcache') || extension_loaded('Memcached');
            }
            // OPCache 特殊处理
            if($ext === 'opcache') {
                $loaded = function_exists('opcache_get_status');
            }
            $results[] = array(
                'status' => $loaded ? 'pass' : 'skip',
                'label' => $label . ' ' . lang('admin_hc_ext_optional'),
                'value' => $loaded ? lang('admin_hc_installed') : lang('admin_hc_not_installed'),
                'message' => $loaded ? '' : $label . ' ' . lang('admin_hc_ext_optional_not_installed'),
            );
        }

        // 目录可写检查
        $dirs = array(
            array('path' => APP_PATH . 'tmp/', 'label' => lang('admin_hc_dir_tmp'), 'fail_level' => 'fail'),
            array('path' => APP_PATH . 'upload/', 'label' => lang('admin_hc_dir_upload'), 'fail_level' => 'fail'),
            array('path' => APP_PATH . 'conf/', 'label' => lang('admin_hc_dir_conf'), 'fail_level' => 'fail'),
        );

        // log 目录：优先使用 conf 中的 log_path
        global $conf;
        $logPath = isset($conf['log_path']) ? $conf['log_path'] : APP_PATH . 'log/';
        $dirs[] = array('path' => $logPath, 'label' => lang('admin_hc_dir_log'), 'fail_level' => 'warn');

        foreach($dirs as $dir) {
            $writable = is_dir($dir['path']) && is_writable($dir['path']);
            $results[] = array(
                'status' => $writable ? 'pass' : $dir['fail_level'],
                'label' => $dir['label'] . lang('admin_hc_writable_suffix'),
                'value' => $writable ? lang('admin_hc_writable') : lang('admin_hc_not_writable'),
                'message' => $writable ? '' : $dir['label'] . lang('admin_hc_not_writable') . lang('admin_hc_check_perm'),
            );
        }

        // install/ 目录检查
        $installDir = APP_PATH . 'install';
        if(is_dir($installDir)) {
            $results[] = array(
                'status' => 'fail',
                'label' => lang('admin_hc_dir_install'),
                'value' => lang('admin_hc_exists'),
                'message' => lang('admin_hc_dir_install_warn'),
            );
        } else {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_dir_install'),
                'value' => lang('admin_hc_not_exists'),
                'message' => '',
            );
        }

        return $results;
    }

    /**
     * 配置检查
     */
    public static function checkConfig() {
        global $conf;
        $results = array();

        // DEBUG 模式
        $debug = defined('DEBUG') ? DEBUG : (isset($conf['debug']) ? intval($conf['debug']) : 0);
        if($debug > 0) {
            $results[] = array(
                'status' => 'warn',
                'label' => lang('admin_hc_debug_mode'),
                'value' => strval($debug),
                'message' => lang('admin_hc_debug_warn'),
            );
        } else {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_debug_mode'),
                'value' => lang('admin_hc_debug_closed'),
                'message' => '',
            );
        }

        // cookie_secure — 检查实际运行时值
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === 443);
        $cookieParams = session_get_cookie_params();
        $cookieSecure = $cookieParams['secure'];
        if($isHttps && !$cookieSecure) {
            $results[] = array(
                'status' => 'warn',
                'label' => lang('admin_hc_cookie_secure'),
                'value' => lang('admin_hc_not_enabled'),
                'message' => lang('admin_hc_cookie_secure_warn'),
            );
        } else {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_cookie_secure'),
                'value' => $cookieSecure ? lang('admin_hc_enabled') : lang('admin_hc_cookie_secure_not_https'),
                'message' => '',
            );
        }

        // cookie_httponly — 检查实际运行时值
        $cookieHttpOnly = $cookieParams['httponly'];
        if(!$cookieHttpOnly) {
            $results[] = array(
                'status' => 'warn',
                'label' => lang('admin_hc_cookie_httponly'),
                'value' => lang('admin_hc_not_enabled'),
                'message' => lang('admin_hc_cookie_httponly_warn'),
            );
        } else {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_cookie_httponly'),
                'value' => lang('admin_hc_enabled'),
                'message' => '',
            );
        }

        // cookie_samesite — 检查实际运行时值
        $sameSite = isset($cookieParams['samesite']) ? $cookieParams['samesite'] : '';
        if(empty($sameSite) || $sameSite === 'None') {
            // None 在 HTTPS 下是安全的，但 HTTP 下不安全
            if($isHttps && $sameSite === 'None') {
                $results[] = array(
                    'status' => 'pass',
                    'label' => lang('admin_hc_cookie_samesite'),
                    'value' => $sameSite,
                    'message' => '',
                );
            } else {
                $results[] = array(
                    'status' => 'warn',
                    'label' => lang('admin_hc_cookie_samesite'),
                    'value' => $sameSite ?: lang('admin_hc_not_configured'),
                    'message' => lang('admin_hc_cookie_samesite_warn'),
                );
            }
        } else {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_cookie_samesite'),
                'value' => $sameSite,
                'message' => '',
            );
        }

        // 缓存驱动匹配（使用 CacheService::getConfig 获取实际运行时配置）
        $cacheType = 'mysql';
        include_once APP_PATH . 'lib/CacheService.php';
        if(class_exists('CacheService') && method_exists('CacheService', 'getConfig')) {
            $runtimeCacheConfig = CacheService::getConfig();
            if(!empty($runtimeCacheConfig['type'])) {
                $cacheType = $runtimeCacheConfig['type'];
            }
        } else {
            $cacheType = isset($conf['cache']['type']) ? $conf['cache']['type'] : 'mysql';
        }
        $cacheOk = true;
        $cacheMessage = '';
        if($cacheType === 'redis') {
            if(!extension_loaded('Redis')) {
                $cacheOk = false;
                $cacheMessage = lang('admin_hc_cache_redis_no_ext');
            }
        } elseif($cacheType === 'memcached') {
            if(!extension_loaded('Memcache') && !extension_loaded('Memcached')) {
                $cacheOk = false;
                $cacheMessage = lang('admin_hc_cache_memcached_no_ext');
            }
        }
        $results[] = array(
            'status' => $cacheOk ? 'pass' : 'fail',
            'label' => lang('admin_hc_cache_driver'),
            'value' => $cacheType,
            'message' => $cacheOk ? '' : $cacheMessage,
        );

        // 数据库连接
        try {
            global $db;
            if(is_object($db)) {
                $row = $db->sql_find_one("SELECT 1 AS v");
                $results[] = array(
                    'status' => ($row !== NULL) ? 'pass' : 'fail',
                    'label' => lang('admin_hc_db_connection'),
                    'value' => ($row !== NULL) ? lang('admin_hc_normal') : lang('admin_hc_abnormal'),
                    'message' => '',
                );
            } else {
                $results[] = array(
                    'status' => 'fail',
                    'label' => lang('admin_hc_db_connection'),
                    'value' => lang('admin_hc_not_initialized'),
                    'message' => lang('admin_hc_db_not_init'),
                );
            }
        } catch(\Throwable $e) {
            $results[] = array(
                'status' => 'fail',
                'label' => lang('admin_hc_db_connection'),
                'value' => lang('admin_hc_abnormal'),
                'message' => sprintf(lang('admin_hc_db_connect_fail_msg'), $e->getMessage()),
            );
        }

        return $results;
    }

    /**
     * 数据库检查
     */
    public static function checkDatabase() {
        global $db, $conf;
        $results = array();

        // 表前缀：兼容不同配置结构
        $tablePre = 'bbs_';
        if(isset($conf['db']['pdo_mysql']['master']['tablepre'])) {
            $tablePre = $conf['db']['pdo_mysql']['master']['tablepre'];
        } elseif(isset($conf['db']['mysql']['master']['tablepre'])) {
            $tablePre = $conf['db']['mysql']['master']['tablepre'];
        } elseif(isset($conf['db']['master']['tablepre'])) {
            $tablePre = $conf['db']['master']['tablepre'];
        }

        // 核心表存在性检查：使用 INFORMATION_SCHEMA 精确查询
        foreach(self::$coreTables as $table) {
            try {
                $safeTable = addslashes($table);
                $row = $db->sql_find_one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$safeTable}'");
                if(!empty($row)) {
                    $results[] = array(
                        'status' => 'pass',
                        'label' => $table,
                        'value' => lang('admin_hc_exists'),
                        'message' => '',
                    );
                } else {
                    $results[] = array(
                        'status' => 'fail',
                        'label' => $table,
                        'value' => lang('admin_hc_not_exists'),
                        'message' => lang('admin_hc_core_table_missing') . ' ' . $table,
                    );
                }
            } catch(\Throwable $e) {
                $results[] = array(
                    'status' => 'fail',
                    'label' => $table,
                    'value' => lang('admin_hc_query_failed'),
                    'message' => sprintf(lang('admin_hc_table_check_fail_msg'), $e->getMessage()),
                );
            }
        }

        // 数据概览
        try {
            $allTables = $db->sql_find("SHOW TABLES");
            $tableCount = is_array($allTables) ? count($allTables) : 0;
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_table_count'),
                'value' => strval($tableCount),
                'message' => '',
            );
        } catch(\Throwable $e) {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_table_count'),
                'value' => lang('admin_hc_query_failed'),
                'message' => '',
            );
        }

        // 用户数
        try {
            $userCount = $db->count('user');
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_user_count'),
                'value' => strval($userCount),
                'message' => '',
            );
        } catch(\Throwable $e) {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_user_count'),
                'value' => lang('admin_hc_query_failed'),
                'message' => '',
            );
        }

        // 帖子数
        try {
            $threadCount = $db->count('thread');
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_thread_count'),
                'value' => strval($threadCount),
                'message' => '',
            );
        } catch(\Throwable $e) {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_thread_count'),
                'value' => lang('admin_hc_query_failed'),
                'message' => '',
            );
        }

        // 回复数
        try {
            $postCount = $db->count('post');
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_post_count'),
                'value' => strval($postCount),
                'message' => '',
            );
        } catch(\Throwable $e) {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_post_count'),
                'value' => lang('admin_hc_query_failed'),
                'message' => '',
            );
        }

        // 附件数
        try {
            $attachCount = $db->count('attach');
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_attach_count'),
                'value' => strval($attachCount),
                'message' => '',
            );
        } catch(\Throwable $e) {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_attach_count'),
                'value' => lang('admin_hc_query_failed'),
                'message' => '',
            );
        }

        // 表前缀检查
        if($tablePre === 'bbs_') {
            $results[] = array(
                'status' => 'warn',
                'label' => lang('admin_hc_table_prefix'),
                'value' => $tablePre,
                'message' => lang('admin_hc_table_prefix_warn'),
            );
        } else {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_table_prefix'),
                'value' => $tablePre,
                'message' => '',
            );
        }

        return $results;
    }

    /**
     * 性能检查
     */
    public static function checkPerformance() {
        $results = array();

        // OPCache 状态
        if(function_exists('opcache_get_status')) {
            try {
                $opStatus = opcache_get_status(false);
                if($opStatus) {
                    $hitRate = isset($opStatus['opcache_statistics']['opcache_hit_rate'])
                        ? round($opStatus['opcache_statistics']['opcache_hit_rate'], 2) . '%'
                        : 'N/A';
                    $usedMemory = isset($opStatus['memory_usage']['used_memory'])
                        ? self::formatBytes($opStatus['memory_usage']['used_memory'])
                        : 'N/A';
                    $freeMemory = isset($opStatus['memory_usage']['free_memory'])
                        ? self::formatBytes($opStatus['memory_usage']['free_memory'])
                        : 'N/A';
                    $results[] = array(
                        'status' => 'pass',
                        'label' => lang('admin_hc_opcache_hit_rate'),
                        'value' => $hitRate,
                        'message' => '',
                    );
                    $results[] = array(
                        'status' => 'pass',
                        'label' => lang('admin_hc_opcache_memory'),
                        'value' => lang('admin_hc_used') . " {$usedMemory} / " . lang('admin_hc_free') . " {$freeMemory}",
                        'message' => '',
                    );
                } else {
                    $results[] = array(
                        'status' => 'warn',
                        'label' => lang('admin_hc_opcache'),
                        'value' => lang('admin_hc_not_enabled'),
                        'message' => lang('admin_hc_opcache_disabled'),
                    );
                }
            } catch(\Throwable $e) {
                $results[] = array(
                    'status' => 'skip',
                    'label' => lang('admin_hc_opcache'),
                    'value' => lang('admin_hc_status_failed'),
                    'message' => '',
                );
            }
        } else {
            $results[] = array(
                'status' => 'skip',
                'label' => lang('admin_hc_opcache'),
                'value' => lang('admin_hc_unavailable'),
                'message' => lang('admin_hc_opcache_no_ext'),
            );
        }

        // 缓存驱动状态（仅检查配置，不做实际连接避免阻塞）
        try {
            include_once APP_PATH . 'lib/CacheService.php';
            if(class_exists('CacheService') && method_exists('CacheService', 'getConfig')) {
                $runtimeCacheConfig = CacheService::getConfig();
                $cacheType = isset($runtimeCacheConfig['type_label']) ? $runtimeCacheConfig['type_label'] : (isset($runtimeCacheConfig['type']) ? $runtimeCacheConfig['type'] : 'mysql');
                $results[] = array(
                    'status' => 'pass',
                    'label' => lang('admin_hc_cache_driver'),
                    'value' => $cacheType . ' ' . lang('admin_hc_configured'),
                    'message' => '',
                );
            } else {
                $results[] = array(
                    'status' => 'skip',
                    'label' => lang('admin_hc_cache_driver'),
                    'value' => lang('admin_hc_cacheservice_unavailable'),
                    'message' => '',
                );
            }
        } catch(\Throwable $e) {
            $results[] = array(
                'status' => 'skip',
                'label' => lang('admin_hc_cache_driver'),
                'value' => lang('admin_hc_detect_failed'),
                'message' => '',
            );
        }

        // PHP memory_limit
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = self::parseIniBytes($memoryLimit);
        if($memoryBytes > 0 && $memoryBytes < 128 * 1024 * 1024) {
            $results[] = array(
                'status' => 'warn',
                'label' => lang('admin_hc_php_memory_limit'),
                'value' => $memoryLimit,
                'message' => lang('admin_hc_memory_low'),
            );
        } else {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_php_memory_limit'),
                'value' => $memoryLimit,
                'message' => '',
            );
        }

        // max_execution_time
        $maxExecTime = ini_get('max_execution_time');
        if($maxExecTime > 0 && $maxExecTime < 30) {
            $results[] = array(
                'status' => 'warn',
                'label' => lang('admin_hc_max_execution_time'),
                'value' => $maxExecTime . ' ' . lang('admin_hc_seconds'),
                'message' => lang('admin_hc_max_exec_low'),
            );
        } else {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_max_execution_time'),
                'value' => $maxExecTime == 0 ? lang('admin_hc_unlimited') : $maxExecTime . ' ' . lang('admin_hc_seconds'),
                'message' => '',
            );
        }

        // upload_max_filesize
        $uploadMax = ini_get('upload_max_filesize');
        $results[] = array(
            'status' => 'pass',
            'label' => lang('admin_hc_upload_max_filesize'),
            'value' => $uploadMax,
            'message' => '',
        );

        // post_max_size
        $postMax = ini_get('post_max_size');
        $results[] = array(
            'status' => 'pass',
            'label' => lang('admin_hc_post_max_size'),
            'value' => $postMax,
            'message' => '',
        );

        return $results;
    }

    /**
     * 安全检查
     */
    public static function checkSecurity() {
        global $db, $conf;
        $results = array();

        // 默认管理员用户名检查
        try {
            $adminCount = $db->count('user', array('gid' => 1, 'username' => 'admin'));
            if($adminCount > 0) {
                $results[] = array(
                    'status' => 'warn',
                    'label' => lang('admin_hc_default_admin'),
                    'value' => lang('admin_hc_admin_exists'),
                    'message' => lang('admin_hc_default_admin_warn'),
                );
            } else {
                $results[] = array(
                    'status' => 'pass',
                    'label' => lang('admin_hc_default_admin'),
                    'value' => lang('admin_hc_no_default_admin'),
                    'message' => '',
                );
            }
        } catch(\Throwable $e) {
            $results[] = array(
                'status' => 'skip',
                'label' => lang('admin_hc_default_admin'),
                'value' => lang('admin_hc_query_failed'),
                'message' => '',
            );
        }

        // upload/ 目录 PHP 执行保护 — 直接文件检测（避免向自身发 HTTP 请求导致 PHP-FPM 死锁）
        $uploadPath = isset($conf['upload_path']) ? $conf['upload_path'] : APP_PATH . 'upload/';
        $htaccessUpload = rtrim($uploadPath, '/') . '/.htaccess';
        $nginxUpload = rtrim($uploadPath, '/') . '/.nginx';
        if(file_exists($htaccessUpload)) {
            $htcontent = file_get_contents($htaccessUpload);
            if(stripos($htcontent, 'php_flag') !== false || stripos($htcontent, 'deny') !== false || stripos($htcontent, 'RemoveType') !== false || stripos($htcontent, '.php') !== false) {
                $results[] = array(
                    'status' => 'pass',
                    'label' => lang('admin_hc_upload_php_protect'),
                    'value' => lang('admin_hc_htaccess_configured'),
                    'message' => '',
                );
            } else {
                $results[] = array(
                    'status' => 'warn',
                    'label' => lang('admin_hc_upload_php_protect'),
                    'value' => lang('admin_hc_htaccess_configured'),
                    'message' => lang('admin_hc_upload_php_warn'),
                );
            }
        } elseif(file_exists($nginxUpload)) {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_upload_php_protect'),
                'value' => lang('admin_hc_htaccess_configured'),
                'message' => '',
            );
        } else {
            $results[] = array(
                'status' => 'info',
                'label' => lang('admin_hc_upload_php_protect'),
                'value' => lang('admin_hc_undetectable'),
                'message' => lang('admin_hc_nginx_upload_rule'),
            );
        }

        // 敏感文件访问保护 — 直接文件检测（避免向自身发 HTTP 请求导致 PHP-FPM 死锁）
        $htaccessConf = APP_PATH . 'conf/.htaccess';
        $nginxConf = APP_PATH . 'conf/.nginx';
        if(file_exists($htaccessConf)) {
            $htcontent = file_get_contents($htaccessConf);
            if(stripos($htcontent, 'deny') !== false || stripos($htcontent, 'Require') !== false || stripos($htcontent, '.php') !== false) {
                $results[] = array(
                    'status' => 'pass',
                    'label' => lang('admin_hc_conf_access_protect'),
                    'value' => lang('admin_hc_htaccess_configured'),
                    'message' => '',
                );
            } else {
                $results[] = array(
                    'status' => 'warn',
                    'label' => lang('admin_hc_conf_access_protect'),
                    'value' => lang('admin_hc_htaccess_configured'),
                    'message' => lang('admin_hc_conf_access_warn'),
                );
            }
        } elseif(file_exists($nginxConf)) {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_conf_access_protect'),
                'value' => lang('admin_hc_htaccess_configured'),
                'message' => '',
            );
        } else {
            $results[] = array(
                'status' => 'info',
                'label' => lang('admin_hc_conf_access_protect'),
                'value' => lang('admin_hc_undetectable'),
                'message' => lang('admin_hc_nginx_conf_rule'),
            );
        }

        return $results;
    }

    /**
     * 第三方服务检查
     */
    public static function checkThirdParty() {
        global $conf;
        $results = array();

        // 获取实际运行时缓存配置（优先从 CacheService 读取，可能不同于 $conf['cache']）
        $actualCacheType = isset($conf['cache']['type']) ? $conf['cache']['type'] : 'mysql';
        $actualCacheConf = isset($conf['cache']) ? $conf['cache'] : array();
        include_once APP_PATH . 'lib/CacheService.php';
        if(class_exists('CacheService') && method_exists('CacheService', 'getConfig')) {
            $runtimeConfig = CacheService::getConfig();
            if(!empty($runtimeConfig['type'])) {
                $actualCacheType = $runtimeConfig['type'];
                $actualCacheConf = $runtimeConfig;
            }
        }

        // Redis 连接测试（仅检查扩展和配置，不做实际连接避免阻塞）
        if($actualCacheType === 'redis') {
            $redisExtOk = extension_loaded('Redis');
            $redisConfOk = !empty($actualCacheConf['redis']['host']);
            if($redisExtOk && $redisConfOk) {
                $results[] = array(
                    'status' => 'pass',
                    'label' => lang('admin_hc_redis_connection'),
                    'value' => lang('admin_hc_configured'),
                    'message' => '',
                );
            } elseif(!$redisExtOk) {
                $results[] = array('status' => 'fail', 'label' => lang('admin_hc_redis_connection'), 'value' => lang('admin_hc_ext_not_installed'), 'message' => lang('admin_hc_redis_no_ext'));
            } else {
                $results[] = array('status' => 'fail', 'label' => lang('admin_hc_redis_connection'), 'value' => lang('admin_hc_not_configured'), 'message' => '');
            }
        } else {
            $results[] = array(
                'status' => 'skip',
                'label' => lang('admin_hc_redis_connection'),
                'value' => lang('admin_hc_not_configured'),
                'message' => lang('admin_hc_not_redis_skip'),
            );
        }

        // SMTP 连接测试
        $smtpConfigured = false;
        $smtpHost = '';
        $smtpPort = 25;

        // 优先读取 conf/smtp.conf.php
        $smtpConfFile = APP_PATH . 'conf/smtp.conf.php';
        if(file_exists($smtpConfFile)) {
            $smtpList = include $smtpConfFile;
            if(is_array($smtpList) && !empty($smtpList)) {
                $first = is_array($smtpList[0]) ? $smtpList[0] : $smtpList;
                $smtpHost = isset($first['host']) ? $first['host'] : '';
                $smtpPort = isset($first['port']) ? intval($first['port']) : 25;
                if(!empty($smtpHost)) {
                    $smtpConfigured = true;
                }
            }
        }

        // 备选：检查 setting_get
        if(!$smtpConfigured && function_exists('setting_get')) {
            $smtpConfig = setting_get('smtp_config');
            if(!empty($smtpConfig)) {
                $smtpConfigured = true;
                $smtpHost = isset($smtpConfig['host']) ? $smtpConfig['host'] : '';
                $smtpPort = isset($smtpConfig['port']) ? intval($smtpConfig['port']) : 25;
            }
        }

        // 备选：检查 $conf 中的 smtp 配置
        if(!$smtpConfigured && isset($conf['smtp']['host'])) {
            $smtpConfigured = true;
            $smtpHost = $conf['smtp']['host'];
            $smtpPort = isset($conf['smtp']['port']) ? intval($conf['smtp']['port']) : 25;
        }

        if($smtpConfigured && !empty($smtpHost)) {
            // 仅检查 SMTP 是否已配置，不做 DNS/连接测试避免阻塞
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_smtp_connection'),
                'value' => "{$smtpHost}:{$smtpPort}",
                'message' => lang('admin_hc_smtp_configured'),
            );
        } else {
            $results[] = array(
                'status' => 'skip',
                'label' => lang('admin_hc_smtp_connection'),
                'value' => lang('admin_hc_not_configured'),
                'message' => lang('admin_hc_smtp_not_configured'),
            );
        }

        // EdgeOne 插件检查（仅检查是否安装和配置，不做 HTTP 请求避免阻塞）
        $edgeoneDir = APP_PATH . 'plugin/xnx_edgeone/';
        if(is_dir($edgeoneDir)) {
            $results[] = array(
                'status' => 'pass',
                'label' => lang('admin_hc_edgeone_plugin'),
                'value' => lang('admin_hc_installed'),
                'message' => '',
            );
            // 检查域名是否已配置
            $edgeoneDomain = '';
            if(function_exists('setting_get')) {
                $edgeoneConfig = setting_get('xnx_edgeone_config');
                if(!empty($edgeoneConfig) && isset($edgeoneConfig['domain'])) {
                    $edgeoneDomain = $edgeoneConfig['domain'];
                }
            }
            if(empty($edgeoneDomain) && file_exists($edgeoneDir . 'conf.php')) {
                $pluginConf = @include($edgeoneDir . 'conf.php');
                if(!empty($pluginConf['domain'])) {
                    $edgeoneDomain = $pluginConf['domain'];
                }
            }
            if(!empty($edgeoneDomain)) {
                $results[] = array(
                    'status' => 'pass',
                    'label' => lang('admin_hc_edgeone_reachable'),
                    'value' => $edgeoneDomain,
                    'message' => '',
                );
            } else {
                $results[] = array(
                    'status' => 'info',
                    'label' => lang('admin_hc_edgeone_domain'),
                    'value' => lang('admin_hc_domain_not_configured'),
                    'message' => lang('admin_hc_edgeone_no_domain'),
                );
            }
        } else {
            $results[] = array(
                'status' => 'skip',
                'label' => lang('admin_hc_edgeone_plugin'),
                'value' => lang('admin_hc_not_installed'),
                'message' => lang('admin_hc_edgeone_not_installed'),
            );
        }

        return $results;
    }

    /**
     * 计算健康评分
     * @param array $results 全部检查结果
     * @return array ['score' => int, 'grade' => string, 'grade_label' => string]
     */
    public static function calculateScore($results) {
        $passCount = 0;
        $warnCount = 0;
        $failCount = 0;
        $skipCount = 0;
        $score = 100;

        foreach($results as $module) {
            if(!is_array($module)) continue;
            foreach($module as $item) {
                if(!is_array($item) || !isset($item['status'])) continue;
                switch($item['status']) {
                    case 'pass':
                        $passCount++;
                        break;
                    case 'warn':
                        $warnCount++;
                        $score -= 5;
                        break;
                    case 'fail':
                        $failCount++;
                        // install/ 目录存在特殊扣分
                        if(isset($item['label']) && strpos($item['label'], 'install') !== false) {
                            $score -= 15;
                        } else {
                            $score -= 10;
                        }
                        break;
                    case 'skip':
                    case 'info':
                        $skipCount++;
                        break;
                }
            }
        }

        $score = max(0, $score);

        // 评级
        if($score >= 90) {
            $grade = 'excellent';
            $gradeLabel = lang('admin_health_grade_excellent');
        } elseif($score >= 70) {
            $grade = 'good';
            $gradeLabel = lang('admin_health_grade_good');
        } elseif($score >= 50) {
            $grade = 'fair';
            $gradeLabel = lang('admin_health_grade_fair');
        } else {
            $grade = 'poor';
            $gradeLabel = lang('admin_health_grade_poor');
        }

        return array(
            'score' => $score,
            'grade' => $grade,
            'grade_label' => $gradeLabel,
            'pass_count' => $passCount,
            'warn_count' => $warnCount,
            'fail_count' => $failCount,
            'skip_count' => $skipCount,
        );
    }

    /**
     * 运行全部检查
     * @param bool $force 是否强制重新检查（忽略缓存）
     * @return array 完整检查结果
     */
    public static function runAll($force = false) {

        // 执行全部检查
        $environment = self::checkEnvironment();
        $config = self::checkConfig();
        $database = self::checkDatabase();
        $performance = self::checkPerformance();
        $security = self::checkSecurity();
        $thirdParty = self::checkThirdParty();

        // 组装结果
        $allChecks = array(
            'environment' => $environment,
            'config' => $config,
            'database' => $database,
            'performance' => $performance,
            'security' => $security,
            'third_party' => $thirdParty,
        );

        // 计算评分
        $scoreInfo = self::calculateScore($allChecks);

        // 统计总数
        $totalChecks = 0;
        foreach($allChecks as $module) {
            $totalChecks += count($module);
        }

        $result = array(
            'environment' => $environment,
            'config' => $config,
            'database' => $database,
            'performance' => $performance,
            'security' => $security,
            'third_party' => $thirdParty,
            'score' => $scoreInfo['score'],
            'grade' => $scoreInfo['grade'],
            'grade_label' => $scoreInfo['grade_label'],
            'checked_at' => time(),
            'total_checks' => $totalChecks,
            'pass_count' => $scoreInfo['pass_count'],
            'warn_count' => $scoreInfo['warn_count'],
            'fail_count' => $scoreInfo['fail_count'],
            'skip_count' => $scoreInfo['skip_count'],
        );

        // 后台管理页面无需缓存

        return $result;
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
     * 解析 php.ini 字节值（如 128M、1G）
     * @param string $value ini 值
     * @return int 字节数
     */
    private static function parseIniBytes($value) {
        if(empty($value) || $value === '-1') {
            return -1;
        }
        $value = trim($value);
        $last = strtolower(substr($value, -1));
        $num = intval(substr($value, 0, -1));
        switch($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }
}
