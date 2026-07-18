# AI 代码生成对照流程

> 规则详情见 [SKILL.md](../SKILL.md) 的「硬规则（不可违反）」与「交付检查表」。本文件只列**生成代码时必须逐项对照的检查流程**，避免与 SKILL.md 重复维护。

---

## 使用方式

每写完一段插件代码（hook / route / model / 模板 / JS / CSS），按下面 7 个阶段对照检查。每项对应 SKILL.md 中具体规则，点击跳转。

## 1. PHP 入口（hook / route / setting）

- [ ] PHP hook 文件以 `<?php exit;` 开头（[SKILL.md §Hook 类型](../SKILL.md#step-5-hook-文件两种类型开头写法不同写错会白屏)）
- [ ] **hook 中无 `return;`**（[SKILL.md §所有 hook 禁止 return](../SKILL.md#硬规则不可违反)）→ 用 `if (条件) { ... }` 包裹，例外：终止性 `exit;` 必须加 `// ponytail:` 注释
- [ ] `include` 用 `_include()`，禁止裸 `include`（除非 `include_once APP_PATH.'lib/xxx.php'` 加载 lib 类）
- [ ] 调用核心 Service 前 `if (!class_exists('X')) { include_once ... }` 守卫，访问静态属性/常量前确保类已加载
- [ ] 数据库结构变更走 `upgrade.php` 幂等迁移，禁止在 `install.php`/`setting.php` 加字段自愈代码
- [ ] `install.php` 末尾清 `tmp/model.min.php`（防 Service 类未加载白屏）
- [ ] 卸载脚本文件名用 `uninstall.php`（不是 `unstall.php`）

## 2. 表单 / 安全

- [ ] 所有 POST 表单含 `CsrfService::input()` + handler 首行 `CsrfService::check()`
- [ ] 后台 `setting.php` 开头有 `$gid != 1 && $gid != 2 AND message(-1, '无权限');`
- [ ] 密码/Token/Secret 用 `param($name, $default, FALSE)` 关闭 htmlspecialchars
- [ ] 所有 HTML 输出 `esc_html()` / `esc_attr()`，JS 字符串 `esc_js()`
- [ ] SQL 用户输入 `intval()` 转义，优先 `db_find*` 而非 `db_sql_find*`
- [ ] 保留复杂 SQL（JOIN/系统表）加 `// 保留 db_sql_find` 或 `// 保留 db_exec` 注释（扫描器据此跳过）

## 3. 模型 / 用户显示

- [ ] 调用单下划线业务层（`thread_create` 非 `thread__create`）
- [ ] 改密码用 `user_change_password()`（不是 `user_update`）
- [ ] 改用户组用 `user_change_group()`
- [ ] 获取用户显示名用 `user_find_by_uids()` / `user_read()` / `user_read_cache()`，禁止 `db_find('user')` 后直接取 `username`
- [ ] 模板显示用户名统一取 `$user['display_name']`
- [ ] 写操作后清缓存：`CacheHelper::pluginDeletePrefix($plugin)` 或自定义 `clearCache()`

## 4. URL / 路由

- [ ] URL 用命名快捷函数（`thread_url($tid)`）或 `route_url()` 通用入口，禁止 `url("thread-$tid")` 字符串拼接
- [ ] 插件自定义路由通过 `hook/model_route_table_end.php` 注册到 `$routes` 数组
- [ ] 分页用 `route_url('xxx_page', [])`（保留 `{page}` 占位符给 `pagination()` 函数）
- [ ] 缓存刷新/跳转 URL 用 `$site_url . url("xxx")`，禁止 `$site_url . '/xxx.htm'`
- [ ] 修改核心路由格式后同步更新 `.htaccess` / nginx 伪静态规则

## 5. 缓存 / 设置

- [ ] 数据缓存用 `CacheHelper::remember()` 代替裸 `cache_get/cache_set`
- [ ] 清除插件缓存用 `CacheHelper::pluginDeletePrefix()` 而非枚举 limit 值逐个 `cache_delete`
- [ ] 缓存键通过 `CacheHelper::pluginKey()` 生成（`p_{plugin}_` 前缀）
- [ ] Service 类构造函数调 `CacheHelper::registerKeys()` 注册缓存键
- [ ] 列表类缓存用版本号机制（数据变更时递增版本号使旧缓存失效）
- [ ] `setting_set/get` 直接存取数组，不用 `xn_json_encode/decode` 中转
- [ ] 跨插件共享配置的保存和读取使用同一个存储键

## 6. 前端（JS / CSS / 模板）

- [ ] 无 jQuery / Alpine.js / idiomorph（用 `XN.toast()` / `XN.ajax()` / `XN.confirm()`）
- [ ] 命名带插件前缀（变量 `__myPluginXxx` / CSS class `.my-plugin-xxx`）
- [ ] 静态资源放 `plugin/<dir>/static/js/` 和 `static/css/`（禁止放 `view/htm/`）
- [ ] `<script src>` / `<link href>` 用 `$conf['view_url']` 而非 `APP_PATH` 或相对路径
- [ ] 引用插件资源：`$conf['view_url'] . '../plugin/<dir>/static/js/xxx.js'`
- [ ] 引用核心资源：`$conf['view_url']js/xxx.js`
- [ ] Card 组件加 `x-card` class，禁止裸用 `border` / `border-*`（包括列表项 border-bottom）
- [ ] `.htm` hook 文件以 `<?php` 开头（不是 `<?php exit;`，否则白屏）
- [ ] 发帖/回复共用 `post.htm` 的 hook 按场景区分：`if ($route == 'thread' && $action == 'create')`
- [ ] AIEditor 工具栏配置用 `toolbarKeys`（不是 `toolbar`），自定义按钮以对象形式进入数组，SVG 用 fill 模式（禁用 stroke 模式）

## 7. 命名 / 跨插件

- [ ] 所有命名带插件前缀（表 / 变量 / 语言键 / JS / CSS / setting）
- [ ] 第三方插件禁止用 `xn_` 或 `xnx_` 前缀（官方预留）
- [ ] `conf.json` 的 `hooks_rank` 键名与 hook 文件名（含扩展名）完全一致
- [ ] 跨插件共享配置的保存和读取使用同一个存储键
- [ ] 注册表/默认配置中的文本用 `lang()` 多语言键，不硬编码中文

---

## 单行注释陷阱（高频踩坑）

PHP 单行注释 `//` 和 `#` 中**禁止包含 `?>`**：

```php
// ❌ 错误：会触发 headers already sent，页面直接显示代码
// 模板中调用：<?php echo thread_url($tid);?>

// ✅ 正确：去掉 <?php 和 ?>
// 模板中调用示例：echo thread_url($tid);
```

块注释 `/* */` 中可以包含 `?>`，不受影响。
