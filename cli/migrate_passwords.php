<?php

if(php_sapi_name() !== 'cli') {
	exit('This script must be run from CLI.');
}

define('APP_PATH', dirname(__FILE__).'/../');
define('DEBUG', 1);
define('XIUNOPHP_PATH', APP_PATH.'xiunophp/');

$conf = @include APP_PATH.'conf/conf.php';
if(!$conf) {
	exit("Error: conf/conf.php not found.\n");
}
$_SERVER['conf'] = $conf;

include XIUNOPHP_PATH.'xiunophp.php';

include APP_PATH.'model/user.func.php';

$n = db_count('user', array('password_hash'=>'', 'password!='=>''));

echo "Found $n users with legacy passwords. They will be auto-upgraded on next login.\n";
