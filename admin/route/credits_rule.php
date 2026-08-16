<?php

!defined('DEBUG') AND exit('Access Denied');

$action = param(1, 'forum');

include APP_PATH . 'service/CreditsRuleService.php';

// hook admin_credits_rule_start.php

// ponytail: global 已迁移到 setting.php 的 creditsrules action，保留重定向兼容旧链接
if($action == 'global') {
	header('Location: ' . admin_setting_url('creditsrules'));
	exit;
}

if($action == 'forum') {
    // 版块规则覆盖（通过 htmx 局部加载）
    $fid = param(2, 0);

    if($method == 'POST') {
        // CSRF 校验
        CsrfService::check();

        // hook admin_credits_rule_forum_post_start.php

        $fid = param('fid', 0);
        $events = param('events', array());
        $rules = array();
        foreach($events as $event) {
            $override = param('override_' . $event, 0);
            if($override) {
                $rules[] = array(
                    'event' => $event,
                    'credits_change' => param('credits_change_' . $event, 0),
                    'golds_change' => param('golds_change_' . $event, 0),
                    'rmbs_change' => param('rmbs_change_' . $event, 0),
                    // ponytail: 版块规则模板只有 override 开关（勾选即启用此条版块规则），
                    // 无 enabled checkbox，硬编码为 1。误用 param('enabled_xxx',0) 会因模板缺字段恒存 0，
                    // 导致 getRule 把版块规则当作禁用处理（不回退全局也不发放积分）。
                    'enabled' => 1,
                    'daily_limit' => param('daily_limit_' . $event, 0),
                );
            } else {
                // 删除版块覆盖，回退到全局规则
                CreditsRuleService::deleteForumRule($fid, $event);
            }
        }

        if(!empty($rules)) {
            $result = CreditsRuleService::saveForumRules($fid, $rules);
        } else {
            $result = array('ok' => true, 'message' => lang('admin_credits_rule_forum_updated'));
        }

        admin_log_create('credits_rule_update', 'credits_rule', strval($fid), lang('admin_log_credits_rule_forum', array('fid'=>$fid)));

        // hook admin_credits_rule_forum_post_end.php

        message($result['ok'] ? 0 : -1, $result['message']);
    }

    // hook admin_credits_rule_forum_get_start.php

    // 获取版块列表
    $forumlist = forum_list_cache();
    $all_forums = array();
    foreach($forumlist as $f) {
        if(empty($f['type']) || $f['type'] != 1) {
            $all_forums[$f['fid']] = $f;
        }
    }

    // 获取全局规则
    $globalRules = CreditsRuleService::getAllGlobalRules();
    $globalRuleMap = array();
    if($globalRules) {
        foreach($globalRules as $r) {
            $globalRuleMap[$r['event']] = $r;
        }
    }

    // 获取版块规则
    $forumRuleMap = array();
    if($fid > 0) {
        $forumRules = CreditsRuleService::getForumRules($fid);
        if($forumRules) {
            foreach($forumRules as $r) {
                $forumRuleMap[$r['event']] = $r;
            }
        }
    }

    // hook admin_credits_rule_forum_get_end.php

    // htmx 请求：仅返回局部模板（无 header/footer）
    include _include(ADMIN_PATH.'view/htm/credits_rule_forum.htm');
    exit;
}

// hook admin_credits_rule_end.php

?>
