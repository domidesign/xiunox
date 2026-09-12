# 官方插件仓库部署指南

本文档说明如何创建和维护 `xiuno-official-plugins` 仓库，用于后台「插件市场」功能（`admin/?plugin-official.htm`）的清单和免费插件 zip 包托管。

## 1. 仓库用途

后台「插件市场」通过 `lib/OfficialPluginService.php` 从 GitHub 拉取清单，下载免费插件 zip 一键安装，付费插件显示跳转外链。仓库本身由人工维护，不在核心代码交付范围内。

**仓库职责**
- 托管 `manifest.json` 插件清单（描述所有可用插件）
- 托管免费插件的图标 + zip 包
- 付费插件只在清单中登记 `homepage` URL，zip 包不放在本仓库

**数据源**：manifest.json + icons + 免费 zip 包可从 xnx_appcenter 后台一键导出（见第 12 节），不用手动维护

## 2. 创建 GitHub 仓库

### 2.1 手动创建

1. 登录 GitHub，点击右上角 `+` → `New repository`
2. 仓库配置：
   - **Repository name**：`xiuno-official-plugins`
   - **Visibility**：`Public`（必须公开，jsdelivr CDN 只能加速公开仓库）
   - **Initialize this repository with**：勾选 `Add a README file`（可选）
3. 点击 `Create repository`

### 2.2 克隆到本地

```bash
git clone https://github.com/{你的账号}/xiuno-official-plugins.git
cd xiuno-official-plugins
```

## 3. 仓库目录结构

```
xiuno-official-plugins/
├── manifest.json                  # 插件清单（必需）
├── icons/                         # 插件图标目录
│   ├── xnx_checkin.png
│   ├── xnx_friendlink.png
│   └── ...
└── free/                          # 免费插件 zip 包
    ├── xnx_checkin/
    │   ├── xnx_checkin-1.0.0.zip
    │   └── xnx_checkin-1.0.1.zip  # 多版本共存（可选）
    └── xnx_friendlink/
        └── xnx_friendlink-1.0.0.zip
```

**目录命名规则**
- `icons/{dir}.png`：图标文件名 = 插件目录名，扩展名 `.png`
- `free/{dir}/{dir}-{version}.zip`：zip 包放在以插件 dir 命名的子目录下，文件名含版本号

## 4. manifest.json 格式

根目录创建 `manifest.json`，结构如下：

```json
{
  "version": "1.0",
  "updated_at": "2026-07-28T10:00:00Z",
  "plugins": [
    {
      "dir": "xnx_checkin",
      "name": "签到打卡",
      "brief": "每日签到送积分",
      "version": "1.0.0",
      "bbs_version": "1.1",
      "author": "twelve",
      "icon_url": "https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/icons/xnx_checkin.png",
      "type": "plugin",
      "free": true,
      "zip_url": "https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/free/xnx_checkin/xnx_checkin-1.0.0.zip"
    },
    {
      "dir": "xnx_pro_stats",
      "name": "高级统计（付费）",
      "brief": "专业站点统计",
      "version": "2.0.0",
      "bbs_version": "1.1",
      "author": "twelve",
      "icon_url": "https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/icons/xnx_pro_stats.png",
      "type": "plugin",
      "free": false,
      "homepage": "https://xiuno.xcxgy.cn/buy/xnx_pro_stats"
    }
  ]
}
```

**把 `{owner}` 替换为你的 GitHub 账号**（与 `lib/OfficialPluginService.php` 中的 `GITHUB_OWNER` 常量一致）。

### 字段说明

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `version` | string | 是 | 清单格式版本（当前固定 `"1.0"`） |
| `updated_at` | string | 是 | 清单更新时间（ISO 8601 格式，仅展示用） |
| `plugins` | array | 是 | 插件数组 |

**单个插件字段**

| 字段 | 类型 | 必填 | 合法值 | 说明 |
|---|---|---|---|---|
| `dir` | string | 是 | 小写字母+数字+下划线 | 插件目录名，唯一键 |
| `name` | string | 是 | 任意字符串 | 显示名称 |
| `brief` | string | 是 | 任意字符串 | 简介（卡片显示，限 2 行） |
| `version` | string | 是 | X.Y.Z 三位制 | 插件版本号 |
| `bbs_version` | string | 是 | X.Y 两位制 | 兼容的核心版本（必须与 XIUNOX_VERSION 前两段一致） |
| `author` | string | 是 | 任意字符串 | 作者 |
| `icon_url` | string | 是 | 绝对 URL | 图标 URL（推荐 jsdelivr CDN） |
| `type` | string | 是 | `"plugin"` / `"theme"` | 插件类型 |
| `free` | bool | 是 | true / false | true=免费可下载，false=付费跳转 |
| `zip_url` | string | `free=true` 时必填 | 绝对 URL | zip 包 URL（推荐 jsdelivr CDN） |
| `homepage` | string | `free=false` 时必填 | 绝对 URL | 付费插件购买/下载页 URL |

## 5. 打包免费插件 zip

**关键**：zip 包根目录必须直接含 `conf.json`（与现有后台「上传安装」流程兼容）。

### 5.1 正确的 zip 结构

```
xnx_checkin-1.0.0.zip
├── conf.json               # 必需，根目录
├── install.php
├── setting.php
├── icon.png
├── route/
│   └── checkin.php
├── model/
│   └── CheckinService.php
└── view/
    └── htm/
        └── checkin.htm
```

### 5.2 打包命令

**进入插件目录打包**（推荐，结构正确）：

```bash
cd /path/to/xiuno/plugin/xnx_checkin
zip -r ../xnx_checkin-1.0.0.zip . -x "*.DS_Store" "__MACOSX*"
cd ..
ls -la xnx_checkin-1.0.0.zip
```

**从项目根目录打包**（需要 cd 到插件子目录）：

```bash
cd /Users/hfbi/Desktop/2026/xiuno/xiunobbs-master/plugin/xnx_checkin
zip -r /tmp/xnx_checkin-1.0.0.zip . -x "*.DS_Store" "__MACOSX*"
```

### 5.3 验证 zip 结构

```bash
unzip -l xnx_checkin-1.0.0.zip | head -20
```

**必须看到 `conf.json` 在第一层**（不是 `xnx_checkin/conf.json`）。如果看到的是 `xnx_checkin/conf.json`，说明打包时多套了一层目录，需要重新打包。

### 5.4 验证 conf.json

```bash
unzip -p xnx_checkin-1.0.0.zip conf.json | python3 -m json.tool
```

确认 `version` / `bbs_version` / `type` 等字段与 manifest.json 中的一致。

## 6. 上传文件到 GitHub

### 6.1 准备文件

把打包好的 zip 和图标放到仓库对应目录：

```bash
# 假设你在仓库根目录
mkdir -p icons free/xnx_checkin

# 复制图标
cp /path/to/xnx_checkin/icon.png icons/xnx_checkin.png

# 复制 zip 包
cp /tmp/xnx_checkin-1.0.0.zip free/xnx_checkin/xnx_checkin-1.0.0.zip
```

### 6.2 编辑 manifest.json

在仓库根目录创建/编辑 `manifest.json`，添加新插件条目（参考第 4 节格式）。

### 6.3 提交并推送

```bash
git add manifest.json icons/ free/
git commit -m "feat: add xnx_checkin v1.0.0"
git push origin main
```

### 6.4 验证 jsdelivr CDN 缓存

jsdelivr 会自动缓存 GitHub 文件，但首次推送后可能需要 5-10 分钟同步。验证方式：

```bash
# 验证清单可访问
curl -s https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/manifest.json | python3 -m json.tool

# 验证 zip 包可下载
curl -I https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/free/xnx_checkin/xnx_checkin-1.0.0.zip
# 期望返回 200 OK

# 验证图标可访问
curl -I https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/icons/xnx_checkin.png
```

**jsdelivr 限制**：单文件最大 50MB（zip 包不能超过 50MB，绝大多数插件远低于此）。

## 7. 修改核心代码中的 GITHUB_OWNER

仓库创建完成后，必须修改 `lib/OfficialPluginService.php` 顶部类常量：

```php
// 文件：lib/OfficialPluginService.php 第 15-18 行
const GITHUB_OWNER = 'xiunox';  // ← 改为你的真实 GitHub 账号
const GITHUB_REPO = 'xiuno-official-plugins';
const MANIFEST_URL_PRIMARY = 'https://cdn.jsdelivr.net/gh/xiunox/xiuno-official-plugins@main/manifest.json';  // ← 同步改 owner
const MANIFEST_URL_FALLBACK = 'https://raw.githubusercontent.com/xiunox/xiuno-official-plugins/main/manifest.json';  // ← 同步改 owner
```

例如你的 GitHub 账号是 `twelve20`，则改为：

```php
const GITHUB_OWNER = 'twelve20';
const GITHUB_REPO = 'xiuno-official-plugins';
const MANIFEST_URL_PRIMARY = 'https://cdn.jsdelivr.net/gh/twelve20/xiuno-official-plugins@main/manifest.json';
const MANIFEST_URL_FALLBACK = 'https://raw.githubusercontent.com/twelve20/xiuno-official-plugins/main/manifest.json';
```

`OfficialPluginService.php` 通过裸 `include` 加载（非 `_include()`），**修改后立即生效，无需清 tmp 编译缓存**。

## 8. 验证后台插件市场

1. 浏览器访问 `admin/?plugin-official.htm`
2. 首次访问会触发清单拉取（主源 jsdelivr → 备源 GitHub raw）
3. 拉取成功后 `tmp/cache/official_plugins.json` 文件被创建
4. 卡片网格展示 manifest.json 中所有插件
5. 点击免费插件的「安装」按钮，验证下载安装流程
6. 点击付费插件的「前往下载」按钮，验证跳转外链

## 9. 更新清单

### 9.1 新增插件

1. 在 `plugin/xnx_xxx/` 打包 zip（参考第 5 节）
2. 上传 zip + 图标到 GitHub 仓库
3. 在 `manifest.json` 的 `plugins` 数组追加新条目
4. 提交推送
5. 后台点击「刷新」按钮强制重新拉取清单

### 9.2 升级插件版本

1. 在 `plugin/xnx_xxx/` 打包新版本 zip（如 `xnx_checkin-1.0.1.zip`）
2. 上传到 `free/xnx_checkin/`（旧版本 zip 可保留也可删除）
3. 修改 `manifest.json` 中该插件的 `version` 和 `zip_url`（指向新版本 zip）
4. 提交推送
5. 后台「本地插件」tab 中禁用已安装的旧版本插件
6. 「插件市场」tab 中点击「升级」按钮

### 9.3 删除插件

1. 从 `manifest.json` 的 `plugins` 数组中移除该条目
2. 提交推送
3. 后台点击「刷新」按钮，该插件不再显示

**注意**：jsdelivr CDN 有缓存（最长 12 小时），更新清单后可能不会立即生效。如需立即生效，可在 jsdelivr 官网手动刷新缓存：

```
https://purge.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/manifest.json
```

或在 manifest.json URL 后加版本参数强制刷新：

```
https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@<commit-hash>/manifest.json
```

## 10. 常见问题

### Q1: 后台「插件市场」显示「无法连接插件市场，请检查网络」

**可能原因**
1. GitHub 仓库未创建或未公开
2. `lib/OfficialPluginService.php` 中 `GITHUB_OWNER` 还是占位值 `xiunox`
3. manifest.json 文件路径错误（必须在仓库根目录）
4. jsdelivr CDN 缓存未同步（首次推送后等 5-10 分钟）

**排查**
```bash
# 1. 验证清单 URL 可访问（替换 owner）
curl -I https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/manifest.json

# 2. 验证备源可访问
curl -I https://raw.githubusercontent.com/{owner}/xiuno-official-plugins/main/manifest.json

# 3. 检查 GITHUB_OWNER 是否已替换
grep 'GITHUB_OWNER' lib/OfficialPluginService.php
```

### Q2: 安装插件时报「zip 包中缺少有效的 conf.json 文件」

**原因**：zip 包多套了一层目录（如 `xnx_checkin/conf.json` 而非根目录直接含 `conf.json`）。

**解决**：重新打包，参考第 5.2 节，在插件目录内执行 `zip -r xxx.zip .`。

### Q3: 升级插件时报「请先禁用插件再升级」

这是预期行为。已启用的插件必须先在「本地插件」tab 中禁用，才能在「插件市场」tab 中升级。但 UI 上仍会显示「有新版本」红色徽章，让用户知道有更新可升级。

### Q4: jsdelivr CDN 缓存导致清单不更新

jsdelivr 对 `@main` 分支的缓存约 12 小时。如需立即生效：

```bash
# 强制刷新 jsdelivr 缓存
curl https://purge.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/manifest.json
curl https://purge.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/free/xnx_checkin/xnx_checkin-1.0.0.zip
```

或在 URL 中用具体 commit hash 替代 `@main`：

```
https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@<commit-hash>/manifest.json
```

### Q5: zip 包超过 jsdelivr 50MB 限制

如果插件 zip 包超过 50MB（如含大量静态资源），jsdelivr CDN 会拒绝服务。解决方案：
1. 拆分插件，把大文件放到外部 CDN
2. 使用 GitHub Releases 代替 jsdelivr（但需修改 `lib/OfficialPluginService.php` 中的 URL 模板）
3. 自建 CDN 服务器

### Q6: 付费插件 zip 包放在哪里

**不放本仓库**。付费插件只在本仓库的 `manifest.json` 中登记 `homepage` URL，用户点击「前往下载」跳转到外部购买页。zip 包由你自行托管（如自己的服务器、付费平台等）。

## 11. 最小可用清单示例

如果暂时只有一个免费插件 `xnx_checkin`，`manifest.json` 可以这样写：

```json
{
  "version": "1.0",
  "updated_at": "2026-07-28T10:00:00Z",
  "plugins": [
    {
      "dir": "xnx_checkin",
      "name": "签到打卡",
      "brief": "每日签到送积分",
      "version": "1.0.0",
      "bbs_version": "1.1",
      "author": "twelve",
      "icon_url": "https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/icons/xnx_checkin.png",
      "type": "plugin",
      "free": true,
      "zip_url": "https://cdn.jsdelivr.net/gh/{owner}/xiuno-official-plugins@main/free/xnx_checkin/xnx_checkin-1.0.0.zip"
    }
  ]
}
```

付费插件先不登记，等有付费插件时再追加条目即可。

## 12. 从 xnx_appcenter 一键导出（推荐方式）

如果已安装并启用了 xnx_appcenter 插件，可以通过后台一键导出 manifest.json + icons + 免费 zip 包，不用手动维护仓库文件。

### 12.1 前置条件

- xnx_appcenter 插件已启用，且至少有 1 个 `status=1`（已发布）的应用
- 每个已发布应用必须有 `slug`（应用标识，用作 manifest.dir）
- 免费应用（`price_type=0`）必须在版本表中上传过 zip 包（至少 1 个版本）

### 12.2 修改硬编码配置

编辑 `plugin/xnx_appcenter/setting.php` 第 513-515 行（`xnx_appcenter_export_manifest()` 函数体内）：

```php
$GITHUB_OWNER = 'xiunox';  // ← 改为你的真实 GitHub 账号
$REPO_NAME = 'xiuno-official-plugins';  // ← 仓库名（默认即可）
$SITE_URL = 'https://xiuno.xcxgy.cn/';  // ← 改为你的站点 URL（用于生成付费插件 homepage）
```

**注意**：`GITHUB_OWNER` 必须与 `lib/OfficialPluginService.php` 第 15 行的 `GITHUB_OWNER` 常量一致，否则导出的 URL 与消费端拉取的 URL 不匹配。

### 12.3 导出操作

1. 进入后台 → 插件管理 → xnx_appcenter → 设置
2. 滚动到底部「导出官方插件清单」区块
3. 点击「导出清单」按钮
4. 浏览器自动下载 `xiuno-official-plugins-export-YYYYMMDD-HHMMSS.zip`

### 12.4 导出 zip 内容

```
xiuno-official-plugins-export-{timestamp}.zip
├── manifest.json              # 清单（从所有 status=1 应用导出）
├── icons/                     # 每个应用的图标（从 icon_aid 复制）
│   ├── xnx_checkin.png
│   └── xnx_friendlink.png
└── free/                      # 免费应用的 zip 包（从最新版本 download_aid 复制）
    └── xnx_checkin/
        └── xnx_checkin-1.0.0.zip
```

### 12.5 字段映射

manifest.json 的每个字段从 xnx_appcenter 的对应字段映射：

| manifest 字段 | xnx_appcenter 来源 | 说明 |
|---|---|---|
| `dir` | `app.slug` | 应用标识（唯一键） |
| `name` | `app.name` | 应用名称 |
| `brief` | `app.brief` | 一句话介绍 |
| `version` | `app.version` | 当前版本号 |
| `bbs_version` | `app.applicable_version` | 兼容版本（空则默认 `1.1`） |
| `author` | `app.developer` | 作者 |
| `icon_url` | jsdelivr URL（`{owner}/{repo}@main/icons/{slug}.png`） | 图标 CDN URL |
| `type` | `app.category` | 类型（plugin/template/extension） |
| `free` | `app.price_type == 0` | true=免费，false=付费 |
| `zip_url` | 仅免费：jsdelivr URL | zip 包 CDN URL |
| `homepage` | 仅付费：`{site_url}appcenter-{app_id}.htm` | 付费插件跳转详情页 |

### 12.6 上传到 GitHub

```bash
# 1. 克隆仓库（首次）
git clone https://github.com/{owner}/xiuno-official-plugins.git
cd xiuno-official-plugins

# 2. 解压导出的 zip 到仓库根目录（覆盖旧文件）
unzip -o /path/to/xiuno-official-plugins-export-*.zip

# 3. 检查 manifest.json
cat manifest.json | python3 -m json.tool

# 4. 提交并推送
git add .
git commit -m "chore: sync manifest from xnx_appcenter"
git push origin main
```

### 12.7 更新流程

xnx_appcenter 有新插件 / 新版本 / 价格调整后：

1. 在 xnx_appcenter 后台修改应用数据
2. 点击「导出清单」按钮，下载新 zip
3. 解压覆盖到本地 git 仓库
4. `git commit && git push`
5. 后台「插件市场」点击「刷新」按钮强制重新拉取清单

### 12.8 注意事项

- **slug 校验已收紧**：从 2026-07-28 起，xnx_appcenter 的 slug 只允许小写字母+数字+下划线，必须以字母开头（正则 `^[a-z][a-z0-9_]{2,49}$`），不再允许短横线 `-`。如果你的数据库中有含 `-` 的旧 slug，需要手动修改数据库：
  ```sql
  -- 查找含短横线的 slug
  SELECT app_id, slug FROM bbs_xnx_appcenter_app WHERE slug LIKE '%-%';
  -- 修改（把 - 替换为 _）
  UPDATE bbs_xnx_appcenter_app SET slug = REPLACE(slug, '-', '_') WHERE slug LIKE '%-%';
  ```
- **免费插件必须有版本**：免费应用（`price_type=0`）必须在版本表中上传过至少 1 个 zip 包，否则导出时 `zip_url` 为空，消费端无法下载安装
- **付费插件不导出 zip**：付费应用只在 manifest 中写 `homepage` URL，zip 包由 xnx_appcenter 自己托管（用户在详情页购买后下载）
- **导出临时文件**：导出过程会在 `tmp/` 目录创建临时目录，打包成 zip 后自动清理
- **jsdelivr CDN 缓存**：更新 GitHub 后，jsdelivr 缓存最长 12 小时，如需立即生效用 `https://purge.jsdelivr.net/gh/{owner}/{repo}@main/manifest.json` 强制刷新
