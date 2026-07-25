<?php

/**
 * AILogService - AI 调用日志统一接口
 *
 * 核心和插件都通过此接口记录 AI 调用日志，后台统一查看。
 * 日志表：xnx_ai_call_log（通过 admin/?upgrade.htm 的 ai_call_log 步骤创建）
 *
 * 使用示例：
 *   AILogService::log([
 *       'uid' => $uid,
 *       'feature' => 'editor',
 *       'source' => 'core',
 *       'provider_name' => 'openai',
 *       'model' => 'gpt-4o-mini',
 *       'mode' => 'global',
 *       'prompt_tokens' => 100,
 *       'completion_tokens' => 200,
 *       'total_tokens' => 300,
 *       'response_time' => 1500,
 *       'status' => 1,
 *       'error_msg' => '',
 *       'request_summary' => '请帮我续写...',
 *       'response_summary' => '好的，以下是续写内容...',
 *   ]);
 */
class AILogService {

    private static $table = 'xnx_ai_call_log';

    /**
     * 写入一条 AI 调用日志
     *
     * @param array $data 日志字段：
     *   - uid:             用户ID（必填）
     *   - feature:         功能标识（editor / xnx_ai_reply 等）
     *   - source:          来源（core=核心 / 插件目录名）
     *   - provider_name:   提供商名称
     *   - model:           模型名
     *   - mode:            模式（global/user_key/both）
     *   - prompt_tokens:   请求 token 数
     *   - completion_tokens: 响应 token 数
     *   - total_tokens:    总 token 数
     *   - response_time:   响应耗时（毫秒）
     *   - status:          0=失败 1=成功
     *   - error_msg:       错误信息（失败时，最多 500 字符）
     *   - request_summary: 请求摘要（最多 200 字符，超出截断）
     *   - response_summary: 响应摘要（最多 500 字符，超出截断）
     * @return int|false 插入的 log_id，失败返回 false
     */
    public static function log(array $data) {
        global $time, $longip;

        // 表不存在时静默跳过（升级未执行的情况）
        if (!function_exists('db_insert')) return false;

        $uid = intval(isset($data['uid']) ? $data['uid'] : 0);
        $insert = array(
            'uid'               => $uid,
            'feature'           => self::truncate(isset($data['feature']) ? $data['feature'] : '', 32),
            'source'            => self::truncate(isset($data['source']) ? $data['source'] : 'core', 32),
            'provider_name'     => self::truncate(isset($data['provider_name']) ? $data['provider_name'] : '', 64),
            'model'             => self::truncate(isset($data['model']) ? $data['model'] : '', 64),
            'mode'              => self::truncate(isset($data['mode']) ? $data['mode'] : '', 16),
            'prompt_tokens'     => intval(isset($data['prompt_tokens']) ? $data['prompt_tokens'] : 0),
            'completion_tokens' => intval(isset($data['completion_tokens']) ? $data['completion_tokens'] : 0),
            'total_tokens'      => intval(isset($data['total_tokens']) ? $data['total_tokens'] : 0),
            'response_time'     => intval(isset($data['response_time']) ? $data['response_time'] : 0),
            'status'            => intval(isset($data['status']) ? $data['status'] : 0),
            'error_msg'         => self::truncate(isset($data['error_msg']) ? $data['error_msg'] : '', 500),
            'request_summary'   => self::truncate(isset($data['request_summary']) ? $data['request_summary'] : '', 200),
            'response_summary'  => self::truncate(isset($data['response_summary']) ? $data['response_summary'] : '', 500),
            'ip'                => intval(isset($longip) ? $longip : 0),
            'create_time'       => intval(isset($data['create_time']) ? $data['create_time'] : $time),
        );
        return db_insert(self::$table, $insert);
    }

    /**
     * 分页查询日志
     *
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @param array $filters 筛选条件：
     *   - feature: 功能标识
     *   - source: 来源
     *   - status: 状态（0/1）
     *   - uid: 用户ID
     *   - start_time: 起始时间戳
     *   - end_time: 结束时间戳
     * @return array 日志列表
     */
    public static function getLogs($page = 1, $pagesize = 20, $filters = array()) {
        $page = max(1, intval($page));
        $pagesize = max(1, intval($pagesize));
        $cond = array();
        if (!empty($filters['feature'])) $cond['feature'] = $filters['feature'];
        if (!empty($filters['source'])) $cond['source'] = $filters['source'];
        if (isset($filters['status']) && $filters['status'] !== '') $cond['status'] = intval($filters['status']);
        if (!empty($filters['uid'])) $cond['uid'] = intval($filters['uid']);
        if (!empty($filters['start_time'])) $cond['create_time'] = array('>=' => intval($filters['start_time']));
        if (!empty($filters['end_time'])) {
            $cond['create_time'] = isset($cond['create_time'])
                ? array('>=' => intval($filters['start_time']), '<=' => intval($filters['end_time']))
                : array('<=' => intval($filters['end_time']));
        }
        return db_find(self::$table, $cond, array('id' => -1), $page, $pagesize);
    }

    /**
     * 统计日志数量
     *
     * @param array $filters 筛选条件（同 getLogs）
     * @return int 总数
     */
    public static function countLogs($filters = array()) {
        $cond = array();
        if (!empty($filters['feature'])) $cond['feature'] = $filters['feature'];
        if (!empty($filters['source'])) $cond['source'] = $filters['source'];
        if (isset($filters['status']) && $filters['status'] !== '') $cond['status'] = intval($filters['status']);
        if (!empty($filters['uid'])) $cond['uid'] = intval($filters['uid']);
        if (!empty($filters['start_time'])) $cond['create_time'] = array('>=' => intval($filters['start_time']));
        if (!empty($filters['end_time'])) {
            $cond['create_time'] = isset($cond['create_time'])
                ? array('>=' => intval($filters['start_time']), '<=' => intval($filters['end_time']))
                : array('<=' => intval($filters['end_time']));
        }
        return db_count(self::$table, $cond);
    }

    /**
     * 按 source 统计今日/本周/本月的调用次数和 token 用量
     *
     * @param string $period today/week/month
     * @return array [source => [count, success, fail, total_tokens], ...]
     */
    public static function getStatsBySource($period = 'today') {
        global $time;
        if ($period == 'week') {
            $start = strtotime('monday this week 00:00:00', $time);
        } elseif ($period == 'month') {
            $start = strtotime(date('Y-m-01 00:00:00', $time));
        } else {
            $start = strtotime(date('Y-m-d 00:00:00', $time));
        }
        // 联表查询，db_find 不支持 GROUP BY，保留 db_sql_find
        global $db;
        $tablepre = $db->tablepre;
        $sql = "SELECT source,
                    COUNT(*) as cnt,
                    SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN status=0 THEN 1 ELSE 0 END) as fail,
                    SUM(total_tokens) as total_tokens
                FROM `{$tablepre}xnx_ai_call_log`
                WHERE create_time >= {$start}
                GROUP BY source";
        $rows = db_sql_find($sql);
        $stats = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $stats[$row['source']] = array(
                    'count' => intval($row['cnt']),
                    'success' => intval($row['success']),
                    'fail' => intval($row['fail']),
                    'total_tokens' => intval($row['total_tokens']),
                );
            }
        }
        return $stats;
    }

    /**
     * 截断字符串到指定长度（多字节安全）
     */
    private static function truncate($s, $len) {
        $s = (string)$s;
        if (mb_strlen($s, 'UTF-8') <= $len) return $s;
        return mb_substr($s, 0, $len, 'UTF-8');
    }
}
