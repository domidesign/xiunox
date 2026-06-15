<?php

!defined('DEBUG') AND exit('Access Denied.');

if($method == 'POST') {
    
    global $tablepre;
    
    $sqls = array();
    $results = array();
    
    if(!db_check_column_exists('user', 'password_hash')) {
        $sqls[] = "ALTER TABLE `{$tablepre}user` ADD COLUMN `password_hash` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'bcrypt password hash' AFTER `salt`";
    }
    if(!db_check_column_exists('user', 'login_attempts')) {
        $sqls[] = "ALTER TABLE `{$tablepre}user` ADD COLUMN `login_attempts` INT NOT NULL DEFAULT 0 COMMENT 'login failure count' AFTER `password_hash`";
    }
    if(!db_check_column_exists('user', 'last_login_ip')) {
        $sqls[] = "ALTER TABLE `{$tablepre}user` ADD COLUMN `last_login_ip` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'last login IP' AFTER `login_attempts`";
    }
    if(!db_check_column_exists('user', 'last_login_time')) {
        $sqls[] = "ALTER TABLE `{$tablepre}user` ADD COLUMN `last_login_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'last login time' AFTER `last_login_ip`";
    }
    if(!db_check_column_exists('user', 'banned_until')) {
        $sqls[] = "ALTER TABLE `{$tablepre}user` ADD COLUMN `banned_until` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'login ban expiry' AFTER `last_login_time`";
    }
    if(!db_check_table_exists('user_login_log')) {
        $sqls[] = "CREATE TABLE `{$tablepre}user_login_log` (
          id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
          uid INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'user id',
          ip INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'login IP',
          time INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'login time',
          success TINYINT NOT NULL DEFAULT 0 COMMENT '1=success, 0=failure',
          user_agent VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'browser user agent',
          PRIMARY KEY (id),
          KEY uid (uid),
          KEY time (time)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8";
    }
    
    foreach($sqls as $sql) {
        $r = db_exec($sql);
        $results[] = $r !== FALSE ? 'success' : 'failed';
    }
    
    // 附件安全加固：自动生成签名密钥
    if(empty($conf['attach_sign_key'])) {
        $conf['attach_sign_key'] = bin2hex(random_bytes(16));
        $s = "<?php\r\nreturn ".var_export($conf, true).";\r\n?>";
        $r = file_put_contents_try(APP_PATH.'conf/conf.php', $s);
        $results[] = $r !== FALSE ? 'attach_sign_key_generated' : 'attach_sign_key_failed';
    }

    plugin_clear_tmp_dir();

    message(0, $results);
}

function db_check_column_exists($table, $column) {
    global $tablepre;
    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}{$table}' AND COLUMN_NAME = '{$column}'";
    $r = db_fetch_one($sql);
    return !empty($r);
}

function db_check_table_exists($table) {
    global $tablepre;
    $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablepre}{$table}'";
    $r = db_fetch_one($sql);
    return !empty($r);
}

?>