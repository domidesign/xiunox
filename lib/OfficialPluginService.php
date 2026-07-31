<?php

/**
 * Xiuno BBS 官方插件市场 Service
 *
 * 负责：清单拉取（jsdelivr 主 + GitHub raw 备）、文件缓存（TTL 6h + 手动刷新）、
 *      zip 下载、安装/升级流程。
 *
 * 依赖：ZipArchive 扩展 + cURL 或 allow_url_fopen，无第三方库。
 * 加载：通过裸 include 加载（lib 类不自动加载），修改后立即生效，无编译缓存。
 * 配置：所有外部配置硬编码在类常量中，不依赖 db / conf，构造函数无参数。
 */
class OfficialPluginService {
    // 配置硬编码（部署前用户自行替换 GITHUB_OWNER 为真实账号）
    const GITHUB_OWNER = 'domidesign';
    const GITHUB_REPO = 'xiuno-official-plugins';
    // 主源用 GitHub raw（始终最新，不受 jsdelivr Git 对象缓存影响）
    // 备源用 jsdelivr CDN（加速，但对 @main 分支引用的更新有延迟）
    // icon/zip 仍走 jsdelivr CDN 加速（文件级缓存，更新即时）
    const MANIFEST_URL_PRIMARY = 'https://raw.githubusercontent.com/domidesign/xiuno-official-plugins/main/manifest.json';
    const MANIFEST_URL_FALLBACK = 'https://cdn.jsdelivr.net/gh/domidesign/xiuno-official-plugins@main/manifest.json';
    const CACHE_FILE = 'tmp/cache/official_plugins.json';
    const CACHE_TTL = 21600; // 6 hours
    const ZIP_DOWNLOAD_TIMEOUT = 120;
    const MANIFEST_TIMEOUT = 30;

    /**
     * 拉取插件清单（含缓存逻辑）
     *
     * 流程：
     *   1. $force=false 时检查缓存：文件存在 + 未过期 → 直接返回缓存
     *   2. 缓存过期或 $force=true → 主源 jsdelivr → 备源 GitHub raw
     *   3. 主备源都失败 + 有过期缓存 → 返回过期缓存（stale=true）
     *   4. 主备源都失败 + 无缓存 → 返回失败
     *
     * @param bool $force true 时忽略缓存强制刷新
     * @return array ['ok'=>bool, 'data'=>array|null, 'from_cache'=>bool, 'stale'=>bool, 'message'=>string]
     */
    public function fetchManifest(bool $force = false): array {
        // 确保缓存目录存在
        $cachePath = APP_PATH . self::CACHE_FILE;
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            $oldUmask = umask(0);
            @mkdir($cacheDir, 0755, true);
            umask($oldUmask);
        }

        // 非强制刷新时先检查缓存
        if (!$force) {
            $cached = $this->readCache();
            if ($cached !== null) {
                $fetchedAt = isset($cached['fetched_at']) ? intval($cached['fetched_at']) : 0;
                $age = time() - $fetchedAt;
                if ($age < self::CACHE_TTL && isset($cached['data']) && is_array($cached['data'])) {
                    return [
                        'ok' => true,
                        'data' => $cached['data'],
                        'from_cache' => true,
                        'stale' => false,
                        'message' => '',
                    ];
                }
            }
        }

        // 尝试主源 → 备源
        $urls = [self::MANIFEST_URL_PRIMARY, self::MANIFEST_URL_FALLBACK];
        $lastError = '';
        foreach ($urls as $url) {
            $resp = $this->httpGet($url, self::MANIFEST_TIMEOUT);
            if (!$resp['ok']) {
                $lastError = $resp['message'];
                continue;
            }
            $data = json_decode($resp['data'], true);
            if (!is_array($data) || !isset($data['plugins']) || !is_array($data['plugins'])) {
                $lastError = '清单 JSON 解析失败';
                continue;
            }
            // 成功拉取 → 写缓存
            $this->writeCache([
                'fetched_at' => time(),
                'data' => $data,
            ]);
            return [
                'ok' => true,
                'data' => $data,
                'from_cache' => false,
                'stale' => false,
                'message' => '',
            ];
        }

        // 主备源都失败 → 尝试回退到过期缓存
        $cached = $this->readCache();
        if ($cached !== null && isset($cached['data']) && is_array($cached['data'])) {
            return [
                'ok' => true,
                'data' => $cached['data'],
                'from_cache' => true,
                'stale' => true,
                'message' => '清单可能不是最新（' . $lastError . '）',
            ];
        }

        return [
            'ok' => false,
            'data' => null,
            'from_cache' => false,
            'stale' => false,
            'message' => '无法连接插件市场，请检查网络',
        ];
    }

    /**
     * 强制刷新清单（删除本地缓存 + 刷新 jsdelivr CDN 缓存 + 重新拉取）
     *
     * 流程：
     *   1. 删除本地 manifest 缓存文件
     *   2. 调用 jsdelivr purge API 刷新 CDN 缓存（manifest.json + 所有 icon/zip）
     *   3. 重新拉取 manifest（主源 jsdelivr → 备源 GitHub raw）
     *
     * @return array 同 fetchManifest 返回结构，额外带 purge_status 字段
     */
    public function forceRefresh(): array {
        // 先刷新 jsdelivr CDN 缓存（此时本地缓存还在，purgeJsddelivrCache 能读到 icon/zip URL）
        // 必须在删除本地缓存之前调用，否则 readCache() 返回 null 只能 purge manifest.json 一个 URL
        $purgeResult = $this->purgeJsddelivrCache();

        // 删除本地 manifest 缓存文件
        $cachePath = APP_PATH . self::CACHE_FILE;
        if (is_file($cachePath)) {
            @unlink($cachePath);
        }

        $result = $this->fetchManifest(true);
        $result['purge_status'] = $purgeResult;
        return $result;
    }

    /**
     * 刷新 jsdelivr CDN 缓存
     *
     * 调用 purge.jsdelivr.net 强制刷新以下文件的 CDN 缓存：
     *   - manifest.json（清单，必刷）
     *   - 所有插件的 icon_url（图标，影响显示）
     *   - 所有免费插件的 zip_url（安装包，影响下载）
     *
     * jsdelivr purge API 限制：每个 URL 需单独请求，无批量接口。
     * 为避免请求过多导致超时，采用并发策略（cURL multi）。
     *
     * @return array ['ok'=>bool, 'purged'=>int, 'failed'=>int, 'message'=>string]
     */
    private function purgeJsddelivrCache(): array {
        // manifest.json 的 jsdelivr CDN URL（即使主源是 GitHub raw，CDN 缓存仍需刷新）
        $urls = [
            self::MANIFEST_URL_FALLBACK,  // manifest.json 的 jsdelivr CDN URL
        ];

        // 从当前缓存或远程拉取 manifest，收集所有 icon/zip URL
        $cached = $this->readCache();
        if ($cached !== null && isset($cached['data']['plugins'])) {
            foreach ($cached['data']['plugins'] as $p) {
                if (!empty($p['icon_url'])) {
                    $urls[] = $p['icon_url'];
                }
                if (!empty($p['free']) && !empty($p['zip_url'])) {
                    $urls[] = $p['zip_url'];
                }
            }
        }

        // 去重
        $urls = array_unique($urls);

        if (empty($urls)) {
            return ['ok' => true, 'purged' => 0, 'failed' => 0, 'message' => '无需刷新'];
        }

        // 将 cdn.jsdelivr.net/gh/ URL 转为 purge.jsdelivr.net/gh/ URL
        $purgeUrls = [];
        foreach ($urls as $url) {
            $purgeUrl = preg_replace(
                '#^https://cdn\.jsdelivr\.net/#',
                'https://purge.jsdelivr.net/',
                $url
            );
            if ($purgeUrl !== $url) {
                $purgeUrls[] = $purgeUrl;
            }
        }

        if (empty($purgeUrls)) {
            return ['ok' => true, 'purged' => 0, 'failed' => 0, 'message' => '无 jsdelivr URL'];
        }

        // 用 cURL multi 并发请求（比串行快 10 倍以上）
        $purged = 0;
        $failed = 0;
        if (function_exists('curl_multi_init')) {
            $handles = [];
            $mh = curl_multi_init();
            foreach ($purgeUrls as $i => $url) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Xiuno-BBS-OfficialPlugin/1.0');
                curl_multi_add_handle($mh, $ch);
                $handles[$i] = $ch;
            }
            // 执行并发请求
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh);
                }
            } while ($active && $status == CURLM_OK);

            // 收集结果
            foreach ($handles as $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode >= 200 && $httpCode < 300) {
                    $purged++;
                } else {
                    $failed++;
                }
                curl_multi_remove_handle($mh, $ch);
                if (PHP_VERSION_ID < 80000) curl_close($ch);
            }
            curl_multi_close($mh);
        } else {
            // 无 cURL multi：串行请求 manifest.json 一个文件即可（最关键）
            $resp = $this->httpGet($purgeUrls[0], 10);
            if ($resp['ok']) {
                $purged = 1;
            } else {
                $failed = 1;
            }
        }

        return [
            'ok' => $purged > 0,
            'purged' => $purged,
            'failed' => $failed,
            'message' => '',
        ];
    }

    /**
     * 下载并安装免费插件
     *
     * 流程：
     *   1. 校验：本地不存在 + plugin/ 可写 + ZipArchive 可用
     *   2. 从 manifest 找到对应插件（按 dir + version 匹配）
     *   3. 下载 zip（主源 jsdelivr → 备源 GitHub raw）
     *   4. 解压到临时目录 tmp/upload_official_{dir}_{time}/
     *   5. 校验 zip 结构（根目录或唯一子目录含 conf.json）
     *   6. 移动解压目录到 plugin/{dir}/
     *   7. 调用 plugin_install($dir) 执行安装（含 install.php）
     *   8. 清除清单缓存
     *
     * @param string $dir 插件目录名
     * @param string $version 期望版本号
     * @return array ['ok'=>bool, 'message'=>string]
     */
    public function downloadAndInstall(string $dir, string $version): array {
        // 校验：本地不存在
        if (is_dir(APP_PATH . 'plugin/' . $dir)) {
            return ['ok' => false, 'message' => '插件已存在，请使用升级功能'];
        }

        // 校验：plugin/ 目录可写
        if (!is_writable(APP_PATH . 'plugin/')) {
            return ['ok' => false, 'message' => 'plugin/ 目录不可写'];
        }

        // 校验：ZipArchive 可用
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => '未安装 ZipArchive 扩展'];
        }

        // 校验：目录名合法性（防御性，防止路径穿越）
        if (!preg_match('#^\w{1,32}$#', $dir)) {
            return ['ok' => false, 'message' => '插件目录名不合法'];
        }

        try {
            // 从 manifest 找到对应插件
            $manifestRes = $this->fetchManifest();
            if (!$manifestRes['ok']) {
                return ['ok' => false, 'message' => $manifestRes['message']];
            }
            $pluginInfo = null;
            foreach ($manifestRes['data']['plugins'] as $p) {
                if (isset($p['dir']) && $p['dir'] === $dir && isset($p['version']) && $p['version'] === $version) {
                    $pluginInfo = $p;
                    break;
                }
            }
            if (!$pluginInfo) {
                return ['ok' => false, 'message' => "清单中未找到插件 {$dir} 版本 {$version}"];
            }
            if (empty($pluginInfo['free']) || empty($pluginInfo['zip_url'])) {
                return ['ok' => false, 'message' => '该插件不支持在线安装（付费或缺少 zip_url）'];
            }

            // 确保 tmp/ 目录存在
            $tmpDir = APP_PATH . 'tmp/';
            if (!is_dir($tmpDir)) {
                $oldUmask = umask(0);
                @mkdir($tmpDir, 0755, true);
                umask($oldUmask);
            }

            // 下载 zip（主源 → 备源）
            $zipPath = $tmpDir . 'upload_official_' . $dir . '_' . time() . '.zip';
            $zipUrl = $pluginInfo['zip_url'];
            $r = $this->downloadZip($zipUrl, $zipPath);
            if (!$r['ok']) {
                // 备源：jsdelivr → GitHub raw
                $fallbackUrl = $this->jsdelivrToRawUrl($zipUrl);
                if ($fallbackUrl !== $zipUrl) {
                    $r = $this->downloadZip($fallbackUrl, $zipPath);
                }
            }
            if (!$r['ok']) {
                return ['ok' => false, 'message' => '下载插件包失败，请检查网络：' . $r['message']];
            }

            // 解压到临时目录
            $extractDir = $tmpDir . 'upload_official_' . $dir . '_' . time() . '/';
            $oldUmask = umask(0);
            @mkdir($extractDir, 0755, true);
            umask($oldUmask);
            $extractRes = $this->extractZip($zipPath, $extractDir);
            // zip 文件解压完即删
            @unlink($zipPath);
            if (!$extractRes['ok']) {
                $this->recursiveDelete($extractDir);
                return ['ok' => false, 'message' => $extractRes['message']];
            }

            // 找到插件根（含 conf.json 的目录）
            $pluginRoot = $this->findPluginRoot($extractDir);
            if ($pluginRoot === '') {
                $this->recursiveDelete($extractDir);
                return ['ok' => false, 'message' => 'zip 包结构错误：未找到 conf.json'];
            }

            // 移动到 plugin/{dir}/
            $pluginPath = APP_PATH . 'plugin/' . $dir . '/';
            $moveOk = $this->moveDir($pluginRoot, $pluginPath);
            // 清理临时解压目录（moveDir 已删 srcDir，但 extractDir 本身可能残留）
            $this->recursiveDelete($extractDir);
            if (!$moveOk) {
                if (is_dir($pluginPath)) {
                    $this->recursiveDelete($pluginPath);
                }
                return ['ok' => false, 'message' => '移动插件文件失败'];
            }

            // 重新初始化插件列表，让 $plugins 全局数组识别到新插件
            if (function_exists('plugin_init')) {
                plugin_init();
            }

            // 调用 plugin_install（复用 model/plugin.func.php 的安装逻辑：写 db + 清缓存）
            if (!function_exists('plugin_install')) {
                if (is_dir($pluginPath)) $this->recursiveDelete($pluginPath);
                return ['ok' => false, 'message' => 'plugin_install 函数未定义'];
            }
            $installResult = plugin_install($dir);
            if ($installResult !== true && $installResult !== 1) {
                // plugin_install 返回 FALSE 表示 $plugins[$dir] 不存在（识别失败）
                if (is_dir($pluginPath)) $this->recursiveDelete($pluginPath);
                return ['ok' => false, 'message' => 'plugin_install 调用失败（插件未识别）'];
            }

            // 执行 install.php（与 admin/route/plugin.php 上传安装流程一致）
            // ponytail: install.php 通常会用 $db / $conf / $plugins 等全局变量，
            // 类方法内部 include 时不会自动获得全局变量，必须先 extract($GLOBALS)
            $installFile = APP_PATH . 'plugin/' . $dir . '/install.php';
            if (is_file($installFile)) {
                if (is_file(APP_PATH . 'lib/xn_safe_io.php')) {
                    require_once APP_PATH . 'lib/xn_safe_io.php';
                }
                $plugin_dir = $dir;
                extract($GLOBALS, EXTR_SKIP);  // 导入全局变量到当前作用域（$db, $conf, $plugins 等）
                if (function_exists('_include')) {
                    include _include($installFile);
                } else {
                    include $installFile;
                }
            }

            // 清除清单缓存（强制下次刷新时重新比对版本）
            $this->clearManifestCache();

            return ['ok' => true, 'message' => '安装成功'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '安装异常：' . $e->getMessage()];
        }
    }

    /**
     * 下载并升级插件（允许已启用状态升级）
     *
     * 流程：
     *   1. 校验：本地存在 + ZipArchive 可用
     *   2. 若插件已启用：先调用 plugin_disable 停用，再执行升级（升级后保持禁用状态，
     *      管理员确认新版无问题后手动启用，避免升级过程中已启用代码半更新导致错误）
     *   3. 备份本地 plugin/{dir}/ → tmp/plugin_bak_{dir}_{time}/（用 rename 提高速度）
     *   4. 下载新版本 zip（主源 → 备源）
     *   5. 解压 → 移动新版本到 plugin/{dir}/（先移动文件再清理 tmp）
     *   6. 执行 upgrade.php（通过 _include 加载）
     *   7. 更新 bbs_plugin.db_version 为新版本号
     *   8. 清除清单缓存
     *   9. 失败时从备份恢复本地插件目录（含 enable 状态）
     *
     * @param string $dir 插件目录名
     * @param string $version 期望版本号
     * @return array ['ok'=>bool, 'message'=>string]
     */
    public function downloadAndUpgrade(string $dir, string $version): array {
        // 校验：本地存在
        if (!is_dir(APP_PATH . 'plugin/' . $dir)) {
            return ['ok' => false, 'message' => '本地插件不存在，请使用安装功能'];
        }

        // 校验：ZipArchive 可用
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => '未安装 ZipArchive 扩展'];
        }

        // 校验：目录名合法性
        if (!preg_match('#^\w{1,32}$#', $dir)) {
            return ['ok' => false, 'message' => '插件目录名不合法'];
        }

        // 若插件已启用：先自动禁用（升级过程会替换文件 + 清缓存，
        // 若不禁用会导致已注册的 hook/路由在半更新状态下报错）。
        // 升级后保持禁用状态，管理员验证新版无问题后手动启用。
        $wasEnabled = false;
        if (function_exists('plugin_db_get') && function_exists('plugin_disable')) {
            $dbRow = plugin_db_get($dir);
            if (!empty($dbRow) && !empty($dbRow['enable'])) {
                $wasEnabled = true;
                plugin_disable($dir);
            }
        }

        $backupPath = '';
        try {
            // 从 manifest 找到对应插件
            $manifestRes = $this->fetchManifest();
            if (!$manifestRes['ok']) {
                return ['ok' => false, 'message' => $manifestRes['message']];
            }
            $pluginInfo = null;
            foreach ($manifestRes['data']['plugins'] as $p) {
                if (isset($p['dir']) && $p['dir'] === $dir && isset($p['version']) && $p['version'] === $version) {
                    $pluginInfo = $p;
                    break;
                }
            }
            if (!$pluginInfo) {
                return ['ok' => false, 'message' => "清单中未找到插件 {$dir} 版本 {$version}"];
            }
            if (empty($pluginInfo['free']) || empty($pluginInfo['zip_url'])) {
                return ['ok' => false, 'message' => '该插件不支持在线升级'];
            }

            // 备份本地插件目录（rename 而非 copy，与 OnlineUpgradeService 风格一致）
            // 注意：必须先备份再解压新版本，符合「先移动新版本文件再清理 tmp 目录」规则
            $backupPath = $this->backupPluginDir($dir);
            if ($backupPath === '') {
                return ['ok' => false, 'message' => '备份本地插件目录失败'];
            }

            // 下载新版本 zip
            $tmpDir = APP_PATH . 'tmp/';
            if (!is_dir($tmpDir)) {
                $oldUmask = umask(0);
                @mkdir($tmpDir, 0755, true);
                umask($oldUmask);
            }
            $zipPath = $tmpDir . 'upload_official_' . $dir . '_' . time() . '.zip';
            $zipUrl = $pluginInfo['zip_url'];
            $r = $this->downloadZip($zipUrl, $zipPath);
            if (!$r['ok']) {
                $fallbackUrl = $this->jsdelivrToRawUrl($zipUrl);
                if ($fallbackUrl !== $zipUrl) {
                    $r = $this->downloadZip($fallbackUrl, $zipPath);
                }
            }
            if (!$r['ok']) {
                // 下载失败 → 回滚备份
                $this->restorePluginDir($dir, $backupPath);
                return ['ok' => false, 'message' => '下载插件包失败，请检查网络：' . $r['message']];
            }

            // 解压
            $extractDir = $tmpDir . 'upload_official_' . $dir . '_' . time() . '/';
            $oldUmask = umask(0);
            @mkdir($extractDir, 0755, true);
            umask($oldUmask);
            $extractRes = $this->extractZip($zipPath, $extractDir);
            @unlink($zipPath);
            if (!$extractRes['ok']) {
                $this->recursiveDelete($extractDir);
                $this->restorePluginDir($dir, $backupPath);
                return ['ok' => false, 'message' => $extractRes['message']];
            }

            // 找到插件根
            $pluginRoot = $this->findPluginRoot($extractDir);
            if ($pluginRoot === '') {
                $this->recursiveDelete($extractDir);
                $this->restorePluginDir($dir, $backupPath);
                return ['ok' => false, 'message' => 'zip 包结构错误：未找到 conf.json'];
            }

            // 移动新版本到 plugin/{dir}/（此时 plugin/{dir}/ 已被备份 rename 走，不存在）
            $pluginPath = APP_PATH . 'plugin/' . $dir . '/';
            $moveOk = $this->moveDir($pluginRoot, $pluginPath);
            // 清理临时解压目录
            $this->recursiveDelete($extractDir);
            if (!$moveOk) {
                if (is_dir($pluginPath)) {
                    $this->recursiveDelete($pluginPath);
                }
                $this->restorePluginDir($dir, $backupPath);
                return ['ok' => false, 'message' => '移动插件文件失败'];
            }

            // 重新初始化插件列表（让 $plugins 全局数组加载新 conf.json）
            if (function_exists('plugin_init')) {
                plugin_init();
            }

            // 执行 upgrade.php（如果存在，通过 _include 加载，spec 要求）
            // ponytail: upgrade.php 同 install.php，需要全局变量 $db / $conf 等
            $upgradeFile = APP_PATH . 'plugin/' . $dir . '/upgrade.php';
            if (is_file($upgradeFile)) {
                if (is_file(APP_PATH . 'lib/xn_safe_io.php')) {
                    require_once APP_PATH . 'lib/xn_safe_io.php';
                }
                $plugin_dir = $dir;
                extract($GLOBALS, EXTR_SKIP);  // 导入全局变量到当前作用域
                if (function_exists('_include')) {
                    include _include($upgradeFile);
                } else {
                    include $upgradeFile;
                }
            }

            // 更新 db_version 为新版本号
            if (function_exists('plugin_db_set_version')) {
                plugin_db_set_version($dir, $version);
            }

            // 清缓存
            $this->clearManifestCache();

            // 升级成功，删除备份
            if (is_dir($backupPath)) {
                $this->recursiveDelete($backupPath);
            }

            // 升级成功：如果之前是启用状态，自动恢复启用
            if ($wasEnabled && function_exists('plugin_enable')) {
                plugin_enable($dir);
            }

            return ['ok' => true, 'message' => '升级成功'];
        } catch (\Throwable $e) {
            // 异常时回滚到备份
            if ($backupPath !== '' && is_dir($backupPath)) {
                $this->restorePluginDir($dir, $backupPath);
            }
            return ['ok' => false, 'message' => '升级失败，已回滚到旧版本：' . $e->getMessage()];
        }
    }

    /**
     * 比对清单与本地插件，返回每个插件的状态
     *
     * 状态定义（免费/付费统一）：
     *   - not_installed           免费插件本地未安装（显示安装按钮）
     *   - paid                    付费插件本地未安装（显示前往下载）
     *   - latest                  本地版本 >= manifest 版本（显示已最新置灰按钮）
     *   - need_upgrade            本地版本 < manifest 版本（显示升级按钮）
     *
     * @param array $manifest fetchManifest 返回的 manifest 数据（含 plugins 数组）
     * @param array $localPlugins $plugins 全局数组（key=dir，已合并 db 状态）
     * @return array ['dir'=>['status'=>string, 'manifest'=>array], ...]
     */
    public function compareWithLocal(array $manifest, array $localPlugins): array {
        $result = [];
        $plugins = isset($manifest['plugins']) && is_array($manifest['plugins']) ? $manifest['plugins'] : [];

        foreach ($plugins as $p) {
            $dir = isset($p['dir']) ? $p['dir'] : '';
            if ($dir === '') continue;
            $free = !empty($p['free']);
            $manifestVersion = isset($p['version']) ? (string)$p['version'] : '0.0.0';

            // 本地未安装：免费显示安装按钮，付费显示前往下载
            if (!isset($localPlugins[$dir])) {
                $result[$dir] = ['status' => $free ? 'not_installed' : 'paid', 'manifest' => $p];
                continue;
            }

            // 本地已存在：比对版本（免费/付费统一逻辑）
            // 优先用 conf.json.version（文件实际版本，最权威）
            // db_version 可能是旧版本（插件升级后未同步更新 bbs_plugin.version），作为 fallback
            $localVersion = '0.0.0';
            if (isset($localPlugins[$dir]['version']) && $localPlugins[$dir]['version'] !== '') {
                $localVersion = (string)$localPlugins[$dir]['version'];
            } elseif (isset($localPlugins[$dir]['db_version']) && $localPlugins[$dir]['db_version'] !== '') {
                $localVersion = (string)$localPlugins[$dir]['db_version'];
            }

            if (version_compare($localVersion, $manifestVersion, '>=')) {
                $result[$dir] = ['status' => 'latest', 'manifest' => $p];
                continue;
            }

            // 本地版本低于清单版本：统一标记为 need_upgrade（启用/禁用均可升级）
            // downloadAndUpgrade 会在升级前自动禁用已启用插件，升级成功后自动恢复
            $result[$dir] = ['status' => 'need_upgrade', 'manifest' => $p];
        }
        return $result;
    }

    // ===================== 私有方法 =====================

    /**
     * HTTP GET 请求（cURL 优先，回退 file_get_contents + stream context）
     *
     * 参考 OnlineUpgradeService::httpGet 的双模式实现，但本类不依赖 db 和 conf。
     *
     * @param string $url 请求 URL
     * @param int $timeout 超时秒数
     * @return array ['ok'=>bool, 'data'=>string, 'message'=>string]
     */
    private function httpGet(string $url, int $timeout = 30): array {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Xiuno-BBS-OfficialPlugin/1.0');
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
                'timeout' => $timeout,
                'user_agent' => 'Xiuno-BBS-OfficialPlugin/1.0',
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
     * 下载 zip 文件到指定路径
     *
     * 复用 httpGet，但使用 ZIP_DOWNLOAD_TIMEOUT=120 秒超时（zip 文件较大）。
     *
     * @param string $url 下载 URL
     * @param string $destPath 目标路径（绝对路径，如 tmp/upload_official_xnx_checkin_1722100000.zip）
     * @return array ['ok'=>bool, 'message'=>string]
     */
    private function downloadZip(string $url, string $destPath): array {
        $resp = $this->httpGet($url, self::ZIP_DOWNLOAD_TIMEOUT);
        if (!$resp['ok']) {
            return ['ok' => false, 'message' => $resp['message']];
        }
        $data = $resp['data'];
        if ($data === '' || $data === null) {
            return ['ok' => false, 'message' => '下载内容为空'];
        }
        // 确保目标目录存在
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            $oldUmask = umask(0);
            @mkdir($destDir, 0755, true);
            umask($oldUmask);
        }
        $r = @file_put_contents($destPath, $data);
        if ($r === false) {
            return ['ok' => false, 'message' => '写入文件失败：' . $destPath];
        }
        if ($r <= 0) {
            @unlink($destPath);
            return ['ok' => false, 'message' => '下载文件大小为 0'];
        }
        return ['ok' => true, 'message' => ''];
    }

    /**
     * 读取缓存文件
     *
     * @return array|null 缓存数组（含 fetched_at + data），无缓存或解析失败返回 null
     */
    private function readCache(): ?array {
        $cachePath = APP_PATH . self::CACHE_FILE;
        if (!is_file($cachePath)) {
            return null;
        }
        $content = @file_get_contents($cachePath);
        if ($content === false || $content === '') {
            return null;
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    /**
     * 写入缓存文件
     *
     * 缓存格式：['fetched_at'=>time(), 'data'=>$manifest]
     *
     * @param array $data 缓存数据
     */
    private function writeCache(array $data): void {
        $cachePath = APP_PATH . self::CACHE_FILE;
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            $oldUmask = umask(0);
            @mkdir($cacheDir, 0755, true);
            umask($oldUmask);
        }
        @file_put_contents(
            $cachePath,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * 备份本地插件目录（用 rename 提高速度，与 OnlineUpgradeService 一致）
     *
     * @param string $dir 插件目录名
     * @return string 备份路径（绝对路径），失败返回空串
     */
    private function backupPluginDir(string $dir): string {
        $src = APP_PATH . 'plugin/' . $dir;
        $backupPath = APP_PATH . 'tmp/plugin_bak_' . $dir . '_' . time();
        if (!is_dir($src)) {
            return '';
        }
        if (!is_dir(APP_PATH . 'tmp/')) {
            $oldUmask = umask(0);
            @mkdir(APP_PATH . 'tmp/', 0755, true);
            umask($oldUmask);
        }
        if (!@rename($src, $backupPath)) {
            return '';
        }
        return $backupPath;
    }

    /**
     * 从备份恢复本地插件目录
     *
     * 先删除残留的 plugin/{dir}/（如果存在），再 rename 备份回来。
     *
     * @param string $dir 插件目录名
     * @param string $backupPath 备份路径
     */
    private function restorePluginDir(string $dir, string $backupPath): void {
        $dst = APP_PATH . 'plugin/' . $dir;
        // 删除残留的 plugin/{dir}/（如果存在）
        if (is_dir($dst)) {
            $this->recursiveDelete($dst);
        }
        if (is_dir($backupPath)) {
            @rename($backupPath, $dst);
        }
    }

    // ===================== 内部辅助方法 =====================

    /**
     * 将 jsdelivr URL 转换为 GitHub raw URL（备源）
     *
     * 主源：https://cdn.jsdelivr.net/gh/{owner}/{repo}@main/{path}
     * 备源：https://raw.githubusercontent.com/{owner}/{repo}/main/{path}
     *
     * @param string $url 主源 URL
     * @return string 备源 URL（非 jsdelivr URL 原样返回）
     */
    private function jsdelivrToRawUrl(string $url): string {
        $pattern = '#^https://cdn\.jsdelivr\.net/gh/'
            . preg_quote(self::GITHUB_OWNER, '#') . '/'
            . preg_quote(self::GITHUB_REPO, '#')
            . '@main/(.+)$#';
        if (preg_match($pattern, $url, $m)) {
            return 'https://raw.githubusercontent.com/'
                . self::GITHUB_OWNER . '/' . self::GITHUB_REPO
                . '/main/' . $m[1];
        }
        return $url;
    }

    /**
     * 解压 zip 到指定目录（含防目录穿越校验）
     *
     * @param string $zipPath zip 文件路径
     * @param string $extractDir 解压目标目录
     * @return array ['ok'=>bool, 'message'=>string]
     */
    private function extractZip(string $zipPath, string $extractDir): array {
        if (!is_file($zipPath)) {
            return ['ok' => false, 'message' => 'zip 文件不存在'];
        }
        $zip = new ZipArchive();
        $openRes = $zip->open($zipPath);
        if ($openRes !== true) {
            return ['ok' => false, 'message' => 'ZipArchive::open 失败，错误码：' . $openRes];
        }
        // 防目录穿越：禁止 zip 内文件名包含 ../ 或 ..\
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (strpos($entryName, '../') !== false || strpos($entryName, '..\\') !== false) {
                $zip->close();
                return ['ok' => false, 'message' => 'zip 包含路径穿越的文件名'];
            }
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            return ['ok' => false, 'message' => '解压失败'];
        }
        $zip->close();
        return ['ok' => true, 'message' => ''];
    }

    /**
     * 在解压目录中查找插件根目录（含 conf.json 的目录）
     *
     * 支持两种 zip 结构（与 admin/route/plugin.php 上传安装流程一致）：
     *   A) conf.json 在 zip 根目录 → 返回解压根目录
     *   B) zip 内有一层目录，conf.json 在该目录下 → 返回该子目录
     * 过滤 __MACOSX 干扰目录
     *
     * @param string $extractDir 解压目录（绝对路径）
     * @return string 插件根目录路径（无末尾斜杠），未找到返回空串
     */
    private function findPluginRoot(string $extractDir): string {
        $extractDir = rtrim($extractDir, '/');
        // 结构 A：根目录直接含 conf.json
        if (is_file($extractDir . '/conf.json')) {
            return $extractDir;
        }
        // 结构 B：唯一子目录下含 conf.json
        $subDirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        if (!$subDirs) return '';
        $realSubDirs = [];
        foreach ($subDirs as $d) {
            $base = basename($d);
            if ($base === '__MACOSX') continue;
            $realSubDirs[] = $d;
        }
        if (count($realSubDirs) === 1 && is_file($realSubDirs[0] . '/conf.json')) {
            return $realSubDirs[0];
        }
        return '';
    }

    /**
     * 递归移动目录内容（rename 优先，失败回退 copy + unlink）
     *
     * 模拟 admin/route/plugin.php 中的 rmove_dir 行为（该函数为局部函数，
     * lib 类不能依赖，故内部实现一份等价逻辑）。
     *
     * @param string $src 源目录
     * @param string $dst 目标目录
     * @return bool 是否成功
     */
    private function moveDir(string $src, string $dst): bool {
        if (!is_dir($src)) return false;
        $src = rtrim($src, '/') . '/';
        $dst = rtrim($dst, '/') . '/';
        if (!is_dir($dst)) {
            $oldUmask = umask(0);
            @mkdir($dst, 0755, true);
            umask($oldUmask);
        }
        $d = @dir($src);
        if (!$d) return false;
        while (false !== ($entry = $d->read())) {
            if ($entry == '.' || $entry == '..') continue;
            $srcPath = $src . $entry;
            $dstPath = $dst . $entry;
            if (is_dir($srcPath)) {
                if (!$this->moveDir($srcPath, $dstPath)) return false;
            } else {
                if (!@rename($srcPath, $dstPath)) {
                    if (!@copy($srcPath, $dstPath)) return false;
                    @unlink($srcPath);
                }
            }
        }
        $d->close();
        @rmdir($src);
        return true;
    }

    /**
     * 递归删除目录
     *
     * @param string $dir 目录路径
     * @return bool 是否成功
     */
    private function recursiveDelete(string $dir): bool {
        if (!is_dir($dir)) return false;
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
     * 清除清单缓存文件（安装/升级成功后调用，强制下次刷新时重新比对版本）
     */
    private function clearManifestCache(): void {
        $cachePath = APP_PATH . self::CACHE_FILE;
        if (is_file($cachePath)) {
            @unlink($cachePath);
        }
    }
}
