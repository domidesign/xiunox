# 用户导航扩展机制（User Nav）

> 适用于 XIUNOX（核心含 `lib/UserNavService.php`，v1.1.8+）
> 相关源码：`lib/UserNavService.php`（核心服务）、`view/htm/sidebar_right.inc.htm`（前台渲染）、`admin/route/setting.php` nav 动作（后台管理）、`admin/view/htm/setting_nav.htm`（后台用户导航 Tab）
> 已接入插件参考：xnx_quest 1.4.20、xnx_quiz 1.2.1、xnx_verify 1.3.2、xnx_duel 1.4.4

---

## 设计背景

插件提供用户个人功能页（我的任务 / 我的答题 / 认证申请 / 我的对决）时，入口分散在各插件自己的侧边栏 hook 里，每个插件各写一套链接样式，站长无法统一排序或关闭某个入口。

用户导航扩展机制把这套能力收敛为**核心服务 + 后台统一管理**：

1. **开发者写一个注册文件**：插件目录放 `user_nav_register.php`，一行 `UserNavService::register()` 完成注册；
2. **前台统一渲染**：首页右侧栏用户信息卡片下方两列宫格，核心统一输出（图标 + 名称），样式/交互（hover/按压反馈）由核心 CSS `.user-nav-item` 保证；
3. **站长统一管理**：后台 → 设置 → 导航 → 用户导航（`/admin/?setting-nav.htm#userNav`），可拖拽排序、启用/禁用，与内置入口（我的资料/积分/帖子/关注）混排管理。

设计对齐 `DiscoverService`（发现页插件注册机制，v2 去核心化）：注册表初始为空，由各插件自注册，核心不硬编码任何插件条目，符合开闭原则。

## 架构总览

```
插件启用
    │
    └─ user_nav_register.php（插件目录根部）
           │  UserNavService::register('xnx_quest', array(...))
           ▼
UserNavService::$registry（内存注册表）
    │
    ├─ ensureRegistered()：lazy 扫描 plugin_paths_enabled() 下所有 user_nav_register.php（单次）
    │
    ├─ $builtins：核心内置 4 项（_profile/_credits/_thread/_following，key 以 _ 前缀防冲突）
    │
    └─ getPluginUserNavItems($for_admin, $include_disabled)
           │  遍历 builtins + registry，合并 setting 配置（plugin_user_nav_items）
           │  默认启用：仅显式 enabled=0 才禁用
           │  usort 按 rank 升序（PHP 8 稳定排序，同 rank 内置在前）
           ▼
前台渲染（view/htm/sidebar_right.inc.htm）
    │  仅登录用户可见；URL 保留原始路由名，由 NavService::href() 统一转换（兼容全部 url_rewrite_on）
    ▼
后台管理（admin/route/setting.php + setting_nav.htm userNav Tab）
       拖拽排序（重写 rank）+ 启用开关，保存到 setting 键 plugin_user_nav_items
```

## 1. 注册文件协议（user_nav_register.php）

在插件目录**根部**新建 `user_nav_register.php`：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

// ponytail: 由 UserNavService::ensureRegistered() 在首次访问注册表前 include
// 插件自注册到首页右侧栏用户卡片"用户导航"区（后台-设置-导航-用户导航 可排序/启停）
UserNavService::register('xnx_quest', array(
    'url'       => 'my-quest',
    'icon'      => 'ti-checklist',
    'name_lang' => 'quest_my_title',
    'rank'      => 10,
));
```

### 字段表

| 字段 | 必填 | 说明 |
|------|------|------|
| `url` | 是 | 前台路由名（如 `my-quest`、`duel-my`）。传原始路由名即可，**不要**传 `url()` 转换后的值——前台渲染时由 `NavService::href()` 统一转换，避免双重转换 |
| `icon` | 是 | Tabler Icons 类名（如 `ti-checklist`），前台宫格项图标 |
| `name_lang` | 是 | 语言键，运行时 `lang()` 解析。**必须**复用插件语言包已有键（`plugin/<dir>/lang/zh-cn.php`，经 `hook/lang_*_bbs.php` 加载），或新增键后同步三语 |
| `rank` | 是 | 默认排序权重。内置项占用 0-3，插件项建议从 10 起步（10/20/30...），站长可在后台拖拽覆盖 |

### 核心约定

- **注册文件不是 hook**：不放在 `hook/` 目录，不进 `_include()` 编译链，由 `UserNavService::ensureRegistered()` 在首次访问注册表时 `include`（lazy + 单次执行）；
- 文件头写 `!defined('DEBUG') AND exit('Access Denied');` 防直接访问；
- `register()` 幂等：同一插件 ID 重复注册时首次生效；
- 插件禁用后 `plugin_paths_enabled()` 不再返回该插件，注册文件不会被加载，入口自动消失——**无需**在卸载脚本里清理配置（配置残留在 setting 中无害，重新启用后恢复）。

### 一个插件只注册一个入口

注册表以插件 ID 为键，一个插件一个入口。插件有多个用户功能页时（如 xnx_quiz 的答题记录 + 错题本），选择主入口注册，其余入口在插件自己的页面内导航。

## 2. 内置项（核心提供，v1.1.8+）

核心内置 4 个入口，key 以 `_` 前缀（`_profile` / `_credits` / `_thread` / `_following`）避免与插件 ID 冲突：

| key | 名称 | 路由 | 图标 | 默认 rank |
|-----|------|------|------|-----------|
| `_profile` | 我的资料 | `my-profile` | ti-user | 0 |
| `_credits` | 我的积分 | `my-credits` | ti-coins | 1 |
| `_thread` | 我的帖子 | `my-thread` | ti-message | 2 |
| `_following` | 我的关注 | `my-following` | ti-heart | 3 |

内置项与插件项**统一管理**：共用同一 setting 键（`plugin_user_nav_items`）、后台同一列表混排（内置行用圆点图标 `ti-circle-dot` 区分插件行的 `ti-plug`）、可被站长禁用或拖到插件项之后。插件开发者无需感知内置项，只需保证默认 rank ≥ 10 即可排在内置项之后。

## 3. 后台管理（站长视角）

后台 → 设置 → 导航 → 用户导航 Tab（`/admin/?setting-nav.htm#userNav`）：

| 操作 | 说明 |
|------|------|
| 启用开关 | 每行 form-switch，关闭后前台立即隐藏（默认全启用） |
| 拖拽排序 | 按住行首图标拖动，保存时按 DOM 顺序重写 rank（内置项与插件项混排） |
| 图标/名称/URL | 只读展示（插件注册的默认值），URL 旁有复制按钮 |

配置存储在 setting 键 `plugin_user_nav_items`，结构为：

```php
array(
    'xnx_quest' => array('enabled' => 1, 'rank' => 15),
    '_profile'  => array('enabled' => 0),  // 站长禁用了"我的资料"
)
```

无记录的项视为默认启用 + 默认 rank（merge 语义：`savePluginUserNavConfig` 只覆盖传入字段，改 rank 不丢 enabled）。

## 4. 前台渲染细节

渲染位置：`view/htm/sidebar_right.inc.htm`（首页右侧栏用户信息卡片内，仅登录用户）：

- 两列宫格（`grid-template-columns:1fr 1fr`），容器占位 class `user-nav-plugin-grid`；
- 每项为 `<a class="user-nav-item ...">`，hover 主色文字 + 次级背景，按压主色背景白字（样式定义在 `view/css/bootstrap-bbs.css`，用 `var(--bs-*)` CSS 变量，自动适配明暗主题）；
- URL 经 `NavService::href()` 转换，兼容全部 6 种 `url_rewrite_on` 模式；
- 名称/图标经 `esc_html()` 转义。

## 5. 完整接入示例

以 xnx_duel（我的对决）为例，三步接入：

**Step 1**：插件目录根部新建 `user_nav_register.php`：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

UserNavService::register('xnx_duel', array(
    'url'       => 'duel-my',
    'icon'      => 'ti-swords',
    'name_lang' => 'xnx_duel_my_title',
    'rank'      => 40,
));
```

**Step 2**：确认语言键存在（`plugin/xnx_duel/lang/zh-cn.php` 已有 `xnx_duel_my_title`，无则新增并同步 zh-tw / en-us）。

**Step 3**：递增 `conf.json` 的 `version`（如 1.4.3 → 1.4.4），站长后台升级插件后生效。

完成。不需要写 hook、不需要改模板、不需要建表——注册机制与编译链完全解耦。

## 6. 与发现导航（DiscoverService）的对比

两套机制同构，按入口位置选择：

| 维度 | 用户导航（UserNavService） | 发现导航（DiscoverService） |
|------|---------------------------|----------------------------|
| 入口位置 | 首页右侧栏用户信息卡片（仅登录用户） | 发现页宫格（所有访客） |
| 注册文件 | `user_nav_register.php` | `discover_register.php` |
| 面向 | 用户个人功能页（我的 xx） | 站点级应用入口 |
| 内置项 | 4 个（资料/积分/帖子/关注） | 无 |
| 配置键 | `plugin_user_nav_items` | `plugin_discover_items` |
| 后台 Tab | 设置-导航-用户导航 | 设置-导航-发现导航 |

同一个插件可以同时注册两处（如答题插件：发现页注册"在线答题"入口 + 用户导航注册"我的答题记录"），互不冲突。

## 7. 接入检查清单

- [ ] 注册文件放在插件目录**根部**（不是 `hook/` 目录）
- [ ] `url` 传原始路由名，未经过 `url()` 预转换
- [ ] `name_lang` 是插件语言包真实存在的键，三语同步
- [ ] `rank` ≥ 10（避开内置项 0-3）
- [ ] `conf.json` 版本号已递增
- [ ] 目标路由对目标用户可用（如认证申请入口对已认证用户是否仍显示，由插件自行在注册后控制——可在 register 前加业务判断，或始终注册交给站长管理）
