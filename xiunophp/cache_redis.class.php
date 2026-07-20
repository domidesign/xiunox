<?php

class cache_redis {
	
	public $conf = array();
	public $link = NULL;
	public $cachepre = '';
	public $errno = 0;
	public $errstr = '';
	
        public function __construct($conf = array()) {
                if(!extension_loaded('Redis')) {
                        return $this->error(-1, ' Redis 扩展没有加载');
                }
                $this->conf = $conf;
		$this->cachepre = isset($conf['cachepre']) ? $conf['cachepre'] : 'pre_';
        }
        public function connect() {
                if($this->link) return $this->link;
                try {
                        $redis = new Redis;
                        // 设置 2 秒连接超时，避免 Redis 无响应时阻塞整个请求
                        $r = @$redis->connect($this->conf['host'], $this->conf['port'], 2);
                        if(!$r) {
                                $this->link = FALSE;
                                return $this->error(-1, '连接 Redis 服务器失败。');
                        }
                        if(!empty($this->conf['password'])) {
                                $auth = $redis->auth($this->conf['password']);
                                if(!$auth) {
                                        $this->link = FALSE;
                                        return $this->error(-1, 'Redis 认证失败。');
                                }
                        }
                        if(isset($this->conf['database']) && intval($this->conf['database']) > 0) {
                                $redis->select(intval($this->conf['database']));
                        }
                        // 执行 PING 命令验证连接是否真正可用（检测是否需要认证）
                        try {
                                $ping = @$redis->ping();
                                // ping 成功返回 +PONG 或 TRUE，失败返回 FALSE
                                if($ping === FALSE) {
                                        $this->link = FALSE;
                                        return $this->error(-1, 'Redis 连接验证失败（可能需要认证）。');
                                }
                        } catch(\Throwable $pingEx) {
                                // PING 抛出异常（如 NOAUTH），说明连接不可用
                                $this->link = FALSE;
                                return $this->error(-1, 'Redis 连接验证异常：' . $pingEx->getMessage());
                        }
                        $this->link = $redis;
                        return $this->link;
                } catch(\Throwable $e) {
                        $this->link = FALSE;
                        return $this->error(-1, '连接 Redis 服务器异常：' . $e->getMessage());
                }
        }
        // 检查缓存连接是否可用
        public function isConnected() {
                return $this->link !== FALSE && $this->link !== NULL;
        }
        // 执行带 NOAUTH 自动重连的 Redis 命令
        // Redis 设置密码后，旧连接可能未认证，遇到 NOAUTH 时自动重连
        // 重连失败时返回 FALSE 而非抛异常，让上层降级机制接管
        private function execWithReauth($callback) {
                if(!$this->link && !$this->connect()) return FALSE;
                try {
                        return call_user_func($callback, $this->link);
                } catch(\RedisException $e) {
                        $msg = $e->getMessage();
                        // NOAUTH / Connection lost 等异常，重连一次后重试
                        if(strpos($msg, 'NOAUTH') !== false || strpos($msg, 'Connection') !== false) {
                                $this->link = NULL;
                                if($this->connect()) {
                                        try {
                                                return call_user_func($callback, $this->link);
                                        } catch(\Throwable $retryEx) {
                                                // 重试仍然失败，返回 FALSE
                                                $this->error(-1, 'Redis 重试失败：' . $retryEx->getMessage());
                                                return FALSE;
                                        }
                                }
                        }
                        // 重连失败或不可恢复异常，返回 FALSE 让上层降级
                        // 不抛异常，避免整个网站白屏
                        $this->error(-1, 'Redis 操作失败：' . $msg);
                        return FALSE;
                } catch(\Throwable $e) {
                        $this->error(-1, 'Redis 操作异常：' . $e->getMessage());
                        return FALSE;
                }
        }

        public function set($k, $v, $life = 0) {
                $r = $this->execWithReauth(function($redis) use ($k, $v, $life) {
                        $_v = xn_json_encode($v);
                        $ret = $redis->set($k, $_v);
                        $life AND $ret AND $redis->expire($k, $life);
                        return $ret;
                });
                return $r;
        }
        public function get($k) {
                $r = $this->execWithReauth(function($redis) use ($k) {
                        $ret = $redis->get($k);
                        return $ret === FALSE ? NULL : xn_json_decode($ret);
                });
                return $r === FALSE ? NULL : $r;
        }
        public function delete($k) {
                $r = $this->execWithReauth(function($redis) use ($k) {
                        return $redis->del($k) ? TRUE : FALSE;
                });
                return $r;
        }
        public function truncate() {
                if(!$this->link && !$this->connect()) return FALSE;
                // 只清除当前 cachepre 前缀的键，不用 flushdb 避免误删 session 等其他数据
                $count = $this->deleteByPrefix($this->cachepre);
                return $count > 0;
        }
        /**
         * 按前缀删除缓存键（生产安全，用 SCAN 代替 KEYS）
         * @param string $prefix 键名前缀（不含 cachepre，会自动拼接）
         * @return int 删除的键数量
         */
        public function deleteByPrefix($prefix) {
                if(!$this->link && !$this->connect()) return 0;
                // 拼接完整前缀：cachepre + 用户传入的 prefix
                $fullPrefix = $this->cachepre . $prefix;
                $deleted = 0;
                $scanned = 0;
                $iterator = NULL;
                $start = microtime(TRUE);
                // 时间预算（秒）：键多时避免阻塞整个请求，超出就停止并返回已删除数量
                // ponytail: 简单粗暴的 ceiling——5 秒内能删多少删多少，剩余键下次清理或自然过期
                $timeBudget = 5.0;
                // 探测 Redis 是否支持 UNLINK（异步删除，不阻塞主线程），失败则回退 DEL
                $useUnlink = NULL;
                // Redis SCAN 是非阻塞迭代器，避免 KEYS 命令阻塞服务器
                // 每次迭代返回 100 个键，比 KEYS 安全得多
                try {
                        while($keys = $this->link->scan($iterator, $fullPrefix . '*', 100)) {
                                if(!empty($keys)) {
                                        $scanned += count($keys);
                                        if($useUnlink === NULL) {
                                                try {
                                                        $this->link->unlink($keys);
                                                        $useUnlink = TRUE;
                                                } catch(\Throwable $unlinkEx) {
                                                        $this->link->del($keys);
                                                        $useUnlink = FALSE;
                                                }
                                        } else {
                                                $useUnlink ? $this->link->unlink($keys) : $this->link->del($keys);
                                        }
                                        $deleted += count($keys);
                                }
                                // SCAN 返回的 iterator 为 0 表示迭代完成
                                if($iterator === 0) break;
                                // 超过时间预算就停止，剩余键下次清理或自然过期
                                if(microtime(TRUE) - $start > $timeBudget) {
                                        $this->error(-1, 'Redis deleteByPrefix 时间预算 ' . $timeBudget . 's 用尽，已删 ' . $deleted . '/' . $scanned . ' 键，剩余稍后清理');
                                        break;
                                }
                        }
                } catch(\Throwable $e) {
                        // SCAN 失败时记录错误但不中断业务
                        $this->error(-1, 'Redis deleteByPrefix 异常：' . $e->getMessage());
                }
                return $deleted;
        }
        public function error($errno = 0, $errstr = '') {
		$this->errno = $errno;
		$this->errstr = $errstr;
		// 只写日志，不 trigger_error 到页面
		// DEBUG 模式下 trigger_error 会把 Redis 连接/认证错误输出到 HTML，
		// 导致 headers already sent 和页面渲染中断（帖子列表不显示）
		if(function_exists('xn_log')) {
			xn_log('Cache Error: ' . $errstr, 'cache_error');
		}
		return FALSE;
	}
        public function __destruct() {

        }
}

?>