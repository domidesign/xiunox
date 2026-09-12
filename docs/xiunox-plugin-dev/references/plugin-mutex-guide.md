# 插件互斥机制开发者指南

XIUNOX 通过**目录命名规范**自动识别同类插件，安装/启用新插件时自动禁用已有的同类插件（保留配置，不卸载数据）。

## 命名你的插件

目录名按下划线分段：`作者_功能标识`。功能标识可以含下划线（如 `ad_selfbuy`、`ai_reply`）。

| 类型 | 命名格式 | 示例 | 互斥行为 |
|---|---|---|---|
| 主题类 | `作者_theme` 或 `作者_theme_变体` | `xnx_theme`、`xnx_theme_moments` | 所有主题互相禁用（只能启用一个主题） |
| 功能插件 | `作者_功能标识`（功能标识可含下划线） | `xnx_checkin`、`xnx_ad_selfbuy`、`my_ai_reply` | 功能标识相同才互斥 |
| 单段 | `myplugin` | — | 不参与互斥（不推荐） |

## 互斥判定规则

1. **主题插件第二段必须是 `theme`** → 所有主题互相互斥
   - `xnx_theme` 与 `xnx_theme_moments` 互斥（无论变体多少）
   - 错误命名：`xnx_moments_theme`（第二段不是 theme，会被当作功能插件）

2. **功能插件按"功能标识"匹配** → 功能标识相同才互斥
   - 功能标识 = 第二段及以后的所有段拼接
   - `xnx_checkin` → 功能标识 `checkin`
   - `xnx_ad_selfbuy` → 功能标识 `ad_selfbuy`
   - `xnx_ai_reply` → 功能标识 `ai_reply`

3. **功能标识不同则不互斥**（自然共存）
   - `xnx_ad_selfbuy` 与 `xnx_ad_banner` → `ad_selfbuy` ≠ `ad_banner` → 不互斥
   - `xnx_checkin` 与 `xnx_checkin_pro` → `checkin` ≠ `checkin_pro` → 不互斥（基础与扩展可共存）

## 互斥关系示例

| 插件 A | 插件 B | 功能标识 A | 功能标识 B | 互斥 |
|---|---|---|---|---|
| `xnx_checkin` | `jack_checkin` | `checkin` | `checkin` | ✅ 互斥 |
| `xnx_ad_selfbuy` | `jack_ad_selfbuy` | `ad_selfbuy` | `ad_selfbuy` | ✅ 互斥 |
| `xnx_ad_selfbuy` | `xnx_ad_banner` | `ad_selfbuy` | `ad_banner` | ❌ 不互斥 |
| `xnx_ai_reply` | `xnx_avatar_ai` | `ai_reply` | `avatar_ai` | ❌ 不互斥 |
| `xnx_checkin` | `xnx_checkin_pro` | `checkin` | `checkin_pro` | ❌ 不互斥 |
| `xnx_theme` | `xnx_theme_dark` | __theme__ | __theme__ | ✅ 互斥（主题）|

## ⚠️ 禁止滥用不同功能标识规避互斥

功能标识的灵活性（可含下划线、段数不限）是为了**让真正不同的功能可以共存**（如 `ad_selfbuy` 自助广告与 `ad_banner` 横幅广告），**不是为了帮功能重复的插件逃避互斥**。

**判断标准**：如果你的插件与某个已有插件**功能实质重复**（用户装两个会导致数据冲突、UI 重复、hook 互相覆盖），就必须使用**相同的功能标识**，让系统自动禁用旧的。只有功能确实不同（各自独立运行、不产生冲突）时，才允许用不同的功能标识。

| 场景 | 错误（规避互斥） | 正确（参与互斥） |
|---|---|---|
| 独立签到插件，想避开与 `jack_checkin` 互斥 | `my_checkin_v2`（功能标识 `checkin_v2`）| `my_checkin`（功能标识 `checkin`）|
| 功能重复的广告插件，想与 `xnx_ad_selfbuy` 共存 | `my_ad_buy`（功能标识 `ad_buy`）| `my_ad_selfbuy`（功能标识 `ad_selfbuy`）|
| 主题插件想避开主题互斥 | `my_dark_theme`（第二段不是 theme，被当作功能插件）| `my_theme_dark`（第二段是 theme，正确归入主题互斥组）|

**允许用不同功能标识的正当场景**：
- 扩展包：`my_checkin_pro` 在 `my_checkin` 基础上增量增强，依赖基础插件运行
- 细分功能：`my_ad_selfbuy`（自助购买）与 `my_ad_banner`（横幅广告）是不同广告形态
- 不同领域：`my_avatar_ai`（AI 头像）与 `my_ai_reply`（AI 回复）虽然都涉及 AI，但功能完全不同

违规后果：
- 用户同时启用两个功能重复的插件，导致数据冲突、UI 重复、hook 互相覆盖
- 官方插件市场审核拒绝上架
- 社区维护者可要求改名

## 开发者 checklist

- [ ] 插件目录名至少两段（`作者_功能标识`）
- [ ] 主题插件第二段为 `theme`（`xxx_theme` 或 `xxx_theme_变体`）
- [ ] **功能重复的插件必须用相同功能标识**（让系统自动禁用旧的），禁止故意取不同名规避互斥
- [ ] 功能确实不同（细分功能/扩展包/不同领域）时，用不同功能标识让两者共存
- [ ] 不使用官方预留前缀 `xn_`、`xnx_`（仅供核心/官方插件）

## 行为说明

- **安装时**：后台预扫描弹窗展示将被禁用的同类插件列表，用户确认后安装
- **启用时**：自动禁用其他已启用的同类插件
- **禁用/卸载时**：不触发互斥检查
- **被禁用的插件**：保留数据库表、设置、语言包等所有配置，可随时重新启用

## 示例场景

**场景 1：开发签到插件**
```
my_checkin/          ← 功能标识 checkin
```
用户若已安装 `jack_checkin`，安装 `my_checkin` 时会自动禁用 `jack_checkin`（两者功能标识都是 `checkin`）。

**场景 2：开发签到插件的扩展包**
```
my_checkin/          ← 基础版，功能标识 checkin
my_checkin_pro/      ← 高级版，功能标识 checkin_pro
```
两者功能标识不同，可以同时启用。

**场景 3：开发广告插件（细分功能）**
```
my_ad_selfbuy/       ← 自助购买广告，功能标识 ad_selfbuy
my_ad_banner/        ← 横幅广告，功能标识 ad_banner
```
两者是不同的广告功能，可以同时启用。

**场景 4：开发主题插件**
```
my_theme/            ← 基础主题
my_theme_dark/       ← 暗色变体
```
两个主题互相禁用，用户只能启用其中一个。

## 实现源码

- `model/plugin.func.php` → `plugin_find_conflicts()` + `plugin_mutex_category()`
- `admin/route/plugin.php` → install/enable 分支调用互斥检查
- `admin/route/plugin_scanner.php` → 安装前预扫描返回冲突列表
