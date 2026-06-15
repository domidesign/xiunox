<?php

/**
 * 插件兼容性扫描 - Alpine.js 检测器
 * 检测 x-data 作用域越界和组件未注册问题
 * @since 4.5.0
 */
class PluginScannerAlpine {

    /**
     * 检查 x-data="xxx()" 引用的组件是否通过 Alpine.data() 注册
     */
    public static function checkRegister(string $filePath, string $shortPath, array $severityLevels): array {
        $content = @file_get_contents($filePath);
        if ($content === false || stripos($content, 'x-data') === false) return [];

        $issues = [];

        // 提取 x-data="xxx()" 引用
        $xdataRefs = [];
        if (preg_match_all('/x-data="(\w+)\(([^"]*)\)"/', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $name = $m[1][0];
                $offset = $m[0][1];
                $lineNum = substr_count(substr($content, 0, $offset), "\n") + 1;
                $xdataRefs[] = ['name' => $name, 'line' => $lineNum];
            }
        }
        if (empty($xdataRefs)) return [];

        // 提取 Alpine.data 注册
        $registered = [];
        if (preg_match_all("/Alpine\.data\(\s*['\"](\w+)['\"]/", $content, $matches)) {
            $registered = $matches[1];
        }

        // 提取全局函数定义（旧模式）
        $globalFuncs = [];
        if (preg_match_all('/function\s+(\w+)\s*\(\s*\)\s*\{\s*[\r\n\s]*return\s*\{/', $content, $matches)) {
            $globalFuncs = $matches[1];
        }

        foreach ($xdataRefs as $ref) {
            $name = $ref['name'];
            if (in_array($name, $registered)) continue;

            if (in_array($name, $globalFuncs)) {
                $issues[] = [
                    'file' => $shortPath,
                    'line' => $ref['line'],
                    'category' => 'alpine_register',
                    'match' => "x-data=\"{$name}()\"",
                    'suggestion' => "x-data=\"{$name}()\" 引用的组件通过全局函数定义，需改为 Alpine.data('{$name}', function() {...}) 并在 alpine:init 事件中注册",
                    'severity' => $severityLevels['alpine_register'] ?? 'warning',
                    'context' => "x-data=\"{$name}()\" → 全局函数 function {$name}()",
                ];
                continue;
            }

            $issues[] = [
                'file' => $shortPath,
                'line' => $ref['line'],
                'category' => 'alpine_register',
                'match' => "x-data=\"{$name}()\"",
                'suggestion' => "x-data=\"{$name}()\" 引用的组件未在本文件中通过 Alpine.data() 注册，请确认是否在 alpine:init 事件中注册",
                'severity' => $severityLevels['alpine_register'] ?? 'warning',
                'context' => "x-data=\"{$name}()\" → 未找到 Alpine.data('{$name}') 注册",
            ];
        }

        return $issues;
    }

    /**
     * 检查 Alpine.js x-data 作用域越界
     */
    public static function checkScoping(string $filePath, string $shortPath): array {
        $content = @file_get_contents($filePath);
        if ($content === false || stripos($content, 'x-data') === false) return [];

        $jsDefs = self::extractJsComponents($content);
        $tags = self::extractHtmlTags($content);
        $scopes = self::buildAlpineScopes($tags, $jsDefs);
        if (empty($scopes)) return [];

        $xforScopes = self::buildXForScopes($tags);
        $issues = [];
        $alpineMagic = ['$store', '$refs', '$el', '$nextTick', '$dispatch', '$watch',
                        '$root', '$id', '$data', '$event', '$wire', '$persist', '$collapse', '$focus'];

        foreach ($tags as $idx => $tag) {
            if ($tag['type'] !== 'open' || empty($tag['attrs'])) continue;

            foreach ($tag['attrs'] as $attr) {
                $refVars = self::extractAlpineRefVars($attr);
                if (empty($refVars)) continue;

                foreach ($refVars as $var) {
                    if ($var === '' || in_array($var, $alpineMagic)) continue;

                    $inScope = false;
                    foreach ($scopes as $scope) {
                        if ($idx >= $scope['start'] && $idx <= $scope['end'] && in_array($var, $scope['props'])) {
                            $inScope = true;
                            break;
                        }
                    }
                    if (!$inScope) {
                        foreach ($xforScopes as $xs) {
                            if ($idx >= $xs['start'] && $idx <= $xs['end'] && in_array($var, $xs['vars'])) {
                                $inScope = true;
                                break;
                            }
                        }
                    }

                    if (!$inScope) {
                        $lineNum = substr_count(substr($content, 0, $tag['offset']), "\n") + 1;
                        $issues[] = [
                            'file' => $shortPath,
                            'line' => $lineNum,
                            'category' => 'alpine_scope',
                            'match' => $attr['name'] . '="' . mb_substr($attr['value'], 0, 40) . '"',
                            'suggestion' => "Alpine 变量 '{$var}' 不在任何 x-data 作用域内，请检查 x-data 是否放在了足够高的父级元素上",
                            'severity' => 'warning',
                            'context' => mb_substr(trim($tag['raw']), 0, 120),
                        ];
                        break;
                    }
                }
            }
        }

        return $issues;
    }

    // ===== 内部方法 =====

    private static function extractHtmlTags(string $content): array {
        $tags = [];
        if (!preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9]*)([^>]*?)(\/?)>/s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];

        $voidElements = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];

        foreach ($matches as $m) {
            $isClose = ($m[1][0] === '/');
            $tagName = strtolower($m[2][0]);
            $attrStr = $m[3][0];
            $selfClose = ($m[4][0] === '/');
            $offset = $m[0][1];
            $raw = $m[0][0];

            $tag = [
                'type' => $isClose ? 'close' : 'open',
                'tagName' => $tagName,
                'attrs' => [],
                'selfClosing' => $selfClose || in_array($tagName, $voidElements),
                'offset' => $offset,
                'raw' => $raw,
            ];

            if (!$isClose && $attrStr !== '') {
                $tag['attrs'] = self::parseAttributes($attrStr);
            }
            $tags[] = $tag;
        }
        return $tags;
    }

    private static function parseAttributes(string $str): array {
        $attrs = [];
        if (!preg_match_all('/([a-zA-Z:@][\w.:_\-]*)(?:\s*=\s*"([^"]*)"|\'([^\']*)\'|([^\s>]+))?/s', $str, $am, PREG_SET_ORDER)) return [];
        foreach ($am as $a) {
            $attrs[] = ['name' => $a[1], 'value' => $a[2] ?? $a[3] ?? $a[4] ?? ''];
        }
        return $attrs;
    }

    private static function buildAlpineScopes(array $tags, array $jsDefs): array {
        $scopes = [];
        $stack = [];

        foreach ($tags as $idx => $tag) {
            if ($tag['type'] === 'close') {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tagName'] === $tag['tagName']) {
                        $entry = array_splice($stack, $i, 1)[0];
                        if (isset($entry['xDataStart'])) {
                            $scopes[] = ['start' => $entry['xDataStart'], 'end' => $idx, 'props' => $entry['xDataProps']];
                        }
                        break;
                    }
                }
                continue;
            }
            if ($tag['type'] !== 'open') continue;

            $entry = ['tagName' => $tag['tagName']];
            foreach ($tag['attrs'] as $attr) {
                if ($attr['name'] === 'x-data') {
                    $entry['xDataStart'] = $idx;
                    $entry['xDataProps'] = self::parseXDataProperties($attr['value'], $jsDefs);
                    break;
                }
            }

            if (!$tag['selfClosing']) {
                $stack[] = $entry;
            } elseif (isset($entry['xDataStart'])) {
                $scopes[] = ['start' => $idx, 'end' => $idx, 'props' => $entry['xDataProps']];
            }
        }

        foreach ($stack as $entry) {
            if (isset($entry['xDataStart'])) {
                $scopes[] = ['start' => $entry['xDataStart'], 'end' => count($tags) - 1, 'props' => $entry['xDataProps']];
            }
        }
        return $scopes;
    }

    private static function buildXForScopes(array $tags): array {
        $scopes = [];
        $stack = [];

        foreach ($tags as $idx => $tag) {
            if ($tag['type'] === 'close') {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tagName'] === $tag['tagName']) {
                        $entry = array_splice($stack, $i, 1)[0];
                        if (isset($entry['xForStart'])) {
                            $scopes[] = ['start' => $entry['xForStart'], 'end' => $idx, 'vars' => $entry['xForVars']];
                        }
                        break;
                    }
                }
                continue;
            }
            if ($tag['type'] !== 'open') continue;

            $entry = ['tagName' => $tag['tagName']];
            foreach ($tag['attrs'] as $attr) {
                if ($attr['name'] === 'x-for') {
                    $vars = [];
                    $leftSide = preg_replace('/\s+(?:in|of)\s+.*/i', '', $attr['value']);
                    $leftSide = trim($leftSide, '() ');
                    if (preg_match_all('/(\w+)/', $leftSide, $vm)) $vars = $vm[1];
                    if (!empty($vars)) {
                        $entry['xForStart'] = $idx;
                        $entry['xForVars'] = $vars;
                    }
                    break;
                }
            }

            if (!$tag['selfClosing']) {
                $stack[] = $entry;
            } elseif (isset($entry['xForStart'])) {
                $scopes[] = ['start' => $idx, 'end' => $idx, 'vars' => $entry['xForVars']];
            }
        }

        foreach ($stack as $entry) {
            if (isset($entry['xForStart'])) {
                $scopes[] = ['start' => $entry['xForStart'], 'end' => count($tags) - 1, 'vars' => $entry['xForVars']];
            }
        }
        return $scopes;
    }

    private static function parseXDataProperties(string $value, array $jsDefs): array {
        $value = trim($value);
        if ($value === '') return [];

        if ($value[0] === '{') {
            $props = [];
            $inner = substr($value, 1, -1);
            if (preg_match_all('/(\w+)\s*:/', $inner, $m)) $props = $m[1];
            return $props;
        }

        if (preg_match('/^(\w+)\s*\(\s*\)/', $value, $m)) {
            if (isset($jsDefs[$m[1]])) return $jsDefs[$m[1]];
        }
        if (preg_match('/^(\w+)$/', $value, $m)) {
            if (isset($jsDefs[$m[1]])) return $jsDefs[$m[1]];
        }
        return [];
    }

    private static function extractJsComponents(string $content): array {
        $defs = [];
        if (!preg_match_all('/<script[^>]*>(.*?)<\/script>/si', $content, $sm)) return [];

        foreach ($sm[1] as $script) {
            // function name() { ... return { props } ... }
            if (preg_match_all('/function\s+(\w+)\s*\(/', $script, $fm, PREG_OFFSET_CAPTURE)) {
                foreach ($fm[1] as $f) {
                    $keys = self::parseFunctionReturnKeys($script, $f[1]);
                    if (!empty($keys)) $defs[$f[0]] = $keys;
                }
            }

            // Alpine.data('name', ...)
            if (preg_match_all('/Alpine\.data\s*\(\s*[\'"](\w+)[\'"]\s*,/', $script, $am, PREG_OFFSET_CAPTURE)) {
                foreach ($am[1] as $a) {
                    $name = $a[0];
                    $pos = $a[1];
                    $commaPos = strpos($script, ',', $pos + strlen($name));
                    if ($commaPos === false) continue;
                    $callbackStart = $commaPos + 1;

                    $cursor = $callbackStart;
                    while ($cursor < strlen($script) && ctype_space($script[$cursor])) $cursor++;

                    $objBracePos = null;

                    if (substr($script, $cursor, 8) === 'function') {
                        $funcBodyStart = strpos($script, '{', $cursor);
                        if ($funcBodyStart !== false) {
                            $depth = 0;
                            for ($k = $funcBodyStart; $k < strlen($script); $k++) {
                                $ch = $script[$k];
                                if ($ch === '{') $depth++;
                                elseif ($ch === '}') { $depth--; if ($depth <= 0) break; }
                                elseif ($depth === 1 && $ch === 'r' && substr($script, $k, 7) === 'return ') {
                                    for ($j = $k + 7; $j < strlen($script); $j++) {
                                        if ($script[$j] === '{') { $objBracePos = $j; break 2; }
                                        elseif (!ctype_space($script[$j])) break;
                                    }
                                }
                            }
                        }
                    } else {
                        $arrowPos = strpos($script, '=>', $cursor);
                        if ($arrowPos !== false) {
                            for ($j = $arrowPos + 2; $j < strlen($script); $j++) {
                                if ($script[$j] === '(') continue;
                                if ($script[$j] === '{') { $objBracePos = $j; break; }
                                elseif (!ctype_space($script[$j])) break;
                            }
                        }
                    }

                    if ($objBracePos !== null) {
                        $objContent = self::extractBracedContent($script, $objBracePos);
                        if ($objContent !== null) $defs[$name] = self::extractTopLevelKeys($objContent);
                    }

                    if (!isset($defs[$name]) || empty($defs[$name])) {
                        $snippet = trim(substr($script, $callbackStart, 60));
                        if (preg_match('/^(_?[a-zA-Z]\w*)(?:\s*[;,\)])/s', $snippet, $vm)) {
                            if (isset($defs[$vm[1]])) $defs[$name] = $defs[$vm[1]];
                        }
                    }
                }
            }
        }
        return $defs;
    }

    private static function parseFunctionReturnKeys(string $script, int $funcNamePos): array {
        $pos = strpos($script, '{', $funcNamePos);
        if ($pos === false) return [];
        $len = strlen($script);
        $depth = 0;

        for ($i = $pos; $i < $len; $i++) {
            $ch = $script[$i];
            if ($ch === '{') $depth++;
            elseif ($ch === '}') { $depth--; if ($depth <= 0) break; }
            elseif ($depth === 1 && $ch === 'r' && substr($script, $i, 7) === 'return ') {
                for ($j = $i + 7; $j < $len; $j++) {
                    if ($script[$j] === '{') {
                        $objContent = self::extractBracedContent($script, $j);
                        if ($objContent !== null) return self::extractTopLevelKeys($objContent);
                    } elseif (!ctype_space($script[$j])) break;
                }
                break;
            }
        }
        return [];
    }

    private static function extractBracedContent(string $str, int $openBracePos): ?string {
        if (!isset($str[$openBracePos]) || $str[$openBracePos] !== '{') return null;
        $len = strlen($str);
        $depth = 0;
        for ($i = $openBracePos; $i < $len; $i++) {
            if ($str[$i] === '{') $depth++;
            elseif ($str[$i] === '}') { $depth--; if ($depth === 0) return substr($str, $openBracePos + 1, $i - $openBracePos - 1); }
        }
        return null;
    }

    private static function extractTopLevelKeys(string $objContent): array {
        $keys = [];
        $len = strlen($objContent);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && (ctype_space($objContent[$i]) || $objContent[$i] === ',')) $i++;
            if ($i >= $len) break;
            $ch = $objContent[$i];

            // 跳过注释
            if ($ch === '/' && $i + 1 < $len) {
                if ($objContent[$i + 1] === '/') { $nl = strpos($objContent, "\n", $i); $i = ($nl !== false) ? $nl + 1 : $len; continue; }
                if ($objContent[$i + 1] === '*') { $end = strpos($objContent, '*/', $i + 2); $i = ($end !== false) ? $end + 2 : $len; continue; }
            }

            // 跳过字符串
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch; $i++;
                while ($i < $len && $objContent[$i] !== $quote) { if ($objContent[$i] === '\\') $i++; $i++; }
                $i++; continue;
            }

            if (preg_match('/^([a-zA-Z_$][\w$]*)\s*/', substr($objContent, $i), $m)) {
                $key = $m[1];
                $afterKey = $i + strlen($m[0]);

                if ($afterKey < $len && $objContent[$afterKey] === ':') {
                    $keys[] = $key;
                    $i = $afterKey + 1;
                    $depth = 0;
                    while ($i < $len) {
                        $c = $objContent[$i];
                        if ($c === '{' || $c === '[' || $c === '(') $depth++;
                        elseif ($c === '}' || $c === ']' || $c === ')') { if ($depth === 0) break; $depth--; }
                        elseif ($c === ',' && $depth === 0) { $i++; break; }
                        elseif ($c === "'" || $c === '"') { $q = $c; $i++; while ($i < $len && $objContent[$i] !== $q) { if ($objContent[$i] === '\\') $i++; $i++; } }
                        $i++;
                    }
                } elseif ($afterKey < $len && $objContent[$afterKey] === '(') {
                    $keys[] = $key;
                    $closeP = strpos($objContent, ')', $afterKey);
                    $i = ($closeP !== false) ? $closeP + 1 : $afterKey + 2;
                    while ($i < $len && ctype_space($objContent[$i])) $i++;
                    if ($i < $len && $objContent[$i] === '{') {
                        $depth = 1; $i++;
                        while ($i < $len && $depth > 0) {
                            if ($objContent[$i] === '{') $depth++;
                            elseif ($objContent[$i] === '}') $depth--;
                            elseif ($objContent[$i] === "'" || $objContent[$i] === '"') { $q = $objContent[$i]; $i++; while ($i < $len && $objContent[$i] !== $q) { if ($objContent[$i] === '\\') $i++; $i++; } }
                            $i++;
                        }
                    }
                } else {
                    $i = $afterKey;
                }
            } else {
                $i++;
            }
        }
        return $keys;
    }

    private static function extractAlpineRefVars(array $attr): array {
        $name = $attr['name'];
        $value = $attr['value'];
        if ($value === '' || strpos($value, '<?') !== false) return [];

        $simpleDirectives = ['x-show', 'x-text', 'x-html', 'x-if', 'x-model', 'x-modelable', 'x-effect'];
        if (in_array($name, $simpleDirectives)) { $var = self::getRootVar($value); return $var !== '' ? [$var] : []; }

        if ($name === 'x-bind' || strpos($name, 'x-bind:') === 0) { $var = self::getRootVar($value); return $var !== '' ? [$var] : []; }
        if (strlen($name) > 1 && $name[0] === ':') { $var = self::getRootVar($value); return $var !== '' ? [$var] : []; }
        if ($name === 'x-for') return [];

        if ($name === 'x-on' || (strlen($name) > 1 && $name[0] === '@')) {
            if (preg_match('/(\w+)\s*\(/', $value, $m)) return [$m[1]];
            $var = self::getRootVar($value);
            return $var !== '' ? [$var] : [];
        }
        return [];
    }

    private static function getRootVar(string $expr): string {
        $expr = trim($expr);
        $expr = preg_replace('/^[!]+/', '', $expr);
        $expr = preg_replace('/^typeof\s+/', '', $expr);
        if (preg_match('/^([a-zA-Z_$][\w$]*)/', $expr, $m)) return $m[1];
        return '';
    }
}
