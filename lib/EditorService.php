<?php

class EditorService {

    private array $conf;

    public function __construct(array $conf) {
        $this->conf = $conf;
    }

    public function getEditorAssets(): array {
        $viewUrl = isset($GLOBALS['conf']['view_url']) ? $GLOBALS['conf']['view_url'] : '/view/';
        $assets = [
            'css' => [
                $viewUrl . 'js/aieditor/style.css',
            ],
            'js' => [
                $viewUrl . 'js/upload-service.js',
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
        $lang = $this->conf['lang'] ?? 'zh-cn';
        $aieditorLang = $this->mapLangCode($lang);

        return <<<HTML
<style>
.aieditor-container {border:1px solid var(--border-color, #ddd);border-radius:4px;min-height:300px;}
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

        // @提及按钮配置
        var mentionBtn = {
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>',
            onClick: function(event, editor) {
                editor.focus();
                editor.insert('@');
            },
            tip: '{$mentionTip}'
        };

        // 构建工具栏：AI 按钮始终显示
        var mobileToolbar = ['bold','italic','link','image','divider', mentionBtn, 'ai','code'];
        var desktopToolbar = ['bold','italic','underline','strike','heading','font-color','link','image','video','attachment','divider', mentionBtn, 'ai','code-block','quote','ordered-list','bullet-list','align','hr','undo','redo'];

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
            placeholder: '',
            content: oldTa ? oldTa.value : '',
            toolbar: toolbar,
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

    private function buildAiConfig(): string {
        $userAi = $this->getUserAiConfig();

        // 用户 AI 配置不完整时，不生成模型配置，但前端仍然渲染 AI 按钮
        if (!$this->isUserAiConfigComplete($userAi)) {
            return '{bubblePanelEnable:false}';
        }

        $ai = $this->conf['ai'] ?? [];
        $ai = $this->mergeAiConfig($ai, $userAi);

        $parts = [];

        // 统一使用 openai 作为模型 key（都用 OpenAI 兼容接口）
        $models = [
            'openai' => [
                'apiKey' => $userAi['apiKey'],
                'endpoint' => $userAi['url'],
                'model' => $userAi['model'],
            ]
        ];
        $parts[] = 'models:' . $this->arrayToJs($models);
        $parts[] = 'bubblePanelModel:"openai"';

        // 默认开启 bubblePanel，用户可在配置中覆盖
        if (isset($ai['bubblePanelEnable'])) {
            $parts[] = 'bubblePanelEnable:' . ($ai['bubblePanelEnable'] ? 'true' : 'false');
        } else {
            $parts[] = 'bubblePanelEnable:true';
        }

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

        // 保存自定义 prompt 变量，前端会用到它们
        if (!empty($ai['promptContinue'])) {
            $parts[] = 'promptContinue:' . json_encode($ai['promptContinue']);
        }
        if (!empty($ai['promptImprove'])) {
            $parts[] = 'promptImprove:' . json_encode($ai['promptImprove']);
        }

        return '{' . implode(',', $parts) . '}';
    }

    private function getUserAiConfig(): array {
        global $uid;
        if (empty($uid)) return [];

        $user = user_read($uid);
        if (empty($user) || empty($user['ai_config'])) return [];

        $config = json_decode($user['ai_config'], true);
        return is_array($config) ? $config : [];
    }

    private function isUserAiConfigComplete(?array $userAi = null): bool {
        if ($userAi === null) {
            $userAi = $this->getUserAiConfig();
        }
        return !empty($userAi['provider_name']) && !empty($userAi['apiKey']) && !empty($userAi['model']) && !empty($userAi['url']);
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
            'ja-jp' => 'ja',
            'ko-kr' => 'ko',
            'th-th' => 'th',
        ];
        return $map[$xiunoLang] ?? 'en';
    }
}
