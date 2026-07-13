# 01 插件架构原理

> 关键源码：`model/plugin.func.php`、`xiunophp/misc.func.php`、`index.inc.php`、`model.inc.php`

---

## 1. 编译时合并（不是运行时分发）

Xiuno BBS 的插件系统是**编译时合并**，不是运行时事件分发器。这点和很多框架不同，必须先理解：

```
plugin/foo/hook/thread_subject_after.htm   ←─── 你写的 hook 文件
                    │
                    │ _include() 编译时
                    ▼
view/htm/thread.htm 里的  <!--{hook thread_subject_after.htm}-->
                    │
                    │ 被物理替换为 hook 文件内容
                    ▼
tmp/<hash>.php  ←─── 编译缓存，后续请求直接用
```

**没有** `add_hook()` / `register_hook()` 这类函数，Hook 注册完全靠**文件放置**：文件在 `plugin/<dir>/hook/<hook名>`，就等于注册了。编译期内联是主机制；此外也提供运行时分发 `plugin_hook($hookname, &$data)`（`plugin.func.php:742`，带 try/catch 错误隔离，单 hook 抛异常不阻断主流程）和向后兼容的 `xn_hook()`（`plugin.func.php:803`），仅支持 `.php` 类型 hook，`.htm` hook 走编译期内联。

### 标记语法（两种等价写法）

```php
// PHP 源码里（model/route/index.inc.php）
// hook model_thread_create_end.php

<!-- HTML 模板里（view/htm/*.htm） -->
<!--{hook thread_subject_after.htm}-->
```

编译时，`<!--{hook xxx}-->` 会被正则归一化成 `// hook xxx`（`plugin.func.php:427`），再用 `plugin_compile_srcfile_callback()` 把所有匹配的 hook 文件内容按 `hooks_rank` 排序后拼进去。hook 文件若以 `<?php exit;` 开头防直接访问，编译期会剥离首尾 PHP 标签（`plugin.func.php:520-531`），与裸 `<?php` 开头的 hook 文件分别走两个剥离分支，避免末尾换行被吞导致多 hook 拼接时注释相互吞噬。

---

## 2. 两种扩展方式

| 方式 | 用途 | 能否多个共存 | 选择原则 |
|---|---|---|---|
| **Hook（钩子）** | 在标记点注入代码，不改原文件 | ✅ 多插件可同点 | **优先用**，小范围增强 |
| **Overwrite（覆盖）** | 整文件替换 | ❌ 只有一个生效（`overwrites_rank` 最高者） | 最后手段，大改用 |

**Hook 是默认选择。** Overwrite 会和其它插件冲突，且让后续维护困难（原文件更新你感知不到）。

> ⚠️ **核心路径受 `protected_paths` 白名单保护**（`plugin.func.php:455-471`）：`conf/`、`xiunophp/`、`lib/`、`admin/`、`api/`、`cli/`、`tool/`、`install/`、`log/`、`tmp/`、`upload/`、`index.php`、`model.inc.php`、`index.inc.php` 命中即记日志 `plugin_overwrite_error` 并跳过该覆盖，防止插件劫持核心骨架。

---

## 3. `_include($srcfile)` —— 一切的入口

无论加载路由、模型还是模板，都走 `_include()`（`plugin.func.php:40`）：

```php
include _include(APP_PATH.'view/htm/header.inc.htm');
include _include(APP_PATH.'route/thread.php');
include _include(APP_PATH.'plugin/xnx_tag/setting.php');
```

`_include()` 干四件事：

1. **查 overwrite**：`plugin_find_overwrite($srcfile)` 找 `overwrites_rank` 最高的覆盖文件，有就用它替代原文件。
2. **合并 hook**：`plugin_compile_srcfile()` 把所有 `// hook <name>` 标记替换为对应 hook 文件内容（按 `hooks_rank` 升序）。
3. **模板安全检测**：`.htm` 文件编译后调用 `_include_scan_dangerous_php()`（`plugin.func.php:51-53` 调用，`plugin.func.php:72-109` 实现），扫描 `eval` / `assert` / `system` / `exec` / `shell_exec` / `passthru` / `proc_open` / `popen` / `create_function` 及 `preg_replace` `/e` 修饰符，命中则 `die()` 并记日志 `template_security_error`，防止主题/插件通过模板注入恶意代码。
4. **缓存**：编译结果经 `_atomic_write()` 原子写入 `tmp/`（先写临时文件再 rename，避免并发读到截断的空文件），返回缓存文件路径。后续请求直接用缓存（除非 `DEBUG > 1` 或缓存缺失）。

> ⚠️ **`_include()` 不比较源文件修改时间**（`plugin.func.php:44` 只检查 `is_file($tmpfile)`，不比较 mtime）：修改 `route/*.php`、`model/*.func.php`、`view/htm/*.htm` 后必须手动删除 `tmp/` 下对应的编译缓存，否则改动不生效。
>
> - 编译缓存命名规则：路径中 `/` 替换为 `_`，如 `route/thread.php` → `tmp/route_thread.php`、`view/htm/thread.htm` → `tmp/view_htm_thread.htm`
> - 清 `tmp/cache/` 无效（那是数据缓存目录，不是编译缓存目录）
> - 批量清理命令：`rm -f tmp/route_*.php tmp/model_*.func.php tmp/view_htm_*.htm`
> - `DEBUG > 1` 或 `cache_disable=1` 时会强制重编译，开发期可借此免手动清缓存

> ⚠️ **直接 `include` 一个源文件会绕过插件系统**，hook 不会生效。永远用 `_include()`。

---

## 4. 编译缓存（`tmp/`）

- 缓存目录：`tmp/`（配置项 `tmp_path`）
- 文件名：源文件路径扁平化后的 hash
- 失效时机：
  - `DEBUG > 1`：每次请求都重编译
  - 手动清 `tmp/`：`plugin_clear_tmp_dir()`（递归删 + 删 `tmp/model.min.php`）
  - 安装/卸载/启用/禁用插件时：自动清

### 什么时候要手动清缓存

| 改了什么 | 要不要清 `tmp/` |
|---|---|
| hook 文件内容（`.htm`/`.php`） | **要**（除非 DEBUG>1） |
| 新增/删除 hook 文件 | **要** |
| 改 `conf.json` 的 `hooks_rank`/`overwrites_rank` | **要** |
| 改插件自己的 `view/htm/*.htm` | 不要（走 _include 的会自动重编译） |
| 改 CSS | 浏览器硬刷新（Ctrl+F5） |

> 工作流硬性约定（见 `AGENTS.md`）：**修改模板 → 清理 `tmp/` 缓存**。

### model.min.php 合并加载机制

`model.inc.php:54-96` 对 model 文件有两条加载路径：

- **DEBUG > 0 或 `cache_disable=1`**：逐个 `include _include($model_files)`，走标准编译缓存
- **生产环境（DEBUG=0 且 `cache_disable` 未启用）**：走 `tmp/model.min.php` 合并加载——把所有 model 文件内容拼到一起一次性 include，减少 IO

合并文件的生成规则（`model.inc.php:60-94`）：

1. 只在 `tmp/model.min.php` **不存在**时重新生成（`is_file()` 检查，**不比较 mtime**）
2. 逐个读取 `_include($model_files)` 编译后的内容，剥离首尾 `<?php` / `?>` 标签后拼接
3. **插件 model 文件做语法预检**（核心 model 信任不检查）：路径含 `/plugin/` 的文件用 `token_get_all($raw, TOKEN_PARSE)` 检查**原始文件**（不是 `_include()` 编译后的 tmp 文件，因为编译后含 hook 注入的数组元素片段，不是完整 PHP 文件会误报）
4. `ParseError` 时跳过该文件并记日志 `plugin_syntax_error`，防止单个插件 Service 类语法错误导致整个 `model.min.php` 解析失败全站白屏

> ⚠️ **修改 `model/*.func.php` 后必须同步处理 `tmp/model.min.php`**（高频违规，已违反 2 次）：
>
> - 生产环境走合并加载，`tmp/model.min.php` 已存在就不会重新生成，**改了核心 model 函数也不会生效**
> - 解决方案二选一：① 直接删除 `tmp/model.min.php` 让核心重编译；② 手动编辑 `tmp/model.min.php` 同步改动
> - `plugin_clear_tmp_dir()` 会额外 `xn_unlink($conf['tmp_path'].'model.min.php')`（`plugin.func.php:282`），所以安装/卸载/启用/禁用插件时会自动清

> 💡 **与 hook 语法预检的区别**：`model.min.php` 的 `token_get_all` 预检**仍在使用**（检查的是完整 PHP 文件），与 `plugin_compile_srcfile_callback` 中已废弃的 hook 语法预检不同。hook 预检废弃原因详见 [07-runtime-safety.md](07-runtime-safety.md) 第 3.4 节。

---

## 5. 插件生命周期

5 个状态动作，全部在 `admin/route/plugin.php` + `model/plugin.func.php` 实现：

```
未安装 ──install──► 已安装·启用 ──disable──► 已安装·禁用
  ▲                    │                        │
  │                    └──── enable ◄───────────┘
  │
  └──────────────── uninstall（卸载） ────────────┘
                                  （从 已安装 状态）
                                  ── upgrade ──► 已安装·启用（版本同步）
```

### install（安装）

控制器流程（`admin/route/plugin.php:161-199`）：

1. **CSRF 校验**：`CsrfService::check()`
2. **并发锁**：`plugin_lock_start()`（基于 `xn_lock_start`，防止同插件并发安装/卸载）
3. **预扫描拦截**：`PluginScanner::scanBeforeInstall($dir)` 跑兼容性扫描，Fatal 级问题拦截安装（除非 URL 带 `?force=1`），Warning 级仅提示
4. **依赖检查**：`plugin_check_dependency($dir, 'install')`，缺依赖则拦截
5. **写状态**：`plugin_install($dir)`（`plugin.func.php:332`）—— 写 `conf.json` 的 `installed=1, enable=1`（`file_replace_var()`），写 `bbs_plugin` 表（`plugin_db_init()` + `plugin_db_set_installed(1)` + `plugin_db_set_enable(1)` + `plugin_db_set_version()` 同步 conf.json.version 到 db.version），最后 `plugin_clear_tmp_dir()` 清缓存
6. **执行 install.php**：若 `plugin/<dir>/install.php` 存在，`require_once lib/xn_safe_io.php` 注入安全 IO 包装并注入 `$plugin_dir` 变量后，`include _include($installfile)`（走编译）—— 这是**插件自己建表/写默认设置**的地方
7. **释放锁**：`plugin_lock_end()`
8. **同类互斥清理**：安装完成后，对 `_theme_` 目录名的插件自动卸载其它 `_theme_` 插件；非主题插件按目录名首 `_` 之后的部分作为 suffix，自动卸载同 suffix 的其它插件（`admin/route/plugin.php:203-222`）

### uninstall（卸载）

`plugin_unstall($dir)`（`plugin.func.php:364`，函数名 `unstall` 是历史遗留拼写，仍在 `plugin.func.php` 中未改名）：镜像 install，flags 归零 + 执行卸载脚本。**插件文件名必须用 `uninstall.php`**（标准拼写），核心 `admin/route/plugin.php:251-255` 卸载入口已改为优先找 `uninstall.php`，找不到才回退旧拼写 `unstall.php`（向后兼容旧插件）。**新插件禁止用 `unstall.php`**。同样走 `plugin_lock_start/end` + CSRF + `xn_safe_io.php` 安全包装。

### enable / disable（启用/禁用）

只切 `enable` flag，不执行 install/uninstall 脚本，但仍走 CSRF + 并发锁 + 依赖检查（禁用时若被别人依赖会拦截）。

### upgrade（升级）

检测机制（`admin/route/plugin.php:44-55`）：列表页对每个已安装插件检测 `has_upgrade_file`（是否存在 `upgrade.php`）和 `need_upgrade`（`conf.json.version` 与 `db.version` 不一致），不一致则显示"需升级"按钮。

执行流程（`admin/route/plugin.php:339-374`）：CSRF → 并发锁 → 依赖检查 → `plugin_install($dir)`（重置 installed/enable 并通过 `plugin_db_set_version()`（`plugin.func.php:672`）同步版本）→ `include _include($upgradefile)` 执行 `upgrade.php`（同样注入 `xn_safe_io.php` 和 `$plugin_dir`）→ 释放锁。

> 上传 zip 升级走 `action=upload`（`admin/route/plugin.php:394-671`）：自动判断全新安装还是升级，升级前自动禁用 + 备份旧版本到 `plugin/{dir}.bak/`，执行 `upgrade.php` 失败则回滚备份。

实践中 `xnx_duel`、`xnx_icon`、`xnx_lottery`、`xnx_status` 等插件已用 `upgrade.php` 做幂等字段迁移。

### 安装前扫描（`PluginScanner`）

`lib/PluginScanner.php` 的 `scanBeforeInstall($dir)` 在安装前跑：
- 检测 jQuery API、缺失 CSRF、Alpine.js、`bbs_version < 4.5`
- **Fatal 级问题会拦截安装**（除非 URL 带 `?force=1`）
- Warning 级仅提示

> 写插件时直接对照 [06-ai-collaboration.md](06-ai-collaboration.md) 的检查表，能过扫描器。

### xn_safe_io.php 注入的安全 IO 函数

插件 `install.php` / `uninstall.php` / `upgrade.php` / `setting.php` 执行前，核心会 `require_once lib/xn_safe_io.php` 注入一组安全 IO 包装函数（`admin/route/plugin.php:194/258/358/390`），同时注入 `$plugin_dir` 变量标识当前插件目录。插件代码应优先使用这些函数替代原生 PHP 文件操作，限制可写范围在 `tmp/`、`upload/`、`log/`、`plugin/{自身}/` 四个白名单前缀内：

| 安全函数 | 包装的原生函数 | 越界行为 |
|---|---|---|
| `xn_safe_path_check($path, $plugin_dir)` | 路径校验（不直接操作文件） | 不在白名单返回 `false` |
| `xn_safe_write($path, $content, $plugin_dir)` | `file_put_contents()` | 记日志 `plugin_io_blocked_error` 并返回 `false` |
| `xn_safe_unlink($path, $plugin_dir)` | `unlink()` | 记日志 `plugin_io_blocked_error` 并返回 `false` |
| `xn_safe_rmdir($path, $plugin_dir)` | 递归 `rmdir()` + `unlink()` | 记日志 `plugin_io_blocked_error` 并返回 `false` |
| `xn_safe_fopen($path, $mode, $plugin_dir)` | `fopen()` | 写模式（含 `w`/`a`/`x`/`c`）越界记日志并返回 `false`；读模式不校验 |

> ⚠️ 这些安全函数是**可选**的——插件代码仍可调用原生 `file_put_contents()` / `unlink()`（PHP 不会强制拦截），但核心推荐走安全包装，越界写操作会被记日志。白名单不包含 `conf/`、`xiunophp/`、`lib/`、`admin/` 等核心目录，防止插件劫持核心文件。

---

## 6. 依赖

`conf.json` 声明：

```json
"dependencies": {
    "xn_search": "1.0"
}
```

- `plugin_dependencies($dir)`：检查依赖是否**存在、启用、版本满足约束**（`plugin.func.php:173`）
- **版本号会被语义化比较**：`plugin_version_satisfies($dep_version, $constraint)`（`plugin.func.php:204`）支持 npm 风格约束 `>=`、`<=`、`>`、`<`、`=`、`^`（兼容主版本）、`~`（兼容次版本）、`*`（任意）；`"1.0"` 被当作精确版本用 `version_compare()` 比较。无法解析的约束默认通过。
- `plugin_check_dependency()`：安装/启用时缺依赖或版本不满足会拦截；卸载/禁用时若被别人依赖也会拦截。

实践中本仓库内置插件**都没有声明依赖**，机制可用但极少用。

---

## 7. 数据库持久化

状态存两处（冗余但一致）：

| 位置 | 内容 |
|---|---|
| `plugin/<dir>/conf.json` | `installed`、`enable`、`version` 字段 |
| `bbs_plugin` 表 | `dir, name, type, installed, enable, version, install_time, enable_time, ...` |

表结构见 `install/install.sql:469`。`type`：0=plugin，1=theme。`version` 字段记录已安装版本号，用于升级检测（`conf.json.version` 与 `bbs_plugin.version` 不一致即"需升级"）。

> ⚠️ **`plugin_init()` 启动时幂等检测 `bbs_plugin.version` 字段**（`plugin.func.php:129-137`）：用 `cache_get('plugin_version_field_checked')` 缓存 24h，缓存失效时 `SHOW COLUMNS` 检测，缺失则 `ALTER TABLE ADD COLUMN version varchar(32)` 自动补齐，保证存量数据库平滑升级。

---

## 8. 一个请求的完整流程（理解 hook 在哪触发）

```
index.php
  ├─ 定义 APP_PATH / DEBUG，加载 xiunophp/
  ├─ 加载 model/plugin.func.php
  └─ _include(model.inc.php)         ← 加载所有 model，触发 model_inc_file.php hook（插件注册自己的 Service）
     └─ _include(index.inc.php)      ← 主入口
        ├─ session_start, 解析 $user/$gid/$group/$forumlist
        ├─ $route = param(0)         ← URL 第一段
        └─ switch($route)
           ├─ case 'index':  _include(route/index.php)
           ├─ case 'thread': _include(route/thread.php)
           ├─ ...
           ├─ // hook index_route_case_end.php   ← 插件注册新路由的固定位置
           └─ default: ...
                              │
                              ▼
           route 内部：权限检查 → 调 model → _include(view/htm/xxx.htm) 渲染
                                              ↑ 模板里到处都是 <!--{hook ...}-->
```

---

## 小结

- **Hook 靠文件名匹配，Overwrite 靠路径匹配，两者都在编译期合并到 `tmp/`**
- **永远 `_include()`，永不裸 `include`**
- **改 hook/conf.json 后清 `tmp/`**
- **install.php 建表、uninstall.php 删表、setting.php 后台页、hook/ 注入逻辑**
- **模型三层命名：调单下划线业务层，别碰双下划线原始层**

下一步：[02-plugin-structure.md](02-plugin-structure.md) 看具体怎么组织文件。
