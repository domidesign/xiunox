<?php

!defined('DEBUG') AND exit('Access Denied.');

$header['title'] = '数据库升级 - Phase 1';

include _include(ADMIN_PATH.'view/htm/header.inc.htm');
include _include(ADMIN_PATH.'view/htm/upgrade_phase1.htm');
include _include(ADMIN_PATH.'view/htm/footer.inc.htm');

?>