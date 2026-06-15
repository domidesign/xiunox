<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * 敏感词过滤服务 - Trie 树实现，支持本地词库和在线管理
 *
 * 接口：
 * - content_filter($text, $scene): 过滤文本，返回 ['pass'=>bool, 'matched_keywords'=>[], 'filtered_text'=>string]
 * - filter_load_words(): 加载词库到 Trie 树
 * - filter_add_word($word): 添加敏感词
 * - filter_delete_word($word): 删除敏感词
 * - filter_batch_import($words_text): 批量导入（每行一个词）
 * - filter_get_all_words(): 获取所有敏感词
 * - filter_clear_words(): 清空词库
 * - filter_test($text): 测试过滤效果
 */
class SensitiveWordFilter {

    // Trie 树根节点
    private static ?array $trie = null;

    // 词库文件路径
    private static string $words_file = '';

    // 是否已加载
    private static bool $loaded = false;

    /**
     * 获取词库文件路径
     */
    private static function get_words_file(): string {
        if (empty(self::$words_file)) {
            self::$words_file = APP_PATH . 'config/sensitive_words.txt';
        }
        return self::$words_file;
    }

    /**
     * 加载词库到 Trie 树
     */
    public static function load(): void {
        if (self::$loaded && self::$trie !== null) {
            return;
        }

        self::$trie = [];
        $file = self::get_words_file();

        if (!file_exists($file)) {
            self::$loaded = true;
            return;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            self::$loaded = true;
            return;
        }

        $words = explode("\n", trim($content));
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || $word[0] === '#') {
                continue; // 跳过空行和注释
            }
            self::insert_word($word);
        }

        self::$loaded = true;
    }

    /**
     * 强制重新加载词库
     */
    public static function reload(): void {
        self::$loaded = false;
        self::$trie = null;
        self::load();
    }

    /**
     * 向 Trie 树插入一个词
     */
    private static function insert_word(string $word): void {
        $node = &self::$trie;
        $len = mb_strlen($word, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($word, $i, 1, 'UTF-8');
            if (!isset($node[$char])) {
                $node[$char] = [];
            }
            $node = &$node[$char];
        }
        $node['#'] = true; // 词结束标记
    }

    /**
     * 从 Trie 树删除一个词
     * 简化实现：标记删除，重建时清除
     * 完整实现需要回溯删除空节点，但复杂度高
     * 这里采用从文件中删除并 reload 的方式
     */
    private static function remove_word(string $word): bool {
        return true;
    }

    /**
     * 在 Trie 树中搜索匹配
     * @return array 匹配到的敏感词列表
     */
    private static function search(string $text): array {
        self::load();

        $matched = [];
        $len = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            if (!isset(self::$trie[$char])) {
                continue;
            }

            // 从位置 i 开始匹配
            $node = self::$trie[$char];
            $word = $char;
            $j = $i + 1;

            // 检查单字是否是词
            if (isset($node['#'])) {
                $matched[] = $word;
            }

            // 继续匹配更长的词
            while ($j < $len) {
                $next_char = mb_substr($text, $j, 1, 'UTF-8');
                if (!isset($node[$next_char])) {
                    break;
                }
                $node = $node[$next_char];
                $word .= $next_char;
                $j++;
                if (isset($node['#'])) {
                    $matched[] = $word;
                }
            }
        }

        return array_unique($matched);
    }

    /**
     * 过滤文本 - 核心接口
     * @param string $text 待过滤文本
     * @param string $scene 场景（预留）
     * @return array ['pass'=>bool, 'matched_keywords'=>array, 'filtered_text'=>string]
     */
    public static function content_filter(string $text, string $scene = ''): array {
        $matched = self::search($text);
        $pass = empty($matched);
        $filtered_text = $text;

        if (!$pass) {
            // 将匹配到的敏感词替换为 ***
            foreach ($matched as $word) {
                $replacement = str_repeat('*', mb_strlen($word, 'UTF-8'));
                $filtered_text = str_replace($word, $replacement, $filtered_text);
            }
        }

        return [
            'pass' => $pass,
            'matched_keywords' => $matched,
            'filtered_text' => $filtered_text,
        ];
    }

    /**
     * 添加敏感词
     */
    public static function add_word(string $word): bool {
        $word = trim($word);
        if ($word === '') return false;

        $file = self::get_words_file();
        $content = '';
        if (file_exists($file)) {
            $content = file_get_contents($file);
        }

        // 检查是否已存在
        $words = $content ? explode("\n", trim($content)) : [];
        if (in_array($word, $words)) {
            return true; // 已存在
        }

        // 追加
        $content = trim($content) . "\n" . $word;
        file_put_contents($file, $content, LOCK_EX);

        // 重新加载
        self::reload();
        return true;
    }

    /**
     * 删除敏感词
     */
    public static function delete_word(string $word): bool {
        $word = trim($word);
        if ($word === '') return false;

        $file = self::get_words_file();
        if (!file_exists($file)) return false;

        $content = file_get_contents($file);
        $words = explode("\n", trim($content));
        $words = array_filter($words, function($w) use ($word) {
            return trim($w) !== $word;
        });

        file_put_contents($file, implode("\n", $words) . "\n", LOCK_EX);
        self::reload();
        return true;
    }

    /**
     * 批量导入敏感词（每行一个词）
     */
    public static function batch_import(string $words_text): int {
        $new_words = explode("\n", trim($words_text));
        $count = 0;

        $file = self::get_words_file();
        $content = '';
        if (file_exists($file)) {
            $content = file_get_contents($file);
        }
        $existing = $content ? explode("\n", trim($content)) : [];
        $existing_map = array_flip($existing);

        foreach ($new_words as $word) {
            $word = trim($word);
            if ($word === '' || $word[0] === '#') continue;
            if (!isset($existing_map[$word])) {
                $existing[] = $word;
                $existing_map[$word] = true;
                $count++;
            }
        }

        file_put_contents($file, implode("\n", $existing) . "\n", LOCK_EX);
        self::reload();
        return $count;
    }

    /**
     * 从txt文件导入敏感词
     * @param string $file_path 文件路径（通常是上传的临时文件）
     * @return int 导入的敏感词数量
     */
    public static function import_from_file(string $file_path): int {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return 0;
        }

        $content = file_get_contents($file_path);
        if ($content === false) {
            return 0;
        }

        // 检测编码，如果不是UTF-8则尝试转换
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return self::batch_import($content);
    }

    /**
     * 获取所有敏感词
     */
    public static function get_all_words(): array {
        $file = self::get_words_file();
        if (!file_exists($file)) return [];

        $content = file_get_contents($file);
        $words = explode("\n", trim($content));
        $result = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '' && $word[0] !== '#') {
                $result[] = $word;
            }
        }
        return $result;
    }

    /**
     * 清空词库
     */
    public static function clear_words(): bool {
        $file = self::get_words_file();
        file_put_contents($file, '', LOCK_EX);
        self::reload();
        return true;
    }

    /**
     * 测试过滤效果
     */
    public static function test(string $text): array {
        return self::content_filter($text, 'test');
    }
}
