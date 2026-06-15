<?php

!defined('DEBUG') AND exit('Forbidden');

/**
 * CreditsRuleService 积分规则服务
 * 负责积分规则的查询、版块覆盖、插件钩子扩展
 * @since 4.5.0
 */
class CreditsRuleService {

	// 扣减事件列表（这些事件使用 CreditsService::sub 扣减积分）
	private static $subEvents = array('like', 'thread_delete', 'reply_delete', 'favorite');

	/**
	 * 获取积分规则
	 * @param string $event 事件标识
	 * @param int $fid 版块ID，0表示仅查全局规则
	 * @param int $uid 用户ID，用于 daily_login 判断（可选）
	 * @return array ['enabled'=>bool, 'credits_change'=>int, 'golds_change'=>int, 'rmbs_change'=>int]
	 */
	public static function getRule(string $event, int $fid = 0, int $uid = 0): array {
		global $db, $tablepre;

		// 1. 触发 credits_rule_get_before 钩子，允许插件修改规则
		$hookResult = self::fireBeforeGetRule($event, $fid, $uid);
		if ($hookResult !== null) {
			// 插件已处理，直接返回
			if (isset($hookResult['enabled']) && !$hookResult['enabled']) {
				return array('enabled' => false);
			}
			if (isset($hookResult['handled']) && $hookResult['handled']) {
				return $hookResult;
			}
		}

		// 2. daily_login 特殊处理：检查今日是否已发放
		if ($event === 'daily_login' && $uid > 0) {
			$todayStart = strtotime(date('Y-m-d'));
			$count = db_count('credits_log', array(
				'uid' => $uid,
				'reason' => 'daily_login',
				'create_date>' => $todayStart,
			));
			if ($count > 0) {
				return array('enabled' => false);
			}
		}

		// 3. 优先查找版块规则
		$rule = null;
		if ($fid > 0) {
			$forumRule = db_find_one('credits_rule_forum', array('fid' => $fid, 'event' => $event));
			if (!empty($forumRule)) {
				$rule = $forumRule;
			}
		}

		// 4. 回退到全局规则
		if ($rule === null) {
			$rule = db_find_one('credits_rule_global', array('event' => $event));
		}

		// 5. 规则不存在或未启用
		if (empty($rule) || empty($rule['enabled'])) {
			return array('enabled' => false);
		}

		return array(
			'enabled' => true,
			'credits_change' => intval($rule['credits_change']),
			'golds_change' => intval($rule['golds_change']),
			'rmbs_change' => intval($rule['rmbs_change']),
		);
	}

	/**
	 * 应用积分规则（便捷方法）
	 * 自动调用 getRule 获取规则，然后调用 CreditsService::add 或 sub
	 * @param string $event 事件标识
	 * @param int $uid 目标用户ID
	 * @param int $fid 版块ID
	 * @return array 操作结果
	 */
	public static function applyRule(string $event, int $uid, int $fid = 0): array {
		if ($uid <= 0) return array('ok' => false, 'message' => '无效的用户ID');

		$rule = self::getRule($event, $fid, $uid);
		if (empty($rule['enabled'])) {
			return array('ok' => true, 'message' => '规则未启用或不存在', 'skipped' => true);
		}

		// 判断是增加还是扣减
		$isSub = in_array($event, self::$subEvents);

		// 引入 CreditsService
		if (!class_exists('CreditsService')) {
			include_once APP_PATH . 'lib/CreditsService.php';
		}
		global $db, $conf;
		$creditsService = new CreditsService($db, $conf);

		$results = array();
		$types = array('credits', 'golds', 'rmbs');

		foreach ($types as $type) {
			$changeKey = $type . '_change';
			$amount = intval($rule[$changeKey] ?? 0);
			if ($amount == 0) continue;

			if ($isSub) {
				// 扣减事件：正值表示扣减量，余额不足时静默跳过
				$result = $creditsService->sub($uid, $type, abs($amount), $event);
			} elseif ($amount > 0) {
				// 增加事件：正值增加
				$result = $creditsService->add($uid, $type, $amount, $event);
			} else {
				// 增加事件但值为负：表示扣减（如发帖扣分），余额不足时静默跳过
				$result = $creditsService->sub($uid, $type, abs($amount), $event);
			}
			$results[$type] = $result;
		}

		return array(
			'ok' => true,
			'message' => '积分规则已应用',
			'event' => $event,
			'uid' => $uid,
			'results' => $results,
		);
	}

	// 积分变化值允许范围
	private static $changeMin = -999;
	private static $changeMax = 999;

	/**
	 * 校验积分变化值范围
	 */
	private static function clampChange(int $val): int {
		return max(self::$changeMin, min(self::$changeMax, $val));
	}

	/**
	 * 获取所有全局规则
	 * @return array
	 */
	public static function getAllGlobalRules(): array {
		return db_find('credits_rule_global', array(), array('ruleid' => 1), 1, 100);
	}

	/**
	 * 获取版块规则
	 * @param int $fid
	 * @return array
	 */
	public static function getForumRules(int $fid): array {
		if ($fid <= 0) return array();
		return db_find('credits_rule_forum', array('fid' => $fid), array('id' => 1), 1, 100);
	}

	/**
	 * 保存全局规则
	 * @param array $rules 规则数组，每个元素包含 event/credits_change/golds_change/rmbs_change/enabled
	 * @return array
	 */
	public static function saveGlobalRules(array $rules): array {
		$updated = 0;
		foreach ($rules as $rule) {
			if (empty($rule['event'])) continue;
			$data = array(
				'credits_change' => self::clampChange(intval($rule['credits_change'] ?? 0)),
				'golds_change' => self::clampChange(intval($rule['golds_change'] ?? 0)),
				'rmbs_change' => self::clampChange(intval($rule['rmbs_change'] ?? 0)),
				'enabled' => intval($rule['enabled'] ?? 1),
			);
			db_update('credits_rule_global', array('event' => $rule['event']), $data);
			$updated++;
		}
		return array('ok' => true, 'message' => "已更新 {$updated} 条全局规则", 'updated' => $updated);
	}

	/**
	 * 保存版块规则
	 * @param int $fid
	 * @param array $rules
	 * @return array
	 */
	public static function saveForumRules(int $fid, array $rules): array {
		if ($fid <= 0) return array('ok' => false, 'message' => '无效的版块ID');

		$updated = 0;
		foreach ($rules as $rule) {
			if (empty($rule['event'])) continue;
			$data = array(
				'fid' => $fid,
				'event' => $rule['event'],
				'credits_change' => self::clampChange(intval($rule['credits_change'] ?? 0)),
				'golds_change' => self::clampChange(intval($rule['golds_change'] ?? 0)),
				'rmbs_change' => self::clampChange(intval($rule['rmbs_change'] ?? 0)),
				'enabled' => intval($rule['enabled'] ?? 1),
			);
			// 检查是否已存在
			$exists = db_find_one('credits_rule_forum', array('fid' => $fid, 'event' => $rule['event']));
			if (!empty($exists)) {
				db_update('credits_rule_forum', array('fid' => $fid, 'event' => $rule['event']), $data);
			} else {
				db_insert('credits_rule_forum', $data);
			}
			$updated++;
		}
		return array('ok' => true, 'message' => "已更新 {$updated} 条版块规则", 'updated' => $updated);
	}

	/**
	 * 删除版块规则
	 * @param int $fid
	 * @param string $event
	 * @return bool
	 */
	public static function deleteForumRule(int $fid, string $event): bool {
		if ($fid <= 0 || empty($event)) return false;
		db_delete('credits_rule_forum', array('fid' => $fid, 'event' => $event));
		return true;
	}

	/**
	 * credits_rule_get_before 钩子
	 * 插件可返回 ['handled'=>true, 'enabled'=>bool, ...] 来接管规则查询
	 * 或返回 null 让默认逻辑继续
	 */
	private static function fireBeforeGetRule(string $event, int $fid, int $uid) {
		global $g_credits_rule_hooks;
		if (empty($g_credits_rule_hooks['credits_rule_get_before'])) return null;

		foreach ($g_credits_rule_hooks['credits_rule_get_before'] as $callback) {
			if (!is_callable($callback)) continue;
			$result = call_user_func($callback, $event, $fid, $uid);
			if ($result !== null) return $result;
		}
		return null;
	}

	/**
	 * 注册钩子
	 * @param string $hookName 钩子名称
	 * @param callable $callback 回调函数
	 */
	public static function registerHook(string $hookName, callable $callback): void {
		global $g_credits_rule_hooks;
		if (!isset($g_credits_rule_hooks)) $g_credits_rule_hooks = array();
		if (!isset($g_credits_rule_hooks[$hookName])) $g_credits_rule_hooks[$hookName] = array();
		$g_credits_rule_hooks[$hookName][] = $callback;
	}
}
