<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * 通知服务类
 * @since 4.5.0
 */
class NotificationService {
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db) {
        $this->db = $db;
    }

    /**
     * 发送通知
     * @param int $uid 接收用户ID
     * @param string $type 通知类型（reply/mention/system 等）
     * @param array $data 通知数据
     * @return int
     */
    public function send(int $uid, string $type, array $data): int {
        if ($uid <= 0 || empty($type)) return 0;
        $insert = [
            'uid' => $uid,
            'type' => $type,
            'content' => $data['content'] ?? '',
            'related_id' => $data['related_id'] ?? 0,
            'is_read' => 0,
            'created_at' => time(),
        ];
        return $this->db->insert('notification', $insert);
    }

    /**
     * 获取未读通知数
     * @param int $uid
     * @return int
     */
    public function getUnreadCount(int $uid): int {
        if ($uid <= 0) return 0;
        return $this->db->count('notification', ['uid' => $uid, 'is_read' => 0]);
    }

    /**
     * 标记为已读
     * @param int $id 通知ID
     * @return int
     */
    public function markAsRead(int $id): int {
        if ($id <= 0) return 0;
        return $this->db->update('notification', ['id' => $id], ['is_read' => 1]);
    }

    /**
     * 标记所有为已读
     * @param int $uid
     * @return int
     */
    public function markAllAsRead(int $uid): int {
        if ($uid <= 0) return 0;
        return $this->db->update('notification', ['uid' => $uid, 'is_read' => 0], ['is_read' => 1]);
    }

    /**
     * 获取通知列表
     * @param int $uid
     * @param int $page
     * @param int $pagesize
     * @return array
     */
    public function getList(int $uid, int $page = 1, int $pagesize = 20): array {
        if ($uid <= 0) return [];
        return $this->db->find('notification', ['uid' => $uid], ['id' => -1], $page, $pagesize, 'id');
    }
}
