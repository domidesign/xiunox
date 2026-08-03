# XIUNOX_SEO SEO 机制

> **适用人群**：站长 / 开发者
> **最后更新**：2026-08-02

## 概述

XIUNOX 论坛系统内置了一套完整的 SEO 优化机制，涵盖搜索引擎爬虫规则、AI 引擎友好、结构化数据、社交分享卡片等多个维度。设计理念是 **"开箱即用 + 精细控制"** —— 系统层自动处理基础 SEO 要素，站长可在后台可视化配置关键选项，开发者则通过钩子机制扩展高级功能。

核心组件包括：动态 sitemap.xml 生成器、robots.txt / llms.txt 双爬虫入口、每页 Meta 标签注入、JSON-LD 结构化数据引擎、Open Graph / Twitter Card 输出、Canonical URL 去重，以及 SEO 健康检查面板。

---

## 站长指南

### 配置入口

登录后台 → **站点设置** → **SEO 设置** 页面，分为四个区块：

| 区块 | 说明 |
|------|------|
| 基础 SEO 设置 | 站点关键词、描述、副标题 |
| 站点地图 (Sitemap) | 启用开关、帖子数量上限、缓存时长 |
| AI/GEO 优化 | Open Graph、JSON-LD、Canonical URL 开关 |
| llms.txt 编辑 | 直接编辑 AI 引擎站点说明文件 |

### 配置项说明

**基础 SEO 设置**

- **站点关键词**：全站默认 meta keywords，多值用英文逗号分隔。首页/排行榜/发现页使用此值，帖子/版块页自动替换为动态关键词。
- **站点描述**：全站默认 meta description，搜索引擎结果摘要。留空时回退到站点介绍。
- **站点副标题**：显示在浏览器标题栏站点名称之后。

**Sitemap 配置**

- **启用 Sitemap**：关闭后 `/sitemap.xml` 返回 404。默认开启。
- **帖子数量上限**：单次 sitemap 包含的最近帖子数，建议 500–2000。超大站点需要分片处理。
- **缓存时长**：sitemap XML 缓存秒数，默认 3600 秒（1 小时），避免频繁查库。

**AI/GEO 优化**

- **Open Graph / Twitter Card**：输出 `og:title`、`og:description`、`og:image`、`twitter:card` 等 meta，控制社交平台分享卡片样式。
- **JSON-LD 结构化数据**：输出 `QAPage`、`DiscussionForumPosting`、`BreadcrumbList`、`WebSite`、`Profile` 等 schema.org 数据，帮助搜索引擎和 AI 引擎精确理解页面内容。
- **Canonical URL**：输出 `<link rel="canonical">`，避免分页、URL 参数导致的重复内容惩罚。

### 使用场景

1. **新站启动**：配置基础 SEO 三项 → 启用 Sitemap → 点击"查看 sitemap.xml" → 提交到 Google Search Console 和百度站长平台。
2. **内容运营**：保持 Sitemap 启用，系统自动收录新帖。llms.txt 中的版块信息建议在版块创建/调整后同步更新。
3. **社交分享**：保持 Open Graph 开启，分享链接时自动展示标题、描述和封面图卡片。
4. **AI 引用优化**：llms.txt 中补充实际版块列表和简介，可大幅提升 AI 引擎（ChatGPT、Claude、Perplexity、文心一言等）引用内容的准确度。

### 注意事项

- **robots.txt 已配置 AI 爬虫全放行**：GPTBot、ClaudeBot、PerplexityBot、Baiduspider 等均为 `Allow: /`，不要误改。
- **敏感路径已禁止索引**：`/admin/`、`/conf/`、`/api/`、`/user-login*` 等不会被搜索引擎收录。
- **noindex 自动标记**：搜索页、个人中心、帖子编辑页等无 SEO 价值页面自动输出 `noindex,follow`。
- **系统层已实现的 SEO 能力无需额外插件**：伪静态 URL、prev/next 分页 meta、robots meta（`max-image-preview:large`、`max-snippet:-1`）均已内置。
- **SEO 健康检查面板**：后台会实时检测各项配置状态，绿色=已配置，黄色=建议优化，红色=必须处理。

---

## 开发者指南

### 核心服务类

| 组件 | 文件位置 | 说明 |
|------|----------|------|
| Sitemap 生成器 | `route/sitemap.php` | 动态生成 XML，含首页+版块+最近帖子+聚合页，支持配置化缓存 |
| robots.txt | 根目录 `robots.txt` | 静态文件，管理爬虫规则和 sitemap 引用 |
| llms.txt | 根目录 `llms.txt` | 面向 AI 引擎的站点说明，可在后台编辑 |

### 钩子点

Sitemap 生成器暴露了两个钩子位，便于插件扩展：

- `sitemap_start.php`：在 sitemap 生成前执行，可修改配置项或注入额外数据源。
- `sitemap_end.php`：在 sitemap XML 组装完成后执行，可追加额外 URL 或修改 XML 结构。

### 扩展方式

**扩展 sitemap 条目**

在 `sitemap_start.php` 钩子中，可以通过修改全局 `$_urls` 数组注入自定义 URL（如标签页、专题页等）。

**新增 JSON-LD Schema 类型**

系统默认输出 `QAPage`（问答帖）、`DiscussionForumPosting`（讨论帖）、`BreadcrumbList`（版块页）、`WebSite`（首页）、`Profile`（用户页）。开发者可通过 Meta 注入钩子扩展 `FAQPage`、`Review`、`Event`、`HowTo` 等 schema 类型。

**Meta 标签注入**

每页的 `<head>` 区域由模板层统一渲染，系统自动注入以下 meta：

- `keywords` / `description`：聚合页用站点默认值，内容页用动态值
- `og:title` / `og:description` / `og:image` / `og:type`
- `twitter:card` / `twitter:title` / `twitter:description`
- `<link rel="canonical">`：Canonical URL
- `<meta name="robots">`：默认 `index,follow,max-image-preview:large,max-snippet:-1`
- `prev` / `next`：分页导航
- `theme-color`：移动端浏览器地址栏配色

### 代码示例

**示例一：通过 sitemap_start 钩子追加自定义 URL**

```php
<?php
// 在 plugin/your-plugin/hook/sitemap_start.php 中

// 追加标签聚合页到 sitemap
global $_urls, $_base, $_esc;

$tags = db_find('tag', array(), array('count' => -1), 1, 200);
foreach ($tags as $tag) {
    $_urls[] = array(
        'loc'      => absolute_url(url('tag', array('tag' => $tag['name']))),
        'lastmod'  => date('Y-m-d', $tag['last_date'] ?? time()),
        'changefreq' => 'weekly',
        'priority' => '0.5',
    );
}
```

**示例二：为自定义页面扩展 JSON-LD 结构化数据**

```php
<?php
// 在 theme/your-theme/inc/meta_hook.php 中

add_hook('meta_tags', function() {
    global $_thread;
    if (empty($_thread)) return;

    // 扩展 FAQPage schema（针对含"FAQ"标签的帖子）
    if (strpos($_thread['subject'], 'FAQ') !== false) {
        $jsonld = array(
            '@context' => 'https://schema.org',
            '@type'    => 'FAQPage',
            'mainEntity' => array(
                '@type' => 'Question',
                'name'  => $_thread['subject'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => mb_substr(strip_tags($_thread['message']), 0, 300),
                ),
            ),
        );
        echo '<script type="application/ld+json">' . json_encode($jsonld, JSON_UNESCAPED_UNICODE) . '</script>';
    }
});
```

---

## 常见问题

1. **Sitemap 提交到哪些搜索引擎？**
   建议同时提交到 Google Search Console（搜索资源平台）和百度站长平台。访问 `/sitemap.xml` 确认内容正常后，复制 URL 粘贴到站长平台的 Sitemap 提交入口即可。Bing Webmaster Tools 也支持提交。

2. **为什么我的帖子在 sitemap 中没有出现？**
   请检查后台 Sitemap 配置中的"帖子数量上限"。默认只收录最近 1000 条帖子（按最后回帖时间倒序）。如果帖子总数超过上限，较早的帖子不会出现在 sitemap 中。可适当提高上限，或通过插件实现分片 sitemap（sitemap-index）。

3. **llms.txt 和 robots.txt 有什么区别？**
   两者都是爬虫规则文件，但面向不同的抓取者。`robots.txt` 主要服务传统搜索引擎（Google、百度等），`llms.txt` 专门面向 AI 语言模型引擎（ChatGPT、Claude、Perplexity 等）。本站点的 robots.txt 已配置所有主流 AI 爬虫全放行，llms.txt 则提供站点结构和内容语义说明，帮助 AI 更准确地理解和引用内容。

4. **Canonical URL 对我的站点有什么影响？**
   Canonical URL 告诉搜索引擎"这是该页面的权威版本"，可以避免因 URL 参数（如 `?sort=hot`、`?page=2`）、登录状态、URL 编码差异等导致的重复内容惩罚。系统在所有动态页面自动注入 Canonical URL，强烈建议保持开启。只有在多域名镜像站点场景下才需要手动调整。

5. **如何验证 SEO 配置是否生效？**
   三种方式验证：① 查看页面源代码，检查是否包含 `<meta name="keywords">`、`<meta property="og:title">`、`<script type="application/ld+json">` 等标签；② 使用 Google Rich Results Test 或 Schema Markup Validator 验证 JSON-LD 结构化数据；③ 通过 Google Search Console 的 URL 检查工具查看页面索引状态和抓取诊断。