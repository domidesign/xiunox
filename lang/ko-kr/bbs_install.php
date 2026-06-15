<?php

return array(
	'installed_tips' => '이미 설치되어 있습니다. 재설치하려면 conf/conf.php와 install/install.lock를 삭제해주세요!',
	'please_set_conf_file_writable' => 'conf/conf.php 파일에 쓰기 권한을 설정해주세요!',
	'evn_not_support_php_mysql' => '현재 PHP 환경에서 mysql과 pdo_mysql을 지원하지 않아 설치를 계속할 수 없습니다.',
	'dbhost_is_empty' => '데이터베이스 호스트를 입력해주세요',
	'dbname_is_empty' => '데이터베이스 이름을 입력해주세요',
	'dbuser_is_empty' => '사용자 이름을 입력해주세요',
	'adminuser_is_empty' => '관리자 사용자 이름을 입력해주세요',
	'adminpass_is_empty' => '관리자 비밀번호를 입력해주세요',
	'conguralation_installed' => '축하합니다! 설치가 완료되었습니다. 보안을 위해 install 디렉토리를 삭제해주세요.',

	'step_1_title' => '1. 설치 환경 확인',
	'runtime_env_check' => '사이트 실행 환경 확인',
	'required' => '요구 사항',
	'current' => '현재',
	'check_result' => '확인 결과',
	'passed' => '통과',
	'not_passed' => '미통과',
	'not_the_best' => '최적 환경이 아닙니다',
	'dir_writable_check' => '디렉토리 / 파일 권한 확인',
	'writable' => '쓰기 가능',
	'unwritable' => '쓰기 불가',
	'check_again' => '다시 확인',
	'os' => '운영체제',
	'unix_like' => 'UNIX 계열',
	'php_version' => 'PHP 버전',

	'step_2_title' => '2. 데이터베이스 설정',
	'db_type' => '데이터베이스 유형',
	'db_engine' => '데이터베이스 엔진',
	'db_host' => '데이터베이스 서버',
	'db_name' => '데이터베이스 이름',
	'db_user' => '데이터베이스 사용자 이름',
	'db_pass' => '데이터베이스 비밀번호',
	'step_3_title' => '3. 관리자 정보',
	'admin_email' => '관리자 이메일',
	'admin_username' => '관리자 사용자 이름',
	'admin_pw' => '관리자 비밀번호',
	'installing_about_moment' => '설치 중입니다. 약 1분 정도 소요됩니다',
	'license_title' => 'XIUNOX 라이선스 계약',
	'license_content' => 'XIUNOX를 선택해주셔서 감사합니다. XIUNOX는 모던하고 가볍고 안정적인 포럼 시스템입니다. Bootstrap 5.3 + htmx 4 아키텍처로 구축되어 모바일 브라우저를 완벽하게 지원합니다. 백엔드는 PHP 8.0+를 채택하고 InnoDB 엔진과 utf8mb4 문자셋을 지원합니다. 타사 라이브러리 의존성이 적어 배포와 유지보수가 용이하며, 2차 개발의 훌륭한 기반이 됩니다.

XIUNOX는 MIT 라이선스로 배포됩니다. 자유롭게 수정, 파생 버전 생성, 상업적 이용이 가능하며 법적 위험이 없습니다 (수정 시 원래의 저작권 정보를 유지해주세요).',
	'license_date' => '배포일: 2026년',
	'agree_license_to_continue' => '라이선스에 동의하고 설치 계속',
	'license_read_hint' => '아래로 스크롤하여 전체 계약을 읽어주세요',
	'license_countdown_text' => '계약 내용을 주의 깊게 읽어주세요',
	'license_ready' => '읽기가 완료되면 동의에 체크해주세요',
	'install_title' => 'XIUNOX 설치 마법사',
	'install_guide' => '설치 마법사',

	'function_check' => '함수 의존성 확인',
	'supported' => '지원됨',
	'not_supported' => '미지원',
	'function_glob_not_exists' => '관리자 플러그인 기능이 이 함수에 의존합니다. php.ini에서 disabled_functions = ; 로 설정하여 제한을 해제해주세요',
	'function_gzcompress_not_exists' => '관리자 플러그인 기능이 이 함수에 의존합니다. Linux에서는 --with-zlib 컴파일 옵션을 추가하고, Windows에서는 php.ini에서 extension=php_zlib.dll의 주석을 해제해주세요',
	'function_mb_substr_not_exists' => '시스템이 이 함수에 의존합니다. Linux에서는 --with-mbstring 컴파일 옵션을 추가하고, Windows에서는 php.ini에서 extension=php_mbstring.dll의 주석을 해제해주세요',

	'optional' => '선택사항',
	'recommended' => '권장',

	'step_lang' => '언어 선택',
	'step_license' => '라이선스 계약',
	'step_env' => '환경 확인',
	'step_config' => '데이터베이스 설정',
	'install_success' => '설치 완료!',
	'visit_site' => '사이트 방문',

	'admin_username_is_empty' => '관리자 사용자 이름을 입력해주세요',
	'admin_password_is_empty' => '관리자 비밀번호를 입력해주세요',
	'admin_password_too_short' => '관리자 비밀번호는 최소 6자 이상이어야 합니다',
	'admin_email_is_empty' => '관리자 이메일을 입력해주세요',
	'admin_email_invalid' => '관리자 이메일 형식이 올바르지 않습니다',

	// hook lang_ko_kr_bbs_install.php

	'db_already_exists_confirm' => '데이터베이스에 XIUNOX 테이블이 이미 존재합니다. 계속 설치하면 모든 데이터가 초기화됩니다! 확인을 클릭하면 계속하고, 취소하면 돌아갑니다.',

);

?>
