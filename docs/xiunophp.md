# 框架核心库（xiunophp/）

> Xiuno BBS 自研的轻量 PHP 框架核心库，提供数组、缓存、数据库、图像、加密、邮件、ZIP 等基础能力。本项目不修改此目录。
> 共 23 个文件，分三类：核心入口、函数库（*.func.php）、类库（*.class.php）。

## 目录结构概览

```
xiunophp/
├── 核心入口
│   ├── xiunophp.php            框架主入口
│   └── xiunophp.min.php        压缩版入口（单文件合并）
├── 函数库（*.func.php）
│   ├── array.func.php          数组工具
│   ├── cache.func.php          缓存抽象层
│   ├── db.func.php             数据库抽象层
│   ├── image.func.php          图像处理
│   ├── misc.func.php           杂项工具
│   ├── pingyin.func.php        拼音转换
│   ├── xn_encrypt.func.php     加密
│   ├── xn_html_safe.func.php   HTML 安全过滤
│   ├── xn_send_mail.func.php    邮件发送
│   ├── xn_zip.func.php         ZIP 压缩
│   └── xn_zip_old.func.php     旧版 ZIP（兼容）
├── 类库（*.class.php）
│   ├── cache_file.class.php        文件缓存实现
│   ├── cache_memcached.class.php   Memcached 缓存
│   ├── cache_mysql.class.php       MySQL 缓存
│   ├── cache_redis.class.php       Redis 缓存
│   ├── db_mysql.class.php          MySQL 驱动（旧，已废弃）
│   ├── db_pdo_mongodb.class.php    MongoDB PDO 驱动（占位）
│   ├── db_pdo_mysql.class.php      MySQL PDO 驱动
│   └── db_pdo_sqlite.class.php     SQLite PDO 驱动
└── 其他
    ├── LICENSE.txt             MIT 许可证
    └── README.txt              框架说明
```

## 文件用途说明

### 核心入口

#### xiunophp.php
- **用途**：框架主入口，定义全局常量与变量、加载所有类库与函数库、初始化 db/cache 并完成请求预处理。
- **关键内容**：定义 `DEBUG`/`APP_PATH`/`XIUNOPHP_PATH`/`IN_CMD` 等常量与 `$time`/`$ip`/`$longip`/`$useragent` 等全局变量；依次 include 七个类库（db_mysql、db_pdo_mysql、db_pdo_sqlite、cache_file、cache_memcached、cache_mysql、cache_redis）与六个函数库（db.func、cache.func、image.func、array.func、xn_encrypt.func、misc.func）；通过 `db_new()` 与 `CacheService::earlyInit()` 初始化数据库与缓存；解析 URL 与 `$_REQUEST`；注册 `error_handle`；将运行上下文写入 `$_SERVER` 超级全局变量。

#### xiunophp.min.php
- **用途**：压缩版入口，将主入口及所有类库、函数库合并为单文件，便于无自动加载环境下的单文件部署。

### 函数库（*.func.php）

#### array.func.php
- **用途**：提供数组与二维列表（arrlist）的通用操作工具。
- **关键函数**：
  - `array_value($arr, $key, $default)` — 取数组键值，不存在时返回默认值
  - `array_filter_empty($arr)` — 移除数组中值为空的元素
  - `array_addslashes(&$var)` — 递归对数组或字符串做 addslashes
  - `array_stripslashes(&$var)` — 递归对数组或字符串做 stripslashes
  - `array_htmlspecialchars(&$var)` — 递归转义 HTML 特殊字符
  - `array_trim(&$var)` — 递归去除空白字符
  - `array_diff_value($arr1, $arr2)` — 比较两个数组值差异，以第一个数组为准
  - `arrlist_multisort($arrlist, $col, $asc)` — 对二维数组按指定列排序
  - `arrlist_cond_orderby($arrlist, $cond, $orderby, $page, $pagesize)` — 对二维数组按条件筛选、排序并分页
  - `array_assoc_slice($arrlist, $start, $length)` — 对关联数组按 key 切片
  - `arrlist_key_values($arrlist, $key, $value, $pre)` — 从二维数组提取 key=>value 一维数组
  - `arrlist_values($arrlist, $key)` — 从二维数组提取某一列值的一维数组
  - `arrlist_sum($arrlist, $key)` — 对二维数组某一列求和
  - `arrlist_max($arrlist, $key)` — 求二维数组某列最大值
  - `arrlist_min($arrlist, $key)` — 求二维数组某列最小值
  - `arrlist_change_key($arrlist, $key, $pre)` — 将某列值更换为结果数组的键
  - `arrlist_keep_keys($arrlist, $keys)` — 仅保留二维数组中指定的列
  - `arrlist_chunk($arrlist, $key)` — 按某列值对二维数组分组

#### cache.func.php
- **用途**：缓存抽象层，根据配置创建不同后端实例并提供统一读写接口。
- **关键函数**：
  - `cache_new($cacheconf)` — 根据 type（file/redis/memcached/mysql）创建对应缓存实例
  - `cache_get($k, $c)` — 读取缓存，key 超过 32 字符自动 md5
  - `cache_set($k, $v, $life, $c)` — 写入缓存并设置有效期
  - `cache_delete($k, $c)` — 删除指定缓存
  - `cache_truncate($c)` — 清空全部缓存（慎用，不清理 kv 数据）

#### db.func.php
- **用途**：数据库抽象层，封装表名前缀、SQL 构造、CRUD 与错误处理，支持多驱动切换。
- **关键函数**：
  - `db_new($dbconf)` — 根据 type 创建数据库驱动实例（pdo_mysql/pdo_sqlite/pdo_mongodb）
  - `db_connect($d)` / `db_close($d)` — 测试连接 / 关闭连接
  - `db_sql_find_one($sql, $d)` — 执行 SQL 返回单行
  - `db_sql_find($sql, $key, $d)` — 执行 SQL 返回多行，可按列作键
  - `db_exec($sql, $d)` — 执行写 SQL，返回 insert_id 或影响行数
  - `db_count($table, $cond, $d)` — 统计行数
  - `db_maxid($table, $field, $cond, $d)` — 取某字段最大值
  - `db_create($table, $arr, $d)` / `db_insert($table, $arr, $d)` — 插入记录
  - `db_replace($table, $arr, $d)` — REPLACE 插入
  - `db_update($table, $cond, $update, $d)` — 更新记录
  - `db_delete($table, $cond, $d)` — 删除记录
  - `db_truncate($table, $d)` — 清空表
  - `db_read($table, $cond, $d)` — 按条件读取单行
  - `db_find($table, $cond, $orderby, $page, $pagesize, $key, $col, $d)` — 分页查询多行
  - `db_find_one($table, $cond, $orderby, $col, $d)` — 查询单行
  - `db_find_group($table, $cond, $groupby, $having, $orderby, $page, $pagesize, $key, $col, $d)` — 带 GROUP BY 的分页聚合查询
  - `db_find_one_group(...)` — 带 GROUP BY 的单条聚合查询
  - `db_cond_to_sqladd($cond)` — 将条件数组转换为 WHERE 子句
  - `db_orderby_to_sqladd($orderby)` — 将排序数组转换为 ORDER BY 子句
  - `db_array_to_update_sqladd($arr)` — 将字段数组转换为 UPDATE SET 子句
  - `db_array_to_insert_sqladd($arr)` — 将字段数组转换为 INSERT 字段/值子句
  - `db_check_column_exists($table, $column)` — 检查列是否存在
  - `db_check_table_exists($table)` — 检查表是否存在

#### image.func.php
- **用途**：图像处理工具，提供缩略、裁剪、目录分配与 GD 资源读取。
- **关键函数**：
  - `image_ext($filename)` — 取图片扩展名（不含点）
  - `image_safe_name($filename, $dir)` — 生成安全文件名，重名追加时间戳随机数
  - `image_thumb_name($filename)` — 生成缩略图文件名（追加 _thumb）
  - `image_rand_name($k)` — 生成随机文件名
  - `image_set_dir($id, $dir)` — 按 ID 计算 000/001 形式两级目录并创建
  - `image_get_dir($id)` — 按 ID 取目录路径
  - `image_read_gd($sourcefile)` — 读取图片为 GD 资源，支持 GD/Imagick 兜底
  - `image_thumb($sourcefile, $destfile, $forcedwidth, $forcedheight)` — 按比例缩略并写文件
  - `image_clip($sourcefile, $destfile, $clipx, $clipy, $clipwidth, $clipheight)` — 图片裁剪
  - `image_clip_thumb($sourcefile, $destfile, $forcedwidth, $forcedheight)` — 先裁剪后缩略
  - `image_safe_thumb($sourcefile, $id, $ext, $dir1, $forcedwidth, $forcedheight, $randomname)` — 安全缩略并按 ID 存储返回 fileurl

#### misc.func.php
- **用途**：杂项工具集合，涵盖请求参数、URL 解析、HTTP 客户端、文件操作、分页、日志、JSON、IP 与浏览器检测等。
- **关键函数**：
  - `param($key, $defval, $htmlspecialchars, $addslashes)` — 安全获取请求参数
  - `param_word($key, $len)` — 仅保留单词字符的参数
  - `param_base64($key, $len)` — 解码 base64 参数
  - `param_json($key)` — 解析 JSON 参数
  - `xn_safe_word($s, $len)` — 过滤仅保留 [a-zA-Z0-9_]
  - `lang($key, $arr)` — 语言包翻译并替换占位符
  - `jump($message, $url, $delay)` — 生成跳转链接
  - `xn_json_encode($data, $pretty, $level)` — JSON 编码（兼容低版本 PHP）
  - `xn_json_decode($json)` — JSON 解码（去除 BOM）
  - `xn_urlencode($s)` / `xn_urldecode($s)` — URL 编解码
  - `xn_url_parse($request_url)` — 解析 Xiuno 风格 URL 为路由参数数组
  - `xn_url_parse_path_format($s)` — 路径风格 URL 解析
  - `xn_url_parse_custom_format($request_uri, $conf)` — 自定义伪静态格式反向解析
  - `xn_url_add_arg($url, $k, $v)` — 向 URL 追加参数
  - `pagination($url, $totalnum, $page, $pagesize)` / `pager(...)` — 分页 HTML 生成
  - `humandate($timestamp, $lan)` — 友好时间显示
  - `humannumber($num)` — 友好数字显示
  - `humansize($num)` — 友好文件大小显示
  - `ip()` — 获取客户端 IP（兼容 CDN）
  - `xn_log($s, $file)` — 按月写日志文件
  - `xn_error($no, $str, $return)` — 记录全局错误
  - `error_handle($errno, $errstr, $errfile, $errline)` — 自定义错误处理器
  - `xn_message($code, $message)` — 输出消息并退出
  - `get__browser()` — 检测浏览器类型与设备
  - `is_robot()` — 判断是否为爬虫
  - `http_get($url, $cookie, $timeout, $times)` / `http_post(...)` — HTTP 请求
  - `https_get(...)` / `https_post(...)` — HTTPS 请求（含证书校验）
  - `http_multi_get($urls)` — 多线程抓取
  - `file_replace_var($filepath, $replace, $pretty)` — 将变量写入 PHP/JS/JSON 配置文件（带备份回滚）
  - `file_backup($filepath)` / `file_backup_restore(...)` / `file_backup_unlink(...)` — 文件备份与还原
  - `file_get_contents_try($file, $times)` / `file_put_contents_try($file, $s, $times)` — 带重试与锁的文件读写
  - `file_ext($filename, $max)` / `file_pre($filename)` / `file_name($path)` — 文件名信息提取
  - `http_url_path()` — 取当前站点 URL 路径
  - `http_404()` / `http_403()` / `http_status($code)` / `http_location($url)` / `http_referer()` — HTTP 响应与跳转
  - `glob_recursive($pattern, $flags)` — 递归遍历目录
  - `rmdir_recusive($dir, $keepdir)` — 递归删除目录
  - `xn_copy($src, $dest)` / `xn_mkdir(...)` / `xn_rmdir(...)` / `xn_unlink(...)` — 文件系统操作封装
  - `xn_set_dir($id, $dir)` / `xn_get_dir($id)` — 按 ID 分配目录
  - `xn_rand($n)` — 生成随机字符串
  - `xn_is_writable($file)` — 兼容 Windows 的可写检测
  - `xn_debug_info()` — 输出调试信息
  - `_GET` / `_POST` / `_COOKIE` / `_REQUEST` / `_ENV` / `_SERVER` / `GLOBALS` / `G` / `_SESSION` — 无 Notice 的超级全局变量取值

#### pingyin.func.php
- **用途**：将中文字符串转换为拼音，内置大型 UTF-8 字节到拼音的查找表。
- **关键函数**：
  - `pinyin($s, $sep)` — 将中文按 UTF-8 字节查表转换为拼音，以分隔符连接输出

#### xn_encrypt.func.php
- **用途**：加解密工具，优先使用 xiuno.so 扩展，未安装时回退到 XXTEA 纯 PHP 实现。
- **关键函数**：
  - `xn_key($fromso)` — 获取加密 key，优先从扩展读取，否则取配置 auth_key
  - `xn_safe_key()` — 基于时间窗口的临时安全 key，用于传输校验
  - `xn_encrypt($txt, $key)` — 加密并 base64+urlencode 输出
  - `xn_decrypt($txt, $key)` — 解密
  - `xxtea_encrypt($str, $key)` / `xxtea_decrypt($str, $key)` — XXTEA 算法纯 PHP 实现

#### xn_html_safe.func.php
- **用途**：HTML 安全过滤与净化，内置 SAX 解析器、白名单过滤器及 HTMLPurifier 适配。
- **关键函数**：
  - `xn_build_iframe_safe_regexp()` — 从后台安全设置构建 iframe 白名单正则
  - `xn_html_purify($html, $config)` — 基于 HTMLPurifier（含 HTML5 配置）净化富文本，支持 iframe 白名单
- **关键类**：
  - `XML_HTMLSax3` — HTML SAX 解析器前端类，提供 `set_object`/`set_element_handler`/`set_data_handler`/`parse` 等方法
  - `HTML_White` — 基于白名单的 HTML 过滤器，提供 `parse($doc)` 方法输出过滤后的 XHTML

#### xn_send_mail.func.php
- **用途**：基于 PHPMailer 7.x 的邮件发送，含 SMTP 配置读取、频率限制与模板渲染。
- **关键函数**：
  - `xn_send_mail($smtp, $from_name, $to_email, $subject, $body, $options)` — 通过 SMTP 发送邮件
  - `xn_email_log_write($to_email, $subject, $smtp_host, $status, $error_msg, $ip)` — 记录邮件发送日志
  - `xn_smtp_get()` — 从 conf/smtp.conf.php 随机选取一个 SMTP 配置
  - `xn_email_rate_check($email, $ip)` — 邮件发送频率限制检查
  - `xn_email_rate_record($email, $ip)` — 记录邮件发送频率
  - `xn_email_template($template_key, $vars)` — 渲染邮件模板并替换变量占位符

#### xn_zip.func.php
- **用途**：ZIP 压缩与解压，优先使用 ZipArchive 扩展，未安装时回退到旧版实现。
- **关键函数**：
  - `xn_zip($zipfile, $extdir)` — 将目录打包为 zip 文件
  - `xn_unzip($zipfile, $extdir)` — 解压 zip 文件到目录，自动去除多余层级
  - `xn_dir_to_zip($z, $zippath, $prelen)` — 递归将目录添加到 ZipArchive
  - `xn_zip_unwrap_path($zippath, $dirname)` — 取第一层目录名用于多层打包兼容

#### xn_zip_old.func.php
- **用途**：无 ZipArchive 扩展时的兼容实现，含纯 PHP 的 php_zip 类与打包/解包函数。
- **关键函数**：
  - `xn_unzip_old($zipfile, $destpath)` — 旧版解压（基于 php_zip 类）
  - `xn_zip_old($destzip, $srcpath)` — 旧版打包
  - `xn_mkdir_recusive($path)` — 按路径递归创建目录
  - `xn_mkdir_by_filename($filename)` — 按文件名创建所在目录
- **关键类**：
  - `php_zip` — 纯 PHP ZIP 实现，提供 `zip($dir, $saveName)` 与 `unzip($zipfile, $zipdir)` 方法

### 类库（*.class.php）

#### cache_file.class.php
- **用途**：基于文件系统的缓存实现，使用 md5 分桶两级目录存储。
- **关键方法**：
  - `cache_file::connect()` — 确保缓存目录存在
  - `cache_file::filepath($k)` — 计算缓存文件路径（md5 前两字符作两级目录）
  - `cache_file::set($k, $v, $life)` — 写入缓存文件（含过期时间戳与 JSON 数据）
  - `cache_file::get($k)` — 读取缓存，过期自动删除
  - `cache_file::delete($k)` — 删除缓存文件
  - `cache_file::truncate()` — 清空缓存目录
  - `cache_file::rmdir_recursive($dir)` — 递归删除目录内容

#### cache_memcached.class.php
- **用途**：基于 Memcache/Memcached 扩展的缓存实现，自动适配两种扩展。
- **关键方法**：
  - `cache_memcached::connect()` — 连接 Memcached 服务器（2 秒超时）
  - `cache_memcached::isConnected()` — 检查连接是否可用
  - `cache_memcached::set($k, $v, $life)` — 写入缓存
  - `cache_memcached::get($k)` — 读取缓存
  - `cache_memcached::delete($k)` — 删除缓存
  - `cache_memcached::truncate()` — 清空全部缓存

#### cache_mysql.class.php
- **用途**：基于 MySQL 表的缓存实现，使用 REPLACE/SELECT 实现 kv 存储。
- **关键方法**：
  - `cache_mysql::connect()` — 连接数据库（可复用全局 $db）
  - `cache_mysql::set($k, $v, $life)` — REPLACE 写入缓存记录
  - `cache_mysql::get($k)` — 查询并按过期时间返回
  - `cache_mysql::delete($k)` — 删除缓存记录
  - `cache_mysql::truncate()` — 清空缓存表

#### cache_redis.class.php
- **用途**：基于 Redis 扩展的缓存实现，支持密码认证与库选择。
- **关键方法**：
  - `cache_redis::connect()` — 连接 Redis（2 秒超时，支持 auth 与 select）
  - `cache_redis::isConnected()` — 检查连接是否可用
  - `cache_redis::set($k, $v, $life)` — 写入缓存并设置过期
  - `cache_redis::get($k)` — 读取缓存
  - `cache_redis::delete($k)` — 删除缓存
  - `cache_redis::truncate()` — 清空当前库

#### db_mysql.class.php
- **用途**：基于 mysql_* 函数的 MySQL 驱动（已废弃，PHP 7.0+ 移除，保留仅供参考）。
- **关键方法**：
  - `db_mysql::connect()` / `connect_master()` / `connect_slave()` — 主从连接
  - `db_mysql::sql_find_one($sql)` — 查询单行
  - `db_mysql::sql_find($sql, $key)` — 查询多行
  - `db_mysql::find($table, $cond, $orderby, $page, $pagesize, $key, $col)` — 分页查询
  - `db_mysql::find_one(...)` — 单行查询
  - `db_mysql::query($sql, $link)` — 执行查询
  - `db_mysql::exec($sql, $link)` — 执行写操作（自动 InnoDB 转换）
  - `db_mysql::count($table, $cond)` — 统计行数（InnoDB 空条件走 information_schema）
  - `db_mysql::maxid($table, $field, $cond)` — 取最大 ID
  - `db_mysql::is_support_innodb()` — 检测是否支持 InnoDB

#### db_pdo_mongodb.class.php
- **用途**：MongoDB PDO 驱动占位类，当前为空实现，预留接口扩展。
- **关键方法**：无（空类）

#### db_pdo_mysql.class.php
- **用途**：基于 PDO 的 MySQL 驱动，实现 DatabaseInterface，支持主从、GROUP BY 聚合查询，为生产推荐驱动。
- **关键方法**：
  - `db_pdo_mysql::connect()` / `connect_master()` / `connect_slave()` — 主从连接（含超时设置）
  - `db_pdo_mysql::real_connect(...)` — 创建 PDO 连接
  - `db_pdo_mysql::find($table, $cond, $orderby, $page, $pagesize, $key, $col)` — 分页查询
  - `db_pdo_mysql::find_one(...)` / `findOne(...)` — 单行查询
  - `db_pdo_mysql::find_group(...)` — 带 GROUP BY/HAVING 的分页聚合查询
  - `db_pdo_mysql::find_one_group(...)` — 带 GROUP BY/HAVING 的单条聚合查询
  - `db_pdo_mysql::sql_find_one($sql)` / `sqlFindOne($sql)` — 原生 SQL 单行查询
  - `db_pdo_mysql::sql_find($sql, $key)` / `sqlFind($sql, $key)` — 原生 SQL 多行查询
  - `db_pdo_mysql::exec($sql)` — 执行写操作（INSERT 返回 insert_id）
  - `db_pdo_mysql::insert($table, $data)` / `update(...)` / `delete(...)` — CRUD 封装
  - `db_pdo_mysql::count($table, $cond)` — 统计行数
  - `db_pdo_mysql::maxid($table, $field, $cond)` — 取最大 ID
  - `db_pdo_mysql::lastInsertId()` / `last_insert_id()` — 取最近插入 ID
  - `db_pdo_mysql::quote($value)` — SQL 转义
  - `db_pdo_mysql::table($table)` — 返回带前缀的表名
  - `db_pdo_mysql::truncate($table)` — 清空表
  - `db_pdo_mysql::is_support_innodb()` — 检测 InnoDB 支持

#### db_pdo_sqlite.class.php
- **用途**：基于 PDO 的 SQLite 驱动，支持主从配置与基础 CRUD。
- **关键方法**：
  - `db_pdo_sqlite::connect()` / `connect_master()` / `connect_slave()` — 主从连接
  - `db_pdo_sqlite::real_connect(...)` — 创建 SQLite PDO 连接
  - `db_pdo_sqlite::sql_find_one($sql)` — 单行查询
  - `db_pdo_sqlite::sql_find($sql, $key)` — 多行查询
  - `db_pdo_sqlite::find(...)` — 分页查询
  - `db_pdo_sqlite::find_one(...)` — 单行查询
  - `db_pdo_sqlite::query($sql)` — 执行查询
  - `db_pdo_sqlite::exec($sql)` — 执行写操作
  - `db_pdo_sqlite::count($table, $cond)` — 统计行数
  - `db_pdo_sqlite::maxid($table, $field, $cond)` — 取最大 ID
  - `db_pdo_sqlite::truncate($table)` — 清空表
  - `db_pdo_sqlite::last_insert_id()` — 取最近插入 ID

### 其他

#### LICENSE.txt
- **用途**：MIT 开源许可证，版权归 axiuno@gmail.com（2015），授权自由使用、修改与分发。

#### README.txt
- **用途**：XiunoPHP 4.0 框架说明，阐述其非约束式函数化设计理念与 PHP7/HHVM 友好的编码原则（禁用 eval/autoload/魔术方法等）。
