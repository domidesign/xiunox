<?php

// 确保 DatabaseInterface 已加载（参考 CreditsService 写法）
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * AIService - AI 调用中台
 *
 * 统一 AI 调用入口，支持三种模式：
 *   - global:    使用管理员配置的全局 provider（apiKey 来自 conf.ai.providers）
 *   - user_key:  使用用户自带的 apiKey（存储在 user.ai_config.$featureKey）
 *   - both:      优先用户配置，不完整回退全局
 *
 * 功能注册：插件通过 AIService::registerFeature() 注册新 AI 功能，
 *           核心在文件末尾注册 editor 功能。
 *
 * 内容后处理：插件通过 AIService::registerPostFilter() 注册过滤器，
 *             对 AI 返回内容做过滤/模板/截断等后处理。
 *
 * 配置结构（conf.ai）：
 *   - providers: 全局提供商库（管理员维护，含 api_key/url/models/type）
 *   - features:  功能配置（每个 AI 功能一项：name/mode/call_method/default_provider/default_model/allowed_providers/type）
 *   - promptContinue/promptImprove: 编辑器专属 prompt（可选）
 *
 * 用户配置（user.ai_config）：
 *   - 新版结构: {"editor": {"apiKey": "...", "model": "...", "url": "...", "provider_name": "..."}}
 *   - 旧版单层结构（含顶层 provider_name）会在读取时自动包装为 {"editor": {旧结构}}
 *
 * 健康状态（kv 持久化）：
 *   - kv 键 ai_provider_health 存储 name => ['online'=>bool, 'last_check'=>int]
 *   - 仅状态变化时写 kv，调用计数不持久化（单请求内 static 缓存）
 */
class AIService {

    /** @var DatabaseInterface */
    private $db;

    /** @var array */
    private $conf;

    /** 静态功能注册表：key => 配置数组 */
    private static $registeredFeatures = array();

    /** 内容后处理过滤器：key => [callback, ...] */
    private static $postFilters = array();

    /** provider 健康状态缓存（kv 加载一次，请求内复用） */
    private static $healthCache = null;

    /**
     * 构造函数
     * @param DatabaseInterface $db 数据库连接
     * @param array $conf 全局配置
     */
    public function __construct(DatabaseInterface $db, array $conf) {
        $this->db = $db;
        $this->conf = $conf;
    }

    /**
     * 静态注册新 AI 功能
     * 已注册的 key 不覆盖（先注册者胜，插件可在 model_inc_start 钩子中注册）
     *
     * @param string $key 功能唯一标识（如 editor）
     * @param array $config 配置：
     *   - name:             功能名称
     *   - type:             provider 类型 text/image/video/audio/transcription
     *   - mode:             global / user_key / both
     *   - call_method:      frontend（前端直连）/ proxy（服务端代理）
     *   - default_provider: 默认全局 provider 名（mode=global/both 时使用）
     *   - default_model:    默认模型名
     *   - allowed_providers: 允许的 provider 名数组
     */
    public static function registerFeature($key, array $config) {
        if (isset(self::$registeredFeatures[$key])) {
            return; // 已注册不覆盖
        }
        self::$registeredFeatures[$key] = $config;
    }

    /**
     * 取所有已注册功能（核心 editor + 插件通过 registerFeature 注册的）
     * 路由层用此方法合并 conf.ai.features，展示完整功能列表
     *
     * @return array key => 配置数组
     */
    public static function getRegisteredFeatures() {
        return self::$registeredFeatures;
    }

    /**
     * 取功能配置（合并注册默认值 + conf.ai.features.$key）
     * conf.ai.features 中的值覆盖注册默认值
     *
     * @param string $key 功能标识
     * @return array 合并后的功能配置
     */
    public function getFeatureConfig($key) {
        $registered = isset(self::$registeredFeatures[$key]) ? self::$registeredFeatures[$key] : array();
        $confFeature = isset($this->conf['ai']['features'][$key]) ? $this->conf['ai']['features'][$key] : array();
        if (!is_array($confFeature)) $confFeature = array();
        // conf 中的值覆盖注册默认值
        return array_merge($registered, $confFeature);
    }

    /**
     * 按 mode 合并全局/用户配置，返回最终生效的配置
     *
     * @param string $key 功能标识
     * @param int|null $uid 用户ID（user_key/both 模式需要）
     * @return array 生效配置，字段：apiKey, model, url, provider_name, promptContinue(可选), promptImprove(可选)
     *               配置不完整时返回空数组 []
     */
    public function getEffectiveConfig($key, $uid = null) {
        $feature = $this->getFeatureConfig($key);
        $mode = isset($feature['mode']) ? $feature['mode'] : 'user_key';

        $result = array();
        if ($mode === 'global') {
            $result = $this->getGlobalConfig($feature);
        } elseif ($mode === 'user_key') {
            $result = $this->getUserEffectiveConfig($key, $uid, $feature);
        } else { // both
            $result = $this->getUserEffectiveConfig($key, $uid, $feature);
            if (empty($result)) {
                $result = $this->getGlobalConfig($feature);
            }
        }

        if (empty($result)) {
            return array();
        }

        // 附加编辑器专属 prompt（可选字段，从 conf.ai 顶层读取）
        if (isset($this->conf['ai']['promptContinue'])) {
            $result['promptContinue'] = $this->conf['ai']['promptContinue'];
        }
        if (isset($this->conf['ai']['promptImprove'])) {
            $result['promptImprove'] = $this->conf['ai']['promptImprove'];
        }

        return $result;
    }

    /**
     * 单 provider 调用（按功能 key 解析配置后调用）
     * 使用 default_provider / user_key 解析出的单个 provider 调用
     *
     * @param string $key 功能标识
     * @param array $messages 消息数组 [[role, content], ...]
     * @param array $options 可选参数：
     *   - uid:           用户ID（user_key/both 模式取用户配置）
     *   - temperature:   采样温度，默认 0.7
     *   - max_tokens:    最大生成 token 数（可选）
     *   - timeout:       超时秒数，默认 30
     *   - source:        日志来源标识，默认 'core'
     * @return array ['code'=>0成功/1失败, 'message'=>错误信息, 'data'=>['content'=>回复文本, 'usage'=>..., 'provider_name'=>..., 'model'=>..., 'time_ms'=>...]]
     */
    public function call($key, array $messages, array $options = array()) {
        $uid = isset($options['uid']) ? intval($options['uid']) : null;
        $config = $this->getEffectiveConfig($key, $uid);
        $feature = $this->getFeatureConfig($key);

        $logBase = array(
            'uid'             => $uid ? $uid : 0,
            'feature'         => $key,
            'source'          => isset($options['source']) ? $options['source'] : 'core',
            'provider_name'   => isset($config['provider_name']) ? $config['provider_name'] : '',
            'model'           => isset($config['model']) ? $config['model'] : '',
            'mode'            => isset($feature['mode']) ? $feature['mode'] : '',
            'request_summary' => $this->summarizeMessages($messages),
        );

        $result = $this->callByConfig($config, $messages, $options, $logBase);

        // 应用内容后处理过滤器
        if ($result['code'] === 0 && !empty($result['data']['content'])) {
            $result['data']['content'] = $this->applyPostFilters($key, $result['data']['content'], array(
                'key'           => $key,
                'messages'      => $messages,
                'provider_name' => isset($result['data']['provider_name']) ? $result['data']['provider_name'] : '',
                'options'       => $options,
            ));
        }

        return $result;
    }

    /**
     * 多 provider 调用（支持 failover/round_robin/random/concurrent 四种模式）
     * 插件复用此方法实现轮询、故障转移、并发竞速
     *
     * provider 来源优先级：
     *   1. options.providers 显式指定
     *   2. feature.allowed_providers
     *   3. feature.default_provider
     *
     * @param string $key 功能标识
     * @param array $messages 消息数组
     * @param array $options:
     *   - mode:              failover（默认）| round_robin | random | concurrent
     *   - retry:             每个 provider 重试次数，默认 0
     *   - providers:         指定 provider 名数组（覆盖 feature.allowed_providers）
     *   - uid:               用户ID
     *   - temperature/max_tokens/timeout: 同 call()
     *   - source:            日志来源标识，默认 'core'
     *   - recover_threshold: 离线恢复阈值秒数，默认 600（超过此时间自动重新尝试离线 provider）
     * @return array 同 call()，data 含 provider_name/model/time_ms
     */
    public function callWithFailover($key, array $messages, array $options = array()) {
        $uid = isset($options['uid']) ? intval($options['uid']) : null;
        $feature = $this->getFeatureConfig($key);
        $mode = isset($options['mode']) ? $options['mode'] : 'failover';
        $retry = intval(isset($options['retry']) ? $options['retry'] : 0);
        $recoverThreshold = intval(isset($options['recover_threshold']) ? $options['recover_threshold'] : 600);

        // 解析 provider 名列表
        $providerNames = isset($options['providers']) ? $options['providers'] : null;
        if (!is_array($providerNames) || empty($providerNames)) {
            $providerNames = isset($feature['allowed_providers']) ? $feature['allowed_providers'] : array();
        }
        if (empty($providerNames)) {
            $dp = isset($feature['default_provider']) ? $feature['default_provider'] : '';
            if (!empty($dp)) $providerNames = array($dp);
        }
        if (empty($providerNames)) {
            return array('code' => 1, 'message' => '无可用 provider', 'data' => null);
        }

        // 构建 config 列表（过滤掉配置不完整和已离线未恢复的）
        $configs = array();
        foreach ($providerNames as $name) {
            if (!$this->isProviderHealthy($name, $recoverThreshold)) continue;
            $provider = $this->findProviderByName($name);
            if (empty($provider) || empty($provider['api_key'])) continue;
            $model = isset($feature['default_model']) ? $feature['default_model'] : '';
            if (empty($model)) $model = $this->getFirstModel($provider);
            if (empty($model)) continue;
            $configs[] = array(
                'apiKey'        => $provider['api_key'],
                'model'         => $model,
                'url'           => isset($provider['url']) ? $provider['url'] : '',
                'provider_name' => isset($provider['name']) ? $provider['name'] : $name,
            );
        }
        if (empty($configs)) {
            return array('code' => 1, 'message' => '无可用 provider 配置（可能全部离线或配置不完整）', 'data' => null);
        }

        $logBase = array(
            'uid'             => $uid ? $uid : 0,
            'feature'         => $key,
            'source'          => isset($options['source']) ? $options['source'] : 'core',
            'provider_name'   => '',
            'model'           => '',
            'mode'            => isset($feature['mode']) ? $feature['mode'] : '',
            'request_summary' => $this->summarizeMessages($messages),
        );

        // 按模式调用
        if ($mode === 'concurrent' && count($configs) > 1) {
            $result = $this->callConcurrent($configs, $messages, $options, $logBase);
        } elseif ($mode === 'random') {
            $result = $this->callRandom($configs, $messages, $options, $retry, $logBase);
        } elseif ($mode === 'round_robin') {
            $result = $this->callRoundRobin($configs, $messages, $options, $retry, $logBase);
        } else {
            $result = $this->callFailover($configs, $messages, $options, $retry, $logBase);
        }

        // 应用内容后处理过滤器
        if ($result['code'] === 0 && !empty($result['data']['content'])) {
            $result['data']['content'] = $this->applyPostFilters($key, $result['data']['content'], array(
                'key'           => $key,
                'messages'      => $messages,
                'provider_name' => isset($result['data']['provider_name']) ? $result['data']['provider_name'] : '',
                'options'       => $options,
            ));
        }

        return $result;
    }

    /**
     * 底层单次调用（按已解析的 config 执行 curl）
     * call() 和 callWithFailover() 的四种模式方法的共用底层
     *
     * @param array $config 含 apiKey/model/url/provider_name
     * @param array $messages 消息数组
     * @param array $options temperature/max_tokens/timeout
     * @param array $logBase 日志公共字段，空则不写日志
     * @return array ['code'=>0/1, 'message'=>..., 'data'=>['content','usage','provider_name','model','time_ms']]
     */
    private function callByConfig(array $config, array $messages, array $options = array(), array $logBase = array()) {
        $startTime = microtime(true);

        if (empty($config) || empty($config['apiKey']) || empty($config['url']) || empty($config['model'])) {
            if (!empty($logBase)) {
                $this->writeLog($logBase, 0, 0, 'AI 配置不完整', 0, $startTime);
            }
            return array('code' => 1, 'message' => 'AI 配置不完整', 'data' => null);
        }

        $url = rtrim($config['url'], '/') . '/chat/completions';
        $body = array(
            'model'       => $config['model'],
            'messages'    => $messages,
            'temperature' => isset($options['temperature']) ? floatval($options['temperature']) : 0.7,
        );
        // max_tokens 可选，未设置时不传（部分模型不支持该参数）
        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = intval($options['max_tokens']);
        }
        $payload = xn_json_encode($body);
        if ($payload === false) {
            if (!empty($logBase)) {
                $this->writeLog($logBase, 0, 0, '请求体编码失败', 0, $startTime);
            }
            return array('code' => 1, 'message' => '请求体编码失败', 'data' => null);
        }

        $timeout = isset($options['timeout']) ? intval($options['timeout']) : 30;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $config['apiKey'],
            'Content-Type: application/json',
            'Accept: application/json',
        ));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        // PHP 8.0+ curl 句柄自动释放，curl_close() 在 8.5 已废弃
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($response === false || $curl_err) {
            if (!empty($logBase)) {
                $this->writeLog($logBase, 0, 0, 'curl error: ' . $curl_err, 0, $startTime);
            }
            return array('code' => 1, 'message' => 'curl error: ' . $curl_err, 'data' => null);
        }
        if ($http_code != 200) {
            $snippet = mb_substr((string)$response, 0, 300);
            if (!empty($logBase)) {
                $this->writeLog($logBase, 0, 0, 'HTTP ' . $http_code . ': ' . $snippet, 0, $startTime);
            }
            return array('code' => 1, 'message' => 'HTTP ' . $http_code . ': ' . $snippet, 'data' => null);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            if (!empty($logBase)) {
                $this->writeLog($logBase, 0, 0, 'Invalid JSON response', 0, $startTime);
            }
            return array('code' => 1, 'message' => 'Invalid JSON response', 'data' => null);
        }

        $content = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';
        if ($content === '' || $content === null) {
            $err_msg = isset($data['error']['message']) ? $data['error']['message'] : 'empty content';
            if (!empty($logBase)) {
                $this->writeLog($logBase, 0, 0, $err_msg, 0, $startTime);
            }
            return array('code' => 1, 'message' => $err_msg, 'data' => null);
        }

        $usage = array(
            'prompt'     => intval(isset($data['usage']['prompt_tokens']) ? $data['usage']['prompt_tokens'] : 0),
            'completion' => intval(isset($data['usage']['completion_tokens']) ? $data['usage']['completion_tokens'] : 0),
            'total'      => intval(isset($data['usage']['total_tokens']) ? $data['usage']['total_tokens'] : 0),
        );
        $timeMs = intval((microtime(true) - $startTime) * 1000);
        $providerName = isset($config['provider_name']) ? $config['provider_name'] : '';

        // 成功日志（补充实际 provider_name 和 model）
        if (!empty($logBase)) {
            $logBase['provider_name'] = $providerName;
            $logBase['model'] = $config['model'];
            $this->writeLog($logBase, $usage['prompt'], $usage['completion'], '', $usage['total'], $startTime, 1, (string)$content);
        }

        return array(
            'code'    => 0,
            'message' => '',
            'data'    => array(
                'content'       => (string)$content,
                'usage'         => $usage,
                'provider_name' => $providerName,
                'model'         => $config['model'],
                'time_ms'       => $timeMs,
            ),
        );
    }

    /**
     * 顺序故障转移：依次尝试每个 provider，每个 retry 次后切下一个
     */
    private function callFailover($configs, $messages, $options, $retry, $logBase) {
        foreach ($configs as $cfg) {
            for ($r = 0; $r <= $retry; $r++) {
                $result = $this->callByConfig($cfg, $messages, $options, $logBase);
                if ($result['code'] === 0) {
                    $this->markProviderOnline($cfg['provider_name']);
                    return $result;
                }
            }
            $this->markProviderOffline($cfg['provider_name']);
        }
        return array('code' => 1, 'message' => '所有 provider 均调用失败', 'data' => null);
    }

    /**
     * 轮询：从上次使用的 provider 下一个开始，顺序尝试
     * 上次使用的 provider 名存 kv 键 ai_roundrobin_last_{feature}
     */
    private function callRoundRobin($configs, $messages, $options, $retry, $logBase) {
        $count = count($configs);
        $featureKey = isset($logBase['feature']) ? $logBase['feature'] : '';
        $lastName = function_exists('kv_get') ? kv_get('ai_roundrobin_last_' . $featureKey) : '';
        $startIdx = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($configs[$i]['provider_name'] === $lastName) {
                $startIdx = ($i + 1) % $count;
                break;
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $idx = ($startIdx + $i) % $count;
            $cfg = $configs[$idx];
            for ($r = 0; $r <= $retry; $r++) {
                $result = $this->callByConfig($cfg, $messages, $options, $logBase);
                if ($result['code'] === 0) {
                    $this->markProviderOnline($cfg['provider_name']);
                    if (function_exists('kv_set')) {
                        kv_set('ai_roundrobin_last_' . $featureKey, $cfg['provider_name']);
                    }
                    return $result;
                }
            }
            $this->markProviderOffline($cfg['provider_name']);
        }
        return array('code' => 1, 'message' => '所有 provider 均调用失败', 'data' => null);
    }

    /**
     * 随机：打乱顺序后顺序 fallback
     * ponytail: 核心 provider 无 weight 字段，加权随机留给插件自行 shuffle 后传 providers 参数
     */
    private function callRandom($configs, $messages, $options, $retry, $logBase) {
        $order = range(0, count($configs) - 1);
        shuffle($order);
        foreach ($order as $idx) {
            $cfg = $configs[$idx];
            for ($r = 0; $r <= $retry; $r++) {
                $result = $this->callByConfig($cfg, $messages, $options, $logBase);
                if ($result['code'] === 0) {
                    $this->markProviderOnline($cfg['provider_name']);
                    return $result;
                }
            }
            $this->markProviderOffline($cfg['provider_name']);
        }
        return array('code' => 1, 'message' => '所有 provider 均调用失败', 'data' => null);
    }

    /**
     * 并发竞速：前 3 个并发，取最快成功返回
     * ponytail: 上限 3 个避免过多并发连接；单 provider 时直接走 callByConfig 不走 curl_multi
     */
    private function callConcurrent($configs, $messages, $options, $logBase) {
        $candidates = array_slice($configs, 0, 3);
        if (count($candidates) === 1) {
            $result = $this->callByConfig($candidates[0], $messages, $options, $logBase);
            if ($result['code'] === 0) {
                $this->markProviderOnline($candidates[0]['provider_name']);
            } else {
                $this->markProviderOffline($candidates[0]['provider_name']);
            }
            return $result;
        }

        $startTime = microtime(true);
        $timeout = isset($options['timeout']) ? intval($options['timeout']) : 30;
        $temperature = isset($options['temperature']) ? floatval($options['temperature']) : 0.7;

        $handles = array();
        $mh = curl_multi_init();
        $map = array();

        foreach ($candidates as $cfg) {
            $url = rtrim($cfg['url'], '/') . '/chat/completions';
            $body = array(
                'model'       => $cfg['model'],
                'messages'    => $messages,
                'temperature' => $temperature,
            );
            if (isset($options['max_tokens'])) {
                $body['max_tokens'] = intval($options['max_tokens']);
            }
            $payload = xn_json_encode($body);
            if ($payload === false) continue;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $cfg['apiKey'],
                'Content-Type: application/json',
                'Accept: application/json',
            ));
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
            $map[(int)$ch] = $cfg;
        }

        $winner = null;
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                $select = curl_multi_select($mh, 1.0);
                if ($select == -1) usleep(10000);
            }
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $response = curl_multi_getcontent($ch);
                $cfg = isset($map[(int)$ch]) ? $map[(int)$ch] : null;
                curl_multi_remove_handle($mh, $ch);

                if ($cfg === null) continue;

                if ($httpCode == 200) {
                    $data = json_decode($response, true);
                    $content = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';
                    if ($content !== '' && $content !== null) {
                        // 找到赢家，清理剩余 handle 后返回
                        foreach ($handles as $h) {
                            if ((int)$h !== (int)$ch) {
                                curl_multi_remove_handle($mh, $h);
                            }
                        }
                        curl_multi_close($mh);

                        $usage = array(
                            'prompt'     => intval(isset($data['usage']['prompt_tokens']) ? $data['usage']['prompt_tokens'] : 0),
                            'completion' => intval(isset($data['usage']['completion_tokens']) ? $data['usage']['completion_tokens'] : 0),
                            'total'      => intval(isset($data['usage']['total_tokens']) ? $data['usage']['total_tokens'] : 0),
                        );
                        $this->markProviderOnline($cfg['provider_name']);

                        if (!empty($logBase)) {
                            $logBase['provider_name'] = $cfg['provider_name'];
                            $logBase['model'] = $cfg['model'];
                            $this->writeLog($logBase, $usage['prompt'], $usage['completion'], '', $usage['total'], $startTime, 1, (string)$content);
                        }

                        return array(
                            'code'    => 0,
                            'message' => '',
                            'data'    => array(
                                'content'       => (string)$content,
                                'usage'         => $usage,
                                'provider_name' => $cfg['provider_name'],
                                'model'         => $cfg['model'],
                                'time_ms'       => intval((microtime(true) - $startTime) * 1000),
                            ),
                        );
                    }
                }
                // 这个 handle 失败，标记离线继续等其他
                $this->markProviderOffline($cfg['provider_name']);
            }
        } while ($running > 0 && $winner === null);

        // 全部失败
        curl_multi_close($mh);
        if (!empty($logBase)) {
            $this->writeLog($logBase, 0, 0, 'concurrent: 所有 provider 均失败', 0, $startTime);
        }
        return array('code' => 1, 'message' => '所有 provider 均调用失败', 'data' => null);
    }

    /**
     * 注册内容后处理过滤器
     * 插件可注册多个过滤器对 AI 返回内容做过滤/模板/截断等后处理
     * 过滤器签名：function(string $content, array $context): string
     *   $context 含 key/messages/provider_name/options
     *   返回空字符串会中断后续过滤器
     *
     * @param string $key 功能标识
     * @param callable $callback 过滤回调
     */
    public static function registerPostFilter($key, $callback) {
        if (!is_callable($callback)) return;
        if (!isset(self::$postFilters[$key])) {
            self::$postFilters[$key] = array();
        }
        self::$postFilters[$key][] = $callback;
    }

    /**
     * 执行该功能的所有后处理过滤器
     * @param string $key 功能标识
     * @param string $content AI 返回内容
     * @param array $context 上下文
     * @return string 过滤后的内容
     */
    private function applyPostFilters($key, $content, $context) {
        if (empty(self::$postFilters[$key])) return $content;
        foreach (self::$postFilters[$key] as $cb) {
            $content = call_user_func($cb, $content, $context);
            if ($content === null || $content === '') return '';
        }
        return (string)$content;
    }

    // ---- provider 健康状态 ----

    /**
     * 加载健康状态缓存（kv 加载一次，请求内复用）
     */
    private function loadHealth() {
        if (self::$healthCache !== null) return;
        $data = function_exists('kv_get') ? kv_get('ai_provider_health') : false;
        self::$healthCache = is_array($data) ? $data : array();
    }

    /**
     * 标记 provider 在线
     * 仅状态变化时写 kv（减少写开销）
     * @param string $name provider 名
     */
    public function markProviderOnline($name) {
        $this->loadHealth();
        $name = (string)$name;
        if ($name === '') return;
        $prev = isset(self::$healthCache[$name]) ? self::$healthCache[$name] : null;
        self::$healthCache[$name] = array('online' => true, 'last_check' => time());
        if (empty($prev) || $prev['online'] !== true) {
            if (function_exists('kv_set')) kv_set('ai_provider_health', self::$healthCache);
        }
    }

    /**
     * 标记 provider 离线
     * 仅状态变化时写 kv
     * @param string $name provider 名
     */
    public function markProviderOffline($name) {
        $this->loadHealth();
        $name = (string)$name;
        if ($name === '') return;
        $prev = isset(self::$healthCache[$name]) ? self::$healthCache[$name] : null;
        self::$healthCache[$name] = array('online' => false, 'last_check' => time());
        if (empty($prev) || $prev['online'] !== false) {
            if (function_exists('kv_set')) kv_set('ai_provider_health', self::$healthCache);
        }
    }

    /**
     * 检查 provider 是否健康（或离线已超过恢复阈值）
     * 未知 provider 视为健康（首次调用允许尝试）
     *
     * @param string $name provider 名
     * @param int $recoverThreshold 离线恢复阈值秒数
     * @return bool
     */
    public function isProviderHealthy($name, $recoverThreshold = 600) {
        $this->loadHealth();
        $name = (string)$name;
        if (!isset(self::$healthCache[$name])) return true;
        $h = self::$healthCache[$name];
        if (!empty($h['online'])) return true;
        // 离线超过阈值，视为可恢复
        return (time() - intval(isset($h['last_check']) ? $h['last_check'] : 0)) >= $recoverThreshold;
    }

    /**
     * 取 provider 健康状态
     * @param string|null $name provider 名，null 返回全部
     * @return array|null
     */
    public function getProviderHealth($name = null) {
        $this->loadHealth();
        if ($name === null) return self::$healthCache;
        $name = (string)$name;
        return isset(self::$healthCache[$name]) ? self::$healthCache[$name] : null;
    }

    // ---- 日志 ----

    /**
     * 写入 AI 调用日志（委托 AILogService）
     * 内部封装，自动计算耗时
     */
    private function writeLog(array $logBase, $promptTokens, $completionTokens, $errorMsg, $totalTokens, $startTime, $status = 0, $responseContent = '') {
        if (!class_exists('AILogService')) {
            include_once APP_PATH . 'lib/AILogService.php';
        }
        $responseTime = intval((microtime(true) - $startTime) * 1000);
        AILogService::log(array(
            'uid'               => $logBase['uid'],
            'feature'           => $logBase['feature'],
            'source'            => $logBase['source'],
            'provider_name'     => $logBase['provider_name'],
            'model'             => $logBase['model'],
            'mode'              => $logBase['mode'],
            'prompt_tokens'     => intval($promptTokens),
            'completion_tokens' => intval($completionTokens),
            'total_tokens'      => intval($totalTokens),
            'response_time'     => $responseTime,
            'status'            => $status,
            'error_msg'         => $errorMsg,
            'request_summary'   => isset($logBase['request_summary']) ? $logBase['request_summary'] : '',
            'response_summary'  => $responseContent,
        ));
    }

    /**
     * 从 messages 数组生成请求摘要（前 200 字符）
     */
    private function summarizeMessages(array $messages) {
        $text = '';
        foreach ($messages as $m) {
            if (isset($m['content'])) $text .= $m['content'] . ' ';
        }
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') <= 200) return $text;
        return mb_substr($text, 0, 200, 'UTF-8');
    }

    /**
     * 测试 provider 连接
     * 从 conf.ai.providers 找对应 provider，发一个简单请求测试
     *
     * @param string $providerName provider 名称
     * @return array ['code'=>0成功/1失败, 'message'=>...]
     */
    public function testProvider($providerName) {
        $provider = $this->findProviderByName($providerName);
        if (empty($provider)) {
            return array('code' => 1, 'message' => 'Provider not found: ' . $providerName);
        }
        if (empty($provider['url']) || empty($provider['api_key'])) {
            return array('code' => 1, 'message' => 'Provider 配置不完整（缺少 url 或 api_key）');
        }

        // 取 models 第一个作为测试模型
        $model = $this->getFirstModel($provider);
        if (empty($model)) {
            return array('code' => 1, 'message' => 'Provider 未配置可用模型');
        }

        $config = array(
            'apiKey'        => $provider['api_key'],
            'model'         => $model,
            'url'           => $provider['url'],
            'provider_name' => $provider['name'],
        );
        $result = $this->callByConfig($config, array(array('role' => 'user', 'content' => 'ping')), array(
            'max_tokens' => 1,
            'temperature' => 0.0,
        ), array()); // 不写日志

        if ($result['code'] === 0) {
            $this->markProviderOnline($providerName);
        } else {
            $this->markProviderOffline($providerName);
        }
        return $result;
    }

    /**
     * 旧配置自动迁移
     * 1. conf.ai.models 旧字段: 遍历，把 apiKey 迁移到 conf.ai.providers 中同名 provider 的 api_key 字段，删除 models
     * 2. user.ai_config 单层结构的迁移在 getUserAiConfig() 中按需进行（读取时自动包装）
     *
     * @return bool 是否发生了 conf 迁移（调用方可据此决定是否写回 conf.php）
     */
    public function migrateLegacyConfig() {
        if (!isset($this->conf['ai']['models']) || !is_array($this->conf['ai']['models'])) {
            return false;
        }

        $oldModels = $this->conf['ai']['models'];
        if (!isset($this->conf['ai']['providers']) || !is_array($this->conf['ai']['providers'])) {
            $this->conf['ai']['providers'] = array();
        }
        $providers = &$this->conf['ai']['providers'];

        // 构建 name => 索引 映射，便于按 name 查找
        $providerIndex = array();
        foreach ($providers as $idx => $p) {
            if (isset($p['name'])) {
                $providerIndex[$p['name']] = $idx;
            }
        }

        foreach ($oldModels as $providerName => $modelConfig) {
            if (!is_array($modelConfig)) continue;
            $apiKey = isset($modelConfig['apiKey']) ? $modelConfig['apiKey'] : '';
            $endpoint = isset($modelConfig['endpoint']) ? $modelConfig['endpoint'] : '';
            $model = isset($modelConfig['model']) ? $modelConfig['model'] : '';

            if (isset($providerIndex[$providerName])) {
                // 已有同名 provider，补充 api_key 字段
                $idx = $providerIndex[$providerName];
                if (!empty($apiKey)) {
                    $providers[$idx]['api_key'] = $apiKey;
                }
                if (!empty($endpoint) && empty($providers[$idx]['url'])) {
                    $providers[$idx]['url'] = $endpoint;
                }
                if (!empty($model) && empty($providers[$idx]['models'])) {
                    $providers[$idx]['models'] = $model;
                }
            } else {
                // 新增 provider 项
                $newProvider = array(
                    'name' => $providerName,
                    'url' => $endpoint,
                    'api_key' => $apiKey,
                    'models' => $model,
                );
                $providers[] = $newProvider;
                $providerIndex[$providerName] = count($providers) - 1;
            }
        }

        unset($this->conf['ai']['models']);
        return true;
    }

    /**
     * 读取用户 ai_config（含旧数据迁移）
     * 检测旧版单层结构（有顶层 provider_name 但没有 editor 等功能 key）→ 包装为 {"editor": {...}}
     *
     * @param int $uid 用户ID
     * @param string|null $featureKey 功能标识，传 null 返回整个数组，传字符串返回该功能子数组
     * @return array
     */
    public function getUserAiConfig($uid, $featureKey = null) {
        $uid = intval($uid);
        if ($uid <= 0) return array();

        $user = $this->db->findOne('user', array('uid' => $uid));
        if (empty($user) || empty($user['ai_config'])) return array();

        $config = json_decode($user['ai_config'], true);
        if (!is_array($config)) return array();

        // 检测旧版单层结构：有顶层 provider_name 但没有 editor 等功能 key
        // 旧结构示例: {"provider_name":"openai","apiKey":"...","model":"...","url":"..."}
        if (isset($config['provider_name']) && !isset($config['editor'])) {
            $config = array('editor' => $config);
        }

        if ($featureKey !== null) {
            return isset($config[$featureKey]) && is_array($config[$featureKey]) ? $config[$featureKey] : array();
        }
        return $config;
    }

    /**
     * 保存用户某功能的配置
     * 读现有 → 设置 $config[$featureKey] = $config → json_encode → user_update
     *
     * @param int $uid 用户ID
     * @param string $featureKey 功能标识
     * @param array $config 该功能的配置数据
     * @return bool 是否保存成功
     */
    public function setUserAiConfig($uid, $featureKey, array $config) {
        $uid = intval($uid);
        if ($uid <= 0) return false;

        $current = $this->getUserAiConfig($uid);
        $current[$featureKey] = $config;
        $json = json_encode($current, JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;

        // user_update 仅过滤 password/password_hash 字段，ai_config 可正常更新
        if (function_exists('user_update')) {
            return user_update($uid, array('ai_config' => $json));
        }
        // 兜底：直接走 db（绕过缓存清理，仅在 user_update 不可用时使用）
        return $this->db->update('user', array('uid' => $uid), array('ai_config' => $json));
    }

    // ---- 私有方法 ----

    /**
     * 取全局配置（mode=global）
     * 从 conf.ai.providers 找 default_provider 的 api_key/url + default_model
     *
     * @param array $feature 功能配置
     * @return array 生效配置，配置不完整返回空数组
     */
    private function getGlobalConfig(array $feature) {
        $defaultProvider = isset($feature['default_provider']) ? $feature['default_provider'] : '';
        $defaultModel = isset($feature['default_model']) ? $feature['default_model'] : '';
        $allowedProviders = isset($feature['allowed_providers']) ? $feature['allowed_providers'] : array();

        // 1. allowed_providers 必须非空（前端强制校验，这里兜底）
        // 2. default_provider 必须在 allowed_providers 中
        $provider = null;
        if (!empty($defaultProvider) && in_array($defaultProvider, $allowedProviders, true)) {
            $provider = $this->findProviderByName($defaultProvider);
        }
        // default_provider 不在允许列表中，从 allowed 列表按顺序找第一个有 apiKey 的
        if (empty($provider) && !empty($allowedProviders)) {
            foreach ($allowedProviders as $pname) {
                $p = $this->findProviderByName($pname);
                if (!empty($p) && !empty($p['api_key'])) {
                    $provider = $p;
                    break;
                }
            }
        }
        if (empty($provider) || empty($provider['api_key'])) {
            return array();
        }

        $url = isset($provider['url']) ? $provider['url'] : '';
        // default_model 优先，其次取 provider.models 第一个
        $model = $defaultModel;
        if (empty($model)) {
            $model = $this->getFirstModel($provider);
        }

        if (empty($url) || empty($model)) {
            return array();
        }

        return array(
            'apiKey'        => $provider['api_key'],
            'model'         => $model,
            'url'           => $url,
            'provider_name' => $provider['name'],
        );
    }

    /**
     * 取用户生效配置（mode=user_key）
     * 从 user.ai_config.$key 读，url 可从 provider_name 反查 conf.ai.providers 得到
     * 任一缺失（apiKey/model/url）返回空数组
     *
     * @param string $key 功能标识
     * @param int|null $uid 用户ID
     * @param array $feature 功能配置
     * @return array 生效配置，配置不完整返回空数组
     */
    private function getUserEffectiveConfig($key, $uid, array $feature) {
        if ($uid === null || $uid <= 0) return array();

        $userConfig = $this->getUserAiConfig($uid, $key);
        if (empty($userConfig)) return array();

        $apiKey = isset($userConfig['apiKey']) ? $userConfig['apiKey'] : '';
        $model = isset($userConfig['model']) ? $userConfig['model'] : '';
        $url = isset($userConfig['url']) ? $userConfig['url'] : '';
        $providerName = isset($userConfig['provider_name']) ? $userConfig['provider_name'] : '';

        // user_key 模式下，provider_name 必须在 feature.allowed_providers 中
        // 用户用自带的 apiKey，但 provider 类型必须匹配（防止用户用 image 类型的 provider 调 text 功能）
        $allowedProviders = isset($feature['allowed_providers']) ? $feature['allowed_providers'] : array();
        if (!empty($providerName) && !empty($allowedProviders) && !in_array($providerName, $allowedProviders, true)) {
            return array();
        }

        // url 可从 provider_name 反查 conf.ai.providers 得到
        if (empty($url) && !empty($providerName)) {
            $provider = $this->findProviderByName($providerName);
            if (!empty($provider) && !empty($provider['url'])) {
                $url = $provider['url'];
            }
        }

        // 任一缺失返回空数组
        if (empty($apiKey) || empty($model) || empty($url)) {
            return array();
        }

        return array(
            'apiKey'        => $apiKey,
            'model'         => $model,
            'url'           => $url,
            'provider_name' => $providerName,
        );
    }

    /**
     * 从 conf.ai.providers 按 name 查找 provider
     *
     * @param string $name provider 名称
     * @return array|null 匹配的 provider 数组，未找到返回 null
     */
    private function findProviderByName($name) {
        if ($name === '') return null;
        $providers = isset($this->conf['ai']['providers']) ? $this->conf['ai']['providers'] : array();
        if (!is_array($providers)) return null;
        foreach ($providers as $p) {
            if (isset($p['name']) && $p['name'] === $name) {
                return $p;
            }
        }
        return null;
    }

    /**
     * 按 type 筛选 providers（可选地限制在 allowed 列表内）
     * 给前端 ai-features.htm 选择「默认提供商」时按功能 type 筛选用
     *
     * @param string $type provider 类型（text/image/video/audio/transcription）
     * @param array $allowedNames 可选，限制只返回这些 name 的 provider（空则返回所有匹配 type 的）
     * @return array 匹配的 provider 数组
     */
    public function getProvidersByType($type, $allowedNames = array()) {
        $providers = isset($this->conf['ai']['providers']) ? $this->conf['ai']['providers'] : array();
        if (!is_array($providers)) return array();
        $result = array();
        foreach ($providers as $p) {
            $pt = isset($p['type']) ? $p['type'] : 'text';
            if ($pt !== $type) continue;
            if (!empty($allowedNames) && !in_array(isset($p['name']) ? $p['name'] : '', $allowedNames, true)) continue;
            $result[] = $p;
        }
        return $result;
    }

    /**
     * 从 provider 取第一个「已启用」的模型
     * models 支持两种格式（兼容）：
     *   - 旧格式：逗号分隔字符串 'gpt-4o,gpt-4o-mini'
     *   - 新格式：[{name:'gpt-4o', enabled:1}, {name:'gpt-4o-mini', enabled:0}]
     *
     * @param array $provider provider 配置
     * @return string 第一个已启用模型名，无则返回空字符串
     */
    private function getFirstModel(array $provider) {
        $models = $this->getEnabledModels($provider);
        return !empty($models) ? $models[0] : '';
    }

    /**
     * 从 provider 取所有「已启用」的模型名数组
     * 兼容旧的逗号分隔字符串格式（自动转为新格式，全部视为启用）
     *
     * @param array $provider provider 配置
     * @return array 已启用模型名数组
     */
    public function getEnabledModels(array $provider) {
        $models = isset($provider['models']) ? $provider['models'] : '';
        if (empty($models)) return array();

        // 新格式：数组
        if (is_array($models)) {
            $result = array();
            foreach ($models as $m) {
                if (is_array($m)) {
                    $name = isset($m['name']) ? trim($m['name']) : '';
                    $enabled = !isset($m['enabled']) || $m['enabled'] == 1;
                    if ($name !== '' && $enabled) $result[] = $name;
                } else {
                    $name = trim((string)$m);
                    if ($name !== '') $result[] = $name;
                }
            }
            return $result;
        }

        // 旧格式：逗号分隔字符串（视为全部启用）
        $models = (string)$models;
        $arr = explode(',', $models);
        $result = array();
        foreach ($arr as $name) {
            $name = trim($name);
            if ($name !== '') $result[] = $name;
        }
        return $result;
    }
}

// 核心功能注册：编辑器 AI（type=text 文本生成，user_key 模式）
// 插件可在 model_inc_start 钩子中调用 AIService::registerFeature() 注册更多功能
// type 取值：text/image/video/audio/transcription，前端 ai-features.htm 据此筛选可用 provider
AIService::registerFeature('editor', array(
    'name'         => 'AIeditor',
    'type'         => 'text',
    'mode'         => 'user_key',
    'call_method'  => 'proxy',
));
