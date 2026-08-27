# UI 与样式规范速查

> 本文件为 UI/样式规范速查，详细说明见 [plugindev/14-plugin-admin-ui.md](manual/14-plugin-admin-ui.md) 第 3-4 节和 [plugindev/05-frontend-security.md](manual/05-frontend-security.md) 第 1 节

## 目录

- [1. 静态资源版本号规范](#1-静态资源版本号规范)
- [2. 前端布局规范](#2-前端布局规范)
- [3. Card 组件规范](#3-card-组件规范)
- [4. Toast vs Modal 场景区分](#4-toast-vs-modal-场景区分)
- [5. 视频与附件显示规范](#5-视频与附件显示规范)
- [6. 表单交互规范](#6-表单交互规范)
- [7. Tab 导航规范](#7-tab-导航规范)
- [8. 按钮样式规范](#8-按钮样式规范)
- [9. 个人签名与侧边栏](#9-个人签名与侧边栏)

---

## 1. 静态资源版本号规范

### 三种方式（按推荐程度排序）

| 方式 | 适用场景 | 代码示例 |
|---|---|---|
| **`filemtime()` 动态版本号** | 推荐，自动跟随文件修改时间 | `filemtime(APP_PATH.'plugin/xxx/static/js/app.js')` |
| **`$static_version`** | Hook 文件（`header_link_after.htm`/`footer_js_after.htm`） | `$static_version`（已在 `header.inc.htm` 第 47 行定义） |
| **`$conf['static_version']`** | 独立视图文件（`view/htm/*.htm`） | 直接取配置值 |

### 正确写法

```php
// 方式 A：filemtime() 动态版本号（最推荐）
<script src="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/js/app.js?v=<?php echo filemtime(APP_PATH.'plugin/my_plugin/static/js/app.js');?>"></script>
<link href="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/css/style.css?v=<?php echo filemtime(APP_PATH.'plugin/my_plugin/static/css/style.css');?>">

// 方式 B：Hook 文件中用 $static_version
<!-- 在 header_link_after.htm 中 -->
<link href="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/css/style.css<?php echo $static_version;?>">

// 方式 C：视图文件中用 $conf['static_version']
<!-- 在 view/htm/setting.htm 中 -->
<script src="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/js/app.js<?php echo $conf['static_version'];?>"></script>
```

### 禁止写法

```php
// ❌ 无版本号（浏览器缓存旧文件）
<script src="<?php echo $conf['view_url'];?>../plugin/my_plugin/static/js/app.js"></script>

// ❌ 硬编码版本号（修改后需手动维护）
<link href="...style.css?v=1.0.0">

// ❌ 用 APP_PATH（扫描器 fatal 拦截）
<script src="<?php echo APP_PATH;?>plugin/my_plugin/static/js/app.js">
```

---

## 2. 前端布局规范

### 三栏布局骨架

前台页面**必须**使用 `layout_three_column.inc.htm`，禁止自行写 `container`/`row`/`col-lg-*`。

```php
// 插件前台页面标准骨架
$main_content = include _include(APP_PATH.'plugin/my_plugin/view/htm/my_page.htm');
include _include(APP_PATH.'view/htm/layout_three_column.inc.htm');
```

### 不需左右栏时

```php
$sidebar_left_file = '';   // 禁用左栏
$sidebar_right_file = '';  // 禁用右栏
$main_content = '页面内容';
include _include(APP_PATH.'view/htm/layout_three_column.inc.htm');
```

### 后台模板

```php
// 后台模板
include _include(ADMIN_PATH.'view/htm/header.inc.htm');
// 页面内容
include _include(ADMIN_PATH.'view/htm/footer.inc.htm');
```

---

## 3. Card 组件规范

### 必须 x-card + card 组合

```html
<!-- ✅ 正确：x-card + card 组合 -->
<div class="x-card">
    <div class="card">
        <div class="card-body">内容</div>
    </div>
</div>

<!-- ❌ 禁止：裸用 card -->
<div class="card">
    <div class="card-body">内容</div>
</div>

<!-- ❌ 禁止：用 border 代替 card -->
<div class="border rounded p-3">内容</div>
```

### 列表分隔

```html
<div class="x-card">
    <div class="card">
        <div class="card-body">
            <div class="py-2 border-bottom">第一项</div>
            <div class="py-2 border-bottom">第二项</div>
            <div class="py-2">第三项</div>
        </div>
    </div>
</div>
```

### 固定高度卡片（插件列表用）

```html
<!-- 插件卡片固定高度 + 2 行描述占位 -->
<div class="x-card">
    <div class="card h-100">
        <div class="card-body">
            <h5 class="card-title">插件名</h5>
            <p class="card-text text-muted small" style="min-height: 2.5rem;">
                插件描述，即使为空也保留占位
            </p>
        </div>
    </div>
</div>
```

### 右侧栏插件模块 card header 规范（强制）

> **范本来源**：首页右侧栏「幸运抽奖」(`xnx_lottery`)、「热门话题」(`xnx_tag`)、「友情链接」(`xnx_friendlink`)。**所有**注入首页/详情页右侧栏、或插件独立页 `<div class="col-xl-3">` 右侧栏内的插件卡片必须照此对齐。

#### 强制格式

```html
<div class="x-card card mt-3">
    <div class="card-body">
        <h3 class="card-title small"><i class="ti ti-xxx"></i> 标题</h3>
    </div>
    <div class="card-body">
        <!-- 卡片正文 -->
    </div>
</div>
```

#### 关键约定

| 项 | 规范 | 反例 |
|---|---|---|
| 外层 class 顺序 | `x-card card mt-3`（x-card 在前） | `card x-card` |
| 标题容器 | **不用 `card-header`**，直接 `<div class="card-body">` 包 `<h3>` | `<div class="card-header bg-transparent border-0 pb-0">` |
| 标题标签 | `<h3 class="card-title small">` | `<h5 class="fw-bold">` / `<h6 class="fw-semibold">` |
| 标题字重 | 由 `card-title small` 控制，**不附加** `fw-bold`/`fw-semibold`/`mb-0` | `fw-bold` |
| 图标间距 | 图标后用 HTML 自然空格 + 文字，**不加** `me-1`/`me-2` | `me-2` |
| 图标着色 | 可选，写在 `<i>` 上（如 `text-warning`/`text-primary`） | 强制无色或单独包 span |
| 副标题 | 紧跟主标题，用 `<small class="text-muted ms-2">副标题</small>` 放在同一个 `<h3>` 内 | 单独成行 `<small>` |

#### 副标题处理

```html
<h3 class="card-title small">
    <i class="ti ti-trophy text-warning"></i> 盈亏排行榜
    <small class="text-muted ms-2">实时更新</small>
</h3>
```

#### 范本对照

| 插件 | 卡片 | 源文件 |
|---|---|---|
| `xnx_lottery` | 幸运抽奖 | `plugin/xnx_lottery/view/htm/lottery_sidebar.htm` |
| `xnx_tag` | 热门话题 | `plugin/xnx_tag/hook/index_site_brief_after.htm`（hook 内联） |
| `xnx_friendlink` | 友情链接 | `plugin/xnx_friendlink/hook/sidebar_friendlink_after.htm` |

#### 反例（已迁移至范本）

```html
<!-- ❌ 反例 A：card-header + h6 + fw-semibold + me-1（xnx_related 旧写法） -->
<div class="x-card card mt-3 mb-3">
    <div class="card-header bg-transparent border-bottom-0 py-2">
        <h6 class="mb-0 fw-semibold"><i class="ti ti-link me-1"></i>相关帖子</h6>
    </div>
    <div class="card-body">...</div>
</div>

<!-- ❌ 反例 B：card-header + h5 + fw-bold + me-2（xnx_checkin 旧写法） -->
<div class="card x-card" id="xo-mood-stats-card">
    <div class="card-header bg-transparent border-0 pb-0">
        <h5 class="mb-0 fw-bold">
            <i class="ti ti-mood-happy me-2 text-warning"></i>今日心情
        </h5>
    </div>
    <div class="card-body">...</div>
</div>

<!-- ❌ 反例 C：副标题单独成行（xnx_dice 旧写法） -->
<div class="card x-card mt-3">
    <div class="card-header bg-transparent border-0 pb-0">
        <h6 class="mb-0 fw-bold">
            <i class="ti ti-trophy me-2 text-warning"></i>盈亏排行榜
        </h6>
        <small class="text-muted" style="font-size:0.7em">实时更新</small>
    </div>
    <div class="card-body">...</div>
</div>
```

> 完整说明见 [plugindev/14-plugin-admin-ui.md#3.5 右侧栏插件模块 card header 规范](manual/14-plugin-admin-ui.md)

---

## 4. Toast vs Modal 场景区分

### 决策树

```
用户操作结果反馈？
├─ 成功/失败/信息/警告 → Toast
│   ├─ 保存成功 → XN.toast('保存成功', 'success')
│   ├─ 网络错误 → XN.toast('网络错误', 'danger')
│   ├─ 请先选择 → XN.toast('请先选择', 'warning')
│   └─ 已加载完成 → XN.toast('已加载完成', 'info')
│
└─ 需要用户确认/输入？ → Modal
    ├─ 删除/卸载/重置 → confirm Modal（XN.confirm）
    ├─ 长文本/重要提示 → alert Modal（XN.alert）
    └─ 需要输入文本 → prompt Modal（XN.prompt）
```

### 代码示例

```js
// Toast（轻提示，3 秒自动消失）
XN.toast('保存成功', 'success');
XN.toast('网络错误', 'danger');
XN.toast('余额不足', 'warning');
XN.toast('信息提示', 'info', 5000); // 自定义时长

// Confirm Modal（需用户确认）
XN.confirm('确定要删除吗？删除后不可恢复。').then(function() {
    // 执行删除
});

// Alert Modal（重要提示）
XN.alert('这是一个重要的提示内容，可以包含较长的文本。', {
    type: 'danger',
    title: '错误提示'
});

// Prompt Modal（需用户输入）
XN.prompt('请输入新名称：', '当前名称').then(function(name) {
    if (name) { /* 保存 */ }
});
```

### 禁止使用

```js
// ❌ 禁止使用原生 alert/confirm/prompt
alert('提示内容');
confirm('确定吗？');
prompt('请输入');
```

---

## 5. 视频与附件显示规范

### 视频内联播放

```html
<!-- ✅ 视频作为内联播放器显示在正文位置 -->
<video controls class="w-100 rounded">
    <source src="<?php echo esc_attr($video_url);?>" type="video/mp4">
    您的浏览器不支持视频播放
</video>

<!-- ❌ 禁止：视频仅出现在附件列表中 -->
<!-- ❌ 禁止：视频显示下载链接 -->
```

### 附件列表过滤

```php
// 附件列表仅显示图片、文档等非视频附件
$allow_video_in_list = false; // 视频通过内联播放

// 前端模板中过滤
<?php foreach ($attachments as $att): ?>
    <?php if ($att['type'] === 'video') continue; // 跳过视频 ?>
    <div class="attachment-item">...</div>
<?php endforeach; ?>
```

---

## 6. 表单交互规范

### 提交时禁用按钮

```html
<form id="myForm" method="post">
    <?php echo CsrfService::input();?>
    <button type="submit" id="submitBtn" class="btn btn-primary">保存</button>
</form>
```

```js
// 原生 JS 方式
document.getElementById('myForm').addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = '保存中...';
    // 提交完成后恢复
    // btn.disabled = false;
    // btn.textContent = '保存';
});
```

### htmx 方式

```html
<form hx-post="<?php echo url('plugin-setting-xxx');?>" hx-target="#result"
      hx-on::before-request="document.getElementById('submitBtn').disabled = true"
      hx-on::after-request="document.getElementById('submitBtn').disabled = false">
    <button type="submit" id="submitBtn" class="btn btn-primary">保存</button>
</form>
```

---

## 7. Tab 导航规范

### 每个 Tab 加图标

```html
<div class="nav nav-tabs">
    <a class="nav-link active" href="..."><i class="ti ti-settings"></i> 设置</a>
    <a class="nav-link" href="..."><i class="ti ti-list-details"></i> 记录</a>
    <a class="nav-link" href="..."><i class="ti ti-shield"></i> 安全</a>
</div>
```

### 单个 Tab 可展开/折叠

```html
<div class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#tabContent">
        <i class="ti ti-chevron-down"></i> 展开/折叠
    </a>
    <div class="collapse" id="tabContent">
        <!-- 子内容 -->
    </div>
</div>
```

### 独立 URL Tab

```php
// 每个 Tab 是独立 URL，不是 DOM 切换
$tabs = array(
    'setting' => array('url' => url('plugin-setting-xnx_xxx-setting'), 'text' => lang('setting')),
    'records' => array('url' => url('plugin-setting-xnx_xxx-records'), 'text' => lang('records')),
);
echo admin_tab_active($tabs, $sub_action);
```

---

## 8. 按钮样式规范

### 禁止 `w-100` 类

```html
<!-- ❌ 禁止 -->
<button class="btn btn-primary w-100">保存</button>

<!-- ✅ 正确：用合适的宽度 -->
<button class="btn btn-primary">保存</button>
```

### 按钮样式分级

```html
<!-- 主要操作 -->
<button class="btn btn-primary">保存</button>

<!-- 次要操作 -->
<button class="btn btn-secondary">取消</button>

<!-- 危险操作 -->
<button class="btn btn-danger">删除</button>

<!-- 链接样式 -->
<a href="..." class="btn btn-link">查看详情</a>
```

### 表单内按钮间距

```html
<div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">保存</button>
    <a href="..." class="btn btn-outline-secondary">取消</a>
</div>
```

---

## 9. 个人签名与侧边栏

### 个人签名位置

```html
<!-- 个人签名放在统计信息上方，浅色背景 -->
<div class="profile-signature bg-light rounded p-3 mb-3">
    <?php echo $user['signature'];?>
</div>

<!-- 统计信息在签名下方 -->
<div class="profile-stats">
    <span>帖子：<?php echo $thread_count;?></span>
    <span>积分：<?php echo $credits;?></span>
</div>
```

### 右侧边栏布局

```
右侧边栏
├─ 帖子目录（替代"最新帖子"区域）
├─ 热门帖子
└─ 友情链接
```

---

## 参考文档

| 文档 | 内容 |
|---|---|
| [plugindev/14-plugin-admin-ui.md](manual/14-plugin-admin-ui.md) | 完整 UI 规范总览 |
| [references/frontend-patterns.md](frontend-patterns.md) | 前端模式速查 |
| [references/admin-patterns.md](admin-patterns.md) | 后台 UI 模式速查 |
| [references/ai-rules.md](ai-rules.md) | AI 协作规则速查 |
