/**
 * 编辑器粘贴 Markdown 自动转 HTML 公共模块
 *
 * 使用场景：
 * - 核心发帖页 EditorService.renderEditorHtml()
 * - xnx_appcenter 应用介绍编辑器 appcenter_desc_editor.js
 *
 * 使用方式：
 *   window.setupEditorPasteMarkdown(container, getEditor, syncFn)
 * - container: 编辑器容器 DOM 元素（绑定 paste 事件）
 * - getEditor:  函数，返回 aiEditorInstance 实例
 * - syncFn:     函数，调用 syncEditorContent 同步隐藏字段
 *
 * 识别策略：
 * 1. 纯 Markdown 文本（无 text/html）→ insertMarkdown
 * 2. IDE/MD 编辑器代码块包装（<pre><code class="language-markdown">）→ insertMarkdown
 * 3. IDE 富文本样式（等宽字体 + 深色背景）+ Markdown 源码 → insertMarkdown
 * 4. 低比例 HTML（ratio < 2）+ Markdown 源码 → insertMarkdown
 * 5. 高比例 HTML / 非 Markdown → 放行给 AIEditor 内置 PasteExt
 *
 * ponytail: 通过多维度特征识别避免误转其他语言代码块和真富文本
 */
(function() {
    'use strict';

    if (window.setupEditorPasteMarkdown) return;

    function looksLikeMarkdown(str) {
        if (!str) return false;
        var patterns = [
            /^#{1,6}\s/m,           // 标题
            /^\s*[-*+]\s/m,         // 无序列表
            /^\s*\d+\.\s/m,         // 有序列表
            /^>\s/m,                // 引用
            /```/,                  // 代码块
            /`[^`]+`/,              // 行内代码
            /\*\*[^*]+\*\*/,        // 粗体
            /\[.+?\]\(.+?\)/,       // 链接
            /^---+$/m,              // 分割线
            /^\|.*\|/m              // 表格
        ];
        var matchCount = 0;
        for (var i = 0; i < patterns.length; i++) {
            if (patterns[i].test(str)) matchCount++;
        }
        // 标题是 Markdown 最强特征
        // ponytail: 要求 >=2 行 # 开头认定，避免 shell 单行注释 `# 注释` 误判
        var headingCount = (str.match(/^#{1,6}\s/gm) || []).length;
        if (headingCount >= 2) return true;
        return matchCount >= 2;
    }

    function isIdeStyledHtml(htmlStr) {
        if (!htmlStr) return false;
        // 等宽字体特征（IDE 代码编辑器常用）
        var monoFont = /font-family:[^;]*\b(JetBrains[ _]Mono|Menlo|Monaco|Courier[ _]New|Consolas|SF[ _]Mono|Fira[ _]Code|Source[ _]Code[ _]Pro)\b/i.test(htmlStr);
        // 深色背景色特征（#1xxxxx/#2xxxxx/#0xxxxx 等）
        var darkBg = /background-color:\s*#[0-2][0-9a-f]{5}/i.test(htmlStr);
        return monoFont && darkBg;
    }

    window.setupEditorPasteMarkdown = function(container, getEditor, syncFn) {
        if (!container || typeof getEditor !== 'function') return;

        container.addEventListener('paste', function(e) {
            var cd = e.clipboardData || window.clipboardData;
            if (!cd) return;

            // 图片/文件粘贴不拦截，让 AIEditor 内置逻辑处理上传
            var hasImage = false;
            if (cd.files && cd.files.length > 0) {
                for (var i = 0; i < cd.files.length; i++) {
                    if (cd.files[i].type.indexOf('image/') === 0) {
                        hasImage = true;
                        break;
                    }
                }
            }
            if (hasImage) {
                console.log('[Editor Paste] 检测到图片，放行给内置逻辑处理');
                return;
            }

            var text = cd.getData('text/plain');
            var html = cd.getData('text/html');
            var textLen = text ? text.length : 0;
            var htmlLen = html ? html.length : 0;
            var ratio = textLen > 0 ? htmlLen / textLen : 0;
            console.log('[Editor Paste] text/plain 长度:', textLen, 'text/html 长度:', htmlLen, '比例:', ratio.toFixed(2));

            // 没有纯文本时不拦截
            if (!text) {
                console.log('[Editor Paste] 无 text/plain，放行');
                return;
            }

            // 检测 markdown 代码块 HTML 包装（VSCode/Typora 复制 .md 文件场景）
            var isMarkdownCodeBlock = !!(html && /<code[^>]*class=["'][^"']*language-markdown/i.test(html));
            // 检测 IDE 富文本样式（Trae CN/VSCode 复制 markdown 文档场景）
            var isIdeMarkdownPaste = !!(html && isIdeStyledHtml(html) && looksLikeMarkdown(text));
            var isMarkdown = isMarkdownCodeBlock || isIdeMarkdownPaste || looksLikeMarkdown(text);

            var shouldConvert = false;
            if (isMarkdownCodeBlock) {
                shouldConvert = true;
                console.log('[Editor Paste] 检测到 markdown 代码块 HTML 包装，强制走 insertMarkdown');
            } else if (isIdeMarkdownPaste) {
                shouldConvert = true;
                console.log('[Editor Paste] 检测到 IDE 样式 HTML + Markdown 源码，强制走 insertMarkdown');
            } else if (!html) {
                if (isMarkdown) {
                    shouldConvert = true;
                    console.log('[Editor Paste] 仅 text/plain 且像 Markdown，走 insertMarkdown');
                } else {
                    console.log('[Editor Paste] 仅 text/plain 但非 Markdown，放行');
                }
            } else {
                if (ratio < 2 && isMarkdown) {
                    shouldConvert = true;
                    console.log('[Editor Paste] HTML 比例低 + 像 Markdown，走 insertMarkdown');
                } else {
                    console.log('[Editor Paste] HTML 比例高或非 Markdown，放行给内置逻辑处理');
                }
            }

            if (!shouldConvert) return;

            var editor = getEditor();
            if (!editor) {
                console.warn('[Editor Paste] editor 实例未就绪，放行');
                return;
            }

            // 走 Markdown 转换路径
            e.preventDefault();
            e.stopImmediatePropagation();

            try {
                editor.insertMarkdown(text);
                if (typeof syncFn === 'function') syncFn();
                console.log('[Editor Paste] 已通过 insertMarkdown 插入，长度:', text.length);
            } catch(err) {
                console.error('[Editor Paste] insertMarkdown 失败，回退到纯文本插入:', err);
                try {
                    editor.insert(text);
                    if (typeof syncFn === 'function') syncFn();
                } catch(e2) {
                    console.error('[Editor Paste] 回退插入也失败:', e2);
                }
            }
        }, true); // capture 阶段，优先级高于内置 PasteExt
    };
})();
