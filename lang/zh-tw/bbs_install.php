<?php

return array(
	'installed_tips' => '程序已經安裝過了，如需重新安裝，請刪除 conf/conf.php 和 install/install.lock ！',
	'please_set_conf_file_writable' => '請設置 conf/conf.php 文件為可寫！',
	'evn_not_support_php_mysql' => '當前 PHP 環境不支持 mysql 和 pdo_mysql，無法繼續安裝。',
	'dbhost_is_empty' => '數據庫主機不能為空',
	'dbname_is_empty' => '數據庫名不能為空',
	'dbuser_is_empty' => '用戶名不能為空',
	'adminuser_is_empty' => '管理員用戶名不能為空',
	'adminpass_is_empty' => '管理員密碼不能為空',
	'conguralation_installed' => '恭喜，安裝成功！為了安全請刪除 install 目錄。',
	
	'step_1_title' => '壹、安裝環境檢測',
	'runtime_env_check' => '網站運行環境檢測',
	'required' => '需要',
	'current' => '當前',
	'check_result' => '檢測結果',
	'passed' => '通過',
	'not_passed' => '通過',
	'not_the_best' => '不是最理想的環境',
	'dir_writable_check' => '目錄 / 文件 權限檢測',
	'writable' => '可寫',
	'unwritable' => '不可寫',
	'check_again' => '重新檢測',
	'os' => '操作系統',
	'unix_like' => '類 UNIX',
	'php_version' => 'PHP 版本',
	
	'step_2_title' => '二、數據庫設置',
	'db_type' => '數據庫類型',
	'db_engine' => '數據庫引擎',
	'db_host' => '數據庫服務器',
	'db_name' => '數據庫名',
	'db_user' => '數據庫用戶名',
	'db_pass' => '數據庫密碼',
	'db_tablepre' => '表前綴',
	'db_tablepre_tip' => '只允許字母、數字和下劃線，必須以字母開頭，默認 bbs_',
	'db_tablepre_invalid' => '表前綴格式不正確，只允許字母、數字和下劃線，且必須以字母開頭',
	'step_3_title' => '三、管理員信息',
	'admin_email' => '管理員郵箱',
	'admin_username' => '管理員用戶名',
	'admin_pw' => '管理員密碼',
	'installing_about_moment' => '正在安裝，大概需要壹分鐘左右',
	'license_title' => 'XIUNOX 授權協議',
	'license_content' => '感謝您選擇 XIUNOX，它是一款現代化、輕量、穩定的論壇系統。基於 Bootstrap 5.3 + htmx 4 架構，全面支持移動端瀏覽器，後端採用 PHP 8.0+，支持 InnoDB 引擎和 utf8mb4 字符集，對第三方類庫依賴極少，方便部署和維護，是一個非常好的二次開發基石。

XIUNOX 採用 MIT 協議發布，您可以自由修改、派生版本、商用而不用擔心任何法律風險（修改後應保留原來的版權信息）。',
	'license_date' => '發布時間：2026年',
	'agree_license_to_continue' => '同意協議繼續安裝',
	'license_read_hint' => '請下滑閱讀完整協議內容',
	'license_countdown_text' => '請認真閱讀協議內容',
	'license_ready' => '閱讀完畢，請勾選同意',
	'install_title' => 'XIUNOX 安裝向導',
	'install_guide' => '安裝向導',

	'function_check' => '函數依賴檢查',
	'supported' => '支持',
	'not_supported' => '不支持',
	'function_glob_not_exists' => '後臺插件功能依賴該函數，請配置 php.ini，設置 disabled_functions = ; 去除對該函數的限制',
	'function_gzcompress_not_exists' => '後臺插件功能依賴該函數，Linux 主機請添加編譯參數 --with-zlib，Windows 主機請配置 php.ini 註釋掉  extension=php_zlib.dll',
	'function_mb_substr_not_exists' => '系統依賴該函數，Linux 主機請添加編譯參數 --with-mbstring，Windows 主機請配置 php.ini 註釋掉 extension=php_mbstring.dll',

	'optional' => '可選',
	'recommended' => '推薦',

	// hook lang_zh_tw_bbs_admin.php

	'step_lang' => '選擇語言',
	'step_license' => '許可協議',
	'step_env' => '環境檢測',
	'step_config' => '數據庫配置',
	'install_success' => '安裝成功！',
	'visit_site' => '訪問站點',

	'admin_username_is_empty' => '管理員用戶名不能為空',
	'admin_password_is_empty' => '管理員密碼不能為空',
	'admin_password_too_short' => '管理員密碼至少6個字符',
	'admin_email_is_empty' => '管理員郵箱不能為空',
	'admin_email_invalid' => '管理員郵箱格式不正確',

	'db_already_exists_confirm' => '數據庫中已存在 Xiuno BBS 的表，繼續安裝將清空所有數據！點擊確定繼續，取消返回修改。',

	'db_connect_denied' => '數據庫連接被拒絕，請檢查用戶名和密碼是否正確',
	'db_not_found' => '數據庫不存在，請先創建數據庫或檢查數據庫名是否正確',
	'db_host_unreachable' => '無法連接數據庫服務器，請檢查主機地址和端口是否正確',
	'db_connect_failed' => '數據庫連接失敗',

	// ========== 安裝界面擴展 ==========
	'install_choose_language' => '選擇語言',
	'install_next_step' => '下一步',
	'install_loading' => '加載中...',
	'install_installing' => '安裝中...',
	'install_submit' => '提交',
	'install_request_failed' => '請求失敗：',
	'install_license_not_found' => 'LICENSE 文件未找到。',
	'install_meta_author' => 'XiunoBBS 4.0',

	// ========== 安裝成功安全提示 ==========
	'install_security_tips' => '安全提示',
	'install_tip_delete_install_dir' => '刪除安裝目錄',
	'install_tip_delete_install_dir_desc' => '系統已自動鎖定安裝程序，但仍建議手動刪除',
	'install_tip_change_admin_pw' => '修改管理員密碼',
	'install_tip_change_admin_pw_desc' => '登錄後請及時修改管理員密碼為強密碼',
	'install_tip_configure_https' => '配置 HTTPS',
	'install_tip_configure_https_desc' => '建議為站點配置 SSL 證書，啟用 HTTPS',
	'install_tip_change_db_pw' => '修改數據庫密碼',
	'install_tip_change_db_pw_desc' => '如果使用了默認數據庫密碼，請及時修改',

);

?>