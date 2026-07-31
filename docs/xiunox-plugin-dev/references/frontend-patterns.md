# 前端模式速查

> 本文件为前端模式速查，详细说明见 [plugindev/05-frontend-security.md](../../plugindev/05-frontend-security.md) 和 [plugindev/10-jquery-removal-guide.md](../../plugindev/10-jquery-removal-guide.md)

## 目录

- [1. 前台 CSS/JS 加载顺序](#1-前台-cssjs-加载顺序)
- [2. htmx 4 事件名约定](#2-htmx-4-事件名约定)
- [3. htmx:config:request 修改请求参数](#3-htmxconfigrequest-修改请求参数)
- [4. htmx 多卡片监听器模式](#4-htmx-多卡片监听器模式)
- [5. XN.* API 速查](#5-xn-api-速查)
- [6. lightbox 全局图片放大组件](#6-lightbox-全局图片放大组件)
- [7. esc_html / esc_attr / esc_js](#7-esc_html--esc_attr--esc_js)
- [8. CSRF 防护](#8-csrf-防护)
- [9. jQuery 移除迁移要点](#9-jquery-移除迁移要点)

---

## 1. 前台 CSS/JS 加载顺序

### 前台 CSS（header.inc.htm）

```
1. vendor/bootstrap/css/bootstrap.min.css     ← Bootstrap 5.3+
2. css/bootstrap-bbs.css                       ← BBS 覆盖样式
3. css/theme.css                                ← 主题色
4. vendor/tabler-icons/tabler-icons.min.css    ← Tabler Icons
5. <!--{hook header_link_after.htm}-->          ← ✅ 插件 CSS 注入点
```

### 前台 JS（header.inc.htm + footer.inc.htm）

```
 1. vendor/htmx/htmx.min.js                       ← htmx 4 核心（header）
 2. vendor/htmx/ext/hx-live.min.js                ← hx-live 扩展（header）
 3. vendor/htmx/ext/hx-optimistic.min.js          ← hx-optimistic 扩展（header）
 4. vendor/animejs/anime.umd.min.js               ← anime.js 动画（header）
 5. lang/{conf['lang']}/bbs.js                    ← 语言包 JSON（footer）
 6. vendor/bootstrap/js/bootstrap.bundle.min.js   ← Bootstrap JS
 7. view/js/xiuno-modern.js                       ← ✅ 现代兼容层（XN.* API）
 8. 内联全局变量（debug, url_rewrite_on, forumarr, uid, gid, bbs_lang, ...）
 9. view/js/bbs.js                                ← BBS 业务逻辑
10. <!--{hook footer_js_after.htm}-->             ← ✅ 插件 JS 注入点
11. view/js/auto-save.js                          ← 表单自动保存（草稿箱）
12. view/js/lightbox.js                           ← 全局图片放大（Bootstrap Modal + 原生 JS）
13. Flash toast 读取 PRG cookie 后显示并清除         ← footer 内联脚本
14. cron_run()                                    ← 后台计划任务触发（footer 末尾）
```

> ⚠️ jQuery 已于 2026-07-24 系统性移除，所有页面禁止使用 `$`/`jQuery`/`$.fn.*`。**无 `xiuno.js`、无 `bootstrap-plugin.js`**（旧版残留名称，已不存在）。

> 后台（`admin/`）**不使用 htmx**，仅使用原生 JS + Bootstrap。后台模板 hook 前缀为 `admin_*`。

---

## 2. htmx 4 事件名约定

> 高频违规项：htmx 2.x → 4 升级后旧名**静默失效**（不报错但不触发）。

事件名**必须用冒号分隔格式**（`htmx:阶段:对象`），禁止 2.x 驼峰连写。

**✅ 4.x 正确写法**：

```
htmx:config:request    htmx:before:request    htmx:after:request
htmx:before:swap       htmx:after:swap        htmx:response:error
htmx:before:send       htmx:before:onload
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

> 适用范围：`hx-on`、`htmx.on()`、`addEventListener('htmx:...', ...)` 等所有事件监听场景。

---

## 3. htmx:config:request 修改请求参数

> ⚠️ 红线：htmx 4 在 `htmx:config:request` 触发时**已完成 `new FormData(form)` 序列化**，此时修改 DOM（如动态添加 input、改 hidden value）**不会进入请求体**。必须直接修改 `evt.detail.parameters` 对象。

**❌ 错误写法（修改 DOM 无效）**：

```js
form.addEventListener('htmx:config:request', function(evt) {
    // ❌ FormData 已构建完成，DOM 修改不会进入请求
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'extra_data';
    hidden.value = 'xxx';
    form.appendChild(hidden);

    // ❌ 错误 API：evt.detail.ctx.request.body 不存在
    evt.detail.ctx.request.body['key'] = 'value';
});
```

**✅ 正确写法（直接改 parameters）**：

```js
form.addEventListener('htmx:config:request', function(evt) {
    // ✅ 直接修改 evt.detail.parameters 对象追加参数
    if (evt.detail && evt.detail.parameters) {
        evt.detail.parameters['extra_data'] = 'xxx';
        evt.detail.parameters['poll_data'] = JSON.stringify({ options: ['A', 'B'] });
    }
});
```

**修改 headers（用 ctx.request.headers）**：

```js
document.body.addEventListener('htmx:config:request', function(evt) {
    // htmx 4 用 fetch API，不自动发送 X-Requested-With，必须手动加
    evt.detail.ctx.request.headers['X-Requested-With'] = 'XMLHttpRequest';
    // POST/PUT/DELETE 自动注入 CSRF header
    var method = (evt.detail.ctx.request.method || '').toUpperCase();
    if (method === 'POST' || method === 'PUT' || method === 'DELETE') {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            evt.detail.ctx.request.headers['X-CSRF-Token'] = csrfMeta.getAttribute('content');
        }
    }
});
```

> API 区分：**改参数**用 `evt.detail.parameters['key'] = 'value'`；**改 headers**用 `evt.detail.ctx.request.headers['Key'] = 'value'`。

---

## 4. htmx 多卡片监听器模式

> 场景：同一表单内有多个互斥卡片（如投票的双卡片、悬赏/最佳答案的状态切换），需把当前可见卡片的数据写入请求。**监听器只在 form 上注册一次**，避免双监听器冲突。

```js
(function () {
    'use strict';

    var cardInstances = [];

    function initCard(card) {
        // ... 卡片内部初始化、serialize 逻辑
        cardInstances.push({
            el: card,
            serialize: function () {
                // 返回序列化后的 JSON 字符串
                return JSON.stringify({ /* 卡片数据 */ });
            }
        });
    }

    // 找到当前可见的 card（offsetParent !== null 表示 display 不是 none）
    function findVisibleCard() {
        for (var i = 0; i < cardInstances.length; i++) {
            if (cardInstances[i].el.offsetParent !== null) {
                return cardInstances[i];
            }
        }
        return null;
    }

    function init() {
        var cardEls = document.querySelectorAll('#card-m, #card-d, #pane-x .x-config');
        if (!cardEls.length) return;

        cardEls.forEach(function (card) { initCard(card); });

        // 只在 form 上注册一次 htmx:config:request 监听器
        var form = cardEls[0].closest('form');
        if (!form) return;

        form.addEventListener('htmx:config:request', function (evt) {
            var visible = findVisibleCard();
            if (!visible) return;
            var json = visible.serialize();
            if (!json) return;
            // ✅ 直接改 parameters，不要试图改 DOM hidden input
            if (evt.detail && evt.detail.parameters) {
                evt.detail.parameters['card_data'] = json;
            }
        });

        // 原生 submit 兜底（非 htmx 场景）
        form.addEventListener('submit', function () {
            var visible = findVisibleCard();
            if (!visible) return;
            var json = visible.serialize();
            var hiddenFields = form.querySelectorAll('input[name="card_data"]');
            hiddenFields.forEach(function (f) { f.value = json; });
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

---

## 5. XN.* API 速查

全局 `XN` 对象，定义于 `view/js/xiuno-modern.js`。前后台 `footer.inc.htm` 已全局加载。**关键修复页面（在线升级/数据库升级/后台登录）禁止使用**，需用原生 `fetch`+`confirm`。

### XN.toast — 提示消息

```js
XN.toast('操作成功', 'success');           // 绿色
XN.toast('出错了', 'danger');              // 红色
XN.toast('请注意', 'warning');             // 黄色
XN.toast('信息', 'info');                  // 蓝色
XN.toast('3秒消失', 'success', 3000);      // 自定义时长（默认 4 秒）
```

### XN.ajax — AJAX 请求（自动注入 CSRF token）

```js
XN.ajax('POST', '/plugin/my_plugin/api.php?action=save', { data: 'value' })
    .then(function (result) { XN.toast(result.message, 'success'); })
    .catch(function (err) { XN.toast(err.message, 'danger'); });
```

### XN.submitFormAjax — AJAX 提交表单

```js
XN.submitFormAjax(formElement, url, function (result) {
    if (result.code === 0) XN.toast('保存成功', 'success');
});
```

### XN.confirm — 确认对话框（Promise）

```js
XN.confirm('确定要删除吗？').then(function () {
    // 用户确认后执行
});
```

### XN.alert — 弹窗

```js
XN.alert('提示内容', { type: 'danger', title: '确认删除' });
```

### XN.escapeHtml — JS 端 HTML 转义

```js
XN.escapeHtml(untrustedString);   // 用于 innerHTML 前转义
```

> 异步 API（`XN.confirm` / `XN.confirmCreditsDeduct`）调用时必须加 `typeof` 守卫 + `try-catch`，避免 shim 加载失败导致按钮永久禁用。

---

## 6. lightbox 全局图片放大组件

> 全局组件：`view/js/lightbox.js` + `#xnLightbox` Modal（footer.inc.htm 已注入）。零依赖（Bootstrap 5 Modal + 原生 JS），支持轮播、滚轮/双击/按钮缩放、拖拽、双指缩放、旋转。**禁止插件自建独立 Modal**。

### 容器/图片选择器表

| 用途 | 选择器 | 说明 |
|------|--------|------|
| 帖子正文图片 | `.message img` | 自动绑定 |
| 应用介绍图片 | `.appcenter-intro-content img` | 自动绑定 |
| 应用截图 | `.appcenter-screenshots img` | 自动绑定 |
| 自定义容器 | `[data-lightbox-container] img` | 插件用此 data 属性声明 |
| 显式单图链接 | `a[data-lightbox]` | `href` 为原图 URL |
| 禁用放大 | `img[data-no-lightbox]` | 单张图片 opt-out |

> 跳过：`data:image/svg`、`data:image/gif;base64,R0lGOD...`（emoji）、`.ti` 内的图标字体回退 img。

### 插件用法示例

```html
<!-- 方式 A：自定义容器（推荐，新场景） -->
<div class="my-plugin-gallery" data-lightbox-container>
    <img src="pic1.jpg" alt="">
    <img src="pic2.jpg" alt="">
    <img src="data:image/svg,..." alt="">  <!-- 自动跳过 SVG emoji -->
</div>

<!-- 方式 B：显式截图链接（a 标签） -->
<a href="full-screenshot.png" data-lightbox>
    <img src="thumb-screenshot.png" alt="截图">
</a>

<!-- 方式 C：单张禁用放大 -->
<img src="icon.png" data-no-lightbox alt="">
```

### htmx 兼容

lightbox.js 内部监听 `htmx:after:swap` 重新绑定，htmx 局部刷新后无需手动重新初始化。

---

## 7. esc_html / esc_attr / esc_js

> PHP 端 XSS 防护。禁止 `echo $user_input` 或 `htmlspecialchars()`，必须用 `esc_*` 函数（定义于 `lib/EscapeService.php`）。

```php
// 输出到 HTML 内容（<p>...</p> 之间）
echo esc_html($user_input);

// 输出到 HTML 属性（value="..."、title="..." 之间）
echo '<input value="' . esc_attr($user_input) . '">';

// 输出到 <script> 内字符串字面量
echo '<script>var name = "' . esc_js($user_input) . '";</script>';
```

**JS 端转义**（非关键页面用 `XN.escapeHtml`，关键修复页面自实现）：

```js
// 关键修复页面自实现（不依赖 xiuno-modern.js）
function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
}
el.innerHTML = escapeHtml(untrustedString);   // 插入 innerHTML 前必转义
el.textContent = untrustedString;            // 安全（自动转义）
```

---

## 8. CSRF 防护

> 所有 POST/PUT/DELETE 请求必须带 CSRF token。`CsrfService` 定义于 `lib/CsrfService.php`。

### PHP 表单 + 处理

```php
// HTML 表单（输出 hidden input）
<form method="post" action="...">
    <?php echo CsrfService::input(); ?>
    <!-- 其他字段 -->
</form>

// PHP 处理（放在最前面，失败直接 exit）
CsrfService::check();
```

### htmx / AJAX 请求

```html
<!-- htmx POST：表单内已含 CsrfService::input() 的 hidden input，自动随 FormData 提交 -->
<form hx-post="<?php echo url('myroute-save'); ?>" hx-target="#result">
    <?php echo CsrfService::input(); ?>
    <input name="content">
    <button type="submit">保存</button>
</form>
```

```js
// XN.ajax() 自动从 hidden input / meta 读取 csrf_token 并注入 header
// 服务端 CsrfService::check() 从 $_POST['csrf_token'] 或 HTTP_X_CSRF_TOKEN 读取
XN.ajax('POST', url, data).then(...);

// 原生 fetch（关键修复页面）：显式传 header + body 双保险
var csrfToken = <?php echo json_encode(CsrfService::token()); ?>;
fetch(url, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&field=' + encodeURIComponent(val),
    credentials: 'same-origin'
});
```

---

## 9. jQuery 移除迁移要点

> 自 2026-07-24 起系统性移除 jQuery。新代码强制原生 JS + htmx 4，禁止 `$`/`jQuery`/`$.fn.*`。

### 高频迁移对照表

| jQuery | 原生 JS |
|---|---|
| `$('#id')` | `document.getElementById('id')` |
| `$('.cls')` | `document.querySelectorAll('.cls')` |
| `$(els).on('click', fn)` | `els.forEach(el => el.addEventListener('click', fn))` |
| `$(document).on('click', '.btn', fn)` | `document.addEventListener('click', e => { if (e.target.closest('.btn')) fn(e); })` |
| `$.ajax({url,type,data,success})` | 自实现 `ajax()`（基于 `fetch`）或 `XN.ajax()` |
| `$.post(url, data, cb)` | `fetch(url, {method:'POST', body:new URLSearchParams(data)})` |
| `jform.serialize()` | `new URLSearchParams(new FormData(jform)).toString()` |
| `$(el).html(s)` | `el.innerHTML = s`（需先 `escapeHtml`） |
| `$(el).val()` / `.val(s)` | `el.value` / `el.value = s` |
| `$(el).data('k')` | `el.dataset.k` |
| `$(el).addClass('c')` | `el.classList.add('c')` |
| `$(el).show()` / `.hide()` | `el.style.display = ''` / `'none'` |
| `$('#modal').modal('show')` | `new bootstrap.Modal('#modal').show()` |
| `$.each(arr, fn)` | `arr.forEach(fn)` |
| `$.extend({}, a, b)` | `Object.assign({}, a, b)` |

### 关键迁移陷阱

1. **`return false` 不阻止默认行为**：原生事件中必须显式 `e.preventDefault(); e.stopPropagation();`
2. **`$.param` 数组序列化**：`URLSearchParams` 会把数组 toString 为 `"1,2,3"`，需自实现 `buildQuery()` 支持 `ids[]=1&ids[]=2`
3. **FormData 不能预设 Content-Type**：浏览器自动加 boundary，手动设会丢失
4. **NodeList.forEach**：现代浏览器支持，兼容旧浏览器用 `Array.prototype.forEach.call(els, fn)`
5. **异步确认弹窗**：`htmx:confirm` 中调用 `XN.confirm` 必须设 `window._htmxConfirmAsync = true`，回调/取消/异常时重置为 `false`

### 关键修复页面规范

> 在线升级 / 数据库升级 / 后台登录 / 插件管理 / 系统工具等「最后手段」页面**禁止依赖 `xiuno-modern.js`**，使用原生 `fetch`+`confirm`+`querySelectorAll`，避免「网站坏 → 修复页面也坏」的死循环。

- [ ] 内联 JS 用原生 `fetch` + `querySelectorAll` + `confirm`，不依赖 `$`/`XN`
- [ ] AJAX 用自实现 `ajax()` 函数，不调用 `$.ajax`/`XN.ajax`
- [ ] CSRF token 通过 PHP `json_encode` 注入 JS 变量，header + body 双保险
- [ ] 动态内容插入 DOM 前用 `escapeHtml()` 转义
- [ ] Toast 独立实现，有 `alert` 兜底

---

## 10. 布局与 Card 规范

> 前台页面**必须使用系统三栏布局骨架** `layout_three_column.inc.htm`，禁止自行硬编码 `row` + `col-lg-*`；Card 组件**必须 `x-card` + `card` 组合使用**，禁止裸 `card`/`border`。

> 📖 **UI 规范总览**：三栏布局骨架完整变量表、x-card + card 组合规范、列表分隔规则、禁止用 `border` 的场景说明，已整合到：
> - 速查版：[admin-patterns.md](admin-patterns.md)（速查表 + 最小代码片段）
> - 完整版：[../../plugindev/14-plugin-admin-ui.md](../../plugindev/14-plugin-admin-ui.md) 第 3 节（x-card + card 组合规范）和第 4 节（前台三栏布局骨架）
