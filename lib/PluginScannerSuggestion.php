<?php

/**
 * 插件兼容性扫描 - 动态建议构建器
 * 根据实际匹配内容生成具体的迁移建议
 * @since 4.5.0
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
            $pos = stripos($line, $pattern);
            if ($pos !== false) {
                return trim(substr($line, max(0, $pos - 10), strlen($pattern) + 20));
            }
        }
        return trim($line);
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
        $map = [
            'data-toggle' => 'data-bs-toggle', 'data-dismiss' => 'data-bs-dismiss',
            'data-target' => 'data-bs-target', 'data-slide-to' => 'data-bs-slide-to',
            'data-slide' => 'data-bs-slide',
        ];
        $newAttr = $map[$pattern] ?? 'data-bs-' . substr($pattern, 5);
        if (preg_match('/' . preg_quote($pattern, '/') . '="([^"]*)"/i', $line, $m)) {
            return "{$pattern}=\"{$m[1]}\" → {$newAttr}=\"{$m[1]}\"";
        }
        return "{$pattern} → {$newAttr}";
    }

    // ===== 图标库 =====

    private static function iconLib(string $pattern, string $line): string {
        $patterns = [
            ' fa-' => ['fa-', 'Font Awesome'],
            ' bi-' => ['bi-', 'Bootstrap Icons'],
            'glyphicon-' => ['glyphicon-', 'Glyphicon'],
        ];
        if (isset($patterns[$pattern])) {
            [$prefix, $name] = $patterns[$pattern];
            if (preg_match('/\b' . preg_quote($prefix, '/') . '([a-z0-9-]+)/i', $line, $m)) {
                return "{$prefix}{$m[1]} → ti-{$m[1]}（参考 Tabler Icons 查找对应图标）";
            }
            return "{$name} → Tabler Icons ti-*";
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
            'img-thumbnail' => 'img-thumbnail（已保留）',
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
                if ($m[1] === 'loading') return ".button('loading') → Alpine.js: x-data=\"{loading:false}\" + :disabled=\"loading\" + @click=\"loading=true\"";
                if ($m[1] === 'reset') return ".button('reset') → Alpine.js: 重置 loading 状态";
            }
            return ".button() → Alpine.js x-data loading 状态控制";
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
            if (preg_match('/\$\.fn\.(\w+)/', $line, $m)) return "$.fn.{$m[1]} → Alpine.data('{$m[1]}', () => ({...}))";
            return '$.fn → Alpine.data() 注册可复用组件';
        }
        $simple = [
            'extend' => 'Object.assign()', 'trim' => 'String.prototype.trim()',
            'parseJSON' => 'JSON.parse()', 'isArray' => 'Array.isArray()',
            'isFunction' => 'typeof fn === "function"', 'browser' => '特性检测（如 CSS @supports）',
            'jQuery' => 'htmx + Alpine.js',
        ];
        if (isset($simple[$method])) return "$.{$method}() → {$simple[$method]}";
        return "{$pattern} → 迁移到 htmx + Alpine.js";
    }
}
