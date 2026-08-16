<?php


class db_pdo_sqlite {
	public $conf = array(); // 配置，可以支持主从
	public $wlink = NULL;  // 写连接
	public $rlink = NULL;  // 读连接
	public $link = NULL;   // 最后一次使用的连接
	public $errno = 0;
	public $errstr = '';
	public $tablepre = '';
	
	public function __construct($conf) {
		$this->conf = $conf;
		$this->tablepre = $conf['master']['tablepre'];
	}
	
	// 根据配置文件连接
	public function connect() {
		$this->wlink = $this->connect_master();
		$this->rlink = $this->connect_slave();
		return $this->wlink && $this->rlink;
	}
	
	// 连接写服务器
	public function connect_master() {
		if($this->wlink) return $this->wlink;
		$conf = $this->conf['master'];
		$this->wlink = $this->real_connect($conf['host'], $conf['user'], $conf['password'], $conf['name'], $conf['charset'], $conf['engine']);
		return $this->wlink;
	}
	
	// 连接从服务器，如果有多台，则随机挑选一台，如果为空，则与主服务器一致。
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
			$link = new PDO($sqlitedb, $attr);//连接sqlite
			//new PDO($sqlitedb,'','',$attr);//连接sqlite
		} catch (Exception $e) {
			$this->error($e->getCode(), (function_exists('lang') && !empty($_SERVER['lang']) ? lang('db_connect_server_failed_detail') : '连接数据库服务器失败:').$e->getMessage());
			return FALSE;
	        }
	        //$link->setFetchMode(PDO::FETCH_ASSOC);
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
		$n = $link->exec($sql); // 返回受到影响的行，插入的 id ?
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
	
	
	// ----------> 4.0 增加的方法
	// $index = array('uid'=>1, 'dateline'=>-1)
	/*
	public function index_create($table, $index) {
		$keys = implode(', ', array_keys($index));
		$keyname = implode('', array_keys($index));
		return $this->exec("CREATE INDEX {$table}_$keyname ON $table($keys)", $this->link);
	}
	
	public function index_drop($table, $index) {
		$keys = implode(', ', array_keys($index));
		$keyname = implode('', array_keys($index));
		return $this->exec("DROP INDEX {$table}_$keyname", $this->link);
	}
	
	// 创建表
	public function table_create($table, $ddls, $engineer = '') {
		$sql = "CREATE TABLE IF NOT EXISTS $table (\n";
		$sep = '';
		foreach($ddls as $ddl) {
			$sqladd = $this->ddl_to_sqladd($ddl);
			$sql .= $sep.$sqladd;
			$sep = ",\n";
		}
		$sql .= ")";
		return $this->exec($sql, $this->wlink);
	}

	// DROP table
	public function table_drop($table) {
		$sql = "DROP TABLE IF EXISTS $table";
		return $this->exec($sql, $this->wlink);
	}
	
	public function table_column_add($table, $ddl) {
		$sqladd = $this->ddl_to_sqladd($ddl);
		$sql = "ALTER TABLE $table ADD COLUMN $sqladd;";
		return $this->exec($sql, $this->wlink);
	}
	
	private function ddl_to_sqladd($ddl) {
		$colname = $ddl[0];
		$colattr = $ddl[1];
		$default = strpos($colattr, 'int') !== FALSE ? "'0'" : "''";
		$sqladd = "$colname $colattr NOT NULL DEFAULT $default;";
		return $sqladd;
	}
	
	// sqlite 不支持 drop column
	public function table_column_drop($table, $colname) {
		return TRUE;
	}
	*/
	
	public function version() {
		$r = $this->sql_find_one("SELECT VERSION() AS v");
		return $r['v'];
	}
	
	// 设置错误。
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

?>