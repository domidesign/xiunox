<?php

!defined('DEBUG') AND exit('Access Denied.');

$action = param(1, 'post_limit');
$method = G('method');

// 兼容旧URL：security-protection 重定向到 security-post_limit
if($action == 'protection') {
    header('Location: '.admin_security_url('post_limit'));
    exit;
}

// ===== 发帖限制 =====
if($action == '' || $action == 'post_limit') {
    include_once APP_PATH . 'lib/security/SecurityConfigService.php';

    if($method == 'GET') {
        $security_config = SecurityConfigService::get_config();
        $header['title'] = lang('admin_post_limit');
        $header['mobile_title'] = lang('admin_post_limit');
        include _include(ADMIN_PATH.'view/htm/security_post_limit.htm');
    } else {
        CsrfService::check();
        $data = array();
        $data['security_post_thread_interval'] = param('security_post_thread_interval', 60);
        $data['security_post_reply_interval'] = param('security_post_reply_interval', 30);
        $data['security_subject_min_length'] = param('security_subject_min_length', 2);
        $data['security_subject_max_length'] = param('security_subject_max_length', 128);
        $data['security_post_min_length'] = param('security_post_min_length', 10);
        $data['security_reply_min_length'] = param('security_reply_min_length', 5);
        $data['security_post_max_length'] = param('security_post_max_length', 50000);
        $data['security_same_thread_reply_interval'] = param('security_same_thread_reply_interval', 0);
        $data['security_new_user_audit_count'] = param('security_new_user_audit_count', 0);

        $r = SecurityConfigService::save_config($data);
        if($r) {
            admin_log_create('security_protection', 'security', '', '修改发帖限制设置');
            message(0, lang('modify_successfully'));
        } else {
            message(-1, '保存失败');
        }
    }

// ===== 账号安全 =====
} elseif($action == 'account') {
    include_once APP_PATH . 'lib/security/SecurityConfigService.php';

    if($method == 'GET') {
        $security_config = SecurityConfigService::get_config();
        $header['title'] = lang('admin_account_security');
        $header['mobile_title'] = lang('admin_account_security');
        include _include(ADMIN_PATH.'view/htm/security_account.htm');
    } else {
        CsrfService::check();
        $data = array();
        $data['security_ip_register_interval'] = param('security_ip_register_interval', 24);
        $data['security_password_max_retries'] = param('security_password_max_retries', 5);
        $data['security_lockout_duration'] = param('security_lockout_duration', 15);
        $data['security_password_min_length'] = param('security_password_min_length', 6);
        $data['security_password_complexity'] = param('security_password_complexity', 'none');
        $data['security_allowed_email_domains'] = param('security_allowed_email_domains', '', FALSE);
        $data['security_email_code_interval'] = param('security_email_code_interval', 60);
        $data['security_email_code_daily_limit'] = param('security_email_code_daily_limit', 5);
        $data['security_email_code_ip_hourly_limit'] = param('security_email_code_ip_hourly_limit', 10);

        $r = SecurityConfigService::save_config($data);
        if($r) {
            admin_log_create('security_protection', 'security', '', '修改账号安全设置');
            message(0, lang('modify_successfully'));
        } else {
            message(-1, '保存失败');
        }
    }

// ===== 内容权限 =====
} elseif($action == 'content') {
    include_once APP_PATH . 'lib/security/SecurityConfigService.php';

    if($method == 'GET') {
        $security_config = SecurityConfigService::get_config();
        $header['title'] = lang('admin_content_permission');
        $header['mobile_title'] = lang('admin_content_permission');
        include _include(ADMIN_PATH.'view/htm/security_content.htm');
    } else {
        CsrfService::check();
        $data = array();
        $data['security_allow_edit'] = param('security_allow_edit', 0);
        $data['security_edit_time_limit'] = param('security_edit_time_limit', 60);
        $data['security_allow_delete'] = param('security_allow_delete', 0);
        $data['security_delete_time_limit'] = param('security_delete_time_limit', 0);
        $data['security_soft_delete'] = param('security_soft_delete', 0);
        $data['security_allow_delete_reply'] = param('security_allow_delete_reply', 0);

        $r = SecurityConfigService::save_config($data);
        if($r) {
            admin_log_create('security_protection', 'security', '', '修改内容权限设置');
            message(0, lang('modify_successfully'));
        } else {
            message(-1, '保存失败');
        }
    }

// ===== 其他设置 =====
} elseif($action == 'other') {
    include_once APP_PATH . 'lib/security/SecurityConfigService.php';

    if($method == 'GET') {
        $security_config = SecurityConfigService::get_config();
        $header['title'] = lang('admin_other_settings');
        $header['mobile_title'] = lang('admin_other_settings');
        include _include(ADMIN_PATH.'view/htm/security_other.htm');
    } else {
        CsrfService::check();
        $data = array();
        $data['security_avatar_upload_limit'] = param('security_avatar_upload_limit', 3);
        $data['security_avatar_max_size'] = param('security_avatar_max_size', 512);
        $data['security_nickname_change_limit'] = param('security_nickname_change_limit', 1);
        $data['security_signature_change_limit'] = param('security_signature_change_limit', 3);
        $data['security_iframe_whitelist'] = param('security_iframe_whitelist', '', FALSE);
        $data['security_search_interval'] = param('security_search_interval', 10);
        $data['security_search_require_login'] = param('security_search_require_login', 0);
        $data['security_cookie_secure'] = param('security_cookie_secure', 0);
        $data['security_cookie_httponly'] = param('security_cookie_httponly', 1);
        $data['security_cookie_samesite'] = param('security_cookie_samesite', 'Lax');

        $r = SecurityConfigService::save_config($data);
        if($r) {
            admin_log_create('security_protection', 'security', '', '修改其他安全设置');
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
        $data = array(
            'types' => array(),
            'gids' => array(),
        );
        // 各场景开关和类型（一维数组，param() 可正常处理）
        $captcha_on = param('captcha_on', array());
        $captcha_type = param('captcha_type', array());
        // 二维数组（captcha_gids[post][]），param() 会破坏嵌套结构，直接从 $_POST 获取
        $captcha_gids_input = isset($_POST['captcha_gids']) ? $_POST['captcha_gids'] : array();
        foreach(CaptchaService::SCENES as $scene) {
            $is_on = !empty($captcha_on[$scene]);
            $type = isset($captcha_type[$scene]) ? $captcha_type[$scene] : 'gd_image';
            $data['types'][$scene] = in_array($type, ['gd_image', 'gd_math']) ? $type : 'gd_image';
            if (in_array($scene, CaptchaService::PRE_AUTH_SCENES)) {
                // 登录/注册/找回密码：开关决定只有游客(0)是否需要验证码
                $data['gids'][$scene] = $is_on ? [0] : [];
            } else {
                // 发帖/回帖：开关打开，或用户组有勾选，都保存用户组配置
                $scene_gids = isset($captcha_gids_input[$scene]) ? $captcha_gids_input[$scene] : array();
                if ($is_on || !empty($scene_gids)) {
                    $data['gids'][$scene] = array_map('intval', (array)$scene_gids);
                } else {
                    $data['gids'][$scene] = [];
                }
            }
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
