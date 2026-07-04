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
                '\beach\(' => 'each() 函数已移除，改用 foreach', 
                'create_function(', 
                '\bsplit\(' => 'split() 函数已移除，改用 preg_split 或 explode',
                '\bspliti\(' => 'spliti() 函数已移除，改用 preg_split 带 i 修饰符',
                '\bereg\(' => 'ereg() 函数已移除，改用 preg_match',
                '\bereg_replace\(' => 'ereg_replace() 函数已移除，改用 preg_replace',
                '\beregi\(' => 'eregi() 函数已移除，改用 preg_match 带 i 修饰符',
                '\beregi_replace\(' => 'eregi_replace() 函数已移除，改用 preg_replace 带 i 修饰符',
                'call_user_method(',
            ],
            'php8_syntax' => [
                '&new ' => '&new 语法已移除，改用 new',
                'preg_replace.*\/e' => 'preg_replace /e 修饰符已移除',
            ],
            'curly_brace_access' => [
                '\$\w+\{' => '花括号数组访问已移除，改用方括号 $arr[0]',
            ],
            'http_post_vars' => [
                'HTTP_POST_VARS', 'HTTP_GET_VARS', 'HTTP_SESSION_VARS',
            ],
            'dangerous_functions' => [
                '\beval\(', '\bsystem\(', '(?<![_>])\bexec\(', '\bpassthru\(', '\bshell_exec\(',
                '\bpopen\(', '\bproc_open\(', '\bpcntl_exec\(',
            ],
            'bs4_classes' => [
                ' ml-' => 'BS4 margin-left → BS5 ms-',
                ' mr-' => 'BS4 margin-right → BS5 me-',
                ' pl-' => 'BS4 padding-left → BS5 ps-',
                ' pr-' => 'BS4 padding-right → BS5 pe-',
                '"media"' => 'BS4 .media → BS5 flex 布局',
                '"media-body"' => 'BS4 .media-body → BS5 flex-grow-1',
                'form-group' => 'BS4 .form-group → BS5 .mb-3',
                'form-control-label' => 'BS4 → BS5 .form-label',
                'custom-select' => 'BS4 .custom-select → BS5 .form-select',
                'custom-control' => 'BS4 .custom-control → BS5 .form-check',
                'btn-block' => 'BS4 .btn-block → BS5 .w-100',
                'input-group-prepend' => 'BS4 input-group-prepend → BS5 直接 input-group-text',
                'input-group-append' => 'BS4 input-group-append → BS5 直接 input-group-text',
            ],
            'bs4_data_attrs' => [
                // 带词边界正则：前后不能是 [-\w]，避免 data-bs-target（BS5 正确写法）和 data-count-target 等自定义属性被误匹配
                '(?<![-\w])data-toggle(?![\w-])' => 'BS4 data-toggle → BS5 data-bs-toggle',
                '(?<![-\w])data-dismiss(?![\w-])' => 'BS4 data-dismiss → BS5 data-bs-dismiss',
                '(?<![-\w])data-target(?![\w-])' => 'BS4 data-target → BS5 data-bs-target',
                '(?<![-\w])data-slide-to(?![\w-])' => 'BS4 data-slide-to → BS5 data-bs-slide-to',
                '(?<![-\w])data-slide(?![\w-])' => 'BS4 data-slide → BS5 data-bs-slide',
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
                'user_update.*password' => '请改用 user_change_password() 修改密码',
                'user_update.*gid' => '请改用 user_change_group() 修改用户组',
                'user_update.*salt' => 'salt 字段由系统自动管理，不应直接修改',
                'user_update.*password_hash' => '请改用 user_change_password() 修改密码',
            ],
            'jquery_usage' => [
                '\$\.ajax\(' => '使用 htmx 或原生 fetch 替代 $.ajax',
                '\$\.post\(' => '使用 htmx hx-post 或原生 fetch 替代 $.post',
                '\$\.get\(' => '使用 htmx hx-get 或原生 fetch 替代 $.get',
                '\$\.each\(' => '使用 Array.forEach() 替代 $.each',
                '\$\.fn\.' => '迁移到原生 JS 类或 htmx 组件',
                '\$\.extend\(' => '使用 Object.assign() 替代 $.extend',
                '\$\.trim\(' => '使用 String.trim() 替代 $.trim',
                '\$\.parseJSON\(' => '使用 JSON.parse() 替代 $.parseJSON',
                '\$\.isArray\(' => '使用 Array.isArray() 替代 $.isArray',
                '\$\.isFunction\(' => '使用 typeof fn === "function" 替代 $.isFunction',
                '\$\.browser' => '$.browser 已移除，使用特性检测替代',
                '\$\(document\)\.ready' => '使用 DOMContentLoaded 替代 $(document).ready',
                '\$\(function\(' => '使用 DOMContentLoaded 替代 $(function())',
                'jQuery\(' => '迁移到 htmx 4 属性或原生 JS',
            ],
            'bs3_classes' => [
                'panel-heading' => 'BS3 .panel-heading → BS5 .card-header',
                'panel-body' => 'BS3 .panel-body → BS5 .card-body',
                'panel-footer' => 'BS3 .panel-footer → BS5 .card-footer',
                'panel-default' => 'BS3 .panel → BS5 .card',
                'panel-primary' => 'BS3 .panel-primary → BS5 .card+ 颜色',
                'panel-success' => 'BS3 .panel-success → BS5 .card+ 颜色',
                'panel-info' => 'BS3 .panel-info → BS5 .card+ 颜色',
                'panel-warning' => 'BS3 .panel-warning → BS5 .card+ 颜色',
                'panel-danger' => 'BS3 .panel-danger → BS5 .card+ 颜色',
                'well' => 'BS3 .well → BS5 .card.card-body 或自定义样式',
                'glyphicon' => 'BS3 Glyphicon → Tabler Icons ti-*',
                'pull-left' => 'BS3 .pull-left → BS5 .float-start',
                'pull-right' => 'BS3 .pull-right → BS5 .float-end',
                'hidden-xs' => 'BS3 .hidden-xs → BS5 .d-none .d-sm-block',
                'visible-xs' => 'BS3 .visible-xs → BS5 .d-sm-none',
                'label-default' => 'BS3 .label → BS5 .badge',
                'label-primary' => 'BS3 .label-primary → BS5 .badge.bg-primary',
                'label-success' => 'BS3 .label-success → BS5 .badge.bg-success',
                'label-info' => 'BS3 .label-info → BS5 .badge.bg-info',
                'label-warning' => 'BS3 .label-warning → BS5 .badge.bg-warning',
                'label-danger' => 'BS3 .label-danger → BS5 .badge.bg-danger',
                'img-responsive' => 'BS3 .img-responsive → BS5 .img-fluid',
                'img-circle' => 'BS3 .img-circle → BS5 .rounded-circle',
                'img-rounded' => 'BS3 .img-rounded → BS5 .rounded',
                'col-xs-' => 'BS3 .col-xs- → BS5 .col-（xs 已移除）',
            ],
            'bs_js_api' => [
                '\$\(.*\)\.modal\(' => 'jQuery .modal() → new bootstrap.Modal() 或 htmx hx-get 加载弹窗',
                '\$\(.*\)\.dropdown\(' => 'jQuery .dropdown() → new bootstrap.Dropdown()',
                '\$\(.*\)\.tooltip\(' => 'jQuery .tooltip() → new bootstrap.Tooltip()',
                '\$\(.*\)\.popover\(' => 'jQuery .popover() → new bootstrap.Popover()',
                '\$\(.*\)\.collapse\(' => 'jQuery .collapse() → new bootstrap.Collapse()',
                '\$\(.*\)\.carousel\(' => 'jQuery .carousel() → new bootstrap.Carousel()',
                '\$\(.*\)\.alert\(' => 'jQuery .alert() → new bootstrap.Alert()',
                '\$\(.*\)\.button\(' => 'jQuery .button("loading") → htmx hx-disabled-elt 或原生 JS disabled 属性',
                '\$\(.*\)\.tab\(' => 'jQuery .tab() → new bootstrap.Tab()',
            ],
            'missing_csrf' => [
                'method="post"' => 'POST 表单缺少 CSRF 令牌，请添加 CsrfService::input()',
            ],
            'direct_db' => [
                'db_exec(' => '原始 SQL 执行，注意 SQL 注入风险',
                'db_sql_find_one(' => '原始 SQL 查询，注意 SQL 注入风险，建议优先使用 db_find_one()',
                'db_sql_find(' => '原始 SQL 查询，注意 SQL 注入风险，建议优先使用 db_find()',
            ],
            'php8_deprecated' => [
                'get_magic_quotes_gpc' => 'get_magic_quotes_gpc() 在 PHP 7.4 废弃、8.0 移除，始终返回 false',
                'get_magic_quotes_runtime' => 'get_magic_quotes_runtime() 在 PHP 7.4 废弃、8.0 移除',
                'utf8_encode(' => 'utf8_encode() 在 PHP 8.2 废弃，使用 mb_convert_encoding()',
                'utf8_decode(' => 'utf8_decode() 在 PHP 8.2 废弃，使用 mb_convert_encoding()',
                'money_format(' => 'money_format() 在 PHP 7.4 废弃、8.0 移除，使用 NumberFormatter',
                'is_resource(' => 'is_resource() 对 PDO/MySQLi 对象返回 false（PHP 8.0+），改用 instanceof',
            ],
            'icon_libraries' => [
                'class="[^"]*\bfa-[a-z]' => 'Font Awesome 图标 → Tabler Icons ti-*',
                'class="[^"]*\bbi-[a-z]' => 'Bootstrap Icons → Tabler Icons ti-*',
                'class="[^"]*glyphicon glyphicon-' => 'Glyphicon 图标 → Tabler Icons ti-*',
            ],
            'frontend_md5' => [
                'hex_md5(' => '前端 MD5 哈希已移除，密码必须明文提交由服务端 password_md5() 处理',
                'md5_hex(' => '前端 MD5 哈希已移除，密码必须明文提交由服务端 password_md5() 处理',
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
        ];
    }

    /**
     * 分类中文名
     */
    public static function getCategoryNames(): array {
        return [
            'php_deprecated_functions' => '废弃函数（PHP 7.x 移除）',
            'php8_syntax' => 'PHP 8 不兼容语法',
            'curly_brace_access' => '花括号数组访问',
            'http_post_vars' => 'HTTP_*_VARS 变量',
            'dangerous_functions' => '危险函数',
            'bs4_classes' => 'BS4 旧类名',
            'bs4_data_attrs' => 'BS4 旧 data 属性',
            'fontello_icons' => 'Fontello 旧图标',
            'permission_security' => '权限安全（敏感字段修改）',
            'jquery_usage' => 'jQuery 使用（建议迁移 htmx/原生 JS）',
            'bs3_classes' => 'Bootstrap 3 旧类名',
            'bs_js_api' => 'Bootstrap jQuery 插件调用',
            'missing_csrf' => 'POST 表单缺少 CSRF 令牌',
            'direct_db' => '原始 SQL 操作（注意注入风险）',
            'php8_deprecated' => 'PHP 8.0+ 废弃函数',
            'icon_libraries' => '非 Tabler Icons 图标库',
            'php_comment_close_tag' => 'PHP 注释中包含 ?> 结束标签',
            'frontend_md5' => '前端 MD5 哈希（密码应明文提交）',
            'md5js_global_load' => '全局加载 md5.js（已禁止）',
            'password_update_api' => 'user_update() 修改密码（应用 user__update()）',
            'db_charset' => '数据库字符集 utf8（应为 utf8mb4）',
            'service_undefined_var' => 'Service 类 SQL 拼接使用未定义变量',
            'raw_htmlspecialchars' => '裸 htmlspecialchars（应用 esc_html/esc_attr/esc_js）',
            'heredoc_php_tag' => 'HEREDOC 内含 PHP 标签（应用 {$var} 语法）',
            'bs_tab_navigation' => '外层导航误用 Bootstrap Tab（应改用 a 链接）',
            'hook_htm_header' => '.htm hook 文件以 exit 开头（会白屏）',
            'db_find_col_string' => 'db_find_one() 第 4 参数为字符串（应为数组）',
            'app_path_in_url' => 'script/link 用 APP_PATH（浏览器无法访问）',
            'install_non_idempotent' => 'CREATE TABLE 缺少 IF NOT EXISTS',
            'capabilities_format' => 'capabilities 字段格式不正确（应为 lowercase.dots 字符串数组）',
            'conf_version' => 'conf.json 版本兼容性检查（bbs_version 缺失或低于 1.0.2）',
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
}
