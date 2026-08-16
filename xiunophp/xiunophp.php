<?php

/*
	XiunoPHP 4.0 只是定义了一些函数和全局变量，方便使用，并没有要求如何组织代码。
	采用静态语言编程风格，有利于 Zend 引擎的编译和 OPCache 缓存，支持 PHP7
	1. 禁止使用 eval(), 正则表达式 e 修饰符
	2. 尽量避免 autoload
	3. 尽量避免 $$var 多重变量
	4. 尽量避免 PHP 高级特性 __call __set __get 等魔术方法，不利于错误排查
	5. 尽量采用函数封装功能，通过前缀区分模块
*/

!defined('DEBUG') AND define('DEBUG', 1); // 1: 开发模式， 2: 线上调试：日志记录，0: 关闭
!defined('APP_PATH') AND define('APP_PATH', './');
!defined('XIUNOPHP_PATH') AND define('XIUNOPHP_PATH', dirname(__FILE__).'/');

function_exists('ini_set') AND ini_set('display_errors', DEBUG ? '1' : '0');
error_reporting(DEBUG ? E_ALL : 0);
// PHP 8.0+ 已移除 set_magic_quotes_runtime() 和 get_magic_quotes_gpc()，此处保留 $get_magic_quotes_gpc = false 以兼容下方 param_force 中对魔术引号的判断
$get_magic_quotes_gpc = false;
$starttime = microtime(1);
$time = time();

// 头部，判断是否运行在命令行下（幂等：api/v1/index.php 与 bootstrap.php 可能重复 include 本文件）
!defined('IN_CMD') AND define('IN_CMD', !empty($_SERVER['SHELL']) || empty($_SERVER['REMOTE_ADDR']));
if(IN_CMD) {
	!isset($_SERVER['REMOTE_ADDR']) AND $_SERVER['REMOTE_ADDR'] = '';
	!isset($_SERVER['REQUEST_URI']) AND $_SERVER['REQUEST_URI'] = '';
	!isset($_SERVER['REQUEST_METHOD']) AND $_SERVER['REQUEST_METHOD'] = 'GET';
} else {
	header("Content-type: text/html; charset=utf-8");
	//header("Cache-Control: max-age=0;"); // 手机返回的时候回导致刷新
	//header("Cache-Control: no-store;");
	//header("X-Powered-By: XiunoPHP 4.0");
}

// hook xiunophp_include_before.php

// ----------------------------------------------------------> db cache class

include XIUNOPHP_PATH.'db_pdo_mysql.class.php';
include XIUNOPHP_PATH.'db_pdo_sqlite.class.php';
include XIUNOPHP_PATH.'cache_file.class.php';
include XIUNOPHP_PATH.'cache_memcached.class.php';
include XIUNOPHP_PATH.'cache_mysql.class.php';
include XIUNOPHP_PATH.'cache_redis.class.php';

// ----------------------------------------------------------> 全局函数

include XIUNOPHP_PATH.'db.func.php';
include XIUNOPHP_PATH.'cache.func.php';
include XIUNOPHP_PATH.'image.func.php';
include XIUNOPHP_PATH.'array.func.php';
include XIUNOPHP_PATH.'xn_encrypt.func.php';
include XIUNOPHP_PATH.'misc.func.php';

// hook xiunophp_include_after.php

empty($conf) AND $conf = array('db'=>array(), 'cache'=>array(), 'tmp_path'=>'./', 'log_path'=>'./', 'timezone'=>'Asia/Shanghai');
empty($conf['tmp_path']) AND $conf['tmp_path'] = ini_get('upload_tmp_dir');
empty($conf['log_path']) AND $conf['log_path'] = './';

// auth_key 安全检测：禁止使用已知硬编码值或空值运行
$_auth_key = isset($conf['auth_key']) ? $conf['auth_key'] : '';
if($_auth_key === ''
	|| $_auth_key === 'efdkjfjiiiwurjdmclsldow753jsdj438'
	|| strlen($_auth_key) < 32) {
	// 仅在非安装路径、非后台路径下阻断
	$_script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
	if(strpos($_script_name, 'install/') === FALSE && strpos($_script_name, 'admin/') === FALSE) {
		die(function_exists('lang') && !empty($_SERVER['lang']) ? lang('auth_key_not_configured') : 'auth_key 未配置或强度不足，请运行安装程序（install/）或在 conf.php 中设置 32 位以上随机密钥。');
	}
}

$ip = ip();
$longip = ip2long($ip);
$longip < 0 AND $longip = sprintf("%u", $longip); // fix 32 位 OS 下溢出的问题
$useragent = _SERVER('HTTP_USER_AGENT');

// 语言包变量
!isset($lang) AND $lang = array();

// 全局的错误，非多线程下很方便。
$errno = 0;
$errstr = '';

// error_handle
// register_shutdown_function('xn_shutdown_handle');
DEBUG AND set_error_handler('error_handle', -1);
empty($conf['timezone']) AND $conf['timezone'] = 'Asia/Shanghai';
date_default_timezone_set($conf['timezone']);

// 超级全局变量
!empty($_SERVER['HTTP_X_REWRITE_URL']) AND $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_REWRITE_URL'];
!isset($_SERVER['REQUEST_URI']) AND $_SERVER['REQUEST_URI'] = '';
$_SERVER['REQUEST_URI'] = str_replace('/index.php?', '/', $_SERVER['REQUEST_URI']); // 兼容 iis6
$_REQUEST = array_merge($_COOKIE, $_POST, $_GET, xn_url_parse($_SERVER['REQUEST_URI']));

// IP 地址
!isset($_SERVER['REMOTE_ADDR']) AND $_SERVER['REMOTE_ADDR'] = '';
!isset($_SERVER['SERVER_ADDR']) AND $_SERVER['SERVER_ADDR'] = '';

// $_SERVER['REQUEST_METHOD'] === 'PUT' ? @parse_str(file_get_contents('php://input', false , null, -1 , $_SERVER['CONTENT_LENGTH']), $_PUT) : $_PUT = array(); // 不需要支持 PUT
$ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower(trim($_SERVER['HTTP_X_REQUESTED_WITH'])) == 'xmlhttprequest') || param('ajax');
$method = $_SERVER['REQUEST_METHOD'];



// 保存到超级全局变量，防止冲突被覆盖。
$_SERVER['starttime'] = $starttime;
$_SERVER['time'] = $time;
$_SERVER['ip'] = $ip;
$_SERVER['longip'] = $longip;
$_SERVER['useragent'] = $useragent;
$_SERVER['conf'] = $conf;
$_SERVER['lang'] = $lang;
$_SERVER['errno'] = $errno;
$_SERVER['errstr'] = $errstr;
$_SERVER['method'] = $method;
$_SERVER['ajax'] = $ajax;
$_SERVER['get_magic_quotes_gpc'] = $get_magic_quotes_gpc;




// 初始化 db cache，这里并没有连接，在获取数据的时候会自动连接。
$db = !empty($conf['db']) ? db_new($conf['db']) : NULL;
//$db AND $db->errno AND xn_message(-1, $db->errstr); // 安装的时候检测过了，不必每次都检测。但是要考虑环境移植。

// 字符集校验：强制 utf8mb4（db_pdo_mysql real_connect 时已 SET NAMES，此处仅校验配置）
if($db && isset($conf['charset']) && $conf['charset'] !== 'utf8mb4') {
	$conf['charset'] = 'utf8mb4';
}

// 缓存初始化通过 CacheService 管理（早期初始化，仅使用 conf 配置）
include APP_PATH.'lib/CacheService.php';
// 加载缓存辅助类（提供 remember/pluginKey/deleteByPrefix 等便捷 API）
include APP_PATH.'lib/CacheHelper.php';
// 加载 AI 调用中台（统一 AI 入口，支持 global/user_key/both 三种模式）
include APP_PATH.'lib/AIService.php';
// 加载轻量事件机制（插件通过 XnEvent::on 注册监听器，核心代码 XnEvent::trigger 触发）
// 零依赖，可在框架启动最早期加载，确保插件在 model_inc_start 等 hook 中即可注册监听器
include APP_PATH.'lib/XnEvent.php';
// 每个请求结束时自动持久化缓存统计，供后台页面读取跨请求累积的命中率
register_shutdown_function(array('CacheHelper', 'persistStats'));
$cache = CacheService::earlyInit();

// 对 key 进行安全保护，Xiuno 专用扩展
!empty($conf) AND (function_exists('xiuno_key') ? ($conf['auth_key'] = xiuno_key()) : NULL);

$_SERVER['db'] = $db;
$_SERVER['cache'] = $cache;

// 全局错误处理器：在框架启动最早期注册，捕获未处理异常/fatal error，避免白屏
// 覆盖 xiunophp 默认的 error_handle，统一由 ErrorHandler 兜底（BizException 200 / 系统 500）
require_once APP_PATH.'lib/ErrorHandler.php';
ErrorHandler::register();

?>
