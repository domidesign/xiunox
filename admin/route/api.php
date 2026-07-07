<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'doc');

// hook admin_api_start.php

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

	include _include(ADMIN_PATH.'view/htm/api_doc.htm');

} elseif($action == 'debug') {

	// hook admin_api_debug_start.php

	if($method == 'GET') {

		$tokens = $db->find('api_token', [], ['id' => -1], 1, 50, 'id');
		$tokenCount = $db->count('api_token');
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
			$apiAuth = new ApiAuthService($db, $conf['api_token_expire'] ?? 30);
			$tokenData = $apiAuth->generateTokens($uid);
		message(0, $tokenData);

		} elseif($op == 'revoke_token') {
			$token = param('token', '');
			if(empty($token)) message(-1, 'Token is required');

			include APP_PATH . 'lib/ApiAuthService.php';
			$apiAuth = new ApiAuthService($db);
			$apiAuth->revokeTokens($token);
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

} elseif($action == 'token_delete') {

	CsrfService::check();
	$id = param(2, 0);
	if($id <= 0) message(-1, 'ID error');
	$db->delete('api_token', ['id' => $id]);
	message(0, lang('delete_successfully'));

} elseif($action == 'settings') {

	// hook admin_api_settings_start.php

	include APP_PATH . 'lib/ApiAuthService.php';
	$apiAuthService = new ApiAuthService($db, $conf['api_token_expire'] ?? 30);

	if($method == 'GET') {
		$apps = $apiAuthService->listApps();
		$api_enabled = intval($conf['api_enabled'] ?? 1);
		$api_log = intval($conf['api_log'] ?? 0);
		$api_rate_limit = intval($conf['api_rate_limit'] ?? 1);
		$api_rate_limit_max = intval($conf['api_rate_limit_max'] ?? 60);
		$api_rate_limit_window = intval($conf['api_rate_limit_window'] ?? 60);
		$api_cors_origin = $conf['api_cors_origin'] ?? '*';
		$api_token_expire = intval($conf['api_token_expire'] ?? 30);
		include _include(ADMIN_PATH.'view/htm/api_settings.htm');
	}

} elseif($action == 'app_create') {

	CsrfService::check();
	if($method != 'POST') message(-1, 'Method not allowed');

	include APP_PATH . 'lib/ApiAuthService.php';
	$apiAuthService = new ApiAuthService($db, $conf['api_token_expire'] ?? 30);

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
	$apiAuthService = new ApiAuthService($db, $conf['api_token_expire'] ?? 30);

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
	$apiAuthService = new ApiAuthService($db, $conf['api_token_expire'] ?? 30);

	$id = param('id', 0);
	if($id <= 0) message(-1, '应用ID无效');

	$ok = $apiAuthService->deleteApp($id);
	$ok ? message(0, lang('delete_successfully')) : message(-1, lang('delete_failed'));

} elseif($action == 'app_reset_secret') {

	CsrfService::check();
	if($method != 'POST') message(-1, 'Method not allowed');

	include APP_PATH . 'lib/ApiAuthService.php';
	$apiAuthService = new ApiAuthService($db, $conf['api_token_expire'] ?? 30);

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

	$changes = [
		'api_enabled' => intval($api_enabled),
		'api_log' => intval($api_log),
		'api_rate_limit' => intval($api_rate_limit),
		'api_rate_limit_max' => intval($api_rate_limit_max),
		'api_rate_limit_window' => intval($api_rate_limit_window),
		'api_cors_origin' => $api_cors_origin,
		'api_token_expire' => intval($api_token_expire),
	];

	$r = file_replace_var(APP_PATH . 'conf/conf.php', $changes);
	$r ? message(0, lang('update_successfully')) : message(-1, lang('update_failed'));
}

// hook admin_api_end.php

?>
