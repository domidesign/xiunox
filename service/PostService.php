<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * 回复服务类
 * @since 1.0.2
 */
class PostService {
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db) {
        $this->db = $db;
    }

    /**
     * 根据PID获取回复
     * @param int $pid
     * @return array|null
     */
    public function getPostById(int $pid): ?array {
        if ($pid <= 0) return null;
        return $this->db->findOne('post', ['pid' => $pid]);
    }

    /**
     * 创建回复
     * @param array $data
     * @return int
     */
    public function createPost(array $data): int {
        if (empty($data['tid'])) throw new InvalidArgumentException('Thread ID is required');
        if (empty($data['uid'])) throw new InvalidArgumentException('User ID is required');

        $insert = [
            'tid' => $data['tid'],
            'isfirst' => $data['isfirst'] ?? 0,
            'uid' => $data['uid'],
            'create_date' => $data['create_date'] ?? time(),
            'userip' => $data['userip'] ?? 0,
            'message' => $data['message'] ?? '',
            'message_fmt' => $data['message_fmt'] ?? '',
            'doctype' => $data['doctype'] ?? 0,
            'quotepid' => $data['quotepid'] ?? 0,
        ];

        return $this->db->insert('post', $insert);
    }

    /**
     * 更新回复
     * @param int $pid
     * @param array $data
     * @return int
     */
    public function updatePost(int $pid, array $data): int {
        if ($pid <= 0) return 0;
        return $this->db->update('post', ['pid' => $pid], $data);
    }

    /**
     * 删除回复
     * @param int $pid
     * @return int
     */
    public function deletePost(int $pid): int {
        if ($pid <= 0) return 0;
        return $this->db->delete('post', ['pid' => $pid]);
    }

    /**
     * 获取帖子下的回复列表
     * @param int $tid
     * @param int $page
     * @param int $pagesize
     * @return array
     */
    public function getPostListByTid(int $tid, int $page = 1, int $pagesize = 20): array {
        if ($tid <= 0) return [];
        return $this->db->find('post', ['tid' => $tid], ['pid' => 1], $page, $pagesize, 'pid');
    }

    /**
     * 获取用户发布的所有帖子列表
     * @param int $uid
     * @param int $page
     * @param int $pagesize
     * @return array
     */
    public function getPostListByUid(int $uid, int $page = 1, int $pagesize = 20): array {
        if ($uid <= 0) return [];
        return $this->db->find('post', ['uid' => $uid], [], $page, $pagesize, 'pid');
    }

    /**
     * 获取所有帖子列表
     * @param int $page
     * @param int $pagesize
     * @return array
     */
    public function getPostList(int $page = 1, int $pagesize = 20): array {
        return $this->db->find('post', [], [], $page, $pagesize, 'pid');
    }

    public function getPostCountByUid(int $uid): int {
        if ($uid <= 0) return 0;
        return $this->db->count('post', ['uid' => $uid]);
    }

    public function batchDelete(array $pids): int {
        if (empty($pids)) return 0;
        $count = 0;
        foreach ($pids as $pid) {
            $pid = intval($pid);
            if ($pid > 0) {
                $this->db->delete('post', ['pid' => $pid]);
                $count++;
            }
        }
        return $count;
    }
}
