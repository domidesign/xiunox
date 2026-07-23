// ========== 全局图片放大 lightbox ==========
// 基于 Bootstrap 5 Modal + 原生 JS,零第三方依赖
// 覆盖范围:帖子正文 .message / appcenter 应用介绍 .appcenter-intro-content /
//          应用截图 .appcenter-screenshots / 显式标记 a[data-lightbox]
// 特性:同容器内轮播、滚轮/双击/按钮缩放、鼠标拖拽、触摸双指缩放、键盘快捷键、旋转
// htmx 兼容:监听 htmx:after:swap 重新绑定

(function () {
    'use strict';

    // ponytail: 容器选择器(用于 closest() 查找图片所属容器,逗号分隔多选)
    var CONTAINER_SELECTORS = '.message, .appcenter-intro-content, .appcenter-screenshots, [data-lightbox-container]';
    // ponytail: 图片选择器(用于 querySelectorAll 查找要绑定的 img,每个容器后必须单独拼 ' img')
    // 错误写法 '.message, .appcenter-intro-content img' 会让前两个匹配容器 div 本身而非 img
    var IMG_SELECTORS = '.message img, .appcenter-intro-content img, .appcenter-screenshots img, [data-lightbox-container] img';
    var ANCHOR_SELECTOR = 'a[data-lightbox]';
    var IMG_EXTS = /\.(jpe?g|png|gif|webp|svg|bmp|avif)(\?|$)/i;

    // ponytail: 打开 lightbox 时的默认缩放比例(80%),让图片略小于窗口便于整体查看
    var DEFAULT_SCALE = 0.8;

    var state = {
        scale: DEFAULT_SCALE,
        rotation: 0,
        tx: 0,
        ty: 0,
        list: [],
        index: 0,
        isDragging: false,
        dragStartX: 0,
        dragStartY: 0,
        pinchStartDist: 0,
        pinchStartScale: DEFAULT_SCALE
    };

    var modal = document.getElementById('xnLightbox');
    // ponytail: 容错——若 footer.inc.htm 未注入 Modal(后台页面等),直接 no-op
    if (!modal) return;

    var img = document.getElementById('xnLightboxImg');
    var counter = document.getElementById('xnLightboxCounter');
    var prevBtn = document.getElementById('xnLightboxPrev');
    var nextBtn = document.getElementById('xnLightboxNext');
    var zoomInBtn = document.getElementById('xnLightboxZoomIn');
    var zoomOutBtn = document.getElementById('xnLightboxZoomOut');
    var scaleLabel = document.getElementById('xnLightboxScale');
    var rotateBtn = document.getElementById('xnLightboxRotate');
    var resetBtn = document.getElementById('xnLightboxReset');
    var bsModal = null;

    function getBsModal() {
        if (!bsModal && typeof bootstrap !== 'undefined') {
            bsModal = new bootstrap.Modal(modal, { keyboard: false });
        }
        return bsModal;
    }

    function isImageUrl(url) {
        if (!url) return false;
        return IMG_EXTS.test(url);
    }

    // 收集容器内所有图片,跳过 emoji / icon / 显式禁用的元素
    function collectImages(container) {
        var imgs = container.querySelectorAll('img');
        var list = [];
        for (var i = 0; i < imgs.length; i++) {
            var im = imgs[i];
            var src = im.getAttribute('src') || '';
            if (!isValidSrc(src)) continue;
            // 跳过 base64 emoji 等(常见 SVG/GIF 小图标)
            if (src.indexOf('data:image/svg') === 0) continue;
            if (src.indexOf('data:image/gif;base64,R0lGOD') === 0) continue;
            // 跳过 tabler-icons 等图标字体回退 img
            if (im.closest('.ti')) continue;
            // 跳过显式禁用
            if (im.hasAttribute('data-no-lightbox')) continue;
            list.push({ src: src, el: im });
        }
        return list;
    }

    function findGroup(clickedImage) {
        // 优先在显式容器内收集
        var container = clickedImage.closest('[data-lightbox-container]');
        if (!container) {
            container = clickedImage.closest(CONTAINER_SELECTORS);
        }
        if (!container) return [{ src: clickededImage.src, el: clickedImage }];
        return collectImages(container);
    }

    function applyTransform() {
        img.style.transform =
            'translate(' + state.tx + 'px, ' + state.ty + 'px) ' +
            'scale(' + state.scale + ') ' +
            'rotate(' + state.rotation + 'deg)';
        if (scaleLabel) scaleLabel.textContent = Math.round(state.scale * 100) + '%';
    }

    function resetTransform() {
        state.scale = DEFAULT_SCALE;
        state.rotation = 0;
        state.tx = 0;
        state.ty = 0;
        applyTransform();
    }

    function show(list, idx) {
        state.list = list;
        state.index = idx;
        var item = list[idx];
        if (!item) return;
        // ponytail: 最终兜底,src 无效就不弹窗(避免 404)
        if (!isValidSrc(item.src)) return;
        img.src = item.src;
        resetTransform();
        if (counter) counter.textContent = (idx + 1) + ' / ' + list.length;
        if (prevBtn) prevBtn.style.display = list.length > 1 ? '' : 'none';
        if (nextBtn) nextBtn.style.display = list.length > 1 ? '' : 'none';
        var m = getBsModal();
        if (m) m.show();
    }

    function showAt(idx) {
        if (state.list.length === 0) return;
        state.index = (idx + state.list.length) % state.list.length;
        img.src = state.list[state.index].src;
        resetTransform();
        if (counter) counter.textContent = (state.index + 1) + ' / ' + state.list.length;
    }

    // ponytail: 检查 src 是否有效(非空、非字面量 'undefined'/'null' 等)
    // 这类 src 浏览器会当相对 URL 解析,加上当前页面后缀 → undefined.html / null.htm → 404
    function isValidSrc(src) {
        if (!src) return false;
        if (src === 'undefined' || src === 'null' || src === 'false' || src === '[object Object]') return false;
        return true;
    }

    function bindImage(imgEl) {
        if (imgEl.dataset.xnLightboxBound === '1') return;
        // ponytail: 跳过 src 无效的图片(避免点击后弹窗显示 undefined.html 404)
        if (!isValidSrc(imgEl.getAttribute('src') || '')) return;
        imgEl.dataset.xnLightboxBound = '1';
        imgEl.style.cursor = 'zoom-in';
        imgEl.addEventListener('click', function (e) {
            // 处理被 <a> 包裹的情况
            var anchor = imgEl.closest('a');
            if (anchor) {
                var href = anchor.getAttribute('href') || '';
                // <a target=_blank> 或非图片 URL 链接,保留默认跳转
                if (anchor.target === '_blank') return;
                if (href && href !== '#' && !isImageUrl(href) && href !== imgEl.src) return;
                e.preventDefault();
            }
            var group = findGroup(imgEl);
            var idx = -1;
            for (var i = 0; i < group.length; i++) {
                if (group[i].el === imgEl) { idx = i; break; }
            }
            if (idx === -1) {
                group = [{ src: imgEl.getAttribute('src') || imgEl.src, el: imgEl }];
                idx = 0;
            }
            show(group, idx);
        });
    }

    function bindAnchor(anchor) {
        if (anchor.dataset.xnLightboxAnchorBound === '1') return;
        anchor.dataset.xnLightboxAnchorBound = '1';
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            var href = anchor.getAttribute('href') || '';
            var imgInAnchor = anchor.querySelector('img');
            var group = imgInAnchor ? findGroup(imgInAnchor) : [];
            if (group.length === 0 && href) {
                group = [{ src: href, el: null }];
            }
            var idx = 0;
            if (imgInAnchor) {
                for (var i = 0; i < group.length; i++) {
                    if (group[i].el === imgInAnchor) { idx = i; break; }
                }
            }
            show(group, idx);
        });
    }

    function bindAll(container) {
        var root = container || document;
        if (!root.querySelectorAll) return;
        // 绑定容器内 img(必须用 IMG_SELECTORS,不能 CONTAINER_SELECTORS + ' img' 拼接)
        var imgs = root.querySelectorAll(IMG_SELECTORS);
        for (var i = 0; i < imgs.length; i++) {
            bindImage(imgs[i]);
        }
        // 绑定显式 a[data-lightbox]
        var anchors = root.querySelectorAll(ANCHOR_SELECTOR);
        for (var j = 0; j < anchors.length; j++) {
            bindAnchor(anchors[j]);
        }
    }

    // ===== 事件绑定 =====
    if (prevBtn) prevBtn.addEventListener('click', function () { showAt(state.index - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { showAt(state.index + 1); });

    if (zoomInBtn) zoomInBtn.addEventListener('click', function () {
        state.scale = Math.min(state.scale + 0.2, 5);
        applyTransform();
    });
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', function () {
        state.scale = Math.max(state.scale - 0.2, 0.5);
        applyTransform();
    });
    if (rotateBtn) rotateBtn.addEventListener('click', function () {
        state.rotation = (state.rotation + 90) % 360;
        applyTransform();
    });
    if (resetBtn) resetBtn.addEventListener('click', resetTransform);

    // 双击切换 默认比例 / 2x
    img.addEventListener('dblclick', function () {
        state.scale = state.scale > 1 ? DEFAULT_SCALE : 2;
        state.tx = 0;
        state.ty = 0;
        applyTransform();
    });

    // 滚轮缩放(直接滚轮,不按 ctrl)
    modal.addEventListener('wheel', function (e) {
        e.preventDefault();
        var delta = e.deltaY > 0 ? -0.1 : 0.1;
        state.scale = Math.max(0.5, Math.min(5, state.scale + delta));
        applyTransform();
    });

    // 鼠标拖拽(仅放大时生效)
    img.addEventListener('mousedown', function (e) {
        if (state.scale === 1) return;
        e.preventDefault();
        state.isDragging = true;
        state.dragStartX = e.clientX - state.tx;
        state.dragStartY = e.clientY - state.ty;
        img.style.cursor = 'grabbing';
    });
    document.addEventListener('mousemove', function (e) {
        if (!state.isDragging) return;
        state.tx = e.clientX - state.dragStartX;
        state.ty = e.clientY - state.dragStartY;
        applyTransform();
    });
    document.addEventListener('mouseup', function () {
        if (!state.isDragging) return;
        state.isDragging = false;
        img.style.cursor = state.scale > 1 ? 'grab' : 'zoom-in';
    });

    // 触摸双指缩放
    function pinchDistance(touches) {
        var dx = touches[0].clientX - touches[1].clientX;
        var dy = touches[0].clientY - touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }
    modal.addEventListener('touchstart', function (e) {
        if (e.touches.length === 2) {
            state.pinchStartDist = pinchDistance(e.touches);
            state.pinchStartScale = state.scale;
        }
    });
    modal.addEventListener('touchmove', function (e) {
        if (e.touches.length === 2 && state.pinchStartDist > 0) {
            e.preventDefault();
            var dist = pinchDistance(e.touches);
            var ratio = dist / state.pinchStartDist;
            state.scale = Math.max(0.5, Math.min(5, state.pinchStartScale * ratio));
            applyTransform();
        }
    });

    // 键盘快捷键
    document.addEventListener('keydown', function (e) {
        if (!modal.classList.contains('show')) return;
        switch (e.key) {
            case 'ArrowLeft': showAt(state.index - 1); break;
            case 'ArrowRight': showAt(state.index + 1); break;
            case '+': case '=': state.scale = Math.min(state.scale + 0.2, 5); applyTransform(); break;
            case '-': state.scale = Math.max(state.scale - 0.2, 0.5); applyTransform(); break;
            case '0': resetTransform(); break;
            case 'r': case 'R': state.rotation = (state.rotation + 90) % 360; applyTransform(); break;
        }
    });

    // Modal 关闭后重置
    modal.addEventListener('hidden.bs.modal', function () {
        resetTransform();
        img.src = '';
    });

    // ===== 初始化 =====
    function init() {
        bindAll(document);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // htmx 局部刷新后重新绑定(htmx 4 事件名带冒号)
    document.addEventListener('htmx:after:swap', function (evt) {
        bindAll(evt.target);
    });

    // 暴露 API
    window.XN = window.XN || {};
    XN.lightbox = {
        bind: bindAll,
        show: function (src) {
            show([{ src: src, el: null }], 0);
        }
    };
})();
