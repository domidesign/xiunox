<?php

require_once __DIR__ . '/AttachmentAccessService.php';
require_once __DIR__ . '/RateLimitService.php';

class AttachmentDownloadService {
    public static function serve(array $attach, int $uid, int $gid, bool $inline = false): void {
        AttachmentAccessService::assertAllowed($attach, $uid, $gid);
        $limit = new RateLimitService(30, 60);
        $key = 'attachment_download:' . RateLimitService::getClientKey() . ':' . $uid;
        if (!$limit->check($key)) {
            http_response_code(429);
            header('Cache-Control: private, no-store');
            header('Retry-After: ' . max(1, $limit->getRetryAfter($key)));
            exit('Too many downloads');
        }
        $path = PrivateAttachmentStorage::path((string)$attach['filename']);
        if (!is_file($path)) { http_response_code(404); exit('Attachment not found'); }
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range !== '') {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match) || ($match[1] === '' && $match[2] === '') || $size === 0) {
                self::invalidRange($size);
            }
            if ($match[1] === '') $start = max(0, $size - intval($match[2]));
            else $start = intval($match[1]);
            if ($match[2] !== '' && $match[1] !== '') $end = min($end, intval($match[2]));
            if ($start > $end || $start >= $size) self::invalidRange($size);
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        $name = str_replace(array("\r", "\n", "\0", '"', '\\'), '_', basename((string)($attach['orgfilename'] ?? 'attachment')));
        $ext = strtolower(pathinfo($attach['filename'], PATHINFO_EXTENSION));
        $types = array('png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'gif'=>'image/gif', 'webp'=>'image/webp', 'bmp'=>'image/bmp', 'mp4'=>'video/mp4', 'webm'=>'video/webm', 'ogg'=>'video/ogg');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . ($inline ? ($types[$ext] ?? 'application/octet-stream') : 'application/octet-stream'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . max(0, $end - $start + 1));
        if (class_exists('SecurityAuditService', false)) SecurityAuditService::event('attachment_download', array('aid'=>intval($attach['aid']), 'uid'=>$uid, 'result'=>'allowed'));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
        $handle = fopen($path, 'rb');
        if (!$handle) { http_response_code(503); exit; }
        fseek($handle, $start);
        $remaining = max(0, $end - $start + 1);
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        exit;
    }

    private static function invalidRange(int $size): void {
        http_response_code(416);
        header('Cache-Control: private, no-store');
        header('Content-Range: bytes */' . $size);
        exit;
    }
}
