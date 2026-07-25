<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * 帖子服务类
 * @since 1.0.2
 */
class ThreadService {
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db) {
        $this->db = $db;
    }

    /**
     * 根据TID获取帖子
     * @param int $tid
     * @return array|null
     */
    public function getThreadById(int $tid): ?array {
        if ($tid <= 0) return null;
        return $this->db->findOne('thread', ['tid' => $tid]);
    }

    /**
     * 创建帖子
     * @param array $data
     * @return int
     */
    public function createThread(array $data): int {
        if (empty($data['fid'])) throw new InvalidArgumentException('Forum ID is required');
        if (empty($data['subject'])) throw new InvalidArgumentException('Subject is required');
        if (empty($data['uid'])) throw new InvalidArgumentException('User ID is required');

        $insert = [
            'fid' => $data['fid'],
            'subject' => $data['subject'],
            'uid' => $data['uid'],
            'create_date' => $data['create_date'] ?? time(),
            'last_date' => $data['last_date'] ?? time(),
            'lastpid' => $data['lastpid'] ?? 0,
            'views' => 0,
            'posts' => 0,
            'top' => $data['top'] ?? 0,
            'closed' => $data['closed'] ?? 0,
            'type' => $data['type'] ?? 0,
        ];

        return $this->db->insert('thread', $insert);
    }

    /**
     * 更新帖子
     * @param int $tid
     * @param array $data
     * @return int
     */
    public function updateThread(int $tid, array $data): int {
        if ($tid <= 0) return 0;
        return $this->db->update('thread', ['tid' => $tid], $data);
    }

    /**
     * 删除帖子
     * @param int $tid
     * @return int
     */
    public function deleteThread(int $tid): int {
        if ($tid <= 0) return 0;
        return $this->db->delete('thread', ['tid' => $tid]);
    }

    /**
     * 获取帖子列表
     * @param array $cond
     * @param array $orderby
     * @param int $page
     * @param int $pagesize
     * @return array
     */
    public function getThreadList(array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 20): array {
        if (empty($orderby)) $orderby = ['tid' => -1];
        return $this->db->find('thread', $cond, $orderby, $page, $pagesize, 'tid');
    }

    /**
     * 设置帖子类型
     * @param int $tid
     * @param int $type
     * @return int
     */
    public function setType(int $tid, int $type): int {
        if ($tid <= 0) return 0;
        return $this->db->update('thread', ['tid' => $tid], ['type' => $type]);
    }

    /**
     * 设置帖子元数据
     * @param int $tid
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function setMeta(int $tid, string $key, string $value): bool {
        if ($tid <= 0 || empty($key)) return false;
        $existing = $this->db->findOne('thread_meta', ['tid' => $tid, 'meta_key' => $key]);
        if ($existing) {
            $this->db->update('thread_meta', ['id' => $existing['id']], ['meta_value' => $value]);
        } else {
            $this->db->insert('thread_meta', [
                'tid' => $tid,
                'meta_key' => $key,
                'meta_value' => $value,
            ]);
        }
        return true;
    }

    /**
     * 获取帖子元数据
     * @param int $tid
     * @param string $key
     * @return string|null
     */
    public function getMeta(int $tid, string $key): ?string {
        if ($tid <= 0 || empty($key)) return null;
        $row = $this->db->findOne('thread_meta', ['tid' => $tid, 'meta_key' => $key]);
        return $row ? $row['meta_value'] : null;
    }

    public function likeThread(int $tid, int $uid): bool {
        if ($tid <= 0 || $uid <= 0) return false;
        $existing = $this->db->findOne('thread_like', ['tid' => $tid, 'uid' => $uid]);
        if ($existing) return true;
        $this->db->insert('thread_like', ['tid' => $tid, 'uid' => $uid, 'create_date' => time()]);
        return true;
    }

    public function unlikeThread(int $tid, int $uid): bool {
        if ($tid <= 0 || $uid <= 0) return false;
        $this->db->delete('thread_like', ['tid' => $tid, 'uid' => $uid]);
        return true;
    }

    public function isLiked(int $tid, int $uid): bool {
        if ($tid <= 0 || $uid <= 0) return false;
        $row = $this->db->findOne('thread_like', ['tid' => $tid, 'uid' => $uid]);
        return !empty($row);
    }

    public function favoriteThread(int $tid, int $uid): bool {
        if ($tid <= 0 || $uid <= 0) return false;
        $existing = $this->db->findOne('thread_favorite', ['tid' => $tid, 'uid' => $uid]);
        if ($existing) return true;
        $this->db->insert('thread_favorite', ['tid' => $tid, 'uid' => $uid, 'create_date' => time()]);
        return true;
    }

    public function unfavoriteThread(int $tid, int $uid): bool {
        if ($tid <= 0 || $uid <= 0) return false;
        $this->db->delete('thread_favorite', ['tid' => $tid, 'uid' => $uid]);
        return true;
    }

    public function isFavorited(int $tid, int $uid): bool {
        if ($tid <= 0 || $uid <= 0) return false;
        $row = $this->db->findOne('thread_favorite', ['tid' => $tid, 'uid' => $uid]);
        return !empty($row);
    }

    public function reportThread(int $tid, int $uid, string $reason): int {
        if ($tid <= 0 || $uid <= 0) return 0;
        return $this->db->insert('thread_report', ['tid' => $tid, 'uid' => $uid, 'reason' => $reason, 'create_date' => time()]);
    }

    public function batchDelete(array $tids): int {
        if (empty($tids)) return 0;
        $count = 0;
        foreach ($tids as $tid) {
            $tid = intval($tid);
            if ($tid > 0) {
                $this->db->delete('thread', ['tid' => $tid]);
                $count++;
            }
        }
        return $count;
    }

    public function batchUpdate(array $tids, array $data): int {
        if (empty($tids) || empty($data)) return 0;
        $allowed = ['top' => 1, 'closed' => 1, 'type' => 1];
        $update = array_intersect_key($data, $allowed);
        if (empty($update)) return 0;
        $count = 0;
        foreach ($tids as $tid) {
            $tid = intval($tid);
            if ($tid > 0) {
                $this->db->update('thread', ['tid' => $tid], $update);
                $count++;
            }
        }
        return $count;
    }

    public function getThreadsByUid(int $uid, int $page = 1, int $pagesize = 20): array {
        if ($uid <= 0) return [];
        return $this->db->find('thread', ['uid' => $uid], ['tid' => -1], $page, $pagesize, 'tid');
    }

    public function getFavoritesByUid(int $uid, int $page = 1, int $pagesize = 20): array {
        if ($uid <= 0) return [];
        $offset = ($page - 1) * $pagesize;
        $favorites = $this->db->find('thread_favorite', ['uid' => $uid], ['id' => -1], $page, $pagesize, 'id');
        $tids = array_column($favorites, 'tid');
        if (empty($tids)) return [];
        $threads = [];
        foreach ($tids as $tid) {
            $thread = $this->getThreadById(intval($tid));
            if ($thread) $threads[] = $thread;
        }
        return $threads;
    }

    public function getFavoriteCount(int $uid): int {
        if ($uid <= 0) return 0;
        return $this->db->count('thread_favorite', ['uid' => $uid]);
    }

    public function getThreadCountByUid(int $uid): int {
        if ($uid <= 0) return 0;
        return $this->db->count('thread', ['uid' => $uid]);
    }

    /**
     * 批量获取多个帖子
     * @param array $tids
     * @return array
     */
    public function getThreadsByIds(array $tids): array {
        if (empty($tids)) return [];
        $validTids = array_filter(array_map('intval', $tids), fn($tid) => $tid > 0);
        if (empty($validTids)) return [];
        $placeholders = rtrim(str_repeat('?,', count($validTids)), ',');
        $sql = "SELECT * FROM " . $this->db->table('thread') . " WHERE tid IN ({$placeholders})";
        // ponytail: db_pdo_mysql 无 execute() 方法，用 prepare() 返回 PDOStatement
        $stmt = $this->db->prepare($sql, $validTids);
        if (!$stmt) return [];
        $result = $stmt->fetchAll();
        return $result ? $result : [];
    }
}
