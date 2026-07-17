<?php


// 只能在当前 request 生命周期缓存，要跨进程，可以再加一层缓存： memcached/xcache/apc/
$g_static_users = array(); // 变量缓存

// user_update() 受保护字段，禁止通过 user_update() 直接修改
define('USER_UPDATE_PROTECTED_FIELDS', array('password', 'password_hash', 'salt', 'gid'));

// hook model_user_start.php

// ------------> 批量预加载用户数据，消除 N+1 查询

/**
 * 批量预加载用户数据到 $g_static_users，避免后续 user_read_cache() 逐条查库
 * 可选批量预加载当前用户对这些用户的关注状态
 * @param array $uids 需要预加载的用户 uid 列表
 * @param bool $preload_follow 是否预加载关注状态（默认false，仅关注列表等需要时才开启）
 */
function user_preload($uids, $preload_follow = false) {
    global $g_static_users, $uid, $g_preloaded_follows;
    if(empty($uids)) return;

    // 过滤已缓存的 uid
    $missing = array();
    foreach($uids as $uid_val) {
        $uid_val = intval($uid_val);
        if($uid_val > 0 && !isset($g_static_users[$uid_val])) {
            $missing[$uid_val] = $uid_val;
        }
    }

    // 仅在明确需要时才预加载关注状态（如关注列表页、用户主页等）
    if($preload_follow && !empty($uid) && !isset($g_preloaded_follows)) {
        $g_preloaded_follows = array();
        $target_uids = array();
        foreach($uids as $uid_val) {
            $uid_val = intval($uid_val);
            if($uid_val > 0 && $uid_val != $uid) {
                $target_uids[] = $uid_val;
            }
        }
        if(!empty($target_uids)) {
            $target_uids = array_unique($target_uids);
            $follows = user_follow_read_batch($uid, $target_uids);
            foreach($target_uids as $tid) {
                $g_preloaded_follows[$tid] = !empty($follows[$tid]) ? 1 : 0;
            }
        }
    }

    // 批量查询并格式化
    if(!empty($missing)) {
        $users = db_find('user', array('uid'=>array_values($missing)), array(), 1, count($missing), 'uid');
        if($users) {
            foreach($users as $user) {
                user_format($user);
                $g_static_users[$user['uid']] = $user;
            }
        }

        // 未查到的 uid 标记为游客，避免反复查库
        foreach($missing as $uid_val) {
            if(!isset($g_static_users[$uid_val])) {
                $g_static_users[$uid_val] = user_guest();
            }
        }
    }
}

// ------------> 最原生的 CURD，无关联其他数据。

function user__create($arr) {
	// hook model_user__create_start.php
	$r = db_insert('user', $arr);
	// hook model_user__create_end.php
	return $r;
}

function user__update($uid, $update) {
	// hook model_user__update_start.php
	$r = db_update('user', array('uid'=>$uid), $update);
	// hook model_user__update_end.php
	return $r;
}

// 带下限保护的计数器递减：GREATEST(field-N, 0)，防止负数
// ponytail: user__update(array('threads-'=>N)) 走 db_array_to_update_sqladd 生成 threads=threads-N 无保护，
// 并发/历史脏数据/重复删除场景会变负数，统一改用本函数。已知天花板：调用方需自行 cache_delete('user-$uid')（与 user__update 一致）
function user_dec($uid, $field, $n = 1) {
	$uid = intval($uid);
	$n = intval($n);
	if($uid <= 0 || $n <= 0) return FALSE;
	if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) return FALSE; // 字段名白名单
	global $db;
	$tablepre = $db->tablepre;
	return db_exec("UPDATE `{$tablepre}user` SET `$field` = GREATEST(`$field` - $n, 0) WHERE uid = '$uid'");
}

function user__read($uid) {
	// hook model_user__read_start.php
	$user = db_find_one('user', array('uid'=>$uid));
	// hook model_user__read_end.php
	return $user;
}

function user__delete($uid) {
	// hook model_user__delete_start.php
	$r = db_delete('user', array('uid'=>$uid));
	// hook model_user__delete_end.php
	return $r;
}

// ------------> 关联 CURD，主要是强相关的数据，比如缓存。弱相关的大量数据需要另外处理。

function user_create($arr) {
	// hook model_user_create_start.php
	global $conf;
	$r = user__create($arr);
	
	// 全站统计
	runtime_set('users+', 1);
	runtime_set('todayusers+', 1);
	
	// hook model_user_create_end.php
	return $r;
}

function user_update($uid, $arr) {
	// 过滤受保护字段，防止通过 user_update() 直接修改密码、盐值、用户组等敏感字段
	foreach(USER_UPDATE_PROTECTED_FIELDS as $field) {
		if(array_key_exists($field, $arr)) {
			xn_log("user_update(): Attempt to modify protected field '$field' for uid=$uid, ignored. Use user_change_password() instead.", 'security');
			unset($arr[$field]);
		}
	}
	// hook model_user_update_start.php
	global $conf, $g_static_users;
	$r = user__update($uid, $arr);
	!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");
	isset($g_static_users[$uid]) AND $g_static_users[$uid] = array_merge($g_static_users[$uid], $arr);
	
	// hook model_user_update_end.php
	return $r;
}

function user_login_verify($password, $user) {
	// 优先使用 bcrypt 验证（新格式）
	if(!empty($user['password_hash'])) {
		return password_verify($password, $user['password_hash']);
	}
	// 旧格式：md5(md5(明文)+salt)，兼容 4.0.4 升级用户
	if(!empty($user['password']) && !empty($user['salt'])) {
		if(hash_equals($user['password'], md5(md5($password).$user['salt']))) {
			// 自动升级：清空旧字段，写入 bcrypt(明文) 到 password_hash
			if(db_check_column_exists('user', 'password_hash')) {
				user__update($user['uid'], array(
					'password' => '',
					'password_hash' => password_hash($password, PASSWORD_DEFAULT),
				));
			}
			return TRUE;
		}
		return FALSE;
	}
	return FALSE;
}

function user_read($uid) {
	global $g_static_users;
	if(empty($uid)) return array();
	$uid = intval($uid);
	// hook model_user_read_start.php
	$user = user__read($uid);
	if(empty($user)) return array(); // 用户不存在时返回空数组，保持返回类型一致，避免下游 null 解引用
	user_format($user);
	$g_static_users[$uid] = $user;
	// hook model_user_read_end.php
	return $user;
}


// 从缓存中读取，避免重复从数据库取数据，主要用来前端显示，可能有延迟。重要业务逻辑不要调用此函数，数据可能不准确，因为并没有清理缓存，针对 request 生命周期有效。
function user_read_cache($uid) {
	global $conf, $g_static_users;
	if(isset($g_static_users[$uid])) return $g_static_users[$uid];
	
	// hook model_user_read_cache_start.php
	
	// 游客
	if($uid == 0) return user_guest();
	
	if(!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql'))) {
		$r = cache_get("user-$uid");
		if($r === NULL || $r === FALSE) {
			$r = user_read($uid);
			cache_set("user-$uid", $r);
		}
	} else {
		$r = user_read($uid);
	}
	
	$g_static_users[$uid] = $r ? $r : user_guest();
	
	// hook model_user_read_cache_end.php
	return $g_static_users[$uid];
}

function user_delete($uid) {
	global $conf, $g_static_users;
	// hook model_user_delete_start.php

	$user = user_read($uid);
	if(empty($user)) return NULL;

	// 分批清理主题帖，避免一次查询过多数据
	$batch_size = 1000;
	$page = 1;
	while(true) {
		$threadlist = mythread_find_by_uid($uid, $page, $batch_size);
		if(empty($threadlist)) break;
		foreach($threadlist as $thread) {
			thread_delete($thread['tid']);
		}
		if(count($threadlist) < $batch_size) break;
		$page++;
	}

	// 清理回帖
	post_delete_by_uid($uid);
	
	// 清理附件
	attach_delete_by_uid($uid);

	user_follow_delete_by_uid($uid);

	$user['avatar_path'] AND xn_unlink($user['avatar_path']);
	
	$r = user__delete($uid);
	
	!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");
	if(isset($g_static_users[$uid])) unset($g_static_users[$uid]);
	
	// 全站统计
	runtime_set('users-', 1);
	
	// hook model_user_delete_end.php
	return $r;
}

function user_find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	global $g_static_users;
	// hook model_user_find_start.php
	$userlist = db_find('user', $cond, $orderby, $page, $pagesize);
	if($userlist) foreach ($userlist as &$user) {
		$g_static_users[$user['uid']] = $user;
		user_format($user);
	}
	// hook model_user_find_end.php
	return $userlist;
}

// ------------> 其他方法

function user_read_by_email($email) {
	global $g_static_users;
	// hook model_user_read_by_email_start.php
	$user = db_find_one('user', array('email'=>$email));
	user_format($user);
	if(empty($user)) return $user;
	$g_static_users[$user['uid']] = $user;
	// hook model_user_read_by_email_end.php
	return $user;
}

function user_read_by_username($username) {
	global $g_static_users;
	// hook model_user_read_by_username_start.php
	$user = db_find_one('user', array('username'=>$username));
	user_format($user);
	if(empty($user)) return $user;
	$g_static_users[$user['uid']] = $user;
	// hook model_user_read_by_username_end.php
	return $user;
}

/**
 * 批量根据用户名查询用户，消除 N+1 查询
 * 单次 SQL：SELECT * FROM user WHERE username IN ('a','b','c')
 * @param array $usernames 用户名数组
 * @return array 以 username 为 key 的 user 数组（含 user_format 处理后的字段），查不到返回空数组
 */
function user_find_by_usernames($usernames) {
	global $g_static_users;
	// hook model_user_find_by_usernames_start.php
	if(empty($usernames) || !is_array($usernames)) return array();
	// 去重、去空
	$usernames = array_unique(array_filter($usernames));
	if(empty($usernames)) return array();

	// 单次 SQL 查询（db_find 的数组条件会自动转 IN）
	$userlist = db_find('user', array('username'=>array_values($usernames)), array(), 1, count($usernames), 'username');
	if($userlist) {
		foreach($userlist as &$user) {
			$g_static_users[$user['uid']] = $user;
			user_format($user);
		}
	}
	// hook model_user_find_by_usernames_end.php
	return $userlist ? $userlist : array();
}

function user_count($cond = array()) {
	// hook model_user_count_start.php
	$n = db_count('user', $cond);
	// hook model_user_count_end.php
	return $n;
}

function user_maxid($cond = array()) {
	// hook model_user_maxid_start.php
	$n = db_maxid('user', 'uid');
	// hook model_user_maxid_end.php
	return $n;
}

function avatar_preset_files() {
	static $cache = null;
	if($cache !== null) return $cache;
	global $conf;
	$dir = APP_PATH.'view/img/avatars/';
	if(!is_dir($dir)) { $cache = array(); return $cache; }
	$files = glob($dir.'*.{png,jpg,jpeg,gif,svg,webp,bmp,avif}', GLOB_BRACE);
	if(!$files) { $cache = array(); return $cache; }
	sort($files, SORT_NATURAL);
	$result = array();
	foreach($files as $i => $fullpath) {
		$basename = basename($fullpath);
		$result[$i + 1] = array(
			'filename' => $basename,
			'url' => $conf['view_url'].'img/avatars/'.$basename
		);
	}
	$cache = $result;
	return $result;
}

function user_format(&$user) {
	global $conf, $grouplist;
	if(empty($user)) return;

	// hook model_user_format_start.php

	// 昵称显示名：nickname 优先，为空时 fallback 到 username
	$user['display_name'] = !empty($user['nickname']) ? $user['nickname'] : $user['username'];

	$user['create_ip_fmt']   = long2ip(intval($user['create_ip']));
	$user['create_date_fmt'] = empty($user['create_date']) ? '0000-00-00' : date('Y-m-d', $user['create_date']);
	$user['login_ip_fmt']    = long2ip(intval($user['login_ip']));
	$user['login_date_fmt'] = empty($user['login_date']) ? '0000-00-00' : date('Y-m-d', $user['login_date']);
	
	$user['groupname'] = group_name($user['gid']);
	
	$dir = substr(sprintf("%09d", $user['uid']), 0, 3);
	// hook model_user_format_avatar_url_before.php
	if($user['avatar'] < 0) {
		$preset_id = abs($user['avatar']);
		$preset_list = avatar_preset_files();
		if(isset($preset_list[$preset_id])) {
			$user['avatar_url'] = $preset_list[$preset_id]['url'];
		} else {
			$user['avatar_url'] = default_avatar_url();
		}
		$user['avatar_path'] = '';
	} elseif($user['avatar'] > 0) {
		// 按优先级查找头像文件：jpg > png > webp（兼容旧格式）
		$_avatar_ext = 'jpg';
		$_avatar_path = $conf['upload_path']."avatar/$dir/$user[uid].jpg";
		if(!is_file($_avatar_path)) {
			$_avatar_path = $conf['upload_path']."avatar/$dir/$user[uid].png";
			if(is_file($_avatar_path)) {
				$_avatar_ext = 'png';
			} else {
				$_avatar_path = $conf['upload_path']."avatar/$dir/$user[uid].webp";
				if(is_file($_avatar_path)) {
					$_avatar_ext = 'webp';
				} else {
					$_avatar_path = '';
				}
			}
		}
		if(!empty($_avatar_path)) {
			$user['avatar_url'] = $conf['upload_url']."avatar/$dir/$user[uid].$_avatar_ext?".$user['avatar'];
			$user['avatar_path'] = $_avatar_path;
		} else {
			$user['avatar_url'] = default_avatar_url();
			$user['avatar_path'] = '';
		}
	} else {
		$user['avatar_url'] = default_avatar_url();
		$user['avatar_path'] = '';
	}
	
	if(isset($grouplist[$user['gid']])) {
		$user['group_icon_class'] = group_icon($grouplist[$user['gid']]);
		$user['group_color'] = isset($grouplist[$user['gid']]['color']) ? $grouplist[$user['gid']]['color'] : '';
	} else {
		$user['group_icon_class'] = '';
		$user['group_color'] = '';
	}
	
	$user['online_status'] = 1;
	$user['is_followed'] = 0;
	if(!empty($uid) && $uid != $user['uid']) {
		// 仅在页面显式预加载了关注状态时才读取，避免列表页不必要的 follow 查询
		global $g_preloaded_follows;
		if(isset($g_preloaded_follows) && isset($g_preloaded_follows[$user['uid']])) {
			$user['is_followed'] = $g_preloaded_follows[$user['uid']];
		}
		// 未预加载时不查库，is_followed 保持 0（关注状态通过 htmx 按需加载）
	}
	// hook model_user_format_end.php
}


function user_guest() {
	global $conf;
	static $guest = NULL;
	// hook model_user_guest_start.php
	
	if($guest) return $guest; // 返回引用，节省内存。
	$guest = array (
		'uid' => 0,
		'gid' => 0,
		'groupname' => lang('guest_group'),
		'username' => lang('guest'),
		'avatar_url' => default_avatar_url(),
		'create_ip_fmt' => '',
		'create_date_fmt' => '',
		'login_date_fmt' => '',
		'email' => '',
		
		'threads' => 0,
		'posts' => 0,
	);
	
	// hook model_user_guest_end.php
	return $guest; // 防止内存拷贝
}

// 根据积分来调整用户组
function user_update_group($uid) {
	global $conf, $grouplist;
	$user = user_read_cache($uid);
	if($user['gid'] < 100) return FALSE;
	
	// hook model_user_update_group_start.php
	
	// 遍历 credits 范围，调整用户组
	foreach($grouplist as $group) {
		if($group['gid'] < 100) continue;
		$n = $user['credits']; // 根据积分
		// hook model_user_update_group_policy_start.php
		if($n >= $group['creditsfrom'] && $n < $group['creditsto']) {
			if($user['gid'] != $group['gid']) {
				// 修复：使用 user__update 原始层绕过 USER_UPDATE_PROTECTED_FIELDS 过滤（原代码 gid 会被静默移除）
				user__update($uid, array('gid' => $group['gid']));
				// 修复：手动清理用户缓存（原代码绕过 user_update，缓存未清会导致后续读到旧 gid）
				global $g_static_users;
				!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");
				isset($g_static_users[$uid]) AND $g_static_users[$uid]['gid'] = $group['gid'];
				// hook model_user_update_group_success.php
				return TRUE;
			}
		}
	}
	
	// hook model_user_update_group_end.php
	return FALSE;
}

// uids: 1,2,3,4 -> array()
function user_find_by_uids($uids) {
	// hook model_user_find_by_uids_start.php
	$uids = trim($uids);
	if(empty($uids)) return array();
	$arr = explode(',', $uids);
	$r = array();
	foreach($arr as $_uid) {
		$user = user_read_cache($_uid);
		if(empty($user)) continue;
		$r[$user['uid']] = $user;
	}
	// hook model_user_find_by_uids_end.php
	return $r;
}

// 获取用户安全信息
function user_safe_info($user) {
	// hook model_user_safe_info_start.php
	unset($user['password']);
	unset($user['email']);
	unset($user['salt']);
	unset($user['password_sms']);
	unset($user['idnumber']);
	unset($user['realname']);
	unset($user['qq']);
	unset($user['mobile']);
	unset($user['create_ip']);
	unset($user['create_ip_fmt']);
	unset($user['create_date']);
	unset($user['create_date_fmt']);
	unset($user['login_ip']);
	unset($user['login_date']);
	unset($user['login_ip_fmt']);
	unset($user['login_date_fmt']);
	unset($user['logins']);
	// hook model_user_safe_info_end.php
	return $user;
}


// 用户
function user_token_get() {
	global $time;
	$_uid = user_token_get_do();
	// hook model_user_token_get_start.php

	if(!$_uid) {
		//setcookie('bbs_token', '', $time - 86400, '');
	}
	
	// hook model_user_token_get_end.php
	
	return $_uid;
}

// 用户
function user_token_get_do() {
	global $time, $ip, $conf;
	$token = param('bbs_token');

	// hook model_user_token_get_do_start.php

	if(empty($token)) return FALSE;
	$tokenkey = md5(xn_key());
	$used_v2 = false;
	$s = xn_decrypt($token, $tokenkey, $used_v2);
	if(empty($s)) return FALSE;
	$arr = explode("\t", $s);
	if(count($arr) != 4) return FALSE;
	list($_ip, $_time, $_uid, $_pwd) = $arr;
	// IP 校验（防止 token 被盗后跨 IP 复用）
	if($ip != $_ip) return FALSE;
	//if($time - $_time > 86400) return FALSE;
	// 检查密码是否被修改。
	if($time - $_time > 1800) {
		$user = user_read($_uid);
		if(empty($user)) return 0;
		if(md5($user['password']) != $_pwd) {
			return 0;
		}
	}

	// 令牌迁移：若解密时回退到 XXTEA（旧格式），重签 v2 令牌并通过 setcookie 下发
	if(!$used_v2) {
		user_token_set($_uid);
	}

	// hook model_user_token_get_do_end.php

	return $_uid;
}

// 获取前台 Cookie 安全选项（与 admin_cookie_options / sess_start 保持一致，读取安全配置）
// 补齐 secure / httponly / samesite 属性，防 Cookie 被窃取与 CSRF
function user_cookie_options($expires = 0, $path = '/') {
	global $conf;
	$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

	// Cookie Secure：security_cookie_secure 已设置时 0=自动检测HTTPS，>0=强制Secure
	// 修复前 0 会 fallthrough 到旧 cookie_secure 配置，导致 HTTPS 下 Secure 标志缺失
	if(isset($conf['security_cookie_secure'])) {
		$cookie_secure = intval($conf['security_cookie_secure']) > 0 || $is_https;
	} elseif(isset($conf['cookie_secure'])) {
		$cookie_secure = intval($conf['cookie_secure']) > 0;
	} else {
		$cookie_secure = $is_https;
	}

	// Cookie HttpOnly：默认开启
	$cookie_httponly = true;
	if(isset($conf['security_cookie_httponly'])) {
		$cookie_httponly = intval($conf['security_cookie_httponly']) > 0;
	}

	// Cookie SameSite：优先使用安全配置，否则默认 Lax（防 CSRF）
	if(isset($conf['security_cookie_samesite']) && in_array($conf['security_cookie_samesite'], array('Lax', 'Strict', 'None'), true)) {
		$samesite = $conf['security_cookie_samesite'];
	} else {
		$samesite = 'Lax';
	}

	return array(
		'expires' => $expires,
		'path' => $path,
		'domain' => '',
		'secure' => $cookie_secure,
		'httponly' => $cookie_httponly,
		'samesite' => $samesite,
	);
}

// 设置 token，防止 sid 过期后被删除
function user_token_set($uid) {
	global $time, $conf;
	if(empty($uid)) return;
	$token = user_token_gen($uid);
	// cookie_path 为空时默认用 /，确保 token 在全站有效
	$_cookie_path = !empty($conf['cookie_path']) ? $conf['cookie_path'] : '/';
	// bbs_token 有效期 7 天（604800 秒，原 100 天 8640000，缩短以降低长期登录泄露风险）
	setcookie('bbs_token', $token, user_cookie_options($time + 604800, $_cookie_path));

	// hook model_user_token_set_end.php
}

function user_token_clear() {
	global $time, $conf;
	// cookie_path 为空时必须用 /，否则 setcookie 会用当前请求目录作为 path
	// 导致退出登录（/user/logout）只清除 /user 路径下的 cookie，根路径下的 token 仍存在
	$_cookie_path = !empty($conf['cookie_path']) ? $conf['cookie_path'] : '/';
	setcookie('bbs_token', '', user_cookie_options($time - 8640000, $_cookie_path));

	// hook model_user_token_clear_end.php
}

function user_token_gen($uid) {
	global $ip, $time, $conf;
	
	// hook model_user_token_gen_start.php
	
	$user = user_read($uid);
	$pwd = md5($user['password']);
	$tokenkey = md5(xn_key());
	$token = xn_encrypt("$ip	$time	$uid	$pwd", $tokenkey);
	
	// hook model_user_token_gen_end.php
	
	return $token;
}


// 前台登录验证
function user_login_check() {
	global $user;
	
	// hook model_user_login_check_start.php
	
	empty($user) AND http_location(url('user-login'));
	
	// hook model_user_login_check_end.php
}



// 获取用户来路
function user_http_referer() {
	// hook user_http_referer_start.php
	// 优先从参数获取（兼容 name="referer" 和 name="next" 两种表单字段名）
	$referer = param('referer');
	empty($referer) AND $referer = param('next');
	empty($referer) AND $referer = array_value($_SERVER, 'HTTP_REFERER', '');

	$referer = str_replace(array('\"', '"', '<', '>', ' ', '*', "\t", "\r", "\n"), '', $referer); // 干掉特殊字符 strip special chars

	// URL 格式校验：允许 http(s)://host 或 http(s)://host/path?query#hash
	if(
		!preg_match('#^(https?://[\w\-=/\.]+(:\d+)?(/[\w\-=/\.%\#?]*)?)$#is', $referer)
		|| strpos($referer, 'user-login') !== FALSE
		|| strpos($referer, 'user-logout') !== FALSE
		|| strpos($referer, 'user-create') !== FALSE
		|| strpos($referer, 'user-setpw') !== FALSE
		|| strpos($referer, 'user-resetpw_complete') !== FALSE
	) {
		$referer = url('');  // 首页绝对路径，避免 ./ 在路径风格下解析为 /user/
	} else {
		// host 白名单校验，仅允许站内跳转，防开放重定向
		$referer_host = parse_url($referer, PHP_URL_HOST);
		$current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
		if ($referer_host && $current_host && $referer_host !== $current_host) {
			$referer = url('');
		}
	}
	// hook user_http_referer_end.php
	return $referer;
}

function user_auth_check($token) {
	// hook user_auth_check_start.php
	global $time;
	$auth = param(2);
	$s = decrypt($auth);
	empty($s) AND message(-1, lang('decrypt_failed'));
	$arr = explode('-', $s);
	count($arr) != 3 AND message(-1, lang('encrypt_failed'));
	list($_ip, $_time, $_uid) = $arr;
	$_user = user_read($_uid);
	empty($_user) AND message(-1, lang('user_not_exists'));
	$time - $_time > 3600 AND message(-1, lang('link_has_expired'));
	// hook user_auth_check_end.php
	return $_user;
}


// 安全修改密码（替代直接 user_update 修改 password 字段）
// $uid: 目标用户 UID
// $new_password: 新密码（明文）
// $old_password: 旧密码（明文），管理员模式可留空
// $is_admin: 是否管理员模式，管理员模式跳过旧密码验证
function user_change_password($uid, $new_password, $old_password = '', $is_admin = FALSE) {
	global $conf, $g_static_users;

	// hook model_user_change_password_start.php

	$user = user_read($uid);
	if(empty($user)) return FALSE;

	if($is_admin) {
		// 管理员模式：验证当前操作者是管理员
		global $user;
		if(empty($user) || $user['gid'] != 1) {
			xn_log("user_change_password(): Non-admin attempted admin password reset for uid=$uid", 'security');
			return FALSE;
		}
	} else {
		// 普通模式：验证旧密码
		if(empty($old_password) || !user_login_verify($old_password, $user)) {
			return FALSE;
		}
	}

	// 直接写 bcrypt(明文) 到 password_hash，清空旧字段
	$update = array(
		'password' => '',
		'salt' => '',
	);

	if(db_check_column_exists('user', 'password_hash')) {
		$update['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
	}

	// 直接调用 user__update 绕过白名单（本函数已做权限验证）
	$r = user__update($uid, $update);

	if($r !== FALSE) {
		// 清除用户 token，强制重新登录
		user_token_clear();

		// 更新静态缓存
		isset($g_static_users[$uid]) AND $g_static_users[$uid] = array_merge($g_static_users[$uid], $update);

		// 清除其他缓存
		!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");

		xn_log("user_change_password(): Password changed for uid=$uid" . ($is_admin ? " (by admin)" : ""), 'security');
	}

	// hook model_user_change_password_end.php

	return $r;
}

// 安全修改用户组（替代直接 user_update 修改 gid 字段）
// $uid: 目标用户 UID
// $new_gid: 新用户组 GID
function user_change_group($uid, $new_gid) {
	global $conf, $g_static_users, $user;

	// hook model_user_change_group_start.php

	// 验证当前操作者是管理员
	if(empty($user) || $user['gid'] != 1) {
		xn_log("user_change_group(): Non-admin attempted to change group for uid=$uid to gid=$new_gid", 'security');
		return FALSE;
	}

	$_user = user_read($uid);
	if(empty($_user)) return FALSE;

	// 禁止将最后一个管理员降级
	if($_user['gid'] == 1 && $new_gid != 1) {
		$admin_count = user_count(array('gid'=>1));
		if($admin_count <= 1) {
			xn_log("user_change_group(): Attempt to demote the last admin uid=$uid", 'security');
			return FALSE;
		}
	}

	// 直接调用 user__update 绕过白名单（本函数已做权限验证）
	$update = array('gid' => $new_gid);
	$r = user__update($uid, $update);

	if($r !== FALSE) {
		// 更新静态缓存
		isset($g_static_users[$uid]) AND $g_static_users[$uid]['gid'] = $new_gid;

		// 清除其他缓存
		!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");

		xn_log("user_change_group(): Group changed for uid=$uid to gid=$new_gid by admin uid={$user['uid']}", 'security');
	}

	// hook model_user_change_group_end.php

	return $r;
}

// hook model_user_end.php

?>