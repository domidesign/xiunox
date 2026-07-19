<?php

class EditorService {

    private array $conf;

    public function __construct(array $conf) {
        $this->conf = $conf;
    }

    public function getEditorAssets(): array {
        $viewUrl = isset($GLOBALS['conf']['view_url']) ? $GLOBALS['conf']['view_url'] : '/view/';
        // upload-service.js / editor-paste-markdown.js 用 filemtime 版本号，避免修改后浏览器缓存旧版本
        $_uploadServicePath = APP_PATH . 'view/js/upload-service.js';
        $_uploadServiceV = is_file($_uploadServicePath) ? '?v=' . substr(md5((string)filemtime($_uploadServicePath)), 0, 8) : ($GLOBALS['conf']['static_version'] ?? '?1.0');
        $_pasteMdPath = APP_PATH . 'view/js/editor-paste-markdown.js';
        $_pasteMdV = is_file($_pasteMdPath) ? '?v=' . substr(md5((string)filemtime($_pasteMdPath)), 0, 8) : ($GLOBALS['conf']['static_version'] ?? '?1.0');
        $assets = [
            'css' => [
                $viewUrl . 'js/aieditor/style.css',
            ],
            'js' => [
                $viewUrl . 'js/upload-service.js' . $_uploadServiceV,
                $viewUrl . 'js/editor-paste-markdown.js' . $_pasteMdV,
                $viewUrl . 'js/aieditor/index.umd.js',
            ],
        ];
        if (function_exists('hook')) {
            $assets = hook('editor_assets', $assets);
        }
        return $assets;
    }

    public function renderEditorHtml(string $textareaId = 'message'): string {
        $uploadUrl = url('attach-create');
        $csrfToken = '';
        if (class_exists('CsrfService')) {
            $csrfToken = CsrfService::getToken();
        }

        $aiConfig = $this->buildAiConfig();
        // AI 是否可用取决于用户配置是否完整
        $aiEnabled = $this->isUserAiConfigComplete();
        $aiEnabledJs = $aiEnabled ? 'true' : 'false';
        $aiNotConfiguredTip = addslashes(lang('ai_not_configured_tip'));
        $myAiUrl = url('my-ai');
        $mentionTip = lang('mention_label');
        $mentionFollowUrl = url('my-follow_users');
        $mentionSearchUrl = url('user-search');
        // 外链媒体插入相关语言包
        $extMediaTip = lang('ext_media_tip');
        $extMediaTitle = lang('ext_media_title');
        $extMediaImage = lang('ext_media_image');
        $extMediaVideo = lang('ext_media_video');
        $extMediaUrlLabel = lang('ext_media_url_label');
        $extMediaWidthLabel = lang('ext_media_width_label');
        $extMediaWidthAuto = lang('ext_media_width_auto');
        $extMediaPreviewLabel = lang('ext_media_preview_label');
        $extMediaPreviewTip = addslashes(lang('ext_media_preview_tip'));
        $extMediaCancel = lang('ext_media_cancel');
        $extMediaInsertBtn = lang('ext_media_insert_btn');
        $extMediaImageInserted = addslashes(lang('ext_media_image_inserted'));
        $extMediaVideoInserted = addslashes(lang('ext_media_video_inserted'));
        $extMediaInsertFail = addslashes(lang('ext_media_insert_fail'));
        $extMediaLoadFail = addslashes(lang('ext_media_load_fail'));
        // 引用话题 / 隐藏内容按钮的语言包（仅主帖页注入）
        global $isfirst;
        $isFirstPost = !empty($isfirst);
        $refThreadTip = lang('ref_thread_title');
        $hiddenContentTip = lang('xnx_hidden_title');
        $refThreadModalMissingTip = addslashes(lang('ref_thread_modal_missing_tip'));
        $hiddenModalMissingTip = addslashes(lang('xnx_hidden_modal_missing_tip'));
        // 主帖页注入引用话题按钮；隐藏内容按钮需 xnx_hidden 插件启用
        // 注意：前台 $plugins 未初始化（plugin_init() 仅在 admin/upgrade 调用），
        // 直接读 conf.json 判断插件启用状态（与 plugin_paths_enabled 同源）
        $_hidden_conf_file = APP_PATH . 'plugin/xnx_hidden/conf.json';
        $hiddenEnabled = false;
        if (is_file($_hidden_conf_file)) {
            $_pconf = xn_json_decode(file_get_contents($_hidden_conf_file));
            $hiddenEnabled = !empty($_pconf['enable']) && !empty($_pconf['installed']);
        }
        $firstPostBtns = [];
        if ($isFirstPost) {
            $firstPostBtns[] = 'refThreadBtn';
            if ($hiddenEnabled) {
                $firstPostBtns[] = 'hiddenContentBtn';
            }
        }
        $firstPostBtnsJson = '[' . implode(',', $firstPostBtns) . ']';
        $lang = $this->conf['lang'] ?? 'zh-cn';
        $aieditorLang = $this->mapLangCode($lang);

        // 编辑器 placeholder 提示文字（后台「显示设置」配置，作为编辑器占位符显示）
        $editorTipText = isset($this->conf['editor_tip']) ? trim($this->conf['editor_tip']) : '';
        $editorTipJs = json_encode($editorTipText);

        // AI 生成中提示文案
        $aiGeneratingText = lang('editor_ai_generating');
        $aiGeneratingToast = lang('editor_ai_generating_wait');

        return <<<HTML
<style>
.aieditor-container {border:1px solid var(--border-color, #ddd);border-radius:4px;min-height:300px;resize:vertical;overflow:hidden;}
.aieditor-container .aieditor {min-height:300px;}
/* 编辑器内容区：移动端不强制固定高度，避免 100vh 在键盘弹起时不变导致内容溢出到键盘下方 */
.aieditor-container .aie-content {min-height:250px;overflow:auto !important;}
/* 桌面端保留较大高度（不使用 !important，允许覆盖） */
@media (min-width: 768px) {
    .aieditor-container .aie-content {min-height:calc(100vh - 400px);}
}
/* 工具栏吸顶 */
.aieditor-container .aie-header {position:sticky;top:0;z-index:10;background:var(--aie-bg-color, #fff);}
.editor-upload-progress {height:3px;background:transparent;position:relative;margin-top:-3px;z-index:10;overflow:hidden;}
.editor-upload-progress .progress-bar {height:100%;width:0;background:var(--bs-primary, #0d6efd);border-radius:0 2px 2px 0;transition:width 0.2s ease;}
.editor-upload-progress.active .progress-bar {background:var(--bs-primary, #0d6efd);}
.editor-upload-progress.complete .progress-bar {background:var(--bs-success, #198754);width:100%;transition:width 0.3s ease;}
.editor-upload-progress.error .progress-bar {background:var(--bs-danger, #dc3545);width:100%;}
.aieditor-container.upload-drop-active {border-color:var(--bs-primary, #0d6efd) !important;box-shadow:0 0 0 3px rgba(13,110,253,0.15);transition:box-shadow 0.2s ease;}
.editor-attachment-list {margin-top:8px;padding:0;list-style:none;}
.editor-attachment-item {display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--border-color, #ddd);border-radius:4px;margin-bottom:4px;font-size:13px;background:var(--bs-body-bg, #fff);}
.editor-attachment-item .att-icon {color:var(--bs-secondary, #6c757d);font-size:16px;}
.editor-attachment-item .att-name {flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.editor-attachment-item .att-name a {color:inherit;text-decoration:none;}
.editor-attachment-item .att-name a:hover {text-decoration:underline;}
.editor-attachment-item .att-size {color:var(--bs-secondary, #6c757d);font-size:12px;white-space:nowrap;}
/* AI 生成中遮罩：全站覆盖，阻止用户操作，提示正在输出 */
.ai-generating-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4);
    backdrop-filter: blur(2px);
    z-index: 9999;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 12px;
}
.ai-gen-spinner {
    width: 40px; height: 40px;
    border: 4px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: ai-gen-spin 0.8s linear infinite;
}
@keyframes ai-gen-spin { to { transform: rotate(360deg); } }
.ai-gen-text {
    color: #fff;
    font-size: 16px; font-weight: 500;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}
</style>
<script>
(function(){
    if (document.getElementById('aieditor-container')) {
        return;
    }

    var wrap = document.getElementById('message-editor-wrap');
    if (!wrap) {
        return;
    }

    // AI 是否已完整配置（由 PHP 端判断）
    var aiConfigured = {$aiEnabledJs};
    var aiNotConfiguredTip = '{$aiNotConfiguredTip}';

    var oldTa = document.getElementById('{$textareaId}');
    if (oldTa) { oldTa.name = '_message_old'; oldTa.style.display = 'none'; }
    var container = document.createElement('div');
    container.id = 'aieditor-container';
    container.className = 'aieditor-container';
    wrap.appendChild(container);

    // 上传进度条
    var progressBar = document.createElement('div');
    progressBar.className = 'editor-upload-progress';
    progressBar.innerHTML = '<div class="progress-bar"></div>';
    wrap.appendChild(progressBar);

    // 附件列表容器
    var attachmentList = document.createElement('ul');
    attachmentList.className = 'editor-attachment-list';
    attachmentList.id = 'editor-attachment-list';
    attachmentList.style.display = 'none';
    wrap.appendChild(attachmentList);

    var hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.id = '{$textareaId}';
    hiddenInput.name = 'message';
    if (oldTa) { hiddenInput.value = oldTa.value; }
    wrap.appendChild(hiddenInput);
    var aiEditorInstance = null;

    // 创建 UploadService 实例，用于拖拽/粘贴上传
    var uploadSvc = null;
    if (typeof UploadService !== 'undefined') {
        uploadSvc = new UploadService({
            uploadUrl: '{$uploadUrl}',
            csrfToken: '{$csrfToken}',
            onProgress: function(file, percent) {
                showProgress(percent);
            },
            onComplete: function(file, response) {
                var msg = response.message || {};
                if (msg.isimage || UploadService.isImageFile(file)) {
                    insertImageToEditor(msg.url || msg.thumb_url || '');
                } else {
                    addAttachmentItem(msg);
                }
                showProgressComplete();
                if (typeof XN !== 'undefined' && XN.toast) {
                    XN.toast('上传成功', 'success');
                }
            },
            onError: function(file, error) {
                showProgressError();
                var errMsg = error || '上传失败';
                if (typeof errMsg === 'string' && errMsg.match(/^lang\[(.+)\]$/)) {
                    errMsg = RegExp.$1.replace(/_/g, ' ');
                }
                if (typeof XN !== 'undefined' && XN.toast) {
                    XN.toast(errMsg, 'danger');
                }
                console.error('[Editor Upload] 上传失败:', error);
            },
            onPreview: function(file, dataUrl) {
            },
            onDragOver: function() {
                container.classList.add('upload-drop-active');
            },
            onDragLeave: function() {
                container.classList.remove('upload-drop-active');
            }
        });
    }

    var aiConfigRaw = {$aiConfig};

    function syncEditorContent() {
        if (aiEditorInstance && hiddenInput) {
            var html = aiEditorInstance.getHtml();
            hiddenInput.value = html;
        }
    }

    // 显示上传进度
    function showProgress(percent) {
        progressBar.className = 'editor-upload-progress active';
        var bar = progressBar.querySelector('.progress-bar');
        bar.style.width = Math.min(percent, 100) + '%';
    }

    // 上传完成，进度条变绿后自动隐藏
    function showProgressComplete() {
        progressBar.className = 'editor-upload-progress complete';
        setTimeout(function() {
            progressBar.className = 'editor-upload-progress';
            progressBar.querySelector('.progress-bar').style.width = '0';
        }, 1500);
    }

    // 上传失败，进度条变红后自动隐藏
    function showProgressError() {
        progressBar.className = 'editor-upload-progress error';
        setTimeout(function() {
            progressBar.className = 'editor-upload-progress';
            progressBar.querySelector('.progress-bar').style.width = '0';
        }, 2000);
    }

    // 向编辑器插入图片
    function insertImageToEditor(url) {
        if (!url || !aiEditorInstance) return;
        try {
            // AiEditor API: insert() 插入内容，setContent() 设置全部内容
            if (typeof aiEditorInstance.insert === 'function') {
                aiEditorInstance.insert('<img src="' + url + '" alt="">');
            } else if (typeof aiEditorInstance.setContent === 'function') {
                var currentHtml = aiEditorInstance.getHtml();
                aiEditorInstance.setContent(currentHtml + '<img src="' + url + '" alt="">');
            }
            syncEditorContent();
        } catch(e) {
            console.error('[Editor Upload] 插入图片失败:', e);
        }
    }

    // 添加附件项到附件列表
    function addAttachmentItem(msg) {
        if (!msg) return;
        attachmentList.style.display = '';
        var li = document.createElement('li');
        li.className = 'editor-attachment-item';
        li.setAttribute('aid', msg.aid || '');
        var url = msg.url || '';
        var name = msg.orgfilename || '附件';
        var size = msg.filesize ? UploadService.formatFileSize(msg.filesize) : '';
        var nameHtml = name;
        if (url) {
            nameHtml = '<a href="' + url + '" target="_blank">' + name + '</a>';
        }
        li.innerHTML = '<span class="att-icon"><i class="ti ti-paperclip"></i></span>' +
            '<span class="att-name" title="' + name + '">' + nameHtml + '</span>' +
            (size ? '<span class="att-size">' + size + '</span>' : '') +
            '<button type="button" class="btn btn-sm btn-outline-danger rounded-pill py-0 px-2" title="删除" onclick="deleteAttach(this, \'' + (msg.aid || 0) + '\')"><i class="ti ti-trash" style="font-size:12px;"></i></button>';
        attachmentList.appendChild(li);
    }

    // 通用上传函数，支持进度条和 toast 提示
    function uploadFile(file, uploadUrl, onSuccess) {
        showProgress(0);
        return new Promise(function(resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', uploadUrl, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('X-CSRF-Token', '{$csrfToken}');
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    showProgress(Math.round(e.loaded * 100 / e.total));
                }
            };
            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        var json = JSON.parse(xhr.responseText);
                        if (parseInt(json.code) === 0) {
                            showProgressComplete();
                            var msg = json.message || {};
                            if (typeof XN !== 'undefined' && XN.toast) {
                                XN.toast('上传成功', 'success');
                            }
                            resolve(onSuccess(msg));
                        } else {
                            showProgressError();
                            var errMsg = json.message || '上传失败';
                            // 处理 lang[key] 格式的未翻译消息
                            if (typeof errMsg === 'string' && errMsg.match(/^lang\[(.+)\]$/)) {
                                errMsg = RegExp.$1.replace(/_/g, ' ');
                            }
                            if (typeof XN !== 'undefined' && XN.toast) {
                                XN.toast(errMsg, 'danger');
                            }
                            reject(errMsg);
                        }
                    } catch(e) {
                        showProgressError();
                        if (typeof XN !== 'undefined' && XN.toast) {
                            XN.toast('响应解析失败', 'danger');
                        }
                        reject('响应解析失败');
                    }
                } else {
                    showProgressError();
                    if (typeof XN !== 'undefined' && XN.toast) {
                        XN.toast('上传失败', 'danger');
                    }
                    reject('上传失败');
                }
            };
            xhr.onerror = function() {
                showProgressError();
                if (typeof XN !== 'undefined' && XN.toast) {
                    XN.toast('网络错误', 'danger');
                }
                reject('网络错误');
            };
            var fd = new FormData();
            fd.append('file', file);
            fd.append('csrf_token', '{$csrfToken}');
            xhr.send(fd);
        });
    }

    // ===== 外链媒体插入（图片/视频）=====
    var extMediaType = 'image';
    var extMediaModalInstance = null;

    function escapeHtmlAttr(s) {
        var d = document.createElement('textarea');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function ensureExtMediaModal() {
        if (document.getElementById('extMediaModal')) {
            if (!extMediaModalInstance && typeof bootstrap !== 'undefined') {
                extMediaModalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('extMediaModal'));
            }
            return;
        }
        var modalHtml = '<div class="modal fade" id="extMediaModal" tabindex="-1" aria-hidden="true">' +
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content">' +
            '<div class="modal-header">' +
            '<h5 class="modal-title"><i class="ti ti-photo me-2"></i>{$extMediaTitle}</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body">' +
            '<ul class="nav nav-tabs mb-3">' +
            '<li class="nav-item"><button type="button" class="nav-link ext-media-tab active" data-type="image"><i class="ti ti-photo me-1"></i>{$extMediaImage}</button></li>' +
            '<li class="nav-item"><button type="button" class="nav-link ext-media-tab" data-type="video"><i class="ti ti-video me-1"></i>{$extMediaVideo}</button></li>' +
            '</ul>' +
            '<div class="mb-3">' +
            '<label class="form-label">{$extMediaUrlLabel}</label>' +
            '<input type="url" class="form-control" id="extMediaUrl" placeholder="https://...">' +
            '</div>' +
            '<div class="mb-3">' +
            '<label class="form-label">{$extMediaWidthLabel}</label>' +
            '<select class="form-select" id="extMediaWidth">' +
            '<option value="">{$extMediaWidthAuto}</option>' +
            '<option value="400">400px</option>' +
            '<option value="500">500px</option>' +
            '<option value="600">600px</option>' +
            '<option value="800">800px</option>' +
            '</select>' +
            '</div>' +
            '<div>' +
            '<label class="form-label">{$extMediaPreviewLabel}</label>' +
            '<div class="border rounded p-3 text-center d-flex align-items-center justify-content-center" id="extMediaPreview" style="min-height:120px"><span class="text-muted small">{$extMediaPreviewTip}</span></div>' +
            '</div>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{$extMediaCancel}</button>' +
            '<button type="button" class="btn btn-primary" id="extMediaInsertBtn" disabled><i class="ti ti-plus me-1"></i>{$extMediaInsertBtn}</button>' +
            '</div>' +
            '</div></div></div>';
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        var modalEl = document.getElementById('extMediaModal');
        modalEl.querySelectorAll('.ext-media-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                extMediaType = this.getAttribute('data-type');
                modalEl.querySelectorAll('.ext-media-tab').forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
                updateExtMediaPreview();
                checkExtMediaUrl();
            });
        });
        document.getElementById('extMediaUrl').addEventListener('input', function() {
            updateExtMediaPreview();
            checkExtMediaUrl();
        });
        document.getElementById('extMediaUrl').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var btn = document.getElementById('extMediaInsertBtn');
                if (!btn.disabled) doExtMediaInsert();
            }
        });
        document.getElementById('extMediaInsertBtn').addEventListener('click', doExtMediaInsert);

        if (typeof bootstrap !== 'undefined') {
            extMediaModalInstance = new bootstrap.Modal(modalEl);
        }
    }

    function openExtMediaModal() {
        ensureExtMediaModal();
        extMediaType = 'image';
        var urlInput = document.getElementById('extMediaUrl');
        urlInput.value = '';
        document.getElementById('extMediaWidth').value = '';
        var tabs = document.querySelectorAll('.ext-media-tab');
        tabs.forEach(function(b) { b.classList.remove('active'); });
        if (tabs[0]) tabs[0].classList.add('active');
        updateExtMediaPreview();
        checkExtMediaUrl();
        if (extMediaModalInstance) {
            extMediaModalInstance.show();
            setTimeout(function() { urlInput.focus(); }, 350);
        }
    }

    function checkExtMediaUrl() {
        var url = document.getElementById('extMediaUrl').value.trim();
        var ok = /^https?:\/\/.+/i.test(url);
        document.getElementById('extMediaInsertBtn').disabled = !ok;
    }

    function updateExtMediaPreview() {
        var url = document.getElementById('extMediaUrl').value.trim();
        var preview = document.getElementById('extMediaPreview');
        if (!url) {
            preview.innerHTML = '<span class="text-muted small">{$extMediaPreviewTip}</span>';
            return;
        }
        var safe = escapeHtmlAttr(url);
        if (extMediaType === 'image') {
            var img = new Image();
            img.style.maxWidth = '100%';
            img.style.maxHeight = '160px';
            img.style.borderRadius = '6px';
            img.onerror = function() {
                preview.innerHTML = '<span class="text-danger small"><i class="ti ti-alert-circle me-1"></i>{$extMediaLoadFail}</span>';
            };
            img.src = url;
            preview.innerHTML = '';
            preview.appendChild(img);
        } else {
            preview.innerHTML = '<video controls src="' + safe + '" style="max-width:100%;max-height:160px;border-radius:6px"></video>';
        }
    }

    function doExtMediaInsert() {
        var url = document.getElementById('extMediaUrl').value.trim();
        if (!url || !/^https?:\/\/.+/i.test(url)) return;
        var width = document.getElementById('extMediaWidth').value;
        var styleAttr = width ? 'max-width:100%;width:' + width + 'px' : 'max-width:100%';
        var html;
        if (extMediaType === 'image') {
            html = '<img src="' + escapeHtmlAttr(url) + '" alt="" style="' + styleAttr + '">';
        } else {
            html = '<video src="' + escapeHtmlAttr(url) + '" controls preload="metadata" style="' + styleAttr + '"></video>';
        }
        if (aiEditorInstance && typeof aiEditorInstance.insert === 'function') {
            try {
                aiEditorInstance.insert(html);
                syncEditorContent();
                if (typeof XN !== 'undefined' && XN.toast) {
                    XN.toast(extMediaType === 'image' ? '{$extMediaImageInserted}' : '{$extMediaVideoInserted}', 'success');
                }
            } catch(e) {
                console.error('[Editor] 插入外链媒体失败:', e);
                if (typeof XN !== 'undefined' && XN.toast) {
                    XN.toast('{$extMediaInsertFail}', 'danger');
                }
                return;
            }
        } else {
            if (typeof XN !== 'undefined' && XN.toast) {
                XN.toast('{$extMediaInsertFail}', 'danger');
            }
            return;
        }
        if (extMediaModalInstance) {
            extMediaModalInstance.hide();
        }
    }

    function initAiEditor() {
        if (aiEditorInstance) return;

        var AE = null;
        if (typeof AiEditor === 'function') { AE = AiEditor; }
        else if (typeof AiEditor === 'object' && AiEditor !== null && AiEditor.AiEditor) { AE = AiEditor.AiEditor; }
        if (!AE) {
            if (initAiEditor._retries === undefined) initAiEditor._retries = 0;
            if (initAiEditor._retries < 50) {
                initAiEditor._retries++;
                setTimeout(initAiEditor, 100);
                return;
            }
            console.error('AiEditor constructor not found after retries');
            return;
        }

        // 构建自定义 bubblePanelMenus，使用我们自定义的 prompt
        var customMenus = [
            {
                icon: 'continue',
                name: 'continue',
                prompt: aiConfigRaw.promptContinue || '',
                model: aiConfigRaw.bubblePanelModel || 'openai'
            },
            {
                icon: 'improve',
                name: 'improve',
                prompt: aiConfigRaw.promptImprove || '',
                model: aiConfigRaw.bubblePanelModel || 'openai'
            }
        ];

        // 如果配置中有自定义 bubblePanelMenus 或者我们有自定义的 prompt，就使用自定义菜单
        if (aiConfigRaw.promptContinue || aiConfigRaw.promptImprove) {
            aiConfigRaw.bubblePanelMenus = customMenus;
        }

        // @提及按钮配置（Remix Icon at 图标，fill 模式）
        var mentionBtn = {
            icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M20 12C20 7.58172 16.4183 4 12 4C7.58172 4 4 7.58172 4 12C4 16.4183 7.58172 20 12 20C13.6418 20 15.1681 19.5054 16.4381 18.6571L17.5476 20.3214C15.9602 21.3818 14.0523 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12V13.5C22 15.433 20.433 17 18.5 17C17.2958 17 16.2336 16.3918 15.6038 15.4659C14.6942 16.4115 13.4158 17 12 17C9.23858 17 7 14.7614 7 12C7 9.23858 9.23858 7 12 7C13.1258 7 14.1647 7.37209 15.0005 8H17V13.5C17 14.3284 17.6716 15 18.5 15C19.3284 15 20 14.3284 20 13.5V12ZM12 9C10.3431 9 9 10.3431 9 12C9 13.6569 10.3431 15 12 15C13.6569 15 15 13.6569 15 12C15 10.3431 13.6569 9 12 9Z"></path></svg>',
            onClick: function(event, editor) {
                editor.focus();
                editor.insert('@');
            },
            tip: '{$mentionTip}'
        };

        // 外链媒体按钮：插入外链图片/视频，点击弹出 Bootstrap Modal
        // SVG 来自 AIEditor 内置 image 按钮（确保 CSS 兼容），加 + 号表示插入
        var extMediaBtn = {
            icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21 15v3h3v2h-3v3h-2v-3h-3v-2h3v-3h2zm-8 3H4l5-5 3 3 2-2 3 3v2h-4v-2.172l-1 1-5-5-3 3.172V18h8v2zm-9 3v-2h6v2H4zM2.992 21C2.444 21 2 20.555 2 20.006V3.994C2 3.445 2.455 3 2.992 3h18.016C21.556 3 22 3.445 22 3.994V14h-2V5H4v14h6v2H2.992zM8 11c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z"></path></svg>',
            onClick: function(event, editor) {
                if (editor) editor.focus();
                openExtMediaModal();
            },
            tip: '{$extMediaTip}'
        };

        // 引用话题按钮：点击弹出搜索 Modal（Remix Icon file-list 图标，fill 模式）
        // 仅主帖页注入；onClick 调用 openExtRefModal()，该函数由 post.htm 的引用话题 Modal JS 提供
        var refThreadBtn = {
            icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M20 22H4C3.44772 22 3 21.5523 3 21V3C3 2.44772 3.44772 2 4 2H20C20.5523 2 21 2.44772 21 3V21C21 21.5523 20.5523 22 20 22ZM19 20V4H5V20H19ZM7 6H11V10H7V6ZM7 12H17V14H7V12ZM7 16H17V18H7V16ZM13 7H17V9H13V7Z"></path></svg>',
            onClick: function(event, editor) {
                if (editor) editor.focus();
                if (typeof openExtRefModal === 'function') {
                    openExtRefModal();
                } else if (typeof XN !== 'undefined' && XN.toast) {
                    XN.toast('{$refThreadModalMissingTip}', 'warning');
                }
            },
            tip: '{$refThreadTip}'
        };

        // 隐藏内容按钮：点击触发隐藏内容 Modal（Remix Icon eye-off 图标，fill 模式）
        // 仅主帖页注入；onClick 调用 openExtHiddenModal()，该函数由 xnx_hidden 插件提供
        var hiddenContentBtn = {
            icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M17.8827 19.2968C16.1814 20.3755 14.1638 21.0002 12.0003 21.0002C6.60812 21.0002 2.12215 17.1204 1.18164 12.0002C1.61832 9.62282 2.81932 7.5129 4.52047 5.93457L1.39366 2.80777L2.80788 1.39355L22.6069 21.1925L21.1927 22.6068L17.8827 19.2968ZM5.9356 7.3497C4.60673 8.56015 3.6378 10.1672 3.22278 12.0002C4.14022 16.0521 7.7646 19.0002 12.0003 19.0002C13.5997 19.0002 15.112 18.5798 16.4243 17.8384L14.396 15.8101C13.7023 16.2472 12.8808 16.5002 12.0003 16.5002C9.51498 16.5002 7.50026 14.4854 7.50026 12.0002C7.50026 11.1196 7.75317 10.2981 8.19031 9.60442L5.9356 7.3497ZM12.9139 14.328L9.67246 11.0866C9.5613 11.3696 9.50026 11.6777 9.50026 12.0002C9.50026 13.3809 10.6196 14.5002 12.0003 14.5002C12.3227 14.5002 12.6309 14.4391 12.9139 14.328ZM20.8068 16.5925L19.376 15.1617C20.0319 14.2268 20.5154 13.1586 20.7777 12.0002C19.8603 7.94818 16.2359 5.00016 12.0003 5.00016C11.1544 5.00016 10.3329 5.11773 9.55249 5.33818L7.97446 3.76015C9.22127 3.26959 10.5793 3.00016 12.0003 3.00016C17.3924 3.00016 21.8784 6.87992 22.8189 12.0002C22.5067 13.6998 21.8038 15.2628 20.8068 16.5925ZM11.7229 7.50857C11.8146 7.50299 11.9071 7.50016 12.0003 7.50016C14.4855 7.50016 16.5003 9.51488 16.5003 12.0002C16.5003 12.0933 16.4974 12.1858 16.4919 12.2775L11.7229 7.50857Z"></path></svg>',
            onClick: function(event, editor) {
                if (editor) editor.focus();
                if (typeof openExtHiddenModal === 'function') {
                    openExtHiddenModal();
                } else if (typeof XN !== 'undefined' && XN.toast) {
                    XN.toast('{$hiddenModalMissingTip}', 'warning');
                }
            },
            tip: '{$hiddenContentTip}'
        };

        // 构建工具栏：按 AIEditor 官方默认配置补齐所有按钮，分隔符为 '|'
        // 官方默认配置参考：https://aieditor.dev/docs/zh/config/toolbar.html
        // 自定义按钮（@提及、外链媒体、引用话题、隐藏内容）放在 image/video/attachment 组之后
        // 引用话题、隐藏内容按钮仅在主帖页注入（isfirst=1）
        // 注意：$firstPostBtnsJson 由 PHP implode 生成 [refThreadBtn,hiddenContentBtn]（无引号）
        // JS 端直接作为变量引用，得到已定义的按钮对象数组
        var firstPostBtns = {$firstPostBtnsJson};
        var mobileToolbar = [
            'undo', 'redo',
            '|', 'heading', 'bold', 'italic', 'underline', 'strike', 'link', 'code',
            '|', 'bullet-list', 'ordered-list', 'quote', 'code-block',
            '|', 'image', 'video', 'attachment', mentionBtn, extMediaBtn,
            '|', 'font-color', 'highlight', 'align',
            '|', 'fullscreen', 'ai'
        ].concat(firstPostBtns);
        var desktopToolbar = [
            'undo', 'redo', 'brush', 'eraser',
            '|', 'heading', 'font-family', 'font-size',
            '|', 'bold', 'italic', 'underline', 'strike', 'link', 'code', 'subscript', 'superscript', 'hr', 'todo', 'emoji',
            '|', 'highlight', 'font-color',
            '|', 'align', 'line-height',
            '|', 'bullet-list', 'ordered-list', 'indent-decrease', 'indent-increase', 'break',
            '|', 'image', 'video', 'attachment', mentionBtn, extMediaBtn, 'quote', 'container', 'code-block', 'table'
        ].concat(firstPostBtns).concat([
            '|', 'source-code', 'printer', 'fullscreen', 'ai'
        ]);

        var toolbar = window.innerWidth < 768 ? mobileToolbar : desktopToolbar;

        // 读取当前主题（前端动态决定，存在 localStorage 中）
        var currentTheme = 'light';
        try {
            currentTheme = localStorage.getItem('theme') ||
                (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        } catch(e) {
            currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
        }

        var opts = {
            element: '#aieditor-container',
            placeholder: {$editorTipJs},
            content: oldTa ? oldTa.value : '',
            toolbarKeys: toolbar,
            theme: currentTheme,
            lang: '{$aieditorLang}',
            image: {
                uploadUrl: '{$uploadUrl}',
                uploadHeaders: {'X-CSRF-Token': '{$csrfToken}'},
                uploader: function(file, uploadUrl, headers) {
                    return uploadFile(file, uploadUrl, function(msg) {
                        return {errorCode: 0, data: {src: msg.url || '', alt: msg.orgfilename || ''}};
                    });
                }
            },
            video: {
                uploadUrl: '{$uploadUrl}',
                uploadHeaders: {'X-CSRF-Token': '{$csrfToken}'},
                uploader: function(file, uploadUrl, headers) {
                    return uploadFile(file, uploadUrl, function(msg) {
                        return {errorCode: 0, data: {src: msg.url || ''}};
                    });
                }
            },
            attachment: {
                uploadUrl: '{$uploadUrl}',
                uploadHeaders: {'X-CSRF-Token': '{$csrfToken}'},
                uploader: function(file, uploadUrl, headers) {
                    // 附件按钮不允许上传图片和视频，提示用户使用对应按钮
                    if (UploadService && UploadService.isImageFile(file)) {
                        if (typeof XN !== 'undefined' && XN.toast) {
                            XN.toast('图片请使用图片按钮上传', 'warning');
                        }
                        return Promise.reject('图片请使用图片按钮上传');
                    }
                    if (UploadService && UploadService.isVideoFile && UploadService.isVideoFile(file)) {
                        if (typeof XN !== 'undefined' && XN.toast) {
                            XN.toast('视频请使用视频按钮上传', 'warning');
                        }
                        return Promise.reject('视频请使用视频按钮上传');
                    }
                    // 兜底：通过文件扩展名判断
                    var fileName = (file.name || '').toLowerCase();
                    var imgExts = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp', '.svg'];
                    var videoExts = ['.mp4', '.webm', '.avi', '.mov', '.wmv', '.flv', '.mkv', '.m4v'];
                    var ext = fileName.substr(fileName.lastIndexOf('.'));
                    if (imgExts.indexOf(ext) !== -1) {
                        if (typeof XN !== 'undefined' && XN.toast) {
                            XN.toast('图片请使用图片按钮上传', 'warning');
                        }
                        return Promise.reject('图片请使用图片按钮上传');
                    }
                    if (videoExts.indexOf(ext) !== -1) {
                        if (typeof XN !== 'undefined' && XN.toast) {
                            XN.toast('视频请使用视频按钮上传', 'warning');
                        }
                        return Promise.reject('视频请使用视频按钮上传');
                    }
                    // 方案B：附件不自动插入编辑器 a 链接，只添加到附件列表
                    return uploadFile(file, uploadUrl, function(msg) {
                        addAttachmentItem(msg);
                        // 返回空数据，阻止 AIEditor 自动插入 a 链接
                        return {errorCode: 0, data: {href: '', fileName: ''}};
                    });
                }
            },
            ai: aiConfigRaw,
            onChange: function(ed) {
                syncEditorContent();
            },
            onMentionQuery: function(query) {
                return new Promise(function(resolve) {
                    // 缓存关注用户列表
                    if (!window._mentionFollowUsers) {
                        window._mentionFollowUsers = [];
                        fetch('{$mentionFollowUrl}', {
                            headers: {'X-Requested-With': 'XMLHttpRequest'}
                        }).then(function(r) { return r.json(); }).then(function(res) {
                            if (res.code == 0 && res.data) {
                                window._mentionFollowUsers = res.data.map(function(u) {
                                    return {id: String(u.uid), label: u.username};
                                });
                            }
                        }).catch(function(){});
                    }

                    if (!query) {
                        resolve(window._mentionFollowUsers.slice(0, 10));
                        return;
                    }

                    fetch('{$mentionSearchUrl}?keyword=' + encodeURIComponent(query), {
                        headers: {'X-Requested-With': 'XMLHttpRequest'}
                    }).then(function(r) { return r.json(); }).then(function(res) {
                        if (res.code == 0 && res.data) {
                            var searchResults = res.data.map(function(u) {
                                return {id: String(u.uid), label: u.display_name || u.username};
                            });
                            // 合并关注用户（去重）
                            var allResults = searchResults.slice();
                            window._mentionFollowUsers.forEach(function(fu) {
                                if (!allResults.some(function(r) { return r.id === fu.id; })) {
                                    if (fu.label.toLowerCase().indexOf(query.toLowerCase()) !== -1) {
                                        allResults.push(fu);
                                    }
                                }
                            });
                            resolve(allResults.slice(0, 10));
                        } else {
                            // 搜索失败时，从关注列表中过滤
                            var filtered = window._mentionFollowUsers.filter(function(u) {
                                return u.label.toLowerCase().indexOf(query.toLowerCase()) !== -1;
                            });
                            resolve(filtered.slice(0, 10));
                        }
                    }).catch(function() {
                        var filtered = window._mentionFollowUsers.filter(function(u) {
                            return u.label.toLowerCase().indexOf(query.toLowerCase()) !== -1;
                        });
                        resolve(filtered.slice(0, 10));
                    });
                });
            }
        };

        try {
            aiEditorInstance = new AE(opts);
            // 暴露到全局，供侧边栏引用帖子等功能调用
            window.aiEditorInstance = aiEditorInstance;

            // ===== AI 生成防抖锁 =====
            // AIEditor 内置的默认 listener (class Da) 的 onStart/onStop 是空实现
            // 用户连续点击 AI 菜单会触发多次请求，无防抖
            // 方案：hook window.fetch 拦截 /ai-chat 请求，加全局锁 + 遮罩 + 编辑器只读
            var aiGenerating = false;
            var aiOverlay = null;

            function showAiGenerating() {
                if (aiOverlay) return;
                aiOverlay = document.createElement('div');
                aiOverlay.className = 'ai-generating-overlay';
                aiOverlay.innerHTML = '<div class="ai-gen-spinner"></div><div class="ai-gen-text">{$aiGeneratingText}</div>';
                document.body.appendChild(aiOverlay);
                // 编辑器设为只读，防止生成期间编辑导致内容错位
                try { aiEditorInstance.setEditable(false); } catch(e) {}
            }

            function hideAiGenerating() {
                if (aiOverlay) { aiOverlay.remove(); aiOverlay = null; }
                try { aiEditorInstance.setEditable(true); } catch(e) {}
            }

            function aiGenDone() {
                if (aiGenerating) {
                    aiGenerating = false;
                    hideAiGenerating();
                }
            }

            var _origFetch = window.fetch;
            window.fetch = function(input, init) {
                var urlStr = typeof input === 'string' ? input : (input && input.url ? input.url : '');
                //console.log('[AI Fetch Hook] 拦截到请求:', urlStr);
                // 用 _ai_proxy=1 标识参数识别 AI 代理请求，不依赖 URL 路径匹配
                // 兼容所有伪静态格式（url_rewrite_on 0-5：ai-chat.htm / ai/chat / ai-chat.html 等）
                if (urlStr.indexOf('_ai_proxy=1') === -1) {
                    //console.log('[AI Fetch Hook] 非 AI 请求，放行');
                    return _origFetch.apply(this, arguments);
                }
                //console.log('[AI Fetch Hook] 命中 AI 请求，当前 aiGenerating=', aiGenerating);
                // 防抖：正在生成时拒绝新请求
                if (aiGenerating) {
                    console.warn('[AI Fetch Hook] 正在生成中，拒绝请求');
                    if (typeof XN !== 'undefined' && XN.toast) {
                        XN.toast('{$aiGeneratingToast}', 'warning');
                    }
                    return Promise.reject(new Error('AI generating'));
                }
                aiGenerating = true;
                //console.log('[AI Fetch Hook] 设置 aiGenerating=true，显示遮罩');
                showAiGenerating();
                //console.log('[AI Fetch Hook] 遮罩已创建:', aiOverlay);
                // 安全兜底：30 秒后自动解锁（防止 stream 异常卡死）
                var safetyTimer = setTimeout(aiGenDone, 30000);

                return _origFetch.apply(this, arguments).then(function(response) {
                    //console.log('[AI Fetch Hook] 收到响应，status=', response.status, 'body=', !!response.body);
                    // 收到响应头说明 AI 已开始输出，立即去掉遮罩，让用户看到文字逐个出现
                    // 防抖锁保持（aiGenerating 仍为 true），防止生成期间再次发起请求
                    hideAiGenerating();
                    //console.log('[AI Fetch Hook] 遮罩已移除，等待 stream 完成');

                    // 用 TransformStream 包装 response.body，在 stream 关闭时检测 done
                    // 不能用 getReader 包装：AIEditor 的 ER 函数通过 pipeThrough 创建新流，会绕过
                    if (response.body && typeof TransformStream !== 'undefined') {
                        var streamDone = false;
                        var transformedBody = response.body.pipeThrough(new TransformStream({
                            transform: function(chunk, controller) {
                                controller.enqueue(chunk);
                            },
                            flush: function() {
                                if (!streamDone) {
                                    streamDone = true;
                                    //console.log('[AI Fetch Hook] TransformStream flush, stream done, 解锁');
                                    clearTimeout(safetyTimer);
                                    aiGenDone();
                                }
                            }
                        }));
                        // 返回新 Response 对象，body 是 TransformStream
                        // AIEditor 的 ER 函数会从新流读取数据，flush 在源流关闭时触发
                        return new Response(transformedBody, {
                            headers: response.headers,
                            status: response.status,
                            statusText: response.statusText
                        });
                    } else {
                        //console.log('[AI Fetch Hook] 无 body 或不支持 TransformStream，立即解锁');
                        clearTimeout(safetyTimer);
                        aiGenDone();
                        return response;
                    }
                }).catch(function(err) {
                    //console.error('[AI Fetch Hook] 请求失败，解锁:', err);
                    clearTimeout(safetyTimer);
                    aiGenDone();
                    throw err;
                });
            };
            // ===== END AI 生成防抖锁 =====

            // 粘贴 Markdown 自动转 HTML：复用公共模块 view/js/editor-paste-markdown.js
            // 识别 IDE/Typora/纯文本等 markdown 复制场景，自动调用 insertMarkdown 转换为 HTML
            if (typeof window.setupEditorPasteMarkdown === 'function') {
                window.setupEditorPasteMarkdown(
                    container,
                    function() { return aiEditorInstance; },
                    syncEditorContent
                );
            }

            // AI 未配置完整时，给 AI 按钮绑定提示和跳转
            if (!aiConfigured) {
                setTimeout(function() {
                    var aiBtn = container.querySelector('aie-ai');
                    if (aiBtn) {
                        // 销毁 tippy 弹出实例
                        if (aiBtn.tippyInstance) {
                            aiBtn.tippyInstance.destroy();
                            aiBtn.tippyInstance = null;
                        }
                        var tippyEl = aiBtn.querySelector('#tippy') || aiBtn.querySelector('.menu-ai');
                        var target = tippyEl || aiBtn;
                        target.addEventListener('click', function(e) {
                            e.stopImmediatePropagation();
                            e.preventDefault();
                            if (confirm(aiNotConfiguredTip)) {
                                window.location.href = '{$myAiUrl}';
                            }
                        }, true);
                    }
                }, 500);
            }

            // 监听网站主题切换，同步编辑器主题
            var themeObserver = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'data-bs-theme') {
                        var newTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
                        if (aiEditorInstance && typeof aiEditorInstance.changeTheme === 'function') {
                            aiEditorInstance.changeTheme(newTheme);
                        }
                    }
                });
            });
            themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

            // 启用拖拽上传
            if (uploadSvc) uploadSvc.enableDragDrop(container);
            // 不启用粘贴上传：AIEditor 内置 image.uploader 已处理图片粘贴，
            // 避免双重上传导致图片重复

            // 立即同步一次初始内容
            setTimeout(syncEditorContent, 200);

            // 监听表单提交，确保提交前同步内容
            var form = hiddenInput.closest('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    syncEditorContent();
                });
            }
        } catch(e) {
            console.error('[Editor] 初始化失败:', e);
        }
    }

    function safeInit() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAiEditor);
        } else {
            initAiEditor();
        }
    }

    // ===== 移动端键盘弹起/收起处理 =====
    // 解决：iOS Safari 键盘收起后 fixed 的 tabbar 不复位、滚动位置错乱、底部残留空白
    var isMobile = window.innerWidth < 768;
    if (isMobile && window.visualViewport) {
        var vv = window.visualViewport;
        var savedScrollY = 0;
        var keyboardOpen = false;
        var restoreTimer = null;

        // 判断键盘是否弹起：视觉视口高度比布局视口小 150px 以上
        function checkKeyboard() {
            return (window.innerHeight - vv.height) > 150;
        }

        function onViewportResize() {
            var nowKb = checkKeyboard();
            if (nowKb && !keyboardOpen) {
                // 键盘弹起：隐藏 fixed tabbar / 悬浮按钮，避免遮挡编辑器
                keyboardOpen = true;
                document.body.classList.add('keyboard-active');
            } else if (!nowKb && keyboardOpen) {
                // 键盘收起：恢复布局，延迟复位滚动位置
                // iOS 上 fixed 元素复位有延迟，用 rAF + 多次校准
                keyboardOpen = false;
                document.body.classList.remove('keyboard-active');
                if (restoreTimer) clearTimeout(restoreTimer);
                var restoreCount = 0;
                function tryRestore() {
                    restoreCount++;
                    window.scrollTo(0, savedScrollY);
                    if (restoreCount < 3) {
                        restoreTimer = setTimeout(tryRestore, 60);
                    }
                }
                restoreTimer = setTimeout(tryRestore, 50);
            }
        }

        // 编辑器获得焦点前记录原始滚动位置（此时键盘尚未弹起）
        // 用 focusin 事件委托，覆盖编辑器内所有可聚焦元素
        wrap.addEventListener('focusin', function() {
            if (!keyboardOpen) {
                savedScrollY = window.scrollY;
            }
        }, true);

        vv.addEventListener('resize', onViewportResize);
        // iOS 键盘弹起时 visualViewport.scroll 也会触发，用于校准
        vv.addEventListener('scroll', function() {
            if (keyboardOpen) {
                // 键盘弹起期间，阻止 iOS 把页面整体顶离（page-top 偏移过大时拉回）
                if (vv.pageTop > savedScrollY + 200) {
                    vv.pageTop = savedScrollY;
                }
            }
        });
    }

    safeInit();

    if (typeof htmx !== 'undefined') {
        document.body.addEventListener('htmx:after:swap', function(evt) {
            if (evt && evt.detail && evt.detail.target && evt.detail.target.querySelector && evt.detail.target.querySelector('#aieditor-container')) {
                setTimeout(safeInit, 100);
            }
        });
    }
})();
</script>
HTML;
    }

    private function getAiService() {
        if (!class_exists('AIService')) {
            include_once APP_PATH . 'lib/AIService.php';
        }
        global $db;
        return new AIService($db, $this->conf);
    }

    private function buildAiConfig(): string {
        $aiService = $this->getAiService();
        global $uid;
        $userAi = $aiService->getEffectiveConfig('editor', $uid);

        // 用户 AI 配置不完整时，不生成模型配置，但前端仍然渲染 AI 按钮
        if (empty($userAi) || empty($userAi['apiKey']) || empty($userAi['model']) || empty($userAi['url'])) {
            return '{bubblePanelEnable:false}';
        }

        $ai = $this->conf['ai'] ?? [];
        $parts = [];

        // 服务端代理模式：不再把 apiKey/endpoint 暴露给前端
        // AIEditor 通过 customUrl 调用 /ai-chat，服务端用真实 apiKey 调 provider
        // AIEditor 的 OpenAI client 不支持自定义 headers，无法带 X-Requested-With
        // 将 CSRF token 注入到 URL query string 中，服务端从 $_GET 取 token 校验
        $csrfToken = CsrfService::getToken();
        // 加 _ai_proxy=1 标识参数，前端 fetch hook 据此识别 AI 代理请求
        // 不依赖 URL 路径匹配，完全兼容所有伪静态格式（url_rewrite_on 0-5）
        // ponytail: url_rewrite_on=0 时 url('ai-chat') 返回 /?ai-chat.htm（已含 ?），
        // 追加 query 必须用 & 否则出现两个 ? 产生非法 URL，浏览器把整段当 query 导致路由解析失败
        $aiChatUrl = url('ai-chat');
        $sep = strpos($aiChatUrl, '?') === FALSE ? '?' : '&';
        $proxyUrl = $aiChatUrl . $sep . '_csrf=' . urlencode($csrfToken) . '&_ai_proxy=1';
        $models = [
            'openai' => [
                'customUrl' => $proxyUrl,
                // apiKey 留空：服务端代理模式下不需要前端传 key
                // AIEditor 仍会带 Authorization 头但服务端忽略它
                'apiKey' => 'proxy',
                'model' => $userAi['model'],
            ]
        ];
        $parts[] = 'models:' . $this->arrayToJs($models);
        $parts[] = 'bubblePanelModel:"openai"';

        // 气泡面板默认开启（后台已移除此配置项，硬编码为 true）
        $parts[] = 'bubblePanelEnable:true';

        if (!empty($ai['menus'])) {
            $menusJs = [];
            foreach ($ai['menus'] as $menu) {
                $menusJs[] = $this->arrayToJs($menu);
            }
            $parts[] = 'menus:[' . implode(',', $menusJs) . ']';
        }

        if (!empty($ai['bubblePanelMenus'])) {
            $bpmJs = [];
            foreach ($ai['bubblePanelMenus'] as $menu) {
                $bpmJs[] = $this->arrayToJs($menu);
            }
            $parts[] = 'bubblePanelMenus:[' . implode(',', $bpmJs) . ']';
        }

        // prompt：用户自定义优先（AIService 已附加全局 prompt），回退全局默认
        $promptContinue = !empty($userAi['promptContinue']) ? $userAi['promptContinue'] : (!empty($ai['promptContinue']) ? $ai['promptContinue'] : '');
        $promptImprove = !empty($userAi['promptImprove']) ? $userAi['promptImprove'] : (!empty($ai['promptImprove']) ? $ai['promptImprove'] : '');
        if (!empty($promptContinue)) {
            $parts[] = 'promptContinue:' . json_encode($promptContinue);
        }
        if (!empty($promptImprove)) {
            $parts[] = 'promptImprove:' . json_encode($promptImprove);
        }

        return '{' . implode(',', $parts) . '}';
    }

    private function getUserAiConfig(): array {
        // 委托 AIService 读取（含旧数据自动迁移）
        $aiService = $this->getAiService();
        global $uid;
        return $aiService->getUserAiConfig($uid, 'editor');
    }

    private function isUserAiConfigComplete(?array $userAi = null): bool {
        // 委托 AIService 判断（$userAi 参数保留以兼容调用方，实际不再使用）
        $aiService = $this->getAiService();
        global $uid;
        $effective = $aiService->getEffectiveConfig('editor', $uid);
        return !empty($effective) && !empty($effective['apiKey']) && !empty($effective['model']) && !empty($effective['url']);
    }

    private function mergeAiConfig(array $global, array $user): array {
        // 用户未启用 AI 时，直接禁用
        if (isset($user['enabled']) && !$user['enabled']) {
            $global['models'] = [];
            $global['bubblePanelEnable'] = false;
            return $global;
        }

        if (!empty($user['models'])) {
            $global['models'] = $user['models'];
        }
        if (isset($user['bubblePanelEnable'])) {
            $global['bubblePanelEnable'] = $user['bubblePanelEnable'];
        }
        if (isset($user['bubblePanelModel'])) {
            $global['bubblePanelModel'] = $user['bubblePanelModel'];
        }
        if (!empty($user['menus'])) {
            $global['menus'] = $user['menus'];
        }
        if (!empty($user['bubblePanelMenus'])) {
            $global['bubblePanelMenus'] = $user['bubblePanelMenus'];
        }
        // 用户自定义 prompt 覆盖全局 prompt
        if (!empty($user['promptContinue'])) {
            $global['promptContinue'] = $user['promptContinue'];
        }
        if (!empty($user['promptImprove'])) {
            $global['promptImprove'] = $user['promptImprove'];
        }
        return $global;
    }

    private function arrayToJs($arr): string {
        if (is_string($arr)) {
            return '"' . addslashes($arr) . '"';
        }
        if (is_bool($arr)) {
            return $arr ? 'true' : 'false';
        }
        if (is_numeric($arr)) {
            return (string)$arr;
        }
        if (!is_array($arr)) {
            return 'null';
        }
        $pairs = [];
        foreach ($arr as $k => $v) {
            $key = is_int($k) ? '' : '"' . addslashes($k) . '":';
            $pairs[] = $key . $this->arrayToJs($v);
        }
        return '{' . implode(',', $pairs) . '}';
    }

    private function mapLangCode(string $xiunoLang): string {
        $map = [
            'zh-cn' => 'zh',
            'zh-tw' => 'zh',
            'en-us' => 'en',
        ];
        return $map[$xiunoLang] ?? 'en';
    }
}
