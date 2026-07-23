/*
 * UploadService - 统一上传模块
 * 替代旧版 FileUploader (upload.js) 和 xn.upload_file (xiuno.js)
 * 提供现代化、统一的文件上传体验
 *
 * 功能：FormData 上传、进度追踪、拖拽上传、粘贴上传、图片预览、多文件队列、文件校验
 */

(function(window) {
    'use strict';

    var DEFAULT_OPTIONS = {
        container: null,
        pasteTarget: null,
        uploadUrl: '',
        csrfToken: '',
        maxImageSize: 10485760,
        maxFileSize: 20480000,
        maxVideoSize: 104857600,
        allowedImageTypes: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        allowedFileTypes: [
            'av', 'wmv', 'wav', 'wma', 'avi', 'rm', 'rmvb', 'mp4', 'mp3',
            'exe', 'bin', 'swf', 'fla', 'as', 'gif', 'jpg', 'jpeg', 'png', 'bmp',
            'doc', 'xls', 'ppt', 'docx', 'xlsx', 'pptx', 'pdf',
            'c', 'cpp', 'cc', 'txt', 'tar', 'zip', 'gz', 'rar', '7z', 'bz',
            'chm', 'bt', 'torrent', 'ttf', 'font', 'fon', 'webp'
        ],
        allowedVideoTypes: ['mp4', 'webm', 'ogg', 'avi', 'rm', 'rmvb'],
        onProgress: function() {},
        onComplete: function() {},
        onError: function() {},
        onPreview: function() {},
        onDragOver: function() {},
        onDragLeave: function() {}
    };

    function extend(target, source) {
        for (var key in source) {
            if (source.hasOwnProperty(key)) {
                target[key] = source[key];
            }
        }
        return target;
    }

    function getFileExtension(filename) {
        if (!filename) return '';
        var lastDot = filename.lastIndexOf('.');
        if (lastDot === -1) return '';
        return filename.substring(lastDot + 1).toLowerCase();
    }

    function isImageFile(file) {
        if (file.type && file.type.indexOf('image/') === 0) return true;
        var ext = getFileExtension(file.name);
        var imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        return imageExts.indexOf(ext) !== -1;
    }

    function isVideoFile(file) {
        var ext = getFileExtension(file.name);
        return ['mp4', 'webm', 'ogg', 'avi', 'rm', 'rmvb', 'wmv', 'wav', 'wma', 'av', 'mp3'].indexOf(ext) !== -1;
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        return '';
    }

    function UploadService(options) {
        this.options = extend({}, DEFAULT_OPTIONS);
        extend(this.options, options || {});

        if (!this.options.csrfToken) {
            this.options.csrfToken = getCsrfToken();
        }

        this._queue = [];
        this._uploading = false;
        this._currentXhr = null;
        this._eventListeners = [];
        this._destroyed = false;

        if (this.options.container) {
            this.enableDragDrop(this.options.container);
        }
        if (this.options.pasteTarget) {
            this.enablePaste(this.options.pasteTarget);
        }
    }

    UploadService.prototype.validateFile = function(file) {
        var ext = getFileExtension(file.name);
        var allTypes = this.options.allowedFileTypes.concat(
            this.options.allowedImageTypes,
            this.options.allowedVideoTypes
        );
        var uniqueTypes = [];
        for (var i = 0; i < allTypes.length; i++) {
            if (uniqueTypes.indexOf(allTypes[i]) === -1) {
                uniqueTypes.push(allTypes[i]);
            }
        }

        if (uniqueTypes.indexOf(ext) === -1) {
            return {
                valid: false,
                error: '不支持的文件类型：' + ext + '，允许的类型：' + uniqueTypes.join(', ')
            };
        }

        var maxSize = this.options.maxFileSize;
        if (isImageFile(file)) {
            maxSize = this.options.maxImageSize;
        } else if (isVideoFile(file)) {
            maxSize = this.options.maxVideoSize;
        }

        if (file.size > maxSize) {
            var maxLabel = isImageFile(file) ? '图片' : (isVideoFile(file) ? '视频' : '文件');
            return {
                valid: false,
                error: maxLabel + '大小超过限制：' + formatFileSize(file.size) + '，最大允许：' + formatFileSize(maxSize)
            };
        }

        if (file.size === 0) {
            return {
                valid: false,
                error: '文件大小为空，请选择有效文件'
            };
        }

        return { valid: true, error: '' };
    };

    UploadService.prototype.getImagePreview = function(file, callback) {
        if (!isImageFile(file)) {
            callback(null);
            return;
        }
        if (typeof FileReader === 'undefined') {
            callback(null);
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            callback(e.target.result);
        };
        reader.onerror = function() {
            callback(null);
        };
        reader.readAsDataURL(file);
    };

    UploadService.prototype.uploadFile = function(file) {
        var _this = this;
        var validation = this.validateFile(file);
        if (!validation.valid) {
            this.options.onError(file, validation.error);
            return Promise.reject(validation.error);
        }

        return new Promise(function(resolve, reject) {
            var xhr = new XMLHttpRequest();
            _this._currentXhr = xhr;

            xhr.open('POST', _this.options.uploadUrl, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (_this.options.csrfToken) {
                xhr.setRequestHeader('X-CSRF-Token', _this.options.csrfToken);
            }

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round(e.loaded * 100 / e.total);
                    _this.options.onProgress(file, percent);
                }
            };

            xhr.onload = function() {
                _this._currentXhr = null;
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.code == 0) {
                            _this.options.onComplete(file, response);
                            resolve(response);
                        } else {
                            var errMsg = response.message || '上传失败';
                            if (typeof errMsg === 'object') {
                                errMsg = JSON.stringify(errMsg);
                            }
                            _this.options.onError(file, errMsg);
                            reject(errMsg);
                        }
                    } catch (e) {
                        _this.options.onError(file, '服务器响应解析失败');
                        reject('服务器响应解析失败');
                    }
                } else {
                    var statusErr = '上传失败，HTTP 状态码：' + xhr.status;
                    _this.options.onError(file, statusErr);
                    reject(statusErr);
                }
            };

            xhr.onerror = function() {
                _this._currentXhr = null;
                _this.options.onError(file, '网络错误，上传失败');
                reject('网络错误，上传失败');
            };

            xhr.onabort = function() {
                _this._currentXhr = null;
                _this.options.onError(file, '上传已取消');
                reject('上传已取消');
            };

            xhr.ontimeout = function() {
                _this._currentXhr = null;
                _this.options.onError(file, '上传超时');
                reject('上传超时');
            };

            var formData = new FormData();
            formData.append('file', file);
            formData.append('csrf_token', _this.options.csrfToken);

            // ponytail: 携带 page_token 用于多标签页/跨帖子附件隔离
            // 从页面 hidden input 读取，attach-create 后端存入 session tmp_files
            var pageTokenEl = document.getElementById('page_token');
            if (pageTokenEl) {
                formData.append('page_token', pageTokenEl.value);
            }

            if (isImageFile(file)) {
                formData.append('is_image', '1');
            } else {
                formData.append('is_image', '0');
            }

            xhr.send(formData);
        });
    };

    UploadService.prototype.uploadFiles = function(fileList) {
        var files = [];
        if (fileList instanceof FileList || Array.isArray(fileList)) {
            for (var i = 0; i < fileList.length; i++) {
                files.push(fileList[i]);
            }
        } else {
            return Promise.resolve([]);
        }

        if (files.length === 0) {
            return Promise.resolve([]);
        }

        var _this = this;
        var results = [];
        var index = 0;

        return new Promise(function(resolve) {
            function uploadNext() {
                if (index >= files.length || _this._destroyed) {
                    resolve(results);
                    return;
                }
                var file = files[index++];
                _this.uploadFile(file).then(function(response) {
                    results.push({ file: file, response: response, success: true });
                    uploadNext();
                }).catch(function() {
                    results.push({ file: file, response: null, success: false });
                    uploadNext();
                });
            }
            uploadNext();
        });
    };

    UploadService.prototype.enableDragDrop = function(element) {
        if (!element) return;
        var _this = this;

        function onDragEnter(e) {
            e.preventDefault();
            e.stopPropagation();
            element.classList.add('upload-drop-active');
            _this.options.onDragOver();
        }

        function onDragOver(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!element.classList.contains('upload-drop-active')) {
                element.classList.add('upload-drop-active');
                _this.options.onDragOver();
            }
        }

        function onDragLeave(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!_this._isChildOf(element, e.relatedTarget)) {
                element.classList.remove('upload-drop-active');
                _this.options.onDragLeave();
            }
        }

        function onDrop(e) {
            e.preventDefault();
            e.stopPropagation();
            element.classList.remove('upload-drop-active');
            _this.options.onDragLeave();

            var files = e.dataTransfer && e.dataTransfer.files;
            if (files && files.length > 0) {
                _this._handleFileList(files);
            }
        }

        element.addEventListener('dragenter', onDragEnter, false);
        element.addEventListener('dragover', onDragOver, false);
        element.addEventListener('dragleave', onDragLeave, false);
        element.addEventListener('drop', onDrop, false);

        this._eventListeners.push({
            element: element,
            events: {
                dragenter: onDragEnter,
                dragover: onDragOver,
                dragleave: onDragLeave,
                drop: onDrop
            }
        });
    };

    UploadService.prototype.enablePaste = function(element) {
        if (!element) return;
        var _this = this;

        function onPaste(e) {
            var clipboardData = e.clipboardData || window.clipboardData;
            if (!clipboardData || !clipboardData.items) return;

            var imageFiles = [];
            for (var i = 0; i < clipboardData.items.length; i++) {
                var item = clipboardData.items[i];
                if (item.kind === 'file' && item.type.indexOf('image/') === 0) {
                    var file = item.getAsFile();
                    if (file) {
                        imageFiles.push(file);
                    }
                }
            }

            if (imageFiles.length > 0) {
                _this._handleFileList(imageFiles);
            }
        }

        element.addEventListener('paste', onPaste, false);

        this._eventListeners.push({
            element: element,
            events: {
                paste: onPaste
            }
        });
    };

    UploadService.prototype._handleFileList = function(fileList) {
        var _this = this;
        var files = [];
        for (var i = 0; i < fileList.length; i++) {
            files.push(fileList[i]);
        }

        for (var j = 0; j < files.length; j++) {
            (function(file) {
                if (isImageFile(file)) {
                    _this.getImagePreview(file, function(dataUrl) {
                        if (dataUrl) {
                            _this.options.onPreview(file, dataUrl);
                        }
                    });
                }
            })(files[j]);
        }

        this.uploadFiles(files);
    };

    UploadService.prototype._isChildOf = function(parent, child) {
        if (!child) return false;
        var node = child.parentNode;
        while (node) {
            if (node === parent) return true;
            node = node.parentNode;
        }
        return false;
    };

    UploadService.prototype.abort = function() {
        if (this._currentXhr) {
            this._currentXhr.abort();
            this._currentXhr = null;
        }
    };

    UploadService.prototype.destroy = function() {
        this._destroyed = true;
        this.abort();

        for (var i = 0; i < this._eventListeners.length; i++) {
            var entry = this._eventListeners[i];
            var element = entry.element;
            var events = entry.events;
            for (var eventName in events) {
                if (events.hasOwnProperty(eventName)) {
                    element.removeEventListener(eventName, events[eventName], false);
                }
            }
        }
        this._eventListeners = [];
        this._queue = [];
    };

    UploadService.isImageFile = isImageFile;
    UploadService.isVideoFile = isVideoFile;
    UploadService.getFileExtension = getFileExtension;
    UploadService.formatFileSize = formatFileSize;

    window.UploadService = UploadService;

})(window);
