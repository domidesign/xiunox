# 10 jQuery 依赖移除与原生 JS 迁移指南

> 自 2026-07-24 起，XIUNOX 已系统性移除所有 jQuery 依赖。本文档记录迁移原则、关键修复页面改造规范、以及 jQuery → 原生 JS API 对照表，供插件开发者参考。

---

## 一、背景

### 历史路径

1. **2026-07-17 起**：项目内置 jQuery 兼容 shim（`view/js/xiuno-modern.js` 暴露 `window.jQuery = $`），让 20 个存量插件 JS 和 `.htm` 模板内联 `$` 代码继续工作。新插件代码强制用 `XN.*` API。
2. **2026-07-24 起**：系统性移除所有 jQuery 依赖。68 个文件中的 `$`/`XN.confirm` 调用全部改造为原生 JS。**关键修复页面**（在线升级/数据库升级/后台登录等）已完全不依赖 `xiuno-modern.js`，使用原生 `fetch` + `confirm` + Web API 实现，避免「网站坏 → 修复页面也坏 → 无法修复」的死循环。

### 设计原则

- **零外部依赖**：关键修复页面（在线升级、数据库升级、后台登录、系统工具）禁止依赖 `xiuno-modern.js` 的 `$`/`XN` shim，使用原生 `fetch` + `confirm` + `querySelectorAll` 等 Web API
- **原生 JS 优先**：新代码强制使用原生 JS + htmx 4 属性，禁止使用 `$`/`jQuery`/`$.fn.*`
- **XN API 保留**：`XN.toast()` / `XN.ajax()` / `XN.confirm()` / `XN.alert()` 等高层 API 仍在 `xiuno-modern.js` 中提供，非关键页面可继续使用（前后台 `footer.inc.htm` 已全局加载 `xiuno-modern.js`）
- **安全兜底**：调用 `XN.confirm` / `XN.confirmCreditsDeduct` 等异步 API 时必须加 `typeof` 守卫 + `try-catch`，避免 shim 加载失败导致按钮永久禁用

---

## 二、关键修复页面规范

> **定义**：用户修复网站问题的「最后手段」页面。旧版本站点可能没有 `xiuno-modern.js` 或该文件加载失败时，这些页面必须仍然能工作。

### 关键修复页面清单

| 文件 | 用途 |
|---|---|
| `admin/view/htm/online_upgrade.htm` | 在线升级（检查更新、下载、应用） |
| `admin/view/htm/upgrade.htm` | 数据库结构升级 |
| `admin/view/htm/index_login.htm` | 后台登录 |
| `admin/view/htm/plugin_list.htm` | 插件管理（启用/禁用/卸载） |
| `admin/view/htm/other_cache_setting.htm` | 系统工具（清缓存） |

### 关键页面改造检查表

- [ ] 内联 JS 使用原生 `fetch` + `querySelectorAll` + `confirm`，不依赖 `$`/`XN`
- [ ] AJAX 请求用自实现的 `ajax()` 函数（基于 `fetch`），不调用 `$.ajax`/`XN.ajax`
- [ ] CSRF token 通过 PHP `json_encode` 注入 JS 变量，请求中显式传递（header + body 双保险）
- [ ] 动态内容插入 DOM 前用 `escapeHtml()` 转义，防止 XSS
- [ ] Toast 提示独立实现，不依赖 `XN.toast`（用 `#toast-container` 或 `alert` 兜底）
- [ ] 按钮状态管理用原生 `disabled` + `innerHTML`，不依赖 `$.fn.button('loading')`
- [ ] 事件监听用 `addEventListener`，不依赖 `$.fn.on`

### 范式：原生 ajax 函数

```javascript
// ponytail: 关键修复页面必须独立于 xiuno-modern.js
function buildQuery(data) {
    var parts = [];
    for (var k in data) {
        if (Object.prototype.hasOwnProperty.call(data, k)) {
            parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
        }
    }
    return parts.join('&');
}

function ajax(opts) {
    var url = opts.url;
    var type = (opts.type || 'GET').toUpperCase();
    var data = opts.data || null;
    var dataType = opts.dataType || 'json';

    var fetchOpts = {
        method: type,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        },
        credentials: 'same-origin'
    };

    if (type === 'POST' && data) {
        if (data instanceof FormData) {
            fetchOpts.body = data;
        } else {
            fetchOpts.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
            fetchOpts.body = typeof data === 'object' ? buildQuery(data) : data;
        }
    } else if (data && !(data instanceof FormData)) {
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        url += sep + buildQuery(data);
    }

    fetch(url, fetchOpts).then(function(response) {
        return response.text().then(function(text) {
            var xhrLike = { status: response.status, responseText: text };
            if (!response.ok) throw xhrLike;
            if (dataType === 'json') {
                try { return JSON.parse(text); }
                catch (e) { throw xhrLike; }
            }
            return text;
        });
    }).then(function(res) {
        if (opts.success) opts.success(res);
    }).catch(function(err) {
        var xhr = err && err.responseText !== undefined ? err : { responseText: (err && err.message) ? err.message : String(err || '') };
        if (opts.error) opts.error(xhr);
    });
}
```

### 范式：独立 Toast 函数

```javascript
// ponytail: 不依赖 xiuno-modern.js 的 showToast/XN.toast
// 复用 footer.inc.htm 已渲染的 #toast-container（若无则 fallback 到 alert）
function showToast(message, type) {
    var container = document.getElementById('toast-container');
    if (!container) { alert(message); return; }
    var toastEl = document.createElement('div');
    toastEl.className = 'toast show align-items-center text-white bg-' + (type === 'success' ? 'success' : (type === 'error' ? 'danger' : 'secondary'));
    toastEl.setAttribute('role', 'alert');
    var body = document.createElement('div');
    body.className = 'd-flex';
    var inner = document.createElement('div');
    inner.className = 'toast-body';
    inner.textContent = message;
    body.appendChild(inner);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-close btn-close-white me-2 m-auto';
    btn.setAttribute('data-bs-dismiss', 'toast');
    btn.onclick = function() { toastEl.remove(); };
    body.appendChild(btn);
    toastEl.appendChild(body);
    container.appendChild(toastEl);
    setTimeout(function() { if (toastEl.parentNode) toastEl.remove(); }, 4000);
}
```

---

## 三、jQuery → 原生 JS API 对照表

### 选择器

| jQuery | 原生 JS | 备注 |
|---|---|---|
| `$('#id')` | `document.getElementById('id')` | 返回单个元素 |
| `$('.class')` | `document.querySelectorAll('.class')` | 返回 NodeList |
| `$('input[name="x"]')` | `document.querySelector('input[name="x"]')` | 单个 |
| `$(els).each(fn)` | `els.forEach(fn)` | NodeList 支持 forEach |
| `$(els).on('click', fn)` | `els.forEach(el => el.addEventListener('click', fn))` | 直接绑定 |
| `$(document).on('click', '.btn', fn)` | `document.addEventListener('click', e => { if (e.target.closest('.btn')) fn(e); })` | 事件委托 |

### AJAX

| jQuery | 原生 JS | 备注 |
|---|---|---|
| `$.ajax({url, type, data, success, error})` | 自实现 `ajax()` 函数（见上范式） | 基于 `fetch` |
| `$.post(url, data, cb)` | `fetch(url, {method:'POST', body:new URLSearchParams(data)})` | 简单场景 |
| `$.get(url, cb)` | `fetch(url).then(r => r.json()).then(cb)` | 简单场景 |
| `$.param({a:[1,2]})` | 自实现 `buildQuery()`（支持 `ids[]=1&ids[]=2`） | PHP 风格数组 |
| `jform.serialize()` | `new FormData(jform)` 或 `new URLSearchParams(new FormData(jform)).toString()` | 表单序列化 |

### DOM 操作

| jQuery | 原生 JS | 备注 |
|---|---|---|
| `$(el).html(s)` | `el.innerHTML = s` | 注意 XSS，需先 `escapeHtml` |
| `$(el).text(s)` | `el.textContent = s` | 安全（自动转义） |
| `$(el).val()` | `el.value` | 读取 |
| `$(el).val(s)` | `el.value = s` | 设置 |
| `$(el).attr('name')` | `el.getAttribute('name')` | |
| `$(el).attr('name', v)` | `el.setAttribute('name', v)` | |
| `$(el).data('key')` | `el.dataset.key` | HTML5 data-* |
| `$(el).addClass('c')` | `el.classList.add('c')` | |
| `$(el).removeClass('c')` | `el.classList.remove('c')` | |
| `$(el).hasClass('c')` | `el.classList.contains('c')` | |
| `$(el).toggleClass('c')` | `el.classList.toggle('c')` | |
| `$(el).show()` | `el.style.display = ''` | |
| `$(el).hide()` | `el.style.display = 'none'` | |
| `$(el).append(child)` | `el.appendChild(child)` | |
| `$(el).remove()` | `el.parentNode.removeChild(el)` | |
| `$(el).empty()` | `el.innerHTML = ''` | |
| `$(el).find(sel)` | `el.querySelectorAll(sel)` | |
| `$(el).closest(sel)` | `el.closest(sel)` | 原生支持 |

### 事件

| jQuery | 原生 JS | 备注 |
|---|---|---|
| `$(el).on('click', fn)` | `el.addEventListener('click', fn)` | |
| `$(el).off('click', fn)` | `el.removeEventListener('click', fn)` | |
| `$(el).trigger('click')` | `el.dispatchEvent(new Event('click'))` | |
| `e.preventDefault()` | `e.preventDefault()` | 一致 |
| `e.stopPropagation()` | `e.stopPropagation()` | 一致 |
| `return false` in handler | `e.preventDefault(); e.stopPropagation();` | 原生 `return false` 不阻止默认行为 |

### 工具函数

| jQuery | 原生 JS | 备注 |
|---|---|---|
| `$.each(arr, fn)` | `arr.forEach(fn)` | |
| `$.extend({}, a, b)` | `Object.assign({}, a, b)` | |
| `$.inArray(v, arr)` | `arr.indexOf(v)` | |
| `$.isArray(v)` | `Array.isArray(v)` | |
| `$.type(v)` | `typeof v` 或 `Array.isArray` | |
| `$.trim(s)` | `s.trim()` | |
| `$.now()` | `Date.now()` | |

### Bootstrap 组件桥接

| jQuery | 原生 JS | 备注 |
|---|---|---|
| `$('#modal').modal('show')` | `new bootstrap.Modal('#modal').show()` | |
| `$('#modal').modal('hide')` | `bootstrap.Modal.getInstance(document.getElementById('modal')).hide()` | |
| `$('#dropdown').dropdown('toggle')` | `new bootstrap.Dropdown('#dropdown').toggle()` | |
| `$('#tab').tab('show')` | `new bootstrap.Tab('#tab').show()` | |
| `$('#tooltip').tooltip()` | `new bootstrap.Tooltip('#tooltip')` | |

---

## 四、异步确认弹窗的兜底保护

`XN.confirm` / `XN.confirmCreditsDeduct` 等异步 API 在 `xiuno-modern.js` 加载失败时会抛 `TypeError`。若在 htmx:confirm 事件中调用且未加保护，会导致 `window._htmxConfirmAsync` 标志位卡在 `true`，提交按钮永久禁用。

### 范式：带兜底的异步确认

```javascript
form.addEventListener('htmx:confirm', function(e) {
    e.preventDefault();
    // ponytail: 兜底保护——若 XN 不可用，直接放行请求
    if (typeof XN === 'undefined' || typeof XN.confirmCreditsDeduct !== 'function') {
        e.detail.issueRequest();
        return;
    }
    window._htmxConfirmAsync = true;
    try {
        XN.confirmCreditsDeduct(creditsEvent, fid, function() {
            window._htmxConfirmAsync = false;
            e.detail.issueRequest();
        }, { onCancel: function() {
            window._htmxConfirmAsync = false;
            e.detail.dropRequest();
            if (window.resetPostSubmit) window.resetPostSubmit();
        } });
    } catch(err) {
        // ponytail: 抛异常时必须重置标志并恢复按钮
        window._htmxConfirmAsync = false;
        if (window.resetPostSubmit) window.resetPostSubmit();
        if (typeof console !== 'undefined' && console.error) console.error('confirm failed:', err);
    }
});
```

---

## 五、XSS 防护：escapeHtml 函数

动态内容插入 `innerHTML` 前必须转义。关键修复页面不依赖 `XN.escapeHtml`，自实现：

```javascript
function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

// 使用示例
resultEl.innerHTML = escapeHtml(res.message);
detailEl.innerHTML = '<div>' + icon + ' ' + escapeHtml(r.name) + '</div>';
```

> 非关键页面可继续使用 `XN.escapeHtml(untrustedString)`（`xiuno-modern.js` 提供）。

---

## 六、迁移检查清单

### 插件 JS 文件迁移

逐项核对：

- [ ] Grep 旧文件所有 `$` 调用：`$.ajax`/`$.xpost`/`$.get`/`$.post`/`$.each`/`$.param`/`$.fn.*`/`$('...')`
- [ ] 每个调用替换为原生 JS 等价实现（参见对照表）
- [ ] `XN.confirm` / `XN.alert` 改为原生 `confirm` / `alert`（或保留 `XN.confirm` 但加 `typeof` 守卫）
- [ ] 表单序列化用 `new FormData(form)` 或 `new URLSearchParams`
- [ ] 事件委托改为 `document.addEventListener('click', e => { if (e.target.closest(sel)) ... })`
- [ ] 确认无 `<script src>` 引用旧 jQuery 文件
- [ ] 修改后清 `tmp/` 缓存，提示用户硬刷新

### 关键修复页面额外检查

- [ ] 无 `$.ajax` / `$()` / `$.each` / `XN.confirm` 等依赖
- [ ] CSRF token 通过 PHP `json_encode` 注入 JS 变量
- [ ] AJAX 请求显式传递 CSRF token（header + body 双保险）
- [ ] 动态内容插入 DOM 前用 `escapeHtml()` 转义
- [ ] Toast 提示独立实现，有 `alert` 兜底
- [ ] 按钮状态管理用原生 `disabled` + `innerHTML`

---

## 七、常见迁移陷阱

### 1. `return false` 在原生事件中不阻止默认行为

```javascript
// ❌ jQuery 写法：return false 等价于 preventDefault + stopPropagation
$(el).on('submit', function() { ... return false; });

// ✅ 原生写法：必须显式调用
el.addEventListener('submit', function(e) {
    e.preventDefault();
    e.stopPropagation();
    // ...
});
```

> 项目的 `$` shim 已在 `$.fn.on` 内部包装 handler，检测 `ret === false` 时调 `e.preventDefault() + e.stopPropagation()`。但新代码仍建议显式接收 `e` 参数并调用 `e.preventDefault()`（双保险）。

### 2. `$.param` 数组序列化格式

```javascript
// ❌ jQuery $.param({ids: [1,2,3]}) → "ids[]=1&ids[]=2&ids[]=3"
// 原生 URLSearchParams 会把数组隐式 toString 为 "1,2,3"

// ✅ 自实现 buildQuery 支持 PHP 风格数组
function buildQuery(data) {
    var parts = [];
    function build(prefix, val) {
        if (Array.isArray(val)) {
            for (var i = 0; i < val.length; i++) build(prefix + '[]', val[i]);
        } else if (typeof val === 'object') {
            for (var k in val) {
                if (Object.prototype.hasOwnProperty.call(val, k)) {
                    build(prefix ? prefix + '[' + k + ']' : k, val[k]);
                }
            }
        } else {
            parts.push(encodeURIComponent(prefix) + '=' + encodeURIComponent(val === null || val === undefined ? '' : val));
        }
    }
    build('', data);
    return parts.join('&').replace(/%20/g, '+');
}
```

### 3. FormData 不能预设 Content-Type

```javascript
// ❌ 错误：手动设 Content-Type 会丢失 boundary
fetchOpts.headers['Content-Type'] = 'multipart/form-data';
fetchOpts.body = formData;

// ✅ 正确：不设 Content-Type，浏览器自动加 boundary
if (data instanceof FormData) {
    fetchOpts.body = data;
    // 不要设 Content-Type
}
```

### 4. NodeList.forEach 兼容性

```javascript
// ✅ 现代浏览器支持
document.querySelectorAll('.btn').forEach(function(el) { ... });

// ✅ 兼容旧浏览器
var els = document.querySelectorAll('.btn');
Array.prototype.forEach.call(els, function(el) { ... });
```

### 5. 异步确认弹窗的按钮状态管理

`htmx:confirm` 事件中调用异步 API（如 `XN.confirm`）时，必须设置 `window._htmxConfirmAsync = true` 阻止 `setTimeout(0)` 提前恢复按钮。回调/取消/异常时必须重置为 `false`。详见第四节范式。

---

## 八、相关文档

- [05-frontend-security.md](05-frontend-security.md) — 前端规范与安全（htmx 4、Bootstrap、XSS）
- [06-ai-collaboration.md](06-ai-collaboration.md) — AI 协作规范（硬规则、扫描器、检查表）
- `view/js/xiuno-modern.js` — XN API 源码（`XN.toast`/`XN.ajax`/`XN.confirm`）
- `admin/view/htm/online_upgrade.htm` — 关键修复页面改造范例
- `admin/view/htm/upgrade.htm` — 数据库升级页改造范例
