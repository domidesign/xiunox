<?php

require_once __DIR__ . '/HiddenContentGuard.php';

/** Local private files are never placed beneath the application/document root. */
class PrivateAttachmentStorage {
    public static function root(): string {
        $app = realpath(APP_PATH);
        if (!$app) throw new RuntimeException('Invalid application root');
        $configured = getenv('XIUNOX_PRIVATE_PATH');
        $config = APP_PATH . 'conf/private_storage.php';
        if (!$configured && is_file($config)) {
            $settings = include $config;
            $configured = is_array($settings) ? ($settings['path'] ?? '') : '';
        }
        $root = $configured ?: dirname($app) . '/.xiunox-private-' . substr(hash('sha256', $app), 0, 12);
        if (!preg_match('~^(?:/|[a-zA-Z]:[\\/])~', $root) || preg_match('~(?:^|[\\/])\.\.(?:[\\/]|$)~', $root)) throw new RuntimeException('Private storage requires an absolute path');
        if (!is_dir($root) && (!function_exists('mkdir') || !mkdir($root, 0700, true))) throw new RuntimeException('Cannot create private storage');
        $root = realpath($root);
        foreach (array($app, realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: $app) as $public) {
            if ($root === $public || strpos($root . '/', rtrim($public, '/') . '/') === 0) throw new RuntimeException('Private storage must be outside the web root');
        }
        return $root . '/';
    }

    public static function relative(string $name): string {
        if ($name === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false || $name[0] === '/' || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[^a-zA-Z0-9_./-]~', $name)) throw new RuntimeException('Invalid attachment key');
        return $name;
    }

    public static function privatePath(string $name, string $area = 'attach'): string {
        if (!in_array($area, array('attach', 'tmp', 'audit'), true)) throw new RuntimeException('Invalid storage area');
        $base = self::root();
        $path = $base . $area . '/' . self::relative($name);
        if (!is_dir(dirname($path)) && (!function_exists('mkdir') || !mkdir(dirname($path), 0700, true))) throw new RuntimeException('Cannot create private directory');
        $parent = realpath(dirname($path));
        if (!$parent || strpos($parent . '/', $base) !== 0 || is_link($path)) throw new RuntimeException('Private storage symlink rejected');
        return $path;
    }

    public static function publicPath(string $name, string $area = 'attach'): string {
        global $conf;
        if (!in_array($area, array('attach', 'tmp'), true)) throw new RuntimeException('Invalid public area');
        $base = realpath($conf['upload_path']);
        if (!$base) throw new RuntimeException('Upload root unavailable');
        $path = $base . '/' . $area . '/' . self::relative($name);
        $real = realpath($path);
        if (is_link($path) || ($real && strpos($real, $base . '/' . $area . '/') !== 0)) throw new RuntimeException('Attachment symlink rejected');
        return $path;
    }

    public static function path(string $name, string $area = 'attach'): string {
        $private = self::privatePath($name, $area);
        return is_file($private) ? $private : self::publicPath($name, $area);
    }

    public static function publicMedia(array $file): bool {
        $ext = strtolower(pathinfo($file['filename'] ?? $file['orgfilename'] ?? '', PATHINFO_EXTENSION));
        return (!empty($file['isimage']) && in_array($ext, array('png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'), true))
            || (($file['filetype'] ?? '') === 'video' && in_array($ext, array('mp4', 'webm', 'ogg', 'avi', 'wmv', 'av', 'rm', 'rmvb', 'wav', 'wma'), true));
    }

    public static function destination(string $name, array $context): string {
        $context['filename'] = $name;
        if (self::needsProtection($context)) return self::privatePath($name);
        $path = self::publicPath($name);
        if (!is_dir(dirname($path)) && (!function_exists('mkdir') || !mkdir(dirname($path), 0755, true))) throw new RuntimeException('Cannot create public media directory');
        return $path;
    }

    public static function needsProtection(array $attach): bool {
        if (!self::publicMedia($attach)) return true;
        $tid = intval($attach['tid'] ?? 0);
        if ($tid <= 0) return false; // Explicit public app icons/screenshots.
        $post = !empty($attach['pid']) ? post__read(intval($attach['pid'])) : null;
        if ($post && (HiddenContentGuard::contains((string)($post['message'] ?? '')) || !empty($post['is_deleted']) || intval($post['audit_status'] ?? 1) !== 1)) return true;
        include_once __DIR__ . '/ApiContentService.php';
        $thread = thread__read($tid);
        return !$thread || !ApiContentService::canReadThread($thread, 0, 0);
    }

    public static function protect(array $attach, bool $force = false): array {
        $name = self::relative((string)$attach['filename']);
        if (!$force && !self::needsProtection($attach)) return array('state' => 'public');
        $source = self::publicPath($name);
        $target = self::privatePath($name);
        if (!is_file($source)) {
            if (!is_file($target)) throw new RuntimeException('Attachment missing from both stores');
            return array('state' => 'private', 'sha256' => hash_file('sha256', $target));
        }
        $hash = hash_file('sha256', $source);
        if (!is_file($target)) {
            $part = $target . '.' . bin2hex(random_bytes(8)) . '.part';
            if (!copy($source, $part) || hash_file('sha256', $part) !== $hash || !rename($part, $target)) {
                if (is_file($part)) unlink($part);
                throw new RuntimeException('Private copy verification failed');
            }
        }
        if (hash_file('sha256', $target) !== $hash || !unlink($source)) throw new RuntimeException('Public copy could not be safely removed');
        // Thumbnails of protected media must not retain a public copy either.
        if (function_exists('attach_thumb_path')) {
            $thumb = attach_thumb_path($name);
            if ($thumb && is_file(self::publicPath($thumb))) self::protect(array('filename' => $thumb), true);
        }
        return array('state' => 'migrated', 'sha256' => $hash);
    }

    public static function isPrivate(array $attach): bool {
        return is_file(self::privatePath((string)$attach['filename']));
    }

    public static function delete(array $attach): void {
        $name = (string)$attach['filename'];
        foreach (array(self::privatePath($name), self::publicPath($name)) as $path) if (is_file($path) && !unlink($path)) throw new RuntimeException('Attachment deletion failed');
    }

    public static function rewrite(string $text, array $attach): string {
        global $conf;
        if (!self::isPrivate($attach)) return $text;
        $url = url('attach-read-' . intval($attach['aid']) . '-private');
        $names = array((string)$attach['filename']);
        if (function_exists('attach_thumb_path') && ($thumb = attach_thumb_path($attach['filename']))) $names[] = $thumb;
        $prefix = parse_url($conf['upload_url'], PHP_URL_PATH) ?: $conf['upload_url'];
        foreach ($names as $name) {
            $path = ltrim(rtrim($prefix, '/') . '/attach/' . $name, '/');
            $text = preg_replace_callback('~(?:https?://[^/\s<>"\x27]+)?/?' . preg_quote($path, '~') . '(?:\?[a-zA-Z0-9_=&%-]*)?~i', static function () use ($url) { return $url; }, $text);
        }
        return $text;
    }

    public static function rewritePost(array $attach): void {
        $pid = intval($attach['pid'] ?? 0);
        if ($pid <= 0 || !self::isPrivate($attach)) return;
        $post = post__read($pid);
        if (!$post) return;
        foreach (array('message', 'message_fmt') as $field) {
            $old = (string)$post[$field];
            $new = self::rewrite($old, $attach);
            if ($old !== $new && db_update('post', array('pid'=>$pid, $field=>$old), array($field=>$new)) === false) throw new RuntimeException('Concurrent post rewrite failed; retry migration');
        }
    }

    public static function protectThread(int $tid): void {
        $cursor = 0;
        do {
            $rows = db_find('attach', array('tid'=>$tid, 'aid'=>array('>'=>$cursor)), array('aid'=>1), 1, 100);
            foreach ($rows ?: array() as $attach) {
                $cursor = intval($attach['aid']);
                if (self::needsProtection($attach)) { self::protect($attach); self::rewritePost($attach); }
            }
        } while (count($rows ?: array()) === 100);
    }
}
