<?php

/**
 * 插件兼容性扫描服务
 * @since 4.5.0
 *
 * 依赖：
 * - PluginScannerRules.php    规则定义
 * - PluginScannerSuggestion.php  动态建议构建
 * - PluginScannerAlpine.php   Alpine.js 检测
 */
class PluginScanner {

    private array $rules;
    private array $severityLevels;

    public function __construct() {
        $this->rules = PluginScannerRules::getRules();
        $this->severityLevels = PluginScannerRules::getSeverityLevels();
    }

    // ===== 公开 API =====

    /**
     * 扫描所有已安装插件
     */
    public function scanAll(): array {
        $pluginDirs = glob(APP_PATH . 'plugin/*', GLOB_ONLYDIR);
        if (empty($pluginDirs)) return [];

        $results = [];
        foreach ($pluginDirs as $dir) {
            $result = $this->scanSingleByDir($dir);
            if ($result !== null) $results[] = $result;
        }
        return $results;
    }

    /**
     * 扫描单个插件（按目录名）
     */
    public function scanSingle(string $pluginDirName): ?array {
        $dir = APP_PATH . 'plugin/' . $pluginDirName;
        if (!is_dir($dir)) return null;
        return $this->scanSingleByDir($dir);
    }

    /**
     * 安装前预扫描
     * fatal 级别问题会阻止安装
     */
    public function scanBeforeInstall(string $pluginDirName): array {
        $dir = APP_PATH . 'plugin/' . $pluginDirName;
        if (!is_dir($dir)) {
            return ['can_install' => false, 'fatal' => [['category' => 'not_found', 'suggestion' => '插件目录不存在']], 'warning' => [], 'summary' => '插件目录不存在'];
        }

        $issues = $this->scanPluginDir($dir);
        $fatal = [];
        $warning = [];
        $mediumCount = 0;
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'fatal') $fatal[] = $issue;
            elseif ($issue['severity'] === 'warning') $warning[] = $issue;
            elseif ($issue['severity'] === 'medium') $mediumCount++;
        }

        $parts = [];
        if (!empty($fatal)) $parts[] = count($fatal) . ' 个致命问题';
        if (!empty($warning)) $parts[] = count($warning) . ' 个警告';
        if ($mediumCount > 0) $parts[] = $mediumCount . ' 个兼容建议';

        return [
            'can_install' => empty($fatal),
            'fatal' => $fatal,
            'warning' => $warning,
            'issues' => $issues,
            'total' => count($issues),
            'summary' => empty($parts) ? '未发现兼容性问题' : implode('，', $parts),
        ];
    }

    /**
     * 获取已安装插件列表
     */
    public function getPluginList(): array {
        $pluginDirs = glob(APP_PATH . 'plugin/*', GLOB_ONLYDIR);
        if (empty($pluginDirs)) return [];

        $list = [];
        foreach ($pluginDirs as $dir) {
            $pluginName = file_name($dir);
            $conffile = $dir . '/conf.json';
            if (!is_file($conffile)) continue;
            $conf = xn_json_decode(file_get_contents($conffile));
            if (empty($conf)) continue;
            $list[] = ['dir' => $pluginName, 'name' => $conf['name'] ?? $pluginName, 'version' => $conf['version'] ?? '?'];
        }
        return $list;
    }

    /**
     * 获取规则摘要
     */
    public function getRulesSummary(): array {
        $summary = [];
        $names = PluginScannerRules::getCategoryNames();
        foreach ($this->rules as $category => $patterns) {
            $summary[$category] = [
                'name' => $names[$category] ?? $category,
                'severity' => $this->severityLevels[$category] ?? 'info',
                'count' => count($patterns),
            ];
        }
        return $summary;
    }

    // ===== 核心扫描逻辑 =====

    /**
     * 扫描单个插件目录
     */
    public function scanPluginDir(string $dirPath): array {
        $issues = [];

        $files = [];
        $this->collectFiles($dirPath, $files, ['php', 'htm', 'html', 'js', 'css']);

        $phpOnlyCats = PluginScannerRules::getPhpOnlyCategories();
        $htmlOnlyCats = PluginScannerRules::getHtmlOnlyCategories();

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $shortPath = str_replace(APP_PATH, '', $file);

            // 读取文件内容，分离 PHP/HTML 部分和 JS/CSS 部分
            $content = @file_get_contents($file);
            if ($content === false) continue;

            // 分离内容：PHP/HTML 部分 vs <script>/<style> 部分
            $parts = $this->splitContentByLanguage($content, $ext);

            // 对 PHP/HTML 部分逐行扫描
            if (!empty($parts['php_html'])) {
                $lines = explode("\n", $parts['php_html']);
                foreach ($lines as $lineNum => $line) {
                    $lineNumber = $lineNum + 1;
                    $this->scanLine($line, $lineNumber, $shortPath, $ext, $phpOnlyCats, $htmlOnlyCats, $issues);
                }
            }

            // 对纯 JS 部分逐行扫描（排除 PHP-only 规则）
            if (!empty($parts['js'])) {
                $jsLines = explode("\n", $parts['js']);
                foreach ($jsLines as $lineNum => $line) {
                    $lineNumber = $lineNum + 1;
                    $this->scanLine($line, $lineNumber, $shortPath, 'js', $phpOnlyCats, $htmlOnlyCats, $issues);
                }
            }

            // 对纯 CSS 部分逐行扫描（排除 PHP-only 和 JS-only 规则）
            if (!empty($parts['css'])) {
                $cssLines = explode("\n", $parts['css']);
                foreach ($cssLines as $lineNum => $line) {
                    $lineNumber = $lineNum + 1;
                    $this->scanLine($line, $lineNumber, $shortPath, 'css', $phpOnlyCats, $htmlOnlyCats, $issues);
                }
            }

            // missing_csrf 特殊处理
            if ($ext === 'htm' || $ext === 'php') {
                if (stripos($content, 'method="post"') !== false) {
                    if (stripos($content, 'CsrfService') === false && stripos($content, 'csrf_token') === false) {
                        $issues[] = [
                            'file' => $shortPath, 'line' => 0, 'category' => 'missing_csrf',
                            'match' => 'method="post"', 'suggestion' => 'POST 表单缺少 CSRF 令牌，请添加 CsrfService::input()',
                            'severity' => $this->severityLevels['missing_csrf'] ?? 'info',
                            'context' => 'POST 表单未包含 CsrfService 或 csrf_token',
                        ];
                    }
                }
            }

            // Alpine.js 检测（仅 htm/html 文件）
            if ($ext === 'htm' || $ext === 'html') {
                $issues = array_merge($issues, PluginScannerAlpine::checkScoping($file, $shortPath));
                $issues = array_merge($issues, PluginScannerAlpine::checkRegister($file, $shortPath, $this->severityLevels));
            }

            // conf.json 版本检查
            if (basename($file) === 'conf.json') {
                $conf = @json_decode($content, true);
                if ($conf && isset($conf['bbs_version']) && version_compare($conf['bbs_version'], '4.5', '<')) {
                    $issues[] = [
                        'file' => $shortPath, 'line' => 0, 'category' => 'conf_version',
                        'match' => "bbs_version: {$conf['bbs_version']}", 'suggestion' => '插件声明版本 < 4.5，建议更新 bbs_version 字段',
                        'severity' => 'info', 'context' => "bbs_version: {$conf['bbs_version']}",
                    ];
                }
            }
        }

        // Hook 文件名检查
        $hookDir = $dirPath . '/hook';
        if (is_dir($hookDir)) {
            foreach (glob($hookDir . '/*.*') as $hf) {
                $hookName = file_name($hf);
                if (!preg_match('/^[a-z_][a-z0-9_]*$/', pathinfo($hookName, PATHINFO_FILENAME))) {
                    $issues[] = [
                        'file' => str_replace(APP_PATH, '', $hf), 'line' => 0, 'category' => 'hook_name',
                        'match' => $hookName, 'suggestion' => 'Hook 文件名不规范', 'severity' => 'info', 'context' => $hookName,
                    ];
                }
            }
        }

        return $issues;
    }

    // ===== 内部方法 =====

    /**
     * 扫描单行代码
     * @param string $line 行内容
     * @param int $lineNumber 行号
     * @param string $shortPath 文件相对路径
     * @param string $contextExt 当前上下文的扩展名（php/htm/js/css）
     * @param array $phpOnlyCats 仅 PHP 的分类
     * @param array $htmlOnlyCats 仅 HTML 的分类
     * @param array &$issues 结果收集
     */
    private function scanLine(string $line, int $lineNumber, string $shortPath, string $contextExt, array $phpOnlyCats, array $htmlOnlyCats, array &$issues): void {
        foreach ($this->rules as $category => $patterns) {
            if ($category === 'missing_csrf') continue;

            // JS/CSS 内容跳过 PHP-only 规则
            if (($contextExt === 'js' || $contextExt === 'css') && in_array($category, $phpOnlyCats)) continue;

            // 纯 PHP 代码跳过 HTML-only 规则
            if ($contextExt === 'php' && in_array($category, $htmlOnlyCats)) continue;

            foreach ($patterns as $pattern => $suggestion) {
                if (is_int($pattern)) { $pattern = $suggestion; $suggestion = null; }

                $found = (strpos($pattern, '.*') !== false || strpos($pattern, '\b') !== false || strpos($pattern, '\$') !== false)
                    ? @preg_match('#' . $pattern . '#i', $line)
                    : stripos($line, $pattern) !== false;

                if ($found) {
                    if ($category === 'direct_db') {
                        if (in_array(basename($shortPath), ['install.php', 'uninstall.php', 'unstall.php', 'upgrade.php'])) continue;
                    }

                    $context = PluginScannerSuggestion::extractContext($line, $pattern, $category);
                    $dynamicSuggestion = PluginScannerSuggestion::build($category, $pattern, $suggestion, $line);

                    $issues[] = [
                        'file' => $shortPath,
                        'line' => $lineNumber,
                        'category' => $category,
                        'match' => $pattern,
                        'suggestion' => $dynamicSuggestion,
                        'severity' => $this->severityLevels[$category] ?? 'info',
                        'context' => mb_substr($context, 0, 120),
                    ];
                }
            }
        }
    }

    /**
     * 按语言分离文件内容
     * 将 .htm/.php 文件中的 <script> 和 <style> 块与 PHP/HTML 部分分离
     * 返回 ['php_html' => string, 'js' => string, 'css' => string]
     *
     * 关键：PHP 规则只扫描 PHP/HTML 部分，不会误扫 <script> 中的 JS 代码
     */
    private function splitContentByLanguage(string $content, string $ext): array {
        $result = ['php_html' => '', 'js' => '', 'css' => ''];

        // 纯 JS/CSS 文件直接返回
        if ($ext === 'js') { $result['js'] = $content; return $result; }
        if ($ext === 'css') { $result['css'] = $content; return $result; }

        // .htm/.php/.html 文件：分离 <script> 和 <style> 块
        // 用占位符替换 <script>/<style> 块，保持行号对齐
        $phpHtml = $content;
        $jsParts = [];
        $cssParts = [];

        // 提取 <script> 块（排除 type="application/json" 等非 JS 类型）
        if (preg_match_all('/<script(?![^>]*type\s*=\s*["\'](?:application\/(?:json|ld\+json)|text\/(?:x-template|html|x-handlebars-template))["\'])([^>]*)>(.*?)<\/script>/si', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $fullMatch) {
                $full = $fullMatch[0];
                $offset = $fullMatch[1];
                $jsContent = isset($matches[3][$i][0]) ? $matches[3][$i][0] : '';

                // 保持行号对齐：用等长空行替换
                $lineCount = substr_count($full, "\n");
                $replacement = str_repeat("\n", $lineCount);
                $phpHtml = substr_replace($phpHtml, $replacement, $offset, strlen($full));

                if ($jsContent !== '') $jsParts[] = $jsContent;
            }
        }

        // 提取 <style> 块
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $fullMatch) {
                $full = $fullMatch[0];
                $offset = $fullMatch[1];
                $cssContent = isset($matches[1][$i][0]) ? $matches[1][$i][0] : '';

                $lineCount = substr_count($full, "\n");
                $replacement = str_repeat("\n", $lineCount);
                $phpHtml = substr_replace($phpHtml, $replacement, $offset, strlen($full));

                if ($cssContent !== '') $cssParts[] = $cssContent;
            }
        }

        $result['php_html'] = $phpHtml;
        $result['js'] = implode("\n", $jsParts);
        $result['css'] = implode("\n", $cssParts);

        return $result;
    }

    /**
     * 扫描单个插件目录（按绝对路径）
     */
    private function scanSingleByDir(string $dir): ?array {
        $pluginName = file_name($dir);
        $conffile = $dir . '/conf.json';
        if (!is_file($conffile)) return null;

        $conf = xn_json_decode(file_get_contents($conffile));
        if (empty($conf)) return null;

        $issues = $this->scanPluginDir($dir);
        $fatalCount = 0;
        $warningCount = 0;
        foreach ($issues as $i) {
            if ($i['severity'] === 'fatal') $fatalCount++;
            if ($i['severity'] === 'warning') $warningCount++;
        }

        return [
            'dir' => $pluginName,
            'name' => $conf['name'] ?? $pluginName,
            'version' => $conf['version'] ?? '?',
            'bbs_version' => $conf['bbs_version'] ?? '?',
            'installed' => !empty($conf['installed']),
            'enable' => !empty($conf['enable']),
            'issues' => $issues,
            'total' => count($issues),
            'fatal' => $fatalCount,
            'warning' => $warningCount,
        ];
    }

    /**
     * 递归收集目录中的文件
     */
    private function collectFiles(string $dir, array &$files, array $extensions): void {
        $items = glob($dir . '/*');
        if ($items === false) return;

        // 跳过的目录名（第三方库、资源、构建产物）
        $skipDirs = ['img', 'images', 'fonts', 'ueditor', 'umeditor', 'ckeditor', 'tinymce', 'vendor', 'node_modules', 'third_party', '3rdparty', 'dist', 'min'];

        foreach ($items as $item) {
            if (is_dir($item)) {
                $basename = basename($item);
                if (in_array($basename, $skipDirs)) continue;
                $this->collectFiles($item, $files, $extensions);
            } elseif (is_file($item)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $extensions)) $files[] = $item;
            }
        }
    }
}
