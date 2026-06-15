<?php

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
			// PHP 8.5 移除了 PDO::ATTR_USE_BUFFERED_QUERY，MySQL 默认就是缓冲查询
			if(defined('PDO::ATTR_USE_BUFFERED_QUERY')) {
				$attr[PDO::ATTR_USE_BUFFERED_QUERY] = true;
			}
			$link = new PDO("mysql:host=$host;port=$port;dbname=$name", $user, $password, $attr);
		} catch (Exception $e) {
			$this->error($e->getCode(), '连接数据库服务器失败:'.$e->getMessage());
			return FALSE;
		}
		$charset AND $link->query("SET names $charset, sql_mode=''");
		return $link;
	}

	public function find(string $table, array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 10, string $key = '', array $col = []): array {
		$page = max(1, $page);
		$cond = db_cond_to_sqladd($cond);
		$orderby = db_orderby_to_sqladd($orderby);
		$offset = ($page - 1) * $pagesize;
		$cols = $col ? implode(',', $col) : '*';
		$r = $this->sql_find("SELECT $cols FROM {$this->tablepre}$table $cond$orderby LIMIT $offset,$pagesize", $key);
		return is_array($r) ? $r : [];
	}

	public function find_one($table, $cond = array(), $orderby = array(), $col = array()): ?array {
		$cond = db_cond_to_sqladd($cond);
		$orderby = db_orderby_to_sqladd($orderby);
		$cols = $col ? implode(',', $col) : '*';
		return $this->sql_find_one("SELECT $cols FROM {$this->tablepre}$table $cond$orderby LIMIT 1");
	}

	public function findOne(string $table, array $cond = [], array $orderby = [], array $col = []): ?array {
		return $this->find_one($table, $cond, $orderby, $col);
	}

	public function sql_find_one($sql): ?array {
		$query = $this->query($sql);
		if(!$query) return NULL;
		$query->setFetchMode(PDO::FETCH_ASSOC);
		$r = $query->fetch();
		return $r === FALSE ? NULL : $r;
	}

	public function sql_find($sql, $key = NULL): array {
		$query = $this->query($sql);
		if(!$query) return [];
		$query->setFetchMode(PDO::FETCH_ASSOC);
		$arrlist = $query->fetchAll();
		$key AND $arrlist = arrlist_change_key($arrlist, $key);
		return is_array($arrlist) ? $arrlist : [];
	}

	public function exec(string $sql): int {
		$this->errno = 0;
		$this->errstr = '';
		if(!$this->wlink && !$this->connect_master()) return 0;
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
			$t3 = substr($t2 - $t1, 0, 6);
		} catch (Exception $e) {
			$this->error($e->getCode(), $e->getMessage());
			return 0;
		}
		if(count($this->sqls) < 1000) $this->sqls[] = "[$t3]".$sql;

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
		$sqladd = db_array_to_insert_sqladd($data);
		if(!$sqladd) return 0;
		return $this->exec("INSERT INTO {$this->tablepre}$table $sqladd");
	}

	public function update(string $table, array $cond, array $data): int {
		$condadd = db_cond_to_sqladd($cond);
		$sqladd = db_array_to_update_sqladd($data);
		if(!$sqladd) return 0;
		return $this->exec("UPDATE {$this->tablepre}$table SET $sqladd $condadd");
	}

	public function delete(string $table, array $cond): int {
		$condadd = db_cond_to_sqladd($cond);
		return $this->exec("DELETE FROM {$this->tablepre}$table $condadd");
	}

	public function count(string $table, array $cond = []): int {
		$this->connect_slave();
		if(empty($cond) && $this->rconf['engine'] == 'innodb') {
			$dbname = $this->rconf['name'];
			$sql = "SELECT TABLE_ROWS as num FROM information_schema.tables WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table'";
		} else {
			$cond = db_cond_to_sqladd($cond);
			$sql = "SELECT COUNT(*) AS num FROM `$table` $cond";
		}
		$arr = $this->sql_find_one($sql);
		return !empty($arr) ? intval($arr['num']) : 0;
	}

	public function maxid(string $table, string $field, array $cond = []): int {
		$sqladd = db_cond_to_sqladd($cond);
		$sql = "SELECT MAX($field) AS maxid FROM `$table` $sqladd";
		$arr = $this->sql_find_one($sql);
		return !empty($arr) ? intval($arr['maxid']) : 0;
	}

	public function lastInsertId(): int {
		return intval($this->last_insert_id());
	}

	public function quote(string $value): string {
		if(!$this->rlink && !$this->connect_slave()) return addslashes((string)$value);
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
		$link = $this->link = $this->rlink;
		try {
			$t1 = microtime(1);
			$query = $link->query($sql);
			$t2 = microtime(1);
			$t3 = substr($t2 - $t1, 0, 6);
		} catch (Exception $e) {
			$this->error($e->getCode(), $e->getMessage());
			return FALSE;
		}
		if($query === FALSE) $this->error();
		if(count($this->sqls) < 1000) $this->sqls[] = substr($t2 - $t1, 0, 6).' '.$sql;
		return $query;
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
