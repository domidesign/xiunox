<?php
/**
 * XnEvent - 轻量事件机制
 *
 * 用于解耦核心逻辑与插件扩展，支持插件监听关键操作（如封禁/解封/清空内容）。
 *
 * 设计目标：
 * 1. 静态调用，无需实例化，与 CacheHelper/PermissionService 风格一致
 * 2. 零依赖（不依赖 db/cache/conf），可在框架启动最早期加载
 * 3. 回调异常不中断主流程，仅记录日志（ponytail: 事件机制是旁路逻辑，不应拖垮主请求）
 * 4. 支持 on/once/trigger/off 四个核心操作
 *
 * 事件名约定：ClassName.methodName，如 UserBanService.beforeBan
 *
 * 使用示例：
 * ```php
 * // 插件在 hook 中注册监听器（model_inc_start.php 是最早的 hook）：
 * XnEvent::on('UserBanService.beforeBan', 'my_plugin', function(&$args) {
 *     // $args 是引用，可修改后传递给主流程
 *     $args['extra_log'] = 'plugin noted this ban';
 * });
 *
 * // 核心代码触发事件：
 * XnEvent::trigger('UserBanService.beforeBan', $banData);
 *
 * // 插件卸载时移除监听器：
 * XnEvent::off('UserBanService.beforeBan', 'my_plugin');
 * ```
 */
class XnEvent {

    // 监听器注册表：[event => [['plugin'=>..., 'callback'=>..., 'once'=>bool], ...]]
    private static $listeners = array();

    /**
     * 注册事件监听器
     *
     * @param string $event 事件名（格式：ClassName.methodName，如 UserBanService.beforeBan）
     * @param string $plugin 插件标识（用于调试和按插件卸载）
     * @param callable $callback 回调函数，签名 function(&$args)
     * @return void
     */
    public static function on($event, $plugin, $callback) {
        if(!isset(self::$listeners[$event])) {
            self::$listeners[$event] = array();
        }
        self::$listeners[$event][] = array(
            'plugin' => $plugin,
            'callback' => $callback,
            'once' => false
        );
    }

    /**
     * 注册只触发一次的事件监听器（触发后自动移除）
     *
     * @param string $event 事件名
     * @param string $plugin 插件标识
     * @param callable $callback 回调函数，签名 function(&$args)
     * @return void
     */
    public static function once($event, $plugin, $callback) {
        if(!isset(self::$listeners[$event])) {
            self::$listeners[$event] = array();
        }
        self::$listeners[$event][] = array(
            'plugin' => $plugin,
            'callback' => $callback,
            'once' => true
        );
    }

    /**
     * 触发事件，依次调用所有监听器
     *
     * @param string $event 事件名
     * @param mixed $args 传递给回调的参数（按引用传递，回调可修改）
     * @return void
     */
    public static function trigger($event, &$args = null) {
        if(!isset(self::$listeners[$event]) || empty(self::$listeners[$event])) {
            return;
        }
        $toRemove = array();
        foreach(self::$listeners[$event] as $idx => $listener) {
            if(!is_callable($listener['callback'])) {
                continue;
            }
            // ponytail: 事件回调错误不中断主流程，仅记录日志
            // 已知天花板：错误日志在 DEBUG=0 时只写文件名含 'error' 的日志（xn_log 限制）
            try {
                call_user_func_array($listener['callback'], array(&$args));
            } catch(Throwable $e) {
                if(function_exists('xn_log')) {
                    xn_log('XnEvent '.$event.' plugin='.$listener['plugin'].' error: '.$e->getMessage(), 'error');
                }
            }
            if($listener['once']) {
                $toRemove[] = $idx;
            }
        }
        // 移除 once 监听器
        if(!empty($toRemove)) {
            foreach($toRemove as $idx) {
                unset(self::$listeners[$event][$idx]);
            }
            self::$listeners[$event] = array_values(self::$listeners[$event]);
        }
    }

    /**
     * 移除事件监听器
     *
     * @param string|null $event 事件名；传 null 清空所有事件的所有监听器
     * @param string|null $plugin 指定插件；传 null 移除该事件的所有监听器
     * @return void
     */
    public static function off($event = null, $plugin = null) {
        if($event === null) {
            self::$listeners = array();
            return;
        }
        if(!isset(self::$listeners[$event])) return;
        if($plugin === null) {
            unset(self::$listeners[$event]);
            return;
        }
        foreach(self::$listeners[$event] as $idx => $listener) {
            if($listener['plugin'] === $plugin) {
                unset(self::$listeners[$event][$idx]);
            }
        }
        self::$listeners[$event] = array_values(self::$listeners[$event]);
    }

    /**
     * 检查事件是否有监听器
     *
     * @param string $event 事件名
     * @return bool
     */
    public static function hasListeners($event) {
        return !empty(self::$listeners[$event]);
    }

}
