<?php
// XiunoX 版本号唯一来源
// 所有入口文件（index.php / api/v1/index.php / install/index.php）通过 include 此文件获取版本号
// 其他地方（UpgradeService、API 响应、模板等）一律引用 XIUNOX_VERSION 常量，禁止硬编码版本号
!defined('XIUNOX_VERSION') AND define('XIUNOX_VERSION', '1.0.3');
