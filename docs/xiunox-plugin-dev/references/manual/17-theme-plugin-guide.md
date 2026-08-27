# 主题插件开发指南

> 版本：XiunoX
> 核对源码：`view/css/theme.css`、`view/htm/header.inc.htm`、`model/plugin.func.php`、`admin/route/theme.php`、`plugin/xnx_theme_moments/`

主题插件是插件的一种（`conf.json.type = "theme"`），机制与功能插件完全相同（`hook/` + `overwrite/` + `install/uninstall`），区别只在于**目标**：功能插件加能力，主题插件改视觉与版式。本篇只讲主题独有的那部分，通用机制见 [02-plugin-structure.md](02-plugin-structure.md) 与 [01-architecture.md](01-architecture.md)。

实战范例：`plugin/xnx_theme_moments/`（仿微信朋友圈信息流主题），本篇所有代码示例均来自该插件与核心源码。

---

## 1. 定位：主题插件 vs 功能插件

| 维度 | 主题插件 | 功能插件 |
|---|---|---|
| `conf.json.type` | `"theme"` | `"plugin"` |
| 目录命名 | `xxx_theme` / `xxx_theme_<变体>`（第二段必须 `theme`） | `xxx_<功能>`（至少两段） |
| 互斥 | 主题插件之间**互斥**，全局只能启用一个 | 按功能标识互斥（详见 [plugin-mutex-guide.md](plugin-mutex-guide.md)） |
| 主要手段 | `overwrite/` 覆盖模板 + `hook/header_link_after.htm` 注入 CSS + `static/css/` | `hook/` 注入逻辑 + `model/` Service + `route/` |
| 改功能逻辑 | ❌ 禁止 | ✅ 可以 |
| 改视觉版式 | ✅ 可以 | 尽量不动（避免与主题插件冲突） |

> 互斥规则详见 [plugin-mutex-guide.md](plugin-mutex-guide.md)。命名必须 `xxx_theme` 或 `xxx_theme_<变体>`，禁止 `xxx_<变体>_theme`（会被误判为功能插件）。

---

## 2. 系统主题机制（主题插件必须适配的基础）

XIUNOX 的主题分两层，主题插件**必须适配**这两层，否则会和系统设置打架。

### 2.1 明暗模式：`data-bs-theme` 属性

- 属性挂在 `<html>` 上：`<html data-bs-theme="light|dark">`，Bootstrap 5.3 原生支持。
- 切换脚本在 `view/htm/header.inc.htm#L173-L189`，**内联同步执行**（禁止 `defer`，否则首屏 FOUC 闪烁）：
  ```php
  var defaultTheme = <?php echo json_encode($conf['default_theme'] ?? 'light');?>;
  var theme = localStorage.getItem('theme') || defaultTheme
      || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.setAttribute('data-bs-theme', theme);
  ```
- 优先级：`localStorage('theme')`（用户手动切换）> `$conf['default_theme']`（后台设置）> `prefers-color-scheme`（系统偏好）。
- 后台管理：`admin/route/theme.php`，只支持 `light`/`dark` 两值，存 `conf/conf.php`。

### 2.2 主题色 brand：`data-theme` 属性 + CSS 变量

- 属性挂在 `<html>` 上：`<html data-theme="blue|green|purple|red|orange|pink|teal|indigo|cyan|lime">`（10 种）。
- 生效靠 `view/css/theme.css#L34-L41`，每个 brand 覆盖 Bootstrap 的 CSS 变量：
  ```css
  [data-theme="blue"] {
      --bs-primary: #2563eb;
      --bs-primary-rgb: 37, 99, 235;
      --bs-link-color: #2563eb;
      --bs-link-color-hover: #1d4ed8;
      --x-page-bg: #f7f8fa;
  }
  ```
- `<meta name="theme-color">` 由 `view/htm/header.inc.htm#L18-L34` 的 `$_theme_colors` 数组映射生成。
- **主题插件适配关键**：自己的样式颜色**必须用 `var(--bs-primary)` / `var(--bs-primary-rgb)`**，不要硬编码，否则用户在后台切主题色时你的插件不变色。

### 2.3 主题插件要适配的三个开关

| 开关 | 用户操作 | 主题插件应对 |
|---|---|---|
| 明暗 | 后台设置 / 前端手动切换 | CSS 用 `[data-bs-theme="dark"]` 覆盖变量 |
| 主题色 | 后台设置 / 前端手动切换 | CSS 颜色引用 `var(--bs-primary)` |
| 系统偏好 | 操作系统切换 | 不要单独用 `prefers-color-scheme`（系统已用 `data-bs-theme` 覆盖系统偏好，单独写会双切打架） |

---

## 3. 主题插件开发流程

### 3.1 `conf.json`

```json
{
    "name": "朋友圈模板",
    "brief": "仿微信朋友圈样式的前台主题模板",
    "version": "1.0.0",
    "bbs_version": "1.1",
    "type": "theme",
    "hooks_rank": {
        "header_link_after.htm": 100,
        "footer_js_after.htm": 100,
        "lang_zh_cn_bbs.php": 100,
        "thread_list_inc_start.htm": 100
    },
    "overwrites_rank": [
        "view/htm/header.inc.htm",
        "view/htm/footer.inc.htm",
        "view/htm/layout_three_column.inc.htm"
    ],
    "dependencies": [],
    "author": "twelve"
}
```

要点：
- `type` 必须 `"theme"`（触发互斥）。
- `overwrites_rank` 是**数组**（列出要覆盖的模板相对路径），`hooks_rank` 是**对象**（键名 = hook 文件名含扩展名）。两者类型不同，别搞混。
- `hooks_rank` 至少含 `header_link_after.htm`（注入主题 CSS）和 `footer_js_after.htm`（注入主题 JS）。
- 通用字段规则（`version`/`bbs_version`/不含 `installed`/`enable` 等）见 [02-plugin-structure.md](02-plugin-structure.md)。

### 3.2 目录结构（以 `xnx_theme_moments` 为例）

```
plugin/xnx_theme_moments/
├── conf.json
├── install.php / uninstall.php / setting.php
├── icon.png
├── hook/
│   ├── header_link_after.htm      # 注入主题 CSS <link>
│   ├── footer_js_after.htm        # 注入主题 JS <script>
│   ├── thread_list_inc_start.htm  # 改版式（信息流卡片）
│   └── lang_zh_cn_bbs.php         # 主题文案多语言
├── overwrite/
│   └── view/htm/
│       ├── header.inc.htm             # 覆盖页头（★必须保留 hook 标记）
│       ├── footer.inc.htm             # 覆盖页脚
│       └── layout_three_column.inc.htm # 覆盖三栏骨架
├── static/
│   ├── css/moments.css            # 主题样式（CSS 变量 + dark 适配）
│   └── js/moments.js              # 主题交互
└── view/htm/                      # 主题自带的子模板片段
    ├── moments_card.inc.htm
    └── thread_list_moments.inc.htm
```

`install.php` / `uninstall.php` / `setting.php` 与功能插件完全一致，见 [02-plugin-structure.md](02-plugin-structure.md)。

---

## 4. ★overwrite 实战（主题插件的核心手段）

`overwrite/` 在编译期把核心模板**整个替换**成你的版本。机制见 `model/plugin.func.php#L598-L640` 的 `plugin_find_overwrite()`。

### 4.1 工作原理

`_include(APP_PATH.'view/htm/header.inc.htm')` 编译时：
1. `plugin_find_overwrite()` 遍历已启用插件，找 `plugin/<dir>/overwrite/view/htm/header.inc.htm`。
2. 命中且路径不在保护名单 → 用你的文件替换核心文件作为编译源。
3. 多个插件覆盖同一文件，按 `overwrites_rank` 取最大值（主题插件互斥，实际只有一个生效）。
4. 替换后再走 `plugin_compile_hooks()`，把 `<!--{hook xxx}-->` 标记替换成各插件 hook 内容。

### 4.2 ★必须保留所有 `<!--{hook xxx}-->` 标记

**这是主题插件最容易踩的坑，也是与功能插件兼容的生命线。**

`overwrite` 在 hook 编译**之前**替换源文件。如果你的覆盖版本删掉了 `<!--{hook xxx}-->` 标记，hook 编译阶段找不到注入点，**所有功能插件的 hook 在该模板上全部失效**——签到、标签、勋章、头像框统统不显示，而且不报错，极难排查。

正确做法（参考 `xnx_theme_moments/overwrite/view/htm/header.inc.htm`）：**完整复制核心模板，只改你要改的部分，hook 标记原样保留**。

```php
// overwrite/view/htm/header.inc.htm —— 复制核心 header.inc.htm，保留所有标记
<?php $conf = G('conf');?>
<?php $header = G('header');?>
<?php
// ... 你要改的 head 区内容 ...
?>
<!--{hook header_start.htm}-->       <!-- ★ 必须保留 -->
<!DOCTYPE html>
<html lang="<?php echo $_lang_bcp47;?>">
<head>
    <!--{hook header_meta_before.htm}-->   <!-- ★ 必须保留 -->
    ... 你改的 meta/css ...
</head>
<body>
    <!--{hook header_body_start.htm}-->    <!-- ★ 必须保留 -->
    ...
```

**自查方法**：覆盖一个核心模板前，先 diff 你的版本和核心版本的 hook 标记数量：
```bash
grep -o '<!--{hook [^}]*}-->' view/htm/header.inc.htm | sort > /tmp/core_hooks.txt
grep -o '<!--{hook [^}]*}-->' plugin/xnx_theme_xxx/overwrite/view/htm/header.inc.htm | sort > /tmp/my_hooks.txt
diff /tmp/core_hooks.txt /tmp/my_hooks.txt   # 应无差异
```

### 4.3 保护路径（禁止覆盖）

`model/plugin.func.php#L615-L619` 定义了白名单，以下路径 `overwrite` 会被静默拦截并记日志（`plugin_overwrite_error`）：

```
conf/  xiunophp/  lib/  admin/  api/  cli/  tool/
install/  log/  tmp/  upload/
index.php  model.inc.php  index.inc.php
```

主题插件只能覆盖 `view/htm/` 下的模板。想改核心逻辑？走 hook，不要想 overwrite。

### 4.4 改完必须清 `tmp/`

`_include()` 不比较 mtime，overwrite 改了模板不清缓存就不生效：
```bash
rm -f tmp/view_htm_*.htm
```

---

## 5. CSS 架构（主题插件的样式规范）

### 5.1 复用系统主题色，禁止硬编码

```css
/* ✅ 正确：引用系统 CSS 变量，跟随用户主题色 */
.moments-card-actions .btn-primary {
    background: var(--bs-primary);
    border-color: var(--bs-primary);
}
.moments-card {
    box-shadow: rgba(var(--bs-primary-rgb), 0.1) 0 6px 12px;
}

/* ❌ 错误：硬编码，用户切主题色时不变色 */
.moments-card-actions .btn-primary {
    background: #07c160;
}
```

### 5.2 dark 适配用 `[data-bs-theme="dark"]`，不要用 `prefers-color-scheme`

系统已用 `data-bs-theme` 属性覆盖系统偏好（支持用户手动切换优先），单独写 `@media (prefers-color-scheme: dark)` 会和系统切换打架。

参考 `xnx_theme_moments/static/css/moments.css`：
```css
:root {
    --moments-text: #333;
    --moments-card-bg: #fff;
    --moments-divider: #eee;
}
[data-bs-theme="dark"] {
    --moments-text: #e0e0e0;
    --moments-card-bg: #1e1e1e;
    --moments-divider: #333;
}
.moments-card {
    background: var(--moments-card-bg);
    color: var(--moments-text);
    border-color: var(--moments-divider);
}
```

**模式**：light 值放 `:root`，dark 覆盖值放 `[data-bs-theme="dark"]`，业务样式只引用变量。

### 5.3 主题 CSS 注入方式

通过 `hook/header_link_after.htm` 注入（编译后拼进 `header.inc.htm`）：
```php
<?php
if (!isset($GLOBALS['__moments_css_loaded'])) {
    $GLOBALS['__moments_css_loaded'] = true;
    $css_path = APP_PATH . 'plugin/xnx_theme_moments/static/css/moments.css';
    if (file_exists($css_path)) {
        echo '<link href="' . $conf['view_url'] . '../plugin/xnx_theme_moments/static/css/moments.css' . $static_version . '" rel="stylesheet">';
    }
}
?>
```
- `$GLOBALS` 守卫防重复加载。
- `file_exists` 守卫避免骨架阶段 static 文件缺失时 404。
- 引用用 `$conf['view_url'] . '../plugin/<dir>/static/...'`，禁止 `APP_PATH`/相对路径。
- 版本号用 `$static_version`（hook 内）或 `$conf['static_version']`（视图内），推荐 `filemtime()` 动态版本。

### 5.4 与核心组件协作

- 卡片统一用 `x-card` + `card` 组合（见 [14-plugin-admin-ui.md](14-plugin-admin-ui.md)），主题插件可覆盖 `x-card` 的视觉，但别删这个 class（功能插件可能依赖它）。
- 列表分隔用 `<hr>` 或间距，禁止 `border-bottom`。
- 不要用 `!important` 覆盖核心样式（会引发优先级战争），用更具体的选择器或 CSS 变量覆盖。

---

## 6. 主题插件不能做什么

| 禁止 | 原因 |
|---|---|
| 改功能逻辑（发帖/积分/权限） | 主题只管视觉，功能留给功能插件。越界会导致与功能插件冲突 |
| 覆盖保护路径（`conf/`/`lib/`/`admin/` 等） | 被 `plugin_find_overwrite()` 静默拦截 |
| 删 `<!--{hook xxx}-->` 标记 | 功能插件 hook 全废，不报错难排查 |
| 用 `prefers-color-scheme` 单独做 dark | 与系统 `data-bs-theme` 切换打架 |
| 硬编码主题色 | 用户后台切主题色时插件不变色 |
| 改主题切换脚本（`view/htm/header.inc.htm#L173-L189`）的 `defer` 行为 | FOUC 闪烁 |
| 与其他主题插件同时启用 | 互斥机制强制，只能启用一个 |

---

## 7. ★主题与功能插件的兼容性

这是主题插件设计的核心约束：**主题插件覆盖了模板，功能插件的 hook 还能不能注入？**

**能，前提是 overwrite 版本保留了 `<!--{hook xxx}-->` 标记。**

原理（见第 4.1 节）：`overwrite` 在 hook 编译**之前**替换源文件，替换后的文件再走 `plugin_compile_hooks()`。所以：

```
核心 header.inc.htm（含 hook 标记）
        ↓ plugin_find_overwrite() 替换
主题 overwrite/header.inc.htm（必须仍含 hook 标记）
        ↓ plugin_compile_hooks()
编译后 header.inc.htm（hook 标记 → 各功能插件 hook 内容已注入）
```

**兼容性检查清单**：
- [ ] 覆盖的每个核心模板，hook 标记数量与核心版一致（用第 4.2 节的 diff 自查）
- [ ] 新增的 hook 标记用规范格式 `<!--{hook xxx.htm}-->`（功能插件可借此注入主题区域）
- [ ] 不覆盖功能插件依赖的模板区段（如发帖表单 `<form>` 边界、右侧栏 `post_ref_thread_after.htm` 注入点）

**反过来**：功能插件开发者也要假设模板可能被主题插件覆盖，hook 注入点要落在核心模板的标准标记上，不要假设某个 `<div>` 一定存在。

---

## 8. 交付检查表（主题专属项）

通用检查表见 [../../SKILL.md](../../SKILL.md)，以下为主题插件额外必查项：

- [ ] `conf.json.type` = `"theme"`
- [ ] 目录名 `xxx_theme` 或 `xxx_theme_<变体>`（第二段 `theme`）
- [ ] `overwrites_rank` 是数组，`hooks_rank` 是对象（类型正确）
- [ ] 覆盖的每个核心模板，`<!--{hook xxx}-->` 标记与核心版一致（diff 自查）
- [ ] 未覆盖任何保护路径（`conf/`/`lib/`/`admin/` 等）
- [ ] CSS 颜色用 `var(--bs-primary)` / `var(--bs-primary-rgb)`，无硬编码主题色
- [ ] dark 适配用 `[data-bs-theme="dark"]`，无 `prefers-color-scheme`
- [ ] 主题 CSS/JS 通过 `header_link_after.htm` / `footer_js_after.htm` 注入，带 `file_exists` + 重复加载守卫
- [ ] 静态资源引用用 `$conf['view_url'] . '../plugin/<dir>/static/...'`
- [ ] 改 overwrite 后清 `tmp/view_htm_*.htm`
- [ ] 与一个功能插件（如签到）同时启用，验证功能插件 hook 在主题下正常显示

---

## 9. 失败排查（主题专属）

| 现象 | 排查 |
|---|---|
| 启用主题后功能插件全不显示 | overwrite 删了 `<!--{hook xxx}-->` 标记。diff 你的覆盖版与核心版的 hook 标记，补回 |
| 主题不生效 / 仍是原版 | 1. 清 `tmp/view_htm_*.htm`（`_include()` 不比较 mtime）2. 检查插件是否启用（db `bbs_plugin`）3. 检查 `overwrites_rank` 路径是否与核心路径完全一致 |
| 主题色不跟随后台设置 | CSS 硬编码了颜色，改用 `var(--bs-primary)` |
| dark 模式样式错乱 / 与系统切换打架 | 用了 `prefers-color-scheme`，改用 `[data-bs-theme="dark"]` |
| 首屏明暗闪烁（FOUC） | 主题切换脚本被加了 `defer`，必须内联同步执行（`view/htm/header.inc.htm#L171-L172`） |
| overwrite 被拦截（日志 `plugin_overwrite_error`） | 覆盖了保护路径，改用 hook 扩展 |
| 两个主题同时生效互相污染 | 主题插件应互斥，检查目录命名是否 `xxx_theme` 格式（误命名会被当成功能插件不互斥） |
| 升级主题后旧样式残留 | 清 `tmp/` + 递增 `conf/conf.php` 的 `static_version` + 硬刷新浏览器 |
| 启用主题后白屏 | overwrite 的模板有 PHP 语法错误（如 `<?php` 未闭合），或删了核心模板里必要的 PHP 变量初始化段（如 `G('conf')`） |

---

## 10. 速查：主题插件 vs 功能插件开发差异

| 步骤 | 功能插件 | 主题插件 |
|---|---|---|
| `conf.json.type` | `"plugin"` | `"theme"` |
| 命名 | `xxx_<功能>` | `xxx_theme[_<变体>]` |
| 互斥 | 按功能标识 | 主题间全互斥 |
| 核心手段 | `hook/` + `model/` Service + `route/` | `overwrite/` + `hook/header_link_after.htm` + `static/css/` |
| 改模板 | 尽量用 hook 注入，不覆盖 | `overwrite/` 覆盖（★保留 hook 标记） |
| 改功能 | ✅ | ❌ |
| CSS 颜色 | 用 `var(--bs-primary)` | 用 `var(--bs-primary)` |
| dark 适配 | `[data-bs-theme="dark"]` | `[data-bs-theme="dark"]` |
| install/uninstall | 相同 | 相同 |
| 清 tmp | 相同 | 相同 |

> 通用开发流程、hook 目录、API 速查、安全规范见 [README.md](README.md) 与 [../../SKILL.md](../../SKILL.md)。
