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
        $fallback = $suggestion ?? lang('scanner_suggestion_manual_check');

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
                return $suggestion ?? lang('scanner_suggestion_php_comment_close_tag');
            case 'frontend_md5':
                return lang('scanner_suggestion_frontend_md5');
            case 'md5js_global_load':
                return lang('scanner_suggestion_md5js_global_load');
            case 'password_update_api':
                return lang('scanner_password_update_api');
            case 'db_charset':
                return lang('scanner_db_charset');
            case 'service_undefined_var':
                return lang('scanner_service_undefined_var');
            case 'raw_htmlspecialchars':
                return lang('scanner_raw_htmlspecialchars');
            case 'heredoc_php_tag':
                return lang('scanner_suggestion_heredoc_php_tag_short');
            case 'bs_tab_navigation':
                return lang('scanner_suggestion_bs_tab_navigation');
            case 'hook_htm_header':
                return lang('scanner_suggestion_hook_htm_header');
            case 'db_find_col_string':
                return lang('scanner_suggestion_db_find_col_string');
            case 'app_path_in_url':
                return lang('scanner_app_path_in_url');
            case 'install_non_idempotent':
                return lang('scanner_install_non_idempotent');
            case 'php_superglobal_output':
                return self::phpSuperglobalOutput($pattern, $line);
            case 'js_eval_call':
                return lang('scanner_suggestion_js_eval');
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
            '\beval\(' => lang('scanner_suggestion_danger_eval'),
            '\bsystem\(' => lang('scanner_suggestion_danger_system'),
            '(?<![_>])\bexec\(' => lang('scanner_suggestion_danger_exec'),
            '\bpassthru\(' => lang('scanner_suggestion_danger_passthru'),
            '\bshell_exec\(' => lang('scanner_suggestion_danger_shell_exec'),
            '\bpopen\(' => lang('scanner_suggestion_danger_popen'),
            '\bproc_open\(' => lang('scanner_suggestion_danger_proc_open'),
            '\bpcntl_exec\(' => lang('scanner_suggestion_danger_pcntl_exec'),
        ];
        return $map[$pattern] ?? lang('scanner_suggestion_danger_fallback');
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
            'input-group-prepend' => lang('scanner_suggestion_bs4_input_group_text'),
            'input-group-append' => lang('scanner_suggestion_bs4_input_group_text'),
        ];
        if (isset($fixed[$pattern])) return ".{$pattern} → .{$fixed[$pattern]}";
        if ($pattern === '"media"') return '.media → d-flex';
        if ($pattern === '"media-body"') return '.media-body → flex-grow-1';
        return lang('scanner_suggestion_bs4_fallback', array('class' => $pattern));
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
                return lang('scanner_suggestion_icon_fa_concrete', array('old' => $m[1], 'new' => $m[1]));
            }
            return lang('scanner_suggestion_icon_fa');
        }
        if (strpos($pattern, 'bi-[a-z]') !== false) {
            // Bootstrap Icons：提取 bi-xxx 类名
            if (preg_match('/\bbi-([a-z0-9-]+)/i', $line, $m)) {
                return lang('scanner_suggestion_icon_bi_concrete', array('old' => $m[1], 'new' => $m[1]));
            }
            return lang('scanner_suggestion_icon_bi');
        }
        if (strpos($pattern, 'glyphicon glyphicon-') !== false) {
            // Glyphicon：提取 glyphicon glyphicon-xxx 类名
            if (preg_match('/glyphicon\s+glyphicon-([a-z0-9-]+)/i', $line, $m)) {
                return lang('scanner_suggestion_icon_glyphicon_concrete', array('old' => $m[1], 'new' => $m[1]));
            }
            return lang('scanner_suggestion_icon_glyphicon');
        }
        return lang('scanner_suggestion_icon_migrate');
    }

    // ===== BS3 类名 =====

    private static function bs3Class(string $pattern, string $line): string {
        $map = [
            'panel-heading' => 'card-header', 'panel-body' => 'card-body',
            'panel-footer' => 'card-footer', 'panel-default' => 'card',
            'panel-primary' => lang('scanner_suggestion_bs3_card_color'), 'panel-success' => lang('scanner_suggestion_bs3_card_color'),
            'panel-info' => lang('scanner_suggestion_bs3_card_color'), 'panel-warning' => lang('scanner_suggestion_bs3_card_color'),
            'panel-danger' => lang('scanner_suggestion_bs3_card_color'), 'well' => 'card.card-body',
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
            return lang('scanner_suggestion_bs3_col_xs');
        }
        return lang('scanner_suggestion_bs3_fallback', array('class' => $pattern));
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
                if ($m[1] === 'loading') return lang('scanner_suggestion_bsjs_button_loading');
                if ($m[1] === 'reset') return lang('scanner_suggestion_bsjs_button_reset');
            }
            return lang('scanner_suggestion_bsjs_button_default');
        }
        if ($method === 'modal') {
            if (preg_match('/\.modal\([\'"](\w+)[\'"]\)/', $line, $m)) {
                $actions = ['show' => 'show()', 'hide' => 'hide()', 'dispose' => 'dispose()', 'toggle' => 'toggle()'];
                $api = $actions[$m[1]] ?? "{$m[1]}()";
                return ".modal('{$m[1]}') → bootstrap.Modal.getInstance(el).{$api}";
            }
            return lang('scanner_suggestion_bsjs_modal_default');
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
        return lang('scanner_suggestion_bsjs_fallback', array('pattern' => $pattern));
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
                return lang('scanner_suggestion_jq_post_ajax', array('method' => $method));
            }
            return lang('scanner_suggestion_jq_ajax_fetch', array('method' => $method));
        }
        if ($method === 'document.ready' || $method === 'function(') {
            return "{$pattern} → document.addEventListener('DOMContentLoaded', fn)";
        }
        if ($method === 'each') return lang('scanner_suggestion_jq_each_replace');
        if ($method === 'fn') {
            if (preg_match('/\$\.fn\.(\w+)/', $line, $m)) return lang('scanner_suggestion_jq_fn_concrete', array('name' => $m[1]));
            return lang('scanner_suggestion_jq_fn');
        }
        $simple = [
            'extend' => 'Object.assign()', 'trim' => 'String.prototype.trim()',
            'parseJSON' => 'JSON.parse()', 'isArray' => 'Array.isArray()',
            'isFunction' => 'typeof fn === "function"', 'browser' => lang('scanner_suggestion_jq_browser_replace'),
            'jQuery' => lang('scanner_suggestion_jq_jquery_replace'),
        ];
        if (isset($simple[$method])) return "$.{$method}() → {$simple[$method]}";
        return lang('scanner_suggestion_jq_fallback', array('pattern' => $pattern));
    }

    // ===== PHP 超全局直接输出（反射型 XSS） =====

    private static function phpSuperglobalOutput(string $pattern, string $line): string {
        // 提取具体的超全局名（$_GET/$_POST/$_REQUEST/$_SERVER/$_COOKIE）
        // 区分大小写：PHP 超全局变量必须大写，$_post ≠ $_POST
        $var = lang('scanner_suggestion_superglobal_var');
        if (preg_match('/\$_(GET|POST|REQUEST|SERVER|COOKIE)\b/', $line, $m)) {
            $var = '$_' . $m[1];
        }
        return lang('scanner_suggestion_superglobal_output', array('var' => $var));
    }

    // ===== JS DOM XSS =====

    private static function jsDomXss(string $pattern, string $line): string {
        if (strpos($pattern, 'document\.write') !== false) {
            return lang('scanner_suggestion_js_dom_write');
        }
        if (strpos($pattern, 'innerHTML') !== false) {
            return lang('scanner_suggestion_js_dom_innerhtml');
        }
        if (strpos($pattern, 'outerHTML') !== false) {
            return lang('scanner_suggestion_js_dom_outerhtml');
        }
        if (strpos($pattern, 'insertAdjacentHTML') !== false) {
            return lang('scanner_suggestion_js_dom_insert');
        }
        return lang('scanner_suggestion_js_dom_fallback');
    }

    // ===== jQuery .html() XSS =====

    private static function jqueryHtmlXss(string $pattern, string $line): string {
        // 尝试提取选择器上下文，给出更具体的建议
        if (preg_match('/\$\(window\)\.html\s*\(/i', $line)) {
            return lang('scanner_suggestion_jquery_html_window');
        }
        return lang('scanner_suggestion_jquery_html_xss');
    }
}
