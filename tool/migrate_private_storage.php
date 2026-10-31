<?php
/**
 * XIUNOX 存量附件私有化迁移工具（仅限命令行）
 *
 * 背景（2026-10-29 附件直链泄露事件）：
 *   upload/attach/ 历史上的应用中心压缩包、隐藏帖内媒体等受保护文件
 *   曾以公开静态资源形式存在，Nginx/Apache 拦截规则只能堵住直链，
 *   必须把文件本体迁出 Web 根目录（PrivateAttachmentStorage 私有存储）才算根治。
 *
 * 行为：
 *   默认 dry-run：只统计与抽样，不动任何文件。
 *   加 --apply：执行迁移（复制 → SHA-256 校验 → 删除公开副本 → 重写正文中的旧 URL）。
 *   判定标准与运行时一致（PrivateAttachmentStorage::needsProtection）：
 *     - 非公开媒体（zip/rar/pdf/文档等全部）一律迁移；
 *     - 公开图片/视频若属于隐藏帖、待审帖、软删帖或受限版块，同样迁移；
 *     - 应用中心版本包（download_aid）无条件迁移。
 *
 * 用法：
 *   php tool/migrate_private_storage.php            # dry-run
 *   php tool/migrate_private_storage.php --apply    # 实际迁移
 *
 * 私有存储位置：环境变量 XIUNOX_PRIVATE_PATH 或 conf/private_storage.php 的 'path'，
 * 未配置时默认落到站点目录内的 upload/private/（自动创建，0700；弱保护，需服务器禁止访问该目录）。
 * 迁移完成后请到后台「其他 → 缓存」清空全站缓存（帖子列表缓存可能残留旧静态 URL）。
 */

if (php_sapi_name() !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
	header('HTTP/1.1 403 Forbidden');
	exit('Forbidden. CLI only.');
}

define('APP_PATH', dirname(__DIR__) . '/');
!defined('DEBUG') AND define('DEBUG', 0);
include APP_PATH . 'xiunophp/xiunophp.php';

// 迁移判定与 rewritePost 依赖的模型函数（xiunophp 只加载框架层，不含 model 层）
foreach (array(
	'model/misc.func.php',
	'model/forum.func.php',
	'model/forum_access.func.php',
	'model/group.func.php',
	'model/thread.func.php',
	'model/post.func.php',
	'model/attach.func.php',
) as $_model_file) {
	include_once APP_PATH . $_model_file;
}
include APP_PATH . 'lib/HiddenContentGuard.php';
include APP_PATH . 'lib/PrivateAttachmentStorage.php';
include APP_PATH . 'lib/ApiContentService.php';

$apply = in_array('--apply', $argv, true);
echo "=== XIUNOX 存量附件私有化迁移 ===\n";
echo "模式：" . ($apply ? "APPLY（实际迁移）" : "DRY-RUN（仅统计，加 --apply 执行）") . "\n";
echo "私有存储根：" . PrivateAttachmentStorage::root() . "\n\n";

$scanned = 0; $need = 0; $migrated = 0; $alreadyPrivate = 0; $failed = 0;
$cursor = 0;

do {
	$rows = db_find('attach', array('aid' => array('>' => $cursor)), array('aid' => 1), 1, 200);
	if (empty($rows)) break;
	foreach ($rows as $attach) {
		$cursor = intval($attach['aid']);
		$scanned++;

		try {
			if (!PrivateAttachmentStorage::needsProtection($attach)) continue; // 公开媒体，留在原地
			$need++;

			if (PrivateAttachmentStorage::isPrivate($attach)) { $alreadyPrivate++; continue; }

			if ($apply) {
				$result = PrivateAttachmentStorage::protect($attach);
				if ($result['state'] === 'migrated') {
					PrivateAttachmentStorage::rewritePost($attach);
					$migrated++;
					if ($migrated <= 20 || $migrated % 500 === 0) {
						echo "  [migrated] aid={$attach['aid']} {$attach['filename']}\n";
					}
				} else {
					$alreadyPrivate++;
				}
			} elseif ($need <= 20) {
				echo "  [need]    aid={$attach['aid']} {$attach['filename']} ({$attach['filetype']})\n";
			}
		} catch (Throwable $e) {
			$failed++;
			echo "  [FAILED]  aid={$attach['aid']} {$attach['filename']}: {$e->getMessage()}\n";
		}
	}
} while (count($rows) === 200);

// upload/tmp 遗留检查：历史 .meta.json / 临时压缩包仍躺在公开目录（Web 规则已拦截，但应清理）
$tmpResidue = 0;
foreach (glob($conf['upload_path'] . 'tmp/*.meta.json') ?: array() as $f) $tmpResidue++;
foreach (glob($conf['upload_path'] . 'tmp/*.{zip,rar,7z,tar,gz,tgz}', GLOB_BRACE) ?: array() as $f) $tmpResidue++;

echo "\n--- 结果 ---\n";
echo "扫描附件总数：{$scanned}\n";
echo "需保护附件：{$need}" . ($alreadyPrivate ? "（已在私有存储 {$alreadyPrivate}）" : "") . "\n";
if ($apply) {
	echo "本次实际迁移：{$migrated}，失败：{$failed}\n";
	echo "\n后续步骤：\n";
	echo " 1. 后台「其他 → 缓存」清空全站缓存（列表缓存可能残留旧静态 URL）；\n";
	echo " 2. 抽查一个已迁移压缩包：直链 /upload/attach/... 应 403/404，站内下载入口应正常；\n";
	echo " 3. CDN 侧清理 upload/attach 路径的历史缓存。\n";
} else {
	echo "（dry-run 未改动文件；确认无误后加 --apply 执行）\n";
}
if ($tmpResidue > 0) {
	echo "另发现 upload/tmp 下遗留敏感文件 {$tmpResidue} 个（.meta.json/压缩包），Web 规则已拦截，可手工删除。\n";
}
