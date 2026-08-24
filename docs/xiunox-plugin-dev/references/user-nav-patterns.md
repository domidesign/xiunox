# 用户导航注册速查

> 本文件为用户导航（User Nav）注册速查，详细说明见 [../plugindev/19-user-nav.md](../../plugindev/19-user-nav.md)

## 目录

- [1. 机制一览](#1-机制一览)
- [2. 注册文件最小代码](#2-注册文件最小代码)
- [3. 字段表](#3-字段表)
- [4. 内置项与 rank 约定](#4-内置项与-rank-约定)
- [5. 与发现导航对比](#5-与发现导航对比)
- [6. 接入检查清单](#6-接入检查清单)

---

## 1. 机制一览

| 项 | 说明 |
|---|---|
| 入口位置 | 首页右侧栏用户信息卡片下方两列宫格（**仅登录用户**） |
| 注册方式 | 插件目录**根部**放 `user_nav_register.php`（不是 hook/ 目录） |
| 加载机制 | `UserNavService::ensureRegistered()` lazy 扫描启用插件，单次 include |
| 站长管理 | 后台 → 设置 → 导航 → 用户导航：拖拽排序 + 启用开关（默认全启用） |
| 配置存储 | setting 键 `plugin_user_nav_items`（merge 语义，无记录=默认启用） |
| 插件禁用 | 注册文件不再被加载，入口自动消失，无需卸载清理 |

## 2. 注册文件最小代码

`plugin/<your_plugin>/user_nav_register.php`：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

UserNavService::register('your_plugin', array(
    'url'       => 'my-quest',        // 原始路由名，未经 url() 转换
    'icon'      => 'ti-checklist',    // Tabler Icons 类名
    'name_lang' => 'quest_my_title',  // 插件语言包真实键（三语同步）
    'rank'      => 10,                // ≥10，避开内置项 0-3
));
```

然后 `conf.json` version 递增即可。不需要写 hook、不改模板、不建表。

## 3. 字段表

| 字段 | 必填 | 说明 |
|---|---|---|
| `url` | ✅ | 原始路由名。前台由 `NavService::href()` 统一转换（兼容全部 url_rewrite_on），**禁止**传 `url()` 预转换值（双重转换） |
| `icon` | ✅ | Tabler Icons 类名（`ti-xxx`） |
| `name_lang` | ✅ | 语言键，`lang()` 运行时解析，必须三语存在 |
| `rank` | ✅ | 默认排序，站长后台可拖拽覆盖 |

## 4. 内置项与 rank 约定

核心内置 4 项（key 以 `_` 前缀防插件 ID 冲突）：`_profile` 我的资料(0) / `_credits` 我的积分(1) / `_thread` 我的帖子(2) / `_following` 我的关注(3)。

插件项 **rank 从 10 起步**（多插件错开：10/20/30...），默认排内置项之后；站长可在后台拖拽混排。一个插件 ID 只注册一个入口，多功能页选主入口。

## 5. 与发现导航对比

| 维度 | 用户导航 | 发现导航（DiscoverService） |
|---|---|---|
| 注册文件 | `user_nav_register.php` | `discover_register.php` |
| 面向 | 用户个人功能页（我的 xx），仅登录 | 站点级应用入口，所有访客 |
| 配置键 | `plugin_user_nav_items` | `plugin_discover_items` |

同一插件可同时注册两处（发现页"在线答题" + 用户导航"我的答题记录"），互不冲突。

## 6. 接入检查清单

- [ ] 注册文件在插件目录根部（不是 `hook/`）
- [ ] `url` 为原始路由名（未经 `url()` 转换）
- [ ] `name_lang` 三语同步存在
- [ ] `rank` ≥ 10
- [ ] `conf.json` version 递增
- [ ] php -l + 清 tmp/

> 完整规范（含 xnx_duel 真实范例、前台渲染细节、后台管理说明）见 [../plugindev/19-user-nav.md](../../plugindev/19-user-nav.md)
