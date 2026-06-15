<?php

class UpgradeService {
    private $db;
    private array $conf;
    private string $backupPath;
    private string $targetVersion = '1.0.1';

    public function __construct($db, array $conf) {
        $this->db = $db;
        $this->conf = $conf;
        $this->backupPath = $conf['tmp_path'] . 'upgrade_backup_' . date('Ymd_His') . '/';
    }

    public function checkPrerequisites(): array {
        $warnings = [];
        $ok = true;

        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $ok = false;
            $warnings[] = 'PHP 版本 ' . PHP_VERSION . ' < 8.0，需先升级 PHP';
        }

        $freeSpace = disk_free_space(APP_PATH);
        if ($freeSpace < 50 * 1024 * 1024) {
            $ok = false;
            $warnings[] = '磁盘空间不足（需至少 50MB）：' . round($freeSpace / 1024 / 1024, 1) . 'MB';
        }

        $confFile = APP_PATH . 'conf/conf.php';
        if (!is_writable($confFile)) {
            $warnings[] = 'conf/conf.php 不可写，配置更新将失败';
        }

        if (!is_writable($this->conf['tmp_path'])) {
            $ok = false;
            $warnings[] = 'tmp 目录不可写：' . $this->conf['tmp_path'];
        }

        if ($ok && file_exists($this->backupPath)) {
            $warnings[] = '备份目录已存在，请手动删除：' . $this->backupPath;
        }

        return [
            'ok' => $ok,
            'message' => $ok ? '检查通过' : '存在 ' . count($warnings) . ' 个问题',
            'need_upgrade' => true,
            'current_version' => $this->conf['version'] ?? '0.0.0',
            'target_version' => $this->targetVersion,
            'warnings' => $warnings,
        ];
    }

    public function backup(): array {
        if (file_exists($this->backupPath)) {
            return ['ok' => true, 'message' => '备份目录已存在，跳过备份', 'backup_path' => $this->backupPath, 'files' => []];
        }
        if (!mkdir($this->backupPath, 0755, true)) {
            return ['ok' => false, 'message' => '创建备份目录失败'];
        }

        $backups = [];
        $confFile = APP_PATH . 'conf/conf.php';
        if (copy($confFile, $this->backupPath . 'conf.php')) {
            $backups[] = 'conf/conf.php';
        }

        $modelInc = APP_PATH . 'model.inc.php';
        if (file_exists($modelInc)) {
            copy($modelInc, $this->backupPath . 'model.inc.php');
            $backups[] = 'model.inc.php';
        }

        return [
            'ok' => true,
            'message' => '备份完成：' . implode(', ', $backups),
            'backup_path' => $this->backupPath,
            'files' => $backups,
        ];
    }

    private function execSql(string $sql): array {
        $this->db->exec($sql);
        if (!empty($this->db->errno)) {
            return ['ok' => false, 'message' => 'MySQL Error ' . $this->db->errno . ': ' . $this->db->errstr];
        }
        return ['ok' => true, 'message' => '完成'];
    }

    private function addColumn(string $table, string $column, string $sql, string $tablepre): array {
        if ($this->dbColumnExists($table, $column, $tablepre)) {
            return ['ok' => true, 'message' => '已存在，跳过'];
        }
        $result = $this->execSql($sql);
        if ($result['ok']) {
            if (!$this->dbColumnExists($table, $column, $tablepre)) {
                return ['ok' => false, 'message' => '执行后验证失败，字段未创建'];
            }
        }
        return $result;
    }

    private function createTable(string $table, string $sql, string $tablepre): array {
        if ($this->dbTableExists($table, $tablepre)) {
            return ['ok' => true, 'message' => '已存在，跳过'];
        }
        $result = $this->execSql($sql);
        if ($result['ok']) {
            if (!$this->dbTableExists($table, $tablepre)) {
                return ['ok' => false, 'message' => '执行后验证失败，表未创建'];
            }
        }
        return $result;
    }

    public function upgradeDbStructure(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        $columns = [
            ['user', 'password_hash', "ALTER TABLE `{$tablepre}user` ADD COLUMN `password_hash` VARCHAR(255) NOT NULL DEFAULT '' AFTER `salt`"],
            ['user', 'login_attempts', "ALTER TABLE `{$tablepre}user` ADD COLUMN `login_attempts` INT NOT NULL DEFAULT 0 AFTER `password_hash`"],
            ['user', 'last_login_ip', "ALTER TABLE `{$tablepre}user` ADD COLUMN `last_login_ip` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `login_attempts`"],
            ['user', 'last_login_time', "ALTER TABLE `{$tablepre}user` ADD COLUMN `last_login_time` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_login_ip`"],
            ['user', 'banned_until', "ALTER TABLE `{$tablepre}user` ADD COLUMN `banned_until` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_login_time`"],
            ['thread', 'videos', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `videos` tinyint(6) NOT NULL DEFAULT 0 AFTER `files`"],
            ['post', 'videos', "ALTER TABLE `{$tablepre}post` ADD COLUMN `videos` smallint(6) NOT NULL DEFAULT 0 AFTER `files`"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        $r = $this->createTable('user_login_log', "CREATE TABLE `{$tablepre}user_login_log` (
          id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
          uid INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户id',
          ip INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录IP',
          time INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录时间',
          success TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否成功',
          user_agent VARCHAR(255) NOT NULL DEFAULT '' COMMENT '浏览器UA',
          PRIMARY KEY (id),
          KEY (uid, time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'user_login_log', 'ok' => $r['ok'], 'message' => $r['message']];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "数据库结构升级完成（{$doneCount} 项新增）" : '部分结构升级失败',
            'results' => $results,
        ];
    }

    public function upgradeSocialTables(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        $columns = [
            ['forum', 'fup', "ALTER TABLE `{$tablepre}forum` ADD COLUMN `fup` int(11) unsigned NOT NULL DEFAULT '0' AFTER `rank`"],
            ['forum', 'type', "ALTER TABLE `{$tablepre}forum` ADD COLUMN `type` tinyint(1) NOT NULL DEFAULT '0' AFTER `fup`"],
            ['forum', 'follows', "ALTER TABLE `{$tablepre}forum` ADD COLUMN `follows` int(11) unsigned NOT NULL DEFAULT '0' AFTER `todaythreads`"],
            ['thread', 'likes', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `likes` int(11) NOT NULL DEFAULT '0' AFTER `posts`"],
            ['thread', 'favorites', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `favorites` int(11) NOT NULL DEFAULT '0' AFTER `likes`"],
            ['post', 'likes', "ALTER TABLE `{$tablepre}post` ADD COLUMN `likes` int(11) NOT NULL DEFAULT '0' AFTER `quotepid`"],
            ['user', 'follows', "ALTER TABLE `{$tablepre}user` ADD COLUMN `follows` int(11) NOT NULL DEFAULT '0' AFTER `avatar`"],
            ['user', 'followeds', "ALTER TABLE `{$tablepre}user` ADD COLUMN `followeds` int(11) NOT NULL DEFAULT '0' AFTER `follows`"],
            ['user', 'favorites', "ALTER TABLE `{$tablepre}user` ADD COLUMN `favorites` int(11) NOT NULL DEFAULT '0' AFTER `followeds`"],
            ['user', 'ai_config', "ALTER TABLE `{$tablepre}user` ADD COLUMN `ai_config` TEXT DEFAULT NULL AFTER `favorites`"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        $tables = [
            ['forum_follow', "CREATE TABLE `{$tablepre}forum_follow` (
              uid int(11) unsigned NOT NULL DEFAULT '0',
              fid smallint(6) unsigned NOT NULL DEFAULT '0',
              create_date int(11) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (uid, fid),
              KEY fid (fid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"],
            ['post_like', "CREATE TABLE `{$tablepre}post_like` (
              tid int(11) unsigned NOT NULL DEFAULT '0',
              pid int(11) unsigned NOT NULL DEFAULT '0',
              uid int(11) unsigned NOT NULL DEFAULT '0',
              create_date int(11) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (uid, pid),
              KEY (tid, uid),
              KEY (pid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"],
            ['thread_favorite', "CREATE TABLE `{$tablepre}thread_favorite` (
              tid int(11) unsigned NOT NULL DEFAULT '0',
              uid int(11) unsigned NOT NULL DEFAULT '0',
              create_date int(11) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (uid, tid),
              KEY (tid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"],
            ['user_follow', "CREATE TABLE `{$tablepre}user_follow` (
              uid int(11) unsigned NOT NULL DEFAULT '0',
              follow_uid int(11) unsigned NOT NULL DEFAULT '0',
              create_date int(11) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (uid, follow_uid),
              KEY (follow_uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"],
            ['notify', "CREATE TABLE `{$tablepre}notify` (
              nid int(11) unsigned NOT NULL AUTO_INCREMENT,
              uid int(11) unsigned NOT NULL DEFAULT '0',
              from_uid int(11) unsigned NOT NULL DEFAULT '0',
              type char(16) NOT NULL DEFAULT '' COMMENT 'thread/like/favorite/follow',
              tid int(11) unsigned NOT NULL DEFAULT '0',
              pid int(11) unsigned NOT NULL DEFAULT '0',
              content char(128) NOT NULL DEFAULT '',
              create_date int(11) unsigned NOT NULL DEFAULT '0',
              is_read tinyint(1) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (nid),
              KEY (uid, is_read, nid),
              KEY (uid, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"],
        ];

        foreach ($tables as $t) {
            $r = $this->createTable($t[0], $t[1], $tablepre);
            $results[] = ['name' => $t[0], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "社交功能表升级完成（{$doneCount} 项新增）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeCreditsSystem(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 创建积分日志表（使用 InnoDB 引擎，支持行锁）
        $r = $this->createTable('credits_log', "CREATE TABLE `{$tablepre}credits_log` (
          logid int(11) unsigned NOT NULL AUTO_INCREMENT,
          uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户id',
          type varchar(16) NOT NULL DEFAULT 'credits' COMMENT '积分类型: credits/golds/rmbs',
          `change` int(11) NOT NULL DEFAULT 0 COMMENT '变动值，正为加，负为减',
          balance int(11) NOT NULL DEFAULT 0 COMMENT '变动后余额',
          reason varchar(64) NOT NULL DEFAULT '' COMMENT '变动原因',
          ip int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作IP',
          create_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
          PRIMARY KEY (logid),
          KEY idx_uid_date (uid, create_date),
          KEY idx_uid_reason_date (uid, reason, create_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'credits_log', 'ok' => $r['ok'], 'message' => $r['message']];

        // 检查 user 表是否已有积分字段
        $columns = [
            ['user', 'credits', "ALTER TABLE `{$tablepre}user` ADD COLUMN `credits` int(11) NOT NULL DEFAULT 0 COMMENT '积分'"],
            ['user', 'golds', "ALTER TABLE `{$tablepre}user` ADD COLUMN `golds` int(11) NOT NULL DEFAULT 0 COMMENT '金币'"],
            ['user', 'rmbs', "ALTER TABLE `{$tablepre}user` ADD COLUMN `rmbs` int(11) NOT NULL DEFAULT 0 COMMENT '人民币余额(分)'"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "积分系统升级完成（{$doneCount} 项新增）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeCreditsRuleTables(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 创建全局规则表
        $r = $this->createTable('credits_rule_global', "CREATE TABLE `{$tablepre}credits_rule_global` (
          ruleid int(11) unsigned NOT NULL AUTO_INCREMENT,
          event varchar(32) NOT NULL DEFAULT '' COMMENT '事件标识',
          label varchar(64) NOT NULL DEFAULT '' COMMENT '事件显示名称',
          credits_change int(11) NOT NULL DEFAULT 0 COMMENT '积分变化值',
          golds_change int(11) NOT NULL DEFAULT 0 COMMENT '金币变化值',
          rmbs_change int(11) NOT NULL DEFAULT 0 COMMENT '人民币变化值',
          enabled tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
          PRIMARY KEY (ruleid),
          UNIQUE KEY event (event)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'credits_rule_global', 'ok' => $r['ok'], 'message' => $r['message']];

        // 创建版块规则覆盖表
        $r = $this->createTable('credits_rule_forum', "CREATE TABLE `{$tablepre}credits_rule_forum` (
          id int(11) unsigned NOT NULL AUTO_INCREMENT,
          fid smallint(6) unsigned NOT NULL DEFAULT 0 COMMENT '版块ID',
          event varchar(32) NOT NULL DEFAULT '' COMMENT '事件标识',
          credits_change int(11) NOT NULL DEFAULT 0 COMMENT '积分变化值',
          golds_change int(11) NOT NULL DEFAULT 0 COMMENT '金币变化值',
          rmbs_change int(11) NOT NULL DEFAULT 0 COMMENT '人民币变化值',
          enabled tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
          PRIMARY KEY (id),
          UNIQUE KEY fid_event (fid, event)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'credits_rule_forum', 'ok' => $r['ok'], 'message' => $r['message']];

        // 插入12条内置规则初始数据（仅当表为新创建时）
        if ($results[0]['message'] === '完成') {
            $builtinRules = [
                ['thread_post', '发主题'],
                ['reply_post', '发回复'],
                ['thread_digest', '加精'],
                ['thread_top', '置顶'],
                ['thread_delete', '删主题'],
                ['reply_delete', '删除回复'],
                ['be_liked', '被点赞'],
                ['like', '点赞他人'],
                ['be_commented', '被回复'],
                ['favorite', '收藏'],
                ['be_favorited', '被收藏'],
                ['daily_login', '每日首次登录'],
            ];
            foreach ($builtinRules as $rule) {
                $this->execSql("INSERT IGNORE INTO `{$tablepre}credits_rule_global` (`event`, `label`, `credits_change`, `golds_change`, `rmbs_change`, `enabled`) VALUES ('{$rule[0]}', '{$rule[1]}', 0, 0, 0, 1)");
            }
            $results[] = ['name' => 'credits_rule_global.init_data', 'ok' => true, 'message' => '插入 ' . count($builtinRules) . ' 条内置规则'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "积分规则表升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeApiV1Tables(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // api_token 的 type/related_id 字段已在 migrateDatabase 建表时包含
        // 此处仅处理旧表缺少字段的情况
        $columns = [];
        if ($this->dbTableExists('api_token', $tablepre) && !$this->dbColumnExists('api_token', 'type', $tablepre)) {
            $columns[] = ['api_token', 'type', "ALTER TABLE `{$tablepre}api_token` ADD COLUMN `type` enum('access','refresh') NOT NULL DEFAULT 'access' AFTER `uid`"];
        }
        if ($this->dbTableExists('api_token', $tablepre) && !$this->dbColumnExists('api_token', 'related_id', $tablepre)) {
            $columns[] = ['api_token', 'related_id', "ALTER TABLE `{$tablepre}api_token` ADD COLUMN `related_id` bigint(16) unsigned NOT NULL DEFAULT 0 AFTER `type`"];
        }

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        $indexExists = $this->dbIndexExists('api_token', 'uid_type', $tablepre);
        if (!$indexExists) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}api_token` ADD INDEX `uid_type` (`uid`, `type`)");
            $results[] = ['name' => 'api_token.uid_type', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'api_token.uid_type', 'ok' => true, 'message' => '已存在，跳过'];
        }

        $tables = [
            ['thread_like', "CREATE TABLE `{$tablepre}thread_like` (
              `id` bigint(16) unsigned NOT NULL AUTO_INCREMENT,
              `tid` int(11) unsigned NOT NULL DEFAULT 0,
              `uid` int(11) unsigned NOT NULL DEFAULT 0,
              `create_date` int(11) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `tid_uid` (`tid`, `uid`),
              KEY `uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='帖子点赞'"],
            ['thread_report', "CREATE TABLE `{$tablepre}thread_report` (
              `id` bigint(16) unsigned NOT NULL AUTO_INCREMENT,
              `tid` int(11) unsigned NOT NULL DEFAULT 0,
              `uid` int(11) unsigned NOT NULL DEFAULT 0,
              `reason` varchar(500) NOT NULL DEFAULT '',
              `create_date` int(11) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `tid` (`tid`),
              KEY `uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='帖子举报'"],
        ];

        foreach ($tables as $t) {
            $r = $this->createTable($t[0], $t[1], $tablepre);
            $results[] = ['name' => $t[0], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // thread_favorite 已在 upgradeSocialTables 中创建，此处仅确保旧表结构兼容
        if ($this->dbTableExists('thread_favorite', $tablepre)) {
            $results[] = ['name' => 'thread_favorite', 'ok' => true, 'message' => '已存在，跳过'];
        } else {
            $r = $this->createTable('thread_favorite', "CREATE TABLE `{$tablepre}thread_favorite` (
              tid int(11) unsigned NOT NULL DEFAULT '0',
              uid int(11) unsigned NOT NULL DEFAULT '0',
              create_date int(11) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (uid, tid),
              KEY (tid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
            $results[] = ['name' => 'thread_favorite', 'ok' => $r['ok'], 'message' => $r['message']];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "API v1 表升级完成（{$doneCount} 项新增）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function migrateDatabase(): array {
        $results = [];
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';

        $columns = [
            ['attach', 'width', "ALTER TABLE `{$tablepre}attach` ADD COLUMN `width` int(11) unsigned NOT NULL DEFAULT 0"],
            ['attach', 'height', "ALTER TABLE `{$tablepre}attach` ADD COLUMN `height` int(11) unsigned NOT NULL DEFAULT 0"],
            ['attach', 'thumb_exists', "ALTER TABLE `{$tablepre}attach` ADD COLUMN `thumb_exists` tinyint(1) NOT NULL DEFAULT 0"],
            ['attach', 'driver', "ALTER TABLE `{$tablepre}attach` ADD COLUMN `driver` varchar(32) NOT NULL DEFAULT 'local'"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        $tables = [
            ['api_token', "CREATE TABLE `{$tablepre}api_token` (
                `id` bigint(16) unsigned NOT NULL AUTO_INCREMENT,
                `uid` int(11) unsigned NOT NULL DEFAULT 0,
                `type` enum('access','refresh') NOT NULL DEFAULT 'access' COMMENT '令牌类型',
                `related_id` bigint(16) unsigned NOT NULL DEFAULT 0 COMMENT '关联令牌ID',
                `token` char(64) NOT NULL DEFAULT '',
                `expires_at` int(11) unsigned NOT NULL DEFAULT 0,
                `created_at` int(11) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `token` (`token`),
                KEY `uid` (`uid`),
                KEY `uid_type` (`uid`, `type`),
                KEY `expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='API令牌'"],
            ['api_log', "CREATE TABLE `{$tablepre}api_log` (
                `id` bigint(16) unsigned NOT NULL AUTO_INCREMENT,
                `resource` varchar(32) NOT NULL DEFAULT '',
                `method` varchar(10) NOT NULL DEFAULT '',
                `uid` int(11) unsigned NOT NULL DEFAULT 0,
                `ip` int(11) unsigned NOT NULL DEFAULT 0,
                `duration` int(11) unsigned NOT NULL DEFAULT 0,
                `create_date` int(11) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `resource_method` (`resource`, `method`),
                KEY `uid` (`uid`),
                KEY `create_date` (`create_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='API日志'"],
        ];

        foreach ($tables as $t) {
            $r = $this->createTable($t[0], $t[1], $tablepre);
            $results[] = ['name' => $t[0], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        if (empty($results)) {
            return ['ok' => true, 'message' => '数据库迁移已是最新，无需操作', 'results' => []];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        return [
            'ok' => $allOk,
            'message' => $allOk ? '数据库迁移完成' : '部分迁移失败',
            'results' => $results,
        ];
    }

    public function migratePasswords(int $batchSize = 100): array {
        $total = $this->db->count('user');
        $migrated = 0;

        $pages = ceil($total / $batchSize);
        for ($page = 1; $page <= $pages; $page++) {
            $users = $this->db->find('user', [], [], $page, $batchSize, 'uid');
            foreach ($users as $user) {
                if (!empty($user['password']) && !empty($user['salt'])) {
                    $migrated++;
                }
            }
        }

        return [
            'ok' => true,
            'message' => "密码迁移策略：{$migrated} 个用户需在下次登录时自动升级为 bcrypt",
            'total' => $total,
            'pending' => $migrated,
        ];
    }

    public function adjustConfig(): array {
        $confFile = APP_PATH . 'conf/conf.php';
        if (!is_writable($confFile)) {
            return ['ok' => false, 'message' => 'conf/conf.php 不可写'];
        }

        $changes = [];
        $newConfigs = [
            'editor' => 'aieditor',
            'api_enabled' => 1,
            'api_token_expire' => 30,
            'user_create_on' => 1,
        ];

        foreach ($newConfigs as $key => $val) {
            if (!isset($this->conf[$key])) {
                $this->conf[$key] = $val;
                $changes[$key] = $val;
            }
        }

        $this->conf['version'] = $this->targetVersion;
        $changes['version'] = $this->targetVersion;

        $content = "<?php\nreturn " . var_export($this->conf, true) . ";\n?>";
        if (file_put_contents($confFile, $content) === false) {
            return ['ok' => false, 'message' => '写入 conf/conf.php 失败'];
        }

        return [
            'ok' => true,
            'message' => '配置更新完成，共 ' . count($changes) . ' 项变更',
            'changes' => $changes,
        ];
    }

    public function recompilePlugins(): array {
        $tmpPath = $this->conf['tmp_path'];
        $deleted = 0;
        if (is_dir($tmpPath)) {
            $files = glob($tmpPath . '*');
            if ($files) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        unlink($f);
                        $deleted++;
                    }
                }
            }
        }

        plugin_init();
        foreach (glob(APP_PATH . 'plugin/*', GLOB_ONLYDIR) as $dir) {
            $conffile = $dir . '/conf.json';
            if (is_file($conffile)) {
                $pconf = xn_json_decode(file_get_contents($conffile));
                if (!empty($pconf['enable']) && !empty($pconf['installed'])) {
                    plugin_enable(file_name($dir));
                }
            }
        }

        return [
            'ok' => true,
            'message' => "插件重编译完成，清理 {$deleted} 个缓存文件",
            'deleted' => $deleted,
        ];
    }

    private function dbColumnExists(string $table, string $column, string $tablepre): bool {
        $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}{$table}' AND COLUMN_NAME = '{$column}'";
        $r = $this->db->sqlFindOne($sql);
        return !empty($r);
    }

    private function dbGetColumnType(string $table, string $column, string $tablepre): string {
        $sql = "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}{$table}' AND COLUMN_NAME = '{$column}'";
        $r = $this->db->sqlFindOne($sql);
        return !empty($r) ? strtolower($r['DATA_TYPE']) : '';
    }

    private function dbTableExists(string $table, string $tablepre): bool {
        $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}{$table}'";
        $r = $this->db->sqlFindOne($sql);
        return !empty($r);
    }

    private function dbIndexExists(string $table, string $indexName, string $tablepre): bool {
        $sql = "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}{$table}' AND INDEX_NAME = '{$indexName}'";
        $r = $this->db->sqlFindOne($sql);
        return !empty($r);
    }

    public function upgradeSearchIndexes(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // bbs_thread.subject FULLTEXT 索引
        if (!$this->dbIndexExists('thread', 'ft_subject', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}thread` ADD FULLTEXT INDEX ft_subject (subject) WITH PARSER ngram");
            $results[] = ['name' => 'thread.ft_subject', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'thread.ft_subject', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // bbs_post.message FULLTEXT 索引
        if (!$this->dbIndexExists('post', 'ft_message', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}post` ADD FULLTEXT INDEX ft_message (message) WITH PARSER ngram");
            $results[] = ['name' => 'post.ft_message', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'post.ft_message', 'ok' => true, 'message' => '已存在，跳过'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "全文搜索索引升级完成（{$doneCount} 项新增）" : '部分索引创建失败',
            'results' => $results,
        ];
    }

    public function upgradeIconColorFields(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 添加用户组图标、颜色字段和版块图标字段
        $columns = [
            ['group', 'icon', "ALTER TABLE `{$tablepre}group` ADD COLUMN `icon` varchar(50) NOT NULL DEFAULT '' COMMENT '用户组图标'"],
            ['group', 'color', "ALTER TABLE `{$tablepre}group` ADD COLUMN `color` char(7) NOT NULL DEFAULT '' COMMENT '用户组颜色'"],
            ['forum', 'icon', "ALTER TABLE `{$tablepre}forum` ADD COLUMN `icon` varchar(50) NOT NULL DEFAULT '' COMMENT '版块图标'"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // 修复 forum.icon 字段类型：旧版为 int(11) 存储时间戳，需改为 varchar(50) 存储 Tabler Icon 类名
        $iconType = $this->dbGetColumnType('forum', 'icon', $tablepre);
        if ($iconType === 'int' || $iconType === 'int unsigned') {
            $r = $this->execSql("ALTER TABLE `{$tablepre}forum` MODIFY COLUMN `icon` varchar(50) NOT NULL DEFAULT '' COMMENT '版块图标'");
            $results[] = ['name' => 'forum.icon_type_fix', 'ok' => $r['ok'], 'message' => $r['ok'] ? 'icon 字段类型已从 int 修改为 varchar(50)' : $r['message']];
        }

        // 清空旧的时间戳格式的 icon 值
        $r = $this->execSql("UPDATE `{$tablepre}group` SET icon = '' WHERE icon REGEXP '^[0-9]+$'");
        $results[] = ['name' => 'group.icon_clean', 'ok' => $r['ok'], 'message' => $r['ok'] ? '旧数据清理完成' : $r['message']];

        $r = $this->execSql("UPDATE `{$tablepre}forum` SET icon = '' WHERE icon REGEXP '^[0-9]+$'");
        $results[] = ['name' => 'forum.icon_clean', 'ok' => $r['ok'], 'message' => $r['ok'] ? '旧数据清理完成' : $r['message']];

        // 设置默认用户组图标和颜色
        $groupDefaults = [
            ['gid' => 0, 'icon' => 'ti ti-user', 'color' => '#6c757d'],
            ['gid' => 1, 'icon' => 'ti ti-shield', 'color' => '#dc3545'],
            ['gid' => 2, 'icon' => 'ti ti-star', 'color' => '#0d6efd'],
            ['gid' => 4, 'icon' => 'ti ti-award', 'color' => '#198754'],
            ['gid' => 5, 'icon' => 'ti ti-user-check', 'color' => '#6c757d'],
        ];

        foreach ($groupDefaults as $g) {
            $r = $this->execSql("UPDATE `{$tablepre}group` SET icon = '{$g['icon']}', color = '{$g['color']}' WHERE gid = {$g['gid']} AND icon = ''");
            $results[] = ['name' => "group.gid{$g['gid']}_default", 'ok' => $r['ok'], 'message' => $r['ok'] ? '默认值设置完成' : $r['message']];
        }

        // 设置默认版块图标
        $r = $this->execSql("UPDATE `{$tablepre}forum` SET icon = 'ti ti-message-circle' WHERE fid = 1 AND icon = ''");
        $results[] = ['name' => 'forum.fid1_default', 'ok' => $r['ok'], 'message' => $r['ok'] ? '默认值设置完成' : $r['message']];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "图标与颜色字段升级完成（{$doneCount} 项新增）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeNoticeIsRead(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 如果 notify 表不存在，先建表（含 is_read 字段，对齐 install.sql）
        if (!$this->dbTableExists('notify', $tablepre)) {
            $r = $this->createTable('notify', "CREATE TABLE `{$tablepre}notify` (
              nid int(11) unsigned NOT NULL AUTO_INCREMENT,
              uid int(11) unsigned NOT NULL DEFAULT '0',
              from_uid int(11) unsigned NOT NULL DEFAULT '0',
              type char(16) NOT NULL DEFAULT '' COMMENT 'thread/like/favorite/follow',
              tid int(11) unsigned NOT NULL DEFAULT '0',
              pid int(11) unsigned NOT NULL DEFAULT '0',
              content char(128) NOT NULL DEFAULT '',
              create_date int(11) unsigned NOT NULL DEFAULT '0',
              is_read tinyint(1) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (nid),
              KEY (uid, is_read, nid),
              KEY (uid, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
            $results[] = ['name' => 'notice', 'ok' => $r['ok'], 'message' => $r['ok'] ? '建表完成' : $r['message']];

            // 同时确保 user 表有 notices / unread_notices 字段
            $userCols = [
                ['user', 'notices', "ALTER TABLE `{$tablepre}user` ADD COLUMN `notices` mediumint(8) unsigned NOT NULL DEFAULT '0'"],
                ['user', 'unread_notices', "ALTER TABLE `{$tablepre}user` ADD COLUMN `unread_notices` mediumint(8) unsigned NOT NULL DEFAULT '0'"],
            ];
            foreach ($userCols as $col) {
                $cr = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
                $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $cr['ok'], 'message' => $cr['message']];
            }
        } else {
            // 表已存在，添加 is_read 字段
            $r = $this->addColumn('notify', 'is_read', "ALTER TABLE `{$tablepre}notify` ADD COLUMN `is_read` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `create_date`", $tablepre);
            $results[] = ['name' => 'notify.is_read', 'ok' => $r['ok'], 'message' => $r['message']];

            // 添加索引
            if (!$this->dbIndexExists('notify', 'uid_is_read_nid', $tablepre)) {
                $idx = $this->execSql("ALTER TABLE `{$tablepre}notify` ADD INDEX `uid_is_read_nid` (`uid`, `is_read`, `nid`)");
                $results[] = ['name' => 'notify.uid_is_read_nid', 'ok' => $idx['ok'], 'message' => $idx['ok'] ? '完成' : $idx['message']];
            } else {
                $results[] = ['name' => 'notify.uid_is_read_nid', 'ok' => true, 'message' => '已存在，跳过'];
            }

            // 确保 user 表有 notices / unread_notices 字段
            $userCols = [
                ['user', 'notices', "ALTER TABLE `{$tablepre}user` ADD COLUMN `notices` mediumint(8) unsigned NOT NULL DEFAULT '0'"],
                ['user', 'unread_notices', "ALTER TABLE `{$tablepre}user` ADD COLUMN `unread_notices` mediumint(8) unsigned NOT NULL DEFAULT '0'"],
            ];
            foreach ($userCols as $col) {
                $cr = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
                $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $cr['ok'], 'message' => $cr['message']];
            }
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "通知系统升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeForumManagement(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 添加发帖审核和回帖审核权限字段
        $columns = [
            ['forum_access', 'allowthreadaudit', "ALTER TABLE `{$tablepre}forum_access` ADD COLUMN `allowthreadaudit` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '发帖审核: 0不审核/1需审核'"],
            ['forum_access', 'allowpostaudit', "ALTER TABLE `{$tablepre}forum_access` ADD COLUMN `allowpostaudit` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '回帖审核: 0不审核/1需审核'"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // 扩展版块图标字段，支持图片路径
        $iconType = $this->dbGetColumnType('forum', 'icon', $tablepre);
        $iconLen = 0;
        $colInfo = $this->db->sqlFindOne("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}forum' AND COLUMN_NAME = 'icon'");
        if (!empty($colInfo)) {
            $iconLen = intval($colInfo['CHARACTER_MAXIMUM_LENGTH']);
        }

        if ($iconLen < 255) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}forum` MODIFY COLUMN `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '版块图标，存储图片路径'");
            $results[] = ['name' => 'forum.icon_expand', 'ok' => $r['ok'], 'message' => $r['ok'] ? 'icon 字段已扩展为 varchar(255)' : $r['message']];
        } else {
            $results[] = ['name' => 'forum.icon_expand', 'ok' => true, 'message' => '已存在，跳过'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "版块管理优化升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeCacheSystem(): array {
        $results = [];

        // 1. 迁移旧缓存配置到 setting
        $cacheConfig = kv_get('cache_config');
        if ($cacheConfig === NULL) {
            // 从 conf 读取旧配置并迁移
            $oldCache = isset($this->conf['cache']) ? $this->conf['cache'] : array();
            $newConfig = array(
                'enable' => isset($oldCache['enable']) ? intval($oldCache['enable']) : 1,
                'type' => isset($oldCache['type']) ? $oldCache['type'] : 'mysql',
                'default_ttl' => 3600,
                'auto_warmup' => 0,
                'file' => array('cachepre' => 'bbs_', 'cache_dir' => ''),
                'redis' => array(
                    'host' => isset($oldCache['redis']['host']) ? $oldCache['redis']['host'] : '127.0.0.1',
                    'port' => isset($oldCache['redis']['port']) ? intval($oldCache['redis']['port']) : 6379,
                    'password' => '',
                    'database' => 0,
                    'cachepre' => 'bbs_',
                ),
                'memcached' => array(
                    'host' => isset($oldCache['memcached']['host']) ? $oldCache['memcached']['host'] : '127.0.0.1',
                    'port' => isset($oldCache['memcached']['port']) ? intval($oldCache['memcached']['port']) : 11211,
                    'cachepre' => 'bbs_',
                ),
                'mysql' => array('cachepre' => 'bbs_'),
            );

            // 兼容旧驱动类型
            if (in_array($newConfig['type'], array('xcache', 'apc', 'yac'))) {
                $newConfig['type'] = 'mysql';
            }

            kv_set('cache_config', $newConfig);
            $results[] = ['name' => 'cache_config_migrate', 'ok' => true, 'message' => '缓存配置已迁移到 setting'];
        } else {
            $results[] = ['name' => 'cache_config_migrate', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // 2. 清理过时驱动文件
        $obsoleteFiles = array(
            APP_PATH . 'xiunophp/cache_xcache.class.php',
            APP_PATH . 'xiunophp/cache_apc.class.php',
            APP_PATH . 'xiunophp/cache_yac.class.php',
        );
        $deleted = 0;
        foreach ($obsoleteFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
                $deleted++;
            }
        }
        $results[] = ['name' => 'obsolete_drivers_cleanup', 'ok' => true, 'message' => $deleted > 0 ? "已清理 {$deleted} 个过时驱动文件" : '无需清理'];

        // 3. 确保 tmp/cache 目录存在
        $cacheDir = APP_PATH . 'tmp/cache/';
        if (!is_dir($cacheDir)) {
            if (mkdir($cacheDir, 0755, true)) {
                $results[] = ['name' => 'cache_dir_create', 'ok' => true, 'message' => '缓存目录已创建'];
            } else {
                $results[] = ['name' => 'cache_dir_create', 'ok' => false, 'message' => '创建缓存目录失败'];
            }
        } else {
            $results[] = ['name' => 'cache_dir_create', 'ok' => true, 'message' => '已存在，跳过'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "缓存系统升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeGroupAuditPermissions(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 添加用户组审核权限字段
        $columns = [
            ['group', 'allow_direct_reply', "ALTER TABLE `{$tablepre}group` ADD COLUMN `allow_direct_reply` tinyint(1) NOT NULL DEFAULT 1 COMMENT '回帖审核: 0需审核/1直接发布'"],
            ['group', 'allow_direct_profile', "ALTER TABLE `{$tablepre}group` ADD COLUMN `allow_direct_profile` tinyint(1) NOT NULL DEFAULT 1 COMMENT '个人资料审核: 0需审核/1直接更新'"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // 创建个人资料审核表
        $r = $this->createTable('user_profile_audit', "CREATE TABLE `{$tablepre}user_profile_audit` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户id',
          `field_name` varchar(32) NOT NULL DEFAULT '' COMMENT '字段名: avatar/signature',
          `old_value` text NOT NULL COMMENT '旧值',
          `new_value` text NOT NULL COMMENT '新值',
          `audit_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '审核状态: 0待审/1通过/2驳回',
          `operator_uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '审核人uid',
          `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '审核原因',
          `create_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '提交时间',
          `audit_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '审核时间',
          PRIMARY KEY (`id`),
          KEY `idx_uid` (`uid`),
          KEY `idx_audit_status` (`audit_status`),
          KEY `idx_create_date` (`create_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'user_profile_audit', 'ok' => $r['ok'], 'message' => $r['message']];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "审核权限升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeSecuritySettings(): array {
        $results = [];

        // 1. 将默认安全配置写入 conf.php（仅写入不存在的键）
        $securityDefaults = SecurityConfigService::DEFAULT_CONFIG;
        $confFile = APP_PATH . 'conf/conf.php';
        $changes = [];

        if (is_writable($confFile)) {
            foreach ($securityDefaults as $key => $val) {
                if (!isset($this->conf[$key])) {
                    $changes[$key] = $val;
                }
            }
            if (!empty($changes)) {
                $r = file_replace_var($confFile, $changes);
                if ($r) {
                    $results[] = ['name' => 'security_config', 'ok' => true, 'message' => '写入 ' . count($changes) . ' 项安全配置'];
                } else {
                    $results[] = ['name' => 'security_config', 'ok' => false, 'message' => '写入 conf.php 失败'];
                }
            } else {
                $results[] = ['name' => 'security_config', 'ok' => true, 'message' => '已存在，跳过'];
            }
        } else {
            $results[] = ['name' => 'security_config', 'ok' => false, 'message' => 'conf/conf.php 不可写'];
        }

        // 2. 创建 sensitive_words.txt 文件（如不存在）
        $wordsFile = APP_PATH . 'config/sensitive_words.txt';
        if (!file_exists($wordsFile)) {
            $dir = dirname($wordsFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (file_put_contents($wordsFile, "# 敏感词库\n# 每行一个词，# 开头为注释\n") !== false) {
                $results[] = ['name' => 'sensitive_words.txt', 'ok' => true, 'message' => '创建完成'];
            } else {
                $results[] = ['name' => 'sensitive_words.txt', 'ok' => false, 'message' => '创建失败'];
            }
        } else {
            $results[] = ['name' => 'sensitive_words.txt', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // 3. 初始化验证码配置到 kv 表（如不存在）
        $captchaConfig = kv_get('security_captcha_config');
        if ($captchaConfig === NULL) {
            $defaultCaptcha = [
                'login' => 0,
                'register' => 0,
                'post' => 0,
                'resetpw' => 0,
                'type' => 'gd_image',
            ];
            kv_set('security_captcha_config', $defaultCaptcha);
            $results[] = ['name' => 'captcha_config', 'ok' => true, 'message' => '初始化完成'];
        } else {
            $results[] = ['name' => 'captcha_config', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // 4. 确保 config/security.php 存在
        $securityConfigFile = APP_PATH . 'config/security.php';
        if (!file_exists($securityConfigFile)) {
            $dir = dirname($securityConfigFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $defaultSecurityConfig = "<?php\n// 安全与审核系统配置\n// 修改后需清理 tmp/ 缓存\n\nreturn array(\n\n    'captcha' => array(\n        'login' => 0,\n        'register' => 0,\n        'post' => 0,\n        'resetpw' => 0,\n        'type' => 'gd_image',\n    ),\n\n    'sensitive_word' => array(\n        'enabled' => 0,\n        'action' => 'reject',\n        'words_file' => APP_PATH . 'config/sensitive_words.txt',\n    ),\n\n    'audit' => array(\n        'enabled' => 0,\n        'credits_on_approve' => 0,\n        'credits_amount' => 1,\n    ),\n\n    'moderation' => array(\n        'enabled' => 0,\n    ),\n\n    'security' => array(\n        'prevent_enumeration' => 1,\n        'verify_sensitive_action' => 1,\n        'show_last_login' => 1,\n    ),\n\n);\n";
            if (file_put_contents($securityConfigFile, $defaultSecurityConfig) !== false) {
                $results[] = ['name' => 'config/security.php', 'ok' => true, 'message' => '创建完成'];
            } else {
                $results[] = ['name' => 'config/security.php', 'ok' => false, 'message' => '创建失败'];
            }
        } else {
            $results[] = ['name' => 'config/security.php', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // 5. 确保审核相关数据库字段存在
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $auditColumns = [
            ['thread', 'audit_status', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `audit_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '审核状态: 0待审/1通过/2驳回'"],
            ['post', 'audit_status', "ALTER TABLE `{$tablepre}post` ADD COLUMN `audit_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '审核状态: 0待审/1通过/2驳回'"],
            ['forum', 'audit_thread', "ALTER TABLE `{$tablepre}forum` ADD COLUMN `audit_thread` tinyint(1) NOT NULL DEFAULT 0 COMMENT '发帖审核: 0不审核/1需审核'"],
            ['group', 'allow_direct_post', "ALTER TABLE `{$tablepre}group` ADD COLUMN `allow_direct_post` tinyint(1) NOT NULL DEFAULT 1 COMMENT '免审核发帖: 0需审核/1直接发布'"],
        ];

        foreach ($auditColumns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // 6. 创建审核日志表
        $r = $this->createTable('audit_log', "CREATE TABLE `{$tablepre}audit_log` (
          `logid` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作者uid',
          `target_type` char(16) NOT NULL DEFAULT '' COMMENT '目标类型: thread/post',
          `target_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '目标ID: tid或pid',
          `action` char(16) NOT NULL DEFAULT '' COMMENT '操作: approve/reject',
          `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '操作原因',
          `create_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作时间',
          PRIMARY KEY (`logid`),
          KEY `idx_target` (`target_type`, `target_id`),
          KEY `idx_uid` (`uid`),
          KEY `idx_create_date` (`create_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'audit_log', 'ok' => $r['ok'], 'message' => $r['message']];

        // 7. 创建IP黑名单表
        $r = $this->createTable('ip_blacklist', "CREATE TABLE `{$tablepre}ip_blacklist` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `ip` varchar(45) NOT NULL DEFAULT '' COMMENT 'IP地址或CIDR段',
          `type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0黑名单/1白名单',
          `remark` varchar(128) NOT NULL DEFAULT '' COMMENT '备注',
          `create_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
          PRIMARY KEY (`id`),
          UNIQUE KEY `ip` (`ip`),
          KEY `type` (`type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='IP黑名单'", $tablepre);
        $results[] = ['name' => 'ip_blacklist', 'ok' => $r['ok'], 'message' => $r['message']];

        // 8. 创建邮箱黑名单表
        $r = $this->createTable('email_blacklist', "CREATE TABLE `{$tablepre}email_blacklist` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `domain` varchar(128) NOT NULL DEFAULT '' COMMENT '邮箱域名',
          `remark` varchar(128) NOT NULL DEFAULT '' COMMENT '备注',
          `create_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
          PRIMARY KEY (`id`),
          UNIQUE KEY `domain` (`domain`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='邮箱黑名单'", $tablepre);
        $results[] = ['name' => 'email_blacklist', 'ok' => $r['ok'], 'message' => $r['message']];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "安全设置升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradePermissionSystem(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 创建 group_permission 表
        $r = $this->createTable('group_permission', "CREATE TABLE `{$tablepre}group_permission` (
          `gid` smallint(6) unsigned NOT NULL DEFAULT 0,
          `permission_key` varchar(64) NOT NULL DEFAULT '',
          `value` tinyint(1) unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY (`gid`, `permission_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户组权限'", $tablepre);
        $results[] = ['name' => 'group_permission', 'ok' => $r['ok'], 'message' => $r['message']];

        // 迁移旧权限数据
        $permissionFields = [
            'allowread', 'allowthread', 'allowpost', 'allowattach', 'allowdown',
            'allowtop', 'allowupdate', 'allowdelete', 'allowmove', 'allowbanuser', 'allowdeleteuser', 'allowviewip',
        ];

        $groups = $this->db->find('group', [], [], 1, 1000, 'gid');
        $migrated = 0;
        foreach ($groups as $group) {
            $gid = intval($group['gid']);
            foreach ($permissionFields as $field) {
                if (isset($group[$field])) {
                    $value = intval($group[$field]);
                    $this->execSql("INSERT IGNORE INTO `{$tablepre}group_permission` (`gid`, `permission_key`, `value`) VALUES ({$gid}, '{$field}', {$value})");
                    $migrated++;
                }
            }
        }
        $results[] = ['name' => 'group_permission.migrate', 'ok' => true, 'message' => "迁移 {$migrated} 条权限记录"];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "权限系统升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeAdminLogTable(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        $r = $this->createTable('admin_log', "CREATE TABLE `{$tablepre}admin_log` (
          id int(11) unsigned NOT NULL AUTO_INCREMENT,
          uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作者uid',
          action varchar(32) NOT NULL DEFAULT '' COMMENT '操作类型',
          target_type varchar(32) NOT NULL DEFAULT '' COMMENT '目标类型',
          target_ids varchar(255) NOT NULL DEFAULT '' COMMENT '目标ID列表',
          detail varchar(1024) NOT NULL DEFAULT '' COMMENT '操作详情',
          ip int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作IP',
          create_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作时间',
          PRIMARY KEY (id),
          KEY idx_uid (uid),
          KEY idx_action (action),
          KEY idx_create_date (create_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='管理员操作日志'", $tablepre);
        $results[] = ['name' => 'admin_log', 'ok' => $r['ok'], 'message' => $r['message']];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "管理操作日志表升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }
    
    public function upgradeFriendlinkTable(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 创建友情链接表
        $r = $this->createTable('friendlink', "CREATE TABLE `{$tablepre}friendlink` (
          `linkid` bigint(11) unsigned NOT NULL AUTO_INCREMENT,
          `type` smallint(11) NOT NULL DEFAULT '0',
          `rank` smallint(11) NOT NULL DEFAULT '0',
          `create_date` int(11) unsigned NOT NULL DEFAULT '0',
          `name` char(32) NOT NULL DEFAULT '',
          `url` char(64) NOT NULL DEFAULT '',
          `favicon` char(128) NOT NULL DEFAULT '',
          PRIMARY KEY (`linkid`),
          KEY `type` (`type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='友情链接'", $tablepre);
        $results[] = ['name' => 'friendlink', 'ok' => $r['ok'], 'message' => $r['message']];

        // 添加精华帖相关字段
        $columns = [
            ['thread', 'is_digest', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `is_digest` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否精华: 0否/1是'"],
            ['thread', 'digest_date', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `digest_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '精华时间'"],
        ];
        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // 创建精华帖索引
        if (!$this->dbIndexExists('thread', 'idx_is_digest', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}thread` ADD INDEX `idx_is_digest` (`is_digest`)");
            $results[] = ['name' => 'thread.idx_is_digest', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'thread.idx_is_digest', 'ok' => true, 'message' => '已存在，跳过'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "友情链接与精华帖升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradeEmailLogTable(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        $r = $this->createTable('email_log', "CREATE TABLE `{$tablepre}email_log` (
          `logid` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `to_email` varchar(200) NOT NULL DEFAULT '' COMMENT '收件人邮箱',
          `subject` varchar(200) NOT NULL DEFAULT '' COMMENT '邮件主题',
          `smtp_host` varchar(100) NOT NULL DEFAULT '' COMMENT 'SMTP服务器',
          `status` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '状态: 0=失败, 1=成功',
          `error_msg` varchar(500) NOT NULL DEFAULT '' COMMENT '错误信息',
          `create_date` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
          `ip` int(11) unsigned NOT NULL DEFAULT '0' COMMENT 'IP地址',
          PRIMARY KEY (`logid`),
          KEY `idx_to_email` (`to_email`),
          KEY `idx_status` (`status`),
          KEY `idx_create_date` (`create_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件发送日志'", $tablepre);
        $results[] = ['name' => 'email_log', 'ok' => $r['ok'], 'message' => $r['message']];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "邮件日志表升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function upgradePluginTable(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 创建插件表
        $r = $this->createTable('plugin', "CREATE TABLE `{$tablepre}plugin` (
          dir varchar(64) NOT NULL COMMENT '插件目录名',
          name varchar(128) NOT NULL DEFAULT '' COMMENT '插件名称',
          type tinyint(1) NOT NULL DEFAULT 0 COMMENT '类型: 0=插件, 1=模板',
          installed tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已安装',
          enable tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已启用',
          install_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '安装时间',
          enable_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '最后启用时间',
          disable_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '最后禁用时间',
          create_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '记录创建时间',
          update_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '记录更新时间',
          PRIMARY KEY (dir),
          KEY type (type),
          KEY enable (enable),
          KEY install_time (install_time),
          KEY enable_time (enable_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'plugin', 'ok' => $r['ok'], 'message' => $r['message']];

        // 如果表是新创建的，初始化现有插件数据
        if ($r['message'] !== '已存在，跳过') {
            plugin_init();
            plugin_db_init_all();
            $results[] = ['name' => 'plugin.init', 'ok' => true, 'message' => '初始化现有插件数据完成'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "插件管理表升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    public function getSteps(): array {
        return [
            ['id' => 'check', 'name' => '前置检查', 'description' => '检查 PHP 版本、磁盘空间、文件权限'],
            ['id' => 'backup', 'name' => '备份', 'description' => '备份数据库和配置文件'],
            ['id' => 'db_structure', 'name' => '数据库结构升级', 'description' => '添加安全增强字段（password_hash, login_attempts 等）和登录日志表'],
            ['id' => 'social_tables', 'name' => '社交功能表升级', 'description' => '新增点赞、收藏、关注、通知表，添加版块分区、帖子互动、用户社交字段'],
            ['id' => 'migrate', 'name' => '数据库迁移', 'description' => '添加附件扩展字段、API 令牌表等'],
            ['id' => 'api_v1', 'name' => 'API v1 表升级', 'description' => 'api_token 双令牌字段、帖子点赞/收藏/举报表'],
            ['id' => 'credits_system', 'name' => '积分系统', 'description' => '创建积分日志表，添加积分系统配置项'],
            ['id' => 'credits_rule', 'name' => '积分规则引擎', 'description' => '创建积分规则表，初始化内置事件规则'],
            ['id' => 'search_indexes', 'name' => '全文搜索索引', 'description' => '为帖子标题和内容添加 FULLTEXT 索引（支持中文分词搜索）'],
            ['id' => 'icon_color_fields', 'name' => '图标与颜色字段', 'description' => '添加用户组图标/颜色、版块图标字段，迁移旧数据并设置默认值'],
            ['id' => 'notice_is_read', 'name' => '通知系统', 'description' => '创建 notice 表（含 is_read 字段）及 user 关联字段，或为已有表添加 is_read 列和索引'],
            ['id' => 'permission_system', 'name' => '权限系统', 'description' => '创建 group_permission 表，迁移旧权限数据，支持统一权限管理'],
            ['id' => 'forum_management', 'name' => '版块管理优化', 'description' => '增加发帖审核/回帖审核权限字段，扩展版块图标字段支持图片路径'],
            ['id' => 'group_audit_permissions', 'name' => '审核权限', 'description' => '添加用户组审核权限字段（发帖审核/回帖审核/资料审核），创建个人资料审核表'],
            ['id' => 'security_settings', 'name' => '安全设置', 'description' => '初始化安全配置项、敏感词库文件、验证码配置、审核字段、IP/邮箱黑名单表'],
            ['id' => 'admin_log_table', 'name' => '管理操作日志表', 'description' => '创建管理操作日志表，用于记录附件删除等后台操作'],
            ['id' => 'plugin_table', 'name' => '插件管理表', 'description' => '创建插件管理表，支持插件时间记录和排序'],
            ['id' => 'email_log', 'name' => '邮件发送日志表', 'description' => '创建邮件发送日志表，记录邮件发送状态、错误信息等'],
            ['id' => 'friendlink_digest', 'name' => '友情链接与精华帖', 'description' => '创建友情链接表，添加帖子精华字段（is_digest, digest_date）'],
            ['id' => 'cache_system', 'name' => '缓存系统优化', 'description' => '迁移旧缓存配置到 setting，清理过时驱动（xcache/apc/yac），初始化默认缓存配置'],
            ['id' => 'password', 'name' => '密码升级', 'description' => '标记旧密码需登录后自动升级'],
            ['id' => 'config', 'name' => '配置调整', 'description' => '更新版本号，新增配置项'],
            ['id' => 'recompile', 'name' => '插件重编译', 'description' => '清空缓存，重编译所有插件'],
        ];
    }

    public function executeStep(string $stepId): array {
        switch ($stepId) {
            case 'check': return $this->checkPrerequisites();
            case 'backup': return $this->backup();
            case 'db_structure': return $this->upgradeDbStructure();
            case 'social_tables': return $this->upgradeSocialTables();
            case 'migrate': return $this->migrateDatabase();
            case 'api_v1': return $this->upgradeApiV1Tables();
            case 'credits_system': return $this->upgradeCreditsSystem();
            case 'credits_rule': return $this->upgradeCreditsRuleTables();
            case 'search_indexes': return $this->upgradeSearchIndexes();
            case 'icon_color_fields': return $this->upgradeIconColorFields();
            case 'notice_is_read': return $this->upgradeNoticeIsRead();
            case 'permission_system': return $this->upgradePermissionSystem();
            case 'forum_management': return $this->upgradeForumManagement();
            case 'group_audit_permissions': return $this->upgradeGroupAuditPermissions();
            case 'security_settings': return $this->upgradeSecuritySettings();
            case 'admin_log_table': return $this->upgradeAdminLogTable();
            case 'plugin_table': return $this->upgradePluginTable();
            case 'email_log': return $this->upgradeEmailLogTable();
            case 'friendlink_digest': return $this->upgradeFriendlinkTable();
            case 'cache_system': return $this->upgradeCacheSystem();
            case 'password': return $this->migratePasswords();
            case 'config': return $this->adjustConfig();
            case 'recompile': return $this->recompilePlugins();
            default: return ['ok' => false, 'message' => '未知步骤：' . $stepId];
        }
    }
}
