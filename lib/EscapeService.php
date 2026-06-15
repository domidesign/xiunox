<?php

function esc_html($var) {
    return htmlspecialchars((string)$var, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function esc_attr($var) {
    return htmlspecialchars((string)$var, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function esc_js($var) {
    return htmlspecialchars(json_encode((string)$var, JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
