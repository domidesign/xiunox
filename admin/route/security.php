<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'protection');
$method = G('method');

// ===== 防护设置 =====
if($action == '' || $action == 'protection') {
    include_once APP_PATH . 'lib/security/SecurityConfigService.php';

    if($method == 'GET') {
        $security_config = SecurityConfigService::get_config();
        $header['title'] = '防护设置';
        $header['mobile_title'] = '防护设置';
        include _include(ADMIN_PATH.'view/htm/security_protection.htm');
    } else {
        CsrfService::check();
        $data = array();
        $data['security_post_thread_interval'] = param('security_post_thread_interval', 60);
        $data['security_post_reply_interval'] = param('security_post_reply_interval', 30);
        $data['security_post_min_length'] = param('security_post_min_length', 10);
        $data['security_reply_min_length'] = param('security_reply_min_length', 5);
        $data['security_post_max_length'] = param('security_post_max_length', 50000);
        $data['security_same_thread_reply_interval'] = param('security_same_thread_reply_interval', 0);
        $data['security_new_user_audit_count'] = param('security_new_user_audit_count', 0);
        $data['security_ip_register_interval'] = param('security_ip_register_interval', 24);
        $data['security_password_max_retries'] = param('security_password_max_retries', 5);
        $data['security_lockout_duration'] = param('security_lockout_duration', 15);
        $data['security_password_min_length'] = param('security_password_min_length', 6);
        $data['security_password_complexity'] = param('security_password_complexity', 'none');
        $data['security_allow_edit'] = param('security_allow_edit', 0);
        $data['security_edit_time_limit'] = param('security_edit_time_limit', 60);
        $data['security_allow_delete'] = param('security_allow_delete', 0);
        $data['security_delete_time_limit'] = param('security_delete_time_limit', 0);
        $data['security_soft_delete'] = param('security_soft_delete', 0);
        $data['security_allow_delete_reply'] = param('security_allow_delete_reply', 0);
        $data['security_avatar_upload_limit'] = param('security_avatar_upload_limit', 3);
        $data['security_avatar_max_size'] = param('security_avatar_max_size', 512);
        $data['security_search_interval'] = param('security_search_interval', 10);
        $data['security_search_require_login'] = param('security_search_require_login', 0);
        $data['security_allowed_email_domains'] = param('security_allowed_email_domains', '', FALSE);

        $r = SecurityConfigService::save_config($data);
        if($r) {
            admin_log_create('security_protection', 'security', '', '修改防护设置');
            message(0, lang('modify_successfully'));
        } else {
            message(-1, '保存失败');
        }
    }

// ===== 验证码配置 =====
} elseif($action == 'captcha') {
    include_once APP_PATH . 'lib/security/CaptchaService.php';

    if($method == 'GET') {
        $captcha_config = CaptchaService::get_config();
        $header['title'] = '验证码配置';
        $header['mobile_title'] = '验证码配置';
        include _include(ADMIN_PATH.'view/htm/security_captcha.htm');
    } else {
        CsrfService::check();
        $data = array();
        foreach(CaptchaService::SCENES as $scene) {
            $data[$scene] = array(
                'enabled' => param('captcha_' . $scene . '_enabled', 0),
                'type' => param('captcha_' . $scene . '_type', 'gd_image'),
            );
        }
        $r = CaptchaService::save_config($data);
        if($r === FALSE) {
            global $errno, $errstr;
            message(-1, '保存失败: db error ' . $errno . ' ' . $errstr);
        } else {
            admin_log_create('security_captcha', 'security', '', '修改验证码设置');
            message(0, lang('modify_successfully'));
        }
    }

// ===== 敏感词库 =====
} elseif($action == 'words') {
    include_once APP_PATH . 'lib/security/SensitiveWordFilter.php';

    if($method == 'GET') {
        $sensitive_words = SensitiveWordFilter::get_all_words();
        $header['title'] = '敏感词库';
        $header['mobile_title'] = '敏感词库';
        include _include(ADMIN_PATH.'view/htm/security_words.htm');
    } else {
        CsrfService::check();
        $word_action = param('word_action', param(2, ''));

        if($word_action == 'add') {
            $word = param('word', '', FALSE);
            if(empty($word)) message(-1, '敏感词不能为空');
            $r = SensitiveWordFilter::add_word($word);
            if($r) {
                admin_log_create('security_badword', 'security', '', '添加敏感词：' . $word);
                message(0, '添加成功');
            } else {
                message(-1, '添加失败或已存在');
            }
        } elseif($word_action == 'delete') {
            $word = param('word', '', FALSE);
            if(empty($word)) message(-1, '参数错误');
            $r = SensitiveWordFilter::delete_word($word);
            if($r) {
                admin_log_create('security_badword', 'security', '', '删除敏感词：' . $word);
                message(0, '删除成功');
            } else {
                message(-1, '删除失败');
            }
        } elseif($word_action == 'import') {
            $words_text = param('words_text', '', FALSE);
            if(empty($words_text)) message(-1, '导入内容不能为空');
            $count = SensitiveWordFilter::batch_import($words_text);
            admin_log_create('security_badword', 'security', '', '批量导入敏感词 ' . $count . ' 个');
            message(0, '成功导入 ' . $count . ' 个敏感词');
        } elseif($word_action == 'import_file') {
            if(empty($_FILES['words_file']) || $_FILES['words_file']['error'] != 0) {
                message(-1, '请选择要上传的文件');
            }
            $file = $_FILES['words_file'];
            if($file['type'] !== 'text/plain' && !preg_match('/\.txt$/i', $file['name'])) {
                message(-1, '仅支持.txt文件');
            }
            if($file['size'] > 2 * 1024 * 1024) {
                message(-1, '文件大小不能超过2MB');
            }
            $count = SensitiveWordFilter::import_from_file($file['tmp_name']);
            admin_log_create('security_badword', 'security', '', '从文件导入敏感词 ' . $count . ' 个');
            message(0, '成功导入 ' . $count . ' 个敏感词');
        } elseif($word_action == 'clear') {
            $r = SensitiveWordFilter::clear_all();
            if($r) {
                admin_log_create('security_badword', 'security', '', '清空敏感词库');
                message(0, '已清空');
            } else {
                message(-1, '清空失败');
            }
        } else {
            message(-1, '未知操作');
        }
    }

// ===== 内容审核 =====
} elseif($action == 'audit') {
    include_once APP_PATH . 'lib/security/AuditService.php';

    if($method == 'GET') {
        $pending_threads = AuditService::get_pending_list('thread', 1, 20);
        $pending_posts = AuditService::get_pending_list('post', 1, 20);
        $pending_thread_count = AuditService::get_pending_count('thread');
        $pending_post_count = AuditService::get_pending_count('post');
        $pending_profiles = AuditService::get_pending_profile_list(1, 20);
        $pending_profile_count = AuditService::get_pending_profile_count();
        $audit_logs = AuditService::get_audit_logs(1, 20);
        $header['title'] = '内容审核';
        $header['mobile_title'] = '内容审核';
        include _include(ADMIN_PATH.'view/htm/security_audit.htm');
    } else {
        CsrfService::check();
        $audit_action = param('audit_action', '');
        global $user;
        $operator_uid = intval($user['uid'] ?? 0);

        if($audit_action == 'approve') {
            $target_type = param('target_type', '', FALSE);
            $target_id = param('target_id', 0);
            if(empty($target_type) || empty($target_id)) message(-1, '参数错误');
            $r = AuditService::approve($target_type, $target_id, $operator_uid);
            $r ? message(0, '审核通过') : message(-1, '操作失败');
        } elseif($audit_action == 'reject') {
            $target_type = param('target_type', '', FALSE);
            $target_id = param('target_id', 0);
            $reason = param('reason', '', FALSE);
            if(empty($target_type) || empty($target_id)) message(-1, '参数错误');
            $r = AuditService::reject($target_type, $target_id, $operator_uid, $reason);
            $r ? message(0, '已驳回') : message(-1, '操作失败');
        } elseif($audit_action == 'batch_approve') {
            $target_type = param('target_type', '', FALSE);
            $ids = param('ids', array());
            if(empty($target_type) || empty($ids)) message(-1, '参数错误');
            $count = AuditService::batch_approve($target_type, $ids, $operator_uid);
            $count > 0 ? message(0, '成功通过 ' . $count . ' 项') : message(-1, '操作失败');
        } elseif($audit_action == 'batch_reject') {
            $target_type = param('target_type', '', FALSE);
            $ids = param('ids', array());
            $reason = param('reason', '', FALSE);
            if(empty($target_type) || empty($ids)) message(-1, '参数错误');
            $count = AuditService::batch_reject($target_type, $ids, $operator_uid, $reason);
            $count > 0 ? message(0, '成功驳回 ' . $count . ' 项') : message(-1, '操作失败');
        } elseif($audit_action == 'profile_approve') {
            $audit_id = param('audit_id', 0);
            if(empty($audit_id)) message(-1, '参数错误');
            $r = AuditService::approve_profile($audit_id, $operator_uid);
            $r ? message(0, '审核通过') : message(-1, '操作失败');
        } elseif($audit_action == 'profile_reject') {
            $audit_id = param('audit_id', 0);
            $reason = param('reason', '', FALSE);
            if(empty($audit_id)) message(-1, '参数错误');
            $r = AuditService::reject_profile($audit_id, $operator_uid, $reason);
            $r ? message(0, '已驳回') : message(-1, '操作失败');
        } elseif($audit_action == 'profile_batch_approve') {
            $ids = param('ids', array());
            if(empty($ids)) message(-1, '参数错误');
            $r = AuditService::batch_approve_profiles($ids, $operator_uid);
            $r ? message(0, '批量通过成功') : message(-1, '操作失败');
        } else {
            message(-1, '未知操作');
        }
    }

// ===== 黑白名单 =====
} elseif($action == 'blacklist') {
    include_once APP_PATH . 'lib/security/IpBlacklistService.php';
    include_once APP_PATH . 'lib/security/EmailBlacklistService.php';

    if($method == 'GET') {
        $ip_blacklist = IpBlacklistService::get_blacklist();
        $ip_whitelist = IpBlacklistService::get_whitelist();
        $email_blacklist = EmailBlacklistService::get_all_domains();
        $header['title'] = '黑白名单';
        $header['mobile_title'] = '黑白名单';
        include _include(ADMIN_PATH.'view/htm/security_blacklist.htm');
    } else {
        CsrfService::check();
        $sub_action = param(2, '');

        if($sub_action == 'ip_blacklist_add') {
            $ip = param('ip', '', FALSE);
            $remark = param('remark', '', FALSE);
            if(empty($ip)) message(-1, 'IP不能为空');
            $r = IpBlacklistService::add_to_blacklist($ip, $remark);
            if($r) {
                admin_log_create('security_blacklist', 'security', '', '添加IP黑名单：' . $ip);
                message(0, '添加成功');
            } else {
                message(-1, '添加失败或已存在');
            }
        } elseif($sub_action == 'ip_blacklist_remove') {
            $ip = param('ip', '', FALSE);
            if(empty($ip)) message(-1, 'IP不能为空');
            $r = IpBlacklistService::remove_from_blacklist($ip);
            if($r) {
                admin_log_create('security_blacklist', 'security', '', '移除IP黑名单：' . $ip);
                message(0, '删除成功');
            } else {
                message(-1, '删除失败');
            }
        } elseif($sub_action == 'ip_whitelist_add') {
            $ip = param('ip', '', FALSE);
            $remark = param('remark', '', FALSE);
            if(empty($ip)) message(-1, 'IP不能为空');
            $r = IpBlacklistService::add_to_whitelist($ip, $remark);
            if($r) {
                admin_log_create('security_blacklist', 'security', '', '添加IP白名单：' . $ip);
                message(0, '添加成功');
            } else {
                message(-1, '添加失败或已存在');
            }
        } elseif($sub_action == 'ip_whitelist_remove') {
            $ip = param('ip', '', FALSE);
            if(empty($ip)) message(-1, 'IP不能为空');
            $r = IpBlacklistService::remove_from_whitelist($ip);
            if($r) {
                admin_log_create('security_blacklist', 'security', '', '移除IP白名单：' . $ip);
                message(0, '删除成功');
            } else {
                message(-1, '删除失败');
            }
        } elseif($sub_action == 'email_add') {
            $domain = param('domain', '', FALSE);
            if(empty($domain)) message(-1, '域名不能为空');
            $r = EmailBlacklistService::add_domain($domain);
            if($r) {
                admin_log_create('security_blacklist', 'security', '', '添加邮箱黑名单：' . $domain);
                message(0, '添加成功');
            } else {
                message(-1, '添加失败或已存在');
            }
        } elseif($sub_action == 'email_remove') {
            $domain = param('domain', '', FALSE);
            if(empty($domain)) message(-1, '域名不能为空');
            $r = EmailBlacklistService::remove_domain($domain);
            if($r) {
                admin_log_create('security_blacklist', 'security', '', '移除邮箱黑名单：' . $domain);
                message(0, '删除成功');
            } else {
                message(-1, '删除失败');
            }
        } elseif($sub_action == 'email_import') {
            $words_text = param('words_text', '', FALSE);
            if(empty($words_text)) message(-1, '导入内容不能为空');
            $domains = array_filter(array_map('trim', preg_split('/[\n\r]+/', $words_text)));
            $count = 0;
            foreach($domains as $d) {
                if(EmailBlacklistService::add_domain($d)) $count++;
            }
            admin_log_create('security_blacklist', 'security', '', '批量导入邮箱黑名单 ' . $count . ' 个');
            message(0, '成功导入 ' . $count . ' 个域名');
        } else {
            message(-1, '未知操作');
        }
    }

} else {
    message(-1, '未知操作');
}
