<?php

return array(
	'installed_tips' => '程序已经安装过了，如需重新安装，请删除 conf/conf.php 和 install/install.lock ！',
	'please_set_conf_file_writable' => '请设置 conf/conf.php 文件为可写！',
	'evn_not_support_php_mysql' => '当前 PHP 环境不支持 mysql 和 pdo_mysql，无法继续安装。',
	'dbhost_is_empty' => '数据库主机不能为空',
	'dbname_is_empty' => '数据库名不能为空',
	'dbuser_is_empty' => '用户名不能为空',
	'adminuser_is_empty' => '管理员用户名不能为空',
	'adminpass_is_empty' => '管理员密码不能为空',
	'conguralation_installed' => '恭喜，安装成功！为了安全请删除 install 目录。',

	'step_1_title' => '一、安装环境检测',
	'runtime_env_check' => '网站运行环境检测',
	'required' => '需要',
	'current' => '当前',
	'check_result' => '检测结果',
	'passed' => '通过',
	'not_passed' => '通过',
	'not_the_best' => '不是最理想的环境',
	'dir_writable_check' => '目录 / 文件 权限检测',
	'writable' => '可写',
	'unwritable' => '不可写',
	'check_again' => '重新检测',
	'os' => '操作系统',
	'unix_like' => '类 UNIX',
	'php_version' => 'PHP 版本',
	'mysql_version' => 'MySQL 版本',
	'mysql_version_pending' => '连接数据库后检测',
	'mysql_version_too_low' => 'MySQL 版本过低，无法安装',

	'step_2_title' => '二、数据库设置',
	'db_type' => '数据库类型',
	'db_engine' => '数据库引擎',
	'db_host' => '数据库服务器',
	'db_name' => '数据库名',
	'db_user' => '数据库用户名',
	'db_pass' => '数据库密码',
	'db_tablepre' => '表前缀',
	'db_tablepre_tip' => '只允许字母、数字和下划线，必须以字母开头，默认 bbs_',
	'db_tablepre_invalid' => '表前缀格式不正确，只允许字母、数字和下划线，且必须以字母开头',
	'step_3_title' => '三、管理员信息',
	'admin_email' => '管理员邮箱',
	'admin_username' => '管理员用户名',
	'admin_pw' => '管理员密码',
	'installing_about_moment' => '正在安装，大概需要一分钟左右',
	'license_title' => 'XIUNOX 授权协议',
	'license_content' => '感谢您选择 XIUNOX，它是一款现代化、轻量、稳定的论坛系统。基于 Bootstrap 5.3 + htmx 4 架构，全面支持移动端浏览器；后端采用 PHP 8.0+，支持 InnoDB 引擎和 utf8mb4 字符集，对第三方类库依赖极少，方便部署和维护，是一个非常好的二次开发基石。

XIUNOX 采用 MIT 协议发布，您可以自由修改、派生版本、商用而不用担心任何法律风险（修改后应保留原来的版权信息）。',
	'license_date' => '发布时间：2026年',
	'agree_license_to_continue' => '同意协议继续安装',
	'license_read_hint' => '请下滑阅读完整协议内容',
	'license_countdown_text' => '请认真阅读协议内容',
	'license_ready' => '阅读完毕，请勾选同意',
	'install_title' => 'XIUNOX 安装向导',
	'install_guide' => '安装向导',

	'function_check' => '函数依赖检查',
	'supported' => '支持',
	'not_supported' => '不支持',
	'function_glob_not_exists' => '后台插件功能依赖该函数，请配置 php.ini，设置 disabled_functions = ; 去除对该函数的限制',
	'function_gzcompress_not_exists' => '后台插件功能依赖该函数，Linux 主机请添加编译参数 --with-zlib，Windows 主机请配置 php.ini 注释掉 extension=php_zlib.dll',
	'function_mb_substr_not_exists' => '系统依赖该函数，Linux 主机请添加编译参数 --with-mbstring，Windows 主机请配置 php.ini 注释掉 extension=php_mbstring.dll',

	'optional' => '可选',
	'recommended' => '推荐',

	'step_lang' => '选择语言',
	'step_license' => '许可协议',
	'step_env' => '环境检测',
	'step_config' => '数据库配置',
	'install_success' => '安装成功！',
	'visit_site' => '访问站点',

	'admin_username_is_empty' => '管理员用户名不能为空',
	'admin_password_is_empty' => '管理员密码不能为空',
	'admin_password_too_short' => '管理员密码至少6个字符',
	'admin_email_is_empty' => '管理员邮箱不能为空',
	'admin_email_invalid' => '管理员邮箱格式不正确',

	// hook lang_zh_cn_bbs_install.php

	'db_already_exists_confirm' => '数据库中已存在 Xiuno BBS 的表，继续安装将清空所有数据！点击确定继续，取消返回修改。',

	'db_connect_denied' => '数据库连接被拒绝，请检查用户名和密码是否正确',
	'db_not_found' => '数据库不存在，请先创建数据库或检查数据库名是否正确',
	'db_host_unreachable' => '无法连接数据库服务器，请检查主机地址和端口是否正确',
	'db_connect_failed' => '数据库连接失败',

	// ========== 安装界面扩展 ==========
	'install_choose_language' => '选择语言',
	'install_next_step' => '下一步',
	'install_loading' => '加载中...',
	'install_installing' => '安装中...',
	'install_submit' => '提交',
	'install_request_failed' => '请求失败：',
	'install_license_not_found' => 'LICENSE 文件未找到。',
	'install_meta_author' => 'XiunoBBS 4.0',

	// ========== 安装成功安全提示 ==========
	'install_security_tips' => '安全提示',
	'install_tip_delete_install_dir' => '删除安装目录',
	'install_tip_delete_install_dir_desc' => '系统已自动锁定安装程序，但仍建议手动删除',
	'install_tip_change_admin_pw' => '修改管理员密码',
	'install_tip_change_admin_pw_desc' => '登录后请及时修改管理员密码为强密码',
	'install_tip_configure_https' => '配置 HTTPS',
	'install_tip_configure_https_desc' => '建议为站点配置 SSL 证书，启用 HTTPS',
	'install_tip_change_db_pw' => '修改数据库密码',
	'install_tip_change_db_pw_desc' => '如果使用了默认数据库密码，请及时修改',

);

?>
