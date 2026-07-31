# 14. 插件后台与 UI 规范

> 插件后台入口模式、Tab 独立页面、Bootstrap 5 组件、x-card Card 规范、三栏布局骨架、GET 搜索/分页 URL 拼接坑点、命名快捷函数、弹窗规范的真实示例总览。速查版见 [../xiunox-plugin-dev/references/admin-patterns.md](../xiunox-plugin-dev/references/admin-patterns.md)。

---

## 快速导航

| 章节 | 主题 | 关键源码/示例 |
|---|---|---|
| [1. 技术栈与加载顺序](#1-技术栈与加载顺序) | Bootstrap 5.3 / Tabler Icons / htmx 4 / 原生 JS | `admin/view/htm/header.inc.htm` |
| [2. 后台 Bootstrap 5 组件规范](#2-后台-bootstrap-5-组件规范) | 表单/按钮/徽章 + 何时用何种组件速查表 | — |
| [3. x-card + card 组合规范](#3-x-card--card-组合规范) | Card 组件强制规范 + 列表分隔 | `plugin/xnx_checkin/view/htm/checkin_setting.htm` |
| [4. 前台三栏布局骨架](#4-前台三栏布局骨架-layout_three_columninchtm) | `layout_three_column.inc.htm` 变量表 + 用法 | `view/htm/layout_three_column.inc.htm` |
| [5. Tab 独立页面模式](#5-tab-独立页面模式) | `admin_tab_active()` + `param(3)` 分发 | `plugin/xnx_checkin/setting.php` |
| [6. 插件后台入口模式](#6-插件后台入口模式) | setting.php 嵌入式 vs admin.php 独立入口 | `xnx_checkin` / `xnx_friendlink` |
| [7. 后台菜单/侧边栏注册](#7-后台菜单侧边栏注册) | 一级菜单不可扩展 + 侧边栏 hook | `admin/view/htm/sidebar.inc.htm` |
| [8. 后台 GET 搜索表单 JS 拦截](#8-后台-get-搜索表单-js-拦截) | 路由参数不丢失的正向规范 | `admin/view/htm/plugin_list.htm` |
| [9. 后台分页 URL 手动拼接](#9-后台分页-url-手动拼接) | `{page}` 占位符不被编码 | `plugin/xnx_checkin/setting.php` |
| [10. 命名快捷函数完整列表](#10-命名快捷函数完整列表) | `admin_url` / `admin_plugin_*` / `frontend_*` | `model/route.func.php` |
| [11. 弹窗规范](#11-弹窗规范) | toast vs Modal 场景区分 | `view/js/xiuno-modern.js` |
| [12. 真实示例对照](#12-真实示例对照) | xnx_checkin / xnx_friendlink 规范点对照表 | — |

---

## 1. 技术栈与加载顺序

### 1.1 技术栈总览

| 端 | UI 框架 | 图标 | 交互 | JS 依赖 |
|---|---|---|---|---|
| 前台 | Bootstrap 5.3+（CDN） | Tabler Icons | **htmx 4**（`hx-get`/`hx-post`/`hx-target`/`hx-optimistic`） | `XN.*` API（`view/js/xiuno-modern.js`） |
| 后台 | Bootstrap 5.3+（CDN） | Tabler Icons | **原生 JS**（`fetch`/`querySelectorAll`/`addEventListener`） | Bootstrap 5 原生组件（`bootstrap.Modal`/`bootstrap.Toast`） |

> ⚠️ **后台（`admin/`）禁用 htmx**：后台是「最后手段」页面（在线升级/数据库升级/后台登录/插件管理），不能依赖前台 `xiuno-modern.js` 和 htmx，避免「网站坏 → 后台也坏」的死循环。后台只用原生 JS + Bootstrap。

> ⚠️ **禁止 jQuery / Alpine.js / idiomorph**：已于 2026-07-24 系统性移除 jQuery，所有页面禁止 `$`/`jQuery`/`$.fn.*`、`x-data`/`x-show`/`x-bind`/`x-model`、idiomorph/alpine-morph 扩展。迁移指南见 [10-jquery-removal-guide.md](10-jquery-removal-guide.md)。

### 1.2 后台静态资源加载顺序

源码：`admin/view/htm/header.inc.htm`。后台 header 中依次加载：

```
1. ../view/vendor/bootstrap/css/bootstrap.min.css   ← Bootstrap 5.3+
2. ../view/css/theme.css?v                          ← 主题色
3. view/css/admin.css?v                             ← 后台专属样式
4. ../view/vendor/tabler-icons/tabler-icons.min.css ← Tabler Icons 线性版
5. ../view/vendor/tabler-icons/tabler-icons-filled.min.css ← Tabler Icons 填充版
```

对应源码片段（`admin/view/htm/header.inc.htm`）：

```html
<link href="../view/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../view/css/theme.css<?php echo $static_version;?>">
<link rel="stylesheet" href="view/css/admin.css<?php echo $static_version;?>">
<link rel="stylesheet" href="../view/vendor/tabler-icons/tabler-icons.min.css">
<link rel="stylesheet" href="../view/vendor/tabler-icons/tabler-icons-filled.min.css">
```

> 插件 CSS 注入点：`<!--{hook admin_header_css_after.htm}-->`（在 Tabler Icons 之后）。

### 1.3 前台静态资源加载顺序

源码：`view/htm/header.inc.htm` + `view/htm/footer.inc.htm`。详见 [05-frontend-security.md](05-frontend-security.md) 第 1 节，简表如下：

```
前台 CSS：bootstrap.min.css → bootstrap-bbs.css → theme.css → tabler-icons.min.css → <!--{hook header_link_after.htm}-->
前台 JS ：htmx.min.js → hx-live.min.js → hx-optimistic.min.js → anime.js → bootstrap.bundle.min.js → xiuno-modern.js → bbs.js → <!--{hook footer_js_after.htm}--> → auto-save.js → lightbox.js
```

### 1.4 插件静态资源引用方式

插件 JS 放 `plugin/<dir>/static/js/`，CSS 放 `plugin/<dir>/static/css/`。引用时**必须用 `$conf['view_url']` 拼接相对路径**，禁止用 `APP_PATH` 绝对路径或裸相对路径：

```php
// ✅ 正确：$conf['view_url'] 拼接相对路径
<script src="<?php echo $conf['view_url'];?>../plugin/xnx_checkin/static/js/checkin.js<?php echo $static_version;?>"></script>

// ❌ 错误：APP_PATH 绝对路径
<script src="<?php echo APP_PATH;?>plugin/xnx_checkin/static/js/checkin.js"></script>
```

> 改 `static/*.js`/`*.css` 后必须递增 `conf/conf.php` 的 `static_version`，否则浏览器命中旧缓存。

---

## 2. 后台 Bootstrap 5 组件规范

后台基于 Bootstrap 5.3+，所有组件 class 严格按 Bootstrap 规范使用，禁止自定义 class 替代等价 Bootstrap class。

### 2.1 表单组件

| 组件 | class | 用途 |
|---|---|---|
| 文本/数字输入 | `form-control` | `<input type="text/number/date">` |
| 下拉选择 | `form-select` | `<select>` |
| 单选/复选 | `form-check` + `form-check-input` + `form-check-label` | 单个开关选项 |
| 开关 | `form-check form-switch` + `form-check-input` | 布尔开关（启用/禁用） |
| 输入组 | `input-group` + `input-group-text` | 图标前缀 + 输入框 |
| 提示文字 | `form-text` | 字段下方灰色说明 |
| 标签 | `form-label` | 字段标题 |

真实示例（`plugin/xnx_checkin/view/htm/checkin_setting.htm`）：

```html
<div class="mb-3">
    <label class="form-label"><?php echo lang('xnx_checkin_reward_type_label');?></label>
    <select class="form-select" name="reward_credits_type">
        <option value="credits">积分</option>
    </select>
    <div class="form-text"><?php echo lang('xnx_checkin_reward_type_tip');?></div>
</div>

<div class="form-check form-switch">
    <input type="hidden" name="discover_enabled" value="0">
    <input class="form-check-input" type="checkbox" name="discover_enabled" id="discoverEnabled" value="1" <?php echo !empty($discover_config['enabled']) ? 'checked' : '';?>>
    <label class="form-check-label" for="discoverEnabled">显示在发现页</label>
</div>

<div class="input-group input-group-sm">
    <span class="input-group-text p-0 px-2"><i class="ti ti-compass"></i></span>
    <input type="text" class="form-control form-control-sm" name="discover_icon" value="">
</div>
```

> ⚠️ 布尔开关（`form-switch`）必须配套一个 `<input type="hidden" name="xxx" value="0">`，否则关闭时表单不提交该字段，后端 `param()` 取到默认值。

### 2.2 按钮组件

| 场景 | class | 示例 |
|---|---|---|
| 主操作（保存/提交） | `btn btn-primary rounded-pill` | `<button class="btn btn-primary rounded-pill">保存</button>` |
| 次操作（补签/测试） | `btn btn-outline-primary rounded-pill` | `<button class="btn btn-outline-primary rounded-pill px-5">补签</button>` |
| 危险操作（删除/清空） | `btn btn-danger rounded-pill` | `<button class="btn btn-danger rounded-pill">删除</button>` |
| 小尺寸 | `btn-sm` | `<button class="btn btn-sm btn-primary rounded-pill">编辑</button>` |

**强制规范**：

- **必须用 `rounded-pill`**（圆角胶囊），与系统后台风格统一
- **禁止 `w-100`**（块级宽度），按钮宽度由内容撑开，需要占满用外层 `col-*` 栅格控制
- **提交按钮禁用 + loading 状态**：异步提交时用 JS 给按钮加 `disabled` 属性 + `<span class="spinner-border spinner-border-sm"></span>`，防止重复提交

真实示例（`plugin/xnx_checkin/view/htm/checkin_setting.htm`）：

```html
<button type="submit" class="btn btn-primary rounded-pill">
    <i class="ti ti-device-floppy"></i> 保存配置
</button>

<button type="submit" class="btn btn-outline-primary rounded-pill px-5">
    <i class="ti ti-calendar-check"></i> 补签
</button>
```

### 2.3 徽章组件

| 含义 | class | 场景 |
|---|---|---|
| 主要 | `badge bg-primary` | 默认状态/新标记 |
| 成功 | `badge bg-success` | 已通过/在线/正常 |
| 警告 | `badge bg-warning` | 待审核/即将到期 |
| 危险 | `badge bg-danger` | 已拒绝/失联/错误 |
| 次要 | `badge bg-secondary` | 已下线/已禁用/灰色状态 |

```html
<span class="badge bg-warning">待审核</span>
<span class="badge bg-success">正常</span>
<span class="badge bg-danger">已拒绝</span>
<span class="badge bg-secondary">已下线</span>
```

### 2.4 何时用何种组件速查表

| 需求 | 用什么 | 禁止 |
|---|---|---|
| 卡片容器 | `x-card` + `card` 组合（见第 3 节） | 裸 `card` / `border` / `border-*` |
| 表单字段 | `form-control` / `form-select` | 自定义 input 样式 |
| 字段提示 | `form-text`（灰色小字） | `text-muted` + `small` 拼凑 |
| 启用/禁用 | `form-check form-switch` | 自定义 checkbox |
| 主按钮 | `btn btn-primary rounded-pill` | `btn btn-default` / `w-100` |
| 状态标记 | `badge bg-*` | 自定义彩色 span |
| 列表分隔 | `py-*` / `mb-*` 间距 | `border-top` / `border-bottom` |
| 模态框 | Bootstrap 5 `Modal`（`bootstrap.Modal`） | 自定义弹窗 |
| 图标 | Tabler Icons `<i class="ti ti-xxx"></i>` | emoji / SVG 内联 / Font Awesome |

---

## 3. x-card + card 组合规范

### 3.1 强制规范

**所有 Card 容器必须用 `x-card` + `card` 组合**，禁止裸用 Bootstrap `card` 或 `border`：

```html
<!-- ✅ 正确：x-card + card 组合 -->
<div class="x-card card">
    <div class="card-body">内容</div>
</div>

<!-- ❌ 错误：裸 card 无 x-card -->
<div class="card"><div class="card-body">内容</div></div>

<!-- ❌ 错误：用 border 代替 card -->
<div class="border rounded p-3">内容</div>
```

`x-card`（定义在 `view/css/theme.css`）的作用：
- 统一 margin（13px 0）、padding（12px）
- 统一背景色（`var(--bs-body-bg)`）和圆角（`--bs-border-radius-lg`）
- 移除 Bootstrap card 默认边框（`border: none`），改用轻微阴影（`box-shadow`）
- 暗色模式自动加 1px 边框（`[data-bs-theme="dark"] .x-card`）

### 3.2 card-body / card-title 用法

```html
<div class="x-card card">
    <div class="card-body">
        <h6 class="card-title small text-muted mb-3">配置组标题</h6>
        <!-- 字段内容 -->
    </div>
</div>
```

- `card-body`：卡片内部内容容器，提供 padding
- `card-title`：卡片标题，通常配 `small text-muted` 显示为灰色小字组标题
- 多个配置组用多个独立的 `x-card card` 并列，不要在一个 card 内堆所有字段

### 3.3 列表分隔用间距，禁止 border

Card 内列表项用 `py-*` / `mb-*` 间距分隔，**禁止用 `border-top` / `border-bottom`**：

```html
<!-- ✅ 正确：间距分隔 -->
<div class="list-group list-group-flush">
    <div class="py-3">项目 A</div>
    <div class="py-3">项目 B</div>
</div>

<!-- ❌ 错误：border 分隔 -->
<div class="border-top py-3">项目 A</div>
<div class="border-top py-3">项目 B</div>
```

### 3.4 真实示例

参考 `plugin/xnx_checkin/view/htm/checkin_setting.htm`，整页用 `x-card card` 包裹，内部每个配置组再用独立的 `x-card card` 分隔：

```html
<div class="x-card card">
    <div class="card-body">
        <h4 class="mb-4">
            <i class="ti ti-calendar-check"></i>
            <?php echo lang('xnx_checkin_admin');?>
        </h4>

        <!-- 统计卡 -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="x-card card">
                    <div class="card-body">
                        <h6 class="card-title small text-muted mb-1">签到统计</h6>
                        <div class="row">
                            <div class="col-6">
                                <div class="text-muted small">今日签到</div>
                                <div class="h4 mb-0"><?php echo intval($stats['today_count']);?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 配置表单 -->
        <form action="" method="post">
            <?php echo CsrfService::input();?>
            <input type="hidden" name="op" value="save_config">

            <div class="card x-card">
                <div class="card-body">
                    <h6 class="card-title small text-muted mb-3">奖励类型</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">积分类型</label>
                                <select class="form-select" name="reward_credits_type">
                                    <option value="credits">积分</option>
                                </select>
                                <div class="form-text">选择签到奖励的积分类型</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary rounded-pill">
                <i class="ti ti-device-floppy"></i> 保存配置
            </button>
        </form>
    </div>
</div>
```

> **禁止用 `border` 的场景**：卡片容器、列表分隔、区块分隔。如需视觉分区，用 `x-card` 的阴影、背景色差异（`bg-body-tertiary`）或间距（`my-*`/`gap-*`）替代。

---

## 4. 前台三栏布局骨架 layout_three_column.inc.htm

### 4.1 强制规范

前台页面（首页/版块/帖子/搜索/排行榜等）**必须使用系统三栏布局骨架** `view/htm/layout_three_column.inc.htm`，**禁止自行写 `container`/`row`/`col-lg-*` 栅格**。骨架内置了 htmx boost 属性、栅格断点、左右栏显隐逻辑，自行硬编码会导致 htmx 局部刷新失效、移动端布局错乱、与系统其他页面视觉不一致。

### 4.2 用法

`ob_start()` 收集中栏内容 → `$main_content = ob_get_clean()` → include 骨架文件：

```php
<?php include _include(APP_PATH.'view/htm/header.inc.htm');?>

<?php ob_start();?>
<!-- 中栏内容写这里 -->
<div class="x-card card">
    <div class="card-body">
        <!-- 内容 -->
    </div>
</div>
<?php $main_content = ob_get_clean();
include _include(APP_PATH.'view/htm/layout_three_column.inc.htm');?>

<?php include _include(APP_PATH.'view/htm/footer.inc.htm');?>
```

### 4.3 骨架变量表

源码：`view/htm/layout_three_column.inc.htm`。所有变量均有默认值，按需覆盖：

| 变量 | 默认值 | 说明 |
|---|---|---|
| `$main_content` | `''` | 中栏内容（`ob_get_clean()` 获取） |
| `$main_file` | `''` | 中栏 include 文件名（优先于 `$main_content`，thread 用） |
| `$sidebar_left_file` | `'sidebar_left.inc.htm'` | 左栏 include 文件名，**空字符串则不渲染左栏** |
| `$sidebar_right_file` | `'sidebar_right.inc.htm'` | 右栏 include 文件名，**空字符串则不渲染右栏** |
| `$right_html` | `''` | 右栏内联 HTML（优先于 `$sidebar_right_file`，forum 版块信息卡用） |
| `$col_main` | `7` | 中栏 xl 栅格宽（左栏 2 + 中栏 7 + 右栏 3 = 12） |
| `$left_lg` | `true` | 左栏是否 lg 断点起显示（`false` 则 xl 起显示，thread 操作栏传 `false`） |
| `$main_class` | `''` | 中栏额外 class（forum 传 `'main'`） |
| `$right_class` | `''` | 右栏额外 class（forum 传 `'aside'`） |

### 4.4 空栏设置

不需左右栏时，将 `$sidebar_left_file` 和 `$sidebar_right_file` 设为空字符串，骨架自动隐藏左右栏，中栏独占中间区域：

```php
<?php ob_start();?>
<!-- 纯中栏内容 -->
<?php $main_content = ob_get_clean();
$sidebar_left_file = '';
$sidebar_right_file = '';
include _include(APP_PATH.'view/htm/layout_three_column.inc.htm');?>
```

### 4.5 骨架源码结构

骨架文件内部结构（仅供理解，不要自行复制）：

```html
<div class="row g-4" hx-boost="true" hx-target="#body" hx-swap="outerHTML" hx-select="#body" hx-push-url="true">
    <div class="d-none d-<?php echo $left_lg ? 'lg' : 'xl'; ?>-block col-xl-2">
        <?php if($sidebar_left_file) include _include(APP_PATH.'view/htm/'.$sidebar_left_file);?>
    </div>
    <div class="col-xl-<?php echo $col_main;?>">
        <?php if($main_file) { include _include(APP_PATH.'view/htm/'.$main_file); } else { echo $main_content; } ?>
    </div>
    <div class="d-none d-xl-block col-xl-3">
        <?php if($right_html !== '') { echo $right_html; } elseif($sidebar_right_file) { include _include(APP_PATH.'view/htm/'.$sidebar_right_file); } ?>
    </div>
</div>
```

> ⚠️ 骨架根 `<div class="row">` 上已绑定 `hx-boost`/`hx-target`/`hx-swap`/`hx-select`/`hx-push-url`，自行硬编码会丢失这些 htmx 属性，导致前台局部刷新失效。

---

## 5. Tab 独立页面模式

### 5.1 核心理念

Xiuno 后台的 Tab 是**独立 URL 整页跳转**，**不是** Bootstrap Tab 组件（`data-bs-toggle="tab"`）切换 DOM。每个子 tab 对应一个独立的 URL，点击 tab 链接是整页刷新。

> ⚠️ **禁止用 Bootstrap Tab 组件**：`data-bs-toggle="tab"` + `href="#pane-x"` 切换 DOM 的写法会被扫描器 `bs_tab_navigation` 拦截。后台 Tab 必须用独立 URL 整页跳转。

### 5.2 admin_tab_active() 函数签名

源码：`admin/admin.func.php:178-187`。

```php
function admin_tab_active($arr, $active) {
    // hook admin_tab_active_start.php
    $s = '<ul class="nav nav-tabs nav-tabs-scroll gap-2">';
    foreach ($arr as $k=>$v) {
        $s .= '<li class="nav-item"><a class="nav-link '.($active == $k ? ' active' : '').'" href="'.$v['url'].'">'.$v['text'].'</a></li>';
    }
    $s .= '</ul>';
    // hook admin_tab_active_end.php
    return $s;
}
```

**参数**：
- `$arr`：tab 数组，格式 `array('key' => array('url' => url('xxx'), 'text' => lang('xxx')))`
- `$active`：当前激活的 key

**输出**：`<ul class="nav nav-tabs nav-tabs-scroll gap-2">` 导航 HTML，当前 tab 项加 `active` class。

### 5.3 menu.conf.php tab 数组结构

源码：`admin/menu.conf.php`。每个一级菜单下有 `tab` 数组，每个 tab 项是 `array('url'=>url('xxx-yyy'), 'text'=>lang('xxx'))`：

```php
'setting' => array(
    'url'=>url('setting-base'),
    'text'=>lang('setting'),
    'icon'=>'icon-cog',
    'tab'=> array (
        'base'=>array('url'=>url('setting-base'), 'text'=>lang('admin_setting_base')),
        'seo'=>array('url'=>url('setting-seo'), 'text'=>lang('admin_setting_seo')),
        'permalink'=>array('url'=>url('setting-permalink'), 'text'=>lang('admin_setting_permalink')),
        // ...
    )
),
'thread' => array(
    'url'=>url('thread-list'),
    'text'=>lang('thread'),
    'icon'=>'icon-comment',
    'tab'=> array (
        'list'=>array('url'=>url('thread-list'), 'text'=>lang('admin_thread_batch')),
        'recycle'=>array('url'=>url('thread-recycle'), 'text'=>lang('admin_recycle_bin')),
    )
),
```

系统内置一级菜单：`setting`/`forum`/`thread`/`audit`/`attach`/`user`/`security`/`log`/`health`/`other`/`notice`/`icon_preview`/`scanner`/`plugin`/`theme`/`ai`。

### 5.4 插件 setting.php 内的 Tab 模式

插件 setting.php 的 URL 格式为 `?plugin-setting-{dir}-{sub_action}`，用 `param(3)` 取 `sub_action` 分发：

```
URL：?plugin-setting-xnx_checkin-records.htm
解析：param(0)='plugin', param(1)='setting', param(2)='xnx_checkin', param(3)='records'
```

源码注释（`plugin/xnx_checkin/setting.php:7-9`）：

```php
// URL 格式：plugin-setting-xnx_checkin-{sub_action}
// param(0)=plugin, param(1)=setting, param(2)=xnx_checkin, param(3)=sub_action
$sub_action = param(3);
if (empty($sub_action)) {
    $sub_action = 'setting';
}
```

### 5.5 完整代码模板

参考 `plugin/xnx_checkin/setting.php`，完整的 Tab 独立页面模式：

```php
<?php
!defined('DEBUG') AND exit('Forbidden');
$gid != 1 && $gid != 2 AND message(-1, lang('user_group_insufficient_privilege'));

// ========== 取子动作 ==========
$sub_action = param(3);
if (empty($sub_action)) {
    $sub_action = 'setting';
}

// ========== POST 操作（必须在 include header 之前，message() 会发送 303 header）==========
if ($method == 'POST') {
    CsrfService::check();
    $op = param('op', '');
    if ($op == 'save_config') {
        // ... 保存配置
        message(0, '保存成功');
    }
    message(-1, '未知操作');
}

// ========== GET 数据准备 ==========
include _include(ADMIN_PATH . 'view/htm/header.inc.htm');

// 顶部 Tab 切换（页面跳转，非 Bootstrap Tab 组件）
$tabs = array(
    'setting'  => array('url' => url('plugin-setting-xnx_checkin-setting'),  'text' => lang('xnx_checkin_config')),
    'records'  => array('url' => url('plugin-setting-xnx_checkin-records'),  'text' => lang('xnx_checkin_admin')),
);
echo admin_tab_active($tabs, $sub_action);

// ========== 子页面分发 ==========
switch ($sub_action) {
    case 'setting':
        // 获取配置
        $config = setting_get('xnx_checkin') ?: array();
        include _include(APP_PATH . 'plugin/xnx_checkin/view/htm/checkin_setting.htm');
        break;

    case 'records':
        // 获取记录列表
        $checkin_records = array();
        // ... 分页查询
        include _include(APP_PATH . 'plugin/xnx_checkin/view/htm/checkin_records.htm');
        break;

    default:
        message(-1, '未知操作');
        break;
}

include _include(ADMIN_PATH . 'view/htm/footer.inc.htm');
```

### 5.6 禁止项

| 禁止 | 正确做法 |
|---|---|
| Bootstrap Tab 组件 `data-bs-toggle="tab"` + `href="#pane-x"` 切换 DOM | 用 `admin_tab_active()` + 独立 URL 整页跳转 |
| 在一个 setting.php 内用 JS 隐藏/显示不同子页面 DOM | 每个子页面独立 URL + `param(3)` 分发 |
| Tab 链接用 `#anchor` 锚点 | Tab 链接用 `url('plugin-setting-{dir}-{sub}')` |

---

## 6. 插件后台入口模式

### 6.1 两种入口模式对比表

| 模式 | 入口文件 | URL 格式 | 注册方式 | 适用场景 |
|---|---|---|---|---|
| **setting.php 嵌入式** | `plugin/<dir>/setting.php` | `?plugin-setting-<dir>` 或 `?plugin-setting-<dir>-<sub>` | 自动（系统识别 setting.php 存在即在插件列表出"设置"按钮） | 配置项较少，无独立后台列表页 |
| **admin.php 独立入口** | `plugin/<dir>/admin.php` | `?<dir>_admin` 或自定义 | 通过 `hook/admin_index_route_case_end.php` 注册 case | 需要独立的后台管理列表页（审核/CRUD/批量操作） |

### 6.2 setting.php 嵌入式完整模板

参考 `plugin/xnx_checkin/setting.php`。权限检查 + CSRF + header/footer 包裹的标准结构：

```php
<?php
!defined('DEBUG') AND exit('Forbidden');
$gid != 1 && $gid != 2 AND message(-1, lang('user_group_insufficient_privilege'));

// ========== POST 处理 ==========
if ($method == 'POST') {
    CsrfService::check();
    $op = param('op', '');
    if ($op == 'save_config') {
        // ... 业务逻辑
        message(0, '保存成功');
    }
    message(-1, '未知操作');
}

// ========== GET 数据准备 ==========
$config = setting_get('my_plugin') ?: array();

include _include(ADMIN_PATH . 'view/htm/header.inc.htm');
include _include(APP_PATH . 'plugin/my_plugin/view/htm/setting.htm');
include _include(ADMIN_PATH . 'view/htm/footer.inc.htm');
```

**要点**：
- `!defined('DEBUG') AND exit('Forbidden');` 防直接访问
- `$gid != 1 && $gid != 2 AND message(-1, ...)` 权限检查（仅管理员/超级版主）
- POST 处理在 `include header` **之前**（`message()` 会发送 303 header，header 输出后无法跳转）
- 后台模板用 `ADMIN_PATH . 'view/htm/header.inc.htm'`，插件自己模板用 `APP_PATH . 'plugin/<dir>/view/htm/xxx.htm'`

### 6.3 admin.php 独立入口完整模板

参考 `plugin/xnx_friendlink/admin.php`。独立后台入口的标准结构：

```php
<?php
/**
 * XX 插件 - 后台管理
 * 通过 admin/?my_plugin_admin 访问
 */
!defined('DEBUG') AND exit('Forbidden');

// Service 已通过 model_inc_file.php hook 自动加载

// ==================== 处理 POST 操作 ====================
if ($method == 'POST') {
    CsrfService::check();
    $op = param('op');

    if ($op == 'approve') {
        $id = intval(param('id', 0));
        if (!$id) message(-1, '参数错误');
        // ... 业务逻辑
        MyService::clearCache();
        message(0, '已通过审核');
    }

    if ($op == 'delete') {
        $id = intval(param('id', 0));
        if (!$id) message(-1, '参数错误');
        MyService::delete($id);
        MyService::clearCache();
        message(0, '已删除');
    }

    // 批量操作
    if ($op == 'batch_action') {
        $ids = param('ids', '');
        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }
        if (empty($ids)) message(-1, '请选择要操作的项');
        // ... 批量逻辑
        message(0, '批量操作完成');
    }
}

// ==================== 加载管理页面数据 ====================
$page = intval(param('page', 1));
$pagesize = 20;
$list = MyService::findList(array(), $page, $pagesize);
$total = MyService::count(array());

// 分页 URL（见第 9 节，{page} 手动拼接）
$pagination_base = url('my_plugin_admin');
$pagination_qs = '?page={page}';
$pagination = pagination($pagination_base . $pagination_qs, $total, $page, $pagesize);

// ==================== 引入模板 ====================
include _include(ADMIN_PATH . 'view/htm/header.inc.htm');
include _include(APP_PATH . 'plugin/my_plugin/admin/view/htm/admin.htm');
include _include(ADMIN_PATH . 'view/htm/footer.inc.htm');
```

**要点**：
- 独立入口模板路径建议放 `plugin/<dir>/admin/view/htm/`，与 setting 模板（`plugin/<dir>/view/htm/`）区分
- 批量操作用 `param('ids', '')` 接收逗号分隔字符串，再 `explode` + `array_map('intval')` 转数组
- 每个写操作后调用 `MyService::clearCache()` 清缓存

### 6.4 路由注册 hook

独立入口必须通过 `hook/admin_index_route_case_end.php` 注册到后台路由分发。源码示例（`plugin/xnx_friendlink/hook/admin_index_route_case_end.php`）：

```php
<?php exit;
case 'xnx_friendlink': include APP_PATH.'plugin/xnx_friendlink/setting.php'; break;
case 'xnx_friendlink_admin': include APP_PATH.'plugin/xnx_friendlink/admin.php'; break;
case 'xnx_friendlink_cool': include APP_PATH.'plugin/xnx_friendlink/route/cool_sites.php'; break;
```

**模板**（复制到你的插件）：

```php
// plugin/<dir>/hook/admin_index_route_case_end.php
<?php exit;
case '<dir>_admin': include APP_PATH.'plugin/<dir>/admin.php'; break;
```

> ⚠️ **case 值禁止含 `-`**：`-` 是 URL 参数分隔符，`param(1)` 只取单段。`my-plugin-admin` 会被解析为 `param(1)='my'`，永远匹配不到。多段子动作用 `param(2)`/`param(3)` 逐段取。

### 6.5 conf.json 注册 hook

独立入口 hook 必须在 `conf.json` 的 `hooks_rank` 中注册（键名含 `.php` 扩展名）：

```json
{
    "hooks_rank": {
        "admin_index_route_case_end.php": 10
    }
}
```

### 6.6 两种模式选择标准

| 场景 | 选哪个 |
|---|---|
| 只有几个配置项（开关/数值/文本） | setting.php 嵌入式 |
| 需要配置项 + 记录列表/审核列表 | setting.php 嵌入式 + Tab 模式（第 5 节） |
| 需要独立的 CRUD 列表页（增删改查/批量操作/审核流程） | admin.php 独立入口 |
| 同时有配置页 + 独立管理页 | 两者都用：setting.php 出"设置"按钮，admin.php 出侧边栏入口 |

---

## 7. 后台菜单/侧边栏注册

### 7.1 一级菜单不可通过 hook 扩展

后台一级菜单写死在 `admin/menu.conf.php`，返回的是 PHP 数组，**没有 hook 注入点**。插件**不应修改系统文件** `menu.conf.php`，否则会被 `protected_paths` 白名单拦截（`conf/`、`xiunophp/`、`lib/`、`admin/` 等核心路径受保护）。

### 7.2 侧边栏入口：模板 hook

侧边栏入口通过模板 hook 注入链接。源码 `admin/view/htm/sidebar.inc.htm` 有两个注入点：

| Hook 位置 | 源码行 | 用途 |
|---|---|---|
| `<!--{hook admin_sidebar_start.htm}-->` | 第 101 行 | 侧边栏顶部，适合放插件独立管理入口 |
| `<!--{hook admin_sidebar_end.htm}-->` | 第 172 行 | 侧边栏底部 |

**hook 示例**（`plugin/<dir>/hook/admin_sidebar_end.htm`）：

```php
<?php
// 插件独立后台入口（admin.php 模式）
$_my_sidebar_active = (param(0) === 'my_plugin_admin') ? ' active' : '';
echo '<a class="nav-link' . $_my_sidebar_active . '" href="' . esc_attr(url('my_plugin_admin')) . '">';
echo '<i class="ti ti-link"></i> 友情链接管理';
echo '</a>';
```

> ⚠️ hook 内局部变量必须加插件前缀（`$_my_sidebar_active`），禁止用 `$active`/`$settings` 等通用名，避免污染宿主作用域。

### 7.3 独立后台页面路由注册

独立后台页面的路由分发通过 `admin_index_route_case_end.php` hook 注册（详见第 6.4 节）。

### 7.4 插件"设置"按钮自动出现

只要插件目录下存在 `setting.php` 文件，系统在插件列表页会自动显示"设置"按钮，链接到 `?plugin-setting-<dir>.htm`，无需额外注册。

---

## 8. 后台 GET 搜索表单 JS 拦截

### 8.1 坑点说明

Xiuno 后台 URL 格式为 `?plugin-setting-xxx.htm`，路由参数 `plugin-setting-xxx.htm` 是 query string 的一部分（不是 path）。根据 HTML 规范，**浏览器原生 GET 表单提交会丢弃 action URL 的 query string**，只用表单字段作为新 query string，导致路由参数丢失，`xn_url_parse` 解析后 `param(0)` 取到表单字段名（如 `cr_page=1`），命中 default 分支 `http_404()`。

**注意**：单纯把 action 改为 `admin_plugin_setting_url($dir)` **无效**！因为 `admin_plugin_setting_url` 返回的也是 `?plugin-setting-xxx.htm` 格式，query string 同样会被浏览器丢弃。

### 8.2 正确做法

用 JS 拦截 submit 手动拼接 URL，把路由参数和表单字段拼成完整的 query string。参考 `admin/view/htm/plugin_list.htm` 的 `pluginSearch` 函数（第 236-250 行）：

```javascript
// 插件搜索:构建保留路由信息的 URL(./?plugin.htm?type=X&keyword=X&status=X)
// 不能依赖 GET 表单默认提交:浏览器会用表单字段替换 action URL 中的 query string,
// 丢失 ./?plugin.htm 中的路由信息 plugin.htm,导致 404。
function pluginSearch() {
    var form = document.getElementById('pluginSearchForm');
    if (!form) return;
    var type = form.elements['type'].value;
    var keyword = form.elements['keyword'].value;
    var status = form.elements['status'].value;
    var url = '<?php echo admin_plugin_url();?>?type=' + encodeURIComponent(type)
            + '&keyword=' + encodeURIComponent(keyword)
            + '&status=' + encodeURIComponent(status);
    window.location.href = url;
}
document.getElementById('pluginSearchForm').addEventListener('submit', function(e) {
    e.preventDefault();
    pluginSearch();
});
```

### 8.3 完整代码模板

通用后台 GET 搜索表单 + JS 拦截模板（适用于带筛选条件且需要翻页保留参数的场景）：

```php
<form id="searchForm" method="get" action="<?php echo esc_attr(admin_plugin_setting_url('xxx')); ?>">
    <input type="hidden" name="type" value="<?php echo intval($type);?>">
    <input name="keyword" value="<?php echo esc_attr($keyword ?? '');?>">
    <select name="status" onchange="doSearch()">
        <option value="">全部</option>
        <option value="0" <?php echo $status==='0'?'selected':'';?>>待审核</option>
        <option value="1" <?php echo $status==='1'?'selected':'';?>>正常</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm rounded-pill">搜索</button>
</form>
<script>
(function() {
    var form = document.getElementById('searchForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        doSearch();
    });
})();
function doSearch() {
    var form = document.getElementById('searchForm');
    var base = form.getAttribute('action');  // ?plugin-setting-xxx.htm
    var fd = new FormData(form);
    var parts = [];
    fd.forEach(function(val, key) {
        if (val !== '') parts.push(key + '=' + encodeURIComponent(val));
    });
    var sep = base.indexOf('?') !== -1 ? '&' : '?';
    window.location.href = base + sep + parts.join('&');
    // 最终 URL: ?plugin-setting-xxx.htm&type=1&keyword=foo&status=0
}
</script>
```

### 8.4 判定标准

**后台插件 GET 搜索表单**（含筛选条件且需要翻页保留参数的场景）**必须**用 JS 拦截 submit 手动构建 URL，禁止依赖浏览器原生 GET 提交（会丢弃路由参数）。纯 POST 表单不受此规则约束。

### 8.5 自检命令

```bash
# 找出所有后台 GET 搜索表单（潜在 404 风险）
grep -rn 'method="get"' plugin/*/view/htm/*.htm plugin/*/admin/view/htm/*.htm admin/view/htm/*.htm
```

---

## 9. 后台分页 URL 手动拼接

### 9.1 坑点说明

`url($template, $extra)` 内部用 `http_build_query($extra)` 编码参数，会把 `{page}` 编码为 `%7Bpage%7D`，而 `pagination()` 用 `str_replace('{page}', $i, $url)` 做字面量替换，找不到 `%7Bpage%7D`，导致分页链接的 `{page}` 无法被替换，点击分页跳到错误的 URL。

**违规示例**：

```php
// ❌ {page} 通过 url() 的 $extra 传递，被 http_build_query 编码
$cr_url_params['cr_page'] = '{page}';
$pagination = pagination(url('plugin-setting-xnx_checkin', $cr_url_params), $total, $page, $pagesize);
// 生成的 URL 含 cr_page=%7Bpage%7D，pagination() 无法替换
```

### 9.2 正确做法

手动拼接 query string，`{page}` 保持原样。参考 `plugin/xnx_checkin/setting.php:134-143`：

```php
// 构建分页URL（保留筛选参数）
// 注意：不能用 url(..., $cr_url_params) 传 {page}，http_build_query 会把 {page} 编码为 %7Bpage%7D，
// pagination() 无法替换。手动拼接 query string。
$cr_pagination_base = url('plugin-setting-xnx_checkin-records');
$cr_pagination_qs = '?';
if ($cr_uid > 0) $cr_pagination_qs .= 'cr_uid=' . intval($cr_uid) . '&';
if (!empty($cr_date_start)) $cr_pagination_qs .= 'cr_date_start=' . urlencode($cr_date_start) . '&';
if (!empty($cr_date_end)) $cr_pagination_qs .= 'cr_date_end=' . urlencode($cr_date_end) . '&';
$cr_pagination_qs .= 'cr_page={page}';  // {page} 不被编码
$checkin_records_pagination = pagination($cr_pagination_base . $cr_pagination_qs, $checkin_records_total, $checkin_records_page, $checkin_records_pagesize);
```

### 9.3 完整代码模板

```php
$pagination_base = admin_plugin_setting_url('xnx_checkin');  // ?plugin-setting-xnx_checkin.htm
$pagination_qs = '?';
if ($uid > 0) $pagination_qs .= 'cr_uid=' . intval($uid) . '&';
if (!empty($date_start)) $pagination_qs .= 'cr_date_start=' . urlencode($date_start) . '&';
if (!empty($date_end)) $pagination_qs .= 'cr_date_end=' . urlencode($date_end) . '&';
$pagination_qs .= 'cr_page={page}';  // {page} 不被编码
$pagination = pagination($pagination_base . $pagination_qs, $total, $page, $pagesize);
```

### 9.4 判定标准

凡是用 `pagination()` 且 URL 需要带筛选参数的场景，`{page}` 占位符**必须**通过手动拼接 query string 传递，**禁止**通过 `url(..., $extra)` 传递。`pager()` 函数同理。

### 9.5 自检命令

```bash
# 找出所有通过 url(..., $params) 传 {page} 的分页调用
grep -rn "pagination(url(" plugin/ admin/ route/ view/ | grep "{page}"
```

---

## 10. 命名快捷函数完整列表

源码：`model/route.func.php`（第 266-562 行）。命名快捷函数优先级：**命名快捷函数 > `route_url()` > `url()`**，禁止硬编码 `.htm`/`.html` 后缀。

### 10.1 核心函数

| 函数 | 签名 | 用途 |
|---|---|---|
| `route_url($name, $args, $query)` | `route_url($name, $args = array(), $query = array())` | 按路由表名生成 URL（内部调 `url()`） |
| `admin_url($url, $extra)` | `admin_url($url, $extra = array())` | 从前台生成指向 admin 后台的 URL（强制 `?xxx.htm` 格式 + `/admin/` 前缀） |

### 10.2 后台 - 插件相关

| 函数 | 签名 | 用途 | 生成的 URL |
|---|---|---|---|
| `admin_plugin_url($query)` | `route_url('admin_plugin', [], $query)` | 插件列表页 | `?plugin.htm` |
| `admin_plugin_setting_url($dir, $query)` | `route_url('admin_plugin_setting', ['dir'=>$dir], $query)` | 插件设置页 | `?plugin-setting-<dir>.htm` |
| `admin_plugin_install_url($dir, $query)` | `route_url('admin_plugin_install', ['dir'=>$dir], $query)` | 插件安装 | `?plugin-install-<dir>.htm` |
| `admin_plugin_enable_url($dir, $query)` | `route_url('admin_plugin_enable', ['dir'=>$dir], $query)` | 启用插件 | `?plugin-enable-<dir>.htm` |
| `admin_plugin_disable_url($dir, $query)` | `route_url('admin_plugin_disable', ['dir'=>$dir], $query)` | 禁用插件 | `?plugin-disable-<dir>.htm` |
| `admin_plugin_unstall_url($dir, $query)` | `route_url('admin_plugin_unstall', ['dir'=>$dir], $query)` | 卸载插件 | `?plugin-unstall-<dir>.htm` |
| `admin_plugin_upgrade_url($dir, $query)` | `route_url('admin_plugin_upgrade', ['dir'=>$dir], $query)` | 升级插件 | `?plugin-upgrade-<dir>.htm` |
| `admin_plugin_scanner_url($query)` | `route_url('admin_plugin_scanner', [], $query)` | 兼容性扫描 | `?plugin-scanner.htm` |

### 10.3 前台 - 帖子/版块/用户

| 函数 | 签名 | 用途 |
|---|---|---|
| `thread_url($tid, $query)` | `route_url('thread', ['tid'=>$tid], $query)` | 前台帖子 `thread-<tid>.htm` |
| `thread_page_url($tid, $page, $query)` | `route_url('thread_page', ['tid'=>$tid, 'page'=>$page], $query)` | 帖子分页 |
| `thread_create_url($fid, $query)` | `route_url('thread_create_fid', ['fid'=>$fid], $query)` | 发帖 |
| `forum_url($fid, $query)` | `route_url('forum', ['fid'=>$fid], $query)` | 前台版块 `forum-<fid>.htm` |
| `forum_page_url($fid, $page, $query)` | `route_url('forum_page', ['fid'=>$fid, 'page'=>$page], $query)` | 版块分页 |
| `user_url($uid, $query)` | `route_url('user', ['uid'=>$uid], $query)` | 前台用户主页 |

### 10.4 后台跳前台（关键）

| 函数 | 签名 | 用途 |
|---|---|---|
| `frontend_thread_url($tid, $query)` | `route_url('frontend_thread', ['tid'=>$tid], $query)` | 后台生成前台帖子链接（带 `../` 前缀跳出 admin 目录） |
| `frontend_user_url($uid, $query)` | `route_url('frontend_user', ['uid'=>$uid], $query)` | 后台生成前台用户链接 |
| `frontend_forum_url($fid, $query)` | `route_url('frontend_forum', ['fid'=>$fid], $query)` | 后台生成前台版块链接 |

### 10.5 禁止项

| 禁止 | 正确做法 |
|---|---|
| 套用 `url()`：`url(admin_plugin_setting_url($dir))` | 直接用 `admin_plugin_setting_url($dir)`（`route_url` 系列内部已调 `url()`，再套会产生 `??xxx.htm.htm` 双后缀） |
| 后台生成前台 URL 用 `url('thread-'.$tid)` | 用 `frontend_thread_url($tid)`（带 `../` 前缀，否则 admin 下解析为 admin 子路径 404） |
| 后台生成前台 URL 用 `url('user-'.$uid)` | 用 `frontend_user_url($uid)` |
| 后台生成前台 URL 用 `url('forum-'.$fid)` | 用 `frontend_forum_url($fid)` |
| 硬编码 `.htm`/`.html` 后缀 | 用命名快捷函数或 `url()`，后缀由路由配置决定 |

> ⚠️ **后台生成前台 URL 必须用 `frontend_*_url`**：`url()` 函数通过 `$_SERVER['SCRIPT_NAME']` 检测 admin 上下文，admin 下会强制 `url_rewrite_on=0`（`?xxx.htm` 格式）并加 `./` 前缀，导致 `url('thread-'.$tid)` 在 admin 下被解析为 `/admin/?thread-xxx.htm`（多了 admin 前缀），跳到后台 404。`frontend_*_url` 系列用 `../` 前缀让浏览器解析时跳出 admin 目录。

---

## 11. 弹窗规范

### 11.1 toast vs Modal 场景区分表

| 场景 | 用什么 | API |
|---|---|---|
| 操作成功（保存成功/已复制/已删除） | toast | `XN.toast(msg, 'success')` |
| 操作失败（网络错误/保存失败/权限不足） | toast | `XN.toast(msg, 'danger')` |
| 普通信息提示（请先选择/已加载完成） | toast | `XN.toast(msg)` 或 `XN.toast(msg, 'info')` |
| 需要确认的操作（删除/卸载/重置等不可逆操作） | confirm Modal | `XN.confirm(msg, callback, {type:'danger', okText:'确认删除'})` |
| 重要错误或需要用户详细阅读的长文本提示 | alert Modal | `XN.alert(msg, {type:'danger'})` |
| 需要用户输入文本（重命名/填写原因） | prompt Modal | `XN.prompt(msg, callback)` |
| 关键业务提示（将扣除积分/将影响 N 个用户） | confirm 或 alert Modal | 同上 |

### 11.2 禁止项

| 禁止 | 正确做法 |
|---|---|
| 原生 `alert(msg)` | `XN.alert(msg)` 或 `XN.toast(msg)` |
| 原生 `confirm(msg)` | `XN.confirm(msg, callback)` |
| 原生 `prompt(msg)` | `XN.prompt(msg, callback)` |
| 自定义弹窗 DOM | 用 `XN.*` API（基于 Bootstrap 5 Modal） |

### 11.3 API 详情

`XN.alert()` / `XN.confirm()` / `XN.prompt()` / `XN.toast()` 定义于 `view/js/xiuno-modern.js`，基于 Bootstrap 5 Modal/Toast 封装，前后台 `footer.inc.htm` 已全局加载。

```javascript
// toast：4 秒自动消失
XN.toast('保存成功', 'success');           // 绿色
XN.toast('出错了', 'danger');              // 红色
XN.toast('请注意', 'warning');             // 黄色
XN.toast('信息', 'info');                  // 蓝色
XN.toast('3秒消失', 'success', 3000);      // 自定义时长

// confirm：Promise 风格
XN.confirm('确定要删除吗？').then(function() {
    // 用户确认后执行
});

// alert：带类型和标题
XN.alert('提示内容', { type: 'danger', title: '确认删除' });
```

> ⚠️ **关键修复页面禁止依赖 `xiuno-modern.js`**：在线升级/数据库升级/后台登录/插件管理/系统工具等「最后手段」页面，用原生 `fetch` + `confirm` + `querySelectorAll`，避免「网站坏 → 修复页面也坏」的死循环。

---

## 12. 真实示例对照

### 12.1 xnx_checkin（setting.php 嵌入式 + Tab 模式）

源码：`plugin/xnx_checkin/setting.php` + `plugin/xnx_checkin/view/htm/checkin_setting.htm`。

**架构**：setting.php 嵌入式入口，用 `param(3)` 分发 `setting`/`records` 两个子 tab。

**演示的规范点**：

| 规范点 | 对应章节 | 源码位置 |
|---|---|---|
| setting.php 嵌入式入口（权限检查 + CSRF + header/footer 包裹） | 第 6.2 节 | `setting.php:1-3, 14-16, 64, 153` |
| Tab 独立页面模式（`admin_tab_active` + `param(3)` 分发） | 第 5 节 | `setting.php:7-12, 67-71, 73-151` |
| POST 处理在 include header 之前 | 第 6.2 节 | `setting.php:14-61` |
| 子页面用 `switch ($sub_action)` 分发 | 第 5.5 节 | `setting.php:73-151` |
| 分页 URL 手动拼接（`{page}` 不被编码） | 第 9 节 | `setting.php:134-143` |
| x-card + card 组合（整页 + 配置组） | 第 3 节 | `checkin_setting.htm:2, 12, 36, 60, 80, 112, 141, 187` |
| form-switch 配套 hidden input | 第 2.1 节 | `checkin_setting.htm:146-147` |
| form-select + form-text 提示 | 第 2.1 节 | `checkin_setting.htm:43-53` |
| btn + rounded-pill 圆角胶囊 | 第 2.2 节 | `checkin_setting.htm:174, 206` |
| Tabler Icons `<i class="ti ti-xxx"></i>` | 第 1 节 | `checkin_setting.htm:5, 175, 207` |

### 12.2 xnx_friendlink（admin.php 独立入口）

源码：`plugin/xnx_friendlink/admin.php` + `plugin/xnx_friendlink/hook/admin_index_route_case_end.php` + `plugin/xnx_friendlink/admin/view/htm/link_admin.htm`。

**架构**：admin.php 独立入口，通过 `admin_index_route_case_end.php` hook 注册路由，提供待审核/已审核两个列表 + GET 搜索 + 分页 + 批量操作。

**演示的规范点**：

| 规范点 | 对应章节 | 源码位置 |
|---|---|---|
| admin.php 独立入口（POST 处理 + 数据加载 + header/footer 包裹） | 第 6.3 节 | `admin.php:1-6, 11-143, 145-211, 213-216` |
| 路由注册 hook（`admin_index_route_case_end.php`） | 第 6.4 节 | `hook/admin_index_route_case_end.php:3` |
| POST 操作以 `CsrfService::check()` 开头 | 第 6.3 节 | `admin.php:12` |
| 批量操作 `param('ids', '')` + `explode` + `array_map('intval')` | 第 6.3 节 | `admin.php:92-95, 105-108` |
| 每个写操作后 `clearCache()` | 第 6.3 节 | `admin.php:28, 49, 58, 75, 86, 98, 140` |
| 用户信息用 `user_read()` 取 `display_name` | — | `admin.php:161-164, 193-197` |
| 分页 URL（独立入口用 `url('xnx_friendlink_admin', $params)`） | 第 9 节 | `admin.php:167-168, 200-204` |
| 状态映射用 `$status_map` 数组 | 第 2.3 节 | `admin.php:150-151` |

### 12.3 对照表

| 维度 | xnx_checkin | xnx_friendlink |
|---|---|---|
| 入口模式 | setting.php 嵌入式 | admin.php 独立入口 |
| URL 格式 | `?plugin-setting-xnx_checkin-{sub}` | `?xnx_friendlink_admin` |
| 路由注册 | 自动（setting.php 存在即出"设置"按钮） | `admin_index_route_case_end.php` hook |
| 子页面分发 | `param(3)` + `switch ($sub_action)` | 单页（待审核 + 已审核同页） |
| Tab 导航 | `admin_tab_active()` 整页跳转 | 无（单页用锚点切换列表） |
| 后台入口按钮 | 插件列表"设置"按钮自动出现 | 侧边栏 hook 注入链接 |
| 分页 | 手动拼接（`{page}` 不编码） | `url()` + `$params`（独立入口 URL 无 `.htm` 路由参数问题） |
| 适用场景 | 配置项 + 记录列表 | 独立 CRUD 列表 + 审核流程 + 批量操作 |
