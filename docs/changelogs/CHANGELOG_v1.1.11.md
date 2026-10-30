# v1.1.11 更新日志 - 2026-10-30

> **版本说明**: 附件越权与付费包直链泄露应急响应（安全大版本）+ 前台审核队列 + 数据计数全量校准 + API 安全基线 —— `GET /attach/{aid}` 补全 Bearer Token 与所有者/管理员鉴权并改字段白名单响应；新增私有附件存储（Web 根外 0700 目录，压缩包/文档类不再可被静态直链）；附件下载收敛为统一服务（限流 + Range 断点续传）；新增 HiddenContentGuard / ApiContentService 封堵隐藏内容在搜索摘要、SEO meta 等 6 个出口的泄露；API 全局接入安全审计（X-Request-Id + security JSONL）与拒绝惩罚限流，RateLimitService 重写为原子滑动窗口（fail-closed）；前台新增版主审核队列页（待审主题/回帖双 tab，审核通知链接按浏览者权限跳转）；后台新增"重建计数"一键校准版块/用户/点赞/收藏/精华全部冗余统计；修复缓存驱动切换后设置弹回旧值、搜索结果存储型 XSS、JSON-LD `</script>` 逃逸、SQLite 下 API 日志筛选恒空等高危问题。详见 `docs/security/attach-disclosure-20261029.md` 与 `docs/security/online-runbook-20261029.md`。

## 🔒 安全加固：附件越权与隐藏内容泄露应急响应（2026-10-29）

### 附件 API 越权（IDOR，CWE-639 / CWE-538，高危）

- **鉴权补全** `api/v1/attach.php`：`GET /attach/{aid}` 此前仅校验 API 应用凭据（AppID）即可返回附件行（含 filename 存储路径），已捕获攻击者枚举 aid 批量拉取元数据后直链下载付费应用包；现强制 Bearer Token，且仅附件所有者与管理员（gid=1/2）可查；DELETE 同步收敛

- **字段白名单**：元数据响应仅返回 aid/tid/pid/uid/create\_date/orgfilename/filetype/filesize/width/height/isimage/downloads/duration，禁止返回 filename/path/url/thumb\_file/driver 等存储字段；"不存在"与"无权"统一 404，不泄露附件存在性；响应头 `private, no-store`

- **文档同步** `docs/api/endpoints/attach.md`：鉴权要求、响应字段、错误码（新增 401）与安全公告交叉链接

### 私有附件存储（新增 lib/PrivateAttachmentStorage.php）

- **Web 根外存储**：非图片附件（压缩包/文档等）不再落入可静态直链的 `upload/attach/`，改存 Web 根外同级私有目录（0700），路径可经环境变量 `XIUNOX_PRIVATE_PATH` 或 `conf/private_storage.php` 配置；强制绝对路径、拒绝 Web 根内、拒绝 `..` 穿越

- **全链路接入**：上传临时文件与 `.meta.json`、发帖关联（attach\_assoc\_post）、attach\_create/delete/delete\_by\_pid/delete\_by\_uid 全部改走统一存取入口；私有附件前台 URL 由 `attach_format()` 改写为 `attach-read-{aid}-private` 授权读取，保存帖子时重写正文中的直链 URL

- **云端兼容**：恢复 `route/attach.php` 的 `storage_serve.php` hook 占位（置于授权检查之后，防 S3 驱动 404，也防 302 绕权）

### 附件下载服务收敛（新增 lib/AttachmentAccessService.php + AttachmentDownloadService.php）

- **统一访问判定**：封禁用户（禁言/锁定）一律拒绝；应用中心付费包须已购买才可下载；metadata/delete 操作限所有者与管理员

- **下载服务**：原 `route/attach.php` 内联 200+ 行 read/download 逻辑收敛为 `AttachmentDownloadService::serve()`——权限断言 + 限流（30 次/60s，429 带 Retry-After）+ HTTP Range 断点续传 + MIME/缓存头，签名 URL 校验与防盗链保留

### 隐藏内容泄露出口封堵（新增 lib/HiddenContentGuard.php + ApiContentService.php）

- **fail-closed 扫描器**：`[hidden]` BBCode（含嵌套、未闭合、HTML 实体编码变体）一律输出 `[Content locked]`，不解码可见 HTML 为可执行标记

- **泄露出口封堵**：搜索结果摘要（`route/search.php`）、SEO meta 等核心出口按查看者权限过滤后再截取；moments 卡片、fcomment 侧栏缓存、AI 外发 LLM 等出口由配套插件版本统一走 HiddenContentGuard 封堵（受影响插件 6 个，见披露文档）

- **API 响应按浏览者序列化** `lib/ApiContentService.php`：帖子响应经版块读权（forum\_access\_user allowread）+ 审核状态过滤，字段白名单输出；`ApiResponse` 全局接入过滤并统一 `no-store`，绝不落入共享缓存

### API 全局安全基线（新增 lib/SecurityAuditService.php）

- 每请求生成 `X-Request-Id`，关闭时落 `security-*.jsonl` 审计事件（状态码/uid/URI）；401/403/404 高频拒绝触发惩罚限流（60 次/300s，超限直接 429）

### 其他安全修复

- **改邮箱/改密验密** `api/v1/user.php`：PUT /user/{uid} 修改邮箱或密码须验证当前密码

- **版块越权** `api/v1/mod.php` / `route/mod.php`：移动主题的目标版块须版主管辖权（allowmove）+ 可读（allowread），下拉只列出有权移入的版块，防 URL 直传越权移入

- **不可读内容统一 404**：无版块读权的 thread/hot、点赞、收藏、举报返回 404 而非暴露存在性

- **搜索存储型 XSS** `route/search.php`：标题高亮此前在未转义 subject 上替换，存储型 HTML 可直接进搜索结果页；现先 esc\_html 再打 `<mark>`，并顺带剥离摘要中的 BBCode 标签

- **JSON-LD 逃逸** `view/htm/header.inc.htm`：`json_encode` 增加 JSON\_HEX\_TAG 等 flag，防数据中 `</script>` 逃逸出 ld+json 上下文

- **上传临时文件归属校验** `api/v1/thread.php`/`post.php`：tmp key 格式白名单 + meta.json 中 uid 归属校验，防越权关联他人暂存附件

## 🆕 新功能

### 前台审核队列（mod-auditlist）

- **新页面** `route/mod.php` + `view/htm/mod_auditlist.htm`：管理员/超版审全站待审内容，版主仅审管辖版块（forum.moduids）；待审主题/待审回帖双 tab 带计数；忽略状态(3)不进前台队列（与"作者无感知"语义一致），后台审核页仍可处理

- **入口**：个人中心、顶部导航、帖子页左侧操作栏、个人页侧栏按权限显示"审核队列"入口（无权限不渲染）

- **审核通知链接按浏览者权限跳转** `lib/security/AuditService.php::entry_url_for_viewer()`：通知入库不再写死后台地址——管理员/超版/有管辖版块的版主落前台审核队列，资料审核仅管理员给后台链接，无权限返回空；存量写死后台地址的旧通知渲染时同样重定向（修复版主点击通知被踢到登录页）

- **批量"忽略"**：批量/单条审核新增 audit\_status=3 忽略操作（内容保持不可见、作者无感知），复用 mod-audit / mod-audit\_post 端点并逐条重新校验管辖权

- **待审回帖反查修复**：post 表无 fid，改为取最近待审回帖批量映射主题 fid 后按管辖版块过滤（原实现只从待审主题出发查回帖，漏掉已通过主题下的待审回帖，前台回帖 tab 恒空）

- **待审列表引用摘要**：AuditService 批量补 quote\_name / quote\_summary（引用楼层作者与 60 字摘要），审批时无需点开原帖

### 后台"重建计数"（admin forum recount）

- `admin/route/forum.php` 新增 recount 动作 + `forum_list.htm` 操作按钮：一键按"审核通过且未软删"口径重算各版块 threads、全站 threads/posts/users/digests、user.threads/posts/digests、thread.posts、thread.likes/favorites、post.likes，并对齐首帖 audit\_status（修复历史版本主题通过时未同步首帖审核状态导致楼主正文对普通用户隐藏）；校准历史漂移（批量删除二次扣减、硬删不清点赞行等遗留）；重算后失效用户缓存

### 在线升级版本说明面板

- `admin/view/htm/online_upgrade.htm` + `lib/OnlineUpgradeService.php`：升级页新增"版本说明"折叠面板，纯前端极简 markdown 渲染（标题/列表/行内代码/加粗/链接/围栏与缩进代码块，先转义后解析防注入，不引第三方库），覆盖 Gitee release body 常用语法

### API 日志筛选修复（SQLite 兼容）

- `api/v1/admin.php`：日志列表/计数此前用 `:named` 占位符 + `$db->query()`，SQLite/部分驱动下参数不绑定导致筛选恒空；改为 `?` 占位符 + `db_sql_find_prepared`/`db_sql_find_one_prepared`

## 🐛 修复

### 缓存驱动切换后设置"弹回旧值"

- `lib/CacheService.php`：后台切换缓存驱动后，旧驱动实例中的 setting 副本成为永久陈旧数据（下一请求 earlyInit 仍用旧驱动起步），表现为设置开关保存后弹回、前台显隐不生效；现切换时清理旧实例 setting 键并重置 kv 内存态，且 cache 配置同步写回 `conf/conf.php` 的 cache 段（驱动类型白名单校验，写失败不影响运行时以 kv 为准），消除每请求"conf 驱动起步 → 后台驱动重建"的无谓分裂

### 数据一致性（第三/四轮全量审计 2026-10-30）

- **移动主题计数守卫** `model/thread.func.php`：仅"审核通过且未软删"的帖子移动时增减版块 threads（待审/软删帖建帖时未计入）；精华表 thread\_digest.fid 快照随移动同步（防此后硬删按旧 fid 递减错误版块）

- **硬删孤儿数据清理**：单条/批量硬删主题清理 post\_like、thread\_top（置顶表此前漏删成孤儿行）、thread\_digest 行并按 uid/fid 聚合递减统计（已软删主题的精华统计软删时已减，防二次扣减）

- **回帖硬删清理点赞行**并递减 thread.likes；软删/恢复保留点赞行天然对称

- **父主题软删时禁止单独恢复回帖**：防主题恢复时同一回帖计数 +2

- **post\_delete\_by\_tid 重构** `model/post.func.php`：旧实现一次性拉全表（pagesize=1000000，SELECT \* 含 longtext），数万回帖时内存爆；且 post\_\_find 默认过滤 is\_deleted=0，回收站里软删过的回帖附件永久残留；改为 db\_find 每批 500 行精简列扫描，含附件 pid 分批清理，回帖本体按 KEY(tid,pid) 范围一次 DELETE，统计按审核/软删状态精确增减

- **彻底删除用户清理点赞/收藏** `model/user.func.php`：删除 post\_like / thread\_favorite 行并逐帖递减 thread.likes/favorites（此前行残留致计数虚高）；新增 `user_count_real()`（排除 deleted\_* 占位用户）供重建计数

- **删除附件重算楼层计数** `model/attach.func.php`：前台删附件后重算该楼层 images/videos/files 并同步首帖所在主题（与写时重算口径一致，修复图标/徽章残留）

- **用户主题列表计数口径** `route/my.php`：游客视角含待审帖（audit\_status 条件区分本人/他人视角）

### 实习版主（gid=5）可见性补全

- `model/forum_access.func.php`、`post_list`/`thread_list_mod` 等多处 `gid < 5` → `gid < 6`：实习版主可见待审标识、审核入口与版主操作栏；版主操作栏同时按 forum\_access\_mod(allowtop) 实际管辖权显隐

### 帖子详情左侧操作栏分页一致性

- `view/htm/thread_left.inc.htm`：page>1 时同样渲染左侧操作栏（此前非首页回退为普通侧栏）

### 通知卡片 URL 转义

- `view/htm/my_notify.htm` 等：通知链接 href 补 esc\_attr

## 🏗️ 架构/重构

### RateLimitService 重写（原子滑动窗口）

- `lib/RateLimitService.php`：由 Redis 检测 + 文件双实现收敛为单一原子滑动窗口（文件锁 + 时间戳队列）；**计数器不可用时拒绝而非静默放行（fail-closed）**；新增 `getClientKey()` / `getRetryAfter()`；目录权限收紧 0700

### API 限流体系升级 `api/v1/bootstrap.php`

- 应用级限流改为按应用自身 `rate_limit` 配置（默认 120/min，后台可设，skip\_rate\_limit 能力豁免），响应带 X-RateLimit-Limit/Remaining/Reset 与 Retry-After；新增登录用户级 120/min（`api_user:{uid}`）；配合安全审计拒绝惩罚三层叠加

### 应用凭据与资源白名单 `lib/ApiAuthService.php`

- 公共限流按应用配置执行；createApp 支持自定义 rate\_limit；scope 为空时的资源白名单扩展为全核心资源 + PluginApiRegistry 注册的插件资源

### xiunophp 框架层加固

- `xiunophp/db.func.php`：新增 `xn_like_escape()`（LIKE 通配符 \\ \_ % 转义，\_/\% 不转义会扩大匹配且让前缀索引失效近似全表扫）；`cache_mysql` 前缀删除的 LIKE 拼接同步修正转义顺序；db\_orderby / db\_max 的字段名白名单校验（仅放行标识符/表.字段，防标识符注入）

- `xiunophp/array.func.php` 等：addslashes/stripslashes/trim/str\_replace 传参 `(string)` 强转，消除 PHP 8.1+ null 弃用告警

- `xiunophp/db_pdo_sqlite.class.php`：find/find\_one 等查询统一走 prepared 语句路径，与 MySQL 驱动行为对齐

- `model/plugin.func.php`：插件编译产物加 `plugin-compile: <dir>` 标记注释；hook 点规范化移除冗余 strpos 前置判断

- `model/route.func.php`：新增 mod\_auditlist / mod\_auditlist\_page 路由别名

## 🎨 UI/UX

- **审核队列入口图标**：shield-check 图标 + 胶囊按钮，管理中心分组（management\_center）

- **取关文案**：unfollow "取消关注/取消關注" → "取消"（followed\_short）

- **static\_version** 1.4.22 → 1.4.23（静态资源缓存刷新）

- 多语言：zh-cn / zh-tw / en-us 新增审核队列、重建计数、版本说明、忽略等 20+ 条文案

## 📚 文档

- **新增** `docs/security/attach-disclosure-20261029.md`：附件元数据越权与压缩包直链泄露的成因、影响面（含已捕获的攻击 AppID）、两轮修复清单与受影响插件版本

- **新增** `docs/security/online-runbook-20261029.md`：线上部署 Runbook——先封直链 → 部署代码（75 文件 + 6 插件，清 tmp 编译缓存 + 重启 php-fpm）→ 私有存储迁移（dry-run → apply）→ 13 项验收清单 → 日志取证与回滚预案

- `docs/install.md`：putenv 从"必需"降为"可选"；新增"CLI ≠ Web/FPM SAPI"验证警告与 check\_env.php Web 探针说明

- `docs/xiunox-plugin-dev/references/ai-rules.md`：新增积分类型名称必须动态读 `$conf['credits_name']/credits_types` 规则（禁止插件硬编码 lang 键）

- 仓库文档文件权限位统一（644 → 755），无内容变化

## 📦 升级说明

- **必须按 Runbook 顺序部署**：先在 Nginx/Apache 封锁 `upload/(attach|tmp)/` 下压缩包直链与 tmp JSON（403），再上传代码、清 `tmp/*` 编译缓存并重启 php-fpm，最后执行私有存储迁移（工具用法见 Runbook），验收通过前保持 API 关闭

- 已上传的历史压缩包类附件需跑迁移工具移入私有存储（幂等，SHA-256 校验）

- 受影响插件须同步升级（xnx\_appcenter 1.15.40 等 6 个，清单见披露文档）

- 无数据库 schema 变更；升级后建议后台执行一次"重建计数"校准历史统计漂移
