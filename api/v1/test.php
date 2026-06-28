<?php

/**
 * Xiuno BBS API 测试脚本
 *
 * 自动测试所有 API 端点，输出 JSON 或 HTML 格式的结果。
 * 警告：此脚本仅用于开发测试，生产环境请务必删除或禁用！
 *
 * 用法：
 *   /api/v1/test.php                              — JSON 输出
 *   /api/v1/test.php?format=html                  — HTML 输出（Bootstrap 5）
 *   /api/v1/test.php?admin_email=xx&admin_password=xx  — 指定管理员凭据
 */

define('API_MODE', true);
define('API_TEST_MODE', true);

!defined('DEBUG') AND define('DEBUG', 1);
!defined('APP_PATH') AND define('APP_PATH', dirname(__DIR__, 2) . '/');
!defined('ADMIN_PATH') AND define('ADMIN_PATH', APP_PATH . 'admin/');
!defined('XIUNOPHP_PATH') AND define('XIUNOPHP_PATH', APP_PATH . 'xiunophp/');

// 加载配置文件（必须在 xiunophp.php 之前）
$conf = (@include APP_PATH . 'conf/conf.php') OR exit('{"code":500,"msg":"Config file not found","data":null}');

// 兼容配置项
!isset($conf['user_create_on']) AND $conf['user_create_on'] = 1;
!isset($conf['cache_disable']) AND $conf['cache_disable'] = 0;
$conf['version'] = 'X1.0.1';

// 转换为绝对路径
substr($conf['log_path'], 0, 2) == './' AND $conf['log_path'] = APP_PATH . $conf['log_path'];
substr($conf['tmp_path'], 0, 2) == './' AND $conf['tmp_path'] = APP_PATH . $conf['tmp_path'];
substr($conf['upload_path'], 0, 2) == './' AND $conf['upload_path'] = APP_PATH . $conf['upload_path'];

$_SERVER['conf'] = $conf;

if (!class_exists('db_mysql')) {
    include APP_PATH . 'xiunophp/xiunophp.php';
}

// ========== 测试配置 ==========

$adminEmail = $_GET['admin_email'] ?? 'admin';
$adminPassword = $_GET['admin_password'] ?? 'admin111';
$outputFormat = $_GET['format'] ?? 'json';

// 自动检测 Base URL
// http_url_path() 在 /api/v1/test.php 上下文中返回 https://domain.com/api/v1/
// 需要去掉 /api/v1 部分，得到站点根 URL，再拼接 /api/v1
$baseUrl = '';
if (function_exists('http_url_path')) {
    $baseUrl = rtrim(str_replace('/api/v1', '', http_url_path()), '/');
}
if (empty($baseUrl)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    // 回退：从脚本路径中去掉 /api/v1
    $baseUrl = $scheme . '://' . $host . preg_replace('#/api/v1$#', '', $scriptDir);
}
$apiBase = $baseUrl . '/api/v1';

// ========== 辅助函数 ==========

/**
 * 发起 HTTP 请求
 */
function httpRequest(string $method, string $url, array $body = [], array $headers = []): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 跟随 301/302 重定向
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "$k: $v";
    }

    if (!empty($body)) {
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        $curlHeaders[] = 'Content-Type: application/json';
    }

    if (!empty($curlHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    // curl_close() 在 PHP 8.0+ 无效果，8.5+ 已废弃，不再调用

    $data = null;
    if ($response !== false) {
        $data = json_decode($response, true);
    }

    return [
        'http_code' => intval($httpCode),
        'body' => $response,
        'data' => $data,
        'error' => $error,
    ];
}

/**
 * 测试单个端点
 */
function testEndpoint(
    string $method,
    string $path,
    array  $body = [],
    array  $headers = [],
    array  $expect = []
): array {
    global $apiBase;

    $url = $apiBase . $path;
    $expectCode = $expect['http_code'] ?? 200;
    $expectApiCode = $expect['api_code'] ?? 0;

    $result = httpRequest($method, $url, $body, $headers);

    $httpCode = $result['http_code'];
    $data = $result['data'];
    $apiCode = $data['code'] ?? null;

    // 判断状态
    $status = 'PASS';
    $errorMessage = '';

    if (!empty($result['error'])) {
        $status = 'FAIL';
        $errorMessage = 'cURL error: ' . $result['error'];
    } elseif ($httpCode !== $expectCode) {
        $status = 'FAIL';
        $errorMessage = "Expected HTTP {$expectCode}, got {$httpCode}";
    } elseif ($apiCode !== null && $apiCode !== $expectApiCode) {
        // 某些端点可能返回非0但仍是成功的（如 409 conflict 在 follow/unfollow 中）
        if (!isset($expect['allow_any_code']) || !$expect['allow_any_code']) {
            $status = 'FAIL';
            $errorMessage = "Expected API code {$expectApiCode}, got {$apiCode}";
        }
    }

    // 生成响应摘要
    $summary = '';
    if ($data) {
        if (isset($data['msg'])) {
            $summary = $data['msg'];
        }
        if (isset($data['data'])) {
            $d = $data['data'];
            if (is_array($d)) {
                $keys = array_keys($d);
                $summary .= ' [' . implode(', ', array_slice($keys, 0, 5)) . ']';
            } elseif (is_string($d)) {
                $summary .= ' ' . mb_substr($d, 0, 50);
            }
        }
    } else {
        $summary = mb_substr($result['body'] ?? '', 0, 100);
    }
    $summary = trim($summary);

    return [
        'method' => $method,
        'path' => $path,
        'status' => $status,
        'http_code' => $httpCode,
        'response_summary' => $summary,
        'error_message' => $errorMessage,
        'response_data' => $data,
    ];
}

/**
 * 标记跳过
 */
function skipTest(string $method, string $path, string $reason): array {
    return [
        'method' => $method,
        'path' => $path,
        'status' => 'SKIP',
        'http_code' => 0,
        'response_summary' => '',
        'error_message' => $reason,
        'response_data' => null,
    ];
}

// ========== 开始测试 ==========

$results = [];
$accessToken = '';
$refreshToken = '';
$adminUid = 1;
$createdTid = 0;
$createdPid = 0;
$loginFailed = false;

// ---------- 1. 公开端点 ----------

$results[] = testEndpoint('GET', '');
$results[] = testEndpoint('GET', '/site');
$results[] = testEndpoint('GET', '/site/stats');
$results[] = testEndpoint('GET', '/forum');
$results[] = testEndpoint('GET', '/forum/tree');
$results[] = testEndpoint('GET', '/thread');
$results[] = testEndpoint('GET', '/user');
$results[] = testEndpoint('GET', '/rank');
$results[] = testEndpoint('GET', '/rank/threads');
$results[] = testEndpoint('GET', '/rank/users');
$results[] = testEndpoint('GET', '/captcha/login');
$results[] = testEndpoint('GET', '/search?q=test');

// ---------- 2. 认证端点 ----------

// 登录获取管理员 token
$loginResult = testEndpoint('POST', '/auth/login', [
    'email' => $adminEmail,
    'password' => $adminPassword,
]);
$results[] = $loginResult;

if ($loginResult['status'] === 'PASS' && !empty($loginResult['response_data']['data'])) {
    $accessToken = $loginResult['response_data']['data']['access_token'] ?? '';
    $refreshToken = $loginResult['response_data']['data']['refresh_token'] ?? '';
    $adminUid = $loginResult['response_data']['data']['uid'] ?? 1;
} else {
    $loginFailed = true;
}

$authHeaders = [];
if (!empty($accessToken)) {
    $authHeaders = ['Authorization' => 'Bearer ' . $accessToken];
}

// 刷新 token
if (!empty($refreshToken)) {
    $results[] = testEndpoint('POST', '/auth/refresh', [
        'refresh_token' => $refreshToken,
    ], [], ['api_code' => 0]);
    // 刷新后用新 token（如果返回了新的）
    $lastResult = $results[count($results) - 1];
    if ($lastResult['status'] === 'PASS' && !empty($lastResult['response_data']['data']['access_token'])) {
        $accessToken = $lastResult['response_data']['data']['access_token'];
        $refreshToken = $lastResult['response_data']['data']['refresh_token'] ?? $refreshToken;
        $authHeaders = ['Authorization' => 'Bearer ' . $accessToken];
    }
} else {
    $results[] = skipTest('POST', '/auth/refresh', 'Login failed, no refresh token');
}

// 登出测试：先单独登录获取一个临时 token 用于登出
$logoutResult = skipTest('POST', '/auth/logout', 'Login failed, cannot test logout');
if (!$loginFailed) {
    $tempLogin = testEndpoint('POST', '/auth/login', [
        'email' => $adminEmail,
        'password' => $adminPassword,
    ]);
    if ($tempLogin['status'] === 'PASS' && !empty($tempLogin['response_data']['data'])) {
        $tempAccessToken = $tempLogin['response_data']['data']['access_token'] ?? '';
        $tempRefreshToken = $tempLogin['response_data']['data']['refresh_token'] ?? '';
        $logoutResult = testEndpoint('POST', '/auth/logout', [
            'refresh_token' => $tempRefreshToken,
        ], ['Authorization' => 'Bearer ' . $tempAccessToken]);
    }
}
$results[] = $logoutResult;

// ---------- 3. 需要认证的用户端点 ----------

if ($loginFailed) {
    $authEndpoints = [
        ['GET', '/user/me'],
        ['GET', '/user/1'],
        ['GET', '/user/1/threads'],
        ['GET', '/user/1/posts'],
        ['GET', '/user/1/following'],
        ['GET', '/user/1/followers'],
        ['GET', '/user/1/ai-config'],
        ['GET', '/user/1/avatar/presets'],
        ['PUT', '/user/1'],
        ['POST', '/user/1/follow'],
        ['DELETE', '/user/1/follow'],
    ];
    foreach ($authEndpoints as $ep) {
        $results[] = skipTest($ep[0], $ep[1], 'Login failed, skipping authenticated test');
    }
} else {
    $results[] = testEndpoint('GET', '/user/me', [], $authHeaders);
    $results[] = testEndpoint('GET', '/user/1', [], $authHeaders);
    $results[] = testEndpoint('GET', '/user/1/threads', [], $authHeaders);
    $results[] = testEndpoint('GET', '/user/1/posts', [], $authHeaders);
    $results[] = testEndpoint('GET', '/user/1/following', [], $authHeaders);
    $results[] = testEndpoint('GET', '/user/1/followers', [], $authHeaders);
    $results[] = testEndpoint('GET', '/user/1/ai-config', [], $authHeaders);
    $results[] = testEndpoint('GET', '/user/1/avatar/presets', [], $authHeaders);

    // 更新用户签名
    $results[] = testEndpoint('PUT', '/user/' . $adminUid, [
        'signature' => 'API test signature ' . date('H:i:s'),
    ], $authHeaders);

    // 关注/取消关注（关注 uid=2，如果不存在可能失败，允许非0返回）
    $results[] = testEndpoint('POST', '/user/2/follow', [], $authHeaders, ['allow_any_code' => true]);
    $results[] = testEndpoint('DELETE', '/user/2/follow', [], $authHeaders, ['allow_any_code' => true]);
}

// ---------- 4. 帖子端点 ----------

if ($loginFailed) {
    $threadEndpoints = [
        ['POST', '/thread'],
        ['GET', '/thread/1'],
        ['PUT', '/thread/1'],
        ['POST', '/thread/1/like'],
        ['DELETE', '/thread/1/like'],
        ['POST', '/thread/1/favorite'],
        ['DELETE', '/thread/1/favorite'],
        ['POST', '/thread/1/report'],
        ['DELETE', '/thread/1'],
    ];
    foreach ($threadEndpoints as $ep) {
        $results[] = skipTest($ep[0], $ep[1], 'Login failed, skipping thread test');
    }
} else {
    // 先获取一个版块 fid
    $forumListResult = testEndpoint('GET', '/forum', [], $authHeaders);
    $testFid = 1;
    if ($forumListResult['status'] === 'PASS' && !empty($forumListResult['response_data']['data']['list'])) {
        $firstForum = $forumListResult['response_data']['data']['list'][0] ?? null;
        if ($firstForum && isset($firstForum['fid'])) {
            $testFid = intval($firstForum['fid']);
        }
    }

    // 创建帖子
    $createThreadResult = testEndpoint('POST', '/thread', [
        'fid' => $testFid,
        'subject' => '[API Test] Test Thread ' . date('Y-m-d H:i:s'),
        'message' => 'This is a test thread created by the API test script. It will be deleted after testing.',
    ], $authHeaders);
    $results[] = $createThreadResult;

    if ($createThreadResult['status'] === 'PASS' && !empty($createThreadResult['response_data']['data']['tid'])) {
        $createdTid = $createThreadResult['response_data']['data']['tid'];
    }

    // 帖子详情
    if ($createdTid > 0) {
        $results[] = testEndpoint('GET', '/thread/' . $createdTid, [], $authHeaders);

        // 更新帖子
        $results[] = testEndpoint('PUT', '/thread/' . $createdTid, [
            'subject' => '[API Test] Updated Thread ' . date('H:i:s'),
        ], $authHeaders);

        // 点赞
        $results[] = testEndpoint('POST', '/thread/' . $createdTid . '/like', [], $authHeaders);
        // 取消点赞
        $results[] = testEndpoint('DELETE', '/thread/' . $createdTid . '/like', [], $authHeaders);
        // 收藏
        $results[] = testEndpoint('POST', '/thread/' . $createdTid . '/favorite', [], $authHeaders);
        // 取消收藏
        $results[] = testEndpoint('DELETE', '/thread/' . $createdTid . '/favorite', [], $authHeaders);
        // 举报
        $results[] = testEndpoint('POST', '/thread/' . $createdTid . '/report', [
            'reason' => 'API test report',
        ], $authHeaders);
    } else {
        $tidEndpoints = [
            ['GET', '/thread/{tid}'],
            ['PUT', '/thread/{tid}'],
            ['POST', '/thread/{tid}/like'],
            ['DELETE', '/thread/{tid}/like'],
            ['POST', '/thread/{tid}/favorite'],
            ['DELETE', '/thread/{tid}/favorite'],
            ['POST', '/thread/{tid}/report'],
        ];
        foreach ($tidEndpoints as $ep) {
            $results[] = skipTest($ep[0], str_replace('{tid}', '0', $ep[1]), 'Thread creation failed');
        }
    }
}

// ---------- 5. 回帖端点 ----------

if ($loginFailed || $createdTid <= 0) {
    $postEndpoints = [
        ['POST', '/post'],
        ['GET', '/post'],
        ['GET', '/post/1'],
        ['PUT', '/post/1'],
        ['POST', '/post/1/like'],
        ['DELETE', '/post/1/like'],
        ['DELETE', '/post/1'],
    ];
    foreach ($postEndpoints as $ep) {
        $results[] = skipTest($ep[0], $ep[1], $loginFailed ? 'Login failed' : 'No test thread available');
    }
} else {
    // 创建回帖
    $createPostResult = testEndpoint('POST', '/post', [
        'tid' => $createdTid,
        'message' => 'This is a test post by the API test script.',
    ], $authHeaders);
    $results[] = $createPostResult;

    if ($createPostResult['status'] === 'PASS' && !empty($createPostResult['response_data']['data']['pid'])) {
        $createdPid = $createPostResult['response_data']['data']['pid'];
    }

    // 回帖列表
    $results[] = testEndpoint('GET', '/post?tid=' . $createdTid, [], $authHeaders);

    // 回帖详情
    if ($createdPid > 0) {
        $results[] = testEndpoint('GET', '/post/' . $createdPid, [], $authHeaders);
        // 更新回帖
        $results[] = testEndpoint('PUT', '/post/' . $createdPid, [
            'message' => 'Updated test post by API test script.',
        ], $authHeaders);
        // 点赞回帖
        $results[] = testEndpoint('POST', '/post/' . $createdPid . '/like', [], $authHeaders);
        // 取消点赞回帖
        $results[] = testEndpoint('DELETE', '/post/' . $createdPid . '/like', [], $authHeaders);
    } else {
        $results[] = skipTest('GET', '/post/{pid}', 'Post creation failed');
        $results[] = skipTest('PUT', '/post/{pid}', 'Post creation failed');
        $results[] = skipTest('POST', '/post/{pid}/like', 'Post creation failed');
        $results[] = skipTest('DELETE', '/post/{pid}/like', 'Post creation failed');
    }
}

// ---------- 6. 版块端点 ----------

$results[] = testEndpoint('GET', '/forum/1', [], $authHeaders);
$results[] = testEndpoint('GET', '/forum/1/threads', [], $authHeaders);

// ---------- 7. 通知端点 ----------

if ($loginFailed) {
    $results[] = skipTest('GET', '/notify', 'Login failed');
    $results[] = skipTest('GET', '/notify/unread', 'Login failed');
    $results[] = skipTest('PUT', '/notify/read-all', 'Login failed');
} else {
    $results[] = testEndpoint('GET', '/notify', [], $authHeaders);
    $results[] = testEndpoint('GET', '/notify/unread', [], $authHeaders);
    $results[] = testEndpoint('PUT', '/notify/read-all', [], $authHeaders);
}

// ---------- 8. 积分端点 ----------

if ($loginFailed) {
    $results[] = skipTest('GET', '/credits', 'Login failed');
    $results[] = skipTest('GET', '/credits/log', 'Login failed');
} else {
    $results[] = testEndpoint('GET', '/credits', [], $authHeaders);
    $results[] = testEndpoint('GET', '/credits/log', [], $authHeaders);
}

// ---------- 9. 版主端点 ----------

if ($loginFailed || $createdTid <= 0) {
    $modEndpoints = [
        ['POST', '/mod/top'],
        ['POST', '/mod/close'],
        ['POST', '/mod/move'],
        ['POST', '/mod/delete'],
    ];
    foreach ($modEndpoints as $ep) {
        $results[] = skipTest($ep[0], $ep[1], $loginFailed ? 'Login failed' : 'No test thread available');
    }
} else {
    // 置顶
    $results[] = testEndpoint('POST', '/mod/top', [
        'tidarr' => [$createdTid],
        'top' => 1,
    ], $authHeaders, ['allow_any_code' => true]);

    // 关闭
    $results[] = testEndpoint('POST', '/mod/close', [
        'tidarr' => [$createdTid],
        'close' => 1,
    ], $authHeaders, ['allow_any_code' => true]);

    // 移动（移回原版块，避免破坏数据）
    $results[] = testEndpoint('POST', '/mod/move', [
        'tidarr' => [$createdTid],
        'newfid' => $testFid,
    ], $authHeaders, ['allow_any_code' => true]);

    // 版主删除（用单独创建的帖子来测试，避免删掉主测试帖）
    $modDeleteTid = 0;
    $modDeleteThread = testEndpoint('POST', '/thread', [
        'fid' => $testFid,
        'subject' => '[API Test] Mod Delete Target',
        'message' => 'This thread will be deleted by mod action.',
    ], $authHeaders);
    if ($modDeleteThread['status'] === 'PASS' && !empty($modDeleteThread['response_data']['data']['tid'])) {
        $modDeleteTid = $modDeleteThread['response_data']['data']['tid'];
        $results[] = testEndpoint('POST', '/mod/delete', [
            'tidarr' => [$modDeleteTid],
        ], $authHeaders, ['allow_any_code' => true]);
    } else {
        $results[] = skipTest('POST', '/mod/delete', 'Could not create thread for mod delete test');
    }
}

// ---------- 10. 管理员端点 ----------

if ($loginFailed) {
    $adminEndpoints = [
        ['GET', '/admin/security'],
        ['PUT', '/admin/security'],
        ['GET', '/admin/security/captcha'],
        ['PUT', '/admin/security/captcha'],
        ['GET', '/admin/audit/pending'],
        ['POST', '/admin/audit/approve'],
        ['POST', '/admin/audit/reject'],
        ['GET', '/admin/sensitive-words'],
        ['POST', '/admin/sensitive-words'],
        ['DELETE', '/admin/sensitive-words/testword'],
        ['GET', '/admin/log/credits'],
        ['GET', '/admin/log/login'],
    ];
    foreach ($adminEndpoints as $ep) {
        $results[] = skipTest($ep[0], $ep[1], 'Login failed, skipping admin test');
    }
} else {
    // 安全配置 - 读取
    $results[] = testEndpoint('GET', '/admin/security', [], $authHeaders);
    // 安全配置 - 更新（读取后原样写回）
    $secResult = $results[count($results) - 1];
    $secData = [];
    if ($secResult['status'] === 'PASS' && !empty($secResult['response_data']['data'])) {
        foreach ($secResult['response_data']['data'] as $k => $v) {
            if (strpos($k, 'security_') === 0) {
                $secData[$k] = $v;
            }
        }
    }
    if (!empty($secData)) {
        $results[] = testEndpoint('PUT', '/admin/security', $secData, $authHeaders, ['allow_any_code' => true]);
    } else {
        $results[] = skipTest('PUT', '/admin/security', 'Could not read security config');
    }

    // 验证码配置 - 读取
    $results[] = testEndpoint('GET', '/admin/security/captcha', [], $authHeaders);
    // 验证码配置 - 更新
    $captchaResult = $results[count($results) - 1];
    $captchaData = [];
    if ($captchaResult['status'] === 'PASS' && !empty($captchaResult['response_data']['data'])) {
        $captchaData = $captchaResult['response_data']['data'];
    }
    if (!empty($captchaData)) {
        $results[] = testEndpoint('PUT', '/admin/security/captcha', $captchaData, $authHeaders, ['allow_any_code' => true]);
    } else {
        $results[] = skipTest('PUT', '/admin/security/captcha', 'Could not read captcha config');
    }

    // 审核列表
    $results[] = testEndpoint('GET', '/admin/audit/pending', [], $authHeaders);
    // 审核通过/驳回（使用不存在的 ID，预期可能失败但端点可达即可）
    $results[] = testEndpoint('POST', '/admin/audit/approve', [
        'target_type' => 'thread',
        'target_id' => 999999,
    ], $authHeaders, ['allow_any_code' => true]);
    $results[] = testEndpoint('POST', '/admin/audit/reject', [
        'target_type' => 'thread',
        'target_id' => 999999,
        'reason' => 'API test',
    ], $authHeaders, ['allow_any_code' => true]);

    // 敏感词 - 列表
    $results[] = testEndpoint('GET', '/admin/sensitive-words', [], $authHeaders);
    // 敏感词 - 添加
    $results[] = testEndpoint('POST', '/admin/sensitive-words', [
        'word' => 'apitestword_' . time(),
    ], $authHeaders, ['allow_any_code' => true]);
    // 敏感词 - 删除
    $results[] = testEndpoint('DELETE', '/admin/sensitive-words/apitestword', [], $authHeaders, ['allow_any_code' => true]);

    // 日志
    $results[] = testEndpoint('GET', '/admin/log/credits', [], $authHeaders);
    $results[] = testEndpoint('GET', '/admin/log/login', [], $authHeaders);
}

// ---------- 11. 批量端点 ----------

$results[] = testEndpoint('GET', '/user?ids=1', [], $authHeaders);
$results[] = testEndpoint('GET', '/thread?ids=1', [], $authHeaders);
$results[] = testEndpoint('GET', '/forum?ids=1', [], $authHeaders);

// ---------- 12. 清理：删除测试创建的帖子 ----------

if (!empty($accessToken) && $createdTid > 0) {
    // 删除测试回帖
    if ($createdPid > 0) {
        testEndpoint('DELETE', '/post/' . $createdPid, [], $authHeaders);
    }
    // 删除测试帖子
    testEndpoint('DELETE', '/thread/' . $createdTid, [], $authHeaders);
}

// ========== 汇总结果 ==========

$total = count($results);
$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($results as &$r) {
    unset($r['response_data']); // 清理响应数据，仅保留摘要
    switch ($r['status']) {
        case 'PASS': $passed++; break;
        case 'FAIL': $failed++; break;
        case 'SKIP': $skipped++; break;
    }
}
unset($r);

$summary = [
    'total' => $total,
    'passed' => $passed,
    'failed' => $failed,
    'skipped' => $skipped,
];

$output = [
    'warning' => 'This is a TEST script. Disable in production!',
    'base_url' => $apiBase,
    'admin_email' => $adminEmail,
    'summary' => $summary,
    'results' => $results,
];

// ========== 输出 ==========

if ($outputFormat === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo renderHtml($output);
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// ========== HTML 渲染函数 ==========

function renderHtml(array $output): string {
    $summary = $output['summary'];
    $results = $output['results'];
    $baseUrl = htmlspecialchars($output['base_url'], ENT_QUOTES, 'UTF-8');

    $methodColors = [
        'GET' => 'primary',
        'POST' => 'success',
        'PUT' => 'warning',
        'DELETE' => 'danger',
    ];

    $statusColors = [
        'PASS' => 'success',
        'FAIL' => 'danger',
        'SKIP' => 'secondary',
    ];

    $rows = '';
    $i = 0;
    foreach ($results as $r) {
        $i++;
        $method = htmlspecialchars($r['method'], ENT_QUOTES, 'UTF-8');
        $path = htmlspecialchars($r['path'], ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8');
        $httpCode = intval($r['http_code']);
        $respSummary = htmlspecialchars($r['response_summary'], ENT_QUOTES, 'UTF-8');
        $errorMsg = htmlspecialchars($r['error_message'], ENT_QUOTES, 'UTF-8');

        $methodColor = $methodColors[$method] ?? 'secondary';
        $statusColor = $statusColors[$status] ?? 'secondary';

        $rows .= <<<HTML
        <tr>
            <td>{$i}</td>
            <td><span class="badge bg-{$methodColor}">{$method}</span></td>
            <td><code>{$path}</code></td>
            <td><span class="badge bg-{$statusColor}">{$status}</span></td>
            <td>{$httpCode}</td>
            <td>{$respSummary}</td>
            <td class="text-danger">{$errorMsg}</td>
        </tr>
HTML;
    }

    $passPct = $summary['total'] > 0 ? round($summary['passed'] / $summary['total'] * 100, 1) : 0;
    $generatedAt = date('Y-m-d H:i:s');

    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Test Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .code { font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.85em; }
        table th { font-size: 0.85rem; }
        table td { font-size: 0.85rem; vertical-align: middle; }
        .warning-banner { background: #fff3cd; border-bottom: 2px solid #ffc107; }
    </style>
</head>
<body>
    <div class="warning-banner py-2 text-center">
        <strong>&#9888;&#65039; Warning:</strong> This is a TEST script. Disable or delete in production!
    </div>

    <div class="container py-4">
        <h2 class="mb-1">API Test Results</h2>
        <p class="text-muted mb-4">Base URL: <code>{$baseUrl}</code></p>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="fs-2 fw-bold">{$summary['total']}</div>
                        <div class="text-muted">Total</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-success">{$summary['passed']}</div>
                        <div class="text-muted">Passed</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-danger">{$summary['failed']}</div>
                        <div class="text-muted">Failed</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center border-secondary">
                    <div class="card-body">
                        <div class="fs-2 fw-bold text-secondary">{$summary['skipped']}</div>
                        <div class="text-muted">Skipped</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="progress mb-4" style="height: 24px;">
            <div class="progress-bar bg-success" style="width: {$passPct}%">{$passPct}%</div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px">#</th>
                                <th style="width:70px">Method</th>
                                <th>Path</th>
                                <th style="width:70px">Status</th>
                                <th style="width:70px">HTTP</th>
                                <th>Summary</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$rows}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <p class="text-muted text-center mt-4" style="font-size:0.8rem;">
            Generated at {$generatedAt}
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
}
