<?php

// SEO: 动态生成 robots.txt，确保 Sitemap 行是完整 URL（含协议+域名）
// Google 抓取工具要求 Sitemap 字段必须是完整 URL，相对路径 /sitemap.xml 会报 Invalid sitemap URL
// 参考 route/sitemap.php 模式，由 index.php 早期拦截 /robots.txt 后 include 此文件
// ponytail: 主体内容保持静态字符串便于审查，仅 Sitemap 行动态拼接 http_url_path() 站点根 URL

// hook robots_start.php

$_robots_base = http_url_path();
// 兼容 base_path 末尾是否带 /：sitemap 路由匹配 /sitemap.xml，base 末尾有/则拼出 //sitemap.xml
$_robots_sitemap_url = rtrim($_robots_base, '/') . '/sitemap.xml';

// robots.txt 主体（与原静态 robots.txt 一致，仅删除末尾的 Sitemap 相对路径行）
// hook robots_body.php
$_robots_body = '# XIUNOX robots.txt

User-agent: *

# 敏感目录
Disallow: /admin/
Disallow: /conf/
Disallow: /log/
Disallow: /tmp/
Disallow: /install/

# API 接口
Disallow: /api/

# 用户相关页面（无 SEO 价值）
Disallow: /user-login*
Disallow: /user-logout*
Disallow: /user-create*
Disallow: /user-resetpw*
Disallow: /my-*

# 功能页面（无 SEO 价值）
Disallow: /search-*
Disallow: /post-create*
Disallow: /banned*
Disallow: /thread-create*

# 附件临时目录
Disallow: /upload/tmp/

# 静态资源（无需索引）
Disallow: /view/vendor/

# ===== AI 爬虫（全部允许，最大化 AI 引擎曝光）=====
User-agent: GPTBot
Allow: /

User-agent: OAI-SearchBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: anthropic-ai
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: PerplexityUser
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: CCBot
Allow: /

User-agent: Applebot-Extended
Allow: /

User-agent: Bytespider
Allow: /

User-agent: cohere-ai
Allow: /

# ===== 国内 AI 爬虫 =====
# 豆包 / 字节跳动 AI（Bytespider 已在上面允许）
User-agent: ToutiaoSpider
Allow: /

# 百度 AI / 文心一言（Baiduspider 系列）
User-agent: Baiduspider
Allow: /

User-agent: Baiduspider-render
Allow: /

User-agent: Baiduspider-image
Allow: /

# 搜狗 AI
User-agent: sogou spider
Allow: /

User-agent: Sogou web spider
Allow: /

# 360 AI
User-agent: 360Spider
Allow: /

User-agent: HaosouSpider
Allow: /

# 神马搜索（UC/阿里）
User-agent: YisouSpider
Allow: /
';

// hook robots_end.php

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $_robots_body;
echo "\n# Sitemap（动态生成，自动适配站点域名）\n";
echo 'Sitemap: ' . $_robots_sitemap_url . "\n";
exit;

?>
