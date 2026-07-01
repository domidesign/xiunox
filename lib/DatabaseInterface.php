<?php

/**
 * DatabaseInterface 数据库抽象接口
 * @since 1.0.2
 */
interface DatabaseInterface {

    /**
     * 连接数据库
     * @return bool
     */
    public function connect(): bool;

    /**
     * 关闭连接
     * @return bool
     */
    public function close(): bool;

    /**
     * 查询多行
     * @param string $table 表名（不含前缀）
     * @param array $cond 条件
     * @param array $orderby 排序
     * @param int $page 页码
     * @param int $pagesize 每页数量
     * @param string $key 主键字段
     * @param array $col 查询列
     * @return array
     */
    public function find(string $table, array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 10, string $key = '', array $col = []): array;

    /**
     * 查询单行
     * @param string $table 表名（不含前缀）
     * @param array $cond 条件
     * @param array $orderby 排序
     * @param array $col 查询列
     * @return array|null
     */
    public function findOne(string $table, array $cond = [], array $orderby = [], array $col = []): ?array;

    /**
     * 带 GROUP BY 的聚合查询（多行）
     * @param string $table 表名（不含前缀）
     * @param array $cond WHERE 条件
     * @param array $groupby GROUP BY 字段数组，如 ['uid']
     * @param array $having HAVING 条件，格式同 $cond，如 ['cnt' => ['>' => 5]]
     * @param array $orderby 排序，如 ['cnt' => -1]
     * @param int $page 页码
     * @param int $pagesize 每页数量
     * @param string $key 返回数组的 key 字段
     * @param array $col SELECT 字段，聚合字段必须用别名，如 ['uid', 'COUNT(*) as cnt']
     * @return array
     */
    public function find_group(string $table, array $cond = [], array $groupby = [], array $having = [], array $orderby = [], int $page = 1, int $pagesize = 10, string $key = '', array $col = []): array;

    /**
     * 带 GROUP BY 的聚合查询（单行）
     * @param string $table 表名（不含前缀）
     * @param array $cond WHERE 条件
     * @param array $groupby GROUP BY 字段数组
     * @param array $having HAVING 条件
     * @param array $orderby 排序
     * @param array $col SELECT 字段（含聚合函数别名）
     * @return array|null
     */
    public function find_one_group(string $table, array $cond = [], array $groupby = [], array $having = [], array $orderby = [], array $col = []): ?array;

    /**
     * 执行 SQL（INSERT/UPDATE/DELETE/CREATE 等）
     * @param string $sql
     * @return int 影响行数或插入ID
     */
    public function exec(string $sql): int;

    /**
     * 插入数据
     * @param string $table 表名（不含前缀）
     * @param array $data 数据
     * @return int 插入ID
     */
    public function insert(string $table, array $data): int;

    /**
     * 更新数据
     * @param string $table 表名（不含前缀）
     * @param array $cond 条件
     * @param array $data 数据
     * @return int 影响行数
     */
    public function update(string $table, array $cond, array $data): int;

    /**
     * 删除数据
     * @param string $table 表名（不含前缀）
     * @param array $cond 条件
     * @return int 影响行数
     */
    public function delete(string $table, array $cond): int;

    /**
     * 统计行数
     * @param string $table 表名（不含前缀）
     * @param array $cond 条件
     * @return int
     */
    public function count(string $table, array $cond = []): int;

    /**
     * 获取最大ID
     * @param string $table 表名（不含前缀）
     * @param string $field 字段名
     * @param array $cond 条件
     * @return int
     */
    public function maxid(string $table, string $field, array $cond = []): int;

    /**
     * 获取最后插入ID
     * @return int
     */
    public function lastInsertId(): int;

    /**
     * 转义值
     * @param string $value
     * @return string
     */
    public function quote(string $value): string;

    /**
     * 执行原始 SQL 查询返回单行
     * @param string $sql
     * @return array|null
     */
    public function sqlFindOne(string $sql): ?array;

    /**
     * 执行原始 SQL 查询返回多行
     * @param string $sql
     * @param string|null $key
     * @return array
     */
    public function sqlFind(string $sql, ?string $key = null): array;

    /**
     * PDO 预处理执行（写操作/读操作通用）
     * 自动选择 wlink（INSERT/UPDATE/DELETE/REPLACE）或 rlink（SELECT）
     * @param string $sql 带 ? 占位符的 SQL
     * @param array $params 绑定参数
     * @return mixed PDOStatement|FALSE
     */
    public function prepare(string $sql, array $params = array());

    /**
     * PDO 预处理查询单条
     * @param string $sql 带 ? 占位符的 SQL
     * @param array $params 绑定参数
     * @return array|null
     */
    public function prepare_one(string $sql, array $params = array());

    /**
     * 获取带前缀的完整表名
     * @param string $table 表名（不含前缀）
     * @return string 完整表名
     */
    public function table(string $table): string;

    /**
     * 清空表
     * @param string $table 表名（不含前缀）
     * @return int
     */
    public function truncate(string $table): int;
}
