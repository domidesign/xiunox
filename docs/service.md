# 业务服务层（service/）

> 8 个 `*Service.php` 业务领域服务类，封装跨表/跨实体的业务逻辑（与 lib/ 基础工具服务区分）

## 目录结构概览

```
service/
├── AttachmentService.php       # 附件上传与管理
├── CreditsRuleService.php      # 积分规则查询与应用
├── ForumService.php            # 版块管理与权限
├── NotificationService.php     # 用户通知收发
├── PostService.php             # 回复（post）管理
├── RankService.php             # 各类排行榜
├── ThreadService.php           # 帖子（thread）管理与互动
└── UserService.php             # 用户账户与元数据
```

## 文件用途说明

### AttachmentService.php
- **用途**：`AttachmentService` 附件统一上传服务类，支持 FormData 文件上传、图片缩略图生成、视频信息提取
- **关键方法**：
  - `::getInstance()` — 静态工厂方法，返回服务实例
  - `uploadImage($file, $uid, $options)` — 图片上传，含缩略图生成，结果保存到 session
  - `uploadVideo($file, $uid, $options)` — 视频上传，提取宽高与时长信息
  - `uploadFile($file, $uid, $options)` — 通用文件上传（自动识别图片/视频/其他）
  - `upload($file, $options)` — 兼容旧接口的通用上传入口（支持 driver 选项）
  - `::getMaxSize($filetype)` — 获取指定类型文件的最大上传字节数
  - `::setMaxSize($filetype, $bytes)` — 设置指定类型文件的大小上限
  - `::getAllowedTypes($category)` — 获取允许的文件扩展名列表（image/video/file）
  - `::formatSize($bytes)` — 将字节数格式化为人类可读字符串
  - `::validateFileType($filename, $filetypes)` — 校验文件扩展名是否允许上传
  - `::validateFileSize($size, $filetype)` — 校验文件大小是否在限制内
  - `::generateThumbnail($srcPath, $filetypes, $maxWidth, $maxHeight)` — 生成图片缩略图（支持 PNG/GIF 透明通道）
  - `::getVideoInfo($filepath)` — 通过 ffprobe 或 getID3 获取视频宽高与时长
  - `getAttachmentById($aid)` — 根据附件 ID 获取附件信息
  - `deleteAttachment($aid)` — 删除附件（同时删除物理文件）

### CreditsRuleService.php
- **用途**：`CreditsRuleService` 积分规则服务，负责积分规则的查询、版块覆盖、批量应用与插件钩子扩展
- **关键方法**：
  - `::getRule($event, $fid, $uid, $source)` — 获取积分规则（优先版块规则，回退全局规则，含缓存与防重入）
  - `::applyRule($event, $uid, $fid, $checkOnly, $source)` — 应用积分规则（自动 add/sub，含 MySQL 应用级锁与每日限制检查）
  - `::applyRuleBatch($event, $uid_fid_pairs)` — 批量应用积分规则（预加载规则消除 N+1 查询）
  - `::applyRuleDeductOnly($event, $uid, $fid)` — 仅执行规则的扣减部分，用于审核场景
  - `::applyRewardOnly($event, $uid, $fid)` — 仅执行规则的奖励部分，用于审核通过后补发
  - `::getAllGlobalRules()` — 获取所有全局积分规则
  - `::getForumRules($fid)` — 获取指定版块的积分规则列表
  - `::saveGlobalRules($rules)` — 批量保存全局规则（含范围 clamp 校验）
  - `::saveForumRules($fid, $rules)` — 批量保存版块规则（不存在则插入）
  - `::deleteForumRule($fid, $event)` — 删除指定版块的指定事件规则
  - `::registerHook($hookName, $callback)` — 注册积分规则插件钩子（如 credits_rule_get_before）

### ForumService.php
- **用途**：`ForumService` 版块服务类，封装版块 CRUD、权限校验、树形结构构建与关注关系
- **关键方法**：
  - `getForumById($fid)` — 根据版块 ID 获取版块信息
  - `createForum($data)` — 创建版块（校验 name 必填）
  - `updateForum($fid, $data)` — 更新版块字段
  - `deleteForum($fid)` — 删除版块
  - `getForumList($cond, $orderby, $page, $pagesize)` — 获取版块列表（默认按 rank 排序）
  - `checkAccess($fid, $gid, $perm)` — 校验用户组对版块的访问权限（accesson 关闭时直接放行）
  - `getForumsByIds($fids)` — 批量根据 ID 获取多个版块
  - `getForumTree()` — 构建版块父子树形结构（含最后发帖、版主列表、图标识别）
  - `followForum($uid, $fid)` — 关注版块（清缓存、更新 follows 计数）
  - `unfollowForum($uid, $fid)` — 取消关注版块
  - `isFollowed($uid, $fid)` — 检查用户是否已关注某版块
  - `checkFollowBatch($uid, $fids)` — 批量检查用户对多个版块的关注状态

### NotificationService.php
- **用途**：`NotificationService` 通知服务类，封装用户通知的发送、查询与已读状态管理
- **关键方法**：
  - `send($uid, $type, $data)` — 向用户发送一条通知（reply/mention/system 等类型）
  - `getUnreadCount($uid)` — 获取用户的未读通知数量
  - `markAsRead($id)` — 将指定通知标记为已读
  - `markAllAsRead($uid)` — 将用户的所有未读通知标记为已读
  - `getList($uid, $page, $pagesize)` — 分页获取用户的通知列表（按 id 倒序）

### PostService.php
- **用途**：`PostService` 回复服务类，封装 post 表的 CRUD、按帖子/用户查询与批量删除
- **关键方法**：
  - `getPostById($pid)` — 根据回复 ID 获取回复
  - `createPost($data)` — 创建回复（校验 tid、uid 必填）
  - `updatePost($pid, $data)` — 更新回复字段
  - `deletePost($pid)` — 删除单条回复
  - `getPostListByTid($tid, $page, $pagesize)` — 获取指定帖子下的回复列表
  - `getPostListByUid($uid, $page, $pagesize)` — 获取用户发布的所有回复列表
  - `getPostList($page, $pagesize)` — 获取全站回复列表
  - `getPostCountByUid($uid)` — 获取用户的回帖总数
  - `batchDelete($pids)` — 批量删除回复

### RankService.php
- **用途**：`RankService` 排行榜服务类，提供热帖排行、活跃用户排行、积分排行（结果缓存 5 分钟）
- **关键方法**：
  - `getHotThreads($period, $page, $pageSize, $isAdmin)` — 获取热帖排行（按 views+posts 综合得分，含版块权限过滤与审核状态区分）
  - `getActiveUsers($period, $page, $pageSize)` — 获取活跃用户排行（按 threads+posts 总数降序）
  - `getCreditsRanking($page, $pageSize)` — 获取积分排行（按用户 credits 降序，含金币/发帖数）

### ThreadService.php
- **用途**：`ThreadService` 帖子服务类，封装 thread 表 CRUD、点赞、收藏、举报与批量管理
- **关键方法**：
  - `getThreadById($tid)` — 根据帖子 ID 获取帖子
  - `createThread($data)` — 创建帖子（校验 fid、subject、uid 必填）
  - `updateThread($tid, $data)` — 更新帖子字段
  - `deleteThread($tid)` — 删除单条帖子
  - `getThreadList($cond, $orderby, $page, $pagesize)` — 获取帖子列表（默认按 tid 倒序）
  - `setType($tid, $type)` — 设置帖子类型
  - `setMeta($tid, $key, $value)` — 设置帖子元数据（thread_meta，存在则更新）
  - `getMeta($tid, $key)` — 获取帖子元数据
  - `likeThread($tid, $uid)` — 点赞帖子（已点赞则幂等返回）
  - `unlikeThread($tid, $uid)` — 取消点赞
  - `isLiked($tid, $uid)` — 检查用户是否已点赞该帖
  - `favoriteThread($tid, $uid)` — 收藏帖子（已收藏则幂等返回）
  - `unfavoriteThread($tid, $uid)` — 取消收藏
  - `isFavorited($tid, $uid)` — 检查用户是否已收藏该帖
  - `reportThread($tid, $uid, $reason)` — 举报帖子
  - `batchDelete($tids)` — 批量删除帖子
  - `batchUpdate($tids, $data)` — 批量更新帖子（仅允许 top/closed/type 字段）
  - `getThreadsByUid($uid, $page, $pagesize)` — 获取用户发布的帖子列表
  - `getFavoritesByUid($uid, $page, $pagesize)` — 获取用户收藏的帖子列表
  - `getFavoriteCount($uid)` — 获取用户的收藏总数
  - `getThreadCountByUid($uid)` — 获取用户的发帖总数
  - `getThreadsByIds($tids)` — 批量根据 ID 获取多个帖子

### UserService.php
- **用途**：`UserService` 用户服务类，封装用户账户 CRUD、密码验证与升级、用户元数据管理
- **关键方法**：
  - `getUserById($uid)` — 根据用户 ID 获取用户信息
  - `getUserByEmail($email)` — 根据邮箱获取用户
  - `getUserByUsername($username)` — 根据用户名获取用户
  - `createUser($data)` — 创建用户（校验 email、username、password 必填，默认 gid=101）
  - `getUserList($page, $pagesize)` — 获取用户列表（按 uid 倒序）
  - `getUserCount()` — 获取用户总数
  - `updateUser($uid, $data)` — 更新用户字段
  - `deleteUser($uid)` — 删除用户
  - `verifyPassword($password, $user)` — 验证密码（优先 bcrypt，兼容旧 MD5+salt，验证成功后自动升级哈希）
  - `upgradePasswordHash($uid, $password)` — 将用户密码哈希升级为 bcrypt 并清空旧字段
  - `setMeta($uid, $key, $value)` — 设置用户元数据（user_meta，存在则更新）
  - `getMeta($uid, $key)` — 获取用户元数据
  - `getUsersByIds($uids)` — 批量根据 ID 获取多个用户
