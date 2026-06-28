<?php
/**
 * 邮件发送函数 - 基于 PHPMailer 7.1.1
 *
 * 依赖：lib/PHPMailer/ 目录下的 PHPMailer 7.x 文件
 *
 * 用法：
 *   $smtp = array('email'=>'noreply@example.com', 'host'=>'smtp.example.com', 'port'=>465, 'user'=>'user', 'pass'=>'pass', 'ssl'=>1);
 *   xn_send_mail($smtp, '站点名称', 'to@example.com', '主题', '<p>正文</p>', array('is_html'=>true));
 */

// 加载 PHPMailer 7.x（位于 lib/PHPMailer/）
require_once APP_PATH . 'lib/PHPMailer/Exception.php';
require_once APP_PATH . 'lib/PHPMailer/PHPMailer.php';
require_once APP_PATH . 'lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\SMTP as PHPMailerSMTP;

/**
 * 发送邮件
 *
 * @param array  $smtp      SMTP 配置（email, host, port, user, pass, ssl）
 *                          ssl: 0=无加密, 1=SSL, 2=TLS
 * @param string $from_name 发件人名称
 * @param string $to_email  收件人邮箱
 * @param string $subject   邮件主题
 * @param string $body      邮件正文
 * @param array  $options   可选参数：
 *                          - charset: 字符集，默认 UTF-8
 *                          - is_html: 是否 HTML 邮件，默认 true
 *                          - alt_body: 纯文本替代正文
 *                          - reply_to: 回复邮箱
 *                          - reply_to_name: 回复人名称
 * @return bool|string 成功返回 TRUE，失败返回错误信息字符串
 */
function xn_send_mail($smtp, $from_name, $to_email, $subject, $body, $options = array()) {
    // 参数兼容：旧版调用方式 $charset 作为第6个参数
    if (is_string($options)) {
        $options = array('charset' => $options);
    }

    $charset = isset($options['charset']) ? $options['charset'] : 'UTF-8';
    $is_html = isset($options['is_html']) ? $options['is_html'] : TRUE;
    $alt_body = isset($options['alt_body']) ? $options['alt_body'] : '';
    $reply_to = isset($options['reply_to']) ? $options['reply_to'] : '';
    $reply_to_name = isset($options['reply_to_name']) ? $options['reply_to_name'] : '';

    $mail = new PHPMailer(TRUE);

    try {
        // SMTP 配置
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = intval($smtp['port']);
        $mail->SMTPAuth = TRUE;
        $mail->Username = $smtp['user'];
        $mail->Password = $smtp['pass'];
        $mail->CharSet = $charset;
        $mail->Encoding = 'base64';

        // SSL/TLS 设置
        $ssl = isset($smtp['ssl']) ? intval($smtp['ssl']) : 0;
        if ($ssl === 1) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // ssl
        } elseif ($ssl === 2) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // tls
        } else {
            $mail->SMTPSecure = '';
        }

        // 超时设置
        $mail->Timeout = 10;
        $mail->SMTPAutoTLS = FALSE;

        // 调试级别（生产环境关闭）
        $mail->SMTPDebug = 0;

        // 发件人
        $from_email = isset($smtp['email']) ? $smtp['email'] : $smtp['user'];
        $mail->setFrom($from_email, $from_name);

        // 回复地址
        if (!empty($reply_to)) {
            $mail->addReplyTo($reply_to, $reply_to_name);
        } else {
            $mail->addReplyTo($from_email, $from_name);
        }

        // 收件人
        $mail->addAddress($to_email);

        // 邮件内容
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML($is_html);

        if (!empty($alt_body)) {
            $mail->AltBody = $alt_body;
        } elseif ($is_html) {
            // 自动生成纯文本版本
            $mail->AltBody = strip_tags($body);
        }

        $mail->send();

        // 记录发送日志
        xn_email_log_write($to_email, $subject, $smtp['host'], 1, '', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');

        return TRUE;

    } catch (PHPMailerException $e) {
        $error_msg = $mail->ErrorInfo;

        // 记录失败日志
        xn_email_log_write($to_email, $subject, $smtp['host'], 0, $error_msg, isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');

        return xn_error(-1, $error_msg);
    }
}

/**
 * 记录邮件发送日志
 * 如果 bbs_email_log 表不存在则跳过（避免安装前报错）
 *
 * @param string $to_email  收件人
 * @param string $subject   主题
 * @param string $smtp_host SMTP 服务器
 * @param int    $status    状态：1=成功, 0=失败
 * @param string $error_msg 错误信息
 * @param string $ip        请求 IP
 */
function xn_email_log_write($to_email, $subject, $smtp_host, $status, $error_msg = '', $ip = '') {
    static $table_checked = FALSE;
    static $table_exists = FALSE;

    if (!$table_checked) {
        $table_checked = TRUE;
        if (function_exists('db_check_table_exists')) {
            $table_exists = db_check_table_exists('email_log');
        }
    }

    if (!$table_exists) return;

    global $time, $longip;
    $log = array(
        'to_email' => $to_email,
        'subject' => mb_substr($subject, 0, 200, 'UTF-8'),
        'smtp_host' => $smtp_host,
        'status' => $status,
        'error_msg' => mb_substr($error_msg, 0, 500, 'UTF-8'),
        'create_date' => $time,
        'ip' => $ip ?: (isset($longip) ? $longip : 0),
    );

    if (function_exists('db_create')) {
        db_create('email_log', $log);
    }
}

/**
 * 从 smtp.conf.php 获取 SMTP 配置并随机选择一个
 * 统一的 SMTP 配置获取入口
 *
 * @return array|FALSE SMTP 配置数组，无可用配置时返回 FALSE
 */
function xn_smtp_get() {
    $confile = APP_PATH . 'conf/smtp.conf.php';
    if (!is_file($confile)) return FALSE;

    $smtplist = include $confile;
    if (!is_array($smtplist) || empty($smtplist)) return FALSE;

    // 过滤掉空配置
    $valid = array();
    foreach ($smtplist as $smtp) {
        if (!empty($smtp['host']) && !empty($smtp['user'])) {
            $valid[] = $smtp;
        }
    }

    if (empty($valid)) return FALSE;

    return $valid[array_rand($valid)];
}

/**
 * 邮件发送频率限制检查
 *
 * @param string $email 收件人邮箱
 * @param string $ip    请求 IP
 * @return bool|string 通过返回 TRUE，超限返回错误信息
 */
function xn_email_rate_check($email, $ip = '') {
    if (!function_exists('kv_get')) return TRUE;

    // 读取后台配置
    $interval = 60;
    $daily_limit = 5;
    $ip_hourly_limit = 10;
    if (class_exists('SecurityConfigService')) {
        $interval = intval(SecurityConfigService::get('security_email_code_interval', 60));
        $daily_limit = intval(SecurityConfigService::get('security_email_code_daily_limit', 5));
        $ip_hourly_limit = intval(SecurityConfigService::get('security_email_code_ip_hourly_limit', 10));
    }

    global $time;

    // 同一邮箱发送间隔检查
    $email_key = 'email_rate_' . md5($email);
    $last_send = kv_get($email_key);
    if (!empty($last_send)) {
        $elapsed = $time - intval($last_send);
        if ($elapsed < $interval) {
            $remaining = $interval - $elapsed;
            return "发送太频繁，请 {$remaining} 秒后再试";
        }
    }

    // 同一邮箱每日发送上限检查
    if ($daily_limit > 0) {
        $daily_key = 'email_rate_daily_' . md5($email) . '_' . date('Ymd', $time);
        $daily_count = intval(kv_get($daily_key));
        if ($daily_count >= $daily_limit) {
            return "该邮箱今日发送次数已达上限（{$daily_limit} 次），请明天再试";
        }
    }

    // 同一 IP 每小时发送上限检查
    if (!empty($ip) && $ip_hourly_limit > 0) {
        $ip_key = 'email_rate_ip_' . $ip;
        $ip_data = kv_get($ip_key);
        if (!empty($ip_data) && is_array($ip_data)) {
            // 清理超过 60 分钟的记录
            $ip_data = array_filter($ip_data, function($t) use ($time) {
                return ($time - $t) < 3600;
            });
            if (count($ip_data) >= $ip_hourly_limit) {
                return "该 IP 发送次数已达上限，请稍后再试";
            }
        }
    }

    return TRUE;
}

/**
 * 记录邮件发送频率
 *
 * @param string $email 收件人邮箱
 * @param string $ip    请求 IP
 */
function xn_email_rate_record($email, $ip = '') {
    if (!function_exists('kv_set')) return;

    global $time;

    // 记录邮箱发送时间
    $email_key = 'email_rate_' . md5($email);
    kv_set($email_key, $time);

    // 记录邮箱每日发送计数
    $daily_key = 'email_rate_daily_' . md5($email) . '_' . date('Ymd', $time);
    $daily_count = intval(kv_get($daily_key));
    kv_set($daily_key, $daily_count + 1, 86400 * 2); // 保留 2 天自动过期

    // 记录 IP 发送次数
    if (!empty($ip)) {
        $ip_key = 'email_rate_ip_' . $ip;
        $ip_data = kv_get($ip_key);
        if (!is_array($ip_data)) $ip_data = array();
        $ip_data[] = $time;
        // 只保留最近 60 分钟的记录
        $ip_data = array_filter($ip_data, function($t) use ($time) {
            return ($time - $t) < 3600;
        });
        kv_set($ip_key, array_values($ip_data));
    }
}

/**
 * 渲染邮件模板
 *
 * @param string $template_key 模板键名（如 user_create_code）
 * @param array  $vars        模板变量（如 array('code'=>'123456', 'sitename'=>'论坛')）
 * @return array 包含 subject 和 body 的数组，模板不存在时返回默认值
 */
function xn_email_template($template_key, $vars = array()) {
    $confile = APP_PATH . 'conf/email_templates.conf.php';
    $templates = array();
    if (is_file($confile)) {
        $templates = include $confile;
    }

    if (!isset($templates[$template_key])) {
        // 返回默认简单模板
        $subject = isset($vars['code']) ? lang('send_code_template', array('rand' => $vars['code'], 'sitename' => isset($vars['sitename']) ? $vars['sitename'] : '')) : '';
        $body = $subject;
        return array('subject' => $subject, 'body' => $body);
    }

    $tpl = $templates[$template_key];
    $subject = $tpl['subject'];
    $body = $tpl['body'];

    // 替换变量占位符
    foreach ($vars as $key => $val) {
        $subject = str_replace('{' . $key . '}', $val, $subject);
        $body = str_replace('{' . $key . '}', $val, $body);
    }

    return array('subject' => $subject, 'body' => $body);
}

?>
