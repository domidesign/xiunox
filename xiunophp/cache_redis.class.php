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
        public function set($k, $v, $life = 0) {
                if(!$this->link && !$this->connect()) return FALSE;
                $v = xn_json_encode($v);
                $r = $this->link->set($k, $v);
                $life AND $r AND $this->link->expire($k, $life);
                return $r;
        }
        public function get($k) {
                if(!$this->link && !$this->connect()) return FALSE;
                $r = $this->link->get($k);
                return $r === FALSE ? NULL : xn_json_decode($r);
        }
        public function delete($k) {
                if(!$this->link && !$this->connect()) return FALSE;
                return $this->link->del($k) ? TRUE : FALSE;
        }
        public function truncate() {
                if(!$this->link && !$this->connect()) return FALSE;
                return $this->link->flushdb(); // flushall
        }
        public function error($errno = 0, $errstr = '') {
		$this->errno = $errno;
		$this->errstr = $errstr;
		DEBUG AND trigger_error('Cache Error:'.$this->errstr);
	}
        public function __destruct() {

        }
}

?>