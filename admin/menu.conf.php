<?php

return array(
	'setting' => array(
		'url'=>url('setting-base'), 
		'text'=>lang('setting'), 
		'icon'=>'icon-cog', 
		'tab'=> array (
			'base'=>array('url'=>url('setting-base'), 'text'=>lang('admin_setting_base')),
		'permalink'=>array('url'=>url('setting-permalink'), 'text'=>lang('admin_setting_permalink')),
			'ai'=>array('url'=>url('setting-ai'), 'text'=>lang('admin_setting_ai')),
			'smtp'=>array('url'=>url('setting-smtp'), 'text'=>lang('admin_setting_smtp')),
			'nav'=>array('url'=>url('setting-nav'), 'text'=>lang('admin_setting_nav')),
			'display'=>array('url'=>url('setting-display'), 'text'=>lang('admin_setting_display')),
			'upload'=>array('url'=>url('setting-upload'), 'text'=>lang('admin_setting_upload')),
			'credits'=>array('url'=>url('setting-credits'), 'text'=>lang('admin_setting_credits_tab')),
			'credits_rule'=>array('url'=>url('credits_rule-global'), 'text'=>lang('admin_credits_rule')),
		)
	),
	'forum' => array(
		'url'=>url('forum-list'),
		'text'=>lang('forum'),
		'icon'=>'icon-comment',
		'tab'=> array (
		)
	),
	'thread' => array(
		'url'=>url('thread-list'),
		'text'=>lang('thread'),
		'icon'=>'icon-comment',
		'tab'=> array (
			'list'=>array('url'=>url('thread-list'), 'text'=>lang('admin_thread_batch')),
		)
	),
	'audit' => array(
		'url'=>url('audit'),
		'text'=>lang('admin_content_audit'),
		'icon'=>'ti-clipboard-check',
		'tab'=> array (
		)
	),
	'attach' => array(
		'url'=>url('attach-list'),
		'text'=>lang('admin_attach_manage'),
		'icon'=>'ti-paperclip',
		'tab'=> array (
		)
	),
	'user' => array(
		'url'=>url('user-list'), 
		'text'=>lang('user'), 
		'icon'=>'icon-user',
		'tab'=> array (
			'list'=>array('url'=>url('user-list'), 'text'=>lang('admin_user_list')),
			'group'=>array('url'=>url('group-list'), 'text'=>lang('admin_user_group')),
			'create'=>array('url'=>url('user-create'), 'text'=>lang('admin_user_create')),
		)
	),
	'security' => array(
		'url'=>url('security-post_limit'),
		'text'=>lang('admin_security_setting_short'),
		'icon'=>'ti-shield-lock',
		'tab'=> array (
			'post_limit'=>array('url'=>url('security-post_limit'), 'text'=>lang('admin_post_limit')),
			'account'=>array('url'=>url('security-account'), 'text'=>lang('admin_account_security')),
			'content'=>array('url'=>url('security-content'), 'text'=>lang('admin_content_permission')),
			'other'=>array('url'=>url('security-other'), 'text'=>lang('admin_other_settings')),
			'captcha'=>array('url'=>url('security-captcha'), 'text'=>lang('admin_captcha_config')),
			'words'=>array('url'=>url('security-words'), 'text'=>lang('admin_sensitive_words')),
		)
	),
	'log' => array(
		'url'=>url('log-credits'),
		'text'=>lang('admin_log_short'),
		'icon'=>'ti-file-text',
		'tab'=> array (
			'credits'=>array('url'=>url('log-credits'), 'text'=>lang('admin_credits_log')),
			'login'=>array('url'=>url('log-login'), 'text'=>lang('admin_login_log')),
			'operation'=>array('url'=>url('log-operation'), 'text'=>lang('admin_log_operation')),
			'audit'=>array('url'=>url('log-audit'), 'text'=>lang('admin_log_audit')),
		)
	),
	'health' => array(
		'url'=>url('health'),
		'text'=>lang('admin_site_health'),
		'icon'=>'ti-heart-rate-monitor',
		'tab'=> array (
		)
	),
	'other' => array(
		'url'=>url('other'), 
		'text'=>lang('other'), 
		'icon'=>'icon-wrench',
		'tab'=> array (
			'cache_setting'=>array('url'=>url('other-cache_setting'), 'text'=>lang('admin_cache_setting')),
			'cache'=>array('url'=>url('other-cache'), 'text'=>lang('admin_other_cache')),
			'upgrade'=>array('url'=>url('upgrade'), 'text'=>lang('admin_system_upgrade')),
		)
	),
	'notice' => array(
		'url'=>url('notice-create'), 
		'text'=>lang('admin_system_notice'), 
		'icon'=>'icon-bell',
		'tab'=> array (
			'post'=>array('url'=>url('notice-create'), 'text'=>'发送消息'),
			'list'=>array('url'=>url('notice-list'), 'text'=>lang('notice_admin_notice_list')),
		)
	),
	'icon_preview' => array(
		'url'=>url('other-icon_preview'), 
		'text'=>lang('admin_icon_preview'), 
		'icon'=>'ti-icons',
		'tab'=> array (
		)
	),
	'api' => array(
		'url'=>url('api-doc'), 
		'text'=>'API', 
		'icon'=>'ti-code',
		'tab'=> array (
			'doc'=>array('url'=>url('api-doc'), 'text'=>lang('admin_api_doc')),
			'debug'=>array('url'=>url('api-debug'), 'text'=>lang('admin_api_debug')),
		)
	),
	'scanner' => array(
		'url'=>url('plugin-scanner'), 
		'text'=>lang('admin_compatibility_check'), 
		'icon'=>'ti-shield-check',
		'tab'=> array (
		)
	),
	'plugin' => array(
		'url'=>url('plugin'), 
		'text'=>lang('plugin'), 
		'icon'=>'icon-cogs',
		'tab'=> array (
			'local'=>array('url'=>url('plugin-local'), 'text'=>lang('admin_plugin_local_list')),
		)
	),
	'theme' => array(
		'url'=>url('theme-list'), 
		'text'=>lang('admin_theme_setting_short'), 
		'icon'=>'icon-cogs',
		'tab'=> array (
		)
	)
);

?>