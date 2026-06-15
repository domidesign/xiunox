<?php
/**
 * Xiuno BBS 演示数据导入脚本
 * 
 * 访问此脚本即可导入预置用户和帖子。
 * 导入完成后会自动删除本文件（安全起见）。
 * 
 * 用法：浏览器访问或 curl 此文件即可。
 */

// ---------- 防重复执行 ----------
$lock_file = __DIR__ . '/tmp/import_seed.lock';
if (file_exists($lock_file)) {
    http_response_code(403);
    die('演示数据已导入，请勿重复执行。如需重新导入，请先删除 tmp/import_seed.lock 文件。');
}

// ---------- 加载数据库配置 ----------
$conf_file = __DIR__ . '/conf/conf.php';
if (!file_exists($conf_file)) {
    die('找不到 conf/conf.php，请确认脚本位于论坛根目录。');
}
$conf = include $conf_file;

$db_conf = $conf['db']['pdo_mysql']['master'];
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $db_conf['host'],
    $db_conf['name'],
    $db_conf['charset'] ?: 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $db_conf['user'], $db_conf['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('数据库连接失败: ' . $e->getMessage());
}

$pre = $db_conf['tablepre'] ?: 'bbs_'; // 表前缀

// ---------- 工具函数 ----------
function db_exec($pdo, $sql) {
    $pdo->exec($sql);
}

function db_query($pdo, $sql) {
    return $pdo->query($sql)->fetchAll();
}

function db_fetch($pdo, $sql) {
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetch() : false;
}

function table($pre, $name) {
    return "`{$pre}{$name}`";
}

// ---------- 检查是否已有非管理员用户（简单防重） ----------
$cnt = db_fetch($pdo, "SELECT COUNT(*) as c FROM " . table($pre, 'user') . " WHERE uid > 1");
if ($cnt['c'] > 5) {
    http_response_code(403);
    die('论坛已有较多用户，跳过导入以避免数据冲突。');
}

// ---------- 确保有可用版块 ----------
$forums = db_query($pdo, "SELECT fid, name FROM " . table($pre, 'forum'));
if (empty($forums)) {
    // 创建几个默认版块
    $now = time();
    db_exec($pdo, "INSERT INTO " . table($pre, 'forum') . " SET fid=1, name='综合讨论', brief='综合话题讨论区', `rank`=10, create_date=$now");
    db_exec($pdo, "INSERT INTO " . table($pre, 'forum') . " SET fid=2, name='技术分享', brief='技术经验交流区', `rank`=9, create_date=$now");
    db_exec($pdo, "INSERT INTO " . table($pre, 'forum') . " SET fid=3, name='生活杂谈', brief='轻松聊天区', `rank`=8, create_date=$now");
    $forums = db_query($pdo, "SELECT fid, name FROM " . table($pre, 'forum'));
}
$forum_ids = array_column($forums, 'fid');

// ---------- 预置用户（10人） ----------
$users = [
    ['username' => '张三丰',    'email' => 'zhangsan@example.com',    'gid' => 101],
    ['username' => '李白',      'email' => 'libai@example.com',       'gid' => 101],
    ['username' => '王小明',    'email' => 'wxm@example.com',         'gid' => 102],
    ['username' => '赵云',      'email' => 'zhaoyun@example.com',     'gid' => 101],
    ['username' => '陈小鱼',    'email' => 'chenxy@example.com',      'gid' => 102],
    ['username' => '孙悟空',    'email' => 'wukong@example.com',      'gid' => 103],
    ['username' => '林黛玉',    'email' => 'daiyu@example.com',       'gid' => 101],
    ['username' => '鲁迅',      'email' => 'luxun@example.com',       'gid' => 102],
    ['username' => '花木兰',    'email' => 'mulan@example.com',       'gid' => 101],
    ['username' => '诸葛亮',    'email' => 'zhugeliang@example.com',  'gid' => 103],
];

$now = time();
$user_uids = [];

foreach ($users as $i => $u) {
    $salt = substr(md5(uniqid(mt_rand(), true)), 0, 16);
    $password = '123456'; // 默认密码
    $password_md5 = md5($password . $salt);
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $create_date = $now - mt_rand(86400, 86400 * 30); // 随机 1~30 天前注册
    $create_ip = ip2long('192.168.1.' . mt_rand(2, 254));

    $sql = sprintf(
        "INSERT INTO %s SET gid=%d, email='%s', username='%s', `password`='%s', salt='%s', password_hash='%s', create_ip=%d, create_date=%d, login_ip=%d, login_date=%d, logins=%d, credits=%d, threads=0, posts=0",
        table($pre, 'user'),
        $u['gid'],
        $u['email'],
        $u['username'],
        $password_md5,
        $salt,
        $password_hash,
        $create_ip,
        $create_date,
        $create_ip,
        $create_date + mt_rand(3600, 86400),
        mt_rand(1, 20),
        mt_rand(10, 200)
    );
    db_exec($pdo, $sql);
    $uid = $pdo->lastInsertId();
    $user_uids[] = $uid;
}

echo "✅ 成功创建 " . count($user_uids) . " 个用户（默认密码: 123456）<br>\n";

// ---------- 预置帖子（20 个主题 + 首帖 + 部分回复） ----------
$threads_data = [
    // [标题, 内容, 版块fid索引, 作者uid索引, 几天前发布]
    ['欢迎来到我们的社区！', '大家好！这是我们论坛的第一篇帖子，欢迎大家加入我们的社区。在这里可以自由交流、分享经验，希望大家都能有所收获。请遵守社区规则，文明发言。', 0, 0, 30],
    ['论坛使用指南和常见问题', '新来的朋友请看这里！本帖整理了论坛的基本使用方法和常见问题解答。\n\n如何发帖：点击版块页面右上角的"发帖"按钮即可。\n如何回复：在帖子下方输入框输入内容点击回复。\n如何修改个人资料：点击右上角头像进入个人设置页面。', 0, 0, 29],
    ['PHP 8.3 新特性全面解析', 'PHP 8.3 带来了很多令人兴奋的新特性。本文将为大家详细介绍其中最重要的几个变化。\n\n1. Typed Class Constants - 类常量现在支持类型声明\n2. json_validate() 函数 - 新增JSON验证函数\n3. Randomizer 扩展 - 随机数生成器新增方法\n4. Dynamic class constant fetch - 支持动态获取类常量\n\n这些特性让 PHP 变得更加强大和灵活。', 1, 2, 25],
    ['分享一个高效的 MySQL 查询优化技巧', '最近在做项目的时候遇到了一个慢查询问题，经过分析发现可以通过覆盖索引来解决。\n\n原始查询需要全表扫描，耗时约3秒。通过创建一个复合索引，将需要查询的字段都包含在索引中，查询时间降到了50毫秒以内。\n\n关键是要善用 EXPLAIN 分析执行计划，找到真正的瓶颈点。', 1, 1, 22],
    ['周末去了趟西湖，风景太美了', '上周末趁着天气好去了杭州西湖，阳光明媚，湖光山色美不胜收。断桥残雪虽然没有雪，但春天的垂柳也很迷人。\n\n推荐大家有空也去走走，特别是苏堤和白堤，非常适合散步拍照。附近的龙井茶也值得品尝。', 2, 6, 20],
    ['推荐几本今年读过的好书', '2026年过了一半，读了一些不错的书，推荐给大家：\n\n《人类简史》 - 从宏观视角审视人类发展\n《百年孤独》 - 马尔克斯的经典魔幻现实主义\n《三体》 - 刘慈欣的科幻巨著\n《原则》 - 瑞·达利欧的人生和工作原则\n\n每本都值得细细品味，欢迎分享你的书单。', 2, 7, 18],
    ['JavaScript 异步编程最佳实践', '异步编程是 JavaScript 的核心概念之一。本文总结了几种常见的异步模式和最佳实践。\n\nPromise 链式调用让异步代码更加清晰；async/await 进一步简化了异步操作的写法；Promise.all 可以并行执行多个异步任务。\n\n需要注意的是，错误处理一定不能忽略，try-catch 和 .catch() 都要合理使用。', 1, 4, 15],
    ['Docker 容器化部署实战经验', '最近把公司的几个项目都迁移到了 Docker 容器化部署，分享一下踩过的坑。\n\n镜像体积优化：使用多阶段构建，最终镜像从 800MB 缩小到了 120MB。\n数据持久化：使用 Docker Volume 管理持久化数据。\n网络配置：使用 Docker Compose 定义多容器应用。\n日志管理：使用 json-file 驱动配合日志轮转。', 1, 5, 14],
    ['如何养成每天运动的好习惯', '坚持运动三年了，分享一些心得。最重要的是循序渐进，不要一上来就给自己定太高的目标。\n\n我的建议：\n从每天15分钟开始，慢慢增加时间\n选择自己喜欢的运动方式\n找一个运动伙伴互相监督\n用 APP 记录运动数据，看到进步会更有动力\n\n最重要的是把运动当成生活的一部分，而不是额外的负担。', 2, 8, 12],
    ['新手请教：Linux 服务器安全加固', '刚买了一台云服务器，想请教大家有什么安全加固的建议？\n\n目前我做了以下几项：\n修改了 SSH 默认端口\n禁用了 root 远程登录\n安装了 fail2ban\n\n还有什么需要注意的吗？防火墙规则怎么设置比较好？', 1, 3, 11],
    ['关于远程办公的一些思考', '远程办公两年多了，有了一些感悟。远程办公最大的挑战不是效率，而是工作与生活的边界感。\n\n我的做法是固定工作区域、固定上下班时间，到点就关电脑。另外每天安排一次户外散步，避免整天闷在家里。\n\n沟通方面，异步沟通为主，减少不必要的会议。团队使用文档协作，把重要信息都记录下来。', 2, 9, 10],
    ['Go 语言入门指南：从零开始', 'Go 语言以其简洁和高效著称，特别适合后端和微服务开发。\n\n学习路径建议：\n先掌握基础语法和并发编程\n学习标准库中的 net/http 和 database/sql\n了解 goroutine 和 channel 的使用\n学习常用框架如 Gin 或 Echo\n\n推荐学习资源：Go 官方文档、Go by Example。', 1, 2, 9],
    ['家里的猫主子又拆家了', '养了一只橘猫，简直是个破坏王。昨天又把我的键盘线咬断了，沙发也被抓得不成样子。\n\n有养猫的朋友吗？怎么训练猫不咬东西？买了猫抓板完全不管用，它就喜欢我的家具……\n\n不过看着它无辜的大眼睛，又舍不得骂它。养猫人的日常就是又爱又恨。', 2, 6, 8],
    ['React 19 与 Vue 4 前端框架对比', '最近分别用 React 19 和 Vue 4 做了两个项目，简单对比一下。\n\nReact 19：Server Components 很强大，Suspense 改进明显，但学习曲线仍然较陡。\nVue 4：Composition API 更加成熟，性能提升明显，文档一如既往地优秀。\n\n两者都很优秀，选择哪个主要看团队熟悉度和项目需求。', 1, 4, 7],
    ['美食分享：自制红烧肉', '周末在家做了一锅红烧肉，家人都说比饭店的还好吃。分享一下我的做法。\n\n材料：五花肉500g、冰糖30g、生抽、老抽、料酒、八角、桂皮、姜。\n\n关键步骤：\n五花肉先焯水去腥\n冰糖小火炒出糖色\n加开水没过肉，小火慢炖1.5小时\n最后大火收汁\n\n秘诀是加一小块陈皮，能去腻增香。', 2, 8, 6],
    ['微服务架构设计中的常见问题', '在微服务架构设计中，经常会遇到以下几个挑战：\n\n服务间通信：REST vs gRPC 各有优劣\n数据一致性：分布式事务的处理方案\n服务发现：Consul 或 Nacos 的选型\n链路追踪：OpenTelemetry 的使用\n日志聚合：ELK 或 Loki 的选择\n\n没有银弹，需要根据团队规模和业务特点选择合适的方案。', 1, 9, 5],
    ['分享我的开发工具箱', '作为全栈开发者，分享一些我日常离不开的工具。\n\n编辑器：VS Code + Vim 快捷键\n终端：Warp（Mac）/ Windows Terminal\nAPI 测试：Bruno（Postman 的开源替代）\n数据库管理：DBeaver\nGit 工具：Lazygit\n容器管理：Lazydocker\n笔记：Obsidian\n\n每个工具都经过了长期使用和比较，强烈推荐给大家试试。', 1, 5, 4],
    ['周末徒步日记 - 穿越武功山', '上周末和朋友去了武功山徒步，两天一夜的行程，累但快乐着。\n\nDay 1：从龙山村出发，经过发云界到达金顶，全程约15公里，海拔爬升1200米。晚上在金顶露营，看到了超美的星空。\n\nDay 2：看完日出后下山，经过吊马桩回到景区大门。下山膝盖压力很大，建议带护膝。\n\n总花费约 300 元/人，性价比很高的户外活动。', 2, 3, 3],
    ['人工智能对编程的影响', 'AI 辅助编程工具越来越普及了，ChatGPT、Copilot 等工具确实在改变开发者的工作方式。\n\n我的感受是：AI 擅长处理重复性的编码工作，比如生成 CRUD 代码、写单元测试、解释复杂的代码逻辑。但在架构设计、业务理解等方面，仍然需要人的判断。\n\n未来开发者更需要具备"提出好问题"的能力，而不是单纯地写代码。', 1, 7, 2],
    ['今天是个好日子，升职加薪了', '今天收到了公司的调薪通知，终于升职了！在公司三年，从初级开发到技术负责人，一路走来不容易。\n\n感谢团队中每一位小伙伴的支持，也感谢论坛里大家的分享和讨论，让我学到了很多。\n\n继续努力，争取带领团队做出更好的产品！请大家吃糖。', 2, 1, 1],
];

$thread_count = 0;
$post_count = 0;

foreach ($threads_data as $idx => $t) {
    list($subject, $message, $forum_idx, $user_idx, $days_ago) = $t;

    $fid = $forum_ids[$forum_idx % count($forum_ids)];
    $uid = $user_uids[$user_idx % count($user_uids)];
    $create_date = $now - $days_ago * 86400 + mt_rand(0, 43200);
    $userip = ip2long('10.0.' . mt_rand(1, 254) . '.' . mt_rand(2, 254));

    // 创建首帖 post
    $message_fmt = nl2br(htmlspecialchars($message));

    $sql = sprintf(
        "INSERT INTO %s SET tid=0, uid=%d, isfirst=1, create_date=%d, userip=%d, images=0, files=0, videos=0, doctype=0, quotepid=0, likes=%d, audit_status=1, message='%s', message_fmt='%s'",
        table($pre, 'post'),
        $uid,
        $create_date,
        $userip,
        mt_rand(0, 15),
        addslashes($message),
        addslashes($message_fmt)
    );
    db_exec($pdo, $sql);
    $firstpid = $pdo->lastInsertId();
    $post_count++;

    // 创建主题 thread
    $last_date = $create_date;
    $sql = sprintf(
        "INSERT INTO %s SET fid=%d, top=0, uid=%d, userip=%d, subject='%s', create_date=%d, last_date=%d, views=%d, posts=0, likes=%d, favorites=%d, images=0, files=0, videos=0, mods=0, closed=0, audit_status=1, is_digest=%d, firstpid=%d, lastuid=%d, lastpid=%d",
        table($pre, 'thread'),
        $fid,
        $uid,
        $userip,
        addslashes($subject),
        $create_date,
        $last_date,
        mt_rand(10, 500),
        mt_rand(0, 30),
        mt_rand(0, 10),
        ($idx < 3) ? 1 : 0, // 前3篇加精
        $firstpid,
        $uid,
        $firstpid
    );
    db_exec($pdo, $sql);
    $tid = $pdo->lastInsertId();

    // 更新首帖的 tid
    db_exec($pdo, "UPDATE " . table($pre, 'post') . " SET tid=$tid WHERE pid=$firstpid");

    // 插入 thread_top 记录
    db_exec($pdo, "INSERT INTO " . table($pre, 'thread_top') . " SET fid=$fid, tid=$tid, top=0");

    // 插入 mythread
    db_exec($pdo, "INSERT IGNORE INTO " . table($pre, 'mythread') . " SET uid=$uid, tid=$tid");

    // 插入 mypost
    db_exec($pdo, "INSERT IGNORE INTO " . table($pre, 'mypost') . " SET uid=$uid, tid=$tid, pid=$firstpid");

    $thread_count++;

    // ---------- 为部分帖子添加回复 ----------
    $reply_pool = [
        '写得很好，收藏了！',
        '感谢分享，学到了很多。',
        '说得太对了，深有同感。',
        '楼主分析得很透彻，赞一个！',
        '请问有更详细的教程吗？',
        '我也遇到过类似的问题，后来用另一种方式解决了。',
        '顶一个，好帖子！',
        '这个观点很新颖，之前没有想过。',
        '补充一点：其实还可以从另一个角度来看这个问题。',
        '太实用了，马上收藏。',
        '学习了，谢谢楼主的分享。',
        '哈哈，笑死我了，太真实了。',
        '同感！我也有类似的经历。',
        'mark 一下，回头仔细看。',
        '有没有相关的 GitHub 仓库可以看看？',
    ];

    $reply_count = mt_rand(0, 4); // 每个帖子 0~4 条回复
    $lastpid = $firstpid;
    $lastuid = $uid;

    for ($r = 0; $r < $reply_count; $r++) {
        $reply_uid = $user_uids[array_rand($user_uids)];
        // 避免回复者和楼主相同（大部分时候）
        if ($reply_uid == $uid && mt_rand(0, 2) > 0) {
            $reply_uid = $user_uids[array_rand($user_uids)];
        }
        $reply_text = $reply_pool[array_rand($reply_pool)];
        $reply_fmt = nl2br(htmlspecialchars($reply_text));
        $reply_date = $create_date + mt_rand(3600, $days_ago * 86400);
        $reply_ip = ip2long('172.16.' . mt_rand(1, 254) . '.' . mt_rand(2, 254));

        $sql = sprintf(
            "INSERT INTO %s SET tid=%d, uid=%d, isfirst=0, create_date=%d, userip=%d, images=0, files=0, videos=0, doctype=0, quotepid=0, likes=%d, audit_status=1, message='%s', message_fmt='%s'",
            table($pre, 'post'),
            $tid,
            $reply_uid,
            $reply_date,
            $reply_ip,
            mt_rand(0, 5),
            addslashes($reply_text),
            addslashes($reply_fmt)
        );
        db_exec($pdo, $sql);
        $pid = $pdo->lastInsertId();

        // mypost
        db_exec($pdo, "INSERT IGNORE INTO " . table($pre, 'mypost') . " SET uid=$reply_uid, tid=$tid, pid=$pid");

        $lastpid = $pid;
        $lastuid = $reply_uid;
        $post_count++;
    }

    // 更新 thread 的回复数和最后回复信息
    db_exec($pdo, "UPDATE " . table($pre, 'thread') . " SET posts=$reply_count, lastuid=$lastuid, lastpid=$lastpid WHERE tid=$tid");
}

echo "✅ 成功创建 $thread_count 个主题<br>\n";
echo "✅ 成功创建 $post_count 个帖子（含回复）<br>\n";

// ---------- 更新用户发帖统计 ----------
foreach ($user_uids as $uid) {
    $thread_cnt = db_fetch($pdo, "SELECT COUNT(*) as c FROM " . table($pre, 'mythread') . " WHERE uid=$uid");
    $post_cnt = db_fetch($pdo, "SELECT COUNT(*) as c FROM " . table($pre, 'mypost') . " WHERE uid=$uid");
    db_exec($pdo, "UPDATE " . table($pre, 'user') . " SET threads={$thread_cnt['c']}, posts={$post_cnt['c']} WHERE uid=$uid");
}
echo "✅ 用户发帖统计已更新<br>\n";

// ---------- 更新版块统计 ----------
foreach ($forum_ids as $fid) {
    $thread_cnt = db_fetch($pdo, "SELECT COUNT(*) as c FROM " . table($pre, 'thread') . " WHERE fid=$fid");
    $post_cnt = db_fetch($pdo, "SELECT SUM(posts) as c FROM " . table($pre, 'thread') . " WHERE fid=$fid");
    $total_posts = ($post_cnt['c'] ?: 0) + $thread_cnt['c']; // 首帖 + 回复
    db_exec($pdo, "UPDATE " . table($pre, 'forum') . " SET threads={$thread_cnt['c']}, todayposts=0, todaythreads=0 WHERE fid=$fid");
}
echo "✅ 版块统计已更新<br>\n";

// ---------- 写入锁文件 ----------
@mkdir(dirname($lock_file), 0755, true);
file_put_contents($lock_file, date('Y-m-d H:i:s') . " imported\n");

echo "<br>\n";
echo "<strong>🎉 演示数据导入完成！</strong><br>\n";
echo "用户默认密码: 123456<br>\n";
echo "共导入 " . count($user_uids) . " 个用户, $thread_count 个主题, $post_count 个帖子<br>\n";
echo "<br>\n";
echo "<a href='/'>返回首页</a>\n";
