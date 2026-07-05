<?php

/*
 * SEO Sitemap 动态生成
 * 访问 /sitemap 触发，输出 application/xml
 * 列出首页、所有版块、最近 5000 条帖子
 */

!defined('DEBUG') AND exit('Access Denied.');

// 1 小时浏览器/CDN 缓存，减轻数据库压力
$ttl = 3600;
$_now = time();
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=' . $ttl);
header('Expires: ' . gmdate('D, d M Y H:i:s', $_now + $ttl) . ' GMT');
header('Pragma: cache');

// 站点根 URL（带尾斜杠）
$base = http_url_path();

// XML 转义辅助
function _sitemap_esc($s) {
	return htmlspecialchars(strval($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// 输出 url 节点辅助
function _sitemap_url($loc, $lastmod = 0, $changefreq = '', $priority = '') {
	$out = "  <url>\n    <loc>" . _sitemap_esc($loc) . "</loc>\n";
	if($lastmod > 0) $out .= "    <lastmod>" . gmdate('Y-m-d\TH:i:s\Z', $lastmod) . "</lastmod>\n";
	if($changefreq) $out .= "    <changefreq>" . $changefreq . "</changefreq>\n";
	if($priority) $out .= "    <priority>" . $priority . "</priority>\n";
	$out .= "  </url>\n";
	return $out;
}

$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// 1. 首页
$out .= _sitemap_url($base, $_now, 'always', '1.0');

// 2. 所有版块（forum_list_cache 已按 rank 排序）
$forumlist = forum_list_cache();
foreach($forumlist as $f) {
	if(empty($f['fid']) || empty($f['name'])) continue;
	$forum_url = $base . ltrim(forum_url($f['fid']), '/');
	$lastmod = !empty($f['last_date']) ? intval($f['last_date']) : 0;
	$out .= _sitemap_url($forum_url, $lastmod, 'hourly', '0.8');
}

// 3. 最近 5000 条已审核帖子（按 tid 倒序）
$_sitemap_threads = db_find('thread', 
	array('is_deleted' => 0, 'audit_status' => 1),
	array('tid' => -1), 
	1, 5000, 'tid', 
	array('tid', 'last_date')
);
foreach($_sitemap_threads as $t) {
	$thread_url = $base . ltrim(thread_url($t['tid']), '/');
	$lastmod = !empty($t['last_date']) ? intval($t['last_date']) : 0;
	$out .= _sitemap_url($thread_url, $lastmod, 'daily', '0.6');
}

$out .= '</urlset>' . "\n";

// hook sitemap_end.php

echo $out;

?>
