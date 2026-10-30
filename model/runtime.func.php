<?php

// hook model_runtime_start.php

function runtime_init() {
	// hook model_runtime_init_start.php
	global $conf;
	$runtime = cache_get('runtime'); // 实时运行的数据，初始化！
	// 自愈检测：缓存中统计字段出现负数即说明上游数据不一致，强制重建避免显示负值
	$_need_rebuild = $runtime === NULL || $runtime === FALSE || !isset($runtime['users'])
		|| (isset($runtime['posts']) && $runtime['posts'] < 0)
		|| (isset($runtime['threads']) && $runtime['threads'] < 0)
		|| (isset($runtime['users']) && $runtime['users'] < 0)
		|| (isset($runtime['digests']) && $runtime['digests'] < 0);
	if($_need_rebuild) {
		$runtime = array();
		// 与后台仪表盘口径一致：user_count_real() 精确 COUNT(*) 且排除已注销（匿名化）用户，
		// 避免缓存重建后 users 回弹（注销时 runtime users-1，裸 COUNT 不减）
		$runtime['users'] = function_exists('user_count_real') ? user_count_real() : user_count(array('uid' => array('>' => 0)));
		// 仅统计未软删且已审核通过的帖子，与 thread_create/soft_delete 的统计口径一致
		$runtime['threads'] = thread_count(array('is_deleted'=>0, 'audit_status'=>1));
		// 评论数 = 非首帖且未软删且已审核通过的 post 数，口径与 threads 完全一致，避免相减出现负数
		// ponytail: bbs_post 字段名是 isfirst（无下划线），不是 is_first；用错会触发 1054 Unknown column 'is_first'
		$runtime['posts'] = post_count(array('is_deleted'=>0, 'audit_status'=>1, 'isfirst'=>0));
		// 精华数 = 审核通过且未软删的精华主题数，与 thread_digest 建删/软删/恢复链路的递增递减口径一致；
		// 此前 runtime_init 不初始化该键，缓存重建后 runtime_set('digests+') 会从 0 起算造成低估
		$runtime['digests'] = thread_count(array('digest'=>array('>'=>0), 'audit_status'=>1, 'is_deleted'=>0));
		$runtime['todayusers'] = 0;
		$runtime['todayposts'] = 0;
		$runtime['todaythreads'] = 0;
		$runtime['onlines'] = max(1, online_count());
		$runtime['cron_1_last_date'] = 0;
		$runtime['cron_2_last_date'] = 0;

		cache_set('runtime', $runtime);

	}
	// hook model_runtime_init_end.php
	return $runtime;
}

function runtime_get($k) {
	// hook model_runtime_get_start.php
	global $runtime;
	// hook model_runtime_get_end.php
	return array_value($runtime, $k, NULL);
}

function runtime_set($k, $v) {
	// hook model_runtime_set_start.php
	global $conf, $runtime;
	$op = substr($k, -1);
	if($op == '+' || $op == '-') {
		$k = substr($k, 0, -1);
		!isset($runtime[$k]) AND $runtime[$k] = 0;
		// ponytail: 减法加 max(0, ...) 下限保护，防止并发/脏数据导致 runtime.posts/threads/digests/users 变负数
		// 已知天花板：runtime 仅作缓存显示，理论上不应出现减法超过当前值的情况，出现即说明上游统计已不一致
		$v = $op == '+' ? ($runtime[$k] + $v) : max(0, $runtime[$k] - $v);
	}

	$runtime[$k] = $v;
	return TRUE;
	// hook model_runtime_set_end.php
}

function runtime_delete($k) {
	// hook model_runtime_delete_start.php
	global $conf, $runtime;
	unset($runtime[$k]);
	runtime_save();
	return TRUE;
	// hook model_runtime_delete_end.php
}

function runtime_save() {
	// hook model_runtime_save_start.php
	global $runtime;
	
	function_exists('chdir') AND chdir(APP_PATH);
	
	$r = cache_set('runtime', $runtime);
	
	// hook model_runtime_save_end.php
}

function runtime_truncate() {
	// hook model_runtime_truncate_start.php
	global $conf;
	cache_delete('runtime');
	// hook model_runtime_truncate_end.php
}

register_shutdown_function('runtime_save');

// hook model_runtime_end.php

?>