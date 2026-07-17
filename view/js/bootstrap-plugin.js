
$.alert = function(subject, timeout, options) {
	var options = options || {size: "md"};
	var s = '\
	<div class="modal fade" tabindex="-1" role="dialog">\
		<div class="modal-dialog modal-dialog-centered modal-'+options.size+'">\
			<div class="modal-content border-0 rounded-3 shadow">\
				<div class="modal-header border-0 pb-0">\
					<h6 class="modal-title fw-bold"><i class="ti ti-info-circle text-primary me-2"></i>'+lang.tips_title+'</h6>\
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>\
				</div>\
				<div class="modal-body pt-2">\
					<p class="mb-0">'+subject+'</p>\
				</div>\
				<div class="modal-footer border-0 pt-0">\
					<button type="button" class="btn btn-primary  px-4" data-bs-dismiss="modal">'+lang.close+'</button>\
				</div>\
			</div>\
		</div>\
	</div>';
	var jmodal = $(s).appendTo('body');
	var modalEl = jmodal[0];
	var bsModal = new bootstrap.Modal(modalEl);
	bsModal.show();
	modalEl.addEventListener('hidden.bs.modal', function() {
		jmodal.remove();
	});
	if(typeof timeout != 'undefined' && timeout >= 0) {
		setTimeout(function() {
			bsModal.hide();
		}, timeout * 1000);
	}
	jmodal._bsModal = bsModal;
	return jmodal;
}

$.confirm = function(subject, ok_callback, options) {
	var options = options || {size: "md"};
	options.body = options.body || '';
	var title = options.body ? subject : lang.confirm_title+':';
	var subjectHtml = options.body ? '' : '<p>'+subject+'</p>';
	var s = '\
	<div class="modal fade" tabindex="-1" role="dialog">\
		<div class="modal-dialog modal-dialog-centered modal-'+options.size+'">\
			<div class="modal-content border-0 rounded-3 shadow">\
				<div class="modal-header border-0 pb-0">\
					<h6 class="modal-title fw-bold"><i class="ti ti-help-circle text-warning me-2"></i>'+title+'</h6>\
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>\
				</div>\
				<div class="modal-body pt-2">\
					'+subjectHtml+'\
					'+options.body+'\
				</div>\
				<div class="modal-footer border-0 pt-0">\
					<button type="button" class="btn btn-primary  px-4 btn-ok">'+lang.confirm+'</button>\
					<button type="button" class="btn btn-outline-secondary  px-4" data-bs-dismiss="modal">'+lang.close+'</button>\
				</div>\
			</div>\
		</div>\
	</div>';
	var jmodal = $(s).appendTo('body');
	var modalEl = jmodal[0];
	var bsModal = new bootstrap.Modal(modalEl);
	bsModal.show();
	modalEl.addEventListener('hidden.bs.modal', function() {
		jmodal.remove();
	});
	jmodal.find('.modal-footer').find('.btn-ok').on('click', function() {
		bsModal.hide();
		if(ok_callback) ok_callback();
	});
	jmodal._bsModal = bsModal;
	return jmodal;
}


xn.get_loaded_script = function () {
	var arr = [];
	$('script[src]').each(function() {
		arr.push($(this).attr('src'));
	});
	return arr;
}
xn.get_stylesheet_link = function (s) {
	var arr = [];
	var r = s.match(/<link[^>]*?href=\s*\"([^"]+)\"[^>]*>/ig);
	if(!r) return arr;
	for(var i=0; i<r.length; i++) {
		var r2 = r[i].match(/<link[^>]*?href=\s*\"([^"]+)\"[^>]*>/i);
		arr.push(r2[1]);
	}
	return arr;
}
xn.get_script_src = function (s) {
	var arr = [];
	var r = s.match(/<script[^>]*?src=\s*\"([^"]+)\"[^>]*><\/script>/ig);
	if(!r) return arr;
	for(var i=0; i<r.length; i++) {
		var r2 = r[i].match(/<script[^>]*?src=\s*\"([^"]+)\"[^>]*><\/script>/i);
		arr.push(r2[1]);
	}
	return arr;
}
xn.get_script_section = function (s) {
	var r = '';
	var arr = s.match(/<script[^>]+ajax-eval="true"[^>]*>([\s\S]+?)<\/script>/ig);
	return arr ? arr : [];
}
xn.strip_script_src = function (s) {
	s = s.replace(/<script[^>]*?src=\s*\"([^"]+)\"[^>]*><\/script>/ig, '');
	return s;
}
xn.strip_script_section = function (s) {
	s = s.replace(/<script([^>]*)>([\s\S]+?)<\/script>/ig, '');
	return s;
}
xn.strip_stylesheet_link = function (s) {
	s = s.replace(/<link[^>]*?href=\s*\"([^"]+)\"[^>]*>/ig, '');
	return s;
}
xn.eval_script = function (arr, args) {
	if(!arr) return;
	for(var i=0; i<arr.length; i++) {
		var s = arr[i].replace(/<script([^>]*)>([\s\S]+?)<\/script>/i, '$2');
		try {
			var func = new Function('args', s);
			func(args);
		} catch(e) {
			console.log("eval_script() error: %o, script: %s", e, s);
			showToast(s, 'danger');
		}
	}
}
xn.eval_stylesheet = function(arr) {
	if(!arr) return;
	if(!$.required_css) $.required_css = {};
	for(var i=0; i<arr.length; i++) {
		if($.required_css[arr[i]]) continue;
		$.require_css(arr[i]);
	}
}

xn.get_title_body_script_css = function (s) {
	var s = $.trim(s);
	s = s.replace(/<!--\[if\slt\sIE\s9\]>([\s\S]+?)<\!\[endif\]-->/ig, '');
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
	var r = s.match(/<title>([^<]+?)<\/title>/i);
	if(r && r[1]) title = r[1];
	var r = s.match(/<body[^>]*>([\s\S]+?)<\/body>/i);
	if(r && r[1]) body = r[1];
	var jtmp = $('<div>'+body+'</div>');
	var t = jtmp.find('div.ajax-body');
	if(t.length == 0) t = jtmp.find('#body');
	if(t.length > 0)  body = t.html();
	if(!body) body = s;
	if(body.indexOf('<meta ') != -1) {
		console.log('加载的数据有问题：body: %s: ', body);
		body = '';
	}
	jtmp.remove();
	return {title: title, body: body, script_sections: script_sections, script_srcs: script_srcs, stylesheet_links: stylesheet_links};
}

$.ajax_modal = function(url, title, size, callback, arg) {
	var jmodal = $.alert(lang.loading || 'Loading...', -1, {size: size});
	jmodal.find('.modal-title').html(title);
	$.xget(url, function(code, message) {
		if(code == -101) {
			var r = xn.get_title_body_script_css(message);
			jmodal.find('.modal-body').html(r.body);
			jmodal.find('.modal-footer').hide();
		} else {
			jmodal.find('.modal-body').html(message);
			return;
		}
		xn.eval_stylesheet(r.stylesheet_links);
		jmodal.script_sections = r.script_sections;
		if(r.script_srcs.length > 0) {
			$.require(r.script_srcs, function() {
				xn.eval_script(r.script_sections, {jmodal: jmodal, callback: callback, arg: arg});
			});
		} else {
			xn.eval_script(r.script_sections, {jmodal: jmodal, callback: callback, arg: arg});
		}
	});
	return jmodal;
}

/**
 * 替代 Bootstrap 5 已移除的 jQuery button('loading'/'reset') API
 * loading：禁用按钮并在内容前插入 spinner；reset：恢复原状
 * 兼容传入 DOM 元素或 jQuery 对象
 * ponytail: 历史调用点 ~50 处统一走此函数，避免每页就地实现
 */
function setBtnLoading(btn, isLoading) {
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
}

$(function() {
	$('[data-modal-title]').each(function() {
		var jthis = $(this);
		jthis.on('click', function() {
			var url = jthis.data('modal-url') || jthis.attr('href');
			var title = jthis.data('modal-title');
			var arg = jthis.data('modal-arg');
			var callback_str = jthis.data('modal-callback');
			callback = window[callback_str];
			var size = jthis.data('modal-size');
			if(this.ajax_modal) {
				var oldBsModal = bootstrap.Modal.getInstance(this.ajax_modal[0]);
				if(oldBsModal) oldBsModal.hide();
				this.ajax_modal.remove();
			}
			this.ajax_modal = $.ajax_modal(url, title, size, callback, arg);
			return false;
		});
	});
});
