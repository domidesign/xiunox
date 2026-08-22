<?php

!defined('DEBUG') AND exit('Access Denied');

/**
 * 管理员审核通知服务
 *
 * 统一封装"站内通知 + 邮件"双通道投递给指定管理员列表，用于插件在产生
 * "需管理员处理的新待办"时主动告知管理员，避免漏审。
 *
 * 防抖策略：同一插件同一 audit_type 在 24 小时内只发一次（cache key 标记），
 * 插件在审核完毕（待办清零）时调用 clearDebounce() 清除标记，下次新待办可再次发送。
 *
 * SMTP 配置读取 conf/smtp.conf.php（内联于 getSmtpConfig()，不依赖 xn_smtp_get()），不走 setting_get('smtp_config')。
 * 通知类型用已有的系统类型 audit_pending（允许自己通知自己）。
 */
class AdminNotifyService {

    // 防抖 cache key 前缀
    const DEBOUNCE_PREFIX = 'admin_notify_';
    // 防抖 TTL（30 分钟； ponytail: 原 24h 过长，用户反馈"根本不通知"，缩短到 30min 平衡及时性与防刷屏）
    const DEBOUNCE_TTL = 1800;

    // ===== 公有 API =====

    /**
     * 发送审核通知给管理员
     *
     * v2.0 改造（2026-07-21）：
     *   - 站内通知：自动发给所有 gid IN (1,2) 且 ban_type=0 的管理员（无需勾选）
     *   - 邮件：发给插件配置 admin_notify_emails（逗号分隔邮箱列表）；
     *     若为空则 fallback 到站内通知接收人的 user.email 字段
     *   - 修复根因：原 status=1 条件 user 表无此字段（user 表用 ban_type），SQL 报 Unknown column
     *
     * @param string $plugin     插件目录名（如 'xnx_verify'）
     * @param string $audit_type 审核类型（如 'verify_apply'）
     * @param string $subject    邮件主题（也作为通知摘要）
     * @param string $content    通知正文（可含 HTML）
     * @param string $url        点击通知跳转的 URL
     * @param array  $options    可选：
     *                           - from_uid: 发送者 uid（默认 0=系统）
     *                           - admin_uids: 覆盖站内通知接收人 uid 数组（保留兼容，默认自动查 gid=1,2）
     *                           - admin_emails: 覆盖邮件接收人邮箱数组（默认读插件配置 admin_notify_emails）
     *                           - skip_notify: true 时跳过站内通知
     *                           - skip_mail: true 时跳过邮件
     *                           - ignore_enabled: true 时跳过插件自身 admin_notify_enabled 总开关检查
     *                             （plugin_notify_fire() 统一门面调用时由统一通知配置把关）
     * @return array ['ok'=>bool, 'reason'=>string, 'sent_notify'=>int, 'sent_mail'=>int]
     */
    public static function audit($plugin, $audit_type, $subject, $content, $url = '', $options = array()) {
        global $conf;

        // 默认返回结构
        $result = array(
            'ok' => false,
            'reason' => '',
            'sent_notify' => 0,
            'sent_mail' => 0,
        );

        // 1. 读插件配置，检查总开关（plugin_notify_fire() 传入 ignore_enabled 时跳过，由统一通知配置把关）
        $ignore_enabled = !empty($options['ignore_enabled']);
        $plugin_cfg = setting_get($plugin);
        $enabled = is_array($plugin_cfg) && isset($plugin_cfg['admin_notify_enabled'])
            ? intval($plugin_cfg['admin_notify_enabled'])
            : 0;
        if (!$ignore_enabled && empty($enabled)) {
            // 用户明确关闭，不写防抖标记，下次开启后立即生效
            $result['reason'] = 'disabled';
            return $result;
        }

        // 2. 防抖检查
        // ponytail: cache_get 在 MySQL 驱动键不存在返回 FALSE、Redis 驱动返回 NULL；
        // 原 `!== NULL` 在 MySQL 驱动下 FALSE!==NULL 恒为 true 导致防抖永远命中（第一次就不发）。
        // 改为同时排除 FALSE 和 NULL，两种驱动都兼容。
        // v1.10.0: 支持 options['skip_debounce']=true 跳过防抖（xnx_appcenter 需每次提交都通知）
        $skip_debounce = !empty($options['skip_debounce']);
        $debounce_key = self::DEBOUNCE_PREFIX . $plugin . '_' . $audit_type;
        if (!$skip_debounce) {
            $debounce_val = cache_get($debounce_key);
            if ($debounce_val !== FALSE && $debounce_val !== NULL) {
                $result['reason'] = 'debounced';
                return $result;
            }
        }

        // 3. 站内通知接收人（options 覆盖 > 自动查 gid=1,2 且 ban_type=0）
        // ponytail: user 表无 status 字段（旧代码 status=1 SQL 报 Unknown column 导致接收人列表恒为空）。
        // user 表用 ban_type 表示封禁状态：0=正常/1=禁言/2=禁止访问/3=锁定。这里查 ban_type=0 的有效管理员。
        $admin_uids = array();
        if (!empty($options['admin_uids']) && is_array($options['admin_uids'])) {
            foreach ($options['admin_uids'] as $uid) {
                $uid = intval($uid);
                $uid > 0 AND $admin_uids[$uid] = $uid;
            }
        } else {
            // 自动查 gid IN (1,2) 且 ban_type=0 的管理员
            $rows = db_find('user', array('gid' => array(1, 2), 'ban_type' => 0), array('uid' => 1), 1, 100, 'uid');
            if (!empty($rows)) {
                foreach ($rows as $uid => $row) {
                    $uid = intval($uid);
                    $admin_uids[$uid] = $uid;
                }
            }
        }

        // 4. 邮件接收人（options 覆盖 > 插件配置 admin_notify_emails > fallback 站内通知接收人的 email）
        $admin_emails = array();
        if (!empty($options['admin_emails']) && is_array($options['admin_emails'])) {
            foreach ($options['admin_emails'] as $email) {
                $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
                if ($email !== false) $admin_emails[$email] = $email;
            }
        } else {
            $admin_emails = self::getAdminEmails($plugin);
        }

        $from_uid = isset($options['from_uid']) ? intval($options['from_uid']) : 0;
        $skip_notify = !empty($options['skip_notify']);
        $skip_mail = !empty($options['skip_mail']);

        // 5. SMTP 检测（内联 getSmtpConfig，不依赖 xn_smtp_get()）
        $smtp = self::getSmtpConfig();
        $smtp_ok = ($smtp !== FALSE);
        $result['smtp_ok'] = $smtp_ok;

        $sent_notify = 0;
        $sent_mail = 0;

        // 6. 发送站内通知（发给所有 admin_uids）
        if (!$skip_notify && !empty($admin_uids)) {
            foreach ($admin_uids as $uid) {
                $extra = array(
                    'message' => $content,
                    'url' => $url,
                );
                $ret = notify_create($uid, $from_uid, 'audit_pending', 0, 0, $subject, $extra);
                if ($ret !== FALSE) {
                    $sent_notify++;
                }
            }
        }

        // 7. 发送邮件
        // ponytail: xn_send_mail() 定义在 xiunophp/xn_send_mail.func.php，audit() 的 7 个调用方
        // （插件路由 verify.php/medal.php/appcenter.php/ad.php 及 ReportService）均不加载该文件，
        // 故此处按需 include。此处不会与 user.php 等路由重复声明：audit() 不从那些路由调用。
        if (!$skip_mail && $smtp_ok) {
            // 邮件接收人：优先用 admin_emails 配置；若为空，fallback 到 admin_uids 对应的 user.email
            $mail_recipients = array();
            if (!empty($admin_emails)) {
                $mail_recipients = $admin_emails;
            } elseif (!empty($admin_uids)) {
                foreach ($admin_uids as $uid) {
                    $user = user_read($uid);
                    if (!empty($user) && !empty($user['email'])) {
                        $mail_recipients[$user['email']] = $user['email'];
                    }
                }
            }
            if (!empty($mail_recipients)) {
                if (!function_exists('xn_send_mail')) {
                    include _include(XIUNOPHP_PATH . 'xn_send_mail.func.php');
                }
                $from_name = isset($conf['sitename']) ? $conf['sitename'] : 'BBS';
                $_mail_url = self::absoluteMailUrl($url);
                $content_html = '<!DOCTYPE html><html><body><div>'
                    . $content
                    . '</div><p><a href="' . htmlspecialchars($_mail_url, ENT_QUOTES) . '">' . lang('admin_notify_click_view') . '</a></p></body></html>';
                foreach ($mail_recipients as $email) {
                    $mail_ret = xn_send_mail($smtp, $from_name, $email, $subject, $content_html, array('is_html' => TRUE));
                    if ($mail_ret === TRUE) {
                        $sent_mail++;
                    } else {
                        $err = is_string($mail_ret) ? $mail_ret : 'unknown';
                        error_log('[AdminNotify] mail failed: email=' . $email . ' err=' . $err);
                    }
                }
            }
        }

        // 8. 写防抖标记（只在站内通知发出或主动跳过时才写，避免发送失败也写防抖导致后续不再尝试）
        // v1.10.0: skip_debounce 时也不写防抖标记
        if (!$skip_debounce && ($sent_notify > 0 || $skip_notify)) {
            cache_set($debounce_key, 1, self::DEBOUNCE_TTL);
        }

        // 9. 返回结果：站内通知发出或邮件发出即视为成功
        $result['ok'] = ($sent_notify > 0 || $sent_mail > 0 || $skip_notify);
        $result['sent_notify'] = $sent_notify;
        $result['sent_mail'] = $sent_mail;
        if (!$result['ok']) {
            $result['reason'] = (empty($admin_uids) && empty($admin_emails)) ? 'no_recipients' : 'notify_failed';
        }
        return $result;
    }

    /**
     * 清除防抖标记（审核完毕待办清零时调用，允许下次新待办再次发送）
     */
    public static function clearDebounce($plugin, $audit_type) {
        cache_delete(self::DEBOUNCE_PREFIX . $plugin . '_' . $audit_type);
    }

    /**
     * 给被审核用户发送审核结果通知（站内通知 + 邮件）
     *
     * 与 audit() 对应：audit() 通知管理员有新待办，notifyUser() 通知用户审核结果。
     * 无防抖逻辑（每个审核结果都应通知对应用户）。
     *
     * @param int    $uid     被审核用户 uid
     * @param string $subject 通知主题（也作为邮件主题）
     * @param string $content 通知正文（可含 HTML）
     * @param string $url     点击通知跳转的 URL
     * @return array ['ok'=>bool, 'sent_notify'=>int, 'sent_mail'=>int]
     */
    public static function notifyUser($uid, $subject, $content, $url = '') {
        $result = array('ok' => false, 'sent_notify' => 0, 'sent_mail' => 0, 'reason' => '');
        $uid = intval($uid);
        if ($uid <= 0) {
            $result['reason'] = 'invalid_uid';
            return $result;
        }

        // 1. 站内通知
        $extra = array('message' => $content, 'url' => $url);
        $ret = notify_create($uid, 0, 'audit_pending', 0, 0, $subject, $extra);
        if ($ret !== FALSE) {
            $result['sent_notify'] = 1;
        }

        // 2. 邮件（仅当 SMTP 已配置且用户有邮箱）
        $smtp = self::getSmtpConfig();
        if ($smtp !== FALSE) {
            $user = user_read($uid);
            if (!empty($user) && !empty($user['email'])) {
                if (!function_exists('xn_send_mail')) {
                    include _include(XIUNOPHP_PATH . 'xn_send_mail.func.php');
                }
                global $conf;
                $from_name = isset($conf['sitename']) ? $conf['sitename'] : 'BBS';
                $_mail_url = self::absoluteMailUrl($url);
                $content_html = '<!DOCTYPE html><html><body><div>'
                    . $content
                    . '</div><p><a href="' . htmlspecialchars($_mail_url, ENT_QUOTES) . '">' . lang('admin_notify_click_view') . '</a></p></body></html>';
                $mail_ret = xn_send_mail($smtp, $from_name, $user['email'], $subject, $content_html, array('is_html' => TRUE));
                if ($mail_ret === TRUE) {
                    $result['sent_mail'] = 1;
                } else {
                    $err = is_string($mail_ret) ? $mail_ret : 'unknown';
                    error_log('[AdminNotify] notifyUser mail failed: uid=' . $uid . ' email=' . $user['email'] . ' err=' . $err);
                }
            }
        }

        $result['ok'] = ($result['sent_notify'] > 0);
        if (!$result['ok']) {
            $result['reason'] = 'notify_failed';
        }
        return $result;
    }

    /**
     * 将相对路径 URL 转为绝对 URL（邮件中链接必须用绝对 URL，邮件客户端无法解析相对路径）
     * ponytail: route_url() 从后台 admin 调用时会生成 './?xxx.htm' 格式（admin 强制 ? 格式 + ./ 前缀），
     *   需先去掉 ./ 前缀再用 absolute_url() 拼接，否则得到 'http://host/path/./?xxx.htm'（虽能用但不干净）
     */
    private static function absoluteMailUrl($url) {
        if ($url === '') return '';
        // 已是绝对 URL 直接返回
        if (strpos($url, 'http') === 0 || strpos($url, '//') === 0) return $url;
        // 去掉 admin 路径下的 ./ 前缀
        if (strpos($url, './') === 0) {
            $url = substr($url, 2);
        }
        // absolute_url() 定义在 model/misc.func.php，核心启动流程已加载
        if (function_exists('absolute_url')) {
            return absolute_url($url);
        }
        // fallback：直接用 http_url_path() 拼接
        if (function_exists('http_url_path')) {
            return rtrim(http_url_path(), '/') . '/' . ltrim($url, '/');
        }
        return $url;
    }

    /**
     * 获取 SMTP 配置（内联 xn_smtp_get() 逻辑，避免依赖 xiunophp/xn_send_mail.func.php）
     *
     * 原因：xn_smtp_get() 定义在 xiunophp/xn_send_mail.func.php，该文件仅由
     * route/user.php、route/my.php、admin/route/setting.php 按需 include _include() 加载。
     * 本类的 isSmtpConfigured() 在插件设置页（admin/route/plugin.php 流程）调用，
     * audit() 在插件路由（verify.php、medal.php 等）调用，这些入口均不加载该文件。
     * 在类顶部兜底 include 会与 user.php 等路由的 include 重复声明 xn_send_mail()，
     * 故内联此逻辑（与 xn_smtp_get() 等价）。
     *
     * @return array|FALSE
     */
    private static function getSmtpConfig() {
        $confile = APP_PATH . 'conf/smtp.conf.php';
        if (!is_file($confile)) return FALSE;
        $smtplist = include $confile;
        if (!is_array($smtplist) || empty($smtplist)) return FALSE;
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
     * 检测 SMTP 是否已配置（供插件设置页调用显示状态）
     * @return bool
     */
    public static function isSmtpConfigured() {
        return self::getSmtpConfig() !== FALSE;
    }

    /**
     * 获取 SMTP 状态文案（供插件设置页调用）
     * @return array ['ok'=>bool, 'label'=>string]
     *   - label 用 lang() 读：已配置时 'admin_notify_smtp_ok'，未配置时 'admin_notify_smtp_missing'
     *   - 如 lang() 返回 key 字面量（未定义），fallback 到中文文案
     */
    public static function getSmtpStatus() {
        $ok = self::isSmtpConfigured();
        $key = 'admin_notify_smtp_' . ($ok ? 'ok' : 'missing');
        $label = lang($key);
        // lang() 未定义时返回 'lang[key]'，fallback 到中文
        if (strpos($label, 'lang[') === 0) {
            $label = $ok ? '已配置' : '未配置';
        }
        return array('ok' => $ok, 'label' => $label);
    }

    /**
     * 获取管理员邮箱列表（供插件设置页回显 + audit() 邮件发送）
     *
     * v2.0 新增（2026-07-21）：替代旧的 admin_notify_uids 勾选机制。
     * 邮箱列表存储在插件配置 admin_notify_emails 字段（逗号/换行分隔的字符串）。
     *
     * @param string $plugin
     * @return array 邮箱数组（已 filter_var 校验，key=value 去重）
     */
    public static function getAdminEmails($plugin) {
        $plugin_cfg = setting_get($plugin);
        $emails = array();
        if (is_array($plugin_cfg) && !empty($plugin_cfg['admin_notify_emails'])) {
            // 支持逗号、换行、空格分隔
            $raw_list = preg_split('/[\s,;]+/', trim($plugin_cfg['admin_notify_emails']));
            if (is_array($raw_list)) {
                foreach ($raw_list as $email) {
                    $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
                    if ($email !== false) {
                        $emails[$email] = $email;
                    }
                }
            }
        }
        return $emails;
    }

    /**
     * 获取接收管理员 uid 列表（供插件设置页回显勾选状态，保留向后兼容）
     *
     * v2.0 起 audit() 不再依赖此方法（自动查 gid=1,2），但保留供旧模板/调用方使用。
     * 已修复 status=1 bug（user 表无 status 字段，改用 ban_type=0）。
     *
     * @param string $plugin
     * @return array uid 数组
     */
    public static function getAdminUids($plugin) {
        $plugin_cfg = setting_get($plugin);
        if (is_array($plugin_cfg) && !empty($plugin_cfg['admin_notify_uids']) && is_array($plugin_cfg['admin_notify_uids'])) {
            $uids = array();
            foreach ($plugin_cfg['admin_notify_uids'] as $uid) {
                $uid = intval($uid);
                $uid > 0 AND $uids[$uid] = $uid;
            }
            if (!empty($uids)) {
                return array_values($uids);
            }
        }
        // fallback：gid IN (1,2) 且 ban_type=0 的有效管理员
        // ponytail: 旧代码 status=1 SQL 报 Unknown column 'status'（user 表用 ban_type 字段）
        $rows = db_find('user', array('gid' => array(1, 2), 'ban_type' => 0), array('uid' => 1), 1, 100, 'uid');
        $uids = array();
        if (!empty($rows)) {
            foreach ($rows as $uid => $row) {
                $uid = intval($uid);
                $uids[$uid] = $uid;
            }
        }
        return array_values($uids);
    }

    /**
     * 获取接收管理员候选列表（供插件设置页渲染多选 checkbox，保留向后兼容）
     *
     * v2.0 起建议改用邮箱输入框（getAdminEmails），此方法保留供未迁移的模板使用。
     * 已修复 status=1 bug（user 表无 status 字段，改用 ban_type=0）。
     *
     * @return array [['uid'=>int,'username'=>string,'display_name'=>string,'gid'=>int], ...]
     */
    public static function getAdminCandidates() {
        // ponytail: db_cond_to_sqladd 不支持 array('IN' => array(1,2)) 语法，
        // 仅支持 array('gid' => array(1,2)) 这种 OR 数组形式（会被识别为 IN）。
        // 旧写法会拼成 `gid`IN? 错误 SQL，查询返回空，导致后台"接收管理员"无选项。
        // 同时修复 status=1 bug：user 表用 ban_type 字段（0=正常/1=禁言/2=禁止访问/3=锁定）
        $rows = db_find('user', array('gid' => array(1, 2), 'ban_type' => 0), array('uid' => 1), 1, 50, 'uid');
        $candidates = array();
        if (!empty($rows)) {
            foreach ($rows as $uid => $row) {
                $candidates[] = array(
                    'uid' => intval($uid),
                    'username' => isset($row['username']) ? $row['username'] : '',
                    'display_name' => isset($row['display_name']) ? $row['display_name'] : (isset($row['username']) ? $row['username'] : ''),
                    'gid' => isset($row['gid']) ? intval($row['gid']) : 0,
                );
            }
        }
        return $candidates;
    }
}
