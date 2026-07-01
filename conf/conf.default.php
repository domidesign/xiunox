<?php

/*

	Xiuno BBS 4.0 配置文件
	支持多台 DB，主从配置好以后，xn 会自动根据 SQL 读写分离。
	支持各种 cache，本机 apc/xcache, 网络: redis/memcached/mysql
	支持 CDN，如果前端开启了 CDN 请设置 cdn_on=>1, 否则获取 IP 会不准确 
	支持临时目录设置，独立 Linux 主机，可以设置为 /dev/shm 通过内存加速
*/
return array (
	'db' => array (
		'type' => 'pdo_mysql',
		'mysql' => array (
			'master' => array (
				'host' => getenv('XIUNO_DB_HOST') ?: 'localhost',
				'user' => getenv('XIUNO_DB_USER') ?: 'root',
				'password' => getenv('XIUNO_DB_PASSWORD') ?: 'root',
				'name' => getenv('XIUNO_DB_NAME') ?: 'test',
				'tablepre' => 'bbs_',
				'charset' => 'utf8mb4',
				'engine' => 'innodb',
			),
			'slaves' => array (),
		),
		'pdo_mysql' => array (
			'master' => array (
				'host' => getenv('XIUNO_DB_HOST') ?: 'localhost',
				'user' => getenv('XIUNO_DB_USER') ?: 'root',
				'password' => getenv('XIUNO_DB_PASSWORD') ?: 'root',
				'name' => getenv('XIUNO_DB_NAME') ?: 'test',
				'tablepre' => 'bbs_',
				'charset' => 'utf8mb4',
				'engine' => 'innodb',
			),
			'slaves' => array (),
		),
	),
	// 缓存配置：默认 file 驱动（无外部依赖，避免 DB 故障时缓存连带雪崩）
	// 可选：file / redis / memcached / mysql
	'cache' => array (
		'enable' => true,
		'type' => 'file',
		'file' => array (
			'cache_dir' => '',  // 留空则使用 APP_PATH . 'tmp/cache/'
			'cachepre' => 'bbs_',
		),
		'redis' => array (
			'host' => '127.0.0.1',
			'port' => 6379,
			'password' => '',
			'database' => 0,
			'cachepre' => 'bbs_',
		),
		'memcached' => array (
			'host' => '127.0.0.1',
			'port' => 11211,
			'cachepre' => 'bbs_',
		),
		'mysql' => array (
			'cachepre' => 'bbs_',
		),
	),
	'tmp_path' => './tmp/',		// 可以配置为 linux 下的 /dev/shm ，通过内存缓存临时文件
	'log_path' => './log/',		// 日志目录
	
	// -------------------> xiuno bbs 4.0 配置

	'view_url' => '/view/',		// 可以配置单独的 CDN 域名：比如：http://static.domain.com/view/
	'upload_url' => '/upload/',	// 可以配置单独的 CDN 域名：比如：http://upload.domain.com/upload/
	'upload_path' => './upload/',	// 物理路径，可以用 NFS 存入到单独的文件服务器
	
	'logo_mobile_url' => '/view/img/logo.png',		// 手机的 LOGO URL
	'logo_pc_url' => '/view/img/logo.png',			// PC 的 LOGO URL
	'logo_water_url' => '/view/img/water-small.png',		// 水印的 LOGO URL
	
	'sitename' => 'Xiuno BBS',
	'sitebrief' => 'Site Brief',
	'timezone' => getenv('XIUNO_TIMEZONE') ?: 'Asia/Shanghai',	// 时区，默认中国
	'lang' => 'zh-cn',
	'runlevel' => 5,		// 0: 站点关闭; 1: 管理员可读写; 2: 会员可读;  3: 会员可读写; 4：所有人只读; 5: 所有人可读写
	'runlevel_reason' => 'The site is under maintenance, please visit later.',
	
	'cookie_domain' => '',
	'cookie_path' => '',
	// auth_key：安装时自动生成随机值，或通过环境变量 XIUNO_AUTH_KEY 设置
	'auth_key' => getenv('XIUNO_AUTH_KEY') ?: '',
	// 可信 CDN 代理 IP 白名单，开启 CDN 时必须配置，如 array('1.2.3.4', '5.6.7.0/24')
	'cdn_ip' => array(),
	
	'pagesize' => 20,
	'postlist_pagesize' => 100,
	'cache_thread_list_pages' => 10,
	'online_update_span' => 120,	// 在线更新频度，大站设置的长一些
	'online_hold_time' => 3600,	// 在线的时间
	'session_delay_update' => 0,
	'upload_image_width' => 927,	// 上传图片自动缩略的最大宽度
	'order_default' => 'lastpid',
	'attach_dir_save_rule' => 'Ym',	// 附件存放规则，附件多用：Ymd，附件少：Ym
	'attach_sign_key' => '',			// 附件签名密钥，安装时自动生成，用于生成图片附件签名URL
	'attach_referer_check' => 0,		// 附件防盗链检查，1=开启 0=关闭

	'update_views_on' => 1,
	'user_create_email_on' => 0,
	'user_create_on' => 1,
	'user_resetpw_on' => 0,
	'login_max_attempts' => 5,
	'login_ban_duration' => 900,
	
	'admin_bind_ip' => 0,		// 后台是否绑定 IP
	
	'cdn_on' => 0,
	
	/* 支持多种 URL 格式：
		0: ?thread-create-1.htm        默认兼容模式
		1: thread-create-1.htm          伪静态模式
		2: ?/thread/create/1            不支持
		3: /thread/create/1             路径风格
		4: thread-create-1.html         .html 后缀风格
		5: 自定义格式（需配合 url_rewrite_custom 配置）
	*/
	'url_rewrite_on' => 0,

	// 自定义伪静态格式（仅 url_rewrite_on=5 时生效）
	// 可用标签：{controller} {action} {id} {page}
	// 示例：
	//   /{controller}-{action}-{id}.html  →  /thread-create-1.html
	//   /{controller}/{action}/{id}.html  →  /thread/create/1.html
	//   /{controller}/{id}.html           →  /thread/1.html
	//   /{controller}-{id}.html           →  /thread-1.html
	//   /archives/{id}.html               →  /archives/1.html
	'url_rewrite_custom' => '/{controller}-{action}-{id}.html',
	
	// 禁止插件
	'disabled_plugin' => 0, 
	  
	'cache_disable' => 0,	// 开发模式：关闭模板编译缓存和模型合并缓存，每次请求都重新编译（1=关闭缓存，0=正常）
	  
	'enabled_themes' => array('light', 'dark', 'cupcake', 'emerald', 'corporate', 'synthwave', 'retro', 'cyberpunk', 'dracula', 'nord', 'dim', 'sunset'),
	'default_theme' => 'light',
	  
	'credits_daily_limit' => 10,        // 同一 reason+uid 每日操作限制次数
	'credits_log_retention_days' => 90,  // 积分日志保留天数
	'credits_types' => array('credits', 'golds', 'rmbs'),  // 启用的积分类型

	// 上传设置
	'upload_max_image_size' => 10485760,     // 图片最大尺寸（10MB）
	'upload_max_file_size' => 20971520,      // 附件最大尺寸（20MB）
	'upload_max_video_size' => 104857600,    // 视频最大尺寸（100MB）
	'upload_thumb_enabled' => 1,             // 是否生成缩略图
	'upload_thumb_width' => 200,             // 缩略图宽度
	'upload_allowed_image_types' => 'jpg,jpeg,png,gif,webp,bmp',
	'upload_allowed_video_types' => 'mp4,webm,ogg,avi,rm,rmvb',
	'upload_allowed_file_types' => 'doc,xls,ppt,docx,xlsx,pptx,pdf,txt,zip,gz,rar,7z',
	'upload_driver' => 'local',              // 上传存储驱动(local/oss)

	// API 设置
	'api_enabled' => 0,
	'api_token_expire' => 30,                // API 令牌过期天数
	'api_default_appid' => '',               // 默认应用ID（升级时自动生成）
	'api_default_secret' => '',              // 默认应用密钥（升级时自动生成）
	'api_rate_limit' => 1,                   // API 速率限制开关
	'api_rate_limit_max' => 60,              // 未认证用户每分钟请求上限
	'api_rate_limit_window' => 60,           // 速率限制窗口（秒）
	'api_log' => 0,                          // API 日志开关
	'api_cors_origin' => '*',                // CORS 允许来源

	// 编辑器
	'editor' => 'aieditor',

	// 安全设置
	'security_password_max_retries' => 5,    // 密码最大重试次数
	'security_lockout_duration' => 900,      // 锁定时长（秒）
	'security_search_require_login' => 0,    // 搜索是否需要登录
	'security_email_code_interval' => 60,    // 发送验证码间隔（秒）
	'security_email_code_daily_limit' => 5,  // 同一邮箱每日发送上限
	'security_email_code_ip_hourly_limit' => 10, // 同一IP每小时发送上限

	// 注意：version 字段运行时会被 index.php 中的 XIUNOX_VERSION 常量覆盖
	// 真实版本号唯一来源为 version.php，修改版本号只需改 version.php
	'version' => '1.0.1',
	'static_version' => '?1.0',
	'installed' => 0,

	// 显示设置
	'home_forum_ids' => array(),         // 首页版块过滤（空=显示全部）
	'default_lang' => '',                // 默认语言（空=跟随浏览器）
	'mobile_nav_items' => array(),       // 手机底部导航（空=使用默认）
	'mobile_nav_enable' => 0,            // 手机底部导航开关

	// -------------------> 基础设施配置（Task 9 新增）
	// 字符集（数据库连接 + SET NAMES）
	'charset' => 'utf8mb4',
	// 数据库从库列表（读写分离），默认空数组表示只用主库
	'db_slaves' => array(),
	// Session 处理器：file / redis / db
	'session_handler' => 'file',
	// Session Redis 配置（session_handler=redis 时生效）
	// 字段名与 cache.redis 保持一致：password/database
	'session_redis' => array(
		'host' => '127.0.0.1',
		'port' => 6379,
		'password' => '',
		'database' => 0,
	),
	// 日志级别：DEBUG / INFO / WARNING / ERROR（DEBUG=0 时只写 WARNING+）
	'log_level' => 'WARNING',
);
?>