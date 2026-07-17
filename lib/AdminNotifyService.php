<?php

!defined('DEBUG') AND exit('Access Denied.');

/**
 * 管理员审核通知服务
 *
 * 统一封装"站内通知 + 邮件"双通道投递给指定管理员列表，用于插件在产生
 * "需管理员处理的新待办"时主动告知管理员，避免漏审。
 *
 * 防抖策略：同一插件同一 audit_type 在 24 小时内只发一次（cache key 标记），
 * 插件在审核完毕（待办清零）时调用 clearDebounce() 清除标记，下次新待办可再次发送。
 *
 * SMTP 配置入口统一走 xn_smtp_get()，读取 conf/smtp.conf.php，不走 setting_get('smtp_config')。
 * 通知类型用已有的系统类型 audit_pending（允许自己通知自己）。
 */
class AdminNotifyService {

    // 防抖 cache key 前缀
    const DEBOUNCE_PREFIX = 'admin_notify_';
    // 防抖 TTL（24 小时）
    const DEBOUNCE_TTL = 86400;

    // ===== 公有 API =====

    /**
     * 发送审核通知给管理员
     *
     * @param string $plugin     插件目录名（如 'xnx_verify'）
     * @param string $audit_type 审核类型（如 'verify_apply'）
     * @param string $subject    邮件主题（也作为通知摘要）
     * @param string $content    通知正文（可含 HTML）
     * @param string $url        点击通知跳转的 URL
     * @param array  $options    可选：
     *                           - from_uid: 发送者 uid（默认 0=系统）
     *                           - admin_uids: 覆盖接收人 uid 数组（默认读插件配置）
     *                           - skip_notify: true 时跳过站内通知
     *                           - skip_mail: true 时跳过邮件
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

        // 1. 读插件配置，检查总开关
        $plugin_cfg = setting_get($plugin);
        $enabled = is_array($plugin_cfg) && isset($plugin_cfg['admin_notify_enabled'])
            ? intval($plugin_cfg['admin_notify_enabled'])
            : 0;
        if (empty($enabled)) {
            // 用户明确关闭，不写防抖标记，下次开启后立即生效
            $result['reason'] = 'disabled';
            return $result;
        }

        // 2. 防抖检查
        $debounce_key = self::DEBOUNCE_PREFIX . $plugin . '_' . $audit_type;
        if (cache_get($debounce_key) !== NULL) {
            $result['reason'] = 'debounced';
            return $result;
        }

        // 3. 获取接收人 admin_uids（options 覆盖 > 插件配置 > fallback gid=1）
        $admin_uids = array();
        if (!empty($options['admin_uids']) && is_array($options['admin_uids'])) {
            foreach ($options['admin_uids'] as $uid) {
                $uid = intval($uid);
                $uid > 0 AND $admin_uids[$uid] = $uid;
            }
        } elseif (is_array($plugin_cfg) && !empty($plugin_cfg['admin_notify_uids']) && is_array($plugin_cfg['admin_notify_uids'])) {
            foreach ($plugin_cfg['admin_notify_uids'] as $uid) {
                $uid = intval($uid);
                $uid > 0 AND $admin_uids[$uid] = $uid;
            }
        } else {
            // fallback：查 gid=1 超管，db_find 返回以 uid 为 key 的行数组
            $rows = db_find('user', array('gid' => 1, 'status' => 1), array(), 1, 100, 'uid');
            if (!empty($rows)) {
                foreach ($rows as $uid => $row) {
                    $uid = intval($uid);
                    $admin_uids[$uid] = $uid;
                }
            }
        }
        if (empty($admin_uids)) {
            $result['reason'] = 'no_recipients';
            return $result;
        }

        $from_uid = isset($options['from_uid']) ? intval($options['from_uid']) : 0;
        $skip_notify = !empty($options['skip_notify']);
        $skip_mail = !empty($options['skip_mail']);

        // 4. SMTP 检测
        $smtp = xn_smtp_get();
        $smtp_ok = ($smtp !== FALSE);
        $result['smtp_ok'] = $smtp_ok;

        $sent_notify = 0;
        $sent_mail = 0;

        // 5. 发送站内通知
        if (!$skip_notify) {
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

        // 6. 发送邮件（仅当 SMTP 已配置）
        if (!$skip_mail && $smtp_ok) {
            $from_name = isset($conf['sitename']) ? $conf['sitename'] : 'BBS';
            $content_html = '<!DOCTYPE html><html><body><div>'
                . $content
                . '</div><p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">点击查看</a></p></body></html>';

            foreach ($admin_uids as $uid) {
                $user = user_read($uid);
                if (empty($user) || empty($user['email'])) {
                    continue;
                }
                $email = $user['email'];
                $mail_ret = xn_send_mail($smtp, $from_name, $email, $subject, $content_html, array('is_html' => TRUE));
                if ($mail_ret === TRUE) {
                    $sent_mail++;
                } else {
                    // 失败不中断，仅记录日志
                    $err = is_string($mail_ret) ? $mail_ret : 'unknown';
                    error_log('[AdminNotify] mail failed: uid=' . $uid . ' email=' . $email . ' err=' . $err);
                }
            }
        }

        // 7. 写防抖标记（只要站内通知发出就算成功）
        cache_set($debounce_key, 1, self::DEBOUNCE_TTL);

        // 8. 返回结果：站内通知发出即视为成功
        $result['ok'] = ($sent_notify > 0 || $skip_notify);
        $result['sent_notify'] = $sent_notify;
        $result['sent_mail'] = $sent_mail;
        if (!$result['ok']) {
            $result['reason'] = 'notify_failed';
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
     * 检测 SMTP 是否已配置（供插件设置页调用显示状态）
     * @return bool
     */
    public static function isSmtpConfigured() {
        return xn_smtp_get() !== FALSE;
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
     * 获取接收管理员 uid 列表（供插件设置页回显勾选状态）
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
        // fallback：gid=1 超管，db_find 返回以 uid 为 key 的行数组
        $rows = db_find('user', array('gid' => 1, 'status' => 1), array(), 1, 100, 'uid');
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
     * 获取接收管理员候选列表（供插件设置页渲染多选 checkbox）
     * 返回 gid IN (1,2) 且 status=1 的用户，避免列出被封禁账号
     * @return array [['uid'=>int,'username'=>string,'display_name'=>string,'gid'=>int], ...]
     */
    public static function getAdminCandidates() {
        $rows = db_find('user', array('gid' => array('IN' => array(1, 2)), 'status' => 1), array('uid' => 1), 1, 50, 'uid');
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
