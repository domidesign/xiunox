// ========== Bootstrap 5 jQuery 插件桥接 ==========
// 让旧插件的 $(el).modal('show') 等调用正常工作
if (typeof jQuery !== 'undefined' && typeof bootstrap !== 'undefined') {
    var _bsBridge = {
        modal: 'Modal', dropdown: 'Dropdown', tooltip: 'Tooltip',
        popover: 'Popover', collapse: 'Collapse', alert: 'Alert', tab: 'Tab'
    };
    Object.keys(_bsBridge).forEach(function(pluginName) {
        if (jQuery.fn[pluginName] === undefined) {
            jQuery.fn[pluginName] = function(methodOrOptions) {
                var bsClassName = _bsBridge[pluginName];
                return this.each(function() {
                    var instance = bootstrap[bsClassName].getInstance(this) || new bootstrap[bsClassName](this);
                    if (typeof methodOrOptions === 'string') {
                        if (methodOrOptions === 'toggle' || methodOrOptions === 'show' || methodOrOptions === 'hide' || methodOrOptions === 'dispose') {
                            instance[methodOrOptions]();
                        }
                    } else if (pluginName === 'tooltip' || pluginName === 'popover') {
                        // tooltip/popover 需要传入 options 对象
                        // instance 已在 new 时创建，无需额外操作
                    }
                });
            };
        }
    });
}

// ========== 通用函数 ==========

// 提取导航高亮逻辑为独立函数
// ponytail: 客户端 URL 匹配，已知 ceiling 是无法处理配置错误的 URL（如路由名格式与实际页面 URL 不一致），
// 升级路径为服务端传递 current_nav_slug
function highlightNav() {
	var current = normalizeNavPath(window.location.pathname);

	// 清除所有 active
	document.querySelectorAll('[data-active^="nav-"]').forEach(function(el) {
		el.classList.remove('active');
	});

	// 选择最长匹配（避免首页 / 误匹配所有页面，优先匹配更具体的导航项）
	var bestEl = null;
	var bestLen = 0;

	document.querySelectorAll('[data-active^="nav-"]').forEach(function(el) {
		var link = el.querySelector('a');
		if(!link) return;
		var navHref = link.getAttribute('href');
		if(!navHref || navHref === '#') return;

		var navPath = normalizeNavPath(navHref);
		if(!navPath) return;

		// 精确匹配 或 前缀匹配（分页场景 /forum-7/2 匹配 /forum-7）
		if(current === navPath || (navPath !== '/' && current.indexOf(navPath + '/') === 0)) {
			if(navPath.length > bestLen) {
				bestEl = el;
				bestLen = navPath.length;
			}
		}
	});

	if(bestEl) bestEl.classList.add('active');
}

// 规范化 URL 路径用于导航高亮匹配
// 去除 query、确保 / 开头、去除末尾斜杠（首页保留 /）、去除 .htm/.html 后缀
function normalizeNavPath(href) {
	if(!href || href === '#') return '';
	var qIdx = href.indexOf('?');
	var path = qIdx >= 0 ? href.substring(0, qIdx) : href;
	if(!path) return '';
	// 相对路径转绝对路径（避免 /a vs a 不匹配）
	if(path[0] !== '/' && path.indexOf('http') !== 0) {
		path = '/' + path;
	}
	// 去除末尾斜杠（首页保留 /）
	path = path.replace(/\/+$/, '') || '/';
	// 去除 .htm/.html 后缀（url_rewrite_on=1/4 风格）
	path = path.replace(/\.(htm|html)$/i, '');
	return path;
}

// 发送验证码（用户注册、重置密码、修改邮箱）
function sendVerifyCode(btn, extraData) {
	var url = btn.getAttribute('data-url');
	if(!url) return;
	var data = {};

	// 自动从最近的表单中获取 email 字段
	var form = btn.closest('form');
	if(form) {
		var emailInput = form.querySelector('input[name="email"]');
		if(emailInput && emailInput.value) {
			data.email = emailInput.value;
		}
	}

	if(extraData) {
		for(var k in extraData) { data[k] = extraData[k]; }
	}
	var csrfToken = document.querySelector('meta[name="csrf-token"]');
	if(csrfToken) data.csrf_token = csrfToken.getAttribute('content');

	var originalText = btn.textContent;

	XN.post(url, data, function(code, msg) {
		if(code == 0) {
			if(typeof XN.toast === 'function') XN.toast(bbs_lang.captcha_sent, 'success');
			// 倒计时秒数从后端返回的 wait 字段获取，默认 60 秒
			var countdown = (this && this.wait) ? parseInt(this.wait) : 60;
			if(isNaN(countdown) || countdown < 1) countdown = 60;
			btn.disabled = true;
			btn.textContent = countdown + 's';
			var timer = setInterval(function() {
				countdown--;
				if(countdown <= 0) {
					clearInterval(timer);
					btn.disabled = false;
					btn.textContent = originalText;
				} else {
					btn.textContent = countdown + 's';
				}
			}, 1000);
		} else {
			if(typeof XN.toast === 'function') XN.toast(msg || bbs_lang.send_failed, 'danger');
		}
	});
}

// 删除附件（发帖页上传预览）
function deleteAttach(btn, aid) {
	var url = XN.url('attach-delete-' + aid);
	var data = {csrf_token: XN.csrfToken || ''};

	XN.post(url, data, function(code, msg) {
		if(code == 0) {
			// 移除附件卡片（兼容多种容器：card、upload-preview-card、editor-attachment-item）
			var card= btn.closest('.card, .upload-preview-card, .editor-attachment-item, [aid]');
			if(card) card.remove();
			if(typeof XN.toast === 'function') XN.toast(bbs_lang.deleted, 'success');
		} else {
			if(typeof XN.toast === 'function') XN.toast(msg || bbs_lang.delete_failed, 'danger');
		}
	});
}

// 附件下载（方案A：原生 <a download> 触发，浏览器显示进度条）
// 使用事件委托，支持动态插入的元素
// 后端 attach-fetch 路由依靠 token+时效签名防盗链，不再强制 X-Requested-With 头
if (typeof document !== 'undefined') {
	document.addEventListener('click', function(e) {
		var btn = e.target.closest('.attach-fetch-btn');
		if(!btn) return;
		e.preventDefault();
		if(btn.disabled) return;

		var fetchUrl = btn.getAttribute('data-url');
		var fileName = btn.getAttribute('data-name') || 'download';
		if(!fetchUrl) {
			if(typeof XN !== 'undefined' && XN.toast) XN.toast('下载链接无效', 'danger');
			return;
		}

		// 用原生 <a download> 触发浏览器下载
		// 浏览器接管后会显示原生下载进度条（含文件大小、剩余时间、可取消）
		// 同源下 download 属性生效；服务端已返回 Content-Disposition: attachment，跨域也会触发下载
		var a = document.createElement('a');
		a.href = fetchUrl;
		a.download = fileName;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);

		// 短暂禁用按钮防止误点；浏览器接管下载后无法准确回调，1.2s 后恢复
		btn.disabled = true;
		var originalHtml = btn.innerHTML;
		btn.innerHTML = '<i class="ti ti-loader ti-spin"></i>';
		if(typeof XN !== 'undefined' && XN.toast) XN.toast('下载已开始，请查看浏览器下载列表', 'primary');

		setTimeout(function() {
			btn.disabled = false;
			btn.innerHTML = originalHtml;
		}, 1200);
	});
}

// 绑定 .post_reply 点击事件（支持动态插入的元素）
function bindPostReplyEvents() {
	var replyBtns = document.querySelectorAll('.post_reply');
	for(var i = 0; i < replyBtns.length; i++) {
		var btn = replyBtns[i];
		if(btn._replyBound) continue;
		btn._replyBound = true;
		btn.addEventListener('click', function(e) {
			e.preventDefault();
			var pid = this.getAttribute('data-pid');
			var username = this.getAttribute('data-username');
			var messageEl = document.getElementById('message');
			if(messageEl) {
				messageEl.value = '@' + username + ' ';
				messageEl.focus();
			}
			var quotepidInput = document.querySelector('input[name="quotepid"]');
			if(quotepidInput) quotepidInput.value = pid;
			var offset = messageEl ? messageEl.getBoundingClientRect() : null;
			if(offset) {
				window.scrollTo({ top: window.pageYOffset + offset.top - 100, behavior: 'smooth' });
			}
		});
	}
}
window.bindPostReplyEvents = bindPostReplyEvents;

// 通用导航高亮：首次加载时执行
highlightNav();

// ========== 版主管理函数（全局可用，在 #body 外不受 htmx swap 影响） ==========

// 收集选中的帖子 tid（兼容列表页复选框 + 详情页 hidden input）
function getModTidarr() {
	var tidarr = [];
	// 优先收集复选框（列表页）
	document.querySelectorAll('input[name="modtid"][type="checkbox"]:checked').forEach(function(el) {
		if(el.value) tidarr.push(el.value);
	});
	// 其次收集 hidden input（详情页）
	if(tidarr.length === 0) {
		document.querySelectorAll('input[name="modtid"]:not([type="checkbox"])').forEach(function(el) {
			if(el.value) tidarr.push(el.value);
		});
	}
	// 最后兜底：从 #modModal 内的 #tidarr 读取（弹窗已打开场景）
	if(tidarr.length === 0) {
		var tidarrInput = document.querySelector('#modModal #tidarr');
		if(tidarrInput && tidarrInput.value) {
			try { tidarr = JSON.parse(tidarrInput.value); } catch(e) {}
		}
	}
	return tidarr;
}

// 全选/取消全选
function toggleCheckAll(el) {
	document.querySelectorAll('input[name="modtid"]').forEach(function(cb) {
		cb.checked = el.checked;
	});
}

// 打开管理操作弹窗：fetch 表单 HTML → 填入 tidarr → 显示 Bootstrap Modal
// tid 参数可选：详情页直接传入 tid，跳过"请选择主题"校验；列表页不传，走复选框逻辑
function openModModal(url, title, size, tid) {
	var tidarr;
	if(tid) {
		// 详情页：直接使用传入的 tid
		tidarr = [String(tid)];
	} else {
		// 列表页：从复选框或 hidden input 收集
		tidarr = getModTidarr();
	}
	if(tidarr.length === 0) {
		if(typeof showToast === 'function') showToast(bbs_lang.please_select_thread, 'warning');
		else XN.alert(bbs_lang.please_select_thread);
		return;
	}

	fetch(url, { headers: { 'Accept': 'text/html' } })
		.then(function(r) { return r.text(); })
		.then(function(html) {
			// 移除旧弹窗
			var old = document.getElementById('modModal');
			if(old) old.remove();

			var modalHtml = '<div class="modal fade" id="modModal" tabindex="-1" aria-hidden="true">'
				+ '<div class="modal-dialog modal-dialog-centered">'
				+ '<div class="modal-content">'
				+ '<div class="modal-header"><h5 class="modal-title">' + title + '</h5>'
				+ '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
				+ '<div class="modal-body p-0">' + html + '</div>'
				+ '</div></div></div>';

			document.body.insertAdjacentHTML('beforeend', modalHtml);

			var modalEl = document.getElementById('modModal');

			// 预填 tidarr：用 tidarr[] 多字段格式，PHP param() 才能正确解析为数组
			var oldTidarrInputs = modalEl.querySelectorAll('input[name="tidarr"]');
			oldTidarrInputs.forEach(function(el) { el.remove(); });
			var formEl = modalEl.querySelector('form');
			if(formEl) {
				tidarr.forEach(function(tid) {
					var input = document.createElement('input');
					input.type = 'hidden';
					input.name = 'tidarr[]';
					input.value = tid;
					formEl.prepend(input);
				});
			}
			var totalEl = modalEl.querySelector('.total');
			if(totalEl) totalEl.textContent = tidarr.length;

			// 绑定取消按钮
			var cancelBtn = modalEl.querySelector('#cancel-btn');
			if(cancelBtn) {
				cancelBtn.addEventListener('click', function() {
					// 先让按钮失焦，避免在 aria-hidden 容器内被聚焦（WAI-ARIA 规范）
					// 用 blur() 比 body.focus() 更可靠（body 默认不可聚焦）
					if(document.activeElement && document.activeElement.blur) {
						document.activeElement.blur();
					}
					var m = bootstrap.Modal.getInstance(modalEl);
					if(m) m.hide();
				});
			}
			// btn-close 关闭按钮同样失焦
			var closeBtn = modalEl.querySelector('.btn-close');
			if(closeBtn) {
				closeBtn.addEventListener('click', function() {
					if(document.activeElement && document.activeElement.blur) {
						document.activeElement.blur();
					}
				});
			}

			var modal = new bootstrap.Modal(modalEl);
			modal.show();

			modalEl.addEventListener('hidden.bs.modal', function() {
				modalEl.remove();
			});
		})
		.catch(function(err) {
			console.error('openModModal error:', err);
			if(typeof showToast === 'function') showToast(bbs_lang.load_failed, 'danger');
			else XN.alert(bbs_lang.load_failed);
		});
}

// 管理按钮点击入口
function handleModBtnClick(url, title, size, tid) {
	openModModal(url, title, size, tid);
}

// 管理表单提交（fetch POST → JSON 响应 → 成功刷新 / 失败显示错误）
function submitModForm(event, form, msgSelector) {
	event.preventDefault();
	var actionUrl = form.getAttribute('data-action') || form.action;
	var formData = new FormData(form);

	// 确保 CSRF token 存在
	if(!formData.has('csrf_token')) {
		var csrfMeta = document.querySelector('meta[name="csrf-token"]');
		if(csrfMeta) formData.set('csrf_token', csrfMeta.getAttribute('content'));
	}

	var msgEl = msgSelector ? document.querySelector(msgSelector) : null;

	fetch(actionUrl, {
		method: 'POST',
		body: formData,
		headers: { 'Accept': 'application/json' }
	})
	.then(function(r) { return r.json(); })
	.then(function(json) {
		if(parseInt(json.code) === 0) {
			var modalEl = form.closest('.modal');
			if(modalEl) {
				var modal = bootstrap.Modal.getInstance(modalEl);
				if(modal) modal.hide();
			}
			if(typeof showToast === 'function') showToast(json.message || bbs_lang.operate_successfully, 'success');
			setTimeout(function() { location.reload(); }, 500);
		} else {
			if(msgEl) {
				msgEl.innerHTML = '<div class="alert alert-danger py-2 small mb-2">' + (json.message || bbs_lang.operation_failed) + '</div>';
			} else {
				XN.alert(json.message || bbs_lang.operation_failed);
			}
		}
	})
	.catch(function(err) {
		console.error('submitModForm error:', err);
		var errMsg = bbs_lang.network_request_failed;
		if(msgEl) {
			msgEl.innerHTML = '<div class="alert alert-danger py-2 small mb-2">' + errMsg + '</div>';
		} else {
			XN.alert(errMsg);
		}
	});

	return false;
}

// 个性签名保存后即时更新页面中的签名显示
// 定义在 #body 外，避免 htmx boost 导航后丢失
function updateSignatureDisplay(form) {
	var ctx = event.detail && event.detail.ctx;
	var response = ctx && ctx.response;
	if (!response || !response.ok) return;
	var sig = form.querySelector('textarea[name="signature"]');
	if (!sig) return;
	var sigValue = sig.value || '';
	var displays = document.querySelectorAll('.user-signature-display');
	displays.forEach(function(el) {
		if (sigValue) {
			var icon = el.querySelector('i');
			el.textContent = sigValue;
			if (icon) el.insertBefore(icon, el.firstChild);
			el.style.display = '';
		} else {
			el.style.display = 'none';
		}
	});
}

// ========== htmx 事件配置 ==========
if(typeof htmx !== 'undefined') {
    // htmx:config:request — 只添加 header，不修改 parameters（表单已有 CsrfService::input() 的 hidden input）
    document.body.addEventListener('htmx:config:request', function(evt) {
        // htmx 4 使用 fetch API，不再自动发送 X-Requested-With 头
        // 服务端依赖此头检测 AJAX 请求，必须手动添加
        evt.detail.ctx.request.headers['X-Requested-With'] = 'XMLHttpRequest';
        var method = (evt.detail.ctx.request.method || '').toUpperCase();
        if (method === 'POST' || method === 'PUT' || method === 'DELETE') {
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                // 只设置 header，不修改 parameters
                // 表单的 hidden input csrf_token 会自动包含在 POST 数据中
                evt.detail.ctx.request.headers['X-CSRF-Token'] = csrfMeta.getAttribute('content');
            }
        }
        // API 请求自动注入应用凭证（仅 /api/v1/ 路径）
        var path = evt.detail.path || '';
        if (path.indexOf('/api/v1/') !== -1) {
            var appIdMeta = document.querySelector('meta[name="api-app-id"]');
            if (appIdMeta) {
                evt.detail.ctx.request.headers['X-App-Id'] = appIdMeta.getAttribute('content');
                // 注意：不发送 X-App-Secret，secret 仅用于服务端调用
            }
        }
    });

    // htmx 成功跳转：toast 提示 + 延迟跳转
    // htmx 4 HX-Trigger 事件：evt.detail 就是触发数据对象本身
    // 后端 message 用 rawurlencode 编码中文，前端需要 decodeURIComponent 解码
    document.addEventListener('htmxSuccessRedirect', function(evt) {
        var data = evt.detail || {};
        // htmx 4 可能将 detail 包装为数组 [data]，也可能是对象本身
        if (Array.isArray(data) && data.length > 0) data = data[0];
        var msg = data.message || bbs_lang.operate_successfully;
        // 解码 rawurlencode 编码的中文消息
        try { msg = decodeURIComponent(msg); } catch(e) {}
        var redirect = data.redirect || '/';
        if(typeof XN.toast === 'function') {
            XN.toast(msg, 'success');
        } else {
            XN.alert(msg);
        }
        // 积分变动提示
        if(data.change_desc) {
            var changeDesc = data.change_desc;
            try { changeDesc = decodeURIComponent(changeDesc); } catch(e) {}
            setTimeout(function() {
                if(typeof XN.toast === 'function') XN.toast(changeDesc, 'info');
            }, 800);
        }
        setTimeout(function() {
            window.location.href = redirect;
        }, 1500);
    });

    // htmx 成功（无跳转）：仅 toast 提示
    document.addEventListener('htmxSuccess', function(evt) {
        var data = evt.detail || {};
        if (Array.isArray(data) && data.length > 0) data = data[0];
        var msg = data.message || bbs_lang.operate_successfully;
        try { msg = decodeURIComponent(msg); } catch(e) {}
        if(typeof XN.toast === 'function') {
            XN.toast(msg, 'success');
        }
        // 积分变动提示
        if(data.change_desc) {
            var changeDesc = data.change_desc;
            try { changeDesc = decodeURIComponent(changeDesc); } catch(e) {}
            setTimeout(function() {
                if(typeof XN.toast === 'function') XN.toast(changeDesc, 'info');
            }, 800);
        }
    });

	window.addEventListener('popstate', function() {
		highlightNav();
	});
	// swap 完成后：导航高亮 + 代码高亮（highlight.js 仅在帖子详情页加载，未加载时跳过）
	document.addEventListener('htmx:after:swap', function(evt) {
		highlightNav();
		if(typeof hljs !== 'undefined' && evt.target) {
			evt.target.querySelectorAll('pre code:not([data-highlighted])').forEach(function(el) {
				hljs.highlightElement(el);
			});
		}
	});
}

// ========== 通知页面：标记已读（htmx 事件驱动） ==========
(function() {
	// 更新顶部通知未读数（数字+铃铛图标）
	function updateNoticeBadge(count) {
		var badge = document.getElementById('notice-badge');
		if(!badge) return;
		if(count > 0) {
			var display = count > 99 ? '99+' : count;
			badge.innerHTML = '<span class=" badge rounded-pill bg-danger" style="font-size:0.65rem;line-height:1;padding:0.15em 0.4em">' + display + '</span>';
		} else {
			badge.innerHTML = '';
		}
		// 同步更新铃铛图标
		var bellIcon = document.getElementById('notify-bell-icon');
		if(bellIcon) {
			if(count > 0) {
				bellIcon.classList.remove('ti-bell');
				bellIcon.classList.add('ti-bell-filled');
				bellIcon.style.color = 'var(--bs-primary)';
			} else {
				bellIcon.classList.remove('ti-bell-filled');
				bellIcon.classList.add('ti-bell');
				bellIcon.style.color = '';
			}
		}
	}

	// 将单条卡片 UI 更新为已读状态
	function updateCardReadUI(card) {
		if(!card) return;
		card.classList.remove('notice-unread');
		var btn = card.querySelector('.notice-mark-read');
		if(btn) btn.remove();
	}

	// 监听后端 HX-Trigger 的 noticeReadUpdated 事件（单条标记已读后更新顶部导航徽章）
	document.addEventListener('noticeReadUpdated', function(evt) {
		var data = evt.detail && evt.detail[0] ? evt.detail[0] : {};
		updateNoticeBadge(data.unread_count !== undefined ? data.unread_count : 0);
	});

	// 监听后端 HX-Trigger 的 noticeMarkAllRead 事件
	document.addEventListener('noticeMarkAllRead', function(evt) {
		var items = document.querySelectorAll('.notice-card');
		items.forEach(function(el) { updateCardReadUI(el); });
		var data = evt.detail && evt.detail[0] ? evt.detail[0] : {};
		updateNoticeBadge(data.unread_count || 0);
		var markAllBtn = document.getElementById('notice-mark-all-read');
		if(markAllBtn) markAllBtn.remove();
		if(typeof XN.toast === 'function') XN.toast(bbs_lang.all_marked_as_read, 'success');
	});

	// 监听后端 HX-Trigger 的 noticeDeleted 事件
	document.addEventListener('noticeDeleted', function(evt) {
		var data = evt.detail && evt.detail[0] ? evt.detail[0] : {};
		if(data.nid) {
			var card = document.getElementById('notice-nid-' + data.nid);
			if(card) card.remove();
		}
	});
})();

// ========== 公告提醒（关闭后不再显示） ==========
(function() {
	var container = document.getElementById('announcement-toast-container');
	if(!container) return;

	// 从 localStorage 读取已关闭的公告列表
	function getDismissedAnnouncements() {
		try {
			var data = localStorage.getItem('dismissed_announcements');
			return data ? JSON.parse(data) : {};
		} catch(e) { return {}; }
	}

	// 记录已关闭的公告
	function dismissAnnouncement(nid) {
		var dismissed = getDismissedAnnouncements();
		dismissed[nid] = Date.now();
		// 只保留最近30天的记录，避免无限增长
		var thirtyDaysAgo = Date.now() - 30 * 24 * 3600 * 1000;
		Object.keys(dismissed).forEach(function(key) {
			if(dismissed[key] < thirtyDaysAgo) delete dismissed[key];
		});
		try { localStorage.setItem('dismissed_announcements', JSON.stringify(dismissed)); } catch(e) {}
	}

	// 延迟加载公告：等浏览器空闲时再请求，不阻塞首屏渲染
	var _loadAnnouncements = function() {
		fetch(bbs_notice_announcements_url, {
			headers: {'X-Requested-With': 'XMLHttpRequest'}
		}).then(function(r) { return r.json(); }).then(function(res) {
		if(res.code == 0 && res.message && res.message.length > 0) {
			var dismissed = getDismissedAnnouncements();
			res.message.forEach(function(ann) {
				// 跳过已关闭的公告
				if(dismissed[ann.nid]) return;

				var iconClass = ann.icon || 'ti-speakerphone';
				var toastId = 'announcement-' + ann.nid;
				var html = '<div class="toast show" role="alert" id="' + toastId + '" style="min-width:300px;max-width:380px;">' +
					'<div class="toast-header">' +
						'<i class="ti ' + iconClass + ' me-2 text-primary"></i>' +
						'<strong class="me-auto">公告</strong>' +
						'<button type="button" class="btn-close" data-bs-dismiss="toast" data-nid="' + ann.nid + '"></button>' +
					'</div>' +
					'<div class="toast-body">';
				if(ann.url) {
					html += '<a href="' + ann.url + '" class="text-decoration-none">' + ann.message + '</a>';
				} else {
					html += ann.message;
				}
				html += '</div></div>';
				container.insertAdjacentHTML('beforeend', html);
			});

			// 监听关闭按钮，记录已关闭的公告
			container.addEventListener('click', function(e) {
				var closeBtn = e.target.closest('.btn-close[data-nid]');
				if(closeBtn) {
					var nid = closeBtn.getAttribute('data-nid');
					if(nid) dismissAnnouncement(nid);
				}
			});
		}
	}).catch(function(){});
	};
	// 使用 requestIdleCallback 延迟加载，不支持时降级为 setTimeout
	if ('requestIdleCallback' in window) {
		requestIdleCallback(_loadAnnouncements, {timeout: 3000});
	} else {
		setTimeout(_loadAnnouncements, 2000);
	}
})();

// ========== 主题切换（明亮/暗黑/跟随系统 + 主题色） ==========
(function () {
	var iconLight = document.getElementById('theme-icon-light');
	var iconDark = document.getElementById('theme-icon-dark');
	var iconAuto = document.getElementById('theme-icon-auto');
	var modeBtns = document.querySelectorAll('.theme-mode-btn');
	var colorBtns = document.querySelectorAll('.theme-color-btn');

	// 安全读写 localStorage（兼容 iOS Safari 私密模式）
	function safeGet(key) {
		try { return localStorage.getItem(key); } catch (e) { return null; }
	}
	function safeSet(key, val) {
		try { localStorage.setItem(key, val); } catch (e) {}
	}

	// 系统暗黑模式媒体查询
	var darkMQ = window.matchMedia('(prefers-color-scheme: dark)');

	function getEffectiveTheme(mode) {
		if (mode === 'auto') {
			return darkMQ.matches ? 'dark' : 'light';
		}
		return mode;
	}

	function applyThemeMode(mode) {
		var effective = getEffectiveTheme(mode);
		document.documentElement.setAttribute('data-bs-theme', effective);
		// 更新图标
		if (iconLight) iconLight.classList.toggle('d-none', mode !== 'light');
		if (iconDark) iconDark.classList.toggle('d-none', mode !== 'dark');
		if (iconAuto) iconAuto.classList.toggle('d-none', mode !== 'auto');
		// 更新按钮选中状态
		modeBtns.forEach(function(btn) {
			var isActive = btn.getAttribute('data-mode') === mode;
			btn.classList.toggle('btn-secondary', isActive);
			btn.classList.toggle('btn-outline-secondary', !isActive);
		});
		safeSet('theme', mode);
	}

	function applyThemeColor(color) {
		document.documentElement.setAttribute('data-theme', color);
		// 清除旧的自定义色
		['primary','success','warning','danger','info'].forEach(function(n) {
			safeSet('theme-color-' + n, null);
			try { localStorage.removeItem('theme-color-' + n); } catch(e) {}
		});
		try { localStorage.removeItem('theme-color-body-bg-light'); } catch(e) {}
		try { localStorage.removeItem('theme-color-body-bg-dark'); } catch(e) {}
		safeSet('theme-color', color);
		// 更新按钮选中状态
		colorBtns.forEach(function(btn) {
			var isActive = btn.getAttribute('data-color') === color;
			btn.style.outline = isActive ? '2px solid var(--bs-body-color)' : 'none';
			btn.style.outlineOffset = '2px';
		});
	}

	// 初始化
	var storedMode = safeGet('theme') || (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');
	applyThemeMode(storedMode);

	var storedColor = safeGet('theme-color') || document.documentElement.getAttribute('data-theme') || 'blue';
	applyThemeColor(storedColor);

	// 模式按钮点击
	modeBtns.forEach(function(btn) {
		btn.addEventListener('click', function() {
			var mode = this.getAttribute('data-mode');
			applyThemeMode(mode);
		});
	});

	// 主题色按钮点击
	colorBtns.forEach(function(btn) {
		btn.addEventListener('click', function() {
			var color = this.getAttribute('data-color');
			applyThemeColor(color);
		});
	});

	// 跟随系统模式时，监听系统主题变化
	darkMQ.addEventListener('change', function() {
		var currentMode = safeGet('theme');
		if (currentMode === 'auto') {
			applyThemeMode('auto');
		}
	});
})();



// ========== 通知系统（铃铛、未读数、下拉列表） ==========
(function () {
	var csrfToken = document.querySelector('meta[name="csrf-token"]');
	var token = csrfToken ? csrfToken.getAttribute('content') : '';

	// 上次通知未读数，用于检测 0→>0 转变
	var _prevNoticeCount = 0;

	// 铃铛持续抖动动画（有未读时一直抖）
	var _bellAnim = null;
	function startBellShake() {
		var bellIcon = document.getElementById('notify-bell-icon');
		if (!bellIcon) return;
		if (_bellAnim) return;
		if (typeof anime === 'undefined') return;
		var animate = anime.animate;
		_bellAnim = animate(bellIcon, {
			rotate: [0, 15, -10, 8, -5, 0],
			duration: 1200,
			loop: true,
			ease: 'inOutSine',
			loopDelay: 2000
		});
	}
	function stopBellShake() {
		if (_bellAnim) {
			_bellAnim.pause();
			_bellAnim = null;
		}
		var bellIcon = document.getElementById('notify-bell-icon');
		if (bellIcon) bellIcon.style.transform = '';
	}

	// 更新铃铛图标状态（有未读→填充+brand色，无未读→线条图标）
	function updateBellIcon(hasUnread) {
		var bellIcon = document.getElementById('notify-bell-icon');
		if (!bellIcon) return;
		if (hasUnread) {
			bellIcon.classList.remove('ti-bell');
			bellIcon.classList.add('ti-bell-filled');
			bellIcon.style.color = 'var(--bs-primary)';
		} else {
			bellIcon.classList.remove('ti-bell-filled');
			bellIcon.classList.add('ti-bell');
			bellIcon.style.color = '';
		}
	}

	// 更新通知未读徽章（数字）
	function updateBadge(count) {
		var badge = document.getElementById('notice-badge');
		if (!badge) return;
		if (_prevNoticeCount === 0 && count > 0) {
			startBellShake();
		} else if (count === 0) {
			stopBellShake();
		}
		_prevNoticeCount = count;
		updateBellIcon(count > 0);
		if (count > 0) {
			var display = count > 99 ? '99+' : count;
			badge.innerHTML = '<span class="  badge rounded-pill bg-danger" style="font-size:0.65rem;line-height:1;padding:0.15em 0.4em">' + display + '</span>';
		} else {
			badge.innerHTML = '';
		}
		var markAllBtn = document.getElementById('notice-dropdown-mark-all');
		var markAllSep = document.getElementById('notice-dropdown-mark-all-sep');
		if (markAllBtn) markAllBtn.style.display = count > 0 ? '' : 'none';
		if (markAllSep) markAllSep.style.display = count > 0 ? '' : 'none';
	}

	// 通知未读数延迟加载：等浏览器空闲时再请求，不阻塞首屏渲染
	(function() {
		var badgeEl = document.getElementById('notice-badge');
		if (!badgeEl || typeof bbs_notify_urls === 'undefined') return;
		var _loadUnreadCount = function() {
			fetch(bbs_notify_urls.unread_count, {
				headers: {'X-Requested-With': 'XMLHttpRequest'}
			})
			.then(function(r) { return r.json(); })
			.then(function(json) {
				if (json.code == 0 && json.data && json.data.total > 0) {
					updateBadge(json.data.total);
				}
			})
			.catch(function() {});
		};
		if ('requestIdleCallback' in window) {
			requestIdleCallback(_loadUnreadCount, {timeout: 3000});
		} else {
			setTimeout(_loadUnreadCount, 2000);
		}
	})();

	// 下拉菜单展开时加载通知列表
	var bellEl = document.getElementById('notify-bell');
	if (bellEl && typeof bbs_notify_urls !== 'undefined') {
		bellEl.addEventListener('show.bs.dropdown', function () {
			var listEl = document.getElementById('notice-dropdown-list');
			if (!listEl) return;
			fetch(bbs_notify_urls.dropdown)
				.then(function (r) { return r.text(); })
				.then(function (html) {
					listEl.innerHTML = html || '<div class="text-center text-body-secondary small py-4">' + (bbs_lang.no_notify || '暂无通知') + '</div>';
				})
				.catch(function () {
					listEl.innerHTML = '<div class="text-center text-body-secondary small py-4">' + (bbs_lang.load_failed || '加载失败') + '</div>';
				});
		});
	}

	// 全部标为已读
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('#notice-dropdown-mark-all');
		if (!btn) return;
		e.preventDefault();
		var data = { csrf_token: token };
		fetch(bbs_notify_urls.mark_all_read, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&')
		}).then(function (r) { return r.json(); }).then(function (json) {
			if (json.code != 0) {
				showToast(json.message || bbs_lang.operation_failed, 'danger');
				return;
			}
			var items = document.querySelectorAll('.notice-dropdown-item');
			items.forEach(function (el) {
				el.classList.remove('fw-semibold');
				var dot = el.querySelector('.badge.bg-primary');
				if (dot) dot.remove();
			});
			updateBadge(0);
			btn.remove();
			showToast(bbs_lang.all_marked_as_read, 'success');
		}).catch(function () { });
	});

	// 下拉菜单中点击单条通知标记已读
	document.addEventListener('click', function (e) {
		var item = e.target.closest('.notice-dropdown-item');
		if (!item) return;
		var nid = item.getAttribute('data-nid');
		if (!nid) return;
		e.preventDefault();
		e.stopPropagation();
		var href = item.getAttribute('href');
		// 通知系统已合并，统一使用 notify_read 接口
		// 使用 URL 模板，兼容所有 url_rewrite_on 格式
		var markUrl = bbs_notify_urls.notify_read_template.replace('__NID__', nid);
		var data = { csrf_token: token };
		fetch(markUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&')
		}).then(function (r) { return r.json(); }).then(function (json) {
			if (json.code == 0) {
				// 移除未读状态类和"新"徽章
				item.classList.remove('notice-unread', 'fw-semibold');
				// 移除子元素的 fw-semibold（用户名加粗）
				var boldSpans = item.querySelectorAll('.fw-semibold');
				for (var i = 0; i < boldSpans.length; i++) {
					boldSpans[i].classList.remove('fw-semibold');
				}
				var dot = item.querySelector('.badge.bg-primary');
				if (dot) dot.remove();
				var unreadCount = json.unread_count !== undefined ? json.unread_count : -1;
				if (unreadCount >= 0) {
					updateBadge(unreadCount);
				}
			}
		}).catch(function () { }).finally(function () {
			if (href && href !== '#') {
				window.location.href = href;
			}
		});
	});
})();

// ========== 点赞按钮弹跳动画 ==========
(function () {
	function bounceHeart(el) {
		if (typeof anime === 'undefined') return;
		var animate = anime.animate;
		animate(el, {
			scale: [1, 1.4, 1],
			duration: 300,
			ease: 'outBack'
		});
	}

	document.addEventListener('click', function (e) {
		var likeBtn = e.target.closest('.thread-like-btn, .post-like-btn');
		if (!likeBtn) return;
		var heartIcon = likeBtn.querySelector('.ti-heart');
		if (!heartIcon || heartIcon.classList.contains('ti-heart-filled')) return;
		bounceHeart(heartIcon);
	});
})();

// ========== 原有 bbs.js 功能 ==========

// 表单快捷键提交 CTRL+ENTER   / form quick submit
// 兼容 htmx：如果表单有 hx-post，使用 htmx 触发提交
$('form').keyup(function(e) {
	var jthis = $(this);
	if((e.ctrlKey && (e.which == 13 || e.which == 10)) || (e.altKey && e.which == 83)) {
		var formEl = jthis[0];
		if(formEl && formEl.hasAttribute('hx-post') && typeof htmx !== 'undefined') {
			htmx.trigger(formEl, 'submit');
		} else {
			jthis.trigger('submit');
		}
		return false;
	}
});

// 点击响应整行：方便手机浏览  / check response line
$('.tap').on('click', function(e) {
	var href = $(this).attr('href') || $(this).data('href');
	if(e.target.nodeName == 'INPUT') return true;
	if($(window).width() > 992) return;
	if(e.ctrlKey) {
		window.open(href);
		return false;
	} else {
		window.location = href;
	}
});
// 点击响应整行：导航栏下拉菜单   / check response line
$('ul.nav > li').on('click', function(e) {
	var jthis = $(this);
	var href = jthis.children('a').attr('href');
	if(e.ctrlKey) {
		window.open(href);
		return false;
	}
});
// 点击响应整行：，但是不响应 checkbox 的点击  / check response line, without checkbox
$('.thread input[type="checkbox"]').parents('td').on('click', function(e) {
	e.stopPropagation();
})

// 确定框 / confirm / GET / POST
// <a href="1.php" data-confirm-text="确定删除？" class="confirm">删除</a>
// <a href="1.php" data-method="post" data-confirm-text="确定删除？" class="confirm">删除</a>
$('a.confirm').on('click', function() {
	var jthis = $(this);
	var text = jthis.data('confirm-text');
	$.confirm(text, function() {
		var method = xn.strtolower(jthis.data('method'));
		var href = jthis.data('href') || jthis.attr('href');
		if(method == 'post') {
			$.xpost(href, function(code, message) {
				if(code == 0) {
					window.location.reload();
				} else {
					XN.alert(message);
				}
			});
		} else {
			//window.location = jthis.attr('href');
		}
	})
	return false;
});

// _confirm 确认删除（POST 方式，用于帖子/回帖删除按钮）
$(document).on('click', 'a._confirm', function() {
	var jthis = $(this);
	var text = jthis.data('confirm-text') || bbs_lang.confirm_delete;
	var href = jthis.data('href');
	if(!href) return false;

	var csrfToken = (typeof XN !== 'undefined' && XN.csrfToken) || $('input[name="csrf_token"]').first().val() || $('meta[name="csrf-token"]').attr('content') || '';
	if(!csrfToken) {
		XN.toast('CSRF token 缺失，请刷新页面', 'danger');
		return false;
	}
	var postData = {csrf_token: csrfToken};

	function doDelete() {
		$.xpost(href, postData, function(code, message) {
			if(parseInt(code) === 0) {
				window.location.reload();
			} else {
				XN.toast(message || bbs_lang.operation_failed, 'danger');
			}
		});
	}

	// 删除操作：先确认删除意图，再检查积分扣减
	var isDelete = jthis.hasClass('post_delete');
	var isfirst = jthis.attr('isfirst') === '1' || jthis.attr('isfirst') === 1;
	var creditsEvent = isfirst ? 'thread_delete' : 'reply_delete';
	// 管理员/版主（gid<5）删除时不弹积分确认窗：扣的是作者积分，不应拿操作者余额做预检查
	var isModDelete = (typeof gid !== 'undefined' && parseInt(gid) > 0 && parseInt(gid) < 5);

	XN.confirm(text, function() {
		if (isDelete && !isModDelete && typeof XN.confirmCreditsDeduct === 'function') {
			var fid = typeof threadFid !== 'undefined' ? threadFid : 0;
			XN.confirmCreditsDeduct(creditsEvent, fid, doDelete);
		} else {
			doDelete();
		}
	});
	return false;
});

// 评论置顶/取消置顶
$(document).on('click', 'a.post_top_btn', function() {
	var jthis = $(this);
	var pid = jthis.data('pid');
	var tid = jthis.data('tid');
	var isTop = jthis.data('is-top');
	var newTop = isTop ? 0 : 1;
	var csrfToken = (typeof XN !== 'undefined' && XN.csrfToken) || $('input[name="csrf_token"]').first().val() || $('meta[name="csrf-token"]').attr('content') || '';
	var modUrl = jthis.data('mod-url') || xn.url('mod-top_post');

	$.xpost(modUrl, {pid: pid, tid: tid, is_top: newTop, csrf_token: csrfToken}, function(code, message) {
		if(parseInt(code) === 0) {
			if(typeof XN.toast === 'function') XN.toast(message, 'success');
			window.location.reload();
		} else {
			if(typeof XN.toast === 'function') XN.toast(message || '操作失败', 'danger');
			else XN.alert(message);
		}
	});
	return false;
});

// 选中所有 / check all
// <input class="checkall" data-target=".tid" />
$('input.checkall').on('click', function() {
	var jthis = $(this);
	var target = jthis.data('target');
	jtarget = $(target);
	jtarget.prop('checked', this.checked);
});

// ========== form.js 功能 ==========

xn.form_radio = function(name, arr, checked) {
	var checked = checked || 0;
	if(xn.empty(arr)) arr = [lang.no, lang.yes];
	var s = '';
	$.each(arr, function(k, v) {
		var add = k == checked ? ' checked="checked"' : '';
		s += "<label class=\"custom-input custom-radio\"><input type=\"radio\" name=\""+name+"\" value=\""+k+"\""+add+" />"+v+"</label> &nbsp; \r\n";
	});
	return s;
}

xn.form_options = function(arr, checked) {
	var checked = checked || 0;
	var s = '';
	$.each(arr, function(k, v) {
		var add = k == checked ? ' selected="selected"' : '';
		s += "<option value=\""+k+"\""+add+">"+v+"</option> \r\n";
	});
	return s;
}

xn.form_select = function(name, arr, checked, id) {
	var checked = checked || 0;
	var id = id || true;
	if(xn.empty(arr)) return '';
	var idadd = id === true ? "id=\""+name+"\"" : (id ? "id=\""+id+"\"" : '');
	var s = '';
	s += "<select name=\""+name+"\" class=\"custom-select\" "+idadd+"> \r\n";
	s += xn.form_options(arr, checked);
	s += "</select> \r\n";
	return s;
}
