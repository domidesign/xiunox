<?php

// 此处的 $db 是局部变量，要注意，它返回后在定义为全局变量，可以有多个实例。
function db_new($dbconf) {
	global $errno, $errstr;
	// 数据库初始化，这里并不会产生连接！
	if($dbconf && isset($dbconf['type'])) {
		//print_r($dbconf);
		// 代码不仅仅是给人看的，更重要的是给编译器分析的，不要玩 $db = new $dbclass()，那样不利于优化和 opcache 。
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

// 测试连接
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

// 如果为 INSERT 或者 REPLACE，则返回 mysql_insert_id();
// 如果为 UPDATE 或者 DELETE，则返回 mysql_affected_rows();
// 对于非自增的表，INSERT 后，返回的一直是 0
// 判断是否执行成功: mysql_exec() === FALSE
// 保留 db_exec：复杂 SQL（DDL/CREATE TABLE 等）入口，调用方需自行参数化
function db_exec($sql, $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

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
 * @param string $sql 带 ? 占位符的 SQL
 * @param array $params 绑定参数
 * @param object|null $d 数据库实例
 * @return int|FALSE 成功返回影响行数或插入ID，失败返回 FALSE
 */
function db_exec_prepared($sql, $params = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

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
 * @param string $sql 带 ? 占位符的 SQL
 * @param array $params 绑定参数
 * @param string $key 返回数组的 key 字段
 * @param object|null $d 数据库实例
 * @return array
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
 * @param string $sql 带 ? 占位符的 SQL
 * @param array $params 绑定参数
 * @param object|null $d 数据库实例
 * @return array|FALSE
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

// NO SQL 封装，可以支持 MySQL Marial PG MongoDB
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
	
	// 高效写法，定参有利于编译器优化
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	
	return $d->find($table, $cond, $orderby, $page, $pagesize, $key, $col);
}

function db_find_one($table, $cond = array(), $orderby = array(), $col = array(), $d = NULL) {
	$db = $_SERVER['db'];

	// 高效写法，定参有利于编译器优化
	$d = $d ? $d : $db;
	if(!$d) return FALSE;

	return $d->find_one($table, $cond, $orderby, $col);
}

/**
 * 带 GROUP BY 的聚合查询
 * @param string $table 表名（不含前缀）
 * @param array $cond WHERE 条件
 * @param array $groupby GROUP BY 字段数组，如 ['uid']
 * @param array $having HAVING 条件
 * @param array $orderby 排序
 * @param int $page 页码
 * @param int $pagesize 每页数量
 * @param string $key 返回数组的 key 字段
 * @param array $col SELECT 字段（含聚合函数别名）
 * @return array
 */
function db_find_group($table, $cond = array(), $groupby = array(), $having = array(), $orderby = array(), $page = 1, $pagesize = 10, $key = '', $col = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	return $d->find_group($table, $cond, $groupby, $having, $orderby, $page, $pagesize, $key, $col);
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
function db_find_one_group($table, $cond = array(), $groupby = array(), $having = array(), $orderby = array(), $col = array(), $d = NULL) {
	$db = $_SERVER['db'];
	$d = $d ? $d : $db;
	if(!$d) return FALSE;
	return $d->find_one_group($table, $cond, $groupby, $having, $orderby, $col);
}

// 保存 $db 错误到全局
function db_errno_errstr($r, $d = NULL, $sql = '') {
	global $errno, $errstr;
	if($r === FALSE || ($d && $d->errno)) {
		$errno = $d->errno;
		$errstr = db_errstr_safe($errno, $d->errstr);
		$s = 'SQL:'.$sql."\r\nerrno: ".$errno.", errstr: ".$errstr;
		xn_log($s, 'db_error');
	}
}

// 安全的错误信息
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


//----------------------------------->  表结构和索引相关 end
/*
$cond = array('id'=>123, 'groupid'=>array('>'=>100, 'LIKE'=>'\'jack'));
list($s, $params) = db_cond_to_sqladd($cond);
echo $s;
print_r($params);

WHERE id=? AND groupid>? AND groupid LIKE ? 
$params = [123, 100, "%'jack%"]

// 格式：
array('id'=>123, 'groupid'=>123)
array('id'=>array(1,2,3,4,5))
array('id'=>array('>' => 100, '<' => 200))
array('username'=>array('LIKE' => 'jack'))
*/

/**
 * 将条件数组转换为 SQL WHERE 子句（占位符 ? + 参数数组）
 * @param array $cond 条件数组
 * @return array 返回 array($sql, $params)，$sql 含 ? 占位符，$params 为绑定参数
 */
function db_cond_to_sqladd($cond) {
	$s = '';
	$params = array();
	if(!empty($cond)) {
		$s = ' WHERE ';
		foreach($cond as $k=>$v) {
			if(!is_array($v)) {
				// 解析列名中的比较操作符后缀：>, <, >=, <=, !=, <>
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
						$v1 = "%$v1%";
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


/*
	$arr = array(
		'name'=>'abc',
		'stocks+'=>1,
		'date'=>12345678900,
	)
	list($s, $params) = db_array_to_update_sqladd($arr);
*/

/**
 * 将数据数组转换为 UPDATE SET 子句（占位符 ? + 参数数组）
 * 支持 +/- 前缀的增量更新：'stocks+' => 1 -> `stocks`=`stocks`+?
 * @param array $arr 数据数组
 * @return array 返回 array($sql, $params)
 */
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

/*
	$arr = array(
		'name'=>'abc',
		'date'=>12345678900,
	)
	list($s, $params) = db_array_to_insert_sqladd($arr);
*/

/**
 * 将数据数组转换为 INSERT VALUES 子句（占位符 ? + 参数数组）
 * @param array $arr 数据数组
 * @return array 返回 array($sql, $params)
 */
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

?>