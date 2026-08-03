# XIUNOX_Database 数据库抽象层

> **适用人群**：开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 的数据库抽象层采用**接口 + 驱动 + 全局函数**三层架构设计。核心接口 `DatabaseInterface`（`lib/DatabaseInterface.php`）定义了统一的 CRUD 契约，驱动层 `db_pdo_mysql`（`xiunophp/db_pdo_mysql.class.php`）基于 PDO MySQL 实现主从分离与读写分离，顶层全局函数（`xiunophp/db.func.php`）提供便捷的过程式调用入口。

该抽象层具备以下特性：**参数绑定防 SQL 注入**（全部使用 `?` 占位符 + `PDOStatement::bindValue`）、**主从读写分离**（自动选择 `wlink`/`rlink`）、**条件数组语法**（支持 `>`, `<`, `>=`, `<=`, `!=`, `LIKE`, `IN` 等操作符）、**字段名白名单校验**防止字段注入。开发者通过统一的函数调用即可完成跨数据库类型的操作，无需关心底层驱动细节。

## 核心接口

### DatabaseInterface 方法

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `connect()` | — | `bool` | 建立主从连接 |
| `close()` | — | `bool` | 关闭全部连接 |
| `find($table, $cond, $orderby, $page, $pagesize, $key, $col)` | 表名、条件、排序、分页、主键、列 | `array` | 多行查询（分页） |
| `findOne($table, $cond, $orderby, $col)` | 表名、条件、排序、列 | `array\|null` | 单行查询 |
| `find_group(...)` | 含 `$groupby`, `$having` | `array` | GROUP BY 聚合多行查询 |
| `find_one_group(...)` | 同上 | `array\|null` | GROUP BY 聚合单行查询 |
| `insert($table, $data)` | 表名、数据数组 | `int` | 插入，返回新 ID |
| `update($table, $cond, $data)` | 表名、条件、数据 | `int` | 更新，返回影响行数 |
| `delete($table, $cond)` | 表名、条件 | `int` | 删除，返回影响行数 |
| `count($table, $cond)` | 表名、条件 | `int` | 统计行数 |
| `maxid($table, $field, $cond)` | 表名、字段、条件 | `int` | 获取最大 ID |
| `truncate($table)` | 表名 | `int` | 清空表 |
| `exec($sql)` | 原始 SQL | `int` | 执行 DDL/DML |
| `sqlFindOne($sql)` | 原始 SQL | `array\|null` | 原生 SQL 单条查询 |
| `sqlFind($sql, $key)` | 原始 SQL、key 字段 | `array` | 原生 SQL 多条查询 |
| `prepare($sql, $params)` | 带 `?` 占位符 SQL、参数 | `PDOStatement\|false` | 预处理执行（自动选读写连接） |
| `prepare_one($sql, $params)` | 同上 | `array\|null` | 预处理单条查询 |
| `quote($value)` | 值 | `string` | 转义（底层直接用参数绑定，通常无需调用） |
| `table($table)` | 表名（不含前缀） | `string` | 返回带前缀的完整表名 |

## 常用函数 API

全局函数通过 `$_SERVER['db']` 自动获取数据库实例，可传入自定义实例 `$d` 作为最后一个参数。

### 查询函数

```php
// 多行查询（分页 10 条，第 1 页）
db_find('user', ['status' => 1], ['uid' => -1], 1, 10);

// 单行查询
db_find_one('user', ['uid' => 1]);

// 统计行数
db_count('user', ['status' => 1]);

// 获取最大 ID
db_maxid('user', 'uid', ['status' => 1]);

// 原生 SQL 查询（复杂 JOIN/子查询场景）
db_sql_find_one('SELECT u.*, g.name FROM user u LEFT JOIN group g ON u.gid=g.id WHERE u.uid=?', [1]);
```

### 写入函数

```php
// 插入（返回新 ID）
$id = db_insert('user', ['username' => 'demo', 'email' => 'demo@test.com']);

// 替换插入（REPLACE INTO）
db_replace('user', ['uid' => 1, 'username' => 'demo']);

// 更新（支持 + / - 前缀增量操作）
db_update('user', ['uid' => 1], ['username' => 'newname', 'stocks+' => 5]);

// 删除
db_delete('user', ['uid' => 1]);

// 清空表
db_truncate('session');
```

### 条件数组语法

条件数组是抽象层的核心，支持以下格式：

```php
// 1. 简单相等
['uid' => 1, 'status' => 1]
// WHERE `uid`=? AND `status`=?  params: [1, 1]

// 2. 比较操作符（后缀写法）
['uid>' => 100, 'uid<' => 200]
// WHERE `uid`>? AND `uid<?  params: [100, 200]

// 3. OR 数组（IN 查询）
['uid' => [1, 2, 3]]
// WHERE (`uid`=? OR `uid`=? OR `uid`=?)  params: [1, 2, 3]

// 4. 多操作符组合
['username' => ['LIKE' => 'jack'], 'status' => ['>' => 0]]
// WHERE `username` LIKE ? AND `status`>?  params: ['%jack%', 0]
```

### 事务支持

Xiuno X 数据库层**未封装显式事务 API**。需通过原生 PDO 实现事务：

```php
$db = $_SERVER['db'];
$wlink = $db->connect_master();

try {
    $wlink->beginTransaction();

    $db->insert('order', ['uid' => 1, 'amount' => 100]);
    $db->update('user', ['uid' => 1], ['balance-' => 100]);

    $wlink->commit();
} catch (Exception $e) {
    $wlink->rollBack();
}
```

> **注意**：事务内的写操作必须在同一连接上执行。`db_pdo_mysql` 的 `prepare()` 已自动将写操作路由到 `wlink`，但 `db_insert`/`db_update` 等函数封装了额外逻辑，复杂事务建议直接使用 `$db->wlink->prepare()`。

## 代码示例

### 基本查询

```php
// 获取最新 10 条主题
$threads = db_find('thread', ['fid' => 5], ['tid' => -1], 1, 10, 'tid', ['tid', 'subject', 'uid']);

foreach ($threads as $tid => $thread) {
    echo "{$thread['tid']}: {$thread['subject']}\n";
}
```

### 条件查询

```php
// 搜索主题（标题含 "PHP"，属于版块 3 或 5，已审核）
$list = db_find('thread', [
    'fid'    => [3, 5],
    'status' => 1,
    'subject' => ['LIKE' => 'PHP'],
], ['tid' => -1], 1, 20);

// 获取指定用户发帖数（GROUP BY 聚合）
$stats = db_find_group('thread', ['status' => 1], ['uid'], [], ['cnt' => -1], 1, 10, 'uid', ['uid', 'COUNT(*) as cnt']);
```

### 事务操作

```php
function transfer($fromUid, $toUid, $amount) {
    $db = $_SERVER['db'];
    $wlink = $db->connect_master();

    try {
        $wlink->beginTransaction();

        // 扣减转出方余额
        $stmt = $wlink->prepare('UPDATE xn_user SET balance=balance-? WHERE uid=? AND balance>=?');
        $stmt->execute([$amount, $fromUid, $amount]);
        if ($stmt->rowCount() === 0) {
            throw new Exception('余额不足');
        }

        // 增加转入方余额
        $stmt = $wlink->prepare('UPDATE xn_user SET balance=balance+? WHERE uid=?');
        $stmt->execute([$amount, $toUid]);

        // 记录流水
        $stmt = $wlink->prepare('INSERT INTO xn_transfer (from_uid, to_uid, amount, created_at) VALUES (?,?,?,?)');
        $stmt->execute([$fromUid, $toUid, $amount, XN_TIME]);

        $wlink->commit();
        return true;
    } catch (Exception $e) {
        $wlink->rollBack();
        return false;
    }
}
```

## 常见问题

1. **如何防止 SQL 注入？**  
   始终使用 `db_find`/`db_insert`/`db_update`/`db_delete` 等封装函数，它们内部使用 PDO 参数绑定（`?` 占位符），参数值不会拼接到 SQL 中。禁止将用户输入直接拼接到 SQL 字符串后传给 `db_exec` 或 `db_sql_find`。

2. **条件数组的字段名为什么要白名单校验？**  
   `db_cond_to_sqladd` 使用正则 `/^[a-zA-Z_][a-zA-Z0-9_]*$/` 过滤字段名，非法字段会被静默跳过。这是为了防止用户可控字段名（如 `GET` 参数传入的 `orderby`）注入任意 SQL。如果确实需要动态字段，务必在业务层做严格的字段名映射。

3. **主从切换是如何工作的？**  
   `db_pdo_mysql` 构造时读取配置中的 `master` 和 `slaves` 数组。读操作（SELECT）自动路由到随机选取的从库（`rlink`），写操作（INSERT/UPDATE/DELETE/DDL）路由到主库（`wlink`）。从库连接失败时自动回退到主库，不会中断服务。

4. **`db_count` 返回 0 一定代表没有数据吗？**  
   不一定。InnoDB 引擎无 WHERE 条件时会查 `information_schema.tables` 获取估算行数（速度快但不精确）。如果是 InnoDB 表且需要精确计数，建议使用带条件的查询或直接调用 `db_sql_find_one("SELECT COUNT(*) FROM ...")`。

5. **如何处理大结果集？**  
   避免一次性加载全部数据。使用 `db_find` 的分页参数分批获取；需要遍历全表时，配合 `LIMIT`/`OFFSET` 或使用游标。`db_pdo_mysql` 默认开启缓冲查询，超大结果集请直接使用原生 PDO 关闭缓冲模式。