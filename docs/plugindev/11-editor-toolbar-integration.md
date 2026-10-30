# 11 编辑器工具栏按钮集成教程

> 适用场景：插件需要在 AIEditor 富文本编辑器工具栏添加自定义按钮（如「隐藏内容」「插入投票」「插入附件」等）。
> 核心机制：通过 `editor_custom_btns_end.php` hook 注入按钮配置，**插件禁用/卸载后按钮自动消失**，核心代码无需感知插件存在。

---

## 一、设计理念

### 旧方式（已废弃）

旧版核心代码在 `lib/EditorService.php` 中硬编码插件名判断：

```php
// ❌ 已废弃：核心代码硬编码插件路径
$hiddenEnabled = false;
$_enabled = plugin_paths_enabled();
if (isset($_enabled[APP_PATH . 'plugin/xnx_hidden'])) {
    $hiddenEnabled = true;
}
if ($hiddenEnabled) {
    // 直接写按钮 HTML/JS
}
```

**问题：**
- 核心代码耦合插件名，插件卸载后核心代码残留判断逻辑
- 核心代码替插件读 conf.json 判断启用状态，职责错位
- 新插件要加按钮必须改核心代码
- 插件禁用后按钮 JS 仍残留，悬停提示显示 `lang[xxx]` 裸 key

### 新方式（推荐）

核心代码提供 `editor_custom_btns_end.php` hook 点，通过 `plugin_hook()` 收集插件注入的按钮配置：

```php
// ✅ 新方式：核心只提供 hook 点，插件自己注入按钮
$customBtns = array();
// 核心按钮（如引用话题）直接加入 $customBtns
$customBtns[] = array('btn_var' => 'refThreadBtn', ...);
// 插件 hook 注入按钮
if (function_exists('plugin_hook')) {
    plugin_hook('editor_custom_btns_end.php', $customBtns);
}
```

**优势：**
- 核心代码零插件感知，只依赖 hook 点名称
- 插件禁用/卸载后 hook 自动不执行，按钮和 JS 一并消失
- 任何插件都能通过 hook 加按钮，无需改核心代码
- 职责清晰：核心管渲染，插件管配置

---

## 二、Hook 点说明

### Hook 基本信息

| 属性 | 值 |
|---|---|
| Hook 文件名 | `editor_custom_btns_end.php` |
| 触发位置 | `lib/EditorService.php` 的 `renderEditorHtml()` 方法内，AIEditor `toolbarKeys` 配置组装时 |
| 触发时机 | 发帖页 / 编辑主帖页 / 高级回复页 / 快速回复（thread.htm 的 quick reply）等所有渲染编辑器的页面 |
| 数据传递 | 通过 `plugin_hook($name, &$data)` 第二参数传 `$customBtns` 数组引用，hook 内 `$data[] = ...` 追加按钮 |
| 执行频率 | 每次渲染编辑器执行一次（页面加载时） |

### Hook 作用域

`plugin_hook` 是独立函数，hook 代码在其自身函数作用域执行，**无法访问调用方局部变量**。所有需要的数据通过第二参数 `$data` 传入。hook 内可用变量：

- `$data`：按钮配置数组（引用传递，追加元素即可）
- `$isfirst`：是否首帖（来自 `global $isfirst`，核心在 hook 前已 `global` 声明）
- `lang()`、`esc_html()` 等全局函数

---

## 三、按钮配置结构

每个按钮是 `$data` 数组的一个元素，结构如下：

```php
$data[] = array(
    'btn_var'    => 'myBtn',        // 必填：JS 变量名，用于 toolbarKeys 数组引用
    'js_def'     => "var myBtn = { ... };",  // 必填：JS 变量定义语句
    'first_only' => true,           // 可选：true 表示只在主帖页显示（非回复页）
);
```

### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `btn_var` | string | 是 | JS 变量名，必须与 `js_def` 中 `var xxx =` 一致，核心会把它加入 `toolbarKeys` 数组 |
| `js_def` | string | 是 | 完整的 JS 变量定义语句（含 `var` 和 `;`），内容是 AIEditor CustomMenu 对象 |
| `first_only` | bool | 否 | `true` = 仅主帖页显示（如隐藏内容只用于主帖）；`false`/不设 = 所有编辑器都显示 |

### CustomMenu 对象字段

`js_def` 中定义的 JS 对象是 AIEditor 的 CustomMenu，支持以下字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `icon` | string | SVG 字符串（**推荐 fill 模式**，详见下方 SVG 规范） |
| `html` | string | HTML 字符串（设置后整体覆盖 `icon`） |
| `onClick` | function(event, editor) | 点击回调，`editor` 是 AIEditor 实例 |
| `tip` | string | 鼠标悬停 tooltip 文字 |
| `name` | string | 按钮唯一标识（用于 AIEditor 内部查找） |

---

## 四、完整示例：插入自定义按钮

下面以「隐藏内容」按钮为例，演示完整集成流程。参考实现：`plugin/xnx_hidden/`。

### Step 1：创建 hook 文件

文件路径：`plugin/<你的插件>/hook/editor_custom_btns_end.php`

```php
<?php exit;
// 通过 editor_custom_btns_end hook 注入「我的按钮」到编辑器工具栏
// ponytail: 插件禁用/卸载后 hook 不执行，按钮自动消失，无需核心代码判断插件启用状态
// 依赖：post_end.htm 注入 Modal HTML + post_js.htm 定义 openMyModal() 函数

// plugin_hook 第二参数 $data 是按钮配置数组引用，追加元素即可
if (!isset($data) || !is_array($data)) $data = array();

// 语言包（必须在 hook/lang_zh_cn_bbs.php 中注册对应键）
$_my_tip = addslashes(lang('my_plugin_btn_tip'));
$_my_missing_tip = addslashes(lang('my_plugin_modal_missing_tip'));

// SVG 图标（fill 模式，path 不带 fill="none"）
// 推荐：从 Remix Icon (https://remixicon.com/) 或 Tabler Icons 复制 SVG path
$_my_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 12h3v8h6v-6h2v6h6v-8h3L12 2z"></path></svg>';

// 按钮配置
$data[] = array(
    'btn_var'    => 'myContentBtn',
    'first_only' => true,  // 仅主帖页显示
    'js_def'     => "var myContentBtn = { icon: '" . $_my_icon . "', onClick: function(event, editor) { if (editor) editor.focus(); if (typeof openMyModal === 'function') { openMyModal(); } else if (typeof XN !== 'undefined' && XN.toast) { XN.toast('" . $_my_missing_tip . "', 'warning'); } }, tip: '" . $_my_tip . "' };",
);
```

**关键点：**
- 文件以 `<?php exit;` 开头（防直接访问，编译器剥掉 exit）
- `$data` 是 `plugin_hook` 传入的引用，直接 `$data[] = ...` 追加
- 语言包用 `lang()` 读取，必须先在 `hook/lang_zh_cn_bbs.php` 注册
- SVG 用 `fill="currentColor"` 模式（详见下方 SVG 规范）
- `js_def` 中的 JS 变量名必须与 `btn_var` 完全一致

### Step 2：注册 hook 到 conf.json

在 `conf.json` 的 `hooks_rank` 中注册：

```json
{
    "hooks_rank": {
        "editor_custom_btns_end.php": 1,
        "post_end.htm": 1,
        "post_js.htm": 1
    }
}
```

**键名必须与 hook 文件名完全一致**（含 `.php` 扩展名）。

### Step 3：提供 Modal HTML 和 JS 函数

按钮点击后通常需要弹 Modal 让用户配置内容。Modal HTML 通过 `post_end.htm` hook 注入，JS 函数通过 `post_js.htm` hook 注入。

#### `hook/post_end.htm`（Modal HTML）

```php
<?php
// 仅发帖页加载（post.htm 同时用于发帖和回复，按场景区分）
if ($route == 'thread' && $action == 'create') {
?>
<div class="modal fade" id="myContentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo lang('my_plugin_modal_title');?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- 表单内容 -->
                <div class="mb-3">
                    <label class="form-label"><?php echo lang('my_plugin_content_label');?></label>
                    <textarea name="my_content" id="myContentInput" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo lang('my_plugin_cancel');?></button>
                <button type="button" class="btn btn-primary" id="myContentInsertBtn"><?php echo lang('my_plugin_insert');?></button>
            </div>
        </div>
    </div>
</div>
<?php
}
?>
```

#### `hook/post_js.htm`（JS 函数 + 插入逻辑）

```php
<?php
// 仅发帖页加载
if ($route == 'thread' && $action == 'create') {
?>
<script>
// 打开 Modal（被 editor_custom_btns_end 注入的按钮调用）
function openMyModal() {
    var modalEl = document.getElementById('myContentModal');
    if (!modalEl) {
        // Modal 未加载，提示用户
        if (typeof XN !== 'undefined' && XN.toast) {
            XN.toast('<?php echo addslashes(lang('my_plugin_modal_missing_tip'));?>', 'warning');
        }
        return;
    }
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

// 插入按钮点击：把表单内容插入编辑器
document.addEventListener('DOMContentLoaded', function() {
    var insertBtn = document.getElementById('myContentInsertBtn');
    if (!insertBtn) return;
    insertBtn.addEventListener('click', function() {
        var input = document.getElementById('myContentInput');
        if (!input || !input.value.trim()) {
            if (typeof XN !== 'undefined' && XN.toast) {
                XN.toast('<?php echo addslashes(lang('my_plugin_empty_tip'));?>', 'warning');
            }
            return;
        }
        // 插入 HTML 到编辑器（AIEditor 实例存在 window.aiEditorInstance）
        var editor = window.aiEditorInstance;
        if (editor) {
            editor.focus();
            // 构造带 data-* 属性的自定义 HTML 标签，便于后端解析
            var html = '<div class="my-hidden-block" data-type="my_content" data-params="' + encodeURIComponent(input.value) + '">' + input.value + '</div>';
            editor.insert(html);
        }
        // 关闭 Modal 并清空输入
        var modalEl = document.getElementById('myContentModal');
        var modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
        input.value = '';
        if (typeof XN !== 'undefined' && XN.toast) {
            XN.toast('<?php echo addslashes(lang('my_plugin_inserted'));?>', 'success');
        }
    });
});
</script>
<?php
}
?>
```

### Step 4：注册语言包

在 `hook/lang_zh_cn_bbs.php` 中注册所有语言键：

```php
<?php
$lang['my_plugin_btn_tip'] = '插入自定义内容';
$lang['my_plugin_modal_missing_tip'] = 'Modal 未加载，请刷新页面';
$lang['my_plugin_modal_title'] = '插入自定义内容';
$lang['my_plugin_content_label'] = '内容';
$lang['my_plugin_cancel'] = '取消';
$lang['my_plugin_insert'] = '插入';
$lang['my_plugin_empty_tip'] = '请输入内容';
$lang['my_plugin_inserted'] = '已插入';
```

同步写入 `hook/lang_zh_tw_bbs.php` 和 `hook/lang_en_us_bbs.php`。

### Step 5：清缓存测试

修改完成后：
- `EditorService.php` 通过 `include` 直接加载，**无需清 `tmp/` 编译缓存**
- hook 文件通过 `plugin_hook()` 运行时 eval 执行，也**无需清 `tmp/`**
- 但**插件首次安装/启用后**必须清 OPcache（核心 `plugin_enable()` 已自动调用 `CacheService::clearByType(['data', 'opcache'])`）
- 启用 OPcache 时，修改 hook 文件后可能需要重启 PHP-FPM 或等 OPcache 自动刷新

**测试点：**
1. 启用插件 → 发新帖页编辑器工具栏出现自定义按钮
2. 悬停按钮 → 显示正确的 tooltip（不是 `lang[xxx]` 裸 key）
3. 点击按钮 → 弹出 Modal
4. 输入内容点插入 → 内容插入到编辑器
5. 禁用插件 → 按钮自动消失
6. 卸载插件 → 同上，无残留 JS/CSS

---

## 五、SVG 图标规范（关键）

AIEditor 的 CSS 会**强制覆盖 SVG 的 fill 属性**：

```css
.aie-container aie-header .aie-menu-item svg {
    fill: var(--aie-menus-svg-color);
    width: 16px;
    height: 16px;
}
```

### ✅ 推荐：fill 模式 SVG

`<path>` 不带 `fill="none"`，path 自带填充语义，被 CSS 覆盖 fill 后仍正常显示。

```html
<!-- ✅ fill 模式：path 填充 -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
    <path d="M12 2L2 12h3v8h6v-6h2v6h6v-8h3L12 2z"></path>
</svg>
```

### ❌ 禁用：stroke 模式 SVG

`fill="none" stroke="currentColor"` 的 SVG 被 CSS 强制设置 fill 后，会变成实心色块导致图标完全不可见。

```html
<!-- ❌ stroke 模式：会被 CSS fill 覆盖成实心块 -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor">
    <path d="M12 2L2 12h3v8h6v-6h2v6h6v-8h3L12 2z" stroke-width="2"></path>
</svg>
```

### 图标来源

- **Remix Icon**：https://remixicon.com/ （推荐，fill 模式为主）
- **Tabler Icons**：https://tabler.io/icons （部分是 stroke 模式，需筛选）
- **Heroicons**：https://heroicons.com/ （solid 版本是 fill 模式）

复制 SVG 时**只取 `<path>` 标签的 `d` 属性**，套用 fill 模式模板：

```html
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
    <path d="这里贴 path 的 d 属性"></path>
</svg>
```

---

## 六、场景区分（仅主帖 vs 所有页面）

`post.htm` 模板同时用于**发帖页**（`thread-create`）和**高级回复页**（`post-create`）。部分功能（如隐藏内容、投票、抽奖）只用于主帖，不应在回复页加载。

### 区分方式

#### 方式 1：按钮配置层区分（推荐）

在 `editor_custom_btns_end.php` hook 中设置 `first_only => true`：

```php
$data[] = array(
    'btn_var'    => 'myContentBtn',
    'first_only' => true,  // 核心会在非首帖页跳过此按钮
    'js_def'     => "var myContentBtn = { ... };",
);
```

核心 `EditorService.php` 会检查 `$isfirst` 全局变量，非首帖页时跳过 `first_only=true` 的按钮。

#### 方式 2：Modal/JS 层区分

`post_end.htm` 和 `post_js.htm` hook 内部判断场景：

```php
<?php
if ($route == 'thread' && $action == 'create') {
    // 仅发帖页加载 Modal 和 JS
?>
<!-- Modal HTML / JS 代码 -->
<?php
}
?>
```

> ⚠️ 编辑主帖页也需加载 Modal，完整判断条件见 `plugin/xnx_hidden/hook/post_end.htm:5`，示例：`if (($route == 'thread' && $action == 'create') || ($route == 'post' && $action == 'update' && !empty($isfirst)))`

**最佳实践：两种方式配合使用。** `first_only => true` 让按钮不在回复页显示，`post_end.htm`/`post_js.htm` 的场景判断让 Modal 和 JS 也不在回复页加载，双重保险。

---

## 七、z-index 层级说明

AIEditor 工具栏弹层（颜色选择器、下拉菜单等）和全屏模式默认 `z-index: 9999`，会覆盖 Bootstrap Modal（`z-index: 1055`）。核心已在 `EditorService.php` 输出的 `<style>` 中统一降级：

```css
/* tippy 弹层降到 1020 */
.tippy-box {z-index:1020 !important;}
/* 全屏模式 aie-container 降到 1020 */
.aie-container[style*="position: fixed"][style*="z-index: 9999"] {z-index:1020 !important;}
```

**插件无需处理 z-index 问题**，只要 Modal 用标准的 Bootstrap 5 `<div class="modal fade">` 结构即可正常显示在编辑器之上。

---

## 八、常见问题排查

### 问题 1：按钮不显示

**排查步骤：**

1. **检查 hook 文件是否注册**：`conf.json` 的 `hooks_rank` 是否有 `editor_custom_btns_end.php` 键
2. **检查 hook 文件名**：必须与 `hooks_rank` 键名完全一致（含 `.php`）
3. **检查插件是否启用**：后台插件列表确认插件状态为「已启用」
4. **清 OPcache**：`CacheService::clearByType(['data', 'opcache'])` 或重启 PHP-FPM
5. **检查 `$data` 变量**：hook 内必须用 `$data`（不是 `$customBtns`），`plugin_hook` 第二参数变量名固定为 `$data`
6. **检查 `plugin_hook` 调用**：核心调用是 `plugin_hook('editor_custom_btns_end.php', $customBtns)`，hook 内部 `plugin_hook` 函数把第二参数赋给 `$data`

### 问题 2：tooltip 显示 `lang[xxx]` 裸 key

**原因：** 语言键未注册或未同步到编译缓存。

**修复：**
1. 在 `hook/lang_zh_cn_bbs.php` 注册对应语言键
2. 同步写入 `hook/lang_zh_tw_bbs.php` 和 `hook/lang_en_us_bbs.php`
3. 清 `tmp/lang_*_bbs.php` 编译缓存

### 问题 3：点击按钮报 `openXxxModal is not defined`

**原因：** Modal 的 JS 函数未加载。

**排查：**
1. 检查 `post_js.htm` hook 是否注册并正确输出 `<script>`
2. 检查 `post_js.htm` 内的场景判断是否过滤掉了当前页面（如 `if ($route == 'thread' && $action == 'create')` 在回复页会跳过）
3. 检查浏览器控制台是否有 JS 语法错误

### 问题 4：SVG 图标不可见（实心色块）

**原因：** SVG 用了 stroke 模式，被 CSS `fill` 覆盖。

**修复：** 改用 fill 模式 SVG，详见 [SVG 图标规范](#五svg-图标规范关键)。

### 问题 5：按钮在回复页也显示

**原因：** 未设置 `first_only => true`，或 `post_end.htm`/`post_js.htm` 未按场景区分。

**修复：**
1. hook 配置加 `'first_only' => true`
2. `post_end.htm` 和 `post_js.htm` 内加 `if ($route == 'thread' && $action == 'create')` 判断

### 问题 6：Modal 被工具栏弹层覆盖

**原因：** 站点自定义 CSS 覆盖了核心的 z-index 降级规则。

**修复：** 检查站点主题 CSS 是否有 `.tippy-box` 或 `.aie-container` 的 `z-index` 覆盖，移除或调整为 `1020`。

---

## 九、完整参考实现

### 核心代码（`lib/EditorService.php`）

核心提供 hook 点，位于 `renderEditorHtml()` 方法内：

```php
// 自定义按钮注入点：核心提供 refThreadBtn，插件通过 editor_custom_btns_end hook 追加
$customBtns = array();
if ($isFirstPost) {
    // 核心按钮：引用话题
    $customBtns[] = array(
        'btn_var'    => 'refThreadBtn',
        'first_only' => true,
        'js_def'     => "var refThreadBtn = { ... };",
    );
}
// 插件 hook 注入按钮
if (function_exists('plugin_hook')) {
    plugin_hook('editor_custom_btns_end.php', $customBtns);
}
// 提取变量名和 JS 定义，注入到 heredoc
$customBtnVars = array();
$customBtnJsDefs = '';
foreach ($customBtns as $btn) {
    if (!empty($btn['first_only']) && !$isFirstPost) continue;
    if (empty($btn['btn_var']) || empty($btn['js_def'])) continue;
    $customBtnVars[] = $btn['btn_var'];
    $customBtnJsDefs .= $btn['js_def'] . "\n";
}
$firstPostBtnsJson = '[' . implode(',', $customBtnVars) . ']';
```

### 插件参考实现

| 插件 | 实现功能 | 参考文件 |
|---|---|---|
| xnx_hidden | 隐藏内容按钮 | `plugin/xnx_hidden/hook/editor_custom_btns_end.php` |

---

## 十、新旧方式迁移指南

如果你的插件原来通过修改 `lib/EditorService.php` 硬编码加按钮，按以下步骤迁移到 hook 机制：

1. **创建 hook 文件**：`plugin/<你的插件>/hook/editor_custom_btns_end.php`
2. **把按钮配置移到 hook**：原 `EditorService.php` 中的按钮定义代码移到 hook 文件，用 `$data[] = ...` 追加
3. **移除核心代码中的插件判断**：删除 `EditorService.php` 中读 conf.json / 判断插件启用的代码
4. **注册 hook 到 conf.json**：`hooks_rank` 加 `"editor_custom_btns_end.php": 1`
5. **测试**：禁用插件 → 按钮消失；启用插件 → 按钮恢复

### 迁移检查清单

- [ ] hook 文件以 `<?php exit;` 开头
- [ ] hook 内用 `$data` 变量（不是 `$customBtns`）
- [ ] `btn_var` 与 `js_def` 中 JS 变量名一致
- [ ] `conf.json` 的 `hooks_rank` 键名含 `.php` 扩展名
- [ ] 语言键已注册到 `hook/lang_zh_cn_bbs.php`
- [ ] SVG 用 fill 模式
- [ ] 仅主帖场景设置 `first_only => true`
- [ ] Modal HTML 通过 `post_end.htm` 注入（非直接改 `post.htm`）
- [ ] JS 函数通过 `post_js.htm` 注入（非直接改 `post.htm`）
- [ ] 核心代码中无插件名硬编码残留

---

## 十一、最佳实践总结

1. **核心代码零插件感知**：核心只提供 hook 点，不判断插件启用状态
2. **插件自包含**：按钮配置、Modal、JS、语言包都在插件目录内
3. **场景区分双重保险**：`first_only` + `post_end.htm`/`post_js.htm` 内的 `$route` 判断
4. **SVG 用 fill 模式**：避免被 CSS 覆盖成实心块
5. **Modal 复用 Bootstrap 5**：不新建独立 Modal 系统
6. **z-index 由核心统一管理**：插件无需处理
7. **语言键同步三套**：zh-cn / zh-tw / en-us
8. **hook 文件用 `$data` 变量**：`plugin_hook` 第二参数固定变量名

---

> **相关文档：**
> - [03-hooks-catalog.md](03-hooks-catalog.md) - Hook 点全量目录
> - [05-frontend-security.md](05-frontend-security.md) - 前端安全与 htmx 4 事件名
> - [plugindev/README.md](README.md) - 完整手册导航
