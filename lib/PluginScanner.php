<?php

/**
 * 插件兼容性扫描服务
 * @since 1.0.2
 *
 * 依赖：
 * - PluginScannerRules.php    规则定义
 * - PluginScannerSuggestion.php  动态建议构建
 */
class PluginScanner {

    private array $rules;
    private array $severityLevels;
    /**
     * direct_db 抑制区间（按文件路径索引）
     * 记录每个文件中"保留 db_*"注释影响的最大行号
     * 格式：['plugin/xxx/model/X.php' => 最大抑制行号]
     */
    private array $suppressDirectDbUntil = [];

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
     * fatal / error 级别问题会阻止安装；force=1 分类检测到即阻止安装（不可跳过）
     */
    public function scanBeforeInstall(string $pluginDirName): array {
        $dir = APP_PATH . 'plugin/' . $pluginDirName;
        if (!is_dir($dir)) {
            return ['can_install' => false, 'fatal' => [['category' => 'not_found', 'suggestion' => '插件目录不存在']], 'error' => [], 'warning' => [], 'summary' => '插件目录不存在'];
        }

        $issues = $this->scanPluginDir($dir);
        $fatal = [];
        $error = [];
        $warning = [];
        $mediumCount = 0;
        $forceCategories = PluginScannerRules::getForceCategories();
        $forceBlocked = [];
        foreach ($issues as $issue) {
            // force=1 的分类检测到即阻止安装，不可被用户手动跳过
            if (in_array($issue['category'], $forceCategories, true)) {
                $forceBlocked[] = $issue;
            }
            if ($issue['severity'] === 'fatal') $fatal[] = $issue;
            elseif ($issue['severity'] === 'error') $error[] = $issue;
            elseif ($issue['severity'] === 'warning') $warning[] = $issue;
            elseif ($issue['severity'] === 'medium') $mediumCount++;
        }

        // fatal、error、force 分类均阻止安装
        $blocked = !empty($fatal) || !empty($error) || !empty($forceBlocked);

        $parts = [];
        if (!empty($fatal)) $parts[] = count($fatal) . ' 个致命问题';
        if (!empty($error)) $parts[] = count($error) . ' 个错误';
        if (!empty($warning)) $parts[] = count($warning) . ' 个警告';
        if ($mediumCount > 0) $parts[] = $mediumCount . ' 个兼容建议';

        return [
            'can_install' => !$blocked,
            'fatal' => $fatal,
            'error' => $error,
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

            // PHP 注释陷阱检测：单行注释中包含 PHP 结束标签会触发 headers already sent
            if ($ext === 'php') {
                $lines = explode("\n", $content);
                foreach ($lines as $lineNum => $lineContent) {
                    $lineNumber = $lineNum + 1;
                    // 检测双斜线单行注释中的 PHP 结束标签
                    if (preg_match('#//.*\?>#', $lineContent)) {
                        $issues[] = $this->buildIssue($shortPath, $lineNumber, 'php_comment_close_tag', '// 注释', '单行注释 // 中包含 PHP 结束标签会触发 headers already sent 错误，请移除或改用块注释 /* */', $lineContent);
                    }
                    // 检测行首 # 单行注释中的 PHP 结束标签
                    elseif (preg_match('/^\s*#.*\?>/', $lineContent)) {
                        $issues[] = $this->buildIssue($shortPath, $lineNumber, 'php_comment_close_tag', '# 注释', '单行注释 # 中包含 PHP 结束标签会触发 headers already sent 错误，请移除或改用块注释 /* */', $lineContent);
                    }
                    // 检测行中 # 单行注释中的 PHP 结束标签（前面有空格，避免匹配 shebang）
                    elseif (preg_match('/\s+#.*\?>/', $lineContent)) {
                        $issues[] = $this->buildIssue($shortPath, $lineNumber, 'php_comment_close_tag', '# 注释', '单行注释 # 中包含 PHP 结束标签会触发 headers already sent 错误，请移除或改用块注释 /* */', $lineContent);
                    }
                }
            }

            // MD5.js 全局加载检测
            if ($ext === 'htm' || $ext === 'html') {
                if (preg_match('/<script[^>]*src\s*=\s*["\'][^"\']*md5[^"\']*\.js["\']/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                    $lineNumber = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
                    $issues[] = $this->buildIssue($shortPath, $lineNumber, 'md5js_global_load', '<script src="*md5*.js">', 'MD5.js 不得全局加载，前端 MD5 哈希已移除，密码必须明文提交', $m[0][0]);
                }
            }

            // HEREDOC 语法检测：HEREDOC 块内含 PHP 开始标签
            if ($ext === 'php') {
                if (preg_match('/<<<(\w+).*?<\?php.*?\1;/s', $content, $m, PREG_OFFSET_CAPTURE)) {
                    $lineNumber = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
                    $issues[] = $this->buildIssue($shortPath, $lineNumber, 'heredoc_php_tag', '<<<EOT ... <?php ... EOT;', 'HEREDOC 语法中需使用 {$variable} 语法嵌入 PHP 变量，避免使用 PHP 开始标签导致解析错误', $m[0][0]);
                }
            }

            // Bootstrap Tab 误用检测：外层导航用 data-bs-toggle="tab" 跳转页面
            if ($ext === 'htm' || $ext === 'html') {
                if (preg_match_all('/<a\s+[^>]*data-bs-toggle\s*=\s*["\']tab["\'][^>]*>/is', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $tagStr = $match[0];
                        $offset = $match[1];
                        // 检查同标签 href 是否含 .htm 或 .php
                        if (preg_match('/href\s*=\s*["\']([^"\']*\.(?:htm|php)[^"\']*)["\']/i', $tagStr, $hrefMatch)) {
                            $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;
                            $issues[] = $this->buildIssue($shortPath, $lineNumber, 'bs_tab_navigation', 'data-bs-toggle="tab" + href="'.$hrefMatch[1].'"', '外层导航（页面跳转）禁止用 Bootstrap Tab 系统，应改为普通 <a> 链接跳转；内层导航才用 tab', $tagStr);
                        }
                    }
                }
            }

            // APP_PATH 误用检测：script/link 的 src/href 用 APP_PATH
            if ($ext === 'htm' || $ext === 'html') {
                if (preg_match('/<(?:script|link)[^>]*(?:src|href)\s*=\s*["\'][^"\']*APP_PATH/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                    $lineNumber = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
                    $issues[] = $this->buildIssue($shortPath, $lineNumber, 'app_path_in_url', '<script/link src/href="*APP_PATH*">', 'APP_PATH 是文件系统绝对路径，浏览器无法访问，必须用 $conf[\'view_url\'] 生成资源 URL', $m[0][0]);
                }
            }

            // install.php 非幂等建表检测
            if (basename($file) === 'install.php') {
                if (preg_match('/CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                    $lineNumber = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
                    $issues[] = $this->buildIssue($shortPath, $lineNumber, 'install_non_idempotent', 'CREATE TABLE (without IF NOT EXISTS)', 'install.php 所有建表语句必须用 IF NOT EXISTS 保证幂等', $m[0][0]);
                }
            }

            // conf.json 版本检查：bbs_version 缺失或低于 1.0.2 升为 error 级（force=1 不可跳过）
            if (basename($file) === 'conf.json') {
                $conf = @json_decode($content, true);
                $confVersionSeverity = $this->severityLevels['conf_version'] ?? 'error';
                if ($conf && !isset($conf['bbs_version'])) {
                    $issues[] = [
                        'file' => $shortPath, 'line' => 0, 'category' => 'conf_version',
                        'match' => 'bbs_version: (missing)', 'suggestion' => '插件缺少 bbs_version 字段，必须声明兼容的 BBS 版本（>=1.0.2）',
                        'severity' => $confVersionSeverity, 'context' => 'bbs_version 字段缺失',
                    ];
                } elseif ($conf && isset($conf['bbs_version']) && version_compare($conf['bbs_version'], '1.0.2', '<')) {
                    $issues[] = [
                        'file' => $shortPath, 'line' => 0, 'category' => 'conf_version',
                        'match' => "bbs_version: {$conf['bbs_version']}", 'suggestion' => '插件声明版本 < 1.0.2，必须更新 bbs_version 字段至 1.0.2 以上',
                        'severity' => $confVersionSeverity, 'context' => "bbs_version: {$conf['bbs_version']}",
                    ];
                }

                // capabilities 字段格式校验：必须为字符串数组，每项为 lowercase.dots 格式
                // 用于插件声明所需权限（如 user.write、thread.delete），便于未来权限沙箱
                if ($conf && isset($conf['capabilities'])) {
                    $caps = $conf['capabilities'];
                    $valid = is_array($caps);
                    if ($valid) {
                        foreach ($caps as $cap) {
                            if (!is_string($cap) || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $cap)) {
                                $valid = false;
                                break;
                            }
                        }
                    }
                    if (!$valid) {
                        $issues[] = [
                            'file' => $shortPath, 'line' => 0, 'category' => 'capabilities_format',
                            'match' => 'capabilities', 'suggestion' => 'capabilities 字段必须是字符串数组，每项为 lowercase.dots 格式（如 user.write、thread.create）',
                            'severity' => $this->severityLevels['capabilities_format'] ?? 'warning',
                            'context' => 'capabilities: ' . substr(json_encode($caps), 0, 120),
                        ];
                    }
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

                // Hook 文件头检测
                $hookExt = strtolower(pathinfo($hf, PATHINFO_EXTENSION));
                $firstLine = '';
                $fp = @fopen($hf, 'r');
                if ($fp) {
                    $firstLine = (string)fgets($fp, 256);
                    fclose($fp);
                }
                $hookShortPath = str_replace(APP_PATH, '', $hf);

                if ($hookExt === 'htm') {
                    // .htm hook 文件以 PHP exit 开头会白屏
                    if (strpos($firstLine, '<?php exit;') === 0) {
                        $issues[] = $this->buildIssue($hookShortPath, 1, 'hook_htm_header', '<?php exit;', '.htm 模板 hook 文件以 <?php exit; 开头会白屏！只能用 <?php 开头（编译拼进模板执行）', $firstLine);
                    }
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
        // direct_db 抑制：检测"保留 db_*"注释，标记后续 10 行跳过 direct_db 报告
        // 用于跳过已审计的合理保留 SQL（JOIN/系统表/复杂聚合等），符合 bugfix_rules 中"保留的原始 SQL 必须在代码注释中说明保留原因"的要求
        if ($contextExt === 'php' && preg_match('/(保留|@suppress).*db_(?:sql_find|sql_find_one|exec)/', $line)) {
            $current = $this->suppressDirectDbUntil[$shortPath] ?? 0;
            $this->suppressDirectDbUntil[$shortPath] = max($current, $lineNumber + 10);
        }

        // JS-only 分类（js_eval_call/js_dom_xss/jquery_html_xss）：仅在 JS 上下文扫描
        // 避免 PHP 代码中字符串里的 JS 函数名被误报
        static $jsOnlyCats = null;
        if ($jsOnlyCats === null) {
            $jsOnlyCats = PluginScannerRules::getJsOnlyCategories();
        }
        $isJsContext = ($contextExt === 'js');

        foreach ($this->rules as $category => $patterns) {
            if ($category === 'missing_csrf') continue;

            // JS/CSS 内容跳过 PHP-only 规则
            if (($contextExt === 'js' || $contextExt === 'css') && in_array($category, $phpOnlyCats)) continue;

            // 纯 PHP 代码跳过 HTML-only 规则
            if ($contextExt === 'php' && in_array($category, $htmlOnlyCats)) continue;

            // 非 JS 上下文跳过 JS-only 规则（如 js_eval_call/js_dom_xss/jquery_html_xss）
            if (!$isJsContext && in_array($category, $jsOnlyCats)) continue;

            foreach ($patterns as $pattern => $suggestion) {
                if (is_int($pattern)) { $pattern = $suggestion; $suggestion = null; }

                // 含正则元字符（反斜杠转义、.*、(、[、|）的模式用 preg_match，否则用 stripos 字面匹配
                $isRegex = strpos($pattern, '\\') !== false
                    || strpos($pattern, '.*') !== false
                    || strpos($pattern, '(') !== false
                    || strpos($pattern, '[') !== false
                    || strpos($pattern, '|') !== false;
                $found = $isRegex
                    ? @preg_match('#' . $pattern . '#i', $line)
                    : stripos($line, $pattern) !== false;

                if ($found) {
                    if ($category === 'direct_db') {
                        $basename = basename($shortPath);
                        // install/uninstall/upgrade 脚本中直接操作数据库是合理的
                        if (in_array($basename, ['install.php', 'uninstall.php', 'unstall.php', 'upgrade.php'])) continue;

                        // 只保留 model/ 目录中的原始 SQL 检测（db_exec/db_sql_find/db_sql_find_one），移除其他 db_* 的检测
                        // route/setting/admin 中的 db_count/db_insert/db_update 等是正常业务操作
                        $isModelFile = strpos($shortPath, 'model/') !== false;
                        $isRawSqlPattern = in_array($pattern, ['db_exec(', 'db_sql_find_one(', 'db_sql_find(']);
                        if (!$isModelFile && !$isRawSqlPattern) continue;
                        if ($isModelFile && !$isRawSqlPattern) continue;  // model 中也只检测原始 SQL

                        // 检查是否在"保留"注释的抑制区间内（10 行覆盖多行 SQL 拼接）
                        $suppressUntil = $this->suppressDirectDbUntil[$shortPath] ?? 0;
                        if ($lineNumber <= $suppressUntil) continue;
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

        // password_update_api 检测：user_update 修改 password 字段
        if ($contextExt === 'php' && in_array('password_update_api', $phpOnlyCats)) {
            if (preg_match('/user_update\s*\(/', $line) && preg_match('/[\'"]password[\'"]/', $line)) {
                $issues[] = $this->buildIssue($shortPath, $lineNumber, 'password_update_api', 'user_update(...password...)', '找回密码必须使用 user__update() 而非 user_update()，因为后者会过滤掉 password 字段', $line);
            }
        }

        // db_charset 检测：数据库字符集为 utf8（非 utf8mb4）
        if ($contextExt === 'php' && in_array('db_charset', $phpOnlyCats)) {
            if (preg_match('/charset\s*=\s*utf8(?!mb4)/i', $line) || preg_match('/set\s+names\s+utf8(?!mb4)/i', $line)) {
                $issues[] = $this->buildIssue($shortPath, $lineNumber, 'db_charset', 'utf8 (without mb4)', '数据库连接字符集必须为 utf8mb4（支持 emoji 等 4 字节字符）', $line);
            }
        }

        // service_undefined_var 检测：Service 类 SQL 拼接使用未定义变量
        if ($contextExt === 'php' && in_array('service_undefined_var', $phpOnlyCats) && strpos($shortPath, 'model/') !== false) {
            if (preg_match('/(SELECT|INSERT|UPDATE|DELETE|FROM|INTO)\s/i', $line)) {
                if (preg_match('/\$tableName\b/', $line) || preg_match('/\$tablePrefix\b/', $line)) {
                    $issues[] = $this->buildIssue($shortPath, $lineNumber, 'service_undefined_var', '$tableName / $tablePrefix', 'Service 类中拼接 SQL 表名必须用 $this->tablepre . \'表名\'，不能使用未定义变量', $line);
                }
            }
        }

        // raw_htmlspecialchars 检测：裸 htmlspecialchars 调用
        if ($contextExt === 'php' && in_array('raw_htmlspecialchars', $phpOnlyCats)) {
            if (preg_match('/\bhtmlspecialchars\s*\(/', $line)) {
                $issues[] = $this->buildIssue($shortPath, $lineNumber, 'raw_htmlspecialchars', 'htmlspecialchars(', '禁止裸写 htmlspecialchars，必须用 esc_html() / esc_attr() / esc_js() 统一转义', $line);
            }
        }

        // db_find_col_string 检测：db_find_one/db_find 第 4 参数为字符串字面量
        if ($contextExt === 'php' && in_array('db_find_col_string', $phpOnlyCats)) {
            if (preg_match('/db_find(?:_one)?\s*\(\s*[^,]+,\s*[^,]+,\s*[^,]+,\s*[\'"][^\'"]+[\'"]\s*\)/', $line)) {
                $issues[] = $this->buildIssue($shortPath, $lineNumber, 'db_find_col_string', 'db_find_one(..., "string")', 'db_find_one() 第 4 个参数 $col 必须传入数组（如 array(\'fid\', \'uid\')），禁止传入字符串以避免 implode() 参数类型错误', $line);
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

    /**
     * 构建 issue 数组的辅助方法
     */
    private function buildIssue(string $shortPath, int $lineNumber, string $category, string $match, string $suggestion, string $contextLine): array {
        return [
            'file' => $shortPath,
            'line' => $lineNumber,
            'category' => $category,
            'match' => $match,
            'suggestion' => $suggestion,
            'severity' => $this->severityLevels[$category] ?? 'info',
            'context' => mb_substr(trim($contextLine), 0, 120),
        ];
    }
}
