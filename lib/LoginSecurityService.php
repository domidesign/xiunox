<?php

class LoginSecurityService {

	public static function checkBan($uid) {
		global $conf;
		if(!db_check_column_exists('user', 'banned_until')) return;
		$_user = user_read($uid);
		if(empty($_user)) return;
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
}
