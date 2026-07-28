<?php

// SEO: 动态生成 sitemap.xml，包含首页 + 所有公开版块 + 最近 N 条帖子
// ponytail: 单文件 sitemap，URL 数上限 50000（Google 规范），实际一般 < 5000 够用
// 若帖子数超大需分片（sitemap-index + sitemap-thread-1.xml），目前站点规模未达到
// 受后台 sitemap_enabled 开关控制，关闭时返回 404

// hook sitemap_start.php

if(!function_exists('conf')) {
	// 兼容性：conf() 函数在 index.inc.php 中定义，sitemap 直接访问时可能未加载
}
$_sitemap_enabled = !isset($conf['sitemap_enabled']) || $conf['sitemap_enabled'];
if(!$_sitemap_enabled) {
	http_response_code(404);
	exit('sitemap is disabled');
}

// 1. 缓存 N 秒（后台可配置），避免每次请求查 DB
$_sitemap_cache_key = 'seo_sitemap_xml_v1';
$_sitemap_cache_ttl = isset($conf['sitemap_cache_ttl']) ? max(60, intval($conf['sitemap_cache_ttl'])) : 3600;
$_sitemap_thread_limit = isset($conf['sitemap_thread_limit']) ? max(100, intval($conf['sitemap_thread_limit'])) : 1000;
$_sitemap_xml = CacheHelper::remember($_sitemap_cache_key, $_sitemap_cache_ttl, function() {
	global $conf, $db;
	$_sitemap_thread_limit_local = isset($conf['sitemap_thread_limit']) ? max(100, intval($conf['sitemap_thread_limit'])) : 1000;

	// 基础 URL（站点根，含协议+域名+base_path）
	$_base = http_url_path();
	// XML 转义辅助
	$_esc = function($s) {
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	};

	$_urls = array();

	// 1) 首页（daily，priority 1.0）
	$_urls[] = array(
		'loc' => $_base,
		'lastmod' => date('Y-m-d'),
		'changefreq' => 'daily',
		'priority' => '1.0',
	);

	// 2) 所有公开版块（daily，priority 0.9）
	$_forumlist = function_exists('forum_list_cache') ? forum_list_cache() : array();
	if(!empty($_forumlist)) {
		foreach($_forumlist as $_f) {
			// 跳过分区（fup=0 的是分区，本身不存帖子，无需进 sitemap）
			// ponytail: forum 表无 access_cid 字段，分区判断用 fup=0
			if(empty($_f['fup'])) continue;
			$_urls[] = array(
				'loc' => absolute_url(forum_url($_f['fid'])),
				'lastmod' => !empty($_f['last_date']) ? date('Y-m-d', $_f['last_date']) : date('Y-m-d'),
				'changefreq' => 'daily',
				'priority' => '0.9',
			);
		}
	}

	// 3. 最近 N 条帖子（按 last_date 倒序，hourly，priority 0.8）
	// ponytail: 默认 1000 条上限兼顾抓取效率与覆盖度，后台可配置
	$_recent_threads = db_find('thread', array('is_deleted' => 0), array('last_date' => -1), 1, $_sitemap_thread_limit_local, 'tid');
	if(!empty($_recent_threads)) {
		foreach($_recent_threads as $_t) {
			$_urls[] = array(
				'loc' => absolute_url(thread_url($_t['tid'])),
				'lastmod' => date('Y-m-d', $_t['last_date']),
				'changefreq' => 'hourly',
				'priority' => '0.8',
			);
		}
	}

	// 4) 版块总览 + 排行榜（weekly，priority 0.6）
	$_urls[] = array(
		'loc' => absolute_url(url('forum_index')),
		'lastmod' => date('Y-m-d'),
		'changefreq' => 'weekly',
		'priority' => '0.6',
	);
	$_urls[] = array(
		'loc' => absolute_url(url('rank')),
		'lastmod' => date('Y-m-d'),
		'changefreq' => 'weekly',
		'priority' => '0.6',
	);

	// 组装 XML
	$_xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$_xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach($_urls as $_u) {
		$_xml .= "\t<url>\n";
		$_xml .= "\t\t<loc>" . $_esc($_u['loc']) . "</loc>\n";
		$_xml .= "\t\t<lastmod>" . $_u['lastmod'] . "</lastmod>\n";
		$_xml .= "\t\t<changefreq>" . $_u['changefreq'] . "</changefreq>\n";
		$_xml .= "\t\t<priority>" . $_u['priority'] . "</priority>\n";
		$_xml .= "\t</url>\n";
	}
	$_xml .= '</urlset>' . "\n";

	return $_xml;
});

// hook sitemap_end.php

// 输出 XML（不渲染 header/footer 模板）
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $_sitemap_xml;
exit;

?>
