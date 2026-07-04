<?php

class LoginSecurityService {

	public static function checkBan($uid) {
		global $conf;
		if(!db_check_column_exists('user', 'banned_until')) return;
		$_user = user_read($uid);
		if(empty($_user)) return;

		// 仅对未被 UserBanService 封禁（ban_type=0）的用户检查登录失败锁定
		// ban_type>0（禁言/禁止访问/锁定）的登录拦截由 UserBanService::checkBanByScene('login') 处理
		// ponytail: banned_until 字段被两个系统复用——LoginSecurityService 写入登录失败锁定，
		// UserBanService::ban 写入封禁到期。两者无法区分，必须靠 ban_type 字段判断来源
		// 已知天花板：ban_type 字段不存在的旧表结构（升级前），退回旧行为检查 banned_until
		$ban_type = isset($_user['ban_type']) ? intval($_user['ban_type']) : 0;
		if($ban_type > 0) return;

		if(isset($_user['banned_until']) && $_user['banned_until'] > time()) {
			$remaining = $_user['banned_until'] - time();
			message(-1003, lang('login_banned', array('seconds'=>$remaining)), array('wait'=>$remaining));
		}
		if(isset($_user['banned_until']) && $_user['banned_until'] > 0 && $_user['banned_until'] <= time()) {
			db_update('user', array('uid'=>$uid), array('login_attempts'=>0, 'banned_until'=>0));
		}
	}

	public static function recordAttempt($uid, $success, $ip, $ua) {
		global $conf, $time;
		$maxAttempts = isset($conf['login_max_attempts']) ? intval($conf['login_max_attempts']) : 5;
		$banDuration = isset($conf['login_ban_duration']) ? intval($conf['login_ban_duration']) : 900;

		if(db_check_table_exists('user_login_log')) {
			db_create('user_login_log', array(
				'uid' => $uid,
				'ip' => $ip,
				'time' => $time,
				'success' => $success ? 1 : 0,
				'user_agent' => substr($ua, 0, 255),
			));
		}

		if(!db_check_column_exists('user', 'login_attempts')) return;

		if($success) {
			db_update('user', array('uid'=>$uid), array(
				'login_attempts' => 0,
				'banned_until' => 0,
				'last_login_ip' => $ip,
				'last_login_time' => $time,
			));
		} else {
			$_user = user_read($uid);
			$attempts = isset($_user['login_attempts']) ? intval($_user['login_attempts']) + 1 : 1;
			$update = array(
				'login_attempts' => $attempts,
				'last_login_ip' => $ip,
				'last_login_time' => $time,
			);
			if($attempts >= $maxAttempts) {
				$update['banned_until'] = $time + $banDuration;
			}
			db_update('user', array('uid'=>$uid), $update);
		}
	}

	public static function resetAttempts($uid) {
		if(!db_check_column_exists('user', 'login_attempts')) return;
		db_update('user', array('uid'=>$uid), array('login_attempts'=>0, 'banned_until'=>0));
	}

	/**
	 * 检查 IP 是否被锁定（登录失败 IP 维度限流）
	 *
	 * 基于 user_login_log 表统计该 IP 在锁定时间窗口内的失败次数，
	 * 达到阈值则拒绝登录。阈值与 uid 维度共用后台 security-account 配置
	 * （login_max_attempts / login_ban_duration，由 SecurityConfigService 同步写入）。
	 *
	 * 防止攻击者用不存在的用户名无限枚举绕过 uid 维度限流。
	 *
	 * @param int $longip ip2long 转换后的整型 IP（用全局 $longip）
	 */
	public static function checkIpBan($longip) {
		if(!db_check_table_exists('user_login_log')) return;
		if(empty($longip)) return;

		global $conf;
		$maxAttempts = isset($conf['login_max_attempts']) ? intval($conf['login_max_attempts']) : 5;
		$banDuration = isset($conf['login_ban_duration']) ? intval($conf['login_ban_duration']) : 900;

		$now = time();
		$cutoff = $now - $banDuration;

		// 取该 IP 在锁定窗口内的失败记录（按时间升序，最多取 maxAttempts 条）
		$logs = db_find('user_login_log', array(
			'ip' => intval($longip),
			'success' => 0,
			'time' => array('>' => $cutoff)
		), array('time'=>1), 1, $maxAttempts);

		if(count($logs) >= $maxAttempts) {
			// 第 maxAttempts 次失败的时间作为锁定起点，之后即使再失败也不刷新锁定时长
			$lockStart = end($logs)['time'];
			$unlockAt = $lockStart + $banDuration;
			if($unlockAt > $now) {
				$remaining = $unlockAt - $now;
				message(-1003, lang('login_banned', array('seconds'=>$remaining)), array('wait'=>$remaining));
			}
		}
	}

	/**
	 * 记录 IP 维度失败尝试（用户名/邮箱不存在时调用，uid 填 0）
	 *
	 * 与 recordAttempt 区别：recordAttempt 针对真实用户，更新 user 表的
	 * login_attempts/banned_until；recordIpAttempt 仅写 user_login_log 用于
	 * IP 维度统计，不依赖真实 uid。
	 *
	 * @param int $longip ip2long 转换后的整型 IP（用全局 $longip）
	 * @param bool $success 本次尝试是否成功
	 * @param string $ua User-Agent
	 */
	public static function recordIpAttempt($longip, $success, $ua) {
		if(!db_check_table_exists('user_login_log')) return;
		if(empty($longip)) return;

		global $time;
		db_create('user_login_log', array(
			'uid' => 0,
			'ip' => intval($longip),
			'time' => $time,
			'success' => $success ? 1 : 0,
			'user_agent' => substr($ua, 0, 255),
		));
	}
}
