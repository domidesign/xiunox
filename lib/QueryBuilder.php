<?php
/**
 * 轻量 Query Builder
 *
 * 用法：
 *   db_table('user')->where('uid', $uid)->first();           // SELECT * FROM user WHERE uid=?
 *   db_table('user')->where('gid', '>', 1)->orderBy('uid', 'DESC')->limit(10)->find();
 *   db_table('user')->insert(array('username'=>'foo'));
 *   db_table('user')->where('uid', $uid)->update(array('email'=>'new'));
 *   db_table('user')->where('uid', $uid)->delete();
 *   db_table('user')->where('uid', 'IN', array(1,2,3))->count();
 *
 * 内部生成参数化 SQL，调用 db_pdo_mysql::prepare()/prepare_one() 执行，杜绝 SQL 注入
 * 写操作（insert/update/delete）调用 prepare() 返回 PDOStatement，再取 last_insert_id()/rowCount()
 * @since 1.0.2
 */
class QueryBuilder {
    private $table;
    private $wheres = array();
    private $orders = array();
    private $limit = 0;
    private $offset = 0;
    private $db;

    public function __construct($table, $db = null) {
        $this->table = $table;
        $this->db = $db !== null ? $db : (isset($_SERVER['db']) ? $_SERVER['db'] : null);
    }

    /**
     * WHERE 条件
     * 三参数形式：where('uid', '>', 100) 或 where('uid', 'IN', array(1,2,3))
     * 两参数形式：where('uid', 100) 等价于 where('uid', '=', 100)
     * @return $this
     */
    public function where($col, $op, $val = null) {
        if($val === null) {
            $val = $op;
            $op = '=';
        }
        $this->wheres[] = array('col' => $col, 'op' => $op, 'val' => $val);
        return $this;
    }

    /**
     * ORDER BY
     * @param string $col 字段名
     * @param string $direction ASC|DESC
     * @return $this
     */
    public function orderBy($col, $direction = 'ASC') {
        $this->orders[] = array('col' => $col, 'dir' => $direction);
        return $this;
    }

    /**
     * @param int $n
     * @return $this
     */
    public function limit($n) {
        $this->limit = intval($n);
        return $this;
    }

    /**
     * @param int $n
     * @return $this
     */
    public function offset($n) {
        $this->offset = intval($n);
        return $this;
    }

    /**
     * 构造 WHERE 子句（参数化）
     * 返回 array($sql, $params)
     */
    private function buildWhere() {
        if(empty($this->wheres)) return array('', array());
        $sql = ' WHERE ';
        $params = array();
        foreach($this->wheres as $w) {
            $col = $w['col'];
            $op = strtoupper($w['op']);
            $val = $w['val'];

            // 校验操作符白名单
            $allow_ops = array('=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN');
            if(!in_array($op, $allow_ops)) {
                throw new InvalidArgumentException("Invalid operator: $op");
            }

            if($op === 'IN' || $op === 'NOT IN') {
                if(!is_array($val) || empty($val)) {
                    throw new InvalidArgumentException("$op requires non-empty array");
                }
                $placeholders = implode(',', array_fill(0, count($val), '?'));
                $sql .= "`$col` $op ($placeholders) AND ";
                foreach($val as $v) $params[] = $v;
            } else {
                $sql .= "`$col` $op ? AND ";
                $params[] = $val;
            }
        }
        $sql = substr($sql, 0, -5); // 移除末尾 ' AND '
        return array($sql, $params);
    }

    private function buildOrderBy() {
        if(empty($this->orders)) return '';
        $sql = ' ORDER BY ';
        foreach($this->orders as $o) {
            $dir = strtoupper($o['dir']) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= "`{$o['col']}` $dir, ";
        }
        return substr($sql, 0, -2);
    }

    private function buildLimit() {
        if($this->limit <= 0) return '';
        if($this->offset > 0) return " LIMIT {$this->offset}, {$this->limit}";
        return " LIMIT {$this->limit}";
    }

    /**
     * 单条查询
     * @param string $cols 查询列，默认 '*'
     * @return array|null
     */
    public function first($cols = '*') {
        $this->limit = 1;
        list($whereSql, $params) = $this->buildWhere();
        $sql = "SELECT $cols FROM " . $this->db->tablepre . $this->table . $whereSql . $this->buildOrderBy() . $this->buildLimit();
        return $this->db->prepare_one($sql, $params);
    }

    /**
     * 多条查询
     * @param string $cols 查询列，默认 '*'
     * @return array
     */
    public function find($cols = '*') {
        list($whereSql, $params) = $this->buildWhere();
        $sql = "SELECT $cols FROM " . $this->db->tablepre . $this->table . $whereSql . $this->buildOrderBy() . $this->buildLimit();
        $stmt = $this->db->prepare($sql, $params);
        if(!$stmt) return array();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $arrlist = $stmt->fetchAll();
        return is_array($arrlist) ? $arrlist : array();
    }

    /**
     * COUNT 查询
     * @return int
     */
    public function count() {
        list($whereSql, $params) = $this->buildWhere();
        $sql = "SELECT COUNT(*) AS cnt FROM " . $this->db->tablepre . $this->table . $whereSql;
        $result = $this->db->prepare_one($sql, $params);
        return $result ? intval($result['cnt']) : 0;
    }

    /**
     * 插入
     * @param array $data 字段键值
     * @return int 插入ID（失败返回 0）
     */
    public function insert(array $data) {
        $cols = array();
        $placeholders = array();
        $params = array();
        foreach($data as $k => $v) {
            $cols[] = "`$k`";
            $placeholders[] = '?';
            $params[] = $v;
        }
        $sql = "INSERT INTO " . $this->db->tablepre . $this->table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $this->db->prepare($sql, $params);
        if(!$stmt) return 0;
        return intval($this->db->last_insert_id());
    }

    /**
     * 更新
     * @param array $data 字段键值
     * @return int 影响行数
     */
    public function update(array $data) {
        $setSql = '';
        $params = array();
        foreach($data as $k => $v) {
            $setSql .= "`$k`=?, ";
            $params[] = $v;
        }
        $setSql = substr($setSql, 0, -2);
        list($whereSql, $whereParams) = $this->buildWhere();
        $sql = "UPDATE " . $this->db->tablepre . $this->table . " SET $setSql" . $whereSql;
        $stmt = $this->db->prepare($sql, array_merge($params, $whereParams));
        if(!$stmt) return 0;
        return intval($stmt->rowCount());
    }

    /**
     * 删除
     * @return int 影响行数
     */
    public function delete() {
        list($whereSql, $params) = $this->buildWhere();
        $sql = "DELETE FROM " . $this->db->tablepre . $this->table . $whereSql;
        $stmt = $this->db->prepare($sql, $params);
        if(!$stmt) return 0;
        return intval($stmt->rowCount());
    }
}

/**
 * 快捷函数：创建 QueryBuilder 实例
 * @param string $table 表名（不含前缀）
 * @param mixed $db 数据库实例，默认取 $_SERVER['db']
 * @return QueryBuilder
 */
function db_table($table, $db = null) {
    return new QueryBuilder($table, $db);
}
