<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * 版块服务类
 * @since 4.5.0
 */
class ForumService {
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db) {
        $this->db = $db;
    }

    public function getForumById(int $fid): ?array {
        if ($fid <= 0) return null;
        return $this->db->findOne('forum', ['fid' => $fid]);
    }

    public function createForum(array $data): int {
        if (empty($data['name'])) throw new InvalidArgumentException('Forum name is required');
        $insert = [
            'name' => $data['name'],
            'rank' => $data['rank'] ?? 0,
            'threads' => 0,
            'posts' => 0,
            'todayposts' => 0,
            'todaythreads' => 0,
            'brief' => $data['brief'] ?? '',
            'announcement' => $data['announcement'] ?? '',
            'accesson' => $data['accesson'] ?? 0,
            'orderby' => $data['orderby'] ?? 0,
            'create_date' => $data['create_date'] ?? time(),
        ];
        return $this->db->insert('forum', $insert);
    }

    public function updateForum(int $fid, array $data): int {
        if ($fid <= 0) return 0;
        return $this->db->update('forum', ['fid' => $fid], $data);
    }

    public function deleteForum(int $fid): int {
        if ($fid <= 0) return 0;
        return $this->db->delete('forum', ['fid' => $fid]);
    }

    public function getForumList(array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 1000): array {
        if (empty($orderby)) $orderby = ['rank' => 1, 'fid' => 1];
        return $this->db->find('forum', $cond, $orderby, $page, $pagesize, 'fid');
    }

    public function checkAccess(int $fid, int $gid, string $perm): bool {
        $forum = $this->getForumById($fid);
        if (!$forum) return false;
        if (empty($forum['accesson'])) return true;
        $access = $this->db->findOne('forum_access', ['fid' => $fid, 'gid' => $gid]);
        return !empty($access[$perm]);
    }

    /**
     * 批量获取多个版块
     * @param array $fids
     * @return array
     */
    public function getForumsByIds(array $fids): array {
        if (empty($fids)) return [];
        $validFids = array_filter(array_map('intval', $fids), fn($fid) => $fid > 0);
        if (empty($validFids)) return [];
        $placeholders = rtrim(str_repeat('?,', count($validFids)), ',');
        $sql = "SELECT * FROM " . $this->db->table('forum') . " WHERE fid IN ({$placeholders})";
        $stmt = $this->db->execute($sql, $validFids);
        $result = $stmt->fetchAll();
        return $result ? $result : [];
    }

    /**
     * 获取版块树形结构
     * 查询所有版块，构建父子层级关系，包含最后发帖信息和版主列表
     * @return array 树形版块列表
     */
    public function getForumTree(): array {
        // 查询所有版块，按 rank 降序、fid 升序排列
        $allForums = $this->db->find('forum', [], ['rank' => -1, 'fid' => 1], 1, 1000, 'fid');
        if (empty($allForums)) return [];

        // 获取每个版块的最新帖子（用于 last_post 信息）
        $lastPosts = $this->getLastPostsByForums();

        // 构建版块树
        $tree = [];
        $childrenMap = []; // fup => 子版块列表

        foreach ($allForums as $fid => $forum) {
            $item = $this->formatForumItem($forum, $lastPosts);

            $fup = intval($forum['fup'] ?? 0);
            if ($fup === 0) {
                // 顶级版块/分区
                $item['children'] = [];
                $tree[$fid] = $item;
            } else {
                // 子版块，暂存到 childrenMap
                if (!isset($childrenMap[$fup])) {
                    $childrenMap[$fup] = [];
                }
                $childrenMap[$fup][] = $item;
            }
        }

        // 将子版块挂载到对应的父版块
        foreach ($childrenMap as $fup => $children) {
            if (isset($tree[$fup])) {
                $tree[$fup]['children'] = $children;
            }
        }

        // 重新索引为数字索引数组
        return array_values($tree);
    }

    /**
     * 获取各版块最新帖子信息
     * @return array fid => last_post 数据
     */
    private function getLastPostsByForums(): array {
        $sql = "SELECT t.fid, t.subject AS title, t.last_date AS time, u.username
                FROM " . $this->db->table('thread') . " t
                LEFT JOIN " . $this->db->table('user') . " u ON t.lastuid = u.uid
                WHERE t.tid IN (
                    SELECT MAX(tid) FROM " . $this->db->table('thread') . " GROUP BY fid
                )";
        $rows = $this->db->sqlFind($sql);
        $result = [];
        foreach ($rows as $row) {
            $result[intval($row['fid'])] = [
                'title' => $row['title'] ?? '',
                'username' => $row['username'] ?? '',
                'time' => intval($row['time'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * 格式化单个版块项
     * @param array $forum 版块原始数据
     * @param array $lastPosts 各版块最新帖子信息
     * @return array 格式化后的版块数据
     */
    private function formatForumItem(array $forum, array $lastPosts): array {
        $fid = intval($forum['fid']);
        $fup = intval($forum['fup'] ?? 0);

        // 判断是否为分区（type=1 为分区，type=0 为版块）
        $isCategory = !empty($forum['type']) && intval($forum['type']) === 1;

        // 版块图标：icon 包含 . 或 / 视为图片路径，否则视为 Tabler Icon 类名
        $iconUrl = '';
        $iconClass = '';
        if (!empty($forum['icon']) && (strpos($forum['icon'], '.') !== false || strpos($forum['icon'], '/') !== false)) {
            $iconUrl = $forum['icon'];
            $iconClass = '';
        } elseif (!empty($forum['icon'])) {
            $iconUrl = '';
            $iconClass = $forum['icon'];
        } else {
            // 默认图标
            $iconClass = 'ti ti-message-circle';
        }

        // 版主列表：从 moduids 字段解析
        $moderators = [];
        if (!empty($forum['moduids'])) {
            $moduids = array_filter(array_map('intval', explode(',', $forum['moduids'])));
            if (!empty($moduids)) {
                $uidList = implode(',', $moduids);
                $sql = "SELECT uid, username FROM " . $this->db->table('user') . " WHERE uid IN ({$uidList})";
                $modRows = $this->db->sqlFind($sql);
                foreach ($modRows as $mod) {
                    $moderators[] = [
                        'uid' => intval($mod['uid']),
                        'username' => $mod['username'] ?? '',
                    ];
                }
            }
        }

        // 最后发帖信息
        $lastPost = isset($lastPosts[$fid]) ? $lastPosts[$fid] : [
            'title' => '',
            'username' => '',
            'time' => 0,
        ];

        return [
            'fid' => $fid,
            'name' => $forum['name'] ?? '',
            'description' => $forum['brief'] ?? '',
            'icon_class' => $iconClass,
            'icon_url' => $iconUrl,
            'today_posts' => intval($forum['todayposts'] ?? 0),
            'threads' => intval($forum['threads'] ?? 0),
            'follows' => intval($forum['follows'] ?? 0),
            'last_post' => $lastPost,
            'moderators' => $moderators,
            'is_category' => $isCategory,
        ];
    }

    /**
     * 关注版块
     */
    public function followForum(int $uid, int $fid): array {
        if (empty($uid) || empty($fid)) {
            return ['code' => -1, 'msg' => '参数错误'];
        }
        // 检查版块是否存在
        $forum = $this->db->findOne('forum', ['fid' => $fid]);
        if (empty($forum)) {
            return ['code' => -1, 'msg' => '版块不存在'];
        }
        $r = forum_follow_create($uid, $fid);
        if ($r === FALSE) {
            return ['code' => -1, 'msg' => '已关注或操作失败'];
        }
        $forum = $this->db->findOne('forum', ['fid' => $fid]);
        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'followed' => true,
                'follows' => intval($forum['follows'] ?? 0)
            ]
        ];
    }

    /**
     * 取消关注版块
     */
    public function unfollowForum(int $uid, int $fid): array {
        if (empty($uid) || empty($fid)) {
            return ['code' => -1, 'msg' => '参数错误'];
        }
        $r = forum_follow_delete($uid, $fid);
        if ($r === FALSE) {
            return ['code' => -1, 'msg' => '操作失败'];
        }
        $forum = $this->db->findOne('forum', ['fid' => $fid]);
        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'followed' => false,
                'follows' => intval($forum['follows'] ?? 0)
            ]
        ];
    }

    /**
     * 检查用户是否关注了版块
     */
    public function isFollowed(int $uid, int $fid): bool {
        if (empty($uid) || empty($fid)) return false;
        $follow = forum_follow_read($uid, $fid);
        return !empty($follow);
    }

    /**
     * 批量检查用户关注状态
     */
    public function checkFollowBatch(int $uid, array $fids): array {
        if (empty($uid) || empty($fids)) return [];
        return forum_follow_check_batch($uid, $fids);
    }
}
