# Xiuno BBS 4.0 表结构

### 用户表 ###
DROP TABLE IF EXISTS `bbs_user`;
CREATE TABLE `bbs_user` (
  uid int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户编号',
  gid smallint(6) unsigned NOT NULL DEFAULT '0' COMMENT '用户组编号',	# 如果要屏蔽，调整用户组即可
  email char(40) NOT NULL DEFAULT '' COMMENT '邮箱',
  username char(32) NOT NULL DEFAULT '' COMMENT '用户名',	# 不可以重复
  nickname varchar(32) NOT NULL DEFAULT '' COMMENT '昵称',	# 可修改，display_name 优先使用
  signature varchar(255) NOT NULL DEFAULT '' COMMENT '个性签名',
  realname char(16) NOT NULL DEFAULT '' COMMENT '真实姓名',	# 真实姓名，天朝预留
  idnumber char(19) NOT NULL DEFAULT '' COMMENT '身份证号',	# 真实身份证号码，天朝预留
  `password` char(32) NOT NULL DEFAULT '' COMMENT '密码',
  `password_sms` char(16) NOT NULL DEFAULT '' COMMENT '密码',	# 预留，手机发送的 sms 验证码
  salt char(16) NOT NULL DEFAULT '' COMMENT '密码混杂',
  `password_hash` varchar(255) NOT NULL DEFAULT '' COMMENT 'bcrypt密码哈希',
  login_attempts int(11) NOT NULL DEFAULT '0' COMMENT '登录失败次数',
  banned_until int(11) unsigned NOT NULL DEFAULT '0' COMMENT '封禁截止时间',
  ban_type tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '封禁类型:0正常/1禁言/2禁止访问/3锁定',
  ban_reason varchar(255) NOT NULL DEFAULT '' COMMENT '封禁原因',
  ban_admin_uid int(11) unsigned NOT NULL DEFAULT '0' COMMENT '操作管理员uid',
  ban_time int(11) unsigned NOT NULL DEFAULT '0' COMMENT '封禁时间戳',
  last_login_ip int(11) unsigned NOT NULL DEFAULT '0' COMMENT '最后登录IP',
  last_login_time int(11) unsigned NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  mobile char(11) NOT NULL DEFAULT '' COMMENT '手机号',		# 预留，供二次开发扩展
  qq char(15) NOT NULL DEFAULT '' COMMENT 'QQ',			# 预留，供二次开发扩展，可以弹出QQ直接聊天
  threads int(11) NOT NULL DEFAULT '0' COMMENT '发帖数',		#
  posts int(11) NOT NULL DEFAULT '0' COMMENT '回帖数',		#
  digests int(11) NOT NULL DEFAULT '0' COMMENT '精华主题数',	#
  credits int(11) NOT NULL DEFAULT '0' COMMENT '积分',		# 预留，供二次开发扩展
  golds int(11) NOT NULL DEFAULT '0' COMMENT '金币',		# 预留，虚拟币
  rmbs int(11) NOT NULL DEFAULT '0' COMMENT '人民币',		# 预留，人民币
  create_ip int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建时IP',
  create_date int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  login_ip int(11) unsigned NOT NULL DEFAULT '0' COMMENT '登录时IP',
  login_date int(11) unsigned NOT NULL DEFAULT '0' COMMENT '登录时间',
  logins int(11) unsigned NOT NULL DEFAULT '0' COMMENT '登录次数',
  avatar int(11) NOT NULL DEFAULT '0' COMMENT '头像: 0=默认, >0=上传时间戳, <0=预设头像索引',
  follows int(11) NOT NULL DEFAULT '0' COMMENT '关注数',
  followeds int(11) NOT NULL DEFAULT '0' COMMENT '粉丝数',
  favorites int(11) NOT NULL DEFAULT '0' COMMENT '收藏数',
  ai_config text DEFAULT NULL COMMENT 'AI配置',
  notices mediumint(8) unsigned NOT NULL DEFAULT '0' COMMENT '通知数',
  unread_notices mediumint(8) unsigned NOT NULL DEFAULT '0' COMMENT '未读通知数',
  PRIMARY KEY (uid),
  UNIQUE KEY username (username),
  UNIQUE KEY nickname (nickname),
  UNIQUE KEY email (email),						# 升级的时候可能为空
  KEY gid (gid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `bbs_user` SET uid=1, gid=1, email='admin@admin.com', username='admin', nickname='admin', `password`='d98bb50e808918dd45a8d92feafc4fa3',salt='123456';

# 用户组
DROP TABLE IF EXISTS `bbs_group`;
CREATE TABLE `bbs_group` (
  gid smallint(6) unsigned NOT NULL,			#	
  name char(20) NOT NULL default '',			# 用户组名称
  creditsfrom int(11) NOT NULL default '0',		# 积分从
  creditsto int(11) NOT NULL default '0',		# 积分到
  allowread int(11) NOT NULL default '0',		# 允许访问
  allowthread int(11) NOT NULL default '0',		# 允许发主题
  allowpost int(11) NOT NULL default '0',		# 允许回帖
  allowattach int(11) NOT NULL default '0',		# 允许上传文件
  allowdown int(11) NOT NULL default '0',		# 允许下载文件
  allowtop int(11) NOT NULL default '0',		# 允许置顶
  allowupdate int(11) NOT NULL default '0',		# 允许编辑
  allowdelete int(11) NOT NULL default '0',		# 允许删除
  allowmove int(11) NOT NULL default '0',		# 允许移动
  allowbanuser int(11) NOT NULL default '0',		# 允许禁止用户
  allowdeleteuser int(11) NOT NULL default '0',		# 允许删除用户
  allowviewip int(11) unsigned NOT NULL default '0',	# 允许查看用户敏感信息
  allow_direct_post tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否直接发布: 0需审核/1直接发布',
  allow_direct_reply tinyint(1) NOT NULL DEFAULT '1' COMMENT '回帖审核: 0需审核/1直接发布',
  allow_direct_profile tinyint(1) NOT NULL DEFAULT '1' COMMENT '个人资料审核: 0需审核/1直接更新',
  color char(7) NOT NULL default '',			# 用户组颜色
  icon varchar(50) NOT NULL default '',			# 用户组图标
  PRIMARY KEY (gid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `bbs_group` SET gid='0', name="游客组", creditsfrom='0', creditsto='0', allowread='1', allowthread='0', allowpost='1', allowattach='0', allowdown='1', allowtop='0', allowupdate='0', allowdelete='0', allowmove='0', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='1', allow_direct_profile='1', icon='ti ti-user', color='#6c757d';

INSERT INTO `bbs_group` SET gid='1', name="管理员组", creditsfrom='0', creditsto='0', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='1', allowupdate='1', allowdelete='1', allowmove='1', allowbanuser='1', allowdeleteuser='1', allowviewip='1', allow_direct_reply='1', allow_direct_profile='1', icon='ti ti-shield', color='#dc3545';
INSERT INTO `bbs_group` SET gid='2', name="超级版主组", creditsfrom='0', creditsto='0', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='1', allowupdate='1', allowdelete='1', allowmove='1', allowbanuser='1', allowdeleteuser='1', allowviewip='1', allow_direct_reply='1', allow_direct_profile='1', icon='ti ti-star', color='#0d6efd';
INSERT INTO `bbs_group` SET gid='4', name="版主组", creditsfrom='0', creditsto='0', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='1', allowupdate='1', allowdelete='1', allowmove='1', allowbanuser='1', allowdeleteuser='0', allowviewip='1', allow_direct_reply='1', allow_direct_profile='1', icon='ti ti-award', color='#198754';
INSERT INTO `bbs_group` SET gid='5', name="实习版主组", creditsfrom='0', creditsto='0', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='1', allowupdate='1', allowdelete='0', allowmove='1', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='1', allow_direct_profile='1', icon='ti ti-user-check', color='#6c757d';

INSERT INTO `bbs_group` SET gid='6', name="待验证用户组", creditsfrom='0', creditsto='0', allowread='1', allowthread='0', allowpost='1', allowattach='0', allowdown='1', allowtop='0', allowupdate='0', allowdelete='0', allowmove='0', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='1', allow_direct_profile='1';
INSERT INTO `bbs_group` SET gid='7', name="禁止用户组", creditsfrom='0', creditsto='0', allowread='0', allowthread='0', allowpost='0', allowattach='0', allowdown='0', allowtop='0', allowupdate='0', allowdelete='0', allowmove='0', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='0', allow_direct_profile='0';

INSERT INTO `bbs_group` SET gid='101', name="一级用户组", creditsfrom='0', creditsto='50', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='0', allowupdate='0', allowdelete='0', allowmove='0', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='1', allow_direct_profile='1';
INSERT INTO `bbs_group` SET gid='102', name="二级用户组", creditsfrom='50', creditsto='200', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='0', allowupdate='0', allowdelete='0', allowmove='0', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='1', allow_direct_profile='1';
INSERT INTO `bbs_group` SET gid='103', name="三级用户组", creditsfrom='200', creditsto='1000', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='0', allowupdate='0', allowdelete='0', allowmove='0', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='1', allow_direct_profile='1';
INSERT INTO `bbs_group` SET gid='104', name="四级用户组", creditsfrom='1000', creditsto='10000', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='0', allowupdate='0', allowdelete='0', allowmove='0', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='1', allow_direct_profile='1';
INSERT INTO `bbs_group` SET gid='105', name="五级用户组", creditsfrom='10000', creditsto='10000000', allowread='1', allowthread='1', allowpost='1', allowattach='1', allowdown='1', allowtop='0', allowupdate='0', allowdelete='0', allowmove='0', allowbanuser='0', allowdeleteuser='0', allowviewip='0', allow_direct_reply='1', allow_direct_profile='1';

# 板块表，一级, runtime 中存放 forumlist 格式化以后的数据。
DROP TABLE IF EXISTS bbs_forum;
CREATE TABLE bbs_forum (
  fid smallint(5) unsigned NOT NULL auto_increment,	# fid
 # fup int(11) unsigned NOT NULL auto_increment,	# 上一级版块，二级版块作为插件
  name char(16) NOT NULL default '',			# 版块名称
  `rank` tinyint(3) unsigned NOT NULL default '0',	# 显示，倒序，数字越大越靠前
  fup int(11) unsigned NOT NULL DEFAULT '0',		# 上级分区 fid，0 表示分区或未归类版块
  type tinyint(1) NOT NULL DEFAULT '0',			# 0=版块 1=分区
  threads mediumint(8) unsigned NOT NULL default '0',	# 主题数
  digests mediumint(8) unsigned NOT NULL DEFAULT '0' COMMENT '精华主题数',
  todayposts mediumint(8) unsigned NOT NULL default '0',# 今日发帖，计划任务每日凌晨０点清空为０，
  todaythreads mediumint(8) unsigned NOT NULL default '0',# 今日发主题，计划任务每日凌晨０点清空为０
  follows int(11) unsigned NOT NULL default '0',		# 关注数
  brief text NOT NULL,					# 版块简介 允许HTML
  announcement text NOT NULL,				# 版块公告 允许HTML
  accesson int(11) unsigned NOT NULL default '0',	# 是否开启权限控制
  audit_thread tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否审核发帖: 0不审核/1审核',
  orderby tinyint(11) NOT NULL default '0',		# 默认列表排序，0: 顶贴时间 last_date， 1: 发帖时间 tid
  create_date int(11) unsigned NOT NULL default '0',	# 板块创建时间
  icon varchar(255) NOT NULL default '',		# 版块图标，存储图片路径
  moduids char(120) NOT NULL default '',		# 每个版块有多个版主，最多10个： 10*12 = 120，删除用户的时候，如果是版主，则调整后再删除。逗号分隔
  seo_title char(64) NOT NULL default '',		# SEO 标题，如果设置会代替版块名称
  seo_keywords char(64) NOT NULL default '',		# SEO keyword
  PRIMARY KEY (fid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO bbs_forum SET fid='1', name='默认版块', brief='默认版块介绍';
#  cache_date int(11) NOT NULL default '0',		# 最后 threadlist 缓存的时间，6种排序前10页结果缓存。如果是前10页，先读缓存，并依据此字段过期。更新条件：发贴
  
# 版块访问规则, forum.accesson 开启时生效, 记录行数： fid * gid
DROP TABLE IF EXISTS bbs_forum_access;
CREATE TABLE bbs_forum_access (				# 字段中文名
  fid smallint(5) unsigned NOT NULL default '0',		# fid
  gid smallint(5) unsigned NOT NULL default '0',		# fid
  allowread tinyint(1) unsigned NOT NULL default '0',	# 允许查看
  allowthread tinyint(1) unsigned NOT NULL default '0',	# 允许发主题
  allowpost tinyint(1) unsigned NOT NULL default '0',	# 允许回复
  allowattach tinyint(1) unsigned NOT NULL default '0',	# 允许上传附件
  allowdown tinyint(1) unsigned NOT NULL default '0',	# 允许下载附件
  allowthreadaudit tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '发帖审核: 0不审核/1需审核',
  allowpostaudit tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '回帖审核: 0不审核/1需审核',
  PRIMARY KEY (fid, gid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 论坛主题
DROP TABLE IF EXISTS bbs_thread;
CREATE TABLE bbs_thread (
  fid smallint(5) unsigned NOT NULL default '0',			# 版块 id
  tid int(11) unsigned NOT NULL auto_increment,		# 主题id
  top tinyint(1) NOT NULL default '0',			# 置顶级别: 0: 普通主题, 1-3 置顶的顺序
  uid int(11) unsigned NOT NULL default '0',		# 用户id
  userip int(11) unsigned NOT NULL default '0',		# 发帖时用户ip ip2long()，主要用来清理
  subject char(128) NOT NULL default '',		# 主题
  create_date int(11) unsigned NOT NULL default '0',	# 发帖时间
  last_date int(11) unsigned NOT NULL default '0',	# 最后回复时间

  views int(11) unsigned NOT NULL default '0',		# 查看次数, 剥离出去，单独的服务，避免 cache 失效
  posts int(11) unsigned NOT NULL default '0',		# 回帖数
  likes int(11) NOT NULL DEFAULT '0',			# 点赞数
  favorites int(11) NOT NULL DEFAULT '0',		# 收藏数
  images tinyint(6) NOT NULL default '0',		# 附件中包含的图片数
  files tinyint(6) NOT NULL default '0',		# 附件中包含的文件数
  videos tinyint(6) NOT NULL default '0',		# 附件中包含的视频数
  mods tinyint(6) NOT NULL default '0',			# 预留：版主操作次数，如果 > 0, 则查询 modlog，显示斑竹的评分
  closed tinyint(1) unsigned NOT NULL default '0',	# 预留：是否关闭，关闭以后不能再回帖、编辑。
  audit_status tinyint(1) NOT NULL DEFAULT '1' COMMENT '审核状态: 0待审/1通过/2驳回',
  resubmit_count tinyint(3) NOT NULL DEFAULT '0' COMMENT '重新提交次数（含首次发布）',
  reject_reason varchar(255) NOT NULL DEFAULT '' COMMENT '驳回原因',
  is_announcement tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否公告: 0否/1是',
  announcement_order int(11) unsigned NOT NULL DEFAULT '0' COMMENT '公告排序',
  is_deleted tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已删除: 0否/1是',
  deleted_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '删除时间',
  deleted_by int(11) unsigned NOT NULL DEFAULT 0 COMMENT '删除操作者uid',
  digest tinyint(1) NOT NULL DEFAULT 0 COMMENT '精华级别: 0否/1-3精华',
  digest_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '精华时间',
  firstpid int(11) unsigned NOT NULL default '0',	# 首贴 pid
  lastuid int(11) unsigned NOT NULL default '0',	# 最近参与的 uid
  lastpid int(11) unsigned NOT NULL default '0',	# 最后回复的 pid
  PRIMARY KEY (tid),					# 主键
  KEY (lastpid),					# 最后回复排序
  KEY (fid, tid),					# 发帖时间排序，正序。数据量大时可以考虑建立小表，对小表进行分区优化，只有数据量达到千万级以上时才需要。
  KEY (fid, lastpid),					# 顶贴时间排序，倒序
  KEY idx_uid_tid (uid, tid),				# 用户帖子列表
  KEY idx_uid_fid (uid, fid),				# 用户版块帖子
  KEY idx_fid_audit_lastpid (fid, audit_status, lastpid),	# 版块列表含审核过滤
  KEY idx_is_deleted (is_deleted)				# 软删除过滤
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 置顶主题
DROP TABLE IF EXISTS bbs_thread_top;
CREATE TABLE bbs_thread_top (
  fid smallint(5) unsigned NOT NULL default '0',			# 查找板块置顶
  tid int(11) unsigned NOT NULL default '0',		# tid
  top int(11) unsigned NOT NULL default '0',		# top: 0 是普通最新贴，> 0 置顶贴。
  PRIMARY KEY (tid),					#
  KEY (top, tid),					# 最新贴：top=0 order by tid desc / 全局置顶： top=3
  KEY (fid, top)					# 版块置顶的贴 fid=1 and top=1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 精华主题
DROP TABLE IF EXISTS bbs_thread_digest;
CREATE TABLE bbs_thread_digest (
  fid smallint(5) unsigned NOT NULL DEFAULT '0',			# 版块 id
  tid int(11) unsigned NOT NULL DEFAULT '0',		# 主题id
  uid int(11) unsigned NOT NULL DEFAULT '0',		# 用户id
  digest tinyint(6) NOT NULL DEFAULT '0',		# 精华级别: 1-3
  PRIMARY KEY (tid),
  KEY (fid),						# 按版块查找精华
  KEY (uid)						# 按用户查找精华
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 论坛帖子数据
DROP TABLE IF EXISTS bbs_post;
CREATE TABLE bbs_post (
  tid int(11) unsigned NOT NULL default '0',		# 主题id
  pid int(11) unsigned NOT NULL auto_increment,		# 帖子id
  uid int(11) unsigned NOT NULL default '0',		# 用户id
  isfirst int(11) unsigned NOT NULL default '0',	# 是否为首帖，与 thread.firstpid 呼应
  create_date int(11) unsigned NOT NULL default '0',	# 发贴时间
  userip int(11) unsigned NOT NULL default '0',		# 发帖时用户ip ip2long()
  images smallint(6) NOT NULL default '0',		# 附件中包含的图片数
  files smallint(6) NOT NULL default '0',		# 附件中包含的文件数
  videos smallint(6) NOT NULL default '0',		# 附件中包含的视频数
  doctype tinyint(3) NOT NULL default '0',		# 类型，0: html, 1: txt; 2: markdown; 3: ubb
  quotepid int(11) NOT NULL default '0',		# 引用哪个 pid，可能不存在
  likes int(11) NOT NULL DEFAULT '0',			# 点赞数
  audit_status tinyint(1) NOT NULL DEFAULT '1' COMMENT '审核状态: 0待审/1通过/2驳回',
  resubmit_count tinyint(3) NOT NULL DEFAULT '0' COMMENT '重新提交次数（含首次发布）',
  reject_reason varchar(255) NOT NULL DEFAULT '' COMMENT '驳回原因',
  is_top tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否置顶评论: 0否/1是',
  is_deleted tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已删除: 0否/1是',
  deleted_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '删除时间',
  deleted_by int(11) unsigned NOT NULL DEFAULT 0 COMMENT '删除操作者uid',

  message longtext NOT NULL,				# 内容，用户提示的原始数据
  message_fmt longtext NOT NULL,			# 内容，存放的过滤后的html内容，可以定期清理，减肥。
  PRIMARY KEY (pid),
  KEY (tid, pid),
  KEY (uid),						# 我的回帖，清理数据需要
  KEY idx_uid_isfirst_pid (uid, isfirst, pid),		# 用户回帖列表
  KEY idx_is_deleted (is_deleted)				# 软删除过滤
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
# 编辑历史

#论坛附件表  只能按照从上往下的方式查找和删除！ 此表如果大，可以考虑通过 aid 分区。
DROP TABLE IF EXISTS bbs_attach;
CREATE TABLE bbs_attach (
  aid int(11) unsigned NOT NULL auto_increment ,	# 附件id
  tid int(11) NOT NULL default '0',			# 主题id
  pid int(11) NOT NULL default '0',			# 帖子id
  uid int(11) NOT NULL default '0',			# 用户id
  filesize int(8) unsigned NOT NULL default '0',	# 文件尺寸，单位字节
  width mediumint(8) unsigned NOT NULL default '0',	# width > 0 则为图片
  height mediumint(8) unsigned NOT NULL default '0',	# height
  filename char(120) NOT NULL default '',		# 文件名称，会过滤，并且截断，保存后的文件名，不包含URL前缀 upload_url
  orgfilename char(120) NOT NULL default '',		# 上传的原文件名
  filetype char(7) NOT NULL default '',			# 文件类型: image/txt/zip，小图标显示 <i class="icon filetype image"></i>
  create_date int(11) unsigned NOT NULL default '0',	# 文件上传时间 UNIX 时间戳
  comment char(100) NOT NULL default '',		# 文件注释 方便于搜索
  downloads int(11) NOT NULL default '0',		# 下载次数，预留
  credits int(11) NOT NULL default '0',			# 需要的积分，预留
  golds int(11) NOT NULL default '0',			# 需要的金币，预留
  rmbs int(11) NOT NULL default '0',			# 需要的人民币，预留
  isimage tinyint(1) NOT NULL default '0',		# 是否为图片
  thumb_exists tinyint(1) NOT NULL DEFAULT 0 COMMENT '缩略图是否存在',
  driver varchar(32) NOT NULL DEFAULT 'local' COMMENT '存储驱动',
  PRIMARY KEY (aid),					# aid
  KEY pid (pid),					# 每个帖子下多个附件
  KEY uid (uid)						# 我的附件，清理数据需要。
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 我的主题，每个主题不管回复多少次，只记录一次。大表，需要分区。
DROP TABLE IF EXISTS bbs_mythread;
CREATE TABLE bbs_mythread (
  uid int(11) unsigned NOT NULL default '0',		# uid
  tid int(11) unsigned NOT NULL default '0',		# 用来清理，删除板块的时候需要
  PRIMARY KEY (uid, tid)				# 每一个帖子只能插入一次 unique
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 我的回帖。大表，需要分区。
DROP TABLE IF EXISTS bbs_mypost;
CREATE TABLE bbs_mypost (
  uid int(11) unsigned NOT NULL default '0',		# uid
  tid int(11) unsigned NOT NULL default '0',		# 用来清理
  pid int(11) unsigned NOT NULL default '0',		#
  KEY (tid),						#
  PRIMARY KEY (uid, pid)				#
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# session 表
# 缓存到 runtime 表。 online_0 全局 online_fid 版块。提高遍历效率。
DROP TABLE IF EXISTS bbs_session;
CREATE TABLE bbs_session (
  sid char(32) NOT NULL default '0',			# 随机生成 id 不能重复 uniqueid() 13 位
  uid int(11) unsigned NOT NULL default '0',		# 用户id 未登录为 0，可以重复
  fid smallint(5) unsigned NOT NULL default '0',		# 所在的版块
  url char(32) NOT NULL default '',			# 当前访问 url
  ip int(11) unsigned NOT NULL default '0',		# 用户ip
  useragent char(128) NOT NULL default '',		# 用户浏览器信息
  data char(255) NOT NULL default '',			# session 数据，超大数据存入大表。
  bigdata tinyint(1) NOT NULL default '0',		# 是否有大数据。
  last_date int(11) unsigned NOT NULL default '0',	# 上次活动时间
  PRIMARY KEY (sid),
  KEY ip (ip),
  KEY fid (fid),
  KEY uid_last_date (uid, last_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS bbs_session_data;
CREATE TABLE bbs_session_data (
  sid char(32) NOT NULL default '0',			#
  last_date int(11) unsigned NOT NULL default '0',	# 上次活动时间
  data text NOT NULL,					# 存超大数据
  PRIMARY KEY (sid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 版主操作日志
DROP TABLE IF EXISTS bbs_modlog;
CREATE TABLE bbs_modlog (
  logid int(11) unsigned NOT NULL auto_increment,	# logid
  uid int(11) unsigned NOT NULL default '0',		# 版主 uid
  tid int(11) unsigned NOT NULL default '0',		# 主题id
  pid int(11) unsigned NOT NULL default '0',		# 帖子id
  subject char(32) NOT NULL default '',			# 主题
  comment char(64) NOT NULL default '',			# 版主评价
  rmbs int(11) NOT NULL default '0',			# 加减人民币, 预留
  create_date int(11) unsigned NOT NULL default '0',	# 时间
  action char(16) NOT NULL default '',			# top|delete|untop
  PRIMARY KEY (logid),
  KEY (uid, logid),
  KEY (tid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 通知表（notice 表已合并到 notify，仅保留 notify 一张表）
# bbs_notify 表在下方统一创建

# 持久的 key value 数据存储, ttserver, mysql
DROP TABLE IF EXISTS bbs_kv;
CREATE TABLE bbs_kv (
  k char(32) NOT NULL default '',
  v mediumtext NOT NULL,
  expiry int(11) unsigned NOT NULL default '0',		# 过期时间
  PRIMARY KEY(k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 缓存表，用来保存临时数据。
DROP TABLE IF EXISTS bbs_cache;
CREATE TABLE bbs_cache (
  k varchar(255) NOT NULL default '',
  v mediumtext NOT NULL,
  expiry int(11) unsigned NOT NULL default '0',		# 过期时间
  PRIMARY KEY(k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 临时队列，用来保存临时数据。
DROP TABLE IF EXISTS bbs_queue;
CREATE TABLE bbs_queue (
  queueid int(11) unsigned NOT NULL default '0',		# 队列 id
  v int(11) NOT NULL default '0',			# 队列中存放的数据，只能为 int
  expiry int(11) unsigned NOT NULL default '0',		# 过期时间，默认 0，不过期
  UNIQUE KEY(queueid, v),
  KEY(expiry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


# 系统表, id
# MAXID 表，几个主要的大表，每天的最大ID，用来削减索引 create_date
# day = 0 表示月； month = 0 AND day = 0 表示年
# 计划任务，1点执行。 不需要太精准，用来作为过滤条件。
# 可以有效的过滤冷热数据
DROP TABLE IF EXISTS `bbs_table_day`;
CREATE TABLE `bbs_table_day` (
  `year` smallint(11) unsigned NOT NULL DEFAULT '0' COMMENT '年',	#
  `month` tinyint(11) unsigned NOT NULL DEFAULT '0' COMMENT '月', 	#
  `day` tinyint(11) unsigned NOT NULL DEFAULT '0' COMMENT '日', 		#
  `create_date` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '时间戳', 	#
  `table` char(16) NOT NULL default '' COMMENT '表名',			#
  `maxid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '最大ID', 	#
  `count` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '总数', 		#
  PRIMARY KEY (`year`, `month`, `day`, `table`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 用户登录日志
DROP TABLE IF EXISTS `bbs_user_login_log`;
CREATE TABLE `bbs_user_login_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '用户id',
  `ip` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '登录IP',
  `time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '登录时间',
  `success` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否成功',
  `user_agent` varchar(255) NOT NULL DEFAULT '' COMMENT '浏览器UA',
  PRIMARY KEY (`id`),
  KEY (`uid`, `time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 昵称修改日志
DROP TABLE IF EXISTS `bbs_nickname_change_log`;
CREATE TABLE `bbs_nickname_change_log` (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户id',
  old_nickname varchar(32) NOT NULL DEFAULT '' COMMENT '旧昵称',
  new_nickname varchar(32) NOT NULL DEFAULT '' COMMENT '新昵称',
  change_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '修改时间',
  ip int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作IP',
  PRIMARY KEY (id),
  KEY uid_change_time (uid, change_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='昵称修改日志';

# 签名修改日志
DROP TABLE IF EXISTS `bbs_signature_change_log`;
CREATE TABLE `bbs_signature_change_log` (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户id',
  old_signature varchar(255) NOT NULL DEFAULT '' COMMENT '旧签名',
  new_signature varchar(255) NOT NULL DEFAULT '' COMMENT '新签名',
  change_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '修改时间',
  ip int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作IP',
  PRIMARY KEY (id),
  KEY uid_change_time (uid, change_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='签名修改日志';

# 帖子点赞
DROP TABLE IF EXISTS bbs_post_like;
CREATE TABLE bbs_post_like (
  tid int(11) unsigned NOT NULL DEFAULT '0',
  pid int(11) unsigned NOT NULL DEFAULT '0',
  uid int(11) unsigned NOT NULL DEFAULT '0',
  create_date int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (uid, pid),
  KEY (tid, uid),
  KEY (pid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 帖子收藏
DROP TABLE IF EXISTS bbs_thread_favorite;
CREATE TABLE bbs_thread_favorite (
  tid int(11) unsigned NOT NULL DEFAULT '0',
  uid int(11) unsigned NOT NULL DEFAULT '0',
  create_date int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (uid, tid),
  KEY (tid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 用户关注
DROP TABLE IF EXISTS bbs_user_follow;
CREATE TABLE bbs_user_follow (
  uid int(11) unsigned NOT NULL DEFAULT '0',
  follow_uid int(11) unsigned NOT NULL DEFAULT '0',
  create_date int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (uid, follow_uid),
  KEY (follow_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 版块关注
DROP TABLE IF EXISTS bbs_forum_follow;
CREATE TABLE bbs_forum_follow (
  uid int(11) unsigned NOT NULL DEFAULT '0',
  fid smallint(5) unsigned NOT NULL DEFAULT '0',
  create_date int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (uid, fid),
  KEY (fid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 站内通知
DROP TABLE IF EXISTS bbs_notify;
CREATE TABLE bbs_notify (
  nid int(11) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) unsigned NOT NULL DEFAULT '0',
  from_uid int(11) unsigned NOT NULL DEFAULT '0',
  type char(16) NOT NULL DEFAULT '' COMMENT 'thread/like/favorite/follow',
  tid int(11) unsigned NOT NULL DEFAULT '0',
  pid int(11) unsigned NOT NULL DEFAULT '0',
  content char(128) NOT NULL DEFAULT '',
  create_date int(11) unsigned NOT NULL DEFAULT '0',
  is_read tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (nid),
  KEY (uid, is_read, nid),
  KEY (uid, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 审核日志
DROP TABLE IF EXISTS bbs_audit_log;
CREATE TABLE bbs_audit_log (
  logid int(11) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作者uid',
  target_type char(16) NOT NULL DEFAULT '' COMMENT '目标类型: thread/post',
  target_id int(11) unsigned NOT NULL DEFAULT 0 COMMENT '目标ID: tid或pid',
  action char(16) NOT NULL DEFAULT '' COMMENT '操作: approve/reject',
  reason varchar(255) NOT NULL DEFAULT '' COMMENT '操作原因',
  create_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作时间',
  PRIMARY KEY (logid),
  KEY (target_type, target_id),
  KEY (uid, logid),
  KEY (create_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 个人资料审核
DROP TABLE IF EXISTS bbs_user_profile_audit;
CREATE TABLE bbs_user_profile_audit (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户id',
  field_name varchar(32) NOT NULL DEFAULT '' COMMENT '字段名: avatar/signature',
  old_value text NOT NULL COMMENT '旧值',
  new_value text NOT NULL COMMENT '新值',
  audit_status tinyint(1) NOT NULL DEFAULT 0 COMMENT '审核状态: 0待审/1通过/2驳回',
  operator_uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '审核人uid',
  reason varchar(255) NOT NULL DEFAULT '' COMMENT '审核原因',
  create_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '提交时间',
  audit_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '审核时间',
  PRIMARY KEY (id),
  KEY idx_uid (uid),
  KEY idx_audit_status (audit_status),
  KEY idx_create_date (create_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 插件管理表
DROP TABLE IF EXISTS bbs_plugin;
CREATE TABLE bbs_plugin (
  dir varchar(64) NOT NULL COMMENT '插件目录名',
  name varchar(128) NOT NULL DEFAULT '' COMMENT '插件名称',
  type tinyint(1) NOT NULL DEFAULT 0 COMMENT '类型: 0=插件, 1=模板',
  installed tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已安装',
  enable tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已启用',
  version varchar(32) NOT NULL DEFAULT '' COMMENT '已安装版本号（来自 conf.json）',
  install_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '安装时间',
  enable_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '最后启用时间',
  disable_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '最后禁用时间',
  create_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '记录创建时间',
  update_time int(11) unsigned NOT NULL DEFAULT 0 COMMENT '记录更新时间',
  PRIMARY KEY (dir),
  KEY type (type),
  KEY enable (enable),
  KEY install_time (install_time),
  KEY enable_time (enable_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# 邮件发送日志
CREATE TABLE IF NOT EXISTS `bbs_email_log` (
  `logid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(200) NOT NULL DEFAULT '' COMMENT '收件人邮箱',
  `subject` varchar(200) NOT NULL DEFAULT '' COMMENT '邮件主题',
  `smtp_host` varchar(100) NOT NULL DEFAULT '' COMMENT 'SMTP服务器',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '状态: 0=失败, 1=成功',
  `error_msg` varchar(500) NOT NULL DEFAULT '' COMMENT '错误信息',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `ip` int(11) unsigned NOT NULL DEFAULT '0' COMMENT 'IP地址',
  PRIMARY KEY (`logid`),
  KEY `idx_to_email` (`to_email`),
  KEY `idx_status` (`status`),
  KEY `idx_create_date` (`create_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='邮件发送日志';

# 管理员操作日志
CREATE TABLE IF NOT EXISTS `bbs_admin_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作者uid',
  `action` varchar(32) NOT NULL DEFAULT '' COMMENT '操作类型',
  `target_type` varchar(32) NOT NULL DEFAULT '' COMMENT '目标类型',
  `target_ids` varchar(255) NOT NULL DEFAULT '' COMMENT '目标ID列表',
  `detail` varchar(1024) NOT NULL DEFAULT '' COMMENT '操作详情',
  `ip` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作IP',
  `create_date` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作时间',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_action` (`action`),
  KEY `idx_create_date` (`create_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='管理员操作日志';

# 积分日志
DROP TABLE IF EXISTS bbs_credits_log;
CREATE TABLE bbs_credits_log (
  logid int(11) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户id',
  type varchar(16) NOT NULL DEFAULT 'credits' COMMENT '积分类型: credits/golds/rmbs',
  `change` int(11) NOT NULL DEFAULT 0 COMMENT '变动值，正为加，负为减',
  balance int(11) NOT NULL DEFAULT 0 COMMENT '变动后余额',
  reason varchar(64) NOT NULL DEFAULT '' COMMENT '变动原因',
  ip int(11) unsigned NOT NULL DEFAULT 0 COMMENT '操作IP',
  create_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (logid),
  KEY idx_uid_date (uid, create_date),
  KEY idx_uid_reason_date (uid, reason, create_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='积分日志';

# 全局积分规则
DROP TABLE IF EXISTS bbs_credits_rule_global;
CREATE TABLE bbs_credits_rule_global (
  ruleid int(11) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(32) NOT NULL DEFAULT '' COMMENT '事件标识',
  label varchar(64) NOT NULL DEFAULT '' COMMENT '事件显示名称',
  credits_change int(11) NOT NULL DEFAULT 0 COMMENT '积分变化值',
  golds_change int(11) NOT NULL DEFAULT 0 COMMENT '金币变化值',
  rmbs_change int(11) NOT NULL DEFAULT 0 COMMENT '人民币变化值',
  enabled tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
  daily_limit INT NOT NULL DEFAULT 0 COMMENT '每日防刷限制次数，0使用全局设置',
  PRIMARY KEY (ruleid),
  UNIQUE KEY event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='全局积分规则';

INSERT INTO bbs_credits_rule_global (event, label, credits_change, golds_change, rmbs_change, enabled) VALUES
('thread_post', '发主题', 0, 0, 0, 1),
('reply_post', '发回复', 0, 0, 0, 1),
('thread_digest', '加精', 0, 0, 0, 1),
('thread_top', '置顶', 0, 0, 0, 1),
('thread_delete', '删主题', 0, 0, 0, 1),
('reply_delete', '删除回复', 0, 0, 0, 1),
('be_liked', '被点赞', 0, 0, 0, 1),
('like', '点赞他人', 0, 0, 0, 1),
('be_commented', '被回复', 0, 0, 0, 1),
('favorite', '收藏', 0, 0, 0, 1),
('be_favorited', '被收藏', 0, 0, 0, 1),
('unlike', '取消点赞', 0, 0, 0, 1),
('unfavorite', '取消收藏', 0, 0, 0, 1);

# 版块积分规则覆盖
DROP TABLE IF EXISTS bbs_credits_rule_forum;
CREATE TABLE bbs_credits_rule_forum (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  fid smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT '版块ID',
  event varchar(32) NOT NULL DEFAULT '' COMMENT '事件标识',
  credits_change int(11) NOT NULL DEFAULT 0 COMMENT '积分变化值',
  golds_change int(11) NOT NULL DEFAULT 0 COMMENT '金币变化值',
  rmbs_change int(11) NOT NULL DEFAULT 0 COMMENT '人民币变化值',
  enabled tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
  daily_limit INT NOT NULL DEFAULT 0 COMMENT '每日防刷限制次数，0使用全局设置',
  PRIMARY KEY (id),
  UNIQUE KEY fid_event (fid, event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='版块积分规则覆盖';

# API 令牌
DROP TABLE IF EXISTS bbs_api_token;
CREATE TABLE bbs_api_token (
  id bigint(16) unsigned NOT NULL AUTO_INCREMENT,
  uid int(11) unsigned NOT NULL DEFAULT 0,
  type enum('access','refresh') NOT NULL DEFAULT 'access' COMMENT '令牌类型',
  related_id bigint(16) unsigned NOT NULL DEFAULT 0 COMMENT '关联令牌ID',
  token char(64) NOT NULL DEFAULT '',
  expires_at int(11) unsigned NOT NULL DEFAULT 0,
  absolute_expires_at int(11) unsigned NOT NULL DEFAULT 0 COMMENT '绝对过期时间戳，0=不限制',
  used tinyint(1) NOT NULL DEFAULT 0 COMMENT 'refresh 是否已用过：0=未用，1=已用',
  created_at int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY token (token),
  KEY uid (uid),
  KEY uid_type (uid, type),
  KEY expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='API令牌';

# API 应用认证
DROP TABLE IF EXISTS bbs_api_app;
CREATE TABLE bbs_api_app (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  appid varchar(32) NOT NULL COMMENT '应用ID',
  secret varchar(64) NOT NULL COMMENT '应用密钥',
  name varchar(100) NOT NULL COMMENT '应用名称',
  description varchar(255) DEFAULT '' COMMENT '应用描述',
  scope varchar(20) NOT NULL DEFAULT 'readonly' COMMENT '权限范围: readonly/readwrite/full',
  is_enabled tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
  uid int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建者UID',
  rate_limit int(11) unsigned NOT NULL DEFAULT 120 COMMENT '每分钟请求上限(0=不限)',
  capabilities text COMMENT '场景级能力开关JSON: skip_captcha/skip_audit/skip_rate_limit/allowed_resources/denied_endpoints',
  ip_whitelist text COMMENT 'IP白名单JSON数组,空=不限,支持CIDR',
  permissions text COMMENT '细粒度权限矩阵JSON: {"thread":"rw","post":"r","admin":"-"}',
  created_at int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (id),
  UNIQUE KEY appid (appid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='API应用表';

# API 日志
DROP TABLE IF EXISTS bbs_api_log;
CREATE TABLE bbs_api_log (
  id bigint(16) unsigned NOT NULL AUTO_INCREMENT,
  resource varchar(32) NOT NULL DEFAULT '',
  method varchar(10) NOT NULL DEFAULT '',
  uid int(11) unsigned NOT NULL DEFAULT 0,
  ip int(11) unsigned NOT NULL DEFAULT 0,
  appid varchar(32) NOT NULL DEFAULT '' COMMENT '应用ID',
  duration int(11) unsigned NOT NULL DEFAULT 0,
  create_date int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY resource_method (resource, method),
  KEY uid (uid),
  KEY create_date (create_date),
  KEY appid (appid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='API日志';

# 帖子点赞（API v1）
DROP TABLE IF EXISTS bbs_thread_like;
CREATE TABLE bbs_thread_like (
  id bigint(16) unsigned NOT NULL AUTO_INCREMENT,
  tid int(11) unsigned NOT NULL DEFAULT 0,
  uid int(11) unsigned NOT NULL DEFAULT 0,
  create_date int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY tid_uid (tid, uid),
  KEY uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='帖子点赞';

# 帖子举报
DROP TABLE IF EXISTS bbs_thread_report;
CREATE TABLE bbs_thread_report (
  id bigint(16) unsigned NOT NULL AUTO_INCREMENT,
  tid int(11) unsigned NOT NULL DEFAULT 0,
  uid int(11) unsigned NOT NULL DEFAULT 0,
  reason varchar(500) NOT NULL DEFAULT '',
  create_date int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY tid (tid),
  KEY uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='帖子举报';

# IP 黑名单
DROP TABLE IF EXISTS bbs_ip_blacklist;
CREATE TABLE bbs_ip_blacklist (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  ip varchar(45) NOT NULL DEFAULT '' COMMENT 'IP地址或CIDR段',
  type tinyint(1) NOT NULL DEFAULT 0 COMMENT '0黑名单/1白名单',
  remark varchar(128) NOT NULL DEFAULT '' COMMENT '备注',
  create_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (id),
  UNIQUE KEY ip (ip),
  KEY type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='IP黑名单';

# 邮箱黑名单
DROP TABLE IF EXISTS bbs_email_blacklist;
CREATE TABLE bbs_email_blacklist (
  id int(11) unsigned NOT NULL AUTO_INCREMENT,
  domain varchar(128) NOT NULL DEFAULT '' COMMENT '邮箱域名',
  remark varchar(128) NOT NULL DEFAULT '' COMMENT '备注',
  create_date int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (id),
  UNIQUE KEY domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='邮箱黑名单';

# 用户组权限
DROP TABLE IF EXISTS bbs_group_permission;
CREATE TABLE bbs_group_permission (
  gid smallint(6) unsigned NOT NULL DEFAULT 0,
  permission_key varchar(64) NOT NULL DEFAULT '',
  value tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (gid, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户组权限';

# 初始化默认权限数据
INSERT INTO bbs_group_permission (gid, permission_key, value) VALUES
(0, 'allowread', 1), (0, 'allowpost', 1), (0, 'allowdown', 1),
(1, 'allowread', 1), (1, 'allowthread', 1), (1, 'allowpost', 1), (1, 'allowattach', 1), (1, 'allowdown', 1), (1, 'allowtop', 1), (1, 'allowupdate', 1), (1, 'allowdelete', 1), (1, 'allowmove', 1), (1, 'allowbanuser', 1), (1, 'allowdeleteuser', 1), (1, 'allowviewip', 1),
(2, 'allowread', 1), (2, 'allowthread', 1), (2, 'allowpost', 1), (2, 'allowattach', 1), (2, 'allowdown', 1), (2, 'allowtop', 1), (2, 'allowupdate', 1), (2, 'allowdelete', 1), (2, 'allowmove', 1), (2, 'allowbanuser', 1), (2, 'allowdeleteuser', 1), (2, 'allowviewip', 1),
(4, 'allowread', 1), (4, 'allowthread', 1), (4, 'allowpost', 1), (4, 'allowattach', 1), (4, 'allowdown', 1), (4, 'allowtop', 1), (4, 'allowupdate', 1), (4, 'allowdelete', 1), (4, 'allowmove', 1), (4, 'allowbanuser', 1), (4, 'allowviewip', 1),
(101, 'allowread', 1), (101, 'allowthread', 1), (101, 'allowpost', 1), (101, 'allowattach', 1), (101, 'allowdown', 1),
(102, 'allowread', 1), (102, 'allowthread', 1), (102, 'allowpost', 1), (102, 'allowattach', 1), (102, 'allowdown', 1),
(103, 'allowread', 1), (103, 'allowthread', 1), (103, 'allowpost', 1), (103, 'allowattach', 1), (103, 'allowdown', 1),
(104, 'allowread', 1), (104, 'allowthread', 1), (104, 'allowpost', 1), (104, 'allowattach', 1), (104, 'allowdown', 1),
(105, 'allowread', 1), (105, 'allowthread', 1), (105, 'allowpost', 1), (105, 'allowattach', 1), (105, 'allowdown', 1);

# 封禁历史记录表
DROP TABLE IF EXISTS bbs_user_ban_log;
CREATE TABLE bbs_user_ban_log (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '被操作用户uid',
  `admin_uid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '操作管理员uid',
  `action` varchar(20) NOT NULL DEFAULT '' COMMENT '操作类型:ban/unban/auto_unban/clear_content',
  `ban_type` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '封禁类型',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因',
  `duration` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '封禁时长(秒),0表示永久',
  `create_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '操作时间戳',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

# IP 黑名单已迁移到 IpBlacklistService（基于 bbs_kv 表的 kv 存储，支持 CIDR/范围/过期时间）
# 废弃的 bbs_banned_ip 表不再在安装时创建；旧站点升级时由 UpgradeService::migrateBannedIpToBlacklist() 自动迁移

# 全文搜索索引（MySQL 5.6+ InnoDB 支持 ngram parser，低版本或不支持时跳过不影响核心功能）
# FULLTEXT_TOLERANT 标记：install_sql_file 遇到失败不中断安装
# FULLTEXT_TOLERANT
CREATE FULLTEXT INDEX ft_subject ON bbs_thread (subject) WITH PARSER ngram;
# FULLTEXT_TOLERANT
CREATE FULLTEXT INDEX ft_message ON bbs_post (message) WITH PARSER ngram;


