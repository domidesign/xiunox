# 用户导航扩展机制（User Nav）

> 适用于 XIUNOX（核心含 `lib/UserNavService.php`）
> 相关源码：`lib/UserNavService.php`（核心服务）、`conf/conf.default.php`（内置项播种）、`view/htm/sidebar_right.inc.htm`（前台渲染）、`admin/route/setting.php` nav 动作（后台管理）、`admin/view/htm/setting_nav.htm`（后台用户导航 Tab）
> 已接入插件参考：xnx_quest 1.4.20、xnx_quiz 1.2.1、xnx_verify 1.3.2、xnx_duel 1.4.4

---

## 设计背景

插件提供用户个人功能页（我的任务 / 我的答题 / 认证申请 / 我的对决）时，入口分散在各插件自己的侧边栏 hook 里，每个插件各写一套链接样式，站长无法统一排序或关闭某个入口。

用户导航扩展机制把能力收敛为 **数据分层 + 后台统一管理**，与发现导航（DiscoverService）完全同构：

1. **自定义项（核心内置 + 站长新增）**：存 `conf['user_nav_items']`，新装站点由 `conf/conf.default.php` 播种内置 4 项（我的资料/积分/帖子/关注）；用户在后台可编辑图标/名称/URL、新增、删除、拖拽排序；
2. **插件注册项**：插件目录放 `user_nav_register.php`，一行 `UserNavService::register()` 完成注册，存 setting 键 `plugin_user_nav_items`（可启停/排序，字段只读）；
3. **前台统一渲染**：首页右侧栏用户信息卡片下方两列宫格，核心统一输出（图标 + 名称），样式/交互（hover/按压反馈）由核心 CSS `.user-nav-item` 保证。

设计对齐 `DiscoverService`（v2 去核心化）：插件注册表初始为空，由各插件自注册，核心不硬编码任何插件条目，符合开闭原则。

## 架构总览

```
数据分层
├─ 自定义项（含内置默认）
│    conf['user_nav_items']（conf.default.php 播种 4 项；conf.php 未配置时 Service 回退内置）
│    后台可编辑/新增/删除/排序（save 后写入 conf.php）
└─ 插件注册项
     user_nav_register.php → UserNavService::$registry（内存，lazy 扫描）
     setting['plugin_user_nav_items']（后台启停/排序，merge 保存）

合并渲染
UserNavService::getUserNavItems($for_admin, $include_disabled)
     ├─ getCustomUserNavItems()：conf['user_nav_items']（未配置回退内置），name_lang 运行时 lang() 解析
     └─ getPluginUserNavItems()：registry 合并 setting，默认启用，可过滤禁用项
     统一 usort 按 rank 升序（PHP 8 稳定排序，同 rank 自定义项在前）

前台（view/htm/sidebar_right.inc.htm）
     仅登录用户可见；URL 保留原始路由名，由 NavService::href() 统一转换（兼容全部 url_rewrite_on）

后台（admin/route/setting.php + setting_nav.htm userNav Tab）
     custom 行：icon/name/slug/class/url 可编辑 + URL 预设 + 删除按钮 + “添加用户导航”
     插件行：只读字段 + URL 复制 + 启用开关 + 拖拽排序
```

## 1. 注册文件协议（user_nav_register.php）

在插件目录**根部**新建 `user_nav_register.php`：

```php
<?php
!defined('DEBUG') AND exit('Access Denied');

// ponytail: 由 UserNavService::ensureRegistered() 在首次访问注册表前 include
// 插件自注册到首页右侧栏用户卡片"用户导航"区（后台可启停/排序）
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
| `rank` | 是 | 默认排序权重。内置/自定义项占用 0-3，插件项建议从 10 起步（10/20/30...），站长可在后台拖拽覆盖 |

### 核心约定

- **注册文件不是 hook**：不放在 `hook/` 目录，不进 `_include()` 编译链，由 `UserNavService::ensureRegistered()` 在首次访问注册表时 `include`（lazy + 单次执行）；
- 文件头写 `!defined('DEBUG') AND exit('Access Denied');` 防直接访问；
- `register()` 幂等：同一插件 ID 重复注册时首次生效；
- 插件禁用后 `plugin_paths_enabled()` 不再返回该插件，注册文件不会被加载，入口自动消失——**无需**在卸载脚本里清理配置（配置残留在 setting 中无害，重新启用后恢复）。

### 一个插件只注册一个入口

注册表以插件 ID 为键，一个插件一个入口。插件有多个用户功能页时（如 xnx_quiz 的答题记录 + 错题本），选择主入口注册，其余入口在插件页面内导航。

## 2. 内置项（核心提供）

核心内置 4 项作为 **custom 项**（非插件项）提供，双保险：

- **新装站点**：`conf/conf.default.php` 播种 `user_nav_items`（icon/name_lang/slug/url/class/rank）；
- **老站点升级/从未保存过**：`conf.php` 无 `user_nav_items` 时 `UserNavService::getCustomUserNavItems()` 回退内置结构（`$builtins`），保证即使没有插件也显示、默认排最前。

| 名称 | 路由 | 图标 | 默认 rank |
|------|------|------|-----------|
| 我的资料 | `my-profile` | ti-user | 0 |
| 我的积分 | `my-credits` | ti-coins | 1 |
| 我的帖子 | `my-thread` | ti-message | 2 |
| 我的关注 | `my-following` | ti-heart | 3 |

内置项**没有启用开关**（与发现导航的自定义项一致，启用列显示 —）；站长要隐藏某个内置项 = 删除该行，首次保存后 conf['user_nav_items'] 落库，回退不再生效（内置项仅存在于默认种子，不参与插件式启停）。

插件开发者无需感知内置项，只需保证默认 rank ≥ 10 即可排在内置项之后。

## 3. 后台管理（站长视角）

后台 → 设置 → 导航 → 用户导航 Tab（`/admin/?setting-nav.htm#userNav`），表格与发现导航同构：

| 行类型 | 操作 |
|--------|------|
| 自定义行（含内置播种项） | 图标（选择器）、名称、slug、class、URL（预设+自定义）均可编辑；行末删除按钮移除；「添加用户导航」新增空行 |
| 插件行 | 字段只读 + URL 复制按钮；启用开关（form-switch）控制前台显隐；行首拖拽排序 |

保存时：
- 自定义行收集至 `conf['user_nav_items']` 写入 `conf/conf.php`（slug 留空自动生成 `unav-xxxx`）；
- 插件行 `plugin_user_nav_ids[]` / `plugin_user_nav_enabled[pid]` / `plugin_user_nav_rank[pid]` 经 `savePluginUserNavConfig()` merge 保存至 setting 键 `plugin_user_nav_items`（无记录=默认启用，改 rank 不丢 enabled）。

配置存储结构：

```php
// conf/conf.php（自定义项，含内置播种）
'user_nav_items' => array(
    array('icon' => 'ti-user', 'name' => '我的资料', 'slug' => 'user-nav-profile', 'url' => 'my-profile', 'class' => '', 'rank' => 0),
    // ...
),

// setting['plugin_user_nav_items']（插件项，仅存站长覆盖）
array(
    'xnx_quest' => array('enabled' => 1, 'rank' => 15),
)
```

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
| 内置/自定义项 | 内置 4 项播种 conf（资料/积分/帖子/关注） | 无内置，纯自定义 |
| 自定义项存储 | `conf['user_nav_items']` | `conf['discover_items']` |
| 插件项配置键 | `plugin_user_nav_items` | `plugin_discover_items` |
| 后台 Tab | 设置-导航-用户导航 | 设置-导航-发现导航 |

同一个插件可以同时注册两处（如答题插件：发现页注册"在线答题"入口 + 用户导航注册"我的答题记录"），互不冲突。

## 7. 接入检查清单

- [ ] 注册文件放在插件目录**根部**（不是 `hook/` 目录）
- [ ] `url` 传原始路由名，未经过 `url()` 预转换
- [ ] `name_lang` 是插件语言包真实存在的键，三语同步
- [ ] `rank` ≥ 10（避开内置/自定义项 0-3）
- [ ] `conf.json` 版本号已递增
- [ ] 目标路由对目标用户可用（如认证申请入口对已认证用户是否仍显示，由插件自行控制——可在 register 前加业务判断，或始终注册交给站长管理）