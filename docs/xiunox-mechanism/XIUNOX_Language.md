# XIUNOX_Language 多语言

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 的多语言系统采用**基于文件的语言包架构**，将所有翻译文本存放在 `lang/` 目录下的 PHP 文件中。系统通过 `lang()` 函数在运行时从语言包中读取对应键值，实现界面文字的动态切换。

语言包支持三级加载机制：站点默认语言配置 → 浏览器语言检测 → 用户主动选择，最终优先级为用户选择 > 站点默认。目前内置三种语言：简体中文（zh-cn）、英文（en-us）、繁体中文（zh-tw）。

## 站长指南

### 配置入口

后台「站点设置 → 基本设置」中的「语言」选项，或直接编辑 `conf/conf.php` 文件中的 `lang` 配置项。

### 配置项说明

| 配置项 | 位置 | 说明 |
|--------|------|------|
| `lang` | `conf/conf.php` | 站点默认语言，如 `zh-cn`、`en-us`、`zh-tw` |
| `default_lang` | `conf/conf.php` | 新用户默认语言偏好，空值表示跟随 `lang` 配置 |

### 使用场景

1. **切换站点默认语言**：修改 `conf/conf.php` 中 `lang` 值为目标语言代码（如 `en-us`），全站界面将以该语言显示。
2. **允许用户切换语言**：在视图模板中添加语言切换链接，指向 `lang.php?lang_code` 路由（如 `/lang.php?en-us`），系统会通过 Cookie 记忆用户选择，有效期一年。
3. **仅后端多语言**：仅修改后台管理界面语言，可在管理员会话中独立设置。

### 注意事项

- 切换语言后需清除浏览器缓存，确保加载最新的语言包文件。
- 自定义语言包需保持与现有语言包相同的文件结构和键名，否则会出现部分文字未翻译的情况。
- 语言包文件为 PHP 数组，修改后直接生效，无需清除 OPcache（系统已自动处理）。

## 开发者指南

### 核心服务类

系统没有独立的语言服务类，语言加载通过以下机制完成：

- **`lang()` 函数**（`xiunophp/misc.func.php:174`）：核心翻译函数
- **`$\_SERVER['lang']`**：语言数组在应用启动时加载到全局变量中
- **`lang.php` 路由**（`route/lang.php`）：处理语言切换请求

### 钩子点

语言包文件支持 hook 扩展，在文件末尾留有钩子注释：

```
// hook lang_zh_cn_bbs.php
// hook lang_zh_cn_bbs_common.php
// hook lang_zh_cn_bbs_admin.php
// hook lang_zh_cn_bbs_install.php
// hook lang_zh_cn_bbs_js.htm
```

插件可通过 `add_hook()` 或 `XnEvent::on()` 在这些位置追加翻译条目。

### 扩展方式

#### 新增语言包步骤

1. 在 `lang/` 目录下创建新语言目录，例如 `lang/ja-jp/`
2. 复制任意现有语言包的文件结构（`bbs.php`、`bbs_admin.php`、`bbs_common.php`、`bbs_install.php`、`bbs.js`）
3. 逐个翻译所有键值，保持键名不变
4. 将新语言代码加入 `route/lang.php` 的 `$supported` 数组中
5. 在 `bbs_common.php` 的语言选项中添加新语言标签（如 `lang_ja_jp`）
6. 清除缓存，测试新语言包效果

#### 现有语言包结构

```
lang/
├── zh-cn/          # 简体中文
│   ├── bbs.php         # 入口文件，加载 bbs_common.php
│   ├── bbs_admin.php   # 后台管理翻译
│   ├── bbs_common.php  # 通用翻译（帖子/用户/通知等）
│   ├── bbs_install.php # 安装程序翻译
│   └── bbs.js          # 前端 JS 翻译
├── en-us/          # 英文
│   └── ...
└── zh-tw/          # 繁体中文
    └── ...
```

文件职责说明：

| 文件 | 职责 |
|------|------|
| `bbs.php` | 语言包入口，加载 `bbs_common.php` 并支持 hook 扩展 |
| `bbs_admin.php` | 后台管理界面翻译，包含后台导航、表单提示等 |
| `bbs_common.php` | 通用翻译，涵盖帖子、用户、通知、搜索等所有前台文案 |
| `bbs_install.php` | 安装向导界面翻译 |
| `bbs.js` | 前端 JavaScript 使用的简短翻译，用于弹窗/确认框等 |

### 代码示例

#### lang() 函数使用

```php
// 基本用法
echo lang('user_login');  // 输出：用户登录

// 带占位符替换
echo lang('delete_thread_confirm', array('title' => '测试帖子'));
// 输出：确定删除「测试帖子」吗？

// 键不存在时返回原始 key（用于调试）
echo lang('nonexistent_key');
// 输出：lang[nonexistent_key]
```

#### 语言包文件示例

```php
<?php
// lang/fr-fr/bbs_admin.php
return array(
    'admin_setting' => 'Paramètres',
    'admin_user' => 'Utilisateurs',
    // ... 其余翻译
);
```

#### 前端 JS 中使用

```javascript
// lang/zh-cn/bbs.js
var lang = {
    'confirm': '确定',
    'cancel': '取消',
    // hook lang_zh_cn_bbs_js.htm
};
```

## 常见问题

1. **切换语言后部分文字仍是旧语言？**
   通常是因为对应语言包缺少某个键的翻译。检查目标语言包的 `bbs_common.php` 是否包含该键，补充翻译即可。同时清除浏览器缓存和服务器 OPcache。

2. **如何让系统根据浏览器自动选择语言？**
   将 `conf/conf.php` 中的 `lang` 设为站点默认语言，同时在模板中输出 `<meta http-equiv="content-language" content="zh-cn">` 并配合 `browser_lang()` 函数实现自动检测。用户首次访问时会根据浏览器 `Accept-Language` 头匹配。

3. **新增语言包后不生效？**
   确认完成以下步骤：① 语言目录名符合格式（如 `fr-fr`）；② 文件结构完整；③ `route/lang.php` 的 `$supported` 数组包含新语言代码；④ `bbs_common.php` 中存在对应语言标签键。

4. **语言包文件修改后没有立即生效？**
   虽然系统会自动处理 OPcache，但在某些服务器配置下仍可能延迟。可访问后台「系统工具 → 清理缓存」或重启 PHP-FPM 进程。

5. **插件如何添加自己的翻译？**
   插件可通过 hook 机制追加翻译。在语言包的 hook 位置（如 `// hook lang_zh_cn_bbs_common.php`）使用 `add_hook('lang_zh_cn_bbs_common', $callback)` 注册回调，返回额外的翻译数组即可。
