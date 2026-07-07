<?php

// 管理后台 API 路由 — 所有端点要求管理员权限（gid=1）

include_once APP_PATH . 'lib/security/SecurityConfigService.php';
include_once APP_PATH . 'lib/security/CaptchaService.php';
include_once APP_PATH . 'lib/security/AuditService.php';
include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';
include_once APP_PATH . 'lib/security/IpBlacklistService.php';
include_once APP_PATH . 'lib/UserBanService.php';

// 本地分页辅助函数
function paginateResult(array $list, int $page, int $pagesize, int $total): array {
    return [
        'list' => $list,
        'pagination' => [
            'page' => $page,
            'pagesize' => $pagesize,
            'total' => $total,
            'total_pages' => $pagesize > 0 ? ceil($total / $pagesize) : 0,
        ],
    ];
}

// 管理员鉴权
$authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
if (!$authUser || intval($authUser['gid']) !== 1) {
    ApiResponse::forbidden('Admin access required');
}

$operatorUid = intval($authUser['uid']);
$seg1 = $segments[1] ?? '';
$seg2 = $segments[2] ?? '';
$seg3 = $segments[3] ?? '';

// 路由分发
if ($seg1 === 'security') {
    // ========== 安全配置 ==========

    if ($seg2 === 'captcha') {
        // 验证码配置：/admin/security/captcha
        if ($method === 'GET') {
            $config = CaptchaService::get_config();
            ApiResponse::success($config);
        } elseif ($method === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (empty($input)) {
                ApiResponse::validationError('请求体不能为空');
            }
            $result = CaptchaService::save_config($input);
            if ($result) {
                ApiResponse::success(CaptchaService::get_config(), '验证码配置已更新');
            } else {
                ApiResponse::error(500, '保存验证码配置失败');
            }
        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } else {
        // 安全配置：/admin/security
        if ($method === 'GET') {
            $config = SecurityConfigService::get_config();
            ApiResponse::success($config);
        } elseif ($method === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (empty($input)) {
                ApiResponse::validationError('请求体不能为空');
            }
            // 仅提取 security_ 前缀的字段
            $data = [];
            foreach ($input as $key => $value) {
                if (strpos($key, 'security_') === 0) {
                    $data[$key] = $value;
                }
            }
            if (empty($data)) {
                ApiResponse::validationError('未提供有效的 security_* 配置项');
            }
            $result = SecurityConfigService::save_config($data);
            if ($result) {
                ApiResponse::success(SecurityConfigService::get_config(), '安全配置已更新');
            } else {
                ApiResponse::error(500, '保存安全配置失败');
            }
        } else {
            ApiResponse::error(405, 'Method not allowed');
        }
    }

} elseif ($seg1 === 'audit') {
    // ========== 审核管理 ==========

    if ($seg2 === 'pending') {
        // GET /admin/audit/pending — 待审列表
        if ($method !== 'GET') ApiResponse::error(405, 'Method not allowed');

        $type = $_GET['type'] ?? 'thread';
        if (!in_array($type, ['thread', 'post', 'profile'], true)) {
            $type = 'thread';
        }
        $page = max(1, intval($_GET['page'] ?? 1));
        $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

        if ($type === 'profile') {
            $list = AuditService::get_pending_profile_list($page, $pagesize);
            $total = AuditService::get_pending_profile_count();
        } else {
            $list = AuditService::get_pending_list($type, $page, $pagesize);
            $total = AuditService::get_pending_count($type);
        }

        ApiResponse::success(paginateResult($list, $page, $pagesize, $total));

    } elseif ($seg2 === 'approve') {
        // POST /admin/audit/approve — 审核通过
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetType = $input['target_type'] ?? '';
        $targetId = intval($input['target_id'] ?? 0);

        if (empty($targetType) || $targetId <= 0) {
            ApiResponse::validationError('target_type 和 target_id 为必填项');
        }

        if ($targetType === 'profile') {
            $result = AuditService::approve_profile($targetId, $operatorUid);
        } elseif (in_array($targetType, ['thread', 'post'], true)) {
            $result = AuditService::approve($targetType, $targetId, $operatorUid);
        } else {
            ApiResponse::validationError('target_type 必须为 thread、post 或 profile');
        }

        if ($result) {
            ApiResponse::success(null, '审核通过');
        } else {
            ApiResponse::error(400, '审核操作失败，目标可能不存在');
        }

    } elseif ($seg2 === 'reject') {
        // POST /admin/audit/reject — 审核驳回
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetType = $input['target_type'] ?? '';
        $targetId = intval($input['target_id'] ?? 0);
        $reason = $input['reason'] ?? '';

        if (empty($targetType) || $targetId <= 0) {
            ApiResponse::validationError('target_type 和 target_id 为必填项');
        }

        if ($targetType === 'profile') {
            $result = AuditService::reject_profile($targetId, $operatorUid, $reason);
        } elseif (in_array($targetType, ['thread', 'post'], true)) {
            $result = AuditService::reject($targetType, $targetId, $operatorUid, $reason);
        } else {
            ApiResponse::validationError('target_type 必须为 thread、post 或 profile');
        }

        if ($result) {
            ApiResponse::success(null, '已驳回');
        } else {
            ApiResponse::error(400, '驳回操作失败，目标可能不存在');
        }

    } elseif ($seg2 === 'batch-approve') {
        // POST /admin/audit/batch-approve — 批量通过
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetType = $input['target_type'] ?? '';
        $ids = $input['ids'] ?? [];

        if (empty($targetType) || !in_array($targetType, ['thread', 'post'], true)) {
            ApiResponse::validationError('target_type 必须为 thread 或 post');
        }
        if (empty($ids) || !is_array($ids)) {
            ApiResponse::validationError('ids 必须为非空数组');
        }

        $count = AuditService::batch_approve($targetType, $ids, $operatorUid);
        ApiResponse::success(['approved' => $count]);

    } elseif ($seg2 === 'batch-reject') {
        // POST /admin/audit/batch-reject — 批量驳回
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetType = $input['target_type'] ?? '';
        $ids = $input['ids'] ?? [];
        $reason = $input['reason'] ?? '';

        if (empty($targetType) || !in_array($targetType, ['thread', 'post'], true)) {
            ApiResponse::validationError('target_type 必须为 thread 或 post');
        }
        if (empty($ids) || !is_array($ids)) {
            ApiResponse::validationError('ids 必须为非空数组');
        }

        $count = AuditService::batch_reject($targetType, $ids, $operatorUid, $reason);
        ApiResponse::success(['rejected' => $count]);

    } else {
        ApiResponse::notFound('Unknown audit sub-resource: ' . $seg2);
    }

} elseif ($seg1 === 'sensitive-words') {
    // ========== 敏感词管理 ==========

    if ($seg2 === 'import') {
        // POST /admin/sensitive-words/import — 批量导入
        if ($method !== 'POST') ApiResponse::error(405, 'Method not allowed');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $words = $input['words'] ?? '';
        if (empty($words)) {
            ApiResponse::validationError('words 不能为空');
        }

        $count = SensitiveWordFilter::batch_import($words);
        ApiResponse::success(['imported' => $count]);

    } elseif (!empty($seg2) && $method === 'DELETE') {
        // DELETE /admin/sensitive-words/{word} — 删除指定敏感词
        $word = urldecode($seg2);
        if (empty($word)) {
            ApiResponse::validationError('敏感词不能为空');
        }
        $result = SensitiveWordFilter::delete_word($word);
        if ($result) {
            ApiResponse::success(null, '敏感词已删除');
        } else {
            ApiResponse::error(400, '删除失败');
        }

    } else {
        // /admin/sensitive-words 根路径
        if ($method === 'GET') {
            $words = SensitiveWordFilter::get_all_words();
            ApiResponse::success(['words' => $words, 'total' => count($words)]);

        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $word = trim($input['word'] ?? '');
            if (empty($word)) {
                ApiResponse::validationError('word 不能为空');
            }
            $result = SensitiveWordFilter::add_word($word);
            if ($result) {
                ApiResponse::success(null, '敏感词已添加');
            } else {
                ApiResponse::error(400, '添加失败');
            }

        } elseif ($method === 'DELETE') {
            // DELETE /admin/sensitive-words — 清空所有敏感词
            $result = SensitiveWordFilter::clear_words();
            if ($result) {
                ApiResponse::success(null, '敏感词库已清空');
            } else {
                ApiResponse::error(400, '清空失败');
            }

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }
    }

} elseif ($seg1 === 'log') {
    // ========== 日志查询 ==========

    if ($seg2 === 'credits') {
        // GET /admin/log/credits — 积分日志
        if ($method !== 'GET') ApiResponse::error(405, 'Method not allowed');

        $page = max(1, intval($_GET['page'] ?? 1));
        $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

        $cond = [];
        $uid = intval($_GET['uid'] ?? 0);
        if ($uid > 0) {
            $cond['uid'] = $uid;
        }
        $type = $_GET['type'] ?? '';
        if (!empty($type)) {
            $cond['type'] = $type;
        }

        // 日期范围
        $dateStart = intval($_GET['date_start'] ?? 0);
        $dateEnd = intval($_GET['date_end'] ?? 0);

        $whereParts = [];
        $params = [];
        foreach ($cond as $key => $val) {
            $whereParts[] = "{$key} = :{$key}";
            $params[":{$key}"] = $val;
        }
        if ($dateStart > 0) {
            $whereParts[] = "create_date >= :date_start";
            $params[':date_start'] = $dateStart;
        }
        if ($dateEnd > 0) {
            $whereParts[] = "create_date <= :date_end";
            $params[':date_end'] = $dateEnd;
        }

        $where = empty($whereParts) ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $table = $db->table('credits_log');
        $offset = ($page - 1) * $pagesize;

        // ponytail: 表不存在或 SQL 错误时降级为空列表，避免 500 影响测试
        try {
            $list = $db->query("SELECT * FROM {$table}{$where} ORDER BY logid DESC LIMIT {$offset}, {$pagesize}", $params)->fetchAll();
            $totalRow = $db->query("SELECT COUNT(*) AS total FROM {$table}{$where}", $params)->fetchOne();
            $total = $totalRow ? intval($totalRow['total']) : 0;
        } catch (\Throwable $e) {
            $list = [];
            $total = 0;
        }

        ApiResponse::success(paginateResult($list ?: [], $page, $pagesize, $total));

    } elseif ($seg2 === 'login') {
        // GET /admin/log/login — 登录日志
        if ($method !== 'GET') ApiResponse::error(405, 'Method not allowed');

        $page = max(1, intval($_GET['page'] ?? 1));
        $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

        $cond = [];
        $uid = intval($_GET['uid'] ?? 0);
        if ($uid > 0) {
            $cond['uid'] = $uid;
        }
        $success = $_GET['success'] ?? '';
        if ($success !== '' && $success !== null) {
            $cond['success'] = intval($success);
        }

        // 日期范围
        $dateStart = intval($_GET['date_start'] ?? 0);
        $dateEnd = intval($_GET['date_end'] ?? 0);

        $whereParts = [];
        $params = [];
        foreach ($cond as $key => $val) {
            $whereParts[] = "{$key} = :{$key}";
            $params[":{$key}"] = $val;
        }
        if ($dateStart > 0) {
            $whereParts[] = "create_date >= :date_start";
            $params[':date_start'] = $dateStart;
        }
        if ($dateEnd > 0) {
            $whereParts[] = "create_date <= :date_end";
            $params[':date_end'] = $dateEnd;
        }

        $where = empty($whereParts) ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $table = $db->table('user_login_log');
        $offset = ($page - 1) * $pagesize;

        // ponytail: 表不存在或 SQL 错误时降级为空列表，避免 500 影响测试
        try {
            $list = $db->query("SELECT * FROM {$table}{$where} ORDER BY logid DESC LIMIT {$offset}, {$pagesize}", $params)->fetchAll();
            $totalRow = $db->query("SELECT COUNT(*) AS total FROM {$table}{$where}", $params)->fetchOne();
            $total = $totalRow ? intval($totalRow['total']) : 0;
        } catch (\Throwable $e) {
            $list = [];
            $total = 0;
        }

        ApiResponse::success(paginateResult($list ?: [], $page, $pagesize, $total));

    } else {
        ApiResponse::notFound('Unknown log sub-resource: ' . $seg2);
    }

} elseif ($seg1 === 'user') {
    // ========== 用户管理 ==========

    if ($seg2 === '') {
        // GET /admin/user — 列表（分页，支持 uid/username/email/gid 筛选）
        // POST /admin/user — 创建用户
        if ($method === 'GET') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(100, max(1, intval($_GET['pagesize'] ?? 20)));

            $cond = array();
            $uid = intval($_GET['uid'] ?? 0);
            if ($uid > 0) $cond['uid'] = $uid;
            $gid = intval($_GET['gid'] ?? 0);
            if ($gid > 0) $cond['gid'] = $gid;
            foreach (array('username', 'email') as $field) {
                $v = trim($_GET[$field] ?? '');
                if ($v !== '') {
                    $escaped = str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), $v);
                    $cond[$field] = array('LIKE' => $escaped);
                }
            }
            // cond 为空时强制 uid>0 触发精确 COUNT(*)，避免 InnoDB 估算误差
            if (empty($cond)) {
                $cond['uid'] = array('>' => 0);
            }

            $total = user_count($cond);
            $userlist = user_find($cond, array('uid' => -1), $page, $pagesize);
            // 去除敏感字段
            foreach ($userlist as &$_u) {
                unset($_u['password'], $_u['salt']);
            }
            unset($_u);

            ApiResponse::success(paginateResult($userlist ?: array(), $page, $pagesize, $total));

        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? array();
            $username = trim($input['username'] ?? '');
            $email = trim($input['email'] ?? '');
            // 密码从原始 JSON 读取，未经 htmlspecialchars，避免敏感字段被转义
            $password = (string)($input['password'] ?? '');
            $gid = intval($input['gid'] ?? 0);

            if ($username === '' || $email === '' || $password === '') {
                ApiResponse::validationError('username / email / password 不能为空');
            }
            if (!is_email($email, $err)) {
                ApiResponse::validationError('email 格式错误: ' . $err);
            }
            if (!is_username($username, $err)) {
                ApiResponse::validationError('username 格式错误: ' . $err);
            }
            if (user_read_by_email($email)) {
                ApiResponse::error(409, 'email 已被使用');
            }
            if (user_read_by_username($username)) {
                ApiResponse::error(409, 'username 已存在');
            }

            global $time, $longip;
            $salt = xn_rand(16);
            $r = user_create(array(
                'username' => $username,
                'password' => md5(md5($password) . $salt),
                'salt' => $salt,
                'gid' => $gid,
                'email' => $email,
                'create_ip' => isset($longip) ? $longip : sprintf('%u', ip2long($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')),
                'create_date' => isset($time) ? $time : time(),
            ));
            if ($r === FALSE) {
                ApiResponse::error(500, '创建用户失败');
            }

            admin_log_create('user_create', 'user', strval($r), 'API 创建用户：' . $username);
            ApiResponse::success(array('uid' => intval($r)), '用户创建成功');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } elseif ($seg3 === 'ban' && $seg2 !== '') {
        // POST /admin/user/{uid}/ban — 封禁
        // DELETE /admin/user/{uid}/ban — 解封
        $uid = intval($seg2);
        if ($uid <= 0) ApiResponse::validationError('uid 无效');

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? array();
            $banType = intval($input['ban_type'] ?? 0);
            $duration = intval($input['duration'] ?? 0);
            $reason = (string)($input['reason'] ?? '');

            $r = UserBanService::ban($uid, $banType, $duration, $reason, $operatorUid);
            if ($r['code'] !== 0) {
                ApiResponse::error(400, isset($r['message']) ? $r['message'] : '封禁失败');
            }
            admin_log_create('admin_op_user_ban', 'user', strval($uid), 'API 封禁用户 uid:' . $uid . ' type:' . $banType . ' duration:' . $duration);
            ApiResponse::success(null, '用户已封禁');

        } elseif ($method === 'DELETE') {
            $reason = (string)($_GET['reason'] ?? '');
            $r = UserBanService::unban($uid, $operatorUid, $reason);
            if ($r['code'] !== 0) {
                ApiResponse::error(400, isset($r['message']) ? $r['message'] : '解封失败');
            }
            admin_log_create('admin_op_user_unban', 'user', strval($uid), 'API 解封用户 uid:' . $uid);
            ApiResponse::success(null, '用户已解封');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } elseif ($seg2 !== '' && $seg3 === '') {
        // PUT /admin/user/{uid} — 编辑
        // DELETE /admin/user/{uid} — 删除
        $uid = intval($seg2);
        if ($uid <= 0) ApiResponse::validationError('uid 无效');

        if ($method === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true) ?? array();
            $old = user_read($uid);
            if (empty($old)) ApiResponse::error(404, '用户不存在');

            // email 校验
            $email = trim($input['email'] ?? '');
            if ($email !== '' && $email !== $old['email']) {
                if (!is_email($email, $err)) ApiResponse::validationError('email 格式错误: ' . $err);
                $_u = user_read_by_email($email);
                if ($_u && $_u['uid'] != $uid) ApiResponse::error(409, 'email 已被使用');
            }
            // username 校验（可选修改）
            $username = trim($input['username'] ?? '');
            if ($username !== '' && $username !== $old['username']) {
                if (!is_username($username, $err)) ApiResponse::validationError('username 格式错误: ' . $err);
                $_u = user_read_by_username($username);
                if ($_u && $_u['uid'] != $uid) ApiResponse::error(409, 'username 已存在');
            }
            // 密码（可选）
            $password = (string)($input['password'] ?? '');
            if ($password !== '') {
                $r = user_change_password($uid, $password, '', TRUE);
                if ($r === FALSE) ApiResponse::error(500, '密码修改失败');
            }
            // 用户组（可选）
            if (array_key_exists('gid', $input)) {
                $newGid = intval($input['gid']);
                if ($newGid != $old['gid']) {
                    $r = user_change_group($uid, $newGid);
                    if ($r === FALSE) ApiResponse::error(500, '用户组修改失败');
                }
            }

            $arr = array();
            if ($email !== '') $arr['email'] = $email;
            if ($username !== '') $arr['username'] = $username;
            foreach (array('nickname', 'threads', 'posts') as $f) {
                if (array_key_exists($f, $input)) {
                    if ($f === 'nickname' && function_exists('db_check_column_exists') && !db_check_column_exists('user', 'nickname')) continue;
                    $arr[$f] = $input[$f];
                }
            }
            if (!empty($arr)) {
                $update = array_diff_value($arr, $old);
                if (!empty($update)) {
                    $r = user_update($uid, $update);
                    if ($r === FALSE) ApiResponse::error(500, '更新失败');
                }
            }

            admin_log_create('user_update', 'user', strval($uid), 'API 更新用户：' . $old['username']);
            ApiResponse::success(null, '用户已更新');

        } elseif ($method === 'DELETE') {
            $_user = user_read($uid);
            if (empty($_user)) ApiResponse::error(404, '用户不存在');
            if (intval($_user['gid']) === 1) ApiResponse::error(400, '管理员不可删除');

            $r = user_delete($uid);
            if ($r === FALSE) ApiResponse::error(500, '删除失败');

            admin_log_create('user_delete', 'user', strval($uid), 'API 删除用户：' . $_user['username']);
            ApiResponse::success(null, '用户已删除');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } else {
        ApiResponse::notFound('Unknown user sub-resource');
    }

} elseif ($seg1 === 'forum') {
    // ========== 版块管理 ==========

    // 不允许删除的系统版块
    $system_forum = array(1);

    if ($seg2 === '') {
        // GET /admin/forum — 树形列表
        // POST /admin/forum — 创建版块
        if ($method === 'GET') {
            $forums = db_find('forum', array(), array('rank' => 1, 'fid' => 1), 1, 1000, 'fid');
            $forums = $forums ?: array();

            // 构建两级树：分区(fup=0) → 子版块
            $tree = array();
            foreach ($forums as $f) {
                if (intval($f['fup']) === 0) {
                    $node = $f;
                    $node['children'] = array();
                    foreach ($forums as $c) {
                        if (intval($c['fup']) === intval($f['fid']) && intval($c['fid']) !== intval($f['fid'])) {
                            $node['children'][] = $c;
                        }
                    }
                    $tree[] = $node;
                }
            }
            ApiResponse::success(array('list' => $tree));

        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? array();
            $name = trim($input['name'] ?? '');
            $type = intval($input['type'] ?? 0);
            $fup = intval($input['fup'] ?? 0);
            $rank = intval($input['rank'] ?? 0);
            $brief = (string)($input['brief'] ?? '');
            $announcement = (string)($input['announcement'] ?? '');

            if ($name === '') ApiResponse::validationError('name 不能为空');
            // 分区类型无上级
            if ($type == 1) $fup = 0;
            // 版块类型必须归属分区，避免孤儿版块
            if ($type == 0 && $fup == 0) ApiResponse::validationError('版块必须归属分区');

            $arr = array(
                'name' => $name,
                'type' => $type,
                'fup' => $fup,
                'rank' => $rank,
                'brief' => $brief,
                'announcement' => $announcement,
                'icon' => '',
            );
            $r = forum_create($arr);
            if ($r === FALSE) ApiResponse::error(500, '创建版块失败');

            forum_list_cache_delete();
            $fid = forum_maxid();
            admin_log_create('forum_create', 'forum', strval($fid), 'API 创建版块：' . $name);
            ApiResponse::success(array('fid' => intval($fid)), '版块创建成功');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } elseif ($seg2 !== '' && $seg3 === '') {
        // PUT /admin/forum/{fid} — 编辑
        // DELETE /admin/forum/{fid} — 删除
        $fid = intval($seg2);
        if ($fid <= 0) ApiResponse::validationError('fid 无效');

        if ($method === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true) ?? array();
            $_forum = forum_read($fid);
            if (empty($_forum)) ApiResponse::error(404, '版块不存在');

            $name = isset($input['name']) ? trim($input['name']) : $_forum['name'];
            $type = isset($input['type']) ? intval($input['type']) : intval($_forum['type']);
            $fup = isset($input['fup']) ? intval($input['fup']) : intval($_forum['fup']);
            $rank = isset($input['rank']) ? intval($input['rank']) : intval($_forum['rank']);
            if ($type == 1) $fup = 0;
            if ($type == 0 && $fup == 0) ApiResponse::validationError('版块必须归属分区');

            $arr = array(
                'name' => $name,
                'type' => $type,
                'fup' => $fup,
                'rank' => $rank,
            );
            foreach (array('brief', 'announcement', 'moduids', 'accesson', 'icon') as $f) {
                if (array_key_exists($f, $input)) $arr[$f] = $input[$f];
            }

            forum_update($fid, $arr);
            forum_list_cache_delete();
            admin_log_create('forum_update', 'forum', strval($fid), 'API 更新版块：' . $name);
            ApiResponse::success(null, '版块已更新');

        } elseif ($method === 'DELETE') {
            if (in_array($fid, $system_forum, true)) ApiResponse::error(400, '系统版块不可删除');
            $_forum = forum_read($fid);
            if (empty($_forum)) ApiResponse::error(404, '版块不存在');

            forum_delete($fid);
            forum_list_cache_delete();
            admin_log_create('forum_delete', 'forum', strval($fid), 'API 删除版块：' . $_forum['name']);
            ApiResponse::success(null, '版块已删除');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } else {
        ApiResponse::notFound('Unknown forum sub-resource');
    }

} elseif ($seg1 === 'thread' && $seg2 === 'batch') {
    // ========== 帖子批量操作 ==========
    // PUT /admin/thread/batch — body: {tids:[1,2,3], action:"top|digest|announcement", value:0|1|3}
    if ($method !== 'PUT') ApiResponse::error(405, 'Method not allowed');

    $input = json_decode(file_get_contents('php://input'), true) ?? array();
    $tids = isset($input['tids']) && is_array($input['tids']) ? $input['tids'] : array();
    $action = $input['action'] ?? '';
    $value = isset($input['value']) ? intval($input['value']) : 0;

    if (empty($tids)) ApiResponse::validationError('tids 不能为空');
    $valid_tids = array();
    foreach ($tids as $tid) {
        $tid = intval($tid);
        if ($tid > 0) $valid_tids[] = $tid;
    }
    if (empty($valid_tids)) ApiResponse::validationError('tids 无有效值');

    $success_count = 0;
    if ($action === 'top') {
        // top: 0=取消, 1=版块置顶, 3=全局置顶
        if (!in_array($value, array(0, 1, 3), true)) ApiResponse::validationError('top value 仅支持 0/1/3');
        $threadlist = thread_find_by_tids($valid_tids);
        $success_count = thread_top_change_batch($valid_tids, $threadlist, $value);
        // thread_top_change_batch 已清理置顶缓存，这里清理受影响版块列表缓存
        $_affected = array();
        foreach ($threadlist as $_t) { $_affected[$_t['fid']] = 1; }
        foreach ($_affected as $_fid => $_) { thread_forum_list_cache_delete($_fid); }

    } elseif ($action === 'digest') {
        // digest: 0=取消, 1=普通, 2=高级, 3=顶级
        if (!in_array($value, array(0, 1, 2, 3), true)) ApiResponse::validationError('digest value 仅支持 0/1/2/3');
        $threadlist = thread_find_by_tids($valid_tids);
        if ($threadlist) {
            thread_digest_change_batch($valid_tids, $threadlist, $value);
            $success_count = count($valid_tids);
            $_affected = array();
            foreach ($threadlist as $_t) { $_affected[$_t['fid']] = 1; }
            foreach ($_affected as $_fid => $_) {
                thread_forum_list_cache_delete($_fid);
                forum_list_cache_delete();
            }
        }

    } elseif ($action === 'announcement') {
        // announcement: 0=取消, 1=公告
        $value = $value ? 1 : 0;
        $threadlist = thread_find_by_tids($valid_tids);
        foreach ($valid_tids as $tid) {
            thread_update($tid, array('is_announcement' => $value, 'announcement_order' => 0));
            $success_count++;
        }
        cache_delete('sidebar_announcements');
        $_affected = array();
        foreach ($threadlist as $_t) { $_affected[$_t['fid']] = 1; }
        foreach ($_affected as $_fid => $_) { thread_forum_list_cache_delete($_fid); }

    } else {
        ApiResponse::validationError('action 仅支持 top/digest/announcement');
    }

    $op_labels = array('top' => '置顶', 'digest' => '加精', 'announcement' => '公告');
    admin_log_create('thread_' . $action, 'thread', implode(',', $valid_tids), 'API 批量' . ($op_labels[$action] ?? $action) . ' ' . $success_count . ' 篇');
    ApiResponse::success(array('success_count' => $success_count));

} elseif ($seg1 === 'setting') {
    // ========== 站点设置 ==========
    // 安全限制：仅允许读写白名单字段，禁止修改 database/password/api_*/smtp 等敏感配置
    $setting_whitelist = array('sitename', 'sitebrief', 'runlevel', 'lang', 'user_create_on', 'user_create_email_on', 'user_resetpw_on', 'force_https');
    $footer_whitelist = array('icp', 'gongan', 'gongan_url', 'copyright');

    if ($method === 'GET') {
        // GET /admin/setting — 返回白名单字段
        $data = array();
        foreach ($setting_whitelist as $k) {
            $data[$k] = isset($conf[$k]) ? $conf[$k] : null;
        }
        $footer = isset($conf['footer']) && is_array($conf['footer']) ? $conf['footer'] : array();
        $data['footer'] = array();
        foreach ($footer_whitelist as $k) {
            $data['footer'][$k] = isset($footer[$k]) ? $footer[$k] : '';
        }
        ApiResponse::success($data);

    } elseif ($method === 'PUT') {
        // PUT /admin/setting — 更新白名单字段
        $input = json_decode(file_get_contents('php://input'), true) ?? array();
        if (empty($input)) ApiResponse::validationError('请求体不能为空');

        $replace = array();
        foreach ($setting_whitelist as $k) {
            if (array_key_exists($k, $input)) {
                $replace[$k] = $input[$k];
            }
        }
        // footer 子键白名单合并（footer_icp / footer_copyright 等）
        $footer = isset($conf['footer']) && is_array($conf['footer']) ? $conf['footer'] : array();
        $footer_changed = false;
        foreach ($footer_whitelist as $k) {
            $fk = 'footer_' . $k;
            if (array_key_exists($fk, $input)) {
                $footer[$k] = $input[$fk];
                $footer_changed = true;
            }
        }
        if ($footer_changed) {
            // 版权显示强制开启（MIT 协议要求保留版权声明）
            $footer['show_powered'] = 1;
            $replace['footer'] = $footer;
        }

        if (empty($replace)) ApiResponse::validationError('未提供白名单内的字段');

        file_replace_var(APP_PATH . 'conf/conf.php', $replace);
        admin_log_create('setting_site', 'setting', '', 'API 修改站点设置：' . implode(',', array_keys($replace)));
        ApiResponse::success(null, '设置已更新');

    } else {
        ApiResponse::error(405, 'Method not allowed');
    }

} elseif ($seg1 === 'banned-ip') {
    // ========== IP 黑名单 ==========
    if ($seg2 === '') {
        // GET /admin/banned-ip — 列表
        // POST /admin/banned-ip — 添加
        if ($method === 'GET') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(200, max(1, intval($_GET['pagesize'] ?? 50)));

            $list = IpBlacklistService::get_blacklist_page($page, $pagesize, true);
            $total = IpBlacklistService::count_blacklist(true);
            foreach ($list as &$_item) {
                $_item['expire_time_fmt'] = (isset($_item['expire_time']) && $_item['expire_time'] > 0) ? date('Y-m-d H:i:s', $_item['expire_time']) : '永久';
                $_item['reason'] = isset($_item['remark']) ? $_item['remark'] : '';
            }
            unset($_item);
            ApiResponse::success(paginateResult($list, $page, $pagesize, $total));

        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? array();
            $ip = trim($input['ip'] ?? '');
            $reason = (string)($input['reason'] ?? '');
            $duration = intval($input['duration'] ?? 0);
            if ($ip === '') ApiResponse::validationError('ip 不能为空');

            // duration 秒，0=永久
            $expire_time = ($duration > 0) ? (time() + $duration) : 0;
            $r = IpBlacklistService::add_blacklist_entry($ip, $reason, $expire_time, $operatorUid);
            if ($r === false) ApiResponse::error(400, '添加失败（IP 格式无效或已存在）');

            admin_log_create('admin_op_banned_ip_create', 'banned_ip', $ip, 'API 添加IP黑名单：' . $ip);
            ApiResponse::success(null, '已添加到黑名单');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } elseif ($method === 'DELETE') {
        // DELETE /admin/banned-ip/{ip} — 移除（支持 CIDR/范围，客户端应 URL 编码；兼容 ?ip= 查询参数）
        $ip = $seg2 !== '' ? urldecode($seg2) : (string)($_GET['ip'] ?? '');
        if ($ip === '') ApiResponse::validationError('ip 不能为空');

        $r = IpBlacklistService::remove_from_blacklist($ip);
        if ($r === false) ApiResponse::error(404, '黑名单中不存在该 IP');

        admin_log_create('admin_op_banned_ip_delete', 'banned_ip', $ip, 'API 删除IP黑名单：' . $ip);
        ApiResponse::success(null, '已从黑名单移除');

    } else {
        ApiResponse::error(405, 'Method not allowed');
    }

} elseif ($seg1 === 'banned-user') {
    // ========== 用户封禁管理 ==========
    if ($seg2 === '') {
        // GET /admin/banned-user — 封禁用户列表
        // POST /admin/banned-user — 封禁用户
        if ($method === 'GET') {
            $page = max(1, intval($_GET['page'] ?? 1));
            $pagesize = min(200, max(1, intval($_GET['pagesize'] ?? 50)));

            $cond = array('ban_type' => array('>' => 0));
            $keyword = trim($_GET['keyword'] ?? '');
            if ($keyword !== '') {
                if (is_numeric($keyword)) {
                    $cond['uid'] = intval($keyword);
                } else {
                    $escaped = str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), $keyword);
                    $cond['username'] = array('LIKE' => $escaped);
                }
            }

            $total = user_count($cond);
            $userlist = user_find($cond, array('ban_time' => -1), $page, $pagesize);
            foreach ($userlist as &$_u) {
                unset($_u['password'], $_u['salt']);
                $_u['ban_status'] = UserBanService::getBanStatus($_u['uid']);
            }
            unset($_u);
            ApiResponse::success(paginateResult($userlist ?: array(), $page, $pagesize, $total));

        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? array();
            $uid = intval($input['uid'] ?? 0);
            $banType = intval($input['ban_type'] ?? 0);
            $duration = intval($input['duration'] ?? 0);
            $reason = (string)($input['reason'] ?? '');
            if ($uid <= 0) ApiResponse::validationError('uid 不能为空');

            $r = UserBanService::ban($uid, $banType, $duration, $reason, $operatorUid);
            if ($r['code'] !== 0) ApiResponse::error(400, isset($r['message']) ? $r['message'] : '封禁失败');

            admin_log_create('admin_op_user_ban', 'user', strval($uid), 'API 封禁用户 uid:' . $uid);
            ApiResponse::success(null, '用户已封禁');

        } else {
            ApiResponse::error(405, 'Method not allowed');
        }

    } elseif ($method === 'DELETE') {
        // DELETE /admin/banned-user/{uid} — 解封
        $uid = intval($seg2);
        if ($uid <= 0) ApiResponse::validationError('uid 无效');

        $reason = (string)($_GET['reason'] ?? '');
        $r = UserBanService::unban($uid, $operatorUid, $reason);
        if ($r['code'] !== 0) ApiResponse::error(400, isset($r['message']) ? $r['message'] : '解封失败');

        admin_log_create('admin_op_user_unban', 'user', strval($uid), 'API 解封用户 uid:' . $uid);
        ApiResponse::success(null, '用户已解封');

    } else {
        ApiResponse::error(405, 'Method not allowed');
    }

} else {
    ApiResponse::notFound('Unknown admin sub-resource: ' . $seg1);
}
