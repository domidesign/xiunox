<?php

!defined('DEBUG') AND exit('Forbidden');

// 可以合并成一个文件，加快速度
// merge to one file.

// hook model_inc_start.php

// 服务注册表（核心服务类直接 include，绕过 tmp 缓存）
// 使用 include_once 防止与 index.inc.php 重复加载导致类重声明
include_once APP_PATH.'lib/ServiceRegistry.php';

// AIService 按需加载兜底（正常已在 xiunophp.php 中 include，此处防止特殊场景未加载）
if (!class_exists('AIService')) {
	include_once APP_PATH.'lib/AIService.php';
}

$include_model_files = array (
	APP_PATH.'model/kv.func.php',
	APP_PATH.'model/queue.func.php',
	APP_PATH.'model/group.func.php',
	APP_PATH.'model/user.func.php',
	APP_PATH.'model/forum.func.php',
	APP_PATH.'model/forum_access.func.php',
	APP_PATH.'model/thread.func.php',
	APP_PATH.'model/thread_top.func.php',
	APP_PATH.'model/thread_digest.func.php',
	APP_PATH.'model/post.func.php',
	APP_PATH.'model/attach.func.php',
	APP_PATH.'model/check.func.php',
	APP_PATH.'model/mythread.func.php',
	APP_PATH.'model/thread_favorite.func.php',
	APP_PATH.'model/user_follow.func.php',
	APP_PATH.'model/forum_follow.func.php',
	APP_PATH.'model/post_like.func.php',
	APP_PATH.'model/notify.func.php',
	APP_PATH.'model/plugin_notify.func.php',
	APP_PATH.'model/runtime.func.php',
	APP_PATH.'model/table_day.func.php',
	APP_PATH.'model/cron.func.php',
	APP_PATH.'model/form.func.php',
	APP_PATH.'model/misc.func.php',
	APP_PATH.'model/route.func.php',
	APP_PATH.'model/session.func.php',
	APP_PATH.'model/user_profile_audit.func.php',
	APP_PATH.'model/admin_log.func.php',

	// hook model_inc_file.php
	
);

// hook model_inc_include_before.php

foreach ($include_model_files as $model_files) {
	include _include($model_files);
}

// hook model_inc_end.php

// 缓存完整初始化（setting_get 可用后，用后台配置重新初始化缓存驱动）
if(class_exists('CacheService', false)) {
    try {
        CacheService::init();
    } catch(\Throwable $e) {
        // 缓存初始化失败时降级到 MySQL 缓存，确保系统可用
        error_log('CacheService::init() 异常，降级到 MySQL 缓存：' . $e->getMessage());
        try {
            global $db;
            if(is_object($db)) {
                // 用 ServiceRegistry 统一注册，内部自动同步 $_SERVER 兼容旧代码
                ServiceRegistry::set('cache', new cache_mysql(array('db' => $db, 'cachepre' => 'bbs_')));
            } else {
                ServiceRegistry::set('cache', NULL);
            }
        } catch(\Throwable $e2) {
            ServiceRegistry::set('cache', NULL);
        }
    }
}









/*
function xn_php_strip_whitespace($file) {
	$s = php_strip_whitespace($file);
	if(substr($s, 0, 5) == '<?php') {
		$s = substr($s, 5);
	}
	if(substr($s, -2) == '?>') {
		$s = substr($s, 0, -2);
	}
	return $s;
}*/

?>