<?php
!defined('DEBUG') AND exit('Access Denied.');

/**
 * 敏感词过滤服务 - Trie 树实现，支持本地词库和在线管理
 *
 * 双词库设计：
 * - reserved:  保留词库（admin/管理员/版主等），仅用于用户名/昵称注册时拦截，防止冒充
 * - sensitive: 内容敏感词库，用于发帖/回帖/签名等用户产生的内容
 *
 * 接口：
 * - content_filter($text, $type): 过滤文本，返回 ['pass'=>bool, 'matched_keywords'=>[], 'filtered_text'=>string]
 * - content_check($text, $type):  仅检查，返回 ['pass'=>bool, 'matched_keywords'=>[]]（不替换，用于拦截场景）
 * - filter_load_words($type):     加载指定词库到 Trie 树
 * - filter_add_word($word, $type):    添加词
 * - filter_delete_word($word, $type): 删除词
 * - filter_batch_import($words_text, $type): 批量导入
 * - filter_get_all_words($type):  获取所有词
 * - filter_clear_words($type):    清空词库
 * - filter_test($text, $type):    测试过滤效果
 *
 * $type 取值：'reserved' 或 'sensitive'，默认 'sensitive'
 */
class SensitiveWordFilter {

    // 词库类型白名单
    const TYPE_RESERVED  = 'reserved';
    const TYPE_SENSITIVE = 'sensitive';

    // 两棵 Trie 树根节点，按类型隔离
    private static array $tries = [
        self::TYPE_RESERVED  => null,
        self::TYPE_SENSITIVE => null,
    ];

    // 词库文件路径缓存
    private static array $words_files = [
        self::TYPE_RESERVED  => '',
        self::TYPE_SENSITIVE => '',
    ];

    // 是否已加载
    private static array $loaded = [
        self::TYPE_RESERVED  => false,
        self::TYPE_SENSITIVE => false,
    ];

    /**
     * 校验并归一化词库类型
     */
    private static function normalize_type(string $type): string {
        if ($type !== self::TYPE_RESERVED && $type !== self::TYPE_SENSITIVE) {
            $type = self::TYPE_SENSITIVE;
        }
        return $type;
    }

    /**
     * 获取词库文件路径
     */
    private static function get_words_file(string $type): string {
        $type = self::normalize_type($type);
        if (empty(self::$words_files[$type])) {
            $filename = $type === self::TYPE_RESERVED ? 'reserved_words.txt' : 'sensitive_words.txt';
            self::$words_files[$type] = APP_PATH . 'config/' . $filename;
        }
        return self::$words_files[$type];
    }

    /**
     * 加载词库到 Trie 树
     */
    public static function load(string $type = self::TYPE_SENSITIVE): void {
        $type = self::normalize_type($type);
        if (self::$loaded[$type] && self::$tries[$type] !== null) {
            return;
        }

        self::$tries[$type] = [];
        $file = self::get_words_file($type);

        if (!file_exists($file)) {
            self::$loaded[$type] = true;
            return;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            self::$loaded[$type] = true;
            return;
        }

        $words = explode("\n", trim($content));
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || $word[0] === '#') {
                continue; // 跳过空行和注释
            }
            self::insert_word($word, $type);
        }

        self::$loaded[$type] = true;
    }

    /**
     * 强制重新加载词库
     */
    public static function reload(string $type = self::TYPE_SENSITIVE): void {
        $type = self::normalize_type($type);
        self::$loaded[$type] = false;
        self::$tries[$type] = null;
        self::load($type);
    }

    /**
     * 向 Trie 树插入一个词
     */
    private static function insert_word(string $word, string $type): void {
        $type = self::normalize_type($type);
        $node = &self::$tries[$type];
        if ($node === null) {
            $node = [];
        }
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
     * 在 Trie 树中搜索匹配
     * @return array 匹配到的敏感词列表
     */
    private static function search(string $text, string $type): array {
        $type = self::normalize_type($type);
        self::load($type);

        // 空词库直接返回
        if (self::$tries[$type] === null || empty(self::$tries[$type])) {
            return [];
        }

        $matched = [];
        $len = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            if (!isset(self::$tries[$type][$char])) {
                continue;
            }

            // 从位置 i 开始匹配
            $node = self::$tries[$type][$char];
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
     * 过滤文本 - 替换为星号（保留旧接口，仅供测试/历史调用使用）
     * @param string $text 待过滤文本
     * @param string $type 词库类型：'reserved' 或 'sensitive'
     * @return array ['pass'=>bool, 'matched_keywords'=>array, 'filtered_text'=>string]
     */
    public static function content_filter(string $text, string $type = self::TYPE_SENSITIVE): array {
        // 兼容旧调用：第二参数曾经是 $scene，若传入非 reserved/sensitive 视为 sensitive
        $type = self::normalize_type($type);
        $matched = self::search($text, $type);
        $pass = empty($matched);
        $filtered_text = $text;

        if (!$pass) {
            // 将匹配到的词替换为等长星号
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
     * 仅检查文本是否命中词库（不替换，用于拦截场景）
     * @param string $text 待检查文本
     * @param string $type 词库类型：'reserved' 或 'sensitive'
     * @return array ['pass'=>bool, 'matched_keywords'=>array]
     */
    public static function content_check(string $text, string $type = self::TYPE_SENSITIVE): array {
        $type = self::normalize_type($type);
        $matched = self::search($text, $type);
        return [
            'pass' => empty($matched),
            'matched_keywords' => $matched,
        ];
    }

    /**
     * 添加词
     */
    public static function add_word(string $word, string $type = self::TYPE_SENSITIVE): bool {
        $type = self::normalize_type($type);
        $word = trim($word);
        if ($word === '') return false;

        $file = self::get_words_file($type);
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
        if (file_put_contents($file, $content, LOCK_EX) === false) {
            xn_log('sensitive word add write failed: ' . $file, 'error_sensitive_word.php');
            return false;
        }

        // 重新加载
        self::reload($type);
        return true;
    }

    /**
     * 删除词
     */
    public static function delete_word(string $word, string $type = self::TYPE_SENSITIVE): bool {
        $type = self::normalize_type($type);
        $word = trim($word);
        if ($word === '') return false;

        $file = self::get_words_file($type);
        if (!file_exists($file)) return false;

        $content = file_get_contents($file);
        $words = explode("\n", trim($content));
        $words = array_filter($words, function($w) use ($word) {
            return trim($w) !== $word;
        });

        if (file_put_contents($file, implode("\n", $words) . "\n", LOCK_EX) === false) {
            xn_log('sensitive word delete write failed: ' . $file, 'error_sensitive_word.php');
            return false;
        }
        self::reload($type);
        return true;
    }

    /**
     * 批量导入词（每行一个词）
     */
    public static function batch_import(string $words_text, string $type = self::TYPE_SENSITIVE): int {
        $type = self::normalize_type($type);
        $new_words = explode("\n", trim($words_text));
        $count = 0;

        $file = self::get_words_file($type);
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

        if (file_put_contents($file, implode("\n", $existing) . "\n", LOCK_EX) === false) {
            xn_log('sensitive word import write failed: ' . $file, 'error_sensitive_word.php');
            return 0;
        }
        self::reload($type);
        return $count;
    }

    /**
     * 从txt文件导入词
     * @param string $file_path 文件路径（通常是上传的临时文件）
     * @param string $type 词库类型
     * @return int 导入的词数量
     */
    public static function import_from_file(string $file_path, string $type = self::TYPE_SENSITIVE): int {
        $type = self::normalize_type($type);
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

        return self::batch_import($content, $type);
    }

    /**
     * 获取所有词
     */
    public static function get_all_words(string $type = self::TYPE_SENSITIVE): array {
        $type = self::normalize_type($type);
        $file = self::get_words_file($type);
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
    public static function clear_words(string $type = self::TYPE_SENSITIVE): bool {
        $type = self::normalize_type($type);
        $file = self::get_words_file($type);
        if (file_put_contents($file, '', LOCK_EX) === false) {
            xn_log('sensitive word clear write failed: ' . $file, 'error_sensitive_word.php');
            return false;
        }
        self::reload($type);
        return true;
    }

    /**
     * 测试过滤效果
     */
    public static function test(string $text, string $type = self::TYPE_SENSITIVE): array {
        return self::content_filter($text, $type);
    }
}
