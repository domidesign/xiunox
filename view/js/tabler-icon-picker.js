/**
 * TablerIconPicker - Tabler 图标选择器可复用组件
 * 不依赖 jQuery，使用原生 JS + Bootstrap 5 Modal
 *
 * 用法1：自动绑定
 *   var picker = new TablerIconPicker({
 *       input: '#iconInput',      // 输入框选择器或 DOM 元素，点击时弹出选择器
 *       preview: '#iconPreview',  // 预览元素选择器或 DOM 元素，点击时也弹出选择器
 *       onSelect: function(iconClass) {} // 可选回调
 *   });
 *
 * 用法2：手动打开
 *   var picker = new TablerIconPicker();
 *   picker.open(function(iconClass) {
 *       // 处理选择
 *   });
 *
 * 用法3：获取图标列表
 *   TablerIconPicker.getIconList().then(function(icons) {
 *       // icons 为图标类名数组，如 ['ti-home', 'ti-user', ...]
 *   });
 */
(function(global) {
	'use strict';

	// 图标缓存，首次加载后存储解析结果
	var _iconCache = null;

	// 并发加载共享 Promise，防止重复请求
	var _loadPromise = null;

	// CSS 解析失败时的回退图标列表（约50个常用图标）
	var _fallbackIcons = [
		'ti-home', 'ti-dashboard', 'ti-settings', 'ti-user', 'ti-users',
		'ti-bell', 'ti-bell-ringing', 'ti-search', 'ti-plus', 'ti-minus',
		'ti-x', 'ti-check', 'ti-circle-check', 'ti-alert-circle', 'ti-alert-triangle',
		'ti-info-circle', 'ti-help-circle', 'ti-trash', 'ti-pencil', 'ti-eye',
		'ti-lock', 'ti-lock-open', 'ti-star', 'ti-star-filled', 'ti-heart',
		'ti-heart-filled', 'ti-bookmark', 'ti-bookmark-filled', 'ti-flag', 'ti-arrow-left',
		'ti-arrow-right', 'ti-arrow-up', 'ti-arrow-down', 'ti-chevron-left', 'ti-chevron-right',
		'ti-list', 'ti-grid-dots', 'ti-message', 'ti-message-circle', 'ti-mail',
		'ti-send', 'ti-phone', 'ti-camera', 'ti-photo', 'ti-file',
		'ti-folder', 'ti-download', 'ti-upload', 'ti-cloud', 'ti-code'
	];

	// Modal 内分页大小
	var MODAL_PAGE_SIZE = 80;

	/**
	 * 从页面 CSS 文件动态解析图标列表
	 * 查找页面中 tabler-icons 的 CSS 链接，fetch 其内容并用正则提取图标类名
	 * @returns {Promise<string[]>} 图标类名数组
	 */
	async function loadIconList() {
		// 已缓存则直接返回
		if (_iconCache !== null) {
			return _iconCache;
		}

		// 已有正在进行的请求，共享同一个 Promise
		if (_loadPromise !== null) {
			return _loadPromise;
		}

		_loadPromise = (async function() {
			try {
				// 从 DOM 中查找 tabler-icons 的 CSS 链接
				var linkEl = document.querySelector('link[href*="tabler-icons"]');
				if (!linkEl || !linkEl.href) {
					console.warn('TablerIconPicker: 未找到 tabler-icons CSS 链接，使用回退图标列表');
					_iconCache = _fallbackIcons.slice();
					return _iconCache;
				}

				var cssUrl = linkEl.href;

				// 获取 CSS 文件内容
				var response = await fetch(cssUrl);
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				var cssText = await response.text();

				// 用正则提取所有图标类名
				var iconSet = new Set();
				var regex = /\.ti-([\w-]+):before/g;
				var match;
				while ((match = regex.exec(cssText)) !== null) {
					iconSet.add('ti-' + match[1]);
				}

				if (iconSet.size === 0) {
					console.warn('TablerIconPicker: CSS 中未解析到图标，使用回退图标列表');
					_iconCache = _fallbackIcons.slice();
					return _iconCache;
				}

				// 去重排序后缓存
				_iconCache = Array.from(iconSet).sort();
				return _iconCache;
			} catch (err) {
				console.warn('TablerIconPicker: 加载图标列表失败，使用回退图标列表', err);
				_iconCache = _fallbackIcons.slice();
				return _iconCache;
			} finally {
				_loadPromise = null;
			}
		})();

		return _loadPromise;
	}

	var MODAL_ID = 'tablerIconPickerModal';

	// 解析选择器或 DOM 元素
	function resolveElement(el) {
		if (!el) return null;
		if (typeof el === 'string') return document.querySelector(el);
		if (el.nodeType) return el;
		return null;
	}

	// 确保 Modal DOM 存在（单例）
	function ensureModal() {
		var existing = document.getElementById(MODAL_ID);
		if (existing) return existing;

		var modal = document.createElement('div');
		modal.className = 'modal fade';
		modal.id = MODAL_ID;
		modal.setAttribute('tabindex', '-1');
		modal.innerHTML =
			'<div class="modal-dialog modal-lg modal-dialog-scrollable">' +
				'<div class="modal-content">' +
					'<div class="modal-header">' +
						'<h6 class="modal-title fw-bold"><i class="ti ti-icons me-1"></i>选择图标</h6>' +
						'<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
					'</div>' +
					'<div class="modal-body">' +
						'<input type="text" class="form-control rounded-pill mb-3" id="tablerIconPickerSearch" placeholder="搜索图标..." autocomplete="off">' +
						'<div id="tablerIconPickerHint" class="text-center text-body-secondary py-4">' +
							'<i class="ti ti-search fs-1 d-block mb-2 opacity-25"></i>' +
							'<p class="mb-0 small">请输入关键词搜索图标</p>' +
						'</div>' +
						'<div class="row g-2" id="tablerIconPickerGrid"></div>' +
						'<div id="tablerIconPickerLoadMore" class="text-center mt-2" style="display:none">' +
							'<button type="button" class="btn btn-sm btn-outline-primary" id="tablerIconPickerLoadMoreBtn">加载更多</button>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>';

		document.body.appendChild(modal);
		return modal;
	}

	/**
	 * TablerIconPicker 构造函数
	 * @param {Object} options 配置项
	 * @param {String|Element} options.input 输入框选择器或 DOM 元素
	 * @param {String|Element} options.preview 预览元素选择器或 DOM 元素
	 * @param {Function} options.onSelect 选中回调，参数为 iconClass（如 "ti ti-home"）
	 */
	function TablerIconPicker(options) {
		options = options || {};
		this._inputEl = resolveElement(options.input);
		this._previewEl = resolveElement(options.preview);
		this._onSelect = options.onSelect || null;

		// 绑定点击事件
		var self = this;
		if (this._inputEl) {
			this._inputEl.setAttribute('readonly', '');
			this._inputEl.style.cursor = 'pointer';
			this._inputEl.addEventListener('click', function() {
				self.open();
			});
		}
		if (this._previewEl) {
			this._previewEl.style.cursor = 'pointer';
			this._previewEl.addEventListener('click', function() {
				self.open();
			});
		}
	}

	/**
	 * 打开图标选择器（异步，先加载图标列表再渲染）
	 * @param {Function} callback 可选回调，覆盖实例回调
	 */
	TablerIconPicker.prototype.open = async function(callback) {
		var modalEl = ensureModal();
		var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
		var grid = document.getElementById('tablerIconPickerGrid');
		var searchInput = document.getElementById('tablerIconPickerSearch');
		var hintEl = document.getElementById('tablerIconPickerHint');
		var loadMoreWrap = document.getElementById('tablerIconPickerLoadMore');
		var loadMoreBtn = document.getElementById('tablerIconPickerLoadMoreBtn');
		var self = this;

		// 当前回调优先使用 open 传入的，否则使用实例回调
		var currentCallback = callback || self._onSelect;

		// 异步加载图标列表
		var iconList = await loadIconList();

		// 分页状态
		var filteredIcons = [];
		var displayedCount = 0;

		// 渲染一批图标（追加到网格）
		function renderPage(startIndex) {
			var endIndex = Math.min(startIndex + MODAL_PAGE_SIZE, filteredIcons.length);
			var fragment = document.createDocumentFragment();

			for (var i = startIndex; i < endIndex; i++) {
				var name = filteredIcons[i];
				var col = document.createElement('div');
				col.className = 'col-4 col-sm-3 col-md-2 col-lg-1';
				col.innerHTML =
					'<div class="icon-item text-center p-2 border rounded-3 cursor-pointer" data-icon="' + name + '" title="' + name + '">' +
						'<i class="ti ' + name + '" style="font-size:1.25rem"></i>' +
						'<div class="small text-body-secondary mt-1 text-truncate" style="font-size:0.6rem">' + name.replace('ti-', '') + '</div>' +
					'</div>';
				fragment.appendChild(col);
			}

			grid.appendChild(fragment);
			displayedCount = endIndex;
			updateLoadMore();
		}

		// 更新加载更多按钮
		function updateLoadMore() {
			if (displayedCount >= filteredIcons.length) {
				loadMoreWrap.style.display = 'none';
			} else {
				loadMoreWrap.style.display = '';
			}
		}

		// 搜索并渲染
		function doSearch(keyword) {
			grid.innerHTML = '';
			displayedCount = 0;

			if (!keyword) {
				hintEl.style.display = '';
				hintEl.innerHTML = '<i class="ti ti-search fs-1 d-block mb-2 opacity-25"></i><p class="mb-0 small">请输入关键词搜索图标</p>';
				loadMoreWrap.style.display = 'none';
				return;
			}

			hintEl.style.display = 'none';

			var kw = keyword.toLowerCase();
			filteredIcons = iconList.filter(function(name) {
				return name.toLowerCase().indexOf(kw) !== -1;
			});

			if (filteredIcons.length === 0) {
				hintEl.style.display = '';
				hintEl.innerHTML = '<i class="ti ti-mood-sad fs-1 d-block mb-2 opacity-25"></i><p class="mb-0 small">未找到匹配的图标</p>';
				loadMoreWrap.style.display = 'none';
				return;
			}

			renderPage(0);
		}

		// 点击图标项
		function onGridClick(e) {
			var item = e.target.closest('.icon-item');
			if (!item) return;
			var iconName = item.getAttribute('data-icon');
			var fullClass = 'ti ' + iconName;

			// 更新绑定的 input 和 preview
			if (self._inputEl) {
				self._inputEl.value = fullClass;
			}
			if (self._previewEl) {
				self._previewEl.innerHTML = '<i class="' + fullClass + '"></i>';
			}

			// 执行回调
			if (typeof currentCallback === 'function') {
				currentCallback(fullClass);
			}

			bsModal.hide();
		}

		// 搜索输入（防抖）
		var searchTimer = null;
		function onSearchInput() {
			clearTimeout(searchTimer);
			var val = searchInput.value.trim();
			searchTimer = setTimeout(function() {
				doSearch(val);
			}, 200);
		}

		// 加载更多
		function onLoadMore() {
			renderPage(displayedCount);
		}

		// 清理事件（Modal 关闭后）
		function onModalHidden() {
			grid.removeEventListener('click', onGridClick);
			searchInput.removeEventListener('input', onSearchInput);
			loadMoreBtn.removeEventListener('click', onLoadMore);
			modalEl.removeEventListener('hidden.bs.modal', onModalHidden);
		}

		// 绑定事件
		grid.addEventListener('click', onGridClick);
		searchInput.addEventListener('input', onSearchInput);
		loadMoreBtn.addEventListener('click', onLoadMore);
		modalEl.addEventListener('hidden.bs.modal', onModalHidden);

		// 重置搜索（默认不展示图标，等用户搜索）
		searchInput.value = '';
		grid.innerHTML = '';
		hintEl.style.display = '';
		hintEl.innerHTML = '<i class="ti ti-search fs-1 d-block mb-2 opacity-25"></i><p class="mb-0 small">请输入关键词搜索图标</p>';
		loadMoreWrap.style.display = 'none';

		bsModal.show();
	};

	/**
	 * 静态方法：获取图标列表
	 * @returns {Promise<string[]>} 图标类名数组
	 */
	TablerIconPicker.getIconList = function() {
		return loadIconList();
	};

	// 暴露到全局
	global.TablerIconPicker = TablerIconPicker;

})(window);
