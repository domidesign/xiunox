<?php

/**
 * Xiuno BBS API v1 入口文件
 *
 * 当请求直接到达 /api/v1/ 目录时（而非通过根 index.php 路由），
 * 此文件负责完整的引导流程。
 */

!defined('API_MODE') AND define('API_MODE', true);

!defined('DEBUG') AND define('DEBUG', 1);
!defined('APP_PATH') AND define('APP_PATH', dirname(__DIR__, 2) . '/');
!defined('XIUNOPHP_PATH') AND define('XIUNOPHP_PATH', APP_PATH . 'xiunophp/');

// 加载配置文件
$conf = (@include APP_PATH . 'conf/conf.php') OR exit(json_encode(['code' => 500, 'msg' => 'Config not found', 'data' => null]));

// 兼容配置项
!isset($conf['user_create_on']) AND $conf['user_create_on'] = 1;
!isset($conf['cache_disable']) AND $conf['cache_disable'] = 0;
$conf['version'] = 'X1.0.1';

// 转换为绝对路径
substr($conf['log_path'], 0, 2) == './' AND $conf['log_path'] = APP_PATH . $conf['log_path'];
substr($conf['tmp_path'], 0, 2) == './' AND $conf['tmp_path'] = APP_PATH . $conf['tmp_path'];
substr($conf['upload_path'], 0, 2) == './' AND $conf['upload_path'] = APP_PATH . $conf['upload_path'];

$_SERVER['conf'] = $conf;

include APP_PATH . 'xiunophp/xiunophp.php';

// 加载模型函数
include APP_PATH . 'model/plugin.func.php';
include _include(APP_PATH . 'model.inc.php');

// 引导 API 路由
include __DIR__ . '/bootstrap.php';
