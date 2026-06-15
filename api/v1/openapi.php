<?php
include APP_PATH . 'lib/ApiDocService.php';
header('Content-Type: application/json; charset=utf-8');
header('X-API-Version: 1.0');
echo json_encode(ApiDocService::getOpenApiSpec(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
