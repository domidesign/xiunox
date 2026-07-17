<?php
/**
 * XiunoX 路由系统
 *
 * 提供集中式路由表 + 命名快捷函数，替代模板中硬编码的 url("xxx-$id") 写法。
 *
 * 设计目标：
 *   1. 模板不再硬编码 "thread-$tid" 等路由字符串
 *   2. 插件可通过 hook model_route_table_end.php 精准 hook 单个路由
 *   3. 命名快捷函数让 IDE 可补全、可重构
 *
 * 调用链：
 *   命名函数（thread_url()）→ route_url() → url()（处理伪静态格式）
 *
 * 插件扩展示例：
 *   // plugin/seo/hook/model_route_table_end.php
 *   <?php
 *   $routes['thread'] = 't/{tid}';        // 修改帖子 URL 格式
 *   $routes['myplugin'] = 'myplugin-{id}'; // 新增插件路由
 */

!defined('DEBUG') AND exit('Access Denied');

// hook model_route_start.php

/**
 * 获取路由表（带静态缓存）
 *
 * 路由表为 key => 模板字符串 的关联数组。
 * 模板中用 {xxx} 占位符表示参数，如 'thread-{tid}'。
 * 插件可通过 hook model_route_table_end.php 修改或扩展。
 *
 * @return array
 */
function route_table() {
	static $routes = null;
	if ($routes === null) {
		$routes = array(

			// ===== 帖子（thread） =====
			'thread'                => 'thread-{tid}',
			'thread_page'           => 'thread-{tid}-{page}',
			'thread_create'         => 'thread-create',
			'thread_create_fid'     => 'thread-create-{fid}',
			'thread_update'         => 'thread-update-{tid}',
			'thread_delete'         => 'thread-delete-{tid}',
			'thread_like'           => 'thread-like-{tid}-{pid}',
			'thread_unlike'         => 'thread-unlike-{tid}-{pid}',
			'thread_favorite'       => 'thread-favorite-{tid}',
			'thread_announcement'   => 'thread-announcement-{tid}',

			// ===== 帖子楼层（post） =====
			'post_create'           => 'post-create-{tid}',
			'post_create_page'      => 'post-create-{tid}-{page}',
			'post_update'           => 'post-update-{pid}',
			'post_delete'           => 'post-delete-{pid}',

			// ===== 用户（user） =====
			'user'                  => 'user-{uid}',
			'user_thread'           => 'user-thread-{uid}',
			'user_thread_page'      => 'user-thread-{uid}-{page}',
			'user_post'             => 'user-post-{uid}',
			'user_post_page'        => 'user-post-{uid}-{page}',
			'user_following'        => 'user-following-{uid}',
			'user_following_page'   => 'user-following-{uid}-{page}',
			'user_followers'        => 'user-followers-{uid}',
			'user_followers_page'   => 'user-followers-{uid}-{page}',
			'user_favorite'         => 'user-favorite-{uid}',
			'user_favorite_page'    => 'user-favorite-{uid}-{page}',
			'user_like'             => 'user-like-{uid}',
			'user_like_page'        => 'user-like-{uid}-{page}',
			'user_follow'           => 'user-follow-{uid}',
			'user_login'            => 'user-login',
			'user_create'           => 'user-create',
			'user_logout'           => 'user-logout',
			'user_resetpw'          => 'user-resetpw',
			'user_resetpw_complete' => 'user-resetpw_complete',
			'user_ai_setting'       => 'user-ai_setting',
			'user_send_code'        => 'user-send_code-{scene}',

			// ===== 版块（forum） =====
			'forum'                 => 'forum-{fid}',
			'forum_page'            => 'forum-{fid}-{page}',
			'forum_create'          => 'forum-create',
			'forum_follow'          => 'forum-follow-{fid}',
			'forum_unfollow'        => 'forum-unfollow-{fid}',
			'forum_followers'       => 'forum-followers-{fid}',
			'forum_followers_page'  => 'forum-followers-{fid}-{page}',
			'forum_follow_status'   => 'forum-follow_status-{fid}',
			'forum_members_block'   => 'forum-members_block-{fid}',

			// ===== 个人中心（my） =====
			'my'                    => 'my',
			'my_thread'             => 'my-thread',
			'my_thread_page'        => 'my-thread-{page}',
			'my_post'               => 'my-post',
			'my_post_page'          => 'my-post-{page}',
			'my_favorite'           => 'my-favorite',
			'my_favorite_page'      => 'my-favorite-{page}',
			'my_like'               => 'my-like',
			'my_like_page'          => 'my-like-{page}',
			'my_following'          => 'my-following',
			'my_following_page'     => 'my-following-{page}',
			'my_followers'          => 'my-followers',
			'my_followers_page'     => 'my-followers-{page}',
			'my_feed'               => 'my-feed',
			'my_notify'             => 'my-notify',
			'my_notify_page'        => 'my-notify-{page}',
			'my_notify_list'        => 'my-notify-{type}',
			'my_notify_list_page'   => 'my-notify-{type}-{page}',
			'my_notify_read'        => 'my-notify_read',
			'my_notify_read_nid'    => 'my-notify_read-{nid}',
			'my_notify_dropdown'    => 'my-notify_dropdown',
			'my_notify_mark_read'   => 'my-notify_mark_read',
			'my_notify_unread_count' => 'my-notify_unread_count',
			'my_profile'            => 'my-profile',
			'my_password'           => 'my-password',
			'my_email'              => 'my-email',
			'my_avatar'             => 'my-avatar',
			'my_avatar_preset'      => 'my-avatar_preset',
			'my_security'           => 'my-security',
			'my_ai'                 => 'my-ai',
			'my_credits'            => 'my-credits',
			'my_credits_page'       => 'my-credits-{page}',
			'my_credits_rules'      => 'my-credits_rules',
			'my_credits_check'      => 'my-credits_check',
			'my_send_email_code'    => 'my-send_email_code',
			'my_level'              => 'my-level',

			// ===== 通知（notice） =====
			'notice_list'           => 'notice-list',
			'notice_list_type'      => 'notice-list-{type}',
			'notice_list_page'      => 'notice-list-{page}',
			'notice_list_type_page' => 'notice-list-{type}-{page}',
			'notice_mark_read'      => 'notice-mark_read',
			'notice_announcements'  => 'notice-announcements',

			// ===== 模块操作（mod） =====
			'mod_delete'            => 'mod-delete',
			'mod_move'              => 'mod-move',
			'mod_top'               => 'mod-top',
			'mod_top_post'         => 'mod-top_post',
			'mod_close'             => 'mod-close',
			'mod_digest'            => 'mod-digest',
			'mod_announcement'      => 'mod-announcement',
			'mod_audit'             => 'mod-audit',
			'mod_audit_post'        => 'mod-audit_post',
			'mod_ban_user'          => 'mod-ban_user',

			// ===== 全局通用 =====
			'index'                 => 'index',
			'forums'                => 'forums',
			'more'                  => 'more',
			'search'                => 'search',
			'search_page'           => 'search-{page}',
			'rank'                  => 'rank',
			'sitemap'               => 'sitemap.xml',
			'browser'               => 'browser',
			'captcha'               => 'captcha-generate-{scene}',
			'lang'                  => 'lang-{code}',
			'theme'                 => 'theme',

			// ===== 后台 - 插件 =====
		'admin_plugin'                  => 'plugin',
		'admin_plugin_setting'          => 'plugin-setting-{dir}',
		'admin_plugin_install'          => 'plugin-install-{dir}',
		'admin_plugin_disable'          => 'plugin-disable-{dir}',
		'admin_plugin_enable'           => 'plugin-enable-{dir}',
		'admin_plugin_unstall'          => 'plugin-unstall-{dir}',
		'admin_plugin_upgrade'          => 'plugin-upgrade-{dir}',
			'admin_plugin_scanner'          => 'plugin-scanner',
			'admin_plugin_scanner_preinstall' => 'plugin-scanner-preinstall',
			'admin_plugin_scanner_do'       => 'plugin-scanner-do',
			'admin_plugin_scanner_export'   => 'plugin-scanner-export',

			// ===== 后台 - 版块 =====
			'admin_forum_create'            => 'forum-create',
			'admin_forum_update'            => 'forum-update-{fid}',
			'admin_forum_delete'            => 'forum-delete-{fid}',
			'admin_forum_list'              => 'forum-list',

			// ===== 后台 - 用户 =====
			'admin_user_create'             => 'user-create',
			'admin_user_update'             => 'user-update-{uid}',
			'admin_user_list'               => 'user-list',

			// ===== 后台 - 用户组 =====
			'admin_group_list'              => 'group-list',
			'admin_group_update'            => 'group-update-{gid}',

			// ===== 后台 - 设置 =====
			'admin_setting'                 => 'setting-{section}',
			'admin_setting_smtp_test'       => 'setting-smtp_test',

			// ===== 后台 - 安全 =====
			'admin_security'                => 'security-{section}',

			// ===== 后台 - 日志 =====
			'admin_log'                     => 'log-{type}',
			'admin_log_page'                => 'log-{type}-{page}',

			// ===== 后台 - 积分规则 =====
			'admin_credits_rule'            => 'credits_rule-{type}',
			'admin_credits_rule_forum'      => 'credits_rule-forum-{fid}',

			// ===== 后台 - 通知 =====
			'admin_notice_create'           => 'notice-create',
			'admin_notice_publish'          => 'notice-publish',
			'admin_notice_delete'           => 'notice-delete',
			'admin_notice_list'             => 'notice-{type}_list',

			// ===== 后台 - 帖子管理 =====
			'admin_thread_scan'             => 'thread-scan',
			'admin_thread_found'            => 'thread-found-{page}',
			'admin_thread_batch'            => 'thread-batch',
			'admin_thread_recycle'          => 'thread-recycle',

			// ===== 后台 - 附件 =====
			'admin_attach_list'             => 'attach-list',
			'admin_attach_delete'           => 'attach-delete',
			'admin_attach_batch_delete'      => 'attach-batch_delete',

			// ===== 后台 - API =====
			'admin_api_doc'                 => 'api-doc',
			'admin_api_debug'               => 'api-debug',
			'admin_api_token_delete'        => 'api-token_delete-{id}',
			'admin_api_settings'            => 'api-settings',
			'admin_api_app_create'          => 'api-app_create',
			'admin_api_app_update'          => 'api-app_update',
			'admin_api_app_delete'          => 'api-app_delete',
			'admin_api_app_reset_secret'    => 'api-app_reset_secret',
			'admin_api_settings_save'       => 'api-settings_save',

			// ===== 后台 - 缓存与系统 =====
			'admin_cache'                   => 'other-cache',
			'admin_cache_setting'           => 'other-cache_setting',
			'admin_upgrade'                 => 'upgrade-do',
			'admin_health'                  => 'health',
		'admin_phpinfo'                 => 'index-phpinfo',
			'admin_logout'                  => 'index-logout',
			'admin_login'                   => 'index-login',
			'admin_audit'                   => 'audit',
			'admin_theme_default'           => 'theme-default',
			'admin_theme_brand'             => 'theme-brand',

			// ===== 后台跳前台 =====
			'frontend_thread'               => '../thread-{tid}',
			'frontend_user'                 => '../user-{uid}',
			'frontend_forum'                => '../forum-{fid}',

		);
		// hook model_route_table_end.php
	}
	return $routes;
}

/**
 * 通用路由 URL 生成
 *
 * @param string $name  路由名（route_table 的 key）
 * @param array  $args  占位符替换（如 ['tid' => 123]）
 * @param array  $query 查询参数（如 ['order' => 'tid']）
 * @return string
 */
function route_url($name, $args = array(), $query = array()) {
	$routes = route_table();
	$template = isset($routes[$name]) ? $routes[$name] : $name;

	// 替换占位符
	if ($args) {
		foreach ($args as $key => $val) {
			$template = str_replace('{' . $key . '}', (string)$val, $template);
		}
	}

	return url($template, $query);
}

// hook model_route_func_end.php


// ============================================================
// 命名快捷函数（按模块分组）
// 模板中调用示例：echo thread_url($tid);
// ============================================================

// ----- 帖子 -----
function thread_url($tid, $query = array())               { return route_url('thread', ['tid' => $tid], $query); }
function thread_page_url($tid, $page, $query = array())   { return route_url('thread_page', ['tid' => $tid, 'page' => $page], $query); }
function thread_create_url($fid = null, $query = array()) {
	return $fid === null ? route_url('thread_create', [], $query) : route_url('thread_create_fid', ['fid' => $fid], $query);
}
function thread_update_url($tid, $query = array())         { return route_url('thread_update', ['tid' => $tid], $query); }
function thread_delete_url($tid, $query = array())          { return route_url('thread_delete', ['tid' => $tid], $query); }
function thread_like_url($tid, $pid, $query = array())     { return route_url('thread_like', ['tid' => $tid, 'pid' => $pid], $query); }
function thread_unlike_url($tid, $pid, $query = array())   { return route_url('thread_unlike', ['tid' => $tid, 'pid' => $pid], $query); }
function thread_favorite_url($tid, $query = array())       { return route_url('thread_favorite', ['tid' => $tid], $query); }
function thread_announcement_url($tid, $query = array())   { return route_url('thread_announcement', ['tid' => $tid], $query); }

// ----- 帖子楼层 -----
function post_create_url($tid, $page = null, $query = array()) {
	return $page === null ? route_url('post_create', ['tid' => $tid], $query) : route_url('post_create_page', ['tid' => $tid, 'page' => $page], $query);
}
function post_update_url($pid, $query = array())           { return route_url('post_update', ['pid' => $pid], $query); }
function post_delete_url($pid, $query = array())           { return route_url('post_delete', ['pid' => $pid], $query); }

// ----- 用户 -----
function user_url($uid, $query = array())                  { return route_url('user', ['uid' => $uid], $query); }
function user_thread_url($uid, $page = null, $query = array()) {
	return $page === null ? route_url('user_thread', ['uid' => $uid], $query) : route_url('user_thread_page', ['uid' => $uid, 'page' => $page], $query);
}
function user_post_url($uid, $page = null, $query = array()) {
	return $page === null ? route_url('user_post', ['uid' => $uid], $query) : route_url('user_post_page', ['uid' => $uid, 'page' => $page], $query);
}
function user_following_url($uid, $page = null, $query = array()) {
	return $page === null ? route_url('user_following', ['uid' => $uid], $query) : route_url('user_following_page', ['uid' => $uid, 'page' => $page], $query);
}
function user_followers_url($uid, $page = null, $query = array()) {
	return $page === null ? route_url('user_followers', ['uid' => $uid], $query) : route_url('user_followers_page', ['uid' => $uid, 'page' => $page], $query);
}
function user_favorite_url($uid, $page = null, $query = array()) {
	return $page === null ? route_url('user_favorite', ['uid' => $uid], $query) : route_url('user_favorite_page', ['uid' => $uid, 'page' => $page], $query);
}
function user_like_url($uid, $page = null, $query = array()) {
	return $page === null ? route_url('user_like', ['uid' => $uid], $query) : route_url('user_like_page', ['uid' => $uid, 'page' => $page], $query);
}
function user_follow_url($uid, $query = array())           { return route_url('user_follow', ['uid' => $uid], $query); }
function user_login_url($query = array())                  { return route_url('user_login', [], $query); }
function user_create_url($query = array())                { return route_url('user_create', [], $query); }
function user_logout_url($query = array())                { return route_url('user_logout', [], $query); }
function user_resetpw_url($query = array())               { return route_url('user_resetpw', [], $query); }
function user_resetpw_complete_url($query = array())       { return route_url('user_resetpw_complete', [], $query); }
function user_ai_setting_url($query = array())            { return route_url('user_ai_setting', [], $query); }
function user_send_code_url($scene = 'user_create', $query = array()) { return route_url('user_send_code', ['scene' => $scene], $query); }

// ----- 版块 -----
function forum_url($fid, $query = array())                 { return route_url('forum', ['fid' => $fid], $query); }
function forum_page_url($fid, $page, $query = array())    { return route_url('forum_page', ['fid' => $fid, 'page' => $page], $query); }
function forum_create_url($query = array())               { return route_url('forum_create', [], $query); }
function forum_follow_url($fid, $query = array())         { return route_url('forum_follow', ['fid' => $fid], $query); }
function forum_unfollow_url($fid, $query = array())        { return route_url('forum_unfollow', ['fid' => $fid], $query); }
function forum_followers_url($fid, $page = null, $query = array()) {
	return $page === null ? route_url('forum_followers', ['fid' => $fid], $query) : route_url('forum_followers_page', ['fid' => $fid, 'page' => $page], $query);
}
function forum_follow_status_url($fid, $query = array())   { return route_url('forum_follow_status', ['fid' => $fid], $query); }
function forum_members_block_url($fid, $query = array()) { return route_url('forum_members_block', ['fid' => $fid], $query); }

// ----- 个人中心 -----
function my_url($query = array())                         { return route_url('my', [], $query); }
function my_thread_url($page = null, $query = array())    {
	return $page === null ? route_url('my_thread', [], $query) : route_url('my_thread_page', ['page' => $page], $query);
}
function my_post_url($page = null, $query = array())      {
	return $page === null ? route_url('my_post', [], $query) : route_url('my_post_page', ['page' => $page], $query);
}
function my_favorite_url($page = null, $query = array())  {
	return $page === null ? route_url('my_favorite', [], $query) : route_url('my_favorite_page', ['page' => $page], $query);
}
function my_like_url($page = null, $query = array())      {
	return $page === null ? route_url('my_like', [], $query) : route_url('my_like_page', ['page' => $page], $query);
}
function my_following_url($page = null, $query = array()) {
	return $page === null ? route_url('my_following', [], $query) : route_url('my_following_page', ['page' => $page], $query);
}
function my_followers_url($page = null, $query = array()) {
	return $page === null ? route_url('my_followers', [], $query) : route_url('my_followers_page', ['page' => $page], $query);
}
function my_feed_url($query = array())                    { return route_url('my_feed', [], $query); }
function my_notify_url($page = null, $query = array())    {
	return $page === null ? route_url('my_notify', [], $query) : route_url('my_notify_page', ['page' => $page], $query);
}
function my_notify_list_url($type, $page = null, $query = array()) {
	if ($page === null) {
		return $type === null ? route_url('my_notify', [], $query) : route_url('my_notify_list', ['type' => $type], $query);
	}
	return route_url('my_notify_list_page', ['type' => $type, 'page' => $page], $query);
}
function my_notify_read_url($nid = null, $query = array()) {
	return $nid === null ? route_url('my_notify_read', [], $query) : route_url('my_notify_read_nid', ['nid' => $nid], $query);
}
function my_notify_dropdown_url($query = array())         { return route_url('my_notify_dropdown', [], $query); }
function my_notify_mark_read_url($query = array())        { return route_url('my_notify_mark_read', [], $query); }
function my_notify_unread_count_url($query = array())     { return route_url('my_notify_unread_count', [], $query); }
function my_profile_url($query = array())                 { return route_url('my_profile', [], $query); }
function my_password_url($query = array())               { return route_url('my_password', [], $query); }
function my_email_url($query = array())                   { return route_url('my_email', [], $query); }
function my_avatar_url($query = array())                  { return route_url('my_avatar', [], $query); }
function my_avatar_preset_url($query = array())          { return route_url('my_avatar_preset', [], $query); }
function my_security_url($query = array())                { return route_url('my_security', [], $query); }
function my_ai_url($query = array())                      { return route_url('my_ai', [], $query); }
function my_credits_url($page = null, $query = array())   {
	return $page === null ? route_url('my_credits', [], $query) : route_url('my_credits_page', ['page' => $page], $query);
}
function my_credits_rules_url($query = array())            { return route_url('my_credits_rules', [], $query); }
function my_credits_check_url($query = array())           { return route_url('my_credits_check', [], $query); }
function my_send_email_code_url($query = array())          { return route_url('my_send_email_code', [], $query); }
function my_level_url($query = array())                    { return route_url('my_level', [], $query); }

// ----- 通知 -----
function notice_list_url($type = null, $page = null, $query = array()) {
	if ($type === null && $page === null) return route_url('notice_list', [], $query);
	if ($type === null && $page !== null) return route_url('notice_list_page', ['page' => $page], $query);
	if ($type !== null && $page === null) return route_url('notice_list_type', ['type' => $type], $query);
	return route_url('notice_list_type_page', ['type' => $type, 'page' => $page], $query);
}
function notice_mark_read_url($query = array())           { return route_url('notice_mark_read', [], $query); }
function notice_announcements_url($query = array())      { return route_url('notice_announcements', [], $query); }

// ----- 模块操作 -----
function mod_delete_url($query = array())                { return route_url('mod_delete', [], $query); }
function mod_move_url($query = array())                  { return route_url('mod_move', [], $query); }
function mod_top_url($query = array())                   { return route_url('mod_top', [], $query); }
function mod_top_post_url($query = array())              { return route_url('mod_top_post', [], $query); }
function mod_close_url($query = array())                 { return route_url('mod_close', [], $query); }
function mod_digest_url($query = array())                { return route_url('mod_digest', [], $query); }
function mod_announcement_url($query = array())          { return route_url('mod_announcement', [], $query); }
function mod_audit_url($query = array())                 { return route_url('mod_audit', [], $query); }
function mod_audit_post_url($query = array())            { return route_url('mod_audit_post', [], $query); }
function mod_ban_user_url($query = array())              { return route_url('mod_ban_user', [], $query); }

// ----- 全局通用 -----
function index_url($query = array())                      { return route_url('index', [], $query); }
function forums_url($query = array())                     { return route_url('forums', [], $query); }
function more_url($query = array())                       { return route_url('more', [], $query); }
function search_url($query = array())                     { return route_url('search', [], $query); }
function search_page_url($keyword, $page, $query = array()) { return route_url('search_page', ['page' => $page], ['keyword' => $keyword] + $query); }
function rank_url($query = array())                       { return route_url('rank', [], $query); }
function browser_url($query = array())                     { return route_url('browser', [], $query); }
function captcha_url($scene, $query = array())            { return route_url('captcha', ['scene' => $scene], $query); }
function lang_url($code, $query = array())                { return route_url('lang', ['code' => $code], $query); }
function theme_url($query = array())                      { return route_url('theme', [], $query); }

// ----- 后台 - 插件 -----
function admin_plugin_url($query = array())                          { return route_url('admin_plugin', [], $query); }
function admin_plugin_setting_url($dir, $query = array())            { return route_url('admin_plugin_setting', ['dir' => $dir], $query); }
function admin_plugin_install_url($dir, $query = array())            { return route_url('admin_plugin_install', ['dir' => $dir], $query); }
function admin_plugin_disable_url($dir, $query = array())           { return route_url('admin_plugin_disable', ['dir' => $dir], $query); }
function admin_plugin_enable_url($dir, $query = array())             { return route_url('admin_plugin_enable', ['dir' => $dir], $query); }
function admin_plugin_unstall_url($dir, $query = array())            { return route_url('admin_plugin_unstall', ['dir' => $dir], $query); }
function admin_plugin_upgrade_url($dir, $query = array())           { return route_url('admin_plugin_upgrade', ['dir' => $dir], $query); }
function admin_plugin_scanner_url($query = array())                 { return route_url('admin_plugin_scanner', [], $query); }
function admin_plugin_scanner_preinstall_url($query = array())      { return route_url('admin_plugin_scanner_preinstall', [], $query); }
function admin_plugin_scanner_do_url($query = array())              { return route_url('admin_plugin_scanner_do', [], $query); }
function admin_plugin_scanner_export_url($query = array())          { return route_url('admin_plugin_scanner_export', [], $query); }

// ----- 后台 - 版块 -----
function admin_forum_create_url($query = array())          { return route_url('admin_forum_create', [], $query); }
function admin_forum_update_url($fid, $query = array())    { return route_url('admin_forum_update', ['fid' => $fid], $query); }
function admin_forum_delete_url($fid, $query = array())    { return route_url('admin_forum_delete', ['fid' => $fid], $query); }
function admin_forum_list_url($query = array())            { return route_url('admin_forum_list', [], $query); }

// ----- 后台 - 用户 -----
function admin_user_create_url($query = array())           { return route_url('admin_user_create', [], $query); }
function admin_user_update_url($uid, $query = array())    { return route_url('admin_user_update', ['uid' => $uid], $query); }
function admin_user_list_url($query = array())            { return route_url('admin_user_list', [], $query); }

// ----- 后台 - 用户组 -----
function admin_group_list_url($query = array())           { return route_url('admin_group_list', [], $query); }
function admin_group_update_url($gid, $query = array())  { return route_url('admin_group_update', ['gid' => $gid], $query); }

// ----- 后台 - 设置 -----
function admin_setting_url($section, $query = array())    { return route_url('admin_setting', ['section' => $section], $query); }
function admin_setting_smtp_test_url($query = array())    { return route_url('admin_setting_smtp_test', [], $query); }

// ----- 后台 - 安全 -----
function admin_security_url($section, $query = array())   { return route_url('admin_security', ['section' => $section], $query); }

// ----- 后台 - 日志 -----
function admin_log_url($type, $page = null, $query = array()) {
	return $page === null ? route_url('admin_log', ['type' => $type], $query) : route_url('admin_log_page', ['type' => $type, 'page' => $page], $query);
}

// ----- 后台 - 积分规则 -----
function admin_credits_rule_url($type = 'global', $fid = null, $query = array()) {
	if ($fid !== null) return route_url('admin_credits_rule_forum', ['fid' => $fid], $query);
	return route_url('admin_credits_rule', ['type' => $type], $query);
}

// ----- 后台 - 通知 -----
function admin_notice_create_url($query = array())         { return route_url('admin_notice_create', [], $query); }
function admin_notice_publish_url($query = array())        { return route_url('admin_notice_publish', [], $query); }
function admin_notice_delete_url($query = array())         { return route_url('admin_notice_delete', [], $query); }
function admin_notice_list_url($type, $query = array())    { return route_url('admin_notice_list', ['type' => $type], $query); }

// ----- 后台 - 帖子管理 -----
function admin_thread_scan_url($query = array())           { return route_url('admin_thread_scan', [], $query); }
function admin_thread_found_url($page, $query = array())  { return route_url('admin_thread_found', ['page' => $page], $query); }
function admin_thread_batch_url($query = array())         { return route_url('admin_thread_batch', [], $query); }
function admin_thread_recycle_url($query = array())       { return route_url('admin_thread_recycle', [], $query); }

// ----- 后台 - 附件 -----
function admin_attach_list_url($query = array())          { return route_url('admin_attach_list', [], $query); }
function admin_attach_delete_url($query = array())        { return route_url('admin_attach_delete', [], $query); }
function admin_attach_batch_delete_url($query = array()) { return route_url('admin_attach_batch_delete', [], $query); }

// ----- 后台 - API -----
function admin_api_doc_url($query = array())               { return route_url('admin_api_doc', [], $query); }
function admin_api_debug_url($query = array())             { return route_url('admin_api_debug', [], $query); }
function admin_api_token_delete_url($id, $query = array()) { return route_url('admin_api_token_delete', ['id' => $id], $query); }
function admin_api_settings_url($query = array())          { return route_url('admin_api_settings', [], $query); }
function admin_api_app_create_url($query = array())        { return route_url('admin_api_app_create', [], $query); }
function admin_api_app_update_url($query = array())        { return route_url('admin_api_app_update', [], $query); }
function admin_api_app_delete_url($query = array())        { return route_url('admin_api_app_delete', [], $query); }
function admin_api_app_reset_secret_url($query = array())  { return route_url('admin_api_app_reset_secret', [], $query); }
function admin_api_settings_save_url($query = array())     { return route_url('admin_api_settings_save', [], $query); }

// ----- 后台 - 缓存与系统 -----
function admin_cache_url($query = array())                { return route_url('admin_cache', [], $query); }
function admin_cache_setting_url($query = array())        { return route_url('admin_cache_setting', [], $query); }
function admin_upgrade_url($query = array())              { return route_url('admin_upgrade', [], $query); }
function admin_online_upgrade_url($query = array())        { return route_url('admin_online_upgrade', [], $query); }
function admin_online_upgrade_check_url($query = array())  { return route_url('admin_online_upgrade_check', [], $query); }
function admin_online_upgrade_step_url($query = array())   { return route_url('admin_online_upgrade_step', [], $query); }
function admin_online_upgrade_rollback_url($query = array()){ return route_url('admin_online_upgrade_rollback', [], $query); }
function admin_online_upgrade_settings_url($query = array()){ return route_url('admin_online_upgrade_settings', [], $query); }
function admin_online_upgrade_reinstall_url($query = array()){ return route_url('admin_online_upgrade_reinstall', [], $query); }
function admin_health_url($query = array())               { return route_url('admin_health', [], $query); }
function admin_phpinfo_url($query = array())              { return route_url('admin_phpinfo', [], $query); }
function admin_logout_url($query = array())               { return route_url('admin_logout', [], $query); }
function admin_login_url($query = array())                { return route_url('admin_login', [], $query); }
function admin_audit_url($query = array())                { return route_url('admin_audit', [], $query); }
function admin_theme_default_url($query = array())         { return route_url('admin_theme_default', [], $query); }
function admin_theme_brand_url($query = array())           { return route_url('admin_theme_brand', [], $query); }

// ----- 后台跳前台 -----
function frontend_thread_url($tid, $query = array())      { return route_url('frontend_thread', ['tid' => $tid], $query); }
function frontend_user_url($uid, $query = array())       { return route_url('frontend_user', ['uid' => $uid], $query); }
function frontend_forum_url($fid, $query = array())       { return route_url('frontend_forum', ['fid' => $fid], $query); }

// hook model_route_end.php
