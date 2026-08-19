# 粘贴大段 Word 富文本卡死浏览器——MutationObserver 死循环踩坑

> XIUNOX 开发者经验复盘 · 2026-08-18

## 一、问题

用户反馈发帖页粘贴大段 Word 富文本内容时浏览器卡死，无响应；「插入 Markdown」弹窗里粘贴大段内容同样卡死。

最初的判断是 AIEditor 内置 ProseMirror 的 DOMParser 性能瓶颈——891KB HTML 富文本解析为 ProseMirror Node 树，节点数量爆炸。但深入调试发现一个关键反常点：**同步 insert 调用 84ms 就返回了**，浏览器却依然卡死。这推翻了"卡在 ProseMirror 同步解析"的判断。

## 二、根因

调用栈暴露真凶——**`xnx_outlink` 插件的 MutationObserver 与 AIEditor 内置 MutationObserver 形成 echo 死循环**：

```
ProseMirror view.updateState 递归渲染 DOM
  → 触发 childList 变化
  → outlink.js MutationObserver 遍历新 <a> 调用 processLink
  → processLink 调用 setAttribute('href'/'data-outlink'/'target'/'rel') 共 4 次
  → 触发 attributes 变化
  → AIEditor 内置 MutationObserver 收到 attributes → flush → 触发 view 更新
  → 又触发 childList 变化 → 回到第 2 步
```

死循环直到主线程被同步任务占用直到崩溃，**console.log 都排不上队**——所以最初看到的日志戛然而止，没有任何错误信息。

测试对比关键数据：
- 粘贴并匹配样式（5277 bytes，外链少）→ 走 AIEditor 原生 handlePaste，循环能收敛，正常
- 普通粘贴（891KB，外链多）→ 走清理路径 + 大量链接，循环爆炸，卡死

## 三、修复

### 1. outlink.js MutationObserver 跳过编辑器内 DOM 变化

```js
var observer = new MutationObserver(function(mutations) {
    for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (m.target.closest && m.target.closest('.aie-content, .ProseMirror')) continue;
        // ... 原有处理逻辑
    }
});
```

编辑器内的外链由后端 `post_create`/`thread_create` 流程统一处理，不需要前台 MutationObserver 介入。

### 2. xiuno-modern.js `_captchaObserver` 同步修复

虽然 `_captchaObserver` 回调只读 `hasAttribute` 不写 `setAttribute`，不会形成死循环，但**为了防御性 + 性能**也加跳过——避免大段粘贴时上千次 addedNodes 遍历的性能浪费，且防御未来回调逻辑变更加入 setAttribute 时形成死循环。

### 3. EditorService.php 新增粘贴 HTML >100KB 自动清理

死循环修复后，891KB 走 AIEditor 原生 PasteExt 仍可能因 ProseMirror DOMParser 解析高密度 HTML 慢。再加一层前端清理：

- container `paste` 事件 capture 阶段拦截
- `DOMParser` 解析后剥离：Office 命名空间元素（`<o:p>`/`<w:*>`/`<v:*>`）、非语义属性（style/class/align 等）、空 span、空 p/div
- **保留粗体斜体**：先把 `<span style="font-weight:bold">` / `<span style="font-style:italic">` 转换为 `<strong>` / `<em>` 语义标签，再走剥离流程
- 清理后 891KB → 13KB（-98%），ProseMirror DOMParser 解析飞快
- 失败回退纯文本插入

### 4. 版本号递增

- `xnx_outlink/conf.json` 1.0.4 → 1.0.5
- `static_version` 1.4.21 → 1.4.22

## 四、反思

### 1. 调试方法学：埋点 + 心跳定位"无日志卡死"

当 console 输出戛然而止，不能武断判定"卡在某行代码"。可能：
- 主线程被同步任务持续占用（setTimeout/setInterval 排不上队）
- 浏览器直接 OOM 崩溃

加 `setTimeout(0) ✓ 主线程恢复空闲` + `setInterval(50ms) heartbeat` 探测：心跳停止的那一行就是主线程被占用的起点。这次没用上是因为同步任务一直没让出来——但日志格式确认了"主线程死了"。

### 2. Shift 键对照测试揭示路径差异

让用户按 Shift 粘贴走纯文本路径对照，加上对比「粘贴并匹配样式」（Mac 系统级纯文本+少量格式）vs「正常粘贴」，三种路径的调用栈对比暴露了 `handlePaste` vs `insert` 路径区别——`handlePaste` 路径不卡死的关键不在体积而在**循环结构**。

### 3. MutationObserver 死循环触发三条件

满足以下三条件必然卡死：
1. 监听 `document.body` 或 `document.documentElement` + `subtree:true + childList:true`
2. 回调里对 addedNodes 调用 `setAttribute`/`removeAttribute`/`classList.add` 等
3. 不做防重入、不跳过编辑器内变化

`hx-live.min.js` 是另一种正确设计的范本——`disconnect` + `queueMicrotask` + 限频 50/sec + 防重入 `r` 标志。

### 4. 沉淀规则

两条规则沉淀至 `.trae/rules/bugfix_rules.md`：
- **MutationObserver 监听 document.body/documentElement + subtree:true 必须跳过编辑器内 DOM 变化**
- **粘贴大段 Word/邮件富文本（>100KB HTML）必须由前端清理后插入**

未来新增/修改 MutationObserver 或粘贴处理逻辑，这两条规则强制约束——避免类似问题再次发生。

### 5. 第三方库不可控，但入口可控

ProseMirror 是 AIEditor 内置的第三方库，改不了内部 DOMParser 性能。但可以在 paste capture 阶段预先清理 HTML，让 ProseMirror 收到的就是清理后的小 HTML。这是富文本编辑器（TinyMCE/CKEditor/Quill）的标配做法，叫 "clean paste"。

---

**修改文件清单**：
- `plugin/xnx_outlink/static/js/outlink.js` — MutationObserver 跳过编辑器内 DOM
- `plugin/xnx_outlink/conf.json` — 1.0.4 → 1.0.5
- `view/js/xiuno-modern.js` — `_captchaObserver` 同步修复
- `conf/conf.default.php` — `static_version` 1.4.21 → 1.4.22
- `lib/EditorService.php` — 粘贴 HTML >100KB 自动清理 IIFE（含粗体斜体语义转换）
- `.trae/rules/bugfix_rules.md` — 沉淀两条规则
