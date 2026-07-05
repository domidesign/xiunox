<?php

/**
 * 插件兼容性扫描 - 动态建议构建器
 * 根据实际匹配内容生成具体的迁移建议
 * @since 1.0.2
 */
class PluginScannerSuggestion {

    /**
     * 根据分类和匹配内容动态生成建议
     */
    public static function build(string $category, string $pattern, ?string $suggestion, string $line): string {
        $fallback = $suggestion ?? '请人工检查此项兼容性';

        switch ($category) {
            case 'bs4_classes':
                return self::bs4Class($pattern, $line);
            case 'bs4_data_attrs':
                return self::bs4DataAttr($pattern, $line);
            case 'fontello_icons':
                return "{$pattern} → {$suggestion}";
            case 'icon_libraries':
                return self::iconLib($pattern, $line);
            case 'bs3_classes':
                return self::bs3Class($pattern, $line);
            case 'bs_js_api':
                return self::bsJsApi($pattern, $line);
            case 'jquery_usage':
                return self::jquery($pattern, $line);
            case 'dangerous_functions':
                return self::dangerousFunctions($pattern, $line);
            case 'php_comment_close_tag':
                return $suggestion ?? '移除注释中的 ?> 或改用块注释 /* */';
            case 'frontend_md5':
                return '密码必须明文提交，由服务端 password_md5() 处理；移除前端 MD5 哈希代码';
            case 'md5js_global_load':
                return 'MD5.js 不得全局加载，前端 MD5 哈希已移除';
            case 'password_update_api':
                return '找回密码必须使用 user__update() 而非 user_update()，因为后者会过滤掉 password 字段';
            case 'db_charset':
                return '数据库连接字符集必须为 utf8mb4（支持 emoji 等 4 字节字符）';
            case 'service_undefined_var':
                return 'Service 类中拼接 SQL 表名必须用 $this->tablepre . \'表名\'，不能使用未定义变量';
            case 'raw_htmlspecialchars':
                return '禁止裸写 htmlspecialchars，必须用 esc_html() / esc_attr() / esc_js() 统一转义';
            case 'heredoc_php_tag':
                return 'HEREDOC 语法中需使用 {$variable} 语法嵌入 PHP 变量';
            case 'bs_tab_navigation':
                return '外层导航（页面跳转）禁止用 Bootstrap Tab，应改为普通 <a> 链接；内层导航才用 tab';
            case 'hook_htm_header':
                return '.htm 模板 hook 文件必须以 <?php 开头（不能是 <?php exit;，否则白屏）';
            case 'db_find_col_string':
                return 'db_find_one() 第 4 个参数 $col 必须传入数组（如 array(\'fid\', \'uid\')）';
            case 'app_path_in_url':
                return 'APP_PATH 是文件系统绝对路径，浏览器无法访问，必须用 $conf[\'view_url\'] 生成资源 URL';
            case 'install_non_idempotent':
                return 'install.php 所有建表语句必须用 IF NOT EXISTS 保证幂等';
            case 'php_superglobal_output':
                return self::phpSuperglobalOutput($pattern, $line);
            case 'js_eval_call':
                return 'JS eval() 调用存在代码注入风险，应避免使用；如需解析 JSON 请用 JSON.parse()';
            case 'js_dom_xss':
                return self::jsDomXss($pattern, $line);
            case 'jquery_html_xss':
                return self::jqueryHtmlXss($pattern, $line);
            default:
                return $fallback;
        }
    }

    /**
     * 提取匹配上下文
     */
    public static function extractContext(string $line, string $pattern, string $category): string {
        $shortCategories = ['fontello_icons', 'bs4_classes', 'bs4_data_attrs', 'bs3_classes', 'icon_libraries'];
        if (in_array($category, $shortCategories)) {
            // pattern 可能是带词边界的正则（如 '(?<![-\w])data-target(?![\w-])'），提取字面量子串用于定位
            $needle = (preg_match('/(data-[a-z-]+)/i', $pattern, $m)) ? $m[1] : $pattern;
            $pos = stripos($line, $needle);
            if ($pos !== false) {
                return trim(substr($line, max(0, $pos - 10), strlen($needle) + 20));
            }
        }
        return trim($line);
    }

    // ===== 危险函数 =====

    private static function dangerousFunctions(string $pattern, string $line): string {
        $map = [
            '\beval\(' => 'eval() 代码注入风险，避免使用 eval()，改用闭包或 json_decode 解析数据',
            '\bsystem\(' => 'system() 命令执行风险，避免直接调用系统命令，必须用 escapeshellarg() + escapeshellcmd() 转义',
            '(?<![_>])\bexec\(' => 'exec() 命令执行风险，避免直接调用系统命令，必须用 escapeshellarg() + escapeshellcmd() 转义',
            '\bpassthru\(' => 'passthru() 命令执行风险，避免直接调用系统命令',
            '\bshell_exec\(' => 'shell_exec() 命令执行风险，避免直接调用系统命令',
            '\bpopen\(' => 'popen() 进程管理风险，避免使用',
            '\bproc_open\(' => 'proc_open() 进程管理风险，避免使用',
            '\bpcntl_exec\(' => 'pcntl_exec() 进程执行风险，避免使用',
        ];
        return $map[$pattern] ?? '危险函数调用，请人工检查此项兼容性';
    }

    // ===== BS4 类名 =====

    private static function bs4Class(string $pattern, string $line): string {
        $spacingMap = [
            ' ml-' => ['ml-', 'ms-'],
            ' mr-' => ['mr-', 'me-'],
            ' pl-' => ['pl-', 'ps-'],
            ' pr-' => ['pr-', 'pe-'],
        ];
        if (isset($spacingMap[$pattern])) {
            [$old, $new] = $spacingMap[$pattern];
            if (preg_match('/\b' . preg_quote($old, '/') . '(\d+)/i', $line, $m)) {
                return "{$old}{$m[1]} → {$new}{$m[1]}";
            }
            return "{$old}* → {$new}*";
        }
        $fixed = [
            'form-group' => 'mb-3', 'form-control-label' => 'form-label',
            'custom-select' => 'form-select', 'custom-control' => 'form-check',
            'btn-block' => 'w-100',
            'input-group-prepend' => '直接使用 input-group-text',
            'input-group-append' => '直接使用 input-group-text',
        ];
        if (isset($fixed[$pattern])) return ".{$pattern} → .{$fixed[$pattern]}";
        if ($pattern === '"media"') return '.media → d-flex';
        if ($pattern === '"media-body"') return '.media-body → flex-grow-1';
        return "BS4 .{$pattern} → BS5 替代类";
    }

    // ===== BS4 data 属性 =====

    private static function bs4DataAttr(string $pattern, string $line): string {
        static $map = [
            'data-toggle' => 'data-bs-toggle', 'data-dismiss' => 'data-bs-dismiss',
            'data-target' => 'data-bs-target', 'data-slide-to' => 'data-bs-slide-to',
            'data-slide' => 'data-bs-slide',
        ];
        // pattern 现为带词边界正则（如 '(?<![-\w])data-target(?![\w-])'），从中提取原始属性名
        if (!preg_match('/(data-[a-z-]+)/i', $pattern, $pm)) {
            return $pattern;
        }
        $attr = $pm[1];
        $newAttr = $map[$attr] ?? 'data-bs-' . substr($attr, 5);
        if (preg_match('/' . preg_quote($attr, '/') . '="([^"]*)"/i', $line, $m)) {
            return "{$attr}=\"{$m[1]}\" → {$newAttr}=\"{$m[1]}\"";
        }
        return "{$attr} → {$newAttr}";
    }

    // ===== 图标库 =====

    private static function iconLib(string $pattern, string $line): string {
        // 新模式以 class=" 开头，按正则匹配区分三种图标库
        if (strpos($pattern, 'fa-[a-z]') !== false) {
            // Font Awesome：提取 fa-xxx 类名
            if (preg_match('/\bfa-([a-z0-9-]+)/i', $line, $m)) {
                return "fa-{$m[1]} → ti-{$m[1]}（参考 Tabler Icons 查找对应图标）";
            }
            return 'Font Awesome → Tabler Icons ti-*';
        }
        if (strpos($pattern, 'bi-[a-z]') !== false) {
            // Bootstrap Icons：提取 bi-xxx 类名
            if (preg_match('/\bbi-([a-z0-9-]+)/i', $line, $m)) {
                return "bi-{$m[1]} → ti-{$m[1]}（参考 Tabler Icons 查找对应图标）";
            }
            return 'Bootstrap Icons → Tabler Icons ti-*';
        }
        if (strpos($pattern, 'glyphicon glyphicon-') !== false) {
            // Glyphicon：提取 glyphicon glyphicon-xxx 类名
            if (preg_match('/glyphicon\s+glyphicon-([a-z0-9-]+)/i', $line, $m)) {
                return "glyphicon glyphicon-{$m[1]} → ti-{$m[1]}（参考 Tabler Icons 查找对应图标）";
            }
            return 'Glyphicon → Tabler Icons ti-*';
        }
        return '迁移到 Tabler Icons ti-*';
    }

    // ===== BS3 类名 =====

    private static function bs3Class(string $pattern, string $line): string {
        $map = [
            'panel-heading' => 'card-header', 'panel-body' => 'card-body',
            'panel-footer' => 'card-footer', 'panel-default' => 'card',
            'panel-primary' => 'card+ 颜色', 'panel-success' => 'card+ 颜色',
            'panel-info' => 'card+ 颜色', 'panel-warning' => 'card+ 颜色',
            'panel-danger' => 'card+ 颜色', 'well' => 'card.card-body',
            'glyphicon' => 'Tabler Icons ti-*', 'pull-left' => 'float-start',
            'pull-right' => 'float-end', 'hidden-xs' => 'd-none .d-sm-block',
            'visible-xs' => 'd-sm-none', 'label-default' => 'badge',
            'label-primary' => 'badge.bg-primary', 'label-success' => 'badge.bg-success',
            'label-info' => 'badge.bg-info', 'label-warning' => 'badge.bg-warning',
            'label-danger' => 'badge.bg-danger', 'img-responsive' => 'img-fluid',
            'img-circle' => 'rounded-circle', 'img-rounded' => 'rounded',
        ];
        if (isset($map[$pattern])) return ".{$pattern} → .{$map[$pattern]}";
        if ($pattern === 'col-xs-') {
            if (preg_match('/col-xs-(\d+)/i', $line, $m)) return "col-xs-{$m[1]} → col-{$m[1]}";
            return 'col-xs-* → col-*（xs 断点已移除）';
        }
        return "BS3 .{$pattern} → BS5 替代类";
    }

    // ===== Bootstrap jQuery API =====

    private static function bsJsApi(string $pattern, string $line): string {
        // 从正则模式中提取方法名，如 '\$\(.*\)\.modal\(' → 'modal'
        $method = '';
        if (preg_match('/\\\\\)\.(\w+)\\\(/', $pattern, $m)) {
            $method = $m[1];
        }

        if ($method === 'button') {
            if (preg_match('/\.button\([\'"](\w+)[\'"]\)/', $line, $m)) {
                if ($m[1] === 'loading') return ".button('loading') → 原生 JS: const btn=document.querySelector(...); btn.disabled=true; 或 htmx hx-disabled-elt";
                if ($m[1] === 'reset') return ".button('reset') → 原生 JS: btn.disabled=false; 重置状态";
            }
            return ".button() → htmx hx-disabled-elt 或原生 JS disabled 属性";
        }
        if ($method === 'modal') {
            if (preg_match('/\.modal\([\'"](\w+)[\'"]\)/', $line, $m)) {
                $actions = ['show' => 'show()', 'hide' => 'hide()', 'dispose' => 'dispose()', 'toggle' => 'toggle()'];
                $api = $actions[$m[1]] ?? "{$m[1]}()";
                return ".modal('{$m[1]}') → bootstrap.Modal.getInstance(el).{$api}";
            }
            return ".modal() → new bootstrap.Modal(el) 或 htmx hx-get 加载弹窗";
        }
        $apiMap = [
            'dropdown' => 'Dropdown', 'tooltip' => 'Tooltip', 'popover' => 'Popover',
            'collapse' => 'Collapse', 'carousel' => 'Carousel', 'alert' => 'Alert', 'tab' => 'Tab',
        ];
        if (isset($apiMap[$method])) {
            $cls = $apiMap[$method];
            $name = strtolower($cls);
            return ".{$name}() → new bootstrap.{$cls}(el)";
        }
        return $pattern . ' → Bootstrap 5 原生 API';
    }

    // ===== jQuery =====

    private static function jquery(string $pattern, string $line): string {
        // 从正则模式中提取 jQuery 方法名
        // '\$\.ajax\(' → 'ajax', '\$\(document\)\.ready' → 'document.ready', 'jQuery\(' → 'jQuery'
        $method = '';
        if (preg_match('/\\\\\$\\\.(\w+)\\\\?[\(.]/', $pattern, $m)) {
            $method = $m[1];
        } elseif (preg_match('/\\\\\$\((\w+)\\\\?\)\\.(\w+)/', $pattern, $m)) {
            $method = $m[1] . '.' . $m[2];
        } elseif (strpos($pattern, 'jQuery') !== false) {
            $method = 'jQuery';
        }

        if (in_array($method, ['ajax', 'post', 'get'])) {
            if (preg_match('/\$\.x?post\([^,]+,\s*[^,]+,\s*function/i', $line)) {
                return "$.{$method}() → htmx hx-{$method} 或 XN.ajax()（xiuno-modern.js）";
            }
            return "$.{$method}() → htmx hx-* 或原生 fetch()";
        }
        if ($method === 'document.ready' || $method === 'function(') {
            return "{$pattern} → document.addEventListener('DOMContentLoaded', fn)";
        }
        if ($method === 'each') return '$.each() → Array.forEach() 或 for...of';
        if ($method === 'fn') {
            if (preg_match('/\$\.fn\.(\w+)/', $line, $m)) return "$.fn.{$m[1]} → 原生 JS class 或 htmx 组件";
            return '$.fn → 原生 JS class 或 htmx 组件';
        }
        $simple = [
            'extend' => 'Object.assign()', 'trim' => 'String.prototype.trim()',
            'parseJSON' => 'JSON.parse()', 'isArray' => 'Array.isArray()',
            'isFunction' => 'typeof fn === "function"', 'browser' => '特性检测（如 CSS @supports）',
            'jQuery' => 'htmx 4 属性或原生 JS',
        ];
        if (isset($simple[$method])) return "$.{$method}() → {$simple[$method]}";
        return "{$pattern} → 迁移到 htmx 4 属性或原生 JS";
    }

    // ===== PHP 超全局直接输出（反射型 XSS） =====

    private static function phpSuperglobalOutput(string $pattern, string $line): string {
        // 提取具体的超全局名（$_GET/$_POST/$_REQUEST/$_SERVER/$_COOKIE）
        $var = '超全局变量';
        if (preg_match('/\$_(GET|POST|REQUEST|SERVER|COOKIE)/i', $line, $m)) {
            $var = '$_' . strtoupper($m[1]);
        }
        return "直接 {$var} 输出会导致反射型 XSS，必须用 esc_html() / esc_attr() 转义后再输出";
    }

    // ===== JS DOM XSS =====

    private static function jsDomXss(string $pattern, string $line): string {
        if (strpos($pattern, 'document\.write') !== false) {
            return 'document.write() 会直接执行字符串中的 HTML/JS 代码导致 DOM XSS，应使用 document.createElement() + textContent 构建 DOM';
        }
        if (strpos($pattern, 'innerHTML') !== false) {
            return '.innerHTML = 会解析 HTML 导致 DOM XSS，应改用 .textContent =（自动转义）';
        }
        if (strpos($pattern, 'outerHTML') !== false) {
            return '.outerHTML = 会解析 HTML 导致 DOM XSS，应改用 DOM API（createElement/replaceChild）';
        }
        if (strpos($pattern, 'insertAdjacentHTML') !== false) {
            return 'insertAdjacentHTML() 会解析 HTML 导致 DOM XSS，应改用 insertAdjacentText() 或 DOM API';
        }
        return 'JS DOM XSS 风险，避免直接设置 HTML 字符串';
    }

    // ===== jQuery .html() XSS =====

    private static function jqueryHtmlXss(string $pattern, string $line): string {
        // 尝试提取选择器上下文，给出更具体的建议
        if (preg_match('/\$\(window\)\.html\s*\(/i', $line)) {
            return '$(window).html() 用法异常，应使用 document.body.innerHTML 或 DOM API';
        }
        return 'jQuery .html() 内部调用 .innerHTML 会导致 DOM XSS；若内容非可信，应改用 .text()（自动转义 HTML 特殊字符）';
    }
}
