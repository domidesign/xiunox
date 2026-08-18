<?php

class cache_file {

    public $conf = array();
    public $cachepre = '';
    public $errno = 0;
    public $errstr = '';
    // 缓存目录
    public $cache_dir = '';

    public function __construct($conf = array()) {
        $this->conf = $conf;
        $this->cachepre = isset($conf['cachepre']) ? $conf['cachepre'] : 'pre_';
        $this->cache_dir = !empty($conf['cache_dir']) ? $conf['cache_dir'] : APP_PATH . 'tmp/cache/';
    }

    public function connect() {
        // 确保缓存目录存在
        if(!is_dir($this->cache_dir)) {
            if (function_exists('mkdir')) {
                // ponytail: 并发请求可能在此 is_dir 检查后、mkdir 前创建目录，
                // 用 @ 抑制 File exists 警告，再用 is_dir 复核是否真正创建成功
                @mkdir($this->cache_dir, 0755, TRUE);
                if(!is_dir($this->cache_dir)) {
                    return $this->error(-1, (function_exists('lang') && !empty($_SERVER['lang']) ? lang('cache_mkdir_failed') : '创建缓存目录失败：') . $this->cache_dir);
                }
            }
        }
        return TRUE;
    }

    /**
     * 根据键名计算缓存文件路径
     * 使用 md5 前两字符作为一级目录，接下来两字符作为二级目录
     */
    public function filepath($k) {
        $hash = md5($k);
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);
        $path = $this->cache_dir . $dir1 . '/' . $dir2 . '/';
        return $path . $k . '.cache';
    }

    /**
     * 确保文件所在目录存在
     */
    public function ensure_dir($path) {
        $dir = dirname($path);
        if(!is_dir($dir)) {
            if (function_exists('mkdir')) {
                // ponytail: 并发请求可能在此 is_dir 检查后、mkdir 前创建目录，
                // 用 @ 抑制 File exists 警告，再用 is_dir 复核是否真正创建成功
                @mkdir($dir, 0755, TRUE);
                if(!is_dir($dir)) {
                    return $this->error(-1, (function_exists('lang') && !empty($_SERVER['lang']) ? lang('cache_mkdir_subdir_failed') : '创建缓存子目录失败：') . $dir);
                }
            }
        }
        return TRUE;
    }

    public function set($k, $v, $life = 0) {
        if(!$this->connect()) return FALSE;

        // 拼接带前缀的键名
        $key = $this->cachepre . $k;
        $filepath = $this->filepath($key);

        if(!$this->ensure_dir($filepath)) return FALSE;

        // 计算过期时间戳，0 表示永不过期
        $expiry = $life > 0 ? (time() + $life) : 0;

        // 格式：EXPIRY_TIMESTAMP\nJSON_DATA
        $data = $expiry . "\n" . xn_json_encode($v);

        $r = file_put_contents($filepath, $data, LOCK_EX);
        if($r === FALSE) {
            return $this->error(-1, (function_exists('lang') && !empty($_SERVER['lang']) ? lang('cache_write_file_failed') : '写入缓存文件失败：') . $filepath);
        }
        // ponytail: 部分面板（宝塔等）通过 disable_functions 禁用 chmod，
        // PHP 8+ 中 undefined function 是 Error 不被 @ 抑制，会直接崩溃导致 nginx 502
        if (function_exists('chmod')) {
            @chmod($filepath, 0644);
        }
        return TRUE;
    }

    public function get($k) {
        if(!$this->connect()) return FALSE;

        $key = $this->cachepre . $k;
        $filepath = $this->filepath($key);

        if(!is_file($filepath)) return NULL;

        $content = file_get_contents($filepath);
        if($content === FALSE) {
            return $this->error(-1, (function_exists('lang') && !empty($_SERVER['lang']) ? lang('cache_read_file_failed') : '读取缓存文件失败：') . $filepath);
        }

        // 解析过期时间戳和数据
        $pos = strpos($content, "\n");
        if($pos === FALSE) return NULL;

        $expiry = (int)substr($content, 0, $pos);
        $json_data = substr($content, $pos + 1);

        // 检查是否过期（0 表示永不过期）
        if($expiry > 0 && time() > $expiry) {
            // 已过期，删除文件并返回 NULL
            @unlink($filepath);
            return NULL;
        }

        return xn_json_decode($json_data);
    }

    public function delete($k) {
        if(!$this->connect()) return FALSE;

        $key = $this->cachepre . $k;
        $filepath = $this->filepath($key);

        if(!is_file($filepath)) return TRUE;

        $r = @unlink($filepath);
        if(!$r) {
            return $this->error(-1, (function_exists('lang') && !empty($_SERVER['lang']) ? lang('cache_delete_file_failed') : '删除缓存文件失败：') . $filepath);
        }
        return TRUE;
    }

    public function truncate() {
        if(!$this->connect()) return FALSE;

        // 递归删除缓存目录下所有文件和子目录
        $this->rmdir_recursive($this->cache_dir);

        // 重新创建缓存目录
        if (function_exists('mkdir')) {
            // ponytail: 并发请求可能在 rmdir_recursive 之后、mkdir 之前重建目录，
            // 用 @ 抑制 File exists 警告，再用 is_dir 复核
            @mkdir($this->cache_dir, 0755, TRUE);
            if(!is_dir($this->cache_dir)) {
                return $this->error(-1, (function_exists('lang') && !empty($_SERVER['lang']) ? lang('cache_recreate_dir_failed') : '重建缓存目录失败：') . $this->cache_dir);
            }
        }
        return TRUE;
    }

    /**
     * 按前缀删除缓存键（生产安全）
     * 遍历缓存目录，删除文件名匹配指定前缀的所有缓存文件，不依赖注册表
     * @param string $prefix 键名前缀（不含 cachepre，会自动拼接）
     * @return int 删除的键数量
     */
    public function deleteByPrefix($prefix) {
        if(!$this->connect()) return 0;
        $fullPrefix = $this->cachepre . $prefix;
        $deleted = 0;
        $this->rdeleteByPrefix($this->cache_dir, $fullPrefix, $deleted);
        return $deleted;
    }

    /**
     * 递归遍历目录删除匹配前缀的缓存文件
     */
    private function rdeleteByPrefix($dir, $prefix, &$deleted) {
        if(!is_dir($dir)) return;
        $entries = scandir($dir);
        foreach($entries as $entry) {
            if($entry == '.' || $entry == '..') continue;
            $path = $dir . $entry;
            if(is_dir($path)) {
                $this->rdeleteByPrefix($path . '/', $prefix, $deleted);
                // 如果目录已空则清理
                @rmdir($path);
            } else {
                // 文件名格式：{cachepre}{key}.cache
                if(strpos($entry, $prefix) === 0) {
                    if(@unlink($path)) $deleted++;
                }
            }
        }
    }

    /**
     * 递归删除目录及其内容
     */
    public function rmdir_recursive($dir) {
        if(!is_dir($dir)) return;
        $entries = scandir($dir);
        foreach($entries as $entry) {
            if($entry == '.' || $entry == '..') continue;
            $path = $dir . $entry;
            if(is_dir($path)) {
                $this->rmdir_recursive($path . '/');
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function error($errno = 0, $errstr = '') {
        $this->errno = $errno;
        $this->errstr = $errstr;
        if(function_exists('xn_log')) {
            xn_log('Cache Error: ' . $errstr, 'cache_error');
        }
    }

    public function __destruct() {

    }
}

?>
