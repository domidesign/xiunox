<?php

// hook model_misc_start.php


/*
	url("thread-create-1.htm");
	根据 $conf['url_rewrite_on'] 设置，返回以下四种格式：
	?thread-create-1.htm
	thread-create-1.htm
	?/thread/create/1
	/thread/create/1
*/
function url($url, $extra = array()) {
	$conf = _SERVER('conf');
	!isset($conf['url_rewrite_on']) AND $conf['url_rewrite_on'] = 0;

	// hook model_url_start.php

	// admin 后台始终使用 ? 格式，不受 url_rewrite_on 影响
	// 避免切换伪静态风格后后台链接失效
	// 用 SCRIPT_NAME 检测 admin，兼容子目录安装（/demo/admin/）
	$_script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
	$is_admin = (strpos($_script_name, '/admin') !== false);
	$url_rewrite_on = $is_admin ? 0 : intval($conf['url_rewrite_on']);

	$r = $path = $query = '';
	if(strpos($url, '/') !== FALSE) {
		$path = substr($url, 0, strrpos($url, '/') + 1);
		$query = substr($url, strrpos($url, '/') + 1);
	} else {
		$path = '';
		$query = $url;
	}

	// 空路由防护：query 为空时（如 url('')、url('/')、url('../')等），返回首页
	if($query === '') {
		if($url_rewrite_on == 0) return '/?index.htm';
		if($url_rewrite_on == 2) return '/?index';
		return '/';
	}

	if($url_rewrite_on == 0) {
		$r = '/' . $path . '?' . $query . '.htm';
	} elseif($url_rewrite_on == 1) {
		$r = '/' . $path . $query . '.htm';
	} elseif($url_rewrite_on == 2) {
		$r = '/' . $path . '?' . str_replace('-', '/', $query);
	} elseif($url_rewrite_on == 3) {
		$r = '/' . $path . str_replace('-', '/', $query);
	} elseif($url_rewrite_on == 4) {
		$r = '/' . $path . $query . '.html';
	} elseif($url_rewrite_on == 5) {
		$r = '/' . $path . str_replace('-', '/', $query) . '.html';
	}
	// 附加参数
	if($extra) {
		$args = http_build_query($extra);
		$sep = strpos($r, '?') === FALSE ? '?' : '&';
		$r .= $sep.$args;
	}

	// hook model_url_end.php

	// 对于没有路径组件的 URL（如 ?forum-5.htm），根据 is_admin 调整前缀
	// 前台：r 已以 / 开头（绝对路径），保持原样
	// admin：r 以 / 开头时替换为 ./，确保浏览器在 /admin/ 下点击跳转到 admin 入口而非站点根
	// 关键：上方所有 url_rewrite_on 分支生成的 r 均以 / 开头，
	//       不能用 $r[0] !== '/' 作为进入此分支的条件（否则 admin 前缀永远不会生效）
	if($path === '' && $r !== '' && strpos($r, 'http') !== 0 && strpos($r, '//') !== 0) {
		if($is_admin) {
			// admin 下使用 ./ 前缀，去掉 r 开头的 / 避免变成 ./ /
			$r = './' . ltrim($r, '/');
		} elseif($r[0] !== '/') {
			// 前台兜底：r 不以 / 开头时补 / 前缀（当前所有 url_rewrite_on 分支 r 均以 / 开头，此分支不触发）
			$r = '/' . $r;
		}
	}

	return $r;
}


// 检测站点的运行级别
function check_runlevel() {
	global $conf, $method, $gid;
	
	$rules = array(
		'user'=>array('login', 'create', 'logout', 'sendinitpw', 'resetpw', 'resetpw_sendcode', 'resetpw_complete', 'synlogin')
	);
	
	// hook model_check_runlevel_start.php
	
	if($gid == 1) return;
	$param0 = param(0);
	$param1 = param(1);
	foreach ($rules as $route=>$actions) {
		if($param0 == $route && (empty($actions) || in_array($param1, $actions))) {
			return;
		}
	}
	
	switch ($conf['runlevel']) {
		case 0: message(-1, lang('runlevel_reson_0')); break;
		case 1: message(-1, lang('runlevel_reson_1')); break;
		case 2: ($gid == 0 || $method != 'GET') AND message(-1, lang('runlevel_reson_2')); break;
		case 3: $gid == 0 AND message(-1, lang('runlevel_reson_3')); break;
		case 4: $method != 'GET' AND message(-1, lang('runlevel_reson_4')); break;
		//case 5: break;
	}
	// hook model_check_runlevel_end.php
}

function htmx_trigger($event_name, $data = array()) {
	$json = json_encode(array($event_name => $data));
	header("HX-Trigger: " . $json);
}

// htmx 请求检测：仅检查 HX-Request 头（htmx 库专用标识）
function is_htmx_request() {
	return !empty($_SERVER['HTTP_HX_REQUEST']);
}

// 渲染点赞按钮 HTML 片段（htmx 4 模式，供 route/thread.php 使用）
function _render_like_btn($tid, $pid, $is_liked, $likes_count, $ctx = 'post') {
	// 已点赞 → 指向取消点赞路由；未点赞 → 指向点赞路由
	$action = $is_liked ? 'unlike' : 'like';
	$like_url = url('thread-'.$action.'-'.$tid.'-'.$pid);
	$title = lang('like');

	// 手机端正文底部：chip 样式（图标+文字+数量），仅手机端显示
	if ($ctx == 'thread_mobile') {
		$icon_class = $is_liked ? 'ti-heart-filled' : 'ti-heart';
		$active_class = $is_liked ? ' is-active' : '';
		$count_html = $likes_count > 0 ? '<span class="chip-count">'.intval($likes_count).'</span>' : '';
		$html = '<span class="thread-action-chip thread-like-btn-mobile d-xl-none'.esc_html($active_class).'"'
			. ' hx-post="'.esc_html($like_url).'" hx-vals=\'{"_ctx":"thread_mobile"}\' hx-target="this" hx-swap="outerHTML"'
			. ' hx-disable="this" hx-confirm=" " role="button" title="'.esc_html($title).'">'
			. '<i class="ti '.esc_html($icon_class).'"></i>'
			. '<span>'.esc_html($title).'</span>'
			. $count_html
			. '</span>';
		return $html;
	}

	switch($ctx) {
		case 'thread':
			// 电脑端左侧操作栏：圆形白底按钮 + 图标 + 角标
			$btn_class = 'thread-like-btn thread-sidebar-btn cursor-pointer transition-colors';
			$btn_style = '';
			$icon_extra = '';
			$count_class = 'thread-sidebar-badge';
			$show_count = true;
			break;
		case 'reply':
			$btn_class = 'post-like-btn cursor-pointer text-body-secondary ms-2';
			$btn_style = 'font-size:0.8em';
			$icon_extra = '';
			$count_class = 'like-count';
			$show_count = true;
			break;
		case 'post':
		default:
			$btn_class = 'post-like-btn cursor-pointer text-body-secondary ms-3';
			$btn_style = '';
			$icon_extra = '';
			$count_class = 'like-count';
			$show_count = true;
			break;
	}
	$icon_class = $is_liked ? 'ti-heart-filled text-primary' : 'ti-heart';
	$badge_class = $is_liked ? 'bg-primary' : 'bg-secondary';
	$style_attr = $btn_style ? ' style="'.esc_html($btn_style).'"' : '';
	$title_attr = ($ctx == 'thread') ? ' data-tip="'.esc_html($title).'"' : ' title="'.esc_html($title).'"';
	// hx-confirm=" " 为真值，触发 htmx:confirm 事件，让积分确认弹窗逻辑生效
	$html = '<span class="'.esc_html($btn_class).'" hx-post="'.esc_html($like_url).'" hx-vals=\'{"_ctx":"'.esc_html($ctx).'"}\' hx-target="this" hx-swap="outerHTML" hx-disable="this" hx-confirm=" " role="button"'.$title_attr.$style_attr.'>'
		. '<i class="ti '.$icon_extra.' '.esc_html($icon_class).'"></i>';
	if ($show_count) {
		$html .= '<span class="'.esc_html($count_class).' '.esc_html($badge_class).'" badge="'.intval($likes_count).'"></span>';
	}
	$html .= '</span>';
	return $html;
}

// 渲染收藏按钮 HTML 片段（htmx 4 模式，供 route/thread.php 使用）
function _render_favorite_btn($tid, $is_favorited, $favorites_count, $ctx = 'thread') {
	$fav_url = url('thread-favorite-'.$tid);
	$title = lang('favorite');

	// 手机端正文底部：chip 样式（图标+文字+数量），仅手机端显示
	if ($ctx == 'thread_mobile') {
		$icon_class = $is_favorited ? 'ti-star-filled' : 'ti-star';
		$active_class = $is_favorited ? ' is-active' : '';
		$count_html = $favorites_count > 0 ? '<span class="chip-count">'.intval($favorites_count).'</span>' : '';
		$html = '<span class="thread-action-chip thread-favorite-btn-mobile d-xl-none'.esc_html($active_class).'"'
			. ' hx-post="'.esc_html($fav_url).'" hx-vals=\'{"_ctx":"thread_mobile"}\' hx-target="this" hx-swap="outerHTML"'
			. ' hx-disable="this" hx-confirm=" " role="button" title="'.esc_html($title).'">'
			. '<i class="ti '.esc_html($icon_class).'"></i>'
			. '<span>'.esc_html($title).'</span>'
			. $count_html
			. '</span>';
		return $html;
	}

	$icon_class = $is_favorited ? 'ti-star-filled text-primary' : 'ti-star';
	$badge_class = $is_favorited ? 'bg-primary' : 'bg-secondary';
	switch($ctx) {
		case 'thread':
			// 电脑端左侧操作栏：圆形白底按钮 + 图标 + 角标
			$btn_class = 'thread-favorite-btn thread-sidebar-btn cursor-pointer transition-colors';
			$icon_extra = '';
			$count_class = 'thread-sidebar-badge';
			$show_count = true;
			break;
		default:
			$btn_class = 'thread-favorite-btn cursor-pointer transition-colors';
			$icon_extra = '';
			$count_class = 'favorite-count';
			$show_count = true;
			break;
	}
	$title_attr = ($ctx == 'thread') ? ' data-tip="'.esc_html($title).'"' : ' title="'.esc_html($title).'"';
	// hx-confirm=" " 为真值，触发 htmx:confirm 事件，让积分确认弹窗逻辑生效
	$html = '<span class="'.esc_html($btn_class).'" hx-post="'.esc_html($fav_url).'" hx-target="this" hx-swap="outerHTML" hx-disable="this" hx-confirm=" " role="button"'.$title_attr.'>'
		. '<i class="ti '.$icon_extra.' '.esc_html($icon_class).'"></i>';
	if ($show_count) {
		$html .= '<span class="'.esc_html($count_class).' '.esc_html($badge_class).'" badge="'.intval($favorites_count).'"></span>';
	}
	$html .= '</span>';
	return $html;
}

/*
	message(0, '登录成功');
	message(1, '密码错误');
	message(-1, '数据库连接失败');
	
	code:
		< 0 全局错误，比如：系统错误：数据库丢失连接/文件不可读写
		= 0 正确
		> 0 一般业务逻辑错误，可以定位到具体控件，比如：用户名为空/密码为空
*/
function message($code, $message, $extra = array()) {
	global $ajax, $header, $conf;
	
	$arr = $extra;
	$arr['code'] = $code.'';
	$arr['message'] = $message;
	if(empty($header['title'])) $header['title'] = $conf['sitename'];
	if(!isset($header['description'])) $header['description'] = '';
	if(!isset($header['keywords'])) $header['keywords'] = '';
	
	// hook model_message_start.php
	
	// 防止 message 本身出现错误死循环
	static $called = FALSE;
	$called ? exit(xn_json_encode($arr)) : $called = TRUE;

	// HTMX 请求处理（优先于 API 检测）
	$is_htmx = is_htmx_request();
	$is_htmx_boost = $is_htmx && !empty($_SERVER['HTTP_HX_BOOSTED']);
	// 调试日志：记录请求头信息
	if(DEBUG) {
		$debug_hx = !empty($_SERVER['HTTP_HX_REQUEST']) ? $_SERVER['HTTP_HX_REQUEST'] : '(none)';
		$debug_xrw = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '(none)';
		$debug_boost = !empty($_SERVER['HTTP_HX_BOOSTED']) ? $_SERVER['HTTP_HX_BOOSTED'] : '(none)';
		error_log("[message()] is_htmx=$is_htmx HX-Request=$debug_hx X-Requested-With=$debug_xrw HX-Boosted=$debug_boost code=$code");
	}
	if($is_htmx) {
		// HTMX boost 导航（GET 请求）+ 错误 → 返回完整 HTML 错误页面
		if($is_htmx_boost && $code != 0) {
			$err_code = ($code < 0) ? 500 : 404;
			if(function_exists('error_page')) {
				error_page($err_code, is_string($message) ? $message : '');
			} else {
				http_response_code($err_code);
				echo '<h1>' . ($err_code == 404 ? '404 Not Found' : 'Server Error') . '</h1>';
			}
			exit;
		}
		// HTMX 表单提交 + 错误 → 返回 HTML 错误消息片段
		if($code != 0) {
			$msg = is_string($message) ? $message : xn_json_encode($message);
			header('Content-Type: text/html; charset=utf-8');
			$code_attr = htmlspecialchars(is_numeric($code) ? $code : -1, ENT_QUOTES, 'UTF-8');
			$wait_attr = '';
			if(!empty($extra['wait'])) {
				$wait_attr = ' data-wait="' . intval($extra['wait']) . '"';
			}
			echo '<div class="alert alert-danger py-2 small mb-2" data-code="' . $code_attr . '"' . $wait_attr . '>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
			exit;
		}
		// 成功 + 有跳转 → HX-Trigger 触发 toast + 延迟跳转（返回 200 + 空片段确保 htmx 处理响应头）
		if(!empty($arr['redirect_url'])) {
			$msg = is_string($message) ? $message : lang('operate_successfully');
			// HTTP 头不支持 UTF-8 中文，需要 rawurlencode 编码
			$trigger_data = array('message' => rawurlencode($msg), 'redirect' => $arr['redirect_url']);
			// 积分变动描述
			if(!empty($arr['change_desc'])) {
				$trigger_data['change_desc'] = rawurlencode($arr['change_desc']);
			}
			$trigger_json = json_encode(array('htmxSuccessRedirect' => $trigger_data), JSON_UNESCAPED_UNICODE);
			header("HX-Trigger: $trigger_json");
			header('Content-Type: text/html; charset=utf-8');
			// 返回空 span，htmx 会 swap 到 target 并处理 HX-Trigger 头
			echo '<span class="htmx-redirect-pending" style="display:none"></span>';
			exit;
		}
		// 成功无跳转 → 检测 jump() 生成的跳转，或通过 HX-Trigger 触发 toast
		$msg = is_string($message) ? $message : '';
		// 检测 jump() 返回的跳转 URL（格式：<a href="URL">msg</a><script>...window.location='URL'...</script>）
		$redirect_url = '';
		if($msg && preg_match('/window\.location=[\'"]([^\'"]+)[\'"]/', $msg, $m)) {
			$redirect_url = $m[1];
			// 提取纯文本消息（去掉 HTML 标签）
			$msg = strip_tags($msg);
		}
		if($redirect_url) {
			// 有跳转 → 走 htmxSuccessRedirect 逻辑
			$trigger_data = array('message' => rawurlencode($msg ?: lang('operate_successfully')), 'redirect' => $redirect_url);
			if(!empty($arr['change_desc'])) {
				$trigger_data['change_desc'] = rawurlencode($arr['change_desc']);
			}
			$trigger_json = json_encode(array('htmxSuccessRedirect' => $trigger_data), JSON_UNESCAPED_UNICODE);
			header("HX-Trigger: $trigger_json");
			header('Content-Type: text/html; charset=utf-8');
			echo '<span class="htmx-redirect-pending" style="display:none"></span>';
			exit;
		}
		// 无跳转 → 通过 HX-Trigger 触发 toast 提示，返回空 span
		$trigger_data = array('message' => rawurlencode($msg ?: lang('operate_successfully')));
		if(!empty($arr['change_desc'])) {
			$trigger_data['change_desc'] = rawurlencode($arr['change_desc']);
		}
		$trigger_json = json_encode(array('htmxSuccess' => $trigger_data), JSON_UNESCAPED_UNICODE);
		header("HX-Trigger: $trigger_json");
		header('Content-Type: text/html; charset=utf-8');
		echo '<span class="htmx-success-pending" style="display:none"></span>';
		exit;
	}

	// API 请求检测：Accept: application/json 或 X-API-Request 或 X-Requested-With header
	$is_api = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
		|| !empty($_SERVER['HTTP_X_API_REQUEST'])
		|| (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower(trim($_SERVER['HTTP_X_REQUESTED_WITH'])) == 'xmlhttprequest');
	if($is_api) {
		// 统一 JSON 响应格式，由 ErrorHandler::renderJson 输出（内部 exit）
		ErrorHandler::renderJson($code, $message, $extra);
	}

	if($ajax) {
		// 统一 JSON 响应格式，由 ErrorHandler::renderJson 输出（内部 exit）
		ErrorHandler::renderJson($code, $message, $extra);
	} else {
		if(IN_CMD) {
			if(is_array($message) || is_object($message)) {
				print_r($message);
			} else {
				echo $message;
			}
			exit;
		} else {
			// PRG 模式：成功时设置 flash cookie + 303 重定向
			// 避免渲染 message.htm 中间页，刷新不会重复提交 POST 表单
			if($code == 0) {
				$msg_str = is_string($message) ? $message : '';
				$has_jump = $msg_str && preg_match('/window\.location=[\'"]([^\'"]+)[\'"]/', $msg_str);
				$has_redirect = !empty($arr['redirect_url']);
				$clean_msg = strip_tags($msg_str ?: lang('operate_successfully'));
				// 从 jump() 中提取跳转 URL
				$jump_url = '';
				if($has_jump && preg_match('/window\.location=[\'"]([^\'"]+)[\'"]/', $msg_str, $m)) {
					$jump_url = $m[1];
				}
				// 确定跳转目标：redirect_url 优先，其次 jump() URL，最后回来源页
				$redirect_target = '';
				if($has_redirect) {
					$redirect_target = $arr['redirect_url'];
				} elseif($jump_url) {
					$redirect_target = $jump_url;
				}
				if($redirect_target) {
					// 有明确跳转目标 → flash cookie + 303 重定向
					setcookie('flash_msg', $clean_msg, time() + 10, '/');
					setcookie('flash_type', 'success', time() + 10, '/');
					http_response_code(303);
					header("Location: " . $redirect_target);
					exit;
				} else {
					// 无跳转目标 → 303 重定向回来源页
					setcookie('flash_msg', $clean_msg, time() + 10, '/');
					setcookie('flash_type', 'success', time() + 10, '/');
					$referer = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/';
					http_response_code(303);
					header("Location: " . $referer);
					exit;
				}
			}
			if(defined('MESSAGE_HTM_PATH')) {
				include _include(MESSAGE_HTM_PATH);
			} else {
				include _include(APP_PATH."view/htm/message.htm");
			}
		}
	}
	// hook model_message_end.php
	exit;
}

// 错误页面展示
function error_page($code, $message = '') {
	global $conf, $header;

	// 确保 $header 已初始化，防止极早期异常时未定义
	if(!isset($header) || !is_array($header)) {
		$header = array('title' => '');
	}

	// 防止 error_page 自身出现错误死循环
	static $called = FALSE;
	if($called) {
		http_response_code($code);
		exit;
	}
	$called = TRUE;

	// 设置 HTTP 状态码
	http_response_code($code);

	// 错误类型配置
	$error_configs = array(
		404 => array(
			'title' => '页面不存在',
			'icon'  => 'ti-error-404',
		),
		403 => array(
			'title' => '禁止访问',
			'icon'  => 'ti-lock-access',
		),
		500 => array(
			'title' => '服务器内部错误',
			'icon'  => 'ti-server-bolt',
		),
	);

	$config = isset($error_configs[$code]) ? $error_configs[$code] : $error_configs[500];

	$error_type = intval($code);
	$error_title = $config['title'];
	$error_icon = $config['icon'];
	$error_message = $message;

	// 设置页面标题
	$header['title'] = $error_title . ' - ' . (isset($conf['sitename']) ? $conf['sitename'] : '');

	// 渲染错误模板
	include _include(APP_PATH."view/htm/error.htm");
	exit;
}

// 上锁
function xn_lock_start($lockname = '', $life = 10) {
	global $conf, $time;
	$lockfile = $conf['tmp_path'].'lock_'.$lockname.'.lock';
	if(is_file($lockfile)) {
		// 大于 $life 秒，删除锁
		if($time - filemtime($lockfile) > $life) {
			xn_unlink($lockfile);
		} else {
			// 锁存在，上锁失败。
			return FALSE;
		}
	}
	
	$r = file_put_contents($lockfile, $time, LOCK_EX);
	return $r;
}

// 删除锁
function xn_lock_end($lockname = '') {
	global $conf, $time;
	$lockfile = $conf['tmp_path'].'lock_'.$lockname.'.lock';
	xn_unlink($lockfile);
}


// class xn_html_safe 由 axiuno@gmail.com 编写

include_once XIUNOPHP_PATH.'xn_html_safe.func.php';

function xn_html_safe($doc, $arg = array()) {
	
	// hook model_xn_html_safe_start.php
	
	empty($arg['table_max_width']) AND $arg['table_max_width'] = 746; // 这个宽度为 bbs 回帖宽度
	
	$pattern = array (
		//'img_url'=>'#^(https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*$#is',
		'img_url'=>'#^(((https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*)|(data:image/png;base64,[\w\/+]+))$#is',
		'url'=>'#^(https?://[^\'"\\\\<>:\s]+(:\d+)?)?([^\'"\\\\<>:\s]+?)*$#is', // '#https?://[\w\-/%?.=]+#is'
		'mailto'=>'#^mailto:([\w%\-\.]+)@([\w%\-\.]+)(\.[\w%\-\.]+?)+$#is',
		'ftp_url'=>'#^ftp:([\w%\-\.]+)@([\w%\-\.]+)(\.[\w%\-\.]+?)+$#is',
		'ed2k_url'=>'#^(?:ed2k|thunder|qvod|magnet)://[^\s\'\"\\\\<>]+$#is',
		'color'=>'#^(\#\w{3,6})|(rgb\(\d+,\s*\d+,\s*\d+\)|(\w{3,10}))$#is',
		'safe'=>'#^[\w\-:;\.\s\x7f-\xff]+$#is',
		'css'=>'#^[\(,\)\#;\w\-\.\s\x7f-\xff]+$#is',
		'word'=>'#^[\w\-\x7f-\xff]+$#is',
	);

	$white_tag = array('a', 'b', 'i', 'u', 'font', 'strong', 'em', 'span',
		'table', 'tr', 'td', 'th', 'tbody', 'thead', 'tfoot','caption',
		'ol', 'ul', 'li', 'dl', 'dt', 'dd', 'menu', 'multicol',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'p', 'div', 'pre',
		'br', 'img', 'area',  'embed', 'code', 'blockquote', 'iframe', 'section', 'fieldset', 'legend'
	);
	$white_value = array(
		'href'=>array('pcre', '', array($pattern['url'], $pattern['ed2k_url'])),
		'src'=>array('pcre', '', array($pattern['img_url'])),
		'width'=>array('range', '', array(0, 4096)),
		'height'=>array('range', 'auto', array(0, 80000)),
		'size'=>array('range', 4, array(-10, 10)),
		'border'=>array('range', 0, array(0, 10)),
		'family'=>array('pcre', '', array($pattern['word'])),
		'class'=>array('pcre', '', array($pattern['safe'])),
		'face'=>array('pcre', '', array($pattern['word'])),
		'color'=>array('pcre', '', array($pattern['color'])),
		'alt'=>array('pcre', '', array($pattern['safe'])),
		'label'=>array('pcre', '', array($pattern['safe'])),
		'title'=>array('pcre', '', array($pattern['safe'])),
		'target'=>array('list', '_self', array('_blank', '_self')),
		'type'=>array('pcre', '', array('#^[\w/\-]+$#')),
		'allowfullscreen'=>array('list', 'true', array('true', '1', 'on')),
		'wmode'=>array('list', 'transparent', array('transparent', '')),
		'allowscriptaccess'=>array('list', 'never', array('never')),
		'value'=>array('list', '', array('#^[\w+/\-]$#')),
		'cellspacing'=>array('range', 0, array(0, 10)),
		'cellpadding'=>array('range', 0, array(0, 10)),
		'frameborder'=>array('range', 0, array(0, 10)),
		'align'=>array('list', 'left', array('left', 'center', 'right')),
		'valign'=>array('list', 'middle', array('middle', 'top', 'bottom')),
        'name'=>array('pcre', '', array($pattern['word'])),
	);
	$white_css = array(
		'font'=>array('pcre', 'none', array($pattern['safe'])),
		'font-style'=>array('pcre', 'none', array($pattern['safe'])),
		'font-weight'=>array('pcre', 'none', array($pattern['safe'])),
		'font-family'=>array('pcre', 'none', array($pattern['word'])),
		'font-size'=>array('range', 12, array(6, 48)),
		'width'=>array('range', '100%', array(1, 1800)),
		'height'=>array('range', '', array(1, 80000)),
		'min-width'=>array('range', 1, array(1, 80000)),
		'min-height'=>array('range', 400, array(1, 80000)),
		'max-width'=>array('range', 1800, array(1, 80000)),
		'max-height'=>array('range', 80000, array(1, 80000)),
		'line-height'=>array('range', '14px', array(1, 50)),
		'color'=>array('pcre', '#000000', array($pattern['color'])),
		'background'=>array('pcre', 'none', array($pattern['color'], '#url\((https?://[^\'"\\\\<>]+?:?\d?)?([^\'"\\\\<>:]+?)*\)[\w\s\-]*$#')),
		'background-color'=>array('pcre', 'none', array($pattern['color'])),
		'background-image'=>array('pcre', 'none', array($pattern['img_url'])),
		'background-position'=>array('pcre', 'none', array($pattern['safe'])),
		'border'=>array('pcre', 'none', array($pattern['css'])),
		'border-left'=>array('pcre', 'none', array($pattern['css'])),
		'border-right'=>array('pcre', 'none', array($pattern['css'])),
		'border-top'=>array('pcre', 'none', array($pattern['css'])),
		'border-left-color'=>array('pcre', 'none', array($pattern['css'])),
		'border-right-color'=>array('pcre', 'none', array($pattern['css'])),
		'border-top-color'=>array('pcre', 'none', array($pattern['css'])),
		'border-bottom-color'=>array('pcre', 'none', array($pattern['css'])),
		'border-left-width'=>array('pcre', 'none', array($pattern['css'])),
		'border-right-width'=>array('pcre', 'none', array($pattern['css'])),
		'border-top-width'=>array('pcre', 'none', array($pattern['css'])),
		'border-bottom-width'=>array('pcre', 'none', array($pattern['css'])),
		'border-bottom-style'=>array('pcre', 'none', array($pattern['css'])),
		'margin-left'=>array('range', 0, array(0, 100)),
		'margin-right'=>array('range', 0, array(0, 100)),
		'margin-top'=>array('range', 0, array(0, 100)),
		'margin-bottom'=>array('range', 0, array(0, 100)),
		'margin'=>array('pcre', '', array($pattern['safe'])),
		'padding'=>array('pcre', '', array($pattern['safe'])),
		'padding-left'=>array('range', 0, array(0, 100)),
		'padding-right'=>array('range', 0, array(0, 100)),
		'padding-top'=>array('range', 0, array(0, 100)),
		'padding-bottom'=>array('range', 0, array(0, 100)),
		'zoom'=>array('range', 1, array(1, 10)),
		'list-style'=>array('list', 'none', array('disc', 'circle', 'square', 'decimal', 'lower-roman', 'upper-roman', 'none')),
		'text-align'=>array('list', 'left', array('left', 'right', 'center', 'justify')),
		'text-indent'=>array('range', 0, array(0, 100)),
		
		// 代码高亮需要支持，但是不安全！
		/*
		'position'=>array('list', 'static', array('absolute', 'fixed', 'relative', 'static')),
		'left'=>array('range', 0, array(0, 1000)),
		'top'=>array('range', 0, array(0, 1000)),
		'white-space'=>array('list', 'nowrap', array('nowrap', 'pre')),
		'word-wrap'=>array('list', 'normal', array('break-word', 'normal')),
		'word-break'=>array('list', 'break-all', array('break-all', 'normal')),
		'display'=>array('list', 'block', array('block', 'table', 'none', 'inline-block', 'table-cell')),
		'overflow'=>array('list', 'auto', array('scroll', 'hidden', 'auto')),
		'overflow-x'=>array('list', 'auto', array('scroll', 'hidden', 'auto')),
		'overflow-y'=>array('list', 'auto', array('scroll', 'hidden', 'auto')),
		*/
		
	);
	
	// hook model_xn_html_safe_new_before.php
	$safehtml = new HTML_White($white_tag, $white_value, $white_css, $arg);
	
	// hook model_xn_html_safe_parse_before.php
	$result = $safehtml->parse($doc);
	
	// hook model_xn_html_safe_end.php

	return $result;
}

/**
 * 渲染用户封禁状态徽章 HTML
 * 接收 user 数组或 ban_type 数值；ban_type>0 时返回徽章 HTML，否则返回空串
 * 复用 UserBanService::getBanTypeLabel() 获取标签与颜色，保持单一数据源
 * @param array|int $user 用户数组（含 ban_type）或 ban_type 数值
 * @return string
 */
function user_ban_badge_html($user) {
	$banType = is_array($user) ? (isset($user['ban_type']) ? intval($user['ban_type']) : 0) : intval($user);
	if($banType <= 0) return '';
	if(!class_exists('UserBanService', false)) {
		include_once APP_PATH . 'lib/UserBanService.php';
	}
	$label = UserBanService::getBanTypeLabel($banType);
	return '<span class="badge bg-' . $label['color'] . ' ms-1" style="font-size:0.6em">' . $label['label'] . '</span>';
}

/**
 * 渲染被封禁用户内容隐藏占位提示 HTML
 * @return string
 */
function user_ban_hidden_notice_html() {
	return '<div class="alert alert-warning py-2 px-3 mb-2 small"><i class="ti ti-eye-off me-1"></i>' . lang('user_ban_content_hidden') . '</div>';
}

// hook model_misc_end.php

?>