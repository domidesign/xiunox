<?php

// 插件通知聚合中心（Plugin Notify Hub）
// 统一三通道门面：站内消息 + 邮件（委托 AdminNotifyService，复用其防抖/SMTP/管理员查询/绝对URL）
// + 后台红点（拉取式 hook 聚合 + KV 缓存单键，TTL 自愈）
// 插件接入文档：docs/plugin-notify-guide.md

// ================= 通道配置（setting 存储） =================

/**
 * 读取全部插件通知配置
 * 结构：array('_global' => array('email_to'=>''), '{dir}' => array('system'=>1,'email'=>1,'badge'=>1,'email_to'=>''))
 */
function plugin_notify_config_all() {
	$conf = function_exists('setting_get') ? setting_get('plugin_notify_config') : NULL;
	return is_array($conf) ? $conf : array();
}

/**
 * 读取全局默认配置（提醒邮箱）
 */
function plugin_notify_config_global() {
	$all = plugin_notify_config_all();
	$g = isset($all['_global']) && is_array($all['_global']) ? $all['_global'] : array();
	return array('email_to' => isset($g['email_to']) ? $g['email_to'] : '');
}

/**
 * 读取某插件通道配置（带默认值兜底：未配置=三通道全开）
 * @return array array('system'=>int, 'email'=>int, 'badge'=>int, 'email_to'=>string)
 */
function plugin_notify_config_get($plugin) {
	$all = plugin_notify_config_all();
	$item = isset($all[$plugin]) && is_array($all[$plugin]) ? $all[$plugin] : array();
	return array(
		'system' => isset($item['system']) ? intval($item['system']) : 1,
		'email' => isset($item['email']) ? intval($item['email']) : 1,
		'badge' => isset($item['badge']) ? intval($item['badge']) : 1,
		'email_to' => isset($item['email_to']) ? strval($item['email_to']) : '',
	);
}

/**
 * 保存配置（合并式，只覆盖传入字段；$plugin='_global' 存全局默认）
 */
function plugin_notify_config_save($plugin, $arr) {
	if(!is_array($arr)) return FALSE;
	$all = plugin_notify_config_all();
	$base = isset($all[$plugin]) && is_array($all[$plugin]) ? $all[$plugin] : array();
	$all[$plugin] = array_merge($base, $arr);
	return setting_set('plugin_notify_config', $all);
}

/**
 * 删除某插件配置（插件卸载时调用，防设置页残留）
 */
function plugin_notify_config_delete($plugin) {
	$all = plugin_notify_config_all();
	if(isset($all[$plugin])) {
		unset($all[$plugin]);
		setting_set('plugin_notify_config', $all);
	}
	return TRUE;
}

// ================= 红点聚合（拉取式 + 缓存单键） =================

/**
 * 获取所有接入插件的待处理计数
 * 聚合缓存单键 core_plugin_notice（TTL 60s，后台 TTL 配置页可调）；
 * 未命中时遍历已启用插件，对存在 hook/plugin_notice_count.php 的插件逐个执行收集。
 *
 * hook 协议：hook 文件内写回 $data['count']（int 绝对数量，查库得出）与
 * $data['url']（后台待处理页地址）；count<=0 视为无待处理。
 *
 * 单插件报错被 try/catch 隔离（该插件计 0，不影响其他插件）；
 * 插件禁用/卸载后 hook 不再被收集，TTL 过期红点自然消失（自愈）。
 *
 * @return array array('{dir}' => array('count'=>int, 'url'=>string))
 */
function plugin_notice_count_all() {
	return CacheHelper::remember('plugin_notice', 60, function() {
		$result = array();
		if(!function_exists('plugin_paths_enabled')) return $result;
		$plugin_paths = plugin_paths_enabled();
		if(empty($plugin_paths) || !is_array($plugin_paths)) return $result;
		foreach($plugin_paths as $path => $pconf) {
			$dir = file_name($path);
			if(!$dir || !is_file(APP_PATH."plugin/$dir/hook/plugin_notice_count.php")) continue;
			// badge 通道被管理员关闭的插件不参与红点
			$config = plugin_notify_config_get($dir);
			if(empty($config['badge'])) continue;
			$data = array('count' => 0, 'url' => '');
			try {
				$t = file_get_contents(APP_PATH."plugin/$dir/hook/plugin_notice_count.php");
				if($t === FALSE) continue;
				// 剥离防直接访问头后 eval（与 plugin_hook 的处理一致）；
				// 逐插件隔离执行而非共用一次 plugin_hook 调用，保证 count/url 归属正确不互相覆盖
				if(preg_match('#^\s*<\?php\s+exit;#is', $t)) {
					$t = preg_replace('#^\s*<\?php\s*exit;#is', '', $t);
					$t = preg_replace('#\?>\s*$#', '', $t);
				} elseif(preg_match('#^\s*<\?php#is', $t)) {
					$t = preg_replace('#^\s*<\?php\s*#', '', $t);
					$t = preg_replace('#\?>\s*$#', '', $t);
				}
				eval($t);
				$count = isset($data['count']) ? intval($data['count']) : 0;
				$url = isset($data['url']) ? strval($data['url']) : '';
				if($count > 0) $result[$dir] = array('count' => $count, 'url' => $url);
			} catch(\Throwable $e) {
				// 单插件报错隔离：该插件计 0，不影响其他插件
				if(function_exists('xn_log')) {
					xn_log("plugin_notice_count error in $dir: ".$e->getMessage(), 'plugin_error');
				}
			}
		}
		return $result;
	});
}

/**
 * 所有插件待处理总数（侧边栏聚合徽章用）
 */
function plugin_notice_total() {
	$total = 0;
	foreach(plugin_notice_count_all() as $item) {
		$total += isset($item['count']) ? intval($item['count']) : 0;
	}
	return $total;
}

/**
 * 主动失效红点聚合缓存（下次后台加载时重建；事件发生/审核处理后调用）
 */
function plugin_notice_flush() {
	return CacheHelper::delete('core_plugin_notice');
}

// ================= 统一事件门面（三通道一次接入） =================

/**
 * 插件统一通知门面：一次调用按配置分发到 站内消息 / 邮件 / 红点 三通道
 *
 * 站内消息 + 邮件委托 AdminNotifyService::audit()（复用其管理员查询 gid IN (1,2) ban_type=0、
 * SMTP 配置、绝对 URL 转换、邮件错误日志），叠加统一通知配置的通道开关。
 * 红点通道直接失效聚合缓存（拉取式数据源独立于本函数，由各插件 hook 提供）。
 *
 * @param string $plugin 插件目录名（如 'xnx_verify'）
 * @param string $event  事件名（如 'new_pending'，同时用作节流键与 AdminNotifyService 防抖类型）
 * @param array  $payload array(
 *   'title'    => string 标题（站内消息摘要与邮件主题默认值）,
 *   'content'  => string 正文（纯文本摘要，站内消息与邮件正文）,
 *   'url'      => string 跳转链接（三通道共用，相对路径会自动转绝对）,
 *   'uid'/'uids' => int/array 站内消息接收者（缺省=所有 gid 1,2 管理员）,
 *   'email_to' => string 收件邮箱覆盖（支持逗号分隔多个；解析顺序：payload > 插件配置 > 全局默认,
 *                  都为空时回退 AdminNotifyService 自身兜底链：插件自有 admin_notify_emails > 管理员账号邮箱）,
 *   'channels' => array 限定通道（如 array('system','badge')；缺省=全部启用通道）,
 *   'badge_flush' => bool 默认 true：事件后失效红点缓存,
 *   'throttle' => int 同 plugin+event 节流秒数，默认 0 不节流（高频事件建议 300-1800）,
 * )
 * @return array 各通道执行结果 array('system'=>..., 'email'=>..., 'badge'=>...)
 *   值为 'off'（通道关闭）/ 'skipped'（未发出）/ 'sent:N' / 'throttled' / 'error:xxx' / 'flushed'
 */
function plugin_notify_fire($plugin, $event, $payload = array()) {
	$result = array('system' => 'off', 'email' => 'off', 'badge' => 'off');
	if(!is_array($payload)) $payload = array();

	$title = isset($payload['title']) ? trim(strval($payload['title'])) : '';
	$content = isset($payload['content']) ? trim(strval($payload['content'])) : '';
	$url = isset($payload['url']) ? strval($payload['url']) : '';
	if($title === '' && $content === '') return $result;

	$channels = isset($payload['channels']) && is_array($payload['channels']) && !empty($payload['channels'])
		? $payload['channels'] : array('system', 'email', 'badge');
	$config = plugin_notify_config_get($plugin);
	$throttle = isset($payload['throttle']) ? intval($payload['throttle']) : 0;

	// ---- 红点通道：失效聚合缓存（下次后台加载重建） ----
	if(in_array('badge', $channels) && !empty($config['badge'])) {
		if(!isset($payload['badge_flush']) || $payload['badge_flush'] !== FALSE) {
			plugin_notice_flush();
		}
		$result['badge'] = 'flushed';
	}

	// ---- 站内消息 + 邮件通道 ----
	$want_notify = in_array('system', $channels) && !empty($config['system']);
	$want_mail = in_array('email', $channels) && !empty($config['email']);

	// 兼容规则：插件自身配置中显式 admin_notify_enabled=0（AdminNotifyService 旧开关）时推送通道整体关闭，
	// 尊重存量站点的既有设置；新接入插件不设该键，走统一通知配置（默认全开）。红点不受此键影响。
	if($want_notify || $want_mail) {
		$plugin_cfg = function_exists('setting_get') ? setting_get($plugin) : NULL;
		if(is_array($plugin_cfg) && isset($plugin_cfg['admin_notify_enabled']) && intval($plugin_cfg['admin_notify_enabled']) === 0) {
			$want_notify = $want_mail = FALSE;
		}
	}
	if(!$want_notify && !$want_mail) return $result;

	// throttle：同 plugin+event 秒数内只推一次（自行实现，覆盖 AdminNotifyService 内置防抖）
	$throttle_key = '';
	if($throttle > 0) {
		$event_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', strval($event));
		$throttle_key = substr('core_plugin_notify_throttle_'.$plugin.'_'.$event_safe, 0, 255);
		if(function_exists('cache_get')) {
			$hit = cache_get($throttle_key);
			// 兼容 MySQL 驱动（不存在返回 FALSE）与 Redis 驱动（返回 NULL）两种未命中语义
			if($hit !== FALSE && $hit !== NULL) {
				$result['system'] = $result['email'] = 'throttled';
				return $result;
			}
		}
	}

	if(!class_exists('AdminNotifyService')) {
		include_once APP_PATH.'lib/AdminNotifyService.php';
	}
	if(!class_exists('AdminNotifyService')) {
		$result['system'] = $result['email'] = 'error:AdminNotifyService unavailable';
		return $result;
	}

	// 邮箱解析链：payload > 统一插件配置 > 统一全局默认（支持逗号/换行分隔多个）
	// 都未配置时不传 admin_emails，回退 AdminNotifyService 兜底链（插件自有 admin_notify_emails > 管理员账号邮箱）
	$email_sources = array();
	if(!empty($payload['email_to'])) $email_sources[] = $payload['email_to'];
	if(!empty($config['email_to'])) $email_sources[] = $config['email_to'];
	$global_cfg = plugin_notify_config_global();
	if(!empty($global_cfg['email_to'])) $email_sources[] = $global_cfg['email_to'];
	$admin_emails = array();
	foreach($email_sources as $src) {
		$raw = preg_split('/[\s,;]+/', trim(strval($src)));
		if(!is_array($raw)) continue;
		foreach($raw as $email) {
			$email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
			if($email !== FALSE) $admin_emails[$email] = $email;
		}
	}

	$options = array(
		'ignore_enabled' => TRUE,          // 统一配置已在上面把关，插件自身 admin_notify_enabled 不再拦截（兼容规则已单独处理）
		'skip_notify' => !$want_notify,
		'skip_mail' => !$want_mail,
		'skip_debounce' => TRUE,           // 节流统一由 throttle 参数管理（默认不节流）
	);
	if(!empty($payload['uid']) || !empty($payload['uids'])) {
		// 指定接收者（缺省由 AdminNotifyService 自动查 gid 1,2 管理员）
		$uids = array();
		if(!empty($payload['uids']) && is_array($payload['uids'])) {
			foreach($payload['uids'] as $u) { $u = intval($u); if($u > 0) $uids[$u] = $u; }
		} elseif(!empty($payload['uid'])) {
			$u = intval($payload['uid']); if($u > 0) $uids[$u] = $u;
		}
		if(!empty($uids)) $options['admin_uids'] = array_values($uids);
	}
	if(!empty($admin_emails)) $options['admin_emails'] = array_values($admin_emails);

	$subject = $title !== '' ? $title : $content;
	try {
		$ret = AdminNotifyService::audit($plugin, strval($event), $subject, $content, $url, $options);
	} catch(\Throwable $e) {
		if(function_exists('xn_log')) {
			xn_log("plugin_notify_fire error: $plugin/$event ".$e->getMessage(), 'plugin_error');
		}
		$result['system'] = $result['email'] = 'error:'.$e->getMessage();
		return $result;
	}

	$result['system'] = $want_notify ? (isset($ret['sent_notify']) && $ret['sent_notify'] > 0 ? 'sent:'.$ret['sent_notify'] : 'skipped') : 'off';
	$result['email'] = $want_mail ? (isset($ret['sent_mail']) && $ret['sent_mail'] > 0 ? 'sent:'.$ret['sent_mail'] : 'skipped') : 'off';
	if(empty($ret['ok']) && !empty($ret['reason'])) {
		$result['email'] .= '('.$ret['reason'].')';
	}

	// throttle 标记在推送实际发出后才写（失败不写，允许下次重试）
	if($throttle > 0 && $throttle_key !== '' && function_exists('cache_set')) {
		$sent_any = (isset($ret['sent_notify']) && $ret['sent_notify'] > 0) || (isset($ret['sent_mail']) && $ret['sent_mail'] > 0);
		if($sent_any) cache_set($throttle_key, 1, $throttle);
	}

	return $result;
}

?>
