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
			$rlink = $this->real_connect($conf['host'], $conf['user'], $conf['password'], $conf['name'], $conf['charset'], $conf['engine']);
			// 从库连接失败时回退主库，避免读请求全部失败或陷入重试循环
			// real_connect 失败返回 FALSE 并已通过 $this->error() 记录错误信息
			if($rlink === FALSE) {
				xn_log('Slave DB connect failed, fallback to master', 'db_error');
				if($this->wlink === NULL) $this->wlink = $this->connect_master();
				$this->rlink = $this->wlink;
				$this->rconf = $this->conf['master'];
			} else {
				$this->rlink = $rlink;
			}
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

	/**
	 * 带 GROUP BY 的聚合查询
	 * @param string $table 表名（不含前缀）
	 * @param array $cond WHERE 条件
	 * @param array $groupby GROUP BY 字段数组，如 ['uid'] 或 ['uid', 'fid']
	 * @param array $having HAVING 条件，格式同 $cond，如 ['cnt' => ['>' => 5]]
	 * @param array $orderby 排序，如 ['cnt' => -1]
	 * @param int $page 页码
	 * @param int $pagesize 每页数量
	 * @param string $key 返回数组的 key 字段
	 * @param array $col SELECT 字段，如 ['uid', 'COUNT(*) as cnt']，注意聚合字段必须用别名
	 * @return array
	 */
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
			// db_cond_to_sqladd 返回 ' WHERE ...'，需要替换为 ' HAVING ...'
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

	/**
	 * 带 GROUP BY 的单条聚合查询
	 * @param string $table 表名（不含前缀）
	 * @param array $cond WHERE 条件
	 * @param array $groupby GROUP BY 字段数组
	 * @param array $having HAVING 条件
	 * @param array $orderby 排序
	 * @param array $col SELECT 字段（含聚合函数别名）
	 * @return array|null
	 */
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
		// 修复：关闭 rlink 上未消费的 PDOStatement，避免 "Cannot execute queries while other unbuffered queries are active"
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
		$id = intval($this->last_insert_id());
		$stmt->closeCursor();
		return $id;
	}

	public function update(string $table, array $cond, array $data): int {
		list($condadd, $condParams) = db_cond_to_sqladd($cond);
		list($sqladd, $updateParams) = db_array_to_update_sqladd($data);
		if(!$sqladd) return 0;
		$sql = "UPDATE {$this->tablepre}$table SET $sqladd $condadd";
		$params = array_merge($updateParams, $condParams);
		$stmt = $this->prepare($sql, $params);
		if(!$stmt) return 0;
		$n = intval($stmt->rowCount());
		$stmt->closeCursor();
		return $n;
	}

	public function delete(string $table, array $cond): int {
		list($condadd, $condParams) = db_cond_to_sqladd($cond);
		$sql = "DELETE FROM {$this->tablepre}$table $condadd";
		$stmt = $this->prepare($sql, $condParams);
		if(!$stmt) return 0;
		$n = intval($stmt->rowCount());
		$stmt->closeCursor();
		return $n;
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
		// 修复：关闭 wlink 上未消费的 PDOStatement
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
		// 判断写/读操作：写操作用 wlink，读操作用 rlink
		$pre = strtoupper(substr(ltrim($sql), 0, 7));
		$isWrite = ($pre == 'INSERT ' || $pre == 'UPDATE ' || $pre == 'DELETE ' || $pre == 'REPLACE' || strtoupper(substr(ltrim($sql), 0, 6)) == 'CREATE' || strtoupper(substr(ltrim($sql), 0, 4)) == 'DROP' || strtoupper(substr(ltrim($sql), 0, 8)) == 'TRUNCATE');
		// 修复：关闭其他 link 上未消费的 PDOStatement
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
