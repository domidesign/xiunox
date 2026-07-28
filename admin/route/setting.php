<?php

!defined('DEBUG') AND exit('Access Denied');

$action = param(1);

include _include(APP_PATH.'model/smtp.func.php');
include _include(APP_PATH.'model/email_log.func.php');
include_once APP_PATH . 'lib/CacheService.php';
$smtplist = smtp_init(APP_PATH.'conf/smtp.conf.php');
// hook admin_setting_start.php

if($action == 'base') {

	// hook admin_setting_base_get_post.php

	if($method == 'GET') {

		// hook admin_setting_base_get_start.php

		$input = array();
		$input['sitename'] = form_text('sitename', $conf['sitename']);
		$input['sitebrief'] = form_textarea('sitebrief', $conf['sitebrief'], '100%', 100);
		$input['runlevel'] = form_radio('runlevel', array(0=>lang('runlevel_0'), 1=>lang('runlevel_1'), 2=>lang('runlevel_2'), 3=>lang('runlevel_3'), 4=>lang('runlevel_4'), 5=>lang('runlevel_5')), $conf['runlevel']);
		$input['user_create_on'] = form_radio_yes_no('user_create_on', $conf['user_create_on']);
		$input['user_create_email_on'] = form_radio_yes_no('user_create_email_on', $conf['user_create_email_on']);
		$input['user_resetpw_on'] = form_radio_yes_no('user_resetpw_on', $conf['user_resetpw_on']);
		$input['force_https'] = form_radio_yes_no('force_https', isset($conf['force_https']) ? $conf['force_https'] : 0);
		$input['lang'] = form_select('lang', array('zh-cn'=>lang('lang_zh_cn'), 'zh-tw'=>lang('lang_zh_tw'), 'en-us'=>lang('lang_en_us')), $conf['lang']);

		$header['title'] = lang('admin_site_setting');
		$header['mobile_title'] =lang('admin_site_setting');

		// hook admin_setting_base_get_end.php

		include _include(ADMIN_PATH.'view/htm/setting_base.htm');

	} else {

		CsrfService::check();

		$sitebrief = param('sitebrief', '', FALSE);
		$sitename = param('sitename', '', FALSE);
		$runlevel = param('runlevel', 0);
		$user_create_on = param('user_create_on', 0);
		$user_create_email_on = param('user_create_email_on', 0);
		$user_resetpw_on = param('user_resetpw_on', 0);
		$force_https = param('force_https', 0);

		$_lang = param('lang');

		// hook admin_setting_base_post_start.php

		$replace = array();
		$replace['sitename'] = $sitename;
		$replace['sitebrief'] = $sitebrief;
		$replace['runlevel'] = $runlevel;
		$replace['user_create_on'] = $user_create_on;
		$replace['user_create_email_on'] = $user_create_email_on;
		$replace['user_resetpw_on'] = $user_resetpw_on;
		$replace['force_https'] = $force_https;
		$replace['lang'] = $_lang;

		file_replace_var(APP_PATH.'conf/conf.php', $replace);

		// hook admin_setting_base_post_end.php

		admin_log_create('setting_site', 'setting', '', '修改站点设置');
		message(0, lang('modify_successfully'));
	}

} elseif($action == 'seo') {

	// hook admin_setting_seo_get_post.php

	if($method == 'GET') {

		// hook admin_setting_seo_get_start.php

		$input = array();
		$input['sitesubtitle'] = form_text('sitesubtitle', isset($conf['sitesubtitle']) ? $conf['sitesubtitle'] : '');
		$input['site_keywords'] = form_text('site_keywords', isset($conf['site_keywords']) ? $conf['site_keywords'] : '');
		$input['site_description'] = form_textarea('site_description', isset($conf['site_description']) ? $conf['site_description'] : '', '100%', 60);
		$input['sitemap_enabled'] = form_radio_yes_no('sitemap_enabled', isset($conf['sitemap_enabled']) ? $conf['sitemap_enabled'] : 1);
		$input['sitemap_thread_limit'] = form_text('sitemap_thread_limit', isset($conf['sitemap_thread_limit']) ? $conf['sitemap_thread_limit'] : 1000);
		$input['sitemap_cache_ttl'] = form_text('sitemap_cache_ttl', isset($conf['sitemap_cache_ttl']) ? $conf['sitemap_cache_ttl'] : 3600);
		$input['seo_og_enabled'] = form_radio_yes_no('seo_og_enabled', isset($conf['seo_og_enabled']) ? $conf['seo_og_enabled'] : 1);
		$input['seo_jsonld_enabled'] = form_radio_yes_no('seo_jsonld_enabled', isset($conf['seo_jsonld_enabled']) ? $conf['seo_jsonld_enabled'] : 1);
		$input['seo_canonical_enabled'] = form_radio_yes_no('seo_canonical_enabled', isset($conf['seo_canonical_enabled']) ? $conf['seo_canonical_enabled'] : 1);

		// llms.txt 内容读取（根目录 llms.txt，不存在时给默认模板）
		$_llms_path = APP_PATH . 'llms.txt';
		$llms_txt_content = is_file($_llms_path) ? file_get_contents($_llms_path) : '';

		// SEO 健康检查
		$seo_checks = array();
		$seo_checks['site_keywords'] = array(
			'label' => lang('seo_check_site_keywords'),
			'status' => !empty($conf['site_keywords']) ? 'ok' : 'warn',
			'msg' => !empty($conf['site_keywords']) ? lang('seo_check_filled') : lang('seo_check_empty'),
		);
		$seo_checks['site_description'] = array(
			'label' => lang('seo_check_site_description'),
			'status' => !empty($conf['site_description']) ? 'ok' : 'warn',
			'msg' => !empty($conf['site_description']) ? lang('seo_check_filled') : lang('seo_check_empty'),
		);
		$seo_checks['sitesubtitle'] = array(
			'label' => lang('seo_check_sitesubtitle'),
			'status' => !empty($conf['sitesubtitle']) ? 'ok' : 'info',
			'msg' => !empty($conf['sitesubtitle']) ? lang('seo_check_filled') : lang('seo_check_optional'),
		);
		$seo_checks['sitemap'] = array(
			'label' => lang('seo_check_sitemap'),
			'status' => (isset($conf['sitemap_enabled']) ? $conf['sitemap_enabled'] : 1) ? 'ok' : 'error',
			'msg' => (isset($conf['sitemap_enabled']) ? $conf['sitemap_enabled'] : 1) ? lang('seo_check_sitemap_on') : lang('seo_check_sitemap_off'),
		);
		$seo_checks['robots'] = array(
			'label' => lang('seo_check_robots'),
			'status' => is_file(APP_PATH . 'robots.txt') ? 'ok' : 'error',
			'msg' => is_file(APP_PATH . 'robots.txt') ? lang('seo_check_robots_ok') : lang('seo_check_robots_missing'),
		);
		$seo_checks['llms'] = array(
			'label' => lang('seo_check_llms'),
			'status' => is_file(APP_PATH . 'llms.txt') ? 'ok' : 'warn',
			'msg' => is_file(APP_PATH . 'llms.txt') ? lang('seo_check_llms_ok') : lang('seo_check_llms_missing'),
		);
		$seo_checks['og'] = array(
			'label' => lang('seo_check_og'),
			'status' => (isset($conf['seo_og_enabled']) ? $conf['seo_og_enabled'] : 1) ? 'ok' : 'warn',
			'msg' => (isset($conf['seo_og_enabled']) ? $conf['seo_og_enabled'] : 1) ? lang('seo_check_og_on') : lang('seo_check_og_off'),
		);
		$seo_checks['jsonld'] = array(
			'label' => lang('seo_check_jsonld'),
			'status' => (isset($conf['seo_jsonld_enabled']) ? $conf['seo_jsonld_enabled'] : 1) ? 'ok' : 'warn',
			'msg' => (isset($conf['seo_jsonld_enabled']) ? $conf['seo_jsonld_enabled'] : 1) ? lang('seo_check_jsonld_on') : lang('seo_check_jsonld_off'),
		);
		$seo_checks['canonical'] = array(
			'label' => lang('seo_check_canonical'),
			'status' => (isset($conf['seo_canonical_enabled']) ? $conf['seo_canonical_enabled'] : 1) ? 'ok' : 'warn',
			'msg' => (isset($conf['seo_canonical_enabled']) ? $conf['seo_canonical_enabled'] : 1) ? lang('seo_check_canonical_on') : lang('seo_check_canonical_off'),
		);
		$seo_checks['permalink'] = array(
			'label' => lang('seo_check_permalink'),
			'status' => (!empty($conf['url_rewrite']) || !empty($conf['seo_url_pretty'])) ? 'ok' : 'warn',
			'msg' => (!empty($conf['url_rewrite']) || !empty($conf['seo_url_pretty'])) ? lang('seo_check_permalink_on') : lang('seo_check_permalink_off'),
		);

		// hook admin_setting_seo_get_end.php

		$header['title'] = lang('admin_setting_seo');
		$header['mobile_title'] = lang('admin_setting_seo');

		include _include(ADMIN_PATH.'view/htm/setting_seo.htm');

	} else {

		CsrfService::check();

		$sitesubtitle = param('sitesubtitle', '', FALSE);
		$site_keywords = param('site_keywords', '', FALSE);
		$site_description = param('site_description', '', FALSE);
		$sitemap_enabled = param('sitemap_enabled', 0);
		$sitemap_thread_limit = param('sitemap_thread_limit', 1000);
		$sitemap_cache_ttl = param('sitemap_cache_ttl', 3600);
		$seo_og_enabled = param('seo_og_enabled', 0);
		$seo_jsonld_enabled = param('seo_jsonld_enabled', 0);
		$seo_canonical_enabled = param('seo_canonical_enabled', 0);
		$llms_txt_content = param('llms_txt', '', FALSE);

		// hook admin_setting_seo_post_start.php

		$replace = array();
		$replace['sitesubtitle'] = $sitesubtitle;
		$replace['site_keywords'] = $site_keywords;
		$replace['site_description'] = $site_description;
		$replace['sitemap_enabled'] = $sitemap_enabled;
		$replace['sitemap_thread_limit'] = max(100, intval($sitemap_thread_limit));
		$replace['sitemap_cache_ttl'] = max(60, intval($sitemap_cache_ttl));
		$replace['seo_og_enabled'] = $seo_og_enabled;
		$replace['seo_jsonld_enabled'] = $seo_jsonld_enabled;
		$replace['seo_canonical_enabled'] = $seo_canonical_enabled;

		file_replace_var(APP_PATH.'conf/conf.php', $replace);

		// 保存 llms.txt 到根目录
		$_llms_path = APP_PATH . 'llms.txt';
		$_llms_ok = file_put_contents($_llms_path, $llms_txt_content);
		if($_llms_ok === false) {
			message(-1, lang('seo_llms_save_failed'));
		}

		// 清理 sitemap 缓存，让新配置立即生效
		if(class_exists('CacheHelper', false) && method_exists('CacheHelper', 'delete')) {
			CacheHelper::delete('seo_sitemap_xml_v1');
		}

		// hook admin_setting_seo_post_end.php

		admin_log_create('setting_seo', 'setting', '', '修改SEO设置');
		message(0, lang('modify_successfully'));
	}

} elseif($action == 'smtp') {

	// hook admin_setting_smtp_get_post.php
	
	if($method == 'GET') {
		
		// hook admin_setting_smtp_get_start.php
		
		$header['title'] = lang('admin_setting_smtp');
		$header['mobile_title'] = lang('admin_setting_smtp');
	
		$smtplist = smtp_find();
		$maxid = smtp_maxid();

		// 加载邮件模板数据
		$email_templates = array();
		$tpl_confile = APP_PATH . 'conf/email_templates.conf.php';
		if(is_file($tpl_confile)) {
			$email_templates = include $tpl_confile;
		}
		if(!is_array($email_templates)) $email_templates = array();
		$default_tpl_keys = array('user_create_code', 'user_resetpw_code', 'email_change_code');
		foreach($default_tpl_keys as $dk) {
			if(!isset($email_templates[$dk])) {
				$default_templates = include APP_PATH . 'conf/email_templates.conf.php';
				if(isset($default_templates[$dk])) {
					$email_templates[$dk] = $default_templates[$dk];
				}
			}
		}

		// 加载邮件日志数据
		$email_log_filter = param('status', -1);
		$email_log_cond = array();
		if($email_log_filter >= 0) {
			$email_log_cond['status'] = intval($email_log_filter);
		}
		$email_log_page = 1;
		$email_log_pagesize = 15;
		$email_loglist = email_log_find($email_log_cond, array('logid'=>-1), $email_log_page, $email_log_pagesize);
		$email_log_totalnum = email_log_count($email_log_cond);
		$email_pagination = pagination(url("setting-email_log-{page}"), $email_log_totalnum, $email_log_page, $email_log_pagesize);
		
		// hook admin_setting_smtp_get_end.php
		
		include _include(ADMIN_PATH."view/htm/setting_smtp.htm");
	
	} else {
		
		CsrfService::check();
		
		// hook admin_setting_smtp_post_start.php
		
		$email = param('email', array(''));
		$host = param('host', array(''));
		$port = param('port', array(0));
		$user = param('user', array(''));
		$pass = param('pass', array(''));
		$ssl = param('ssl', array(0));

		$smtplist = array();
		foreach ($email as $k=>$v) {
			$smtplist[$k] = array(
				'email'=>$email[$k],
				'host'=>$host[$k],
				'port'=>$port[$k],
				'user'=>$user[$k],
				'pass'=>$pass[$k],
				'ssl'=>intval($ssl[$k]),
			);
		}
		$r = file_put_contents_try(APP_PATH.'conf/smtp.conf.php', "<?php\r\nreturn ".var_export($smtplist,true).";\r\n?>");
		!$r AND message(-1, lang('conf/smtp.conf.php', array('file'=>'conf/smtp.conf.php')));

		// ponytail: opcache.revalidate_freq=2 会导致 1 秒后 reload 读到旧字节码,保存后立即失效
		if (function_exists('opcache_invalidate')) {
			opcache_invalidate(APP_PATH.'conf/smtp.conf.php', true);
		}

		// hook admin_setting_smtp_post_end.php

		admin_log_create('setting_smtp', 'setting', '', '修改SMTP设置');
		message(0, lang('save_successfully'));
	}

} elseif($action == 'smtp_test') {

	$method != 'POST' AND message(-1, lang('method_error'));
	CsrfService::check();

	include _include(XIUNOPHP_PATH.'xn_send_mail.func.php');

	$test_email = param('test_email');
	$smtp_index = param('smtp_index', 0);

	empty($test_email) AND message(-1, '请输入测试邮箱');
	!filter_var($test_email, FILTER_VALIDATE_EMAIL) AND message(-1, '邮箱格式不正确');

	// ponytail: smtp.conf.php 是后台动态写入的配置文件，禁止走 _include() 编译缓存
	// _include() 不比较源文件 mtime，后台保存后未清 tmp/conf_smtp.conf.php 会导致测试邮件读旧缓存
	// 应与 xn_smtp_get()/smtp_init()/HealthCheckService 保持一致，直接 include 源文件
	$smtplist = include APP_PATH.'conf/smtp.conf.php';
	if(!is_array($smtplist) || empty($smtplist)) {
		message(-1, '未配置 SMTP 服务器');
	}

	// 如果指定了索引，使用对应的 SMTP，否则随机选择
	if(isset($smtplist[$smtp_index]) && !empty($smtplist[$smtp_index]['host'])) {
		$smtp = $smtplist[$smtp_index];
	} else {
		$smtp = xn_smtp_get();
	}

	if(empty($smtp)) {
		message(-1, '无有效的 SMTP 配置');
	}

	$subject = '测试邮件 - ' . $conf['sitename'];
	$body = '<h3>测试邮件</h3><p>这是一封来自 <strong>' . esc_html($conf['sitename']) . '</strong> 的测试邮件。</p><p>如果您收到此邮件，说明 SMTP 配置正确。</p><p>发送时间：' . date('Y-m-d H:i:s') . '</p>';

	// ponytail: 测试邮件传 timeout=5，让 SMTP 连接/SSL 握手失败时更快返回（默认 10s 偏慢）
	$r = xn_send_mail($smtp, $conf['sitename'], $test_email, $subject, $body, array('timeout' => 5));

	if($r === TRUE) {
		message(0, '测试邮件发送成功，请检查收件箱');
	} else {
		// xn_send_mail 失败时返回错误字符串，如 SMTP connect() failed 等
		$error_msg = is_string($r) ? $r : '未知错误';
		message(-1, '测试邮件发送失败：' . $error_msg);
	}

} elseif($action == 'upload') {

	// hook admin_setting_upload_get_post.php

	if($method == 'GET') {

		// hook admin_setting_upload_get_start.php

		// 文件大小限制（从配置读取，单位MB用于显示）
		$upload_max_image_size = isset($conf['upload_max_image_size']) ? intval($conf['upload_max_image_size']) : 10485760;
		$upload_max_file_size = isset($conf['upload_max_file_size']) ? intval($conf['upload_max_file_size']) : 20971520;
		$upload_max_video_size = isset($conf['upload_max_video_size']) ? intval($conf['upload_max_video_size']) : 104857600;
		$upload_max_image_size_mb = intval($upload_max_image_size / 1048576);
		$upload_max_file_size_mb = intval($upload_max_file_size / 1048576);
		$upload_max_video_size_mb = intval($upload_max_video_size / 1048576);

		// 缩略图设置
		$upload_thumb_enabled = isset($conf['upload_thumb_enabled']) ? intval($conf['upload_thumb_enabled']) : 1;
		$upload_thumb_width = isset($conf['upload_thumb_width']) ? intval($conf['upload_thumb_width']) : 200;

		// 允许的文件类型
		$upload_allowed_image_types_str = isset($conf['upload_allowed_image_types']) ? $conf['upload_allowed_image_types'] : 'jpg,jpeg,png,gif,webp,bmp';
		$upload_allowed_video_types_str = isset($conf['upload_allowed_video_types']) ? $conf['upload_allowed_video_types'] : 'mp4,webm,ogg,avi,rm,rmvb';
		$upload_allowed_file_types_str = isset($conf['upload_allowed_file_types']) ? $conf['upload_allowed_file_types'] : 'doc,xls,ppt,docx,xlsx,pptx,pdf,txt,zip,gz,rar,7z';

		$allowed_image_types = explode(',', $upload_allowed_image_types_str);
		$allowed_video_types = explode(',', $upload_allowed_video_types_str);
		$allowed_file_types = explode(',', $upload_allowed_file_types_str);

		// 可选类型列表
		$image_type_options = array('jpg','jpeg','png','gif','webp','bmp');
		$video_type_options = array('mp4','webm','ogg','avi','rm','rmvb');
		$file_type_options = array('doc','xls','ppt','docx','xlsx','pptx','pdf','txt','zip','gz','rar','7z');

		// 存储驱动
		$upload_driver = isset($conf['upload_driver']) ? $conf['upload_driver'] : 'local';

		$header['title'] = lang('admin_setting_upload');
		$header['mobile_title'] = lang('admin_setting_upload');

		// hook admin_setting_upload_get_end.php

		include _include(ADMIN_PATH.'view/htm/setting_upload.htm');

	} else {

		CsrfService::check();

		// hook admin_setting_upload_post_start.php

		// 文件大小限制（前端传MB，存储为字节）
		$upload_max_image_size_mb = param('upload_max_image_size', 10);
		$upload_max_file_size_mb = param('upload_max_file_size', 20);
		$upload_max_video_size_mb = param('upload_max_video_size', 100);
		$upload_max_image_size = intval($upload_max_image_size_mb) * 1048576;
		$upload_max_file_size = intval($upload_max_file_size_mb) * 1048576;
		$upload_max_video_size = intval($upload_max_video_size_mb) * 1048576;

		// 缩略图设置
		$upload_thumb_enabled = param('upload_thumb_enabled', 0);
		$upload_thumb_width = param('upload_thumb_width', 200);

		// 允许的文件类型
		$upload_allowed_image_types_arr = param('upload_allowed_image_types', array());
		$upload_allowed_video_types_arr = param('upload_allowed_video_types', array());
		$upload_allowed_file_types_arr = param('upload_allowed_file_types', array());
		$upload_allowed_image_types = implode(',', $upload_allowed_image_types_arr);
		$upload_allowed_video_types = implode(',', $upload_allowed_video_types_arr);
		$upload_allowed_file_types = implode(',', $upload_allowed_file_types_arr);

		// 存储驱动
		$upload_driver = param('upload_driver', 'local');
		if(!in_array($upload_driver, array('local', 'oss'))) {
			$upload_driver = 'local';
		}

		$replace = array();
		$replace['upload_max_image_size'] = $upload_max_image_size;
		$replace['upload_max_file_size'] = $upload_max_file_size;
		$replace['upload_max_video_size'] = $upload_max_video_size;
		$replace['upload_thumb_enabled'] = $upload_thumb_enabled;
		$replace['upload_thumb_width'] = $upload_thumb_width;
		$replace['upload_allowed_image_types'] = $upload_allowed_image_types;
		$replace['upload_allowed_video_types'] = $upload_allowed_video_types;
		$replace['upload_allowed_file_types'] = $upload_allowed_file_types;
		$replace['upload_driver'] = $upload_driver;

		file_replace_var(APP_PATH.'conf/conf.php', $replace);

		// hook admin_setting_upload_post_end.php

		admin_log_create('setting_upload', 'setting', '', '修改上传设置');
		message(0, lang('modify_successfully'));
	}

} elseif($action == 'nav') {

	// hook admin_setting_nav_get_post.php

	// 加载 NavService（插件导航注册服务）
	include _include(APP_PATH.'lib/NavService.php');
	// 加载 DiscoverService（发现页插件项，GET 渲染和 POST 返回都需使用）
	include APP_PATH.'lib/DiscoverService.php';

	if($method == 'GET') {

		// hook admin_setting_nav_get_start.php

		$nav_items = isset($conf['nav_items']) ? $conf['nav_items'] : array();
		$sidebar_nav_items = isset($conf['sidebar_nav_items']) ? $conf['sidebar_nav_items'] : array();
		$discover_items = isset($conf['discover_items']) ? $conf['discover_items'] : array();
		$mobile_nav_items = isset($conf['mobile_nav_items']) ? $conf['mobile_nav_items'] : array();
		$mobile_nav_enable = !empty($conf['mobile_nav_enable']);
		// 左侧导航开关：默认开启（未配置时视为 1，保持向前兼容）
		$sidebar_nav_enable = !isset($conf['sidebar_nav_enable']) ? 1 : (!empty($conf['sidebar_nav_enable']) ? 1 : 0);

		// 标记用户自定义项的 source（便于后台区分插件项与用户项）
		foreach ($nav_items as &$_ni) { $_ni['source'] = 'custom'; }
		unset($_ni);
		foreach ($sidebar_nav_items as &$_sni) { $_sni['source'] = 'custom'; }
		unset($_sni);
		foreach ($mobile_nav_items as &$_mni) { $_mni['source'] = 'custom'; }
		unset($_mni);
		foreach ($discover_items as &$_dni) { $_dni['source'] = 'custom'; }
		unset($_dni);

		// top/side/mobile 插件项不再混入表格，仅在顶部"插件注册项"展示区显示（source=plugin_*）
		// 发现导航合并 DiscoverService 返回的已启用插件项（前台 /more 实际显示的项）
		$_plugin_discover = DiscoverService::getPluginDiscoverItems(true);
		$discover_items = array_merge($discover_items, $_plugin_discover);

		// 按 rank 排序，同 rank 时分类标题排在前，一级链接次之
		$nav_sort = function($a, $b) {
			$ra = intval($a['rank'] ?? 0);
			$rb = intval($b['rank'] ?? 0);
			if ($ra !== $rb) return $ra - $rb;
			$_type_order = function($t) { return $t === 'title' ? 0 : ($t === 'top_link' ? 1 : 2); };
			return $_type_order($a['type'] ?? 'link') - $_type_order($b['type'] ?? 'link');
		};
		usort($nav_items, $nav_sort);
		usort($sidebar_nav_items, $nav_sort);
		usort($mobile_nav_items, $nav_sort);
		usort($discover_items, $nav_sort);

		// 获取所有插件注册信息（用于后台只读展示）
		$plugin_nav_registry_top = NavService::getPluginNavItems('top');
		$plugin_nav_registry_side = NavService::getPluginNavItems('side');
		$plugin_nav_registry_mobile = NavService::getPluginNavItems('mobile');

		// 页脚配置数据
		$footer_config = isset($conf['footer']) ? $conf['footer'] : array();
		$footer_icp = isset($footer_config['icp']) ? $footer_config['icp'] : '';
		$footer_gongan = isset($footer_config['gongan']) ? $footer_config['gongan'] : '';
		$footer_gongan_url = isset($footer_config['gongan_url']) ? $footer_config['gongan_url'] : '';
		$footer_copyright = isset($footer_config['copyright']) ? $footer_config['copyright'] : '';
		$footer_show_powered = isset($footer_config['show_powered']) ? intval($footer_config['show_powered']) : 1;

		$header['title'] = lang('admin_setting_nav');
		$header['mobile_title'] = lang('admin_setting_nav');

		// 防止浏览器缓存导航设置页，确保保存后刷新能看到新数据
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		header('Expires: 0');

		// hook admin_setting_nav_get_end.php

		include _include(ADMIN_PATH.'view/htm/setting_nav.htm');

	} else {

		CsrfService::check();

		// hook admin_setting_nav_post_start.php

		// 顶部导航（插件项不参与表单提交，只保存 custom 项）
		$nav_icon = param('nav_icon', array(''));
		$nav_name = param('nav_name', array(''));
		$nav_slug = param('nav_slug', array(''));
		$nav_url = param('nav_url', array(''));
		$nav_rank = param('nav_rank', array(0));
		$nav_type = param('nav_type', array('link'));
		$nav_class = param('nav_class', array(''));

		$nav_items = array();
		foreach ($nav_name as $k=>$v) {
			if(empty($nav_name[$k]) && empty($nav_icon[$k])) continue;
			$slug = !empty($nav_slug[$k]) ? $nav_slug[$k] : 'nav-'.substr(md5(($nav_name[$k] ?? '').$k.microtime(true)), 0, 8);
			$nav_items[] = array(
				'type'=>isset($nav_type[$k]) ? $nav_type[$k] : 'link',
				'icon'=>$nav_icon[$k] ?? '',
				'name'=>$nav_name[$k] ?? '',
				'slug'=>$slug,
				'url'=>NavService::normalize($nav_url[$k] ?? ''),
				'class'=>trim($nav_class[$k] ?? ''),
				'rank'=>intval($nav_rank[$k]),
			);
		}

		// 左侧导航
		$sidebar_icon = param('sidebar_icon', array(''));
		$sidebar_name = param('sidebar_name', array(''));
		$sidebar_slug = param('sidebar_slug', array(''));
		$sidebar_url = param('sidebar_url', array(''));
		$sidebar_rank = param('sidebar_rank', array(0));
		$sidebar_type = param('sidebar_type', array('link'));
		$sidebar_class = param('sidebar_class', array(''));

		$sidebar_nav_items = array();
		foreach ($sidebar_name as $k=>$v) {
			if(empty($sidebar_name[$k]) && empty($sidebar_icon[$k])) continue;
			$slug = !empty($sidebar_slug[$k]) ? $sidebar_slug[$k] : 'side-'.substr(md5(($sidebar_name[$k] ?? '').$k.microtime(true)), 0, 8);
			$sidebar_nav_items[] = array(
				'type'=>isset($sidebar_type[$k]) ? $sidebar_type[$k] : 'link',
				'icon'=>$sidebar_icon[$k] ?? '',
				'name'=>$sidebar_name[$k] ?? '',
				'slug'=>$slug,
				'url'=>NavService::normalize($sidebar_url[$k] ?? ''),
				'class'=>trim($sidebar_class[$k] ?? ''),
				'rank'=>intval($sidebar_rank[$k]),
			);
		}

		// 手机导航
		$mobile_icons = param('mobile_icon', array());
		$mobile_icons_active = param('mobile_icon_active', array());
		$mobile_names = param('mobile_name', array());
		$mobile_urls = param('mobile_url', array());
		$mobile_ranks = param('mobile_rank', array());
		$mobile_need_login = param('mobile_need_login', array());
		$mobile_classes = param('mobile_class', array());
		$mobile_items = array();
		for($i = 0; $i < count($mobile_names); $i++) {
			if(empty($mobile_names[$i]) && empty($mobile_icons[$i]) && empty($mobile_icons_active[$i])) continue;
			$mobile_items[] = array(
				'icon' => $mobile_icons[$i] ?? '',
				'icon_active' => $mobile_icons_active[$i] ?? '',
				'name' => $mobile_names[$i] ?? '',
				'url' => NavService::normalize($mobile_urls[$i] ?? ''),
				'class' => trim($mobile_classes[$i] ?? ''),
				'rank' => intval($mobile_ranks[$i] ?? 0),
				'need_login' => !empty($mobile_need_login[$i]) ? 1 : 0,
			);
		}

		$replace = array();
		$replace['nav_items'] = $nav_items;
		$replace['sidebar_nav_items'] = $sidebar_nav_items;

		// 发现导航列表
		$discover_icons = param('discover_icon', array());
		$discover_names = param('discover_name', array());
		$discover_slugs = param('discover_slug', array());
		$discover_urls = param('discover_url', array());
		$discover_ranks = param('discover_rank', array());
		$discover_classes = param('discover_class', array());
		$discover_items = array();
		for($i = 0; $i < count($discover_names); $i++) {
			// name/icon/url 均为空才跳过
			if(empty($discover_names[$i]) && empty($discover_urls[$i]) && empty($discover_icons[$i])) continue;
			// slug 留空时自动生成
			$disc_slug = !empty($discover_slugs[$i]) ? $discover_slugs[$i] : 'disc-'.substr(md5(($discover_names[$i] ?? '').$i.microtime(true)), 0, 8);
			$discover_items[] = array(
				'icon' => $discover_icons[$i] ?? '',
				'name' => $discover_names[$i] ?? '',
				'slug' => $disc_slug,
				'url' => NavService::normalize($discover_urls[$i] ?? ''),
				'class' => trim($discover_classes[$i] ?? ''),
				'rank' => intval($discover_ranks[$i] ?? 0),
			);
		}
		$replace['discover_items'] = $discover_items;

		$replace['mobile_nav_items'] = $mobile_items;
		$replace['mobile_nav_enable'] = param('mobile_nav_enable', 0) ? 1 : 0;
		$replace['sidebar_nav_enable'] = param('sidebar_nav_enable', 0) ? 1 : 0;

		// 页脚设置
		$footer_icp = param('footer_icp', '');
		$footer_gongan = param('footer_gongan', '');
		$footer_gongan_url = param('footer_gongan_url', '');
		$footer_copyright = param('footer_copyright', '', FALSE);
		// 版权显示强制开启，不允许用户关闭（MIT 协议要求保留版权声明）
		$footer_show_powered = 1;

		$replace['footer'] = array(
		    'icp' => $footer_icp,
		    'gongan' => $footer_gongan,
		    'gongan_url' => $footer_gongan_url,
		    'copyright' => $footer_copyright,
		    'show_powered' => $footer_show_powered,
		);

		$_save_ok = file_replace_var(APP_PATH.'conf/conf.php', $replace);
		if($_save_ok === FALSE) {
			message(-1, 'conf/conf.php 写入失败，请检查文件权限');
		}
		// 强制清除缓存，确保下次请求读到新配置
		if(function_exists('opcache_invalidate')) {
			opcache_invalidate(APP_PATH.'conf/conf.php', true);
		}
		clearstatcache(true, APP_PATH.'conf/conf.php');
		// 同步更新当前请求的 $conf（避免同进程内后续 hook 读到旧值）
		foreach($replace as $_k => $_v) $conf[$_k] = $_v;
		$_SERVER['conf'] = $conf;

		// hook admin_setting_nav_post_end.php

		admin_log_create('setting_nav', 'setting', '', '修改导航设置');

		// 准备返回给前端的最新数据（合并插件项 + 排序）
		// 前端用这些数据直接重新渲染页面，不依赖浏览器重新加载，完全绕过所有缓存层
		$_return_nav = $nav_items;
		$_return_side = $sidebar_nav_items;
		$_return_mobile = $mobile_items;
		$_return_discover = $discover_items;

		// top/side/mobile 插件项不再混入表格返回数据，仅在顶部展示区显示
		$_plugin_discover_ret = DiscoverService::getPluginDiscoverItems(true);
		$_return_discover = array_merge($_return_discover, $_plugin_discover_ret);

		$nav_sort_ret = function($a, $b) {
			$ra = intval($a['rank'] ?? 0);
			$rb = intval($b['rank'] ?? 0);
			if ($ra !== $rb) return $ra - $rb;
			$_type_order = function($t) { return $t === 'title' ? 0 : ($t === 'top_link' ? 1 : 2); };
			return $_type_order($a['type'] ?? 'link') - $_type_order($b['type'] ?? 'link');
		};
		usort($_return_nav, $nav_sort_ret);
		usort($_return_side, $nav_sort_ret);
		usort($_return_mobile, $nav_sort_ret);
		usort($_return_discover, $nav_sort_ret);

		message(0, lang('save_successfully'), array(
			'nav_items' => $_return_nav,
			'sidebar_nav_items' => $_return_side,
			'mobile_nav_items' => $_return_mobile,
			'discover_items' => $_return_discover,
			'mobile_nav_enable' => $replace['mobile_nav_enable'],
			'sidebar_nav_enable' => $replace['sidebar_nav_enable'],
		));
	}

} elseif($action == 'credits') {

	// hook admin_setting_credits_get_post.php

	if($method == 'GET') {

		// hook admin_setting_credits_get_start.php

		$credits_daily_limit = isset($conf['credits_daily_limit']) ? intval($conf['credits_daily_limit']) : 10;
		$credits_log_retention_days = isset($conf['credits_log_retention_days']) ? intval($conf['credits_log_retention_days']) : 90;
		$credits_types = isset($conf['credits_types']) ? $conf['credits_types'] : array('credits', 'golds', 'rmbs');
		$credits_name = isset($conf['credits_name']) ? $conf['credits_name'] : '积分';
		$golds_name = isset($conf['golds_name']) ? $conf['golds_name'] : '金币';
		$rmbs_name = isset($conf['rmbs_name']) ? $conf['rmbs_name'] : '人民币';

		// 所有可选积分类型
		$all_credits_types = array('credits', 'golds', 'rmbs');

		$header['title'] = lang('admin_setting_credits');
		$header['mobile_title'] = lang('admin_setting_credits');

		// hook admin_setting_credits_get_end.php

		include _include(ADMIN_PATH.'view/htm/setting_credits.htm');

	} else {

		CsrfService::check();

		// hook admin_setting_credits_post_start.php

		$credits_daily_limit = param('credits_daily_limit', 10);
		$credits_log_retention_days = param('credits_log_retention_days', 90);
		$credits_types = param('credits_types', array());
		$credits_name = param('credits_name', '积分', FALSE);
		$golds_name = param('golds_name', '金币', FALSE);
		$rmbs_name = param('rmbs_name', '人民币', FALSE);

		if(empty($credits_types)) {
			message(-1, lang('admin_credits_types_min_one'));
		}

		$replace = array();
		$replace['credits_daily_limit'] = intval($credits_daily_limit);
		$replace['credits_log_retention_days'] = intval($credits_log_retention_days);
		$replace['credits_types'] = $credits_types;
		$replace['credits_name'] = $credits_name;
		$replace['golds_name'] = $golds_name;
		$replace['rmbs_name'] = $rmbs_name;

		file_replace_var(APP_PATH.'conf/conf.php', $replace);

		// hook admin_setting_credits_post_end.php

		admin_log_create('setting_credits', 'setting', '', '修改积分设置');
		message(0, lang('modify_successfully'));
	}
} elseif($action == 'email_log') {

	if($method == 'GET') {
		$page = param(2, 1);
		$pagesize = 20;
		$filter_status = param('status', -1);

		$cond = array();
		if($filter_status >= 0) {
			$cond['status'] = intval($filter_status);
		}

		$loglist = email_log_find($cond, array('logid'=>-1), $page, $pagesize);
		$totalnum = email_log_count($cond);
		$pagination = pagination(url("setting-email_log-{page}"), $totalnum, $page, $pagesize);

		$header['title'] = '邮件发送日志';
		$header['mobile_title'] = '邮件发送日志';

		include _include(ADMIN_PATH."view/htm/setting_email_log.htm");
	}
} elseif($action == 'permalink') {

	// hook admin_setting_permalink_get_post.php

	if($method == 'GET') {

		// hook admin_setting_permalink_get_start.php

		$url_rewrite_on = isset($conf['url_rewrite_on']) ? intval($conf['url_rewrite_on']) : 0;

		// 子目录安装时 Nginx location 路径需带子目录前缀
		$_base_path = isset($conf['base_path']) ? $conf['base_path'] : '';
		$_loc = $_base_path !== '' ? $_base_path . '/' : '/';
		// try_files / error_page 中的 /index.php 是相对 server root 的绝对路径
		// 子目录安装时必须带 base_path 前缀，否则指向根目录的 index.php 导致 404
		$_idx = $_base_path !== '' ? $_base_path . '/index.php' : '/index.php';

		// Nginx rewrite 规则
		// 宝塔面板用户：复制"宝塔伪静态"内容到网站设置→伪静态
		// 自建 Nginx 用户：复制"完整配置"内容到 nginx.conf 的 server 块内
		// 注意：后台 URL 始终使用 ? 格式（./?setting-permalink.htm），不需要伪静态规则
		$nginx_rules = '# ========== 宝塔面板 / 伪静态配置 ==========
# 复制以下内容到 宝塔面板→网站→设置→伪静态
# 注意：不要包含 server 块，只放 location 指令' . ($_base_path !== '' ? "\n# 检测到子目录安装（base_path='$_base_path'），location 已自动适配" : '') . '

# 修复自定义 404 页面（必须放在最前面）
# fastcgi_intercept_errors off: 关闭 Nginx 对 PHP 404 的拦截，让 Xiuno 自定义错误页直接透传
# 宝塔默认 fastcgi_intercept_errors on 会把 PHP 返回的 404 拦截并跳到宝塔默认 404 页
# error_page: 覆盖宝塔默认的 error_page 404 /404.html，改由 index.php 处理
fastcgi_intercept_errors off;
error_page 404 =404 ' . $_idx . ';

# 前台伪静态（后台使用 ? 格式，无需伪静态规则）
location ' . $_loc . '{
    try_files $uri $uri/ ' . $_idx . '$is_args$args;
}

# ========== 自建 Nginx 完整配置 ==========
# 以下为完整 server 块参考，自建 Nginx 用户按需修改
# 宝塔面板用户请忽略此部分

# 自建 Nginx 完整 server 块参考（自建用户按需修改，宝塔用户忽略）
#server {
#    listen 80;
#    server_name your-domain.com;
#
#    # 如需 HTTPS，请自行配置 SSL 证书并添加 HTTP→HTTPS 跳转
#    # 建议在宝塔面板或其他管理工具中一键开启 HTTPS
#
#    root /path/to/xiuno;
#    index index.php index.html;
#
#    location ' . $_loc . '{
#        try_files $uri $uri/ ' . $_idx . '$is_args$args;
#    }
#
#    location ~ \.php$ {
#        fastcgi_pass unix:/tmp/php-cgi.sock;
#        fastcgi_index index.php;
#        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
#        include fastcgi_params;
#    }
#
#    location ~ \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
#        expires 30d;
#        access_log off;
#    }
#
#    location ~ /\. {
#        deny all;
#    }
#}';

		// Apache rewrite 规则
		// 根目录 .htaccess 已内置，Apache 用户无需额外配置
		// 后台 URL 使用 ? 格式，不需要伪静态规则
		$apache_rules = '# 根目录 .htaccess（已内置，无需手动配置）
RewriteEngine On

# 如需 HTTPS 强制跳转，请在 Web 服务器层面配置
# 或取消以下注释启用：
# RewriteCond %{HTTPS} off
# RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# 伪静态核心规则（仅前台需要，后台使用 ? 格式）
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [L,QSA]

# 禁止访问隐藏文件
<FilesMatch "^\.">
    Deny from all
</FilesMatch>';

		// Caddy v2 伪静态规则
		// 将以下内容添加到 Caddyfile 的站点块（example.com { ... }）中
		// 后台 URL 使用 ? 格式，不需要伪静态规则
		$_caddy_index = $_base_path !== '' ? $_base_path . '/index.php' : '/index.php';
		$caddy_rules = '# ========== Caddy v2 伪静态配置 ==========
# 将以下内容添加到 Caddyfile 的站点块中：
# example.com {
#     ...（粘贴以下指令）
# }' . ($_base_path !== '' ? "\n# 检测到子目录安装（base_path='$_base_path'），路径已自动适配" : '') . '

# 前台伪静态核心规则（后台使用 ? 格式，无需伪静态）
try_files {path} {path}/ ' . $_caddy_index . '?{query}

# PHP FastCGI 处理（如已使用 php_fastcgi 指令可省略）
# php_fastcgi unix//tmp/php-cgi.sock

# 静态资源缓存 30 天
@static {
    path *.js *.css *.png *.jpg *.jpeg *.gif *.ico *.svg *.woff *.woff2 *.ttf *.eot
}
header @static Cache-Control "public, max-age=2592000"

# 禁止访问隐藏文件（如 .git/.env）
@hidden path /.*
respond @hidden 404

# ========== 完整 Caddyfile 参考 ==========
# 以下为完整站点配置参考，按需修改域名、根目录、PHP 监听方式
#example.com {
#    root * /path/to/xiuno
#    encode gzip
#
#    # PHP FastCGI（按实际监听方式修改）
#    php_fastcgi unix//tmp/php-cgi.sock
#
#    # 前台伪静态核心规则
#    try_files {path} {path}/ ' . $_caddy_index . '?{query}
#
#    # 静态资源缓存
#    @static {
#        path *.js *.css *.png *.jpg *.jpeg *.gif *.ico *.svg *.woff *.woff2 *.ttf *.eot
#    }
#    header @static Cache-Control "public, max-age=2592000"
#
#    # 禁止访问隐藏文件
#    @hidden path /.*
#    respond @hidden 404
#}';

		$header['title'] = lang('admin_setting_permalink');
		$header['mobile_title'] = lang('admin_setting_permalink');

		// hook admin_setting_permalink_get_end.php

		include _include(ADMIN_PATH.'view/htm/setting_permalink.htm');

	} else {

		CsrfService::check();

		$url_rewrite_on = param('url_rewrite_on', 0);
		$skip_detect = param('skip_detect', 0);
		// 只允许 0, 1, 3, 4, 5
		if(!in_array($url_rewrite_on, array(0, 1, 3, 4, 5))) {
			$url_rewrite_on = 0;
		}

		// hook admin_setting_permalink_post_start.php

		$old_url_rewrite_on = isset($conf['url_rewrite_on']) ? intval($conf['url_rewrite_on']) : 0;

		// 保存新设置
		$replace = array();
		$replace['url_rewrite_on'] = $url_rewrite_on;
		file_replace_var(APP_PATH.'conf/conf.php', $replace);

		// ponytail: 固定链接切换会改变全站 URL 生成规则，必须清 tmp 编译缓存 + 数据缓存 + OPcache
		// 否则模板编译产物 / NavService 缓存 / opcache 字节码仍是旧 url_rewrite_on，前端 404
		// 原条件 >0 && !=old && !skip_detect 漏掉切到 0 模式和 skip_detect 场景
		if(class_exists('CacheService', false)) {
			CacheService::clearByType(array('tmp', 'data', 'opcache'));
		}

		// 如果切换到需要 rewrite 的模式，检测 rewrite 是否生效
		if($url_rewrite_on > 0 && $url_rewrite_on != $old_url_rewrite_on && !$skip_detect) {

			// 构建测试 URL：使用新格式请求首页
			$base_url = http_url_path();
			$test_url = '';
			if($url_rewrite_on == 1) {
				$test_url = $base_url . 'index.htm';
			} elseif($url_rewrite_on == 3) {
				$test_url = $base_url . 'index';
			} elseif($url_rewrite_on == 4) {
				$test_url = $base_url . 'index.html';
			} elseif($url_rewrite_on == 5) {
				// 路径+html 格式：index → /index.html
				$test_url = $base_url . 'index.html';
			}

			// 发起 HTTP 请求检测（支持 HTTPS）
			$rewrite_ok = FALSE;
			if($test_url) {
				// 优先使用 curl（更可靠，支持 HTTPS）
				if(function_exists('curl_init')) {
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $test_url);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_TIMEOUT, 5);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
				curl_setopt($ch, CURLOPT_NOBODY, false);
					$response = curl_exec($ch);
				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				// PHP 8.0+ curl_close() 已废弃且无效果，unset 释放资源
				unset($ch);
					// 2xx 或 3xx 响应码都说明 rewrite 生效
					if($response !== FALSE && $http_code > 0 && $http_code < 500) {
						$rewrite_ok = TRUE;
					}
				} else {
					// 回退到 file_get_contents，同时支持 HTTPS
					$ctx = stream_context_create(array(
						'http' => array(
							'method' => 'GET',
							'timeout' => 5,
							'follow_location' => 0,
						),
						'ssl' => array(
						'verify_peer' => true,
						'verify_peer_name' => true,
					),
					));
					$response = @file_get_contents($test_url, false, $ctx);
					if($response !== FALSE) {
						$rewrite_ok = TRUE;
					}
				}
			}

			if(!$rewrite_ok) {
			// ponytail: 检测失败不回滚，保留用户选择，仅返回警告；
			// 自动回滚会让用户以为保存没生效，且掩盖了真实的 rewrite 配置问题
			message(-1, lang('admin_permalink_detect_fail'));
		}
		}

		// hook admin_setting_permalink_post_end.php

		admin_log_create('setting_seo', 'setting', '', '修改永久链接设置：url_rewrite_on=' . $url_rewrite_on);
		message(0, lang('admin_permalink_detect_success'));
	}

} elseif($action == 'email_template') {

    if($method == 'GET') {
        $confile = APP_PATH . 'conf/email_templates.conf.php';
        $templates = array();
        if(is_file($confile)) {
            $templates = include $confile;
        }
        if(!is_array($templates)) $templates = array();

        // 确保所有默认模板都存在
        $default_keys = array('user_create_code', 'user_resetpw_code', 'email_change_code');
        foreach($default_keys as $key) {
            if(!isset($templates[$key])) {
                // 加载默认模板
                $default_templates = include APP_PATH . 'conf/email_templates.conf.php';
                if(isset($default_templates[$key])) {
                    $templates[$key] = $default_templates[$key];
                }
            }
        }

        $header['title'] = '邮件模板设置';
        $header['mobile_title'] = '邮件模板设置';

        include _include(ADMIN_PATH."view/htm/setting_email_template.htm");

    } else {
        CsrfService::check();

        $template_keys = param('template_key', array());
        $subjects = param('subject', array(''));
        $bodies = param('body', array('', ''));  // 注意 body 可能有 HTML

        $templates = array();
        foreach($template_keys as $k => $key) {
            if(empty($key)) continue;
            $templates[$key] = array(
                'subject' => $subjects[$k],
                'body' => $bodies[$k],
            );
        }

        $r = file_put_contents_try(APP_PATH.'conf/email_templates.conf.php', "<?php\r\nreturn ".var_export($templates, true).";\r\n?>");
        !$r AND message(-1, '保存失败，请检查 conf 目录权限');

        // ponytail: 同 smtp.conf.php,opcache 缓存导致 reload 读旧值
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate(APP_PATH.'conf/email_templates.conf.php', true);
        }

        admin_log_create('setting_email_tpl', 'setting', '', '修改邮件模板设置');
        message(0, lang('save_successfully'));
    }

} elseif($action == 'display') {

	// hook admin_setting_display_get_post.php

	if($method == 'GET') {

		// hook admin_setting_display_get_start.php

		$status_labels = thread_status_labels();

		// 首页版块过滤
		$home_forum_ids = isset($conf['home_forum_ids']) ? $conf['home_forum_ids'] : array();

		// 发帖页版块过滤
		$post_forum_ids = isset($conf['post_forum_ids']) ? $conf['post_forum_ids'] : array();

		// 编辑器提示文字
		$editor_tip = isset($conf['editor_tip']) ? $conf['editor_tip'] : '';

		// 版块列表（用于版块过滤选择）
		$all_forums = isset($forumlist_show) ? $forumlist_show : array();

		$header['title'] = lang('admin_setting_display');
		$header['mobile_title'] = lang('admin_setting_display');

		// hook admin_setting_display_get_end.php

		include _include(ADMIN_PATH.'view/htm/setting_display.htm');

	} else {

		CsrfService::check();

		// hook admin_setting_display_post_start.php

		$status_key = param('status_key', array());
		$status_icon = param('status_icon', array(''));
		$status_text = param('status_text', array(''));
		$show_icons = param('show_icon_val', array());
		$show_texts = param('show_text_val', array());
		$status_color = param('status_color_text', array('#6c757d'));
		$status_text_color = param('status_text_color_text', array('#ffffff'));
		$status_badge_class = param('status_badge_class', array('badge'));
		$status_badge_font_size = param('status_badge_font_size', array('0.7em'));
		$status_rank = param('status_rank', array(0));

		$labels = array();
		foreach($status_key as $k => $key) {
			if(empty($key)) continue;
			$color = isset($status_color[$k]) ? $status_color[$k] : '#6c757d';
			if(!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
				$color = '#6c757d';
			}
			$text_color = isset($status_text_color[$k]) ? $status_text_color[$k] : '#ffffff';
			if(!preg_match('/^#[0-9a-fA-F]{6}$/', $text_color)) {
				$text_color = '#ffffff';
			}
			$labels[$key] = array(
				'icon' => $status_icon[$k],
				'text' => $status_text[$k],
				'show_icon' => !empty($show_icons[$k]) ? true : false,
				'show_text' => !empty($show_texts[$k]) ? true : false,
				'color' => $color,
				'text_color' => $text_color,
				'badge_class' => !empty($status_badge_class[$k]) ? $status_badge_class[$k] : 'badge',
				'badge_font_size' => $status_badge_font_size[$k],
				'rank' => intval($status_rank[$k]),
			);
		}

		kv_set('thread_status_labels', xn_json_encode($labels));

		// 首页版块过滤
		$home_forum_ids = param('home_forum_ids', array());
		$home_forum_ids = array_map('intval', $home_forum_ids);

		// 发帖页版块过滤
		$post_forum_ids = param('post_forum_ids', array());
		$post_forum_ids = array_map('intval', $post_forum_ids);

		// 编辑器提示文字（纯文本，保留换行）
	// 关闭 htmlspecialchars：该值后续经 json_encode 输出到 JS placeholder，htmlspecialchars 会把 " 破坏成 &quot; 导致显示乱码
	$editor_tip = trim(param('editor_tip', '', FALSE));

		$display_replace = array();
		$display_replace['home_forum_ids'] = $home_forum_ids;
		$display_replace['post_forum_ids'] = $post_forum_ids;
		$display_replace['editor_tip'] = $editor_tip;
		file_replace_var(APP_PATH.'conf/conf.php', $display_replace);

		// hook admin_setting_display_post_end.php

		admin_log_create('setting_display', 'setting', '', '修改显示设置');
		message(0, lang('save_successfully'));
	}

} elseif($action == 'avatar') {

	// hook admin_setting_avatar_get_post.php

	if($method == 'GET') {

		// hook admin_setting_avatar_get_start.php

		// 读取当前头像形状(avatar_component_get_shape 走 setting_get('avatar_shape'))
		$avatar_shape = function_exists('setting_get') ? setting_get('avatar_shape') : '';
		if(!in_array($avatar_shape, array('rounded', 'circle', 'square'), true)) {
			$avatar_shape = 'rounded';
		}

		$header['title'] = lang('admin_setting_avatar');
		$header['mobile_title'] = lang('admin_setting_avatar');

		// hook admin_setting_avatar_get_end.php

		include _include(ADMIN_PATH.'view/htm/setting_avatar.htm');

	} else {

		CsrfService::check();

		// hook admin_setting_avatar_post_start.php

		$avatar_shape = param('avatar_shape', 'rounded');
		// 白名单校验,非法值回退到默认 rounded
		if(!in_array($avatar_shape, array('rounded', 'circle', 'square'), true)) {
			$avatar_shape = 'rounded';
		}

		// 用 setting_set 保存(走 kv 存储,avatar_component_get_shape 用 setting_get 读取)
		// setting_set 内部已通过 kv_cache_set 同步更新 db 和 cache,无需手动清缓存
		setting_set('avatar_shape', $avatar_shape);

		// hook admin_setting_avatar_post_end.php

		admin_log_create('setting_avatar', 'setting', '', '修改头像设置: shape=' . $avatar_shape);

		// htmx 请求:返回 HTML 片段(成功提示带 data-code),非 htmx 请求:返回 JSON
		if(isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true') {
			header('Content-Type: text/html; charset=utf-8');
			echo '<div class="alert alert-success d-flex align-items-center mb-0" data-code="0" role="alert">';
			echo '<i class="ti ti-circle-check me-2"></i>';
			echo '<span>' . esc_html(lang('admin_setting_avatar_saved')) . '</span>';
			echo '</div>';
			exit;
		}
		message(0, lang('admin_setting_avatar_saved'));
	}

}

// hook admin_setting_end.php

?>