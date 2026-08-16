<?php

/**
 * 插件兼容性扫描 - 规则定义
 * @since 1.0.2
 */
class PluginScannerRules {

    /**
     * 扫描规则：分类 => [匹配模式 => 建议]
     */
    public static function getRules(): array {
        return [
            'php_deprecated_functions' => [
                'mysql_connect', 'mysql_select_db', 'mysql_query', 'mysql_fetch_array',
                'mysql_fetch_assoc', 'mysql_fetch_row', 'mysql_num_rows', 'mysql_affected_rows',
                'mysql_insert_id', 'mysql_error', 'mysql_real_escape_string', 'mysql_close',
                'mysql_result', 'mysql_list_tables', 'mysql_list_fields', 'mysql_db_name',
                'mysql_pconnect', 'mysql_get_server_info', 'mysql_set_charset',
                '\beach\(' => lang('scanner_rule_each_removed'),
                'create_function\(' => lang('scanner_rule_create_function_removed'),
                '\bsplit\(' => lang('scanner_rule_split_removed'),
                '\bspliti\(' => lang('scanner_rule_spliti_removed'),
                '\bereg\(' => lang('scanner_rule_ereg_removed'),
                '\bereg_replace\(' => lang('scanner_rule_ereg_replace_removed'),
                '\beregi\(' => lang('scanner_rule_eregi_removed'),
                '\beregi_replace\(' => lang('scanner_rule_eregi_replace_removed'),
                'call_user_method\(' => lang('scanner_rule_call_user_method_removed'),
            ],
            'php8_syntax' => [
                '&new ' => lang('scanner_rule_amp_new_removed'),
                'preg_replace\s*\(\s*[\'"][^\'"]*\/[a-zA-Z]*e[a-zA-Z]*[\'"]' => lang('scanner_rule_preg_replace_e_removed'),
            ],
            'curly_brace_access' => [
                '\$\w+\{' => lang('scanner_rule_curly_brace_removed'),
            ],
            'http_post_vars' => [
                'HTTP_POST_VARS', 'HTTP_GET_VARS', 'HTTP_SESSION_VARS',
            ],
            'dangerous_functions' => [
                '\beval\(', '\bsystem\(', '(?<![_>])\bexec\(', '\bpassthru\(', '\bshell_exec\(',
                '\bpopen\(', '\bproc_open\(', '\bpcntl_exec\(', '\bassert\(',
            ],
            'bs4_classes' => [
                ' ml-' => lang('scanner_rule_bs4_ml'),
                ' mr-' => lang('scanner_rule_bs4_mr'),
                ' pl-' => lang('scanner_rule_bs4_pl'),
                ' pr-' => lang('scanner_rule_bs4_pr'),
                '"media"' => lang('scanner_rule_bs4_media'),
                '"media-body"' => lang('scanner_rule_bs4_media_body'),
                'form-group' => lang('scanner_rule_bs4_form_group'),
                'form-control-label' => lang('scanner_rule_bs4_form_control_label'),
                'custom-select' => lang('scanner_rule_bs4_custom_select'),
                'custom-control' => lang('scanner_rule_bs4_custom_control'),
                'btn-block' => lang('scanner_rule_bs4_btn_block'),
                'input-group-prepend' => lang('scanner_rule_bs4_input_group_prepend'),
                'input-group-append' => lang('scanner_rule_bs4_input_group_append'),
            ],
            'bs4_data_attrs' => [
                // 带词边界正则：前后不能是 [-\w]，避免 data-bs-target（BS5 正确写法）和 data-count-target 等自定义属性被误匹配
                '(?<![-\w])data-toggle(?![\w-])' => lang('scanner_rule_bs4_data_toggle'),
                '(?<![-\w])data-dismiss(?![\w-])' => lang('scanner_rule_bs4_data_dismiss'),
                '(?<![-\w])data-target(?![\w-])' => lang('scanner_rule_bs4_data_target'),
                '(?<![-\w])data-slide-to(?![\w-])' => lang('scanner_rule_bs4_data_slide_to'),
                '(?<![-\w])data-slide(?![\w-])' => lang('scanner_rule_bs4_data_slide'),
            ],
            'fontello_icons' => [
                'icon-lock' => 'ti-lock',
                'icon-home' => 'ti-home',
                'icon-edit' => 'ti-pencil',
                'icon-remove' => 'ti-trash',
                'icon-eye' => 'ti-eye',
                'icon-ok' => 'ti-check',
                'icon-cog' => 'ti-settings',
                'icon-cogs' => 'ti-settings-2',
                'icon-comment' => 'ti-message',
                'icon-user' => 'ti-user',
                'icon-envelope' => 'ti-mail',
                'icon-key' => 'ti-key',
                'icon-star' => 'ti-star',
            ],
            'permission_security' => [
                'user_update.*password' => lang('scanner_rule_perm_password'),
                'user_update.*gid' => lang('scanner_rule_perm_gid'),
                'user_update.*salt' => lang('scanner_rule_perm_salt'),
                'user_update.*password_hash' => lang('scanner_rule_perm_password'),
            ],
            'jquery_usage' => [
                '\$\.ajax\(' => lang('scanner_rule_jq_ajax'),
                '\$\.post\(' => lang('scanner_rule_jq_post'),
                '\$\.get\(' => lang('scanner_rule_jq_get'),
                '\$\.each\(' => lang('scanner_rule_jq_each'),
                '\$\.fn\.' => lang('scanner_rule_jq_fn'),
                '\$\.extend\(' => lang('scanner_rule_jq_extend'),
                '\$\.trim\(' => lang('scanner_rule_jq_trim'),
                '\$\.parseJSON\(' => lang('scanner_rule_jq_parse_json'),
                '\$\.isArray\(' => lang('scanner_rule_jq_is_array'),
                '\$\.isFunction\(' => lang('scanner_rule_jq_is_function'),
                '\$\.browser' => lang('scanner_rule_jq_browser'),
                '\$\(document\)\.ready' => lang('scanner_rule_jq_ready'),
                '\$\(function\(' => lang('scanner_rule_jq_function'),
                'jQuery\(' => lang('scanner_rule_jq_jquery'),
            ],
            'bs3_classes' => [
                'panel-heading' => lang('scanner_rule_bs3_panel_heading'),
                'panel-body' => lang('scanner_rule_bs3_panel_body'),
                'panel-footer' => lang('scanner_rule_bs3_panel_footer'),
                'panel-default' => lang('scanner_rule_bs3_panel_default'),
                'panel-primary' => lang('scanner_rule_bs3_panel_primary'),
                'panel-success' => lang('scanner_rule_bs3_panel_success'),
                'panel-info' => lang('scanner_rule_bs3_panel_info'),
                'panel-warning' => lang('scanner_rule_bs3_panel_warning'),
                'panel-danger' => lang('scanner_rule_bs3_panel_danger'),
                'well' => lang('scanner_rule_bs3_well'),
                'glyphicon' => lang('scanner_rule_bs3_glyphicon'),
                'pull-left' => lang('scanner_rule_bs3_pull_left'),
                'pull-right' => lang('scanner_rule_bs3_pull_right'),
                'hidden-xs' => lang('scanner_rule_bs3_hidden_xs'),
                'visible-xs' => lang('scanner_rule_bs3_visible_xs'),
                'label-default' => lang('scanner_rule_bs3_label_default'),
                'label-primary' => lang('scanner_rule_bs3_label_primary'),
                'label-success' => lang('scanner_rule_bs3_label_success'),
                'label-info' => lang('scanner_rule_bs3_label_info'),
                'label-warning' => lang('scanner_rule_bs3_label_warning'),
                'label-danger' => lang('scanner_rule_bs3_label_danger'),
                'img-responsive' => lang('scanner_rule_bs3_img_responsive'),
                'img-circle' => lang('scanner_rule_bs3_img_circle'),
                'img-rounded' => lang('scanner_rule_bs3_img_rounded'),
                'col-xs-' => lang('scanner_rule_bs3_col_xs'),
            ],
            'bs_js_api' => [
                '\$\(.*\)\.modal\(' => lang('scanner_rule_bsjs_modal'),
                '\$\(.*\)\.dropdown\(' => lang('scanner_rule_bsjs_dropdown'),
                '\$\(.*\)\.tooltip\(' => lang('scanner_rule_bsjs_tooltip'),
                '\$\(.*\)\.popover\(' => lang('scanner_rule_bsjs_popover'),
                '\$\(.*\)\.collapse\(' => lang('scanner_rule_bsjs_collapse'),
                '\$\(.*\)\.carousel\(' => lang('scanner_rule_bsjs_carousel'),
                '\$\(.*\)\.alert\(' => lang('scanner_rule_bsjs_alert'),
                '\$\(.*\)\.button\(' => lang('scanner_rule_bsjs_button'),
                '\$\(.*\)\.tab\(' => lang('scanner_rule_bsjs_tab'),
            ],
            'missing_csrf' => [
                'method="post"' => lang('scanner_missing_csrf'),
            ],
            'direct_db' => [
                'db_exec\(' => lang('scanner_rule_direct_db_exec'),
                'db_sql_find_one\(' => lang('scanner_rule_direct_db_find_one'),
                'db_sql_find\(' => lang('scanner_rule_direct_db_find'),
            ],
            'php8_deprecated' => [
                'get_magic_quotes_gpc' => lang('scanner_rule_php8_magic_quotes_gpc'),
                'get_magic_quotes_runtime' => lang('scanner_rule_php8_magic_quotes_runtime'),
                'utf8_encode\(' => lang('scanner_rule_php8_utf8_encode'),
                'utf8_decode\(' => lang('scanner_rule_php8_utf8_decode'),
                'money_format\(' => lang('scanner_rule_php8_money_format'),
                'is_resource\(' => lang('scanner_rule_php8_is_resource'),
            ],
            'icon_libraries' => [
                'class="[^"]*\bfa-[a-z]' => lang('scanner_rule_icon_fa'),
                'class="[^"]*\bbi-[a-z]' => lang('scanner_rule_icon_bi'),
                'class="[^"]*glyphicon glyphicon-' => lang('scanner_rule_icon_glyphicon'),
            ],
            'frontend_md5' => [
                'hex_md5\(' => lang('scanner_rule_frontend_md5'),
                'md5_hex\(' => lang('scanner_rule_frontend_md5'),
            ],
            // XSS 风险检测（warning 级别，可跳过）
            'php_superglobal_output' => [
                '\b(echo|print|printf)\b.*\$_(GET|POST|REQUEST|SERVER|COOKIE)\b' => lang('scanner_rule_superglobal_output'),
            ],
            'js_eval_call' => [
                '\beval\s*\(' => lang('scanner_rule_js_eval'),
            ],
            'js_dom_xss' => [
                '\bdocument\.write(?:ln)?\s*\(' => lang('scanner_rule_js_dom_write'),
                '\.innerHTML\s*=[^=]' => lang('scanner_rule_js_dom_innerhtml'),
                '\.outerHTML\s*=[^=]' => lang('scanner_rule_js_dom_outerhtml'),
                '\.insertAdjacentHTML\s*\(' => lang('scanner_rule_js_dom_insert'),
            ],
            'jquery_html_xss' => [
                '\$\(.*\)\.html\s*\(' => lang('scanner_rule_jquery_html_xss'),
            ],
        ];
    }

    /**
     * 严重级别映射
     */
    public static function getSeverityLevels(): array {
        return [
            'php_deprecated_functions' => 'fatal',
            'php8_syntax' => 'fatal',
            'curly_brace_access' => 'fatal',
            'http_post_vars' => 'fatal',
            'dangerous_functions' => 'fatal',
            'bs4_classes' => 'medium',
            'bs4_data_attrs' => 'medium',
            'fontello_icons' => 'medium',
            'permission_security' => 'warning',
            'jquery_usage' => 'medium',
            'bs3_classes' => 'medium',
            'bs_js_api' => 'warning',
            'missing_csrf' => 'info',
            'direct_db' => 'info',
            'php8_deprecated' => 'fatal',
            'icon_libraries' => 'medium',
            'php_comment_close_tag' => 'fatal',
            'frontend_md5' => 'warning',
            'md5js_global_load' => 'warning',
            'password_update_api' => 'warning',
            'db_charset' => 'warning',
            'service_undefined_var' => 'fatal',
            'raw_htmlspecialchars' => 'warning',
            'heredoc_php_tag' => 'fatal',
            'bs_tab_navigation' => 'warning',
            'hook_htm_header' => 'fatal',
            'db_find_col_string' => 'warning',
            'app_path_in_url' => 'fatal',
            'install_non_idempotent' => 'warning',
            'capabilities_format' => 'warning',
            'conf_version' => 'error',
            'conf_required_fields' => 'fatal',
            // XSS 风险统一 warning（可跳过，不强制阻止安装）
            'php_superglobal_output' => 'warning',
            'js_eval_call' => 'warning',
            'js_dom_xss' => 'warning',
            'jquery_html_xss' => 'warning',
        ];
    }

    /**
     * force=1 不可跳过的分类（检测到即阻止安装，不可被用户手动跳过）
     * 包含所有 fatal 级分类 + bbs_version 兼容性检查（error 级但强制阻止）
     */
    public static function getForceCategories(): array {
        return [
            'php_deprecated_functions',
            'php8_syntax',
            'curly_brace_access',
            'http_post_vars',
            'dangerous_functions',
            'php8_deprecated',
            'php_comment_close_tag',
            'service_undefined_var',
            'heredoc_php_tag',
            'hook_htm_header',
            'app_path_in_url',
            'conf_version',
            'conf_required_fields',
        ];
    }

    /**
     * 分类中文名
     */
    public static function getCategoryNames(): array {
        return [
            'php_deprecated_functions' => lang('scanner_cat_php_deprecated_functions'),
            'php8_syntax' => lang('scanner_cat_php8_syntax'),
            'curly_brace_access' => lang('scanner_cat_curly_brace_access'),
            'http_post_vars' => lang('scanner_cat_http_post_vars'),
            'dangerous_functions' => lang('scanner_cat_dangerous_functions'),
            'bs4_classes' => lang('scanner_cat_bs4_classes'),
            'bs4_data_attrs' => lang('scanner_cat_bs4_data_attrs'),
            'fontello_icons' => lang('scanner_cat_fontello_icons'),
            'permission_security' => lang('scanner_cat_permission_security'),
            'jquery_usage' => lang('scanner_cat_jquery_usage'),
            'bs3_classes' => lang('scanner_cat_bs3_classes'),
            'bs_js_api' => lang('scanner_cat_bs_js_api'),
            'missing_csrf' => lang('scanner_cat_missing_csrf'),
            'direct_db' => lang('scanner_cat_direct_db'),
            'php8_deprecated' => lang('scanner_cat_php8_deprecated'),
            'icon_libraries' => lang('scanner_cat_icon_libraries'),
            'php_comment_close_tag' => lang('scanner_cat_php_comment_close_tag'),
            'frontend_md5' => lang('scanner_cat_frontend_md5'),
            'md5js_global_load' => lang('scanner_cat_md5js_global_load'),
            'password_update_api' => lang('scanner_cat_password_update_api'),
            'db_charset' => lang('scanner_cat_db_charset'),
            'service_undefined_var' => lang('scanner_cat_service_undefined_var'),
            'raw_htmlspecialchars' => lang('scanner_cat_raw_htmlspecialchars'),
            'heredoc_php_tag' => lang('scanner_cat_heredoc_php_tag'),
            'bs_tab_navigation' => lang('scanner_cat_bs_tab_navigation'),
            'hook_htm_header' => lang('scanner_cat_hook_htm_header'),
            'db_find_col_string' => lang('scanner_cat_db_find_col_string'),
            'app_path_in_url' => lang('scanner_cat_app_path_in_url'),
            'install_non_idempotent' => lang('scanner_cat_install_non_idempotent'),
            'capabilities_format' => lang('scanner_cat_capabilities_format'),
            'conf_version' => lang('scanner_cat_conf_version'),
            'conf_required_fields' => lang('scanner_cat_conf_required_fields'),
            // XSS 风险分类中文名
            'php_superglobal_output' => lang('scanner_cat_php_superglobal_output'),
            'js_eval_call' => lang('scanner_cat_js_eval_call'),
            'js_dom_xss' => lang('scanner_cat_js_dom_xss'),
            'jquery_html_xss' => lang('scanner_cat_jquery_html_xss'),
        ];
    }

    /**
     * 仅适用于 PHP 代码的分类（不应扫描 JS/CSS 内容）
     */
    public static function getPhpOnlyCategories(): array {
        return [
            'php_deprecated_functions',
            'php8_syntax',
            'curly_brace_access',
            'http_post_vars',
            'dangerous_functions',
            'permission_security',
            'php8_deprecated',
            'direct_db',
            'password_update_api',
            'db_charset',
            'service_undefined_var',
            'raw_htmlspecialchars',
            'db_find_col_string',
            'php_superglobal_output',
        ];
    }

    /**
     * 仅适用于 HTML/模板内容的分类（不应扫描纯 PHP 逻辑代码）
     */
    public static function getHtmlOnlyCategories(): array {
        return [
            'bs4_classes',
            'bs4_data_attrs',
            'fontello_icons',
            'bs3_classes',
            'icon_libraries',
        ];
    }

    /**
     * 仅适用于 JS 内容的分类（只在 <script> 块和 .js 文件中扫描）
     * 避免 PHP 代码中字符串里的 JS 函数名被误报
     */
    public static function getJsOnlyCategories(): array {
        return [
            'js_eval_call',
            'js_dom_xss',
            'jquery_html_xss',
        ];
    }
}
