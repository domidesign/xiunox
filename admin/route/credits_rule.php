<?php

!defined('DEBUG') AND exit('Access Denied');

$action = param(1, 'global');

include APP_PATH . 'service/CreditsRuleService.php';

// hook admin_credits_rule_start.php

if($action == 'global') {
    // GET: 展示全局规则编辑页面（含版块覆盖 tab）
    // POST: 保存全局规则
    if($method == 'POST') {
        // hook admin_credits_rule_global_post_start.php

        $events = param('events', array());
        $rules = array();
        foreach($events as $event) {
            $rules[] = array(
                'event' => $event,
                'credits_change' => param('credits_change_' . $event, 0),
                'golds_change' => param('golds_change_' . $event, 0),
                'rmbs_change' => param('rmbs_change_' . $event, 0),
                'enabled' => param('enabled_' . $event, 0),
            );
        }

        $result = CreditsRuleService::saveGlobalRules($rules);

        admin_log_create('credits_rule_update', 'credits_rule', '', '修改全局积分规则');

        // hook admin_credits_rule_global_post_end.php

        message($result['ok'] ? 0 : -1, $result['message']);
    }

    // hook admin_credits_rule_global_get_start.php

    $rules = CreditsRuleService::getAllGlobalRules();
    $rulemap = array();
    if($rules) {
        foreach($rules as $r) {
            $rulemap[$r['event']] = $r;
        }
    }

    // 获取版块列表（供版块覆盖 tab 使用）
    $forumlist = forum_list_cache();
    $all_forums = array();
    foreach($forumlist as $f) {
        if(empty($f['type']) || $f['type'] != 1) {
            $all_forums[$f['fid']] = $f;
        }
    }

    $header['title'] = lang('admin_credits_rule_global');
    $header['mobile_title'] = lang('admin_credits_rule_global');

    // hook admin_credits_rule_global_get_end.php

    include _include(ADMIN_PATH.'view/htm/credits_rule.htm');

} elseif($action == 'forum') {
    // 版块规则覆盖（通过 htmx 局部加载）
    $fid = param(2, 0);

    if($method == 'POST') {
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
                    'enabled' => param('enabled_' . $event, 0),
                );
            } else {
                // 删除版块覆盖，回退到全局规则
                CreditsRuleService::deleteForumRule($fid, $event);
            }
        }

        if(!empty($rules)) {
            $result = CreditsRuleService::saveForumRules($fid, $rules);
        } else {
            $result = array('ok' => true, 'message' => '已更新版块规则');
        }

        admin_log_create('credits_rule_update', 'credits_rule', strval($fid), '修改版块积分规则 fid=' . $fid);

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
