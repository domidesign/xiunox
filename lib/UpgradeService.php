<?php

class UpgradeService {
    private $db;
    private array $conf;
    private string $backupPath;
    private string $targetVersion = XIUNOX_VERSION;

    // KV 键名：记录上次升级完成时的版本号（不被 index.php 运行时覆盖）
    const INSTALLED_VERSION_KEY = 'installed_version';

    public function __construct($db, array $conf) {
        $this->db = $db;
        $this->conf = $conf;
        $this->backupPath = $conf['tmp_path'] . 'upgrade_backup_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '/';
    }

    /**
     * 获取已安装版本号（持久化存储，不被代码版本覆盖）
     * 优先读 kv.installed_version；不存在时 fallback 到 $conf['version']
     * （注意：$conf['version'] 在 index.php 中被 XIUNOX_VERSION 覆盖，
     *  所以旧系统首次升级时 fallback 值等于代码版本，需升级完成后 kv 才会写入真实版本）
     */
    public function getInstalledVersion(): string {
        $installed = function_exists('kv_get') ? kv_get(self::INSTALLED_VERSION_KEY) : NULL;
        if (!empty($installed) && is_string($installed)) {
            return $installed;
        }
        return $this->conf['version'] ?? '0.0.0';
    }

    /**
     * 是否需要升级：installed < target
     */
    public function needUpgrade(): bool {
        return version_compare($this->getInstalledVersion(), $this->targetVersion, '<');
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
            'need_upgrade' => $this->needUpgrade(),
            'current_version' => $this->getInstalledVersion(),
            'target_version' => $this->targetVersion,
            'warnings' => $warnings,
        ];
    }

    public function backup(): array {
        if (file_exists($this->backupPath)) {
            return ['ok' => true, 'message' => '备份目录已存在，跳过备份', 'backup_path' => $this->backupPath, 'files' => []];
        }
        $oldUmask = umask(0);
        $created = @mkdir($this->backupPath, 0755, true);
        umask($oldUmask);
        if (!$created) {
            $error = error_get_last();
            $errMsg = !empty($error['message']) ? $error['message'] : '未知错误';
            return ['ok' => false, 'message' => '创建备份目录失败: ' . $this->backupPath . ' (' . $errMsg . ')'];
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
            ['post', 'is_top', "ALTER TABLE `{$tablepre}post` ADD COLUMN `is_top` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否置顶评论: 0否/1是' AFTER `audit_status`"],
            ['user', 'nickname', "ALTER TABLE `{$tablepre}user` ADD COLUMN `nickname` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '昵称' AFTER `username`"],
            ['credits_rule_global', 'daily_limit', "ALTER TABLE `{$tablepre}credits_rule_global` ADD COLUMN `daily_limit` INT NOT NULL DEFAULT 0 COMMENT '每日防刷限制次数，0使用全局设置' AFTER `enabled`"],
            ['credits_rule_forum', 'daily_limit', "ALTER TABLE `{$tablepre}credits_rule_forum` ADD COLUMN `daily_limit` INT NOT NULL DEFAULT 0 COMMENT '每日防刷限制次数，0使用全局设置' AFTER `enabled`"],
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

        // 昵称修改日志表
        $r = $this->createTable('nickname_change_log', "CREATE TABLE `{$tablepre}nickname_change_log` (
          id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
          uid INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户id',
          old_nickname VARCHAR(32) NOT NULL DEFAULT '' COMMENT '旧昵称',
          new_nickname VARCHAR(32) NOT NULL DEFAULT '' COMMENT '新昵称',
          change_time INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '修改时间',
          ip INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作IP',
          PRIMARY KEY (id),
          KEY (uid, change_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'nickname_change_log', 'ok' => $r['ok'], 'message' => $r['message']];

        // 签名修改日志表
        $r = $this->createTable('signature_change_log', "CREATE TABLE `{$tablepre}signature_change_log` (
          id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
          uid INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户id',
          old_signature VARCHAR(255) NOT NULL DEFAULT '' COMMENT '旧签名',
          new_signature VARCHAR(255) NOT NULL DEFAULT '' COMMENT '新签名',
          change_time INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '修改时间',
          ip INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作IP',
          PRIMARY KEY (id),
          KEY (uid, change_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'signature_change_log', 'ok' => $r['ok'], 'message' => $r['message']];

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
              fid smallint(5) unsigned NOT NULL DEFAULT '0',
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
          daily_limit INT NOT NULL DEFAULT 0 COMMENT '每日防刷限制次数，0使用全局设置',
          PRIMARY KEY (ruleid),
          UNIQUE KEY event (event)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'credits_rule_global', 'ok' => $r['ok'], 'message' => $r['message']];

        // 创建版块规则覆盖表
        $r = $this->createTable('credits_rule_forum', "CREATE TABLE `{$tablepre}credits_rule_forum` (
          id int(11) unsigned NOT NULL AUTO_INCREMENT,
          fid smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT '版块ID',
          event varchar(32) NOT NULL DEFAULT '' COMMENT '事件标识',
          credits_change int(11) NOT NULL DEFAULT 0 COMMENT '积分变化值',
          golds_change int(11) NOT NULL DEFAULT 0 COMMENT '金币变化值',
          rmbs_change int(11) NOT NULL DEFAULT 0 COMMENT '人民币变化值',
          enabled tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
          daily_limit INT NOT NULL DEFAULT 0 COMMENT '每日防刷限制次数，0使用全局设置',
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
            ];
            foreach ($builtinRules as $rule) {
                $this->execSql("INSERT IGNORE INTO `{$tablepre}credits_rule_global` (`event`, `label`, `credits_change`, `golds_change`, `rmbs_change`, `enabled`) VALUES ('{$rule[0]}', '{$rule[1]}', 0, 0, 0, 1)");
            }
            $results[] = ['name' => 'credits_rule_global.init_data', 'ok' => true, 'message' => '插入 ' . count($builtinRules) . ' 条内置规则'];
        }

        // 补充 unlike/unfavorite 事件（对已存在的系统追加新事件，使用 INSERT IGNORE 避免重复）
        $newEvents = [
            ['unlike', '取消点赞'],
            ['unfavorite', '取消收藏'],
        ];
        $insertedNew = 0;
        foreach ($newEvents as $ev) {
            $r = $this->execSql("INSERT IGNORE INTO `{$tablepre}credits_rule_global` (`event`, `label`, `credits_change`, `golds_change`, `rmbs_change`, `enabled`, `daily_limit`) VALUES ('{$ev[0]}', '{$ev[1]}', 0, 0, 0, 1, 0)");
            if ($r['ok']) $insertedNew++;
        }
        $results[] = ['name' => 'credits_rule_global.unlike_unfavorite', 'ok' => true, 'message' => "补充 unlike/unfavorite 事件（新增 {$insertedNew} 条）"];

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

    /**
     * 创建 API 应用表，自动生成默认应用凭据
     */
    public function upgradeApiAppTable(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 创建 api_app 表
        $r = $this->createTable('api_app', "CREATE TABLE `{$tablepre}api_app` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `appid` varchar(32) NOT NULL COMMENT '应用ID',
          `secret` varchar(64) NOT NULL COMMENT '应用密钥',
          `name` varchar(100) NOT NULL COMMENT '应用名称',
          `description` varchar(255) DEFAULT '' COMMENT '应用描述',
          `scope` varchar(20) NOT NULL DEFAULT 'readonly' COMMENT '权限范围: readonly/readwrite/full',
          `is_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
          `uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建者UID',
          `rate_limit` int(11) unsigned NOT NULL DEFAULT 120 COMMENT '每分钟请求上限(0=不限)',
          `created_at` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
          PRIMARY KEY (`id`),
          UNIQUE KEY `appid` (`appid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='API应用表'", $tablepre);
        $results[] = ['name' => 'api_app', 'ok' => $r['ok'], 'message' => $r['message']];

        // 表为新创建时，自动插入默认应用
        if ($r['message'] === '完成') {
            $appid = bin2hex(random_bytes(8));
            $secret = bin2hex(random_bytes(16));
            $now = time();

            $insertSql = "INSERT INTO `{$tablepre}api_app` (`appid`, `secret`, `name`, `description`, `scope`, `is_enabled`, `uid`, `rate_limit`, `created_at`)
                VALUES ('{$appid}', '{$secret}', '默认应用', '系统自动创建的默认应用，用于前台页面', 'full', 1, 0, 0, {$now})";
            $ir = $this->execSql($insertSql);
            $results[] = ['name' => 'api_app.default_app', 'ok' => $ir['ok'], 'message' => $ir['ok'] ? '默认应用已创建' : $ir['message']];

            // 将默认应用凭据写入 conf.php
            if ($ir['ok'] && function_exists('file_replace_var')) {
                $confFile = APP_PATH . 'conf/conf.php';
                if (is_writable($confFile)) {
                    $changes = [];
                    if (!isset($this->conf['api_default_appid'])) {
                        $changes['api_default_appid'] = $appid;
                    }
                    if (!isset($this->conf['api_default_secret'])) {
                        $changes['api_default_secret'] = $secret;
                    }
                    if (!empty($changes)) {
                        $wr = file_replace_var($confFile, $changes);
                        if ($wr) {
                            $results[] = ['name' => 'api_app.conf_update', 'ok' => true, 'message' => '已写入 ' . count($changes) . ' 项配置到 conf.php'];
                        } else {
                            $results[] = ['name' => 'api_app.conf_update', 'ok' => false, 'message' => '写入 conf.php 失败'];
                        }
                    }
                } else {
                    $results[] = ['name' => 'api_app.conf_update', 'ok' => false, 'message' => 'conf/conf.php 不可写'];
                }
            }
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "API 应用认证表升级完成（{$doneCount} 项操作）" : '部分升级失败',
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
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $total = $this->db->count('user');

        // 统计旧格式用户（password 字段非空，说明是 md5(md5(明文)+salt) 格式）
        $legacyUsers = 0;
        $pages = ceil($total / $batchSize);
        for ($page = 1; $page <= $pages; $page++) {
            $users = $this->db->find('user', [], [], $page, $batchSize, 'uid');
            foreach ($users as $user) {
                if (!empty($user['password']) && !empty($user['salt'])) {
                    $legacyUsers++;
                }
            }
        }

        // 清空旧格式用户的 password_hash，让他们下次登录时走旧 password 字段验证后自动升级为 bcrypt(明文)
        // 注意：仅清空 password 非空的用户（旧格式），全新安装的用户 password 为空不受影响
        $r = $this->execSql("UPDATE `{$tablepre}user` SET `password_hash` = '' WHERE `password` != ''");

        return [
            'ok' => $r['ok'],
            'message' => "密码迁移：{$legacyUsers} 个旧格式用户已清空 password_hash，下次登录时自动升级为 bcrypt(明文)",
            'total' => $total,
            'pending' => $legacyUsers,
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

        // 数据库连接 charset 升级为 utf8mb4（支持 emoji）
        if (isset($this->conf['db']['pdo_mysql']['master']['charset']) && $this->conf['db']['pdo_mysql']['master']['charset'] !== 'utf8mb4') {
            $this->conf['db']['pdo_mysql']['master']['charset'] = 'utf8mb4';
            $changes['db.pdo_mysql.master.charset'] = 'utf8mb4';
        }
        if (isset($this->conf['db']['pdo_mysql']['slaves'])) {
            foreach ($this->conf['db']['pdo_mysql']['slaves'] as $i => &$slave) {
                if (isset($slave['charset']) && $slave['charset'] !== 'utf8mb4') {
                    $slave['charset'] = 'utf8mb4';
                    $changes["db.pdo_mysql.slaves.{$i}.charset"] = 'utf8mb4';
                }
            }
            unset($slave);
        }
        // 兼容旧版 mysql 驱动配置
        if (isset($this->conf['db']['mysql']['master']['charset']) && $this->conf['db']['mysql']['master']['charset'] !== 'utf8mb4') {
            $this->conf['db']['mysql']['master']['charset'] = 'utf8mb4';
            $changes['db.mysql.master.charset'] = 'utf8mb4';
        }

        $this->conf['version'] = $this->targetVersion;
        $changes['version'] = $this->targetVersion;

        $content = "<?php\nreturn " . var_export($this->conf, true) . ";\n?>";
        if (file_put_contents($confFile, $content) === false) {
            return ['ok' => false, 'message' => '写入 conf/conf.php 失败'];
        }

        // 持久化记录已安装版本到 kv（核心：区分「已安装版本」和「代码版本」）
        // index.php 运行时会用 XIUNOX_VERSION 覆盖 $conf['version']，导致 conf.php 的 version 字段无法反映真实安装版本
        // kv 的 installed_version 是真实安装版本的唯一可信来源
        if (function_exists('kv_set')) {
            kv_set(self::INSTALLED_VERSION_KEY, $this->targetVersion);
        }

        return [
            'ok' => true,
            'message' => '配置更新完成，共 ' . count($changes) . ' 项变更',
            'changes' => $changes,
        ];
    }

    public function upgradeUtf8mb4(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 需要转换的核心表（含文本字段，可能存储 emoji）
        $tables = [
            'thread', 'post', 'user', 'forum', 'group', 'attach',
            'modlog', 'notify', 'kv',
        ];

        foreach ($tables as $table) {
            $fullTable = $tablepre . $table;
            // 检查表是否存在
            $exists = $this->dbTableExists($table, $tablepre);
            if (!$exists) {
                $results[] = ['name' => $table, 'ok' => true, 'message' => '表不存在，跳过'];
                continue;
            }

            // 检查当前字符集
            $colInfo = $this->db->sqlFindOne("SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$fullTable}'");
            $currentCollation = !empty($colInfo) ? $colInfo['TABLE_COLLATION'] : '';

            if (strpos($currentCollation, 'utf8mb4') === 0) {
                $results[] = ['name' => $table, 'ok' => true, 'message' => '已是 utf8mb4，跳过'];
                continue;
            }

            // 转换表字符集
            $r = $this->execSql("ALTER TABLE `{$fullTable}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $results[] = ['name' => $table, 'ok' => $r['ok'], 'message' => $r['ok'] ? '已转换为 utf8mb4' : $r['message']];
        }

        // 修复已损坏的 emoji（? 字符无法恢复，但确保后续写入正确）
        $results[] = ['name' => 'note', 'ok' => true, 'message' => '已有 emoji 数据若显示为?则无法恢复，新写入的 emoji 将正常显示'];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已是 utf8mb4，跳过' && $r['message'] !== '表不存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "UTF8MB4 升级完成（{$doneCount} 项转换）" : '部分转换失败',
            'results' => $results,
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

    /**
     * 性能索引优化：为高频查询添加联合索引
     */
    public function upgradePerfIndexes(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // thread 表：用户帖子列表 WHERE uid=X ORDER BY tid DESC
        if (!$this->dbIndexExists('thread', 'idx_uid_tid', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}thread` ADD INDEX `idx_uid_tid` (`uid`, `tid`)");
            $results[] = ['name' => 'thread.idx_uid_tid', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'thread.idx_uid_tid', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // thread 表：用户版块帖子 WHERE uid=X AND fid IN(...) ORDER BY lastpid DESC
        if (!$this->dbIndexExists('thread', 'idx_uid_fid', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}thread` ADD INDEX `idx_uid_fid` (`uid`, `fid`)");
            $results[] = ['name' => 'thread.idx_uid_fid', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'thread.idx_uid_fid', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // post 表：用户回帖列表 WHERE uid=X AND isfirst=0 ORDER BY pid DESC
        if (!$this->dbIndexExists('post', 'idx_uid_isfirst_pid', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}post` ADD INDEX `idx_uid_isfirst_pid` (`uid`, `isfirst`, `pid`)");
            $results[] = ['name' => 'post.idx_uid_isfirst_pid', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'post.idx_uid_isfirst_pid', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // thread 表：审核状态过滤 WHERE fid=X AND audit_status!=0 ORDER BY lastpid DESC
        if (!$this->dbIndexExists('thread', 'idx_fid_audit_lastpid', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}thread` ADD INDEX `idx_fid_audit_lastpid` (`fid`, `audit_status`, `lastpid`)");
            $results[] = ['name' => 'thread.idx_fid_audit_lastpid', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'thread.idx_fid_audit_lastpid', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // user_login_log 表：IP 维度登录限流查询 WHERE ip=X AND success=0 AND time>Y ORDER BY time
        // 避免 IP 限流检查全表扫描
        if ($this->dbTableExists('user_login_log', $tablepre) && !$this->dbIndexExists('user_login_log', 'idx_ip_success_time', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}user_login_log` ADD INDEX `idx_ip_success_time` (`ip`, `success`, `time`)");
            $results[] = ['name' => 'user_login_log.idx_ip_success_time', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'user_login_log.idx_ip_success_time', 'ok' => true, 'message' => '已存在，跳过'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "性能索引优化完成（{$doneCount} 项新增）" : '部分索引创建失败',
            'results' => $results,
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

    /**
     * 统一 fid 字段类型为 smallint(5) unsigned
     * 修复历史遗留的 fid 类型不一致（int/smallint(6)/tinyint(3) 混用），
     * 避免 JOIN 时隐式类型转换导致索引失效。
     * 使用 MODIFY COLUMN 幂等操作，重复执行无副作用。
     */
    public function upgradeFidFieldType(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 安全检查：forum.fid 最大值不能超过 smallint 上限（65535）
        if ($this->dbTableExists('forum', $tablepre)) {
            // 保留 db_sql_find_one：聚合 MAX(fid) 无需 db_find_one
            $maxRow = $this->db->sqlFindOne("SELECT MAX(fid) AS maxfid FROM `{$tablepre}forum`");
            $maxFid = isset($maxRow['maxfid']) ? intval($maxRow['maxfid']) : 0;
            if ($maxFid > 65535) {
                return [
                    'ok' => false,
                    'message' => "forum.fid 最大值 {$maxFid} 超过 smallint 上限 65535，跳过 fid 类型统一以避免数据截断",
                    'results' => [],
                ];
            }
        }

        // 需要统一 fid 类型的表 => 字段定义（不含字段名，由 MODIFY COLUMN `fid` 后拼接）
        $targets = [
            'forum'              => "smallint(5) unsigned NOT NULL AUTO_INCREMENT",
            'forum_access'       => "smallint(5) unsigned NOT NULL DEFAULT '0'",
            'thread'             => "smallint(5) unsigned NOT NULL DEFAULT '0'",
            'thread_top'         => "smallint(5) unsigned NOT NULL DEFAULT '0'",
            'thread_digest'      => "smallint(5) unsigned NOT NULL DEFAULT '0'",
            'forum_follow'       => "smallint(5) unsigned NOT NULL DEFAULT '0'",
            'credits_rule_forum' => "smallint(5) unsigned NOT NULL DEFAULT '0'",
            'session'            => "smallint(5) unsigned NOT NULL DEFAULT '0'",
        ];

        foreach ($targets as $table => $colDef) {
            if (!$this->dbTableExists($table, $tablepre)) {
                $results[] = ['name' => "{$table}.fid", 'ok' => true, 'message' => '表不存在，跳过'];
                continue;
            }
            $r = $this->execSql("ALTER TABLE `{$tablepre}{$table}` MODIFY COLUMN `fid` {$colDef}");
            $results[] = [
                'name'    => "{$table}.fid",
                'ok'      => $r['ok'],
                'message' => $r['ok'] ? '已统一为 smallint(5) unsigned' : $r['message'],
            ];
        }

        return [
            'ok' => true,
            'message' => 'fid 字段类型统一完成',
            'results' => $results,
        ];
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
        // notice 表已废弃合并到 notify，此处只建 notify 表
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
            $results[] = ['name' => 'notify', 'ok' => $r['ok'], 'message' => $r['ok'] ? '建表完成' : $r['message']];

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
            $oldUmask = umask(0);
            $created = @mkdir($cacheDir, 0755, true);
            umask($oldUmask);
            if ($created) {
                $results[] = ['name' => 'cache_dir_create', 'ok' => true, 'message' => '缓存目录已创建'];
            } else {
                $error = error_get_last();
                $errMsg = !empty($error['message']) ? $error['message'] : '未知错误';
                $results[] = ['name' => 'cache_dir_create', 'ok' => false, 'message' => '创建缓存目录失败: ' . $cacheDir . ' (' . $errMsg . ')'];
            }
        } else {
            $results[] = ['name' => 'cache_dir_create', 'ok' => true, 'message' => '已存在，跳过'];
        }

        // 4. 扩容 bbs_cache.k 字段：char(32) → varchar(255)
        // ponytail: CACHE_KEY_MD5_THRESHOLD=200 允许长键，但字段只有 char(32)，
        // STRICT_TRANS_TABLES 模式下超 32 字符的 cache_set 会失败（如 thread_pl_replies_<tid>_<md5>_v<n> = 56字符），
        // 导致帖子回复列表缓存形同虚设，每次访问都重查 DB。扩到 varchar(255) 让长键能正常写入。
        $cacheTable = $this->conf['db']['tablepre'] . 'cache';
        $colInfo = $this->db->query("SHOW COLUMNS FROM `{$cacheTable}` LIKE 'k'")->fetch();
        if ($colInfo && stripos($colInfo['Type'], 'char(32)') !== false) {
            try {
                $this->db->exec("ALTER TABLE `{$cacheTable}` MODIFY `k` VARCHAR(255) NOT NULL DEFAULT ''");
                $results[] = ['name' => 'cache_key_varchar', 'ok' => true, 'message' => 'bbs_cache.k 已扩容 char(32) → varchar(255)'];
            } catch (\Throwable $e) {
                $results[] = ['name' => 'cache_key_varchar', 'ok' => false, 'message' => '扩容 bbs_cache.k 失败: ' . $e->getMessage()];
            }
        } else {
            $results[] = ['name' => 'cache_key_varchar', 'ok' => true, 'message' => '已扩容或字段不存在，跳过'];
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
                $oldUmask = umask(0);
                @mkdir($dir, 0755, true);
                umask($oldUmask);
            }
            if (!is_dir($dir)) {
                $results[] = ['name' => 'sensitive_words.txt', 'ok' => false, 'message' => '创建目录失败: ' . $dir];
            } elseif (file_put_contents($wordsFile, "# 敏感词库\n# 每行一个词，# 开头为注释\n") !== false) {
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
                $oldUmask = umask(0);
                @mkdir($dir, 0755, true);
                umask($oldUmask);
            }
            if (!is_dir($dir)) {
                $results[] = ['name' => 'config/security.php', 'ok' => false, 'message' => '创建目录失败: ' . $dir];
            } else {
                $defaultSecurityConfig = "<?php\n// 安全与审核系统配置\n// 修改后需清理 tmp/ 缓存\n\nreturn array(\n\n    'captcha' => array(\n        'login' => 0,\n        'register' => 0,\n        'post' => 0,\n        'resetpw' => 0,\n        'type' => 'gd_image',\n    ),\n\n    'sensitive_word' => array(\n        'enabled' => 0,\n        'action' => 'reject',\n        'words_file' => APP_PATH . 'config/sensitive_words.txt',\n    ),\n\n    'audit' => array(\n        'enabled' => 0,\n        'credits_on_approve' => 0,\n        'credits_amount' => 1,\n    ),\n\n    'moderation' => array(\n        'enabled' => 0,\n    ),\n\n    'security' => array(\n        'prevent_enumeration' => 1,\n        'verify_sensitive_action' => 1,\n        'show_last_login' => 1,\n    ),\n\n);\n";
                if (file_put_contents($securityConfigFile, $defaultSecurityConfig) !== false) {
                    $results[] = ['name' => 'config/security.php', 'ok' => true, 'message' => '创建完成'];
                } else {
                    $results[] = ['name' => 'config/security.php', 'ok' => false, 'message' => '创建失败'];
                }
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
            // 驳回重提相关字段
            ['thread', 'resubmit_count', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `resubmit_count` tinyint(3) NOT NULL DEFAULT 0 COMMENT '重新提交次数（含首次发布）'"],
            ['post', 'resubmit_count', "ALTER TABLE `{$tablepre}post` ADD COLUMN `resubmit_count` tinyint(3) NOT NULL DEFAULT 0 COMMENT '重新提交次数（含首次发布）'"],
            ['thread', 'reject_reason', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `reject_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '驳回原因'"],
            ['post', 'reject_reason', "ALTER TABLE `{$tablepre}post` ADD COLUMN `reject_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '驳回原因'"],
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

        // 添加精华帖相关字段
        $columns = [
            ['thread', 'digest', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `digest` tinyint(1) NOT NULL DEFAULT 0 COMMENT '精华级别: 0否/1-3精华'"],
            ['thread', 'digest_date', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `digest_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '精华时间'"],
        ];
        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // 创建精华帖索引表（thread_digest_change 依赖此表）
        $r = $this->createTable('thread_digest', "CREATE TABLE `{$tablepre}thread_digest` (
          `fid` smallint(5) unsigned NOT NULL DEFAULT '0',
          `tid` int(11) unsigned NOT NULL DEFAULT '0',
          `uid` int(11) unsigned NOT NULL DEFAULT '0',
          `digest` tinyint(6) NOT NULL DEFAULT '0',
          PRIMARY KEY (`tid`),
          KEY `fid` (`fid`),
          KEY `uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='精华主题'", $tablepre);
        $results[] = ['name' => 'thread_digest', 'ok' => $r['ok'], 'message' => $r['message']];

        // 创建精华帖索引
        if (!$this->dbIndexExists('thread', 'idx_digest', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}thread` ADD INDEX `idx_digest` (`digest`)");
            $results[] = ['name' => 'thread.idx_digest', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'thread.idx_digest', 'ok' => true, 'message' => '已存在，跳过'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "精华帖升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    /**
     * 软删除字段：为 thread 和 post 表添加 is_deleted, deleted_date, deleted_by 字段及索引
     */
    public function upgradeSoftDeleteFields(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // thread 表添加软删除字段
        $columns = [
            ['thread', 'is_deleted', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `is_deleted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已删除: 0否/1是' AFTER `announcement_order`"],
            ['thread', 'deleted_date', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `deleted_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '删除时间' AFTER `is_deleted`"],
            ['thread', 'deleted_by', "ALTER TABLE `{$tablepre}thread` ADD COLUMN `deleted_by` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '删除操作者uid' AFTER `deleted_date`"],
            ['post', 'is_deleted', "ALTER TABLE `{$tablepre}post` ADD COLUMN `is_deleted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已删除: 0否/1是' AFTER `is_top`"],
            ['post', 'deleted_date', "ALTER TABLE `{$tablepre}post` ADD COLUMN `deleted_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '删除时间' AFTER `is_deleted`"],
            ['post', 'deleted_by', "ALTER TABLE `{$tablepre}post` ADD COLUMN `deleted_by` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '删除操作者uid' AFTER `deleted_date`"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // 添加 is_deleted 索引
        $indexes = [
            ['thread', 'idx_is_deleted', "ALTER TABLE `{$tablepre}thread` ADD INDEX `idx_is_deleted` (`is_deleted`)"],
            ['post', 'idx_is_deleted', "ALTER TABLE `{$tablepre}post` ADD INDEX `idx_is_deleted` (`is_deleted`)"],
        ];

        foreach ($indexes as $idx) {
            if (!$this->dbIndexExists($idx[0], $idx[1], $tablepre)) {
                $r = $this->execSql($idx[2]);
                $results[] = ['name' => $idx[0].'.'.$idx[1], 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
            } else {
                $results[] = ['name' => $idx[0].'.'.$idx[1], 'ok' => true, 'message' => '已存在，跳过'];
            }
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "软删除字段升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    /**
     * 用户封禁系统：为 user 表添加封禁字段，创建封禁历史记录表与 IP 黑名单表
     */
    public function upgradeUserBanSystem(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 1. 为 user 表添加封禁相关字段（放在 banned_until 之后）
        $columns = [
            ['user', 'ban_type', "ALTER TABLE `{$tablepre}user` ADD COLUMN `ban_type` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '封禁类型:0正常/1禁言/2禁止访问/3锁定' AFTER `banned_until`"],
            ['user', 'ban_reason', "ALTER TABLE `{$tablepre}user` ADD COLUMN `ban_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '封禁原因' AFTER `ban_type`"],
            ['user', 'ban_admin_uid', "ALTER TABLE `{$tablepre}user` ADD COLUMN `ban_admin_uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作管理员uid' AFTER `ban_reason`"],
            ['user', 'ban_time', "ALTER TABLE `{$tablepre}user` ADD COLUMN `ban_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '封禁时间戳' AFTER `ban_admin_uid`"],
        ];

        foreach ($columns as $col) {
            $r = $this->addColumn($col[0], $col[1], $col[2], $tablepre);
            $results[] = ['name' => $col[0].'.'.$col[1], 'ok' => $r['ok'], 'message' => $r['message']];
        }

        // 2. 创建封禁历史记录表
        $r = $this->createTable('user_ban_log', "CREATE TABLE `{$tablepre}user_ban_log` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '被操作用户uid',
          `admin_uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作管理员uid',
          `action` varchar(20) NOT NULL DEFAULT '' COMMENT '操作类型:ban/unban/auto_unban/clear_content',
          `ban_type` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '封禁类型',
          `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因',
          `duration` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '封禁时长(秒),0表示永久',
          `create_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作时间戳',
          PRIMARY KEY (`id`),
          KEY `uid` (`uid`),
          KEY `create_time` (`create_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $tablepre);
        $results[] = ['name' => 'user_ban_log', 'ok' => $r['ok'], 'message' => $r['message']];

        // 3. 初始化已存在用户 ban_type=0（字段 DEFAULT 0 已保证，此处幂等兜底）
        // 注：废弃的 banned_ip 表不再创建，IP 黑名单统一由 IpBlacklistService（kv 存储）管理，
        // 旧站点若存在 banned_ip 表数据，由 migrate_banned_ip 升级步骤自动迁移
        $r = $this->execSql("UPDATE `{$tablepre}user` SET `ban_type` = 0 WHERE `ban_type` IS NULL OR `ban_type` NOT IN (0,1,2,3)");
        $results[] = ['name' => 'user.ban_type_init', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "用户封禁系统升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }

    /**
     * 迁移 banned_ip 表数据到 IpBlacklistService（kv 存储）
     * 幂等：通过 kv 标记 banned_ip_migrated 防止重复迁移
     * 转换规则：
     *   - ip_start == ip_end → 单个 IP（如 192.168.1.1）
     *   - ip_start != ip_end → 范围格式（如 192.168.1.1-192.168.1.10）
     *   - 已过期记录（expire_time > 0 且 <= now）跳过
     */
    public function migrateBannedIpToBlacklist(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 1. 检测 banned_ip 表是否存在（新安装无此表，直接标记已迁移避免重复检测）
        if (!$this->dbTableExists('banned_ip', $tablepre)) {
            kv_set('banned_ip_migrated', 1);
            return ['ok' => true, 'message' => 'banned_ip 表不存在（新安装），已标记跳过迁移', 'results' => []];
        }

        // 2. 加载 IpBlacklistService
        if (!class_exists('IpBlacklistService')) {
            include_once APP_PATH . 'lib/security/IpBlacklistService.php';
        }
        if (!class_exists('IpBlacklistService')) {
            return ['ok' => false, 'message' => 'IpBlacklistService 类不可用', 'results' => []];
        }

        // 3. 幂等检查
        $migrated = kv_get('banned_ip_migrated');
        if ($migrated === 1) {
            return ['ok' => true, 'message' => 'banned_ip 数据已迁移，跳过', 'results' => []];
        }

        // 4. 读取所有未过期记录
        $now = time();
        $sql = "SELECT * FROM `{$tablepre}banned_ip` WHERE expire_time = 0 OR expire_time > {$now}";
        $rows = $this->db->sqlFind($sql);

        if (empty($rows)) {
            kv_set('banned_ip_migrated', 1);
            return ['ok' => true, 'message' => 'banned_ip 表无未过期记录，已标记为迁移完成', 'results' => []];
        }

        // 5. 转换并写入 IpBlacklistService
        $migratedCount = 0;
        $skippedCount = 0;
        foreach ($rows as $row) {
            $ip_start_long = intval($row['ip_start']);
            $ip_end_long = intval($row['ip_end']);
            $ip_start_str = long2ip($ip_start_long);
            $ip_end_str = long2ip($ip_end_long);

            // ip_start == ip_end → 单 IP；否则用范围格式
            $ip = ($ip_start_long === $ip_end_long) ? $ip_start_str : $ip_start_str . '-' . $ip_end_str;

            $r = IpBlacklistService::add_blacklist_entry(
                $ip,
                isset($row['reason']) ? $row['reason'] : '',
                intval($row['expire_time']),
                intval($row['admin_uid'])
            );
            if ($r) {
                $migratedCount++;
            } else {
                $skippedCount++;
            }
        }

        // 6. 标记已迁移
        kv_set('banned_ip_migrated', 1);

        $results[] = [
            'name' => 'migrate_banned_ip',
            'ok' => true,
            'message' => "迁移 {$migratedCount} 条，跳过 {$skippedCount} 条重复",
        ];

        return [
            'ok' => true,
            'message' => "banned_ip 数据迁移完成：迁移 {$migratedCount} 条到 IpBlacklistService，跳过 {$skippedCount} 条重复",
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
            ['id' => 'api_app', 'name' => 'API 应用认证表', 'description' => '创建 API 应用表，自动生成默认应用凭据'],
            ['id' => 'credits_system', 'name' => '积分系统', 'description' => '创建积分日志表，添加积分系统配置项'],
            ['id' => 'credits_rule', 'name' => '积分规则引擎', 'description' => '创建积分规则表，初始化内置事件规则'],
            ['id' => 'search_indexes', 'name' => '全文搜索索引', 'description' => '为帖子标题和内容添加 FULLTEXT 索引（支持中文分词搜索）'],
            ['id' => 'icon_color_fields', 'name' => '图标与颜色字段', 'description' => '添加用户组图标/颜色、版块图标字段，迁移旧数据并设置默认值'],
            ['id' => 'notice_is_read', 'name' => '通知系统', 'description' => '创建 notify 表（含 is_read 字段）及 user 关联字段，或为已有表添加 is_read 列和索引（notice 表已废弃）'],
            ['id' => 'permission_system', 'name' => '权限系统', 'description' => '创建 group_permission 表，迁移旧权限数据，支持统一权限管理'],
            ['id' => 'forum_management', 'name' => '版块管理优化', 'description' => '增加发帖审核/回帖审核权限字段，扩展版块图标字段支持图片路径'],
            ['id' => 'group_audit_permissions', 'name' => '审核权限', 'description' => '添加用户组审核权限字段（发帖审核/回帖审核/资料审核），创建个人资料审核表'],
            ['id' => 'security_settings', 'name' => '安全设置', 'description' => '初始化安全配置项、敏感词库文件、验证码配置、审核字段、IP/邮箱黑名单表'],
            ['id' => 'admin_log_table', 'name' => '管理操作日志表', 'description' => '创建管理操作日志表，用于记录附件删除等后台操作'],
            ['id' => 'plugin_table', 'name' => '插件管理表', 'description' => '创建插件管理表，支持插件时间记录和排序'],
            ['id' => 'email_log', 'name' => '邮件发送日志表', 'description' => '创建邮件发送日志表，记录邮件发送状态、错误信息等'],
            ['id' => 'friendlink_digest', 'name' => '精华帖', 'description' => '添加帖子精华字段（digest, digest_date），创建精华帖索引表'],
            ['id' => 'soft_delete', 'name' => '软删除字段', 'description' => '为 thread 和 post 表添加 is_deleted, deleted_date, deleted_by 字段及索引，支持软删除功能'],
            ['id' => 'user_ban_system', 'name' => '用户封禁系统', 'description' => '为 user 表添加 ban_type/ban_reason/ban_admin_uid/ban_time 字段，创建封禁历史记录表和 IP 黑名单表'],
            ['id' => 'migrate_banned_ip', 'name' => 'IP黑名单数据迁移', 'description' => '将 banned_ip 表数据迁移到 IpBlacklistService（kv 存储，支持 CIDR 和范围格式）'],
            ['id' => 'cache_system', 'name' => '缓存系统优化', 'description' => '迁移旧缓存配置到 setting，清理过时驱动（xcache/apc/yac），初始化默认缓存配置'],
            ['id' => 'nickname_field', 'name' => '昵称字段迁移', 'description' => '将现有用户名复制到昵称字段，支持用户名不可修改、昵称可修改'],
            ['id' => 'notify_merge', 'name' => '通知系统合并', 'description' => '扩展 notify 表字段（message/icon/url 等），将 notice 表数据迁移到 notify 表，删除旧 notice 表'],
            ['id' => 'utf8mb4', 'name' => 'UTF8MB4 字符集升级', 'description' => '将数据库表从 utf8 转换为 utf8mb4，支持 emoji 等四字节字符'],
            ['id' => 'password', 'name' => '密码升级', 'description' => '标记旧密码需登录后自动升级'],
            ['id' => 'config', 'name' => '配置调整', 'description' => '更新版本号，新增配置项'],
            ['id' => 'user_group_resync', 'name' => '用户组重同步', 'description' => '修复存量用户组与积分不匹配（遍历所有积分用户组用户，按当前 credits 重新计算用户组）'],
            ['id' => 'recompile', 'name' => '插件重编译', 'description' => '清空缓存，重编译所有插件'],
            ['id' => 'perf_indexes', 'name' => '性能索引优化', 'description' => '为用户帖子列表、回帖列表等高频查询添加联合索引，消除全表扫描'],
            ['id' => 'fid_field_type', 'name' => 'fid 字段类型统一', 'description' => '统一所有表的 fid 字段为 smallint(5) unsigned，避免 JOIN 隐式类型转换导致索引失效'],
            ['id' => 'ai_call_log', 'name' => 'AI 调用日志表', 'description' => '创建 xnx_ai_call_log 表，统一记录核心与插件的 AI 调用日志'],
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
            case 'api_app': return $this->upgradeApiAppTable();
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
            case 'soft_delete': return $this->upgradeSoftDeleteFields();
            case 'user_ban_system': return $this->upgradeUserBanSystem();
            case 'migrate_banned_ip': return $this->migrateBannedIpToBlacklist();
            case 'cache_system': return $this->upgradeCacheSystem();
            case 'nickname_field': return $this->upgradeNicknameField();
            case 'notify_merge': return $this->upgradeNotifyMerge();
            case 'utf8mb4': return $this->upgradeUtf8mb4();
            case 'password': return $this->migratePasswords();
            case 'config': return $this->adjustConfig();
            case 'user_group_resync': return $this->upgradeUserGroupResync();
            case 'recompile': return $this->recompilePlugins();
            case 'perf_indexes': return $this->upgradePerfIndexes();
            case 'fid_field_type': return $this->upgradeFidFieldType();
            case 'ai_call_log': return $this->upgradeAiCallLogTable();
            default: return ['ok' => false, 'message' => '未知步骤：' . $stepId];
        }
    }

    /**
     * 昵称字段迁移：将现有 username 复制到 nickname
     */
    public function upgradeNicknameField(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 检查 nickname 字段是否存在
        if (!$this->dbColumnExists('user', 'nickname', $tablepre)) {
            return ['ok' => false, 'message' => 'nickname 字段尚未创建，请先执行数据库结构升级'];
        }

        // 将现有 username 复制到 nickname（仅处理 nickname 为空的记录）
        $sql = "UPDATE `{$tablepre}user` SET `nickname` = `username` WHERE `nickname` = ''";
        $r = $this->execSql($sql);
        $results[] = ['name' => 'copy_username_to_nickname', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];

        // 为 nickname 添加唯一索引
        try {
            $r2 = $this->execSql("ALTER TABLE `{$tablepre}user` ADD UNIQUE INDEX `nickname` (`nickname`)");
            $results[] = ['name' => 'nickname_unique_index', 'ok' => true, 'message' => '完成'];
        } catch (\Exception $e) {
            // 索引可能已存在
            $results[] = ['name' => 'nickname_unique_index', 'ok' => true, 'message' => '已存在，跳过'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "昵称字段迁移完成（{$doneCount} 项操作）" : '部分迁移失败',
            'results' => $results,
        ];
    }

    /**
     * 通知系统合并：将 notice 表数据迁移到 notify 表，扩展 notify 表字段
     */
    public function upgradeNotifyMerge(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 1. 扩展 notify 表字段
        // content 从 char(128) 改为 text
        if ($this->dbColumnExists('notify', 'content', $tablepre)) {
            $r = $this->execSql("ALTER TABLE `{$tablepre}notify` MODIFY COLUMN `content` TEXT COMMENT '内容摘要或全文'");
            $results[] = ['name' => 'notify.content->text', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        }

        // 新增 message 字段（富文本消息）
        $r = $this->addColumn('notify', 'message', "ALTER TABLE `{$tablepre}notify` ADD COLUMN `message` LONGTEXT AFTER `content`", $tablepre);
        $results[] = ['name' => 'notify.message', 'ok' => $r['ok'], 'message' => $r['message']];

        // 新增 icon 字段
        $r = $this->addColumn('notify', 'icon', "ALTER TABLE `{$tablepre}notify` ADD COLUMN `icon` VARCHAR(64) DEFAULT '' AFTER `message`", $tablepre);
        $results[] = ['name' => 'notify.icon', 'ok' => $r['ok'], 'message' => $r['message']];

        // 新增 url 字段
        $r = $this->addColumn('notify', 'url', "ALTER TABLE `{$tablepre}notify` ADD COLUMN `url` VARCHAR(255) DEFAULT '' AFTER `icon`", $tablepre);
        $results[] = ['name' => 'notify.url', 'ok' => $r['ok'], 'message' => $r['message']];

        // 新增 reply_to_uid 字段
        $r = $this->addColumn('notify', 'reply_to_uid', "ALTER TABLE `{$tablepre}notify` ADD COLUMN `reply_to_uid` INT(11) UNSIGNED DEFAULT 0 AFTER `pid`", $tablepre);
        $results[] = ['name' => 'notify.reply_to_uid', 'ok' => $r['ok'], 'message' => $r['message']];

        // 新增 parent_pid 字段
        $r = $this->addColumn('notify', 'parent_pid', "ALTER TABLE `{$tablepre}notify` ADD COLUMN `parent_pid` INT(11) UNSIGNED DEFAULT 0 AFTER `reply_to_uid`", $tablepre);
        $results[] = ['name' => 'notify.parent_pid', 'ok' => $r['ok'], 'message' => $r['message']];

        // 2. 迁移 notice 数据到 notify（仅迁移尚未迁移的数据）
        if ($this->dbTableExists('notice', $tablepre)) {
            // 检查是否已迁移过：全局公告(uid=0)是 notice 表迁移的标志数据
            // 注意：from_uid 是管理员 uid（非0），不能用 from_uid=0 判断
            $checkSql = "SELECT COUNT(*) as cnt FROM `{$tablepre}notify` WHERE uid = 0 AND type IN ('announcement','system','pm','other')";
            $checkResult = $this->db->sqlFindOne($checkSql);
            $alreadyMigrated = !empty($checkResult) && intval($checkResult['cnt']) > 0;

            if (!$alreadyMigrated) {
                $migrateSql = "INSERT INTO `{$tablepre}notify` (`uid`, `from_uid`, `type`, `tid`, `pid`, `content`, `message`, `icon`, `url`, `create_date`, `is_read`)
                SELECT
                  `recvuid`,
                  `fromuid`,
                  CASE `type`
                    WHEN 1 THEN 'announcement'
                    WHEN 3 THEN 'system'
                    WHEN 7 THEN 'pm'
                    ELSE 'other'
                  END,
                  0, 0,
                  LEFT(`message`, 1000),
                  `message`,
                  `icon`,
                  `url`,
                  `create_date`,
                  `is_read`
                FROM `{$tablepre}notice`";
                $r = $this->execSql($migrateSql);
                $results[] = ['name' => 'migrate_notice_data', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
            } else {
                $results[] = ['name' => 'migrate_notice_data', 'ok' => true, 'message' => '已迁移，跳过'];
            }
            // 清理重复的公告数据：保留每个 message 的最小 nid，删除其余重复记录
            $dedupSql = "DELETE n1 FROM `{$tablepre}notify` n1
                INNER JOIN `{$tablepre}notify` n2
                ON n1.`message` = n2.`message`
                AND n1.`uid` = n2.`uid`
                AND n1.`type` = n2.`type`
                AND n1.`nid` > n2.`nid`
                WHERE n1.`uid` = 0 AND n1.`type` IN ('announcement','system','pm','other')";
            $r = $this->execSql($dedupSql);
            $results[] = ['name' => 'dedup_announcements', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'migrate_notice_data', 'ok' => true, 'message' => 'notice 表不存在，跳过'];
        }

        // 3. 更新 user.unread_notices 为合并后的未读数
        $updateCountSql = "UPDATE `{$tablepre}user` u SET u.`unread_notices` = (
            SELECT COUNT(*) FROM `{$tablepre}notify` WHERE `uid` = u.`uid` AND `is_read` = 0
        )";
        $r = $this->execSql($updateCountSql);
        $results[] = ['name' => 'update_unread_count', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];

        // 4. 删除旧的 bbs_notice 表（数据已迁移到 notify，model/notice.func.php 兼容层已移除）
        if ($this->dbTableExists('notice', $tablepre)) {
            $dropSql = "DROP TABLE IF EXISTS `{$tablepre}notice`";
            $r = $this->execSql($dropSql);
            $results[] = ['name' => 'drop_notice_table', 'ok' => $r['ok'], 'message' => $r['ok'] ? '完成' : $r['message']];
        } else {
            $results[] = ['name' => 'drop_notice_table', 'ok' => true, 'message' => '表不存在，跳过'];
        }

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] === '完成'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "通知系统合并完成（{$doneCount} 项操作）" : '部分合并失败',
            'results' => $results,
        ];
    }

    /**
     * 修复存量用户组与积分不匹配
     * 由于历史 bug（gid 被 USER_UPDATE_PROTECTED_FIELDS 过滤 + CreditsService 缓存未清），
     * 部分用户的积分已变动但用户组未更新。此步骤遍历所有积分用户组（gid >= 100）用户，
     * 根据当前 credits 自动重新计算并更新到正确的用户组。
     */
    public function upgradeUserGroupResync(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        // 1. 加载 user_update_group 函数（admin 已通过 index.php 加载过 index.inc.php）
        if (!function_exists('user_update_group')) {
            $modelFile = APP_PATH . 'model/user.func.php';
            if (file_exists($modelFile)) {
                include_once _include($modelFile);
            }
        }
        if (!function_exists('user_update_group')) {
            return [
                'ok' => false,
                'message' => 'user_update_group 函数不可用，跳过',
                'results' => [],
            ];
        }

        // 2. 确保 group_list 已加载（$grouplist）
        global $grouplist;
        if (empty($grouplist)) {
            $grouplist = function_exists('group_list_cache') ? group_list_cache() : array();
        }

        // 3. 查询所有 gid >= 100 的用户
        $sql = "SELECT uid, gid, credits FROM `{$tablepre}user` WHERE gid >= 100";
        $rows = $this->db->sqlFind($sql);
        if (empty($rows)) {
            return [
                'ok' => true,
                'message' => '无积分用户组用户，跳过',
                'results' => [],
            ];
        }

        $scanned = 0;
        $upgraded = 0;
        $unchanged = 0;
        $examples = [];

        foreach ($rows as $row) {
            $scanned++;
            $oldGid = intval($row['gid']);
            $credits = intval($row['credits']);
            $targetGid = $oldGid;

            // 根据积分匹配用户组
            foreach ($grouplist as $group) {
                if ($group['gid'] < 100) continue;
                if ($credits >= $group['creditsfrom'] && $credits < $group['creditsto']) {
                    $targetGid = intval($group['gid']);
                    break;
                }
            }

            if ($targetGid !== $oldGid) {
                // 调用 user_update_group 执行升级（会清缓存 + user__update 写库）
                if (function_exists('user_update_group')) {
                    user_update_group(intval($row['uid']));
                    // user_update_group 找到第一个匹配就 return，可能没真正升级（如果原 gid 不在有效区间）
                    // 兜底：直接 user__update
                    $check = $this->db->findOne('user', ['uid' => intval($row['uid'])]);
                    if (intval($check['gid']) !== $targetGid) {
                        $this->db->update('user', ['uid' => intval($row['uid'])], ['gid' => $targetGid]);
                    }
                } else {
                    $this->db->update('user', ['uid' => intval($row['uid'])], ['gid' => $targetGid]);
                }
                $upgraded++;
                if (count($examples) < 10) {
                    $examples[] = "uid={$row['uid']} gid {$oldGid}->{$targetGid} (credits={$credits})";
                }
            } else {
                $unchanged++;
            }
        }

        $results[] = [
            'name' => 'resync_user_group',
            'ok' => true,
            'message' => "扫描 {$scanned} 个积分用户，升级 {$upgraded} 个，未变 {$unchanged} 个"
                . ($examples ? '，示例：' . implode('; ', $examples) : ''),
        ];

        return [
            'ok' => true,
            'message' => "用户组重同步完成：扫描 {$scanned}，升级 {$upgraded}，未变 {$unchanged}",
            'results' => $results,
        ];
    }

    /**
     * AI 调用日志表：统一记录核心 AIService::call() 与插件的 AI 调用
     */
    public function upgradeAiCallLogTable(): array {
        $tablepre = $this->conf['db']['tablepre'] ?? 'bbs_';
        $results = [];

        $r = $this->createTable('xnx_ai_call_log', "CREATE TABLE `{$tablepre}xnx_ai_call_log` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `uid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '调用用户ID',
          `feature` varchar(32) NOT NULL DEFAULT '' COMMENT '功能标识：editor/xnx_ai_reply 等',
          `source` varchar(32) NOT NULL DEFAULT 'core' COMMENT '来源：core=核心 / 插件目录名',
          `provider_name` varchar(64) NOT NULL DEFAULT '' COMMENT '提供商名称',
          `model` varchar(64) NOT NULL DEFAULT '' COMMENT '调用的模型',
          `mode` varchar(16) NOT NULL DEFAULT '' COMMENT '模式：global/user_key/both',
          `prompt_tokens` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '请求 token 数',
          `completion_tokens` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '响应 token 数',
          `total_tokens` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '总 token 数',
          `response_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '响应耗时（毫秒）',
          `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=失败 1=成功',
          `error_msg` varchar(500) NOT NULL DEFAULT '' COMMENT '错误信息（失败时）',
          `request_summary` varchar(255) NOT NULL DEFAULT '' COMMENT '请求摘要（前 200 字符）',
          `response_summary` varchar(600) NOT NULL DEFAULT '' COMMENT '响应摘要（前 500 字符）',
          `ip` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '调用者 IP（ip2long）',
          `create_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
          PRIMARY KEY (`id`),
          KEY `idx_uid` (`uid`),
          KEY `idx_feature` (`feature`),
          KEY `idx_source` (`source`),
          KEY `idx_status` (`status`),
          KEY `idx_create_time` (`create_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 调用日志（核心+插件统一记录）'", $tablepre);
        $results[] = ['name' => 'xnx_ai_call_log', 'ok' => $r['ok'], 'message' => $r['message']];

        $allOk = !in_array(false, array_column($results, 'ok'), true);
        $doneCount = count(array_filter($results, function($r) { return $r['ok'] && $r['message'] !== '已存在，跳过'; }));
        return [
            'ok' => $allOk,
            'message' => $allOk ? "AI 调用日志表升级完成（{$doneCount} 项操作）" : '部分升级失败',
            'results' => $results,
        ];
    }
}
