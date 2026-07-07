<?php
/**
 * UserBanService - 用户封禁服务
 *
 * 提供 4 档封禁状态管理：正常(0)/禁言(1)/禁止访问(2)/锁定(3)
 *
 * 设计要点：
 * 1. 静态调用，与 CacheHelper/LoginSecurityService 风格一致
 * 2. 通过 XnEvent 事件机制支持插件扩展（beforeBan/afterBan/beforeUnban/afterUnban/beforeClearContent/afterClearContent）
 * 3. 调用 model 函数前 include_once（生产环境走 tmp/model.min.php 合并加载，类加载顺序不可预测）
 * 4. 不在代码中硬编码中文，全部通过 lang() 引用语言包（key 命名 user_ban_xxx，由 Task 17 同步 zh-cn/zh-tw/en-us）
 * 5. XnEvent 已在 xiunophp.php/min.php 中加载，无需 include
 *
 * 使用示例：
 * ```php
 * // 封禁用户 7 天（禁言）
 * $r = UserBanService::ban($uid, UserBanService::BAN_TYPE_SILENCE, 86400*7, '广告灌水', $adminUid);
 *
 * // 检查封禁状态（含到期自动解封）
 * $status = UserBanService::checkBan($uid);
 * if($status['banned']) { ... }
 *
 * // 按场景检查
 * $check = UserBanService::checkBanByScene($uid, 'post');
 * if(!$check['allowed']) { message(-1, $check['message']); }
 * ```
 */
class UserBanService {

	// ===== 封禁类型常量 =====
	const BAN_TYPE_NORMAL = 0;
	const BAN_TYPE_SILENCE = 1;      // 禁言：可浏览，不能发帖回帖
	const BAN_TYPE_BAN_ACCESS = 2;   // 禁止访问：不能登录、不能浏览
	const BAN_TYPE_LOCK = 3;          // 锁定：不能登录、不能改密找密

	// 永久封禁时间戳（约 2286 年，避免 32 位系统 PHP_INT_MAX 溢出）
	const PERMANENT_BAN = 9999999999;

	// 管理员组 gid（不可被封禁/清空内容）
	const ADMIN_GIDS = array(1, 2);

	/**
	 * 封禁用户
	 *
	 * @param int $uid 被封禁用户 uid
	 * @param int $banType 封禁类型（1/2/3）
	 * @param int $duration 封禁时长（秒），0 表示永久
	 * @param string $reason 封禁原因
	 * @param int $adminUid 操作管理员 uid
	 * @return array ['code'=>0 成功, 'message'=>错误信息]
	 */
	public static function ban($uid, $banType, $duration, $reason, $adminUid) {
		$uid = intval($uid);
		$banType = intval($banType);
		$duration = intval($duration);
		$adminUid = intval($adminUid);
		$reason = (string)$reason;

		// 1. 参数校验
		if($uid <= 0) {
			return array('code' => 1, 'message' => lang('user_ban_invalid_uid'));
		}
		if(!in_array($banType, array(self::BAN_TYPE_SILENCE, self::BAN_TYPE_BAN_ACCESS, self::BAN_TYPE_LOCK), true)) {
			return array('code' => 1, 'message' => lang('user_ban_invalid_type'));
		}
		if($duration < 0) {
			return array('code' => 1, 'message' => lang('user_ban_invalid_duration'));
		}
		if($adminUid <= 0) {
			return array('code' => 1, 'message' => lang('user_ban_invalid_admin'));
		}
		// 2. 不能封禁自己
		if($uid === $adminUid) {
			return array('code' => 1, 'message' => lang('user_ban_cannot_ban_self'));
		}
		// 3. 字段存在性校验
		if(!function_exists('db_check_column_exists') || !db_check_column_exists('user', 'ban_type')) {
			return array('code' => 1, 'message' => lang('user_ban_field_missing'));
		}

		// 确保 model 函数已加载（lib 类可能在 model 加载前被调用）
		if(!function_exists('user_read')) {
			include_once APP_PATH . 'model/user.func.php';
		}
		if(!function_exists('ban_log_create')) {
			include_once APP_PATH . 'model/ban_log.func.php';
		}

		// 4. 读取用户，校验存在性 + 不能封禁管理员组（gid=1,2）
		$_user = user_read($uid);
		if(empty($_user)) {
			return array('code' => 1, 'message' => lang('user_not_exists'));
		}
		if(in_array(intval($_user['gid']), self::ADMIN_GIDS, true)) {
			return array('code' => 1, 'message' => lang('user_ban_cannot_ban_admin'));
		}

		// 5. 触发 beforeBan 事件（参数引用，插件可修改 banType/duration/reason）
		$eventArgs = array(
			'uid' => $uid,
			'banType' => $banType,
			'duration' => $duration,
			'reason' => $reason,
			'adminUid' => $adminUid,
		);
		XnEvent::trigger('UserBanService.beforeBan', $eventArgs);
		// 回读，允许插件修改后继续主流程
		$banType = intval($eventArgs['banType']);
		$duration = intval($eventArgs['duration']);
		$reason = (string)$eventArgs['reason'];

		// 6. 计算 banned_until：duration=0 为永久，否则 time()+duration
		global $time;
		$now = intval($time);
		$bannedUntil = ($duration === 0) ? self::PERMANENT_BAN : ($now + $duration);

		// 7. 更新 user 表
		// 用 user_update() 而非 user__update()：
		//   - ban_type/banned_until/ban_reason/ban_admin_uid/ban_time 不在 USER_UPDATE_PROTECTED_FIELDS 中
		//   - user_update 会清缓存（cache_delete("user-$uid")）、合并静态缓存、触发 hook，比 user__update 更安全
		$update = array(
			'ban_type' => $banType,
			'banned_until' => $bannedUntil,
			'ban_reason' => $reason,
			'ban_admin_uid' => $adminUid,
			'ban_time' => $now,
		);
		$r = user_update($uid, $update);
		if($r === FALSE) {
			return array('code' => 2, 'message' => lang('update_failed'));
		}

		// 8. 写入 ban_log（action='ban'）
		ban_log_create(array(
			'uid' => $uid,
			'admin_uid' => $adminUid,
			'action' => 'ban',
			'ban_type' => $banType,
			'reason' => $reason,
			'duration' => $duration,
		));

		// 9. 发送站内通知
		$banInfo = array(
			'banType' => $banType,
			'reason' => $reason,
			'bannedUntil' => $bannedUntil,
			'duration' => $duration,
		);
		self::sendNotice($uid, 'ban', $banInfo);

		// 10. 触发 afterBan 事件
		$afterArgs = array(
			'uid' => $uid,
			'banType' => $banType,
			'duration' => $duration,
			'reason' => $reason,
			'adminUid' => $adminUid,
			'bannedUntil' => $bannedUntil,
		);
		XnEvent::trigger('UserBanService.afterBan', $afterArgs);

		return array('code' => 0);
	}

	/**
	 * 解封用户
	 *
	 * @param int $uid
	 * @param int $adminUid
	 * @param string $reason 解封原因（可选）
	 * @return array
	 */
	public static function unban($uid, $adminUid, $reason = '') {
		$uid = intval($uid);
		$adminUid = intval($adminUid);
		$reason = (string)$reason;

		if($uid <= 0) {
			return array('code' => 1, 'message' => lang('user_ban_invalid_uid'));
		}
		if($adminUid <= 0) {
			return array('code' => 1, 'message' => lang('user_ban_invalid_admin'));
		}
		if(!function_exists('db_check_column_exists') || !db_check_column_exists('user', 'ban_type')) {
			return array('code' => 1, 'message' => lang('user_ban_field_missing'));
		}

		if(!function_exists('user_read')) {
			include_once APP_PATH . 'model/user.func.php';
		}
		if(!function_exists('ban_log_create')) {
			include_once APP_PATH . 'model/ban_log.func.php';
		}

		$_user = user_read($uid);
		if(empty($_user)) {
			return array('code' => 1, 'message' => lang('user_not_exists'));
		}

		// 1. 触发 beforeUnban 事件（允许插件修改 reason）
		$eventArgs = array(
			'uid' => $uid,
			'adminUid' => $adminUid,
			'reason' => $reason,
		);
		XnEvent::trigger('UserBanService.beforeUnban', $eventArgs);
		$reason = (string)$eventArgs['reason'];

		// 2. 重置封禁字段（ban_type=0, banned_until=0, ban_reason='', ban_admin_uid=0, ban_time=0）
		$update = array(
			'ban_type' => self::BAN_TYPE_NORMAL,
			'banned_until' => 0,
			'ban_reason' => '',
			'ban_admin_uid' => 0,
			'ban_time' => 0,
		);
		$r = user_update($uid, $update);
		if($r === FALSE) {
			return array('code' => 2, 'message' => lang('update_failed'));
		}

		// 3. 写入 ban_log（action='unban'）
		ban_log_create(array(
			'uid' => $uid,
			'admin_uid' => $adminUid,
			'action' => 'unban',
			'ban_type' => self::BAN_TYPE_NORMAL,
			'reason' => $reason,
			'duration' => 0,
		));

		// 4. 发送站内通知
		$banInfo = array(
			'banType' => self::BAN_TYPE_NORMAL,
			'reason' => $reason,
			'bannedUntil' => 0,
			'duration' => 0,
		);
		self::sendNotice($uid, 'unban', $banInfo);

		// 5. 触发 afterUnban 事件
		$afterArgs = array(
			'uid' => $uid,
			'adminUid' => $adminUid,
			'reason' => $reason,
		);
		XnEvent::trigger('UserBanService.afterUnban', $afterArgs);

		return array('code' => 0);
	}

	/**
	 * 检查用户封禁状态（含到期自动解封）
	 *
	 * @param int $uid
	 * @return array ['banned'=>bool, 'ban_type'=>int, 'ban_reason'=>string, 'expire_time'=>int, 'expire_formatted'=>string]
	 */
	public static function checkBan($uid) {
		$uid = intval($uid);
		$empty = array(
			'banned' => false,
			'ban_type' => self::BAN_TYPE_NORMAL,
			'ban_reason' => '',
			'expire_time' => 0,
			'expire_formatted' => '',
		);

		if($uid <= 0) return $empty;
		if(!function_exists('db_check_column_exists') || !db_check_column_exists('user', 'ban_type')) {
			return $empty;
		}

		if(!function_exists('user_read')) {
			include_once APP_PATH . 'model/user.func.php';
		}

		$_user = user_read($uid);
		if(empty($_user)) return $empty;

		$banType = isset($_user['ban_type']) ? intval($_user['ban_type']) : 0;
		$bannedUntil = isset($_user['banned_until']) ? intval($_user['banned_until']) : 0;
		$banReason = isset($_user['ban_reason']) ? (string)$_user['ban_reason'] : '';

		// 2. 未封禁
		if($banType === self::BAN_TYPE_NORMAL) {
			return $empty;
		}

		// 3. 到期自动解封（banned_until > 0 且已过期）
		if($bannedUntil > 0 && $bannedUntil <= time()) {
			self::autoUnban($uid);
			return $empty;
		}

		// 4. 仍在封禁中
		return array(
			'banned' => true,
			'ban_type' => $banType,
			'ban_reason' => $banReason,
			'expire_time' => $bannedUntil,
			'expire_formatted' => self::formatExpireTime($bannedUntil),
		);
	}

	/**
	 * 按场景检查封禁（用于不同入口）
	 *
	 * 场景规则：
	 * - login：ban_type=2,3 拒绝（禁止访问/锁定不能登录）
	 * - browse：ban_type=2,3 拒绝（禁止访问/锁定不能浏览）
	 * - post：ban_type=1,2,3 拒绝（禁言及以上不能发帖回帖）
	 * - password：ban_type=3 拒绝（锁定不能改密找密）
	 *
	 * @param int $uid
	 * @param string $scene 'login'/'browse'/'post'/'password'
	 * @return array ['allowed'=>bool, 'message'=>拒绝原因]
	 */
	public static function checkBanByScene($uid, $scene) {
		$status = self::checkBan($uid);
		if(!$status['banned']) {
			return array('allowed' => true, 'message' => '');
		}

		$banType = intval($status['ban_type']);
		$reason = (string)$status['ban_reason'];
		$expire = self::formatExpireTime($status['expire_time']);

		// 场景 → 拒绝的 ban_type 集合
		switch($scene) {
			case 'login':
				$denyTypes = array(self::BAN_TYPE_BAN_ACCESS, self::BAN_TYPE_LOCK);
				$msgKey = 'user_ban_login_denied';
				break;
			case 'browse':
				$denyTypes = array(self::BAN_TYPE_BAN_ACCESS, self::BAN_TYPE_LOCK);
				$msgKey = 'user_ban_browse_denied';
				break;
			case 'post':
				$denyTypes = array(self::BAN_TYPE_SILENCE, self::BAN_TYPE_BAN_ACCESS, self::BAN_TYPE_LOCK);
				$msgKey = 'user_ban_post_denied';
				break;
			case 'password':
				$denyTypes = array(self::BAN_TYPE_LOCK);
				$msgKey = 'user_ban_password_denied';
				break;
			default:
				// 未知场景默认拒绝所有封禁状态（保守策略）
				$denyTypes = array(self::BAN_TYPE_SILENCE, self::BAN_TYPE_BAN_ACCESS, self::BAN_TYPE_LOCK);
				$msgKey = 'user_ban_denied';
		}

		if(in_array($banType, $denyTypes, true)) {
			$message = lang($msgKey, array(
				'reason' => $reason,
				'expire' => $expire,
			));
			return array('allowed' => false, 'message' => $message);
		}

		return array('allowed' => true, 'message' => '');
	}

	/**
	 * 清空用户内容（保留账号）
	 *
	 * 删除用户发布的回帖、主题索引、附件、通知，并重置主题/回帖计数为 0
	 * 不删除用户账号本身、不修改密码和用户组
	 *
	 * @param int $uid
	 * @param int $adminUid
	 * @return array
	 */
	public static function clearContent($uid, $adminUid) {
		$uid = intval($uid);
		$adminUid = intval($adminUid);

		if($uid <= 0) {
			return array('code' => 1, 'message' => lang('user_ban_invalid_uid'));
		}
		if($adminUid <= 0) {
			return array('code' => 1, 'message' => lang('user_ban_invalid_admin'));
		}

		if(!function_exists('user_read')) {
			include_once APP_PATH . 'model/user.func.php';
		}
		if(!function_exists('ban_log_create')) {
			include_once APP_PATH . 'model/ban_log.func.php';
		}

		$_user = user_read($uid);
		if(empty($_user)) {
			return array('code' => 1, 'message' => lang('user_not_exists'));
		}

		// 2. 不能清空管理员组（gid=1,2）
		if(in_array(intval($_user['gid']), self::ADMIN_GIDS, true)) {
			return array('code' => 1, 'message' => lang('user_ban_cannot_clear_admin'));
		}

		// 1. 触发 beforeClearContent 事件
		$eventArgs = array(
			'uid' => $uid,
			'adminUid' => $adminUid,
		);
		XnEvent::trigger('UserBanService.beforeClearContent', $eventArgs);

		// 确保 model 函数已加载
		if(!function_exists('post_delete_by_uid')) {
			include_once APP_PATH . 'model/post.func.php';
		}
		if(!function_exists('mythread_delete_by_uid')) {
			include_once APP_PATH . 'model/mythread.func.php';
		}
		if(!function_exists('attach_delete_by_uid')) {
			include_once APP_PATH . 'model/attach.func.php';
		}
		if(!function_exists('notify_delete_by_uid')) {
			include_once APP_PATH . 'model/notify.func.php';
		}

		// 3. 删除所有回帖
		if(function_exists('post_delete_by_uid')) {
			post_delete_by_uid($uid);
		}
		// 4. 删除主题索引
		// ponytail: mythread_delete_by_uid 仅删除 mythread 索引表，不会删除 bbs_thread 主题记录
		// 已知天花板：用户的主题帖（bbs_thread）会变成孤儿数据，仍可在版块中可见
		// 升级路径：如需彻底删除主题，应按 user_delete() 模式分批 mythread_find_by_uid + thread_delete
		if(function_exists('mythread_delete_by_uid')) {
			mythread_delete_by_uid($uid);
		}
		// 5. 删除附件
		if(function_exists('attach_delete_by_uid')) {
			attach_delete_by_uid($uid);
		}
		// 6. 删除通知
		if(function_exists('notify_delete_by_uid')) {
			notify_delete_by_uid($uid);
		}

		// 7. 重置主题/回帖计数
		$update = array(
			'threads' => 0,
			'posts' => 0,
		);
		user_update($uid, $update);

		// 8. 写入 ban_log（action='clear_content'）
		ban_log_create(array(
			'uid' => $uid,
			'admin_uid' => $adminUid,
			'action' => 'clear_content',
			'ban_type' => 0,
			'reason' => '',
			'duration' => 0,
		));

		// 9. 触发 afterClearContent 事件
		$afterArgs = array(
			'uid' => $uid,
			'adminUid' => $adminUid,
		);
		XnEvent::trigger('UserBanService.afterClearContent', $afterArgs);

		return array('code' => 0);
	}

	/**
	 * 获取用户封禁状态（用于前端显示）
	 *
	 * @param int $uid
	 * @return array 包含 ban_type/ban_reason/ban_time/banned_until/expire_formatted/status_label/status_color
	 */
	public static function getBanStatus($uid) {
		$uid = intval($uid);
		$normal = array(
			'ban_type' => self::BAN_TYPE_NORMAL,
			'ban_reason' => '',
			'ban_time' => 0,
			'banned_until' => 0,
			'expire_formatted' => '',
			'status_label' => lang('user_ban_status_normal'),
			'status_color' => 'success',
		);
		if($uid <= 0) return $normal;
		if(!function_exists('db_check_column_exists') || !db_check_column_exists('user', 'ban_type')) {
			return $normal;
		}
		if(!function_exists('user_read')) {
			include_once APP_PATH . 'model/user.func.php';
		}
		$_user = user_read($uid);
		if(empty($_user)) return $normal;

		$banType = isset($_user['ban_type']) ? intval($_user['ban_type']) : 0;
		$bannedUntil = isset($_user['banned_until']) ? intval($_user['banned_until']) : 0;
		$banReason = isset($_user['ban_reason']) ? (string)$_user['ban_reason'] : '';
		$banTime = isset($_user['ban_time']) ? intval($_user['ban_time']) : 0;

		// 已过期但未自动解封：视为正常（顺便触发自动解封）
		if($banType !== self::BAN_TYPE_NORMAL && $bannedUntil > 0 && $bannedUntil <= time()) {
			self::autoUnban($uid);
			return $normal;
		}

		$label = self::getBanTypeLabel($banType);
		return array(
			'ban_type' => $banType,
			'ban_reason' => $banReason,
			'ban_time' => $banTime,
			'banned_until' => $bannedUntil,
			'expire_formatted' => self::formatExpireTime($bannedUntil),
			'status_label' => $label['label'],
			'status_color' => $label['color'],
		);
	}

	/**
	 * 自动解封（到期触发，内部调用）
	 * 重置封禁字段，写入 ban_log（action='auto_unban'），不发通知（避免打扰）
	 *
	 * @param int $uid
	 */
	private static function autoUnban($uid) {
		$uid = intval($uid);
		if($uid <= 0) return;

		if(!function_exists('user_update')) {
			include_once APP_PATH . 'model/user.func.php';
		}
		if(!function_exists('ban_log_create')) {
			include_once APP_PATH . 'model/ban_log.func.php';
		}

		// 重置字段
		$update = array(
			'ban_type' => self::BAN_TYPE_NORMAL,
			'banned_until' => 0,
			'ban_reason' => '',
			'ban_admin_uid' => 0,
			'ban_time' => 0,
		);
		user_update($uid, $update);

		// 写入 ban_log（admin_uid=0 表示系统自动）
		if(function_exists('ban_log_create')) {
			ban_log_create(array(
				'uid' => $uid,
				'admin_uid' => 0,
				'action' => 'auto_unban',
				'ban_type' => self::BAN_TYPE_NORMAL,
				'reason' => '',
				'duration' => 0,
			));
		}
		// 不发通知（避免到期用户被频繁打扰）
	}

	/**
	 * 发送封禁/解封通知
	 *
	 * @param int $uid 接收者 uid
	 * @param string $type 'ban' 或 'unban'
	 * @param array $banInfo 封禁信息（banType/reason/bannedUntil/duration）
	 */
	private static function sendNotice($uid, $type, $banInfo) {
		$uid = intval($uid);
		if($uid <= 0) return;

		if(!function_exists('notify_create')) {
			include_once APP_PATH . 'model/notify.func.php';
		}
		if(!function_exists('notify_create')) return;

		$banType = intval($banInfo['banType']);
		$reason = (string)$banInfo['reason'];
		$bannedUntil = intval($banInfo['bannedUntil']);
		$duration = intval($banInfo['duration']);

		$typeLabel = self::getBanTypeLabel($banType);
		$expireFormatted = self::formatExpireTime($bannedUntil);
		$durationFormatted = self::formatDuration($duration);

		if($type === 'ban') {
			// type='system' 允许系统通知自己（from_uid=0），不会被 notify_create 的自己通知自己过滤拦截
			$message = lang('user_ban_notice_ban', array(
				'type' => $typeLabel['label'],
				'reason' => $reason,
				'duration' => $durationFormatted,
				'expire' => $expireFormatted,
			));
		} else {
			// 解封通知：reason 为空时使用无原因文案，避免显示「原因：」后接空白
			if($reason !== '') {
				$message = lang('user_ban_notice_unban', array('reason' => $reason));
			} else {
				$message = lang('user_ban_notice_unban_no_reason');
			}
		}

		// notify_create 签名：($uid, $from_uid, $type, $tid=0, $pid=0, $content='', $extra=array())
		// from_uid=0 表示系统；extra.message 为富文本内容（通知列表展示用）
		notify_create($uid, 0, 'system', 0, 0, '', array('message' => $message));
	}

	/**
	 * 获取封禁类型标签
	 *
	 * @param int $banType
	 * @return array ['label'=>string, 'color'=>string]
	 */
	public static function getBanTypeLabel($banType) {
		$banType = intval($banType);
		switch($banType) {
			case self::BAN_TYPE_SILENCE:
				return array('label' => lang('user_ban_type_silence'), 'color' => 'warning');
			case self::BAN_TYPE_BAN_ACCESS:
				return array('label' => lang('user_ban_type_ban_access'), 'color' => 'danger');
			case self::BAN_TYPE_LOCK:
				return array('label' => lang('user_ban_type_lock'), 'color' => 'dark');
			case self::BAN_TYPE_NORMAL:
			default:
				return array('label' => lang('user_ban_status_normal'), 'color' => 'success');
		}
	}

	/**
	 * 格式化封禁时长为可读文本
	 *
	 * @param int $duration 秒
	 * @return string 如 "7天" / "永久" / "3天"
	 */
	public static function formatDuration($duration) {
		$duration = intval($duration);
		if($duration === 0) {
			return lang('user_ban_permanent');
		}
		if($duration < 3600) {
			$m = max(1, intval($duration / 60));
			return lang('user_ban_duration_minutes', array('n' => $m));
		}
		if($duration < 86400) {
			$h = max(1, intval($duration / 3600));
			return lang('user_ban_duration_hours', array('n' => $h));
		}
		$d = max(1, intval($duration / 86400));
		return lang('user_ban_duration_days', array('n' => $d));
	}

	/**
	 * 格式化解封时间为可读文本
	 *
	 * @param int $banned_until 时间戳
	 * @return string
	 */
	public static function formatExpireTime($banned_until) {
		$banned_until = intval($banned_until);
		if($banned_until === 0) {
			return '';
		}
		if($banned_until >= self::PERMANENT_BAN) {
			return lang('user_ban_permanent');
		}
		return date('Y-m-d H:i:s', $banned_until);
	}
}
