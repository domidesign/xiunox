<?php

require_once __DIR__ . '/HiddenContentGuard.php';

/** Viewer-specific serialization; never persist this output in shared caches. */
class ApiContentService {
    public static function canReadThread(array $thread, int $uid, int $gid): bool {
        if (!$thread || !empty($thread['is_deleted'])) return false;
        if (intval($thread['audit_status'] ?? 1) !== 1 && intval($thread['uid']) !== $uid && !in_array($gid, array(1, 2), true)) return false;
        if (!function_exists('forum_access_user')) include_once APP_PATH . 'model/forum_access.func.php';
        if (empty($GLOBALS['forumlist'])) $GLOBALS['forumlist'] = forum_list_cache();
        if (empty($GLOBALS['grouplist'])) $GLOBALS['grouplist'] = group_list_cache();
        return intval($thread['fid'] ?? 0) > 0 && (bool)forum_access_user(intval($thread['fid']), $gid, 'allowread');
    }

    public static function post(array $post, int $uid, int $gid): ?array {
        $thread = thread__read(intval($post['tid'] ?? 0));
        if (!$thread || !self::canReadThread($thread, $uid, $gid) || !empty($post['is_deleted'])) return null;
        if (intval($post['audit_status'] ?? 1) !== 1 && intval($post['uid']) !== $uid && !in_array($gid, array(1, 2), true)) return null;
        $safe = array_intersect_key($post, array_flip(array(
            'pid', 'tid', 'uid', 'isfirst', 'create_date', 'update_date', 'doctype', 'quotepid',
            'message', 'message_fmt', 'images', 'videos', 'files', 'likes', 'audit_status',
            'username', 'user_avatar_url', 'create_date_fmt', 'is_top',
        )));
        foreach (array('message', 'message_fmt') as $field) {
            if (!isset($safe[$field])) continue;
            $safe[$field] = self::hidden((string)$safe[$field], intval($post['pid']), $uid, $gid);
        }
        return $safe;
    }

    public static function hidden(string $text, int $pid, int $uid, int $gid): string {
        if (!HiddenContentGuard::contains($text)) return $text;
        if (class_exists('HiddenService', false) && method_exists('HiddenService', 'filterForViewer')) {
            try { return HiddenService::filterForViewer($text, $pid, $uid, $gid); }
            catch (Throwable $e) { error_log('XIUNOX hidden authorization failed; content withheld'); }
        }
        return HiddenContentGuard::filter($text);
    }

    public static function response($data, int $uid, int $gid) {
        if (is_string($data)) return HiddenContentGuard::contains($data) ? HiddenContentGuard::filter($data) : $data;
        if (!is_array($data)) return $data;
        if (isset($data['pid'], $data['tid']) && (isset($data['message']) || isset($data['message_fmt']))) {
            return self::post($data, $uid, $gid);
        }
        foreach ($data as $key => $value) $data[$key] = self::response($value, $uid, $gid);
        return $data;
    }
}
