<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1);
if(empty($action)) $action = 'providers';

// hook admin_ai_start.php

// 初始化 AIService（核心 editor 功能在文件末尾自动注册）
if(!class_exists('AIService')) include_once APP_PATH . 'lib/AIService.php';
$aiService = new AIService($db, $conf);

// ====== Action: providers ======
if($action == 'providers') {

	// hook admin_ai_providers_get_post.php

	if($method == 'GET') {

		// hook admin_ai_providers_get_start.php

		$ai_config = isset($conf['ai']) ? $conf['ai'] : array();

		// 读取 providers 配置；若不存在则尝试从旧版 models 迁移
		$ai_providers = array();
		if(!empty($ai_config['providers'])) {
			$ai_providers = array_values($ai_config['providers']);
		} elseif(!empty($ai_config['models'])) {
			foreach($ai_config['models'] as $name => $config) {
				$url = isset($config['endpoint']) ? $config['endpoint'] : (isset($config['url']) ? $config['url'] : '');
				if(empty($name) && empty($url)) continue;
				$ai_providers[] = array(
					'name' => $name,
					'url'  => $url,
				);
			}
		}

		$header['title'] = lang('admin_ai_tab_providers');
		$header['mobile_title'] = lang('admin_ai_tab_providers');

		// hook admin_ai_providers_get_end.php

		include _include(ADMIN_PATH.'view/htm/ai_providers.htm');

	} else {

		CsrfService::check();

		// hook admin_ai_providers_post_start.php

		// 提供商列表：name/type/url/apikey
		// apiKey 用 param 第 3 参数 FALSE 关闭 htmlspecialchars，避免含 &<>"' 的密钥被破坏
		$ai_provider_name   = param('ai_provider_name',   array(''));
		$ai_provider_type   = param('ai_provider_type',   array('text'));
		$ai_provider_url    = param('ai_provider_url',    array(''));
		$ai_provider_apikey = param('ai_provider_apikey', array(''), FALSE);
		// models 为二维数组（ai_provider_model_names[idx][]），param_force 对二维数组有 bug
		// 会把内层数组替换为 defval[0]（空字符串），导致模型数据全部丢失
		// 直接从 $_POST 取值并手动 trim，绕过 param_force 的递归替换
		$ai_provider_model_names   = isset($_POST['ai_provider_model_names'])   ? $_POST['ai_provider_model_names']   : array();
		$ai_provider_model_enabled = isset($_POST['ai_provider_model_enabled']) ? $_POST['ai_provider_model_enabled'] : array();

		// type 白名单校验
		$validTypes = array('text','image','video','audio','transcription');

		$providers = array();
		for($i = 0; $i < count($ai_provider_name); $i++) {
			$name = isset($ai_provider_name[$i])   ? trim($ai_provider_name[$i])   : '';
			$type = isset($ai_provider_type[$i])   ? trim($ai_provider_type[$i])   : 'text';
			$url  = isset($ai_provider_url[$i])    ? trim($ai_provider_url[$i])    : '';
			$key  = isset($ai_provider_apikey[$i]) ? $ai_provider_apikey[$i]       : '';
			// 过滤掉名称和 URL 都为空的行
			if($name === '' && $url === '') continue;
			// type 白名单兜底
			if(!in_array($type, $validTypes, true)) $type = 'text';

			// 收集该提供商的模型列表（新格式：[{name, enabled}, ...]）
			$models = array();
			$names = isset($ai_provider_model_names[$i]) ? $ai_provider_model_names[$i] : array();
			$enableds = isset($ai_provider_model_enabled[$i]) ? $ai_provider_model_enabled[$i] : array();
			if(is_array($names)) {
				for($j = 0; $j < count($names); $j++) {
					$mname = isset($names[$j]) ? trim($names[$j]) : '';
					if($mname === '') continue;
					$menabled = isset($enableds[$j]) ? 1 : 0;
					$models[] = array('name' => $mname, 'enabled' => $menabled);
				}
			}

			$providers[] = array(
				'name'    => $name,
				'type'     => $type,
				'url'     => $url,
				'api_key' => $key,
				'models'  => $models,
			);
		}

		$ai_config = isset($conf['ai']) ? $conf['ai'] : array();
		$ai_config['providers'] = $providers;
		// 迁移完成，删除旧版 models 字段避免干扰
		unset($ai_config['models']);

		file_replace_var(APP_PATH.'conf/conf.php', array('ai' => $ai_config));

		// 清理 tmp 编译缓存，确保新配置生效
		$tmp_path = isset($conf['tmp_path']) ? $conf['tmp_path'] : APP_PATH.'tmp/';
		$tmp_files = glob($tmp_path.'*.php');
		if($tmp_files) {
			foreach($tmp_files as $f) {
				@unlink($f);
			}
		}

		// hook admin_ai_providers_post_end.php

		admin_log_create('ai_providers', 'ai', '', '修改AI提供商配置');
		message(0, lang('save_successfully'));
	}

// ====== Action: features ======
} elseif($action == 'features') {

	// hook admin_ai_features_get_post.php

	if($method == 'GET') {

		// hook admin_ai_features_get_start.php

		$ai_config = isset($conf['ai']) ? $conf['ai'] : array();

		// providers 用于功能配置页选择默认提供商和允许的服务商
		$ai_providers = !empty($ai_config['providers']) ? array_values($ai_config['providers']) : array();

		// 合并已注册功能（核心 editor + 插件注册的）与 conf.ai.features
		// array_merge 字符串键后者覆盖前者：conf 中的值覆盖注册默认值
		$confFeatures = !empty($ai_config['features']) ? $ai_config['features'] : array();
		$ai_features = array_merge(AIService::getRegisteredFeatures(), $confFeatures);

		$header['title'] = lang('admin_ai_tab_features');
		$header['mobile_title'] = lang('admin_ai_tab_features');

		// hook admin_ai_features_get_end.php

		include _include(ADMIN_PATH.'view/htm/ai_features.htm');

	} else {

		CsrfService::check();

		// hook admin_ai_features_post_start.php

		// 功能配置：mode/default_provider/default_model/allowed_providers
		// call_method 字段已移除：所有调用统一走服务端代理
		$feature_modes             = param('feature_mode',             array(''));
		$feature_default_providers = param('feature_default_provider', array(''));
		$feature_default_models    = param('feature_default_model',    array(''));
		// feature_allowed_providers 为二维数组（feature_allowed_providers[fkey][]）
		// param_force 对二维数组有 bug，直接从 $_POST 取值
		$feature_allowed_providers = isset($_POST['feature_allowed_providers']) ? $_POST['feature_allowed_providers'] : array();

		$ai_config = isset($conf['ai']) ? $conf['ai'] : array();
		if(!isset($ai_config['features']) || !is_array($ai_config['features'])) {
			$ai_config['features'] = array();
		}

		$validProviderNames = array();
		foreach($ai_config['providers'] as $p) {
			if(!empty($p['name'])) $validProviderNames[] = $p['name'];
		}

		foreach($feature_modes as $key => $mode) {
			if(!isset($ai_config['features'][$key]) || !is_array($ai_config['features'][$key])) {
				$ai_config['features'][$key] = array();
			}
			// 所有功能均可在 global/user_key/both 三种模式中选择
			$ai_config['features'][$key]['mode']             = $mode;
			// call_method 已废弃，所有调用统一走服务端代理
			$ai_config['features'][$key]['call_method']      = 'proxy';
			$ai_config['features'][$key]['default_provider'] = isset($feature_default_providers[$key]) ? $feature_default_providers[$key] : '';
			$ai_config['features'][$key]['default_model']    = isset($feature_default_models[$key]) ? $feature_default_models[$key] : '';

			// 多选服务商：必须至少勾选一个（强制校验，避免功能被静默禁用）
			$allowed = isset($feature_allowed_providers[$key]) ? $feature_allowed_providers[$key] : array();
			if(!is_array($allowed)) $allowed = array();
			// 过滤掉已不存在的 provider 名（防止 provider 被删除后 allowed 列表残留脏数据）
			$allowed = array_values(array_filter($allowed, function($n) use ($validProviderNames) {
				return in_array($n, $validProviderNames, true);
			}));
			if(empty($allowed)) {
				message(-1, lang('admin_ai_feature_allowed_providers_required'));
			}
			$ai_config['features'][$key]['allowed_providers'] = $allowed;

			// 校验 default_provider 必须在 allowed_providers 中
			$dp = $ai_config['features'][$key]['default_provider'];
			if(!empty($dp) && !in_array($dp, $allowed, true)) {
				// default_provider 不在允许列表中，清空让它走第一个允许的 provider
				$ai_config['features'][$key]['default_provider'] = '';
			}
		}

		file_replace_var(APP_PATH.'conf/conf.php', array('ai' => $ai_config));

		// 清理 tmp 编译缓存
		$tmp_path = isset($conf['tmp_path']) ? $conf['tmp_path'] : APP_PATH.'tmp/';
		$tmp_files = glob($tmp_path.'*.php');
		if($tmp_files) {
			foreach($tmp_files as $f) {
				@unlink($f);
			}
		}

		// hook admin_ai_features_post_end.php

		admin_log_create('ai_features', 'ai', '', '修改AI功能配置');
		message(0, lang('save_successfully'));
	}

// ====== Action: editor ======
} elseif($action == 'editor') {

	// hook admin_ai_editor_get_post.php

	if($method == 'GET') {

		// hook admin_ai_editor_get_start.php

		$ai_config = isset($conf['ai']) ? $conf['ai'] : array();

		$ai_editor_config = array(
			'promptContinue'    => isset($ai_config['promptContinue'])    ? $ai_config['promptContinue']    : '',
			'promptImprove'     => isset($ai_config['promptImprove'])     ? $ai_config['promptImprove']     : '',
		);

		$header['title'] = lang('admin_ai_tab_editor');
		$header['mobile_title'] = lang('admin_ai_tab_editor');

		// hook admin_ai_editor_get_end.php

		include _include(ADMIN_PATH.'view/htm/ai_editor.htm');

	} else {

		CsrfService::check();

		// hook admin_ai_editor_post_start.php

		$ai_config = isset($conf['ai']) ? $conf['ai'] : array();
		// 气泡面板和@提及功能已移除后台开关，功能始终启用（EditorService 中硬编码）
		// prompt 含中文及特殊字符，关闭 htmlspecialchars
		$ai_config['promptContinue']    = param('editor_prompt_continue', '', FALSE);
		$ai_config['promptImprove']     = param('editor_prompt_improve',  '', FALSE);

		file_replace_var(APP_PATH.'conf/conf.php', array('ai' => $ai_config));

		// 清理 tmp 编译缓存
		$tmp_path = isset($conf['tmp_path']) ? $conf['tmp_path'] : APP_PATH.'tmp/';
		$tmp_files = glob($tmp_path.'*.php');
		if($tmp_files) {
			foreach($tmp_files as $f) {
				@unlink($f);
			}
		}

		// hook admin_ai_editor_post_end.php

		admin_log_create('ai_editor', 'ai', '', '修改AI编辑器配置');
		message(0, lang('save_successfully'));
	}

// ====== Action: logs（调用日志） ======
} elseif($action == 'logs') {

	// hook admin_ai_logs_get_post.php

	if(!class_exists('AILogService')) include_once APP_PATH . 'lib/AILogService.php';

	// 筛选参数
	$filter_source  = param('source', '');
	$filter_feature = param('feature', '');
	$filter_status  = param('status', '');
	$page    = param(2, 1);
	$pagesize = 20;

	$filters = array();
	if($filter_source !== '')  $filters['source']  = $filter_source;
	if($filter_feature !== '') $filters['feature'] = $filter_feature;
	if($filter_status !== '')  $filters['status']  = intval($filter_status);

	$logs = AILogService::getLogs($page, $pagesize, $filters);
	$total = AILogService::countLogs($filters);
	$pagination = pagination(url('ai-logs'), $total, $page, $pagesize);

	// 统计概览（今日）
	$statsToday = AILogService::getStatsBySource('today');

	$header['title'] = lang('admin_ai_tab_logs');
	$header['mobile_title'] = lang('admin_ai_tab_logs');

	// hook admin_ai_logs_get_end.php

	include _include(ADMIN_PATH.'view/htm/ai_logs.htm');

} else {
	// 未知 action
	message(-1, lang('admin_request_failed_retry'));
}

// hook admin_ai_end.php

?>
