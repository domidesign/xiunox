<?php
/**
 * 轻量服务注册表
 *
 * 替代 $_SERVER['db']/$_SERVER['cache']/$_SERVER['conf'] 等服务存储
 * 旧代码通过 $_SERVER['xxx'] 仍可访问（兼容层在 index.inc.php/model.inc.php 中实现）
 * @since 1.0.2
 */
class ServiceRegistry {
    private static $services = array();

    /**
     * 注册服务
     * @param string $name 服务名（如 'db'/'cache'/'conf'）
     * @param mixed $instance 服务实例
     */
    public static function set($name, $instance) {
        self::$services[$name] = $instance;
        // 同步到 $_SERVER 兼容旧代码
        $_SERVER[$name] = $instance;
    }

    /**
     * 获取服务
     * @param string $name 服务名
     * @return mixed|null
     */
    public static function get($name) {
        return isset(self::$services[$name]) ? self::$services[$name] : null;
    }

    /**
     * 检查服务是否已注册
     * @param string $name 服务名
     * @return bool
     */
    public static function has($name) {
        return isset(self::$services[$name]);
    }

    /**
     * 注销服务
     * @param string $name 服务名
     */
    public static function remove($name) {
        unset(self::$services[$name]);
        unset($_SERVER[$name]);
    }

    /**
     * 清空所有服务（用于测试）
     */
    public static function clear() {
        foreach(self::$services as $name => $_) {
            unset($_SERVER[$name]);
        }
        self::$services = array();
    }
}
