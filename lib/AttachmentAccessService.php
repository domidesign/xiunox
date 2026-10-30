<?php

require_once __DIR__ . '/PrivateAttachmentStorage.php';
require_once __DIR__ . '/ApiContentService.php';

class AttachmentAccessService {
    public static function appVersion(int $aid): ?array {
        // Query the table even when the plugin is disabled: disabling cannot publish paid files.
        if (!function_exists('db_check_table_exists') || !db_check_table_exists('xnx_appcenter_version')) return null;
        return db_find_one('xnx_appcenter_version', array('download_aid' => $aid)) ?: null;
    }

    public static function allowed(array $attach, int $uid, int $gid, string $operation = 'download'): bool {
        if ($uid > 0 && function_exists('user_read')) {
            $viewer = user_read($uid);
            if (!$viewer || in_array(intval($viewer['ban_type'] ?? 0), array(2, 3), true)) return false;
            $gid = intval($viewer['gid']);
        } elseif ($uid <= 0) { $gid = 0; }
        if ($operation === 'metadata' || $operation === 'delete') {
            return $uid > 0 && (intval($attach['uid']) === $uid || in_array($gid, array(1, 2), true));
        }
        $version = self::appVersion(intval($attach['aid']));
        if ($version) {
            if ($uid <= 0) return false;
            $app = db_find_one('xnx_appcenter_app', array('app_id' => intval($version['app_id'])));
            if (!$app) return false;
            $owner = intval($app['uid']) === $uid;
            $admin = in_array($gid, array(1, 2), true);
            if (!$owner && !$admin && intval($app['status']) !== 1) return false;
            if (!class_exists('AppCenterService', false) || !method_exists('AppCenterService', 'canDownload')) return false;
            $check = AppCenterService::canDownload($uid, $gid, $owner);
            if (empty($check['ok'])) return false;
            if (intval($app['price_type']) === 1 && !$owner && !$admin) {
                return class_exists('PurchaseService', false) && PurchaseService::isPurchased(intval($app['app_id']), $uid);
            }
            return true;
        }
        $tid = intval($attach['tid'] ?? 0);
        if ($tid <= 0) return false; // Unassociated uploads are not downloadable via a guessed aid.
        $thread = thread__read($tid);
        if (!$thread || !ApiContentService::canReadThread($thread, $uid, $gid)) return false;
        $post = post__read(intval($attach['pid'] ?? 0));
        if (!$post || !empty($post['is_deleted']) || (intval($post['audit_status'] ?? 1) !== 1 && intval($post['uid']) !== $uid && !in_array($gid, array(1, 2), true))) return false;
        if (!forum_access_user(intval($thread['fid']), $gid, 'allowdown') && !PrivateAttachmentStorage::publicMedia($attach)) return false;
        // Files embedded in hidden posts require access to every protected block that names them.
        if (HiddenContentGuard::contains((string)$post['message'])) {
            if (!class_exists('HiddenService', false) || !method_exists('HiddenService', 'canAccessAttachment')) return false;
            return HiddenService::canAccessAttachment($attach, $post, $uid, $gid);
        }
        return true;
    }

    public static function assertAllowed(array $attach, int $uid, int $gid, string $operation = 'download'): void {
        if (!self::allowed($attach, $uid, $gid, $operation)) {
            http_response_code(404);
            header('Cache-Control: private, no-store');
            exit('Attachment not found');
        }
    }
}
