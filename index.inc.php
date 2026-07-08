<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook index_inc_start.php

$sid = sess_start();

include APP_PATH.'lib/CsrfService.php';
include APP_PATH.'lib/EscapeService.php';
include APP_PATH.'lib/EditorService.php';
include APP_PATH.'lib/PermissionService.php';
include_once APP_PATH.'lib/ServiceRegistry.php';

// 将 xiunophp.php 已初始化的 db/cache/conf 注册进 ServiceRegistry
// ServiceRegistry::set 内部会同步 $_SERVER['xxx']，旧代码无需改动
// db/cache 由 xiunophp.php 创建并赋值到 $_SERVER，此处纳入注册表统一管理
if(isset($_SERVER['conf'])) ServiceRegistry::set('conf', $_SERVER['conf']);
if(isset($_SERVER['db'])) ServiceRegistry::set('db', $_SERVER['db']);
if(isset($_SERVER['cache'])) ServiceRegistry::set('cache', $_SERVER['cache']);

// 用户级语言切换：cookie > 浏览器 > 后台默认语言 > 站点配置
$user_lang = _COOKIE('lang');
if ($user_lang && is_dir(APP_PATH."lang/$user_lang")) {
    $conf['lang'] = $user_lang;
} elseif (empty($user_lang)) {
    // 优先使用后台设置的默认语言
    $_default_lang = isset($conf['default_lang']) ? $conf['default_lang'] : '';
    if(!empty($_default_lang) && is_dir(APP_PATH."lang/$_default_lang")) {
        $conf['lang'] = $_default_lang;
    } else {
        $accept_langs = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 5) : '';
        $lang_map = array('zh-cn'=>'zh-cn', 'zh-tw'=>'zh-tw', 'en'=>'en-us');
        foreach ($lang_map as $prefix => $locale) {
            if (stripos($accept_langs, $prefix) === 0 && is_dir(APP_PATH."lang/$locale")) {
                $conf['lang'] = $locale;
                break;
            }
        }
    }
}

// 语言 / Language
$_r = include _include(APP_PATH."lang/$conf[lang]/bbs.php");
$_SERVER['lang'] = $lang = is_array($_r) ? $_r : array();

// 积分类型名称动态覆盖
if(isset($conf['credits_name']) && $conf['credits_name']) {
    $lang['credits_label'] = $conf['credits_name'];
    $lang['admin_credits_type_credits'] = $conf['credits_name'];
}
if(isset($conf['golds_name']) && $conf['golds_name']) {
    $lang['golds_label'] = $conf['golds_name'];
    $lang['admin_credits_type_golds'] = $conf['golds_name'];
}
if(isset($conf['rmbs_name']) && $conf['rmbs_name']) {
    $lang['admin_credits_type_rmbs'] = $conf['rmbs_name'];
}
// 积分规则相关名称覆盖
if(isset($conf['credits_name']) && $conf['credits_name']) {
    $lang['admin_credits_rule_credits_change'] = $conf['credits_name'] . '变化';
}
if(isset($conf['golds_name']) && $conf['golds_name']) {
    $lang['admin_credits_rule_golds_change'] = $conf['golds_name'] . '变化';
}
if(isset($conf['rmbs_name']) && $conf['rmbs_name']) {
    $lang['admin_credits_rule_rmbs_change'] = $conf['rmbs_name'] . '变化';
}
$_SERVER['lang'] = $lang;

// 用户组 / Group
$grouplist = group_list_cache();

// 支持 Token 接口（token 与 session 双重登陆机制，方便 REST 接口设计，也方便 $_SESSION 使用）
// Support Token interface (token and session dual match, to facilitate the design of the REST interface, but also to facilitate the use of $_SESSION)
$uid = intval(_SESSION('uid'));
empty($uid) AND $uid = user_token_get() AND $_SESSION['uid'] = $uid;
$user = user_read($uid);

// 账号锁定检查：banned_until > 当前时间则强制退出登录（前台会话也失效）
// 防止攻击者偷取前台 cookie 后，即使后台密码错误被锁，仍能持前台会话操作
if(!empty($user) && isset($user['banned_until']) && $user['banned_until'] > $time) {
	// 清除 session 中的 uid
	unset($_SESSION['uid']);
	// 清除 bbs_token cookie
	if(function_exists('user_token_clear')) {
		user_token_clear();
	}
	// 重置 $uid 和 $user，使本次请求以游客身份处理
	$uid = 0;
	$user = array();
}

$gid = empty($user) ? 0 : intval($user['gid']);
$group = isset($grouplist[$gid]) ? $grouplist[$gid] : (isset($grouplist[0]) ? $grouplist[0] : array());

// 版块 / Forum
$fid = 0;
$forumlist = function_exists('forum_list_cache') ? forum_list_cache() : array();
$forumlist_show = function_exists('forum_list_access_filter') ? forum_list_access_filter($forumlist, $gid) : $forumlist;
$forumarr = arrlist_key_values($forumlist_show, 'fid', 'name');

// 头部 header.inc.htm 
$header = array(
	'title'=>$conf['sitename'],
	'mobile_title'=>'',
	'mobile_link'=>'./',
	'keywords'=>'', // 搜索引擎自行分析 keywords, 自己指定没用 / Search engine automatic analysis of key words, so keep it empty.
	'description'=>strip_tags($conf['sitebrief']),
	'navs'=>array(),
);

$header['csrf_token'] = CsrfService::generate();

// 运行时数据，存放于 cache_set() / runtime data
$runtime = runtime_init();

// 安全配置缓存（避免 header_nav 每次查库）
if(!class_exists('SecurityConfigService')) include APP_PATH . 'lib/security/SecurityConfigService.php';
$_search_require_login = SecurityConfigService::get('security_search_require_login', 1);

// 检测站点运行级别 / restricted access
check_runlevel();

// 在线升级维护模式检测：升级过程中前台非管理员返回 503
$_maint_lock = isset($conf['tmp_path']) ? $conf['tmp_path'] . 'maintenance.lock' : APP_PATH . 'tmp/maintenance.lock';
if (is_file($_maint_lock) && $gid != 1) {
    header('HTTP/1.1 503 Service Unavailable');
    header('Retry-After: 300');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-cn"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>站点升级中</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8f9fa;color:#495057;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{background:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.08);padding:3rem;text-align:center;max-width:420px}.icon{font-size:3rem;color:#0d6efd;margin-bottom:1rem}h1{font-size:1.5rem;margin:0 0 .5rem}p{margin:0;color:#6c757d}</style></head><body><div class="card"><div class="icon">&#9881;</div><h1>站点升级中</h1><p>系统正在升级，请稍后访问</p><p style="margin-top:.5rem;font-size:.875rem">预计 5 分钟内恢复</p></div></body></html>';
    exit;
}

// 全局封禁检查：禁止访问(ban_type=2)/锁定(ban_type=3)用户跳转封禁提示页
// 游客(uid=0)不检查；admin 上下文不检查（admin 有独立入口和权限体系）
// 管理员组(gid=1,2)豁免，避免误封导致系统无法管理
// 用 SCRIPT_NAME 检测 admin，兼容子目录安装（项目规则：admin 检测用 SCRIPT_NAME 而非 REQUEST_URI）
$_ban_script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
$_ban_is_admin = (strpos($_ban_script_name, '/admin') !== false);
if(!$_ban_is_admin && $uid > 0) {
	if(!class_exists('UserBanService')) { include_once APP_PATH.'lib/UserBanService.php'; }
	// 管理员组豁免：二次检查，避免误封导致系统无法管理
	if(!in_array($gid, UserBanService::ADMIN_GIDS, true)) {
		$ban_check = UserBanService::checkBanByScene($uid, 'browse');
		// hook user_ban_check.php
		if(!$ban_check['allowed']) {
			// AJAX 请求返回 JSON，避免 htmx 等异步请求收到 HTML 页面
			$_ban_is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
				|| !empty($_SERVER['HTTP_HX_REQUEST']);
			if($_ban_is_ajax) {
				header('Content-Type: application/json');
				echo json_encode(array('code' => -1003, 'message' => $ban_check['message']));
				exit;
			}
			// 普通请求渲染封禁提示页（不用 message() 函数，message 会输出完整页面或跳转）
			$ban_status = UserBanService::getBanStatus($uid);
			$header['title'] = lang('user_ban_page_title');
			include _include(APP_PATH.'view/htm/banned_notice.htm');
			exit;
		}
	}
}

// 全站的设置数据，站点名称，描述，关键词
// $setting = kv_get('setting');

// 固定链接 301 重定向：旧格式 URL 自动跳转到新格式
if(!empty($conf['url_rewrite_on'])) {
    $request_uri = $_SERVER['REQUEST_URI'];
    $uri_no_path = isset($_SERVER['REQUEST_URI_NO_PATH']) ? $_SERVER['REQUEST_URI_NO_PATH'] : '';

    // 检测是否为旧格式 ?xxx-yyy.htm（url_rewrite_on=0 的格式）
    // 当 url_rewrite_on > 0 时，如果 URL 以 ? 开头（如 /?user-1.htm），说明是旧格式
    // 兼容微信等应用复制 URL 自动追加等号（如 /?index.htm=）
    if(preg_match('#^/\?[\w\-]+.*\.htm=?#', $request_uri)) {
        // 构建新格式 URL
        $path = http_url_path();
        $new_url = '';
        if($conf['url_rewrite_on'] == 1) {
            // 伪静态：去掉问号 /?user-1.htm → /user-1.htm
            $new_url = str_replace('/?', '/', $request_uri);
        } elseif($conf['url_rewrite_on'] == 3) {
            // 路径风格：/?user-1.htm → /user/1
            $new_url = str_replace('/?', '/', $request_uri);
            $new_url = preg_replace('#/([\w]+)-([\w\-]+)\.htm#', '/$1/$2', $new_url);
            $new_url = preg_replace('#/([\w]+)\.htm#', '/$1', $new_url);
        } elseif($conf['url_rewrite_on'] == 4) {
            // .html 后缀：/?user-1.htm → /user-1.html
            $new_url = str_replace('/?', '/', $request_uri);
            $new_url = preg_replace('#\.htm=?$#', '.html', $new_url);
        } elseif($conf['url_rewrite_on'] == 5) {
            // 自定义格式：使用 url() 函数生成新 URL
            $new_url = str_replace('/?', '/', $request_uri);
            $new_url = preg_replace('#\.htm=?$#', '', $new_url);
            $new_url = ltrim($new_url, '/');
            $parts = explode('-', $new_url);
            $custom = isset($conf['url_rewrite_custom']) ? $conf['url_rewrite_custom'] : '/{controller}-{action}-{id}.html';
            $replace = array(
                '{controller}' => isset($parts[0]) ? $parts[0] : '',
                '{action}' => isset($parts[1]) ? $parts[1] : '',
                '{id}' => isset($parts[2]) ? $parts[2] : '',
                '{page}' => isset($parts[2]) ? $parts[2] : '',
            );
            $new_url = str_replace(array_keys($replace), array_values($replace), $custom);
            $new_url = preg_replace('#\{[\w]+\}#', '', $new_url);
            $new_url = preg_replace('#--+#', '-', $new_url);
            $new_url = preg_replace('#-\.#', '.', $new_url);
            $new_url = preg_replace('#/-#', '/', $new_url);
        }
        // 去掉微信等应用追加的尾部等号
        $new_url = rtrim($new_url, '=');
        if($new_url && $new_url != $request_uri) {
            header("HTTP/1.1 301 Moved Permanently");
            header("Location: " . $path . ltrim($new_url, '/'));
            exit;
        }
    }

    // 跨格式 301 重定向：非当前格式的伪静态 URL 跳转到当前格式
    // 如 url_rewrite_on=3（路径风格）时，访问 /user-21.html 应跳转到 /user/21
    // 如 url_rewrite_on=4（.html后缀）时，访问 /user/21 应跳转到 /user-21.html
    // 注意：admin 后台不参与跨格式重定向
    // 用 SCRIPT_NAME 检测 admin，兼容子目录安装
    $_script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
    $is_admin_request = (strpos($_script_name, '/admin') !== false);
    $uri_path = parse_url($request_uri, PHP_URL_PATH) ?? '';
    $has_html_suffix = ($uri_path && (substr($uri_path, -5) === '.html' || substr($uri_path, -4) === '.htm'));
    // 计算去掉安装前缀后的相对路径，用于判断是否为路径风格
    $_script_dir = dirname($_script_name);
    if($_script_dir === '\\' || $_script_dir === '.') $_script_dir = '/';
    $uri_rel = $uri_path;
    if($_script_dir !== '/' && strpos($uri_path, $_script_dir) === 0) {
        $uri_rel = substr($uri_path, strlen($_script_dir));
    }
    $is_path_format_redirect = (substr_count(trim($uri_rel, '/'), '/') >= 1);
    $path_base = http_url_path();
    $need_redirect = false;
    $redirect_url = '';

    if(!$is_admin_request && $conf['url_rewrite_on'] == 3 && $has_html_suffix) {
        // 路径风格，但 URL 以 .html/.htm 结尾 → 跳转到路径风格
        // /user-21.html → /user/21, /my.html → /my, /thread-123-1.html → /thread/123/1
        $clean = preg_replace('#\.(html|htm)=?$#', '', $uri_path);
        $parts = explode('/', trim($clean, '/'));
        $last = end($parts);
        $segments = explode('-', $last);
        if(count($segments) > 1) {
            // user-21 → user/21, thread-123-1 → thread/123/1
            array_pop($parts);
            $redirect_url = '/' . implode('/', $parts) . '/' . implode('/', $segments);
        } else {
            $redirect_url = '/' . implode('/', $parts);
        }
        // 保留 query string
        $qs = parse_url($request_uri, PHP_URL_QUERY);
        if($qs) $redirect_url .= '?' . $qs;
        $need_redirect = true;
    } elseif(!$is_admin_request && $conf['url_rewrite_on'] == 1 && $has_html_suffix && substr($uri_path, -5) === '.html') {
        // .htm 格式，但 URL 以 .html 结尾 → 跳转到 .htm
        $redirect_url = preg_replace('#\.html=?$#', '.htm', $request_uri);
        $need_redirect = true;
    } elseif(!$is_admin_request && $conf['url_rewrite_on'] == 4 && !$has_html_suffix) {
        // .html 后缀格式，但 URL 没有后缀（路径风格）→ 跳转到 .html
        // /user/21 → /user-21.html, /my → /my.html
        $clean = trim($uri_path, '/');
        $parts = explode('/', $clean);
        $controller = $parts[0] ?? '';
        if($controller && preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $controller)) {
            if(count($parts) > 1) {
                // /user/21 → user-21.html
                $redirect_url = '/' . implode('-', $parts) . '.html';
            } else {
                // /my → /my.html
                $redirect_url = '/' . $controller . '.html';
            }
            $qs = parse_url($request_uri, PHP_URL_QUERY);
            if($qs) $redirect_url .= '?' . $qs;
            $need_redirect = true;
        }
    } elseif(!$is_admin_request && $conf['url_rewrite_on'] == 1 && !$has_html_suffix && $is_path_format_redirect) {
        // .htm 格式，但 URL 是路径风格 → 跳转到 .htm 格式
        // /user/21 → /user-21.htm, /my → /my.htm
        $clean = trim($uri_rel, '/');
        $parts = explode('/', $clean);
        $controller = $parts[0] ?? '';
        if($controller && preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $controller)) {
            $redirect_url = '/' . implode('-', $parts) . '.htm';
            $qs = parse_url($request_uri, PHP_URL_QUERY);
            if($qs) $redirect_url .= '?' . $qs;
            $need_redirect = true;
        }
    } elseif(!$is_admin_request && $conf['url_rewrite_on'] == 5 && !$has_html_suffix) {
        // 路径+html 格式，但 URL 没有 .html 后缀（路径风格 /user/1 或 .htm 格式 /user-1.htm）
        // → 跳转到 /user/1.html
        // /user/1 → user-1 → url('user-1') → /user/1.html
        // /user-1.htm → user-1 → url('user-1') → /user/1.html
        $clean = trim($uri_rel, '/');
        // 去掉可能的 .htm 后缀
        $clean = preg_replace('#\.htm$#', '', $clean);
        $parts = explode('/', $clean);
        $controller = $parts[0] ?? '';
        if($controller && preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $controller)) {
            // 将路径风格参数转为 - 连接格式，再用 url() 生成
            $url_query = implode('-', $parts);
            $redirect_url = url($url_query);
            $qs = parse_url($request_uri, PHP_URL_QUERY);
            if($qs) $redirect_url .= (strpos($redirect_url, '?') === FALSE ? '?' : '&') . $qs;
            $need_redirect = true;
        }
    } elseif(!$is_admin_request && $conf['url_rewrite_on'] == 5 && $has_html_suffix && !$is_path_format_redirect) {
        // 路径+html 格式，但 URL 是 .html 后缀的非路径风格（如 /user-1.html，url_rewrite_on=4 的格式）
        // → 跳转到 /user/1.html
        // /user-1.html → user-1 → url('user-1') → /user/1.html
        $clean = trim($uri_rel, '/');
        $clean = preg_replace('#\.html=?$#', '', $clean);
        $parts = explode('-', $clean);
        $controller = $parts[0] ?? '';
        if($controller && preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*$#', $controller)) {
            $url_query = implode('-', $parts);
            $redirect_url = url($url_query);
            $qs = parse_url($request_uri, PHP_URL_QUERY);
            if($qs) $redirect_url .= (strpos($redirect_url, '?') === FALSE ? '?' : '&') . $qs;
            $need_redirect = true;
        }
    }

    if($need_redirect && $redirect_url && $redirect_url !== $request_uri) {
        header("HTTP/1.1 301 Moved Permanently");
        header("Location: " . $path_base . ltrim($redirect_url, '/'));
        exit;
    }
}

// 严格 URL 路径校验：防止任意路径显示内容（SEO 重复内容、错误链接应 404）
// 仅对前台非 API、非 admin 请求校验，且仅当开启伪静态时生效
// 解决问题：1.末尾带斜杠显示首页 2.垃圾前缀+有效路由显示对应页面 3..html后加字符参数错误
if(!empty($conf['url_rewrite_on'])) {
    $strict_request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $_strict_is_admin = (strpos($_script_name, '/admin') !== false);
    if($strict_request_uri && strpos($strict_request_uri, '/api/v1/') !== 0 && !$_strict_is_admin) {
        $strict_uri_path = parse_url($strict_request_uri, PHP_URL_PATH);
        if($strict_uri_path && $strict_uri_path !== '/') {
            // 获取安装前缀（根目录为 '/'，子目录为 '/demo'）
            $strict_script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
            $strict_prefix = dirname($strict_script);
            if($strict_prefix === '\\') $strict_prefix = '/';
            // 去掉安装前缀，得到相对路径
            $strict_rel = $strict_uri_path;
            if($strict_prefix !== '/' && strpos($strict_uri_path, $strict_prefix) === 0) {
                $strict_rel = substr($strict_uri_path, strlen($strict_prefix));
            }
            // 校验 1：禁止末尾斜杠（除根路径 /）
            if(strlen($strict_rel) > 1 && substr($strict_rel, -1) === '/') {
                http_404();
            }
            // 校验 2：禁止多级路径前缀（路径风格 url_rewrite_on=3/5 除外）
            $strict_is_path_style = ($conf['url_rewrite_on'] == 3 || $conf['url_rewrite_on'] == 5);
            if(!$strict_is_path_style && substr_count($strict_rel, '/') > 1) {
                http_404();
            }
            // 校验 3：禁止 .html/.htm 后缀后还有其他字符
            $strict_last = ltrim($strict_rel, '/');
            if(preg_match('#\.(html|htm)#', $strict_last) && !preg_match('#\.(html|htm)$#', $strict_last)) {
                http_404();
            }
        }
    }
}

$route = param(0, 'index');

// 兼容 ?keyword=xxx 格式的搜索 URL（缺少路由标识时自动跳转搜索）
// admin 请求跳过：admin 有自己的搜索体系（插件搜索、AI 日志搜索等），
// admin 下路由（plugin/ai-logs/setting 等）不在前台白名单中，否则会被误跳到前台 search 路由
if(isset($_REQUEST['keyword']) && trim($_REQUEST['keyword']) !== '') {
	$_kw_script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
	$_kw_is_admin = (strpos($_kw_script_name, '/admin') !== false);
	if(!$_kw_is_admin) {
		$valid_routes = array('index','thread','forum','user','my','attach','post','mod','browser','theme','search','lang','api','forums','rank','notice','captcha');
		if(!in_array($route, $valid_routes)) {
			http_location(url('search', array('keyword' => trim($_REQUEST['keyword']))));
		}
	}
}

// hook index_inc_route_before.php

// API 路由：支持伪静态 /api/v1/xxx 和非伪静态 ?api-v1-xxx.htm 两种格式
$isApiRoute = false;
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/v1/') === 0) {
    // 伪静态格式：/api/v1/auth/login
    $isApiRoute = true;
} elseif ($route === 'api') {
    // 非伪静态格式：?api-v1-auth-login.htm
    $subRoute = param(1, '');
    if ($subRoute === 'v1') {
        $isApiRoute = true;
        // 将路由参数还原到 REQUEST_URI，使 bootstrap.php 能正确解析
        $apiPath = '/api/v1';
        $i = 2;
        while (($seg = param($i, '')) !== '') {
            $apiPath .= '/' . $seg;
            $i++;
        }
        $_SERVER['REQUEST_URI'] = $apiPath;
    }
}

if ($isApiRoute) {
    define('SKIP_ROUTE', true);
    include APP_PATH . 'api/v1/bootstrap.php';
    exit;
}

if(!defined('SKIP_ROUTE')) {

	// 按照使用的频次排序，增加命中率，提高效率
	// According to the frequency of the use of sorting, increase the hit rate, improve efficiency
	switch ($route) {
		// hook index_route_case_start.php
		case 'index': 	include _include(APP_PATH.'route/index.php'); 	break;
		case 'thread':	include _include(APP_PATH.'route/thread.php'); 	break;
		case 'forum': 	include _include(APP_PATH.'route/forum.php'); 	break;
		case 'user': 	include _include(APP_PATH.'route/user.php'); 	break;
		case 'my': 	include _include(APP_PATH.'route/my.php'); 	break;
		case 'attach': 	include _include(APP_PATH.'route/attach.php'); 	break;
		case 'post': 	include _include(APP_PATH.'route/post.php'); 	break;
		case 'mod': 	include _include(APP_PATH.'route/mod.php'); 	break;
		case 'browser': include _include(APP_PATH.'route/browser.php'); break;
		case 'theme': 	include _include(APP_PATH.'route/theme.php'); 	break;
	case 'search': 	include _include(APP_PATH.'route/search.php'); 	break;
	case 'lang': 	include _include(APP_PATH.'route/lang.php'); 	break;
	case 'api':		include _include(APP_PATH.'api/v1/bootstrap.php');	break;
	case 'forums':	include _include(APP_PATH.'route/forum_index.php');	break;
	case 'rank':	include _include(APP_PATH.'route/rank.php');	break;
	case 'notice':	include _include(APP_PATH.'route/notice.php');	break;
	case 'captcha':	include _include(APP_PATH.'route/captcha.php');	break;
		// hook index_route_case_end.php
		default:
			// hook index_route_case_default.php
			!is_word($route) AND http_404();
			$routefile = APP_PATH."route/$route.php";
			!is_file($routefile) AND http_404();
			include _include($routefile);
			break;
	}
}

// hook index_inc_end.php

?>