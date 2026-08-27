# 09 model 文件加载机制重构（v1.1.4）

> 变更时间：2026-07-24
> 影响范围：核心架构 + 所有插件
> 版本要求：XIUNOX v1.1.4+

---

## 一、背景

### 1.1 旧机制（v1.1.3 及之前）

v1.1.3 及之前版本使用 `tmp/model.min.php` 合并加载机制：

```
所有 model/*.func.php + 插件 model/*.php
    ↓
合并成单个文件 tmp/model.min.php
    ↓
一次性 include 加载
```

**设计初衷**：减少磁盘 I/O 次数，提升 PHP 5.x 时代的加载性能。

### 1.2 存在的问题

随着 PHP 8 + OPcache 的普及，合并加载的收益可忽略，但带来了大量稳定性问题：

| 问题 | 影响 | 严重程度 |
|---|---|---|
| **加载顺序不确定** | 类/函数依赖关系可能因合并顺序不同而断裂，生产环境与开发环境行为不一致 | ⚠️ 高 |
| **单插件语法错误全站白屏** | 一个插件的 model 文件有语法错误，导致整个 model.min.php 解析失败，全站 500 | 🔴 致命 |
| **并发重建文件损坏** | 多请求同时触发重建时，可能读到截断的空文件，导致随机 fatal | 🟠 中 |
| **缓存陈旧难以排查** | 插件升级后 model.min.php 未及时重建，旧代码继续运行，问题隐蔽 | 🟠 中 |
| **调试困难** | 错误行号对应合并后的文件，无法直接定位到源文件 | 🟡 低 |
| **OPcache 命中率低** | 单个大文件修改后整个文件重新编译，不如小文件粒度细 | 🟡 低 |

### 1.3 触发事件

2026-07-24 连续出现多起因 model.min.php 引发的故障：
- 字段名修正后缓存未更新，导致 SQL 错误反复出现
- xnx_hidden 插件因 Service 类未加载连续崩溃 3 次，触发自动禁用
- 开发环境正常、生产环境报错的"灵异"问题频繁出现

---

## 二、新机制（v1.1.4+）

### 2.1 逐文件加载

v1.1.4 起彻底废弃 `model.min.php` 合并机制，改为**逐文件 include**：

```php
// model.inc.php
foreach ($include_model_files as $model_files) {
    include _include($model_files);
}
```

所有环境（DEBUG / 生产）统一走相同逻辑，行为一致。

### 2.2 性能保障

**PHP 8 + OPcache 热身后，逐文件 include 的性能与合并加载无感知差异**，原因：

1. **OPcache 缓存字节码**：首次加载后 PHP 把编译后的字节码存在共享内存中，后续请求直接执行字节码，不需要重新读取磁盘文件、重新解析、重新编译
2. **内存中执行**：OPcache 热身后，include 的开销从"磁盘 I/O + 语法解析 + 编译"降低为"哈希表查找 + 指针跳转"
3. **27 个核心 model + N 个插件 model**：即使 100 个文件，include 的总开销也在微秒级，远小于一次 DB 查询的毫秒级开销

### 2.3 收益

| 改进 | 说明 |
|---|---|
| ✅ **错误隔离** | 单个插件语法错误只影响该插件自身，不会导致全站白屏 |
| ✅ **加载顺序确定** | 按数组顺序逐个加载，开发/生产环境行为完全一致 |
| ✅ **无并发重建问题** | 每个文件独立编译缓存，互不影响 |
| ✅ **调试友好** | 错误行号直接对应源文件，堆栈清晰 |
| ✅ **OPcache 粒度更细** | 修改一个文件只重新编译该文件，其他文件缓存继续有效 |
| ✅ **代码更简洁** | 删除了合并/重建/清理的大量样板代码 |

---

## 三、插件开发者注意事项

### 3.1 已移除的操作

以下操作**不再需要**，可从插件代码中删除：

1. **install.php 中清理 `tmp/model.min.php`**
   ```php
   // ❌ 旧代码（已废弃，可删除）
   if (isset($conf['tmp_path']) && function_exists('xn_unlink')) {
       @xn_unlink($conf['tmp_path'].'model.min.php');
   }
   ```

2. **upgrade.php 中清理 `tmp/model.min.php`**（同上）

3. **任何手动清理 model.min.php 的代码**

核心已在插件 install / upgrade / enable / disable 时自动调用 `plugin_clear_tmp_dir()` 清理全部编译缓存。

### 3.2 仍需遵守的规则

1. **`_include()` 编译缓存仍需清理**
   - 修改 `route/*.php`、`model/*.func.php`、`view/htm/*.htm` 后，需清理 `tmp/` 下对应的编译缓存
   - `_include()` 只检查缓存文件是否存在，不比较源文件修改时间；但 DEBUG > 1 或 `$conf['cache_disable']` 开启时会触发重新编译
   - 批量清理：`rm -f tmp/route_*.php tmp/model_*.func.php tmp/view_htm_*.htm`

2. **lib 类不会自动加载**
   - 项目无 spl_autoload，`lib/` 下的类不会自动加载
   - 调用核心 Service（如 `CreditsService`、`UserBanService`）前必须手动 include：
     ```php
     if (!class_exists('CreditsService')) {
         include_once APP_PATH . 'lib/CreditsService.php';
     }
     ```
   - 访问静态属性/常量前必须先确保类已加载

3. **model 文件仍走 `_include()`**
   - model 文件通过 `_include()` 加载，支持 hook 注入和编译缓存
   - 插件 Service 类可通过 `hook/model_inc_file.php` 注册（可选，详见 3.4）

### 3.3 兜底加载逻辑（v1.1.4+）

**背景**：仅靠 `model.inc.php` 的 `model_inc_file.php` hook 注入加载 Service 类，存在 tmp 缓存陈旧风险——`tmp/model.inc.php` 未重新编译（hook 注入丢失），但 `tmp/model_xxx.func.php` 重新编译注入了 Service 调用，两者不一致会导致 `Class not found` fatal 崩溃，触发插件自动禁用机制。

**机制**：`index.php` / `api/v1/index.php` 启动时，遍历 `plugin_paths_enabled()` 返回的已启用插件，扫描其 `model/*.php` 文件，用 `class_exists($class, false)` 守卫后 `include_once` 加载：

```php
$_plugin_paths_fallback = plugin_paths_enabled();
foreach ($_plugin_paths_fallback as $_path_fb => $_pconf_fb) {
    $_model_dir_fb = $_path_fb . '/model';
    if (!is_dir($_model_dir_fb)) continue;
    foreach (glob($_model_dir_fb . '/*.php') as $_service_file_fb) {
        // 跳过 xiuno 原版 *.func.php 函数库文件：由 model_inc_file hook 注入加载，不自动扫描
        if (substr($_service_file_fb, -9) === '.func.php') continue;
        $_class_name_fb = ucfirst(basename($_service_file_fb, '.php'));
        if (!class_exists($_class_name_fb, false)) {
            include_once $_service_file_fb;
        }
    }
}
```

**关键规则**：

| 规则 | 说明 |
|---|---|
| **`model/` 只放 Service 类** | 类名 = 文件名（如 `MyService.php` → `class MyService`），由 `class_exists` 守卫推断类名去重 |
| **禁止 `model/*.func.php`** | xiuno 原版函数库写法，自动扫描会**跳过**不加载（避免与 hook 注入冲突导致函数重声明 fatal）；函数库应改为静态类方法 |
| **`hook/model_inc_file.php` 可选** | 兜底逻辑已自动覆盖，hook 注入是冗余的双保险；保留可应对兜底逻辑失效的极端场景，但不强制 |
| **类名必须与文件名一致** | 否则 `class_exists(ucfirst(basename), false)` 守卫失效，可能导致重复 include（但 `include_once` 会兜底去重） |

**第三方插件从 xiuno 原版迁移到 XIUNOX**：

1. `model/xxx.func.php` 全局函数库 → 改写为 `model/XxxService.php` 静态类方法
2. 删除 `hook/model_inc_file.php`（或保留作为双保险，但路径需指向新的 Service 类）
3. 调用点 `xxx_func()` → `XxxService::func()`

> 📖 详见 [02-plugin-structure.md §model_inc_file.php](02-plugin-structure.md)

### 3.4 代码迁移清单

| 检查项 | 操作 |
|---|---|
| install.php 中有 `model.min.php` 清理代码 | 删除 |
| upgrade.php 中有 `model.min.php` 清理代码 | 删除 |
| setting.php 中有 `model.min.php` 清理代码 | 删除 |
| 文档/注释中提到「生产环境走 model.min.php」 | 更新为「项目无 spl_autoload，lib 类不会自动加载」 |
| 调用 lib 类前有 `class_exists` 守卫 | 保持（仍需遵守） |

---

## 四、核心修改概览

### 4.1 修改的核心文件

| 文件 | 修改内容 |
|---|---|
| `model.inc.php` | 删除 model.min.php 合并分支，统一走逐文件 include |
| `model/plugin.func.php` | 删除 `plugin_clear_tmp_dir()` 中清理 model.min.php 的代码 |
| `lib/ErrorHandler.php` | 删除错误自愈逻辑中清理 model.min.php 的代码 |
| `lib/OnlineUpgradeService.php` | 更新注释，删除对 model.min.php 的引用 |

### 4.2 修改的插件文件

共清理 61 个插件文件中的 model.min.php 引用：
- 所有插件的 `install.php`：删除清理代码
- 所有插件的 `upgrade.php`：删除清理代码
- 所有插件的 `uninstall.php`：删除清理代码
- 部分插件的 `setting.php` / `model/*.php`：删除相关注释和代码

---

## 五、向后兼容性

### 5.1 插件兼容性

- **旧插件仍可正常运行**：install/upgrade 中残留的清理代码只是无效操作，不会报错
- 建议逐步清理：插件升级时顺手删除无用的清理代码即可，不强制要求

### 5.2 数据库兼容性

- 无数据库结构变更
- 无需执行升级脚本

### 5.3 配置兼容性

- 无配置项变更
- 无需修改 `conf.php`

---

## 六、FAQ

### Q1：逐文件 include 会不会比合并加载慢很多？

**不会。** PHP 8 + OPcache 热身后，include 的开销在微秒级，远小于一次 DB 查询（毫秒级）。合并加载在 PHP 5.x 时代有意义，在 PHP 8 + OPcache 时代收益可忽略。

### Q2：为什么不直接用 composer 的 autoload？

Xiuno 项目历史包袱较重，整体迁移到 composer  autoload 成本较高。本次优化先解决最痛的稳定性问题，autoload 改造留待后续版本。

### Q3：插件升级后还是需要清 tmp 缓存吗？

**是的。** `_include()` 的编译缓存机制没变（不比较源文件 mtime），修改源文件后仍需清理对应缓存。但核心已在插件 install/upgrade/enable/disable 时自动调用 `plugin_clear_tmp_dir()`，正常流程下无需手动清理。

### Q4：以后还会有类似的全站白屏问题吗？

**概率大幅降低。** 新机制下单个插件的语法错误只会影响该插件自身的编译缓存，不会导致全站 model 加载失败。配合已有的「插件崩溃自动禁用」机制，单个插件出问题最多是该插件功能失效，不会影响全站。

---

## 七、相关文档

- [01-architecture.md](01-architecture.md) — 插件架构原理（已更新 model 加载机制章节）
- [02-plugin-structure.md](02-plugin-structure.md) — 插件结构（已删除 model.min.php 清理示例；`model_inc_file.php` 标注为可选；明确禁止 `model/*.func.php`）
- [07-runtime-safety.md](07-runtime-safety.md) — 运行时安全 / 崩溃自动禁用
