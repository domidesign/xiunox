<?php

/*
	XiunoPHP 4.0 只是定义了一些函数和全局变量，方便使用，并没有要求如何组织代码。
	采用静态语言编程风格，有利于 Zend 引擎的编译和 OPCache 缓存，支持 PHP7
	1. 禁止使用 eval(), 正则表达式 e 修饰符
	2. 尽量避免 autoload
	3. 尽量避免 $$var 多重变量
	4. 尽量避免 PHP 高级特性 __call __set __get 等魔术方法，不利于错误排查
	5. 尽量采用函数封装功能，通过前缀区分模块
*/

!defined('DEBUG') AND define('DEBUG', 1); // 1: 开发模式， 2: 线上调试：日志记录，0: 关闭
!defined('APP_PATH') AND define('APP_PATH', './');
!defined('XIUNOPHP_PATH') AND define('XIUNOPHP_PATH', dirname(__FILE__).'/');

function_exists('ini_set') AND ini_set('display_errors', DEBUG ? '1' : '0');
error_reporting(DEBUG ? E_ALL : 0);
// PHP 8.0+ 已移除 set_magic_quotes_runtime() 和 get_magic_quotes_gpc()，此处保留 $get_magic_quotes_gpc = false 以兼容下方 param_force 中对魔术引号的判断
$get_magic_quotes_gpc = false;
$starttime = microtime(1);
$time = time();

// 头部，判断是否运行在命令行下（幂等：api/v1/index.php 与 bootstrap.php 可能重复 include 本文件）
!defined('IN_CMD') AND define('IN_CMD', !empty($_SERVER['SHELL']) || empty($_SERVER['REMOTE_ADDR']));
if(IN_CMD) {
	!isset($_SERVER['REMOTE_ADDR']) AND $_SERVER['REMOTE_ADDR'] = '';
	!isset($_SERVER['REQUEST_URI']) AND $_SERVER['REQUEST_URI'] = '';
	!isset($_SERVER['REQUEST_METHOD']) AND $_SERVER['REQUEST_METHOD'] = 'GET';
} else {
	header("Content-type: text/html; charset=utf-8");
	//header("Cache-Control: max-age=0;"); // 手机返回的时候回导致刷新
	//header("Cache-Control: no-store;");
	//header("X-Powered-By: XiunoPHP 4.0");
}

// hook xiunophp_include_before.php


if(!interface_exists('DatabaseInterface')) {
	$iface = defined('APP_PATH') ? APP_PATH.'lib/DatabaseInterface.php' : dirname(__FILE__).'/../lib/DatabaseInterface.php';
	if(file_exists($iface)) include $iface;
}

class db_pdo_mysql implements DatabaseInterface {

	public $conf = array();
	public $rconf = array();
	public $wlink = NULL;
	public $rlink = NULL;
	public $link = NULL;
	public $errno = 0;
	public $errstr = '';
	public $sqls = array();
	public $tablepre = '';
	public $innodb_first = TRUE;

	public function __construct($conf) {
		$this->conf = $conf;
		$this->tablepre = $conf['master']['tablepre'];
	}

	public function connect(): bool {
		$this->wlink = $this->connect_master();
		$this->rlink = $this->connect_slave();
		return $this->wlink && $this->rlink;
	}

	public function connect_master() {
		if($this->wlink) return $this->wlink;
		$conf = $this->conf['master'];
		$this->wlink = $this->real_connect($conf['host'], $conf['user'], $conf['password'], $conf['name'], $conf['charset'], $conf['engine']);
		return $this->wlink;
	}

	public function connect_slave() {
		if($this->rlink) return $this->rlink;
		if(empty($this->conf['slaves'])) {
			if($this->wlink === NULL) $this->wlink = $this->connect_master();
			$this->rlink = $this->wlink;
			$this->rconf = $this->conf['master'];
		} else {
			$arr = array_rand($this->conf['slaves'], 1);
			$conf = $this->conf['slaves'][$arr[0]];
			$this->rconf = $conf;
			$this->rlink = $this->real_connect($conf['host'], $conf['user'], $conf['password'], $conf['name'], $conf['charset'], $conf['engine']);
		}
		return $this->rlink;
	}

	public function real_connect($host, $user, $password, $name, $charset = '', $engine = '') {
		if(strpos($host, ':') !== FALSE) {
			list($host, $port) = explode(':', $host);
		} else {
			$port = 3306;
		}
		try {
			$attr = array(
				PDO::ATTR_TIMEOUT => 5,
			);

			if(defined('PDO::ATTR_USE_BUFFERED_QUERY')) {
				$attr[PDO::ATTR_USE_BUFFERED_QUERY] = true;
			}
			$link = new PDO("mysql:host=$host;port=$port;dbname=$name", $user, $password, $attr);
		} catch (Exception $e) {
			$this->error($e->getCode(), '连接数据库服务器失败:'.$e->getMessage());
			return FALSE;
		}
		// 字符集从配置读取，默认 utf8mb4（避免 charset 为空时跳过 SET NAMES）
		if(!$charset) $charset = isset($this->conf['master']['charset']) ? $this->conf['master']['charset'] : 'utf8mb4';
		$charset AND $link->query("SET names $charset, sql_mode=''");
		return $link;
	}

	public function find(string $table, array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 10, string $key = '', array $col = []): array {
		$page = max(1, $page);
		list($condSql, $condParams) = db_cond_to_sqladd($cond);
		$orderby = db_orderby_to_sqladd($orderby);
		$offset = ($page - 1) * $pagesize;
		$cols = $col ? implode(',', $col) : '*';
		$sql = "SELECT $cols FROM {$this->tablepre}$table $condSql$orderby LIMIT $offset,$pagesize";
		$stmt = $this->prepare($sql, $condParams);
		if(!$stmt) return [];
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$arrlist = $stmt->fetchAll();
		$stmt->closeCursor();
		$key AND $arrlist = arrlist_change_key($arrlist, $key);
		return is_array($arrlist) ? $arrlist : [];
	}

	public function find_one($table, $cond = array(), $orderby = array(), $col = array()): ?array {
		list($condSql, $condParams) = db_cond_to_sqladd($cond);
		$orderby = db_orderby_to_sqladd($orderby);
		$cols = $col ? implode(',', $col) : '*';
		$sql = "SELECT $cols FROM {$this->tablepre}$table $condSql$orderby LIMIT 1";
		return $this->prepare_one($sql, $condParams);
	}

	public function find_group(string $table, array $cond = [], array $groupby = [], array $having = [], array $orderby = [], int $page = 1, int $pagesize = 10, string $key = '', array $col = []): array {
		$page = max(1, $page);
		list($condSql, $condParams) = db_cond_to_sqladd($cond);
		$orderbySql = db_orderby_to_sqladd($orderby);
		$offset = ($page - 1) * $pagesize;
		$cols = $col ? implode(',', $col) : '*';

		$groupbySql = '';
		if (!empty($groupby)) {
			$groupbySql = ' GROUP BY ' . implode(',', $groupby);
		}

		$havingSql = '';
		$havingParams = array();
		if (!empty($having)) {
			list($havingSql, $havingParams) = db_cond_to_sqladd($having);

			$havingSql = str_replace(' WHERE ', ' HAVING ', $havingSql);
		}

		$sql = "SELECT $cols FROM {$this->tablepre}$table $condSql$groupbySql$havingSql$orderbySql LIMIT $offset,$pagesize";
		$params = array_merge($condParams, $havingParams);
		$stmt = $this->prepare($sql, $params);
		if(!$stmt) return [];
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$arrlist = $stmt->fetchAll();
		$stmt->closeCursor();
		$key AND $arrlist = arrlist_change_key($arrlist, $key);
		return is_array($arrlist) ? $arrlist : [];
	}

	public function find_one_group(string $table, array $cond = [], array $groupby = [], array $having = [], array $orderby = [], array $col = []): ?array {
		list($condSql, $condParams) = db_cond_to_sqladd($cond);
		$orderbySql = db_orderby_to_sqladd($orderby);
		$cols = $col ? implode(',', $col) : '*';

		$groupbySql = '';
		if (!empty($groupby)) {
			$groupbySql = ' GROUP BY ' . implode(',', $groupby);
		}

		$havingSql = '';
		$havingParams = array();
		if (!empty($having)) {
			list($havingSql, $havingParams) = db_cond_to_sqladd($having);
			$havingSql = str_replace(' WHERE ', ' HAVING ', $havingSql);
		}

		$sql = "SELECT $cols FROM {$this->tablepre}$table $condSql$groupbySql$havingSql$orderbySql LIMIT 1";
		$params = array_merge($condParams, $havingParams);
		return $this->prepare_one($sql, $params);
	}

	public function findOne(string $table, array $cond = [], array $orderby = [], array $col = []): ?array {
		return $this->find_one($table, $cond, $orderby, $col);
	}

	public function sql_find_one($sql): ?array {
		$query = $this->query($sql);
		if(!$query) return NULL;
		$query->setFetchMode(PDO::FETCH_ASSOC);
		$r = $query->fetch();
		$query->closeCursor();
		return $r === FALSE ? NULL : $r;
	}

	public function sql_find($sql, $key = NULL): array {
		$query = $this->query($sql);
		if(!$query) return [];
		$query->setFetchMode(PDO::FETCH_ASSOC);
		$arrlist = $query->fetchAll();
		$query->closeCursor();
		$key AND $arrlist = arrlist_change_key($arrlist, $key);
		return is_array($arrlist) ? $arrlist : [];
	}

	public function exec(string $sql): int {
		$this->errno = 0;
		$this->errstr = '';
		if(!$this->wlink && !$this->connect_master()) return 0;

		$this->link = NULL;
		$link = $this->link = $this->wlink;
		$n = 0;
		try {
			if(strtoupper(substr($sql, 0, 12)) == 'CREATE TABLE') {
				$fulltext = strpos($sql, 'FULLTEXT(') !== FALSE;
				$highversion = version_compare($this->version(), '5.6') >= 0;
				if(!$fulltext || ($fulltext && $highversion)) {
					$conf = $this->conf['master'];
					if(strtolower($conf['engine']) != 'myisam') {
						$this->innodb_first AND $this->is_support_innodb() AND $sql = str_ireplace('MyISAM', 'InnoDB', $sql);
					}
				}
			}
			$t1 = microtime(1);
			$n = $link->exec($sql);
			$t2 = microtime(1);
		} catch (Exception $e) {
			$this->error($e->getCode(), $e->getMessage());
			return 0;
		}
		if(count($this->sqls) < 1000) $this->sqls[] = '['.number_format($t2 - $t1, 4).']'.$sql;

		if($n !== FALSE) {
			$pre = strtoupper(substr(trim($sql), 0, 7));
			if($pre == 'INSERT ' || $pre == 'REPLACE') {
				return intval($this->last_insert_id());
			}
			return intval($n);
		} else {
			$this->error();
			return 0;
		}
	}

	public function insert(string $table, array $data): int {
		list($sqladd, $params) = db_array_to_insert_sqladd($data);
		if(!$sqladd) return 0;
		$sql = "INSERT INTO {$this->tablepre}$table $sqladd";
		$stmt = $this->prepare($sql, $params);
		if(!$stmt) return 0;
		return intval($this->last_insert_id());
	}

	public function update(string $table, array $cond, array $data): int {
		list($condadd, $condParams) = db_cond_to_sqladd($cond);
		list($sqladd, $updateParams) = db_array_to_update_sqladd($data);
		if(!$sqladd) return 0;
		$sql = "UPDATE {$this->tablepre}$table SET $sqladd $condadd";
		$params = array_merge($updateParams, $condParams);
		$stmt = $this->prepare($sql, $params);
		if(!$stmt) return 0;
		return intval($stmt->rowCount());
	}

	public function delete(string $table, array $cond): int {
		list($condadd, $condParams) = db_cond_to_sqladd($cond);
		$sql = "DELETE FROM {$this->tablepre}$table $condadd";
		$stmt = $this->prepare($sql, $condParams);
		if(!$stmt) return 0;
		return intval($stmt->rowCount());
	}

	public function count(string $table, array $cond = []): int {
		$this->connect_slave();
		if(empty($cond) && $this->rconf['engine'] == 'innodb') {
			$dbname = $this->rconf['name'];
			// 联表查 information_schema 系统表，db_find 不支持，保留直接 sql_find_one
			$sql = "SELECT TABLE_ROWS as num FROM information_schema.tables WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table'";
			$arr = $this->sql_find_one($sql);
		} else {
			list($condSql, $condParams) = db_cond_to_sqladd($cond);
			$sql = "SELECT COUNT(*) AS num FROM `$table` $condSql";
			$arr = $this->prepare_one($sql, $condParams);
		}
		return !empty($arr) ? intval($arr['num']) : 0;
	}

	public function maxid(string $table, string $field, array $cond = []): int {
		list($sqladd, $condParams) = db_cond_to_sqladd($cond);
		$sql = "SELECT MAX($field) AS maxid FROM `$table` $sqladd";
		$arr = $this->prepare_one($sql, $condParams);
		return !empty($arr) ? intval($arr['maxid']) : 0;
	}

	public function lastInsertId(): int {
		return intval($this->last_insert_id());
	}

	public function quote(string $value): string {
		// 无连接时返回原始值（此场景查询必然失败，无需转义）
		if(!$this->rlink && !$this->connect_slave()) return (string)$value;
		return substr($this->rlink->quote($value), 1, -1);
	}

	public function sqlFindOne(string $sql): ?array {
		return $this->sql_find_one($sql);
	}

	public function sqlFind(string $sql, ?string $key = null): array {
		return $this->sql_find($sql, $key);
	}

	public function table(string $table): string {
		return $this->tablepre . $table;
	}

	public function truncate(string $table): int {
		return $this->exec("TRUNCATE {$this->tablepre}$table");
	}

	public function query($sql) {
		$this->errno = 0;
		$this->errstr = '';
		if(!$this->rlink && !$this->connect_slave()) return FALSE;

		$this->link = NULL;
		$link = $this->link = $this->rlink;
		try {
			$t1 = microtime(1);
			$query = $link->query($sql);
			$t2 = microtime(1);
		} catch (Exception $e) {
			$this->error($e->getCode(), $e->getMessage());
			return FALSE;
		}
		if($query === FALSE) $this->error();
		if(count($this->sqls) < 1000) $this->sqls[] = number_format($t2 - $t1, 4).' '.$sql;
		return $query;
	}

	/**
	 * PDO 预处理执行（写操作/读操作通用）
	 * 自动选择 wlink（INSERT/UPDATE/DELETE/REPLACE）或 rlink（SELECT）
	 * @param string $sql 带 ? 占位符的 SQL
	 * @param array $params 绑定参数
	 * @return PDOStatement|FALSE
	 */
	public function prepare($sql, $params = array()) {
		$this->errno = 0;
		$this->errstr = '';
		$pre = strtoupper(substr(ltrim($sql), 0, 7));
		$isWrite = ($pre == 'INSERT ' || $pre == 'UPDATE ' || $pre == 'DELETE ' || $pre == 'REPLACE' || strtoupper(substr(ltrim($sql), 0, 6)) == 'CREATE' || strtoupper(substr(ltrim($sql), 0, 4)) == 'DROP' || strtoupper(substr(ltrim($sql), 0, 8)) == 'TRUNCATE');
		$this->link = NULL;
		if($isWrite) {
			if(!$this->wlink && !$this->connect_master()) return FALSE;
			$link = $this->link = $this->wlink;
		} else {
			if(!$this->rlink && !$this->connect_slave()) return FALSE;
			$link = $this->link = $this->rlink;
		}
		try {
			$t1 = microtime(1);
			$stmt = $link->prepare($sql);
			if($stmt === FALSE) {
				$this->error();
				return FALSE;
			}
			$i = 1;
			foreach($params as $v) {
				if(is_int($v)) {
					$stmt->bindValue($i, $v, PDO::PARAM_INT);
				} elseif(is_bool($v)) {
					$stmt->bindValue($i, $v, PDO::PARAM_BOOL);
				} elseif(is_null($v)) {
					$stmt->bindValue($i, $v, PDO::PARAM_NULL);
				} else {
					$stmt->bindValue($i, (string)$v, PDO::PARAM_STR);
				}
				$i++;
			}
			$stmt->execute();
			$t2 = microtime(1);
		} catch (Exception $e) {
			$this->error($e->getCode(), $e->getMessage());
			return FALSE;
		}
		if(count($this->sqls) < 1000) $this->sqls[] = '['.number_format($t2 - $t1, 4).']'.$sql.' ['.xn_json_encode($params).']';
		return $stmt;
	}

	/**
	 * PDO 预处理查询单条
	 * @param string $sql 带 ? 占位符的 SQL
	 * @param array $params 绑定参数
	 * @return array|null
	 */
	public function prepare_one($sql, $params = array()) {
		$stmt = $this->prepare($sql, $params);
		if(!$stmt) return NULL;
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$r = $stmt->fetch();
		$stmt->closeCursor();
		return $r === FALSE ? NULL : $r;
	}

	public function last_insert_id(): int {
		return intval($this->wlink->lastinsertid());
	}

	public function version(): string {
		$r = $this->sql_find_one("SELECT VERSION() AS v");
		return is_array($r) && isset($r['v']) ? $r['v'] : '0.0.0';
	}

	public function error($errno = 0, $errstr = '') {
		$error = $this->link ? $this->link->errorInfo() : array(0, $errno, $errstr);
		$this->errno = $errno ? $errno : (isset($error[1]) ? $error[1] : 0);
		$this->errstr = $errstr ? $errstr : (isset($error[2]) ? $error[2] : '');
	}

	public function is_support_innodb(): bool {
		$arrlist = $this->sql_find('SHOW ENGINES');
		$arrlist2 = arrlist_key_values($arrlist, 'Engine', 'Support');
		return isset($arrlist2['InnoDB']) AND $arrlist2['InnoDB'] == 'YES';
	}

	public function close(): bool {
		$this->wlink = NULL;
		$this->rlink = NULL;
		return TRUE;
	}

	public function __destruct() {
		if($this->wlink) $this->wlink = NULL;
		if($this->rlink) $this->rlink = NULL;
	}
}

class db_pdo_sqlite {
	public $conf = array();
	public $wlink = NULL;
	public $rlink = NULL;
	public $link = NULL;
	public $errno = 0;
	public $errstr = '';
	public $tablepre = '';

	public function __construct($conf) {
		$this->conf = $conf;
		$this->tablepre = $conf['master']['tablepre'];
	}

	public function connect() {
		$this->wlink = $this->connect_master();
		$this->rlink = $this->connect_slave();
		return $this->wlink && $this->rlink;
	}

	public function connect_master() {
		if($this->wlink) return $this->wlink;
		$conf = $this->conf['master'];
		$this->wlink = $this->real_connect($conf['host'], $conf['user'], $conf['password'], $conf['name'], $conf['charset'], $conf['engine']);
		return $this->wlink;
	}

	public function connect_slave() {
		if($this->rlink) return $this->rlink;
		if(empty($this->conf['slaves'])) {
			if(!$this->wlink) $this->wlink = $this->connect_master();
			$this->rlink = $this->wlink;
		} else {
			$n = array_rand($this->conf['slaves']);
			$conf = $this->conf['slaves'][$n];
			$this->rlink = $this->real_connect($conf['host'], $conf['user'], $conf['password'], $conf['name'], $conf['charset'], $conf['engine']);
		}
		return $this->rlink;
	}

	public function real_connect($host, $user, $password, $name, $charset = '', $engine = '') {
		$sqlitedb = "sqlite:$host";
		try {
			$attr = array(
				PDO::ATTR_TIMEOUT => 5,
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
			);
			$link = new PDO($sqlitedb, $attr);

		} catch (Exception $e) {
			$this->error($e->getCode(), '连接数据库服务器失败:'.$e->getMessage());
			return FALSE;
	        }

		return $link;

	}

	public function sql_find_one($sql) {
		$query = $this->query($sql);
		if(!$query) return $query;
		$query->setFetchMode(PDO::FETCH_ASSOC);
		return $query->fetch();
	}

	public function sql_find($sql, $key = NULL) {
		$query = $this->query($sql);
		if(!$query) return $query;
		$query->setFetchMode(PDO::FETCH_ASSOC);
		$arrlist = $query->fetchAll();
		$key AND $arrlist = arrlist_change_key($arrlist, $key);
		return $arrlist;
	}

	public function find($table, $cond = array(), $orderby = array(), $page = 1, $pagesize = 10, $key = '', $col = array()) {
		$page = max(1, $page);
		list($condSql, $condParams) = db_cond_to_sqladd($cond);
		$orderby = db_orderby_to_sqladd($orderby);
		$offset = ($page - 1) * $pagesize;
		$cols = $col ? implode(',', $col) : '*';
		$sql = "SELECT $cols FROM {$this->tablepre}$table $condSql$orderby LIMIT $offset,$pagesize";
		$stmt = $this->prepare($sql, $condParams);
		if(!$stmt) return FALSE;
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$arrlist = $stmt->fetchAll();
		$stmt->closeCursor();
		$key AND $arrlist = arrlist_change_key($arrlist, $key);
		return $arrlist;
	}

	public function find_one($table, $cond = array(), $orderby = array(), $col = array()) {
		list($condSql, $condParams) = db_cond_to_sqladd($cond);
		$orderby = db_orderby_to_sqladd($orderby);
		$cols = $col ? implode(',', $col) : '*';
		$sql = "SELECT $cols FROM {$this->tablepre}$table $condSql$orderby LIMIT 1";
		return $this->prepare_one($sql, $condParams);
	}

	/**
	 * PDO 预处理执行
	 */
	public function prepare($sql, $params = array()) {
		$this->errno = 0;
		$this->errstr = '';
		$pre = strtoupper(substr(ltrim($sql), 0, 7));
		$isWrite = ($pre == 'INSERT ' || $pre == 'UPDATE ' || $pre == 'DELETE ' || $pre == 'REPLACE' || strtoupper(substr(ltrim($sql), 0, 6)) == 'CREATE' || strtoupper(substr(ltrim($sql), 0, 4)) == 'DROP' || strtoupper(substr(ltrim($sql), 0, 8)) == 'TRUNCATE');
		$this->link = NULL;
		if($isWrite) {
			if(!$this->wlink && !$this->connect_master()) return FALSE;
			$link = $this->link = $this->wlink;
		} else {
			if(!$this->rlink && !$this->connect_slave()) return FALSE;
			$link = $this->link = $this->rlink;
		}
		try {
			$stmt = $link->prepare($sql);
			if($stmt === FALSE) {
				$this->error();
				return FALSE;
			}
			$i = 1;
			foreach($params as $v) {
				if(is_int($v)) {
					$stmt->bindValue($i, $v, PDO::PARAM_INT);
				} elseif(is_bool($v)) {
					$stmt->bindValue($i, $v, PDO::PARAM_BOOL);
				} elseif(is_null($v)) {
					$stmt->bindValue($i, $v, PDO::PARAM_NULL);
				} else {
					$stmt->bindValue($i, (string)$v, PDO::PARAM_STR);
				}
				$i++;
			}
			$stmt->execute();
		} catch (Exception $e) {
			$this->error($e->getCode(), $e->getMessage());
			return FALSE;
		}
		if(count($this->sqls) < 1000) $this->sqls[] = $sql;
		return $stmt;
	}

	/**
	 * PDO 预处理查询单条
	 */
	public function prepare_one($sql, $params = array()) {
		$stmt = $this->prepare($sql, $params);
		if(!$stmt) return FALSE;
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$r = $stmt->fetch();
		$stmt->closeCursor();
		return $r === FALSE ? NULL : $r;
	}

	public function query($sql) {
		if(!$this->rlink && !$this->connect_slave()) return FALSE;
		$link = $this->link = $this->rlink;
		$query = $link->query($sql);
		if($query === FALSE) $this->error();

		if(count($this->sqls) < 1000) $this->sqls[] = $sql;

		return $query;
	}

	public function exec($sql) {
		if(!$this->wlink && !$this->connect_master()) return FALSE;
		$link = $this->link = $this->wlink;
		$n = $link->exec($sql);
		if(count($this->sqls) < 1000) $this->sqls[] = $sql;
		if($n !== FALSE) {
			$pre = strtoupper(substr(trim($sql), 0, 7));
			if($pre == 'INSERT ' || $pre == 'REPLACE') {
				return $this->last_insert_id();
			}
		} else {
			$this->error();
		}

		return $n;
	}

	public function count($table, $cond = array()) {
		list($condSql, $condParams) = db_cond_to_sqladd($cond);
		$sql = "SELECT COUNT(*) AS num FROM `$table` $condSql";
		$arr = $this->prepare_one($sql, $condParams);
		return !empty($arr) ? intval($arr['num']) : $arr;
	}

	public function maxid($table, $field, $cond = array()) {
		list($sqladd, $condParams) = db_cond_to_sqladd($cond);
		$sql = "SELECT MAX($field) AS maxid FROM `$table` $sqladd";
		$arr = $this->prepare_one($sql, $condParams);
		return !empty($arr) ? intval($arr['maxid']) : $arr;
	}

	public function truncate($table) {
		return $this->exec("TRUNCATE $table");
	}

	public function last_insert_id() {
		return $this->wlink->lastinsertid();
	}

	public function version() {
		$r = $this->sql_find_one("SELECT VERSION() AS v");
		return $r['v'];
	}

	public function error($errno = 0, $errstr = '') {
		$error = $this->link ? $this->link->errorInfo() : array(0, 0, '');
		$this->errno = $errno ? $errno : (isset($error[1]) ? $error[1] : 0);
		$this->errstr = $errstr ? $errstr : (isset($error[2]) ? $error[2] : '');
		DEBUG AND trigger_error('Database Error:'.$this->errstr);
	}

	public function __destruct() {
		if($this->wlink) $this->wlink = NULL;
		if($this->rlink) $this->rlink = NULL;
	}
}

class cache_file {

	public $conf = array();
	public $cachepre = '';
	public $errno = 0;
	public $errstr = '';
	// 缓存目录
	public $cache_dir = '';

	public function __construct($conf = array()) {
		$this->conf = $conf;
		$this->cachepre = isset($conf['cachepre']) ? $conf['cachepre'] : 'pre_';
		$this->cache_dir = isset($conf['cache_dir']) ? $conf['cache_dir'] : APP_PATH . 'tmp/cache/';
	}

	public function connect() {
		// 确保缓存目录存在
		if(!is_dir($this->cache_dir)) {
			if(!mkdir($this->cache_dir, 0755, TRUE)) {
				return $this->error(-1, '创建缓存目录失败：' . $this->cache_dir);
			}
		}
		return TRUE;
	}

	/**
	 * 根据键名计算缓存文件路径
	 * 使用 md5 前两字符作为一级目录，接下来两字符作为二级目录
	 */
	public function filepath($k) {
		$hash = md5($k);
		$dir1 = substr($hash, 0, 2);
		$dir2 = substr($hash, 2, 2);
		$path = $this->cache_dir . $dir1 . '/' . $dir2 . '/';
		return $path . $k . '.cache';
	}

	/**
	 * 确保文件所在目录存在
	 */
	public function ensure_dir($path) {
		$dir = dirname($path);
		if(!is_dir($dir)) {
			if(!mkdir($dir, 0755, TRUE)) {
				return $this->error(-1, '创建缓存子目录失败：' . $dir);
			}
		}
		return TRUE;
	}

	public function set($k, $v, $life = 0) {
		if(!$this->connect()) return FALSE;

		// 拼接带前缀的键名
		$key = $this->cachepre . $k;
		$filepath = $this->filepath($key);

		if(!$this->ensure_dir($filepath)) return FALSE;

		// 计算过期时间戳，0 表示永不过期
		$expiry = $life > 0 ? (time() + $life) : 0;

		// 格式：EXPIRY_TIMESTAMP\nJSON_DATA
		$data = $expiry . "\n" . xn_json_encode($v);

		$r = file_put_contents($filepath, $data, LOCK_EX);
		if($r === FALSE) {
			return $this->error(-1, '写入缓存文件失败：' . $filepath);
		}
		@chmod($filepath, 0644);
		return TRUE;
	}

	public function get($k) {
		if(!$this->connect()) return FALSE;

		$key = $this->cachepre . $k;
		$filepath = $this->filepath($key);

		if(!is_file($filepath)) return NULL;

		$content = file_get_contents($filepath);
		if($content === FALSE) {
			return $this->error(-1, '读取缓存文件失败：' . $filepath);
		}

		// 解析过期时间戳和数据
		$pos = strpos($content, "\n");
		if($pos === FALSE) return NULL;

		$expiry = (int)substr($content, 0, $pos);
		$json_data = substr($content, $pos + 1);

		// 检查是否过期（0 表示永不过期）
		if($expiry > 0 && time() > $expiry) {
			// 已过期，删除文件并返回 NULL
			@unlink($filepath);
			return NULL;
		}

		return xn_json_decode($json_data);
	}

	public function delete($k) {
		if(!$this->connect()) return FALSE;

		$key = $this->cachepre . $k;
		$filepath = $this->filepath($key);

		if(!is_file($filepath)) return TRUE;

		$r = @unlink($filepath);
		if(!$r) {
			return $this->error(-1, '删除缓存文件失败：' . $filepath);
		}
		return TRUE;
	}

	public function truncate() {
		if(!$this->connect()) return FALSE;

		// 递归删除缓存目录下所有文件和子目录
		$this->rmdir_recursive($this->cache_dir);

		// 重新创建缓存目录
		if(!mkdir($this->cache_dir, 0755, TRUE)) {
			return $this->error(-1, '重建缓存目录失败：' . $this->cache_dir);
		}
		return TRUE;
	}

	/**
	 * 按前缀删除缓存键（生产安全）
	 * 遍历缓存目录，删除文件名匹配指定前缀的所有缓存文件，不依赖注册表
	 */
	public function deleteByPrefix($prefix) {
		if(!$this->connect()) return 0;
		$fullPrefix = $this->cachepre . $prefix;
		$deleted = 0;
		$this->rdeleteByPrefix($this->cache_dir, $fullPrefix, $deleted);
		return $deleted;
	}

	private function rdeleteByPrefix($dir, $prefix, &$deleted) {
		if(!is_dir($dir)) return;
		$entries = scandir($dir);
		foreach($entries as $entry) {
			if($entry == '.' || $entry == '..') continue;
			$path = $dir . $entry;
			if(is_dir($path)) {
				$this->rdeleteByPrefix($path . '/', $prefix, $deleted);
				@rmdir($path);
			} else {
				if(strpos($entry, $prefix) === 0) {
					if(@unlink($path)) $deleted++;
				}
			}
		}
	}

	/**
	 * 递归删除目录及其内容
	 */
	public function rmdir_recursive($dir) {
		if(!is_dir($dir)) return;
		$entries = scandir($dir);
		foreach($entries as $entry) {
			if($entry == '.' || $entry == '..') continue;
			$path = $dir . $entry;
			if(is_dir($path)) {
				$this->rmdir_recursive($path . '/');
				@rmdir($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}

	public function error($errno = 0, $errstr = '') {
		$this->errno = $errno;
		$this->errstr = $errstr;
		DEBUG AND trigger_error('Cache Error:' . $this->errstr);
	}

	public function __destruct() {

	}
}

class cache_memcached {

	public $conf = array();
	public $link = NULL;
	public $cachepre = '';
	public $errno = 0;
	public $errstr = '';
	public $ismemcache = FALSE;

        public function __construct($conf = array()) {
                if(!extension_loaded('Memcache') && !extension_loaded('Memcached') ) {
                        return $this->error(1, ' Memcached 扩展没有加载，请检查您的 PHP 版本');
                }
                $this->conf = $conf;
		$this->cachepre = isset($conf['cachepre']) ? $conf['cachepre'] : 'pre_';
        }
        public function connect() {
                $conf = $this->conf;
                if($this->link) return $this->link;
                try {
                        if(extension_loaded('Memcache')) {
                                $this->ismemcache = TRUE;
                                $memcache = new Memcache;

                                set_error_handler(function($errno) { return $errno == E_DEPRECATED; });
                                try {
                                        $r = @$memcache->connect($conf['host'], $conf['port'], 2);
                                } finally {
                                        restore_error_handler();
                                }
                        } elseif(extension_loaded('Memcached')) {
                                $this->ismemcache = FALSE;
                                $memcache = new Memcached;
                                $memcache->setOption(Memcached::OPT_CONNECT_TIMEOUT, 2000);
                                $r = $memcache->addserver($conf['host'], $conf['port']);
                        } else {
                                $this->link = FALSE;
                                return $this->error(-1, 'Memcache 扩展不存在。');
                        }

                        if(!$r) {
                                $this->link = FALSE;
                                return $this->error(-1, '连接 Memcached 服务器失败。');
                        }
                        $this->link = $memcache;
                        return $this->link;
                } catch(\Throwable $e) {
                        $this->link = FALSE;
                        return $this->error(-1, '连接 Memcached 服务器异常：' . $e->getMessage());
                }
        }

        public function isConnected() {
                return $this->link !== FALSE && $this->link !== NULL;
        }
        public function set($k, $v, $life = 0) {
                if(!$this->link && !$this->connect()) return FALSE;
                if($this->ismemcache) {
                	$r = $this->link->set($k, $v, 0, $life);
                } else {
                	$r = $this->link->set($k, $v, $life);
                }
                return $r;
        }
        public function get($k) {
                if(!$this->link && !$this->connect()) return FALSE;
                $r = $this->link->get($k);
                return $r === FALSE ? NULL : $r;
        }
        public function delete($k) {
                if(!$this->link && !$this->connect()) return FALSE;
                return $this->link->delete($k);
        }
        public function truncate() {
                if(!$this->link && !$this->connect()) return FALSE;
                return $this->link->flush();
        }
       	public function error($errno = 0, $errstr = '') {
		$this->errno = $errno;
		$this->errstr = $errstr;
		DEBUG AND trigger_error('Cache Error:'.$this->errstr);
	}
        public function __destruct() {

        }
}

class cache_mysql {

	public $conf = array();
	public $db = NULL;
	public $link = NULL;
	public $table = 'cache';
	public $cachepre = '';
	public $errno = 0;
	public $errstr = '';

        public function __construct($dbconf = array()) {

                if(is_object($dbconf['db'])) {
                        $this->db = $dbconf['db'];
                } else {
                        $this->conf = $dbconf;
                        $this->db = db_new($dbconf);
                }
		$this->cachepre = isset($dbconf['cachepre']) ? $dbconf['cachepre'] : 'pre_';
        }
        public function connect() {
        	return db_connect($this->db);
        }
        public function set($k, $v, $life = 0) {
                $time = time();
                $expiry = $life ? $time + $life : 0;
                $arr= array(
                	'k'=>$k,
                	'v'=>xn_json_encode($v),
                	'expiry'=>$expiry,
                );
                $r = db_replace($this->table, $arr, $this->db);
                if($r === FALSE) {
                	$this->errno = $this->db->errno;
                	$this->errstr = $this->db->errstr;
                	return FALSE;
                }
                return $r !== FALSE;
        }
        public function get($k) {
                $time = time();
                $arr = db_find_one($this->table, array('k'=>$k), array(), array(), $this->db);

                if($arr === FALSE) {
                	$this->errno = $this->db->errno;
                	$this->errstr = $this->db->errstr;
                	return FALSE;
                }
                if(!$arr) return NULL;
                if($arr['expiry'] && $time > $arr['expiry']) {
                	db_delete($this->table, array('k'=>$k), $this->db);
                        return NULL;
                }
                return xn_json_decode($arr['v'], 1);
        }
        public function delete($k) {
        	$r = db_delete($this->table, array('k'=>$k), $this->db);
        	if($r === FALSE) {
                	$this->errno = $this->db->errno;
                	$this->errstr = $this->db->errstr;
                	return FALSE;
                }
                return empty($r) ? FALSE : TRUE;
        }
        public function truncate() {
        	$r = db_truncate($this->table, $this->db);
        	if($r === FALSE) {
                	$this->errno = $this->db->errno;
                	$this->errstr = $this->db->errstr;
                	return FALSE;
                }
                return TRUE;
        }
        /**
         * 按前缀删除缓存键（生产安全）
         * 用 SQL LIKE 匹配删除所有以指定前缀开头的键，不依赖注册表
         */
        public function deleteByPrefix($prefix) {
                if(!$this->db) return 0;
                $fullPrefix = $this->cachepre . $prefix;
                $table = $this->db->tablepre . $this->table;
                $sql = "DELETE FROM `{$table}` WHERE k LIKE '" . addslashes($fullPrefix) . "%'";
                $n = db_exec($sql, $this->db);
                return $n === FALSE ? 0 : intval($n);
        }
        public function error($errno, $errstr) {
        	$this->errno = $errno;
        	$this->errstr = $errstr;
        }
        public function __destruct() {

        }
}

class cache_redis {

	public $conf = array();
	public $link = NULL;
	public $cachepre = '';
	public $errno = 0;
	public $errstr = '';

        public function __construct($conf = array()) {
                if(!extension_loaded('Redis')) {
                        return $this->error(-1, ' Redis 扩展没有加载');
                }
                $this->conf = $conf;
		$this->cachepre = isset($conf['cachepre']) ? $conf['cachepre'] : 'pre_';
        }
        public function connect() {
                if($this->link) return $this->link;
                try {
                        $redis = new Redis;

                        $r = @$redis->connect($this->conf['host'], $this->conf['port'], 2);
                        if(!$r) {
                                $this->link = FALSE;
                                return $this->error(-1, '连接 Redis 服务器失败。');
                        }
                        if(!empty($this->conf['password'])) {
                                $auth = $redis->auth($this->conf['password']);
                                if(!$auth) {
                                        $this->link = FALSE;
                                        return $this->error(-1, 'Redis 认证失败。');
                                }
                        }
                        if(isset($this->conf['database']) && intval($this->conf['database']) > 0) {
                                $redis->select(intval($this->conf['database']));
                        }
                        $this->link = $redis;
                        return $this->link;
                } catch(\Throwable $e) {
                        $this->link = FALSE;
                        return $this->error(-1, '连接 Redis 服务器异常：' . $e->getMessage());
                }
        }

        public function isConnected() {
                return $this->link !== FALSE && $this->link !== NULL;
        }
        public function set($k, $v, $life = 0) {
                if(!$this->link && !$this->connect()) return FALSE;
                $v = xn_json_encode($v);
                $r = $this->link->set($k, $v);
                $life AND $r AND $this->link->expire($k, $life);
                return $r;
        }
        public function get($k) {
                if(!$this->link && !$this->connect()) return FALSE;
                $r = $this->link->get($k);
                return $r === FALSE ? NULL : xn_json_decode($r);
        }
        public function delete($k) {
                if(!$this->link && !$this->connect()) return FALSE;
                return $this->link->del($k) ? TRUE : FALSE;
        }
        public function truncate() {
                if(!$this->link && !$this->connect()) return FALSE;
                return $this->link->flushdb();
        }
        public function error($errno = 0, $errstr = '') {
		$this->errno = $errno;
		$this->errstr = $errstr;
		DEBUG AND trigger_error('Cache Error:'.$this->errstr);
	}
        public function __destruct() {

        }
}

function db_new($dbconf) {
	global $errno, $errstr;

	if($dbconf && isset($dbconf['type'])) {

		switch ($dbconf['type']) {
			case 'mysql':
			xn_log('db type mysql deprecated, fallback to pdo_mysql', 'error');
			$db = new db_pdo_mysql($dbconf['pdo_mysql']);
			break;
			case 'pdo_mysql':  $db = new db_pdo_mysql($dbconf['pdo_mysql']);	break;
			case 'pdo_sqlite': $db = new db_pdo_sqlite($dbconf['pdo_sqlite']);	break;
			case 'pdo_mongodb': $db = new db_pdo_mongodb($dbconf['pdo_mongodb']);	break;
			default: return xn_error(-1, 'Not suppported db type:'.$dbconf['type']);
		}
		if(!$db || ($db && $db->errstr)) {
			$errno = -1;
			$errstr = $db->errstr;
			return FALSE;
		}
		return $db;
	}
	return NULL;
}

function db_connect($d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;

	$r = $d->connect();

	db_errno_errstr($r, $d);

	return $r;
}

function db_close($d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	$r = $d->close();

	db_errno_errstr($r, $d);

	return $r;
}

// 保留 db_sql_find_one：复杂 SQL（JOIN/子查询）入口，调用方需自行参数化
function db_sql_find_one($sql, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	$arr = $d->sql_find_one($sql);

	db_errno_errstr($arr, $d, $sql);

	return $arr;
}

// 保留 db_sql_find：复杂 SQL（JOIN/子查询）入口，调用方需自行参数化
function db_sql_find($sql, $key = NULL, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	$arr = $d->sql_find($sql, $key);

	db_errno_errstr($arr, $d, $sql);

	return $arr;
}

// 保留 db_exec：复杂 SQL（DDL/CREATE TABLE 等）入口，调用方需自行参数化
function db_exec($sql, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	DEBUG AND xn_log($sql, 'db_exec');

	$n = $d->exec($sql);

	// exec() 返回 int，异常时返回 0 而非 FALSE；需检查 errno 判断是否真正出错
	if($d->errno) {
		db_errno_errstr(FALSE, $d, $sql);
		return FALSE;
	}

	db_errno_errstr($n, $d, $sql);

	return $n;
}

/**
 * 预处理执行 SQL（写操作），返回影响行数或插入 ID
 */
function db_exec_prepared($sql, $params = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	DEBUG AND xn_log($sql.' ['.xn_json_encode($params).']', 'db_exec');

	$stmt = $d->prepare($sql, $params);
	if(!$stmt) {
		db_errno_errstr(FALSE, $d, $sql);
		return FALSE;
	}

	// 判断是否 INSERT/REPLACE，返回 last_insert_id；否则返回 rowCount
	$pre = strtoupper(substr(ltrim($sql), 0, 7));
	if($pre == 'INSERT ' || $pre == 'REPLACE') {
		$n = intval($d->last_insert_id());
	} else {
		$n = intval($stmt->rowCount());
	}

	db_errno_errstr($n, $d, $sql);
	$stmt->closeCursor();
	return $n;
}

/**
 * 预处理查询多行
 */
function db_sql_find_prepared($sql, $params = array(), $key = NULL, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return array();
	$stmt = $d->prepare($sql, $params);
	if(!$stmt) {
		db_errno_errstr(FALSE, $d, $sql);
		return array();
	}
	$stmt->setFetchMode(PDO::FETCH_ASSOC);
	$arrlist = $stmt->fetchAll();
	$stmt->closeCursor();
	$key AND $arrlist = arrlist_change_key($arrlist, $key);
	db_errno_errstr($arrlist, $d, $sql);
	return is_array($arrlist) ? $arrlist : array();
}

/**
 * 预处理查询单行
 */
function db_sql_find_one_prepared($sql, $params = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	$arr = $d->prepare_one($sql, $params);
	db_errno_errstr($arr, $d, $sql);
	return $arr;
}

function db_count($table, $cond = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	if(!is_array($cond)) $cond = array();

	$r = $d->count($d->tablepre.$table, $cond);

	db_errno_errstr($r, $d);

	return $r;
}

function db_maxid($table, $field, $cond = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	if(!is_array($cond)) $cond = array();

	$r = $d->maxid($d->tablepre.$table, $field, $cond);

	db_errno_errstr($r, $d);

	return $r;
}

function db_create($table, $arr, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	return db_insert($table, $arr);
}

function db_insert($table, $arr, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	list($sqladd, $params) = db_array_to_insert_sqladd($arr);
	if(!$sqladd) return FALSE;
	return db_exec_prepared("INSERT INTO {$d->tablepre}$table $sqladd", $params, $d);
}

function db_replace($table, $arr, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	list($sqladd, $params) = db_array_to_insert_sqladd($arr);
	if(!$sqladd) return FALSE;
	return db_exec_prepared("REPLACE INTO {$d->tablepre}$table $sqladd", $params, $d);
}

function db_update($table, $cond, $update, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	list($condadd, $condParams) = db_cond_to_sqladd($cond);
	list($sqladd, $updateParams) = db_array_to_update_sqladd($update);
	if(!$sqladd) return FALSE;
	$params = array_merge($updateParams, $condParams);
	return db_exec_prepared("UPDATE {$d->tablepre}$table SET $sqladd $condadd", $params, $d);
}

function db_delete($table, $cond, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	list($condadd, $condParams) = db_cond_to_sqladd($cond);
	return db_exec_prepared("DELETE FROM {$d->tablepre}$table $condadd", $condParams, $d);
}

function db_truncate($table, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	return $d->truncate($d->tablepre.$table);
}

function db_read($table, $cond, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	list($sqladd, $params) = db_cond_to_sqladd($cond);
	$sql = "SELECT * FROM {$d->tablepre}$table $sqladd";
	return db_sql_find_one_prepared($sql, $params, $d);
}

function db_find($table, $cond = array(), $orderby = array(), $page = 1, $pagesize = 10, $key = '', $col = array(), $d = NULL) {
	$db = $_SERVER['db'];

	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	return $d->find($table, $cond, $orderby, $page, $pagesize, $key, $col);
}

function db_find_one($table, $cond = array(), $orderby = array(), $col = array(), $d = NULL) {
	$db = $_SERVER['db'];

	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	return $d->find_one($table, $cond, $orderby, $col);
}

function db_find_group($table, $cond = array(), $groupby = array(), $having = array(), $orderby = array(), $page = 1, $pagesize = 10, $key = '', $col = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	return $d->find_group($table, $cond, $groupby, $having, $orderby, $page, $pagesize, $key, $col);
}

function db_find_one_group($table, $cond = array(), $groupby = array(), $having = array(), $orderby = array(), $col = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	return $d->find_one_group($table, $cond, $groupby, $having, $orderby, $col);
}

function db_errno_errstr($r, $d = NULL, $sql = '') {
	global $errno, $errstr;
	if($r === FALSE || ($d && $d->errno)) {
		$errno = $d->errno;
		$errstr = db_errstr_safe($errno, $d->errstr);
		$s = 'SQL:'.$sql."\r\nerrno: ".$errno.", errstr: ".$errstr;
		xn_log($s, 'db_error');
	}
}

function db_errstr_safe($errno, $errstr) {
	if(DEBUG) return $errstr;
	if($errno == 1049) {
		return '数据库名不存在，请手工创建';
	} elseif($errno == 2003 ) {
		return '连接数据库服务器失败，请检查IP是否正确，或者防火墙设置';
	} elseif($errno == 1024) {
		return '连接数据库失败';
	} elseif($errno == 1045) {
		return '数据库账户密码错误';
	}
	return $errstr;
}

function db_cond_to_sqladd($cond) {
	$s = '';
	$params = array();
	if(!empty($cond)) {
		$s = ' WHERE ';
		foreach($cond as $k=>$v) {
			if(!is_array($v)) {

				$col = $k;
				$op = '=';
				if(preg_match('/^(.+?)(>=|<=|!=|<>|>|<)$/', $k, $m)) {
					$col = $m[1];
					$op = $m[2];
				}
				// 字段名白名单校验，防字段名注入
				if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
					continue;
				}
				$s .= "`$col`$op? AND ";
				$params[] = $v;
			} elseif(isset($v[0])) {
				// OR 数组：array(1,2,3) -> (col=? OR col=? OR col=?)
				if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $k)) {
					continue;
				}
				$s .= '(';

				foreach ($v as $v1) {
					$s .= "`$k`=? OR ";
					$params[] = $v1;
				}
				$s = substr($s, 0, -4);
				$s .= ') AND ';

			} else {
				// 操作符数组：array('>' => 100, 'LIKE' => 'jack')
				if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $k)) {
					continue;
				}
				foreach($v as $k1=>$v1) {
					if($k1 == 'LIKE') {
						$k1 = ' LIKE ';
						$v1="%$v1%";
					}
					$s .= "`$k`$k1? AND ";
					$params[] = $v1;
				}
			}
		}
		$s = substr($s, 0, -4);
	}
	return array($s, $params);
}

function db_orderby_to_sqladd($orderby) {
	$s = '';
	if(!empty($orderby)) {
		$s .= ' ORDER BY ';
		$comma = '';
		foreach($orderby as $k=>$v) {
			$s .= $comma."`$k` ".($v == 1 ? ' ASC ' : ' DESC ');
			$comma = ',';
		}
	}
	return $s;
}

function db_array_to_update_sqladd($arr) {
	$s = '';
	$params = array();
	foreach($arr as $k=>$v) {
		$op = substr($k, -1);
		if($op == '+' || $op == '-') {
			$col = substr($k, 0, -1);
			// 字段名白名单校验
			if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
				continue;
			}
			$s .= "`$col`=$col$op?,";
			$params[] = $v;
		} else {
			// 字段名白名单校验
			if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $k)) {
				continue;
			}
			$s .= "`$k`=?,";
			$params[] = $v;
		}
	}
	return array(substr($s, 0, -1), $params);
}

function db_array_to_insert_sqladd($arr) {
	$s = '';
	$keys = array();
	$placeholders = array();
	$params = array();
	foreach($arr as $k=>$v) {
		// 字段名白名单校验
		if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $k)) {
			continue;
		}
		$keys[] = '`'.$k.'`';
		$placeholders[] = '?';
		$params[] = $v;
	}
	$keystr = implode(',', $keys);
	$phstr = implode(',', $placeholders);
	$sqladd = "($keystr) VALUES ($phstr)";
	return array($sqladd, $params);
}

function db_check_column_exists($table, $column) {
	$db = $_SERVER['db'];
	if(!$db) return FALSE;
	// 联表查 INFORMATION_SCHEMA 系统表，db_find 不支持，保留 db_sql_find_one_prepared
	$sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
	$r = db_sql_find_one_prepared($sql, array($db->tablepre.$table, $column));
	return !empty($r);
}

function db_check_table_exists($table) {
	$db = $_SERVER['db'];
	if(!$db) return FALSE;
	// 联表查 INFORMATION_SCHEMA 系统表，db_find 不支持，保留 db_sql_find_one_prepared
	$sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
	$r = db_sql_find_one_prepared($sql, array($db->tablepre.$table));
	return !empty($r);
}

function cache_new($cacheconf) {

	if($cacheconf && !empty($cacheconf['enable']) && isset($cacheconf['type'])) {
		switch ($cacheconf['type']) {
			case 'file':      $cache = new cache_file($cacheconf['file']);           break;
			case 'redis':     $cache = new cache_redis($cacheconf['redis']);          break;
			case 'memcached': $cache = new cache_memcached($cacheconf['memcached']);  break;
			case 'pdo_mysql':
			case 'mysql':
				$cache = new cache_mysql($cacheconf['mysql']); break;
			default: return xn_error(-1, '不支持的 cache type:'.$cacheconf['type']);
		}
		return $cache;
	}
	return NULL;
}

function cache_get($k, $c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return FALSE;

	strlen($k) > 32 AND $k = md5($k);

	$k = $c->cachepre.$k;
	$r = $c->get($k);
	return $r;
}

function cache_set($k, $v, $life = 0, $c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return FALSE;

	strlen($k) > 32 AND $k = md5($k);

	$k = $c->cachepre.$k;
	$r = $c->set($k, $v, $life);
	return $r;
}

function cache_delete($k, $c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return FALSE;

	strlen($k) > 32 AND $k = md5($k);

	$k = $c->cachepre.$k;
	$r = $c->delete($k);
	return $r;
}

function cache_truncate($c = NULL) {
	$cache = $_SERVER['cache'];
	$c = $c ? $c : $cache;
	if(!$c) return FALSE;

	$r = $c->truncate();
	return $r;
}

function image_ext($filename) {
	return strtolower(substr(strrchr($filename, '.'), 1));
}

function image_safe_name($filename, $dir) {
	$time = $_SERVER['time'];

	$s1 = substr($filename, 0, strrpos($filename, '.'));
	$s2 = substr(strrchr($filename, '.'), 1);
	$s1 = preg_replace('#\W#', '_', $s1);
	$s2 = preg_replace('#\W#', '_', $s2);
	if(is_file($dir."$s1.$s2")) {
		$newname = $s1.$time.rand(1, 1000).'.'.$s2;
	} else {
		$newname = "$s1.$s2";
	}
	return $newname;
}

function image_thumb_name($filename) {
	return substr($filename, 0, strrpos($filename, '.')).'_thumb'.strrchr($filename, '.');
}

function image_rand_name($k) {
	$time = $_SERVER['time'];
	return $time.'_'.rand(1000000000, 9999999999).'_'.$k;
}

function image_set_dir($id, $dir) {

	$id = sprintf("%09d", $id);
	$s1 = substr($id, 0, 3);
	$s2 = substr($id, 3, 3);
	$dir = $dir."$s1/$s2";
	!is_dir($dir) && mkdir($dir, 0777, TRUE);

	return "$s1/$s2";
}

function image_get_dir($id) {
	$id = sprintf("%09d", $id);
	$s1 = substr($id, 0, 3);
	$s2 = substr($id, 3, 3);
	return "$s1/$s2";
}

function image_read_gd($sourcefile) {
	$imginfo = @getimagesize($sourcefile);
	if(!$imginfo) return false;

	$img = false;
	switch($imginfo['mime']) {
		case 'image/jpeg': $img = @imagecreatefromjpeg($sourcefile); break;
		case 'image/png':  $img = @imagecreatefrompng($sourcefile);  break;
		case 'image/gif':  $img = @imagecreatefromgif($sourcefile);  break;
		case 'image/bmp':  if(function_exists('imagecreatefrombmp')) $img = @imagecreatefrombmp($sourcefile); break;
		case 'image/webp':
			if(function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($sourcefile);

			if(!$img) {
				$imgdata = @file_get_contents($sourcefile);
				if($imgdata !== false) $img = @imagecreatefromstring($imgdata);
			}
			break;
		default:

			$data = @file_get_contents($sourcefile);
			if($data !== false) $img = @imagecreatefromstring($data);
			break;
	}

	if($img && imagesx($img) > 0 && imagesy($img) > 0) return $img;

	if(class_exists('Imagick')) {
		try {
			$imagick = new Imagick($sourcefile);
			$imagick->setImageFormat('png');
			$blob = $imagick->getImageBlob();
			$imagick->clear();
			$imagick->destroy();
			if(!empty($blob)) {
				$img = @imagecreatefromstring($blob);
				if($img && imagesx($img) > 0 && imagesy($img) > 0) return $img;
			}
		} catch(Exception $e) {

		}
	}

	return false;
}

function image_thumb($sourcefile, $destfile, $forcedwidth = 80, $forcedheight = 80) {
	$return = array('filesize'=>0, 'width'=>0, 'height'=>0);
	$destext = image_ext($destfile);
	if(!in_array($destext, array('gif', 'jpg', 'bmp', 'png', 'webp'))) {
		return $return;
	}

	$imginfo = getimagesize($sourcefile);
	$src_width = $imginfo[0];
	$src_height = $imginfo[1];
	if($src_width == 0 || $src_height == 0) {
		return $return;
	}

	if(!function_exists('imagecreatefromjpeg')) {
		copy($sourcefile, $destfile);
		$return = array('filesize'=>filesize($destfile), 'width'=>$src_width, 'height'=>$src_height);
		return $return;
	}

	$src_scale = $src_width / $src_height;
	$des_scale = $forcedwidth / $forcedheight;
	if($src_width <= $forcedwidth && $src_height <= $forcedheight) {
		$des_width = $src_width;
		$des_height = $src_height;
	} elseif($src_scale >= $des_scale) {
		$des_width = ($src_width >= $forcedwidth) ? $forcedwidth : $src_width;
		$des_height = $des_width / $src_scale;
		$des_height = ($des_height >= $forcedheight) ? $forcedheight : $des_height;
	} else {
		$des_height = ($src_height >= $forcedheight) ? $forcedheight : $src_height;
		$des_width = $des_height * $src_scale;
		$des_width = ($des_width >= $forcedwidth) ? $forcedwidth : $des_width;
	}

	$img_src = image_read_gd($sourcefile);
	if(!$img_src) return $return;

	$img_dst = imagecreatetruecolor($des_width, $des_height);
	imagefill($img_dst, 0, 0 , 0xFFFFFF);
	imagecopyresampled($img_dst, $img_src, 0, 0, 0, 0, $des_width, $des_height, $src_width, $src_height);

	$conf = _SERVER('conf');
	$tmppath = isset($conf['tmp_path']) ? $conf['tmp_path'] : ini_get('upload_tmp_dir').'/';
	$tmppath == '/' AND $tmppath = './tmp/';

	$tmpfile = $tmppath.md5($destfile).'.tmp';
	switch($destext) {
		case 'jpg': imagejpeg($img_dst, $tmpfile, 90); break;
		case 'gif': imagegif($img_dst, $tmpfile); break;
		case 'png': imagepng($img_dst, $tmpfile); break;
		case 'webp': imagewebp($img_dst, $tmpfile, 90); break;
	}
	$r = array('filesize'=>filesize($tmpfile), 'width'=>$des_width, 'height'=>$des_height);;
	copy($tmpfile, $destfile);
	is_file($tmpfile) && unlink($tmpfile);
	imagedestroy($img_dst);
	return $r;
}

function image_clip($sourcefile, $destfile, $clipx, $clipy, $clipwidth, $clipheight) {
	$getimgsize = getimagesize($sourcefile);
	if(empty($getimgsize)) {
		return 0;
	} else {
		$imgwidth = $getimgsize[0];
		$imgheight = $getimgsize[1];
		if($imgwidth == 0 || $imgheight == 0) {
			return 0;
		}
	}

	if(!function_exists('imagecreatefromjpeg')) {
		copy($sourcefile, $destfile);
		return filesize($destfile);
	}
	$imgcolor = image_read_gd($sourcefile);
	if(!$imgcolor) return 0;

	$img_dst = imagecreatetruecolor($clipwidth, $clipheight);
	imagefill($img_dst, 0, 0 , 0xFFFFFF);
	imagecopyresampled($img_dst, $imgcolor, 0, 0, $clipx, $clipy, $imgwidth, $imgheight, $imgwidth, $imgheight);

	$conf = _SERVER('conf');
	$tmppath = isset($conf['tmp_path']) ? $conf['tmp_path'] : ini_get('upload_tmp_dir').'/';
	$tmppath == '/' AND $tmppath = './tmp/';

	$tmpfile = $tmppath.md5($destfile).'.tmp';
	imagejpeg($img_dst, $tmpfile, 100);
	$n = filesize($tmpfile);
	copy($tmpfile, $destfile);
	is_file($tmpfile) && @unlink($tmpfile);
	return $n;
}

function image_clip_thumb($sourcefile, $destfile, $forcedwidth = 80, $forcedheight = 80) {

	$getimgsize = getimagesize($sourcefile);
	if(empty($getimgsize)) {
		return 0;
	} else {
		$src_width = $getimgsize[0];
		$src_height = $getimgsize[1];
		if($src_width == 0 || $src_height == 0) {
			return 0;
		}
	}

	$src_scale = $src_width / $src_height;
	$des_scale = $forcedwidth / $forcedheight;

	if($src_width <= $forcedwidth && $src_height <= $forcedheight) {
		$des_width = $src_width;
		$des_height = $src_height;
		$n = image_clip($sourcefile, $destfile, 0, 0, $des_width, $des_height);
		return filesize($destfile);

	} elseif($src_scale >= $des_scale) {

		$des_height = $src_height;
		$des_width = $src_height / $des_scale;
		$n = image_clip($sourcefile, $destfile, 0, 0, $des_width, $des_height);
		if($n <= 0) return 0;
		$r = image_thumb($destfile, $destfile, $forcedwidth, $forcedheight);
		return $r['filesize'];

	} else {

		$des_width = $src_width;
		$des_height = $src_width / $des_scale;

		$n = image_clip($sourcefile, $destfile, 0, 0, $des_width, $des_height);
		if($n <= 0) return 0;
		$r = image_thumb($destfile, $destfile, $forcedwidth, $forcedheight);
		return $r['filesize'];
	}
}

function image_safe_thumb($sourcefile, $id, $ext, $dir1, $forcedwidth, $forcedheight, $randomname = 0) {
	$time = $_SERVER['time'];
	$ip = $_SERVER['ip'];
	$dir2 = image_set_dir($id, $dir1);
	$filename = $randomname ? md5(rand(0, 1000000000).$time.$ip).$ext : $id.$ext;
	$filepath = "$dir1$dir2/$filename";
	$arr = image_thumb($sourcefile, $filepath, $forcedwidth, $forcedheight);
	$arr['fileurl'] = "$dir2/$filename";
	return $arr;
}

function array_value($arr, $key, $default = '') {
	return isset($arr[$key]) ? $arr[$key] : $default;
}

function array_filter_empty($arr) {
	foreach($arr as $k=>$v) {
		if(empty($v)) unset($arr[$k]);
	}
	return $arr;
}

function array_addslashes(&$var) {
	if(is_array($var)) {
		foreach($var as $k=>&$v) {
			array_addslashes($v);
		}
	} else {
		$var = addslashes($var);
	}
	return $var;
}

function array_stripslashes(&$var) {
	if(is_array($var)) {
		foreach($var as $k=>&$v) {
			array_stripslashes($v);
		}
	} else {
		$var = stripslashes($var);
	}
	return $var;
}

function array_htmlspecialchars(&$var) {
	if(is_array($var)) {
		foreach($var as $k=>&$v) {
			array_htmlspecialchars($v);
		}
	} else {
		$var = str_replace(array('&', '"', '<', '>'), array('&amp;', '&quot;', '&lt;', '&gt;'), $var);
	}
	return $var;
}

function array_trim(&$var) {
	if(is_array($var)) {
		foreach($var as $k=>&$v) {
			array_trim($v);
		}
	} else {
		$var = trim($var);
	}
	return $var;
}

function array_diff_value($arr1, $arr2) {
	foreach ($arr1 as $k=>$v) {
		if(isset($arr2[$k]) && $arr2[$k] == $v ) unset($arr1[$k]);
	}
	return $arr1;
}

function arrlist_multisort($arrlist, $col, $asc = TRUE) {
	$colarr = array();
	foreach($arrlist as $k=>$arr) {
		$colarr[$k] = $arr[$col];
	}
	$asc = $asc ? SORT_ASC : SORT_DESC;
	array_multisort($colarr, $asc, $arrlist);
	return $arrlist;
}

function arrlist_cond_orderby($arrlist, $cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	$resultarr = array();
	if(empty($arrlist)) return $arrlist;

	if($cond) {
		foreach($arrlist as $key=>$val) {
			$ok = TRUE;
			foreach($cond as $k=>$v) {
				if(!isset($val[$k])) {
					$ok = FALSE; break;
				}
				if(!is_array($v)) {
					if($val[$k] != $v) {
						$ok = FALSE; break;
					}
				} else {
					foreach($v as $k3=>$v3) {
						if(
							($k3 == '>' && $val[$k] <= $v3) ||
							($k3 == '<' && $val[$k] >= $v3) ||
							($k3 == '>=' && $val[$k] < $v3) ||
							($k3 == '<=' && $val[$k] > $v3) ||
							($k3 == '==' && $val[$k] != $v3) ||
							($k3 == 'LIKE' && stripos($val[$k], $v3) === FALSE)
						)  {
							$ok = FALSE; break 2;
						}
					}
				}
			}
			if($ok) $resultarr[$key] = $val;
		}
	} else {
		$resultarr = $arrlist;
	}

	if($orderby) {

		$k = key($orderby);
		$v = current($orderby);

		$resultarr = arrlist_multisort($resultarr, $k, $v == 1);
	}

	$start = ($page - 1) * $pagesize;

	$resultarr = array_assoc_slice($resultarr, $start, $pagesize);
	return $resultarr;
}

function array_assoc_slice($arrlist, $start, $length = 0) {
	if(isset($arrlist[0])) return array_slice($arrlist, $start, $length);
	$keys = array_keys($arrlist);
	$keys2 = array_slice($keys, $start, $length);
	$retlist = array();
	foreach($keys2 as $key) {
		$retlist[$key] = $arrlist[$key];
	}

	return $retlist;
}

function arrlist_key_values($arrlist, $key, $value = NULL, $pre = '') {
	$return = array();
	if($key) {
		foreach((array)$arrlist as $k=>$arr) {
			$return[$pre.$arr[$key]] = $value ? $arr[$value] : $k;
		}
	} else {
		foreach((array)$arrlist as $arr) {
			$return[] = $arr[$value];
		}
	}
	return $return;
}

function arrlist_values($arrlist, $key) {
	if(!$arrlist) return array();
	$return = array();
	foreach($arrlist as &$arr) {
		$return[] = $arr[$key];
	}
	return $return;
}

function arrlist_sum($arrlist, $key) {
	if(!$arrlist) return 0;
	$n = 0;
	foreach($arrlist as &$arr) {
		$n += $arr[$key];
	}
	return $n;
}

function arrlist_max($arrlist, $key) {
	if(!$arrlist) return 0;
	$first = array_pop($arrlist);
	$max = $first[$key];
	foreach($arrlist as &$arr) {
		if($arr[$key] > $max) {
			$max = $arr[$key];
		}
	}
	return $max;
}

function arrlist_min($arrlist, $key) {
	if(!$arrlist) return 0;
	$first = array_pop($arrlist);
	$min = $first[$key];
	foreach($arrlist as &$arr) {
		if($min > $arr[$key]) {
			$min = $arr[$key];
		}
	}
	return $min;
}

function arrlist_change_key($arrlist, $key = '', $pre = '') {
	$return = array();
	if(empty($arrlist)) return $return;
	foreach($arrlist as &$arr) {
		if(empty($key)) {
			$return[] = $arr;
		} else {
			$return[$pre.''.$arr[$key]] = $arr;
		}
	}

	return $return;
}

function arrlist_keep_keys($arrlist, $keys = array()) {
	!is_array($keys) AND $keys = array($keys);
	foreach($arrlist as &$v) {
		$arr = array();
		foreach($keys as $key) {
			$arr[$key] = isset($v[$key]) ? $v[$key] : NULL;
		}
		$v = $arr;
	}
	return $arrlist;
}

function arrlist_chunk($arrlist, $key) {
	$r = array();
	if(empty($arrlist)) return $r;
	foreach($arrlist as &$arr) {
		!isset($r[$arr[$key]]) AND $r[$arr[$key]] = array();
		$r[$arr[$key]][] = $arr;
	}
	return $r;
}

function xn_key($fromso = TRUE) {
	$conf = _SERVER('conf');
	return ($fromso && function_exists('xiuno_key')) ? xiuno_key() : (isset($conf['auth_key']) ? $conf['auth_key'] : '');
}

function xn_safe_key() {
	global $conf, $longip, $time, $useragent;
	$conf = _SERVER('conf');
	$longip = _SERVER('longip');
	$time = _SERVER('time');
	$useragent = _SERVER('useragent');
	$key = xn_key();
	$behind = intval(substr($time, -2, 2));
	$t = $behind > 80 ? $time - 20 : ($behind < 20 ? $time - 40 : $time);
	$front = substr($t, 0, -2);
	$key = md5($key.$useragent.$front);
	return $key;
}

// AES-256-GCM 加密（v2，替代 XXTEA）
// 密钥派生：HKDF-SHA256，info='xiuno-token'，输出 32 字节
// IV：12 字节随机数（GCM 标准）
// 认证标签：16 字节
// 密文格式：base64(iv[12] + ciphertext + tag[16])
function xn_encrypt_v2($key, $data) {
	$derived_key = hash_hkdf('sha256', $key, 0, 'xiuno-token');
	$iv = random_bytes(12);
	$tag = '';
	$ciphertext = openssl_encrypt($data, 'aes-256-gcm', $derived_key, OPENSSL_RAW_DATA, $iv, $tag);
	if($ciphertext === false) return false;
	return base64_encode($iv . $ciphertext . $tag);
}

// AES-256-GCM 解密（v2）
// 失败返回 false；GCM 自带认证标签校验，tag 不匹配则 openssl_decrypt 返回 false
function xn_decrypt_v2($key, $payload) {
	$raw = base64_decode($payload, true);
	if($raw === false) return false;
	// 最小长度：12(IV) + 16(tag) = 28 字节
	if(strlen($raw) < 28) return false;
	$derived_key = hash_hkdf('sha256', $key, 0, 'xiuno-token');
	$iv = substr($raw, 0, 12);
	$tag = substr($raw, -16);
	$ciphertext = substr($raw, 12, -16);
	$plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $derived_key, OPENSSL_RAW_DATA, $iv, $tag);
	if($plaintext === false) return false;
	return $plaintext;
}

// 兼容入口：加密永远用 v2
// 保留原签名 ($txt, $key) 以兼容所有调用方
function xn_encrypt($txt, $key = '') {
	empty($key) AND $key = xn_key();
	return xn_encrypt_v2($key, $txt);
}

// 兼容入口：解密先试 v2，失败回退 XXTEA（迁移期兼容旧令牌）
// 第三个可选参数 &$used_v2：true 表示 v2 解密成功，false 表示 fallback 到 XXTEA
function xn_decrypt($txt, $key = '', &$used_v2 = false) {
	empty($key) AND $key = xn_key();
	// 先尝试 v2（密文为 raw base64，无需 xn_urldecode）
	$v2_result = xn_decrypt_v2($key, $txt);
	if($v2_result !== false) {
		$used_v2 = true;
		return $v2_result;
	}
	// 回退到 XXTEA 旧逻辑（旧令牌经 xn_urlencode 包装，需 xn_urldecode 还原）
	$used_v2 = false;
	$encrypt = base64_decode(xn_urldecode($txt));
	$ret = function_exists('xiuno_decrypt') ? xiuno_decrypt($encrypt, $key) : xxtea_decrypt($encrypt, $key);
	return $ret;
}

if(!function_exists('xxtea_encrypt')) {

	function xxtea_long2str($v, $w) {
		$len = count($v);
		$n = ($len - 1) << 2;
		if ($w) {
			$m = $v[$len - 1];
			if (($m < $n - 3) || ($m > $n)) return FALSE;
			$n = $m;
		}
		$s = array();
		for ($i = 0; $i < $len; $i++) {
			$s[$i] = pack("V", $v[$i]);
		}
		if ($w) {
			return substr(join('', $s), 0, $n);
		}
		else {
			return join('', $s);
		}
	}

	function xxtea_str2long($s, $w) {
		$v = unpack("V*", $s. str_repeat("\0", (4 - strlen($s) % 4) & 3));
		$v = array_values($v);
		if ($w) {
			$v[count($v)] = strlen($s);
		}
		return $v;
	}

	function xxtea_int32($n) {
		while ($n >= 2147483648) $n -= 4294967296;
		while ($n <= -2147483649) $n += 4294967296;
		return (int)$n;
	}

	function xxtea_encrypt($str, $key) {
		if($str == '') return '';
		$v = xxtea_str2long($str, TRUE);
		$k = xxtea_str2long($key, FALSE);
		if (count($k) < 4) {
			for ($i = count($k); $i < 4; $i++) {
				$k[$i] = 0;
			}
		}
		$n = count($v) - 1;

		$z = $v[$n];
		$y = $v[0];
		$delta = 0x9E3779B9;
		$q = floor(6 + 52 / ($n + 1));
		$sum = 0;
		while (0 < $q--) {
			$sum = xxtea_int32($sum + $delta);
			$e = $sum >> 2 & 3;
			for ($p = 0; $p < $n; $p++) {
				$y = $v[$p + 1];
				$mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
				$z = $v[$p] = xxtea_int32($v[$p] + $mx);
			}
			$y = $v[0];
			$mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
			$z = $v[$n] = xxtea_int32($v[$n] + $mx);
		}
		return xxtea_long2str($v, FALSE);
	}

	function xxtea_decrypt($str, $key) {
		if($str == '') return '';
		$v = xxtea_str2long($str, FALSE);
		$k = xxtea_str2long($key, FALSE);
		if(count($k) < 4) {
			for ($i = count($k); $i < 4; $i++) {
				$k[$i] = 0;
			}
		}
		$n = count($v) - 1;

		$z = $v[$n];
		$y = $v[0];
		$delta = 0x9E3779B9;
		$q = floor(6 + 52 / ($n + 1));
		$sum = xxtea_int32($q * $delta);
		while ($sum != 0) {
			$e = $sum >> 2 & 3;
			for ($p = $n; $p > 0; $p--) {
				$z = $v[$p - 1];
				$mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
				$y = $v[$p] = xxtea_int32($v[$p] - $mx);
			}
			$z = $v[$n];
			$mx = xxtea_int32((($z >> 5 & 0x07ffffff) ^ $y << 2) + (($y >> 3 & 0x1fffffff) ^ $z << 4)) ^ xxtea_int32(($sum ^ $y) + ($k[$p & 3 ^ $e] ^ $z));
			$y = $v[0] = xxtea_int32($v[0] - $mx);
			$sum = xxtea_int32($sum - $delta);
		}
		return xxtea_long2str($v, TRUE);
	}
}

function xn_message($code, $message) {
	$ajax = $_SERVER['ajax'];
	echo $ajax ? xn_json_encode(array('code'=>$code, 'message'=>$message)) : $message;
	exit;
}

function xn_log_post_data() {
	$method = $_SERVER['method'];
	if($method != 'POST') return;
	$post = $_POST;
	isset($post['password']) AND $post['password'] = '******';
	isset($post['password_new']) AND $post['password_new'] = '******';
	isset($post['password_old']) AND $post['password_old'] = '******';

	xn_log(xn_json_encode($post), 'post_data');
}

function error_handle($errno, $errstr, $errfile, $errline) {

	if(DEBUG == 0)  return FALSE;

	$time = $_SERVER['time'];
	$ajax = $_SERVER['ajax'];
	IN_CMD AND $errstr = str_replace('<br>', "\n", $errstr);

	$subject = "Error[$errno]: $errstr, File: $errfile, Line: $errline";
	$message = array();
	xn_log($subject, 'php_error');

	$arr = debug_backtrace();
	array_shift($arr);
	foreach($arr as $v) {
		$args = '';
		if(!empty($v['args']) && is_array($v['args'])) foreach ($v['args'] as $v2) $args .= ($args ? ' , ' : '').(is_array($v2) ? 'array('.count($v2).')' : (is_object($v2) ? 'object' : $v2));
		!isset($v['file']) AND $v['file'] = '';
		!isset($v['line']) AND $v['line'] = '';
		$message [] = "File: $v[file], Line: $v[line], $v[function]($args) ";
	}
	$txt = $subject."\r\n".implode("\r\n", $message);
	$html = $s = "<fieldset class=\"fieldset small notice\">
			<b>$subject</b>
			<div>".implode("<br>\r\n", $message)."</div>
		</fieldset>";
	echo ($ajax || IN_CMD) ? $txt : $html;
	DEBUG == 2 AND xn_log($txt, 'debug_error');
	return TRUE;
}

function xn_error($no, $str, $return = FALSE) {
	global $errno, $errstr;
	$errno = $no;
	$errstr = $str;
	return $return;
}

function param($key, $defval = '', $htmlspecialchars = TRUE, $addslashes = FALSE) {
	if(!isset($_REQUEST[$key]) || ($key === 0 && empty($_REQUEST[$key]))) {
		if(is_array($defval)) {
			return array();
		} else {
			return $defval;
		}
	}
	$val = $_REQUEST[$key];
	$val = param_force($val, $defval, $htmlspecialchars, $addslashes);
	return $val;
}

function param_word($key, $len = 32) {
	$s = param($key);
	$s = xn_safe_word($s, $len);
	return $s;
}

function param_base64($key, $len = 0) {
	$s = param($key, '', FALSE);
	if(empty($s)) return '';
	$s = substr($s, strpos($s, ',') + 1);
	$s = base64_decode($s);
	$len AND $s = substr($s, 0, $len);
	return $s;
}

function param_json($key) {
	$s = param($key, '', FALSE);
	if(empty($s)) return '';
	$arr = xn_json_decode($s);
	return $arr;
}

function param_url($key) {
	$s = param($key, '', FALSE);
	$arr = xn_urldecode($s);
	return $arr;
}

function xn_safe_word($s, $len) {
	$s = preg_replace('#\W+#', '', $s);
	$s = substr($s, 0, $len);
	return $s;
}

function param_force($val, $defval, $htmlspecialchars = TRUE, $addslashes = FALSE) {
	$get_magic_quotes_gpc = _SERVER('get_magic_quotes_gpc');
	if(is_array($defval)) {
		$defval = empty($defval) ? '' : $defval[0];
		if(is_array($val)) {
			foreach($val as &$v) {
				if(is_array($v)) {
					$v = $defval;
				} else {
					if(is_string($defval)) {

					$v = (string)$v;
					$addslashes AND !$get_magic_quotes_gpc && $v = addslashes($v);
					!$addslashes AND $get_magic_quotes_gpc && $v = stripslashes($v);
					$htmlspecialchars AND $v = htmlspecialchars($v);
					} else {
						$v = intval($v);
					}
				}
			}
		} else {
			return array();
		}
	} else {
		if(is_array($val)) {
			$val = $defval;
		} else {
			if(is_string($defval)) {

				$val = (string)$val;
				$addslashes AND !$get_magic_quotes_gpc && $val = addslashes($val);
				!$addslashes AND $get_magic_quotes_gpc && $val = stripslashes($val);
				$htmlspecialchars AND $val = htmlspecialchars($val);
			} else {
				$val = intval($val);
			}
		}
	}
	return $val;
}

function lang($key, $arr = array()) {
	$lang = $_SERVER['lang'];
	if(!isset($lang[$key])) return 'lang['.$key.']';
	$s = $lang[$key];
	if(!empty($arr)) {
		foreach($arr as $k=>$v) {
			$s = str_replace('{'.$k.'}', $v, $s);
		}
	}
	return $s;
}

function jump($message, $url = '', $delay = 3) {
	$ajax = $_SERVER['ajax'];
	if($ajax) return $message;
	if(!$url) return $message;
	$url == 'back' AND $url = 'javascript:history.back()';
	$htmladd = '<script>setTimeout(function() {window.location=\''.$url.'\'}, '.($delay * 1000).');</script>';
	return '<a href="'.$url.'">'.$message.'</a>'.$htmladd;
}

function xn_strlen($s) {
	return mb_strlen($s, 'UTF-8');
}

function xn_substr($s, $start, $len) {
	return mb_substr($s, $start, $len, 'UTF-8');
}

function xn_txt_to_html($s) {
	$s = htmlspecialchars($s);
	$s = str_replace(" ", '&nbsp;', $s);
	$s = str_replace("\t", ' &nbsp; &nbsp; &nbsp; &nbsp;', $s);
	$s = str_replace("\r\n", "\n", $s);
	$s = str_replace("\n", '<br>', $s);
	return $s;
}

function xn_urlencode($s) {
    $s = urlencode($s);
    $s = str_replace('_', '_5f', $s);
    $s = str_replace('-', '_2d', $s);
    $s = str_replace('.', '_2e', $s);
    $s = str_replace('+', '_2b', $s);
    $s = str_replace('=', '_3d', $s);
    $s = str_replace('%', '_', $s);
    return $s;
}

function xn_urldecode($s) {
    $s = str_replace('_', '%', $s);
    $s = urldecode($s);
    return $s;
}

function xn_json_encode($data, $pretty = FALSE, $level = 0) {
	// PHP 8.0+ 自带 json_encode 完全支持 JSON_UNESCAPED_UNICODE 等常量，无需再保留 5.3 兼容分支
	return json_encode($data, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
}

function xn_json_decode($json) {
	$json = trim($json, "\xEF\xBB\xBF");
	$json = trim($json, "\xFE\xFF");
	return json_decode($json, 1);
}

function pagination_tpl($url, $text, $active = '') {
	global $g_pagination_tpl;
	empty($g_pagination_tpl) AND $g_pagination_tpl = '<li class="page-item{active}"><a class="page-link" href="{url}">{text}</a></li>';
	return str_replace(array('{url}', '{text}', '{active}'), array($url, $text, $active), $g_pagination_tpl);
}

function pagination($url, $totalnum, $page, $pagesize = 20) {
	$totalpage = ceil($totalnum / $pagesize);
	if($totalpage < 2) return '';
	$page = min($totalpage, $page);
	$shownum = 2;	// 当前页左右各显示多少个页码，总计 5 个页码按钮

	$start = max(1, $page - $shownum);
	$end = min($totalpage, $page + $shownum);

	$right = $page + $shownum - $totalpage;
	$right > 0 && $start = max(1, $start -= $right);
	$left = $page - $shownum;
	$left < 0 && $end = min($totalpage, $end -= $left);

	$s = '';
	$prev_icon = '<i class="ti ti-chevron-left align-middle fs-lg"></i>';
	$next_icon = '<i class="ti ti-chevron-right align-middle fs-lg"></i>';
	$page != 1 && $s .= pagination_tpl(str_replace('{page}', $page-1, $url), $prev_icon, '');
	if($start > 1) $s .= pagination_tpl(str_replace('{page}', 1, $url),'1'.($start > 2 ? ' <span class="px-1">&hellip;</span>' : ''));
	for($i=$start; $i<=$end; $i++) {
		$s .= pagination_tpl(str_replace('{page}', $i, $url), $i, $i == $page ? ' active' : '');
	}
	if($end != $totalpage) $s .= pagination_tpl(str_replace('{page}', $totalpage, $url), ($totalpage - $end > 1 ? '<span class="px-1">&hellip;</span> ' : '').$totalpage);
	$page != $totalpage && $s .= pagination_tpl(str_replace('{page}', $page+1, $url), $next_icon);
	return $s;
}

function pager($url, $totalnum, $page, $pagesize = 20) {
	$totalpage = ceil($totalnum / $pagesize);
	if($totalpage < 2) return '';
	$page = min($totalpage, $page);

	$s = '';
	$prev_icon = '<i class="ti ti-chevron-left"></i>';
	$next_icon = '<i class="ti ti-chevron-right"></i>';
	$page > 1 AND $s .= '<li class="page-item"><a class="page-link" href="'.str_replace('{page}', $page-1, $url).'">'.$prev_icon.'</a></li>';
	$s .= '<li class="page-item disabled"><span class="page-link">'.$page.' / '.$totalpage.'</span></li>';
	$totalnum >= $pagesize AND $page != $totalpage AND $s .= '<li class="page-item"><a class="page-link" href="'.str_replace('{page}', $page+1, $url).'">'.$next_icon.'</a></li>';
	return $s;
}

function mid($n, $min, $max) {
	if($n < $min) return $min;
	if($n > $max) return $max;
	return $n;
}

function humandate($timestamp, $lan = array()) {
	$time = $_SERVER['time'];
	$lang = $_SERVER['lang'];

	static $custom_humandate = NULL;
	if($custom_humandate === NULL) $custom_humandate = function_exists('custom_humandate');
	if($custom_humandate) return custom_humandate($timestamp, $lan);

	$seconds = $time - $timestamp;
	$lan = empty($lang) ? $lan : $lang;
	empty($lan) AND $lan = array(
		'month_ago'=>'月前',
		'day_ago'=>'天前',
		'hour_ago'=>'小时前',
		'minute_ago'=>'分钟前',
		'second_ago'=>'秒前',
	);
	if($seconds > 31536000) {
		return date('Y-n-j', $timestamp);
	} elseif($seconds > 2592000) {
		return floor($seconds / 2592000).$lan['month_ago'];
	} elseif($seconds > 86400) {
		return floor($seconds / 86400).$lan['day_ago'];
	} elseif($seconds > 3600) {
		return floor($seconds / 3600).$lan['hour_ago'];
	} elseif($seconds > 60) {
		return floor($seconds / 60).$lan['minute_ago'];
	} else {
		return $seconds.$lan['second_ago'];
	}
}

function humannumber($num) {

	static $custom_humannumber = NULL;
	if($custom_humannumber === NULL) $custom_humannumber = function_exists('custom_humannumber');
	if($custom_humannumber) return custom_humannumber($num);

	$num > 100000 && $num = ceil($num / 10000).'万';
	return $num;
}

function humansize($num) {

	static $custom_humansize = NULL;
	if($custom_humansize === NULL) $custom_humansize = function_exists('custom_humansize');
	if($custom_humansize) return custom_humansize($num);

	if($num > 1073741824) {
		return number_format($num / 1073741824, 2, '.', '').'G';
	} elseif($num > 1048576) {
		return number_format($num / 1048576, 2, '.', '').'M';
	} elseif($num > 1024) {
		return number_format($num / 1024, 2, '.', '').'K';
	} else {
		return $num.'B';
	}
}

function ip() {
	$conf = _SERVER('conf');
	$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
	$local_ips = array('127.0.0.1', '::1', '0.0.0.0');
	$is_local = in_array($ip, $local_ips);
	if(empty($conf['cdn_ip']) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		if($is_local && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$arr = array_filter(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
			if(!empty($arr)) {
				$real_ip = reset($arr);
				return long2ip(ip2long($real_ip));
			}
		}
		return long2ip(ip2long($ip));
	}
	$is_trusted = $is_local;
	$ip_long = ip2long($ip);
	foreach($conf['cdn_ip'] as $cdnip) {
		$cdnip = trim($cdnip);
		if($cdnip === '' || $ip_long === false) continue;
		if(strpos($cdnip, '/') !== false) {
			list($subnet, $mask) = explode('/', $cdnip, 2);
			$subnet_long = ip2long($subnet);
			$mask = intval($mask);
			if($subnet_long !== false && $mask >= 0 && $mask <= 32) {
				$mask_long = $mask == 0 ? 0 : (~((1 << (32 - $mask)) - 1) & 0xFFFFFFFF);
				if(($ip_long & $mask_long) === ($subnet_long & $mask_long)) {
					$is_trusted = true;
					break;
				}
			}
		} elseif($ip === $cdnip) {
			$is_trusted = true;
			break;
		}
	}
	if(!$is_trusted) {
		return long2ip(ip2long($ip));
	}
	// 可信代理：取 X-Forwarded-For 第一个值（首跳，最远端用户 IP）
	$arr = array_filter(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
	if(empty($arr)) return long2ip(ip2long($ip));
	$real_ip = reset($arr);
	return long2ip(ip2long($real_ip));
}

// 日志记录
// $level: DEBUG/INFO/WARNING/ERROR，按 conf.log_level 过滤低级别日志
function xn_log($s, $file = 'error', $level = 'WARNING') {
	// DEBUG=0 时仅写文件名含 error 或 security 的日志（security 用于安全审计日志）
	if(DEBUG == 0 && strpos($file, 'error') === FALSE && strpos($file, 'security') === FALSE) return;

	// 级别过滤：低于 conf.log_level 阈值的日志不写
	static $levels = array('DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3);
	$conf = _SERVER('conf');
	$config_level = isset($conf['log_level']) ? $conf['log_level'] : 'WARNING';
	if(isset($levels[$level]) && isset($levels[$config_level]) && $levels[$level] < $levels[$config_level]) {
		return;
	}
	// 未知级别归一为 WARNING
	$level = isset($levels[$level]) ? $level : 'WARNING';

	$time = $_SERVER['time'];
	$ip = $_SERVER['ip'];
	$uid = intval(G('uid'));
	$day = date('Ym', $time);
	$mtime = date('Y-m-d H:i:s');
	$url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
	$logpath = $conf['log_path'].$day;
	!is_dir($logpath) AND mkdir($logpath, 0777, true);

	$s = str_replace(array("\r\n", "\n", "\t"), ' ', $s);
	$s = "<?php exit;?>\t[$level]\t$mtime\t$ip\t$url\t$uid\t$s\r\n";

	@error_log($s, 3, $logpath."/$file.php");
}

function get__browser() {

	$browser = array(
		'device'=>'pc',
		'name'=>'chrome',
		'version'=>30,
	);
	$agent = _SERVER('HTTP_USER_AGENT');

	if(strpos($agent, 'msie') !== FALSE || stripos($agent, 'trident') !== FALSE) {
		$browser['name'] = 'ie';
		$browser['version'] = 8;
		preg_match('#msie\s*([\d\.]+)#is', $agent, $m);
		if(!empty($m[1])) {
			if(strpos($agent, 'compatible; msie 7.0;') !== FALSE) {
				$browser['version'] = 8;
			} else {
				$browser['version'] = intval($m[1]);
			}
		} else {

			preg_match('#Trident/([\d\.]+)#is', $agent, $m);
			if(!empty($m[1])) {
				$trident = intval($m[1]);
				$trident == 4 AND $browser['version'] = 8;
				$trident == 5 AND $browser['version'] = 9;
				$trident > 5 AND $browser['version'] = 10;
			}
		}
	}

	if(isset($_SERVER['HTTP_X_WAP_PROFILE']) || (isset($_SERVER['HTTP_VIA']) && stristr($_SERVER['HTTP_VIA'], "wap") || stripos($agent, 'phone')  || stripos($agent, 'mobile') || strpos($agent, 'ipod'))) {
		$browser['device'] = 'mobile';
	} elseif(strpos($agent, 'pad') !== FALSE) {
		$browser['device'] = 'pad';
		$browser['name'] = '';
		$browser['version'] = '';

	} else {
		$robots = array('bot', 'spider', 'slurp');
		foreach($robots as $robot) {
			if(strpos($agent, $robot) !== FALSE) {
				$browser['name'] = 'robot';
				return $browser;
			}
		}
	}
	return $browser;
}

function check_browser($browser) {
	if($browser['name'] == 'ie' && $browser['version'] < 8) {
		include _include(APP_PATH.'view/htm/browser.htm');
		exit;
	}
}

function is_robot() {
	$agent = _SERVER('HTTP_USER_AGENT');
	$robots = array('bot', 'spider', 'slurp');
	foreach($robots as $robot) {
		if(strpos($agent, $robot) !== FALSE) {
			return TRUE;
		}
	}
	return FALSE;
}

function browser_lang() {

	$accept = _SERVER('HTTP_ACCEPT_LANGUAGE');
	$accept = substr($accept, 0, strpos($accept, ';'));
	if(strpos($accept, 'ko-kr') !== FALSE) {
		return 'ko-kr';

	} else {
		return 'zh-cn';
	}
}

function http_get($url, $cookie = '', $timeout = 30, $times = 3) {

	if(substr($url, 0, 8) == 'https://') {
		return https_get($url, $cookie, $timeout, $times);
	}
	$arr = array(
		'http' => array(
			'method'=> 'GET',
			'timeout' => $timeout
		)
	);
	$stream = stream_context_create($arr);
	while($times-- > 0) {
		$s = @file_get_contents($url, false, $stream, 0, 4096000);
		if($s !== FALSE) return $s;
	}
	return FALSE;
}

function http_post($url, $post = '', $cookie='', $timeout = 30, $times = 3) {
	if(substr($url, 0, 8) == 'https://') {
		return https_post($url, $post, $cookie, $timeout, $times);
	}
	is_array($post) AND $post = http_build_query($post);
	is_array($cookie) AND $cookie = http_build_query($cookie);
	$stream = stream_context_create(array('http' => array('header' => "Content-type: application/x-www-form-urlencoded\r\nx-requested-with: XMLHttpRequest\r\nCookie: $cookie\r\n", 'method' => 'POST', 'content' => $post, 'timeout' => $timeout)));
	while($times-- > 0) {
		$s = @file_get_contents($url, false, $stream, 0, 4096000);
		if($s !== FALSE) return $s;
	}
	return FALSE;
}

function https_get($url, $cookie = '', $timeout = 30, $times = 1) {
	if(substr($url, 0, 7) == 'http://') {
		return xn_error(-1, 'https_get() only accepts https:// URLs');
	}
	return https_post($url, '', $cookie, $timeout, $times, 'GET');
}

function https_post($url, $post = '', $cookie = '', $timeout = 30, $times = 1, $method = 'POST') {
	if(substr($url, 0, 7) == 'http://') {
		return xn_error(-1, 'https_post() only accepts https:// URLs');
	}
	is_array($post) AND $post = http_build_query($post);
	is_array($cookie) AND $cookie = http_build_query($cookie);
	$w = stream_get_wrappers();
	$allow_url_fopen = strtolower(ini_get('allow_url_fopen'));
	$allow_url_fopen = (empty($allow_url_fopen) || $allow_url_fopen == 'off') ? 0 : 1;
	if(extension_loaded('openssl') && in_array('https', $w) && $allow_url_fopen) {
		$stream = stream_context_create(array(
			'http' => array('header' => "Content-type: application/x-www-form-urlencoded\r\nx-requested-with: XMLHttpRequest\r\nCookie: $cookie\r\n", 'method' => $method, 'content' => $post, 'timeout' => $timeout),
			'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)
		));
		$s = @file_get_contents($url, false, $stream, 0, 4096000);
		return $s;
	} elseif (!function_exists('curl_init')) {
		return xn_error(-1, 'server not installed curl.');
	}
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HEADER, 2);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/x-www-form-urlencoded', 'x-requested-with: XMLHttpRequest'));
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_USERAGENT, _SERVER('HTTP_USER_AGENT'));
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

	if(defined('CURLPROTO_HTTPS')) {
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
	}
	if($method == 'POST') {
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
	}
	$header = array('Content-type: application/x-www-form-urlencoded', 'X-Requested-With: XMLHttpRequest');
	if($cookie) {
		$header[] = "Cookie: $cookie";
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

	(!ini_get('safe_mode') && !ini_get('open_basedir')) && curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	$data = curl_exec($ch);
	if(curl_errno($ch)) {
		return xn_error(-1, 'Errno'.curl_error($ch));
	}
	if(!$data) {
		// PHP 8.0+ curl 句柄自动释放，curl_close() 在 8.5 已废弃
		if(PHP_VERSION_ID < 80000) curl_close($ch);
		return '';
	}

	list($header, $data) = explode("\r\n\r\n", $data);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if($http_code == 301 || $http_code == 302) {
		$matches = array();
		preg_match('/Location:(.*?)\n/', $header, $matches);
		$url = trim(array_pop($matches));
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		$data = curl_exec($ch);
	}
	// PHP 8.0+ curl 句柄自动释放，curl_close() 在 8.5 已废弃
	if(PHP_VERSION_ID < 80000) curl_close($ch);
	return $data;
}

function http_multi_get($urls) {

	$data = array();
	if(!function_exists('curl_multi_init')) {
		foreach($urls as $k=>$url) {
			$data[$k] = https_get($url);
		}
		return $data;
	}

	$multi_handle = curl_multi_init();
	foreach ($urls as $i => $url) {
		$conn[$i] = curl_init($url);
		curl_setopt($conn[$i], CURLOPT_RETURNTRANSFER, 1);
		$timeout = 3;
		curl_setopt($conn[$i], CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($conn[$i], CURLOPT_FOLLOWLOCATION, 1);

		if(substr($url, 0, 8) == 'https://') {
			curl_setopt($conn[$i], CURLOPT_SSL_VERIFYPEER, 1);
			curl_setopt($conn[$i], CURLOPT_SSL_VERIFYHOST, 2);
			if(defined('CURLPROTO_HTTPS')) {
				curl_setopt($conn[$i], CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
				curl_setopt($conn[$i], CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
			}
		}

		curl_multi_add_handle($multi_handle, $conn[$i]);
	}
	do {
		$mrc = curl_multi_exec($multi_handle, $active);
	} while ($mrc == CURLM_CALL_MULTI_PERFORM);

	while($active and $mrc == CURLM_OK) {
		if(curl_multi_select($multi_handle) != - 1) {
			do {
				$mrc = curl_multi_exec($multi_handle, $active);
			} while ($mrc == CURLM_CALL_MULTI_PERFORM);
		}
	}
	foreach($urls as $i => $url) {
		$data[$i] = curl_multi_getcontent($conn[$i]);
		curl_multi_remove_handle($multi_handle, $conn[$i]);
		// PHP 8.0+ curl 句柄自动释放，curl_close() 在 8.5 已废弃
		if(PHP_VERSION_ID < 80000) curl_close($conn[$i]);
	}
	return $data;
}

function file_replace_var($filepath, $replace = array(), $pretty = FALSE) {
	$ext = file_ext($filepath);
	if($ext == 'php') {

		if(function_exists('opcache_invalidate')) {
			opcache_invalidate($filepath, true);
		}
		$arr = include $filepath;
		if(!is_array($arr)) $arr = array();
		$arr = array_merge($arr, $replace);
		$s = "<?php\r\nreturn ".var_export($arr, true).";\r\n?>";

		file_backup($filepath);
		$r = file_put_contents_try($filepath, $s);
		if($r === FALSE || $r != strlen($s)) {
			file_backup_restore($filepath);
			xn_log("file_replace_var 写入失败: $filepath (r=" . var_export($r, true) . ", len=" . strlen($s) . ")", 'save_error');
			return FALSE;
		}
		file_backup_unlink($filepath);

		if(function_exists('opcache_invalidate')) {
			opcache_invalidate($filepath, true);
		}
		return $r;
	} elseif($ext == 'js' || $ext == 'json') {
		$s = file_get_contents_try($filepath);
		$arr = xn_json_decode($s);
		if(empty($arr)) return FALSE;
		$arr = array_merge($arr, $replace);
		$s = xn_json_encode($arr, $pretty);
		file_backup($filepath);
		$r = file_put_contents_try($filepath, $s);
		$r != strlen($s) ? file_backup_restore($filepath) : file_backup_unlink($filepath);
		return $r;
	}
}

function file_backname($filepath) {

	$dirname = dirname($filepath);

	$filepre = file_pre($filepath);
	$fileext = file_ext($filepath);
	$s = "$filepre.backup.$fileext";
	return $s;
}

function is_backfile($filepath) {
	return strpos($filepath, '.backup.') !== FALSE;
}

function file_backup($filepath) {
	$backfile = file_backname($filepath);
	if(is_file($backfile)) return TRUE;
	$r = xn_copy($filepath, $backfile);
	clearstatcache();
	return $r && filesize($backfile) == filesize($filepath);
}

function file_backup_restore($filepath) {
	$backfile = file_backname($filepath);
	$r = xn_copy($backfile, $filepath);
	clearstatcache();
	$r && filesize($backfile) == filesize($filepath) && xn_unlink($backfile);
	return $r;
}

function file_backup_unlink($filepath) {
	$backfile = file_backname($filepath);
	$r = xn_unlink($backfile);
	return $r;
}

function file_get_contents_try($file, $times = 3) {
	while($times-- > 0) {
		$fp = fopen($file, 'rb');
		if($fp) {
			$size = filesize($file);
			if($size == 0) return '';
			$s = fread($fp, $size);
			fclose($fp);
			return $s;
		} else {
			sleep(1);
		}
	}
	return FALSE;
}

function file_put_contents_try($file, $s, $times = 3) {
	$dir = dirname($file);
	if(!is_dir($dir)) {
		mkdir($dir, 0777, TRUE);
	}
	while($times-- > 0) {
		$fp = fopen($file, 'wb');
		if($fp AND flock($fp, LOCK_EX)){
			$n = fwrite($fp, $s);
			version_compare(PHP_VERSION, '5.3.2', '>=') AND flock($fp, LOCK_UN);
			fclose($fp);
			clearstatcache();
			return $n;
		} else {
			sleep(1);
		}
	}
	return FALSE;
}

function in_string($s, $str) {
	if(!$s || !$str) return FALSE;
	$s = ",$s,";
	$str = ",$str,";
	return strpos($str, $s) !== FALSE;
}

function move_upload_file($srcfile, $destfile) {

	$r = xn_copy($srcfile, $destfile);
	return $r;
}

function file_ext($filename, $max = 16) {
	$ext = strtolower(substr(strrchr($filename, '.'), 1));
	$ext = xn_urlencode($ext);
	strlen($ext) > $max AND $ext = substr($ext, 0, $max);
	if(!preg_match('#^\w+$#', $ext)) $ext = 'attach';
	return $ext;
}

function file_pre($filename, $max = 32) {
	return substr($filename, 0, strrpos($filename, '.'));
}

function file_name($path) {
	return substr($path, strrpos($path, '/') + 1);
}

function http_url_path() {
	$port = _SERVER('SERVER_PORT');

	$host = _SERVER('HTTP_HOST');
	$https = strtolower(_SERVER('HTTPS', 'off') ?: 'off');
	$proto = strtolower(_SERVER('HTTP_X_FORWARDED_PROTO', '') ?: '');
	$path = substr($_SERVER['PHP_SELF'], 0, strrpos($_SERVER['PHP_SELF'], '/'));
	$http = (($port == 443) || $proto == 'https' || ($https && $https != 'off')) ? 'https' : 'http';
	return  "$http://$host$path/";
}

function xn_url_parse($request_url) {

	$request_url = str_replace('/?', '/', $request_url);
	$arr = parse_url($request_url);

	$q = array_value($arr, 'path');
	$pos = strrpos($q, '/');
	$pos === FALSE && $pos = -1;
	$q = substr($q, $pos + 1);

	$sep = strpos($q, '?') === FALSE ? strpos($q, '&') : FALSE;
	if($sep !== FALSE) {

		$front = substr($q, 0, $sep);
		$behind = substr($q, $sep + 1);
	} else {
		$front = $q;
		$behind = '';
	}

	// 兼容微信等应用复制 URL 自动追加等号：必须在后缀检查之前执行
	$front = rtrim($front, '=');
	if(substr($front, -4) == '.htm') $front = substr($front, 0, -4);

	if(substr($front, -5) == '.html') $front = substr($front, 0, -5);
	$r = $front ? (array)explode('-', $front) : array();

	$arr1 = $arr2 = $arr3 = array();
	$behind AND parse_str($behind, $arr1);

	if(!empty($arr['query'])) {
		parse_str($arr['query'], $arr2);
	} else {
		!empty($_GET) AND $_GET = array();
	}
	$arr3 = $arr1 + $arr2;
	if($arr3) {

		count($arr3) != count($_GET) AND $_GET = $arr3;
	} else {
		!empty($_GET) AND $_GET = array();
	}
	$r += $arr3;

	$_SERVER['REQUEST_URI_NO_PATH'] = substr($_SERVER['REQUEST_URI'], strrpos($_SERVER['REQUEST_URI'], '/') + 1);

	$conf = _SERVER('conf');
	// 用 SCRIPT_NAME 检测 admin，兼容子目录安装
	$_script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
	$is_admin_path = (strpos($_script_name, '/admin') !== false);

	$_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$_has_html_suffix = ($_uri_path && (substr($_uri_path, -5) === '.html' || substr($_uri_path, -4) === '.htm'));
	$_script_dir = dirname($_script_name);
	if($_script_dir === '\\' || $_script_dir === '.') $_script_dir = '/';
	$_uri_rel = $_uri_path;
	if($_script_dir !== '/' && strpos($_uri_path, $_script_dir) === 0) {
		$_uri_rel = substr($_uri_path, strlen($_script_dir));
	}
	$_is_path_format = (substr_count(trim($_uri_rel, '/'), '/') >= 1);
	$_url_rw = intval(isset($conf['url_rewrite_on']) ? $conf['url_rewrite_on'] : 0);
	if(!empty($_url_rw) && ($_url_rw == 2 || $_url_rw == 3 || $_url_rw == 5) && !$is_admin_path && $_is_path_format) {
		$r = xn_url_parse_path_format($_SERVER['REQUEST_URI']) + $r;
	}

	isset($r[0]) AND $r[0] == 'index.php' AND $r[0] = 'index';
	return $r;
}

function xn_url_add_arg($url, $k, $v) {
	$pos = strpos($url, '.htm');
	if($pos === FALSE) {
		// 无 ? 时用 ? 拼接，有 ? 时用 & 拼接
		return strpos($url, '?') === FALSE ? $url."?$k=$v" : $url."&$k=$v";
	} else {
		return substr($url, 0, $pos).'-'.$v.substr($url, $pos);
	}
}

function xn_url_parse_path_format($s) {
	$get = array();
	// 兼容微信等应用复制 URL 自动追加等号
	$s = rtrim($s, '=');
	substr($s, 0, 1) == '/' AND $s = substr($s, 1);

	if(substr($s, -5) == '.html') $s = substr($s, 0, -5);
	if(substr($s, -4) == '.htm') $s = substr($s, 0, -4);
	$arr = explode('/', $s);
	$get = $arr;
	$last = array_pop($arr);
	if(strpos($last, '?') !== FALSE) {
		$get = $arr;
		$arr1 = explode('?', $last);
		parse_str($arr1[1], $arr2);
		$get[] = $arr1[0];
		$get = array_merge($get, $arr2);
	}
	return $get;
}

function xn_url_parse_custom_format($request_uri, $conf) {
	$custom = isset($conf['url_rewrite_custom']) ? $conf['url_rewrite_custom'] : '';
	if(empty($custom)) return array();

	$pos = strpos($request_uri, '?');
	if($pos !== FALSE) {
		$request_uri = substr($request_uri, 0, $pos);
	}

	// 兼容子目录安装：去除安装目录前缀后再匹配
	$_script_dir = dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php');
	if($_script_dir === '\\' || $_script_dir === '.') $_script_dir = '/';
	if($_script_dir !== '/' && strpos($request_uri, $_script_dir) === 0) {
		$request_uri = substr($request_uri, strlen($_script_dir) - 1);
	}

	// 限制使用范围：仅支持 {controller} 和 {id}，{action} 已废弃但代码兼容
	// {id} 正则放宽以支持字符串（如 user-login 中的 login）
	$pattern = $custom;
	$pattern = preg_quote($pattern, '#');
	$pattern = str_replace(preg_quote('{controller}', '#'), '(?P<controller>[a-zA-Z_][a-zA-Z0-9_]*)', $pattern);
	$pattern = str_replace(preg_quote('{action}', '#'), '(?P<action>[a-zA-Z0-9_]+)', $pattern);
	$pattern = str_replace(preg_quote('{id}', '#'), '(?P<id>[a-zA-Z0-9_]+)', $pattern);
	$pattern = str_replace(preg_quote('{page}', '#'), '(?P<page>[0-9]+)', $pattern);
	$pattern = '#' . $pattern . '$#';

	if(!preg_match($pattern, $request_uri, $matches)) {
		return array();
	}

	// 根据 {action} 是否存在智能映射 id 位置，保证生成/解析双向一致
	$_has_action_tag = (strpos($custom, '{action}') !== false);
	$r = array();
	if(!empty($matches['controller'])) $r[0] = $matches['controller'];
	if($_has_action_tag) {
		// 兼容旧格式：{controller}-{action}-{id}.html → r[1]=action, r[2]=id
		if(!empty($matches['action'])) $r[1] = $matches['action'];
		if(!empty($matches['id'])) $r[2] = $matches['id'];
		if(!empty($matches['page']) && !isset($r[2])) $r[2] = $matches['page'];
	} else {
		// 推荐格式：{controller}-{id}.html → r[1]=id
		// 因为 url() 生成 2 段 URL（如 user-login）时把第二段映射到 {id}
		if(!empty($matches['id'])) $r[1] = $matches['id'];
		if(!empty($matches['page']) && !isset($r[1])) $r[1] = $matches['page'];
	}

	return $r;
}

function glob_recursive($pattern, $flags = 0) {
	$files = glob($pattern, $flags);
	foreach(glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir) {
		 $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
	}
	return $files;
}

function rmdir_recusive($dir, $keepdir = 0) {
	if($dir == '/' || $dir == './' || $dir == '../') return FALSE;
	if(!is_dir($dir)) return FALSE;

	substr($dir, -1) != '/' AND $dir .= '/';

	$files = glob($dir.'*');
	foreach(glob($dir.'.*') as $v) {
		if(substr($v, -1) != '.' && substr($v, -2) != '..') $files[] = $v;
	}
	$filearr = $dirarr = array();
	if($files) {
		foreach($files as $file) {
			if(is_dir($file)) {
				$dirarr[] = $file;
			} else {
				$filearr[] = $file;
			}
		}
	}
	if($filearr) {
		foreach($filearr as $file) {
			xn_unlink($file);
		}
	}
	if($dirarr) {
		foreach($dirarr as $file) {
			rmdir_recusive($file);
		}
	}
	if(!$keepdir) xn_rmdir($dir);
	return TRUE;
}

function xn_copy($src, $dest) {
	$r = is_file($src) ? copy($src, $dest) : FALSE;
	return $r;
}

function xn_mkdir($dir, $mod = 0777, $recusive = TRUE) {
	$r = !is_dir($dir) ? mkdir($dir, $mod, $recusive) : FALSE;
	return $r;
}

function xn_rmdir($dir) {
	$r = is_dir($dir) ? rmdir($dir) : FALSE;
	return $r;
}

function xn_unlink($file) {
	$r = is_file($file) ? unlink($file) : FALSE;
	return $r;
}

function xn_filemtime($file) {
	return is_file($file) ? filemtime($file) : 0;
}

function xn_set_dir($id, $dir = './') {

	$id = sprintf("%09d", $id);
	$s1 = substr($id, 0, 3);
	$s2 = substr($id, 3, 3);
	$dir1 = $dir.$s1;
	$dir2 = $dir."$s1/$s2";

	!is_dir($dir1) && mkdir($dir1, 0777);
	!is_dir($dir2) && mkdir($dir2, 0777);
	return "$s1/$s2";
}

function xn_get_dir($id) {
	$id = sprintf("%09d", $id);
	$s1 = substr($id, 0, 3);
	$s2 = substr($id, 3, 3);
	return "$s1/$s2";
}

function copy_recusive($src, $dst) {
	substr($src, -1) == '/' AND $src = substr($src, 0, -1);
	substr($dst, -1) == '/' AND $dst = substr($dst, 0, -1);
	$dir = opendir($src);
	!is_dir($dst) AND mkdir($dst);
	while(FALSE !== ($file = readdir($dir))) {
		if(($file != '.') && ($file != '..')) {
			if(is_dir($src . '/' . $file)) {
				copy_recusive($src.'/'.$file,$dst.'/'.$file);
			}  else {
				xn_copy($src.'/'.$file, $dst.'/'.$file);
			}
		}
	}
	closedir($dir);
}

function xn_rand($n = 16) {
	$str = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
	$len = strlen($str);
	$return = '';
	for($i=0; $i<$n; $i++) {
		$r = mt_rand(1, $len);
		$return .= $str[$r - 1];
	}
	return $return;
}

function xn_is_writable($file) {

	if(PHP_OS != 'WINNT') {
		return is_writable($file);
	} else {

		if(is_file($file)) {
			$fp = fopen($file, 'a+');
			if(!$fp) return FALSE;
			fclose($fp);
			return TRUE;
		} elseif(is_dir($file)) {
			$tmpfile = $file.uniqid().'.tmp';
			$r = touch($tmpfile);
			if(!$r) return FALSE;
			if(!is_file($tmpfile)) return FALSE;
			xn_unlink($tmpfile);
			return TRUE;
		} else {
			return FALSE;
		}
	}
}

function xn_shutdown_handle() {
}

function xn_debug_info() {
	$db = $_SERVER['db'];
	$starttime = $_SERVER['starttime'];
	$s = '';
	if(DEBUG > 1) {
		$s .= '<fieldset class="fieldset small debug break-all">';
		$s .= '<p>Processed Time:'.(microtime(1) - $starttime).'</p>';
		if(IN_CMD) {
			foreach($db->sqls as $sql) {
				$s .= "$sql\r\n";
			}
		} else {
			$s .= "\r\n<ul>\r\n";
			foreach($db->sqls as $sql) {
				$s .= "<li>$sql</li>\r\n";
			}
			$s .= "</ul>\r\n";
			$s .= '_REQUEST:<br>';
			$s .= xn_txt_to_html(print_r($_REQUEST, 1));
			if(!empty($_SESSION)) {
				$s .= '_SESSION:<br>';
				$s .= xn_txt_to_html(print_r($_SESSION, 1));
			}
			$s .= '';
		}
		$s .= '</fieldset>';
	}
	return $s;
}

function base64_decode_file_data($data) {
	if(substr($data, 0, 5) == 'data:') {
		$data = substr($data, strpos($data, ',') + 1);
	}
	$data = base64_decode($data);
	return $data;
}

function http_404() {
	if(function_exists('error_page')) {
		error_page(404);
	} else {
		header('HTTP/1.1 404 Not Found');
		header('Status: 404 Not Found');
		echo '<h1>404 Not Found</h1>';
		exit;
	}
}

function http_status($code) {
	$statuses = array(
		400 => 'Bad Request',
		403 => 'Forbidden',
		404 => 'Not Found',
		500 => 'Internal Server Error',
	);
	$msg = isset($statuses[$code]) ? $statuses[$code] : 'Unknown';
	header('HTTP/1.1 '.$code.' '.$msg);
	header('Status: '.$code.' '.$msg);
}

function http_403() {
	if(function_exists('error_page')) {
		error_page(403);
	} else {
		header('HTTP/1.1 403 Forbidden');
		header('Status: 403 Forbidden');
		echo '<h1>403 Forbidden</h1>';
		exit;
	}
}

function http_location($url, $allow_external = FALSE) {
	// 默认仅允许站内跳转，防开放重定向
	if(!$allow_external) {
		$url_host = parse_url($url, PHP_URL_HOST);
		$current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
		if($url_host && $current_host && $url_host !== $current_host) {
			$url = url('');
		}
	}
	header('Location:'.$url);
	exit;
}

function http_referer() {
	$len = strlen(http_url_path());
	$referer = param('referer');
	empty($referer) AND $referer = (string)_SERVER('HTTP_REFERER');
	if(empty($referer)) $referer = '';
	if($referer && strncmp($referer, http_url_path(), $len) !== 0) {
		$referer = '/';
	}
	$referer2 = substr($referer, $len);
	if(strpos($referer, url('user-login')) !== FALSE || strpos($referer, url('user-logout')) !== FALSE || strpos($referer, url('user-create')) !== FALSE) {
		$referer = '/';
	}

	if(!preg_match('#^\\??[\w\-/]+\.(htm|html)$#', $referer2) && !preg_match('#^[\w\/]*$#', $referer2)) {
		$referer = '/';
	}
	return $referer;
}

function str_push($str, $v, $sep = '_') {
	if(empty($str)) return $v;
	if(strpos($str, $v.$sep) === FALSE) {
		return $str.$sep.$v;
	}
	return $str;
}

function y2f($rmb) {
        $rmb = floor($rmb * 10 * 10);
        return $rmb;
}

function f2y($rmb, $round = 'float') {
        $rmb = floor($rmb * 100) / 10000;
        if($round == 'float') {
                $rmb = number_format($rmb, 2, '.', '');
        } elseif($round == 'round') {
                $rmb = round($rmb);
        } elseif ($round == 'ceil') {
                $rmb = ceil($rmb);
        } elseif ($round == 'floor') {
                $rmb = floor($rmb);
        }
        return $rmb;
}

function _GET($k, $def = NULL) { return isset($_GET[$k]) ? $_GET[$k] : $def; }
function _POST($k, $def = NULL) { return isset($_POST[$k]) ? $_POST[$k] : $def; }
function _COOKIE($k, $def = NULL) { return isset($_COOKIE[$k]) ? $_COOKIE[$k] : $def; }
function _REQUEST($k, $def = NULL) { return isset($_REQUEST[$k]) ? $_REQUEST[$k] : $def; }
function _ENV($k, $def = NULL) { return isset($_ENV[$k]) ? $_ENV[$k] : $def; }
function _SERVER($k, $def = NULL) { return isset($_SERVER[$k]) ? $_SERVER[$k] : $def; }
function GLOBALS($k, $def = NULL) { return isset($GLOBALS[$k]) ? $GLOBALS[$k] : $def; }
function G($k, $def = NULL) { return isset($GLOBALS[$k]) ? $GLOBALS[$k] : $def; }
function _SESSION($k, $def = NULL) {
	global $g_session;
	return isset($_SESSION[$k]) ? $_SESSION[$k] : (isset($g_session[$k]) ? $g_session[$k] : $def);
}

// hook xiunophp_include_after.php

empty($conf) AND $conf = array('db'=>array(), 'cache'=>array(), 'tmp_path'=>'./', 'log_path'=>'./', 'timezone'=>'Asia/Shanghai');
empty($conf['tmp_path']) AND $conf['tmp_path'] = ini_get('upload_tmp_dir');
empty($conf['log_path']) AND $conf['log_path'] = './';

// auth_key 安全检测：禁止使用已知硬编码值或空值运行
$_auth_key = isset($conf['auth_key']) ? $conf['auth_key'] : '';
if($_auth_key === ''
	|| $_auth_key === 'efdkjfjiiiwurjdmclsldow753jsdj438'
	|| strlen($_auth_key) < 32) {
	// 仅在非安装路径、非后台路径下阻断
	$_script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
	if(strpos($_script_name, 'install/') === FALSE && strpos($_script_name, 'admin/') === FALSE) {
		die('auth_key 未配置或强度不足，请运行安装程序（install/）或在 conf.php 中设置 32 位以上随机密钥。');
	}
}

$ip = ip();
$longip = ip2long($ip);
$longip < 0 AND $longip = sprintf("%u", $longip); // fix 32 位 OS 下溢出的问题
$useragent = _SERVER('HTTP_USER_AGENT');

// 语言包变量
!isset($lang) AND $lang = array();

// 全局的错误，非多线程下很方便。
$errno = 0;
$errstr = '';

// error_handle
// register_shutdown_function('xn_shutdown_handle');
DEBUG AND set_error_handler('error_handle', -1);
empty($conf['timezone']) AND $conf['timezone'] = 'Asia/Shanghai';
date_default_timezone_set($conf['timezone']);

// 超级全局变量
!empty($_SERVER['HTTP_X_REWRITE_URL']) AND $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_REWRITE_URL'];
!isset($_SERVER['REQUEST_URI']) AND $_SERVER['REQUEST_URI'] = '';
$_SERVER['REQUEST_URI'] = str_replace('/index.php?', '/', $_SERVER['REQUEST_URI']); // 兼容 iis6
$_REQUEST = array_merge($_COOKIE, $_POST, $_GET, xn_url_parse($_SERVER['REQUEST_URI']));

// IP 地址
!isset($_SERVER['REMOTE_ADDR']) AND $_SERVER['REMOTE_ADDR'] = '';
!isset($_SERVER['SERVER_ADDR']) AND $_SERVER['SERVER_ADDR'] = '';

// $_SERVER['REQUEST_METHOD'] === 'PUT' ? @parse_str(file_get_contents('php://input', false , null, -1 , $_SERVER['CONTENT_LENGTH']), $_PUT) : $_PUT = array(); // 不需要支持 PUT
$ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower(trim($_SERVER['HTTP_X_REQUESTED_WITH'])) == 'xmlhttprequest') || param('ajax');
$method = $_SERVER['REQUEST_METHOD'];



// 保存到超级全局变量，防止冲突被覆盖。
$_SERVER['starttime'] = $starttime;
$_SERVER['time'] = $time;
$_SERVER['ip'] = $ip;
$_SERVER['longip'] = $longip;
$_SERVER['useragent'] = $useragent;
$_SERVER['conf'] = $conf;
$_SERVER['lang'] = $lang;
$_SERVER['errno'] = $errno;
$_SERVER['errstr'] = $errstr;
$_SERVER['method'] = $method;
$_SERVER['ajax'] = $ajax;
$_SERVER['get_magic_quotes_gpc'] = $get_magic_quotes_gpc;




// 初始化 db cache，这里并没有连接，在获取数据的时候会自动连接。
$db = !empty($conf['db']) ? db_new($conf['db']) : NULL;
//$db AND $db->errno AND xn_message(-1, $db->errstr); // 安装的时候检测过了，不必每次都检测。但是要考虑环境移植。

// 缓存初始化通过 CacheService 管理（早期初始化，仅使用 conf 配置）
include APP_PATH.'lib/CacheService.php';
// 加载缓存辅助类（提供 remember/pluginKey/deleteByPrefix 等便捷 API）
include APP_PATH.'lib/CacheHelper.php';
// 加载 AI 调用中台（统一 AI 入口，支持 global/user_key/both 三种模式）
include APP_PATH.'lib/AIService.php';
// 加载轻量事件机制（插件通过 XnEvent::on 注册监听器，核心代码 XnEvent::trigger 触发）
// 零依赖，可在框架启动最早期加载，确保插件在 model_inc_start 等 hook 中即可注册监听器
include APP_PATH.'lib/XnEvent.php';
// 每个请求结束时自动持久化缓存统计，供后台页面读取跨请求累积的命中率
register_shutdown_function(array('CacheHelper', 'persistStats'));
$cache = CacheService::earlyInit();

// 对 key 进行安全保护，Xiuno 专用扩展
!empty($conf) AND (function_exists('xiuno_key') ? ($conf['auth_key'] = xiuno_key()) : NULL);

$_SERVER['db'] = $db;
$_SERVER['cache'] = $cache;

// 全局错误处理器：在框架启动最早期注册，捕获未处理异常/fatal error，避免白屏
// 覆盖 xiunophp 默认的 error_handle，统一由 ErrorHandler 兜底（BizException 200 / 系统 500）
require_once APP_PATH.'lib/ErrorHandler.php';
ErrorHandler::register();

?>