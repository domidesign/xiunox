<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'doc');

// hook admin_api_start.php

// ponytail: api_log 未开启时从 API 菜单 tab 中隐藏「日志」项，与各页面顶部按钮的条件显示逻辑一致
if(empty($conf['api_log']) && isset($menu['api']['tab']['log'])) {
	unset($menu['api']['tab']['log']);
}

// ponytail: 统一设置 API 模块各页面的标题（同时用于 <title> 和面包屑导航）
$_api_titles = array(
	'doc'      => lang('admin_api_doc'),
	'settings' => lang('admin_api_settings'),
	'debug'    => lang('admin_api_debug'),
	'log'      => lang('admin_api_log_title'),
);
if(isset($_api_titles[$action])) {
	$header['title'] = $_api_titles[$action];
	$header['mobile_title'] = $_api_titles[$action];
}
unset($_api_titles);

/**
 * 从请求中构建应用 capabilities JSON 字符串
 * 安全限制：gid != 1 时强制关闭 skip_captcha/skip_audit
 * @param int $gid 当前用户组ID
 * @return string JSON 字符串
 */
function xn_build_app_capabilities($gid = 0) {
	$skipCaptcha = param('skip_captcha', 0) == 1 ? 1 : 0;
	$skipAudit = param('skip_audit', 0) == 1 ? 1 : 0;
	$skipRateLimit = param('skip_rate_limit', 0) == 1 ? 1 : 0;
	$allowedResources = param('allowed_resources', []);
	$deniedEndpoints = param('denied_endpoints', '', false);

	// 安全限制：skip_audit/skip_captcha 仅 gid=1 可启用
	if(intval($gid) !== 1) {
		$skipCaptcha = 0;
		$skipAudit = 0;
	}

	// ponytail: scope=full 时清空 allowed_resources（避免 scope=full 仍被白名单拦截，与 UI 联动一致）
	$scope = param('scope', 'readonly');
	if($scope === 'full') {
		$allowedResources = [];
	}

	$capabilities = [
		'skip_captcha' => $skipCaptcha,
		'skip_audit' => $skipAudit,
		'skip_rate_limit' => $skipRateLimit,
		'allowed_resources' => is_array($allowedResources) ? $allowedResources : [],
		'denied_endpoints' => array_filter(array_map('trim', explode("\n", $deniedEndpoints))),
	];
	return json_encode($capabilities, JSON_UNESCAPED_UNICODE);
}

/**
 * 从请求中构建应用 IP 白名单 JSON 数组字符串
 * @return string JSON 数组字符串
 */
function xn_build_app_ip_whitelist() {
	$ipWhitelist = param('ip_whitelist', '', false);
	$ipList = array_filter(array_map('trim', explode("\n", $ipWhitelist)));
	return json_encode($ipList, JSON_UNESCAPED_UNICODE);
}

if($action == 'doc') {

	// hook admin_api_doc_start.php

	include APP_PATH . 'lib/ApiDocService.php';
	$apiGroups = ApiDocService::getEndpoints();
	$errorCodes = ApiDocService::getErrorCodes();
	$baseUrl = rtrim(str_replace('/admin/', '/', http_url_path()), '/') . '/';
	$tokenExpire = $conf['api_token_expire'] ?? 30;
	$accessTokenExpire = intval($conf['api_access_token_expire'] ?? 2);
	$tokenAbsoluteExpire = intval($conf['api_token_absolute_expire'] ?? 90);

	include _include(ADMIN_PATH.'view/htm/api_doc.htm');

} elseif($action == 'debug') {

	// hook admin_api_debug_start.php

	if($method == 'GET') {

		$tokens = $db->find('api_token', [], ['id' => -1], 1, 50, 'id');
		$tokenCount = $db->count('api_token');
		// ponytail: 调试页需选 API 应用携带 X-App-Id（bootstrap.php 中间件强制要求）；
		// secret 是 password_hash 存储无法反查，仅传 appid/scope/name 供下拉框展示
		$apps = $db->find('api_app', ['is_enabled' => 1], ['id' => -1], 1, 100, 'id');
		include _include(ADMIN_PATH.'view/htm/api_debug.htm');

	} elseif($method == 'POST') {

		CsrfService::check();

		$op = param('op');

		if($op == 'generate_token') {
			$uid = param('uid', 0);
			if($uid <= 0) message(-1, lang('uid_error'));
			$user = $db->findOne('user', ['uid' => $uid]);
			if(!$user) message(-1, lang('user_not_exists'));

			include APP_PATH . 'lib/ApiAuthService.php';
			$apiAuth = new ApiAuthService($db, intval($conf['api_token_expire'] ?? 30), intval($conf['api_access_token_expire'] ?? 2), intval($conf['api_token_absolute_expire'] ?? 90));
			$tokenData = $apiAuth->generateTokens($uid);
		message(0, $tokenData);

		} elseif($op == 'revoke_token') {
			$token = param('token', '');
			if(empty($token)) message(-1, 'Token is required');

			include APP_PATH . 'lib/ApiAuthService.php';
			$apiAuth = new ApiAuthService($db);
			$apiAuth->revokeTokens($token);
			message(0, lang('delete_successfully'));

		} elseif($op == 'revoke_token_by_id') {
			// ponytail: 按 refresh token 主键 id 删除整对 access+refresh
			// revokeTokens() 接收明文 token，但调试页表格只有 hash，无法调用
			$id = param('id', 0);
			if($id <= 0) message(-1, 'ID error');
			$row = $db->findOne('api_token', ['id' => $id, 'type' => 'refresh']);
			if(!$row) message(-1, lang('record_not_exists'));
			// 删除 refresh 行 + 关联的 access 行（access.related_id 指向 refresh.id）
			$db->delete('api_token', ['id' => $id]);
			if(!empty($row['related_id'])) {
				$db->delete('api_token', ['id' => $row['related_id']]);
			}
			message(0, lang('delete_successfully'));

		} elseif($op == 'test_api') {
			$endpoint = param('endpoint', '');
			$method_type = param('method_type', 'GET');
			$token = param('token', '');
			$body = param('body', '', false);

			if(empty($endpoint)) message(-1, 'Endpoint is required');

			$baseUrl = rtrim(str_replace('/admin/', '/', http_url_path()), '/') . '/';
			$url = $baseUrl . 'api/v1/' . ltrim($endpoint, '/');

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method_type);

			$headers = ['Accept: application/json'];
			if(!empty($token)) {
				$headers[] = 'Authorization: Bearer ' . $token;
			}
			if($method_type === 'POST' || $method_type === 'PUT') {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
				$headers[] = 'Content-Type: application/json';
			}
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			// PHP 8.0+ curl 句柄自动释放，curl_close() 在 8.5 已废弃
			if (PHP_VERSION_ID < 80000) curl_close($ch);

			$result = [
				'http_code' => $httpCode,
				'response' => $response ? json_decode($response, true) : null,
				'raw' => $response,
				'error' => $error,
				'url' => $url,
			];
			message(0, $result);
		}

	} else {
		message(-1, 'Method not allowed');
	}

} elseif($action == 'log') {

	// hook admin_api_log_start.php

	// ponytail: api_log 未开启时直接跳到设置页，避免空表查询
	if(empty($conf['api_log'])) {
		http_location(admin_api_settings_url());
	}

	// ponytail: 老版本 UpgradeService 创建 api_log 表漏了 appid 字段，bootstrap.php 写日志静默失败
	// 首次访问日志页时幂等补字段（用 setting kv 标记避免每次访问都查 INFORMATION_SCHEMA）
	if(!setting_get('api_log_appid_col_checked')) {
		$_col = $db->sqlFindOne("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$db->tablepre}api_log' AND COLUMN_NAME = 'appid'");
		if(empty($_col)) {
			$db->exec("ALTER TABLE `{$db->tablepre}api_log` ADD COLUMN `appid` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '应用ID' AFTER `ip`, ADD INDEX `appid` (`appid`)");
		}
		setting_set('api_log_appid_col_checked', 1);
	}

	$page = param(2, 1);
	$pagesize = 50;

	// 过滤条件（从 GET 取，URL 形式 api-log-{page}?resource=thread&method=GET&uid=1&appid=xxx）
	$srch_resource = param('resource', '');
	$srch_method   = param('method', '');
	$srch_uid      = param('uid', 0);
	$srch_appid    = param('appid', '');

	$cond = [];
	if($srch_resource !== '') $cond['resource'] = $srch_resource;
	if($srch_method !== '')   $cond['method']   = $srch_method;
	if($srch_uid > 0)         $cond['uid']      = intval($srch_uid);
	if($srch_appid !== '')    $cond['appid']    = $srch_appid;

	$n = $db->count('api_log', $cond);
	$logs = $db->find('api_log', $cond, ['id' => -1], $page, $pagesize, 'id');
	if(!is_array($logs)) $logs = [];

	// 批量查用户名（避免 N+1）
	// ponytail: user_find_by_uids() 第578行 trim() 期望字符串"1,2,3"，不能传数组
	$uids = [];
	foreach($logs as $_l) {
		if(!empty($_l['uid'])) $uids[$_l['uid']] = $_l['uid'];
	}
	$users = [];
	if($uids) {
		$_users = user_find_by_uids(implode(',', array_keys($uids)));
		if(is_array($_users)) {
			foreach($_users as $_u) $users[$_u['uid']] = $_u;
		}
	}

	// 拼接过滤查询串（分页链接保留过滤条件）
	$flt_q = [];
	if($srch_resource !== '') $flt_q['resource'] = $srch_resource;
	if($srch_method !== '')   $flt_q['method']   = $srch_method;
	if($srch_uid > 0)         $flt_q['uid']      = $srch_uid;
	if($srch_appid !== '')    $flt_q['appid']    = $srch_appid;
	$flt_qs = $flt_q ? '&' . http_build_query($flt_q) : '';
	$pagination = pagination(url("api-log-{page}") . $flt_qs, $n, $page, $pagesize);

	$header['title'] = lang('admin_api_log_title');
	$header['mobile_title'] = lang('admin_api_log_title');

	include _include(ADMIN_PATH.'view/htm/api_log.htm');

} elseif($action == 'token_delete') {

	CsrfService::check();
	$id = param(2, 0);
	if($id <= 0) message(-1, 'ID error');
	$db->delete('api_token', ['id' => $id]);
	message(0, lang('delete_successfully'));

} elseif($action == 'settings') {

	// hook admin_api_settings_start.php

	include APP_PATH . 'lib/ApiAuthService.php';
	$apiAuthService = new ApiAuthService($db, intval($conf['api_token_expire'] ?? 30), intval($conf['api_access_token_expire'] ?? 2), intval($conf['api_token_absolute_expire'] ?? 90));

	if($method == 'GET') {
		$apps = $apiAuthService->listApps();
		$api_enabled = intval($conf['api_enabled'] ?? 1);
		$api_log = intval($conf['api_log'] ?? 0);
		$api_rate_limit = intval($conf['api_rate_limit'] ?? 1);
		$api_rate_limit_max = intval($conf['api_rate_limit_max'] ?? 60);
		$api_rate_limit_window = intval($conf['api_rate_limit_window'] ?? 60);
		$api_cors_origin = $conf['api_cors_origin'] ?? '*';
		$api_token_expire = intval($conf['api_token_expire'] ?? 30);
		$api_access_token_expire = intval($conf['api_access_token_expire'] ?? 2);
		$api_token_absolute_expire = intval($conf['api_token_absolute_expire'] ?? 90);
		include _include(ADMIN_PATH.'view/htm/api_settings.htm');
	}

} elseif($action == 'app_create') {

	CsrfService::check();
	if($method != 'POST') message(-1, 'Method not allowed');

	include APP_PATH . 'lib/ApiAuthService.php';
	$apiAuthService = new ApiAuthService($db, intval($conf['api_token_expire'] ?? 30), intval($conf['api_access_token_expire'] ?? 2), intval($conf['api_token_absolute_expire'] ?? 90));

	$name = param('name', '');
	$description = param('description', '');
	$scope = param('scope', 'readonly');
	$rate_limit = param('rate_limit', 120);

	if(empty($name)) message(-1, '应用名称不能为空');

	// 接收 capabilities 字段
	$capabilitiesJson = xn_build_app_capabilities($gid ?? 0);
	$ipWhitelistJson = xn_build_app_ip_whitelist();

	$app = $apiAuthService->createApp($name, $description, $scope, $uid, $capabilitiesJson, $ipWhitelistJson);
	message(0, $app);

} elseif($action == 'app_update') {

	CsrfService::check();
	if($method != 'POST') message(-1, 'Method not allowed');

	include APP_PATH . 'lib/ApiAuthService.php';
	$apiAuthService = new ApiAuthService($db, intval($conf['api_token_expire'] ?? 30), intval($conf['api_access_token_expire'] ?? 2), intval($conf['api_token_absolute_expire'] ?? 90));

	$id = param('id', 0);
	if($id <= 0) message(-1, '应用ID无效');

	$data = [];
	$name = param('name', '');
	if(!empty($name)) $data['name'] = $name;
	$description = param('description', '');
	$data['description'] = $description;
	$scope = param('scope', '');
	if(!empty($scope)) $data['scope'] = $scope;
	$is_enabled = param('is_enabled', -1);
	if($is_enabled !== -1) $data['is_enabled'] = intval($is_enabled);
	$rate_limit = param('rate_limit', -1);
	if($rate_limit !== -1) $data['rate_limit'] = intval($rate_limit);

	// 接收 capabilities 字段
	$data['capabilities'] = xn_build_app_capabilities($gid ?? 0);
	$data['ip_whitelist'] = xn_build_app_ip_whitelist();

	$ok = $apiAuthService->updateApp($id, $data);
	$ok ? message(0, lang('update_successfully')) : message(-1, lang('update_failed'));

} elseif($action == 'app_delete') {

	CsrfService::check();
	if($method != 'POST') message(-1, 'Method not allowed');

	include APP_PATH . 'lib/ApiAuthService.php';
	$apiAuthService = new ApiAuthService($db, intval($conf['api_token_expire'] ?? 30), intval($conf['api_access_token_expire'] ?? 2), intval($conf['api_token_absolute_expire'] ?? 90));

	$id = param('id', 0);
	if($id <= 0) message(-1, '应用ID无效');

	$ok = $apiAuthService->deleteApp($id);
	$ok ? message(0, lang('delete_successfully')) : message(-1, lang('delete_failed'));

} elseif($action == 'app_reset_secret') {

	CsrfService::check();
	if($method != 'POST') message(-1, 'Method not allowed');

	include APP_PATH . 'lib/ApiAuthService.php';
	$apiAuthService = new ApiAuthService($db, intval($conf['api_token_expire'] ?? 30), intval($conf['api_access_token_expire'] ?? 2), intval($conf['api_token_absolute_expire'] ?? 90));

	$id = param('id', 0);
	if($id <= 0) message(-1, '应用ID无效');

	$result = $apiAuthService->regenerateSecret($id);
	$result ? message(0, $result) : message(-1, '重置失败');

} elseif($action == 'settings_save') {

	CsrfService::check();
	if($method != 'POST') message(-1, 'Method not allowed');

	$api_enabled = param('api_enabled', 1);
	$api_log = param('api_log', 0);
	$api_rate_limit = param('api_rate_limit', 1);
	$api_rate_limit_max = param('api_rate_limit_max', 60);
	$api_rate_limit_window = param('api_rate_limit_window', 60);
	$api_cors_origin = param('api_cors_origin', '*');
	$api_token_expire = param('api_token_expire', 30);
	$api_access_token_expire = param('api_access_token_expire', 2);
	$api_token_absolute_expire = param('api_token_absolute_expire', 90);

	$changes = [
		'api_enabled' => intval($api_enabled),
		'api_log' => intval($api_log),
		'api_rate_limit' => intval($api_rate_limit),
		'api_rate_limit_max' => intval($api_rate_limit_max),
		'api_rate_limit_window' => intval($api_rate_limit_window),
		'api_cors_origin' => $api_cors_origin,
		'api_token_expire' => intval($api_token_expire),
		'api_access_token_expire' => max(1, intval($api_access_token_expire)),
		'api_token_absolute_expire' => max(0, intval($api_token_absolute_expire)),
	];

	$r = file_replace_var(APP_PATH . 'conf/conf.php', $changes);
	$r ? message(0, lang('update_successfully')) : message(-1, lang('update_failed'));
}

// hook admin_api_end.php

?>
