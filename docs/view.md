# 前台视图与静态资源（view/）

> 论坛前台 HTML 模板、CSS、JS、图片资源
>
> 注：
> - `view/vendor/` 为第三方前端库（animejs/bootstrap/chartjs/cropperjs/highlightjs/htmx/qrcodejs/tabler-icons），不在本文档展开。
> - `view/js/aieditor/` 为第三方 AI 编辑器组件，不在本文档展开。
> - `view/js/jquery-3.7.1.min.js` 与 `view/js/jquery.qrcode.min.js` 为第三方 jQuery 库，不在本文档展开。

## 目录结构概览

```
view/
├── htm/            # 前台 HTML 模板（约 73 个 .htm，按业务分组见下文）
├── css/            # 样式表（2 个文件：bootstrap-bbs.css、theme.css）
├── js/             # 自有脚本（11 个）+ 第三方组件（aieditor/、jquery-*.js）
├── img/            # 图片资源（含 avatars/ 默认头像 12 张）
└── vendor/         # 第三方前端库（不展开）
```

## 文件用途说明

### view/htm/ 模板（按业务分组）

#### 用户类
用户主页及账户相关页面，含登录注册、找回密码、用户帖子/收藏/关注/粉丝列表及 AI 设置。
- 文件：user.htm, user_login.htm, user_create.htm, user_resetpw.htm, user_resetpw_complete.htm, user_thread.htm, user_post.htm, user_favorite.htm, user_followers.htm, user_following.htm, user_like.htm, user_info_card.inc.htm, user_ai_setting.htm, user.common.template.htm, user.template.htm, user_thread.template.htm

#### 个人中心类
当前登录用户的个人中心，涵盖资料、头像、密码、安全、帖子、收藏、动态、通知、关注/粉丝、AI 等管理页面。
- 文件：my.htm, my_avatar.htm, my_avatar_page.htm, my_password.htm, my_profile.htm, my_security.htm, my_thread.htm, my_post.htm, my_favorite.htm, my_feed.htm, my_like.htm, my_notify.htm, my_followers.htm, my_following.htm, my_ai.htm, my.layout.inc.htm, my.common.template.htm, my.template.htm, my_favorite.template.htm, my_feed.template.htm, my_notify.template.htm, my_thread.template.htm

#### 帖子类
帖子详情、回复及帖子列表渲染。
- 文件：thread.htm, post.htm, post_list.inc.htm, thread_list.inc.htm, thread_list_mod.inc.htm

#### 版块类
版块主页与版块成员、关注者展示。
- 文件：forum.htm, forum_index.htm, forum_members_block.htm, forum_followers.htm

#### 管理操作类
版主对帖子/版块的管理操作表单（公告、审核、关闭、删除、精华、移动、置顶）。
- 文件：mod_announcement.htm, mod_audit.htm, mod_close.htm, mod_delete.htm, mod_digest.htm, mod_move.htm, mod_top.htm

#### 功能类
排行榜、搜索、浏览器兼容提示、积分及积分规则、更多、主题切换等功能页面。
- 文件：rank.htm, search.htm, browser.htm, credits.htm, credits_rules.htm, credits_rules_table.inc.htm, more.htm, theme.htm

#### 通用组件
页面公共布局与可复用片段：头部/底部导航、底部标签栏、浮动操作按钮、左右侧栏、用户卡片、分页及帖子/版块列表片段。
- 文件：header.inc.htm, header_nav.inc.htm, footer.inc.htm, footer_nav.inc.htm, bottom_nav.inc.htm, floating_action.inc.htm, sidebar_left.inc.htm, sidebar_right.inc.htm, sidebar_user_card.inc.htm, pagination.inc.htm, pagination_infinite.inc.htm, thread_list_mod.inc.htm, post_list.inc.htm, forum_members_block.htm

#### 页面
站点首页、全局消息提示页与错误页。
- 文件：index.htm, message.htm, error.htm

### view/css/

#### bootstrap-bbs.css
- **用途**：论坛基于 Bootstrap 的业务样式覆盖与组件定制（如卡片、公告徽标、aie 编辑器内容区等）。

#### theme.css
- **用途**：主题色与明暗模式变量定义，通过 `data-theme`/`data-bs-theme` 属性切换站点主题色方案（蓝/绿等多套）。

### view/js/ 自有脚本

#### async.js
- **用途**：异步流程控制工具库（caolan/async），提供并行、串行、瀑布等任务编排能力。

#### attach_manage.js
- **用途**：附件管理后台页面脚本，处理附件单删与批量清理孤儿附件的确认弹窗及提交（基于 xiuno-modern.js，不依赖 jQuery）。

#### bbs.js
- **用途**：论坛前端通用脚本，含 Bootstrap 5 jQuery 插件桥接、导航高亮、验证码发送倒计时、附件删除/下载等通用交互。

#### bootstrap-plugin.js
- **用途**：基于 Bootstrap 5 Modal 的 `$.alert`/`$.confirm` 弹窗辅助方法及已加载脚本/样式查询工具。

#### color-utils.js
- **用途**：颜色工具函数，提供 HEX/HSL/RGB 互转、相对亮度计算与色阶生成，服务于主题色定制。

#### form.js
- **用途**：表单元素生成工具，封装 `xn.form_radio`/`form_select`/`form_options` 等 PHP 风格的表单 HTML 生成函数。

#### tabler-icon-picker.js
- **用途**：Tabler 图标选择器组件（原生 JS + Bootstrap 5 Modal），弹出图标网格供搜索选择并回填输入框。

#### upload-service.js
- **用途**：统一上传模块 `UploadService`，提供 FormData 上传、进度追踪、拖拽/粘贴上传、图片预览与文件类型/大小校验。

#### upload.js
- **用途**：旧版 `FileUploader` 上传组件（已废弃，由 upload-service.js 替代），仅为向后兼容保留。

#### xiuno-modern.js
- **用途**：原生 JS 兼容层（`XN` 命名空间），渐进式替代 jQuery，提供 DOM 选择器、AJAX、CSRF、toast 等现代 API。

#### xiuno.js
- **用途**：核心工具库（`xn` 命名空间），模拟 PHP 常用函数（`htmlspecialchars`/`intval`/数组操作等）及浏览器检测、上传等基础能力。

### view/img/
- avatars/ — 默认头像目录，共 12 张 `avatar1~12.webp` 格式头像。
- app.png — 移动端/App 相关配图。
- avatar.png — 默认头像占位图。
- browser.gif — 浏览器兼容提示用动图。
- favicon.ico — 站点图标（ICO 格式）。
- favicon.png — 站点图标（PNG 格式）。
- forum.png — 版块默认封面/图标。
- logo.png — 站点 Logo。
- water-small.png — 图片上传水印素材。

### view/vendor/ 第三方前端库（不展开）
- animejs/ — 动画库
- bootstrap/ — CSS 框架（含 css/js）
- chartjs/ — 图表库
- cropperjs/ — 图片裁剪库
- highlightjs/ — 代码高亮库（含 styles/）
- htmx/ — AJAX 库（含 ext/ 扩展：hx-live、hx-optimistic）
- qrcodejs/ — 二维码生成库
- tabler-icons/ — 图标字体库（含 fonts/ 与 css）
