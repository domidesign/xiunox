<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook search_start.php

// 搜索限制
include_once APP_PATH . 'lib/security/SecurityConfigService.php';

// 搜索建议先读取，用于跳过限制检查
$suggest = param('suggest', 0);
// 编辑器引用帖子搜索（返回 JSON）
$ref_suggest = param('ref_suggest', 0);

// 搜索需要登录检查（建议模式跳过）
$search_require_login = SecurityConfigService::get('security_search_require_login', 1);
if (!$suggest && !$ref_suggest && $search_require_login && (empty($uid) || $uid == 0)) {
    $is_htmx = !empty($_SERVER['HTTP_HX_REQUEST']);
    if($is_htmx) {
        $msg = '请先登录后再搜索';
        echo '<div id="searchResults"><div class="x-card card mt-3"><div class="card-body text-center py-5 text-body-secondary"><i class="ti ti-alert-circle fs-1 mb-3 d-block opacity-25"></i><p class="fs-5 fw-semibold">'.$msg.'</p></div></div></div>';
        echo '<script type="text/javascript">if(typeof XN.toast==="function")XN.toast("'.$msg.'","danger");</script>';
        exit;
    }
    message(-1, '请先登录后再搜索');
}

// 先解析 keyword 来源，确定 page 在 URL 路径中的位置
// 两种 URL 形式：
//   1) /search-keyword-2.htm （keyword 在位置 1，page 在位置 2）
//   2) /search-2.htm?keyword=xxx （keyword 在 query string，page 在位置 1）
$keyword_from_qs = param('keyword');
if ($keyword_from_qs !== '' && $keyword_from_qs !== null) {
    // keyword 来自 query string，page 在路径位置 1
    $page = param(1, 1);
} else {
    // keyword 来自 URL 路径位置 1，page 在位置 2
    $page = param(2, 1);
}

// 搜索频率限制检查（建议模式和翻页模式跳过，翻页只是浏览已有结果）
if (!$suggest && !$ref_suggest && $page <= 1) {
    $search_interval = SecurityConfigService::get('security_search_interval', 10);
    if ($search_interval > 0) {
        $search_key = 'security_search_time_' . ($uid > 0 ? $uid : $longip);
        $last_search = kv_get($search_key);
        if (!empty($last_search) && ($time - intval($last_search)) < $search_interval) {
            $remaining = $search_interval - ($time - intval($last_search));
            $is_htmx = !empty($_SERVER['HTTP_HX_REQUEST']);
            if($is_htmx) {
                $msg = '搜索间隔太短，请' . $remaining . '秒后再试';
                echo '<div id="searchResults"><div class="x-card card mt-3"><div class="card-body text-center py-5 text-body-secondary"><i class="ti ti-alert-circle fs-1 mb-3 d-block opacity-25"></i><p class="fs-5 fw-semibold">'.$msg.'</p></div></div></div>';
                echo '<script type="text/javascript">if(typeof XN.toast==="function")XN.toast("'.$msg.'","danger");</script>';
                exit;
            }
            message(-1, '搜索间隔太短，请' . $remaining . '秒后再试');
        }
        kv_set($search_key, $time);
    }
}

// 获取 keyword（优先 query string，回退到 URL 路径位置 1）
$keyword = $keyword_from_qs;
empty($keyword) AND $keyword = param(1);
$keyword = trim($keyword);
$keyword_decode = xn_urldecode($keyword);
$keyword_safe = str_replace(array('\'', '\\', '"', '%', '<', '>', '`', '*', '&', '#'), '', $keyword_decode);
$keyword_safe = preg_replace('#\s+#', ' ', $keyword_safe);
$keyword_safe = trim($keyword_safe);

$pagesize = $conf['pagesize'];
$threadlist = array();
$pagination = '';
$total = 0;
$keyword_too_short = false;

$search_type = param('type', 'thread');

// hook search_keyword_after.php

$userlist = array();
$user_total = 0;
$user_pagination = '';

/**
 * 检测并自动创建 FULLTEXT 索引
 * @param string $table 表名（不含前缀）
 * @param string $column 列名
 * @param string $index_name 索引名
 * @return bool 索引是否可用
 */
function search_ensure_fulltext($table, $column, $index_name) {
    global $db;
    $full_table = $db->tablepre . $table;

    // 缓存 SHOW INDEX 结果，避免每次搜索都执行该查询（3600 秒 = 1 小时）
    // 缓存 key 包含 table 和 index_name，避免同表多索引冲突
    $cache_key = 'search_fulltext_index_' . $table . '_' . $index_name;
    $cached = function_exists('cache_get') ? cache_get($cache_key) : FALSE;
    // cache_get 未命中返回 FALSE；命中则为我们存入的 true
    if ($cached === true) {
        return true;
    }

    // 查询索引是否存在
    $indexes = db_sql_find("SHOW INDEX FROM {$full_table} WHERE Key_name = '{$index_name}'");
    if (!empty($indexes)) {
        // 缓存命中：索引已存在
        if (function_exists('cache_set')) {
            cache_set($cache_key, true, 3600);
        }
        return true;
    }

    // 索引不存在，尝试创建
    $r = db_exec("ALTER TABLE {$full_table} ADD FULLTEXT INDEX {$index_name} ({$column}) WITH PARSER ngram");
    if ($r !== FALSE) {
        if (function_exists('cache_set')) {
            cache_set($cache_key, true, 3600);
        }
        return true;
    }

    // ngram parser 不可用（MySQL < 5.6 或未安装），尝试不带 parser
    $r = db_exec("ALTER TABLE {$full_table} ADD FULLTEXT INDEX {$index_name} ({$column})");
    if ($r !== FALSE) {
        if (function_exists('cache_set')) {
            cache_set($cache_key, true, 3600);
        }
    }
    return $r !== FALSE;
}

if($keyword_safe) {
    // 关键词长度校验（FULLTEXT + ngram 最少2字符）
    if(mb_strlen($keyword_safe) < 2) {
        $keyword_too_short = true;
    } elseif($search_type == 'thread') {
        $keyword_escaped = addslashes($keyword_safe);

        // BOOLEAN MODE 关键词：转义特殊字符并用双引号包裹，实现精确短语匹配
        // ngram parser 下，双引号要求 ngram 片段按顺序连续出现，避免英文被过度分词导致误匹配
        $keyword_boolean_clean = preg_replace('/[+\-~<>()"\']/', ' ', $keyword_safe);
        $keyword_boolean_clean = trim(preg_replace('/\s+/', ' ', $keyword_boolean_clean));
        $keyword_boolean = '"' . addslashes($keyword_boolean_clean) . '"';

        // 搜索建议模式：只返回标题匹配
        if($suggest) {
            // 确保 FULLTEXT 索引存在
            $has_fulltext = search_ensure_fulltext('thread', 'subject', 'ft_subject');

            if($has_fulltext) {
                $suggestlist = db_sql_find("SELECT tid, subject FROM bbs_thread WHERE MATCH(subject) AGAINST('{$keyword_boolean}' IN BOOLEAN MODE) ORDER BY tid DESC LIMIT 5");
            }
            // FULLTEXT 失败时回退到 LIKE
            if(empty($suggestlist)) {
                $suggestlist = db_find('thread', array('subject' => array('LIKE' => $keyword_safe)), array('tid' => -1), 1, 5, 'tid', array('tid', 'subject'));
            }
            if($suggestlist) {
                foreach($suggestlist as &$s) {
                    $s['subject'] = str_ireplace($keyword_safe, '<mark>' . $keyword_safe . '</mark>', $s['subject']);
                    $s['url'] = thread_url($s['tid']);
                }
                echo '<div class="list-group list-group-flush">';
                foreach($suggestlist as $s) {
                    echo '<a href="' . $s['url'] . '" class="list-group-item list-group-item-action py-2 px-3"><i class="ti ti-message-2 me-2 text-body-secondary"></i>' . $s['subject'] . '</a>';
                }
                echo '</div>';
            }
            exit;
        }

        // 编辑器引用帖子搜索：返回 JSON 格式
        if($ref_suggest) {
            $has_fulltext = search_ensure_fulltext('thread', 'subject', 'ft_subject');
            $reflist = array();
            if($has_fulltext) {
                $reflist = db_sql_find("SELECT tid, subject FROM bbs_thread WHERE MATCH(subject) AGAINST('{$keyword_boolean}' IN BOOLEAN MODE) ORDER BY tid DESC LIMIT 10");
            }
            if(empty($reflist)) {
                $reflist = db_find('thread', array('subject' => array('LIKE' => $keyword_safe)), array('tid' => -1), 1, 10, 'tid', array('tid', 'subject'));
            }
            $result = array();
            if($reflist) {
                foreach($reflist as $r) {
                    $result[] = array(
                        'tid' => intval($r['tid']),
                        'subject' => strip_tags($r['subject']),
                        'url' => thread_url($r['tid']),
                    );
                }
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('code' => 0, 'data' => $result), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 主搜索：FULLTEXT 优先，LIKE 回退
        // 预构建权限/审核过滤条件：查询阶段即过滤，避免 total 与展示数量不一致
        $fid_filter_sql = '';
        $audit_filter_sql = '';
        $fid_filter_sql_t = '';   // 带 t. 前缀的版本，用于 JOIN 查询
        $audit_filter_sql_t = '';
        if($gid != 1 && $gid != 2) {
            // 非管理员：加入版块权限过滤
            if(!isset($forumlist)) forum_list_cache();
            $accessible_forums = forum_list_access_filter($forumlist, $gid);
            $accessible_fids = array_keys($accessible_forums);
            if(!empty($accessible_fids)) {
                $fid_filter_sql = ' AND fid IN (' . implode(',', $accessible_fids) . ')';
                $fid_filter_sql_t = ' AND t.fid IN (' . implode(',', $accessible_fids) . ')';
            } else {
                $fid_filter_sql = ' AND 1=0';
                $fid_filter_sql_t = ' AND 1=0';
            }
            // 非管理员/游客：过滤待审帖子（只看已审核或自己发布的）
            if($uid > 0) {
                $audit_filter_sql = ' AND (audit_status = 1 OR uid = ' . intval($uid) . ')';
                $audit_filter_sql_t = ' AND (t.audit_status = 1 OR t.uid = ' . intval($uid) . ')';
            } else {
                $audit_filter_sql = ' AND audit_status = 1';
                $audit_filter_sql_t = ' AND t.audit_status = 1';
            }
        }

        $has_fulltext_subject = search_ensure_fulltext('thread', 'subject', 'ft_subject');
        $has_fulltext_message = search_ensure_fulltext('post', 'message', 'ft_message');
        $use_fulltext = $has_fulltext_subject && $has_fulltext_message;

        if($use_fulltext) {
            // FULLTEXT 搜索：BOOLEAN MODE + 双引号实现精确短语匹配
            // 搜索标题（加入权限/审核过滤，LIMIT 限制避免无分页查询返回过多数据）
            $thread_ids_from_subject = db_sql_find("SELECT tid, MATCH(subject) AGAINST('{$keyword_boolean}' IN BOOLEAN MODE) AS relevance FROM bbs_thread WHERE MATCH(subject) AGAINST('{$keyword_boolean}' IN BOOLEAN MODE)" . $fid_filter_sql . $audit_filter_sql . " LIMIT 100");

            // 搜索内容（只搜主帖 isfirst=1，JOIN thread 表过滤权限/审核，字段需加 t. 前缀避免歧义）
            if($fid_filter_sql_t || $audit_filter_sql_t) {
                $thread_ids_from_content = db_sql_find("SELECT p.tid, MAX(MATCH(p.message) AGAINST('{$keyword_boolean}' IN BOOLEAN MODE)) AS relevance FROM bbs_post p JOIN bbs_thread t ON p.tid = t.tid WHERE MATCH(p.message) AGAINST('{$keyword_boolean}' IN BOOLEAN MODE) AND p.isfirst=1" . $fid_filter_sql_t . $audit_filter_sql_t . " GROUP BY p.tid LIMIT 100");
            } else {
                $thread_ids_from_content = db_sql_find("SELECT tid, MAX(MATCH(message) AGAINST('{$keyword_boolean}' IN BOOLEAN MODE)) AS relevance FROM bbs_post WHERE MATCH(message) AGAINST('{$keyword_boolean}' IN BOOLEAN MODE) AND isfirst=1 GROUP BY tid LIMIT 100");
            }

            // 合并结果，取最高相关度
            $merged = array();
            if($thread_ids_from_subject) {
                foreach($thread_ids_from_subject as $row) {
                    $merged[$row['tid']] = floatval($row['relevance']);
                }
            }
            if($thread_ids_from_content) {
                foreach($thread_ids_from_content as $row) {
                    $tid = $row['tid'];
                    $rel = floatval($row['relevance']);
                    if(!isset($merged[$tid]) || $merged[$tid] < $rel) {
                        $merged[$tid] = $rel;
                    }
                }
            }

            // FULLTEXT 返回空结果时，回退到 LIKE
            if(empty($merged)) {
                $use_fulltext = false;
            }
        }

        if(!$use_fulltext) {
            // LIKE 回退模式：合并为 2 次大表查询（原 5 次）
            // 原查询：1) subject LIKE  2) 自己待审(subject)  3) post LIKE  4) thread过滤(post)  5) 自己待审(post)
            // 合并后：1) subject LIKE（含自己待审，OR 条件）  2) post LIKE JOIN thread（含自己待审，OR 条件）
            $merged = array();
            $kw_escaped = addslashes($keyword_safe);

            // 构建权限/审核 SQL 片段
            // 管理员（gid=1,2）：subject 无限制；post 保持原逻辑 audit_status=1
            // 游客（uid=0）：audit_status=1 AND fid IN(可访问版块)
            // 已登录非管理员：(audit_status=1 AND fid IN(...)) OR (audit_status=0 AND uid=X AND fid IN(...))
            $subject_perm_sql = '';
            $post_perm_sql = ''; // 带 t. 前缀，用于 JOIN 查询
            if($gid != 1 && $gid != 2) {
                if(!empty($accessible_fids)) {
                    $fid_in = implode(',', $accessible_fids);
                    if($uid > 0) {
                        // 已登录：已审核帖子（可访问版块）+ 自己的待审帖（可访问版块）
                        $subject_perm_sql = " AND ((audit_status=1 AND fid IN ({$fid_in})) OR (audit_status=0 AND uid=" . intval($uid) . " AND fid IN ({$fid_in})))";
                        $post_perm_sql = " AND ((t.audit_status=1 AND t.fid IN ({$fid_in})) OR (t.audit_status=0 AND t.uid=" . intval($uid) . " AND t.fid IN ({$fid_in})))";
                    } else {
                        // 游客：只看已审核帖子（可访问版块）
                        $subject_perm_sql = " AND audit_status=1 AND fid IN ({$fid_in})";
                        $post_perm_sql = " AND t.audit_status=1 AND t.fid IN ({$fid_in})";
                    }
                } else {
                    // 没有可访问版块：无可见帖子
                    $subject_perm_sql = " AND 1=0";
                    $post_perm_sql = " AND 1=0";
                }
            } else {
                // 管理员：post 查询保持原逻辑（audit_status=1），subject 无限制
                $post_perm_sql = " AND t.audit_status=1";
            }

            // 合并查询 1：subject LIKE（含自己待审帖子，OR 条件合并）
            $subject_sql = "SELECT tid FROM bbs_thread WHERE subject LIKE '%{$kw_escaped}%'{$subject_perm_sql} ORDER BY tid DESC LIMIT 1000";
            $subject_threads = db_sql_find($subject_sql);
            if($subject_threads) {
                foreach($subject_threads as $row) {
                    $merged[$row['tid']] = 1;
                }
            }

            // 合并查询 2：post LIKE JOIN thread（含自己待审帖子，OR 条件合并）
            $post_sql = "SELECT DISTINCT p.tid FROM bbs_post p JOIN bbs_thread t ON p.tid=t.tid WHERE p.message LIKE '%{$kw_escaped}%' AND p.isfirst=1{$post_perm_sql} ORDER BY p.tid DESC LIMIT 1000";
            $post_threads = db_sql_find($post_sql);
            if($post_threads) {
                foreach($post_threads as $row) {
                    if(!isset($merged[$row['tid']])) {
                        $merged[$row['tid']] = 1;
                    }
                }
            }
        }

        // 验证 tid 真实存在于 thread 表（避免 post 表残留或数据不一致导致空结果）
        if(!empty($merged)) {
            $valid_tids = db_find('thread', array('tid' => array_keys($merged)), array('tid' => -1), 1, count($merged), 'tid', array('tid'));
            if($valid_tids) {
                $merged = array_intersect_key($merged, $valid_tids);
            } else {
                $merged = array();
            }
        }

        $total = count($merged);

        if($total > 0) {
            // 按相关度排序
            arsort($merged);

            // 分页
            $pagination = pagination(route_url('search_page', array(), array('keyword' => $keyword_safe)), $total, $page, $pagesize);

            // 取当前页的 tid 列表
            $merged_slice = array_slice($merged, ($page - 1) * $pagesize, $pagesize, true);
            $tidarr = array_keys($merged_slice);

            // DEBUG: 记录 tidarr
            xn_log("search_debug: tidarr=" . json_encode($tidarr) . " page={$page} pagesize={$pagesize}", 'debug_error');

            // 查询帖子详情，用 tid 作 key 确保结果不重复
            $threadlist = db_find('thread', array('tid' => $tidarr), array('tid' => -1), 1, $pagesize, 'tid');

            // DEBUG: 记录 threadlist 结果
            xn_log("search_debug: threadlist_count=" . (is_array($threadlist) ? count($threadlist) : 'not_array') . " threadlist_keys=" . (is_array($threadlist) ? json_encode(array_keys($threadlist)) : 'n/a'), 'debug_error');

            if($threadlist) {
                // 按相关度重新排序
                $sorted = array();
                foreach($tidarr as $tid) {
                    if(isset($threadlist[$tid])) {
                        $sorted[] = $threadlist[$tid];
                    }
                }
                $threadlist = $sorted;

                // 批量查询主帖内容，消除 N+1 查询
                $_search_tids = array_column($threadlist, 'tid');
                $_first_posts = empty($_search_tids) ? array() : db_find('post', array('tid'=>$_search_tids, 'isfirst'=>1), array(), 1, count($_search_tids), 'tid');

                foreach($threadlist as &$thread) {
                    thread_format($thread);

                    // 标题高亮
                    $thread['subject'] = str_ireplace($keyword_safe, '<mark>' . $keyword_safe . '</mark>', $thread['subject']);

                    // 获取内容摘要（从批量查询结果取）
                    $post = isset($_first_posts[$thread['tid']]) ? $_first_posts[$thread['tid']] : array();
                    if($post) {
                        $message = strip_tags($post['message_fmt'] ? $post['message_fmt'] : $post['message']);
                        $message = preg_replace('/\s+/', ' ', $message);
                        $message = trim($message);

                        // 查找关键词位置，截取摘要
                        $pos = mb_stripos($message, $keyword_safe);
                        if($pos !== false) {
                            $start = max(0, $pos - 80);
                            $length = mb_strlen($keyword_safe) + 160;
                            $summary = mb_substr($message, $start, $length);
                            if($start > 0) $summary = '...' . $summary;
                            if($start + $length < mb_strlen($message)) $summary .= '...';
                        } else {
                            $summary = mb_substr($message, 0, 160);
                            if(mb_strlen($message) > 160) $summary .= '...';
                        }
                        $thread['summary'] = str_ireplace($keyword_safe, '<mark>' . $keyword_safe . '</mark>', $summary);
                    } else {
                        $thread['summary'] = '';
                    }

                    // 标记是否标题匹配
                    $thread['subject_match'] = (stripos(strip_tags($thread['subject']), $keyword_safe) !== false);
                }
                // 必须 unset 引用变量，否则模板中 foreach($threadlist as $thread) 会覆盖最后一个元素
                unset($thread);
            }
        } else {
            $pagination = '';
        }
    }

    // 用户搜索（同时搜索用户名和昵称）
    if($search_type == 'user' || $search_type == 'thread') {
        // 检查 nickname 字段是否存在
        $has_nickname = db_check_column_exists('user', 'nickname');
        
        // 构建 SQL 查询（子查询去重，防止 username 和 nickname 同时匹配时重复）
        $keyword_escaped_user = addslashes($keyword_safe);
        if($has_nickname) {
            $user_sql = "SELECT u.* FROM {$db->tablepre}user u INNER JOIN (SELECT DISTINCT uid FROM {$db->tablepre}user WHERE username LIKE '%{$keyword_escaped_user}%' OR nickname LIKE '%{$keyword_escaped_user}%') t ON u.uid = t.uid ORDER BY u.uid DESC LIMIT 20";
            $user_count_sql = "SELECT COUNT(DISTINCT uid) as num FROM {$db->tablepre}user WHERE username LIKE '%{$keyword_escaped_user}%' OR nickname LIKE '%{$keyword_escaped_user}%'";
        } else {
            $user_sql = "SELECT * FROM {$db->tablepre}user WHERE username LIKE '%{$keyword_escaped_user}%' ORDER BY uid DESC LIMIT 20";
            $user_count_sql = "SELECT COUNT(*) as num FROM {$db->tablepre}user WHERE username LIKE '%{$keyword_escaped_user}%'";
        }
        
        $userlist_result = db_sql_find($user_sql);
        if($userlist_result) {
            // 按 uid 去重，防止同一用户出现多次
            $uid_map = array();
            foreach($userlist_result as $u) {
                if(!isset($uid_map[$u['uid']])) {
                    $uid_map[$u['uid']] = $u;
                }
            }
            $userlist_result = array_values($uid_map);

            // 批量查询关注状态，消除 N+1 查询（原 foreach 内逐条调用 user_follow_read）
            $result_uids = array();
            foreach($userlist_result as $u) {
                $result_uids[] = intval($u['uid']);
            }
            $follow_map = array();
            if(!empty($uid) && !empty($result_uids)) {
                $follow_map = user_follow_read_batch($uid, $result_uids);
            }

            foreach($userlist_result as &$u) {
                user_format($u);
                if(!empty($uid)) {
                    $u['is_followed'] = !empty($follow_map[$u['uid']]);
                } else {
                    $u['is_followed'] = false;
                }
            }
            unset($u);
            $userlist = $userlist_result;
        }
        $user_count_result = db_sql_find_one($user_count_sql);
        $user_total = !empty($user_count_result) ? intval($user_count_result['num']) : 0;
    }
}

// hook search_end.php

$header['title'] = $keyword_safe ? lang('search_results') . ': ' . $keyword_safe : lang('search');
$header['keywords'] = $keyword_safe;
$header['description'] = lang('search_results') . ': ' . $keyword_safe;

$_SESSION['fid'] = 0;

include _include(APP_PATH . 'view/htm/search.htm');

?>
