<?php
// HiddenContentGuard 独立回归：不连数据库，只测过滤器的 fail-closed 行为。
// 运行：php tool/test_hidden_content_guard.php
define('APP_PATH', dirname(__DIR__) . '/');
require APP_PATH . 'lib/HiddenContentGuard.php';

$LOCK = '[Content locked]';
$cases = array(
    // array(名称, 输入, 是否必须包含锁定占位, 是否必须不含敏感词)
    array('bbcode 基本锁定', '前文 [hidden type=login]SECRET123[/hidden] 后文', true, true),
    array('bbcode 未闭合锁定', '前文 [hidden type=login]SECRET123', true, true),
    array('bbcode 损坏开标记（实体编码）', '&lt;div class=&quot;xnx-hidden-tag&quot;&gt;SECRET123', true, true),
    array('html 块锁定', '<div class="xnx-hidden-tag" data-type="login">SECRET123</div>', true, true),
    array('html 嵌套块锁定', '<div class="xnx-hidden-tag">A<div>B</div>SECRET123</div>', true, true),
    array('嵌套 bbcode 外层锁', '[hidden type=reply]OUTER [hidden type=login]SECRET123[/hidden][/hidden]', true, true),
    array('无隐藏内容原样保留', '普通内容 no-secret-here', false, false),
    array('同名非隐藏标签不受影响', '[quote]hello[/quote] [hide]x[/hide]', false, false),
);
foreach ($cases as $case) {
    list($name, $input, $mustLock, $mustHide) = $case;
    $out = HiddenContentGuard::filter($input);
    if ($mustLock && strpos($out, $LOCK) === false) {
        throw new RuntimeException("FAIL {$name}: 未输出锁定占位，实际：{$out}");
    }
    if ($mustHide && stripos($out, 'SECRET123') !== false) {
        throw new RuntimeException("FAIL {$name}: 敏感内容泄露，实际：{$out}");
    }
    if (!$mustLock && !$mustHide) {
        if ($out !== $input) {
            throw new RuntimeException("FAIL {$name}: 无隐藏内容被误改，实际：{$out}");
        }
    }
    if (HiddenContentGuard::contains($input) && !HiddenContentGuard::contains($out) && strpos($out, $LOCK) === false) {
        throw new RuntimeException("FAIL {$name}: 输入含隐藏标记但输出既无标记也无占位");
    }
}
// contains 判定
if (!HiddenContentGuard::contains('[hidden type=login]x[/hidden]')) throw new RuntimeException('FAIL contains bbcode');
if (!HiddenContentGuard::contains('<div class="xnx-hidden-tag">')) throw new RuntimeException('FAIL contains html');
if (HiddenContentGuard::contains('plain text')) throw new RuntimeException('FAIL contains false positive');
// attribute 解析（双引号/单引号/裸值）
if (HiddenContentGuard::attribute('type="credit" params="1"', 'type') !== 'credit') throw new RuntimeException('FAIL attr double-quote');
if (HiddenContentGuard::attribute("data-type='user' data-params='5'", 'data-params') !== '5') throw new RuntimeException('FAIL attr single-quote');
// 自定义渲染回调（授权渲染路径使用）
$out = HiddenContentGuard::filter('[hidden type=login]SECRET123[/hidden]', function ($attrs, $body, $format) {
    return '<div>UNLOCKED:' . $body . '</div>';
});
if (strpos($out, 'UNLOCKED:SECRET123') === false) throw new RuntimeException('FAIL render callback path');
echo 'PASS: HiddenContentGuard ' . count($cases) . ' filter cases + contains/attribute/render.' . PHP_EOL;
