/**
 * xiuno-modern.js — 原生 JS 兼容层
 *
 * 渐进式替代 jQuery，新代码应使用本文件中的 API。
 * 旧代码（xiuno.js / bbs.js / form.js）仍依赖 jQuery，保持不变。
 *
 * 使用方式：
 *   新插件/新页面 → 用 XN.xxx() 或原生 JS
 *   旧插件/旧页面 → 继续用 $.xxx()，无需改动
 *
 * 加载顺序（footer.inc.htm）：
 *   1. bootstrap.bundle.min.js
 *   2. jquery-3.7.1.min.js
 *   3. xiuno.js（依赖 jQuery）
 *   4. xiuno-modern.js（本文件，不依赖 jQuery）
 *   5. bbs.js / form.js / async.js
 */

(function (global) {
    'use strict';

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

        if (isPost && data) {
            if (data instanceof FormData) {
                body = data;
            } else if (typeof data === 'object') {
                headers['Content-Type'] = 'application/x-www-form-urlencoded';
                body = Object.keys(data).map(function (k) {
                    return encodeURIComponent(k) + '=' + encodeURIComponent(data[k] == null ? '' : data[k]);
                }).join('&');
            } else {
                body = data;
            }
        } else if (!isPost && data) {
            var sep = url.indexOf('?') === -1 ? '?' : '&';
            url += sep + Object.keys(data).map(function (k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(data[k] == null ? '' : data[k]);
            }).join('&');
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

})(window);

console.log('xiuno-modern.js loaded');
