<?php

/**
 * 前台 AI 服务端代理路由
 *
 * AIEditor 的 AI 功能（气泡面板续写/优化、AI 菜单等）通过 customUrl 调用此接口，
 * 服务端根据 feature 配置（global/user_key/both）选择 apiKey 调用 provider，
 * 流式（SSE）转发响应给前端。
 *
 * URL: /ai-chat（POST）
 * 请求体：AIEditor 标准 OpenAI 兼容格式 {model, messages, max_tokens, temperature, stream}
 * 响应：SSE 流（data: {...}\n\n）
 *
 * 安全：
 *   - 必须登录（$uid > 0）
 *   - CSRF 校验（URL query string ?_csrf=xxx 或 X-CSRF-Token 头）
 *   - 限流：每用户每分钟 20 次（可配置 conf.ai.rate_limit）
 *   - apiKey 不暴露给前端（服务端调用）
 */

!defined('DEBUG') AND exit('Access Denied');

$action = param(1);
if(empty($action)) $action = 'chat';

// hook route_ai_start.php

// 必须登录
if(empty($uid) || $uid <= 0) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('code' => 1, 'message' => lang('login_required')));
    exit;
}

if(!class_exists('AIService')) include_once APP_PATH . 'lib/AIService.php';
if(!class_exists('AILogService')) include_once APP_PATH . 'lib/AILogService.php';

$aiService = new AIService($db, $conf);

// ====== Action: chat（SSE 流式代理） ======
if($action == 'chat') {

    // hook route_ai_chat_start.php

    // CSRF 校验：AIEditor 的 OpenAI client 不支持自定义 headers
    // token 通过 URL query string 传递（EditorService::buildAiConfig 注入到 customUrl）
    // 同时支持 X-CSRF-Token 头（供其他客户端调用）
    $csrfToken = isset($_GET['_csrf']) ? $_GET['_csrf'] : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
    $sessionToken = CsrfService::getToken();
    if(empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('code' => 1, 'message' => 'CSRF token verification failed'));
        exit;
    }

    // 限流：每用户每分钟 N 次（默认 20）
    $rate_limit = isset($conf['ai']['rate_limit']) ? intval($conf['ai']['rate_limit']) : 20;
    if($rate_limit > 0 && function_exists('cache_get')) {
        $rate_key = 'ai_rate_' . $uid;
        $count = cache_get($rate_key);
        if($count === NULL || $count === FALSE) {
            $count = 0;
        }
        if(intval($count) >= $rate_limit) {
            header('HTTP/1.1 429 Too Many Requests');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 1, 'message' => '请求过于频繁，请稍后再试'));
            exit;
        }
        cache_set($rate_key, intval($count) + 1, 60);
    }

    // 读取请求体（AIEditor POST 的 JSON）
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if(!is_array($body) || empty($body['messages'])) {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('code' => 1, 'message' => 'Invalid request body'));
        exit;
    }

    $messages = $body['messages'];
    $options = array(
        'uid'         => $uid,
        'temperature' => isset($body['temperature']) ? $body['temperature'] : 0.7,
    );
    if(isset($body['max_tokens'])) {
        $options['max_tokens'] = intval($body['max_tokens']);
    }
    if(isset($body['stream']) && $body['stream']) {
        $options['stream'] = true;
    }

    // 取生效配置（决定用哪个 provider/apiKey）
    $featureKey = 'editor'; // 当前仅编辑器 AI 走此接口
    $config = $aiService->getEffectiveConfig($featureKey, $uid);
    if(empty($config) || empty($config['apiKey']) || empty($config['url']) || empty($config['model'])) {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('code' => 1, 'message' => 'AI 配置不完整，请在个人设置中配置'));
        exit;
    }

    // 强制用配置中的 model（忽略前端传的 model，防止越权使用其他模型）
    $body['model'] = $config['model'];

    $start_time = microtime(true);
    $logBase = array(
        'uid'             => $uid,
        'feature'         => $featureKey,
        'source'          => 'core',
        'provider_name'   => $config['provider_name'],
        'model'           => $config['model'],
        'mode'            => $aiService->getFeatureConfig($featureKey)['mode'] ?? '',
        'request_summary' => '',
    );
    // 请求摘要
    $reqText = '';
    foreach($messages as $m) {
        if(isset($m['content'])) $reqText .= $m['content'] . ' ';
    }
    $logBase['request_summary'] = mb_substr(trim($reqText), 0, 200, 'UTF-8');

    // 流式 or 非流式
    $stream = !empty($options['stream']);

    if($stream) {
        // SSE 流式代理
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Nginx 关闭缓冲
        @set_time_limit(120);
        // 清空所有 PHP 输出缓冲层，确保 curl 收到数据立即 flush 给客户端
        while(ob_get_level() > 0) { @ob_end_flush(); }
        @flush();

        $url = rtrim($config['url'], '/') . '/chat/completions';
        $body['stream'] = true;
        // 请求 provider 在最后一帧返回 usage（OpenAI 兼容接口规范）
        // 不是所有 provider 都支持，未返回时日志 token 记为 0，前端显示 '-'
        $body['stream_options'] = array('include_usage' => true);
        $payload = xn_json_encode($body);

        // 累积流式 usage（最后一帧 choices 为空数组 + 含 usage 字段）
        $streamUsage = array('prompt' => 0, 'completion' => 0, 'total' => 0);
        $streamBuffer = '';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $config['apiKey'],
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        // 性能优化：启用 HTTP/2（ALPN 协商）、禁用 Nagle
        // 注意：不要启用 CURLOPT_ENCODING（gzip），否则 curl 会等待完整 gzip 块才解码
        // 导致 SSE 流式数据被缓冲，前端收不到逐 chunk 的数据，失去打字效果
        curl_setopt($ch, CURLOPT_SSL_ENABLE_ALPN, true);
        if(defined('CURLOPT_TCP_NODELAY')) {
            curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
        }
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$streamUsage, &$streamBuffer) {
            echo $data;
            // 同时清 PHP 缓冲层 + 通知 web server flush
            if(ob_get_level() > 0) { @ob_flush(); }
            @flush();

            // 解析 SSE 帧累积 usage（不影响原样转发给前端）
            // OpenAI 兼容接口最后一帧格式：data: {"choices":[],"usage":{...}}\n\n
            $streamBuffer .= $data;
            while(($pos = strpos($streamBuffer, "\n\n")) !== false) {
                $frame = substr($streamBuffer, 0, $pos);
                $streamBuffer = substr($streamBuffer, $pos + 2);
                if(strpos($frame, 'data: ') !== 0) continue;
                $jsonStr = substr($frame, 6);
                if(trim($jsonStr) === '[DONE]') continue;
                $json = json_decode($jsonStr, true);
                if(is_array($json) && isset($json['usage'])) {
                    $streamUsage['prompt'] = intval(isset($json['usage']['prompt_tokens']) ? $json['usage']['prompt_tokens'] : 0);
                    $streamUsage['completion'] = intval(isset($json['usage']['completion_tokens']) ? $json['usage']['completion_tokens'] : 0);
                    $streamUsage['total'] = intval(isset($json['usage']['total_tokens']) ? $json['usage']['total_tokens'] : 0);
                }
            }

            return strlen($data);
        });

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        if(PHP_VERSION_ID < 80000) curl_close($ch);

        $responseTime = intval((microtime(true) - $start_time) * 1000);

        if($response === false || $curl_err) {
            AILogService::log(array_merge($logBase, array(
                'response_time' => $responseTime,
                'status'        => 0,
                'error_msg'     => 'curl error: ' . $curl_err,
            )));
            echo "data: " . json_encode(array('error' => 'curl error: ' . $curl_err)) . "\n\n";
            @flush();
        } elseif($http_code != 200) {
            AILogService::log(array_merge($logBase, array(
                'response_time' => $responseTime,
                'status'        => 0,
                'error_msg'     => 'HTTP ' . $http_code,
            )));
        } else {
            // 成功：写入累积到的 usage（provider 不支持 include_usage 时为 0，前端显示 '-'）
            AILogService::log(array_merge($logBase, array(
                'response_time'     => $responseTime,
                'status'            => 1,
                'prompt_tokens'     => $streamUsage['prompt'],
                'completion_tokens' => $streamUsage['completion'],
                'total_tokens'      => $streamUsage['total'],
            )));
        }
        echo "data: [DONE]\n\n";
        @flush();
        exit;
    } else {
        // 非流式：走 AIService::call()（内部已记录日志）
        $result = $aiService->call($featureKey, $messages, $options);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

} else {
    message(-1, lang('admin_request_failed_retry'));
}

?>
