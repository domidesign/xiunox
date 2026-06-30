<?php

!defined('DEBUG') AND exit('Forbidden');

/**
 * CreditsRuleService 积分规则服务
 * 负责积分规则的查询、版块覆盖、插件钩子扩展
 * @since 4.5.0
 */
class CreditsRuleService {

	// 扣减事件列表（这些事件使用 CreditsService::sub 扣减积分）
	private static $subEvents = array('like', 'unlike', 'thread_delete', 'reply_delete', 'favorite', 'unfavorite');

	// 规则缓存：[event][fid] => rule_array，避免批量场景下 N+1 规则查询
	// fid=0 表示全局规则
	private static $ruleCache = array();

	// 全局规则批量预加载标记：[event] => true，表示该 event 的全局规则已查过
	private static $globalRuleLoaded = array();

	/**
	 * 获取积分规则
	 * @param string $event 事件标识
	 * @param int $fid 版块ID，0表示仅查全局规则
	 * @param int $uid 用户ID（可选）
	 * @param string $source 来源标识（如 pid/tid），用于日志溯源和防重入
	 * @return array ['enabled'=>bool, 'credits_change'=>int, 'golds_change'=>int, 'rmbs_change'=>int]
	 */
	public static function getRule(string $event, int $fid = 0, int $uid = 0, string $source = ''): array {
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

		// 2. be_liked / be_favorited 防重复：同用户对同一帖子只发放一次
		if ($source !== '' && in_array($event, array('be_liked', 'be_favorited'))) {
			$reason = $event . ':' . $source;
			$count = db_count('credits_log', array('uid' => $uid, 'reason' => $reason));
			if ($count > 0) {
				return array('enabled' => false);
			}
		}

		// 4. 优先查找版块规则（走缓存）
		$rule = null;
		if($fid > 0) {
			if(isset(self::$ruleCache[$event][$fid])) {
				$rule = self::$ruleCache[$event][$fid];
			} else {
				$forumRule = db_find_one('credits_rule_forum', array('fid' => $fid, 'event' => $event));
				$rule = !empty($forumRule) ? $forumRule : null;
				// 缓存（包括 null 结果，避免重复查询）
				if(!isset(self::$ruleCache[$event])) self::$ruleCache[$event] = array();
				self::$ruleCache[$event][$fid] = $rule;
			}
		}

		// 4. 回退到全局规则（走缓存）
		if($rule === null) {
			if(isset(self::$ruleCache[$event][0])) {
				$rule = self::$ruleCache[$event][0];
			} else {
				$rule = db_find_one('credits_rule_global', array('event' => $event));
				if(!isset(self::$ruleCache[$event])) self::$ruleCache[$event] = array();
				self::$ruleCache[$event][0] = $rule;
			}
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
			'daily_limit' => intval($rule['daily_limit'] ?? 0),
		);
	}

	/**
	 * 应用积分规则（便捷方法）
	 * 自动调用 getRule 获取规则，然后调用 CreditsService::add 或 sub
	 * @param string $event 事件标识
	 * @param int $uid 目标用户ID
	 * @param int $fid 版块ID
	 * @param bool $checkOnly 仅检查不执行（用于前端预检查）
	 * @param string $source 来源标识（pid/tid），用于防重入锁和日志溯源
	 * @return array 操作结果
	 */
	public static function applyRule(string $event, int $uid, int $fid = 0, bool $checkOnly = false, string $source = ''): array {
		if ($uid <= 0) return array('ok' => false, 'message' => '无效的用户ID');

		// ===== MySQL 应用级锁：防止并发请求同时处理同一事件（TOCTOU 防护） =====
		$lockKey = NULL;
		$lockAcquired = FALSE;
		if (!$checkOnly && $uid > 0 && in_array($event, array('be_liked', 'be_favorited'))) {
			global $db;
			$lockKey = 'credits_' . $event . '_' . $uid . ($source !== '' ? '_' . $source : '');
			if (isset($db->wlink) && $db->wlink) {
				$stmt = $db->wlink->query("SELECT GET_LOCK(" . $db->wlink->quote($lockKey) . ", 3) AS lk");
				if ($stmt && $stmt->fetchColumn() == 1) {
					$lockAcquired = TRUE;
				}
			}
			// 获取锁失败说明存在并发冲突，拒绝本次操作以防重复发放
			if (!$lockAcquired) {
				return array('ok' => false, 'message' => '系统繁忙，请稍后重试');
			}
		}

		$rule = self::getRule($event, $fid, $uid, $source);
		if (empty($rule['enabled'])) {
			self::_releaseLock($lockAcquired, $lockKey);
			return array('ok' => true, 'message' => '规则未启用或不存在', 'skipped' => true);
		}

		// 判断是增加还是扣减
		$isSub = in_array($event, self::$subEvents);

		// 获取规则级每日限制
		$dailyLimit = intval($rule['daily_limit'] ?? 0);

		// 为 be_liked/be_favorited 构建含来源的 reason，用于防刷溯源
		$creditsReason = $event;
		if ($source !== '' && in_array($event, array('be_liked', 'be_favorited'))) {
			$creditsReason = $event . ':' . $source;
		}

		// 引入 CreditsService
		if (!class_exists('CreditsService')) {
			include_once APP_PATH . 'lib/CreditsService.php';
		}
		global $db, $conf;
		$creditsService = new CreditsService($db, $conf);

		// 统一防刷检查：在 applyRule 层面只检查一次，避免多积分类型重复计数
		// checkOnly 模式也需检查每日限制，否则预检查阶段无法阻止超额操作
		if (!empty($creditsReason)) {
			$limitCheck = $creditsService->checkDailyLimitPublic($uid, $creditsReason, $dailyLimit);
			if (!$limitCheck['ok']) {
				// 每日上限达到：不拦截操作，但不执行积分变动
				self::_releaseLock($lockAcquired, $lockKey);
				return array(
					'ok' => true,
					'daily_limit_reached' => true,
					'message' => $limitCheck['message'],
					'event' => $event,
					'uid' => $uid,
				);
			}
		}

		$results = array();
		$types = array('credits', 'golds', 'rmbs');

		// 构建扣减描述（用于前端提示）
		$deductDesc = array();

		foreach ($types as $type) {
			$changeKey = $type . '_change';
			$amount = intval($rule[$changeKey] ?? 0);
			if ($amount == 0) continue;

			if ($isSub) {
				// 扣减事件：正值表示扣减量
				$deductAmount = abs($amount);

				// 仅检查模式：检查余额是否充足
				if ($checkOnly) {
					$check = $creditsService->checkNegative($uid, $type, $deductAmount);
					$results[$type] = array(
						'ok' => $check['sufficient'],
						'amount' => $deductAmount,
						'balance' => $check['balance'] ?? 0,
						'sufficient' => $check['sufficient'] ?? false,
					);
					$typeName = self::getTypeName($type);
					if (!$check['sufficient']) {
						return array(
							'ok' => false,
							'message' => $typeName . '余额不足，需要 ' . $deductAmount . '，当前 ' . ($check['balance'] ?? 0),
							'event' => $event,
							'uid' => $uid,
							'results' => $results,
						);
					}
					$deductDesc[] = $typeName . ' -' . $deductAmount;
					continue;
				}

				$result = $creditsService->sub($uid, $type, $deductAmount, $creditsReason, -1);
				if (!$result['ok']) {
					// 余额不足，扣减失败，整个操作应中止
					self::_releaseLock($lockAcquired, $lockKey);
					return array(
						'ok' => false,
						'message' => $result['message'],
						'event' => $event,
						'uid' => $uid,
						'results' => $results,
					);
				}
				$results[$type] = $result;
			} elseif ($amount > 0) {
				// 增加事件：正值增加
				if ($checkOnly) continue; // 增加事件不需要预检查
				$result = $creditsService->add($uid, $type, $amount, $creditsReason, -1);
				$results[$type] = $result;
			} else {
				// 增加事件但值为负：表示扣减
				$deductAmount = abs($amount);

				if ($checkOnly) {
					$check = $creditsService->checkNegative($uid, $type, $deductAmount);
					$results[$type] = array(
						'ok' => $check['sufficient'],
						'amount' => $deductAmount,
						'balance' => $check['balance'] ?? 0,
						'sufficient' => $check['sufficient'] ?? false,
					);
					$typeName = self::getTypeName($type);
					if (!$check['sufficient']) {
						return array(
							'ok' => false,
							'message' => $typeName . '余额不足，需要 ' . $deductAmount . '，当前 ' . ($check['balance'] ?? 0),
							'event' => $event,
							'uid' => $uid,
							'results' => $results,
						);
					}
					$deductDesc[] = $typeName . ' -' . $deductAmount;
					continue;
				}

				$result = $creditsService->sub($uid, $type, $deductAmount, $creditsReason, -1);
				if (!$result['ok']) {
					self::_releaseLock($lockAcquired, $lockKey);
					return array(
						'ok' => false,
						'message' => $result['message'],
						'event' => $event,
						'uid' => $uid,
						'results' => $results,
					);
				}
				$results[$type] = $result;
			}
		}

		$returnData = array(
			'ok' => true,
			'message' => $checkOnly ? '预检查通过' : '积分规则已应用',
			'event' => $event,
			'uid' => $uid,
			'results' => $results,
		);

		// 构建积分变动描述（非预检查模式）
		if (!$checkOnly) {
			$changeDesc = array();
			foreach ($results as $type => $r) {
				if (empty($r['ok']) || empty($r['change'])) continue;
				$change = intval($r['change']);
				if ($change == 0) continue;
				$typeName = self::getTypeName($type);
				$changeDesc[] = $typeName . ($change > 0 ? ' +' . $change : ' ' . $change);
			}
			if (!empty($changeDesc)) {
				$returnData['change_desc'] = implode('，', $changeDesc);
			}
		}

		// 预检查模式返回扣减描述
		if ($checkOnly && !empty($deductDesc)) {
			$returnData['deduct_desc'] = implode('，', $deductDesc);
		}

		// 释放应用级锁
		self::_releaseLock($lockAcquired, $lockKey);

		return $returnData;
	}

	/**
	 * 批量应用积分规则
	 *
	 * 适用场景：mod.php 中对多个 thread 同时应用同一事件（thread_top/thread_delete 等）
	 *
	 * 优化策略：
	 * 1. 批量预查询规则并缓存到 self::$ruleCache，消除规则的 N+1 查询
	 *    （一次查询所有相关 fid 的版块规则 + 一次查询全局规则）
	 * 2. 然后循环调用 applyRule，规则查询走缓存
	 *
	 * 关于未完全批量化的说明：
	 * - CreditsService::add/sub 内部使用事务 + SELECT FOR UPDATE 行锁保证余额正确性，
	 *   同一 uid 的多次操作必须串行执行，无法合并为单次 SQL
	 * - 防刷检查（checkDailyLimit）按 reason 统计当日次数，合并会改变防刷语义
	 * - 用户组升级（user_update_group）需在每个 uid 积分变动后独立检查
	 * 因此保留按 (uid, fid) 循环调用 applyRule，但规则查询已通过缓存消除 N+1
	 *
	 * @param string $event         事件标识
	 * @param array  $uid_fid_pairs [[uid, fid], [uid, fid], ...] 用户与版块配对列表
	 * @return array ['processed'=>int, 'results'=>array]
	 */
	public static function applyRuleBatch(string $event, array $uid_fid_pairs): array {
		// hook credits_rule_apply_batch_start.php
		if(empty($uid_fid_pairs) || !is_array($uid_fid_pairs)) {
			return array('processed' => 0, 'results' => array());
		}

		// 1. 收集所有 fid（去重），用于批量预加载版块规则
		$fids = array();
		foreach($uid_fid_pairs as $pair) {
			if(!is_array($pair) || count($pair) < 2) continue;
			$_fid = intval($pair[1]);
			if($_fid > 0) $fids[$_fid] = true;
		}
		$unique_fids = array_keys($fids);

		// 2. 批量预加载版块规则（一次查询所有 fid 的规则）
		if(!empty($unique_fids)) {
			self::preloadForumRules($event, $unique_fids);
		}

		// 3. 预加载全局规则（一次查询）
		if(!isset(self::$globalRuleLoaded[$event])) {
			$globalRule = db_find_one('credits_rule_global', array('event' => $event));
			if(!isset(self::$ruleCache[$event])) self::$ruleCache[$event] = array();
			self::$ruleCache[$event][0] = !empty($globalRule) ? $globalRule : null;
			self::$globalRuleLoaded[$event] = true;
		}

		// 4. 循环调用 applyRule（规则查询走缓存）
		$results = array();
		$processed = 0;
		foreach($uid_fid_pairs as $pair) {
			if(!is_array($pair) || count($pair) < 2) continue;
			$_uid = intval($pair[0]);
			$_fid = intval($pair[1]);
			if($_uid <= 0) continue;

			$result = self::applyRule($event, $_uid, $_fid);
			$results[] = $result;
			$processed++;
		}

		// hook credits_rule_apply_batch_end.php
		return array('processed' => $processed, 'results' => $results);
	}

	/**
	 * 批量预加载指定 event + 多个 fid 的版块规则到缓存
	 * 一次 SQL 查询所有 fid 的规则，避免 N+1 查询
	 *
	 * @param string $event 事件标识
	 * @param array  $fids  版块ID数组
	 */
	private static function preloadForumRules(string $event, array $fids): void {
		if(empty($fids)) return;
		$fids = array_map('intval', $fids);
		$fids = array_unique($fids);

		// 检查哪些 fid 还没缓存
		$missing_fids = array();
		if(!isset(self::$ruleCache[$event])) self::$ruleCache[$event] = array();
		foreach($fids as $fid) {
			if(!array_key_exists($fid, self::$ruleCache[$event])) {
				$missing_fids[] = $fid;
			}
		}
		if(empty($missing_fids)) return;

		// 一次查询所有未缓存的 fid 的规则
		$rows = db_find('credits_rule_forum', array('fid' => $missing_fids, 'event' => $event), array(), 1, count($missing_fids), 'fid');
		$rows = empty($rows) ? array() : $rows;

		// 写入缓存（包括没有规则的 fid，标记为 null 避免重复查询）
		$found_fids = array();
		foreach($rows as $row) {
			self::$ruleCache[$event][intval($row['fid'])] = $row;
			$found_fids[] = intval($row['fid']);
		}
		foreach($missing_fids as $fid) {
			if(!in_array($fid, $found_fids)) {
				self::$ruleCache[$event][$fid] = null;
			}
		}
	}

	/**
	 * 仅执行积分规则的扣除部分（正值跳过），用于审核场景
	 * 扣除部分（负值/扣减事件）立即执行，奖励部分（正值）延迟到审核通过后由 grantCredits 补发
	 * @param string $event 事件标识
	 * @param int $uid 目标用户ID
	 * @param int $fid 版块ID
	 * @return array 操作结果
	 */
	public static function applyRuleDeductOnly(string $event, int $uid, int $fid = 0): array {
		if ($uid <= 0) return array('ok' => false, 'message' => '无效的用户ID');

		$rule = self::getRule($event, $fid, $uid);
		if (empty($rule['enabled'])) {
			return array('ok' => true, 'message' => '规则未启用或不存在', 'skipped' => true);
		}

		// 判断是否为扣减事件
		$isSub = in_array($event, self::$subEvents);

		if (!class_exists('CreditsService')) {
			include_once APP_PATH . 'lib/CreditsService.php';
		}
		global $db, $conf;
		$creditsService = new CreditsService($db, $conf);

		$creditsReason = $event;
		$results = array();
		$types = array('credits', 'golds', 'rmbs');

		foreach ($types as $type) {
			$changeKey = $type . '_change';
			$amount = intval($rule[$changeKey] ?? 0);
			if ($amount == 0) continue;

			if ($isSub) {
				// 扣减事件：正值表示扣减量，立即执行
				$deductAmount = abs($amount);
				$result = $creditsService->sub($uid, $type, $deductAmount, $creditsReason, -1);
				$results[$type] = $result;
			} elseif ($amount < 0) {
				// 增加事件但值为负：表示扣减，立即执行
				$deductAmount = abs($amount);
				$result = $creditsService->sub($uid, $type, $deductAmount, $creditsReason, -1);
				$results[$type] = $result;
			}
			// 正值（奖励部分）跳过，审核通过后由 grantCredits 补发
		}

		return array(
			'ok' => true,
			'message' => '积分扣除部分已执行，奖励部分待审核通过后发放',
			'event' => $event,
			'uid' => $uid,
			'results' => $results,
		);
	}

	/**
	 * 仅执行积分规则的奖励部分（正值），用于审核通过后补发
	 * @param string $event 事件标识
	 * @param int $uid 目标用户ID
	 * @param int $fid 版块ID
	 * @return array 操作结果
	 */
	public static function applyRewardOnly(string $event, int $uid, int $fid = 0): array {
		if ($uid <= 0) return array('ok' => false, 'message' => '无效的用户ID');

		$rule = self::getRule($event, $fid, $uid);
		if (empty($rule['enabled'])) {
			return array('ok' => true, 'message' => '规则未启用或不存在', 'skipped' => true);
		}

		// 扣减事件不处理奖励
		$isSub = in_array($event, self::$subEvents);
		if ($isSub) {
			return array('ok' => true, 'message' => '扣减事件无奖励部分', 'skipped' => true);
		}

		if (!class_exists('CreditsService')) {
			include_once APP_PATH . 'lib/CreditsService.php';
		}
		global $db, $conf;
		$creditsService = new CreditsService($db, $conf);

		$creditsReason = $event;
		$results = array();
		$types = array('credits', 'golds', 'rmbs');

		foreach ($types as $type) {
			$changeKey = $type . '_change';
			$amount = intval($rule[$changeKey] ?? 0);
			if ($amount <= 0) continue; // 跳过扣除部分（已执行）

			// 正值：发放奖励
			$result = $creditsService->add($uid, $type, $amount, $creditsReason, -1);
			$results[$type] = $result;
		}

		return array(
			'ok' => true,
			'message' => '审核通过，奖励积分已发放',
			'event' => $event,
			'uid' => $uid,
			'results' => $results,
		);
	}

	/**
	 * 释放 MySQL 应用级锁
	 */
	private static function _releaseLock(bool $acquired, ?string $key): void {
		if (!$acquired || $key === null) return;
		global $db;
		if (isset($db->wlink) && $db->wlink) {
			$db->wlink->exec("SELECT RELEASE_LOCK(" . $db->wlink->quote($key) . ")");
		}
	}

	/**
	 * 获取积分类型中文名
	 */
	private static function getTypeName(string $type): string {
		$names = array('credits' => '积分', 'golds' => '金币', 'rmbs' => '人民币');
		return $names[$type] ?? $type;
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
		$failed = 0;
		foreach ($rules as $rule) {
			if (empty($rule['event'])) continue;
			$data = array(
				'credits_change' => self::clampChange(intval($rule['credits_change'] ?? 0)),
				'golds_change' => self::clampChange(intval($rule['golds_change'] ?? 0)),
				'rmbs_change' => self::clampChange(intval($rule['rmbs_change'] ?? 0)),
				'enabled' => intval($rule['enabled'] ?? 1),
				'daily_limit' => intval($rule['daily_limit'] ?? 0),
			);
			$r = db_update('credits_rule_global', array('event' => $rule['event']), $data);
			if ($r === FALSE) {
				$failed++;
			} else {
				$updated++;
			}
		}
		if ($failed > 0) {
			return array('ok' => false, 'message' => "保存失败 {$failed} 条全局规则，可能缺少 daily_limit 字段，请先执行后台一键升级", 'updated' => $updated, 'failed' => $failed);
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
		$failed = 0;
		foreach ($rules as $rule) {
			if (empty($rule['event'])) continue;
			$data = array(
				'fid' => $fid,
				'event' => $rule['event'],
				'credits_change' => self::clampChange(intval($rule['credits_change'] ?? 0)),
				'golds_change' => self::clampChange(intval($rule['golds_change'] ?? 0)),
				'rmbs_change' => self::clampChange(intval($rule['rmbs_change'] ?? 0)),
				'enabled' => intval($rule['enabled'] ?? 1),
				'daily_limit' => intval($rule['daily_limit'] ?? 0),
			);
			// 检查是否已存在
			$exists = db_find_one('credits_rule_forum', array('fid' => $fid, 'event' => $rule['event']));
			if (!empty($exists)) {
				$r = db_update('credits_rule_forum', array('fid' => $fid, 'event' => $rule['event']), $data);
			} else {
				$r = db_insert('credits_rule_forum', $data);
			}
			if ($r === FALSE) {
				$failed++;
			} else {
				$updated++;
			}
		}
		if ($failed > 0) {
			return array('ok' => false, 'message' => "保存失败 {$failed} 条版块规则，可能缺少 daily_limit 字段，请先执行后台一键升级", 'updated' => $updated, 'failed' => $failed);
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
