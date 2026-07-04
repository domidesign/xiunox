<?php
/**
 * CacheHelper - 缓存系统辅助类
 *
 * 提供以下能力：
 * 1. remember()：消除 if(cache_get)...else{compute;cache_set} 样板代码
 * 2. pluginKey()：插件命名空间前缀，避免键名冲突
 * 3. pluginDeletePrefix()：通配符删除某插件的所有缓存（基于 SCAN，生产安全）
 * 4. registerKeys()：插件注册自己的缓存键（用于统计和批量清理）
 * 5. 统计与调试：DEBUG 模式记录命中率、缓存键清单
 *
 * 缓存键命名规范：
 * - 核心缓存：core_{name}_{params}，如 core_forumlist、core_index_tl_xxx
 * - 插件缓存：p_{plugin}_{name}_{params}，如 p_checkin_rank_total
 * - 前缀删除时用 p_{plugin}_* 匹配
 *
 * 使用示例：
 * ```php
 * // 旧写法（样板代码）：
 * $cached = function_exists('cache_get') ? cache_get($key) : NULL;
 * if($cached !== NULL && $cached !== FALSE) { return $cached; }
 * $result = db_find(...);
 * if(function_exists('cache_set')) { cache_set($key, $result, 300); }
 * return $result;
 *
 * // 新写法（一行搞定）：
 * return CacheHelper::remember($key, 300, function() {
 *     return db_find(...);
 * });
 *
 * // 插件用法（自动加前缀 p_checkin_）：
 * return CacheHelper::remember('rank_total', 300, function() {
 *     return db_find(...);
 * }, 'checkin');
 *
 * // 清除整个插件的缓存：
 * CacheHelper::pluginDeletePrefix('checkin');
 * ```
 */
class CacheHelper {

    // 插件缓存键注册表：plugin => [key => [ttl, desc]]
    private static $registeredKeys = array();

    // 运行时统计：['hit' => N, 'miss' => N, 'keys' => ['key' => ['hit'=>N,'miss'=>N,'set'=>N]]]
    private static $stats = array('hit' => 0, 'miss' => 0, 'keys' => array());

    // 持久化注册表的缓存键与 TTL（注册表跨请求共享，TTL 设为 0 表示永久）
    private static $persistedKeysKey = 'core_cache_registered_keys';
    private static $persistedKeysTTL = 0;

    /**
     * 读取缓存，未命中则执行 $callback 并写入缓存
     *
     * @param string $key 缓存键（不含插件前缀，会自动加）
     * @param int $ttl 缓存有效期（秒），0 表示永久
     * @param callable $callback 未命中时的数据获取回调
     * @param string $plugin 插件名（可选，用于命名空间隔离和统计）
     * @return mixed 缓存数据
     */
    public static function remember($key, $ttl, $callback, $plugin = '') {
        $fullKey = self::pluginKey($key, $plugin);
        $cached = self::get($fullKey, $plugin);

        if($cached !== NULL && $cached !== FALSE) {
            self::recordStat($fullKey, 'hit', $plugin);
            return $cached;
        }

        // 未命中，执行回调获取数据
        self::recordStat($fullKey, 'miss', $plugin);
        $result = call_user_func($callback);

        self::set($fullKey, $result, $ttl, $plugin);
        return $result;
    }

    /**
     * 读取缓存（带统计）
     */
    public static function get($key, $plugin = '') {
        if(!function_exists('cache_get') || empty($_SERVER['cache'])) {
            return NULL;
        }
        return cache_get($key);
    }

    /**
     * 写入缓存（带统计）
     */
    public static function set($key, $value, $ttl = 0, $plugin = '') {
        if(!function_exists('cache_set') || empty($_SERVER['cache'])) {
            return FALSE;
        }
        self::recordStat($key, 'set', $plugin);
        return cache_set($key, $value, $ttl);
    }

    /**
     * 删除单个缓存键
     */
    public static function delete($key, $plugin = '') {
        if(!function_exists('cache_delete') || empty($_SERVER['cache'])) {
            return FALSE;
        }
        return cache_delete($key);
    }

    /**
     * 生成插件命名空间缓存键
     * 核心代码用 core_ 前缀，插件用 p_{plugin}_ 前缀
     *
     * @param string $key 原始键名
     * @param string $plugin 插件名（核心代码传空字符串或 'core'）
     * @return string 带前缀的完整键名
     */
    public static function pluginKey($key, $plugin = '') {
        if($plugin === '' || $plugin === 'core') {
            // 核心代码：如果 $key 已有 core_ 前缀则不重复加
            if(strpos($key, 'core_') === 0) return $key;
            return 'core_' . $key;
        }
        // 插件：p_{plugin}_{key}
        $prefix = 'p_' . $plugin . '_';
        // 如果 $key 已有插件前缀则不重复加
        if(strpos($key, $prefix) === 0) return $key;
        return $prefix . $key;
    }

    /**
     * 按前缀删除缓存（通配符删除）
     * 用于清除某个插件的所有缓存，或某类数据的所有缓存
     *
     * @param string $prefix 缓存键前缀（不含 cachepre，如 'p_checkin_' 会删除 p_checkin_rank_total 等）
     * @return int 删除的键数量
     */
    public static function deleteByPrefix($prefix) {
        if(empty($_SERVER['cache'])) return 0;

        $cache = $_SERVER['cache'];

        // 优先使用驱动原生的 deleteByPrefix（redis/mysql/file 都已实现）
        if(method_exists($cache, 'deleteByPrefix')) {
            return $cache->deleteByPrefix($prefix);
        }

        // 回退：其他驱动（如 memcached）依赖注册表逐个删除
        $allKeys = self::getRegisteredKeys();
        $deleted = 0;
        foreach($allKeys as $plugin => $keys) {
            foreach($keys as $key => $meta) {
                $fullKey = self::pluginKey($key, $plugin);
                if(strpos($fullKey, $prefix) === 0) {
                    if(cache_delete($fullKey)) $deleted++;
                }
            }
        }
        return $deleted;
    }

    /**
     * 清除整个插件的所有缓存
     *
     * @param string $plugin 插件名
     * @return int 删除的键数量
     */
    public static function pluginDeletePrefix($plugin) {
        $prefix = 'p_' . $plugin . '_';
        return self::deleteByPrefix($prefix);
    }

    /**
     * 注册插件的缓存键（用于统计和批量清理）
     * 插件在启动时调用，声明自己会用到的所有缓存键
     *
     * @param string $plugin 插件名
     * @param array $keys 键名 => [ttl, desc] 的映射
     *   例如：['rank_total' => [300, '签到总排行'], 'today_stats' => [60, '今日签到统计']]
     */
    public static function registerKeys($plugin, $keys) {
        if(!isset(self::$registeredKeys[$plugin])) {
            self::$registeredKeys[$plugin] = array();
        }
        foreach($keys as $key => $meta) {
            self::$registeredKeys[$plugin][$key] = array(
                'ttl' => isset($meta[0]) ? intval($meta[0]) : 0,
                'desc' => isset($meta[1]) ? $meta[1] : '',
            );
        }
        // 同步持久化到缓存，使后台等其他请求能读到注册表
        self::persistRegisteredKeys($plugin);
    }

    /**
     * 获取所有已注册的缓存键
     * 合并「持久化缓存 + 当前请求内存」，当前请求优先
     */
    public static function getRegisteredKeys() {
        $persisted = self::loadPersistedKeys();
        if(empty(self::$registeredKeys)) return $persisted;
        $merged = $persisted;
        foreach(self::$registeredKeys as $plugin => $keys) {
            if(!isset($merged[$plugin])) {
                $merged[$plugin] = $keys;
            } else {
                // 当前请求的注册覆盖持久化的（最新优先）
                $merged[$plugin] = array_merge($merged[$plugin], $keys);
            }
        }
        return $merged;
    }

    /**
     * 从缓存读取持久化的注册表
     * 不做 static 缓存，确保 registerKeys/unregisterPlugin 后立即读到最新数据
     */
    private static function loadPersistedKeys() {
        if(!function_exists('cache_get') || empty($_SERVER['cache'])) {
            return array();
        }
        $data = cache_get(self::$persistedKeysKey);
        return is_array($data) ? $data : array();
    }

    /**
     * 将某插件的注册表持久化到缓存
     * 合并已有持久化数据，避免覆盖其他插件的注册
     */
    private static function persistRegisteredKeys($plugin) {
        if(!function_exists('cache_set') || empty($_SERVER['cache'])) return;
        if(!isset(self::$registeredKeys[$plugin])) return;
        // 读取已有持久化数据（不走 static 缓存，确保拿到最新）
        $persisted = is_array($persistedRaw = cache_get(self::$persistedKeysKey)) ? $persistedRaw : array();
        $persisted[$plugin] = self::$registeredKeys[$plugin];
        cache_set(self::$persistedKeysKey, $persisted, self::$persistedKeysTTL);
    }

    /**
     * 移除某插件的缓存键注册（插件禁用/卸载时调用）
     * @param string $plugin 插件名
     */
    public static function unregisterPlugin($plugin) {
        unset(self::$registeredKeys[$plugin]);
        if(!function_exists('cache_get') || empty($_SERVER['cache'])) return;
        $persisted = is_array($persistedRaw = cache_get(self::$persistedKeysKey)) ? $persistedRaw : array();
        if(isset($persisted[$plugin])) {
            unset($persisted[$plugin]);
            cache_set(self::$persistedKeysKey, $persisted, self::$persistedKeysTTL);
        }
    }

    /**
     * 清空全部已注册的缓存键（持久化 + 内存）
     * 用于后台手动重置注册表
     */
    public static function clearRegisteredKeys() {
        self::$registeredKeys = array();
        if(function_exists('cache_delete') && !empty($_SERVER['cache'])) {
            cache_delete(self::$persistedKeysKey);
        }
    }

    // 持久化统计的缓存键和 TTL
    private static $persistStatsKey = 'core_cache_stats';
    private static $persistStatsTTL = 86400; // 24小时

    /**
     * 获取缓存统计（合并当前请求 + 持久化累积）
     */
    public static function getStats() {
        // 读取持久化累积统计
        $persisted = self::loadPersistedStats();
        // 合并：当前请求统计覆盖持久化的同键数据（最新优先）
        $merged = array(
            'hit' => $persisted['hit'] + self::$stats['hit'],
            'miss' => $persisted['miss'] + self::$stats['miss'],
            'keys' => array(),
        );
        // 合并键级统计：持久化 + 当前请求
        foreach($persisted['keys'] as $k => $v) {
            $merged['keys'][$k] = $v;
        }
        foreach(self::$stats['keys'] as $k => $v) {
            if(!isset($merged['keys'][$k])) {
                $merged['keys'][$k] = $v;
            } else {
                $merged['keys'][$k]['hit'] += $v['hit'];
                $merged['keys'][$k]['miss'] += $v['miss'];
                $merged['keys'][$k]['set'] += $v['set'];
            }
        }
        return $merged;
    }

    /**
     * 重置统计（同时清除持久化数据）
     */
    public static function resetStats() {
        self::$stats = array('hit' => 0, 'miss' => 0, 'keys' => array());
        if(function_exists('cache_delete')) {
            cache_delete(self::$persistStatsKey);
        }
    }

    /**
     * 从缓存读取持久化统计
     */
    private static function loadPersistedStats() {
        static $loaded = NULL;
        if($loaded !== NULL) return $loaded;
        if(!function_exists('cache_get') || empty($_SERVER['cache'])) {
            $loaded = array('hit' => 0, 'miss' => 0, 'keys' => array());
            return $loaded;
        }
        $data = cache_get(self::$persistStatsKey);
        if(!is_array($data) || !isset($data['hit'])) {
            $loaded = array('hit' => 0, 'miss' => 0, 'keys' => array());
            return $loaded;
        }
        $loaded = $data;
        return $loaded;
    }

    /**
     * 将当前请求统计持久化到缓存（register_shutdown_function 触发）
     */
    public static function persistStats() {
        if(self::$stats['hit'] == 0 && self::$stats['miss'] == 0) return;
        if(!function_exists('cache_get') || empty($_SERVER['cache'])) return;
        // 读取已有持久化数据并累加
        $persisted = self::loadPersistedStats();
        $persisted['hit'] += self::$stats['hit'];
        $persisted['miss'] += self::$stats['miss'];
        foreach(self::$stats['keys'] as $k => $v) {
            if(!isset($persisted['keys'][$k])) {
                $persisted['keys'][$k] = $v;
            } else {
                $persisted['keys'][$k]['hit'] += $v['hit'];
                $persisted['keys'][$k]['miss'] += $v['miss'];
                $persisted['keys'][$k]['set'] += $v['set'];
            }
        }
        // 限制键级统计数量，避免缓存无限增长
        if(count($persisted['keys']) > 200) {
            // 按总操作数排序，保留 top 200
            uasort($persisted['keys'], function($a, $b) {
                return ($b['hit'] + $b['miss'] + $b['set']) - ($a['hit'] + $a['miss'] + $a['set']);
            });
            $persisted['keys'] = array_slice($persisted['keys'], 0, 200, true);
        }
        cache_set(self::$persistStatsKey, $persisted, self::$persistStatsTTL);
    }

    /**
     * 记录缓存命中/未命中统计
     */
    private static function recordStat($key, $type, $plugin = '') {
        if(!isset(self::$stats['keys'][$key])) {
            self::$stats['keys'][$key] = array('hit' => 0, 'miss' => 0, 'set' => 0, 'plugin' => $plugin);
        }
        self::$stats['keys'][$key][$type]++;
        if($type === 'hit') self::$stats['hit']++;
        if($type === 'miss') self::$stats['miss']++;

        // DEBUG 模式记录缓存命中/未命中日志
        if(defined('DEBUG') && DEBUG) {
            $tag = $type === 'hit' ? 'HIT' : ($type === 'miss' ? 'MISS' : 'SET');
            $pluginTag = $plugin ? "[{$plugin}]" : '[core]';
            // 日志文件名必须包含 error 才会在生产环境写入，调试用 debug_error
            if(function_exists('xn_log')) {
                xn_log("{$pluginTag} {$tag} {$key}", 'cache_debug_error');
            }
        }
    }

    /**
     * 缓存预热：主动生成核心高频数据的缓存
     * 用于后台手动触发或 cron 定时任务
     *
     * 预热范围：
     * 1. 核心基础数据：版块列表、用户组、全站统计、置顶帖、版块树
     * 2. 首页高频数据：帖子列表第1页（默认+最热）、帖子总数（游客视角 gid=0）
     * 3. 版块高频数据：每个版块帖子列表第1页、帖子总数（游客视角 gid=0）
     * 4. 插件预热：通过 cache_warmup_after.php 钩子由插件自行注册
     *
     * @param string $target 预热目标：'core' 或插件名，'all' 表示全部
     * @return array 预热结果 ['success' => N, 'fail' => N, 'details' => []]
     */
    public static function warmup($target = 'all') {
        $result = array('success' => 0, 'fail' => 0, 'details' => array());

        // 核心缓存预热
        if($target === 'all' || $target === 'core') {
            $coreKeys = array(
                'core_forumlist' => array('ttl' => 60, 'desc' => '版块列表'),
                'core_grouplist' => array('ttl' => 0, 'desc' => '用户组列表'),
                'core_runtime' => array('ttl' => 0, 'desc' => '全站统计'),
                'core_thread_top_list' => array('ttl' => 300, 'desc' => '置顶帖列表'),
                'core_forum_tree' => array('ttl' => 300, 'desc' => '版块树'),
            );

            foreach($coreKeys as $key => $meta) {
                try {
                    if(self::warmupCoreKey($key)) {
                        $result['success']++;
                        $result['details'][] = "{$meta['desc']} 预热成功";
                    }
                } catch(\Throwable $e) {
                    $result['fail']++;
                    $result['details'][] = "{$meta['desc']} 预热失败：" . $e->getMessage();
                }
            }

            // 预热首页/版块页高频数据（游客视角 gid=0，第一页）
            try {
                $warmupList = self::warmupHighFreqKeys();
                foreach($warmupList as $desc => $ok) {
                    if($ok) {
                        $result['success']++;
                        $result['details'][] = $desc . ' 预热成功';
                    } else {
                        $result['fail']++;
                        $result['details'][] = $desc . ' 预热失败';
                    }
                }
            } catch(\Throwable $e) {
                $result['fail']++;
                $result['details'][] = '高频数据预热失败：' . $e->getMessage();
            }
        }

        // 触发插件预热钩子
        // hook cache_warmup_after.php
        // 插件通过此钩子注册自己的预热逻辑，例如：
        // CacheHelper::remember('hot_10', 300, function() { ... }, 'tag');

        return $result;
    }

    /**
     * 预热首页和版块页的高频访问数据（第一页，游客视角 gid=0）
     * 这些是站点最常被访问的页面，预热后可显著降低冷启动延迟
     *
     * @return array ['描述' => bool] 预热结果
     */
    private static function warmupHighFreqKeys() {
        $results = array();
        if(!function_exists('forum_list_cache') || !function_exists('thread_find_by_fids')) {
            return $results;
        }

        // 获取版块列表（已在前面预热，这里直接读缓存）
        $forumlist = forum_list_cache();
        if(empty($forumlist)) return $results;

        // 游客视角（gid=0）的版块权限过滤
        // forum_list_access_filter 依赖 $grouplist 全局变量，需先初始化
        if(function_exists('group_list_cache')) {
            $GLOBALS['grouplist'] = group_list_cache();
        }
        $forumlist_show = function_exists('forum_list_access_filter')
            ? forum_list_access_filter($forumlist, 0)
            : $forumlist;

        // 过滤出可发帖的版块（type=0）
        $fids = array();
        foreach($forumlist_show as $fid => $f) {
            if(!empty($f['fid']) && $f['fid'] > 0 && isset($f['type']) && $f['type'] == 0) {
                $fids[] = $f['fid'];
            }
        }
        if(empty($fids)) return $results;

        // 首页版块过滤：如果后台设置了 home_forum_ids，则只显示指定版块的帖子
        // 与 route/index.php 保持一致，确保缓存键一致
        $_home_forum_ids = isset($GLOBALS['conf']['home_forum_ids']) ? $GLOBALS['conf']['home_forum_ids'] : array();
        if(!empty($_home_forum_ids)) {
            $fids = array_intersect($fids, $_home_forum_ids);
            $fids = array_values($fids);
        }
        if(empty($fids)) return $results;

        // 首页帖子总数（游客视角 gid=0）
        // 注意：thread_count 返回 0 也是合法值（版块无帖子），不能用作失败判断
        $_count_key = 'core_index_thread_count_' . md5(implode(',', $fids)) . '_0';
        self::remember($_count_key, 60, function() use ($fids) {
            return thread_count(array('fid' => $fids, 'is_deleted' => 0, 'top' => 0, 'audit_status' => 1));
        });
        $results['首页帖子总数'] = true;

        // 首页帖子列表第1页（默认排序 + 最热排序，游客视角）
        // 缓存键与 route/index.php 保持一致：$_list_order = ($order == 'hot') ? 'views' : $order
        foreach(array('new' => '默认排序', 'views' => '最热排序') as $_list_order => $desc) {
            $_list_key = 'core_index_tl_' . $_list_order . '_1_0_' . md5(implode(',', $fids));
            self::remember($_list_key, 60, function() use ($fids, $_list_order) {
                return thread_find_by_fids($fids, 1, 20, $_list_order, FALSE);
            });
            $results['首页帖子列表(' . $desc . ')'] = true;
        }

        // 每个版块：帖子总数 + 帖子列表第1页（游客视角 gid=0）
        // 限制最多预热前 20 个版块，避免大型站点预热时间过长
        // 版块默认排序为 lastpid（与 route/forum.php 保持一致）
        $warmup_fids = array_slice($fids, 0, 20);
        foreach($warmup_fids as $fid) {
            // 版块帖子总数
            $_fc_key = 'core_forum_tc_' . $fid . '_0';
            self::remember($_fc_key, 60, function() use ($fid) {
                return thread_count(array('fid' => $fid, 'is_deleted' => 0, 'top' => 0, 'audit_status' => 1));
            });
            $results['版块#' . $fid . '帖子总数'] = true;

            // 版块帖子列表第1页（默认排序 lastpid）
            $_fl_key = 'core_forum_tl_' . $fid . '_lastpid_1_0';
            self::remember($_fl_key, 60, function() use ($fid) {
                return thread_find_by_fid($fid, 1, 20, 'lastpid');
            });
            $results['版块#' . $fid . '帖子列表'] = true;
        }

        return $results;
    }

    /**
     * 预热核心缓存键
     */
    private static function warmupCoreKey($key) {
        switch($key) {
            case 'core_forumlist':
            case 'forumlist':
                if(function_exists('forum_list_cache')) {
                    forum_list_cache();
                    return true;
                }
                return false;
            case 'core_grouplist':
            case 'grouplist':
                if(function_exists('group_list_cache')) {
                    group_list_cache();
                    return true;
                }
                return false;
            case 'core_runtime':
            case 'runtime':
                // 预热全站统计缓存，调用 runtime_init() 重建整个 runtime 数据
                // 不能用 runtime_get() 因为它需要参数 $k 且只读取不重建
                if(function_exists('runtime_init')) {
                    runtime_init();
                    return true;
                }
                return false;
            case 'core_thread_top_list':
            case 'thread_top_list':
                if(function_exists('thread_top_find_cache')) {
                    thread_top_find_cache();
                    return true;
                }
                return false;
            case 'core_forum_tree':
            case 'forum_tree':
                if(class_exists('ForumService')) {
                    $fs = new ForumService();
                    $fs->getForumTree();
                    return true;
                }
                return false;
        }
        return false;
    }
}
