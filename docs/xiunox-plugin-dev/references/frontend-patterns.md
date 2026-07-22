# XIUNOX 插件前端交互模式参考

> 本文件沉淀插件开发中反复出现的前端交互模式与坑点，是 [SKILL.md](../SKILL.md) 的补充。涉及 htmx 多卡片、lightbox、图片上传、表单提交等具体实现细节。

---

## 1. 多卡片共用同一 form 的 htmx 监听器

### 问题

`post.htm` 同一个 `<form>` 内同时注入 PC 右侧栏和手机端表单内两个 hook：

- PC：`post_ref_thread_after.htm`（`d-none d-xl-block` 容器）
- mobile：`post_ref_thread_after_mobile.htm`（`d-xl-none` 容器）

两个卡片在 DOM 中同时存在，CSS 控制显隐。如果每个卡片各自注册 `htmx:config:request` 监听器，后注册的监听器可能覆盖前者的 form data，或被隐藏卡片的早返回拦截，导致提交数据始终为空。

### 正确范式

**只在 form 上注册一次监听器**，触发时遍历所有卡片实例，仅对可见卡片（`offsetParent !== null`）序列化：

```javascript
var cardInstances = []; // initCard 时 push 进去

function findVisibleCard() {
    for (var i = 0; i < cardInstances.length; i++) {
        if (cardInstances[i].el.offsetParent !== null) {
            return cardInstances[i];
        }
    }
    return null;
}

// init() 中只注册一次 form 监听器
form.addEventListener('htmx:config:request', function(evt) {
    var visible = findVisibleCard();
    if (!visible) return;
    var json = visible.serialize();
    if (evt.detail && evt.detail.parameters) {
        evt.detail.parameters['poll_data'] = json;
    }
});
```

### 关键提醒

- `htmx:config:request` 触发时 `new FormData(form)` 已构建完成，**修改 DOM hidden input value 不会进入已构建的 FormData**，必须改 `evt.detail.parameters`
- 或者改用方案 A：mobile hook 的表单字段名全部加 `_m` 后缀，后端按可见卡片的 enabled 标志位取对应字段值（见 SKILL.md 禁止清单）

---

## 2. 图片放大 lightbox 全局组件

### 规则

图片放大功能**禁止**在多个页面/插件各写一套独立 Modal + JS，必须复用全局组件 `view/js/lightbox.js` + 全局 `#xnLightbox` Modal（已在 `view/htm/footer.inc.htm` 全局注入）。

### 自动覆盖范围

| 选择器 | 场景 |
|---|---|
| `.message img` | 帖子主帖/回复正文图片 |
| `.appcenter-intro-content img` | appcenter 应用介绍正文内嵌图片 |
| `.appcenter-screenshots img` | appcenter 应用截图 |
| `a[data-lightbox]` | 显式标记的截图链接 |
| `[data-lightbox-container] img` | 自定义容器 |

### 显式禁用单张图片

```html
<img src="..." data-no-lightbox>
```

### 关键设计

1. **同容器内轮播**：点击图片时用 `closest(CONTAINER_SELECTORS)` 找最近的容器，容器内所有 img 作为一个轮播组
2. **接管 `<a>` 包裹的图片**：只有 `<a target="_blank">` 或 href 非图片 URL 时才保留默认跳转
3. **缩放交互**：滚轮、双击切换 1x/2x、按钮 +/-、键盘 +/-、触摸双指缩放，范围 0.5x~5x
4. **拖拽**：仅 scale>1 时启用
5. **旋转**：`r` 键或按钮，90° 递增
6. **重置**：`0` 键或按钮，关闭 Modal 时自动重置
7. **htmx 兼容**：监听 `htmx:after:swap` 重新绑定

### 检查清单

- [ ] 禁止新建独立 lightbox Modal/JS
- [ ] 新场景的图片容器加 `[data-lightbox-container]` 或专用 class
- [ ] 显式截图链接用 `<a href="原图URL" data-lightbox><img></a>`
- [ ] 跳过 emoji/icon 字体 img（组件已自动跳过）
- [ ] 单张图片显式禁用加 `data-no-lightbox`

---

## 3. AIEditor 图片上传后立即 promote

### 问题

AIEditor 上传走核心 `attach-create` API 时文件落到 `upload/tmp/`，编辑器拿到的是 `tmp/` URL。如果保存时 HTML 直接入库（含 `tmp/` URL），1 天后会被 `attach_gc()` 每日清理导致 URL 失效（"次日消失"）。

核心 `attach_assoc_post()` 已对 post 表做兜底扫描，但**仅用于 post 表**，插件自定义表不会自动处理。

### 正确范式

新增 promote API，编辑器 uploader 上传到 tmp/ 后立即调 promote API 转为正式 attach，编辑器拿到 attach/ 最终 URL：

```javascript
function promoteImage(tmpKey) {
    return new Promise(function(resolve, reject) {
        var fd = new FormData();
        fd.append('tmp_key', tmpKey);
        fd.append('csrf_token', csrfToken);
        fetch(promoteUrl, { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.ok && res.url) resolve({url: res.url, aid: res.aid});
                else reject(res.message || 'promote failed');
            }).catch(reject);
    });
}

// AIEditor uploader
uploader: function(file) {
    return uploadFile(file).then(function(msg) {
        var tmpKey = msg.aid || '';
        if (!tmpKey) return {errorCode: 0, data: {src: msg.url || '', alt: ''}};
        return promoteImage(tmpKey).then(function(promoted) {
            return {errorCode: 0, data: {src: promoted.url, alt: msg.orgfilename || ''}};
        }).catch(function() {
            return {errorCode: 0, data: {src: msg.url || '', alt: ''}}; // 降级
        });
    });
}
```

---

## 4. 多文件上传禁止并行 fetch

### 问题

核心 `route/attach.php` 用 `$_SESSION['tmp_files']` 数组 + `max(array_keys)+1` 计数器管理临时文件。多个并行 fetch 上传请求都读到相同初始 session，写入时后者覆盖前者，最终只剩一个 tmp_file。

### 正确范式

递归串行上传：

```javascript
var files = Array.prototype.slice.call(this.files);
var idx = 0;

function uploadNext() {
    if (idx >= files.length) return;
    var file = files[idx++];
    uploadFile(file, function(res) {
        addScreenshotItem(res);
        uploadNext();
    }, null);
}
uploadNext();
```

### 判断规则

- 所有 AIEditor 等富文本编辑器上传图片的 uploader，必须在上传成功后立即调 promote API
- 所有"一次选多文件上传"的场景，必须串行上传，禁止并行 fetch

---

## 5. 后台表单提交优化范式

### 推荐做法

1. **表单提交改为 fetch 拦截**：避免页面跳转
2. **按钮 loading spinner**：提交期间禁用按钮并显示 loading 状态，防止重复提交
3. **toast 反馈**：成功/失败用 `XN.toast()` 右上角提示
4. **前端范围校验**：数字字段提交前校验范围
5. **删除无意义单选项**：下拉框只有一个选项时直接删除

### 范式代码

```javascript
form.addEventListener('submit', function(e) {
    e.preventDefault();
    var errMsg = validateRange();
    if (errMsg) {
        if (window.XN && XN.toast) { XN.toast(errMsg, 'warning'); }
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span><span>保存</span>';
    fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) { return r.json(); })
      .then(function(res) {
          if (window.XN && XN.toast) {
              XN.toast(res.message || '', res.code == 0 ? 'success' : 'danger');
          }
      }).catch(function(err) {
          if (window.XN && XN.toast) { XN.toast(err.message || err, 'danger'); }
      }).finally(function() {
          btn.disabled = false;
          btn.innerHTML = origHtml;
      });
});
```

---

## 6. 动态增删列表项时同步刷新状态

### 规则

动态增删选项列表时，`refreshOptionRows()` 必须同步更新：

1. 选项序号（`<span class="input-group-text">N</span>`）
2. placeholder（`选项 N`）
3. 删除按钮的 disabled 状态（达到最小数量时禁用）

### 范式

```javascript
function refreshOptionRows() {
    if (!optionsList) return;
    var rows = optionsList.querySelectorAll('.poll-option-row');
    var cur = 0;
    rows.forEach(function(row) {
        cur++;
        var span = row.querySelector('.input-group-text');
        var del = row.querySelector('.poll-option-del');
        var optInput = row.querySelector('.poll-option');
        if (span) span.textContent = String(cur);
        if (optInput) optInput.placeholder = I18N.options + ' ' + cur;
        if (del) {
            if (rows.length <= minN) {
                del.setAttribute('disabled', 'disabled');
            } else {
                del.removeAttribute('disabled');
            }
        }
    });
}
```

---

## 7. 自定义 tab 切换属性命名

### 规则

插件自定义 tab 切换逻辑（非 Bootstrap 5 原生 `data-bs-toggle="tab"`）**禁止**用 `data-target` 属性存储目标 pane 选择器。扫描器会把 `data-target` 判定为 Bootstrap 4 遗留属性建议改成 `data-bs-target`，但实际是自定义 JS 读取的属性。

改成 `data-bs-target` 又会导致 Bootstrap 5 误识别为 tab 触发器（冲突）。

### 正确范式

用自定义属性名 `data-pane-target`：

```html
<button type="button" class="special-topic-btn" data-pane-target="#pane-xxx" data-tab-key="xxx">
```

```javascript
function getPaneByBtn(btn) {
    var target = btn.getAttribute('data-pane-target');
    return target ? document.querySelector(target) : null;
}
```

### 判断规则

所有非 Bootstrap 5 原生 tab/collapse/modal 组件的自定义 data 属性，禁止用 `data-target`/`data-bs-target`/`data-toggle`/`data-bs-toggle` 等 Bootstrap 保留属性名，改用 `data-pane-target`/`data-count-target` 等自定义名。
