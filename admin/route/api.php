<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'doc');

// hook admin_api_start.php

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
			$tokenData = $apiAuth->generateToken($uid);
			message(0, $tokenData);

		} elseif($op == 'revoke_token') {
			$token = param('token', '');
			if(empty($token)) message(-1, 'Token is required');

			include APP_PATH . 'lib/ApiAuthService.php';
			$apiAuth = new ApiAuthService($db);
			$apiAuth->revokeToken($token);
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
			curl_close($ch);

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

}

// hook admin_api_end.php

?>
