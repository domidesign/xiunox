<?php
// api/v1/my.php
// 个人中心自助 API（全部需要 Authenticated，需 Bearer Token）

// === 鉴权：所有端点都需要 Bearer Token ===
$authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
if (!$authUser) {
    ApiResponse::unauthorized();
}

$uid = intval($authUser['uid']);
$gid = intval($authUser['gid']);
$action = $segments[1] ?? '';
$subAction = $segments[2] ?? '';

// 当前用户完整数据（部分端点需要）
$user = user_read($uid);
if (empty($user)) {
    ApiResponse::notFound('User not found');
}

// 移除敏感字段后返回用户数据
function my_sanitize_user(array $u): array {
    unset($u['password'], $u['salt'], $u['password_hash'], $u['login_attempts'], $u['banned_until'], $u['last_login_ip'], $u['last_login_time'], $u['ai_config']);
    return $u;
}

// 生成本人头像 URL（注意：与 model/route.func.php 的 my_avatar_url 路由URL生成器同名但语义不同，加前缀避免冲突）
function api_my_avatar_url(array $user): string {
    global $conf;
    if (!empty($user['avatar_url'])) return $user['avatar_url'];
    if (isset($user['avatar']) && $user['avatar'] > 0) {
        return $conf['upload_url'] . 'avatar/' . substr(sprintf("%09d", $user['uid']), 0, 3) . '/' . $user['uid'] . '.png?' . $user['avatar'];
    }
    return default_avatar_url();
}

// 分页结果封装
function my_paginate(array $list, int $page, int $pagesize, int $total): array {
    return [
        'list' => $list,
        'pagination' => [
            'page' => $page,
            'pagesize' => $pagesize,
            'total' => $total,
            'total_pages' => $pagesize > 0 ? (int)ceil($total / $pagesize) : 0,
        ],
    ];
}

switch ($action) {

    // ========== GET/PUT /my/profile ==========
    case 'profile':
        if ($method === 'GET') {
            // 计算昵称/签名剩余可修改次数（30天周期）
            if (!class_exists('SecurityConfigService')) {
                include_once APP_PATH . 'lib/security/SecurityConfigService.php';
            }
            $nickname_change_limit = intval(SecurityConfigService::get('security_nickname_change_limit', 1));
            $signature_change_limit = intval(SecurityConfigService::get('security_signature_change_limit', 3));
            $nickname_remaining = $nickname_change_limit;
            $signature_remaining = $signature_change_limit;
            $thirty_days_ago = time() - 30 * 86400;
            if ($nickname_change_limit > 0 && db_check_table_exists('nickname_change_log')) {
                $recent = db_count('nickname_change_log', array('uid' => $uid, 'change_time' => array('>' => $thirty_days_ago)));
                $nickname_remaining = max(0, $nickname_change_limit - $recent);
            }
            if ($signature_change_limit > 0 && db_check_table_exists('signature_change_log')) {
                $recent = db_count('signature_change_log', array('uid' => $uid, 'change_time' => array('>' => $thirty_days_ago)));
                $signature_remaining = max(0, $signature_change_limit - $recent);
            }

            $userData = my_sanitize_user($user);
            $userData['avatar_url'] = api_my_avatar_url($user);
            $userData['nickname_change_limit'] = $nickname_change_limit;
            $userData['nickname_remaining'] = $nickname_remaining;
            $userData['signature_change_limit'] = $signature_change_limit;
            $userData['signature_remaining'] = $signature_remaining;

            ApiResponse::success($userData);

        } elseif ($method === 'PUT') {
            $nickname = param('nickname', '');
            // 签名支持HTML：第三参数FALSE取消基础htmlspecialchars转义，由xn_signature_purify统一净化
            $signature = param('signature', '', FALSE);

            // 签名 HTML 净化与长度检查
            if ($signature !== '') {
                $signature = xn_signature_purify($signature);
                if (mb_strlen(strip_tags($signature)) > 255) {
                    ApiResponse::validationError('signature_length_too_long');
                }
            }
            if (!empty($nickname) && mb_strlen($nickname) > 32) {
                ApiResponse::validationError('nickname_length_too_long');
            }

            // 检查个人资料审核权限
            if (!class_exists('PermissionService')) {
                include_once APP_PATH . 'lib/PermissionService.php';
            }
            $need_profile_audit = !PermissionService::check('allow_direct_profile');

            $update = array();
            if (!empty($nickname) && $nickname != $user['nickname']) {
                // 昵称保留词检查
                if (!class_exists('SensitiveWordFilter')) {
                    include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
                }
                $nickname_check = SensitiveWordFilter::content_check($nickname, SensitiveWordFilter::TYPE_RESERVED);
                if (!$nickname_check['pass']) {
                    ApiResponse::validationError('昵称包含保留词：' . implode('、', $nickname_check['matched_keywords']));
                }
                // 昵称全局唯一性检查
                $exists = db_find_one('user', array('nickname' => $nickname));
                if (!empty($exists) && $exists['uid'] != $uid) {
                    ApiResponse::validationError('nickname_is_in_use');
                }
                // 昵称修改频率限制：30天内最多N次
                if (!class_exists('SecurityConfigService')) {
                    include_once APP_PATH . 'lib/security/SecurityConfigService.php';
                }
                $nickname_change_limit = intval(SecurityConfigService::get('security_nickname_change_limit', 1));
                if ($nickname_change_limit > 0 && db_check_column_exists('user', 'nickname') && db_check_table_exists('nickname_change_log')) {
                    $thirty_days_ago = time() - 30 * 86400;
                    $recent_changes = db_count('nickname_change_log', array('uid' => $uid, 'change_time' => array('>' => $thirty_days_ago)));
                    if ($recent_changes >= $nickname_change_limit) {
                        $last_log = db_find_one('nickname_change_log', array('uid' => $uid, 'change_time' => array('>' => $thirty_days_ago)), array('change_time' => -1));
                        $last_change_time = $last_log ? $last_log['change_time'] : time();
                        $remain_days = 30 - intval((time() - $last_change_time) / 86400);
                        $remain_days = $remain_days > 0 ? $remain_days : 1;
                        ApiResponse::validationError('昵称修改过于频繁，' . $remain_days . ' 天后再试');
                    }
                }
                $update['nickname'] = $nickname;
            }
            if (db_check_column_exists('user', 'signature') && $signature != $user['signature']) {
                // 签名内容敏感词检查
                if (!class_exists('SensitiveWordFilter')) {
                    include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
                }
                $sig_check = SensitiveWordFilter::content_check($signature, SensitiveWordFilter::TYPE_SENSITIVE);
                if (!$sig_check['pass']) {
                    ApiResponse::validationError('签名包含敏感词：' . implode('、', $sig_check['matched_keywords']));
                }
                // 签名修改频率限制
                if (!class_exists('SecurityConfigService')) {
                    include_once APP_PATH . 'lib/security/SecurityConfigService.php';
                }
                $signature_change_limit = intval(SecurityConfigService::get('security_signature_change_limit', 3));
                if ($signature_change_limit > 0 && db_check_table_exists('signature_change_log')) {
                    $thirty_days_ago = time() - 30 * 86400;
                    $recent_changes = db_count('signature_change_log', array('uid' => $uid, 'change_time' => array('>' => $thirty_days_ago)));
                    if ($recent_changes >= $signature_change_limit) {
                        $last_log = db_find_one('signature_change_log', array('uid' => $uid, 'change_time' => array('>' => $thirty_days_ago)), array('change_time' => -1));
                        $last_change_time = $last_log ? $last_log['change_time'] : time();
                        $remain_days = 30 - intval((time() - $last_change_time) / 86400);
                        $remain_days = $remain_days > 0 ? $remain_days : 1;
                        ApiResponse::validationError('签名修改过于频繁，' . $remain_days . ' 天后再试');
                    }
                }
                $update['signature'] = $signature;
            }

            if (!empty($update)) {
                if ($need_profile_audit) {
                    // 需要审核：将变更存入审核表
                    foreach ($update as $field_name => $new_value) {
                        $old_value = isset($user[$field_name]) ? $user[$field_name] : '';
                        user_profile_audit_create(array(
                            'uid' => $uid,
                            'field_name' => $field_name,
                            'old_value' => $old_value,
                            'new_value' => $new_value,
                            'audit_status' => 0,
                            'create_date' => time(),
                        ));
                    }
                    ApiResponse::success(array('pending_audit' => true), '资料已提交，等待审核');
                } else {
                    $r = user_update($uid, $update);
                    if ($r === FALSE) {
                        ApiResponse::error(500, 'update_error');
                    }
                    // 记录昵称修改日志
                    if (isset($update['nickname']) && db_check_table_exists('nickname_change_log')) {
                        db_insert('nickname_change_log', array(
                            'uid' => $uid,
                            'old_nickname' => $user['nickname'],
                            'new_nickname' => $update['nickname'],
                            'change_time' => time(),
                            'ip' => $longip,
                        ));
                    }
                    // 记录签名修改日志
                    if (isset($update['signature']) && db_check_table_exists('signature_change_log')) {
                        db_insert('signature_change_log', array(
                            'uid' => $uid,
                            'old_signature' => $user['signature'],
                            'new_signature' => $update['signature'],
                            'change_time' => time(),
                            'ip' => $longip,
                        ));
                    }
                }
            }
            $freshUser = my_sanitize_user(user_read($uid));
            $freshUser['avatar_url'] = api_my_avatar_url(user_read($uid));
            ApiResponse::success($freshUser, 'update_successfully');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }
        break;

    // ========== PUT /my/password ==========
    case 'password':
        if ($method !== 'PUT') {
            ApiResponse::error(405, 'Method not allowed');
        }

        // 改密前检查封禁状态（锁定用户禁止改密，管理员组 gid=1,2 豁免）
        if (!class_exists('UserBanService')) {
            include_once APP_PATH . 'lib/UserBanService.php';
        }
        if (!in_array($gid, UserBanService::ADMIN_GIDS, true)) {
            $ban_check = UserBanService::checkBanByScene($uid, 'password');
            if (!$ban_check['allowed']) {
                ApiResponse::error(403, $ban_check['message']);
            }
        }

        $password_old = param('password_old', '', FALSE);
        $password_new = param('password_new', '', FALSE);
        $password_new_repeat = param('password_new_repeat', '', FALSE);

        if (!hash_equals($password_new, $password_new_repeat)) {
            ApiResponse::validationError('repeat_password_incorrect');
        }

        // 密码策略校验
        if (!class_exists('SecurityConfigService')) {
            include_once APP_PATH . 'lib/security/SecurityConfigService.php';
        }
        $policy_err = SecurityConfigService::checkPasswordPolicy($password_new);
        if ($policy_err) {
            ApiResponse::validationError($policy_err);
        }

        // 旧密码校验 + 修改密码
        $r = user_change_password($uid, $password_new, $password_old);
        if ($r === FALSE) {
            ApiResponse::validationError('old_password_incorrect');
        }

        // 使其他 token 失效：删除该用户除当前 access token 外的所有 api_token
        $currentToken = ApiAuthService::getBearerToken();
        if ($currentToken) {
            $otherTokens = db_find('api_token', array('uid' => $uid), array(), 1, 1000, 'id');
            if (!empty($otherTokens)) {
                foreach ($otherTokens as $t) {
                    if ($t['token'] !== $currentToken) {
                        db_delete('api_token', array('id' => $t['id']));
                    }
                }
            }
        }

        ApiResponse::success(null, 'password_modify_successfully');
        break;

    // ========== GET/PUT /my/email，POST /my/email/send-code ==========
    case 'email':
        if ($method === 'GET') {
            ApiResponse::success(array(
                'email' => isset($user['email']) ? $user['email'] : '',
                'verified' => !empty($user['email']),
            ));

        } elseif ($method === 'PUT') {
            $email_new = param('email_new', '');
            $email_code = param('email_code', '');

            if (empty($email_new)) {
                ApiResponse::validationError('please_input_email');
            }
            if (!filter_var($email_new, FILTER_VALIDATE_EMAIL)) {
                ApiResponse::validationError('email_format_mismatch');
            }
            if ($email_new == $user['email']) {
                ApiResponse::validationError('email_same_as_current');
            }

            // API 模式无 session，验证码存 cache（key 含 uid，10 分钟有效）
            $cache_code_key = 'api_email_code_' . $uid;
            $cache_target_key = 'api_email_target_' . $uid;
            $session_code = cache_get($cache_code_key);
            $session_email = cache_get($cache_target_key);

            if (empty($session_code)) {
                ApiResponse::validationError('请先发送验证码');
            }
            if ($session_email != $email_new) {
                ApiResponse::validationError('邮箱与验证码不匹配');
            }
            if ($session_code != $email_code) {
                ApiResponse::validationError('验证码不正确');
            }

            $exists = user_read_by_email($email_new);
            if (!empty($exists) && $exists['uid'] != $uid) {
                ApiResponse::validationError('email_is_in_use');
            }

            $r = user_update($uid, array('email' => $email_new));
            if ($r === FALSE) {
                ApiResponse::error(500, 'modify_failed');
            }

            // 清理验证码缓存
            cache_delete($cache_code_key);
            cache_delete($cache_target_key);

            ApiResponse::success(null, 'modify_successfully');

        } elseif ($method === 'POST' && $subAction === 'send-code') {
            $email = param('email', '');

            if (empty($email)) {
                ApiResponse::validationError('please_input_email');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                ApiResponse::validationError('email_format_mismatch');
            }

            // 邮箱域名白名单检查
            if (!class_exists('SecurityConfigService')) {
                include_once APP_PATH . 'lib/security/SecurityConfigService.php';
            }
            $allowed_domains = SecurityConfigService::get('security_allowed_email_domains', '');
            if (!empty($allowed_domains)) {
                $email_domain = strtolower(substr(strrchr($email, '@'), 1));
                $allowed_list = array_map('trim', explode(',', strtolower($allowed_domains)));
                $allowed_list = array_filter($allowed_list);
                if (!empty($allowed_list) && !in_array($email_domain, $allowed_list)) {
                    ApiResponse::validationError('该邮箱域名不允许使用，仅支持：' . implode('、', $allowed_list));
                }
            }

            if ($email == $user['email']) {
                ApiResponse::validationError('email_same_as_current');
            }
            $exists = user_read_by_email($email);
            if (!empty($exists) && $exists['uid'] != $uid) {
                ApiResponse::validationError('email_is_in_use');
            }

            // 频率限制：60s/次（key 含 uid+email 防绕过）
            $interval = intval(SecurityConfigService::get('security_email_code_interval', 60));
            $rate_key = 'api_email_code_sent_' . $uid . '_' . md5($email);
            if (cache_get($rate_key)) {
                ApiResponse::tooManyRequests('验证码发送过于频繁，请 ' . $interval . ' 秒后再试');
            }

            // 加载邮件函数
            include_once XIUNOPHP_PATH . 'xn_send_mail.func.php';

            $code = random_int(100000, 999999);
            // 验证码缓存 10 分钟
            cache_set('api_email_code_' . $uid, $code, 600);
            cache_set('api_email_target_' . $uid, $email, 600);

            $template = xn_email_template('email_change_code', array('code' => $code, 'sitename' => $conf['sitename']));
            $subject = $template['subject'];
            $message = $template['body'];

            $smtp = xn_smtp_get();
            if (empty($smtp)) {
                ApiResponse::error(500, '邮件发送未配置，请联系管理员');
            }

            // SMTP 频率限制（xn 内置按邮箱+IP）
            $rate_check = xn_email_rate_check($email, $longip);
            if ($rate_check !== TRUE) {
                ApiResponse::tooManyRequests($rate_check);
            }

            // 频率记录：先记录防止绕过频率限制连续调用
            xn_email_rate_record($email, $longip);
            // 设置频率限制标记
            cache_set($rate_key, 1, $interval);

            // 伪异步发送：通过 register_shutdown_function 在响应返回客户端后再执行 SMTP 发送
            // ponytail: 异步模式无法获取返回值，邮件失败由 xn_send_mail() 内部写入 email_log 表
            xn_send_mail_async($smtp, $conf['sitename'], $email, $subject, $message, array('is_html' => TRUE));

            ApiResponse::success(array('wait' => $interval), 'send_code_successfully');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }
        break;

    // ========== GET /my/security ==========
    case 'security':
        if ($method !== 'GET') {
            ApiResponse::error(405, 'Method not allowed');
        }
        if (!class_exists('SecurityConfigService')) {
            include_once APP_PATH . 'lib/security/SecurityConfigService.php';
        }
        $security_config = SecurityConfigService::get_config();
        // 当前用户登录安全状态
        $user_security = array(
            'uid' => $uid,
            'gid' => $gid,
            'login_attempts' => isset($user['login_attempts']) ? intval($user['login_attempts']) : 0,
            'banned_until' => isset($user['banned_until']) ? intval($user['banned_until']) : 0,
            'last_login_time' => isset($user['last_login_time']) ? intval($user['last_login_time']) : 0,
            'create_date' => isset($user['create_date']) ? intval($user['create_date']) : 0,
        );
        ApiResponse::success(array(
            'config' => $security_config,
            'user_status' => $user_security,
        ));
        break;

    // ========== GET /my/credits/rules，POST /my/credits/check ==========
    case 'credits':
        if (!class_exists('CreditsRuleService')) {
            include_once APP_PATH . 'service/CreditsRuleService.php';
        }

        if ($subAction === 'rules' && $method === 'GET') {
            $fid = intval($_GET['fid'] ?? 0);
            if ($fid > 0) {
                $credits_rules = CreditsRuleService::getForumRules($fid);
            } else {
                $credits_rules = CreditsRuleService::getAllGlobalRules();
            }
            // 仅返回已启用的规则
            if (!empty($credits_rules)) {
                $credits_rules = array_filter($credits_rules, function($rule) {
                    return !empty($rule['enabled']);
                });
                $credits_rules = array_values($credits_rules);
            }
            ApiResponse::success(array(
                'rules' => $credits_rules,
                'fid' => $fid,
            ));

        } elseif ($subAction === 'check' && $method === 'POST') {
            // 积分消耗预检查
            // action 参数：post/reply/like/favorite → 对应 credits event
            $action_input = param('action', '');
            $fid = intval(param('fid', 0));

            // action 到 credits event 的映射
            $event_map = array(
                'post'     => 'thread_create',
                'reply'    => 'post_create',
                'like'     => 'like',
                'favorite' => 'favorite',
            );
            if (!isset($event_map[$action_input])) {
                ApiResponse::validationError('不支持的 action：' . $action_input);
            }
            $event = $event_map[$action_input];

            $result = CreditsRuleService::applyRule($event, $uid, $fid, true);

            // 附带用户当前余额
            $fresh_user = user_read_cache($uid);
            if (!empty($fresh_user)) {
                $result['balances'] = array(
                    'credits' => intval($fresh_user['credits'] ?? 0),
                    'golds'   => intval($fresh_user['golds'] ?? 0),
                    'rmbs'    => intval($fresh_user['rmbs'] ?? 0),
                );
            }

            ApiResponse::success(array(
                'sufficient'   => !empty($result['ok']),
                'event'        => $event,
                'action'       => $action_input,
                'fid'          => $fid,
                'deduct_desc'  => isset($result['deduct_desc']) ? $result['deduct_desc'] : '',
                'balances'     => isset($result['balances']) ? $result['balances'] : null,
                'message'      => isset($result['message']) ? $result['message'] : '',
                'daily_limit_reached' => !empty($result['daily_limit_reached']),
            ));

        } else {
            ApiResponse::notFound('Endpoint not found');
        }
        break;

    // ========== GET /my/likes ==========
    case 'likes':
        if ($method !== 'GET') {
            ApiResponse::error(405, 'Method not allowed');
        }
        $page = max(1, intval($_GET['page'] ?? 1));
        $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

        // 按帖子去重查询点赞列表，JOIN thread 表过滤已删除帖子
        global $db;
        $tablepre = $db->tablepre;
        $offset = ($page - 1) * $pagesize;
        // 联表查询，db_find 不支持 JOIN，保留 db_sql_find
        $sql = "SELECT pl.tid, MAX(pl.create_date) AS last_like_time FROM {$tablepre}post_like pl INNER JOIN {$tablepre}thread t ON pl.tid=t.tid WHERE pl.uid='" . intval($uid) . "' GROUP BY pl.tid ORDER BY last_like_time DESC LIMIT " . intval($offset) . ", " . intval($pagesize);
        $tid_rows = db_sql_find($sql);
        $threadlist = array();
        if ($tid_rows) {
            $like_tids = array_column($tid_rows, 'tid');
            $threadlist = thread_find_by_tids($like_tids);
            // 转为索引数组
            $threadlist = array_values($threadlist);
        }
        // 去重后的总数，保留 db_sql_find_one
        $total_row = db_sql_find_one("SELECT COUNT(DISTINCT pl.tid) AS cnt FROM {$tablepre}post_like pl INNER JOIN {$tablepre}thread t ON pl.tid=t.tid WHERE pl.uid='" . intval($uid) . "'");
        $total = !empty($total_row['cnt']) ? intval($total_row['cnt']) : 0;

        ApiResponse::success(my_paginate($threadlist, $page, $pagesize, $total));
        break;

    // ========== GET /my/feed ==========
    case 'feed':
        if ($method !== 'GET') {
            ApiResponse::error(405, 'Method not allowed');
        }
        // 复用 FeedsService（plugin/xnx_feeds/），插件未启用时返回空
        // ponytail: 插件启用状态唯一权威源为 db bbs_plugin 表，禁止读 conf.json 的 enable/installed
        // （conf.json 彻底废弃，代码层任何情况下都不读；存量 conf.json 带 enable=1/installed=1 是脏数据会导致已禁用插件被误判启用）
        $feed_enabled = false;
        $feeds = array();
        if (is_file(APP_PATH . 'plugin/xnx_feeds/model/FeedsService.php')) {
            if (!function_exists('plugin_db_get')) {
                include APP_PATH . 'model/plugin.func.php';
            }
            // 直接查 db 判断插件启用状态（db 异常时返回空数组，默认未启用）
            $plugin_row = plugin_db_get('xnx_feeds');
            $xnx_feeds_enabled = !empty($plugin_row['installed']) && !empty($plugin_row['enable']);
            if ($xnx_feeds_enabled) {
                if (!class_exists('FeedsService')) {
                    include_once APP_PATH . 'plugin/xnx_feeds/model/FeedsService.php';
                }
                $feed_enabled = true;
            }
        }

        if ($feed_enabled) {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(50, max(1, intval($_GET['pagesize'] ?? 20)));
            // FeedsService::getList 不支持分页，按需查询
            $limit = $page * $pagesize;
            $all = FeedsService::getList($limit);
            $total = count($all);
            $list = array_slice($all, ($page - 1) * $pagesize, $pagesize);
            ApiResponse::success(my_paginate($list, $page, $pagesize, $total));
        } else {
            // 插件未启用，返回空
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(50, max(1, intval($_GET['pagesize'] ?? 20)));
            ApiResponse::success(my_paginate(array(), $page, $pagesize, 0));
        }
        break;

    // ========== GET /my/follow-users ==========
    case 'follow-users':
        if ($method !== 'GET') {
            ApiResponse::error(405, 'Method not allowed');
        }
        // 用于编辑器 @提及，返回最多 50 个关注用户
        $followlist = user_follow_find_following($uid, 1, 50);
        $users = array();
        if ($followlist) {
            // 批量查询用户数据，避免循环内 user_read_cache 的 N+1 查询
            $follow_uids = arrlist_values($followlist, 'follow_uid');
            $userlist = user_find(array('uid' => $follow_uids), array(), 1, count($follow_uids));
            if ($userlist) {
                // 以 uid 为 key 重新组织
                $user_map = array();
                foreach ($userlist as $u) {
                    $user_map[$u['uid']] = $u;
                }
                foreach ($followlist as $f) {
                    $_uid = $f['follow_uid'];
                    if (isset($user_map[$_uid])) {
                        $u = $user_map[$_uid];
                        $users[] = array(
                            'uid' => intval($u['uid']),
                            'username' => $u['display_name'] ?? $u['username'],
                            'nickname' => isset($u['nickname']) ? $u['nickname'] : '',
                            'avatar_url' => !empty($u['avatar_url']) ? $u['avatar_url'] : api_my_avatar_url($u),
                        );
                    }
                }
            }
        }
        ApiResponse::success(array('list' => $users, 'total' => count($users)));
        break;

    // ========== GET /my/notify/dropdown ==========
    case 'notify':
        if ($subAction !== 'dropdown' || $method !== 'GET') {
            ApiResponse::notFound('Endpoint not found');
        }
        // 未读数 + 最近 N 条通知（默认 5 条）
        $limit = max(1, min(20, intval($_GET['limit'] ?? 5)));
        $unread = notify_count_unread($uid);
        $notifylist = notify_find_latest($uid, $limit);

        $list = array();
        if (!empty($notifylist)) {
            foreach ($notifylist as $n) {
                $list[] = array(
                    'nid' => intval($n['nid']),
                    'type' => isset($n['type']) ? $n['type'] : '',
                    'type_label' => isset($n['type_label']) ? $n['type_label'] : '',
                    'is_read' => empty($n['is_read']) ? 0 : 1,
                    'from_uid' => isset($n['from_uid']) ? intval($n['from_uid']) : 0,
                    'from_username' => isset($n['from_username']) ? $n['from_username'] : '',
                    'from_avatar_url' => isset($n['from_avatar_url']) ? $n['from_avatar_url'] : default_avatar_url(),
                    'message' => isset($n['message']) ? $n['message'] : '',
                    'summary' => isset($n['summary']) ? $n['summary'] : '',
                    'url' => isset($n['url']) ? $n['url'] : '',
                    'tid' => isset($n['tid']) ? intval($n['tid']) : 0,
                    'pid' => isset($n['pid']) ? intval($n['pid']) : 0,
                    'create_date' => isset($n['create_date']) ? intval($n['create_date']) : 0,
                    'create_date_fmt' => isset($n['create_date_fmt']) ? $n['create_date_fmt'] : '',
                );
            }
        }

        ApiResponse::success(array(
            'unread_count' => intval($unread),
            'list' => $list,
        ));
        break;

    default:
        ApiResponse::notFound('Endpoint not found');
}
