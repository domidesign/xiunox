<?php

// 如果环境支持，可以直接改为 redis get() set() 持久存储相关 API，提高速度。


// 无缓存
function kv__get($k) {
	$arr = db_find_one('kv', array('k'=>$k));
	return $arr ? xn_json_decode($arr['v']) : NULL;
}
function kv_get($k) {
	global $g_kv_cache;
	if(!isset($g_kv_cache)) $g_kv_cache = array();
	strlen($k) > 32 AND $k = md5($k);
	if(!isset($g_kv_cache[$k])) {
		$g_kv_cache[$k] = kv__get($k);
	}
	return $g_kv_cache[$k];
}
function kv_set($k, $v, $life = 0) {
	global $g_kv_cache;
	strlen($k) > 32 AND $k = md5($k);
	$arr = array(
		'k'=>$k,
		'v'=>xn_json_encode($v),
	);
	$r = db_replace('kv', $arr);
	// 同步更新内存缓存，避免同请求内读取到旧值
	$g_kv_cache[$k] = $v;
	return $r;
}
function kv_delete($k) {
	global $g_kv_cache;
	strlen($k) > 32 AND $k = md5($k);
	$r = db_delete('kv', array('k'=>$k));
	// 同步清除内存缓存
	unset($g_kv_cache[$k]);
	return $r;
}



// --------------------> kv + cache
function kv_cache_get($k) {
	$r = cache_get($k);
	// cache_get 返回 NULL（未找到）或 FALSE（出错/未初始化）时，都应回退到数据库
	if($r === NULL || $r === FALSE) {
		$r = kv_get($k);
	}
	return $r;
}
function kv_cache_set($k, $v, $life = 0) {
	// 先写数据库，确保数据持久化
	$r = kv_set($k, $v);
	// 再更新缓存；若缓存写入失败，删除旧缓存避免下次读到过期值
	if(!cache_set($k, $v, $life)) {
		cache_delete($k);
	}
	return $r;
}
function kv_cache_delete($k) {
	cache_delete($k);
	$r = kv_delete($k);
	return $r;
}



// ------------> kv + cache + setting
$g_setting = FALSE;
function setting_get($k) {
	global $g_setting;
	$g_setting === FALSE AND $g_setting = kv_cache_get('setting', $g_setting);
	empty($g_setting) AND $g_setting = array();
	return array_value($g_setting, $k, NULL);
}
// 全站的设置，全局变量 $g_setting = array();
function setting_set($k, $v) {
	global $g_setting;
	$g_setting === FALSE AND $g_setting = kv_cache_get('setting', $g_setting);
	empty($g_setting) AND $g_setting = array();
	$g_setting[$k] = $v;
	return kv_cache_set('setting', $g_setting);
}
function setting_delete($k) {
	global $g_setting;
	$g_setting === FALSE AND $g_setting = kv_cache_get('setting', $g_setting);
	empty($g_setting) AND $g_setting = array();
	if(isset($g_setting[$k])) unset($g_setting[$k]);
	kv_cache_set('setting', $g_setting);
	return TRUE;
}

?>