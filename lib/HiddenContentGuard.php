<?php

/** Shared fail-closed scanner. No database writes or unlock side effects. */
class HiddenContentGuard {
    public static function contains(string $text): bool {
        return (bool) preg_match('/\[\/?hidden\b|xnx-hidden-(?:tag|inner)/i', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Localized placeholder. Falls back to the plain literal when no lang pack is loaded (CLI, early bootstrap). */
    public static function lockedText() {
        if (isset($_SERVER['lang']) && function_exists('lang')) {
            $text = lang('content_locked');
            // lang() returns "lang[key]" for missing keys — keep the literal placeholder then.
            if (is_string($text) && strpos($text, 'lang[') !== 0) return $text;
        }
        return '[Content locked]';
    }

    public static function filter(string $text, ?callable $render = null): string {
        $locked = self::lockedText();
        // Encoded/broken delimiters are withheld without decoding visible HTML into executable markup.
        $decoded = $text;
        for ($i = 0; $i < 2; $i++) $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/&(?:lt|#0*60|#x0*3c);[^\n]*xnx-hidden-/i', $text)
            || (preg_match('/\[hidden\b/i', $decoded) && !preg_match('/\[hidden\b/i', $text))) return $locked;
        $render = $render ?? static function () use ($locked) { return $locked; };
        // Scan complete outer BBCode blocks, including nested and unclosed blocks.
        preg_match_all('/\[(\/?)hidden\b([^\]]*)\]/i', $text, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $depth = 0;
        $ranges = array();
        foreach ($tokens as $token) {
            $closing = $token[1][0] === '/';
            if (!$closing) {
                if ($depth++ === 0) {
                    $start = $token[0][1];
                    $bodyStart = $start + strlen($token[0][0]);
                    $attrs = $token[2][0];
                }
            } elseif ($depth > 0 && --$depth === 0) {
                $end = $token[0][1] + strlen($token[0][0]);
                $ranges[] = array($start, $end, $attrs, substr($text, $bodyStart, $token[0][1] - $bodyStart), count($ranges));
            }
        }
        if ($depth > 0) $ranges[] = array($start, strlen($text), '', null, count($ranges));
        foreach (array_reverse($ranges) as $range) {
            $replacement = $range[3] === null ? $locked : $render($range[2], $range[3], 'bbcode', $range[4]);
            $text = substr_replace($text, $replacement, $range[0], $range[1] - $range[0]);
        }
        // HTML editor blocks may contain arbitrarily nested div elements.
        preg_match_all('/<\/?div\b[^>]*>/i', $text, $tokens, PREG_OFFSET_CAPTURE);
        $depth = 0;
        $ranges = array();
        foreach ($tokens[0] as $token) {
            $closing = strpos($token[0], '</') === 0;
            if (!$closing) {
                if ($depth > 0) { $depth++; }
                elseif (preg_match('/\bclass\s*=\s*(?:"[^"]*\bxnx-hidden-tag\b[^"]*"|\x27[^\x27]*\bxnx-hidden-tag\b[^\x27]*\x27|xnx-hidden-tag\b)/i', $token[0])) {
                    $depth = 1;
                    $start = $token[1];
                    $bodyStart = $start + strlen($token[0]);
                    $attrs = $token[0];
                }
            } elseif ($depth > 0 && --$depth === 0) {
                $ranges[] = array($start, $token[1] + strlen($token[0]), $attrs, substr($text, $bodyStart, $token[1] - $bodyStart), count($ranges));
            }
        }
        if ($depth > 0) $ranges[] = array($start, strlen($text), '', null, count($ranges));
        foreach (array_reverse($ranges) as $range) {
            $text = substr_replace($text, $range[3] === null ? $locked : $render($range[2], $range[3], 'html', $range[4]), $range[0], $range[1] - $range[0]);
        }
        // Broken opening delimiters must not turn protected text into public text.
        $text = preg_replace('/\[hidden\b[^\]]*$/is', $locked, $text);
        $text = preg_replace('/<div\b[^>]*xnx-hidden-tag\b[^>]*$/is', $locked, $text);
        return $text;
    }

    public static function attribute(string $attrs, string $key): string {
        $attrs = html_entity_decode($attrs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/(?:^|\s)' . preg_quote($key, '/') . '\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))/i', $attrs, $match)) {
            return $match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
        }
        return '';
    }
}
