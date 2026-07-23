/**
 * xiuno-modern.js — 原生 JS 兼容层 + jQuery 兼容 shim
 *
 * 本文件同时承担两个职责：
 *   1. 新 API 层：提供 XN.xxx() 原生 API（选择器/AJAX/DOM/事件/toast/modal 等）
 *   2. 旧 API 兼容 shim：提供 $ 别名、$.fn.* 扩展、xn.* PHP 函数库、Bootstrap 5 桥接，
 *      让所有未改写的旧代码（前台/后台模板内联 $ 代码、20 个插件 JS）继续工作，
 *      不再依赖 jQuery/xiuno.js/bootstrap-plugin.js。
 *
 * 使用方式：
 *   新插件/新页面 → 用 XN.xxx() 或原生 JS
 *   旧插件/旧页面 → 继续用 $.xxx() / xn.xxx()，无需改动
 *
 * 加载顺序（footer.inc.htm）：
 *   1. bootstrap.bundle.min.js
 *   2. xiuno-modern.js（本文件，不依赖 jQuery）
 *   3. bbs.js / form.js / async.js（已迁移为原生 JS，无需 jQuery）
 */

(function (global) {
    'use strict';

    // 浏览器控制台版本标识输出
    (function () {
        var url = 'https://github.com/domidesign/xiunox';
        var style = 'font-size:12px;font-weight:bold;color:#fff;background:#0d6efd;padding:2px 8px;border-radius:3px;';
        var urlStyle = 'font-size:12px;color:#6c757d;';
        if (typeof console !== 'undefined' && console.log) {
            console.log('%cXIUNOX%c\n' + url, style, urlStyle);
        }
    })();

    var XN = global.XN || {};

    // 从页面中的 CSRF token hidden input 自动获取
    XN.csrfToken = (function() {
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    })();

    // ========== DOM 选择器 ==========

    XN.$ = function (selector) {
        if (typeof selector === 'string') {
            return document.querySelector(selector);
        }
        return selector;
    };

    XN.$$ = function (selector) {
        if (typeof selector === 'string') {
            return Array.from(document.querySelectorAll(selector));
        }
        if (selector instanceof NodeList) {
            return Array.from(selector);
        }
        if (Array.isArray(selector)) {
            return selector;
        }
        return [selector];
    };

    // ========== AJAX ==========

    XN.ajax = function (method, url, data, options) {
        options = options || {};
        var timeout = options.timeout || 30000;
        var headers = Object.assign({
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': XN.csrfToken || ''
        }, options.headers || {});

        var isPost = method.toUpperCase() === 'POST';
        var body = null;

        // ponytail: 复用 $.param 序列化，支持数组值（ids[]=1&ids[]=2），PHP $_REQUEST 能正确解析为数组
        // 已违反 1 次：原简化实现 encodeURIComponent(arr) 会把数组隐式 toString 为 "1,2,3"，
        // 导致后端 param('ids', array()) 返回空数组，audit.htm 批量操作报"参数错误"
        if (isPost && data) {
            if (data instanceof FormData) {
                body = data;
            } else if (typeof data === 'object') {
                headers['Content-Type'] = 'application/x-www-form-urlencoded';
                body = $.param(data);
            } else {
                // ponytail: 字符串 body（如 jform.serialize() 的 key=val&key=val 格式）
                // 必须显式设 Content-Type，否则 fetch 默认 text/plain，PHP 不解析到 $_POST
                // 已违反 1 次：导致后台所有 $.xpost(action, jform.serialize()) 表单 $_POST 为空，
                // param() 全部返回默认值（permalink 保存退回 0、user_update 字段丢失等）
                if (!headers['Content-Type']) {
                    headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
                }
                body = data;
            }
        } else if (!isPost && data) {
            var sep = url.indexOf('?') === -1 ? '?' : '&';
            url += sep + $.param(data);
        }

        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeout);

        var fetchOptions = {
            method: method.toUpperCase(),
            headers: headers,
            body: isPost ? body : undefined,
            credentials: 'same-origin',
            signal: controller.signal
        };

        return fetch(url, fetchOptions).then(function (response) {
            clearTimeout(timer);
            if (!response.ok) {
                throw { code: -response.status, message: 'HTTP ' + response.status };
            }
            return response.text().then(function (text) {
                if (!text) {
                    throw { code: -100, message: 'Server Response Empty' };
                }
                try {
                    var json = JSON.parse(text);
                    return json;
                } catch (e) {
                    throw { code: -101, message: text };
                }
            });
        }).then(function (json) {
            if (json && json.code !== undefined) {
                if (json.code == 0) {
                    return { code: 0, message: json.message, data: json };
                } else {
                    throw { code: json.code, message: json.message };
                }
            }
            return { code: 0, message: json, data: json };
        }).catch(function (err) {
            clearTimeout(timer);
            if (err.name === 'AbortError') {
                throw { code: -1001, message: 'Request timeout' };
            }
            throw err;
        });
    };

    XN.get = function (url, callback) {
        return XN.ajax('GET', url).then(function (result) {
            if (callback) callback(result.code, result.message);
        }).catch(function (err) {
            if (callback) callback(err.code || -1, err.message || 'Network error');
        });
    };

    XN.post = function (url, data, callback) {
        return XN.ajax('POST', url, data).then(function (result) {
            if (callback) callback(result.code, result.message);
        }).catch(function (err) {
            if (callback) callback(err.code || -1, err.message || 'Network error');
        });
    };

    // ========== Cookie ==========

    XN.cookie = function (name, value, time, path) {
        if (value !== undefined) {
            if (value === null) {
                value = '';
                time = -1;
            }
            var expires = '';
            if (time !== undefined) {
                var date = new Date();
                date.setTime(date.getTime() + (time * 1000));
                expires = '; expires=' + date.toUTCString();
            }
            path = path ? '; path=' + path : '; path=/';
            document.cookie = name + '=' + encodeURIComponent(value) + expires + path;
        } else {
            var v = '';
            if (document.cookie && document.cookie !== '') {
                var cookies = document.cookie.split(';');
                for (var i = 0; i < cookies.length; i++) {
                    var cookie = cookies[i].trim();
                    if (cookie.substring(0, name.length + 1) === (name + '=')) {
                        v = decodeURIComponent(cookie.substring(name.length + 1));
                        break;
                    }
                }
            }
            return v;
        }
    };

    // ========== LocalStorage ==========

    XN.storage = {
        get: function (key) {
            try {
                var val = localStorage.getItem(key);
                return val ? JSON.parse(val) : null;
            } catch (e) {
                return null;
            }
        },
        set: function (key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
                return true;
            } catch (e) {
                return false;
            }
        },
        remove: function (key) {
            try {
                localStorage.removeItem(key);
                return true;
            } catch (e) {
                return false;
            }
        }
    };

    // ========== DOM 操作 ==========

    XN.addClass = function (el, cls) {
        if (typeof el === 'string') el = XN.$(el);
        if (el) el.classList.add.apply(el.classList, cls.split(/\s+/));
    };

    XN.removeClass = function (el, cls) {
        if (typeof el === 'string') el = XN.$(el);
        if (el) el.classList.remove.apply(el.classList, cls.split(/\s+/));
    };

    XN.toggleClass = function (el, cls) {
        if (typeof el === 'string') el = XN.$(el);
        if (el) el.classList.toggle(cls);
    };

    XN.hasClass = function (el, cls) {
        if (typeof el === 'string') el = XN.$(el);
        return el ? el.classList.contains(cls) : false;
    };

    XN.show = function (el) {
        if (typeof el === 'string') el = XN.$(el);
        if (el) el.style.display = '';
    };

    XN.hide = function (el) {
        if (typeof el === 'string') el = XN.$(el);
        if (el) el.style.display = 'none';
    };

    XN.toggle = function (el) {
        if (typeof el === 'string') el = XN.$(el);
        if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
    };

    XN.attr = function (el, name, value) {
        if (typeof el === 'string') el = XN.$(el);
        if (!el) return null;
        if (value === undefined) {
            return el.getAttribute(name);
        }
        el.setAttribute(name, value);
    };

    XN.removeAttr = function (el, name) {
        if (typeof el === 'string') el = XN.$(el);
        if (el) el.removeAttribute(name);
    };

    XN.val = function (el, value) {
        if (typeof el === 'string') el = XN.$(el);
        if (!el) return null;
        if (value === undefined) {
            return el.value;
        }
        el.value = value;
    };

    XN.html = function (el, content) {
        if (typeof el === 'string') el = XN.$(el);
        if (!el) return '';
        if (content === undefined) {
            return el.innerHTML;
        }
        el.innerHTML = content;
    };

    XN.text = function (el, content) {
        if (typeof el === 'string') el = XN.$(el);
        if (!el) return '';
        if (content === undefined) {
            return el.textContent;
        }
        el.textContent = content;
    };

    // ========== 事件 ==========

    XN.on = function (el, event, selector, handler) {
        if (typeof el === 'string') el = XN.$(el);
        if (!el) return;
        if (typeof selector === 'function') {
            handler = selector;
            selector = null;
            el.addEventListener(event, handler);
            return;
        }
        el.addEventListener(event, function (e) {
            var target = e.target.closest(selector);
            if (target && el.contains(target)) {
                handler.call(target, e);
            }
        });
    };

    XN.off = function (el, event, handler) {
        if (typeof el === 'string') el = XN.$(el);
        if (el) el.removeEventListener(event, handler);
    };

    XN.ready = function (fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    };

    // ========== 表单 ==========

    XN.serialize = function (form) {
        if (typeof form === 'string') form = XN.$(form);
        if (!form) return {};
        var data = {};
        var fd = new FormData(form);
        fd.forEach(function (value, key) {
            if (data[key] !== undefined) {
                if (!Array.isArray(data[key])) {
                    data[key] = [data[key]];
                }
                data[key].push(value);
            } else {
                data[key] = value;
            }
        });
        return data;
    };

    XN.submit = function (form, url, callback) {
        if (typeof form === 'string') form = XN.$(form);
        if (!form) return;
        var fd = new FormData(form);
        var csrfToken = form.querySelector('input[name="csrf_token"]');
        if (csrfToken && !fd.has('csrf_token')) {
            fd.set('csrf_token', csrfToken.value);
        }
        XN.ajax('POST', url || form.action, fd).then(function (result) {
            if (callback) callback(result.code, result.message);
        }).catch(function (err) {
            if (callback) callback(err.code || -1, err.message || 'Network error');
        });
    };

    // ========== Toast 提示 ==========

    XN.toast = function (message, type, duration) {
        type = type || 'info';
        duration = duration || 3000;
        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }
        var bgMap = { success: 'bg-success', danger: 'bg-danger', warning: 'bg-warning text-dark', info: 'bg-primary' };
        var bg = bgMap[type] || bgMap.info;
        var toast = document.createElement('div');
        toast.className = 'toast show align-items-center text-white border-0 rounded-3 ' + bg;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = '<div class="d-flex"><div class="toast-body">' + XN.escapeHtml(message) + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        container.appendChild(toast);
        var bsToast = new bootstrap.Toast(toast, { delay: duration });
        bsToast.show();
        toast.addEventListener('hidden.bs.toast', function () { toast.remove(); });
    };

    // ========== Modal 弹窗（Bootstrap 5.3） ==========

    // XN.alert(message, options) — 替代原生 alert()
    // options: { title, type, size, duration }
    //   type: 'info' | 'success' | 'warning' | 'danger'（默认 'info'）
    //   size: 'sm' | 'md' | 'lg'（默认 'md'）
    //   duration: 自动关闭秒数，0 或不传则不自动关闭
    // 返回 Bootstrap Modal 实例，可调用 .hide() 手动关闭
    XN.alert = function (message, options) {
        options = options || {};
        var type = options.type || 'info';
        var size = options.size || 'md';
        var duration = options.duration || 0;
        var title = options.title || '';

        var iconMap = {
            info: 'ti-info-circle text-primary',
            success: 'ti-circle-check text-success',
            warning: 'ti-alert-triangle text-warning',
            danger: 'ti-alert-circle text-danger'
        };
        var iconCls = iconMap[type] || iconMap.info;
        var titleText = title || (typeof lang !== 'undefined' && lang.tips_title) || '提示';

        var id = 'xn-alert-' + Date.now();
        var html = '<div class="modal fade" id="' + id + '" tabindex="-1" aria-hidden="true">' +
            '<div class="modal-dialog modal-dialog-centered modal-' + size + '">' +
            '<div class="modal-content border-0 rounded-3 shadow">' +
            '<div class="modal-header border-0 pb-0">' +
            '<h6 class="modal-title fw-bold"><i class="ti ' + iconCls + ' me-2"></i>' + XN.escapeHtml(titleText) + '</h6>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body pt-2"><p class="mb-0">' + message + '</p></div>' +
            '<div class="modal-footer border-0 pt-0">' +
            '<button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal">' +
            ((typeof lang !== 'undefined' && lang.close) || '关闭') + '</button>' +
            '</div></div></div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var el = document.getElementById(id);
        var modal = new bootstrap.Modal(el);
        modal.show();
        el.addEventListener('hidden.bs.modal', function () { el.remove(); });

        if (duration > 0) {
            setTimeout(function () { modal.hide(); }, duration * 1000);
        }

        return modal;
    };

    // XN.confirm(message, okCallback, options) — 替代原生 confirm()
    // options: { title, type, size, okText, cancelText, body, cancelCallback }
    //   type: 'info' | 'warning' | 'danger'（默认 'warning'）
    //   okText / cancelText: 按钮文字
    //   body: 额外 HTML 内容（插入到 message 下方）
    //   cancelCallback: 取消时的回调
    // 返回 Bootstrap Modal 实例
    XN.confirm = function (message, okCallback, options) {
        options = options || {};
        var type = options.type || 'warning';
        var size = options.size || 'md';
        var okText = options.okText || ((typeof lang !== 'undefined' && lang.confirm) || '确定');
        var cancelText = options.cancelText || ((typeof lang !== 'undefined' && lang.close) || '关闭');
        var body = options.body || '';
        var cancelCallback = options.cancelCallback;

        var iconMap = {
            info: 'ti-help-circle text-primary',
            warning: 'ti-help-circle text-warning',
            danger: 'ti-alert-circle text-danger'
        };
        var iconCls = iconMap[type] || iconMap.warning;
        var titleText = options.title || (typeof lang !== 'undefined' && lang.confirm_title) || '确认';

        var id = 'xn-confirm-' + Date.now();
        var html = '<div class="modal fade" id="' + id + '" tabindex="-1" aria-hidden="true">' +
            '<div class="modal-dialog modal-dialog-centered modal-' + size + '">' +
            '<div class="modal-content border-0 rounded-3 shadow">' +
            '<div class="modal-header border-0 pb-0">' +
            '<h6 class="modal-title fw-bold"><i class="ti ' + iconCls + ' me-2"></i>' + XN.escapeHtml(titleText) + '</h6>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body pt-2">' +
            '<p class="mb-0">' + message + '</p>' +
            body +
            '</div>' +
            '<div class="modal-footer border-0 pt-0">' +
            '<button type="button" class="btn btn-primary px-4 xn-confirm-ok">' + XN.escapeHtml(okText) + '</button>' +
            '<button type="button" class="btn btn-outline-secondary px-4 xn-confirm-cancel" data-bs-dismiss="modal">' + XN.escapeHtml(cancelText) + '</button>' +
            '</div></div></div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var el = document.getElementById(id);
        var modal = new bootstrap.Modal(el);
        modal.show();

        // okCallback 标志：避免 hidden.bs.modal 重复触发回调
        // true = 用户点了确定；false = 用户取消或关闭
        var okCallbackFired = false;
        el.addEventListener('hidden.bs.modal', function () {
            el.remove();
            // 只有用户点了确定按钮才执行 okCallback
            if (okCallbackFired && okCallback) {
                okCallback();
            }
        });

        el.querySelector('.xn-confirm-ok').addEventListener('click', function () {
            // 先让按钮失焦，避免在 aria-hidden 容器内被聚焦（WAI-ARIA 规范）
            if(document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            okCallbackFired = true;
            modal.hide();
        });

        // 取消按钮和关闭按钮：标记为取消并触发 cancelCallback
        el.querySelector('.xn-confirm-cancel').addEventListener('click', function () {
            if(document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            if (cancelCallback) cancelCallback();
        });
        el.querySelector('.btn-close').addEventListener('click', function () {
            if(document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            if (cancelCallback) cancelCallback();
        });

        return modal;
    };

    // XN.prompt(message, defaultValue, callback, options) — 替代原生 prompt()
    // options: { title, type, size, okText, cancelText, placeholder, required, multiline, cancelCallback, validate }
    //   type: 'info' | 'warning' | 'danger'（默认 'info'）
    //   size: 'sm' | 'md' | 'lg'（默认 'md'）
    //   required: true 时空值不让提交
    //   multiline: true 渲染 textarea，否则 input
    //   validate: function(value) -> string|undefined，返回错误文案阻止提交
    //   callback: function(value)，用户确定时调用（同步读取 value）；取消时不调用
    //   cancelCallback: function()，取消时调用
    // 返回 Bootstrap Modal 实例
    XN.prompt = function (message, defaultValue, callback, options) {
        options = options || {};
        var type = options.type || 'info';
        var size = options.size || 'md';
        var okText = options.okText || ((typeof lang !== 'undefined' && lang.confirm) || '确定');
        var cancelText = options.cancelText || ((typeof lang !== 'undefined' && lang.close) || '关闭');
        var placeholder = options.placeholder || '';
        var required = options.required !== false;
        var multiline = !!options.multiline;
        var validate = options.validate;
        var cancelCallback = options.cancelCallback;

        var iconMap = {
            info: 'ti-keyboard text-primary',
            warning: 'ti-alert-triangle text-warning',
            danger: 'ti-alert-circle text-danger'
        };
        var iconCls = iconMap[type] || iconMap.info;
        var titleText = options.title || (typeof lang !== 'undefined' && lang.input_title) || '请输入';

        var id = 'xn-prompt-' + Date.now();
        var inputHtml = multiline
            ? '<textarea class="form-control xn-prompt-input" rows="3" placeholder="' + XN.escapeHtml(placeholder) + '"></textarea>'
            : '<input type="text" class="form-control xn-prompt-input" placeholder="' + XN.escapeHtml(placeholder) + '" />';
        var html = '<div class="modal fade" id="' + id + '" tabindex="-1" aria-hidden="true">' +
            '<div class="modal-dialog modal-dialog-centered modal-' + size + '">' +
            '<div class="modal-content border-0 rounded-3 shadow">' +
            '<div class="modal-header border-0 pb-0">' +
            '<h6 class="modal-title fw-bold"><i class="ti ' + iconCls + ' me-2"></i>' + XN.escapeHtml(titleText) + '</h6>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body pt-2">' +
            (message ? '<p class="mb-2">' + message + '</p>' : '') +
            inputHtml +
            '<div class="xn-prompt-error text-danger small mt-1" style="display:none;"></div>' +
            '</div>' +
            '<div class="modal-footer border-0 pt-0">' +
            '<button type="button" class="btn btn-primary px-4 xn-prompt-ok">' + XN.escapeHtml(okText) + '</button>' +
            '<button type="button" class="btn btn-outline-secondary px-4 xn-prompt-cancel" data-bs-dismiss="modal">' + XN.escapeHtml(cancelText) + '</button>' +
            '</div></div></div></div>';

        document.body.insertAdjacentHTML('beforeend', html);
        var el = document.getElementById(id);
        var inputEl = el.querySelector('.xn-prompt-input');
        var errEl = el.querySelector('.xn-prompt-error');
        if (defaultValue !== undefined && defaultValue !== null) {
            inputEl.value = String(defaultValue);
        }

        var modal = new bootstrap.Modal(el);
        modal.show();

        // ponytail: 自动聚焦输入框并选中默认值（模拟原生 prompt 行为）
        el.addEventListener('shown.bs.modal', function () {
            inputEl.focus();
            inputEl.select();
        });

        var okCallbackFired = false;
        el.addEventListener('hidden.bs.modal', function () {
            el.remove();
            if (okCallbackFired && callback) {
                callback(inputEl.value);
            }
        });

        function showError(msg) {
            if (msg) {
                errEl.textContent = msg;
                errEl.style.display = 'block';
            } else {
                errEl.style.display = 'none';
            }
        }

        function doOk() {
            var val = inputEl.value;
            if (required && !val.trim()) {
                showError((typeof lang !== 'undefined' && lang.input_required) || '请输入内容');
                return;
            }
            if (typeof validate === 'function') {
                var err = validate(val);
                if (err) { showError(err); return; }
            }
            showError('');
            okCallbackFired = true;
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            modal.hide();
        }

        el.querySelector('.xn-prompt-ok').addEventListener('click', doOk);
        el.querySelector('.xn-prompt-cancel').addEventListener('click', function () {
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            if (cancelCallback) cancelCallback();
        });
        el.querySelector('.btn-close').addEventListener('click', function () {
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            if (cancelCallback) cancelCallback();
        });

        // ponytail: 单行 input 按 Enter 提交，textarea 按 Ctrl/Cmd+Enter 提交
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !multiline) {
                e.preventDefault();
                doOk();
            } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && multiline) {
                e.preventDefault();
                doOk();
            }
        });

        return modal;
    };

    // ========== 积分扣除确认 ==========

    // 调用后端预检查 API，有扣减则弹 Modal 确认，确认后才执行 callback
    // options: { onCancel: 取消时的回调 }
    XN.confirmCreditsDeduct = function (event, fid, callback, options) {
        options = options || {};
        var url = (typeof creditsCheckUrl !== 'undefined') ? creditsCheckUrl : XN.url('my-credits_check');
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        url += sep + 'event=' + encodeURIComponent(event) + '&fid=' + encodeURIComponent(fid || 0);

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) {
            return r.text();
        })
        .then(function (text) {
            var res;
            try { res = JSON.parse(text); }
            catch (e) {
                // JSON 解析失败（可能是 PHP 错误输出），放行
                callback();
                return;
            }
            var data = res.data || {};

            // 每日上限达到 → 直接放行，不弹窗（操作继续，但不扣减/奖励积分）
            if (res.code === 0 && data.daily_limit_reached) {
                callback();
                return;
            }

            // 无扣减 → 直接放行
            if (res.code === 0 && !data.deduct_desc) {
                callback();
                return;
            }

            // 失败（余额不足/超限等）→ toast 提示并阻止
            if (res.code !== 0) {
                var msg = res.message || '操作失败';
                XN.toast(msg, 'danger');
                if (options.onCancel) options.onCancel();
                return;
            }

            // 有扣减 → 弹 Bootstrap Modal 确认
            var deductDesc = data.deduct_desc || '';
            var balances = data.balances || {};
            var body = '<div class="mb-2"><i class="ti ti-minus text-warning me-1"></i>' + XN.escapeHtml(deductDesc) + '</div>';

            if (balances.credits !== undefined) {
                body += '<div class="small text-body-secondary border-top pt-2 mt-2">';
                body += '<div class="d-flex justify-content-between"><span>积分余额</span><span class="fw-semibold">' + balances.credits + '</span></div>';
                if (balances.golds !== undefined) body += '<div class="d-flex justify-content-between"><span>金币余额</span><span class="fw-semibold">' + balances.golds + '</span></div>';
                if (balances.rmbs !== undefined) body += '<div class="d-flex justify-content-between"><span>人民币余额</span><span class="fw-semibold">' + balances.rmbs + '</span></div>';
                body += '</div>';
            }

            XN.confirm('本次操作将扣除积分，是否继续？', callback, {
                title: '积分确认',
                type: 'warning',
                okText: '确认',
                cancelText: '取消',
                body: body,
                cancelCallback: options.onCancel
            });
        })
        .catch(function (err) {
            // 网络错误 → 放行
            callback();
        });
    };

    // ========== 工具函数 ==========

    XN.escapeHtml = function (s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    };

    XN.redirect = function (url, delay) {
        delay = delay || 0;
        setTimeout(function () {
            if (url) {
                window.location.href = url;
            } else {
                window.location.reload();
            }
        }, delay * 1000);
    };

    XN.intval = function (s) {
        var i = parseInt(s, 10);
        return isNaN(i) ? 0 : i;
    };

    XN.empty = function (v) {
        if (v === undefined || v === null || v === false || v === '0' || v === '') return true;
        if (Array.isArray(v)) return v.length === 0;
        if (typeof v === 'object') return Object.keys(v).length === 0;
        return false;
    };

    XN.url = function (u) {
        var on = window.url_rewrite_on || 0;
        // admin 后台始终使用 ? 格式（url_rewrite_on=0）
        var is_admin = (window.location.pathname.indexOf('/admin') !== -1);
        if(is_admin) {
            on = 0;
        }
        var result;
        if (u.indexOf('/') !== -1) {
            var pos = u.lastIndexOf('/');
            var path = u.substring(0, pos + 1);
            var query = u.substring(pos + 1);
        } else {
            var path = '';
            var query = u;
        }
        // 空路由防护
        if (query === '') {
            var bp = window.base_path || '';
            if (on === 0) return bp + '/?index.htm';
            if (on === 2) return bp + '/?index';
            return bp + '/';
        }
        if (!on) {
            result = path + '?' + query + '.htm';
        } else if (on === 1) {
            result = path + query + '.htm';
        } else if (on === 2) {
            result = path + '?' + query.replace(/-/g, '/');
        } else if (on === 3) {
            result = path + query.replace(/-/g, '/');
        } else if (on === 4) {
            result = path + query + '.html';
        } else if (on === 5) {
            // on=5 路径+html 风格：thread-create-1 → thread/create/1.html
            result = path + query.replace(/-/g, '/') + '.html';
        } else {
            result = path + query;
        }
        // 前缀处理：前台用 base_path，admin 用 ./
        if (result && result.indexOf('http') !== 0 && result.indexOf('//') !== 0) {
            if (is_admin) {
                result = './' + result.replace(/^\//, '');
            } else {
                result = (window.base_path || '') + '/' + result.replace(/^\//, '');
            }
        }
        return result;
    };

    // ========== htmx 增强 ==========

    XN.htmx = {
        post: function (url, data, target, swap) {
            if (typeof htmx === 'undefined') return;
            htmx.ajax('POST', url, {
                target: target || '#main',
                swap: swap || 'innerHTML',
                values: data || {}
            });
        },
        get: function (url, target, swap) {
            if (typeof htmx === 'undefined') return;
            htmx.ajax('GET', url, {
                target: target || '#main',
                swap: swap || 'innerHTML'
            });
        }
    };

    // ========== 验证码刷新 ==========

    // 验证码过期定时器：到时图片变灰 + 浮层「已过期，点击刷新」
    // ponytail: 浮层覆盖 img 父级（input-group 等），点击触发 refreshFn 或 img.click()
    XN.captchaScheduleExpire = function (imgEl, expires_in, refreshFn) {
        if (!imgEl || !expires_in) return;
        // 清理之前的定时器与浮层
        if (imgEl._captchaExpireTimer) {
            clearTimeout(imgEl._captchaExpireTimer);
            imgEl._captchaExpireTimer = null;
        }
        var parent = imgEl.parentNode;
        if (parent) {
            var existing = parent.querySelector('.captcha-expired-overlay');
            if (existing) existing.remove();
        }
        imgEl.style.filter = '';
        // 设置新定时器
        imgEl._captchaExpireTimer = setTimeout(function () {
            imgEl._captchaExpireTimer = null;
            imgEl.style.filter = 'grayscale(1) opacity(0.5)';
            if (!parent) return;
            if (getComputedStyle(parent).position === 'static') {
                parent.style.position = 'relative';
            }
            var overlay = document.createElement('div');
            overlay.className = 'captcha-expired-overlay';
            var txt = (typeof lang !== 'undefined' && lang.captcha_expired) ? lang.captcha_expired : '已过期，点击刷新';
            overlay.textContent = txt;
            overlay.title = txt;
            overlay.style.cssText = 'position:absolute;top:0;right:0;bottom:0;width:120px;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.55);color:#fff;font-size:12px;cursor:pointer;border-radius:0 20px 20px 0;text-align:center;z-index:5';
            parent.appendChild(overlay);
            overlay.addEventListener('click', function (e) {
                e.stopPropagation();
                overlay.remove();
                imgEl.style.filter = '';
                if (typeof refreshFn === 'function') {
                    refreshFn();
                } else {
                    imgEl.click();
                }
            });
        }, expires_in * 1000);
    };

    XN.captchaRefresh = function (scene) {
        var url;
        // 优先使用 PHP url() 函数生成的 URL 模板，兼容所有伪静态格式
        if (typeof bbs_captcha_url_template !== 'undefined' && bbs_captcha_url_template) {
            url = bbs_captcha_url_template.replace('__SCENE__', scene);
        } else if (typeof url_rewrite_on !== 'undefined' && url_rewrite_on == 1) {
            url = 'captcha-generate-' + scene + '.htm';
        } else if (typeof url_rewrite_on !== 'undefined' && url_rewrite_on == 3) {
            url = 'captcha/generate/' + scene;
        } else if (typeof url_rewrite_on !== 'undefined' && url_rewrite_on == 4) {
            url = 'captcha-generate-' + scene + '.html';
        } else {
            url = '?captcha-generate-' + scene + '.htm';
        }
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) return null;
                return r.text();
            })
            .then(function (text) {
                if (!text) return;
                try {
                    var res = JSON.parse(text);
                    if (res.code === 0 && res.data && res.data.image) {
                        var img = document.getElementById('captcha-img-' + scene);
                        if (img) {
                            img.src = res.data.image;
                            img.style.display = '';
                            // 启动过期定时器
                            XN.captchaScheduleExpire(img, res.data.expires_in || 600, function () {
                                XN.captchaRefresh(scene);
                            });
                        }
                    }
                } catch (e) {
                    console.warn('[captchaRefresh] JSON parse error for scene:', scene, 'response:', text.substring(0, 300));
                }
            })
            .catch(function (err) {
                console.warn('[captchaRefresh] request failed for scene:', scene, err);
            });
    };

    // ========== 导出 ==========

    global.XN = XN;

    // ========== xn / XN 命名空间桥接 ==========
    // xiuno.js 定义了小写 xn，xiuno-modern.js 定义了大写 XN
    // 两者互相补齐缺失的 API，但不覆盖已有实现

    var _xn = global.xn || {};

    // XN → xn：将 XN 上有但 xn 上没有的 API 复制过去
    var _xnBridgeKeys = ['post', 'get', 'ajax', 'toast', 'cookie', 'redirect',
        'escapeHtml', 'addClass', 'removeClass', 'toggleClass', 'hasClass',
        'show', 'hide', 'toggle', 'attr', 'removeAttr', 'val', 'html', 'text',
        'on', 'off', 'ready', 'serialize', 'submit', '$', '$$', 'htmx', 'storage',
        'captchaRefresh', 'alert', 'confirm'];
    for (var i = 0; i < _xnBridgeKeys.length; i++) {
        var _k = _xnBridgeKeys[i];
        if (XN[_k] !== undefined && _xn[_k] === undefined) {
            _xn[_k] = XN[_k];
        }
    }

    // xn → XN：将 xn 上有但 XN 上没有的 API 复制过去
    // 注意：不覆盖 XN 已有的 intval、empty、url
    var _xnToXNKeys = ['urlencode', 'urldecode'];
    for (var i = 0; i < _xnToXNKeys.length; i++) {
        var _k = _xnToXNKeys[i];
        if (_xn[_k] !== undefined && XN[_k] === undefined) {
            XN[_k] = _xn[_k];
        }
    }

    // 写回全局 xn
    global.xn = _xn;

    // 全局快捷函数（供模板 hx-on 属性直接调用）
    global.captchaRefresh = XN.captchaRefresh;

    // 全局 showToast 别名：指向 XN.toast（带 escapeHtml 转义，防 XSS）
    // 旧版 xiuno.js 的 showToast 用 innerHTML 直接拼接 message，有 XSS 风险 + 双重编码问题，已废弃
    // type 兼容映射：error → danger（旧版用 error，Bootstrap toast 用 danger）
    global.showToast = function(message, type) {
        if (type === 'error') type = 'danger';
        XN.toast(message, type);
    };

    // 全局 setBtnLoading：替代 Bootstrap 5 已移除的 jQuery button('loading'/'reset') API
    // 迁移自 bootstrap-plugin.js:204-225（原文件已不再加载），兼容 DOM 元素或 jQuery 对象
    // ponytail: 历史调用点 ~50 处统一走此函数，避免每页就地实现
    global.setBtnLoading = function(btn, isLoading) {
        var el = btn && btn.jquery ? btn[0] : btn;
        if (!el || !el.tagName) return;
        if (isLoading) {
            if (el.getAttribute('data-btn-loading') === '1') return;
            el.setAttribute('data-btn-loading', '1');
            el.disabled = true;
            var spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-1';
            spinner.setAttribute('role', 'status');
            spinner.setAttribute('aria-hidden', 'true');
            spinner.setAttribute('data-btn-loading-spinner', '');
            el.insertBefore(spinner, el.firstChild);
        } else {
            if (el.getAttribute('data-btn-loading') === '1') {
                var s = el.querySelector('[data-btn-loading-spinner]');
                if (s) s.remove();
                el.disabled = false;
                el.removeAttribute('data-btn-loading');
            }
        }
    };

    // 验证码自动初始化：监听 DOM 变化，当 [data-captcha-scene] 元素被插入时自动刷新
    // 解决 htmx boost 导航时内联 <script> 不执行的问题
    function initCaptchaOnInsert() {
        document.querySelectorAll('[data-captcha-scene]:not([data-captcha-init])').forEach(function(el) {
            var scene = el.getAttribute('data-captcha-scene');
            if (scene) {
                el.setAttribute('data-captcha-init', '1');
                XN.captchaRefresh(scene);
            }
        });
    }

    // 首次加载时初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCaptchaOnInsert);
    } else {
        initCaptchaOnInsert();
    }

    // htmx swap 后初始化
    if (typeof htmx !== 'undefined') {
        document.addEventListener('htmx:after:swap', initCaptchaOnInsert);
    }

    // MutationObserver 兜底：捕获动态插入的验证码元素
    var _captchaObserver = new MutationObserver(function(mutations) {
        var found = false;
        for (var i = 0; i < mutations.length; i++) {
            var added = mutations[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
                if (added[j].nodeType === 1) {
                    if (added[j].hasAttribute && added[j].hasAttribute('data-captcha-scene')) {
                        found = true;
                    } else if (added[j].querySelector && added[j].querySelector('[data-captcha-scene]')) {
                        found = true;
                    }
                }
            }
        }
        if (found) initCaptchaOnInsert();
    });
    _captchaObserver.observe(document.documentElement || document.body, { childList: true, subtree: true });

    // ========================================================================
    // 旧 API 兼容 shim（迁移自 xiuno.js / bootstrap-plugin.js / bbs.js）
    // ========================================================================
    // 以下代码用原生 JS 实现 $ 别名、$.fn.* 扩展、xn.* PHP 函数库、
    // $.ajax_modal、Bootstrap 5 桥接，让所有未改写的旧代码继续工作。

    // ========== 块 1: Object 扩展 + xn.* PHP 函数库 ==========

    var xn = global.xn || {};

    // Object 扩展（照搬 xiuno.js:8-56，Object.sum 中 $.each 改为 for-in）
    if (!Object.keys) {
        Object.keys = function(o) {
            var arr = [];
            for (var k in o) { if (o.hasOwnProperty(k)) arr.push(k); }
            return arr;
        };
    }
    if (!Object.values) {
        Object.values = function(o) {
            var arr = [];
            if (!o) return arr;
            for (var k in o) { if (o.hasOwnProperty(k)) arr.push(o[k]); }
            return arr;
        };
    }
    Object.first = function(obj) { for (var k in obj) return obj[k]; };
    Object.last = function(obj) { var v; for (var k in obj) v = obj[k]; return v; };
    // ponytail: Object.length 是内置函数的只读属性（表示形参数量），严格模式不可直接赋值；
    // 用 defineProperty 强制覆盖。全项目 grep 未发现 Object.length() 调用，仅为向后兼容保留。
    try { Object.defineProperty(Object, 'length', { value: function(obj) { var n = 0; for (var k in obj) n++; return n; }, writable: true, configurable: true }); } catch (e) {}
    Object.count = function(obj) {
        if (!obj) return 0;
        if (obj.length) return obj.length;
        var n = 0;
        for (var k in obj) { if (obj.hasOwnProperty(k)) n++; }
        return n;
    };
    Object.sum = function(obj) {
        var sum = 0;
        for (var k in obj) { sum += xn.intval(obj[k]); }
        return sum;
    };

    // 浏览器检测（照搬 xiuno.js:69-72，in_mobile 改用 window.innerWidth）
    xn.is_ie = (!!document.all) ? true : false;
    xn.is_ie_10 = navigator.userAgent.indexOf('Trident') !== -1;
    xn.is_ff = navigator.userAgent.indexOf('Firefox') !== -1;
    xn.in_mobile = (window.innerWidth < 1140);
    xn.options = {water_image_url: 'view/img/water-small.png'};

    // 字符串编码
    xn.htmlspecialchars = function(s) {
        s = (s == null ? '' : s) + '';
        return s.replace(/</g, '&lt;').replace(/>/g, '&gt;');
    };
    xn._urlencode = function(s) { return xn.strtolower(encodeURIComponent(s)); };
    xn._urldecode = function(s) { return decodeURIComponent(s); };
    xn.urlencode = function(s) {
        s = encodeURIComponent(s);
        s = s.replace(/_/g, '%5f').replace(/\-/g, '%2d').replace(/\./g, '%2e')
             .replace(/\~/g, '%7e').replace(/\!/g, '%21').replace(/\*/g, '%2a')
             .replace(/\(/g, '%28').replace(/\)/g, '%29').replace(/\%/g, '_');
        return s;
    };
    xn.urldecode = function(s) { return decodeURIComponent(s.replace(/_/g, '%')); };
    xn.nl2br = function(s) {
        return s.replace(/\r\n/g, '\n').replace(/\n/g, '<br>').replace(/\t/g, '&nbsp; &nbsp; &nbsp; &nbsp; ');
    };

    // 数值/类型
    xn.time = function() { return xn.intval(Date.now() / 1000); };
    xn.intval = function(s) {
        // 与已有 XN.intval 共存：若已定义则复用，避免重复
        if (XN.intval && XN.intval !== xn.intval) return XN.intval(s);
        var i = parseInt(s);
        return isNaN(i) ? 0 : i;
    };
    xn.floatval = function(s) {
        if (!s) return 0;
        if (s.constructor === Array) {
            for (var i = 0; i < s.length; i++) s[i] = xn.floatval(s[i]);
            return s;
        }
        var r = parseFloat(s);
        return isNaN(r) ? 0 : r;
    };
    xn.isset = function(k) {
        var t = typeof k;
        return t !== 'undefined' && t !== 'unknown';
    };
    xn.empty = function(s) {
        if (s == '0') return true;
        if (!s) return true;
        if (s.constructor === Object) return Object.keys(s).length === 0;
        if (s.constructor === Array) return s.length === 0;
        return false;
    };
    // xn.is_number：照搬 xiuno.js:394（原文件已于 2026-07-17 删除，此处补迁）
    // 14 个后台模板（user_update/setting_*/security_*/credits_rule*）在 $.xpost 回调中用它判断 code 是否为数字
    // 正则支持负号：message() 输出 code 时强制转字符串（misc.func.php:285 $arr['code']=$code.''），
    // 系统错误码 -1 经 JSON 序列化后是字符串 "-1"，原 /^\d+$/ 不匹配负号 → message(-1, ...) 走 else 分支
    // 找不到 [name="-1"] 元素，错误提示静默丢失。已违反 1 次，影响 user_create 等 14 个后台模板
    xn.is_number = function(obj) {
        return Object.prototype.toString.apply(obj) == '[object Number]' || /^-?\d+$/.test(obj);
    };
    xn.ceil = Math.ceil;
    xn.round = Math.round;
    xn.floor = Math.floor;
    xn.f2y = function(i, callback) {
        if (!callback) callback = Math.round;
        return callback(i / 100);
    };
    xn.y2f = function(s) { return xn.round(xn.intval(s) * 100); };
    xn.strtolower = function(s) { return (s + '').toLowerCase(); };
    xn.strtoupper = function(s) { return (s + '').toUpperCase(); };

    // JSON
    xn.json_type = function(o) {
        var _toS = Object.prototype.toString;
        var _types = {
            'undefined': 'undefined', 'number': 'number', 'boolean': 'boolean',
            'string': 'string', '[object Function]': 'function',
            '[object RegExp]': 'regexp', '[object Array]': 'array',
            '[object Date]': 'date', '[object Error]': 'error'
        };
        return _types[typeof o] || _types[_toS.call(o)] || (o ? 'object' : 'null');
    };
    xn.json_encode = function(o) {
        if (typeof JSON !== 'undefined' && JSON.stringify) return JSON.stringify(o);
        // ponytail: 老浏览器兜底，已知 ceiling 是不支持循环引用检测
        var replace = function(chr) {
            var m = {'\b': '\\b', '\t': '\\t', '\n': '\\n', '\f': '\\f', '\r': '\\r', '"': '\\"', '\\': '\\\\'};
            return m[chr] || '\\u00' + Math.floor(chr.charCodeAt() / 16).toString(16) + (chr.charCodeAt() % 16).toString(16);
        };
        var s = [];
        switch (xn.json_type(o)) {
            case 'undefined': return 'undefined';
            case 'null': return 'null';
            case 'number': case 'boolean': case 'date': case 'function': return o.toString();
            case 'string': return '"' + o.replace(/[\x00-\x1f\\"]/g, replace) + '"';
            case 'array':
                for (var i = 0; i < o.length; i++) s.push(xn.json_encode(o[i]));
                return '[' + s.join(',') + ']';
            case 'error': case 'object':
                for (var p in o) s.push('"' + p + '":' + xn.json_encode(o[p]));
                return '{' + s.join(',') + '}';
            default: return '';
        }
    };
    xn.json_decode = function(s) {
        if (!s) return null;
        try {
            if (s.match(/^<!DOCTYPE/i)) return null;
            return JSON.parse(s);
        } catch (e) { return null; }
    };

    xn.min = function() { return Math.min.apply(this, arguments); };
    xn.max = function() { return Math.max.apply(this, arguments); };
    xn.substr = function(str, start, len) {
        if (!str) return '';
        var length = str.length;
        var end;
        if (start < 0) start = length + start;
        if (!len) end = length;
        else if (len > 0) end = start + len;
        else end = length + len;
        return str.substring(start, end);
    };
    xn.strrpos = function(str, s) { return str.lastIndexOf(s); };
    xn.strpos = function(haystack, needle) { return String(haystack).indexOf(needle); };
    xn.str_replace = function(search, replace, subject) {
        // 兼容 PHP str_replace：search/replace 可为数组
        if (Object.prototype.toString.call(search) === '[object Array]') {
            for (var i = 0; i < search.length; i++) {
                subject = xn.str_replace(search[i], replace[i] || '', subject);
            }
            return subject;
        }
        return String(subject).split(search).join(replace);
    };
    // xn.url: 简短路由格式 → 完整 URL（照搬 xiuno.js:516，支持 on=0..5 六种伪静态 + admin 强制 ? 格式）
    xn.url = function(u, url_rewrite) {
        var on = window.url_rewrite_on || url_rewrite || 0;
        var is_admin = (window.location.pathname.indexOf('/admin') !== -1);
        if (is_admin) on = 0;
        var path, query;
        if (xn.strpos(u, '/') !== -1) {
            path = xn.substr(u, 0, xn.strrpos(u, '/') + 1);
            query = xn.substr(u, xn.strrpos(u, '/') + 1);
        } else {
            path = '';
            query = u;
        }
        if (query === '') {
            var bp = window.base_path || '';
            if (on === 0) return bp + '/?index.htm';
            if (on === 2) return bp + '/?index';
            return bp + '/';
        }
        var r = '';
        if (!on) {
            r = path + '?' + query + '.htm';
        } else if (on === 1) {
            r = path + query + '.htm';
        } else if (on === 2) {
            r = path + '?' + xn.str_replace('-', '/', query);
        } else if (on === 3) {
            r = path + xn.str_replace('-', '/', query);
        } else if (on === 4) {
            r = path + query + '.html';
        } else if (on === 5) {
            r = path + xn.str_replace('-', '/', query) + '.html';
        }
        if (r && r.indexOf('http') !== 0 && r.indexOf('//') !== 0) {
            if (is_admin) {
                r = './' + r.replace(/^\//, '');
            } else {
                r = (window.base_path || '') + '/' + r.replace(/^\//, '');
            }
        }
        return r;
    };
    xn.url_add_arg = function(url, k, v) {
        var pos = xn.strpos(url, '.htm');
        if (pos === false || pos === -1) {
            return xn.strpos(url, '?') === -1 ? url + '?' + k + '=' + v : url + '&' + k + '=' + v;
        }
        return xn.substr(url, 0, pos) + '-' + v + xn.substr(url, pos);
    };
    xn.array_diff = function(arr1, arr2) {
        if (arr1 && arr1.constructor === Array) {
            var o = {};
            for (var i = 0; i < arr2.length; i++) o[arr2[i]] = true;
            var r = [];
            for (var i = 0; i < arr1.length; i++) {
                if (!o[arr1[i]]) r.push(arr1[i]);
            }
            return r;
        } else {
            var r = {};
            for (var k in arr1) { if (!arr2[k]) r[k] = arr1[k]; }
            return r;
        }
    };
    xn.array_filter = function(arr, callback) {
        var newarr = [];
        for (var k in arr) {
            var v = arr[k];
            if (callback && callback(k, v)) continue;
            newarr.push(v);
        }
        return newarr;
    };
    // 改为原生 indexOf（原 $.inArray）
    xn.in_array = function(v, arr) { return arr.indexOf(v) !== -1; };

    // 分页 HTML 生成（照搬 xiuno.js:411-441）
    xn.pages = function(url, totalnum, page, pagesize) {
        if (!page) page = 1;
        if (!pagesize) pagesize = 20;
        var totalpage = xn.ceil(totalnum / pagesize);
        if (totalpage < 2) return '';
        page = xn.min(totalpage, page);
        var shownum = 5;
        var start = xn.max(1, page - shownum);
        var end = xn.min(totalpage, page + shownum);
        var right = page + shownum - totalpage;
        if (right > 0) start = xn.max(1, start -= right);
        var left = page - shownum;
        if (left < 0) end = xn.min(totalpage, end -= left);
        var s = '';
        var rep = function(p) { return url.replace('{page}', p); };
        if (page !== 1) s += '<a href="' + rep(page - 1) + '"><i class="ti ti-chevron-left"></i></a>';
        if (start > 1) s += '<a href="' + rep(1) + '">1 ' + (start > 2 ? '... ' : '') + '</a>';
        for (var i = start; i <= end; i++) {
            if (i === page) s += '<a href="' + rep(i) + '" class="active">' + i + '</a>';
            else s += '<a href="' + rep(i) + '">' + i + '</a>';
        }
        if (end !== totalpage) s += '<a href="' + rep(totalpage) + '">' + (totalpage - end > 1 ? '... ' : '') + totalpage + '</a>';
        if (page !== totalpage) s += '<a href="' + rep(page + 1) + '"><i class="ti ti-chevron-right"></i></a>';
        return s;
    };

    // 图片缩略（照搬 xiuno.js:1215-1352，依赖 canvas）
    xn.image_file_type = function(file_base64_data) {
        var pre = xn.substr(file_base64_data, 0, 14);
        if (pre === 'data:image/gif') return 'gif';
        if (pre === 'data:image/jpe' || pre === 'data:image/jpg') return 'jpg';
        if (pre === 'data:image/png') return 'png';
        return 'jpg';
    };
    xn.image_resize = function(file_base64_data, callback, options) {
        var thumb_width = options.width || 2560;
        var thumb_height = options.height || 4960;
        var action = options.action || 'thumb';
        var filetype = options.filetype || xn.image_file_type(file_base64_data);
        var qulity = options.qulity || 0.9;

        if (thumb_width < 1) return callback(-1, '缩略图宽度不能小于 1 / thumb image width length is less 1 pix');
        if (xn.substr(file_base64_data, 0, 10) !== 'data:image') return callback(-1, '传入的 base64 数据有问题 / deformed base64 data');

        var img = new Image();
        img.onload = function() {
            var water_img_onload = function(water_on, orientation) {
                var canvas = document.createElement('canvas');
                var width = 0, height = 0, canvas_width = 0, canvas_height = 0;
                var dx = 0, dy = 0;
                var img_width = img.width;
                var img_height = img.height;
                var qkswap = false;
                if (orientation === 6 || orientation === 8) {
                    img_width = img.height; img_height = img.width; qkswap = true;
                }
                if (xn.substr(file_base64_data, 0, 14) === 'data:image/gif') {
                    return callback(0, {width: img_width, height: img_height, data: file_base64_data});
                }
                if (action === 'thumb') {
                    if (img_width < thumb_width && img_height < thumb_height) {
                        width = img_width; height = img_height;
                    } else if (img_width / img_height > thumb_width / thumb_height) {
                        width = thumb_width;
                        height = Math.ceil((thumb_width / img_width) * img_height);
                    } else {
                        height = thumb_height;
                        width = Math.ceil((img_width / img_height) * thumb_height);
                    }
                    canvas_width = width; canvas_height = height;
                } else if (action === 'clip') {
                    if (img_width < thumb_width && img_height < thumb_height) {
                        if (img_height > thumb_height) {
                            thumb_width = width = img_width;
                        } else {
                            thumb_width = width = img_width;
                            thumb_height = height = img_height;
                        }
                    } else if (img_width / img_height > thumb_width / thumb_height) {
                        height = thumb_height;
                        width = Math.ceil((img_width / img_height) * thumb_height);
                        dx = -((width - thumb_width) / 2);
                    } else {
                        width = thumb_width;
                        height = Math.ceil((img_height / img_width) * thumb_width);
                        dy = -((height - thumb_height) / 2);
                    }
                    canvas_width = thumb_width; canvas_height = thumb_height;
                }
                canvas.width = canvas_width;
                canvas.height = canvas_height;
                var ctx = canvas.getContext('2d');
                if (orientation === 3) { ctx.translate(width, height); ctx.rotate(180 * Math.PI / 180); }
                else if (orientation === 6) { ctx.translate(width, 0); ctx.rotate(90 * Math.PI / 180); }
                else if (orientation === 8) { ctx.translate(0, height); ctx.rotate(-90 * Math.PI / 180); }
                ctx.clearRect(0, 0, width, height);
                ctx.drawImage(img, 0, 0, img.width, img.height, qkswap ? dy : dx, qkswap ? dx : dy, qkswap ? height : width, qkswap ? width : height);
                // ponytail: 水印逻辑保留，依赖外部 water_img 变量，已知 ceiling 是默认图片路径不存在
                if (water_on && arguments[3]) {
                    var water_img = arguments[3];
                    var water_width = water_img.width;
                    var water_height = water_img.height;
                    if (img_width > 400 && img_width > water_width && water_width > 4) {
                        ctx.globalAlpha = 0.3;
                        ctx.drawImage(water_img, 0, 0, water_width, water_height, img_width - water_width - 16, img_height - water_height - 16, water_width, water_height);
                    }
                }
                var imagedata = ctx.getImageData(0, 0, canvas_width, canvas_height);
                ctx.putImageData(imagedata, 0, 0);
                if (filetype === 'jpg') filetype = 'jpeg';
                var s = canvas.toDataURL('image/' + filetype, qulity);
                if (callback) callback(0, {width: width, height: height, data: s});
            };

            // 水印图片加载 + EXIF 方向检测（照搬 xiuno.js:1335-1346）
            var water_img = new Image();
            water_img.onload = function() {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var view = new DataView(e.target.result);
                    if (view.getUint16(0, false) !== 0xFFD8) { water_img_onload(true, -2, null, water_img); return; }
                    var length = view.byteLength, offset = 2;
                    while (offset < length) {
                        if (view.getUint16(offset + 2, false) <= 8) { water_img_onload(true, -1, null, water_img); return; }
                        var marker = view.getUint16(offset, false);
                        offset += 2;
                        if (marker === 0xFFE1) {
                            if (view.getUint32(offset += 2, false) !== 0x45786966) { water_img_onload(true, -1, null, water_img); return; }
                            var little = view.getUint16(offset += 6, false) === 0x4949;
                            offset += view.getUint32(offset + 4, little);
                            var tags = view.getUint16(offset, little);
                            offset += 2;
                            for (var i = 0; i < tags; i++) {
                                if (view.getUint16(offset + (i * 12), little) === 0x0112) {
                                    water_img_onload(true, view.getUint16(offset + (i * 12) + 8, little), null, water_img);
                                    return;
                                }
                            }
                        } else if ((marker & 0xFF00) !== 0xFF00) {
                            break;
                        } else {
                            offset += view.getUint16(offset, false);
                        }
                    }
                    water_img_onload(true, -1, null, water_img);
                };
                var dataarr = file_base64_data.split(',');
                var mime = dataarr[0].match(/:(.*?);/)[1];
                var bstr = atob(dataarr[1]);
                var n = bstr.length;
                var u8arr = new Uint8Array(n);
                while (n--) u8arr[n] = bstr.charCodeAt(n);
                reader.readAsArrayBuffer(new Blob([u8arr], {type: mime}));
            };
            water_img.onerror = function() { water_img_onload(false, 0); };
            water_img.src = options.water_image_url || xn.options.water_image_url;
            if (!water_img.src) water_img_onload(false, 0);
        };
        img.onerror = function(e) {
            console.log(e);
            if (typeof XN !== 'undefined' && XN.alert) XN.alert(e);
        };
        img.src = file_base64_data;
    };

    // ========== 块 2: $ 全局别名 shim + XNNodeList 类 ==========

    function XNNodeList(nodes) {
        if (!nodes) nodes = [];
        var arr = Array.prototype.slice.call(nodes);
        for (var i = 0; i < arr.length; i++) {
            this[i] = arr[i];
        }
        this.length = arr.length;
    }

    XNNodeList.prototype = {
        jquery: '3.x-shim',
        constructor: XNNodeList,

        // 内部工具：内容转 DOM 节点数组
        _toNodes: function(content) {
            if (!content) return [];
            if (typeof content === 'string') {
                if (content[0] === '<') {
                    var div = document.createElement('div');
                    div.innerHTML = content;
                    return Array.prototype.slice.call(div.childNodes);
                }
                return Array.prototype.slice.call(document.querySelectorAll(content));
            }
            if (content.jquery || content instanceof XNNodeList) {
                return Array.prototype.slice.call(content);
            }
            if (content.nodeType) return [content];
            if (Array.isArray(content) || content instanceof NodeList) {
                return Array.prototype.slice.call(content);
            }
            return [];
        },

        // 遍历
        each: function(fn) {
            for (var i = 0; i < this.length; i++) {
                if (fn.call(this[i], i, this[i]) === false) break;
            }
            return this;
        },
        get: function(i) {
            if (i === undefined) return Array.prototype.slice.call(this);
            return i < 0 ? this[this.length + i] : this[i];
        },
        eq: function(i) {
            var idx = i < 0 ? this.length + i : i;
            return new XNNodeList(idx >= 0 && idx < this.length ? [this[idx]] : []);
        },
        first: function() { return this.eq(0); },
        last: function() { return this.eq(this.length - 1); },
        find: function(selector) {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                r = r.concat(Array.prototype.slice.call(this[i].querySelectorAll(selector)));
            }
            return new XNNodeList(r);
        },
        // jQuery 兼容：filter 支持函数 / 选择器 / 元素 / NodeList
        // ponytail: 已知天花板——不支持 jQuery 的 DOM 过滤函数变体（如接收 index 参数的函数）
        filter: function(arg) {
            var r = [];
            if (typeof arg === 'function') {
                for (var i = 0; i < this.length; i++) {
                    if (arg.call(this[i], i, this[i])) r.push(this[i]);
                }
            } else if (typeof arg === 'string') {
                for (var j = 0; j < this.length; j++) {
                    if (this[j].matches && this[j].matches(arg)) r.push(this[j]);
                }
            } else if (arg && arg.nodeType) {
                for (var k = 0; k < this.length; k++) {
                    if (this[k] === arg) r.push(this[k]);
                }
            } else if (arg && arg.length !== undefined) {
                var set = Array.prototype.slice.call(arg);
                for (var m = 0; m < this.length; m++) {
                    if (set.indexOf(this[m]) !== -1) r.push(this[m]);
                }
            }
            return new XNNodeList(r);
        },
        closest: function(selector) {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                var el = this[i].closest ? this[i].closest(selector) : null;
                if (el && r.indexOf(el) === -1) r.push(el);
            }
            return new XNNodeList(r);
        },
        parent: function() {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                if (this[i].parentNode && r.indexOf(this[i].parentNode) === -1) r.push(this[i].parentNode);
            }
            return new XNNodeList(r);
        },
        parents: function(selector) {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                var p = this[i].parentNode;
                while (p && p !== document) {
                    if (!selector || (p.matches && p.matches(selector))) {
                        if (r.indexOf(p) === -1) r.push(p);
                    }
                    p = p.parentNode;
                }
            }
            return new XNNodeList(r);
        },
        children: function() {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                r = r.concat(Array.prototype.slice.call(this[i].children));
            }
            return new XNNodeList(r);
        },
        siblings: function() {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                var p = this[i].parentNode;
                if (!p) continue;
                for (var j = 0; j < p.children.length; j++) {
                    if (p.children[j] !== this[i]) r.push(p.children[j]);
                }
            }
            return new XNNodeList(r);
        },
        offsetParent: function() {
            var el = this[0] && this[0].offsetParent;
            return new XNNodeList(el ? [el] : []);
        },
        next: function(selector) {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                var el = this[i].nextElementSibling;
                if (el && (!selector || (el.matches && el.matches(selector)))) r.push(el);
            }
            return new XNNodeList(r);
        },
        prev: function(selector) {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                var el = this[i].previousElementSibling;
                if (el && (!selector || (el.matches && el.matches(selector)))) r.push(el);
            }
            return new XNNodeList(r);
        },

        // 事件（支持委托）
        on: function(event, selectorOrHandler, handler) {
            var delegated = typeof selectorOrHandler === 'string';
            var actualHandler = delegated ? handler : selectorOrHandler;
            var selector = delegated ? selectorOrHandler : null;
            // 支持空格分隔多事件
            var events = event.split(/\s+/);
            return this.each(function() {
                var el = this;
                if (!el._xnEventStore) el._xnEventStore = [];
                for (var ei = 0; ei < events.length; ei++) {
                    var ev = events[ei];
                    if (!ev) continue;
                    // ponytail: jQuery 兼容 — handler return false 等价于 preventDefault + stopPropagation
                    // 原生 addEventListener 不处理 return false，会导致表单 AJAX + 原生 POST 双重提交（积分双倍 bug 根因）
                    // 已违反 1 次：影响 user_update.htm 等 17+ 后台表单
                    var wrap = function(e) {
                        var ret;
                        if (delegated) {
                            var target = e.target.closest(selector);
                            if (target && el.contains(target)) {
                                ret = actualHandler.call(target, e);
                            }
                        } else {
                            ret = actualHandler.call(el, e);
                        }
                        if (ret === false) {
                            e.preventDefault();
                            e.stopPropagation();
                        }
                    };
                    el.addEventListener(ev, wrap);
                    el._xnEventStore.push({event: ev, handler: actualHandler, wrap: wrap, selector: selector});
                }
            });
        },
        off: function(event, handler) {
            return this.each(function() {
                var el = this;
                if (!el._xnEventStore) return;
                var remain = [];
                for (var i = 0; i < el._xnEventStore.length; i++) {
                    var s = el._xnEventStore[i];
                    var match = false;
                    if (!event) match = true;
                    else if (s.event === event && (!handler || s.handler === handler)) match = true;
                    if (match) {
                        el.removeEventListener(s.event, s.wrap);
                    } else {
                        remain.push(s);
                    }
                }
                el._xnEventStore = remain;
            });
        },
        one: function(event, handler) {
            var self = this;
            var wrap = function(e) {
                handler.call(this, e);
                $(this).off(event, wrap);
            };
            return this.on(event, wrap);
        },
        trigger: function(event) {
            var ev = (typeof event === 'string') ? new Event(event, {bubbles: true}) : event;
            return this.each(function() {
                if (typeof this[event] === 'function' && (event === 'submit' || event === 'click' || event === 'focus' || event === 'blur')) {
                    this[event]();
                } else {
                    this.dispatchEvent(ev);
                }
            });
        },

        // 类操作
        addClass: function(cls) {
            return this.each(function() {
                if (this.classList) this.classList.add.apply(this.classList, cls.split(/\s+/));
            });
        },
        removeClass: function(cls) {
            return this.each(function() {
                if (this.classList) this.classList.remove.apply(this.classList, cls.split(/\s+/));
            });
        },
        toggleClass: function(cls) {
            return this.each(function() {
                if (this.classList) this.classList.toggle(cls);
            });
        },
        hasClass: function(cls) {
            return this[0] ? this[0].classList.contains(cls) : false;
        },

        // 属性
        attr: function(name, value) {
            if (value === undefined) {
                return this[0] ? this[0].getAttribute(name) : null;
            }
            return this.each(function() { this.setAttribute(name, value); });
        },
        removeAttr: function(name) {
            return this.each(function() { this.removeAttribute(name); });
        },
        prop: function(name, value) {
            if (value === undefined) {
                return this[0] ? this[0][name] : undefined;
            }
            return this.each(function() { this[name] = value; });
        },
        data: function(name, value) {
            if (value === undefined) {
                return this[0] ? this[0].getAttribute('data-' + name) : null;
            }
            return this.each(function() { this.setAttribute('data-' + name, value); });
        },
        val: function(value) {
            if (value === undefined) {
                return this[0] ? this[0].value : undefined;
            }
            return this.each(function() { this.value = value; });
        },
        html: function(content) {
            if (content === undefined) {
                return this[0] ? this[0].innerHTML : '';
            }
            // jQuery 兼容：字符串直接设 innerHTML；其他类型（节点/NodeList/XNNodeList）走 empty+append，避免 innerHTML = object 得到 "[object Object]"
            if (typeof content === 'string') {
                return this.each(function() { this.innerHTML = content; });
            }
            return this.empty().append(content);
        },
        text: function(content) {
            if (content === undefined) {
                return this[0] ? this[0].textContent : '';
            }
            return this.each(function() { this.textContent = content; });
        },

        // DOM 操作
        append: function(content) {
            var nodes = this._toNodes(content);
            return this.each(function() {
                for (var i = 0; i < nodes.length; i++) {
                    this.appendChild(nodes[i].cloneNode(true));
                }
            });
        },
        prepend: function(content) {
            var nodes = this._toNodes(content);
            return this.each(function() {
                for (var i = nodes.length - 1; i >= 0; i--) {
                    this.insertBefore(nodes[i].cloneNode(true), this.firstChild);
                }
            });
        },
        before: function(content) {
            var nodes = this._toNodes(content);
            return this.each(function() {
                for (var i = 0; i < nodes.length; i++) {
                    if (this.parentNode) this.parentNode.insertBefore(nodes[i].cloneNode(true), this);
                }
            });
        },
        after: function(content) {
            var nodes = this._toNodes(content);
            return this.each(function() {
                for (var i = nodes.length - 1; i >= 0; i--) {
                    if (this.parentNode) this.parentNode.insertBefore(nodes[i].cloneNode(true), this.nextSibling);
                }
            });
        },
        // wrap: 用 HTML 字符串/元素包裹每个匹配元素（jQuery 语义，每个元素独立包裹）
        // ponytail: 仅支持 HTML 字符串（覆盖已知调用场景），已知 ceiling 是不支持选择器/函数入参
        wrap: function(wrappingElement) {
            var nodes = (typeof wrappingElement === 'string') ? this._toNodes(wrappingElement) : (wrappingElement ? [wrappingElement] : []);
            if (!nodes.length) return this;
            return this.each(function() {
                if (!this.parentNode) return;
                var wrapper = nodes[0].cloneNode(true);
                this.parentNode.insertBefore(wrapper, this);
                wrapper.appendChild(this);
            });
        },
        appendTo: function(parent) {
            var p0 = $(parent)[0];
            if (!p0) return this;
            return this.each(function() { p0.appendChild(this); });
        },
        insertBefore: function(target) {
            var t0 = $(target)[0];
            if (!t0 || !t0.parentNode) return this;
            return this.each(function() { t0.parentNode.insertBefore(this, t0); });
        },
        remove: function() {
            return this.each(function() {
                if (this.parentNode) this.parentNode.removeChild(this);
            });
        },
        empty: function() {
            return this.each(function() {
                while (this.firstChild) this.removeChild(this.firstChild);
            });
        },

        // 样式
        css: function(name, value) {
            if (typeof name === 'object') {
                return this.each(function() {
                    for (var k in name) { this.style[k] = name[k]; }
                });
            }
            if (value === undefined) {
                if (!this[0]) return '';
                return this[0].style[name] || getComputedStyle(this[0])[name];
            }
            return this.each(function() { this.style[name] = value; });
        },
        show: function() {
            return this.each(function() { this.style.display = ''; });
        },
        hide: function() {
            return this.each(function() { this.style.display = 'none'; });
        },
        toggle: function() {
            return this.each(function() {
                this.style.display = this.style.display === 'none' ? '' : 'none';
            });
        },
        slideToggle: function() {
            // ponytail: 简化实现为 display 切换，已知 ceiling 是无动画过渡
            return this.each(function() {
                this.style.display = this.style.display === 'none' ? '' : 'none';
            });
        },
        fadeIn: function() { return this.show(); },
        fadeOut: function() { return this.hide(); },

        // 尺寸/位置
        width: function() { return this[0] ? this[0].offsetWidth : 0; },
        height: function() { return this[0] ? this[0].offsetHeight : 0; },
        innerWidth: function() { return this[0] ? this[0].clientWidth : 0; },
        innerHeight: function() { return this[0] ? this[0].clientHeight : 0; },
        outerWidth: function() { return this[0] ? this[0].offsetWidth : 0; },
        outerHeight: function() { return this[0] ? this[0].offsetHeight : 0; },
        offset: function() {
            if (!this[0]) return {left: 0, top: 0};
            var rect = this[0].getBoundingClientRect();
            return {left: rect.left + window.pageXOffset, top: rect.top + window.pageYOffset};
        },
        position: function() {
            if (!this[0]) return {left: 0, top: 0};
            return {left: this[0].offsetLeft, top: this[0].offsetTop};
        },
        scrollTop: function(value) {
            if (value === undefined) {
                return this[0] ? this[0].scrollTop : 0;
            }
            return this.each(function() { this.scrollTop = value; });
        },

        // 表单
        serializeArray: function() {
            var r = [];
            for (var i = 0; i < this.length; i++) {
                var form = this[i];
                if (!form || !form.elements) continue;
                try {
                    var fd = new FormData(form);
                    fd.forEach(function(value, key) {
                        r.push({name: key, value: value});
                    });
                } catch (e) {
                    // 兜底：手动遍历
                    var els = form.elements;
                    for (var j = 0; j < els.length; j++) {
                        var el = els[j];
                        if (!el.name || el.disabled) continue;
                        var type = (el.type || '').toLowerCase();
                        if (type === 'radio' || type === 'checkbox') {
                            if (el.checked) r.push({name: el.name, value: el.value});
                        } else if (type !== 'submit' && type !== 'reset' && type !== 'button') {
                            r.push({name: el.name, value: el.value});
                        }
                    }
                }
            }
            return r;
        },
        serialize: function() {
            return this.serializeArray().map(function(p) {
                return encodeURIComponent(p.name) + '=' + encodeURIComponent(p.value);
            }).join('&');
        },

        // 表单方法
        focus: function() { return this.each(function() { if (this.focus) this.focus(); }); },
        blur: function() { return this.each(function() { if (this.blur) this.blur(); }); },
        submit: function() { return this.each(function() { if (this.submit) this.submit(); }); },

        // 显示状态
        is: function(selector) {
            if (!this[0]) return false;
            if (typeof selector === 'string') {
                // jQuery 伪类 :visible / :hidden 原生 matches() 不支持，需特判
                // ponytail: 仅覆盖 jQuery 这两个常见伪类，其他伪类交给原生 matches
                if (selector === ':visible') {
                    var el = this[0];
                    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
                }
                if (selector === ':hidden') {
                    var el = this[0];
                    return !(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
                }
                return this[0].matches ? this[0].matches(selector) : false;
            }
            return this[0] === selector;
        },

        // jQuery queue 模拟（简化版，用于 button().delay().button() 链式调用）
        // ponytail: 简化实现，同步执行；旧代码 .delay(1000).location() 不会延迟
        queue: function(fn) {
            if (typeof fn === 'function') fn(function() {});
            return this;
        },
        dequeue: function() { return this; },
        delay: function() { return this; },
        promise: function() {
            // 兼容 $.when($(...).promise()) 调用，返回立即 resolved 的 Promise
            return {then: function(fn) { fn && fn(); return this; }, done: function(fn) { fn && fn(); return this; }};
        }
    };

    // 事件快捷绑定方法（jQuery 语法糖：$(...).keyup(handler) ≡ $(...).on('keyup', handler)）
    // ponytail: 仅支持"绑定"语义（有 handler 参数）；已知 ceiling 是不支持无参触发形式
    // （focus/blur/submit 已单独定义为触发方法，不在此处覆盖）
    ['click', 'dblclick', 'keyup', 'keydown', 'keypress', 'change', 'input',
     'mouseover', 'mouseenter', 'mouseleave', 'mouseout', 'scroll', 'resize', 'load'].forEach(function(name) {
        XNNodeList.prototype[name] = function(handler) {
            return this.on(name, handler);
        };
    });

    // $ 函数
    function $(selector, context) {
        if (typeof selector === 'function') {
            // DOM ready
            if (document.readyState !== 'loading') selector();
            else document.addEventListener('DOMContentLoaded', selector);
            return;
        }
        if (selector && selector.jquery) return selector; // 已是 XNNodeList
        if (selector instanceof XNNodeList) return selector;
        if (selector && selector.nodeType) {
            return new XNNodeList([selector]);
        }
        if (typeof selector === 'string') {
            if (selector[0] === '<') {
                var div = document.createElement('div');
                div.innerHTML = selector;
                return new XNNodeList(Array.prototype.slice.call(div.childNodes));
            }
            var ctx = context ? (context instanceof XNNodeList ? context[0] : (context.jquery ? context[0] : context)) : document;
            if (!ctx) return new XNNodeList([]);
            var nodes = Array.prototype.slice.call(ctx.querySelectorAll(selector));
            return new XNNodeList(nodes);
        }
        if (Array.isArray(selector) || selector instanceof NodeList) {
            return new XNNodeList(Array.prototype.slice.call(selector));
        }
        if (selector && selector.length !== undefined && typeof selector !== 'string') {
            // 类数组对象（如 FileList）
            try {
                return new XNNodeList(Array.prototype.slice.call(selector));
            } catch (e) {}
        }
        return new XNNodeList([]);
    }

    $.fn = XNNodeList.prototype;

    // ========== 块 3: $.* 静态方法 ==========

    // $.ajax —— jQuery 兼容 shim，基于 fetch 实现，覆盖项目中 6 个后台页面的 10 处调用。
    // ponytail: ceiling = 不支持 jqXHR 的 .done/.fail/.always 链式调用、不支持同步 xhr、不支持 timeout 选项、不支持 jsonp/script 类型；
    //           升级路径 = 调用方迁移到 XN.ajax（基于 AbortController，原生 Promise）。
    $.ajax = function(opts) {
        opts = opts || {};
        var method = (opts.type || opts.method || 'GET').toUpperCase();
        var url = opts.url || '';
        var data = opts.data;
        var dataType = (opts.dataType || '').toLowerCase();
        var processData = opts.processData !== false;
        var contentType = opts.contentType;
        var isPost = method !== 'GET' && method !== 'HEAD';
        var headers = Object.assign({}, opts.headers || {});

        // 伪 xhr 对象：支持 beforeSend 设置 header、回调中读取 status/responseText
        var xhr = {
            status: 0,
            statusText: '',
            responseText: '',
            responseJSON: null,
            _headers: {},
            _respHeaders: {},
            setRequestHeader: function(name, value) { this._headers[name] = value; },
            getResponseHeader: function(name) { return this._respHeaders[name.toLowerCase()] || null; }
        };

        // 合并 beforeSend 中设置的 headers
        if (typeof opts.beforeSend === 'function') {
            opts.beforeSend(xhr);
            for (var h in xhr._headers) { headers[h] = xhr._headers[h]; }
        }

        var body = null;
        if (data != null) {
            if (data instanceof FormData) {
                body = data;
                // FormData 不设 Content-Type，让浏览器自动加 boundary
            } else if (typeof data === 'object' && processData) {
                var parts = [];
                for (var k in data) {
                    if (!data.hasOwnProperty(k)) continue;
                    var v = data[k];
                    if (v == null) v = '';
                    if (Array.isArray(v)) {
                        for (var i = 0; i < v.length; i++) {
                            parts.push(encodeURIComponent(k) + '[]=' + encodeURIComponent(v[i]));
                        }
                    } else {
                        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
                    }
                }
                var serialized = parts.join('&');
                if (isPost) {
                    if (contentType !== false && !headers['Content-Type']) {
                        headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                    }
                    body = serialized;
                } else {
                    url += (url.indexOf('?') === -1 ? '?' : '&') + serialized;
                }
            } else {
                // string 或 processData=false 的原始 body
                if (isPost) {
                    if (contentType !== false && !headers['Content-Type'] && typeof data === 'string') {
                        headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                    }
                    body = data;
                } else {
                    url += (url.indexOf('?') === -1 ? '?' : '&') + data;
                }
            }
        }

        // 默认 header
        if (!headers['X-Requested-With']) headers['X-Requested-With'] = 'XMLHttpRequest';
        if (XN.csrfToken && !headers['X-CSRF-Token']) headers['X-CSRF-Token'] = XN.csrfToken;

        fetch(url, {
            method: method,
            headers: headers,
            body: body,
            credentials: 'same-origin'
        }).then(function(resp) {
            xhr.status = resp.status;
            xhr.statusText = resp.statusText;
            resp.headers.forEach(function(value, name) { xhr._respHeaders[name.toLowerCase()] = value; });
            return resp.text().then(function(text) {
                xhr.responseText = text;
                var ct = resp.headers.get('Content-Type') || '';
                if (dataType === 'json' || (!dataType && /application\/json/i.test(ct))) {
                    try { xhr.responseJSON = JSON.parse(text); return xhr.responseJSON; } catch (e) {}
                }
                return text;
            });
        }).then(function(result) {
            var isSuccess = xhr.status >= 200 && xhr.status < 300 || xhr.status === 304;
            if (isSuccess) {
                if (typeof opts.success === 'function') opts.success.call(xhr, result, 'success', xhr);
                if (typeof opts.complete === 'function') opts.complete.call(xhr, xhr, 'success');
            } else {
                if (typeof opts.error === 'function') opts.error.call(xhr, xhr, 'error', new Error('HTTP ' + xhr.status));
                if (typeof opts.complete === 'function') opts.complete.call(xhr, xhr, 'error');
            }
        }).catch(function(err) {
            if (typeof opts.error === 'function') opts.error.call(xhr, xhr, 'error', err);
            if (typeof opts.complete === 'function') opts.complete.call(xhr, xhr, 'error');
        });

        return xhr;
    };

    $.get = function(url, data, success, dataType) {
        if (typeof data === 'function') { dataType = success; success = data; data = undefined; }
        return $.ajax({ url: url, type: 'GET', data: data, success: success, dataType: dataType });
    };
    $.post = function(url, data, success, dataType) {
        if (typeof data === 'function') { dataType = success; success = data; data = undefined; }
        return $.ajax({ url: url, type: 'POST', data: data, success: success, dataType: dataType });
    };
    $.getJSON = function(url, data, success) {
        if (typeof data === 'function') { success = data; data = undefined; }
        return $.ajax({ url: url, type: 'GET', data: data, success: success, dataType: 'json' });
    };

    $.trim = function(s) { return String.prototype.trim.call(s); };
    $.each = function(obj, callback) {
        if (obj == null) return obj;
        if (Array.isArray(obj) || obj.length !== undefined && typeof obj !== 'string' && !obj.hasOwnProperty) {
            for (var i = 0; i < obj.length; i++) {
                if (callback.call(obj[i], i, obj[i]) === false) return false;
            }
        } else {
            for (var k in obj) {
                if (callback.call(obj[k], k, obj[k]) === false) return false;
            }
        }
        return obj;
    };
    $.inArray = function(v, arr) { return arr.indexOf(v); };
    $.grep = function(arr, fn) {
        var r = [];
        for (var i = 0; i < arr.length; i++) {
            if (fn(arr[i], i)) r.push(arr[i]);
        }
        return r;
    };
    $.map = function(arr, fn) {
        var r = [];
        for (var i = 0; i < arr.length; i++) {
            var v = fn(arr[i], i);
            if (v != null) r.push(v);
        }
        return r;
    };
    $.makeArray = function(obj) { return Array.prototype.slice.call(obj); };
    $.merge = function(first, second) {
        return first.concat(Array.prototype.slice.call(second));
    };
    $.isFunction = function(obj) { return typeof obj === 'function'; };
    $.isPlainObject = function(obj) {
        return Object.prototype.toString.call(obj) === '[object Object]';
    };
    $.isArray = Array.isArray || function(obj) {
        return Object.prototype.toString.call(obj) === '[object Array]';
    };
    $.isEmptyObject = function(obj) {
        for (var k in obj) return false;
        return true;
    };
    $.parseHTML = function(s) {
        var div = document.createElement('div');
        div.innerHTML = s;
        return Array.prototype.slice.call(div.childNodes);
    };
    $.parseJSON = JSON.parse;
    $.now = function() { return Date.now(); };
    $.extend = function() {
        var args = arguments;
        var deep = false;
        var target;
        var start = 0;
        if (typeof args[0] === 'boolean') {
            deep = args[0];
            target = args[1] || {};
            start = 2;
        } else {
            target = args[0] || {};
            start = 1;
        }
        for (var i = start; i < args.length; i++) {
            if (!args[i]) continue;
            for (var key in args[i]) {
                if (deep && args[i][key] && typeof args[i][key] === 'object' && !Array.isArray(args[i][key])) {
                    if (!target[key] || typeof target[key] !== 'object') target[key] = {};
                    $.extend(true, target[key], args[i][key]);
                } else {
                    target[key] = args[i][key];
                }
            }
        }
        return target;
    };

    $.pdata = function(key, value) {
        if (typeof value !== 'undefined') {
            value = xn.json_encode(value);
            try {
                if (window.localStorage) return localStorage.setItem(key, value);
            } catch (e) {}
            try { return sessionStorage.setItem(key, value); } catch (e) {}
        } else {
            var r = null;
            try { r = localStorage.getItem(key); } catch (e) {}
            return xn.json_decode(r);
        }
    };

    $.cookie = function(name, value, time, path) {
        return XN.cookie(name, value, time, path);
    };

    $.unparam = function(str) {
        return str.split('&').reduce(function(params, param) {
            var paramSplit = param.split('=').map(function(value) {
                return decodeURIComponent(value.replace('+', ' '));
            });
            params[paramSplit[0]] = paramSplit[1];
            return params;
        }, {});
    };

    // ponytail: jQuery $.param 兼容实现，支持扁平对象和嵌套对象/数组（traditional=false 默认）
    // ceiling: 不支持 jQuery 的 traditional=true 深层嵌套语义差异，当前所有调用点（thread_list.htm:227）均为扁平对象
    $.param = function(obj, traditional) {
        var parts = [];
        function build(prefix, val) {
            if (val === null || val === undefined) val = '';
            if (Array.isArray(val)) {
                for (var i = 0; i < val.length; i++) {
                    if (traditional) {
                        build(prefix, val[i]);
                    } else {
                        build(prefix + '[]', val[i]);
                    }
                }
            } else if (typeof val === 'object') {
                for (var k in val) {
                    if (Object.prototype.hasOwnProperty.call(val, k)) {
                        build(prefix ? prefix + '[' + k + ']' : k, val[k]);
                    }
                }
            } else {
                parts.push(encodeURIComponent(prefix) + '=' + encodeURIComponent(val));
            }
        }
        build('', obj);
        return parts.join('&').replace(/%20/g, '+');
    };

    // ponytail: 串行执行，去掉了 async.js 依赖
    $.each_sync = function(array, func, callback) {
        var i = 0;
        function next() {
            if (i >= array.length) {
                if (callback) callback(null, 'complete');
                return;
            }
            var idx = i++;
            func(idx, next);
        }
        next();
    };

    // 包装 XN.post，签名兼容（code, message）
    $.xpost = function(url, postdata, callback, progress_callback) {
        if (typeof postdata === 'function') {
            callback = postdata;
            postdata = null;
        }
        XN.post(url, postdata, function(code, message) {
            if (callback) callback(code, message);
        });
        // ponytail: progress_callback 暂未透传（XN.ajax 未暴露 progress 接口）
    };

    // 包装 XN.get
    $.xget = function(url, callback, retry) {
        XN.get(url, function(code, message) {
            if (callback) callback(code, message);
        });
    };

    $.required = [];
    $.require = function() {
        var args;
        if (arguments[0] && typeof arguments[0] === 'object' && (Array.isArray(arguments[0]) || arguments[0].length !== undefined)) {
            args = Array.isArray(arguments[0]) ? arguments[0] : Array.prototype.slice.call(arguments[0]);
            if (arguments[1]) args.push(arguments[1]);
        } else {
            args = Array.prototype.slice.call(arguments);
        }

        function load(arr, i) {
            if (arr[i] === undefined) return;
            if (typeof arr[i] === 'string') {
                var js = arr[i];
                if ($.inArray(js, $.required) !== -1) {
                    if (i < arr.length) load(arr, i + 1);
                    return;
                }
                $.required.push(js);
                var script = document.createElement('script');
                script.src = js;
                script.onerror = function() {
                    console.log('script load error:' + js);
                    load(arr, i + 1);
                };
                script.onload = function() { load(arr, i + 1); };
                document.getElementsByTagName('head')[0].appendChild(script);
            } else if (typeof arr[i] === 'function') {
                arr[i]();
                if (i < arr.length) load(arr, i + 1);
            } else {
                load(arr, i + 1);
            }
        }
        load(args, 0);
    };

    $.require_css = function(filename) {
        var tags = document.getElementsByTagName('link');
        for (var i = 0; i < tags.length; i++) {
            if (tags[i].href.indexOf(filename) !== -1) return false;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.type = 'text/css';
        link.href = filename;
        document.getElementsByTagName('head')[0].appendChild(link);
    };

    // ========== 块 4: $.fn.* 扩展（迁移自 xiuno.js）==========

    // loading 图标显示
    $.fn.loading = function(action) {
        return this.each(function() {
            var el = this;
            el.style.position = 'relative';
            if (!el._jloading) {
                var loading = document.createElement('div');
                loading.className = 'loading';
                loading.innerHTML = '<img src="static/loading.gif" />';
                el.appendChild(loading);
                el._jloading = loading;
            }
            var jloading = el._jloading;
            jloading.style.display = '';
            if (!action) {
                var w = el.offsetWidth;
                var h = Math.min(el.offsetHeight, window.innerHeight);
                var lw = jloading.offsetWidth;
                var lh = jloading.offsetHeight;
                var left = w / 2 - lw / 2;
                var top = (h / 2 - lh / 2) * 2 / 3;
                jloading.style.position = 'absolute';
                jloading.style.left = left + 'px';
                jloading.style.top = top + 'px';
            } else if (action === 'close') {
                jloading.remove();
                el._jloading = null;
            }
        });
    };

    // 设置/获取 select/radio/checkbox 选中
    $.fn.checked = function(v) {
        if (v) v = v instanceof Array ? v.map(function(vv) { return vv + ''; }) : v + '';
        var filter = function(el) {
            if (!(v instanceof Array)) return el.value == v;
            return v.indexOf(el.value) !== -1;
        };
        if (v) {
            return this.each(function() {
                if (this.tagName.toLowerCase() === 'select') {
                    var options = this.querySelectorAll('option');
                    for (var i = 0; i < options.length; i++) {
                        if (filter(options[i])) options[i].selected = true;
                    }
                } else if (this.type && (this.type.toLowerCase() === 'checkbox' || this.type.toLowerCase() === 'radio')) {
                    if (filter(this)) this.checked = true;
                }
            });
        } else {
            if (this.length === 0) return [];
            var tagtype = this[0].tagName.toLowerCase() === 'select' ? 'select' : (this[0].type || '').toLowerCase();
            var r = (tagtype === 'checkbox' ? [] : '');
            for (var i = 0; i < this.length; i++) {
                var tag = this[i];
                if (tagtype === 'select') {
                    var options = tag.querySelectorAll('option');
                    for (var j = 0; j < options.length; j++) {
                        if (options[j].selected) return options[j].getAttribute('value');
                    }
                } else if (tagtype === 'checkbox') {
                    if (tag.checked) r.push(tag.value);
                } else if (tagtype === 'radio') {
                    if (tag.checked) return tag.value;
                }
            }
            return r;
        }
    };

    // serializeObject（以 xiuno.js:1669 注释中简化实现为准）
    $.fn.serializeObject = function() {
        var formobj = {};
        var form0 = this[0];
        if (!form0 || !form0.elements) return formobj;
        var elements = Array.prototype.slice.call(form0.elements);
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            var type = (el.getAttribute('type') || '').toLowerCase();
            var name = el.getAttribute('name');
            if (name && el.nodeName.toLowerCase() !== 'fieldset' && !el.disabled &&
                type !== 'submit' && type !== 'reset' && type !== 'button' &&
                ((type !== 'radio' && type !== 'checkbox') || el.checked)) {
                if (type === 'radio' || type === 'checkbox') {
                    if (!formobj[name]) formobj[name] = [];
                    formobj[name].push(el.value);
                } else {
                    formobj[name] = el.value;
                }
            }
        }
        return formobj;
    };

    // button: loading/disabled/enable/reset/自定义文本
    // ponytail: 简化实现，去掉 jQuery queue 异步执行，已知 ceiling 是无法用 .delay() 串联
    $.fn.button = function(status) {
        return this.each(function() {
            var loading_text = this.getAttribute('loading-text') || this.getAttribute('data-loading-text');
            if (status === 'loading') {
                this.disabled = true;
                this.classList.add('disabled');
                if (!this.getAttribute('default-text')) {
                    this.setAttribute('default-text', this.textContent || '');
                }
                if (loading_text) this.innerHTML = loading_text;
            } else if (status === 'disabled') {
                this.disabled = true;
                this.classList.add('disabled');
            } else if (status === 'enable') {
                this.disabled = false;
                this.classList.remove('disabled');
            } else if (status === 'reset') {
                this.disabled = false;
                this.classList.remove('disabled');
                var defaultText = this.getAttribute('default-text');
                if (defaultText !== null) this.textContent = defaultText;
            } else {
                this.textContent = status;
            }
        });
    };

    // 跳转
    $.fn.location = function(href) {
        if (!href) window.location.reload();
        else window.location.href = href;
        return this;
    };

    // 表单错误提示：原名 $.fn.alert，但 BS5 defineJQueryPlugin 在 DOMContentLoaded 时会
    // 覆盖 $.fn.alert 为 Alert.jQueryInterface（把字符串参数当方法名解析，抛 "No method named xxx"），
    // 故重命名为 $.fn.fieldError 避免冲突。已违反 1 次，影响 14 个后台模板的字段错误提示。
    $.fn.fieldError = function(message) {
        return this.each(function() {
            var jthis = $(this);
            var parent = jthis.closest('.form-group');
            if (parent.length) parent.addClass('has-danger');
            jthis.addClass('form-control-danger');
            jthis.attr('title', message);
            var tip = jthis.next('.xn-field-tip');
            if (tip.length) {
                tip.text(message).show();
            } else {
                jthis.after('<span class="xn-field-tip text-danger small">' + message + '</span>');
            }
        });
    };

    // 重置 form 状态
    $.fn.reset = function() {
        return this.each(function() {
            var form = this;
            if (form.tagName === 'FORM' && form.reset) {
                try { form.reset(); } catch (e) {}
            }
            var submits = form.querySelectorAll('input[type="submit"]');
            for (var i = 0; i < submits.length; i++) {
                $(submits[i]).button('reset');
            }
            var tips = form.querySelectorAll('.xn-field-tip');
            for (var i = 0; i < tips.length; i++) tips[i].remove();
            var inputs = form.querySelectorAll('input');
            for (var i = 0; i < inputs.length; i++) inputs[i].removeAttribute('title');
        });
    };

    // 代替 <base href="../" />
    $.fn.base_href = function(base) {
        function replace_url(url) {
            if (/^https?:\/\//i.test(url)) return url;
            return base + url;
        }
        this.find('img').each(function() {
            var src = this.getAttribute('src');
            if (src) this.setAttribute('src', replace_url(src));
        });
        this.find('a').each(function() {
            var href = this.getAttribute('href');
            if (href) this.setAttribute('href', replace_url(href));
        });
        return this;
    };

    // 批量修改 input name="gid[123]" 中的 123
    $.fn.attr_name_index = function(rowid) {
        return this.each(function() {
            var name = this.getAttribute('name');
            if (!name) return;
            name = name.replace(/\[(\d*)\]/, function(all, oldid) {
                var newid = rowid === undefined ? xn.intval(oldid) + 1 : rowid;
                return '[' + newid + ']';
            });
            this.setAttribute('name', name);
        });
    };

    $.fn.son = $.fn.children;

    // remove() 并清除子节点事件（避免内存泄露）
    $.fn.removeDeep = function() {
        this.find('*').off();
        this.off();
        this.remove();
        return this;
    };

    // empty() 并清除子节点事件
    $.fn.emptyDeep = function() {
        this.find('*').off();
        this.empty();
        return this;
    };

    // 图片缩略/裁剪 base64 存入隐藏域（依赖 xn.image_resize）
    $.fn.base64_encode_file = function(width, height, action) {
        action = action || 'thumb';
        var jform = this;
        jform.on('change', 'input[type="file"]', function(e) {
            var jfile = $(this);
            var assocId = jfile.data('assoc');
            var jassoc = assocId ? $('#' + assocId) : null;
            var obj = e.target;
            jform.find('input[type="submit"]').button('disabled');
            var file = obj.files[0];
            if (!file) return;

            // 创建隐藏域保存 base64
            var jhidden = document.createElement('input');
            jhidden.type = 'hidden';
            jhidden.name = obj.name;
            jform[0].appendChild(jhidden);
            obj.name = '';

            var reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = function() {
                if (width && height && xn.substr(this.result, 0, 10) === 'data:image') {
                    xn.image_resize(this.result, function(code, message) {
                        if (code === 0) {
                            if (jassoc && jassoc.length) jassoc.attr('src', message.data);
                            jhidden.value = message.data;
                        } else {
                            if (typeof XN !== 'undefined' && XN.alert) XN.alert(message);
                        }
                        jform.find('input[type="submit"]').button('reset');
                    }, {width: width, height: height, action: action});
                } else {
                    if (jassoc && jassoc.length) jassoc.attr('src', this.result);
                    jhidden.value = this.result;
                    jform.find('input[type="submit"]').button('reset');
                }
            };
        });
    };

    // 浮动定位算法（照搬 xiuno.js:1799，把 $ 调用改为原生/XNNodeList）
    $.fn.xn_position = function(jfloat, pos, offset) {
        var jthis = $(this);
        var jparent = jthis.offsetParent();
        pos = pos || 0;
        offset = offset || {left: 0, top: 0};
        offset.left = offset.left || 0;
        offset.top = offset.top || 0;

        // 如果 menu 藏的特别深，把它移动出来
        var fp = jfloat.offsetParent();
        if (fp[0] !== jparent[0]) {
            jfloat.appendTo(jparent);
        }

        // 设置菜单为绝对定位
        jfloat.css('position', 'absolute').css('z-index', (jthis.css('z-index') || 0) + 1);

        var p = jthis.position();
        p.w = jthis.outerWidth();
        p.h = jthis.outerHeight();
        var m = {left: 0, top: 0};
        m.w = jfloat.outerWidth();
        m.h = jfloat.outerHeight();

        if (pos === 12) { m.left = p.left + ((p.w - m.w) / 2); m.top = p.top - m.h; }
        else if (pos === 1) { m.left = p.left + (p.w - m.w); m.top = p.top - m.h; }
        else if (pos === 11) { m.left = p.left; m.top = p.top - m.h; }
        else if (pos === 2) { m.left = p.left + p.w; m.top = p.top; }
        else if (pos === 3) { m.left = p.left + p.w; m.top = p.top + ((p.h - m.h) / 2); }
        else if (pos === 4) { m.left = p.left + p.w; m.top = p.top + (p.h - m.h); }
        else if (pos === 5) { m.left = p.left + (p.w - m.w); m.top = p.top + p.h; }
        else if (pos === 6) { m.left = p.left + ((p.w - m.w) / 2); m.top = p.top + p.h; }
        else if (pos === 7) { m.left = p.left; m.top = p.top + p.h; }
        else if (pos === 8) { m.left = p.left - m.w; m.top = p.top + (p.h - m.h); }
        else if (pos === 9) { m.left = p.left - m.w; m.top = p.top + ((p.h - m.h) / 2); }
        else if (pos === 10) { m.left = p.left - m.w; m.top = p.top; }
        else if (pos === -12) { m.left = p.left + ((p.w - m.w) / 2); m.top = p.top; }
        else if (pos === -1) { m.left = p.left + (p.w - m.w); m.top = p.top; }
        else if (pos === -3) { m.left = p.left + p.w - m.w; m.top = p.top + ((p.h - m.h) / 2); }
        else if (pos === -5) { m.left = p.left + (p.w - m.w); m.top = p.top + p.h - m.h; }
        else if (pos === -6) { m.left = p.left + ((p.w - m.w) / 2); m.top = p.top + p.h - m.h; }
        else if (pos === -7) { m.left = p.left; m.top = p.top + p.h - m.h; }
        else if (pos === -9) { m.left = p.left; m.top = p.top + ((p.h - m.h) / 2); }
        else if (pos === -11) { m.left = p.left; m.top = p.top - m.h + m.h; }
        else if (pos === 0) { m.left = p.left + ((p.w - m.w) / 2); m.top = p.top + ((p.h - m.h) / 2); }
        jfloat.css({left: m.left + offset.left, top: m.top + offset.top});
    };

    // 菜单定位（照搬 xiuno.js:1917）
    $.fn.xn_menu = function(jmenu, pos, option) {
        var jthis = $(this);
        pos = pos || 6;
        var offset = {};
        option = option || {hidearrow: 0};

        if (!jmenu.jarrow && !option.hidearrow) {
            var arrow = document.createElement('div');
            arrow.className = 'arrow arrow-up';
            arrow.style.display = 'none';
            var arrowBox = document.createElement('div');
            arrowBox.className = 'arrow-box';
            arrow.appendChild(arrowBox);
            if (jthis[0].parentNode) {
                jthis[0].parentNode.insertBefore(arrow, jthis[0].nextSibling);
            }
            jmenu.jarrow = $(arrow);
        }
        if (!option.hidearrow) {
            if (pos === 2 || pos === 3 || pos === 4) {
                jmenu.jarrow.addClass('arrow-left');
                offset.left = 7;
            } else if (pos === 5 || pos === 6 || pos === 7) {
                jmenu.jarrow.addClass('arrow-up');
                offset.top = 7;
            } else if (pos === 8 || pos === 9 || pos === 10) {
                jmenu.jarrow.addClass('arrow-right');
                offset.left = -7;
            } else if (pos === 11 || pos === 12 || pos === 1) {
                jmenu.jarrow.addClass('arrow-down');
                offset.top = -7;
            }
        }
        var arr_pos_map = {2: 10, 3: 9, 4: 8, 5: 1, 6: 12, 7: 11, 8: 4, 10: 2, 11: 7, 12: 6, 1: 5};
        var arr_offset_map = {
            2: {left: -1, top: 10}, 3: {left: -1, top: 0}, 4: {left: -1, top: -10},
            5: {left: -10, top: -1}, 6: {left: 0, top: -1}, 7: {left: 10, top: -1},
            8: {left: 1, top: -10}, 9: {left: 1, top: 0}, 10: {left: 1, top: 10},
            11: {left: 10, top: 1}, 12: {left: 0, top: 1}, 1: {left: -10, top: 1}
        };
        jthis.xn_position(jmenu, pos, offset);
        jmenu.toggle();

        var mpos = arr_pos_map[pos];
        if (!option.hidearrow) jmenu.xn_position(jmenu.jarrow, mpos, arr_offset_map[mpos]);
        if (!option.hidearrow) jmenu.jarrow.toggle();

        var menu_hide = function() {
            if (jmenu[0] && jmenu[0].style.display === 'none') return;
            jmenu.toggle();
            if (!option.hidearrow) jmenu.jarrow.hide();
            $('body').off('click', menu_hide);
        };
        $('body').off('click', menu_hide).on('click', menu_hide);
    };

    // xn_dropdown（照搬 xiuno.js:1973）
    $.fn.xn_dropdown = function() {
        return this.each(function() {
            var jthis = $(this);
            var jtoggler = jthis.find('.dropdown-toggle');
            var jdropmenu = jthis.find('.dropdown-menu');
            var pos = jthis.data('pos') || 5;
            var hidearrow = !!jthis.data('hidearrow');
            jtoggler.on('click', function() {
                jtoggler.xn_menu(jdropmenu, pos, {hidearrow: hidearrow});
                return false;
            });
        });
    };

    // xn_toggle（照搬 xiuno.js:1987，slideToggle 已简化为 display 切换）
    $.fn.xn_toggle = function() {
        return this.each(function() {
            var jthis = $(this);
            var targetSel = jthis.data('target');
            var jtarget = $(targetSel);
            var target_hide = function() {
                if (jtarget[0] && jtarget[0].style.display === 'none') return;
                jtarget.slideToggle('fast');
                $('body').off('click', target_hide);
            };
            jthis.on('click', function() {
                jtarget.slideToggle('fast');
                $('body').off('click', target_hide).on('click', target_hide);
                return false;
            });
        });
    };

    // ========== 块 5: $.alert / $.confirm / $.ajax_modal + HTML 解析辅助 ==========

    // HTML 解析辅助函数（照搬 bootstrap-plugin.js:77-171）
    xn.get_loaded_script = function() {
        var arr = [];
        var scripts = document.querySelectorAll('script[src]');
        for (var i = 0; i < scripts.length; i++) {
            arr.push(scripts[i].getAttribute('src'));
        }
        return arr;
    };
    xn.get_stylesheet_link = function(s) {
        var arr = [];
        var r = s.match(/<link[^>]*?href=\s*"([^"]+)"[^>]*>/ig);
        if (!r) return arr;
        for (var i = 0; i < r.length; i++) {
            var r2 = r[i].match(/<link[^>]*?href=\s*"([^"]+)"[^>]*>/i);
            if (r2) arr.push(r2[1]);
        }
        return arr;
    };
    xn.get_script_src = function(s) {
        var arr = [];
        var r = s.match(/<script[^>]*?src=\s*"([^"]+)"[^>]*><\/script>/ig);
        if (!r) return arr;
        for (var i = 0; i < r.length; i++) {
            var r2 = r[i].match(/<script[^>]*?src=\s*"([^"]+)"[^>]*><\/script>/i);
            if (r2) arr.push(r2[1]);
        }
        return arr;
    };
    xn.get_script_section = function(s) {
        var arr = s.match(/<script[^>]+ajax-eval="true"[^>]*>([\s\S]+?)<\/script>/ig);
        return arr ? arr : [];
    };
    xn.strip_script_src = function(s) {
        return s.replace(/<script[^>]*?src=\s*"([^"]+)"[^>]*><\/script>/ig, '');
    };
    xn.strip_script_section = function(s) {
        return s.replace(/<script([^>]*)>([\s\S]+?)<\/script>/ig, '');
    };
    xn.strip_stylesheet_link = function(s) {
        return s.replace(/<link[^>]*?href=\s*"([^"]+)"[^>]*>/ig, '');
    };
    xn.eval_script = function(arr, args) {
        if (!arr) return;
        for (var i = 0; i < arr.length; i++) {
            var s = arr[i].replace(/<script([^>]*)>([\s\S]+?)<\/script>/i, '$2');
            try {
                var func = new Function('args', s);
                func(args);
            } catch (e) {
                console.log('eval_script() error: %o, script: %s', e, s);
                if (typeof XN !== 'undefined' && XN.toast) XN.toast(s, 'danger');
            }
        }
    };
    xn.eval_stylesheet = function(arr) {
        if (!arr) return;
        for (var i = 0; i < arr.length; i++) {
            $.require_css(arr[i]);
        }
    };
    xn.get_title_body_script_css = function(s) {
        s = $.trim(s);
        s = s.replace(/<!--\[if\s+lt\s+IE\s+9\]>([\s\S]+?)<\!\[endif\]-->/ig, '');
        var title = '';
        var body = '';
        var script_sections = xn.get_script_section(s);
        var stylesheet_links = xn.get_stylesheet_link(s);
        var arr1 = xn.get_loaded_script();
        var arr2 = xn.get_script_src(s);
        var script_srcs = xn.array_diff(arr2, arr1);
        s = xn.strip_script_src(s);
        s = xn.strip_script_section(s);
        s = xn.strip_stylesheet_link(s);
        var r1 = s.match(/<title>([^<]+?)<\/title>/i);
        if (r1 && r1[1]) title = r1[1];
        var r2 = s.match(/<body[^>]*>([\s\S]+?)<\/body>/i);
        if (r2 && r2[1]) body = r2[1];
        var tmp = document.createElement('div');
        tmp.innerHTML = body;
        var t = tmp.querySelector('div.ajax-body') || tmp.querySelector('#body');
        if (t) body = t.innerHTML;
        if (!body) body = s;
        if (body.indexOf('<meta ') !== -1) {
            console.log('加载的数据有问题：body: %s: ', body);
            body = '';
        }
        return {title: title, body: body, script_sections: script_sections, script_srcs: script_srcs, stylesheet_links: stylesheet_links};
    };

    // $.alert（照搬 bootstrap-plugin.js:2-35，用 XNNodeList + bootstrap.Modal）
    $.alert = function(subject, timeout, options) {
        options = options || {size: 'md'};
        var langObj = (typeof lang !== 'undefined') ? lang : {};
        var s = '<div class="modal fade" tabindex="-1" role="dialog">' +
            '<div class="modal-dialog modal-dialog-centered modal-' + (options.size || 'md') + '">' +
            '<div class="modal-content border-0 rounded-3 shadow">' +
            '<div class="modal-header border-0 pb-0">' +
            '<h6 class="modal-title fw-bold"><i class="ti ti-info-circle text-primary me-2"></i>' + (langObj.tips_title || '提示') + '</h6>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body pt-2"><p class="mb-0">' + subject + '</p></div>' +
            '<div class="modal-footer border-0 pt-0">' +
            '<button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal">' + (langObj.close || '关闭') + '</button>' +
            '</div></div></div></div>';
        var jmodal = $(s).appendTo('body');
        var modalEl = jmodal[0];
        var bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();
        modalEl.addEventListener('hidden.bs.modal', function() {
            jmodal.remove();
        });
        if (typeof timeout !== 'undefined' && timeout >= 0) {
            setTimeout(function() { bsModal.hide(); }, timeout * 1000);
        }
        jmodal._bsModal = bsModal;
        return jmodal;
    };

    // $.confirm（照搬 bootstrap-plugin.js:37-74）
    $.confirm = function(subject, ok_callback, options) {
        options = options || {size: 'md'};
        options.body = options.body || '';
        var langObj = (typeof lang !== 'undefined') ? lang : {};
        var title = options.body ? subject : (langObj.confirm_title || '确认') + ':';
        var subjectHtml = options.body ? '' : '<p>' + subject + '</p>';
        var s = '<div class="modal fade" tabindex="-1" role="dialog">' +
            '<div class="modal-dialog modal-dialog-centered modal-' + (options.size || 'md') + '">' +
            '<div class="modal-content border-0 rounded-3 shadow">' +
            '<div class="modal-header border-0 pb-0">' +
            '<h6 class="modal-title fw-bold"><i class="ti ti-help-circle text-warning me-2"></i>' + title + '</h6>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body pt-2">' + subjectHtml + options.body + '</div>' +
            '<div class="modal-footer border-0 pt-0">' +
            '<button type="button" class="btn btn-primary px-4 btn-ok">' + (langObj.confirm || '确定') + '</button>' +
            '<button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">' + (langObj.close || '关闭') + '</button>' +
            '</div></div></div></div>';
        var jmodal = $(s).appendTo('body');
        var modalEl = jmodal[0];
        var bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();
        modalEl.addEventListener('hidden.bs.modal', function() {
            jmodal.remove();
        });
        jmodal.find('.btn-ok').on('click', function() {
            bsModal.hide();
            if (ok_callback) ok_callback();
        });
        jmodal._bsModal = bsModal;
        return jmodal;
    };

    // $.ajax_modal（照搬 bootstrap-plugin.js:173-196，用 XN.get 代替 $.xget）
    $.ajax_modal = function(url, title, size, callback, arg) {
        var langObj = (typeof lang !== 'undefined') ? lang : {};
        var jmodal = $.alert(langObj.loading || 'Loading...', -1, {size: size});
        jmodal.find('.modal-title').html(title);
        XN.get(url, function(code, message) {
            if (code === -101) {
                var r = xn.get_title_body_script_css(message);
                jmodal.find('.modal-body').html(r.body);
                jmodal.find('.modal-footer').hide();
            } else {
                jmodal.find('.modal-body').html(message);
                return;
            }
            xn.eval_stylesheet(r.stylesheet_links);
            jmodal.script_sections = r.script_sections;
            if (r.script_srcs.length > 0) {
                $.require(r.script_srcs, function() {
                    xn.eval_script(r.script_sections, {jmodal: jmodal, callback: callback, arg: arg});
                });
            } else {
                xn.eval_script(r.script_sections, {jmodal: jmodal, callback: callback, arg: arg});
            }
        });
        return jmodal;
    };

    // ========== 块 6: Bootstrap 5 桥接（迁移自 bbs.js:3-26）==========
    // 让旧插件的 $(el).modal('show') / .dropdown('toggle') 等调用正常工作
    if (typeof bootstrap !== 'undefined') {
        var _bsBridge = {
            modal: 'Modal', dropdown: 'Dropdown', tooltip: 'Tooltip',
            popover: 'Popover', collapse: 'Collapse', alert: 'Alert', tab: 'Tab'
        };
        Object.keys(_bsBridge).forEach(function(pluginName) {
            $.fn[pluginName] = function(methodOrOptions) {
                return this.each(function() {
                    var bsClassName = _bsBridge[pluginName];
                    if (!bootstrap[bsClassName]) return;
                    var instance = bootstrap[bsClassName].getInstance(this) || new bootstrap[bsClassName](this);
                    if (typeof methodOrOptions === 'string') {
                        if (['toggle', 'show', 'hide', 'dispose', 'enable', 'disable', 'update'].indexOf(methodOrOptions) !== -1) {
                            instance[methodOrOptions]();
                        }
                    }
                });
            };
        });
    }

    // ========== 块 7: 暴露全局 + DOM ready 自动初始化 ==========

    global.$ = $;
    global.XN = XN;
    global.xn = xn;
    // 兼容直接用 jQuery 的旧代码（仅当外部未定义时）
    if (typeof global.jQuery === 'undefined') {
        global.jQuery = $;
    }

    // ponytail: Bootstrap 5 检测到 window.jQuery 存在时会用 n.Event(type, args) 触发事件，
    // 必须补 $.Event 静态构造函数，否则 toast/modal .show() 会抛 "n.Event is not a function"。
    // Bootstrap 5 trigger() 调用链：s=n.Event(e,i); n(t).trigger(s); 然后基于 s 的
    // isPropagationStopped/isImmediatePropagationStopped/isDefaultPrevented 决定是否再派发原生事件。
    // shim 的 .trigger() 已通过 dispatchEvent 派发 s，若让 Bootstrap 再 dispatchEvent(l) 会双重触发
    // 监听器（toast 弹两次等），故 isPropagationStopped / isImmediatePropagationStopped 返回 true
    // 让 Bootstrap 跳过自己的派发；isDefaultPrevented 跟随原生 defaultPrevented 以支持 preventDefault。
    // 已知 ceiling: 不支持 jQuery.Event 的 namespace 与 handler 派发，仅满足 Bootstrap trigger 需求。
    if (typeof $.Event !== 'function') {
        $.Event = function(type, props) {
            var ev = new Event(type, {bubbles: true, cancelable: true});
            ev.isPropagationStopped = function() { return true; };
            ev.isImmediatePropagationStopped = function() { return true; };
            ev.isDefaultPrevented = function() { return ev.defaultPrevented; };
            if (props) {
                for (var k in props) {
                    if (Object.prototype.hasOwnProperty.call(props, k)) ev[k] = props[k];
                }
            }
            return ev;
        };
    }

    // DOM ready 自动初始化 dropdown/toggle/data-modal-title
    XN.ready(function() {
        try { $('.xn-dropdown').xn_dropdown(); } catch (e) { console.warn('xn_dropdown init error:', e); }
        try { $('.xn-toggle').xn_toggle(); } catch (e) { console.warn('xn_toggle init error:', e); }

        // data-modal-title 自动绑定（照搬 bootstrap-plugin.js:227-245）
        document.querySelectorAll('[data-modal-title]').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                var url = el.getAttribute('data-modal-url') || el.getAttribute('href');
                var title = el.getAttribute('data-modal-title');
                var arg = el.getAttribute('data-modal-arg');
                var callbackStr = el.getAttribute('data-modal-callback');
                var callback = callbackStr ? window[callbackStr] : null;
                var size = el.getAttribute('data-modal-size');
                if (el.ajax_modal) {
                    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        var oldBsModal = bootstrap.Modal.getInstance(el.ajax_modal[0]);
                        if (oldBsModal) oldBsModal.hide();
                    }
                    el.ajax_modal.remove();
                }
                el.ajax_modal = $.ajax_modal(url, title, size, callback, arg);
                return false;
            });
        });
    });

})(window);

console.log('xiuno-modern.js loaded');
