<?php

 // 本地插件
//$plugin_srcfiles = array();
$plugin_paths = array();
$plugins = array();

// todo: 对路径进行处理 include _include(APP_PATH.'view/htm/header.inc.htm');
$g_include_slot_kv = array();

// 原子写入：先写临时文件再 rename，避免并发时其他进程读到截断后的空文件
function _atomic_write($file, $s) {
	$dir = dirname($file);
	if(!is_dir($dir)) {
		mkdir($dir, 0777, TRUE);
	}
	$tmp = $file . '.' . substr(md5($s . mt_rand()), 0, 8) . '.tmp';
	$r = file_put_contents($tmp, $s, LOCK_EX);
	if($r !== FALSE) {
		// Windows 下 rename() 不能覆盖已存在的文件，需先删除
		if(DIRECTORY_SEPARATOR === '\\' && is_file($file)) {
			@unlink($file);
		}
		if(!@rename($tmp, $file)) {
			// Windows 下跨盘符 rename 会失败，回退用 copy+unlink
			if(!@copy($tmp, $file)) {
				@unlink($tmp);
				return file_put_contents($file, $s, LOCK_EX);
			}
			@unlink($tmp);
		}
		clearstatcache();
		return $r;
	}
	// 回退：直接写入
	@unlink($tmp);
	return file_put_contents($file, $s, LOCK_EX);
}

function _include($srcfile) {
	global $conf;
	$len = strlen(APP_PATH);
	$tmpfile = $conf['tmp_path'].substr(str_replace('/', '_', $srcfile), $len);
	if(!is_file($tmpfile) || DEBUG > 1 || !empty($conf['cache_disable'])) {
		// 开始编译
		$s = plugin_compile_srcfile($srcfile);

		// .htm 模板安全检测：扫描危险 PHP 函数，防止主题/插件注入恶意代码
		// 本项目模板直接使用 <?php 标签（非 <!--{php}--> 标记体系），因此不阻止裸 PHP 标签
		// 而是检测 eval/system/exec/assert 等危险函数，命中则拒绝编译并记录日志
		if(pathinfo($srcfile, PATHINFO_EXTENSION) === 'htm') {
			_include_scan_dangerous_php($srcfile, $s);
		}

		// 支持 <template> <slot>
		$g_include_slot_kv = array();
		for($i = 0; $i < 10; $i++) {
			$s = preg_replace_callback('#<template\sinclude="(.*?)">(.*?)</template>#is', '_include_callback_1', $s);
			if(strpos($s, '<template') === FALSE) break;
		}
		_atomic_write($tmpfile, $s);

		$s = plugin_compile_srcfile($tmpfile);
		_atomic_write($tmpfile, $s);

	}
	return $tmpfile;
}

// 扫描 .htm 模板编译内容中的危险 PHP 函数模式
// 检测到则记录日志并终止执行，防止恶意代码通过模板注入执行
function _include_scan_dangerous_php($srcfile, $content) {
	// 危险 PHP 函数/模式列表（不应出现在视图模板中）
	// 使用 (?<!\.) 负向回顾断言排除 JS 方法调用（如 regex.exec()），仅匹配 PHP 全局函数调用
	$dangerous_patterns = array(
		'(?<!\.)\beval\s*\('           => 'eval',
		'(?<!\.)\bassert\s*\('         => 'assert',
		'(?<!\.)\bsystem\s*\('         => 'system',
		'(?<!\.)\bexec\s*\('           => 'exec',
		'(?<!\.)\bshell_exec\s*\('     => 'shell_exec',
		'(?<!\.)\bpassthru\s*\('       => 'passthru',
		'(?<!\.)\bproc_open\s*\('      => 'proc_open',
		'(?<!\.)\bpopen\s*\('          => 'popen',
		'(?<!\.)\bcreate_function\s*\(' => 'create_function',
	);

	foreach($dangerous_patterns as $pattern => $name) {
		if(preg_match('/' . $pattern . '/i', $content, $m, PREG_OFFSET_CAPTURE)) {
			$line = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
			xn_log("Template $srcfile contains dangerous PHP function [$name] at line $line, refuse to compile", 'template_security_error');
			$msg = 'Template security error: dangerous PHP function [' . $name . '] detected';
			if(DEBUG > 0) {
				$msg .= ' in ' . basename($srcfile) . ' line ' . $line;
			}
			die($msg);
		}
	}

	// 检测 preg_replace 的 /e 修饰符（PHP 7+ 已废弃，常用于代码执行）
	if(preg_match('/(?<!\.)\bpreg_replace\s*\(\s*[\'"].*?\/[a-z]*e[a-z]*\s*[\'"]/is', $content, $m, PREG_OFFSET_CAPTURE)) {
		$line = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
		xn_log("Template $srcfile contains preg_replace /e modifier at line $line, refuse to compile", 'template_security_error');
		$msg = 'Template security error: preg_replace /e modifier detected';
		if(DEBUG > 0) {
			$msg .= ' in ' . basename($srcfile) . ' line ' . $line;
		}
		die($msg);
	}
}

function _include_callback_1($m) {
	global $g_include_slot_kv;
	$r = file_get_contents($m[1]);
	preg_match_all('#<slot\sname="(.*?)">(.*?)</slot>#is', $m[2], $m2);
	if(!empty($m2[1])) {
		$kv = array_combine($m2[1], $m2[2]);
		$g_include_slot_kv += $kv;
		foreach($g_include_slot_kv as $slot=>$content) {
			$r = preg_replace('#<slot\sname="'.$slot.'"\s*/>#is', $content, $r);
		}
	}
	return $r;
}

// 在安装、卸载插件的时候，需要先初始化
function plugin_init() {
	global $plugin_srcfiles, $plugin_paths, $plugins, $db;

	// 存量数据库升级：bbs_plugin 表加 version 字段（幂等，KV 缓存避免每次请求 SHOW COLUMNS）
	$version_field_checked = cache_get('plugin_version_field_checked');
	if (empty($version_field_checked)) {
		$col_exists = db_sql_find_one("SHOW COLUMNS FROM {$db->tablepre}plugin LIKE 'version'");
		if (empty($col_exists)) {
			db_exec("ALTER TABLE {$db->tablepre}plugin ADD COLUMN version varchar(32) NOT NULL DEFAULT '' COMMENT '已安装版本号' AFTER enable");
		}
		cache_set('plugin_version_field_checked', 1, 86400);
	}

	$plugin_paths = glob(APP_PATH.'plugin/*', GLOB_ONLYDIR);
	if(is_array($plugin_paths)) {
		foreach($plugin_paths as $path) {
			$dir = file_name($path);
			$conffile = $path."/conf.json";
			if(!is_file($conffile)) continue;
			$arr = xn_json_decode(file_get_contents($conffile));
			if(empty($arr)) continue;
			$plugins[$dir] = $arr;

			// 额外的信息
			$plugins[$dir]['hooks'] = array();
			$hookpaths = glob(APP_PATH."plugin/$dir/hook/*.*"); // path
			if(is_array($hookpaths)) {
				foreach($hookpaths as $hookpath) {
					$hookname = file_name($hookpath);
					$plugins[$dir]['hooks'][$hookname] = $hookpath;
				}
			}

			// 本地 + 线上数据
			$plugins[$dir] = plugin_read_by_dir($dir);
		}
	}

	// db 为权威：用 bbs_plugin 表覆盖 enable/installed，无记录的老插件平移 conf.json 状态
	// ponytail: 兜底——db 异常时跳过，保留 conf.json 值，避免后台白屏
	if (!empty($plugins)) {
		$db_list = array();
		try {
			$db_list = plugin_db_get_all();
		} catch (\Throwable $e) {
			$db_list = array();
		}
		foreach ($plugins as $dir => $unused) {
			if (isset($db_list[$dir])) {
				// db 权威覆盖
				$plugins[$dir]['installed'] = isset($db_list[$dir]['installed']) ? (int)$db_list[$dir]['installed'] : 0;
				$plugins[$dir]['enable']    = isset($db_list[$dir]['enable'])    ? (int)$db_list[$dir]['enable']    : 0;
				if (isset($db_list[$dir]['version']) && $db_list[$dir]['version'] !== '') {
					$plugins[$dir]['db_version'] = $db_list[$dir]['version'];
				}
			} else {
			// db 无记录：首次发现该插件，默认未安装未启用
			// ponytail: conf.json 是静态配置，installed/enable 是运行时状态，只以 db 为准
			// 旧代码用 conf.json 的 enable=1 平移到 db，导致未安装的插件自动"已启用"（已违反 1 次）
			$plugins[$dir]['installed'] = 0;
			$plugins[$dir]['enable'] = 0;
			plugin_db_init($dir, $plugins[$dir]);
			if (!empty($plugins[$dir]['version'])) plugin_db_set_version($dir, $plugins[$dir]['version']);
		}
		}
	}
}

// 插件依赖检测，返回依赖的插件列表，如果返回为空则表示不依赖
/*
	返回依赖的插件数组：
	array(
		'xn_ad'=>'1.0',
		'xn_umeditor'=>'1.0',
	);
*/
function plugin_dependencies($dir) {
	global $plugin_srcfiles, $plugin_paths, $plugins;
	$plugin = $plugins[$dir];
	$dependencies = $plugin['dependencies'];
	
	// 检查插件依赖关系
	$arr = array();
	foreach($dependencies as $_dir=>$version) {
		// 依赖插件未安装或未启用
		if(!isset($plugins[$_dir]) || !$plugins[$_dir]['enable']) {
			$arr[$_dir] = $version;
			continue;
		}
		// 依赖插件已安装，检查版本约束（支持 >=/^/~ 语义化版本）
		if($version && $version !== '*') {
			$dep_version = isset($plugins[$_dir]['version']) ? $plugins[$_dir]['version'] : '0.0.0';
			if(!plugin_version_satisfies($dep_version, $version)) {
				$arr[$_dir] = $version;
			}
		}
	}
	return $arr;
}

/**
 * 版本约束检查（npm 风格语义化版本）
 * 支持：>=1.0.2, ^1.0.2, ~1.0.2, >4.4, <5.0, =1.0.2, 1.0.2（精确）, *（任意）
 * @param string $version 实际版本号
 * @param string $constraint 约束表达式
 * @return bool 是否满足约束
 */
function plugin_version_satisfies($version, $constraint) {
	$constraint = trim($constraint);
	if($constraint === '' || $constraint === '*') return true;
	
	// 解析约束：操作符 + 版本号
	if(preg_match('/^(>=|<=|>|<|=|\^|~)?(\d+(?:\.\d+){0,2})$/', $constraint, $m)) {
		$op = $m[1] !== '' ? $m[1] : '=';
		$required = $m[2];
		
		switch($op) {
			case '>=': return version_compare($version, $required, '>=');
			case '<=': return version_compare($version, $required, '<=');
			case '>':  return version_compare($version, $required, '>');
			case '<':  return version_compare($version, $required, '<');
			case '=':  return version_compare($version, $required, '=');
			case '^':  // ^1.0.2 = >=1.0.2 && <5.0.0（兼容主版本）
				$parts = explode('.', $required);
				$major = $parts[0];
				return version_compare($version, $required, '>=') 
					&& version_compare($version, ($major+1).'.0.0', '<');
			case '~':  // ~1.0.2 = >=1.0.2 && <4.6.0（兼容次版本）
				$parts = explode('.', $required);
				$major = $parts[0];
				$minor = isset($parts[1]) ? $parts[1] : 0;
				return version_compare($version, $required, '>=') 
					&& version_compare($version, $major.'.'.($minor+1).'.0', '<');
		}
	}
	
	return true; // 无法解析的约束默认通过
}

/*
	返回被依赖的插件数组：
	array(
		'xn_ad'=>'1.0',
		'xn_umeditor'=>'1.0',
	);
*/
function plugin_by_dependencies($dir) {
	global $plugins;
	
	$arr = array();
	foreach($plugins as $_dir=>$plugin) {
		if(isset($plugin['dependencies'][$dir]) && $plugin['enable']) {
			$arr[$_dir] = $plugin['version'];
		}
	}
	return $arr;
}

function plugin_enable($dir) {
	global $plugins;

	if(!isset($plugins[$dir])) {
		return FALSE;
	}

	$plugins[$dir]['enable'] = 1;

	//plugin_overwrite($dir, 'install');
	//plugin_hook($dir, 'install');

	// 写入数据库（db 为权威，conf.json 不再被运行时改写）
	plugin_db_init($dir, $plugins[$dir]);
	plugin_db_set_enable($dir, 1);

	plugin_clear_tmp_dir();

	return TRUE;
}

// 清空插件生命周期相关缓存：tmp/ 编译缓存 + 整站数据缓存 + OPcache
// 调用位置：install/enable/disable/uninstall 后，确保新启停的插件状态在所有缓存层即时生效
function plugin_clear_tmp_dir() {
	global $conf;
	rmdir_recusive($conf['tmp_path'], TRUE);
	xn_unlink($conf['tmp_path'].'model.min.php');
	// 整站数据缓存 + OPcache 清理
	// - 数据缓存：Redis/Memcached 驱动下尤其必要（file 驱动下 tmp/cache 已被上面 rmdir_recusive 删除）
	// - OPcache：validate_timestamps=1 + revalidate_freq 较大或多 worker 时，旧字节码不会自动重载
	//   → 启用插件后 hook/Service 类不生效 → 调用未定义符号 → 500。必须显式 opcache_reset()
	// ponytail: 全站清理粒度较粗，但插件启停属于低频管理操作，性能可接受；类未加载时静默跳过避免独立入口致命错误
	if(class_exists('CacheService', false)) {
		try {
			CacheService::clearByType(array('data', 'opcache'));
		} catch(\Throwable $e) {
			error_log('plugin lifecycle clearByType(data,opcache) failed: '.$e->getMessage());
		}
	} else {
		// 兜底：CacheService 未加载时直接调用底层函数（独立入口/CLI 场景）
		if(function_exists('opcache_reset')) { @opcache_reset(); }
		if(function_exists('cache_truncate')) { @cache_truncate(); }
	}
}

function plugin_disable($dir) {
	global $plugins;

	if(!isset($plugins[$dir])) {
		return FALSE;
	}

	$plugins[$dir]['enable'] = 0;

	//plugin_overwrite($dir, 'unstall');
	//plugin_hook($dir, 'unstall');

	// 写入数据库（db 为权威，conf.json 不再被运行时改写）
	plugin_db_init($dir, $plugins[$dir]);
	plugin_db_set_enable($dir, 0);

	plugin_clear_tmp_dir();

	return TRUE;
}

// 安装所有的本地插件
function plugin_install_all() {
	global $plugins;
	
	// 检查文件更新
	foreach ($plugins as $dir=>$plugin) {
		plugin_install($dir);
	}
}

// 卸载所有的本地插件
function plugin_unstall_all() {
	global $plugins;
	
	// 检查文件更新
	foreach ($plugins as $dir=>$plugin) {
		plugin_unstall($dir);
	}
}
/*
	插件安装：
		把所有的插件点合并，重新写入文件。如果没有备份文件，则备份一份。
		插件名可以为源文件名：view/header.htm
*/
function plugin_install($dir) {
	global $plugins, $conf;

	if(!isset($plugins[$dir])) {
		return FALSE;
	}

	$plugins[$dir]['installed'] = 1;
	$plugins[$dir]['enable'] = 1;

	// 1. 直接覆盖的方式
	//plugin_overwrite($dir, 'install');

	// 2. 钩子的方式
	//plugin_hook($dir, 'install');

	// 写入数据库（db 为权威，conf.json 不再被运行时改写）
	plugin_db_init($dir, $plugins[$dir]);
	plugin_db_set_installed($dir, 1);
	plugin_db_set_enable($dir, 1);
	// 同步 conf.json.version 到 db.version，用于后续「需升级」检测
	plugin_db_set_version($dir, isset($plugins[$dir]['version']) ? $plugins[$dir]['version'] : '');

	plugin_clear_tmp_dir();

	return TRUE;
}

// copy from plugin_install 修改
function plugin_unstall($dir) {
	global $plugins;

	if(!isset($plugins[$dir])) {
		return TRUE;
	}

	$plugins[$dir]['installed'] = 0;
	$plugins[$dir]['enable'] = 0;

	// 1. 直接覆盖的方式
	//plugin_overwrite($dir, 'unstall');

	// 2. 钩子的方式
	//plugin_hook($dir, 'unstall');

	// 写入数据库（db 为权威，conf.json 不再被运行时改写）
	plugin_db_init($dir, $plugins[$dir]);
	plugin_db_set_installed($dir, 0);
	plugin_db_set_enable($dir, 0);

	plugin_clear_tmp_dir();

	return TRUE;
}

function plugin_paths_enabled() {
	static $return_paths;
	if(empty($return_paths)) {
		$return_paths = array();
		$plugin_paths = glob(APP_PATH.'plugin/*', GLOB_ONLYDIR);
		if(empty($plugin_paths)) return array();

		// db 为权威：批量取 enable/installed
		// ponytail: 兜底——db 异常或表不存在(install 阶段)回退读 conf.json
		$db_list = array();
		$db_available = TRUE;
		try {
			$db_list = plugin_db_get_all();
		} catch (\Throwable $e) {
			$db_list = array();
			$db_available = FALSE;
		}

		foreach($plugin_paths as $path) {
			$conffile = $path."/conf.json";
			if(!is_file($conffile)) continue;
			$pconf = xn_json_decode(file_get_contents($conffile));
			if(empty($pconf)) continue;

			$dir = file_name($path);
			if (isset($db_list[$dir])) {
				// db 权威
				$enable    = !empty($db_list[$dir]['enable']);
				$installed = !empty($db_list[$dir]['installed']);
			} elseif (!$db_available) {
				// db 不可用（install 阶段/异常）：回退 conf.json
				$enable    = !empty($pconf['enable']);
				$installed = !empty($pconf['installed']);
			} else {
				// db 可用但无记录：默认未安装未启用（忽略 conf.json 的 installed/enable）
				$enable    = FALSE;
				$installed = FALSE;
			}
			if(!$enable || !$installed) continue;
			$pconf['enable'] = 1;
			$pconf['installed'] = 1;
			$return_paths[$path] = $pconf;
		}
	}
	return $return_paths;
}

// 编译源文件，把插件合并到该文件，不需要递归，执行的过程中 include _include() 自动会递归。
function plugin_compile_srcfile($srcfile) {
	global $conf;
	// 判断是否开启插件
	if(!empty($conf['disabled_plugin'])) {
		$s = file_get_contents($srcfile);
		return $s;
	}
	
	// 如果有 overwrite，则用 overwrite 替换掉
	$srcfile = plugin_find_overwrite($srcfile);
	$s = file_get_contents($srcfile);
	
	// 最多支持 10 层
	for($i = 0; $i < 10; $i++) {
		if(strpos($s, '<!--{hook') !== FALSE || strpos($s, '// hook') !== FALSE) {
			$s = preg_replace('#<!--{hook\s+(.*?)}-->#', '// hook \\1', $s);
			// hook 名只允许字母/数字/下划线/点/短横线，避免注释里 `// hook 位于...` 被误识别
			// ponytail: 旧正则 \S+ 贪婪匹配中文，导致注释行被当 hook 名截断引发 ParseError；合法 hook 文件名均符合 [\w.\-]+
			$s = preg_replace_callback('#//\s*hook\s+([\w\.\-]+)#is', 'plugin_compile_srcfile_callback', $s);
		} else {
			break;
		}
	}
	return $s;
}


// 只返回一个权重最高的文件名
function plugin_find_overwrite($srcfile) {
	//$plugin_paths = glob(APP_PATH.'plugin/*', GLOB_ONLYDIR);

	$plugin_paths = plugin_paths_enabled();

	$len = strlen(APP_PATH);

	$returnfile = $srcfile;
	$maxrank = 0;

	// 先遍历插件，检查是否真的存在 overwrite 文件
	$filepath_half = substr($srcfile, $len);
	foreach($plugin_paths as $path=>$pconf) {
		$dir = file_name($path);
		$overwrite_file = APP_PATH."plugin/$dir/overwrite/$filepath_half";
		if(is_file($overwrite_file)) {
			// 有插件尝试覆盖，再检查白名单
			$protected_paths = array(
				'conf/', 'xiunophp/', 'lib/', 'admin/', 'api/', 'cli/', 'tool/',
				'install/', 'log/', 'tmp/', 'upload/',
				'index.php', 'model.inc.php', 'index.inc.php',
			);
			$is_protected = false;
			foreach($protected_paths as $protected) {
				if(strpos($filepath_half, $protected) === 0 || $filepath_half === $protected) {
					$is_protected = true;
					break;
				}
			}
			if($is_protected) {
				// 核心路径禁止覆盖，记日志并跳过该插件
				xn_log("Plugin overwrite blocked (protected path): $filepath_half by plugin/$dir", 'plugin_overwrite_error');
				continue;
			}
			$rank = isset($pconf['overwrites_rank'][$filepath_half]) ? $pconf['overwrites_rank'][$filepath_half] : 0;
			if($rank >= $maxrank) {
				$returnfile = $overwrite_file;
				$maxrank = $rank;
			}
		}
	}
	return $returnfile;
}

function plugin_compile_srcfile_callback($m) {
	static $hooks;
	if(empty($hooks)) {
		$hooks = array();
		$plugin_paths = plugin_paths_enabled();
		
		//$plugin_paths = glob(APP_PATH.'plugin/*', GLOB_ONLYDIR);
		foreach($plugin_paths as $path=>$pconf) {
			$dir = file_name($path);
			$hookpaths = glob(APP_PATH."plugin/$dir/hook/*.*"); // path
			if(is_array($hookpaths)) {
				foreach($hookpaths as $hookpath) {
					$hookname = file_name($hookpath);
					$rank = isset($pconf['hooks_rank']["$hookname"]) ? $pconf['hooks_rank']["$hookname"] : 0;
					$hooks[$hookname][] = array('hookpath'=>$hookpath, 'rank'=>$rank, 'plugin_dir'=>$dir);
				}
			}
		}
		foreach ($hooks as $hookname=>$arrlist) {
			// 主键 rank 降序（保持原 arrlist_multisort FALSE 语义），二级键 plugin_dir 字母升序保证同 rank 顺序确定
			usort($arrlist, function($a, $b) {
				if($a['rank'] !== $b['rank']) {
					return $b['rank'] - $a['rank'];
				}
				return strcmp($a['plugin_dir'], $b['plugin_dir']);
			});
			$hooks[$hookname] = arrlist_values($arrlist, 'hookpath');
		}
		
	}
	
	$s = '';
	$hookname = $m[1];
	$is_lang_hook = (strpos($hookname, 'lang_') === 0);
	if(!empty($hooks[$hookname])) {
		$fileext = file_ext($hookname);
		foreach($hooks[$hookname] as $path) {
			$t = file_get_contents($path);
			if($fileext == 'php' && preg_match('#^\s*<\?php\s+exit;#is', $t)) {
				// 去掉 php 开始标签加 exit 前缀，与 elseif 分支保持一致的剥离方式
				// 旧正则末尾的 \s*$ 会吞掉末尾换行符，
				// 导致多 hook 拼接时前一个 hook 的行注释与后一个 hook 的块注释连在同一行，
				// 块注释被行注释吞掉，块注释内的中文变成裸标识符引发 ParseError
				$t = preg_replace('#^\s*<\?php\s*exit;#is', '', $t);
				$t = preg_replace('#\?>\s*$#', '', $t);
			} elseif($fileext == 'php') {
				// 兼容裸 php 开始标签开头（不带 exit）的 hook 文件
				$t = preg_replace('#^\s*<\?php\s*#', '', $t);
				$t = preg_replace('#\?>\s*$#', '', $t);
			}
		// 语言 hook 安全检查：验证语法有效性，防止错误代码导致整个语言系统崩溃
			if($is_lang_hook && $fileext == 'php') {
				// 检查是否只包含 $lang['key']='value' 赋值语句
				// 允许的格式：$lang['xxx'] = 'yyy'; 或 $lang["xxx"] = 'yyy';
				$lines = array_filter(array_map('trim', explode("\n", $t)));
				$all_valid = TRUE;
				foreach($lines as $line) {
					if($line === '' || $line === '<?php' || preg_match('#^//.*$#', $line)) continue;
					if(!preg_match('#^\$lang\[\'[^\']+\'\]\s*=\s*.*;$#', $line) &&
					   !preg_match('#^\$lang\["[^"]+"\]\s*=\s*.*;$#', $line)) {
						$all_valid = FALSE;
						break;
					}
				}
				if(!$all_valid) {
					// 记录日志，跳过有问题的语言 hook
					xn_log("Plugin lang hook syntax error, skipped: $path", 'lang_error');
					continue;
				}
			}
			// PHP hook 语法预检：已废弃，不再做 token_get_all 检查
			// ponytail: 项目里存在多种"上下文依赖型"hook 片段，单独检查必然误报：
			//   - model_inc_file.php：数组元素片段（APP_PATH.'xxx',）
			//   - index_route_case_end.php：switch case 片段（case 'xxx': ... break;）
			//   - 可能还有其他片段型 hook 未发现
			// 逐一枚举豁免太脆弱，改为不做语法预检，语法错误的 hook 由 B 部分（autoDisableCrashedPlugin 崩溃计数）兜底
			// 保留 plugin-compile 注释注入，用于 fatal error 归因
			// 拼接前加 plugin-compile 注释，用于 fatal error 时从 tmp 文件行号反推插件目录（见 ErrorHandler::handleShutdown）
			// 仅 PHP hook 加注释；.htm hook 不加避免污染 HTML 输出
			if($fileext == 'php') {
				// 从 path 反推插件 dir：plugin/{dir}/hook/{hookname}
				$plugin_dir = basename(dirname(dirname($path)));
				$s .= "\n// plugin-compile: $plugin_dir  $path\n";
			}
			$s .= $t;
		}
	}
	return $s;
}

// -------------------> 本地插件列表缓存到本地。
// 安装，卸载，禁用，更新
function plugin_read_by_dir($dir) {
	global $plugins;

	$local = array_value($plugins, $dir, array());
	if(empty($local)) return array();

	!isset($local['name']) && $local['name'] = '';
	!isset($local['price']) && $local['price'] = 0;
	!isset($local['brief']) && $local['brief'] = '';
	!isset($local['version']) && $local['version'] = '1.0.0';
	!isset($local['bbs_version']) && $local['bbs_version'] = '1.0';
	!isset($local['installed']) && $local['installed'] = 0;
	!isset($local['enable']) && $local['enable'] = 0;
	!isset($local['hooks']) && $local['hooks'] = array();
	!isset($local['hooks_rank']) && $local['hooks_rank'] = array();
	!isset($local['dependencies']) && $local['dependencies'] = array();
	!isset($local['icon_url']) && $local['icon_url'] = '';
	!isset($local['have_setting']) && $local['have_setting'] = 0;
	!isset($local['setting_url']) && $local['setting_url'] = 0;
	// capabilities 字段：插件声明所需权限（如 user.write、thread.create），用于未来权限沙箱
	!isset($local['capabilities']) && $local['capabilities'] = array();

	$plugin = $local;
	$plugin['icon_url'] = "../plugin/$dir/icon.png";
	$plugin['setting_url'] = $plugin['installed'] && is_file("../plugin/$dir/setting.php") ? "plugin-setting-$dir.htm" : "";
	$plugin['downloaded'] = isset($plugins[$dir]);
	return $plugin;
}

// 判断是否为主题/模板插件
function plugin_is_theme($dir, $conf = []) {
    // 1. 优先检查 conf.json 中的 type 字段
    if (isset($conf['type']) && in_array(strtolower($conf['type']), ['theme', 'template', 'skin'])) {
        return true;
    }
    
    // 2. 检查目录名关键词
    $theme_keywords = ['theme', 'template', 'skin', '风格', '模板'];
    foreach ($theme_keywords as $keyword) {
        if (stripos($dir, $keyword) !== false) {
            return true;
        }
    }
    
    // 3. 检查插件名称关键词
    if (isset($conf['name'])) {
        foreach ($theme_keywords as $keyword) {
            if (stripos($conf['name'], $keyword) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

// 获取插件数据库记录
function plugin_db_get($dir) {
    global $db, $tablepre;
    $arr = $db->find_one($tablepre.'plugin', array('dir'=>$dir));
    return $arr ? $arr : array();
}

// 获取所有插件数据库记录（以 dir 为 key 的关联数组）
// ponytail: db 类无 find_all 方法，用 find + key='dir' 直接返回以 dir 为 key 的数组
function plugin_db_get_all() {
    global $db, $tablepre;
    return $db->find($tablepre.'plugin', [], [], 1, 1000000, 'dir');
}

// 初始化插件数据库记录（如果不存在则创建）
function plugin_db_init($dir, $conf = array()) {
    global $db, $tablepre, $time;

    $arr = plugin_db_get($dir);
    if (empty($arr)) {
        $arr = array(
            'dir' => $dir,
            'name' => isset($conf['name']) ? $conf['name'] : '',
            'type' => plugin_is_theme($dir, $conf) ? 1 : 0,
            'installed' => isset($conf['installed']) ? $conf['installed'] : 0,
            'enable' => isset($conf['enable']) ? $conf['enable'] : 0,
            'version' => isset($conf['version']) ? $conf['version'] : '',
            'install_time' => 0,
            'enable_time' => 0,
            'disable_time' => 0,
            'create_time' => $time,
            'update_time' => $time,
        );
        $db->insert($tablepre.'plugin', $arr);
    }
    return $arr;
}

// 更新插件安装状态
function plugin_db_set_installed($dir, $installed) {
    global $db, $tablepre, $time;
    $update = array(
        'installed' => $installed,
        'update_time' => $time,
    );
    if ($installed) {
        $update['install_time'] = $time;
    }
    $db->update($tablepre.'plugin', array('dir'=>$dir), $update);
}

// 更新插件已安装版本号
function plugin_db_set_version($dir, $version) {
    global $db, $tablepre, $time;
    $db->update($tablepre.'plugin', array('dir'=>$dir), array(
        'version' => $version,
        'update_time' => $time,
    ));
}

// 更新插件启用状态
function plugin_db_set_enable($dir, $enable) {
    global $db, $tablepre, $time;
    $update = array(
        'enable' => $enable,
        'update_time' => $time,
    );
    if ($enable) {
        $update['enable_time'] = $time;
    } else {
        $update['disable_time'] = $time;
    }
    $db->update($tablepre.'plugin', array('dir'=>$dir), $update);
}

// 初始化所有插件数据（升级用）
function plugin_db_init_all() {
    global $plugins;
    
    if (empty($plugins)) {
        plugin_init();
    }
    
    foreach ((array)$plugins as $dir => $conf) {
        plugin_db_init($dir, $conf);
    }
}

// 获取插件信息（合并数据库和conf.json）
function plugin_read_by_dir_with_db($dir) {
    $plugin = plugin_read_by_dir($dir);
    $db_data = plugin_db_get($dir);

    if (!empty($db_data)) {
        $plugin['install_time'] = isset($db_data['install_time']) ? $db_data['install_time'] : 0;
        $plugin['enable_time'] = isset($db_data['enable_time']) ? $db_data['enable_time'] : 0;
        $plugin['disable_time'] = isset($db_data['disable_time']) ? $db_data['disable_time'] : 0;
        $plugin['type'] = isset($db_data['type']) ? $db_data['type'] : 0;
        $plugin['db_version'] = isset($db_data['version']) ? $db_data['version'] : '';
    } else {
        $plugin['install_time'] = 0;
        $plugin['enable_time'] = 0;
        $plugin['disable_time'] = 0;
        $plugin['type'] = plugin_is_theme($dir, $plugin) ? 1 : 0;
        $plugin['db_version'] = '';
    }

    return $plugin;
}

/**
 * 运行时 hook 分发（带错误隔离）
 *
 * Xiuno 默认通过编译时内联（plugin_compile_srcfile_callback）合并 hook 文件到源文件，
 * 本函数提供运行时分发替代方案，适用于需要错误隔离的动态 hook 场景。
 *
 * 单个 hook 抛出 Throwable 时不会终止其他 hook 和主流程，错误记录到 plugin_error 日志。
 * 仅支持 .php 类型 hook（.htm 模板 hook 走编译时内联）。
 *
 * @param string $hookname hook 名称（含扩展名，如 thread_create_after.php）
 * @param mixed $data 传递给 hook 的引用数据（可选）
 */
function plugin_hook($hookname, &$data = NULL) {
	global $conf;
	if(empty($hookname)) return;

	// 收集所有已启用插件中匹配 hookname 的 hook 文件，按 hooks_rank 降序
	// 使用 plugin_paths_enabled() 直接读 conf.json，兼容前端运行时（plugin_init 仅在 admin/upgrade 调用）
	$plugin_paths = plugin_paths_enabled();
	if(empty($plugin_paths)) return;

	$hookfiles = array();
	foreach($plugin_paths as $path => $pconf) {
		$dir = file_name($path);
		$hookpath = APP_PATH . "plugin/$dir/hook/$hookname";
		if(!is_file($hookpath)) continue;
		$rank = isset($pconf['hooks_rank'][$hookname]) ? $pconf['hooks_rank'][$hookname] : 0;
		$hookfiles[] = array('path' => $hookpath, 'rank' => $rank, 'dir' => $dir);
	}
	if(empty($hookfiles)) return;

	// 按 rank 降序（与编译时 plugin_compile_srcfile_callback 排序一致）
	usort($hookfiles, function($a, $b) {
		return $b['rank'] - $a['rank'];
	});

	foreach($hookfiles as $hf) {
		// 错误隔离：单 hook 出错不影响其他 hook 和主流程
		try {
			$t = file_get_contents($hf['path']);
			if($t === FALSE) continue;
			// 去掉防直接访问前缀，与编译时 plugin_compile_srcfile_callback 处理一致
			// hook 文件以 <?php exit; 开头，include 会终止执行，故剥离标签后 eval
			if(preg_match('#^\s*<\?php\s+exit;#is', $t)) {
				// 与编译时 plugin_compile_srcfile_callback 保持一致：分别剥离首尾标签，保留末尾换行
				$t = preg_replace('#^\s*<\?php\s*exit;#is', '', $t);
				$t = preg_replace('#\?>\s*$#', '', $t);
			} elseif(preg_match('#^\s*<\?php#is', $t)) {
				// 兼容裸 <?php 开头（不带 exit;）的 hook 文件
				$t = preg_replace('#^\s*<\?php\s*#', '', $t);
				$t = preg_replace('#\?>\s*$#', '', $t);
			}
			// 在调用方作用域执行 hook 代码，可访问 $data 及全局变量
		// ponytail: eval 是 xiuno 插件 hook 机制的核心设计，无法替代
		// hook 文件以 <?php exit; 开头防直接访问，剥离标签后必须在调用方作用域执行
		// include 会因 exit; 终止，Closure 无法注入调用方作用域的 $data，故只能用 eval
		// 已知风险：恶意 hook 文件可执行任意代码（hook 文件由开发者提供，等同源代码信任级别）
		// ponytail: $data 为关联数组时 extract 到当前作用域，让 hook 能以变量名访问调用方数据
		// 解决 plugin_hook 在自身函数作用域 eval、无法访问调用方局部变量的问题
		if (is_array($data)) {
			extract($data, EXTR_SKIP);
		}
		eval($t);
		} catch(\Throwable $e) {
			// PHP 7+ Throwable 兼容 Error 和 Exception
			$msg = "Plugin hook error: $hookname in plugin " . $hf['dir'] . ": " . $e->getMessage();
			xn_log($msg, 'plugin_error');
			// trace 记录到 debug 日志（文件名含 error 才会在生产环境写入）
			xn_log($e->getTraceAsString(), 'plugin_error_debug');
			// 继续执行后续 hook，不终止
		}
	}
}

/**
 * 兼容旧版 xn_hook() 调用
 * @deprecated 已被 plugin_hook() 替代，仅为向后兼容保留
 */
function xn_hook($hookname, &$data = NULL) {
	// 旧版 xn_hook 不带 .php 后缀，新版 plugin_hook 需要含扩展名（如 thread_create_after.php）
	// 幂等：调用方无论是否带 .php 后缀都能正确分发
	if(substr($hookname, -4) !== '.php') {
		$hookname .= '.php';
	}
	return plugin_hook($hookname, $data);
}

?>