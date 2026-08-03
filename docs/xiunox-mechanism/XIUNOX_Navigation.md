# XIUNOX_Navigation 导航系统

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

Xiuno X 的导航系统采用**去核心化插件注册架构**，核心由 `NavService` 和 `DiscoverService` 两个服务类驱动。导航菜单分为**顶部导航、侧边导航、手机导航**三种位置，加上独立的**发现页**入口。所有插件导航项均通过各自目录下的 `nav_register.php` 文件自注册，`NavService` 不再硬编码任何插件条目，符合开闭原则。

系统支持 lazy 加载机制：首次被访问时自动扫描所有已启用插件的 `nav_register.php` 文件完成注册。插件启用即在前台展示，无需后台单独控制。同时提供 `isActive()` 方法实现激活状态判断，支持精确匹配和前缀匹配（处理分页场景），以及 `href()` 方法统一处理 URL 渲染（兼容历史存量 URL 格式）。

## 站长指南

### 配置入口

后台导航管理位于：**后台 → 设置 → 导航**（`setting-nav`）。该页面分为两部分：

- **自定义菜单项**：可自由添加、编辑、拖拽排序（支持顶部/侧边/手机三个位置）
- **插件导航项**：由插件自动注册，为只读展示，提供 URL 复制按钮

### 配置项说明

| 字段 | 说明 |
|------|------|
| 菜单名称 | 显示在导航上的文字 |
| URL 地址 | 支持路由名（如 `forum-1`）、`?xxx.htm`、`/xxx`、外链 |
| 图标 | FontAwesome / Tabler 图标类名 |
| 位置 | top（顶部）/ side（侧边）/ mobile（手机） |
| 排序权重 | 数值越小越靠前 |

### 使用场景

1. **添加顶部导航链接**：在后台导航设置页新增菜单项，位置选"顶部"，填写路由名即可
2. **侧边栏快捷入口**：在插件注册的导航项中，位置选 `side`，即可在侧边栏展示
3. **手机端独立导航**：为移动端单独配置菜单，位置选 `mobile`
4. **外链跳转**：填写完整 URL（`https://example.com`），系统将保持外链原样输出

### 注意事项

- 插件导航项为只读，如需调整请在插件的 `nav_register.php` 中修改 `rank` 值
- URL 规范化会自动剥离 `.htm` / `.html` 后缀和前导 `?`，建议直接使用路由名
- 激活状态匹配基于当前请求路由，分页场景（如 `forum-1-2`）会自动匹配 `forum-1`

## 开发者指南

### 核心服务类

#### NavService（`lib/NavService.php`）

| 方法 | 说明 |
|------|------|
| `register($plugin_id, $defaults)` | 插件自注册入口 |
| `getPluginNavItems($position)` | 获取指定位置的所有插件导航项 |
| `isActive($url)` | 判断导航项是否匹配当前路由（支持精确/前缀匹配） |
| `href($url)` | 渲染导航链接 href，兼容历史 URL 格式 |
| `normalize($url)` | 规范化 URL 为路由名格式 |
| `url_frontend($url)` | 生成前台固定链接（绕过 admin 路径限制） |

#### DiscoverService（`lib/DiscoverService.php`）

| 方法 | 说明 |
|------|------|
| `register($plugin_id, $defaults)` | 插件注册到发现页 |
| `getPluginDiscoverItems($for_admin)` | 获取所有已启用的发现页插件项 |
| `savePluginDiscoverConfig($plugin_id, $data)` | 保存单个插件的发现页配置 |
| `getPluginDiscoverConfig($plugin_id)` | 获取单个插件的发现页配置 |

### 钩子点

- **`nav_register.php`**：插件导航注册钩子，位于插件根目录
- **`discover_register.php`**：发现页注册钩子，位于插件根目录
- 首次调用 `getPluginNavItems()` 时触发 lazy 加载，扫描所有启用插件的注册文件

### 扩展方式

#### 1. 注册顶部/侧边/手机导航

在插件目录下创建 `nav_register.php`：

```php
<?php
!defined('DEBUG') AND exit;

NavService::register('my_plugin', array(
    'position'  => array('top', 'side', 'mobile'),
    'url'       => 'my-plugin-page',
    'icon'      => 'ti-star',
    'name_lang' => 'my_plugin_nav',
    'rank'      => 100,
));
```

#### 2. 注册发现页入口

在插件目录下创建 `discover_register.php`：

```php
<?php
!defined('DEBUG') AND exit;

DiscoverService::register('my_plugin', array(
    'url'       => 'my-plugin-page',
    'icon'      => 'ti-star',
    'name_lang' => 'my_plugin_discover',
    'rank'      => 100,
));
```

#### 3. 后台菜单扩展

后台菜单通过 `admin/menu.conf.php` 配置，返回数组结构如下：

```php
return array(
    'my_plugin' => array(
        'url'  => url('my-plugin-config'),
        'text' => lang('my_plugin_menu'),
        'icon' => 'ti-star',
        'tab'  => array(
            'config' => array('url' => url('my-plugin-config'), 'text' => lang('my_plugin_config')),
        ),
    ),
);
```

### 代码示例

前台模板中渲染导航并判断激活状态：

```php
$items = NavService::getPluginNavItems('top');
foreach ($items as $item) {
    $active = NavService::isActive($item['url']) ? ' active' : '';
    echo '<li class="nav-item' . $active . '">';
    echo '<a href="' . NavService::href($item['url']) . '">' . $item['name'] . '</a>';
    echo '</li>';
}
```

## 常见问题

**Q1：插件导航项没有显示在前台怎么办？**

请检查：① 插件是否已启用（后台 → 插件列表）；② `nav_register.php` 中 `position` 是否包含目标位置；③ `plugin_paths_enabled()` 是否能正确返回插件路径。

**Q2：如何让导航项在侧边栏和手机端显示不同的名称？**

`name_lang` 字段支持语言键，可通过 `lang()` 函数动态解析。如需按位置显示不同名称，建议在前台模板中根据 `$item['source']` 字段做条件判断，或使用 CSS 类 `class` 字段配合样式隐藏。

**Q3：`isActive()` 对分页 URL（如 `forum-1-2`）能正确匹配 `forum-1` 吗？**

可以。`isActive()` 采用前缀匹配策略，当导航项 URL 为 `forum-1`、当前路由为 `forum-1-2` 时（`strpos` 精确匹配失败后进入前缀匹配分支），会正确返回 `true`，同时精确匹配场景（如 `rank` 对 `rank`）也能正常工作。

**Q4：外链在导航中是如何处理的？**

外链（以 `http://`、`https://`、`//` 开头）会被原样返回，不参与 `normalize()` 规范化和 `isActive()` 激活判断。锚点（以 `#` 开头）同样直接输出。

**Q5：`nav_register.php` 和 `discover_register.php` 的加载时机是什么？**

两者均为 lazy 加载：首次调用 `getPluginNavItems()` 或 `getPluginDiscoverItems()` 时触发 `ensureRegistered()`，扫描所有已启用插件的对应注册文件。后续请求中 `$registered` 标记为 `true`，不会重复扫描。

