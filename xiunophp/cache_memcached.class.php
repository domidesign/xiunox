<?php

class cache_memcached {
	
	public $conf = array();
	public $link = NULL;
	public $cachepre = '';
	public $errno = 0;
	public $errstr = '';
	public $ismemcache = FALSE;

        public function __construct($conf = array()) {
                if(!extension_loaded('Memcache') && !extension_loaded('Memcached') ) {
                        return $this->error(1, function_exists('lang') && !empty($_SERVER['lang']) ? lang('memcached_ext_not_loaded') : ' Memcached 扩展没有加载，请检查您的 PHP 版本');
                }
                $this->conf = $conf;
		$this->cachepre = isset($conf['cachepre']) ? $conf['cachepre'] : 'pre_';
        }
        public function connect() {
                $conf = $this->conf;
                if($this->link) return $this->link;
                try {
                        if(extension_loaded('Memcache')) {
                                $this->ismemcache = TRUE;
                                $memcache = new Memcache;
                                // PHP 8.2+ Memcache::connect 内部创建动态属性触发 E_DEPRECATED，临时抑制
                                // 设置 2 秒连接超时，避免 Memcache 无响应时阻塞整个请求
                                set_error_handler(function($errno) { return $errno == E_DEPRECATED; });
                                try {
                                        $r = @$memcache->connect($conf['host'], $conf['port'], 2);
                                } finally {
                                        restore_error_handler();
                                }
                        } elseif(extension_loaded('Memcached')) {
                                $this->ismemcache = FALSE;
                                $memcache = new Memcached;
                                $memcache->setOption(Memcached::OPT_CONNECT_TIMEOUT, 2000);
                                $r = $memcache->addserver($conf['host'], $conf['port']);
                        } else {
                                $this->link = FALSE;
                                return $this->error(-1, function_exists('lang') && !empty($_SERVER['lang']) ? lang('memcached_ext_not_exists') : 'Memcache 扩展不存在。');
                        }

                        if(!$r) {
                                $this->link = FALSE;
                                return $this->error(-1, function_exists('lang') && !empty($_SERVER['lang']) ? lang('memcached_connect_failed') : '连接 Memcached 服务器失败。');
                        }
                        $this->link = $memcache;
                        return $this->link;
                } catch(\Throwable $e) {
                        $this->link = FALSE;
                        return $this->error(-1, (function_exists('lang') && !empty($_SERVER['lang']) ? lang('memcached_connect_exception') : '连接 Memcached 服务器异常：') . $e->getMessage());
                }
        }
        // 检查缓存连接是否可用
        public function isConnected() {
                return $this->link !== FALSE && $this->link !== NULL;
        }
        public function set($k, $v, $life = 0) {
                if(!$this->link && !$this->connect()) return FALSE;
                if($this->ismemcache) {
                	$r = $this->link->set($k, $v, 0, $life);
                } else {
                	$r = $this->link->set($k, $v, $life);
                }
                return $r;
        }
        public function get($k) {
                if(!$this->link && !$this->connect()) return FALSE;
                $r = $this->link->get($k);
                return $r === FALSE ? NULL : $r;
        }
        public function delete($k) {
                if(!$this->link && !$this->connect()) return FALSE;
                return $this->link->delete($k); // TRUE|FALSE
        }
        public function truncate() {
                if(!$this->link && !$this->connect()) return FALSE;
                return $this->link->flush();
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