<?php

/**
 * 插件 API 路由注册表
 *
 * 插件通过在 plugin/{dir}/api_register.php 中调用 PluginApiRegistry::register()
 * 声明 API 路由映射。bootstrap.php 在核心路由未命中时回退到本注册表。
 *
 * 约定：
 * - 插件 api_register.php 文件以 `<?php exit;` 开头防止直接访问
 *   （编译期会被 plugin.func.php 自动剥掉，运行期 include 不受影响）
 * - 插件 register() 传入的 $file 必须是绝对路径
 */
class PluginApiRegistry {
    private static $routes = [];
    private static $initialized = false;

    /**
     * 初始化：扫描已启用插件，include 其 api_register.php 让插件自行注册路由
     * 不依赖 _include() 编译机制，对直接 include bootstrap.php 的入口同样生效
     */
    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        // 兜底：确保 plugin_paths_enabled() 可用
        // api/v1/index.php 已加载 plugin.func.php；index.inc.php 经 index.php 也已加载
        if (!function_exists('plugin_paths_enabled')) {
            $pluginFuncFile = APP_PATH . 'model/plugin.func.php';
            if (is_file($pluginFuncFile)) {
                include $pluginFuncFile;
            } else {
                return;
            }
        }

        $pluginPaths = plugin_paths_enabled();
        if (empty($pluginPaths)) return;

        foreach ($pluginPaths as $path => $pconf) {
            $registerFile = $path . '/api_register.php';
            if (!is_file($registerFile)) continue;
            // 插件在该文件中调用 PluginApiRegistry::register($key, $file)
            // try/catch 隔离：单个插件 api_register.php 语法错误不让整个 API 系统崩
            try {
                include $registerFile;
            } catch(\Throwable $e) {
                if(function_exists('xn_log')) {
                    xn_log("Plugin api_register.php error, skipped: $registerFile - ".$e->getMessage(), 'plugin_syntax_error');
                }
            }
        }
    }

    /**
     * 注册插件 API 路由
     * @param string $routeKey 路由 key（URL 第一段，如 'lottery'）
     * @param string $file 路由文件绝对路径
     */
    public static function register($routeKey, $file) {
        self::$routes[$routeKey] = $file;
    }

    /**
     * 解析路由
     * @param string $routeKey URL 第一段
     * @return string|null 路由文件路径，未命中返回 null
     */
    public static function resolve($routeKey) {
        self::init();
        return self::$routes[$routeKey] ?? null;
    }

    /**
     * 获取所有已注册路由
     */
    public static function getRoutes(): array {
        self::init();
        return self::$routes;
    }
}
