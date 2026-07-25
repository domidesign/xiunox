<?php

// 确保 DatabaseInterface 已加载
if (!interface_exists('DatabaseInterface')) {
    include APP_PATH . 'lib/DatabaseInterface.php';
}

/**
 * 用户服务类
 * @since 1.0.2
 */
class UserService {
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db) {
        $this->db = $db;
    }

    /**
     * 根据UID获取用户信息
     * @param int $uid 用户ID
     * @return array|null
     */
    public function getUserById(int $uid): ?array {
        if ($uid <= 0) return null;
        return $this->db->findOne('user', ['uid' => $uid]);
    }

    /**
     * 根据邮箱获取用户
     * @param string $email
     * @return array|null
     */
    public function getUserByEmail(string $email): ?array {
        if (empty($email)) return null;
        return $this->db->findOne('user', ['email' => $email]);
    }

    /**
     * 根据用户名获取用户
     * @param string $username
     * @return array|null
     */
    public function getUserByUsername(string $username): ?array {
        if (empty($username)) return null;
        return $this->db->findOne('user', ['username' => $username]);
    }

    /**
     * 创建用户
     * @param array $data 用户数据
     * @return int 新用户UID
     * @throws InvalidArgumentException
     */
    public function createUser(array $data): int {
        if (empty($data['email'])) throw new InvalidArgumentException('Email is required');
        if (empty($data['username'])) throw new InvalidArgumentException('Username is required');
        if (empty($data['password_hash']) && empty($data['password'])) throw new InvalidArgumentException('Password is required');

        $insert = [
            'username' => $data['username'],
            'email' => $data['email'],
            'gid' => $data['gid'] ?? 101,
            'create_date' => $data['create_date'] ?? time(),
            'create_ip' => $data['create_ip'] ?? 0,
            'avatar' => $data['avatar'] ?? 0,
            'password' => $data['password'] ?? '',
            'salt' => $data['salt'] ?? '',
            'password_hash' => $data['password_hash'] ?? '',
            'threads' => 0,
            'posts' => 0,
        ];

        return $this->db->insert('user', $insert);
    }

    /**
     * 更新用户
     * @param int $uid
     * @param array $data
     * @return int
     */
    public function getUserList(int $page = 1, int $pagesize = 20): array {
        return $this->db->find('user', [], ['uid' => -1], $page, $pagesize, 'uid');
    }

    public function getUserCount(): int {
        return $this->db->count('user');
    }

    public function updateUser(int $uid, array $data): int {
        if ($uid <= 0) return 0;
        return $this->db->update('user', ['uid' => $uid], $data);
    }

    /**
     * 删除用户
     * @param int $uid
     * @return int
     */
    public function deleteUser(int $uid): int {
        if ($uid <= 0) return 0;
        return $this->db->delete('user', ['uid' => $uid]);
    }

    /**
     * 验证密码（支持旧MD5+salt和新bcrypt）
     * @param string $password 明文密码
     * @param array $user 用户数组
     * @return bool
     */
    public function verifyPassword(string $password, array $user): bool {
        // 优先 bcrypt 验证（新格式）
        if (!empty($user['password_hash'])) {
            return password_verify($password, $user['password_hash']);
        }
        // 旧格式：md5(md5(明文)+salt)，兼容 4.0.4 升级用户
        if (!empty($user['password']) && !empty($user['salt'])) {
            if (md5(md5($password) . $user['salt']) === $user['password']) {
                $this->upgradePasswordHash(intval($user['uid']), $password);
                return true;
            }
        }
        return false;
    }

    /**
     * 升级密码哈希为bcrypt，清空旧字段
     * @param int $uid
     * @param string $password 明文密码
     * @return void
     */
    public function upgradePasswordHash(int $uid, string $password): void {
        if ($uid <= 0) return;
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->updateUser($uid, [
            'password_hash' => $hash,
            'password' => '',
            'salt' => '',
        ]);
    }

    /**
     * 设置用户元数据
     * @param int $uid
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function setMeta(int $uid, string $key, string $value): bool {
        if ($uid <= 0 || empty($key)) return false;
        $existing = $this->db->findOne('user_meta', ['uid' => $uid, 'meta_key' => $key]);
        if ($existing) {
            $this->db->update('user_meta', ['id' => $existing['id']], ['meta_value' => $value]);
        } else {
            $this->db->insert('user_meta', [
                'uid' => $uid,
                'meta_key' => $key,
                'meta_value' => $value,
            ]);
        }
        return true;
    }

    /**
     * 获取用户元数据
     * @param int $uid
     * @param string $key
     * @return string|null
     */
    public function getMeta(int $uid, string $key): ?string {
        if ($uid <= 0 || empty($key)) return null;
        $row = $this->db->findOne('user_meta', ['uid' => $uid, 'meta_key' => $key]);
        return $row ? $row['meta_value'] : null;
    }

    /**
     * 批量获取多个用户
     * @param array $uids
     * @return array
     */
    public function getUsersByIds(array $uids): array {
        if (empty($uids)) return [];
        $validUids = array_filter(array_map('intval', $uids), fn($uid) => $uid > 0);
        if (empty($validUids)) return [];
        $placeholders = rtrim(str_repeat('?,', count($validUids)), ',');
        $sql = "SELECT * FROM " . $this->db->table('user') . " WHERE uid IN ({$placeholders})";
        // ponytail: db_pdo_mysql 无 execute() 方法，用 prepare() 返回 PDOStatement
        $stmt = $this->db->prepare($sql, $validUids);
        if (!$stmt) return [];
        $result = $stmt->fetchAll();
        return $result ? $result : [];
    }
}
