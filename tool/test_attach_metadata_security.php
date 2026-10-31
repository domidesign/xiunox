<?php
// 独立回归：直接执行真实 API 路由，不连接数据库、不读取站点配置。
define('APP_PATH', dirname(__DIR__) . '/');
function group_list_cache() { return array(); }
class AttachTestResponse extends RuntimeException {
    public $data;
    public function __construct($status, $data = null) {
        parent::__construct('API response', $status);
        $this->data = $data;
    }
}
class ApiResponse {
    public static function unauthorized() { throw new AttachTestResponse(401); }
    public static function validationError($message) { throw new AttachTestResponse(422); }
    public static function notFound($message) { throw new AttachTestResponse(404); }
    public static function success($data) { throw new AttachTestResponse(200, $data); }
}
class ApiAuthService {
    public static $token;
    public static function getBearerToken() { return self::$token; }
}
function attach_test_request($token, $viewer, $row, $id = 42) {
    ApiAuthService::$token = $token;
    $apiAuth = new class($viewer) {
        private $viewer;
        public function __construct($viewer) { $this->viewer = $viewer; }
        public function validateAccessToken($token) { return $this->viewer; }
    };
    $attachmentService = new class($row) {
        private $row;
        public function __construct($row) { $this->row = $row; }
        public function getAttachmentById($id) { return $this->row; }
    };
    $segments = array('attach', $id);
    $method = 'GET';
    try {
        include APP_PATH . 'api/v1/attach.php';
    } catch (AttachTestResponse $response) {
        return $response;
    }
    throw new RuntimeException('API did not terminate');
}
$row = array(
    'aid' => 42, 'uid' => 7, 'tid' => 0, 'pid' => 0,
    'orgfilename' => 'private.zip', 'filesize' => 123, 'isimage' => 0,
    'filename' => '202610/secret.zip', 'path' => '/private/secret.zip',
    'url' => '/upload/attach/202610/secret.zip', 'thumb_url' => '/secret',
    'driver' => 's3', 'bucket_secret' => 'future-sensitive-field',
);
$cases = array(
    array('anonymous', null, null, $row, 42, 401),
    array('invalid token', 'invalid', null, $row, 42, 401),
    array('invalid identity', 'valid', array('uid' => 0, 'gid' => 1), $row, 42, 401),
    array('other user', 'valid', array('uid' => 8, 'gid' => 101), $row, 42, 404),
    array('missing attachment', 'valid', array('uid' => 7, 'gid' => 101), null, 42, 404),
    array('invalid aid', 'valid', array('uid' => 7, 'gid' => 101), $row, 0, 422),
    array('owner', 'valid', array('uid' => 7, 'gid' => 101), $row, 42, 200),
    array('admin', 'valid', array('uid' => 8, 'gid' => 1), $row, 42, 200),
    array('secondary admin', 'valid', array('uid' => 8, 'gid' => 2), $row, 42, 200),
);
foreach ($cases as $case) {
    list($name, $token, $viewer, $attachment, $id, $status) = $case;
    $result = attach_test_request($token, $viewer, $attachment, $id);
    if ($result->getCode() !== $status) {
        throw new RuntimeException($name . ': unexpected status ' . $result->getCode());
    }
    if ($status === 200) {
        foreach (array('filename', 'path', 'url', 'thumb_url', 'driver', 'bucket_secret') as $field) {
            if (array_key_exists($field, $result->data)) {
                throw new RuntimeException($name . ': leaked ' . $field);
            }
        }
        if ($result->data['aid'] !== 42 || $result->data['orgfilename'] !== 'private.zip') {
            throw new RuntimeException($name . ': missing safe metadata');
        }
    }
}
echo 'PASS: 9 real-route metadata access cases; storage fields excluded.' . PHP_EOL;
