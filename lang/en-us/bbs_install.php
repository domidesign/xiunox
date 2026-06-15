<?php

return array(
	'installed_tips' => 'Already installed. To reinstall, please delete conf/conf.php and install/install.lock!',
	'please_set_conf_file_writable' => 'Please set the conf/conf.php file to write!',
	'evn_not_support_php_mysql' => 'The current PHP environment does not support mysql and pdo_mysql driver, can not continue to install.',
	'dbhost_is_empty' => 'Database host cannot be empty',
	'dbname_is_empty' => 'Database name cannot be empty',
	'dbuser_is_empty' => 'User name cannot be empty',
	'adminuser_is_empty' => 'Administrator user name can not be empty',
	'adminpass_is_empty' => 'Administrator password can not be empty',
	'conguralation_installed' => 'Congratulations, installation success, please remove install directory for security.',
	
	'step_1_title' => '1. Environmental Check',
	'runtime_env_check' => 'Runtime environment detection',
	'required' => 'Required',
	'current' => 'Current',
	'check_result' => 'Check Result',
	'passed' => 'Passed',
	'not_passed' => 'Not Passed',
	'not_the_best' => 'Not the ideal environment',
	'dir_writable_check' => 'Directory / file permissions',
	'writable' => 'Writable',
	'unwritable' => 'Unwritable',
	'check_again' => 'Check Again',
	'os' => 'OS',
	'unix_like' => 'UNIX Like',
	'php_version' => 'PHP Version',
	
	'step_2_title' => '2. Database settings',
	'db_type' => 'Database type',
	'db_engine' => 'Database Engine',
	'db_host' => 'Database Host',
	'db_name' => 'Database Name',
	'db_user' => 'Database User',
	'db_pass' => 'Database Password',
	'step_3_title' => '3. Administrator information',
	'admin_email' => 'Administrator Email',
	'admin_username' => 'Administrator Username',
	'admin_pw' => 'Administrator Password',
	'installing_about_moment' => 'Installing, it takes about a minute or so',
	'license_title' => 'XIUNOX License Agreement',
	'license_content' => 'Thank you for choosing XIUNOX, a modern, lightweight and stable forum system. Built on Bootstrap 5.3 + htmx 4 architecture with full mobile browser support. The backend uses PHP 8.0+ with InnoDB engine and utf8mb4 charset. Minimal third-party dependencies make it easy to deploy and maintain, and an excellent foundation for secondary development.

XIUNOX is released under the MIT license. You are free to modify, create derivative works, and use it commercially without any legal concerns (original copyright information should be retained after modification).',
	'license_date' => 'Release date: 2026',
	'agree_license_to_continue' => 'Agree to the license and continue installation',
	'license_read_hint' => 'Please scroll down to read the full agreement',
	'license_countdown_text' => 'Please read the agreement carefully',
	'license_ready' => 'Done reading, please check the box to agree',
	'install_title' => 'XIUNOX Installation Wizard',
	'install_guide' => 'Installation Wizard',

	
	'function_check' => 'Function dependency check',
	'supported' => 'Supported',
	'not_supported' => 'Not Supported',
	'function_glob_not_exists' => 'Plugin install dependent on it, please setting php.ini, set disabled_functions = ; Lifting restrictions on this function',
	'function_gzcompress_not_exists' => 'Plugin install dependent on it, on Linux server, add compile argument: --with-zlib, on Windows Server, please setting php.ini open extension=php_zlib.dll',
	'function_mb_substr_not_exists' => 'System dependent on it, on Linux server, add compile argument: --with-mbstring, on Windows Server, please setting php.ini open extension=php_mbstring.dll',

	'optional' => 'Optional',
	'recommended' => 'Recommended',

	// hook lang_en_us_bbs_install.php

	// Missing keys from zh-cn
	'step_lang'=>'Select Language',
	'step_license'=>'License Agreement',
	'step_env'=>'Environment Check',
	'step_config'=>'Database Configuration',
	'install_success'=>'Installation Successful!',
	'visit_site'=>'Visit Site',
	'admin_username_is_empty'=>'Administrator username cannot be empty',
	'admin_password_is_empty'=>'Administrator password cannot be empty',
	'admin_password_too_short'=>'Administrator password must be at least 6 characters',
	'admin_email_is_empty'=>'Administrator email cannot be empty',
	'admin_email_invalid'=>'Administrator email format is invalid',
	'db_already_exists_confirm'=>'XIUNOX tables already exist in the database. Continuing will clear all data! Click OK to continue, or Cancel to go back.',
);

?>