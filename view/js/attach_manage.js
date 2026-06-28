/**
 * 附件管理页面 JS
 * 使用 xiuno-modern.js API，不依赖 jQuery
 */
(function() {
    'use strict';

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    // 删除单个附件
    var deleteButtons = XN.$$('.btn-delete-attach');
    // 操作模式：'single' = 单删, 'batch' = 批量清理孤儿
    var deleteMode = 'single';
    var currentDeleteAid = 0;
    var currentDeleteOrphan = 0;

    deleteButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var aid = this.getAttribute('data-aid');
            var isOrphan = this.getAttribute('data-orphan') === '1';
            currentDeleteAid = aid;
            currentDeleteOrphan = isOrphan;
            deleteMode = 'single';

            var modal = document.getElementById('deleteConfirmModal');
            var title = document.getElementById('deleteModalTitle');
            var body = document.getElementById('deleteModalBody');

            if (isOrphan) {
                title.textContent = lang.admin_attach_delete;
                body.textContent = lang.admin_attach_delete_confirm;
            } else {
                title.textContent = lang.admin_attach_force_delete;
                body.textContent = lang.admin_attach_force_delete_confirm;
            }

            var bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        });
    });

    // 确认删除（统一处理单删和批量清理）
    var confirmBtn = document.getElementById('btnConfirmDelete');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (deleteMode === 'batch') {
                // 批量清理孤儿附件
                var data = {};
                data[csrfToken ? 'csrf_token' : 'bbs_admin_token'] = csrfToken || '';

                XN.post(attach_batch_delete_url, data, function(code, message) {
                    if (code === 0) {
                        var m = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
                        if (m) m.hide();
                        showToast(message, 'success');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(message || 'Error', 'danger');
                    }
                });
                return;
            }

            // 单个删除
            if (!currentDeleteAid) return;

            var data = {
                aid: currentDeleteAid,
                force: currentDeleteOrphan ? 0 : 1
            };
            data[csrfToken ? 'csrf_token' : 'bbs_admin_token'] = csrfToken || '';

            XN.post(attach_delete_url, data, function(code, message) {
                if (code === 0) {
                    var modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
                    if (modal) modal.hide();
                    // 移除行或刷新
                    var row = document.querySelector('tr[data-aid="' + currentDeleteAid + '"]');
                    if (row) {
                        row.style.opacity = '0.3';
                        setTimeout(function() { row.remove(); }, 300);
                    }
                    showToast(message, 'success');
                    // 延迟刷新页面以更新统计
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showToast(message || 'Error', 'danger');
                }
            });
        });
    }

    // 批量清理孤儿附件
    var cleanBtn = document.getElementById('btnCleanOrphan');
    if (cleanBtn) {
        cleanBtn.addEventListener('click', function() {
            var orphanCount = this.getAttribute('data-orphan-count');
            if (parseInt(orphanCount) === 0) {
                showToast(lang.admin_attach_no_orphan, 'info');
                return;
            }

            var modal = document.getElementById('deleteConfirmModal');
            var title = document.getElementById('deleteModalTitle');
            var body = document.getElementById('deleteModalBody');

            title.textContent = lang.admin_attach_clean_orphan;
            body.textContent = lang.admin_attach_clean_orphan_confirm.replace('{n}', orphanCount);

            // 设置为批量模式
            deleteMode = 'batch';
            currentDeleteAid = 0;

            var bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        });
    }

    // Toast 提示
    function showToast(message, type) {
        type = type || 'success';
        var container = document.getElementById('toast-container');
        if (!container) return;
        var bgClass = type === 'success' ? 'bg-success' : type === 'danger' ? 'bg-danger' : type === 'info' ? 'bg-info' : 'bg-secondary';
        var el = document.createElement('div');
        el.className = 'toast align-items-center text-white ' + bgClass + ' border-0';
        el.setAttribute('role', 'alert');
        el.innerHTML = '<div class="d-flex"><div class="toast-body">' + message + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        container.appendChild(el);
        var bsToast = new bootstrap.Toast(el, { delay: 3000 });
        bsToast.show();
        el.addEventListener('hidden.bs.toast', function() { el.remove(); });
    }
})();
