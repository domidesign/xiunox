<?php

!defined('DEBUG') AND exit('Access Denied.');

/**
 * 通知类型注册中心
 *
 * 集中管理通知 type 到 tab/icon/label/message 模板的映射。
 * 核心 19 种 type 通过 register_core_types() 注册，插件可通过
 * hook 文件 notify_types_register.php 注册自定义 type。
 *
 * 使用方式：
 *   NotifyTypeRegistry::init();                            // 初始化（幂等）
 *   $tabs = NotifyTypeRegistry::get_all_tabs();            // 获取 tab 菜单
 *   $types = NotifyTypeRegistry::get_types_by_tab('system'); // 获取 tab 下 type 列表
 *   $icon = NotifyTypeRegistry::get_icon('like');         // 获取 type 的 icon
 *   $label = NotifyTypeRegistry::get_label('like');       // 获取 type 的操作描述（如"赞了你的帖子"）
 *   $action_text = NotifyTypeRegistry::get_action_text('like', $notify, $prefetched); // 动态获取描述（区分帖子/评论）
 */
class NotifyTypeRegistry {

    // type 配置注册表：type => array(tab/icon/label/message_callback)
    private static array $registry = array();

    // tab 定义（顺序即菜单顺序）。tab 的 name 是 tab 菜单名（短词，如"点赞"）；
    // 与 type 的 label（操作描述，如"赞了你的帖子"）独立。
    // name 在 get_all_tabs() 时通过 lang_or() 国际化，避免硬编码中文。
    private static array $tabs = array(
        0              => array('name_key' => 'notify_tab_all',           'class' => 'info',      'icon' => ''),
        'like'         => array('name_key' => 'notify_tab_like',          'class' => 'danger',    'icon' => 'heart'),
        'reply'        => array('name_key' => 'notify_tab_reply',         'class' => 'primary',   'icon' => 'message'),
        'favorite'     => array('name_key' => 'notify_tab_favorite',      'class' => 'warning',   'icon' => 'star'),
        'mention'      => array('name_key' => 'notify_tab_mention',       'class' => 'info',      'icon' => 'at'),
        'follow'       => array('name_key' => 'notify_tab_follow',        'class' => 'success',   'icon' => 'user-plus'),
        'thread'       => array('name_key' => 'notify_tab_thread',        'class' => 'primary',   'icon' => 'file-text'),
        'announcement' => array('name_key' => 'notify_tab_announcement',  'class' => 'info',      'icon' => 'speakerphone'),
        'system'       => array('name_key' => 'notify_tab_system',        'class' => 'danger',    'icon' => 'file-text'),
        'other'        => array('name_key' => 'notify_tab_other',         'class' => 'secondary', 'icon' => 'bell'),
    );

    // 默认 tab（兜底）
    private const DEFAULT_TAB = 'other';

    // 默认 icon（兜底）
    private const DEFAULT_ICON = 'bell';

    // 默认 label（兜底，操作描述）
    private const DEFAULT_LABEL = '通知';

    // 初始化标志
    private static bool $initialized = false;

    /**
     * 注册一个通知 type
     *
     * @param string $type   通知类型 key（如 like/reply/mention）
     * @param array  $config 配置：
     *   - tab: string             归属 tab key（如 like/reply/thread/system/other）
     *   - icon: string            Tabler Icons 图标名（如 heart-filled）
     *   - label: string           操作描述（如"赞了你的帖子"，用于通知卡片显示）
     *   - message_callback: callable 可选，function($notify, $prefetched) 返回 array('summary'=>..., 'message'=>...)
     */
    public static function register(string $type, array $config): void {
        if($type === '') return;
        self::$registry[$type] = array(
            'tab'              => isset($config['tab']) ? $config['tab'] : self::DEFAULT_TAB,
            'icon'             => isset($config['icon']) ? $config['icon'] : self::DEFAULT_ICON,
            'label'            => isset($config['label']) ? $config['label'] : self::DEFAULT_LABEL,
            'message_callback' => isset($config['message_callback']) && is_callable($config['message_callback']) ? $config['message_callback'] : null,
        );
    }

    /**
     * 获取 type 所属 tab
     * 未知 type 返回 'other'
     */
    public static function get_tab(string $type): string {
        if(isset(self::$registry[$type])) {
            return self::$registry[$type]['tab'];
        }
        return self::DEFAULT_TAB;
    }

    /**
     * 获取 type 的 icon
     * 未知 type 返回 'bell'
     */
    public static function get_icon(string $type): string {
        if(isset(self::$registry[$type])) {
            return self::$registry[$type]['icon'];
        }
        return self::DEFAULT_ICON;
    }

    /**
     * 获取 type 的操作描述（用于通知卡片显示）
     * 例如 like 返回 "赞了你的帖子"，comment 返回 "评论了你的帖子"
     * 未知 type 返回 "通知"
     */
    public static function get_label(string $type): string {
        if(isset(self::$registry[$type])) {
            return self::$registry[$type]['label'];
        }
        return self::DEFAULT_LABEL;
    }

    /**
     * 动态获取操作描述（根据 notify 数据细化场景）
     *
     * 用于通知卡片第二行显示，例如：
     * - like + pid==thread.firstpid → "赞了你的帖子"
     * - like + pid!=thread.firstpid → "赞了你的评论"
     * - mention + pid==thread.firstpid → "在帖子中提及了你"
     * - mention + parent_pid>0 → "在回复中提及了你"
     * - mention + 其他 → "在评论中提及了你"
     * - 其他 type 直接返回 label
     *
     * @param string $type        通知 type
     * @param array  $notify      通知记录
     * @param array  $prefetched  预加载数据（threads/posts）
     * @return string 操作描述
     */
    public static function get_action_text(string $type, array $notify, array $prefetched = array()): string {
        // 点赞：区分帖子 vs 评论/回复
        if($type === 'like') {
            $_is_post = self::is_post_target($notify, $prefetched);
            return $_is_post
                ? self::lang_or('notify_action_like_post', '赞了你的帖子')
                : self::lang_or('notify_action_like_comment', '赞了你的评论');
        }
        // 提及：区分帖子/评论/回复
        if($type === 'mention') {
            $_is_post = self::is_post_target($notify, $prefetched);
            if($_is_post) {
                return self::lang_or('notify_action_mention_thread', '在帖子中提及了你');
            }
            if(!empty($notify['parent_pid'])) {
                return self::lang_or('notify_action_mention_reply', '在回复中提及了你');
            }
            return self::lang_or('notify_action_mention_comment', '在评论中提及了你');
        }
        // 其他 type 直接返回 label
        return self::get_label($type);
    }

    /**
     * 判断 notify 的目标 pid 是否为帖子（thread 的 first post）
     * 用于 like/mention 等场景区分帖子 vs 评论/回复
     *
     * @param array $notify     通知记录
     * @param array $prefetched 预加载数据
     * @return bool true=帖子，false=评论/回复
     */
    private static function is_post_target(array $notify, array $prefetched): bool {
        if(empty($notify['pid']) || empty($notify['tid'])) return true;
        $_thread = null;
        if(isset($prefetched['threads']) && isset($prefetched['threads'][$notify['tid']])) {
            $_thread = $prefetched['threads'][$notify['tid']];
        } elseif(function_exists('thread_read_cache')) {
            $_thread = thread_read_cache($notify['tid']);
        }
        if(empty($_thread)) return true;
        // firstpid 为帖子第一条 post id；pid 等于 firstpid 即为帖子，否则是评论/回复
        $first_pid = isset($_thread['firstpid']) ? intval($_thread['firstpid']) : 0;
        if($first_pid === 0) return true;
        return intval($notify['pid']) === $first_pid;
    }

    /**
     * 获取 type 的 message 模板回调
     * 未知 type 或无回调返回 null（notify_format 中走默认分支）
     */
    public static function get_message_callback(string $type): ?callable {
        if(isset(self::$registry[$type]) && !empty(self::$registry[$type]['message_callback'])) {
            return self::$registry[$type]['message_callback'];
        }
        return null;
    }

    /**
     * 获取所有 tab 列表（用于构建 $notice_menu）
     * 返回 key => array(name/class/icon)
     * name 字段通过 lang_or() 国际化（找不到 key 时回退到中文兜底）
     */
    public static function get_all_tabs(): array {
        $result = array();
        foreach(self::$tabs as $k => $tab) {
            $name_key = isset($tab['name_key']) ? $tab['name_key'] : '';
            $result[$k] = array(
                'name'  => $name_key ? self::lang_or($name_key, '') : '',
                'class' => isset($tab['class']) ? $tab['class'] : 'info',
                'icon'  => isset($tab['icon']) ? $tab['icon'] : '',
            );
            // 兜底：lang_or 找不到 key 时返回原 key，此时按 tab key 给中文兜底
            if(empty($result[$k]['name'])) {
                $result[$k]['name'] = self::fallback_tab_name($k);
            }
        }
        return $result;
    }

    /**
     * tab 中文名兜底（仅当语言包未提供翻译时使用）
     */
    private static function fallback_tab_name($key): string {
        $fallback = array(
            0              => '全部',
            'like'         => '点赞',
            'reply'        => '评论/回复',
            'favorite'     => '收藏',
            'mention'      => '@',
            'follow'       => '关注',
            'thread'       => '帖子',
            'announcement' => '公告',
            'system'       => '系统',
            'other'        => '其他',
        );
        return isset($fallback[$key]) ? $fallback[$key] : (string)$key;
    }

    /**
     * 获取某 tab 下的所有 type（用于查询条件）
     * 返回 type 字符串数组
     */
    public static function get_types_by_tab(string $tab): array {
        $types = array();
        foreach(self::$registry as $type => $config) {
            if($config['tab'] === $tab) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * 获取默认 tab（兜底）
     */
    public static function get_default_tab(): string {
        return self::DEFAULT_TAB;
    }

    /**
     * 检查是否已初始化
     */
    public static function is_initialized(): bool {
        return self::$initialized;
    }

    /**
     * 初始化：注册核心 type + 触发插件 hook
     * 幂等，重复调用安全
     */
    public static function init(): void {
        if(self::$initialized) return;

        // 1. 注册核心 19 种 type
        self::register_core_types();

        // 2. 触发 hook 让插件注册自定义 type
        // 扫描 plugin/*/hook/notify_types_register.php 并 include
        $hook_dir = defined('APP_PATH') ? APP_PATH . 'plugin/' : './plugin/';
        if(is_dir($hook_dir)) {
            $plugins = glob($hook_dir . '*/hook/notify_types_register.php');
            if(!empty($plugins)) {
                foreach($plugins as $hook_file) {
                    include $hook_file;
                }
            }
        }

        self::$initialized = true;
    }

    /**
     * 注册全部 19 种核心 type
     * 按 tab 分组：like/reply/favorite/mention/follow/thread/announcement/system/other
     *
     * label 字段为"操作描述"（用于通知卡片显示），例如：
     *   - like.label = "赞了你的帖子"（默认场景；mention/like 场景细分由 get_action_text() 动态返回）
     *   - comment.label = "评论了你的帖子"
     *   - reply.label = "回复了你的评论"
     * tab 名（短词如"点赞"）存储在 $tabs 数组中，与 label 独立。
     */
    private static function register_core_types(): void {

        // ===== like tab =====
        self::register('like', array(
            'tab'   => 'like',
            'icon'  => 'heart-filled',
            'label' => self::lang_or('notify_action_like', '赞了你的帖子'),
            // message_callback 仅作为兜底（模板优先用 action_text + 字段拼接）
            'message_callback' => function($notify, $prefetched = array()) {
                list(, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                $_action = self::get_action_text('like', $notify, $prefetched);
                $message = $notify['from_username'].' '.$_action;
                if($subject_link) $message .= ' '.$subject_link;
                return array('summary' => $_action, 'message' => $message);
            },
        ));

        // ===== reply tab（comment + reply）=====
        // comment：评论了你的帖子「标题」（引用评论内容）
        self::register('comment', array(
            'tab'   => 'reply',
            'icon'  => 'message',
            'label' => self::lang_or('notify_action_comment', '评论了你的帖子'),
            'message_callback' => function($notify, $prefetched = array()) {
                list(, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                $message = $notify['from_username'].' '.self::lang_or('notify_action_comment', '评论了你的帖子');
                if($subject_link) $message .= ' '.$subject_link;
                return array('summary' => self::lang_or('notify_action_comment', '评论了你的帖子'), 'message' => $message);
            },
        ));

        // reply：回复了你的评论「被回复内容」来自帖子「标题」（引用回复内容）
        self::register('reply', array(
            'tab'   => 'reply',
            'icon'  => 'message',
            'label' => self::lang_or('notify_action_reply', '回复了你的评论'),
            'message_callback' => function($notify, $prefetched = array()) {
                list(, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                // 计算被回复的评论内容（来自 parent_pid）
                $_quoted_short = '';
                if(!empty($notify['parent_pid'])) {
                    if(isset($prefetched['posts']) && isset($prefetched['posts'][$notify['parent_pid']])) {
                        $_quoted_post = $prefetched['posts'][$notify['parent_pid']];
                    } elseif(function_exists('post_read_cache')) {
                        $_quoted_post = post_read_cache($notify['parent_pid']);
                    } else {
                        $_quoted_post = array();
                    }
                    if(!empty($_quoted_post)) {
                        $_quoted_text = isset($_quoted_post['message']) ? strip_tags($_quoted_post['message']) : '';
                        $_quoted_short = mb_strlen($_quoted_text) > 20 ? mb_substr($_quoted_text, 0, 20).'...' : $_quoted_text;
                    }
                }
                $message = $notify['from_username'].' '.self::lang_or('notify_action_reply', '回复了你的评论');
                if($_quoted_short) $message .= ' 「'.htmlspecialchars($_quoted_short).'」';
                if($subject_link) {
                    $message .= ' '.self::lang_or('notify_from_thread', '来自帖子').' '.$subject_link;
                }
                return array('summary' => self::lang_or('notify_action_reply', '回复了你的评论'), 'message' => $message);
            },
        ));

        // ===== favorite tab =====
        self::register('favorite', array(
            'tab'   => 'favorite',
            'icon'  => 'star-filled',
            'label' => self::lang_or('notify_action_favorite', '收藏了你的帖子'),
            'message_callback' => function($notify, $prefetched = array()) {
                list(, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                $message = $notify['from_username'].' '.self::lang_or('notify_action_favorite', '收藏了你的帖子');
                if($subject_link) $message .= ' '.$subject_link;
                return array('summary' => self::lang_or('notify_action_favorite', '收藏了你的帖子'), 'message' => $message);
            },
        ));

        // ===== mention tab =====
        // label 默认"提及了你"，实际显示用 get_action_text() 区分帖子/评论/回复
        self::register('mention', array(
            'tab'   => 'mention',
            'icon'  => 'at',
            'label' => self::lang_or('notify_action_mention', '提及了你'),
            'message_callback' => function($notify, $prefetched = array()) {
                list(, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                $_action = self::get_action_text('mention', $notify, $prefetched);
                $message = $notify['from_username'].' '.$_action;
                if($subject_link) $message .= ' '.$subject_link;
                return array('summary' => $_action, 'message' => $message);
            },
        ));

        // ===== follow tab =====
        self::register('follow', array(
            'tab'   => 'follow',
            'icon'  => 'user-plus',
            'label' => self::lang_or('notify_action_follow', '关注了你'),
            'message_callback' => function($notify, $prefetched = array()) {
                return array(
                    'summary' => self::lang_or('notify_action_follow', '关注了你'),
                    'message' => $notify['from_username'].' '.self::lang_or('notify_action_follow', '关注了你'),
                );
            },
        ));

        // ===== thread tab（thread + thread_forum + forum_post）=====
        // thread：发布了新帖「标题」来自你关注的用户
        self::register('thread', array(
            'tab'   => 'thread',
            'icon'  => 'file-text',
            'label' => self::lang_or('notify_action_thread', '发布了新帖'),
            'message_callback' => function($notify, $prefetched = array()) {
                list(, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                $message = $notify['from_username'].' '.self::lang_or('notify_action_thread', '发布了新帖');
                if($subject_link) $message .= ' '.$subject_link;
                $message .= ' '.self::lang_or('notify_from_followed_user', '来自你关注的用户');
                return array('summary' => self::lang_or('notify_action_thread', '发布了新帖'), 'message' => $message);
            },
        ));

        // thread_forum：发布了新帖「标题」来自你关注的用户和版块 版块名
        self::register('thread_forum', array(
            'tab'   => 'thread',
            'icon'  => 'file-text',
            'label' => self::lang_or('notify_action_thread', '发布了新帖'),
            'message_callback' => function($notify, $prefetched = array()) {
                global $forumlist;
                list($_thread, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                $_forum_name = self::get_forum_name($_thread, $forumlist);
                $message = $notify['from_username'].' '.self::lang_or('notify_action_thread', '发布了新帖');
                if($subject_link) $message .= ' '.$subject_link;
                $message .= ' '.self::lang_or('notify_from_followed_user_and_forum', '来自你关注的用户和版块');
                if($_forum_name) $message .= ' '.htmlspecialchars($_forum_name);
                return array('summary' => self::lang_or('notify_action_thread', '发布了新帖'), 'message' => $message);
            },
        ));

        // forum_post：发布了新帖「标题」来自你关注的版块 版块名
        self::register('forum_post', array(
            'tab'   => 'thread',
            'icon'  => 'news',
            'label' => self::lang_or('notify_action_thread', '发布了新帖'),
            'message_callback' => function($notify, $prefetched = array()) {
                global $forumlist;
                list($_thread, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                $_forum_name = self::get_forum_name($_thread, $forumlist);
                $message = $notify['from_username'].' '.self::lang_or('notify_action_thread', '发布了新帖');
                if($subject_link) $message .= ' '.$subject_link;
                $message .= ' '.self::lang_or('notify_from_followed_forum', '来自你关注的版块');
                if($_forum_name) $message .= ' '.htmlspecialchars($_forum_name);
                return array('summary' => self::lang_or('notify_action_thread', '发布了新帖'), 'message' => $message);
            },
        ));

        // ===== announcement tab =====
        self::register('announcement', array(
            'tab'   => 'announcement',
            'icon'  => 'speakerphone',
            'label' => '公告',
            // 无 message_callback：notify_format 中走默认分支（优先 message 字段，其次 content）
        ));

        // ===== system tab（7 种 type）=====
        self::register('system', array(
            'tab'   => 'system',
            'icon'  => 'file-text',
            'label' => '系统通知',
            // 无 message_callback：走默认分支
        ));

        self::register('audit_pending', array(
            'tab'   => 'system',
            'icon'  => 'shield-check',
            'label' => self::lang_or('notify_type_label_audit_pending', '审核中'),
            'message_callback' => function($notify, $prefetched = array()) {
                return array(
                    'summary' => '审核中',
                    'message' => $notify['content'] ? $notify['content'] : '您的内容正在审核中',
                );
            },
        ));

        self::register('audit_approve', array(
            'tab'   => 'system',
            'icon'  => 'shield-check',
            'label' => self::lang_or('notify_type_label_audit_approve', '审核通过'),
            'message_callback' => function($notify, $prefetched = array()) {
                return array(
                    'summary' => '审核通过',
                    'message' => $notify['content'] ? $notify['content'] : '您的内容已通过审核',
                );
            },
        ));

        self::register('audit_reject', array(
            'tab'   => 'system',
            'icon'  => 'shield-x',
            'label' => self::lang_or('notify_type_label_audit_reject', '审核驳回'),
            'message_callback' => function($notify, $prefetched = array()) {
                return array(
                    'summary' => '审核驳回',
                    'message' => $notify['content'] ? $notify['content'] : '您的内容未通过审核',
                );
            },
        ));

        self::register('digest', array(
            'tab'   => 'system',
            'icon'  => 'star-filled',
            'label' => self::lang_or('notify_type_label_digest', '帖子加精'),
            'message_callback' => function($notify, $prefetched = array()) {
                list(, , $subject_link) = self::compute_subject_context($notify, $prefetched);
                $_action = self::lang_or('notify_action_digest', '将你的帖子设为精华');
                $message = $notify['from_username'].' '.$_action;
                if($subject_link) $message .= ' '.$subject_link;
                return array('summary' => $_action, 'message' => $message);
            },
        ));

        self::register('report_auto_audit', array(
            'tab'   => 'system',
            'icon'  => 'flag',
            'label' => '举报审核',
            'message_callback' => function($notify, $prefetched = array()) {
                return array(
                    'summary' => '举报审核',
                    'message' => $notify['content'] ? $notify['content'] : '举报内容已自动审核处理',
                );
            },
        ));

        self::register('report_result', array(
            'tab'   => 'system',
            'icon'  => 'flag',
            'label' => '举报处理结果',
            'message_callback' => function($notify, $prefetched = array()) {
                return array(
                    'summary' => '举报处理结果',
                    'message' => $notify['content'] ? $notify['content'] : '您的举报已处理',
                );
            },
        ));

        self::register('report_penalty', array(
            'tab'   => 'system',
            'icon'  => 'flag',
            'label' => '违规处理',
            'message_callback' => function($notify, $prefetched = array()) {
                return array(
                    'summary' => '违规处理',
                    'message' => $notify['content'] ? $notify['content'] : '您的内容因违反社区规范已被处理',
                );
            },
        ));

        // ===== other tab（pm + other，兜底）=====
        self::register('pm', array(
            'tab'   => 'other',
            'icon'  => 'mail',
            'label' => '私信',
            // 无 message_callback：走默认分支
        ));

        self::register('other', array(
            'tab'   => 'other',
            'icon'  => 'bell',
            'label' => '通知',
            // 无 message_callback：走默认分支
        ));
    }

    /**
     * 计算通知关联的帖子上下文（标题、链接）
     * 供 message_callback 闭包复用，避免重复查询
     *
     * @param array $notify     通知行
     * @param array $prefetched 预加载数据（threads/posts）
     * @return array array($_thread, $thread_subject, $subject_link)
     */
    private static function compute_subject_context(array $notify, array $prefetched): array {
        $_thread = null;
        if(!empty($notify['tid']) && $notify['tid'] > 0) {
            if(isset($prefetched['threads']) && isset($prefetched['threads'][$notify['tid']])) {
                $_thread = $prefetched['threads'][$notify['tid']];
            } elseif(function_exists('thread_read_cache')) {
                $_thread = thread_read_cache($notify['tid']);
            }
        }

        $thread_subject = '';
        $thread_url = '';
        if(!empty($notify['tid']) && $notify['tid'] > 0) {
            if(!empty($_thread)) {
                $thread_subject = isset($_thread['subject']) ? $_thread['subject'] : '';
            }
            // ponytail: 用 frontend_thread_url 而非 url('thread-')，避免 admin 上下文下
            // message 中拼接的帖子链接被浏览器解析为 /admin/?thread-xxx.htm（多了 admin 前缀）
            $thread_url = function_exists('frontend_thread_url') ? frontend_thread_url($notify['tid']) : (function_exists('url') ? url('thread-'.$notify['tid']) : '');
        }

        // 帖子标题链接（截断超长标题）
        $subject_short = $thread_subject ? (mb_strlen($thread_subject) > 30 ? mb_substr($thread_subject, 0, 30).'...' : $thread_subject) : '';
        $subject_link = ($subject_short && $thread_url) ? '<a href="'.$thread_url.'">'.htmlspecialchars($subject_short).'</a>' : '';

        return array($_thread, $thread_subject, $subject_link);
    }

    /**
     * 从 thread 数据获取版块名称
     *
     * @param array|null $_thread   帖子数据
     * @param array|null $forumlist 全局版块列表
     * @return string
     */
    private static function get_forum_name(?array $_thread = null, ?array $forumlist = null): string {
        if(empty($_thread)) return '';
        $_fid = isset($_thread['fid']) ? intval($_thread['fid']) : 0;
        if($_fid === 0) return '';
        $_forum = array();
        if(!empty($forumlist) && isset($forumlist[$_fid])) {
            $_forum = $forumlist[$_fid];
        } elseif(function_exists('forum_read')) {
            $_forum = forum_read($_fid);
        }
        return !empty($_forum) && isset($_forum['name']) ? $_forum['name'] : '';
    }

    /**
     * 安全调用 lang()，不可用时返回硬编码中文兜底
     *
     * @param string $key      lang key
     * @param string $fallback 硬编码中文兜底
     * @return string
     */
    private static function lang_or(string $key, string $fallback): string {
        if(!function_exists('lang')) return $fallback;
        $result = lang($key);
        // lang() 找不到 key 时可能返回 key 本身，此时用兜底
        return ($result === $key) ? $fallback : $result;
    }
}
