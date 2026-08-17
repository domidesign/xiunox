<?php
// XiunoX 版本号唯一来源
// 所有入口文件（index.php / api/v1/index.php / install/index.php）通过 include 此文件获取版本号
// 其他地方（UpgradeService、API 响应、模板等）一律引用 XIUNOX_VERSION 常量，禁止硬编码版本号
!defined('XIUNOX_VERSION') AND define('XIUNOX_VERSION', '1.1.6');

// 在线升级源配置（Gitee 仓库）
// 修改 owner/repo 为你自己的仓库信息，私有仓库需填 XIUNOX_GITEE_TOKEN
!defined('XIUNOX_GITEE_OWNER') AND define('XIUNOX_GITEE_OWNER', 'poisonkid');
!defined('XIUNOX_GITEE_REPO') AND define('XIUNOX_GITEE_REPO', 'xiunoxbbs');
!defined('XIUNOX_GITEE_TOKEN') AND define('XIUNOX_GITEE_TOKEN', '');  // 私有仓库访问令牌，公开仓库留空
