<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * 排行榜服务类
 * 提供热帖排行、活跃用户、积分排行等功能
 * @since 1.0.2
 */
class RankService {
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db) {
        $this->db = $db;
    }

    /**
     * 获取热帖排行
     * 按浏览量+回复数综合得分排序
     * @param string $period 时间周期：week=近7天, month=近30天, all=全部
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array 包含 list 和 total
     */
    public function getHotThreads(string $period, int $page, int $pageSize, bool $isAdmin = false): array {
        // 缓存 5 分钟：不同用户组看到的权限过滤结果不同，按 gid 区分缓存
        global $gid;
        $cacheKey = 'rank_hot_threads_' . $period . '_' . intval($page) . '_' . intval($pageSize) . '_gid' . intval($gid) . ($isAdmin ? '_admin' : '');
        $cached = function_exists('cache_get') ? cache_get($cacheKey) : NULL;
        if($cached !== NULL && $cached !== FALSE) return $cached;

        $pre = $this->db->tablepre;

        // 根据时间周期构建条件
        $timeCond = '';
        if ($period === 'week') {
            $timeCond = ' WHERE t.create_date >= ' . (time() - 7 * 86400);
        } elseif ($period === 'month') {
            $timeCond = ' WHERE t.create_date >= ' . (time() - 30 * 86400);
        }
        // period === 'all' 时不加时间条件

        $offset = ($page - 1) * $pageSize;

        // 查询列表，按 views+posts 综合得分降序，非管理员只显示审核通过的帖子
        // 多查一些用于版块权限过滤后仍能填满 pageSize
        $fetchSize = $pageSize * 3;
        $whereAudit = $isAdmin ? '' : (($timeCond ? ' AND' : ' WHERE') . ' t.audit_status = 1');
        $sql = "SELECT t.tid, t.subject AS title, t.uid, t.posts AS replies, t.views, t.last_date, t.fid, t.audit_status,
                       IFNULL(NULLIF(u.nickname,''), u.username) AS username
                FROM {$pre}thread t
                LEFT JOIN {$pre}user u ON t.uid = u.uid
                {$timeCond}{$whereAudit}
                ORDER BY (t.views + t.posts) DESC
                LIMIT {$offset}, {$fetchSize}";

        $list = $this->db->sqlFind($sql);

        // 版块权限过滤
        global $gid, $forumlist;
        if(!empty($list) && $gid > 2) {
            $list = array_filter($list, function($row) use ($gid, $forumlist) {
                $fid = intval($row['fid']);
                if(!empty($forumlist[$fid]['accesson']) && !forum_access_user($fid, $gid, 'allowread')) {
                    return false;
                }
                return true;
            });
        }
        // 无论是否进行权限过滤，都截断到 pageSize，避免多查的 3 倍数据被返回
        $list = array_slice(array_values($list), 0, $pageSize);

        // 格式化输出
        $items = array_map(function($row) {
            return [
                'tid' => intval($row['tid']),
                'title' => $row['title'] ?? '',
                'author' => $row['username'] ?? '',
                'replies' => intval($row['replies']),
                'views' => intval($row['views']),
                'last_reply_time' => !empty($row['last_date'])
                    ? date('c', intval($row['last_date']))
                    : null,
            ];
        }, $list);

        // 查询总数（非管理员排除待审帖子）
        $countWhereAudit = $isAdmin ? '' : $whereAudit;
        $countSql = "SELECT COUNT(*) AS total FROM {$pre}thread t{$timeCond}{$countWhereAudit}";
        $countRow = $this->db->sqlFindOne($countSql);
        $total = !empty($countRow) ? intval($countRow['total']) : 0;

        $result = ['list' => $items, 'total' => $total];
        if(function_exists('cache_set')) cache_set($cacheKey, $result, 300);
        return $result;
    }

    /**
     * 获取活跃用户排行
     * 按发帖+回帖总数排序
     * @param string $period 时间周期（简化处理，按用户总发帖量排序）
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array 包含 list 和 total
     */
    public function getActiveUsers(string $period, int $page, int $pageSize): array {
        // 缓存 5 分钟
        $cacheKey = 'rank_active_users_' . $period . '_' . intval($page) . '_' . intval($pageSize);
        $cached = function_exists('cache_get') ? cache_get($cacheKey) : NULL;
        if($cached !== NULL && $cached !== FALSE) return $cached;

        $pre = $this->db->tablepre;

        $offset = ($page - 1) * $pageSize;

        // 按用户 threads+posts 总数排序
        $sql = "SELECT u.uid, IFNULL(NULLIF(u.nickname,''), u.username) AS username, u.avatar, u.threads, u.posts, u.create_date, u.gid,
                       COALESCE(u.credits, 0) AS credits
                FROM {$pre}user u
                ORDER BY (u.threads + u.posts) DESC
                LIMIT {$offset}, {$pageSize}";

        $list = $this->db->sqlFind($sql);

        // 格式化输出
        $items = array_map(function($row) {
            // 头像URL处理：保留原始相对路径，前端 avatar_component_from_data 有 onerror 回退
            $avatarUrl = !empty($row['avatar']) ? $row['avatar'] : '/view/img/avatar.png';

            return [
                'uid' => intval($row['uid']),
                'username' => $row['username'] ?? '',
                'avatar_url' => $avatarUrl,
                'gid' => intval($row['gid'] ?? 0),
                'posts' => intval($row['threads']) + intval($row['posts']),
                'reputation' => intval($row['credits']),
                'registered_at' => !empty($row['create_date'])
                    ? date('c', intval($row['create_date']))
                    : null,
            ];
        }, $list);

        // 查询总数
        $countSql = "SELECT COUNT(*) AS total FROM {$pre}user";
        $countRow = $this->db->sqlFindOne($countSql);
        $total = !empty($countRow) ? intval($countRow['total']) : 0;

        $result = ['list' => $items, 'total' => $total];
        if(function_exists('cache_set')) cache_set($cacheKey, $result, 300);
        return $result;
    }

    /**
     * 获取积分排行
     * 按用户积分降序排序
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array 包含 list 和 total
     */
    public function getCreditsRanking(int $page, int $pageSize): array {
        // 缓存 5 分钟
        $cacheKey = 'rank_credits_' . intval($page) . '_' . intval($pageSize);
        $cached = function_exists('cache_get') ? cache_get($cacheKey) : NULL;
        if($cached !== NULL && $cached !== FALSE) return $cached;

        $pre = $this->db->tablepre;

        $offset = ($page - 1) * $pageSize;

        // 按用户积分降序排序
        $sql = "SELECT u.uid, IFNULL(NULLIF(u.nickname,''), u.username) AS username, u.avatar, u.credits, u.golds, u.threads, u.posts, u.gid
                FROM {$pre}user u
                ORDER BY u.credits DESC
                LIMIT {$offset}, {$pageSize}";

        $list = $this->db->sqlFind($sql);

        // 格式化输出
        $items = array_map(function($row) {
            // 头像URL处理：保留原始相对路径，前端 avatar_component_from_data 有 onerror 回退
            $avatarUrl = !empty($row['avatar']) ? $row['avatar'] : '/view/img/avatar.png';

            return [
                'uid' => intval($row['uid']),
                'username' => $row['username'] ?? '',
                'avatar_url' => $avatarUrl,
                'gid' => intval($row['gid'] ?? 0),
                'credits' => intval($row['credits']),
                'golds' => intval($row['golds']),
                'threads' => intval($row['threads']),
                'posts' => intval($row['posts']),
            ];
        }, $list);

        // 查询总数
        $countSql = "SELECT COUNT(*) AS total FROM {$pre}user";
        $countRow = $this->db->sqlFindOne($countSql);
        $total = !empty($countRow) ? intval($countRow['total']) : 0;

        $result = ['list' => $items, 'total' => $total];
        if(function_exists('cache_set')) cache_set($cacheKey, $result, 300);
        return $result;
    }

}
