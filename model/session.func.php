<?php 

/*
	php 默认的 session 采用文件存储，并且使用 flock() 文件锁避免并发访问不出问题（实际上还是无法解决业务层的并发读后再写入）
	自定义的 session 采用数据表来存储，同样无法解决业务层并发请求问题。
	xiuno.js $.each_sync() 串行化并发请求，可以避免客户端并发访问导致的 session 写入问题。
*/

$sid = '';
$g_session = array();	
$g_session_invalid = FALSE;

class XiunoSessionHandler implements SessionHandlerInterface {
	public function open(string $save_path, string $session_name): bool {
		return sess_open($save_path, $session_name);
	}
	public function close(): bool {
		return sess_close();
	}
	public function read(string $sid): string|false {
		return sess_read($sid);
	}
	public function write(string $sid, string $data): bool {
		return sess_write($sid, $data);
	}
	public function destroy(string $sid): bool {
		return sess_destroy($sid);
	}
	public function gc(int $maxlifetime): int|false {
		return sess_gc($maxlifetime) ? 1 : false;
	}
}

function sess_open($save_path, $session_name) {
	//echo "sess_open($save_path,$session_name) \r\n";
	return true;
}

// 关闭句柄，清理资源，这里 $sid 已经为空，
function sess_close() {
	return true;
}

// 如果 cookie 中没有 bbs_sid, php 会自动生成 sid，作为参数
function sess_read($sid) { 
	global $g_session, $longip, $time;
	//echo "sess_read() sid: $sid <br>\r\n";
	if(empty($sid)) {
		// 查找刚才是不是已经插入一条了？  如果相隔时间特别短，并且 data 为空，则删除。
		// 测试是否支持 cookie，如果不支持 cookie，则不生成 sid
		$sid = session_id();
		sess_new($sid);
		return '';
	}
	$arr = db_find_one('session', array('sid'=>$sid));
	if(empty($arr)) {
		sess_new($sid);
		return '';
	}
	if($arr['bigdata'] == 1) {
		// ponytail: session_data 记录可能被 GC 清理但 session 仍存在 bigdata=1 的残留状态，
		// 此时 $arr2 为 false，访问 ['data'] 会触发 PHP8 Trying to access array offset on null Warning
		// → ErrorHandler 升级 ErrorException → 500。守卫空值，回退到空 session 数据
		$arr2 = db_find_one('session_data', array('sid'=>$sid));
		$arr['data'] = $arr2 ? $arr2['data'] : '';
	}
	$g_session = $arr;
	// 在 php 5.6.29 版本，需要返回 session_decode()
	//return $arr ? session_decode($arr['data']) : '';
	return $arr ? $arr['data'] : '';
}

function sess_new($sid) {
	global $time, $longip, $conf, $g_session, $g_session_invalid;

	$agent = _SERVER('HTTP_USER_AGENT', '');

	// 干掉同 ip 的 sid，仅仅在遭受攻击的时候
	//db_delete('session', array('ip'=>$longip));

	// 判断是否 HTTPS，用于设置 cookie 安全属性
	$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
	// SameSite 默认 Lax：None 会导致 CSRF 风险，且浏览器要求 None 必须配合 Secure
	$samesite = 'Lax';
	$cookie_options = array(
		'expires' => 0,
		'path' => '/',
		'domain' => '',
		'secure' => $is_https,
		'httponly' => true,
		'samesite' => $samesite,
	);

	$cookie_test = _COOKIE('cookie_test');
	if($cookie_test) {
		$cookie_test_decode = xn_decrypt($cookie_test, $conf['auth_key']);
		$g_session_invalid = ($cookie_test_decode != md5($agent.$longip));
		// 删除 cookie_test，使用与 session cookie 一致的安全属性
		$del_options = $cookie_options;
		$del_options['expires'] = $time - 86400;
		setcookie('cookie_test', '', $del_options);
	} else {
		$cookie_test = xn_encrypt(md5($agent.$longip), $conf['auth_key']);
		// 设置 cookie_test，使用与 session cookie 一致的安全属性
		$set_options = $cookie_options;
		$set_options['expires'] = $time + 86400;
		setcookie('cookie_test', $cookie_test, $set_options);
		$g_session_invalid = FALSE;
		// 不再提前返回，始终创建 session 记录
		// 否则首次访问时 CSRF token 等会话数据无法持久化到数据库
	}

	// 可能会暴涨
	$url = _SERVER('REQUEST_URI_NO_PATH');

	$arr = array(
		'sid'=>$sid,
		'uid'=>0,
		'fid'=>0,
		'url'=>$url,
		'last_date'=>$time,
		'data'=> '',
		'ip'=> $longip,
		'useragent'=> $agent,
		'bigdata'=> 0,
	);
	$g_session = $arr;
	// 使用 replace into 避免 SID 重复时插入失败
	db_replace('session', $arr);
}

// 重新启动 session，降低并发写入数据的问题，这回抛弃前面的 _SESSION 数据
function sess_restart() {
	global $sid;
	$data = sess_read($sid);
	session_decode($data); // 直接存入了 $_SESSION
}

// 将当前的 _SESSION 变量保存
function sess_save() {
	global $sid;
	sess_write($sid, TRUE);
}

// 模拟加锁，如果发现写入的时候数据已经发生改变，则读取后，合并数据，重新写入（合并总比删除安全一点）。
function sess_write($sid, $data) {
	global $g_session, $time, $longip, $g_session_invalid, $conf, $db;
	
	// 静态资源请求跳过 session 更新，避免不必要的数据库写入
	// 包括：.css .js .map .png .jpg .gif .svg .ico .woff .ttf .eot 等
	$request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
	if($request_uri) {
		$static_ext_pattern = '/\.(css|js|map|png|jpe?g|gif|svg|ico|woff2?|ttf|eot|webp|avif)(\?|$)/i';
		if(preg_match($static_ext_pattern, $request_uri)) {
			return TRUE;
		}
	}
	
	//echo "sess_write($sid, $data)";
	//if($g_session_invalid) return TRUE;
	
	$uid = _SESSION('uid');
	$fid = _SESSION('fid');
	unset($_SESSION['uid']);
	unset($_SESSION['fid']);
	
	if($data) {
		//$arr = session_decode($data);
		//unset($_SESSION['uid']);
		//unset($_SESSION['fid']);
		$data = session_encode();
	}
	
	function_exists('chdir') AND chdir(APP_PATH);
	
	$url = _SERVER('REQUEST_URI_NO_PATH');
	$agent = _SERVER('HTTP_USER_AGENT', '');
	$arr = array(
		'uid'=>$uid,
		'fid'=>$fid,
		'url'=>$url,
		'last_date'=>$time,
		'data'=> $data,
		'ip'=> $longip,
		'useragent'=> $agent,
		'bigdata'=> 0,
	);
	
	// 开启 session 延迟更新，减轻压力，会导致不重要的数据(useragent,url)显示有些延迟，单位为秒。
	$session_delay_update_on = !empty($conf['session_delay_update']) && $time - $g_session['last_date'] < $conf['session_delay_update'];
	if($session_delay_update_on) {
		unset($arr['fid']);
		unset($arr['url']);
		unset($arr['last_date']);
	}
	
	// 判断数据是否超长
	$len = strlen($data);
	// ponytail: $g_session 可能为空数组（sess_read/sess_new 异常时），用 ?? 0 守卫避免 PHP8 Undefined array key
	if($len > 255 && ($g_session['bigdata'] ?? 0) == 0) {
		// INSERT IGNORE 避免并发请求重复插入同 sid 导致主键冲突
		// ponytail: 并发场景下多个请求可能同时进入此分支，IGNORE 让第二个静默成功
		$_t = $db->tablepre ?? '';
		db_exec("INSERT IGNORE INTO {$_t}session_data (`sid`) VALUES ('" . addslashes($sid) . "')");
		// 注意：不在此处修改 $g_session['bigdata']，否则下方 array_diff_value 会认为 bigdata 无变化
		// 导致 session 表 bigdata 列不更新（保持 0），后续请求读不到 session_data 中的大数据
	}
	if($len <= 255) {
		$update = array_diff_value($arr, $g_session);
		db_update('session', array('sid'=>$sid), $update);
		if(!empty($g_session) && ($g_session['bigdata'] ?? 0) == 1) {
			db_delete('session_data', array('sid'=>$sid));
		}
	} else {
		$arr['data'] = '';
		$arr['bigdata'] = 1;
		$update = array_diff_value($arr, $g_session);
		$update AND db_update('session', array('sid'=>$sid), $update);
		$arr2 = array('data'=>$data, 'last_date'=>$time);
		if($session_delay_update_on) unset($arr2['last_date']);
		$update2 = array_diff_value($arr2, $g_session);
		$update2 AND db_update('session_data', array('sid'=>$sid), $update2);
		// 更新 DB 后再同步 $g_session，避免同请求内重复 INSERT
		$g_session['bigdata'] = 1;
	}
	return TRUE;
}

function sess_destroy($sid) { 
	//echo "sess_destroy($sid) \r\n";
	db_delete('session', array('sid'=>$sid));
	db_delete('session_data', array('sid'=>$sid));
	return TRUE; 
}

function sess_gc($maxlifetime) {
	global $time;
	// echo "sess_gc($maxlifetime) \r\n";
	$expiry = $time - $maxlifetime;
	db_delete('session', array('last_date'=>array('<'=>$expiry)));
	db_delete('session_data', array('last_date'=>array('<'=>$expiry)));
	return TRUE; 
}

function sess_start() {
	global $conf, $sid, $g_session;
	ini_set('session.name', 'bbs_sid');
	
	ini_set('session.use_cookies', 'On');
	ini_set('session.use_only_cookies', 'On');

	// 设置 session cookie 安全属性
	// 优先读取安全配置中的 Cookie 设置，未配置则自动检测
	$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

	// Cookie Secure：security_cookie_secure 已设置时 0=自动检测HTTPS，>0=强制Secure
	// 修复前 0 会 fallthrough 到旧 cookie_secure 配置，导致 HTTPS 下 Secure 标志缺失
	if(isset($conf['security_cookie_secure'])) {
		$cookie_secure = intval($conf['security_cookie_secure']) > 0 || $is_https;
	} elseif(isset($conf['cookie_secure'])) {
		$cookie_secure = intval($conf['cookie_secure']) > 0;
	} else {
		$cookie_secure = $is_https;
	}

	// Cookie HttpOnly：默认开启
	$cookie_httponly = true;
	if(isset($conf['security_cookie_httponly'])) {
		$cookie_httponly = intval($conf['security_cookie_httponly']) > 0;
	}

	// Cookie SameSite：优先使用安全配置，否则默认 Lax（防 CSRF）
	if(isset($conf['security_cookie_samesite']) && in_array($conf['security_cookie_samesite'], array('Lax', 'Strict', 'None'), true)) {
		$samesite = $conf['security_cookie_samesite'];
	} else {
		$samesite = 'Lax';
	}

	session_set_cookie_params(array(
		'lifetime' => 604800, // 7 天有效期（原 100 天 8640000，缩短以降低 cookie 泄露窗口）
		'path' => '/',
		'domain' => '',
		'secure' => $cookie_secure,
		'httponly' => $cookie_httponly,
		'samesite' => $samesite,
	));
	
	ini_set('session.gc_maxlifetime', $conf['online_hold_time']);	// 活动时间 $conf['online_hold_time']
	ini_set('session.gc_probability', 1); 	// 垃圾回收概率 = gc_probability/gc_divisor
	ini_set('session.gc_divisor', 500); 	// 垃圾回收时间 5 秒，在线人数 * 10 
	
	// 根据 session_handler 配置选择存储驱动
	// - file：PHP 默认文件存储（性能最佳，但不支持 online_count 等依赖 session 表的功能）
	// - redis：Redis 存储（需 phpredis 扩展，连接失败自动回退 file）
	// - db：数据库存储（默认，兼容 online_count/online_list 等业务功能）
	$session_handler = isset($conf['session_handler']) ? $conf['session_handler'] : 'db';
	switch($session_handler) {
		case 'file':
			// 使用 PHP 默认 file handler，不注册自定义 save handler
			break;
		case 'redis':
			// 连接失败会回退到 PHP 默认 file handler
			sess_init_redis_handler();
			break;
		case 'db':
		default:
			session_set_save_handler(new XiunoSessionHandler());
			break;
	}

	// register_shutdown_function 会丢失当前目录，需要 chdir(APP_PATH)

	// 这个比须有，否则 ZEND 会提前释放 $db 资源
	register_shutdown_function('session_write_close');

	session_start();
	
	$sid = session_id();
	
	//$_SESSION['uid'] = $g_session['uid'];
	//$_SESSION['fid'] = $g_session['fid'];
	
	//echo "sess_start() sid: $sid <br>\r\n";
	//print_r(db_find('session'));
	return $sid;
}

function online_count() {
	return db_count('session');
}

function online_find_cache() {
	// 增加 WHERE 和 LIMIT 限制，避免全表扫描
	$time = time();
	return db_find('session', array('last_date'=>array('>'=>$time - 3600)), array(), 1, 1000, '', array('uid', 'ip', 'last_date'));
}

function online_list_cache() {
	$onlinelist = cache_get('online_list');
	if($onlinelist === NULL || $onlinelist === FALSE) {
		$onlinelist = db_find('session', array('uid'=>array('>'=>0)), array('last_date'=>-1), 1, 500);
		foreach($onlinelist as &$online) {
			$user = user_read_cache($online['uid']);
			$online['username'] = isset($user['display_name']) ? $user['display_name'] : $user['username'];
			$online['gid'] = $user['gid'];
			$online['ip_fmt'] = long2ip($online['ip']);
			$online['last_date_fmt'] = date('Y-n-j H:i', $online['last_date']);
		}
		cache_set('online_list', $onlinelist, 300);
	}
	return $onlinelist;
}

/**
 * Redis Session Handler（自定义实现）
 * 密码通过 Redis::auth() 单独认证，不写入 session.save_path，避免 phpinfo() 泄露
 * 连接失败时静默回退到 PHP 默认 file handler
 */
class XiunoRedisSessionHandler implements SessionHandlerInterface {
	/** @var Redis */
	private $redis;
	/** @var string session key 前缀 */
	private $prefix = 'sess:';

	public function __construct(Redis $redis) {
		$this->redis = $redis;
	}

	public function open(string $save_path, string $session_name): bool {
		return true;
	}

	public function close(): bool {
		// 不主动 close，保持连接供 write 使用，由 register_shutdown_function 管理
		return true;
	}

	public function read(string $sid): string|false {
		$data = $this->redis->get($this->prefix . $sid);
		return $data === false ? '' : $data;
	}

	public function write(string $sid, string $data): bool {
		$maxlifetime = intval(ini_get('session.gc_maxlifetime'));
		if($maxlifetime <= 0) $maxlifetime = 1440;
		return $this->redis->setex($this->prefix . $sid, $maxlifetime, $data);
	}

	public function destroy(string $sid): bool {
		$this->redis->del($this->prefix . $sid);
		return true;
	}

	public function gc(int $maxlifetime): int|false {
		// Redis 通过 setex TTL 自动过期，无需主动 GC
		return 0;
	}
}

/**
 * 初始化 Redis Session Handler
 * 使用自定义 XiunoRedisSessionHandler 代替 phpredis 原生 session handler
 * 密码通过 Redis::auth 单独认证，不写入 session.save_path（避免 phpinfo/error log 泄露）
 * 连接失败时静默回退到 PHP 默认 file handler
 */
function sess_init_redis_handler() {
	global $conf;

	// 检查 phpredis 扩展是否可用
	if(!class_exists('Redis')) {
		xn_log('Redis extension not available, fallback to file session', 'session_error');
		return;
	}

	$cfg = isset($conf['session_redis']) ? $conf['session_redis'] : array();
	$host = isset($cfg['host']) ? $cfg['host'] : '127.0.0.1';
	$port = isset($cfg['port']) ? $cfg['port'] : 6379;
	// 兼容新旧字段名：password（新）/ auth（旧）；database（新）/ db（旧）
	$auth = isset($cfg['password']) ? $cfg['password'] : (isset($cfg['auth']) ? $cfg['auth'] : '');
	$db = isset($cfg['database']) ? $cfg['database'] : (isset($cfg['db']) ? $cfg['db'] : 0);

	// 创建 Redis 连接，密码通过 auth() 单独认证（不写入 save_path URL）
	try {
		$redis = new Redis();
		$connected = $redis->connect($host, intval($port), 2);
		if(!$connected) {
			throw new Exception('Redis connect returned false');
		}
		if($auth) $redis->auth($auth);
		if($db) $redis->select(intval($db));
	} catch(Exception $e) {
		xn_log('Redis connect failed: ' . $e->getMessage() . ', fallback to file session', 'session_error');
		return;
	}

	// 注册自定义 SessionHandler，密码不暴露在 save_path 中
	// phpredis 原生 handler 需 save_path=tcp://host:port?auth=xxx，密码会出现在 phpinfo() 输出
	session_set_save_handler(new XiunoRedisSessionHandler($redis), true);
}

?>