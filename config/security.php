<?php
// 安全与审核系统配置
// 修改后需清理 tmp/ 缓存

return array(

    // 验证码配置
    'captcha' => array(
        // 各场景开关（0关闭/1开启）
        'login' => 0,
        'register' => 0,
        'post' => 0,
        'resetpw' => 0,
        // 验证码类型：gd_image（图片验证码）/ gd_math（算术验证码）
        'type' => 'gd_image',
    ),

    // 敏感词过滤配置
    'sensitive_word' => array(
        // 是否启用敏感词过滤
        'enabled' => 0,
        // 命中后动作：reject（拒绝发布）/ review（进入审核）/ replace（替换为***）
        'action' => 'reject',
        // 词库文件路径
        'words_file' => APP_PATH . 'config/sensitive_words.txt',
    ),

    // 帖子审核配置
    'audit' => array(
        // 是否启用审核系统
        'enabled' => 0,
        // 审核通过后是否补发积分
        'credits_on_approve' => 0,
        // 补发积分数
        'credits_amount' => 1,
    ),

    // 内容安全审核配置
    'moderation' => array(
        // 是否启用（内置实现默认关闭，需插件提供具体实现）
        'enabled' => 0,
    ),

    // 安全增强配置
    'security' => array(
        // 防止用户名枚举
        'prevent_enumeration' => 1,
        // 敏感操作二次验证
        'verify_sensitive_action' => 1,
        // 登录后展示上次登录信息
        'show_last_login' => 1,
    ),

);
