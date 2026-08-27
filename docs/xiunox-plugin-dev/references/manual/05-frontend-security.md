# 05 前端规范与安全

> 源码：`view/htm/header.inc.htm`、`view/htm/footer.inc.htm`、`view/js/xiuno-modern.js`、`lib/CsrfService.php`、`lib/EscapeService.php`、`AGENTS.md`

---

## 1. 技术栈与加载顺序

### 前台 CSS 加载顺序（header.inc.htm）

```
1. vendor/bootstrap/css/bootstrap.min.css     ← Bootstrap 5.3+
2. css/bootstrap-bbs.css                       ← BBS 覆盖样式
3. css/theme.css                                ← 主题色
4. vendor/tabler-icons/tabler-icons.min.css    ← Tabler Icons
5. <!--{hook header_link_after.htm}-->          ← ✅ 插件 CSS 注入点
```

### 前台 JS 加载顺序

header.inc.htm（head 尾部）与 footer.inc.htm（footer_js_before.htm 之后）依次加载：

```
 1. vendor/htmx/htmx.min.js                       ← htmx 4 核心（header）
 2. vendor/htmx/ext/hx-live.min.js                ← hx-live 扩展（header）
 3. vendor/htmx/ext/hx-optimistic.min.js          ← hx-optimistic 扩展（header）
 4. vendor/animejs/anime.umd.min.js               ← anime.js 动画（header）
 5. lang/{conf['lang']}/bbs.js                    ← 语言包 JSON（footer）
 6. vendor/bootstrap/js/bootstrap.bundle.min.js   ← Bootstrap JS
 7. view/js/xiuno-modern.js                       ← ✅ 现代兼容层（XN.* API）
 8. 内联全局变量（debug, url_rewrite_on, forumarr, uid, gid, bbs_lang, ...）
 9. view/js/bbs.js                               ← BBS 业务逻辑
10. <!--{hook footer_js_after.htm}-->             ← ✅ 插件 JS 注入点
11. view/js/auto-save.js                          ← 表单自动保存（草稿箱）
12. view/js/lightbox.js                           ← 全局图片放大（Bootstrap Modal + 原生 JS）
13. Flash toast 读取 PRG cookie 后显示并清除         ← footer 内联脚本
14. cron_run()                                    ← 后台计划任务触发（footer 末尾）
```

> ⚠️ **jQuery 已于 2026-07-24 系统性移除**，所有页面禁止使用 `$`/`jQuery`/`$.fn.*`。用 `xiuno-modern.js` 的 `XN.*` API、htmx 4 属性或原生 JS（`fetch`/`querySelectorAll`/`addEventListener`）。迁移指南见 [10-jquery-removal-guide.md](10-jquery-removal-guide.md)。

### 后台不使用 htmx

> 后台（`admin/`）**不使用 htmx**，仅使用原生 JS + Bootstrap。后台模板 hook 前缀为 `admin_*`。

---

### 1.5 静态资源版本号规范

插件 JS/CSS 必须带版本号，避免浏览器缓存旧文件。三种方式按推荐程度排序：

| 方式 | 适用场景 | 代码示例 |
|---|---|---|
| **`filemtime()` 动态版本号** | 推荐，自动跟随文件修改时间 | `filemtime(APP_PATH.'plugin/xxx/static/js/app.js')` |
| **`$static_version`** | Hook 文件（`header_link_after.htm`/`footer_js_after.htm`） | `$static_version`（已在 `header.inc.htm` 第 47 行定义） |
| **`$conf['static_version']`** | 独立视图文件（`view/htm/*.htm`） | 直接取配置值 |

**正确写法**：

```php
// 方式 A：filemtime() 动态版本号（最推荐）
<script src="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/js/app.js?v=<?php echo filemtime(APP_PATH.'plugin/my_plugin/static/js/app.js');?>"></script>

// 方式 B：Hook 文件中用 $static_version
<!-- 在 header_link_after.htm 中 -->
<link href="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/css/style.css<?php echo $static_version;?>">

// 方式 C：视图文件中用 $conf['static_version']
<!-- 在 view/htm/setting.htm 中 -->
<script src="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/js/app.js<?php echo $conf['static_version'];?>"></script>
```

**禁止写法**：

```php
// ❌ 无版本号（浏览器缓存旧文件）
<script src="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/js/app.js"></script>

// ❌ 硬编码版本号（修改后需手动维护）
<link href="...style.css?v=1.0.0">

// ❌ 用 APP_PATH（扫描器 fatal 拦截）
<script src="<?php echo APP_PATH;?>plugin/my_plugin/static/js/app.js">
```

> 速查版见 [../ui-patterns.md](../ui-patterns.md) 第 1 节

---

## 2. xiuno-modern.js API（`XN.*`）

全局 `XN` 对象，定义于 `view/js/xiuno-modern.js`。插件 JS 应优先使用这些 API。

### XN.toast — 提示消息

```js
XN.toast('操作成功', 'success');   // 绿色
XN.toast('出错了', 'danger');       // 红色
XN.toast('请注意', 'warning');     // 黄色
XN.toast('信息', 'info');          // 蓝色
XN.toast('3秒消失', 'success', 3000);  // 自定义时长
```

使用 Bootstrap 5 Toast，自动创建 `.toast-container` 和 Toast 元素，`hidden.bs.toast` 时移除 DOM。

### XN.ajax — AJAX 请求

```js
XN.ajax('POST', '/plugin/my_plugin/api.php?action=save', { data: 'value' })
    .then(result => {
        XN.toast(result.message, 'success');
    })
    .catch(err => {
        XN.toast(err.message, 'danger');
    });
```

自动注入 `csrf_token`（从 hidden input 读取）。

### XN.submitFormAjax — AJAX 提交表单

```js
XN.submitFormAjax(formElement, url, function(result) {
    if(result.code === 0) {
        XN.toast('保存成功', 'success');
    }
});
```

### XN.alert — 弹窗

```js
XN.alert('提示内容', { type: 'danger', title: '确认删除' });
```

### XN.escapeHtml — JS 端转义

```js
XN.escapeHtml(untrustedString);
```

### XN.confirm — 确认对话框

```js
XN.confirm('确定要删除吗？').then(() => {
    // 确认后执行
});
```

---

## 3. htmx 4 核心原则

> 详见 [htmx 4 文档](https://four.htmx.org/docs/)，以下是插件开发常用模式。

### htmx 4 事件名约定（⚠️ 高频违规项）

> 源于 2026-07 多页面事件失效事故：htmx 2.x → 4 升级后事件名格式变更，旧名**静默失效**（不报错但不触发），调试极难。已违反 1 次，影响 8 个核心页面。

htmx 4 事件名**必须用冒号分隔格式**（`htmx:阶段:对象`），禁止使用 2.x 的驼峰连写格式。

**✅ 4.x 正确写法**：

```
htmx:config:request    htmx:before:request    htmx:after:request
htmx:before:swap       htmx:after:swap        htmx:response:error
htmx:before:send       htmx:after:request     htmx:before:onload
```

**❌ 2.x 旧名（禁止使用，会静默失效）**：

```
htmx:configRequest     htmx:beforeRequest     htmx:afterRequest
htmx:beforeSwap        htmx:afterSwap         htmx:afterSettle
htmx:responseError     htmx:beforeOnLoad
```

**完整映射表**：

| 2.x 旧名 | 4.x 新名 | 说明 |
|----------|----------|------|
| `htmx:configRequest` | `htmx:config:request` | 请求配置（可改 headers/params） |
| `htmx:beforeRequest` | `htmx:before:request` | 请求发出前 |
| `htmx:afterRequest` | `htmx:after:request` | 请求完成后（无论成败） |
| `htmx:beforeSwap` | `htmx:before:swap` | 内容置换前 |
| `htmx:afterSwap` | `htmx:after:swap` | 内容置换后 |
| `htmx:afterSettle` | `htmx:after:swap` | 4 中合并入 `after:swap`（DOM 已稳定） |
| `htmx:responseError` | `htmx:response:error` | 响应错误（非 2xx） |

> 适用范围：`hx-on`、`htmx.on()`、`addEventListener('htmx:...', ...)`、`document.body.addEventListener` 等所有事件监听场景，事件名一律用 4.x 冒号格式。

> 参考资料：`doc/htmx4/migration-guide.md`（如存在）；官方迁移说明 https://htmx.org/migration-guide/

### 基本属性

```html
<!-- GET 请求 -->
<button hx-get="<?php echo url('myroute-data');?>" hx-target="#result">
    加载数据
</button>

<!-- POST 请求 -->
<form hx-post="<?php echo url('myroute-save');?>" hx-target="#result">
    <?php echo CsrfService::input();?>
    <input name="content" value="">
    <button type="submit">保存</button>
</form>

<!-- 触发时机 -->
<div hx-get="<?php echo url('myroute-check');?>" hx-trigger="load">自动加载</div>
<div hx-get="..." hx-trigger="every 30s">定时刷新</div>
```

### 常用属性补充

| 属性 | 作用 | 示例 |
|------|------|------|
| `hx-swap` | 置换策略 | `hx-swap="innerHTML"` |
| `hx-headers` | 传递 HTTP 头（JSON） | `hx-headers='{"X-CSRF-Token": "..."}'` |
| `hx-vals` | 传递额外参数（JSON） | `hx-vals='{"tid": 123}'` |
| `hx-params` | 控制提交哪些字段 | `hx-params="not password"` |
| `hx-push-url` | 更新浏览器 URL（支持后退） | `hx-push-url="true"` |

**hx-swap 置换策略取值**：

- `innerHTML`（默认）— 替换目标内部 HTML
- `outerHTML` — 替换目标元素本身（含标签）
- `afterend` / `beforeend` — 在目标内部末尾/开头追加
- `afterbegin` / `beforebegin` — 在目标外部前/后插入
- `innerMorph` / `outerMorph` — morph 置换，**保留表单输入状态**（详见下文）
- `delete` — 删除目标元素
- `none` — 不置换（仅触发副作用，常配合 `hx-on` 使用）

**示例**：

```html
<!-- hx-swap：用 afterend 在列表后追加新项 -->
<button hx-get="<?php echo url('item-next');?>"
        hx-target="#list"
        hx-swap="beforeend">加载更多</button>

<!-- hx-headers：传递自定义请求头 -->
<button hx-post="<?php echo url('api-save');?>"
        hx-headers='{"X-Trigger": "plugin-btn"}'
        hx-target="#result">保存</button>

<!-- hx-vals：补充额外参数（不依赖表单字段） -->
<button hx-post="<?php echo url('post-like-'.$pid);?>"
        hx-vals='{"from": "list"}'
        hx-target="#like-box">点赞</button>

<!-- hx-params：仅提交指定字段（排除敏感字段如 password） -->
<form hx-post="<?php echo url('user-profile');?>"
      hx-params="not password, password_confirm">
    <input name="nickname">
    <input name="password" type="password">
    <button type="submit">保存</button>
</form>

<!-- hx-params="*" 提交全部；hx-params="none" 不提交任何表单字段 -->
<!-- hx-params="name,email" 仅提交 name 和 email -->

<!-- hx-push-url：更新 URL，支持浏览器后退/前进 -->
<a hx-get="<?php echo url('thread-'.$tid);?>"
   hx-target="#content"
   hx-push-url="true">查看帖子</a>
```

> ⚠️ `hx-headers` / `hx-vals` 的值是 **JSON 字符串**，键名必须用双引号。PHP 端可用 `json_encode(['tid' => $tid])` 动态生成。

### 乐观更新（hx-optimistic）

```html
<!-- 点赞：先更新 UI，服务端返回后自动替换 -->
<button hx-post="<?php echo url('post-like-'.$pid);?>"
        hx-optimistic="innerHTML:.liked-count">
    <span class="liked-count"><?php echo $post['likes'];?></span>
</button>
```

### hx-live（纯前端交互）

```html
<!-- 根据 DOM 状态显示/隐藏 -->
<div hx-live="onLoad">
    <span hx-show="count > 0" class="badge">有新消息</span>
</div>
```

### 服务端返回约定

```php
// 成功（自动 toast + 可选重定向）
message(0, '保存成功', ['redirect_url' => url('myroute-list')]);

// 成功（局部替换，不重定向）
message(0, '已更新');
```

### 禁止使用的属性

```html
<!-- ❌ 禁止 Alpine.js -->
<div x-data="{ open: false }" x-show="open">

<!-- ❌ 禁止 idiomorph / alpine-morph -->
<div hx-swap="morph:idom">

<!-- ✅ htmx 4 内置 morph -->
<div hx-swap="innerMorph">
```

### innerMorph 与 outerMorph 区别

`innerHTML` / `outerHTML` 会**完全重建 DOM**，导致目标元素内的所有客户端状态丢失（input 值、textarea 内容、select 选中项、滚动位置、未保存的草稿、第三方组件挂载状态等）。`innerMorph` / `outerMorph` 是 htmx 4 内置的 morph 算法，按节点差异**就地修补 DOM**，保留上述状态。

| 置换策略 | 作用范围 | 保留状态 | 适用场景 |
|----------|----------|----------|----------|
| `innerHTML` | 替换目标元素**内部** HTML | ❌ 丢失 | 静态内容刷新、首次加载 |
| `outerHTML` | 替换目标元素**本身**（含标签） | ❌ 丢失 | 整块替换、无表单的场景 |
| `innerMorph` | morph 目标元素**内部** HTML | ✅ 保留 | 表单/列表内的乐观更新 |
| `outerMorph` | morph 目标元素**本身**（含标签） | ✅ 保留 | 需替换自身标签的乐观更新 |

**典型场景**：

- **点赞 / 收藏 / 关注**等乐观更新按钮：服务端返回新计数或新状态 HTML，用 `innerMorph` 仅替换内部，**避免用户在按钮旁输入框中正在输入的内容被重置**。
- **帖子列表局部刷新**：刷新某个帖子卡片时，若用户正在该卡片的回复输入框内打字，`innerHTML` 会清空输入；`innerMorph` 则保留。
- **需要替换标签本身**（如把 `<button>` 换成 `<a>`，或更改 `class`）：用 `outerMorph`。

**示例**：

```html
<!-- ❌ 错误：用 innerHTML 会导致旁边的输入框内容丢失 -->
<div hx-get="<?php echo url('like-status-'.$pid);?>"
     hx-target="#like-box"
     hx-swap="innerHTML">刷新</div>

<!-- ✅ 正确：用 innerMorph 保留输入状态 -->
<div hx-get="<?php echo url('like-status-'.$pid);?>"
     hx-target="#like-box"
     hx-swap="innerMorph">刷新</div>

<!-- ✅ outerMorph：需要替换按钮自身标签（如 button → a） -->
<button hx-post="<?php echo url('post-pin-'.$pid);?>"
        hx-target="this"
        hx-swap="outerMorph">置顶</button>
```

> ⚠️ `innerMorph` 只能保留**目标元素内部**的状态；如果需要保留目标元素自身的属性（如 `class`、`data-*`），用 `outerMorph`。两者都依赖 htmx 4 内置 morph，禁止改用 `morph:idom`（idiomorph 扩展）。

### hx-on — 内联事件监听

`hx-on` 用于在元素上内联监听 htmx 事件（或原生事件），无需单独写 `<script>`。事件名**必须用 htmx 4 冒号格式**（详见本节开头「htmx 4 事件名约定」），用 2.x 旧名会**静默失效**。

**基本用法**：

```html
<!-- 监听 htmx 事件（注意冒号格式） -->
<div hx-on="htmx:after:swap: console.log('swapped')"
     hx-get="<?php echo url('thread-'.$tid);?>"
     hx-target="#content"
     hx-swap="innerHTML">加载</div>
```

**多事件监听**（用分号分隔）：

```html
<div hx-get="<?php echo url('data-load');?>"
     hx-target="#content"
     hx-on="htmx:before:request: showLoader(); htmx:after:swap: hideLoader();">
    加载
</div>
```

**配合 `hx-on:事件名` 单事件写法**（htmx 4 推荐，可读性更好）：

```html
<button hx-post="<?php echo url('post-like-'.$pid);?>"
        hx-target="#like-box"
        hx-on:htmx:before:request="this.disabled = true"
        hx-on:htmx:after:request="this.disabled = false"
        hx-on:htmx:response:error="XN.toast('请求失败', 'danger')">
    点赞
</button>
```

> ⚠️ **事件名格式红线**：`hx-on` / `hx-on:事件名` 中的事件名同样必须用 4.x 冒号格式（`htmx:before:request`、`htmx:after:swap`、`htmx:response:error`），禁止 2.x 旧名（`htmx:beforeRequest`、`htmx:afterSwap`、`htmx:responseError`）。旧名不会报错但不触发，是高频踩坑点。

> 💡 原生事件也可用 `hx-on:click`、`hx-on:change` 等（不带 `htmx:` 前缀）。

---

## 4. Tabler Icons

> 图标用法统一使用 `<i class="ti ti-xxx"></i>` 格式，前后台共用。完整图标列表见 https://tabler.io/icons。

> 📖 **UI 规范总览**：Tabler Icons 的加载顺序（前后台 CSS 引入位置）、与 Bootstrap 5 组件的搭配规范、按钮/徽章中图标用法速查表，已整合到 [14-plugin-admin-ui.md](14-plugin-admin-ui.md) 第 1 节（技术栈与加载顺序）和第 2 节（后台 Bootstrap 5 组件规范）。

---

## 5. 插件资源加载

### 方式 A：从 hook 注入（全局）

```php
// hook/footer_js_after.htm
<script src="plugin/my_plugin/view/js/my_plugin.js"></script>

// hook/header_link_after.htm
<link rel="stylesheet" href="plugin/my_plugin/view/css/my_plugin.css">
```

### 方式 B：在插件自己的模板中（局部）

```php
// plugin/my_plugin/view/htm/my_page.htm
<link rel="stylesheet" href="plugin/my_plugin/view/css/my_plugin.css">
<!-- 页面内容 -->
<script src="plugin/my_plugin/view/js/my_plugin.js"></script>
```

### 方式 C：在 hook 中内联 JS/数据

```php
// hook/post_start_init.htm（参考 xnx_tag）
<script>
var __myPluginConfig = <?php echo json_encode(setting_get('my_plugin'));?>;
</script>
```

> ⚠️ **路径格式**：`plugin/<dir>/view/css/x.css`，**相对路径**，不是 `APP_PATH` 绝对路径。

---

## 6. 安全规则（必须遵守）

### CSRF（所有 POST 请求）

```php
// HTML 表单
<form method="post">
    <?php echo CsrfService::input();?>
    <!-- 字段 -->
</form>

// PHP 处理
CsrfService::check();  // 放在最前面，失败直接 exit

// htmx 请求
// XN.ajax() 自动注入 X-CSRF-TOKEN header
// xiuno-modern.js 自动从 <meta name="csrf-token"> 读取（供 JS 注入请求头/表单字段）
// 服务端 CsrfService::check() 从 $_POST['csrf_token'] 或 HTTP_X_CSRF_TOKEN 请求头读取
```

### XSS 防护

```php
// ❌ 禁止
echo $user_input;
echo htmlspecialchars($user_input);

// ✅ 必须用
echo esc_html($user_input);    // 输出到 HTML 内容
echo esc_attr($user_input);    // 输出到 HTML 属性
echo esc_js($user_input);      // 输出到 <script> 内
```

### 输入校验

```php
// 读取参数时自动转义
$keyword = param('keyword');          // htmlspecialchars 自动
$page = param('page', 1);             // 类型强转
$dir = param_word('dir');            // 仅字母数字下划线
$tid = param(2, 0);                   // URL 段 + 默认值 + 类型

// SQL 用的值会被 db_cond_to_sqladd 自动 addslashes
// 但 param_word/param 已经做了 htmlspecialchars
```

### 权限检查

```php
// 检查登录
user_login_check();   // 游客跳转登录页

// 检查管理员
if($gid != 1 && $gid != 2) {
    message(-1, '需要管理员权限');
}

// 检查板块权限
if(!forum_access_user($fid, $gid, 'allowthread')) {
    message(-1, '没有发帖权限');
}

// 检查插件自定义权限
if(!PermissionService::check('my_plugin_manage')) {
    message(-1, '没有管理权限');
}
```

### 安装脚本安全

```php
// install.php / uninstall.php
!defined('DEBUG') AND exit('Access Denied');

// setting.php
!defined('DEBUG') AND exit('Access Denied');
$gid != 1 && $gid != 2 AND message(-1, '需要管理员权限');
CsrfService::check();
```

---

## 7. message() 返回格式（HTMX 自动处理）

| 场景 | 返回 |
|---|---|
| HTMX + 成功 + redirect_url | `HX-Trigger` header → toast + 自动跳转 |
| HTMX + 成功 | `<div class="alert alert-success">...</div>` |
| HTMX + 错误 | `<div class="alert alert-danger">...</div>` |
| API/AJAX | JSON `{code, message, ...}` |
| 普通浏览器 | 渲染 `view/htm/message.htm`（带返回按钮） |

```php
// HTMX 交互示例
// 按钮：<button hx-post="<?php echo url('post-like-'.$pid);?>" ...>点赞</button>

// 处理
if($is_liked) {
    // 返回新的点赞数（HTML 片段）
    echo '<span class="badge bg-danger">'.($post['likes'] + 1).'</span>';
    exit;
} else {
    message(-1, '已经点过赞了');
}
```

---

## 8. 暗色模式

主题切换逻辑在 `header.inc.htm` 的内联 `<script>` 中：

```js
// 读取 localStorage，设置 data-bs-theme / data-theme
localStorage.getItem('theme') === 'dark'
    ? document.documentElement.setAttribute('data-bs-theme', 'dark')
    : document.documentElement.setAttribute('data-bs-theme', 'light');
```

插件 CSS 如需支持暗色模式，用 `data-bs-theme="dark"` 选择器：

```css
[data-bs-theme="dark"] .my-plugin-card {
    background: var(--bs-tertiary-bg);
}
```

---

## 9. 布局与 Card 规范

> 前台页面**必须使用系统三栏布局骨架** `layout_three_column.inc.htm`，禁止自行硬编码 `row` + `col-lg-*`；Card 组件**必须 `x-card` + `card` 组合使用**，禁止裸 `card`/`border`。

> 📖 **UI 规范总览**：三栏布局骨架的完整变量表（`$sidebar_left_file`/`$sidebar_right_file`/`$right_html`/`$col_main`/`$left_lg`/`$main_class`/`$right_class`）、用法代码模板、x-card + card 组合规范、列表分隔规则、禁止用 `border` 的场景说明，已整合到 [14-plugin-admin-ui.md](14-plugin-admin-ui.md) 第 3 节（x-card + card 组合规范）和第 4 节（前台三栏布局骨架）。

---

## 小结

- **CSS 注入** → `header_link_after.htm`（全局）或模板内（局部）
- **JS 注入** → `footer_js_after.htm`（全局）或模板内（局部）
- **JS API** → `XN.toast()` / `XN.ajax()` / `XN.confirm()` / `XN.alert()`（非关键页面）或原生 `fetch`+`confirm`（关键修复页面）
- **交互** → htmx 4 属性（`hx-get`/`hx-post`/`hx-target`/`hx-optimistic`）
- **布局** → 三栏骨架 `layout_three_column.inc.htm`（前台必须使用）
- **Card** → `x-card` + `card` 组合，禁止裸 `card`/`border`
- **安全** → `CsrfService::input()` + `CsrfService::check()` + `esc_html()`
- **禁止** → jQuery（已移除）/ Alpine.js / idiomorph / `window.__xxxData`
