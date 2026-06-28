<?php

/**
 * 统一权限管理服务
 * @since 4.5.0
 */
class PermissionService {

    // 核心权限项定义：key => [label, group]
    private static array $corePermissions = [
        // 普通用户权限
        'allowread'       => ['允许阅读', 'user'],
        'allowthread'     => ['允许发主题', 'user'],
        'allowpost'       => ['允许回帖', 'user'],
        'allowattach'     => ['允许上传附件', 'user'],
        'allowdown'       => ['允许下载附件', 'user'],
        // 审核权限
        'allow_direct_post'    => ['发帖免审', 'audit'],
        'allow_direct_reply'   => ['回帖免审', 'audit'],
        'allow_direct_profile' => ['资料免审', 'audit'],
        // 版主管理权限
        'allowtop'        => ['允许置顶', 'mod'],
        'allowupdate'     => ['允许编辑', 'mod'],
        'allowdelete'     => ['允许删除', 'mod'],
        'allowmove'       => ['允许移动', 'mod'],
        'allowbanuser'    => ['允许封禁用户', 'mod'],
        'allowdeleteuser' => ['允许删除用户', 'mod'],
        'allowviewip'     => ['允许查看IP', 'mod'],
    ];

    // 插件注册的权限项
    private static array $pluginPermissions = [];

    // 权限分组名称
    private static array $groupLabels = [
        'user'   => '普通用户权限',
        'mod'    => '版主管理权限',
        'audit'  => '审核权限',
        'plugin' => '插件扩展权限',
    ];

    /**
     * 注册插件权限项
     * @param string $plugin 插件目录名
     * @param string $key 权限键名（建议格式：plugin_dir_permission）
     * @param string $label 权限显示名称
     * @param string $group 权限分组（默认 plugin）
     */
    public static function register(string $plugin, string $key, string $label, string $group = 'plugin'): void {
        self::$pluginPermissions[$key] = [
            'label'  => $label,
            'group'  => $group,
            'plugin' => $plugin,
        ];
    }

    /**
     * 检查权限
     * @param string $permission_key 权限键名
     * @param int $uid 用户UID，0表示使用当前用户
     * @return bool
     */
    public static function check(string $permission_key, int $uid = 0): bool {
        global $gid, $grouplist;
        $global_uid = $GLOBALS['uid'] ?? 0;

        $_uid = $uid > 0 ? $uid : intval($global_uid);
        if(empty($_uid)) return FALSE;

        // 获取用户 gid
        if($uid > 0 && $uid != $global_uid) {
            $_user = user_read_cache($uid);
            if(empty($_user)) return FALSE;
            $_gid = $_user['gid'];
        } else {
            $_gid = $gid;
        }

        // 管理员组拥有所有权限
        if($_gid == 1 || $_gid == 2) return TRUE;

        // 先查 group_permission 表
        $perm_value = self::getPermissionValue($_gid, $permission_key);
        if($perm_value !== NULL) {
            return !empty($perm_value);
        }

        // 回退到 group 表旧字段
        if(isset($grouplist[$_gid][$permission_key])) {
            return !empty($grouplist[$_gid][$permission_key]);
        }

        return FALSE;
    }

    /**
     * 获取某用户组的所有权限
     * @param int $gid 用户组GID
     * @return array [permission_key => value]
     */
    public static function getPermissions(int $gid): array {
        global $grouplist;

        $permissions = [];

        // 从 group_permission 表读取
        if(self::tableExists()) {
            $perm_list = db_find('group_permission', array('gid'=>$gid), array(), 1, 200, 'permission_key');
            if($perm_list) {
                foreach($perm_list as $p) {
                    $permissions[$p['permission_key']] = $p['value'];
                }
            }
        }

        // 合并 group 表旧字段（group_permission 表中不存在的才用旧字段）
        $all_keys = self::getAllRegisteredKeys();
        foreach($all_keys as $key => $def) {
            if(!isset($permissions[$key]) && isset($grouplist[$gid][$key])) {
                $permissions[$key] = $grouplist[$gid][$key];
            }
        }

        return $permissions;
    }

    /**
     * 批量更新用户组权限
     * @param int $gid 用户组GID
     * @param array $permissions [permission_key => value]
     * @return bool
     */
    public static function updatePermissions(int $gid, array $permissions): bool {
        if(!self::tableExists()) return FALSE;
        if(empty($permissions)) return TRUE;

        // 使用 INSERT ... ON DUPLICATE KEY UPDATE 批量 upsert，消除 N+1 查询
        // group_permission 表有复合主键 (gid, permission_key)，支持 ON DUPLICATE KEY UPDATE
        global $db;
        $tablepre = $db->tablepre;
        $gid = intval($gid);

        $values = array();
        foreach($permissions as $key => $value) {
            $value = intval($value);
            $key_escaped = addslashes((string)$key);
            $values[] = "({$gid}, '{$key_escaped}', {$value})";
        }

        if(empty($values)) return TRUE;

        $sql = "INSERT INTO `{$tablepre}group_permission` (`gid`, `permission_key`, `value`) VALUES "
             . implode(',', $values)
             . " ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        db_exec($sql);

        return TRUE;
    }

    /**
     * 获取所有已注册的权限项定义
     * @return array [key => ['label'=>..., 'group'=>..., 'plugin'=>...]]
     */
    public static function getAllRegisteredKeys(): array {
        return array_merge(self::$corePermissions, self::$pluginPermissions);
    }

    /**
     * 获取权限分组名称
     * @param string $group 分组标识
     * @return string
     */
    public static function getGroupLabel(string $group): string {
        return self::$groupLabels[$group] ?? $group;
    }

    /**
     * 获取所有分组
     * @return array [group => label]
     */
    public static function getGroups(): array {
        return self::$groupLabels;
    }

    /**
     * 检查 group_permission 表是否存在
     * @return bool
     */
    private static function tableExists(): bool {
        static $exists = NULL;
        if($exists === NULL) {
            global $conf;
            $tablepre = $conf['db']['master']['tablepre'] ?? 'bbs_';
            $row = db_sql_find_one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}group_permission'");
            $exists = !empty($row);
        }
        return $exists;
    }

    /**
     * 从 group_permission 表读取单个权限值
     * @param int $gid
     * @param string $key
     * @return int|null NULL 表示无记录
     */
    private static function getPermissionValue(int $gid, string $key): ?int {
        if(!self::tableExists()) return NULL;
        $row = db_find_one('group_permission', array('gid'=>$gid, 'permission_key'=>$key));
        if($row) {
            return intval($row['value']);
        }
        return NULL;
    }
}
