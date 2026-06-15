<?php

class EditorService {

    private array $conf;

    public function __construct(array $conf) {
        $this->conf = $conf;
    }

    public function getEditorAssets(): array {
        $assets = [
            'css' => [
                'view/js/aieditor/style.css',
            ],
            'js' => [
                'view/js/upload-service.js',
                'view/js/aieditor/index.umd.js',
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
        $mentionTip = lang('mention_label');
        $mentionFollowUrl = url('my-follow_users');
        $mentionSearchUrl = url('user-search');

        return <<<HTML
<style>
.aieditor-container {border:1px solid var(--border-color, #ddd);border-radius:4px;min-height:450px;}
.aieditor-container .aieditor {min-height:450px;}
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
        var url = msg.url || '';
        var name = msg.orgfilename || '附件';
        var size = msg.filesize ? UploadService.formatFileSize(msg.filesize) : '';
        var nameHtml = name;
        if (url) {
            nameHtml = '<a href="' + url + '" target="_blank">' + name + '</a>';
        }
        li.innerHTML = '<span class="att-icon"><i class="ti ti-paperclip"></i></span>' +
            '<span class="att-name" title="' + name + '">' + nameHtml + '</span>' +
            (size ? '<span class="att-size">' + size + '</span>' : '');
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

        var opts = {
            element: '#aieditor-container',
            placeholder: '',
            content: oldTa ? oldTa.value : '',
            toolbar: window.innerWidth < 768
                ? ['bold','italic','link','image',
                    {
                        icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>',
                        onClick: function(event, editor) {
                            editor.focus();
                            editor.insert('@');
                        },
                        tip: '{$mentionTip}'
                    },
                    'ai','code']
                : ['bold','italic','underline','strikeThrough','heading','color','link','image',
                    {
                        icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>',
                        onClick: function(event, editor) {
                            editor.focus();
                            editor.insert('@');
                        },
                        tip: '{$mentionTip}'
                    },
                    'ai','codeBlock','quote','orderedList','unorderedList','align','hr','undo','redo'],
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
                    return uploadFile(file, uploadUrl, function(msg) {
                        return {errorCode: 0, data: {href: msg.url || '', fileName: msg.orgfilename || file.name}};
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
                                return {id: String(u.uid), label: u.username};
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
        $ai = $this->conf['ai'] ?? [];

        $userAi = $this->getUserAiConfig();
        if (!empty($userAi)) {
            $ai = $this->mergeAiConfig($ai, $userAi);
        }

        if (empty($ai) || empty($ai['models'])) {
            return '{bubblePanelEnable:false}';
        }

        $parts = [];

        if (!empty($ai['models'])) {
            $modelsJs = [];
            foreach ($ai['models'] as $name => $config) {
                // 如果用户配置的 custom 直接转为 openai 兼容配置，这样简单易用
                if ($name === 'custom') {
                    $name = 'openai';
                    if (!empty($config['url']) && empty($config['endpoint'])) {
                        $config['endpoint'] = $config['url'];
                        unset($config['url']);
                    }
                    $parts[] = 'bubblePanelModel:' . json_encode($name);
                }
                $modelConfig = $this->arrayToJs($config);
                $modelsJs[] = '"' . addslashes($name) . '":' . $modelConfig;
            }
            $parts[] = 'models:{' . implode(',', $modelsJs) . '}';
        }

        if (isset($ai['bubblePanelEnable'])) {
            $parts[] = 'bubblePanelEnable:' . ($ai['bubblePanelEnable'] ? 'true' : 'false');
        }

        if (isset($ai['bubblePanelModel'])) {
            $parts[] = 'bubblePanelModel:"' . addslashes($ai['bubblePanelModel']) . '"';
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

        if (empty($parts)) {
            return '{bubblePanelEnable:false}';
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

    private function mergeAiConfig(array $global, array $user): array {
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
}
