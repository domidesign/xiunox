<?php

function esc_html($var) {
    return htmlspecialchars((string)$var, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function esc_attr($var) {
    return htmlspecialchars((string)$var, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function esc_js($var) {
	// 用于 script 标签内单引号字符串上下文
	// json_encode 处理双引号、反斜杠、控制字符；去掉外层双引号；额外转义单引号
	$s = json_encode((string)$var, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$s = substr($s, 1, -1);
	$s = str_replace("'", "\\'", $s);
	// 防止 script 标签注入
	$s = str_replace('</', '<\/', $s);
	return $s;
}
