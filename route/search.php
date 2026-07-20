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

// 获取 keyword（优先 query string，回退到 URL 路径位置 1）
$keyword = $keyword_from_qs;
empty($keyword) AND $keyword = param(1);
$keyword = trim($keyword);
$keyword_decode = xn_urldecode($keyword);
$keyword_safe = str_replace(array('\'', '\\', '"', '%', '<', '>', '`', '*', '&', '#'), '', $keyword_decode);
$keyword_safe = preg_replace('#\s+#', ' ', $keyword_safe);
$keyword_safe = trim($keyword_safe);

// LIKE 查询用的转义值（转义 _ 和 % 通配符，避免搜索词中的符号被当通配符导致匹配范围爆炸）
$kw_like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $keyword_safe) . '%';

// 检测关键词是否纯 ASCII（英文/数字/符号）
// 纯 ASCII 关键词跳过 FULLTEXT+ngram（ngram 对英文分词不友好且会过滤符号），直接用 LIKE 精确子串匹配
$is_ascii_keyword = !preg_match('/[^\x20-\x7E]/', $keyword_safe);

// 搜索最小字符数（供模板使用）
$search_min_length = SecurityConfigService::get('security_search_min_length', 2);

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
    // 搜索频率限制检查（只有真正发起搜索才计时；建议模式/翻页/纯访问搜索页不计）
    // 翻页只是浏览已有结果，不计入；page<=1 表示首次搜索
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

    // 关键词长度校验（最多50字符防 DoS）
    $_kw_len = mb_strlen($keyword_safe);
    if($_kw_len < $search_min_length) {
        $keyword_too_short = true;
    } elseif($_kw_len > 50) {
        message(-1, lang('search_keyword_too_long'));
    } elseif($search_type == 'thread') {

        // BOOLEAN MODE 关键词：转义特殊字符并用双引号包裹，实现精确短语匹配
        // ngram parser 下，双引号要求 ngram 片段按顺序连续出现，避免英文被过度分词导致误匹配
        $keyword_boolean_clean = preg_replace('/[+\-~<>()"\']/', ' ', $keyword_safe);
        $keyword_boolean_clean = trim(preg_replace('/\s+/', ' ', $keyword_boolean_clean));
        $keyword_boolean = '"' . $keyword_boolean_clean . '"';

        // 搜索建议模式：只返回标题匹配
        if($suggest) {
            // 纯 ASCII 关键词跳过 FULLTEXT（ngram 对英文/符号分词不友好），直接 LIKE
            $has_fulltext = !$is_ascii_keyword && search_ensure_fulltext('thread', 'subject', 'ft_subject');

            if($has_fulltext) {
                $suggestlist = db_sql_find_prepared("SELECT tid, subject FROM {$db->tablepre}thread WHERE MATCH(subject) AGAINST(? IN BOOLEAN MODE) ORDER BY tid DESC LIMIT 5", array($keyword_boolean));
            }
            // FULLTEXT 失败或纯 ASCII 时回退到 LIKE
            if(empty($suggestlist)) {
                $suggestlist = db_find('thread', array('subject' => array('LIKE' => $kw_like)), array('tid' => -1), 1, 5, 'tid', array('tid', 'subject'));
            }
            if($suggestlist) {
                foreach($suggestlist as &$s) {
                    // 先 esc_html 转义内容和关键词，再用 <mark> 包裹（避免 esc_html 把 <mark> 标签也转义）
                    $_kw_esc = esc_html($keyword_safe);
                    $s['subject'] = str_ireplace($_kw_esc, '<mark>' . $_kw_esc . '</mark>', esc_html($s['subject']));
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
            $has_fulltext = !$is_ascii_keyword && search_ensure_fulltext('thread', 'subject', 'ft_subject');
            $reflist = array();
            if($has_fulltext) {
                $reflist = db_sql_find_prepared("SELECT tid, subject FROM {$db->tablepre}thread WHERE MATCH(subject) AGAINST(? IN BOOLEAN MODE) ORDER BY tid DESC LIMIT 10", array($keyword_boolean));
            }
            if(empty($reflist)) {
                $reflist = db_find('thread', array('subject' => array('LIKE' => $kw_like)), array('tid' => -1), 1, 10, 'tid', array('tid', 'subject'));
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
        // 纯 ASCII 关键词跳过 FULLTEXT（ngram 对英文/符号分词不友好），直接走 LIKE 精确子串匹配
        $use_fulltext = $has_fulltext_subject && $has_fulltext_message && !$is_ascii_keyword;

        if($use_fulltext) {
            // FULLTEXT 搜索：BOOLEAN MODE + 双引号实现精确短语匹配
            // 搜索标题（加入权限/审核过滤，LIMIT 限制避免无分页查询返回过多数据）
            $thread_ids_from_subject = db_sql_find_prepared("SELECT tid, MATCH(subject) AGAINST(? IN BOOLEAN MODE) AS relevance FROM {$db->tablepre}thread WHERE MATCH(subject) AGAINST(? IN BOOLEAN MODE)" . $fid_filter_sql . $audit_filter_sql . " LIMIT 100", array($keyword_boolean, $keyword_boolean));

            // 搜索内容（只搜主帖 isfirst=1，JOIN thread 表过滤权限/审核，字段需加 t. 前缀避免歧义）
            if($fid_filter_sql_t || $audit_filter_sql_t) {
                $thread_ids_from_content = db_sql_find_prepared("SELECT p.tid, MAX(MATCH(p.message) AGAINST(? IN BOOLEAN MODE)) AS relevance FROM {$db->tablepre}post p JOIN {$db->tablepre}thread t ON p.tid = t.tid WHERE MATCH(p.message) AGAINST(? IN BOOLEAN MODE) AND p.isfirst=1" . $fid_filter_sql_t . $audit_filter_sql_t . " GROUP BY p.tid LIMIT 100", array($keyword_boolean, $keyword_boolean));
            } else {
                $thread_ids_from_content = db_sql_find_prepared("SELECT tid, MAX(MATCH(message) AGAINST(? IN BOOLEAN MODE)) AS relevance FROM {$db->tablepre}post WHERE MATCH(message) AGAINST(? IN BOOLEAN MODE) AND isfirst=1 GROUP BY tid LIMIT 100", array($keyword_boolean, $keyword_boolean));
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
            // $kw_like 已在顶部定义（已转义 _ 和 % 通配符）

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
            $subject_sql = "SELECT tid FROM {$db->tablepre}thread WHERE subject LIKE ?{$subject_perm_sql} ORDER BY tid DESC LIMIT 1000";
            $subject_threads = db_sql_find_prepared($subject_sql, array($kw_like));
            if($subject_threads) {
                foreach($subject_threads as $row) {
                    $merged[$row['tid']] = 1;
                }
            }

            // 合并查询 2：post LIKE JOIN thread（含自己待审帖子，OR 条件合并）
            $post_sql = "SELECT DISTINCT p.tid FROM {$db->tablepre}post p JOIN {$db->tablepre}thread t ON p.tid=t.tid WHERE p.message LIKE ? AND p.isfirst=1{$post_perm_sql} ORDER BY p.tid DESC LIMIT 1000";
            $post_threads = db_sql_find_prepared($post_sql, array($kw_like));
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

            // 查询帖子详情，用 tid 作 key 确保结果不重复
            $threadlist = db_find('thread', array('tid' => $tidarr), array('tid' => -1), 1, $pagesize, 'tid');

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

                    // 标题高亮：thread_format 已对 subject 做 esc_html，关键词也需转义后在已转义的 subject 中匹配
                    $_kw_esc = esc_html($keyword_safe);
                    $thread['subject'] = str_ireplace($_kw_esc, '<mark>' . $_kw_esc . '</mark>', $thread['subject']);

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
                        // 摘要先 esc_html 转义，再用 <mark> 包裹已转义的关键词
                        $thread['summary'] = str_ireplace($_kw_esc, '<mark>' . $_kw_esc . '</mark>', esc_html($summary));
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

        // 构建 SQL 查询（子查询去重，防止 username 和 nickname 同时匹配时重复）；PDO 预处理防注入
        // $kw_like 已在顶部定义（已转义 _ 和 % 通配符）
        if($has_nickname) {
            $user_sql = "SELECT u.* FROM {$db->tablepre}user u INNER JOIN (SELECT DISTINCT uid FROM {$db->tablepre}user WHERE username LIKE ? OR nickname LIKE ?) t ON u.uid = t.uid ORDER BY u.uid DESC LIMIT 20";
            $user_count_sql = "SELECT COUNT(DISTINCT uid) as num FROM {$db->tablepre}user WHERE username LIKE ? OR nickname LIKE ?";
            $user_params = array($kw_like, $kw_like);
        } else {
            $user_sql = "SELECT * FROM {$db->tablepre}user WHERE username LIKE ? ORDER BY uid DESC LIMIT 20";
            $user_count_sql = "SELECT COUNT(*) as num FROM {$db->tablepre}user WHERE username LIKE ?";
            $user_params = array($kw_like);
        }

        $userlist_result = db_sql_find_prepared($user_sql, $user_params);
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
        $user_count_result = db_sql_find_one_prepared($user_count_sql, $user_params);
        $user_total = !empty($user_count_result) ? intval($user_count_result['num']) : 0;
    }
}

// hook search_end.php

$header['title'] = $keyword_safe ? lang('search_results') . ': ' . $keyword_safe : lang('search');
$header['keywords'] = $keyword_safe;
$header['description'] = lang('search_results') . ': ' . $keyword_safe;
// SEO: 搜索结果页禁止索引，避免低质量重复内容被搜索引擎收录
$header['noindex'] = TRUE;
$header['canonical'] = absolute_url(url('search'));

$_SESSION['fid'] = 0;

include _include(APP_PATH . 'view/htm/search.htm');

?>
