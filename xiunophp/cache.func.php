<?php

function cache_new($cacheconf) {
	// 缓存初始化，这里并不会产生连接！在真正使用的时候才连接。
	// 这里采用最笨拙的方式而不采用 new $classname 的方式，有利于 opcode 缓存。
	if($cacheconf && !empty($cacheconf['enable']) && isset($cacheconf['type'])) {
		switch ($cacheconf['type']) {
			case 'file':      $cache = new cache_file($cacheconf['file']);           break;
			case 'redis':     $cache = new cache_redis($cacheconf['redis']);          break;
			case 'memcached': $cache = new cache_memcached($cacheconf['memcached']);  break;
			case 'pdo_mysql':
			case 'mysql':
				$cache = new cache_mysql($cacheconf['mysql']); break;
			default: return xn_error(-1, (function_exists('lang') && !empty($_SERVER['lang']) ? lang('cache_type_unsupported') : '不支持的 cache type:').$cacheconf['type']);
		}
		return $cache;
	}
	return NULL;
}

// md5 阈值从 32 放宽到 200：允许更长的明文键名，便于前缀删除和调试
// 超过 200 字符的键名仍会 md5，但实际业务中极少超过
if(!defined('CACHE_KEY_MD5_THRESHOLD')) {
	define('CACHE_KEY_MD5_THRESHOLD', 200);
}

function cache_get($k, $c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return FALSE;

	strlen($k) > CACHE_KEY_MD5_THRESHOLD AND $k = md5($k);

	$k = $c->cachepre.$k;
	$r = $c->get($k);
	return $r;
}

function cache_set($k, $v, $life = 0, $c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return FALSE;

	strlen($k) > CACHE_KEY_MD5_THRESHOLD AND $k = md5($k);

	$k = $c->cachepre.$k;
	$r = $c->set($k, $v, $life);
	return $r;
}

function cache_delete($k, $c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return FALSE;

	strlen($k) > CACHE_KEY_MD5_THRESHOLD AND $k = md5($k);

	$k = $c->cachepre.$k;
	$r = $c->delete($k);
	return $r;
}

/**
 * 按前缀删除缓存（通配符删除）
 * 用于清除某类数据的所有缓存，如 'p_checkin_' 会删除 p_checkin_rank_total 等
 * Redis 驱动用 SCAN 安全删除，其他驱动依赖注册表逐个删除
 *
 * @param string $prefix 键名前缀（不含 cachepre）
 * @return int 删除的键数量
 */
function cache_delete_prefix($prefix, $c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return 0;

	// Redis 驱动有原生的 deleteByPrefix 方法
	if(method_exists($c, 'deleteByPrefix')) {
		return $c->deleteByPrefix($prefix);
	}

	// 其他驱动：无法做通配符删除，返回 0
	// 如需批量删除，请使用 CacheHelper::deleteByPrefix() 依赖注册表
	return 0;
}

// 尽量避免调用此方法，不会清理保存在 kv 中的数据，逐条 cache_delete() 比较保险
function cache_truncate($c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return FALSE;

	//$k = $c->cachepre.$k;
	$r = $c->truncate();
	return $r;
}

?>