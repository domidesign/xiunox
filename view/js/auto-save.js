/*!
 * AutoSave - 表单内容自动保存（草稿箱）
 * 基于浏览器 localStorage，纯原生 JS，零依赖
 * 兼容 Chrome / Firefox / Safari / Edge
 *
 * 用法（声明式）：
 *   <form data-autosave="thread_create" data-autosave-context="<?php echo $fid;?>">
 *   在 footer.inc.htm 全局加载后自动初始化，无需手写 JS
 *
 * 用法（编程式）：
 *   AutoSave.init();                          // 扫描页面所有 data-autosave 表单
 *   AutoSave.save('thread_create', data);     // 手动保存
 *   var data = AutoSave.restore('thread_create'); // 手动恢复
 *   AutoSave.clear('thread_create');          // 手动清除
 */
(function (window, document) {
    'use strict';

    var DEBOUNCE_DELAY = 500;       // 输入停止后 500ms 保存
    var EXPIRE_MS = 7 * 24 * 60 * 60 * 1000;  // 7 天过期
    var SCHEMA_VERSION = 1;          // 数据格式版本
    var KEY_PREFIX = 'draft_';
    // ponytail: 最小内容长度——短于该长度的内容不保存草稿
    // 避免"顶""好""支持"等短回复产生无意义草稿堆积（参考 Reddit 评论框不保存草稿的策略，
    // 但采用更温和的长度阈值而非完全禁用，保留短回复的草稿能力）
    var MIN_CONTENT_LENGTH = 10;
    // 不保存的字段 name 关键词（不区分大小写）
    var SENSITIVE_NAME_RE = /captcha|verify|code|token|password|csrf/i;
    // 不保存的 input type
    var SKIP_TYPES = { password: 1, file: 1, submit: 1, button: 1, reset: 1, image: 1 };

    // 全局 uid（由 footer.inc.htm 暴露的 var uid）
    var _uid = 0;

    /**
     * 生成草稿 storage key
     * 格式: draft_<formType>_u<uid>_<context>
     */
    function buildKey(formType, context) {
        var ctx = context || 'default';
        return KEY_PREFIX + formType + '_u' + _uid + '_' + ctx;
    }

    /**
     * 安全读取 localStorage（兼容无痕模式）
     */
    function storageGet(key) {
        try {
            var raw = localStorage.getItem(key);
            if (!raw) return null;
            var obj = JSON.parse(raw);
            if (!obj || typeof obj !== 'object') return null;
            // 版本不匹配 → 丢弃
            if (obj.v !== SCHEMA_VERSION) return null;
            // 过期检查
            if (!obj.ts || Date.now() - obj.ts > EXPIRE_MS) {
                storageRemove(key);
                return null;
            }
            return obj;
        } catch (e) {
            // JSON 解析失败或 localStorage 不可用 → 清理坏数据
            storageRemove(key);
            return null;
        }
    }

    /**
     * 安全写入 localStorage
     */
    function storageSet(key, data) {
        try {
            var payload = JSON.stringify({
                v: SCHEMA_VERSION,
                data: data,
                ts: Date.now()
            });
            localStorage.setItem(key, payload);
            return true;
        } catch (e) {
            // QuotaExceededError 或 localStorage 不可用 → 静默失败
            return false;
        }
    }

    /**
     * 安全删除 localStorage key
     * ponytail: 同时清理 AIEditor 遗留的 contentRetentionKey（ai-editor-content）
     * AIEditor 内置内容保留机制已在 EditorService 中禁用，但 localStorage 中可能仍有
     * 禁用前保存的旧数据，需在清除草稿时一并清理，避免旧内容被恢复
     */
    function storageRemove(key) {
        try {
            localStorage.removeItem(key);
        } catch (e) {
            // 静默失败
        }
        // 清理 AIEditor 遗留的内容保留 key
        try {
            localStorage.removeItem('ai-editor-content');
        } catch (e) {}
    }

    /**
     * 判断草稿数据是否包含实质性用户内容
     * 只看用户可输入字段，忽略 doctype/quotepid 等隐藏默认字段
     * ponytail: 必须忽略 '0' 值（hidden 字段默认值如 doctype=0, quotepid=0, fid=0），
     * 否则空表单（只有默认值）也会被判定为"有内容"而保存草稿（"空内容也存了草稿"根因）
     */
    function hasUserContent(data) {
        if (!data || typeof data !== 'object') return false;
        // 检查 AIEditor 富文本内容
        if (data._editor_html) {
            // 去除空标签后判断是否有实质内容
            var text = String(data._editor_html).replace(/<[^>]+>/g, '').replace(/&nbsp;/g, '').trim();
            if (text) return true;
        }
        // 检查用户可输入字段
        for (var k in data) {
            if (k === '_editor_html') continue;
            var val = data[k];
            if (val === '' || val === false || val === null) continue;
            if (typeof val === 'string') {
                var trimmed = val.trim();
                // 忽略 '0' 值（hidden 字段默认值如 doctype=0, quotepid=0, fid=0）
                if (trimmed === '' || trimmed === '0') continue;
                return true;
            }
            // checkbox/radio 选中状态
            if (val === true) return true;
            // 数组（select-multiple）
            if (Array.isArray(val) && val.length > 0) return true;
        }
        return false;
    }

    /**
     * 计算草稿数据的总内容长度（字符数）
     * 用于判断是否达到最小保存长度阈值
     */
    function getContentLength(data) {
        if (!data || typeof data !== 'object') return 0;
        var total = 0;
        // AIEditor 富文本内容长度（去除标签后的纯文本）
        if (data._editor_html) {
            var text = String(data._editor_html).replace(/<[^>]+>/g, '').replace(/&nbsp;/g, '').trim();
            total += text.length;
        }
        // 其他用户可输入字段
        for (var k in data) {
            if (k === '_editor_html') continue;
            var val = data[k];
            if (typeof val === 'string') {
                var trimmed = val.trim();
                if (trimmed === '' || trimmed === '0') continue;
                total += trimmed.length;
            }
        }
        return total;
    }

    /**
     * 判断是否应该保存草稿
     * ponytail: 必须同时满足两个条件：
     *   1. 有实质性用户内容（hasUserContent）
     *   2. 内容总长度 >= MIN_CONTENT_LENGTH（避免短回复产生无意义草稿）
     */
    function shouldSaveDraft(data) {
        if (!hasUserContent(data)) return false;
        if (getContentLength(data) < MIN_CONTENT_LENGTH) return false;
        return true;
    }

    /**
     * 判断字段是否应该保存
     */
    function shouldSaveField(el) {
        if (!el || !el.name) return false;
        var type = (el.type || '').toLowerCase();
        // 跳过敏感 type
        if (SKIP_TYPES[type]) return false;
        // 跳过 name 含敏感关键词的字段
        if (SENSITIVE_NAME_RE.test(el.name)) return false;
        // hidden 类型只保存非敏感的（doctype/quotepid/message 等）
        if (type === 'hidden') return true;
        // text/url/email/textarea/select 保存
        if (type === 'text' || type === 'url' || type === 'email' ||
            type === 'textarea' || type === 'select-one' || type === 'select-multiple' ||
            type === 'search' || type === 'tel' || type === 'number') {
            return true;
        }
        // checkbox/radio 保存选中状态
        if (type === 'checkbox' || type === 'radio') {
            return el.checked;
        }
        return false;
    }

    /**
     * 序列化表单字段
     * @returns {Object} name → value 映射
     */
    function serializeForm(form) {
        var data = {};
        var els = form.elements;
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (!shouldSaveField(el)) continue;
            if (el.type === 'checkbox') {
                data[el.name] = el.checked;
            } else if (el.type === 'radio') {
                if (el.checked) data[el.name] = el.value;
            } else if (el.type === 'select-multiple') {
                var vals = [];
                for (var j = 0; j < el.options.length; j++) {
                    if (el.options[j].selected) vals.push(el.options[j].value);
                }
                data[el.name] = vals;
            } else {
                data[el.name] = el.value;
            }
        }
        // AIEditor 富文本内容：从编辑器实例直接读取（比 hidden input 更可靠）
        var editor = getFormEditor(form);
        if (editor && typeof editor.getHtml === 'function') {
            try {
                data._editor_html = editor.getHtml();
            } catch (e) {
                // 编辑器未就绪，跳过
            }
        }
        return data;
    }

    /**
     * 恢复表单字段
     */
    function restoreForm(form, data) {
        if (!data || typeof data !== 'object') return false;
        var restored = false;
        var els = form.elements;
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (!el.name || !(el.name in data)) continue;
            if (!shouldSaveField(el)) continue;
            var val = data[el.name];
            if (el.type === 'checkbox') {
                el.checked = !!val;
                restored = true;
            } else if (el.type === 'radio') {
                if (el.value === String(val)) {
                    el.checked = true;
                    restored = true;
                }
            } else if (el.type === 'select-multiple') {
                if (Array.isArray(val)) {
                    for (var j = 0; j < el.options.length; j++) {
                        el.options[j].selected = val.indexOf(el.options[j].value) !== -1;
                    }
                    restored = true;
                }
            } else {
                // 不覆盖 AIEditor 的 message hidden input（由编辑器 setHtml 处理）
                if (el.name === 'message' && getFormEditor(form)) continue;
                el.value = val;
                restored = true;
            }
        }
        // AIEditor 富文本恢复
        var editor = getFormEditor(form);
        if (editor && data._editor_html && typeof editor.setContent === 'function') {
            try {
                editor.setContent(data._editor_html);
                restored = true;
            } catch (e) {
                // 编辑器未就绪，延迟重试一次
                setTimeout(function () {
                    try { editor.setContent(data._editor_html); } catch (e2) {}
                }, 300);
            }
        }
        return restored;
    }

    /**
     * 获取表单关联的 AIEditor 实例
     * 全局只有一个 aiEditorInstance，需确认它属于当前 form
     * AIEditor 实际 DOM 结构：容器 #aieditor-container，内部含 .aieditor / .aie-content
     */
    function getFormEditor(form) {
        var editor = window.aiEditorInstance;
        if (!editor) return null;
        // 检查 form 内是否有 AIEditor 容器（AIEditor 无 getDom 方法，用容器 id 判断）
        if (form.querySelector('#aieditor-container, .aieditor-container, .aieditor, .aie-content')) {
            return editor;
        }
        return null;
    }

    /**
     * 清空表单中由草稿恢复的字段
     */
    function clearFormFields(form) {
        var els = form.elements;
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (!shouldSaveField(el)) continue;
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.checked = false;
            } else if (el.type === 'select-multiple') {
                el.selectedIndex = -1;
            } else {
                el.value = '';
            }
        }
        var editor = getFormEditor(form);
        if (editor && typeof editor.setContent === 'function') {
            try { editor.setContent(''); } catch (e) {}
        }
    }

    /**
     * 创建恢复提示条 UI
     */
    function createRestoreBar(form, onDiscard, onClose) {
        // 移除已有提示条
        var existing = form.previousElementSibling;
        if (existing && existing.classList.contains('autosave-restore-bar')) {
            existing.remove();
        }

        var bar = document.createElement('div');
        bar.className = 'autosave-restore-bar alert alert-info d-flex align-items-center py-2 mb-2';
        bar.setAttribute('role', 'alert');
        bar.innerHTML =
            '<i class="ti ti-clock-history me-2"></i>' +
            '<span class="flex-fill small">' + getLang('draft_restored') + '</span>' +
            '<button type="button" class="btn btn-sm btn-link text-decoration-none py-0 px-1 ms-2" data-action="discard">' +
            getLang('discard_draft') + '</button>' +
            '<button type="button" class="btn-close btn-sm ms-1 py-0" data-action="close" aria-label="Close"></button>';

        bar.querySelector('[data-action="discard"]').addEventListener('click', function () {
            bar.remove();
            if (onDiscard) onDiscard();
        });
        bar.querySelector('[data-action="close"]').addEventListener('click', function () {
            bar.remove();
            if (onClose) onClose();
        });

        // 插入到 form 之前
        form.parentNode.insertBefore(bar, form);
        return bar;
    }

    /**
     * 获取语言字符串（从全局 bbs_lang 或默认中文）
     */
    var _lang = {
        draft_restored: '已恢复未提交的草稿',
        discard_draft: '放弃草稿'
    };
    function getLang(key) {
        if (window.bbs_lang && window.bbs_lang[key]) return window.bbs_lang[key];
        return _lang[key] || key;
    }

    /**
     * debounce 工具
     */
    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var ctx = this, args = arguments;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () {
                timer = null;
                fn.apply(ctx, args);
            }, delay);
        };
    }

    /**
     * 初始化单个表单的自动保存
     */
    function initForm(form) {
        var formType = form.getAttribute('data-autosave');
        var context = form.getAttribute('data-autosave-context') || '';
        if (!formType) return;

        var key = buildKey(formType, context);

        // ---- 恢复草稿 ----
        var draft = storageGet(key);
        if (draft && draft.data) {
            // 只在草稿含实质性用户内容时才恢复（避免恢复只有 doctype=0 的空草稿）
            if (hasUserContent(draft.data)) {
                // 普通字段立即恢复
                var restored = restoreForm(form, draft.data);
                if (restored) {
                    createRestoreBar(form, function onDiscard() {
                        // 放弃草稿：清除存储 + 清空表单
                        storageRemove(key);
                        clearFormFields(form);
                    }, function onClose() {
                        // 仅关闭提示条，保留草稿内容
                    });
                }
                // AIEditor 富文本恢复：编辑器异步初始化，等待就绪后 setHtml
                if (draft.data._editor_html) {
                    restoreEditorContent(form, draft.data._editor_html);
                }
            } else {
                // 草稿无实质内容，清除无效草稿
                storageRemove(key);
            }
        }

        // ---- 输入监听 + debounce 保存 ----
        var debouncedSave = debounce(function () {
            var data = serializeForm(form);
            // ponytail: 用 shouldSaveDraft 替代 hasUserContent
            // 短于 MIN_CONTENT_LENGTH 的内容不保存草稿（避免"顶""好"等短回复产生无意义草稿）
            if (shouldSaveDraft(data)) {
                storageSet(key, data);
            } else {
                // 无实质内容或内容太短 → 清除草稿（用户清空了输入或输入过短）
                storageRemove(key);
            }
        }, DEBOUNCE_DELAY);

        // input/change 事件：捕获 textarea/input/select 变化
        form.addEventListener('input', debouncedSave);
        form.addEventListener('change', debouncedSave);

        // AIEditor 内容变化监听：编辑器 onChange 不触发 form input 事件，
        // 需主动 hook。编辑器异步初始化，等待就绪后挂载监听。
        hookEditorChange(form, debouncedSave);

        // ---- 提交成功后清除草稿 ----
        initSubmitSuccessClear(form, key);

        // ---- 页面卸载前保存一次 ----
        // ponytail: 提交成功后 htmx 触发页面跳转，beforeunload 会触发，但此时表单字段还在，
        // 如果不检查 _autosaveSubmitSuccess 标志位，会把残留内容重新保存为草稿
        // （"帖子发布后没有清空草稿"根因：initSubmitSuccessClear 清除草稿后 beforeunload 又重新保存）
        window.addEventListener('beforeunload', function () {
            if (form._autosaveSubmitSuccess) return;  // 提交成功，不再保存草稿
            var data = serializeForm(form);
            if (shouldSaveDraft(data)) storageSet(key, data);
        });
    }

    /**
     * 等待 AIEditor 就绪后恢复富文本内容
     * AIEditor 异步初始化（EditorService 有重试机制），需轮询等待
     */
    function restoreEditorContent(form, html) {
        var retries = 0;
        var maxRetries = 60; // 最多等待 6 秒（60 × 100ms）
        function tryRestore() {
            var editor = getFormEditor(form);
            if (editor && typeof editor.setContent === 'function') {
                try {
                    editor.setContent(html);
                    return;
                } catch (e) {
                    // setContent 失败，稍后重试
                }
            }
            retries++;
            if (retries < maxRetries) {
                setTimeout(tryRestore, 100);
            }
        }
        tryRestore();
    }

    /**
     * Hook AIEditor 的内容变化
     * AIEditor 异步初始化，需轮询等待实例就绪后挂载 onChange 监听
     */
    function hookEditorChange(form, saveFn) {
        var retries = 0;
        var maxRetries = 60; // 最多等待 6 秒
        var hooked = false;
        function tryHook() {
            if (hooked) return;
            var editor = getFormEditor(form);
            if (editor) {
                // 优先用 AIEditor 的 onChange 事件（如果支持）
                // 注意：AIEditor 初始化时已配置 onChange，这里用轮询监听 content 变化
                // 方案：监听编辑器容器内的 input 事件（contenteditable 元素）
                var container = form.querySelector('#aieditor-container');
                if (container) {
                    container.addEventListener('input', saveFn);
                    hooked = true;
                    return;
                }
            }
            retries++;
            if (retries < maxRetries) {
                setTimeout(tryHook, 100);
            }
        }
        tryHook();
    }

    /**
     * 监听表单提交成功并清除草稿
     * 支持 htmx 表单和传统 PRG 表单
     * ponytail: htmx 成功检测优先用后端 HX-Trigger 头触发的 htmxSuccessRedirect/htmxSuccess 事件
     * （事件在 form 上冒泡接收），比 htmx:after:request 检查响应文本更可靠：
     *   - htmxSuccessRedirect：成功 + 跳转（发帖成功场景）
     *   - htmxSuccess：成功无跳转
     * 兜底：htmx:after:request 检查响应文本（处理非标准成功响应，如快速回复返回 HTML 片段）
     */
    function initSubmitSuccessClear(form, key) {
        var isHtmx = form.hasAttribute('hx-post') || form.hasAttribute('hx-put');

        // ponytail: 统一的成功处理——清除草稿存储 + 移除恢复提示条 + 设置成功标志位
        // 快速回复成功后 form.reset() 只清空表单字段，不会移除 .autosave-restore-bar 提示条，
        // 必须在此统一处理，否则提示条会残留在页面上
        function clearDraftAndUI() {
            storageRemove(key);
            form._autosaveSubmitSuccess = true;
            var bar = form.previousElementSibling;
            if (bar && bar.classList.contains('autosave-restore-bar')) {
                bar.remove();
            }
        }

        if (isHtmx) {
            // ponytail: 优先监听后端 HX-Trigger 头触发的成功事件（最可靠）
            // 后端 message(0, ..., array('redirect_url'=>...)) 返回 HX-Trigger: htmxSuccessRedirect
            // 后端 message(0, ...) 无跳转返回 HX-Trigger: htmxSuccess
            // 这两个事件由 htmx 在处理响应头时触发，从 form 冒泡到 document
            form.addEventListener('htmxSuccessRedirect', function () {
                clearDraftAndUI();
            });
            form.addEventListener('htmxSuccess', function () {
                clearDraftAndUI();
            });

            // 兜底：htmx:after:request 检查响应文本（处理非标准成功响应，如快速回复返回 HTML 片段）
            form.addEventListener('htmx:after:request', function (event) {
                // 如果已通过 htmxSuccessRedirect/htmxSuccess 标记成功，跳过
                if (form._autosaveSubmitSuccess) return;

                var responseText = '';
                try {
                    responseText = (event.detail && event.detail.ctx && event.detail.ctx.text) || '';
                } catch (e) {
                    return;
                }

                // 尝试 JSON 解析（兼容 JSON 响应）
                try {
                    var json = JSON.parse(responseText);
                    if (json.code === 0) {
                        clearDraftAndUI();
                        return;
                    }
                    if (json.code < 0) {
                        // 业务错误，保留草稿
                        return;
                    }
                } catch (e) {
                    // 非 JSON 响应，继续检查 HTML 错误标记
                }

                // 检查 HTML 响应中是否含错误标记
                if (responseText.indexOf('data-code="-') !== -1 ||
                    responseText.indexOf('"code":-') !== -1) {
                    // 含错误标记，保留草稿
                    return;
                }

                // 无错误标记 → 假设成功，清除草稿
                clearDraftAndUI();
            });
        } else {
            // 传统表单 PRG：提交前标记 pending，下次页面加载检测成功 flash
            form.addEventListener('submit', function () {
                form._autosaveSubmitSuccess = true;
                try {
                    sessionStorage.setItem('autosave_pending_' + key, '1');
                } catch (e) {}
            });
        }
    }

    /**
     * 检查传统表单的提交结果（页面加载时调用）
     * 如果有 pending 标记且检测到成功 flash，清除草稿
     */
    function checkPendingSubmit() {
        try {
            var keys = Object.keys(sessionStorage);
            for (var i = 0; i < keys.length; i++) {
                var k = keys[i];
                if (k.indexOf('autosave_pending_') !== 0) continue;

                var draftKey = k.substring('autosave_pending_'.length);
                var flashMsg = getCookie('flash_msg');
                var flashType = getCookie('flash_type') || 'success';

                if (flashMsg && flashType === 'success') {
                    // 提交成功，清除草稿
                    storageRemove(draftKey);
                }
                sessionStorage.removeItem(k);
            }
        } catch (e) {
            // sessionStorage 不可用，静默失败
        }
    }

    function getCookie(name) {
        try {
            var value = '; ' + document.cookie;
            var parts = value.split('; ' + name + '=');
            if (parts.length === 2) return parts.pop().split(';').shift();
        } catch (e) {}
        return '';
    }

    // ========== 公开 API ==========

    var AutoSave = {
        /**
         * 初始化：扫描页面所有 data-autosave 表单
         */
        init: function (options) {
            // 全局开关检测
            if (window.XN_AUTO_SAVE_DISABLE === true) return;

            // 读取全局 uid（footer.inc.htm 暴露的 var uid）
            _uid = (typeof window.uid !== 'undefined') ? parseInt(window.uid, 10) || 0 : 0;

            // 检查传统表单的 pending 提交结果
            checkPendingSubmit();

            // 扫描所有带 data-autosave 属性的表单
            var forms = document.querySelectorAll('form[data-autosave]');
            for (var i = 0; i < forms.length; i++) {
                try {
                    initForm(forms[i]);
                } catch (e) {
                    // 单个表单初始化失败不影响其他表单
                    if (window.console && console.warn) {
                        console.warn('[AutoSave] 表单初始化失败:', e);
                    }
                }
            }
        },

        /**
         * 手动保存
         * @param {string} formType - 表单类型
         * @param {Object} data - 要保存的数据
         * @param {string} [context] - 上下文标识
         */
        save: function (formType, data, context) {
            var key = buildKey(formType, context);
            return storageSet(key, data);
        },

        /**
         * 手动恢复
         * @param {string} formType - 表单类型
         * @param {string} [context] - 上下文标识
         * @returns {Object|null} 草稿数据
         */
        restore: function (formType, context) {
            var key = buildKey(formType, context);
            var draft = storageGet(key);
            return draft ? draft.data : null;
        },

        /**
         * 手动清除
         * @param {string} formType - 表单类型
         * @param {string} [context] - 上下文标识
         */
        clear: function (formType, context) {
            var key = buildKey(formType, context);
            storageRemove(key);
        },

        /**
         * 检测是否存在草稿
         * @param {string} formType - 表单类型
         * @param {string} [context] - 上下文标识
         * @returns {boolean}
         */
        detect: function (formType, context) {
            var key = buildKey(formType, context);
            var draft = storageGet(key);
            return !!draft;
        },

        /**
         * 为动态加载的表单注册自动保存（htmx 局部刷新后调用）
         * @param {HTMLFormElement} form - 表单元素
         */
        register: function (form) {
            if (!form || !form.getAttribute('data-autosave')) return;
            if (window.XN_AUTO_SAVE_DISABLE === true) return;
            initForm(form);
        }
    };

    // 暴露到全局
    window.AutoSave = AutoSave;

})(window, document);
