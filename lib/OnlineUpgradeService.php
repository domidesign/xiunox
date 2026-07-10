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
                'message' => '未配置 Gitee 仓库信息（gitee_owner / gitee_repo）',
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
                'message' => '请求 Gitee API 失败：' . $resp['message'],
            ];
        }

        $data = json_decode($resp['data'], true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return [
                'ok' => false,
                'has_update' => false,
                'current_version' => $currentVersion,
                'message' => '解析 Gitee Release 响应失败',
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
            'message' => $hasUpdate ? "发现新版本 {$latestVersion}" : '已是最新版本',
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
            $warnings[] = 'PHP 版本 ' . PHP_VERSION . ' < 8.0，需先升级 PHP';
        }

        $freeSpace = @disk_free_space(APP_PATH);
        if ($freeSpace !== false && $freeSpace < 200 * 1024 * 1024) {
            $ok = false;
            $warnings[] = '磁盘空间不足（需至少 200MB）：' . round($freeSpace / 1024 / 1024, 1) . 'MB';
        }

        if (!class_exists('ZipArchive')) {
            $ok = false;
            $warnings[] = '未安装 ZipArchive 扩展，无法解压升级包';
        }

        $hasCurl = function_exists('curl_init');
        $allowUrlFopen = (bool)ini_get('allow_url_fopen');
        if (!$hasCurl && !$allowUrlFopen) {
            $ok = false;
            $warnings[] = '既无 cURL 扩展也未开启 allow_url_fopen，无法下载升级包';
        }

        // APP_PATH 不可写改为非阻断警告：升级包覆盖阶段若涉及根目录文件会失败，
        // extractAndOverwrite 会逐文件报错；此处不阻断以便宝塔等严格权限环境可继续尝试
        if (!is_writable(APP_PATH)) {
            $warnings[] = 'APP_PATH 目录不可写（不阻断，建议 chown -R www:www ' . APP_PATH . '）：覆盖根目录文件时可能失败';
        }

        if (!is_writable($this->tmpPath)) {
            $ok = false;
            $warnings[] = 'tmp 目录不可写：' . $this->tmpPath;
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
            'message' => $ok ? '前置检查通过' : '存在 ' . count($warnings) . ' 个问题',
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
            return ['ok' => false, 'message' => '写入维护锁失败：' . $this->lockFile];
        }
        return ['ok' => true, 'message' => '维护模式已开启', 'lock_file' => $this->lockFile];
    }

    /**
     * 3.2 关闭维护模式：删 lock
     */
    public function maintenanceOff(): array {
        if (file_exists($this->lockFile)) {
            if (!@unlink($this->lockFile)) {
                return ['ok' => false, 'message' => '删除维护锁失败：' . $this->lockFile];
            }
        }
        return ['ok' => true, 'message' => '维护模式已关闭'];
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
            return ['ok' => false, 'message' => '下载 URL 为空'];
        }

        if (!is_dir($this->tmpPath)) {
            $oldUmask = umask(0);
            @mkdir($this->tmpPath, 0755, true);
            umask($oldUmask);
        }

        $zipPath = $this->tmpPath . 'upgrade_' . $version . '.zip';
        $r = $this->httpDownload($zipUrl, $zipPath);
        if (!$r['ok']) {
            return ['ok' => false, 'message' => '下载失败：' . $r['message']];
        }

        $size = filesize($zipPath);
        if ($size === false || $size <= 0) {
            @unlink($zipPath);
            return ['ok' => false, 'message' => '下载文件大小为 0，可能下载失败'];
        }

        return [
            'ok' => true,
            'zip_path' => $zipPath,
            'size' => $size,
            'message' => '下载完成，大小 ' . round($size / 1024 / 1024, 2) . 'MB',
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
            return ['ok' => false, 'message' => 'zip 文件不存在：' . $zipPath];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => '未安装 ZipArchive 扩展'];
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
            return ['ok' => false, 'message' => 'ZipArchive::open 失败，错误码：' . $openRes];
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            return ['ok' => false, 'message' => '解压失败'];
        }
        $zip->close();

        // 解压后的目录结构可能为：extractDir/{repo-name}/... 或 extractDir/...
        // 找到实际的项目根目录（包含 index.inc.php 或 model.inc.php 的目录）
        $projectRoot = $this->findProjectRoot($extractDir);
        if ($projectRoot === '') {
            $this->recursiveDelete($extractDir);
            return ['ok' => false, 'message' => '未找到项目根目录（缺少 index.inc.php 或 model.inc.php）'];
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
            $more = count($failed) > 20 ? '（共 ' . count($failed) . ' 个，仅展示前 20 个）' : '';
            return [
                'ok' => false,
                'extracted' => $extracted,
                'skipped' => $skipped,
                'failed' => $failed,
                'message' => "覆盖失败 " . count($failed) . " 个文件{$more}：\n" . implode("\n", $show) . "\n\n建议执行：chown -R www:www " . APP_PATH,
            ];
        }

        return [
            'ok' => true,
            'extracted' => $extracted,
            'skipped' => $skipped,
            'message' => "覆盖完成，覆盖 {$extracted} 个文件，跳过 {$skipped} 个黑名单文件",
        ];
    }

    /**
     * 7. 跑 DB 升级：复用 UpgradeService，顺序执行 getSteps() 返回的全部步骤
     */
    public function runDbUpgrade(): array {
        $upgradeServiceFile = APP_PATH . 'lib/UpgradeService.php';
        if (!is_file($upgradeServiceFile)) {
            return ['ok' => false, 'message' => 'UpgradeService.php 不存在', 'results' => []];
        }
        if (!class_exists('UpgradeService')) {
            include_once $upgradeServiceFile;
        }
        if (!class_exists('UpgradeService')) {
            return ['ok' => false, 'message' => 'UpgradeService 类加载失败', 'results' => []];
        }

        $service = new UpgradeService($this->db, $this->conf);
        $steps = $service->getSteps();
        $results = [];
        $allOk = true;

        foreach ($steps as $step) {
            $stepId = isset($step['id']) ? $step['id'] : '';
            $stepName = isset($step['name']) ? $step['name'] : $stepId;
            if (empty($stepId)) {
                continue;
            }
            $r = $service->executeStep($stepId);
            $results[] = [
                'id' => $stepId,
                'name' => $stepName,
                'ok' => !empty($r['ok']),
                'message' => isset($r['message']) ? $r['message'] : '',
            ];
            if (empty($r['ok'])) {
                $allOk = false;
            }
        }

        return [
            'ok' => $allOk,
            'results' => $results,
            'message' => $allOk ? '数据库升级全部成功' : '部分数据库升级步骤失败',
        ];
    }

    /**
     * 8. 清理：删 tmp/*（保留 upgrade_backup_* 目录和 maintenance.lock）
     */
    public function cleanup(): array {
        $deleted = 0;
        if (!is_dir($this->tmpPath)) {
            return ['ok' => true, 'deleted' => 0, 'message' => 'tmp 目录不存在'];
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
        $cacheMsg = ($cacheRes['ok'] && $cacheRes['message']) ? '；已清 ' . $cacheRes['message'] : '';

        return [
            'ok' => true,
            'deleted' => $deleted,
            'message' => "清理完成，删除 {$deleted} 个文件/目录" . $cacheMsg,
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
            return ['ok' => false, 'message' => '未配置 Gitee 仓库信息'];
        }

        // Gitee API 列出所有 release，找到 tag_name == v{current_version}
        $url = "https://gitee.com/api/v5/repos/{$owner}/{$repo}/releases";
        if (!empty($apiToken)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'access_token=' . $apiToken;
        }
        $resp = $this->httpGet($url);
        if (!$resp['ok']) {
            return ['ok' => false, 'message' => '请求 Gitee Release 列表失败：' . $resp['message']];
        }

        $releases = json_decode($resp['data'], true);
        if (!is_array($releases)) {
            return ['ok' => false, 'message' => '解析 Gitee Release 列表失败'];
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
            return ['ok' => false, 'message' => "未在 Gitee Release 中找到当前版本 {$currentVersion} 的下载包"];
        }

        // 私有仓库下载需带 token
        if (!empty($apiToken) && strpos($zipUrl, 'access_token') === false) {
            $zipUrl .= (strpos($zipUrl, '?') === false ? '?' : '&') . 'access_token=' . $apiToken;
        }

        // 执行下载 -> 覆盖流程
        $downRes = $this->download($zipUrl, $currentVersion);
        if (!$downRes['ok']) {
            return ['ok' => false, 'message' => '重装下载失败：' . $downRes['message']];
        }

        $extractRes = $this->extractAndOverwrite($downRes['zip_path']);
        if (!$extractRes['ok']) {
            return ['ok' => false, 'message' => '重装覆盖失败：' . $extractRes['message']];
        }

        // 重装后清全部缓存（data+tmp+opcache），确保覆盖的新源码生效
        // tmp 编译缓存（route_*.php/model.min.php 等）必须清，否则 _include() 仍加载旧缓存导致新代码不生效
        $cacheRes = $this->clearCaches(['data', 'tmp', 'opcache']);
        $cacheMsg = ($cacheRes['ok'] && $cacheRes['message']) ? '；已清 ' . $cacheRes['message'] : '';

        return [
            'ok' => true,
            'message' => "已重装当前版本 {$currentVersion}：" . $extractRes['message'] . $cacheMsg,
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
                return ['ok' => false, 'data' => '', 'message' => 'cURL 错误[' . $errno . ']: ' . $errmsg];
            }
            if ($httpCode >= 400) {
                return ['ok' => false, 'data' => '', 'message' => 'HTTP 状态码 ' . $httpCode];
            }
            return ['ok' => true, 'data' => $data, 'message' => ''];
        }

        // 回退 file_get_contents
        if (!ini_get('allow_url_fopen')) {
            return ['ok' => false, 'data' => '', 'message' => '未开启 allow_url_fopen 且无 cURL'];
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
            $errMsg = !empty($error['message']) ? $error['message'] : '未知错误';
            return ['ok' => false, 'data' => '', 'message' => 'file_get_contents 失败: ' . $errMsg];
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
                return ['ok' => false, 'message' => '无法写入文件：' . $savePath];
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
                return ['ok' => false, 'message' => 'cURL 下载错误[' . $errno . ']: ' . $errmsg];
            }
            if ($httpCode >= 400) {
                @unlink($savePath);
                return ['ok' => false, 'message' => '下载 HTTP 状态码 ' . $httpCode];
            }
            return ['ok' => true, 'message' => ''];
        }

        // 回退 file_get_contents
        if (!ini_get('allow_url_fopen')) {
            return ['ok' => false, 'message' => '未开启 allow_url_fopen 且无 cURL'];
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
            $errMsg = !empty($error['message']) ? $error['message'] : '未知错误';
            return ['ok' => false, 'message' => 'file_get_contents 下载失败: ' . $errMsg];
        }
        if (@file_put_contents($savePath, $data) === false) {
            return ['ok' => false, 'message' => '写入文件失败：' . $savePath];
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

        // 3. tmp/ 目录下的合并缓存文件（DEBUG=0 时会走 model.min.php 合并加载）
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
                $giteeInfo['zip_download'] = '失败：' . $dl['message'];
            } else {
                $giteeInfo['zip_download'] = '成功，size=' . filesize($diagZip);
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
                        $giteeInfo['zip_open_error'] = 'ZipArchive::open 失败，错误码：' . $openRes;
                    }
                } else {
                    $giteeInfo['zip_open_error'] = '未安装 ZipArchive 扩展';
                }
                // 清理诊断临时 zip
                @unlink($diagZip);
            }
        }
        $result['gitee'] = $giteeInfo;

        return ['ok' => true, 'data' => $result];
    }
}
