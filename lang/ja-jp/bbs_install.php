<?php

return array(
	'installed_tips' => 'プログラムは既にインストールされています。再インストールする場合は conf/conf.php と install/install.lock を削除してください！',
	'please_set_conf_file_writable' => 'conf/conf.php ファイルを書き込み可能に設定してください！',
	'evn_not_support_php_mysql' => '現在のPHP環境は mysql と pdo_mysql をサポートしていません。インストールを続行できません。',
	'dbhost_is_empty' => 'データベースホストを入力してください',
	'dbname_is_empty' => 'データベース名を入力してください',
	'dbuser_is_empty' => 'ユーザー名を入力してください',
	'adminuser_is_empty' => '管理者ユーザー名を入力してください',
	'adminpass_is_empty' => '管理者パスワードを入力してください',
	'conguralation_installed' => 'インストールに成功しました！セキュリティのため install ディレクトリを削除してください。',

	'step_1_title' => '1. インストール環境チェック',
	'runtime_env_check' => 'サイト実行環境チェック',
	'required' => '必要',
	'current' => '現在',
	'check_result' => 'チェック結果',
	'passed' => '合格',
	'not_passed' => '不合格',
	'not_the_best' => '最適な環境ではありません',
	'dir_writable_check' => 'ディレクトリ / ファイル 権限チェック',
	'writable' => '書き込み可能',
	'unwritable' => '書き込み不可',
	'check_again' => '再チェック',
	'os' => 'オペレーティングシステム',
	'unix_like' => 'UNIX系',
	'os_windows_not_recommended' => 'Windows非推奨、本番環境はLinuxをご利用ください',
	'php_version' => 'PHPバージョン',

	'step_2_title' => '2. データベース設定',
	'db_type' => 'データベースタイプ',
	'db_engine' => 'データベースエンジン',
	'db_host' => 'データベースサーバー',
	'db_name' => 'データベース名',
	'db_user' => 'データベースユーザー名',
	'db_pass' => 'データベースパスワード',
	'step_3_title' => '3. 管理者情報',
	'admin_email' => '管理者メールアドレス',
	'admin_username' => '管理者ユーザー名',
	'admin_pw' => '管理者パスワード',
	'installing_about_moment' => 'インストール中です。約1分ほどかかります',
	'license_title' => 'XIUNOX ライセンス契約',
	'license_content' => 'XIUNOX をご選択いただきありがとうございます。XIUNOX はモダンで軽量、安定したフォーラムシステムです。Bootstrap 5.3 + htmx 4 アーキテクチャで構築され、モバイルブラウザに完全対応。バックエンドは PHP 8.0+ を採用し、InnoDB エンジンと utf8mb4 文字セットをサポートしています。サードパーティライブラリへの依存が少なく、デプロイや保守が簡単で、二次開発の素晴らしい基盤です。

XIUNOX は MITライセンスで公開されており、自由に変更、派生バージョンの作成、商用利用が可能です（変更後も元の著作権情報を保持してください）。',
	'license_date' => '公開日：2026年',
	'agree_license_to_continue' => 'ライセンスに同意してインストールを続行',
	'license_read_hint' => '下までスクロールして全文をお読みください',
	'license_countdown_text' => '契約内容をよくお読みください',
	'license_ready' => '読み終わりましたら、同意にチェックしてください',
	'install_title' => 'XIUNOX インストールウィザード',
	'install_guide' => 'インストールウィザード',

	'function_check' => '関数依存チェック',
	'supported' => '対応',
	'not_supported' => '未対応',
	'function_glob_not_exists' => '管理画面のプラグイン機能がこの関数に依存しています。php.ini で disabled_functions = ; と設定し、この関数の制限を解除してください',
	'function_gzcompress_not_exists' => '管理画面のプラグイン機能がこの関数に依存しています。zlib拡張をインストールしてください（例: Ubuntu: sudo apt install php8.5-zlib）',
	'function_mb_substr_not_exists' => 'システムがこの関数に依存しています。mbstring拡張をインストールしてください（例: Ubuntu: sudo apt install php8.5-mbstring）',

	'optional' => 'オプション',
	'recommended' => '推奨',

	'step_lang' => '言語を選択',
	'step_license' => 'ライセンス契約',
	'step_env' => '環境チェック',
	'step_config' => 'データベース設定',
	'install_success' => 'インストール成功！',
	'visit_site' => 'サイトにアクセス',

	'admin_username_is_empty' => '管理者ユーザー名を入力してください',
	'admin_password_is_empty' => '管理者パスワードを入力してください',
	'admin_password_too_short' => '管理者パスワードは6文字以上にしてください',
	'admin_email_is_empty' => '管理者メールアドレスを入力してください',
	'admin_email_invalid' => '管理者メールアドレスの形式が正しくありません',

	// hook lang_ja_jp_bbs_install.php

	'db_already_exists_confirm' => 'データベースに XIUNOX のテーブルが既に存在します。インストールを続行するとすべてのデータが消去されます！続行する場合はOKを、戻る場合はキャンセルをクリックしてください。',

);

?>
