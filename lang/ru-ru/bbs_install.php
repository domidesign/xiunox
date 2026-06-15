<?php

return array(
	'installed_tips' => 'Форум уже установлен, если вы хотите переустановить, удалите conf/conf.php и install/install.lock',
	'please_set_conf_file_writable' => 'Установите права чтения/запси для conf/conf.php !',
	'evn_not_support_php_mysql' => 'Текущая версия PHP,mysql и pdo_mysql driver не соответствует минимальным системным требованиям, не могу установить',
	'dbhost_is_empty' => 'Введите имя сервера',
	'dbname_is_empty' => 'Введите имя базы данных',
	'dbuser_is_empty' => 'Укажите пользователя БД',
	'adminuser_is_empty' => 'Не может быть пустым',
	'adminpass_is_empty' => 'Не может быть пустым',
	'conguralation_installed' => 'Поздравляем, вы установили форум, не забудьте удалить папку install в целях вашей безопасности.',
	
	'step_1_title' => '1. Системные требования',
	'runtime_env_check' => 'Системные требования',
	'required' => 'Требуется',
	'current' => 'Ваш сервер',
	'check_result' => 'Результат',
	'passed' => 'Соотвествует',
	'not_passed' => 'Не соответсвует',
	'not_the_best' => 'Не соответсвует системным требованиям',
	'dir_writable_check' => 'Проверка прав доступа',
	'writable' => 'Чтение/запись',
	'unwritable' => 'Чтение',
	'check_again' => 'Проверить снова',
	'os' => 'OS',
	'unix_like' => 'UNIX',
	'php_version' => 'Версия PHP',
	
	'step_2_title' => '2. База данных',
	'db_type' => 'Тип',
	'db_engine' => 'Движок',
	'db_host' => 'Сервер',
	'db_name' => 'Название',
	'db_user' => 'Пользователь',
	'db_pass' => 'Пароль',
	'step_3_title' => '3. Администратор',
	'admin_email' => 'E-mail',
	'admin_username' => 'Логин',
	'admin_pw' => 'Пароль',
	'installing_about_moment' => 'Установка, ожидайте...',
	'license_title' => 'Лицензионное соглашение XIUNOX',
	'license_content' => 'Спасибо, что выбрали XIUNOX — современную, легковесную и стабильную форумную систему. Построена на архитектуре Bootstrap 5.3 + htmx 4 с полной поддержкой мобильных браузеров. Бэкенд использует PHP 8.0+ с движком InnoDB и кодировкой utf8mb4. Минимальные зависимости от сторонних библиотек делают систему удобной для развёртывания и обслуживания, а также отличной основой для разработки.

XIUNOX выпускается под лицензией MIT. Вы можете свободно модифицировать, создавать производные версии и использовать в коммерческих целях без каких-либо юридических рисков (оригинальная информация об авторских правах должна быть сохранена).',
	'license_date' => 'Дата выпуска: 2026',
	'agree_license_to_continue' => 'Принять лицензию и продолжить',
	'license_read_hint' => 'Прокрутите вниз, чтобы прочитать полное соглашение',
	'license_countdown_text' => 'Пожалуйста, внимательно прочитайте соглашение',
	'license_ready' => 'Готово, отметьте согласие',
	'install_title' => 'Мастер установки XIUNOX',
	'install_guide' => 'Мастер установки',

	
	'function_check' => 'Проверка необходимых функций',
	'supported' => 'Поддерживается',
	'not_supported' => 'Не поддерживается',
	'function_glob_not_exists' => 'Plugin install dependent on it, please setting php.ini, set disabled_functions = ; Lifting restrictions on this function',
	'function_gzcompress_not_exists' => 'Plugin install dependent on it, on Linux server, add compile argument: --with-zlib, on Windows Server, please setting php.ini open extension=php_zlib.dll',
	'function_mb_substr_not_exists' => 'System dependent on it, on Linux server, add compile argument: --with-mbstring, on Windows Server, please setting php.ini open extension=php_mbstring.dll',

	'optional' => 'Опционально',
	'recommended' => 'Рекомендуется',

	// hook lang_en_us_bbs_install.php

	'step_lang' => 'Выбор языка',
	'step_license' => 'Лицензионное соглашение',
	'step_env' => 'Проверка среды',
	'step_config' => 'Настройка базы данных',
	'install_success' => 'Установка завершена!',
	'visit_site' => 'Перейти на сайт',

	'admin_username_is_empty' => 'Имя администратора не может быть пустым',
	'admin_password_is_empty' => 'Пароль администратора не может быть пустым',
	'admin_password_too_short' => 'Пароль администратора должен быть не менее 6 символов',
	'admin_email_is_empty' => 'Email администратора не может быть пустым',
	'admin_email_invalid' => 'Неверный формат email администратора',

	'db_already_exists_confirm' => 'В базе данных уже существуют таблицы XIUNOX. Продолжение установки удалит все данные! Нажмите OK для продолжения или Отмена для возврата.',
);

?>
