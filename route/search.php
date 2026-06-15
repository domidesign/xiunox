<?php

!defined('DEBUG') AND exit('Access Denied.');

// hook search_start.php

// 搜索限制
include_once APP_PATH . 'lib/security/SecurityConfigService.php';

// 搜索建议先读取，用于跳过限制检查
$suggest = param('suggest', 0);

// 搜索需要登录检查（建议模式跳过）
$search_require_login = SecurityConfigService::get('security_search_require_login', 1);
if (!$suggest && $search_require_login && (empty($uid) || $uid == 0)) {
    message(-1, '请先登录后再搜索');
}

// 搜索频率限制检查（建议模式跳过）
if (!$suggest) {
    $search_interval = SecurityConfigService::get('security_search_interval', 10);
    if ($search_interval > 0) {
        $search_key = 'security_search_time_' . ($uid > 0 ? $uid : $longip);
        $last_search = kv_get($search_key);
        if (!empty($last_search) && ($time - intval($last_search)) < $search_interval) {
            $remaining = $search_interval - ($time - intval($last_search));
            message(-1, '搜索间隔太短，请' . $remaining . '秒后再试');
        }
        kv_set($search_key, $time);
    }
}

$keyword = param('keyword');
empty($keyword) AND $keyword = param(1);
$keyword = trim($keyword);
$keyword_decode = xn_urldecode($keyword);
$keyword_safe = str_replace(array('\'', '\\', '"', '%', '<', '>', '`', '*', '&', '#'), '', $keyword_decode);
$keyword_safe = preg_replace('#\s+#', ' ', $keyword_safe);
$keyword_safe = trim($keyword_safe);

$page = param(2, 1);
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

    // 查询索引是否存在
    $indexes = db_sql_find("SHOW INDEX FROM {$full_table} WHERE Key_name = '{$index_name}'");
    if (!empty($indexes)) {
        return true;
    }

    // 索引不存在，尝试创建
    $r = db_exec("ALTER TABLE {$full_table} ADD FULLTEXT INDEX {$index_name} ({$column}) WITH PARSER ngram");
    if ($r !== FALSE) {
        return true;
    }

    // ngram parser 不可用（MySQL < 5.6 或未安装），尝试不带 parser
    $r = db_exec("ALTER TABLE {$full_table} ADD FULLTEXT INDEX {$index_name} ({$column})");
    return $r !== FALSE;
}

if($keyword_safe) {
    // 关键词长度校验（FULLTEXT + ngram 最少2字符）
    if(mb_strlen($keyword_safe) < 2) {
        $keyword_too_short = true;
    } elseif($search_type == 'thread') {
        $keyword_escaped = addslashes($keyword_safe);

        // 搜索建议模式：只返回标题匹配
        if($suggest) {
            // 确保 FULLTEXT 索引存在
            $has_fulltext = search_ensure_fulltext('thread', 'subject', 'ft_subject');

            if($has_fulltext) {
                $suggestlist = db_sql_find("SELECT tid, subject FROM bbs_thread WHERE MATCH(subject) AGAINST('{$keyword_escaped}' IN NATURAL LANGUAGE MODE) ORDER BY tid DESC LIMIT 5");
            }
            // FULLTEXT 失败时回退到 LIKE
            if(empty($suggestlist)) {
                $suggestlist = db_find('thread', array('subject' => array('LIKE' => $keyword_safe)), array('tid' => -1), 1, 5, 'tid', array('tid', 'subject'));
            }
            if($suggestlist) {
                foreach($suggestlist as &$s) {
                    $s['subject'] = str_ireplace($keyword_safe, '<mark>' . $keyword_safe . '</mark>', $s['subject']);
                    $s['url'] = url("thread-$s[tid]");
                }
                echo '<div class="list-group list-group-flush">';
                foreach($suggestlist as $s) {
                    echo '<a href="' . $s['url'] . '" class="list-group-item list-group-item-action py-2 px-3"><i class="ti ti-message-2 me-2 text-body-secondary"></i>' . $s['subject'] . '</a>';
                }
                echo '</div>';
            }
            exit;
        }

        // 主搜索：FULLTEXT 优先，LIKE 回退
        $has_fulltext_subject = search_ensure_fulltext('thread', 'subject', 'ft_subject');
        $has_fulltext_message = search_ensure_fulltext('post', 'message', 'ft_message');
        $use_fulltext = $has_fulltext_subject && $has_fulltext_message;

        if($use_fulltext) {
            // FULLTEXT 搜索：联合搜索标题和内容
            $thread_ids_from_subject = db_sql_find("SELECT tid, MATCH(subject) AGAINST('{$keyword_escaped}' IN NATURAL LANGUAGE MODE) AS relevance FROM bbs_thread WHERE MATCH(subject) AGAINST('{$keyword_escaped}' IN NATURAL LANGUAGE MODE)");

            $thread_ids_from_content = db_sql_find("SELECT tid, MAX(MATCH(message) AGAINST('{$keyword_escaped}' IN NATURAL LANGUAGE MODE)) AS relevance FROM bbs_post WHERE MATCH(message) AGAINST('{$keyword_escaped}' IN NATURAL LANGUAGE MODE) AND isfirst=0 GROUP BY tid");

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
            // LIKE 回退模式：搜索标题 + 内容
            $merged = array();
            $like_threads = db_find('thread', array('subject' => array('LIKE' => $keyword_safe)), array('tid' => -1), 1, 1000, 'tid', array('tid'));
            if($like_threads) {
                foreach($like_threads as $row) {
                    $merged[$row['tid']] = 1;
                }
            }
            $like_posts = db_find('post', array('message' => array('LIKE' => $keyword_safe), 'isfirst' => 1), array('pid' => -1), 1, 500, 'tid', array('tid'));
            if($like_posts) {
                foreach($like_posts as $row) {
                    if(!isset($merged[$row['tid']])) {
                        $merged[$row['tid']] = 1;
                    }
                }
            }
        }

        $total = count($merged);

        if($total > 0) {
            // 按相关度排序
            arsort($merged);

            // 分页
            $pagination = pagination(url("search-{page}", array('keyword' => $keyword_safe)), $total, $page, $pagesize);

            // 取当前页的 tid 列表
            $merged_slice = array_slice($merged, ($page - 1) * $pagesize, $pagesize, true);
            $tidarr = array_keys($merged_slice);

            // 查询帖子详情
            $threadlist = db_find('thread', array('tid' => $tidarr), array('tid' => -1), 1, $pagesize);

            if($threadlist) {
                // 按相关度重新排序
                $sorted = array();
                foreach($tidarr as $tid) {
                    foreach($threadlist as $thread) {
                        if($thread['tid'] == $tid) {
                            $sorted[] = $thread;
                            break;
                        }
                    }
                }
                $threadlist = $sorted;

                foreach($threadlist as &$thread) {
                    thread_format($thread);

                    // 标题高亮
                    $thread['subject'] = str_ireplace($keyword_safe, '<mark>' . $keyword_safe . '</mark>', $thread['subject']);

                    // 获取内容摘要
                    $post = db_find_one('post', array('tid' => $thread['tid'], 'isfirst' => 1));
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
            }

            thread_list_access_filter($threadlist, $gid);
        } else {
            $pagination = '';
        }
    }

    // 用户搜索（保持 LIKE 方式）
    if($search_type == 'user' || $search_type == 'thread') {
        $userlist_result = db_find('user', array('username' => array('LIKE' => $keyword_safe)), array('uid' => -1), 1, 20);
        if($userlist_result) {
            foreach($userlist_result as &$u) {
                user_format($u);
                if(!empty($uid)) {
                    $follow = user_follow_read($uid, $u['uid']);
                    $u['is_followed'] = !empty($follow);
                } else {
                    $u['is_followed'] = false;
                }
            }
            $userlist = $userlist_result;
        }
        $user_total = db_count('user', array('username' => array('LIKE' => $keyword_safe)));
    }
}

// hook search_end.php

$header['title'] = $keyword_safe ? lang('search_results') . ': ' . $keyword_safe : lang('search');
$header['keywords'] = $keyword_safe;
$header['description'] = lang('search_results') . ': ' . $keyword_safe;

$_SESSION['fid'] = 0;

include _include(APP_PATH . 'view/htm/search.htm');

?>
