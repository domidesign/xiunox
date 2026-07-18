# 02 插件目录结构与 conf.json

> 关键源码：`model/plugin.func.php`（`plugin_read_by_dir` 在 560-587）、`plugin/xnx_tag/`、`plugin/xnx_checkin/`

---

## 1. 标准目录结构

只有 `conf.json` 必需，其余按需创建。

```
plugin/my_plugin/
├── conf.json              # ✅ 必需：插件清单
├── icon.png               # 📌 推荐：96x96 图标
├── install.php            # 安装脚本（建表、写默认 setting）
├── upgrade.php            # 升级脚本（数据库结构变更走此机制）
├── uninstall.php          # 卸载脚本（删表、删 setting）—— 统一用此名
├── setting.php            # 后台设置页逻辑（出现后 admin 自动出"设置"按钮）
├── hook/                  # Hook 文件目录（文件名 = hook 点名，含扩展名）
│   ├── lang_zh_cn_bbs.php       # 语言扩展 hook（特殊校验，见下）
│   ├── model_inc_file.php       # 注册插件 Service/Model（拼进 model.inc.php）
│   ├── index_route_case_end.php # 注册新路由（拼进 index.inc.php 的 switch）
│   ├── thread_subject_after.htm # 模板注入 hook（输出 HTML）
│   └── model_thread_create_end.php  # 模型层 PHP hook
├── overwrite/             # 覆盖文件（镜像 app 根路径，慎用）
│   └── view/htm/header.inc.htm
├── route/                 # 插件自己的路由处理器
│   └── my_page.php
├── view/
│   ├── htm/               # 插件模板
│   │   ├── my_page.htm
│   │   └── setting.htm
│   ├── css/               # 插件 CSS
│   └── js/                # 插件 JS
├── model/                 # 插件 Service/Model 类
│   └── MyService.php
├── lang/                  # 独立语言包（按需）
│   └── zh-cn.php
└── api.php                # AJAX/API 入口（按需）
```

### 文件名约定（重要）

| 文件 | 命名规则 |
|---|---|
| `install.php` / `uninstall.php` | 固定名。⚠️ 旧插件有 `unstall.php`，**新插件统一 `uninstall.php`** |
| `setting.php` | 固定名。存在则 admin 出"设置"按钮，URL = `plugin-setting-<dir>` |
| `hook/<name>` | **文件名必须 = hook 点标记里的名字，含扩展名**。`thread_subject_after.htm` ≠ `thread_subject_after.php` |
| `icon.png` | 96x96，自动显示在插件列表 |

---

## 2. conf.json 全字段

```json
{
    "name": "插件名称",
    "brief": "插件简介，支持 HTML",
    "version": "1.0.0",
    "bbs_version": "1.0",
    "installed": 0,
    "enable": 0,
    "hooks_rank": {
        "model_inc_file.php": 10,
        "thread_subject_after.htm": 10
    },
    "overwrites_rank": {
        "view/htm/header.inc.htm": 10
    },
    "dependencies": {
        "xn_search": "1.0"
    },
    "type": "plugin",
    "author": "作者",
    "id": "my_plugin"
}
```

### 字段表

| 字段 | 类型 | 必需 | 默认 | 说明 |
|---|---|---|---|---|
| `name` | string | ✅ | — | 显示名 |
| `brief` | string | ✅ | — | 简介（允许 HTML） |
| `version` | string | ✅ | `'1.0.0'` | 插件版本，必须三位制（X.Y.Z，如 "1.0.0"） |
| `bbs_version` | string | ✅ | `'1.0'` | 兼容的核心主次版本，必须两位制（X.Y，如 "1.0"）。语义：声明兼容核心 X.Y.0-X.Y.x 分支。不能高于当前核心主次版本 |
| `installed` | int | ✅ | `0` | 系统维护，勿手填 |
| `enable` | int | ✅ | `0` | 系统维护，勿手填 |
| `hooks_rank` | object | ❌ | `{}` | hook 排序权重 |
| `overwrites_rank` | object | ❌ | `{}` | 覆盖优先级 |
| `dependencies` | object | ❌ | `{}` | 依赖（值是最低版本，但**实际不比较版本**，只看在不在/启没启） |
| `capabilities` | array | ❌ | `[]` | 权限沙箱声明，扫描器已强制校验格式（要求 `lowercase.dots` 数组）。规则详见 06-ai-collaboration.md 第六节 `capabilities_format` |
| `type` | string | ❌ | `"plugin"` | `"plugin"` 或 `"theme"` |
| `author` | string | ❌ | — | 作者 |
| `id` | string | ❌ | — | 旧式 id |

### hooks_rank 排序规则

当多个插件注册同一 hook 点时，按 `hooks_rank` 值**降序执行**（值大先跑，默认 0）。

```json
"hooks_rank": {
    "model_inc_file.php": 10,
    "thread_subject_after.htm": 10
}
```

- 数值越大 → 越早执行
- 不在表里的 hook → 默认权重 0
- 仅当**执行顺序对你重要**时才需要填（如多个插件都改同一模板块）

### overwrites_rank 规则

同一文件被多个插件 overwrite 时，**值最大的生效**（与 hooks_rank 相反：覆盖是"赢家通吃"）。

```json
"overwrites_rank": {
    "view/htm/header.inc.htm": 10
}
```

---

## 3. 特殊 hook 文件（名字固定，有特殊语义）

这几个 hook 不是普通注入点，编译器对它们有专门处理：

### `model_inc_file.php` —— 注册插件 Service

内容拼进 `model.inc.php` 的 `$include_model_files` 数组。**每行必须是一个逗号结尾的文件路径**：

```php
<?php exit;
APP_PATH.'plugin/my_plugin/model/MyService.php',
```

这样插件的 `MyService` 类就全局可用了（在路由/hook/setting 里 `MyService::xxx()` 直接调）。

### `index_route_case_end.php` —— 注册新路由

内容拼进 `index.inc.php` 的 `switch($route)`：

```php
<?php exit;
case 'mypage': include APP_PATH.'plugin/my_plugin/route/my_page.php'; break;
```

之后 `url("mypage")`、`url("mypage-list-1")` 就能访问。

### `lang_zh_cn_bbs.php` —— 语言扩展（严格校验）

**每行非空非注释内容必须匹配 `$lang['key'] = value;`**，否则被跳过并记日志（`xn_log(...,'lang_error')`）：

```php
<?php
// 正确
$lang['my_plugin_title'] = '我的插件';
$lang['my_plugin_count'] = '%d 个';

// ❌ 错误，会被跳过
echo 'something';
$config['x'] = 1;
```

语言键用法：`lang('my_plugin_title')`、`lang('my_plugin_count', array(5))` → `"5 个"`。

### 独立语言包 `lang/zh-cn.php`（与 hook 语言扩展的区别）

`lang/zh-cn.php` 是**独立语言包文件**，不通过 hook 注入，与上面的 `lang_zh_cn_bbs.php` 是两套机制：

| 机制 | 加载方式 | 适用场景 |
|---|---|---|
| `hook/lang_zh_cn_bbs.php` | 编译期自动合并到全局 `$lang` 数组，全站可 `lang('key')` 取 | 少量文案、前端/后台都要用的键 |
| `lang/zh-cn.php` | 需手动 `include`，作用域局部 | 插件内部文案量大、按需加载（如只在某个路由页用） |

手动加载示例：

```php
$my_lang = include APP_PATH.'plugin/my_plugin/lang/zh-cn.php';
// $my_lang 是数组，可按需 merge 到全局 $lang 或直接取用
foreach ($my_lang as $k => $v) {
    $lang[$k] = $v;
}
```

**推荐**：少量文案优先用 hook 注入（`lang_zh_cn_bbs.php`），自动加载零成本；只有当文案量较大、且只需在特定页面使用时，才用独立语言包按需加载。

### `.htm` vs `.php` hook

| 扩展名 | 拼进哪里 | 内容 |
|---|---|---|
| `.htm` | 编译后的模板（view/htm） | HTML + `<?php ?>` 混合 |
| `.php` | PHP 源码（model/route/index） | 纯 PHP 逻辑 |

### PHP hook 的 `<?php exit;` 守卫

`.php` hook 文件**应该**以 `<?php exit;` 开头，防止被人通过 URL 直接访问触发执行。编译器会自动剥掉这行（`plugin.func.php:520-531`）：

```php
<?php exit;
// 你的逻辑，会运行在 hook 点
$tid = param(2, 0);
MyService::doSomething($tid);
```

裸 `<?php`（无 exit）也能用，但不推荐。

---

## 4. upgrade.php 升级脚本

> 关键源码：`model/plugin.func.php`（`plugin_init` 幂等检测 `bbs_plugin.version`）、`admin/route/plugin.php`（升级入口）、`admin/view/htm/plugin_list.htm`（"需升级"徽章）

### 何时创建

当插件发布新版本需要**数据库结构变更**（加字段、改字段、加索引、建新表）时，必须创建 `upgrade.php`。纯代码逻辑变更（PHP/JS/CSS/模板）不需要 `upgrade.php` —— 用户覆盖文件后即生效。

### conf.json.version vs bbs_plugin.version 对比机制

XIUNOX 用两个版本号协同管理升级：

| 版本号 | 存放位置 | 作用 |
|---|---|---|
| `conf.json.version` | 插件清单文件 | 声明"当前代码版本"，三位制 X.Y.Z |
| `bbs_plugin.version` | 数据库 `bbs_plugin` 表 | 记录"已安装版本"，由 `upgrade.php` 执行后同步 |

- `plugin_init()` 启动时**幂等检测** `bbs_plugin` 表是否含 `version` 字段，缺失则 `ALTER TABLE` 补齐（结果缓存 24h，避免每次启动都查）
- 后台插件列表对比 `conf.json.version` 与 `bbs_plugin.version`，**不一致时显示「需升级」红色徽章 + 红色按钮**
- 用户点击升级后执行 `upgrade.php`，并通过 `plugin_db_set_version()` 将 `conf.json.version` 同步写入 `bbs_plugin.version`，徽章消失

### 幂等字段迁移示例

参考 `plugin/xnx_duel/upgrade.php`、`plugin/xnx_tag/upgrade.php` 风格，**用 `SHOW COLUMNS LIKE` 检查字段是否存在**，避免重复 `ALTER TABLE` 报错：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');
global $db, $conf;

// 1.0.1 升级：为已安装用户补齐 featured 字段
$check = db_sql_find_one("SHOW COLUMNS FROM {$tablepre}xnx_tag LIKE 'featured'");
if (empty($check)) {
    db_exec("ALTER TABLE {$tablepre}xnx_tag ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER threads");
}

// 清理 model.min.php 编译缓存，确保本插件 Service 类被重新加载
if (isset($conf['tmp_path']) && function_exists('xn_unlink')) {
    @xn_unlink($conf['tmp_path'].'model.min.php');
}
```

### 末尾必须清理 model.min.php

每次 `upgrade.php` 执行末尾**必须**清理编译缓存，否则新增/变更的 Service 类不会生效：

```php
@xn_unlink($conf['tmp_path'].'model.min.php');
```

### 禁止模式

⚠️ **不要在 `install.php` 或 `setting.php` 里加 `SHOW COLUMNS` + `ALTER TABLE` 自愈代码**。结构变更一律走 `upgrade.php` 幂等迁移：

| ❌ 禁止 | ✅ 正确 |
|---|---|
| `install.php` 里 `SHOW COLUMNS` 检查老字段并 `ALTER` | `install.php` 只建表，老用户走 `upgrade.php` |
| `setting.php` 每次访问都 `ALTER TABLE` | `setting.php` 只读写配置，结构变更在 `upgrade.php` 一次性完成 |

### UI 徽章触发流程

1. `admin/route/plugin.php` 读取 `bbs_plugin.version` 与 `conf.json.version`
2. 不一致 → `admin/view/htm/plugin_list.htm` 检测 `need_upgrade` 标志显示红色徽章 + 红色"升级"按钮
3. 用户点击 → POST 到 `plugin-upgrade-<dir>` → 执行 `upgrade.php` → `plugin_db_set_version()` 同步版本

### 跨文件参考

完整 `upgrade.php` 示例见 `plugin/xnx_duel/upgrade.php`（多版本累积迁移 + 题库种子）、`plugin/xnx_tag/upgrade.php`（单字段补齐最小示例）。

---

## 5. API 入口文件 api.php

插件目录下的 `api.php` 用于处理 AJAX / API 请求（如签到打卡、骰子抽奖、数据查询）。

### 访问方式

通过 `hook/index_route_case_end.php` 注册路由 case，将 `api` 路由指向插件 `api.php`：

```php
<?php exit;
case 'my_plugin_api': include APP_PATH.'plugin/my_plugin/api.php'; break;
```

之后 `url("my_plugin_api")`、`url("my_plugin_api-action-1")` 即可访问。

### CSRF 处理

**api.php 中无需重复写 CSRF 校验**。中央化 CSRF 校验在 `index.inc.php` 已对**非 GET 请求**统一调用 `CsrfService::check()`（`ai` 路由除外），api.php 在路由分发后执行，已自动通过校验。如需在 GET 请求中也做权限校验，应在 api.php 内自行检查用户登录态/权限。

### 返回格式约定

使用 `message($code, $msg, $data)` 统一返回 JSON：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

$op = param(2, 'list');
if ($op === 'list') {
    $list = MyService::getList();
    message(0, 'OK', $list);
} elseif ($op === 'create') {
    // POST 已通过 CsrfService::check()
    $r = MyService::create($_POST);
    $r ? message(0, '创建成功') : message(-1, '创建失败');
}
```

htmx 场景下 `message()` 会自动处理 HX-Redirect / HX-Trigger 等响应头，无需手动设置。

### 跨文件参考

API 速查（`message()`、`param()`、`url()` 等签名）见 `04-api-cheatsheet.md`。

---

## 6. 真实示例：xnx_tag 的文件清单

`plugin/xnx_tag/` 是一个完整、现代的插件（DB 表 + hook + Service + 路由 + 设置 + 语言）：

```
xnx_tag/
├── conf.json              # hooks_rank 全填 10
├── install.php            # 建 xnx_tag + xnx_thread_tag 表，写默认 setting
├── uninstall.php          # 删表删 setting
├── setting.php            # 后台页（CSRF + 多个子 action）
├── model/
│   └── TagService.php     # 业务逻辑
├── route/
│   ├── tag.php            # tag 聚合页路由
│   └── autocomplete.php   # 自动补全 AJAX
├── view/
│   └── htm/
│       ├── setting.htm    # 后台 UI
│       ├── tag.htm         # 聚合页
│       └── tags_all.htm    # 全部标签页
└── hook/
    ├── model_inc_file.php              # 注册 TagService
    ├── model_route_table_end.php        # 注册路由表
    ├── index_route_case_end.php        # 注册 tag 路由
    ├── lang_zh_cn_bbs.php              # xnx_tag_* 语言键（zh-cn）
    ├── lang_zh_tw_bbs.php              # 语言键（zh-tw）
    ├── lang_en_us_bbs.php              # 语言键（en-us）
    ├── thread_create_thread_start.php  # 发帖前置处理
    ├── thread_create_thread_end.php    # 发帖后同步标签关联
    ├── post_update_post_start.php      # 编辑后同步
    ├── post_start_init.htm             # 编辑器页注入 JS 数据
    ├── post_ref_thread_after.htm       # 引用帖后处理
    ├── thread_list_inc_start.htm       # 列表页前置注入
    ├── thread_list_inc_subject_after.htm  # 列表视图标签徽章
    ├── thread_subject_after.htm        # 标题下显示标签徽章
    ├── index_site_brief_after.htm      # 首页热标签组件
    ├── footer_js_after.htm             # 页脚注入 JS
    └── model_thread_delete_end.php     # 删帖时清理关联
```

> 以上为 `plugin/xnx_tag/` 实际文件清单，共 17 个 hook 文件（3 个语言 hook + 4 个 model/route hook + 10 个注入 hook）。

---

## 7. 最小可运行插件（3 个文件）

```
plugin/my_hello/
├── conf.json
└── hook/
    └── thread_subject_after.htm
```

`conf.json`：
```json
{
    "name": "Hello",
    "brief": "在帖子标题后加一句 hello",
    "version": "1.0.0",
    "bbs_version": "1.0",
    "installed": 0,
    "enable": 0,
    "hooks_rank": {},
    "overwrites_rank": {},
    "dependencies": {}
}
```

`hook/thread_subject_after.htm`：
```html
<span class="badge bg-secondary ms-2">Hello</span>
```

放进 `plugin/my_hello/`，后台插件列表就能看到，安装后帖子标题后会显示徽章。

---

## 小结

- **`conf.json` 是唯一必需文件**，字段表照着填
- **hook 文件名含扩展名，必须和标记一模一样**
- **`model_inc_file.php` / `index_route_case_end.php` / `lang_zh_cn_bbs.php` 是有特殊处理的固定 hook**
- **PHP hook 以 `<?php exit;` 开头**
- **新插件卸载脚本统一叫 `uninstall.php`**

---

## 3. zip 打包规范（发布插件必读）

后台「上传安装 / 升级插件」对 zip 包结构有严格检测，不符合规范会被拒绝并给出细分原因。打包时遵守以下规则：

### 3.1 支持的两种 zip 结构

```
结构 A（推荐）：conf.json 在 zip 根目录
my_plugin.zip
├── conf.json
├── install.php
└── hook/

结构 B：zip 内只有一层目录，conf.json 在该目录下
my_plugin.zip
└── my_plugin/
    ├── conf.json
    ├── install.php
    └── hook/
```

> 后台会按上传文件名（结构 A）或内层目录名（结构 B）作为插件目录名。两种都支持，**但不要混用**：zip 根目录同时有 conf.json 和子目录时，会走结构 A，子目录被忽略。

### 3.2 禁止事项

| 禁止项 | 后果 | 正确做法 |
|--------|------|---------|
| 多个顶层目录（如同时打包 `my_plugin/` + `docs/`） | 拒绝安装，提示「多个顶层目录」 | 只打包插件目录本身，文档单独发布 |
| macOS Finder 右键「压缩」生成 `__MACOSX/` 目录 | 后台会自动忽略 `__MACOSX`，但若同时打包了多个目录仍会被拒 | 用命令行 `zip -r -X my_plugin.zip my_plugin/` 跳过扩展属性 |
| conf.json 带 UTF-8 BOM 头（`EF BB BF`） | `json_decode` 返回 null，提示「BOM 头」 | 用 VS Code / Sublime，编码切到「UTF-8 无 BOM」保存 |
| conf.json 不是合法 JSON | 拒绝安装，提示「不是合法的 JSON」 | 用 [jsonlint.com](https://jsonlint.com/) 校验语法 |
| conf.json 为空文件或 `{}` | 拒绝安装，提示「内容为空」 | 至少包含 `name / version / bbs_version` 三个字段 |

### 3.3 推荐打包命令

```bash
# macOS / Linux
cd plugin/
zip -r -X my_plugin.zip my_plugin/
# -X 跳过 macOS 扩展属性（._ 前缀文件和 __MACOSX 目录）

# 检查 zip 内容（不应有 __MACOSX 或 ._ 前缀文件）
unzip -l my_plugin.zip
```

```bash
# Windows PowerShell
Compress-Archive -Path my_plugin -DestinationPath my_plugin.zip
```

### 3.4 错误信息对照表

上传失败时后台会返回带原因的提示，对照下表定位：

| 错误信息 | 根因 | 解决 |
|---------|------|------|
| `zip 包中缺少有效的 conf.json...：zip 包根目录及唯一子目录下都未找到 conf.json` | conf.json 不在 zip 内，或目录层级不对 | 检查 zip 结构是否符合 3.1 |
| `zip 包中缺少有效的 conf.json...。检测到 macOS Finder 生成的 __MACOSX 干扰目录` | 用了 Finder 右键压缩且 conf.json 不在根目录 | 用 `zip -r -X` 命令重打 |
| `zip 包内存在多个顶层目录（xxx, yyy）` | 同时打包了多个顶层目录 | 删除无关目录，只保留插件目录 |
| `zip 包中缺少有效的 conf.json...：conf.json 文件带 UTF-8 BOM 头（EF BB BF）` | 编辑器默认加了 BOM | 切到「UTF-8 无 BOM」保存 |
| `zip 包中缺少有效的 conf.json...：conf.json 文件不是合法的 JSON` | JSON 语法错误（缺逗号、多余逗号、引号未闭合等） | jsonlint.com 校验 |
| `zip 包中缺少有效的 conf.json...：conf.json 文件内容为空或不是 JSON 对象` | conf.json 是空文件或 `[]` | 至少写 `{}` + 必需字段 |

---

下一步：[03-hooks-catalog.md](03-hooks-catalog.md) 找你要的 hook 点。
