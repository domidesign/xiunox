<?php

/**
 * Xiuno BBS 在线升级服务
 * 负责：检查新版本、下载/解压/覆盖文件、复用 UpgradeService 跑 DB 升级
 * 依赖：ZipArchive 扩展 + cURL 或 allow_url_fopen，无第三方库
 */
class OnlineUpgradeService {
    private $db;
    private array $conf;
    private string $tmpPath;
    private string $lockFile;       // 维护锁文件路径

    public function __construct($db, array $conf) {
        $this->db = $db;
        $this->conf = $conf;
        // 兼容 tmp_path 配置（默认 ./tmp/），统一转换为绝对路径
        $tmpPath = isset($conf['tmp_path']) ? $conf['tmp_path'] : APP_PATH . 'tmp/';
        if (substr($tmpPath, -1) !== '/') {
            $tmpPath .= '/';
        }
        $this->tmpPath = $tmpPath;
        $this->lockFile = $this->tmpPath . 'maintenance.lock';
    }

    /**
     * 从 version.php 中定义的常量读取 Gitee 升级源配置
     * 支持的 key：gitee_owner / gitee_repo / api_token / channel / auto_backup
     */
    private function getConfig(string $key, $default = '') {
        switch($key) {
            case 'gitee_owner': return defined('XIUNOX_GITEE_OWNER') ? XIUNOX_GITEE_OWNER : 'xiunox';
            case 'gitee_repo':  return defined('XIUNOX_GITEE_REPO')  ? XIUNOX_GITEE_REPO  : 'xiunobbs';
            case 'api_token':   return defined('XIUNOX_GITEE_TOKEN') ? XIUNOX_GITEE_TOKEN : '';
            case 'channel':     return 'stable';
            case 'auto_backup': return 1;
            default: return $default;
        }
    }

    /**
     * 1. 检查最新版本
     * 调 Gitee API GET /api/v5/repos/{owner}/{repo}/releases/latest
     * 私有仓库拼 access_token 参数
     */
    public function checkLatestVersion(): array {
        $owner = $this->getConfig('gitee_owner', '');
        $repo = $this->getConfig('gitee_repo', '');
        $apiToken = $this->getConfig('api_token', '');
        $currentVersion = isset($this->conf['version']) ? $this->conf['version'] : '0.0.0';

        if (empty($owner) || empty($repo)) {
            return [
                'ok' => false,
                'has_update' => false,
                'current_version' => $currentVersion,
                'message' => lang('upgrade_gitee_not_configured'),
            ];
        }

        $url = "https://gitee.com/api/v5/repos/{$owner}/{$repo}/releases/latest";
        if (!empty($apiToken)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'access_token=' . $apiToken;
        }

        $resp = $this->httpGet($url);
        if (!$resp['ok']) {
            return [
                'ok' => false,
                'has_update' => false,
                'current_version' => $currentVersion,
                'message' => lang('upgrade_gitee_api_failed', array('error' => $resp['message'])),
            ];
        }

        $data = json_decode($resp['data'], true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return [
                'ok' => false,
                'has_update' => false,
                'current_version' => $currentVersion,
                'message' => lang('upgrade_gitee_release_parse_failed'),
            ];
        }

        $tagName = (string)$data['tag_name'];
        // 去掉 v 前缀
        $latestVersion = ltrim($tagName, 'vV');

        $zipUrl = '';
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (!empty($asset['browser_download_url'])) {
                    $zipUrl = $asset['browser_download_url'];
                    break;
                }
            }
        }
        // Gitee 私有仓库下载需带 token
        if (!empty($zipUrl) && !empty($apiToken) && strpos($zipUrl, 'access_token') === false) {
            $zipUrl .= (strpos($zipUrl, '?') === false ? '?' : '&') . 'access_token=' . $apiToken;
        }

        $releaseNotes = isset($data['body']) ? (string)$data['body'] : '';
        $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

        return [
            'ok' => true,
            'has_update' => $hasUpdate,
            'latest_version' => $latestVersion,
            'current_version' => $currentVersion,
            'zip_url' => $zipUrl,
            'release_notes' => $releaseNotes,
            'message' => $hasUpdate ? lang('upgrade_new_version_found', array('version' => $latestVersion)) : lang('upgrade_already_latest'),
        ];
    }

    /**
     * 2. 前置检查
     * PHP>=8.0、磁盘空间>=200MB、ZipArchive、cURL 或 allow_url_fopen、APP_PATH 可写、tmp 可写
     */
    public function preflight(): array {
        $warnings = [];
        $ok = true;

        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $ok = false;
            $warnings[] = lang('upgrade_php_version_too_low', array('version' => PHP_VERSION));
        }

        $freeSpace = @disk_free_space(APP_PATH);
        if ($freeSpace !== false && $freeSpace < 200 * 1024 * 1024) {
            $ok = false;
            $warnings[] = lang('upgrade_disk_space_insufficient', array('limit' => 200, 'size' => round($freeSpace / 1024 / 1024, 1)));
        }

        if (!class_exists('ZipArchive')) {
            $ok = false;
            $warnings[] = lang('upgrade_ziparchive_missing');
        }

        $hasCurl = function_exists('curl_init');
        $allowUrlFopen = (bool)ini_get('allow_url_fopen');
        if (!$hasCurl && !$allowUrlFopen) {
            $ok = false;
            $warnings[] = lang('upgrade_no_curl_no_fopen');
        }

        // APP_PATH 不可写改为非阻断警告：升级包覆盖阶段若涉及根目录文件会失败，
        // extractAndOverwrite 会逐文件报错；此处不阻断以便宝塔等严格权限环境可继续尝试
        if (!is_writable(APP_PATH)) {
            $warnings[] = lang('upgrade_app_path_not_writable', array('path' => APP_PATH));
        }

        if (!is_writable($this->tmpPath)) {
            $ok = false;
            $warnings[] = lang('upgrade_tmp_not_writable', array('dir' => $this->tmpPath));
        }

        // 检查 tmp/ 和 log/ 目录的 Web 访问保护文件，缺失时自动创建
        $htaccessContent = "# 禁止所有 Web 访问，保护敏感文件\n# Apache 生效；Nginx 需在 server 配置中加：location ^~ /{dir}/ { deny all; }\nDeny from all\n";
        $tmpHtaccess = $this->tmpPath . '.htaccess';
        if (!is_file($tmpHtaccess)) {
            @file_put_contents($tmpHtaccess, str_replace('{dir}', 'tmp', $htaccessContent));
        }
        $logHtaccess = APP_PATH . 'log/.htaccess';
        if (is_dir(APP_PATH . 'log') && !is_file($logHtaccess)) {
            @file_put_contents($logHtaccess, str_replace('{dir}', 'log', $htaccessContent));
        }

        return [
            'ok' => $ok,
            'warnings' => $warnings,
            'message' => $ok ? lang('upgrade_preflight_passed') : lang('upgrade_issues_exist', array('n' => count($warnings))),
        ];
    }

    /**
     * 3.1 开启维护模式：写 tmp/maintenance.lock，内容 timestamp|uid
     */
    public function maintenanceOn(int $uid): array {
        if (!is_dir($this->tmpPath)) {
            $oldUmask = umask(0);
            @mkdir($this->tmpPath, 0755, true);
            umask($oldUmask);
        }
        $content = time() . '|' . intval($uid);
        $r = file_put_contents($this->lockFile, $content);
        if ($r === false) {
            return ['ok' => false, 'message' => lang('upgrade_maintenance_lock_write_failed', array('path' => $this->lockFile))];
        }
        return ['ok' => true, 'message' => lang('upgrade_maintenance_on'), 'lock_file' => $this->lockFile];
    }

    /**
     * 3.2 关闭维护模式：删 lock
     */
    public function maintenanceOff(): array {
        if (file_exists($this->lockFile)) {
            if (!@unlink($this->lockFile)) {
                return ['ok' => false, 'message' => lang('upgrade_maintenance_lock_delete_failed', array('path' => $this->lockFile))];
            }
        }
        return ['ok' => true, 'message' => lang('upgrade_maintenance_off')];
    }

    /**
     * 3.3 检测维护模式
     */
    public function isMaintenance(): bool {
        return file_exists($this->lockFile);
    }

    /**
     * 4. 下载：cURL 下载 zip 到 tmp/upgrade_{version}.zip，校验 size > 0
     * 注：原 backup() 步骤已移除，备份责任由用户在升级前确认 Modal 中手动完成
     */
    public function download(string $zipUrl, string $version): array {
        if (empty($zipUrl)) {
            return ['ok' => false, 'message' => lang('upgrade_download_url_empty')];
        }

        if (!is_dir($this->tmpPath)) {
            $oldUmask = umask(0);
            @mkdir($this->tmpPath, 0755, true);
            umask($oldUmask);
        }

        $zipPath = $this->tmpPath . 'upgrade_' . $version . '.zip';
        $r = $this->httpDownload($zipUrl, $zipPath);
        if (!$r['ok']) {
            return ['ok' => false, 'message' => lang('upgrade_download_failed', array('error' => $r['message']))];
        }

        $size = filesize($zipPath);
        if ($size === false || $size <= 0) {
            @unlink($zipPath);
            return ['ok' => false, 'message' => lang('upgrade_download_zero_size')];
        }

        return [
            'ok' => true,
            'zip_path' => $zipPath,
            'size' => $size,
            'message' => lang('upgrade_download_done', array('size' => round($size / 1024 / 1024, 2))),
        ];
    }

    /**
     * 6. 解压并覆盖
     * ZipArchive 解压到 tmp/extract_{timestamp}/
     * 遍历解压后的文件，按黑名单跳过，复制到 APP_PATH/{相对路径}
     * 黑名单目录：conf/（允许 conf/conf.default.php）、upload/、log/、tmp/、plugin/、.trae/
     */
    public function extractAndOverwrite(string $zipPath): array {
        if (!is_file($zipPath)) {
            return ['ok' => false, 'message' => lang('upgrade_zip_not_exists', array('path' => $zipPath))];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => lang('plugin_ziparchive_not_installed')];
        }

        $extractDir = $this->tmpPath . 'extract_' . date('YmdHis') . '/';
        if (!is_dir($extractDir)) {
            $oldUmask = umask(0);
            @mkdir($extractDir, 0755, true);
            umask($oldUmask);
        }

        $zip = new ZipArchive();
        $openRes = $zip->open($zipPath);
        if ($openRes !== true) {
            return ['ok' => false, 'message' => lang('plugin_zip_open_failed', array('code' => $openRes))];
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            return ['ok' => false, 'message' => lang('plugin_extract_failed')];
        }
        $zip->close();

        // 解压后的目录结构可能为：extractDir/{repo-name}/... 或 extractDir/...
        // 找到实际的项目根目录（包含 index.inc.php 或 model.inc.php 的目录）
        $projectRoot = $this->findProjectRoot($extractDir);
        if ($projectRoot === '') {
            $this->recursiveDelete($extractDir);
            return ['ok' => false, 'message' => lang('upgrade_project_root_not_found')];
        }

        $extracted = 0;
        $skipped = 0;
        $failed = []; // 收集覆盖失败的文件，避免静默吞错（典型场景：根目录无写权限）
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            $relativePath = substr($f->getPathname(), strlen($projectRoot));
            $relativePath = ltrim($relativePath, '/');

            if ($f->isDir()) {
                // 目录本身：若被黑名单排除则跳过
                if ($this->isPathBlacklisted($relativePath)) {
                    continue;
                }
                $dst = APP_PATH . $relativePath;
                if (!is_dir($dst)) {
                    $oldUmask = umask(0);
                    @mkdir($dst, 0755, true);
                    umask($oldUmask);
                }
                continue;
            }

            // 文件
            if ($this->isPathBlacklisted($relativePath)) {
                $skipped++;
                continue;
            }

            $dst = APP_PATH . $relativePath;
            $dstDir = dirname($dst);
            if (!is_dir($dstDir)) {
                $oldUmask = umask(0);
                @mkdir($dstDir, 0755, true);
                umask($oldUmask);
            }
            if (@copy($f->getPathname(), $dst)) {
                $extracted++;
            } else {
                $failed[] = $relativePath;
            }
        }

        // 清理解压临时目录
        $this->recursiveDelete($extractDir);

        // 有文件覆盖失败：返回失败列表，前端会标红步骤并提示用户修权限后重试
        if (!empty($failed)) {
            $show = array_slice($failed, 0, 20);
            $more = count($failed) > 20 ? lang('upgrade_failed_more', array('n' => count($failed))) : '';
            return [
                'ok' => false,
                'extracted' => $extracted,
                'skipped' => $skipped,
                'failed' => $failed,
                'message' => lang('upgrade_overwrite_failed', array('n' => count($failed), 'more' => $more, 'files' => implode("\n", $show), 'path' => APP_PATH)),
            ];
        }

        return [
            'ok' => true,
            'extracted' => $extracted,
            'skipped' => $skipped,
            'message' => lang('upgrade_overwrite_done', array('extracted' => $extracted, 'skipped' => $skipped)),
        ];
    }

    /**
     * 7. 跑 DB 升级：复用 UpgradeService，顺序执行 getSteps() 返回的全部步骤
     */
    public function runDbUpgrade(): array {
        $upgradeServiceFile = APP_PATH . 'lib/UpgradeService.php';
        if (!is_file($upgradeServiceFile)) {
            return ['ok' => false, 'message' => lang('upgrade_service_file_missing'), 'results' => []];
        }
        if (!class_exists('UpgradeService')) {
            include_once $upgradeServiceFile;
        }
        if (!class_exists('UpgradeService')) {
            return ['ok' => false, 'message' => lang('upgrade_service_class_load_failed'), 'results' => []];
        }

        $service = new UpgradeService($this->db, $this->conf);
        $steps = $service->getSteps();
        $results = [];
        $allOk = true;
        $configStepOk = false; // 跟踪 config 步骤是否成功（决定是否需要兜底递增 static_version）

        foreach ($steps as $step) {
            $stepId = isset($step['id']) ? $step['id'] : '';
            $stepName = isset($step['name']) ? $step['name'] : $stepId;
            if (empty($stepId)) {
                continue;
            }
            // ponytail: try/catch 防止单步 fatal error 中断 foreach，确保 config 步骤仍能执行
            try {
                $r = $service->executeStep($stepId);
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'message' => lang('upgrade_step_exception', array('error' => $e->getMessage()))];
            }
            $results[] = [
                'id' => $stepId,
                'name' => $stepName,
                'ok' => !empty($r['ok']),
                'message' => isset($r['message']) ? $r['message'] : '',
            ];
            if (empty($r['ok'])) {
                $allOk = false;
            } elseif ($stepId === 'config') {
                $configStepOk = true;
            }
        }

        // 兜底：config 步骤失败时（fatal error 导致 foreach 中断或 adjustConfig 写入失败），
        // 仍需递增 static_version，否则浏览器走旧缓存
        $svMsg = '';
        if (!$configStepOk && function_exists('conf_bump_static_version')) {
            $svRes = conf_bump_static_version();
            $svMsg = $svRes['ok'] ? lang('upgrade_semicolon_prefix', array('message' => $svRes['message'])) : '';
        }

        return [
            'ok' => $allOk,
            'results' => $results,
            'message' => ($allOk ? lang('upgrade_db_all_success') : lang('upgrade_db_partial_failed')) . $svMsg,
        ];
    }

    /**
     * 8. 清理：删 tmp/*（保留 upgrade_backup_* 目录和 maintenance.lock）
     */
    public function cleanup(): array {
        $deleted = 0;
        if (!is_dir($this->tmpPath)) {
            return ['ok' => true, 'deleted' => 0, 'message' => lang('upgrade_tmp_not_exists')];
        }

        $entries = glob($this->tmpPath . '*');
        if ($entries) {
            foreach ($entries as $entry) {
                $baseName = basename($entry);
                // 保留备份目录
                if (strpos($baseName, 'upgrade_backup_') === 0) {
                    continue;
                }
                // 保留维护锁
                if ($baseName === 'maintenance.lock') {
                    continue;
                }
                // 保留 cache 子目录（缓存系统使用）
                if ($baseName === 'cache') {
                    continue;
                }
                // 保留 .htaccess 安全保护文件（防止 tmp/ 目录被 Web 访问）
                if ($baseName === '.htaccess') {
                    continue;
                }
                if (is_dir($entry)) {
                    if ($this->recursiveDelete($entry)) {
                        $deleted++;
                    }
                } else {
                    if (@unlink($entry)) {
                        $deleted++;
                    }
                }
            }
        }

        // 追加清理数据缓存和 opcache（tmp 编译缓存已由上方逻辑清理）
        // data 用 cache_delete_prefix('') 按前缀删，避免 Redis flushdb 误删 session
        $cacheRes = $this->clearCaches(['data', 'opcache']);
        $cacheMsg = ($cacheRes['ok'] && $cacheRes['message']) ? lang('upgrade_cache_cleared_prefix', array('message' => $cacheRes['message'])) : '';

        return [
            'ok' => true,
            'deleted' => $deleted,
            'message' => lang('upgrade_cleanup_done', array('n' => $deleted)) . $cacheMsg,
        ];
    }

    /**
     * 9. 重装当前版本：跳过版本对比，下载当前 conf['version'] 对应的 release 包并执行覆盖流程
     * 需要先调 Gitee API 找到 tag_name = v{current_version} 的 release
     */
    public function reinstall(): array {
        $owner = $this->getConfig('gitee_owner', '');
        $repo = $this->getConfig('gitee_repo', '');
        $apiToken = $this->getConfig('api_token', '');
        $currentVersion = isset($this->conf['version']) ? $this->conf['version'] : '0.0.0';

        if (empty($owner) || empty($repo)) {
            return ['ok' => false, 'message' => lang('upgrade_gitee_not_configured_short')];
        }

        // Gitee API 列出所有 release，找到 tag_name == v{current_version}
        $url = "https://gitee.com/api/v5/repos/{$owner}/{$repo}/releases";
        if (!empty($apiToken)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'access_token=' . $apiToken;
        }
        $resp = $this->httpGet($url);
        if (!$resp['ok']) {
            return ['ok' => false, 'message' => lang('upgrade_gitee_release_list_failed', array('error' => $resp['message']))];
        }

        $releases = json_decode($resp['data'], true);
        if (!is_array($releases)) {
            return ['ok' => false, 'message' => lang('upgrade_gitee_release_list_parse_failed')];
        }

        $targetTagV = 'v' . $currentVersion;
        $targetTagNoV = $currentVersion;
        $zipUrl = '';
        foreach ($releases as $rel) {
            $tagName = isset($rel['tag_name']) ? (string)$rel['tag_name'] : '';
            if ($tagName === $targetTagV || $tagName === $targetTagNoV) {
                if (!empty($rel['assets']) && is_array($rel['assets'])) {
                    foreach ($rel['assets'] as $asset) {
                        if (!empty($asset['browser_download_url'])) {
                            $zipUrl = $asset['browser_download_url'];
                            break 2;
                        }
                    }
                }
            }
        }

        if (empty($zipUrl)) {
            return ['ok' => false, 'message' => lang('upgrade_release_not_found', array('version' => $currentVersion))];
        }

        // 私有仓库下载需带 token
        if (!empty($apiToken) && strpos($zipUrl, 'access_token') === false) {
            $zipUrl .= (strpos($zipUrl, '?') === false ? '?' : '&') . 'access_token=' . $apiToken;
        }

        // 执行下载 -> 覆盖流程
        $downRes = $this->download($zipUrl, $currentVersion);
        if (!$downRes['ok']) {
            return ['ok' => false, 'message' => lang('upgrade_reinstall_download_failed', array('error' => $downRes['message']))];
        }

        $extractRes = $this->extractAndOverwrite($downRes['zip_path']);
        if (!$extractRes['ok']) {
            return ['ok' => false, 'message' => lang('upgrade_reinstall_extract_failed', array('error' => $extractRes['message']))];
        }

        // 重装后清全部缓存（data+tmp+opcache），确保覆盖的新源码生效
        // tmp 编译缓存（route_*.php 等）必须清，否则 _include() 仍加载旧缓存导致新代码不生效
        $cacheRes = $this->clearCaches(['data', 'tmp', 'opcache']);
        $cacheMsg = ($cacheRes['ok'] && $cacheRes['message']) ? lang('upgrade_cache_cleared_prefix', array('message' => $cacheRes['message'])) : '';

        // 递增 static_version，强制浏览器刷新 JS/CSS 缓存（重装必然伴随静态资源覆盖）
        $svMsg = '';
        if (function_exists('conf_bump_static_version')) {
            $svRes = conf_bump_static_version();
            $svMsg = $svRes['ok'] ? lang('upgrade_semicolon_prefix', array('message' => $svRes['message'])) : '';
        }

        return [
            'ok' => true,
            'message' => lang('upgrade_reinstall_done', array('version' => $currentVersion, 'message' => $extractRes['message'])) . $cacheMsg . $svMsg,
        ];
    }

    /**
     * 清理缓存：委托 CacheService::clearByType
     * data: 按前缀删数据缓存键（不影响 session）；tmp: 删编译缓存（保留 cache 子目录，glob 不匹配 .htaccess）；opcache: 清字节码
     */
    private function clearCaches(array $types): array {
        if (!class_exists('CacheService')) {
            $cacheServiceFile = APP_PATH . 'lib/CacheService.php';
            if (is_file($cacheServiceFile)) {
                include_once $cacheServiceFile;
            }
        }
        if (!class_exists('CacheService') || !method_exists('CacheService', 'clearByType')) {
            return ['ok' => false, 'cleared' => [], 'message' => ''];
        }
        $cleared = CacheService::clearByType($types);
        return ['ok' => true, 'cleared' => $cleared, 'message' => $cleared ? implode('、', $cleared) : ''];
    }

    // ===================== 辅助方法 =====================

    /**
     * 递归删除目录
     */
    private function recursiveDelete(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        return @rmdir($dir);
    }

    /**
     * 黑名单判断：相对路径以 conf/、upload/、log/、tmp/、plugin/、.trae/ 开头则跳过
     * 但 conf/conf.default.php 例外允许
     */
    private function isPathBlacklisted(string $relativePath): bool {
        $relativePath = ltrim($relativePath, '/');
        $blacklistDirs = ['conf/', 'upload/', 'log/', 'tmp/', 'plugin/', '.trae/'];

        foreach ($blacklistDirs as $dir) {
            if (strpos($relativePath, $dir) === 0) {
                // conf/conf.default.php 例外允许
                if ($dir === 'conf/' && $relativePath === 'conf/conf.default.php') {
                    return false;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * 在解压目录中查找项目根目录（包含 index.inc.php 或 model.inc.php）
     * @return string 项目根目录路径（带末尾 /），未找到返回空串
     */
    private function findProjectRoot(string $extractDir): string {
        $extractDir = rtrim($extractDir, '/') . '/';

        // 优先检查解压根目录
        if (is_file($extractDir . 'index.inc.php') || is_file($extractDir . 'model.inc.php')) {
            return $extractDir;
        }

        // 检查一级子目录
        $subDirs = glob($extractDir . '*', GLOB_ONLYDIR);
        if ($subDirs) {
            foreach ($subDirs as $sub) {
                $sub = rtrim($sub, '/') . '/';
                if (is_file($sub . 'index.inc.php') || is_file($sub . 'model.inc.php')) {
                    return $sub;
                }
            }
        }

        // 二级子目录（极少数打包结构）
        if ($subDirs) {
            foreach ($subDirs as $sub) {
                $sub = rtrim($sub, '/') . '/';
                $subSubDirs = glob($sub . '*', GLOB_ONLYDIR);
                if ($subSubDirs) {
                    foreach ($subSubDirs as $subSub) {
                        $subSub = rtrim($subSub, '/') . '/';
                        if (is_file($subSub . 'index.inc.php') || is_file($subSub . 'model.inc.php')) {
                            return $subSub;
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * HTTP GET 请求（cURL 优先，回退 file_get_contents）
     * 返回 ['ok'=>bool, 'data'=>'', 'message'=>'']
     */
    private function httpGet(string $url): array {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Xiuno-BBS-Upgrade/1.0');
            $data = curl_exec($ch);
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // PHP 8.0+ curl 句柄自动释放，curl_close() 在 8.5 已废弃
            if (PHP_VERSION_ID < 80000) curl_close($ch);
            if ($errno !== 0) {
                return ['ok' => false, 'data' => '', 'message' => lang('plugin_curl_error', array('code' => $errno, 'message' => $errmsg))];
            }
            if ($httpCode >= 400) {
                return ['ok' => false, 'data' => '', 'message' => lang('plugin_http_status', array('code' => $httpCode))];
            }
            return ['ok' => true, 'data' => $data, 'message' => ''];
        }

        // 回退 file_get_contents
        if (!ini_get('allow_url_fopen')) {
            return ['ok' => false, 'data' => '', 'message' => lang('plugin_no_curl_no_url_fopen')];
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Xiuno-BBS-Upgrade/1.0',
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            $error = error_get_last();
            $errMsg = !empty($error['message']) ? $error['message'] : lang('unknown_error');
            return ['ok' => false, 'data' => '', 'message' => lang('plugin_file_get_contents_failed', array('error' => $errMsg))];
        }
        return ['ok' => true, 'data' => $data, 'message' => ''];
    }

    /**
     * HTTP 下载文件到指定路径（cURL 优先，回退 file_get_contents）
     * 返回 ['ok'=>bool, 'message'=>'']
     */
    private function httpDownload(string $url, string $savePath): array {
        if (function_exists('curl_init')) {
            $fp = @fopen($savePath, 'wb');
            if (!$fp) {
                return ['ok' => false, 'message' => lang('upgrade_write_file_failed', array('path' => $savePath))];
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Xiuno-BBS-Upgrade/1.0');
            curl_exec($ch);
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // PHP 8.0+ curl 句柄自动释放，curl_close() 在 8.5 已废弃
            if (PHP_VERSION_ID < 80000) curl_close($ch);
            fclose($fp);
            if ($errno !== 0) {
                @unlink($savePath);
                return ['ok' => false, 'message' => lang('upgrade_curl_download_error', array('code' => $errno, 'message' => $errmsg))];
            }
            if ($httpCode >= 400) {
                @unlink($savePath);
                return ['ok' => false, 'message' => lang('upgrade_http_status_download', array('code' => $httpCode))];
            }
            return ['ok' => true, 'message' => ''];
        }

        // 回退 file_get_contents
        if (!ini_get('allow_url_fopen')) {
            return ['ok' => false, 'message' => lang('plugin_no_curl_no_url_fopen')];
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 300,
                'user_agent' => 'Xiuno-BBS-Upgrade/1.0',
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            $error = error_get_last();
            $errMsg = !empty($error['message']) ? $error['message'] : lang('unknown_error');
            return ['ok' => false, 'message' => lang('upgrade_file_get_contents_download_failed', array('error' => $errMsg))];
        }
        if (@file_put_contents($savePath, $data) === false) {
            return ['ok' => false, 'message' => lang('download_write_file_failed', array('path' => $savePath))];
        }
        return ['ok' => true, 'message' => ''];
    }

    /**
     * 诊断：用于排查「升级后版本号未变」问题
     * 输出当前 version.php 内容、Gitee release 信息、升级包内 version.php 内容、tmp 缓存状态、备份目录对比
     * 不修改任何文件，只读
     */
    public function diagnose(): array {
        $result = [];

        // 1. 运行时常量与配置
        $result['runtime'] = [
            'XIUNOX_VERSION' => defined('XIUNOX_VERSION') ? XIUNOX_VERSION : '(未定义)',
            'conf.version' => isset($this->conf['version']) ? $this->conf['version'] : '(未设置)',
            'APP_PATH' => APP_PATH,
            'tmpPath' => $this->tmpPath,
            'APP_PATH_writable' => is_writable(APP_PATH),
            'tmpPath_writable' => is_writable($this->tmpPath),
            'DEBUG' => defined('DEBUG') ? DEBUG : '(未定义)',
        ];

        // 2. 服务器上 version.php 实际内容
        $versionFile = APP_PATH . 'version.php';
        $result['server_version_php'] = [
            'path' => $versionFile,
            'exists' => is_file($versionFile),
            'mtime' => is_file($versionFile) ? date('Y-m-d H:i:s', filemtime($versionFile)) : '',
            'content' => is_file($versionFile) ? file_get_contents($versionFile) : '(文件不存在)',
        ];

        // 3. tmp/ 目录下的编译缓存文件
        $tmpFiles = [];
        if (is_dir($this->tmpPath)) {
            $dirIt = new DirectoryIterator($this->tmpPath);
            foreach ($dirIt as $f) {
                if ($f->isDot() || $f->isDir()) continue;
                $name = $f->getFilename();
                // 只关注 min.php、upgrade_*.zip、maintenance.lock
                if (preg_match('/\.min\.php$/', $name) || preg_match('/^upgrade_.*\.zip$/', $name) || $name === 'maintenance.lock') {
                    $content = '';
                    if (preg_match('/\.min\.php$/', $name)) {
                        // 检查合并缓存里是否含 XIUNOX_VERSION 定义
                        $raw = @file_get_contents($f->getPathname());
                        if ($raw !== false && strpos($raw, 'XIUNOX_VERSION') !== false) {
                            // 提取匹配行
                            if (preg_match("/define\('XIUNOX_VERSION',\s*'[^']+'\)/", $raw, $m)) {
                                $content = $m[0];
                            } else {
                                $content = '(包含 XIUNOX_VERSION 但格式不匹配)';
                            }
                        } else {
                            $content = '(不含 XIUNOX_VERSION)';
                        }
                    }
                    $tmpFiles[] = [
                        'name' => $name,
                        'mtime' => date('Y-m-d H:i:s', $f->getMTime()),
                        'size' => $f->getSize(),
                        'xiunox_version_line' => $content,
                    ];
                }
            }
        }
        $result['tmp_files'] = $tmpFiles;

        // 4. 列出升级备份目录及其 version.php 内容（对比升级前后是否变化）
        $backups = [];
        if (is_dir($this->tmpPath)) {
            $dirs = glob($this->tmpPath . 'upgrade_backup_*', GLOB_ONLYDIR);
            if ($dirs) {
                // 按 mtime 倒序，最新的在前
                usort($dirs, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                foreach ($dirs as $dir) {
                    $bkVersionFile = $dir . '/version.php';
                    $version = '(无 version.php)';
                    if (is_file($bkVersionFile)) {
                        $raw = @file_get_contents($bkVersionFile);
                        if (preg_match("/define\('XIUNOX_VERSION',\s*'([^']+)'\)/", $raw, $m)) {
                            $version = $m[1];
                        }
                    }
                    $backups[] = [
                        'dir' => basename($dir),
                        'mtime' => date('Y-m-d H:i:s', filemtime($dir)),
                        'version' => $version,
                    ];
                }
            }
        }
        $result['backups'] = $backups;

        // 5. Gitee 最新 release 信息 + 升级包内 version.php 内容
        $releaseInfo = $this->checkLatestVersion();
        $giteeInfo = [
            'ok' => $releaseInfo['ok'],
            'message' => isset($releaseInfo['message']) ? $releaseInfo['message'] : '',
            'latest_version' => isset($releaseInfo['latest_version']) ? $releaseInfo['latest_version'] : '',
            'current_version' => isset($releaseInfo['current_version']) ? $releaseInfo['current_version'] : '',
            'has_update' => isset($releaseInfo['has_update']) ? $releaseInfo['has_update'] : false,
            'zip_url' => isset($releaseInfo['zip_url']) ? $releaseInfo['zip_url'] : '',
            'release_notes_preview' => isset($releaseInfo['release_notes']) ? mb_substr($releaseInfo['release_notes'], 0, 200) : '',
        ];

        // 6. 下载升级包到 tmp/diagnose_{ts}.zip，用 ZipArchive 直接读 version.php 内容（不解压）
        if (!empty($giteeInfo['zip_url'])) {
            $diagZip = $this->tmpPath . 'diagnose_' . date('YmdHis') . '.zip';
            $dl = $this->httpDownload($giteeInfo['zip_url'], $diagZip);
            if (!$dl['ok']) {
                $giteeInfo['zip_download'] = lang('upgrade_diag_zip_download_failed', array('error' => $dl['message']));
            } else {
                $giteeInfo['zip_download'] = lang('upgrade_diag_zip_download_ok', array('size' => filesize($diagZip)));
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    $openRes = $zip->open($diagZip);
                    if ($openRes === true) {
                        $zipVersionContent = '(未找到 version.php)';
                        // 升级包可能含一层仓库名目录，遍历找 version.php
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $entry = $zip->getNameIndex($i);
                            if (preg_match('#(^|/)version\.php$#', $entry)) {
                                $zipVersionContent = $zip->getFromIndex($i);
                                // 提取版本号
                                if (preg_match("/define\('XIUNOX_VERSION',\s*'([^']+)'\)/", $zipVersionContent, $m)) {
                                    $giteeInfo['zip_version_php_version'] = $m[1];
                                }
                                $giteeInfo['zip_version_php_path'] = $entry;
                                $giteeInfo['zip_version_php_content'] = $zipVersionContent;
                                break;
                            }
                        }
                        $zip->close();
                    } else {
                        $giteeInfo['zip_open_error'] = lang('plugin_zip_open_failed', array('code' => $openRes));
                    }
                } else {
                    $giteeInfo['zip_open_error'] = lang('plugin_ziparchive_not_installed');
                }
                // 清理诊断临时 zip
                @unlink($diagZip);
            }
        }
        $result['gitee'] = $giteeInfo;

        return ['ok' => true, 'data' => $result];
    }
}
