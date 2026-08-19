<?php
/**
 * XIUNOX 管理员密码重置工具（仅限命令行）
 *
 * 用法：
 *   php tool/reset_password.php 用户名                 （交互式输入新密码）
 *   php tool/reset_password.php 用户名 新密码
 *   php tool/reset_password.php 1 新密码               （按 uid 重置）
 *
 * 说明：
 * - 仅 CLI 模式可运行，浏览器直接访问本文件会被拒绝（无后门风险，无需用完删除）
 * - 落库动作与 user_change_password() 完全对齐：
 *   写 bcrypt 新密码、清旧 md5 字段、password_ver+1（使所有旧登录 token 失效）、
 *   撤销该用户全部 API token、清 user 缓存
 * - 详见 docs/userguide/reset-admin-password.md
 */

// 仅允许命令行运行，Web 访问直接拒绝
// REQUEST_METHOD 双保险：Web 服务器（fpm/apache/cgi）或被 include 模拟请求时一并拦截
if (php_sapi_name() !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
	header('HTTP/1.1 403 Forbidden');
	exit('Forbidden. CLI only.');
}

$root = dirname(__DIR__);
echo "=== XIUNOX 密码重置工具 ===\n";

// ---------- 加载数据库配置 ----------
$confFile = $root . '/conf/conf.php';
if (!is_file($confFile)) {
	exit("错误：未找到 conf/conf.php，请在网站根目录下运行本工具。\n");
}
$conf = include $confFile;
if (!is_array($conf) || empty($conf['db']['type'])) {
	exit("错误：conf/conf.php 格式不正确。\n");
}
$dbConf = $conf['db'][$conf['db']['type']]['master'];
if (empty($dbConf['host']) || empty($dbConf['name'])) {
	exit("错误：conf/conf.php 中缺少数据库配置。\n");
}

// ---------- 参数解析 ----------
$argvUser = isset($argv[1]) ? trim($argv[1]) : '';
if ($argvUser === '') {
	exit("用法：php tool/reset_password.php 用户名 [新密码]\n      php tool/reset_password.php uid [新密码]\n");
}

$newPassword = isset($argv[2]) ? (string)$argv[2] : '';
if ($newPassword === '') {
	echo "请输入新密码（至少 6 位，输入时不显示）：";
	$newPassword = trim((string)fgets(STDIN));
	echo "请再次输入新密码：";
	$confirm = trim((string)fgets(STDIN));
	if ($newPassword !== $confirm) {
		exit("错误：两次输入的密码不一致，请重试。\n");
	}
}
if (strlen($newPassword) < 6) {
	exit("错误：新密码至少需要 6 个字符。\n");
}

// ---------- 连接数据库 ----------
try {
	$dsn = "mysql:host={$dbConf['host']};dbname={$dbConf['name']};charset=" . (empty($dbConf['charset']) ? 'utf8mb4' : $dbConf['charset']);
	$db = new PDO($dsn, $dbConf['user'], $dbConf['password'], array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	));
} catch (PDOException $e) {
	exit("错误：数据库连接失败：" . $e->getMessage() . "\n");
}
$tablepre = $dbConf['tablepre'];

// ---------- 查找用户（数字按 uid，否则按用户名/邮箱） ----------
if (ctype_digit($argvUser)) {
	$stmt = $db->prepare("SELECT uid, username, email FROM {$tablepre}user WHERE uid = ?");
	$stmt->execute(array($argvUser));
} else {
	$stmt = $db->prepare("SELECT uid, username, email FROM {$tablepre}user WHERE username = ? OR email = ?");
	$stmt->execute(array($argvUser, $argvUser));
}
$user = $stmt->fetch();
if (empty($user)) {
	exit("错误：找不到用户「{$argvUser}」。请确认用户名/邮箱/uid 后重试。\n");
}
echo "找到用户：{$user['username']}（uid={$user['uid']}，邮箱={$user['email']}）\n";

// ---------- 检测可选列（兼容旧库） ----------
$columns = array();
foreach ($db->query("SHOW COLUMNS FROM {$tablepre}user") as $col) {
	$columns[] = $col['Field'];
}

$update = array(
	'password' => '',
	'salt' => '',
);
if (in_array('password_hash', $columns, true)) {
	$update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
} else {
	// 极旧库无 password_hash 列：退回 md5(md5+salt) 旧格式，登录后会自动升级为 bcrypt
	$salt = substr(md5(uniqid(rand(), true)), 0, 8);
	$update['salt'] = $salt;
	$update['password'] = md5(md5($newPassword) . $salt);
}

// ---------- 更新密码 ----------
$sets = array();
$params = array();
foreach ($update as $k => $v) {
	$sets[] = "$k = ?";
	$params[] = $v;
}
if (in_array('password_ver', $columns, true)) {
	$sets[] = "password_ver = password_ver + 1";
}
$params[] = $user['uid'];
try {
	$stmt = $db->prepare("UPDATE {$tablepre}user SET " . implode(', ', $sets) . " WHERE uid = ?");
	$stmt->execute($params);
} catch (PDOException $e) {
	exit("错误：密码更新失败：" . $e->getMessage() . "\n");
}

// ---------- 撤销该用户全部 API token（对齐 user_change_password） ----------
try {
	$db->exec("DELETE FROM {$tablepre}api_token WHERE uid = " . (int)$user['uid']);
} catch (PDOException $e) {
	// 表不存在（旧库未升级）时忽略
}

// ---------- 清理 user 缓存（MySQL 缓存型站点缓存存于 bbs_cache 表） ----------
try {
	$db->exec("DELETE FROM {$tablepre}cache WHERE k = 'user-" . (int)$user['uid'] . "'");
} catch (PDOException $e) {
	// 表不存在时忽略
}

echo "密码重置成功！\n";
echo "现在可以用新密码登录了。安全提醒：\n";
echo " 1. 登录后请尽快在「个人中心 → 密码修改」再改一次为自己专属的密码；\n";
echo " 2. 本次重置已使该账号在其他设备/所有 API 令牌全部失效，属正常现象；\n";
echo " 3. 如果站点启用了 Redis/文件缓存且仍无法登录，请在后台「其他 → 缓存」清空一次缓存。\n";
