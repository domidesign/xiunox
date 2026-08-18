<?php


// 只能在当前 request 生命周期缓存，要跨进程，可以再加一层缓存： memcached/xcache/apc/
$g_static_users = array(); // 变量缓存

// user_update() 受保护字段，禁止通过 user_update() 直接修改
// ponytail: password_ver 必须受保护——否则外部 user_update(['password_ver'=>0]) 可重置版本号让改密前 token 复活
define('USER_UPDATE_PROTECTED_FIELDS', array('password', 'password_hash', 'password_ver', 'salt', 'gid'));

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
				$_hash = password_hash($password, PASSWORD_DEFAULT);
				user__update($user['uid'], array(
					'password' => '',
					'password_hash' => $_hash,
				));
				// 同步静态缓存，避免同请求内 user_read_cache 读到旧 md5 密码，
				// 导致 token 密码指纹（md5(password_hash)）与数据库不一致
				global $g_static_users;
				if(isset($g_static_users[$user['uid']])) {
					$g_static_users[$user['uid']]['password'] = '';
					$g_static_users[$user['uid']]['password_hash'] = $_hash;
				}
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

	// 匿名化：清空身份信息（password/email/avatar/IP 等），保留 uid 和帖子
	// ponytail: 不调 user_update() 因 password/salt/gid 是受保护字段，直接走 user__update
	// 审计日志由调用方记录（admin_log_create），保留原 username 痕迹
	// ⚠️ bbs_user 的 username/nickname/email 均为 UNIQUE 索引，多个用户匿名化时
	// 必须保证字段值唯一。nickname 不能用统一文案（如「已注销用户」），否则触发
	// 1062 Duplicate entry 错误。存储用 deleted_{uid}，user_format() 显示时统一覆盖为「已注销用户」
	$anonymize_update = array(
		'username'      => 'deleted_' . $uid,
		'nickname'      => 'deleted_' . $uid,
		'email'         => 'deleted_' . $uid . '@anon.invalid',
		'password'      => '',
		'password_sms'  => '',
		'salt'          => '',
		'password_hash' => '',
		'realname'      => '',
		'idnumber'      => '',
		'mobile'        => '',
		'qq'            => '',
		'signature'     => '',
		'create_ip'     => 0,
		'login_ip'      => 0,
		'last_login_ip' => 0,
		'login_attempts'=> 0,
		'banned_until'  => 0,
		'ban_reason'    => '',
		'ban_admin_uid' => 0,
		'ban_time'      => 0,
		// ban_type 保留，用于审计识别已注销用户
		'avatar'        => 0, // 0 = 默认头像
	);
	// ponytail: 匿名化时 password_ver +1，强制失效所有旧 token
	// 注销账号属于"身份变更"，按安全最佳实践应立即撤销所有会话
	if(db_check_column_exists('user', 'password_ver')) {
		$anonymize_update['password_ver'] = intval($user['password_ver']) + 1;
	}

	$r = user__update($uid, $anonymize_update);
	if($r === FALSE) return FALSE;

	// 删除头像文件
	$user['avatar_path'] AND xn_unlink($user['avatar_path']);

	// 清理用户缓存
	!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");
	if(isset($g_static_users[$uid])) unset($g_static_users[$uid]);

	// 全站统计：注销用户计入 users- 统计
	runtime_set('users-', 1);

	// hook model_user_delete_end.php
	return $r;
}

/**
 * 彻底物理删除用户及其所有内容（帖子/回帖/附件/关注等）
 * 用于管理员明确要求完全清除用户数据的场景
 * 默认 user_delete() 是匿名化保留帖子，本方法是不可恢复的物理删除
 */
function user_purge($uid) {
	global $conf, $g_static_users;
	// hook model_user_purge_start.php

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

	// 兜底：mythread 表可能不完整（历史数据/并发异常/迁移漏数据），
	// 再按 uid 直接查 thread 表清理漏删的主题帖。必须在 post_delete_by_uid 之前执行，
	// 否则 post_delete_by_uid 会删掉漏删 thread 的 first post，产生孤儿 thread（thread 在但 first post 不存在）
	$orphan_page = 1;
	while(true) {
		$orphan_threads = db_find('thread', array('uid'=>$uid, 'is_deleted'=>0), array(), $orphan_page, $batch_size);
		if(empty($orphan_threads)) break;
		foreach($orphan_threads as $orphan_thread) {
			thread_delete($orphan_thread['tid']);
		}
		if(count($orphan_threads) < $batch_size) break;
		$orphan_page++;
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

	// hook model_user_purge_end.php
	return $r;
}

/**
 * 判断用户是否为已注销（匿名化）用户
 * 匿名化后 username 形如 'deleted_{uid}'，email 形如 'deleted_{uid}@anon.invalid'
 */
function user_is_anonymized($user) {
	if(empty($user) || empty($user['username'])) return FALSE;
	return preg_match('/^deleted_\d+$/', $user['username']) === 1;
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

	// 已注销（匿名化）用户：display_name 统一显示「已注销用户」，防止泄露原 username 规律
	if(user_is_anonymized($user)) {
		$label = lang('deleted_user_label');
		$user['display_name'] = $label;
		$user['username'] = $label;
		$user['nickname'] = $label;
	} else {
		// 昵称显示名：nickname 优先，为空时 fallback 到 username
		$user['display_name'] = !empty($user['nickname']) ? $user['nickname'] : $user['username'];
	}

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
	global $time, $conf;
	$_uid = user_token_get_do();
	// hook model_user_token_get_start.php

	if(!$_uid) {
		// ponytail: token 校验失败必须清掉 cookie，否则浏览器继续带旧 token
		// 每次请求都重复触发 user_token_get_do 写 password_changed 日志，导致日志爆炸
		// cookie_path 必须与 user_token_set/user_token_clear 一致（默认 /），否则只清当前路径下的 cookie
		$_cookie_path = !empty($conf['cookie_path']) ? $conf['cookie_path'] : '/';
		setcookie('bbs_token', '', user_cookie_options($time - 8640000, $_cookie_path));
	}

	// hook model_user_token_get_end.php

	return $_uid;
}

// 登录态丢失排查日志
// ponytail: 只记失败路径（token 校验失败/强制下线/主动退出），游客无 token 的请求不记，避免日志刷盘。
//   2026-08-17 应排查需求取消 300 秒去重：全量记录每条掉线请求（含动作上下文与会话/cookie 状态），
//   便于完整还原"用户在做什么动作时被踢"。token 损坏/异常场景日志量会明显增大，排查完可按需恢复去重。
function user_login_trace($reason, $uid = 0, $extra = array()) {
	$_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
	$_ua = isset($_SERVER['HTTP_USER_AGENT']) ? xn_substr($_SERVER['HTTP_USER_AGENT'], 0, 80) : '';
	$_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	$_msg = "[login-lost] reason={$reason} uid=" . intval($uid) . " ip={$_ip} uri={$_uri} ua={$_ua}";

	// 动作上下文：请求方法 + 路由名/子动作 + 来源页，定位"用户在做什么时掉线"
	$_method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
	$_route = function_exists('param') ? (string)param(0) : '';
	$_sub   = function_exists('param') ? (string)param(1) : '';
	$_referer = isset($_SERVER['HTTP_REFERER']) ? xn_substr($_SERVER['HTTP_REFERER'], 0, 120) : '';
	$_msg .= " method={$_method} route={$_route}/{$_sub} referer={$_referer}";

	// 会话/cookie 状态：区分"session 丢了"还是"token cookie 丢了"还是"token 无效"
	$_sess_uid = isset($_SESSION['uid']) ? intval($_SESSION['uid']) : -1;
	$_msg .= " sess_uid={$_sess_uid} token_cookie=" . (isset($_COOKIE['bbs_token']) ? '1' : '0') . " sid_cookie=" . (isset($_COOKIE['bbs_sid']) ? '1' : '0');

	foreach((array)$extra as $_k => $_v) {
		$_msg .= " {$_k}={$_v}";
	}
	xn_log($_msg, 'user_login_error');
}

// 判断两个 IP 是否同 C 段（/24）
// ponytail: 用于 token IP 校验容错——多 IP 出口服务器下同 /24 网段视为可信
function user_ip_same_c_segment($ip1, $ip2) {
	$long1 = ip2long($ip1);
	$long2 = ip2long($ip2);
	if($long1 === false || $long2 === false) return $ip1 === $ip2;
	// /24 掩码：保留前 24 位，后 8 位忽略
	return ($long1 & 0xFFFFFF00) === ($long2 & 0xFFFFFF00);
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
	if(empty($s)) {
		user_login_trace('token_decrypt_fail', 0, array('token_prefix' => xn_substr($token, 0, 8)));
		return FALSE;
	}
	$arr = explode("\t", $s);
	$_ver = null;
	if(count($arr) == 5) {
		// 新格式 token：ip \t time \t uid \t pwd_fingerprint \t password_ver
		list($_ip, $_time, $_uid, $_pwd, $_ver) = $arr;
	} elseif(count($arr) == 4) {
		// 旧格式 token：ip \t time \t uid \t pwd_fingerprint（兼容期，存量 cookie 仍走 md5 指纹校验）
		list($_ip, $_time, $_uid, $_pwd) = $arr;
	} else {
		user_login_trace('token_format_bad', 0, array('token_prefix' => xn_substr($token, 0, 8)));
		return FALSE;
	}
	// IP 校验已移除：
	// ponytail: 原为同 /24 网段容错匹配，但全局 CDN（EdgeOne/Cloudflare 等）回源时
	// REMOTE_ADDR 为边缘节点 IP 且跨节点即跨 C 段，token 校验失败导致用户被强制下线。
	// token 本身含 xn_key 加密 + 密码指纹（30 分钟窗口重校验），已足够防止伪造/盗用，
	// 不再绑定 IP。如需 IP 维度防护，应通过真实 IP 透传（信任 CDN-SRC-IP）后另做限流。
	// if(!user_ip_same_c_segment($ip, $_ip)) return FALSE;
	//if($time - $_time > 86400) return FALSE;
	// 检查密码是否被修改。
	if($time - $_time > 1800) {
		$user = user_read($_uid);
		if(empty($user)) {
			user_login_trace('user_deleted', $_uid, array('token_ip' => $_ip, 'token_time' => $_time));
			return 0;
		}
		// 新格式 token 优先校验 password_ver：改密时 +1，自动升级（md5+salt→bcrypt）不变
		// ponytail: 用 ver 代替 md5(password_hash) 指纹校验的核心收益——
		// 避免明文密码未变但存储格式升级时，多设备旧 token 因指纹变化被误踢下线
		if($_ver !== null && db_check_column_exists('user', 'password_ver')) {
			if(intval($user['password_ver']) !== intval($_ver)) {
				user_login_trace('password_changed', $_uid, array('token_ip' => $_ip, 'token_time' => $_time, 'ver_token' => $_ver, 'ver_db' => $user['password_ver']));
				return 0;
			}
		} elseif(md5(!empty($user['password_hash']) ? $user['password_hash'] : $user['password']) != $_pwd) {
			// 兼容旧格式 token：md5 指纹校验。存量 token 仍走此路径直到自然过期（≤7 天）
			user_login_trace('password_changed', $_uid, array('token_ip' => $_ip, 'token_time' => $_time));
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
	// 用户登录时效（天），默认 7 天；配置缺失/非法时回退默认值，最小 1 天
	$expire_days = isset($conf['security_user_login_expire']) ? intval($conf['security_user_login_expire']) : 7;
	$expire_days = max(1, $expire_days);
	setcookie('bbs_token', $token, user_cookie_options($time + $expire_days * 86400, $_cookie_path));

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
	$pwd = md5(!empty($user['password_hash']) ? $user['password_hash'] : $user['password']);
	// ponytail: 新格式 token 带 password_ver（5 字段），校验时优先比 ver 而非 md5 指纹
	// 兼容：旧 token 为 4 字段，user_token_get_do 按 count($arr) 分流走旧 md5 校验路径
	$ver = db_check_column_exists('user', 'password_ver') ? intval($user['password_ver']) : 0;
	$tokenkey = md5(xn_key());
	$token = xn_encrypt("$ip	$time	$uid	$pwd	$ver", $tokenkey);

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
	// 优先从参数获取（兼容 name="referer" / name="next" / ?redirect_url= 三种来源）
	$referer = param('referer');
	empty($referer) AND $referer = param('next');
	empty($referer) AND $referer = param('redirect_url');
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
		// 普通模式：
		// 已有密码的用户必须验证旧密码；
		// 无密码用户（如纯 OAuth 绑定账号，password/password_hash 均为空）跳过旧密码验证，
		// 此时本调用等同于"首次设置密码"，旧密码无从谈起，安全由已登录会话保证
		$has_password = !empty($user['password']) || !empty($user['password_hash']);
		if($has_password && (empty($old_password) || !user_login_verify($old_password, $user))) {
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

	// ponytail: password_ver +1 让所有旧 token（含其他设备）失效，符合 OAuth 2.1 改密撤销 token 最佳实践
	// token 校验用 ver 代替 md5(password_hash) 指纹，避免 user_login_verify 自动升级（md5+salt→bcrypt）
	// 时因指纹变化误踢明文密码未变的多设备用户
	if(db_check_column_exists('user', 'password_ver')) {
		$update['password_ver'] = intval($user['password_ver']) + 1;
	}

	// 直接调用 user__update 绕过白名单（本函数已做权限验证）
	$r = user__update($uid, $update);

	if($r !== FALSE) {
		// 清除用户 token，强制重新登录
		user_token_clear();

		// ponytail: 改密后撤销该用户所有 API access/refresh token，防止旧 token 继续可用（OAuth 2.1 最佳实践）
		// 直接 db_delete 避免对 ApiAuthService 类的硬依赖（model 层不应依赖 lib）
		db_delete('api_token', array('uid' => $uid));

		// 更新静态缓存
		isset($g_static_users[$uid]) AND $g_static_users[$uid] = array_merge($g_static_users[$uid], $update);

		// 清除其他缓存
		!in_array($conf['cache']['type'], array('mysql', 'pdo_mysql')) AND cache_delete("user-$uid");

		xn_log("user_change_password(): Password changed for uid=$uid, all API tokens revoked" . ($is_admin ? " (by admin)" : ""), 'security');
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