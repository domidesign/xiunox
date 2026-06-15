<?php

// hook model_attach_start.php

// ------------> 最原生的 CURD，无关联其他数据。

function attach__create($arr) {
	// hook model_attach__create_start.php
	$r = db_create('attach', $arr);
	// hook model_attach__create_end.php
	return $r;
}

function attach__update($aid, $arr) {
	// hook model_attach__update_start.php
	$r = db_update('attach', array('aid'=>$aid), $arr);
	// hook model_attach__update_end.php
	return $r;
}

function attach__read($aid) {
	// hook model_attach__read_start.php
	$attach = db_find_one('attach', array('aid'=>$aid));
	// hook model_attach__read_end.php
	return $attach;
}

function attach__delete($aid) {
	// hook model_attach__delete_start.php
	$r = db_delete('attach', array('aid'=>$aid));
	// hook model_attach__delete_end.php
	return $r;
}

function attach__find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook model_attach__find_start.php
	$attachlist = db_find('attach', $cond, $orderby, $page, $pagesize);
	// hook model_attach__find_end.php
	return $attachlist;
}

// ------------> 关联 CURD，主要是强相关的数据，比如缓存。弱相关的大量数据需要另外处理。

function attach_create($arr) {
	// hook model_attach_create_start.php
	$r = attach__create($arr);
	// hook model_attach_create_end.php
	return $r;
}

function attach_update($aid, $arr) {
	// hook model_attach_update_start.php
	$r = attach__update($aid, $arr);
	// hook model_attach_update_end.php
	return $r;
}

function attach_read($aid) {
	// hook model_attach_read_start.php
	$attach = attach__read($aid);
	attach_format($attach);
	// hook model_attach_read_end.php
	return $attach;
}

function attach_delete($aid) {
	// hook model_attach_delete_start.php
	global $conf;
	$attach = attach_read($aid);
	$path = $conf['upload_path'].'attach/'.$attach['filename'];
	file_exists($path) AND unlink($path);

	// 删除缩略图
	$thumb_path = attach_thumb_path($attach['filename']);
	if($thumb_path) {
		$full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
		file_exists($full_thumb_path) AND unlink($full_thumb_path);
	}

	$r = attach__delete($aid);
	// hook model_attach_delete_end.php
	return $r;
}

function attach_delete_by_pid($pid) {
	global $conf;
	list($attachlist, $imagelist, $filelist) = attach_find_by_pid($pid);
	// hook model_attach_delete_by_pid_start.php
	foreach($attachlist as $attach) {
		$path = $conf['upload_path'].'attach/'.$attach['filename'];
		file_exists($path) AND unlink($path);
		// 删除缩略图
		$thumb_path = attach_thumb_path($attach['filename']);
		if($thumb_path) {
			$full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
			file_exists($full_thumb_path) AND unlink($full_thumb_path);
		}
		attach__delete($attach['aid']);
	}
	// hook model_attach_delete_by_pid_end.php
	return count($attachlist);
}

function attach_delete_by_uid($uid) {
	global $conf;
	// hook model_attach_delete_by_uid_start.php
	$attachlist = db_find('attach', array('uid'=>$uid), array(), 1, 9000);
	foreach ($attachlist as $attach) {
		$path = $conf['upload_path'].'attach/'.$attach['filename'];
		file_exists($path) AND unlink($path);
		// 删除缩略图
		$thumb_path = attach_thumb_path($attach['filename']);
		if($thumb_path) {
			$full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
			file_exists($full_thumb_path) AND unlink($full_thumb_path);
		}
		attach__delete($attach['aid']);
	}
	// hook model_attach_delete_by_uid_end.php
}

function attach_find($cond = array(), $orderby = array(), $page = 1, $pagesize = 20) {
	// hook model_attach_find_start.php
	$attachlist = attach__find($cond, $orderby, $page, $pagesize);
	if($attachlist) foreach ($attachlist as &$attach) attach_format($attach);
	// hook model_attach_find_end.php
	return $attachlist;
}

// 获取 $filelist $imagelist
function attach_find_by_pid($pid) {
	$attachlist = $imagelist = $filelist = array();
	// hook model_attach_find_by_pid_start.php
	$attachlist = attach__find(array('pid'=>$pid), array(), 1, 1000);
	if($attachlist) {
		foreach ($attachlist as &$attach) {
			attach_format($attach);
			$attach['isimage'] ? ($imagelist[] = $attach) : ($filelist[] = $attach);
		}
		unset($attach);
	}
	// hook model_attach_find_by_pid_end.php
	return array($attachlist, $imagelist, $filelist);
}

// ------------> 其他方法

// 判断附件文件名是否为新格式（32位随机hex）
function attach_is_new_filename($filename) {
	$basename = basename($filename);
	return (bool)preg_match('/^[0-9a-f]{32}\./', $basename);
}

function attach_format(&$attach) {
	global $conf;
	if(empty($attach)) return;
	// hook model_attach_format_start.php
	$attach['create_date_fmt'] = date('Y-n-j', $attach['create_date']);

	// 根据文件名格式生成不同类型的URL
	if(attach_is_new_filename($attach['filename'])) {
		if(!empty($attach['isimage'])) {
			// 新格式图片：签名URL
			$sign_key = array_value($conf, 'attach_sign_key', '');
			$token = md5($attach['aid'] . $attach['filename'] . $sign_key);
			$attach['url'] = url("attach-read-{$attach['aid']}-{$token}");
		} else {
			// 新格式非图片：下载URL
			$attach['url'] = url("attach-download-{$attach['aid']}");
		}
	} else {
		// 旧格式：直接物理路径
		$attach['url'] = $conf['upload_url'].'attach/'.$attach['filename'];
	}

	// hook model_attach_format_end.php
}

function attach_count($cond = array()) {
	// hook model_attach_count_start.php
	$cond = db_cond_to_sqladd($cond);
	$n = db_count('attach', $cond);
	// hook model_attach_count_end.php
	return $n;
}

// 格式化文件大小
function format_filesize($bytes) {
	if($bytes >= 1073741824) {
		return round($bytes / 1073741824, 2) . ' GB';
	} elseif($bytes >= 1048576) {
		return round($bytes / 1048576, 2) . ' MB';
	} elseif($bytes >= 1024) {
		return round($bytes / 1024, 2) . ' KB';
	} else {
		return $bytes . ' B';
	}
}

function attach_type($name, $types) {
	// hook model_attach_type_start.php
	$ext = file_ext($name);
	foreach($types as $type=>$exts) {
		if($type == 'all') continue;
		if(in_array($ext, $exts)) {
			return $type;
		}
	}
	// hook model_attach_type_end.php
	return 'other';
}

// 根据附件文件名推导缩略图相对路径
// filename 格式: 202606/uid_xxx.jpg → 返回 202606/thumb/uid_xxx_thumb.jpg
function attach_thumb_path($filename) {
	if(empty($filename)) return '';
	$pathinfo = pathinfo($filename);
	$thumb_filename = $pathinfo['filename'].'_thumb.jpg';
	return $pathinfo['dirname'].'/thumb/'.$thumb_filename;
}

// 扫描垃圾的附件，每日清理一次
function attach_gc() {
	global $time, $conf;
	// hook model_attach_gc_start.php
	$tmpfiles = glob($conf['upload_path'].'tmp/*.*');
	if(is_array($tmpfiles)) {
		foreach($tmpfiles as $file) {
			// 清理超过一天还没处理的临时文件
			if($time - filemtime($file) > 86400) {
				unlink($file);
			}
		}
	}
	// 清理 tmp/thumb/ 下的过期缩略图
	$thumbfiles = glob($conf['upload_path'].'tmp/thumb/*.*');
	if(is_array($thumbfiles)) {
		foreach($thumbfiles as $file) {
			if($time - filemtime($file) > 86400) {
				unlink($file);
			}
		}
	}
	// hook model_attach_gc_end.php
}

// 关联 session 中的临时文件，并不会重新统计 images, files
function attach_assoc_post($pid) {
	global $uid, $time, $conf;
	$sess_tmp_files = _SESSION('tmp_files');
	//if(empty($tmp_files)) return;

    // fixed by qiukong, https://bbs.xiuno.com/thread-150336.htm
    if(!$sess_tmp_files && preg_match('/tmp\+files\|(a\:1\:\{.*\})/',_SESSION('data'),$arr)) {
        $sess_tmp_files = unserialize(str_replace(array('+','='),array('_','.'),$arr['1']));
    }
	
	$post = post__read($pid);
	if(empty($post)) return;
	
	// hook attach_assoc_post_start.php
	
	$tid = $post['tid'];
	$post['message_old'] = $post['message_fmt'];
	
	// 把临时文件 upload/tmp/xxx.xxx 也处理了
	//preg_match_all('#src="upload/tmp/(\w+\.\w+)"#', $post['message_old'], $m);
	//$use_tmp_files = $m[1]; // 实际使用的临时文件，不用的全部删除？如果是两个帖子一起编辑？
	
	// 将 session 中的数据和 message 中的数据合并。
	//$tmp_files = array_unique(array_merge($sess_tmp_files, $use_tmp_files));
	
	$attach_dir_save_rule = array_value($conf, 'attach_dir_save_rule', 'Ym');
	
	$tmp_files = $sess_tmp_files;
	if($tmp_files) {
		foreach($tmp_files as $file) {
			
			// 将文件移动到 upload/attach 目录
			$filename = file_name($file['url']);
			
			$day = date($attach_dir_save_rule, $time);
			$path = $conf['upload_path'].'attach/'.$day;
			$url = $conf['upload_url'].'attach/'.$day;
			!is_dir($path) AND mkdir($path, 0777, TRUE);
			
			$destfile = $path.'/'.$filename;
			$desturl = $url.'/'.$filename;
			$r = xn_copy($file['path'], $destfile);
			!$r AND xn_log("xn_copy($file[path]), $destfile) failed, pid:$pid, tid:$tid", 'php_error');
			if(is_file($destfile) && filesize($destfile) == filesize($file['path'])) {
				@unlink($file['path']);
			}

			// 移动缩略图到 attach/$day/thumb/ 目录
			if(!empty($file['thumb_url'])) {
				$thumb_relative = str_replace($conf['upload_url'].'tmp/', '', $file['thumb_url']);
				$thumb_src_path = $conf['upload_path'].'tmp/'.$thumb_relative;
				if(is_file($thumb_src_path)) {
					$thumb_dest_dir = $path.'/thumb';
					!is_dir($thumb_dest_dir) AND mkdir($thumb_dest_dir, 0777, TRUE);
					$thumb_filename = file_name($file['thumb_url']);
					$thumb_dest_path = $thumb_dest_dir.'/'.$thumb_filename;
					$thumb_dest_url = $url.'/thumb/'.$thumb_filename;
					$tr = xn_copy($thumb_src_path, $thumb_dest_path);
					if($tr && is_file($thumb_dest_path) && filesize($thumb_dest_path) == filesize($thumb_src_path)) {
						@unlink($thumb_src_path);
					}
					$post['message'] = str_replace($file['thumb_url'], $thumb_dest_url, $post['message']);
					$post['message_fmt'] = str_replace($file['thumb_url'], $thumb_dest_url, $post['message_fmt']);
				}
			}

			$arr = array(
				'tid'=>$tid,
				'pid'=>$pid,
				'uid'=>$uid,
				'filesize'=>$file['filesize'],
				'width'=>$file['width'],
				'height'=>$file['height'],
				'filename'=>"$day/$filename",
				'orgfilename'=>$file['orgfilename'],
				'filetype'=>$file['filetype'],
				'create_date'=>$time,
				'comment'=>'',
				'downloads'=>0,
				'isimage'=>$file['isimage']
			);
			
			// 插入后，进行关联
			$aid = attach_create($arr);
			$post['message'] = str_replace($file['url'], $desturl, $post['message']);
			$post['message_fmt'] = str_replace($file['url'], $desturl, $post['message_fmt']);
			
		}
	}

	// 清空 session
	$_SESSION['tmp_files'] = array();
	
	$post['message_old'] != $post['message_fmt'] AND post__update($pid, array('message'=>$post['message'], 'message_fmt'=>$post['message_fmt']));
	
	// 处理不在 message 中的图片，删除掉没有插入的图片附件
	/*
	list($attachlist, $imagelist, $filelist) = attach_find_by_pid($pid);
	foreach($imagelist as $k=>$attach) {
		$url = $conf['upload_url'].'attach/'.$attach['filename'];
		if(strpos($post['message_fmt'], $url) === FALSE) {
			unset($imagelist[$k]);
			attach_delete($attach['aid']);
		}
	}
	*/
	
	// 更新 images videos files
	list($attachlist, $imagelist, $filelist) = attach_find_by_pid($pid);
	$images = count($imagelist);
	$videos = 0;
	$files = 0;
	foreach($attachlist as $attach) {
		if(!empty($attach['isimage'])) continue; // images already counted
		if(isset($attach['filetype']) && $attach['filetype'] == 'video') {
			$videos++;
		} else {
			$files++;
		}
	}
	$post['isfirst'] AND thread__update($tid, array('images'=>$images, 'videos'=>$videos, 'files'=>$files));
	post__update($pid, array('images'=>$images, 'videos'=>$videos, 'files'=>$files));
	
	// hook attach_assoc_post_end.php
	
	return TRUE;
}


// ------------> 后台管理函数

// 带筛选/排序/分页的附件列表查询
function attach_admin_find($filter = array(), $orderby = array('aid'=>-1), $page = 1, $pagesize = 20) {
    // hook model_attach_admin_find_start.php
    $cond = array();

    // 类型筛选：根据 filetype 或后缀归类
    if(!empty($filter['type_category'])) {
        global $conf;
        $types = include $conf['app_path'].'conf/attach.conf.php';
        $category = $filter['type_category'];
        // 映射：image/video/music/document/archive/other
        $category_map = array(
            'image' => 'image',
            'video' => 'video',
            'audio' => 'music',
            'document' => array('office', 'pdf', 'text'),
            'archive' => 'zip',
            'other' => 'other'
        );
        if(isset($category_map[$category])) {
            $mapped = $category_map[$category];
            if(is_array($mapped)) {
                $exts = array();
                foreach($mapped as $m) {
                    if(isset($types[$m])) $exts = array_merge($exts, $types[$m]);
                }
            } else {
                $exts = isset($types[$mapped]) ? $types[$mapped] : array();
            }
            // 使用 SQL LIKE 条件匹配后缀
            if(!empty($exts)) {
                $like_parts = array();
                foreach($exts as $ext) {
                    $like_parts[] = "orgfilename LIKE '%." . addslashes($ext) . "'";
                }
                $cond['sql_extra'] = '(' . implode(' OR ', $like_parts) . ')';
            } elseif($category === 'other') {
                // 其他类型：排除已知类型
                $all_exts = isset($types['all']) ? $types['all'] : array();
                $known_exts = array();
                foreach($types as $k => $v) {
                    if($k !== 'all' && $k !== 'other' && !empty($v)) {
                        $known_exts = array_merge($known_exts, $v);
                    }
                }
                if(!empty($known_exts)) {
                    $like_parts = array();
                    foreach($known_exts as $ext) {
                        $like_parts[] = "orgfilename NOT LIKE '%." . addslashes($ext) . "'";
                    }
                    $cond['sql_extra'] = implode(' AND ', $like_parts);
                }
            }
        }
    }

    // 孤儿状态筛选
    if(isset($filter['orphan']) && $filter['orphan'] !== '') {
        if($filter['orphan'] == 1) {
            // 仅孤儿：tid=0 AND pid=0，或关联帖子/回复不存在
            $orphan_sql = "(tid = 0 AND pid = 0)";
            // 还需要包含 tid>0 但帖子已删除的，或 pid>0 但回复已删除的
            // 这个通过后续标记处理，这里先只筛选 tid=0 AND pid=0 的
            $cond['sql_extra'] = (isset($cond['sql_extra']) ? $cond['sql_extra'] . ' AND ' : '') . $orphan_sql;
        } elseif($filter['orphan'] == 0) {
            // 仅正常：tid>0 OR pid>0
            $cond['sql_extra'] = (isset($cond['sql_extra']) ? $cond['sql_extra'] . ' AND ' : '') . "(tid > 0 OR pid > 0)";
        }
    }

    // 关键词搜索
    if(!empty($filter['keyword'])) {
        $kw = addslashes($filter['keyword']);
        $kw_cond = "orgfilename LIKE '%{$kw}%'";
        $cond['sql_extra'] = (isset($cond['sql_extra']) ? $cond['sql_extra'] . ' AND ' : '') . $kw_cond;
    }

    $attachlist = db_find('attach', $cond, $orderby, $page, $pagesize);
    if($attachlist) {
        foreach ($attachlist as &$attach) {
            attach_format($attach);
            // 检测孤儿状态
            $attach['is_orphan'] = attach_admin_check_orphan($attach);
        }
        unset($attach);
    }

    // hook model_attach_admin_find_end.php
    return $attachlist ? $attachlist : array();
}

// 检测单个附件是否为孤儿
function attach_admin_check_orphan($attach) {
    if($attach['tid'] == 0 && $attach['pid'] == 0) {
        return TRUE; // 无关联
    }
    if($attach['pid'] > 0) {
        $post = db_find_one('post', array('pid'=>$attach['pid']));
        if(empty($post)) return TRUE;
    } elseif($attach['tid'] > 0) {
        $thread = db_find_one('thread', array('tid'=>$attach['tid']));
        if(empty($thread)) return TRUE;
    }
    return FALSE;
}

// 统计数据
function attach_admin_stats() {
    global $db;
    // hook model_attach_admin_stats_start.php

    $total = db_count('attach');

    // 总占用空间
    $tablepre = $db->tablepre;
    $size_row = db_find_one("SELECT SUM(filesize) AS total_size FROM `{$tablepre}attach`");
    $total_size = !empty($size_row['total_size']) ? $size_row['total_size'] : 0;

    // 孤儿数量（tid=0 AND pid=0 的简单统计，加上关联不存在的）
    // 先统计 tid=0 AND pid=0 的
    $orphan_simple = db_count('attach', array('tid'=>0, 'pid'=>0));

    // 统计 tid>0 但帖子已删除的
    $orphan_thread = 0;
    $orphan_post = 0;
    $sql_orphan_thread = "SELECT COUNT(*) AS cnt FROM `{$tablepre}attach` a LEFT JOIN `{$tablepre}thread` t ON a.tid = t.tid WHERE a.tid > 0 AND a.pid = 0 AND t.tid IS NULL";
    $row1 = db_find_one($sql_orphan_thread);
    if(!empty($row1['cnt'])) $orphan_thread = intval($row1['cnt']);

    // 统计 pid>0 但回复已删除的
    $sql_orphan_post = "SELECT COUNT(*) AS cnt FROM `{$tablepre}attach` a LEFT JOIN `{$tablepre}post` p ON a.pid = p.pid WHERE a.pid > 0 AND p.pid IS NULL";
    $row2 = db_find_one($sql_orphan_post);
    if(!empty($row2['cnt'])) $orphan_post = intval($row2['cnt']);

    $orphan_count = $orphan_simple + $orphan_thread + $orphan_post;

    // 按类型统计
    $types = include APP_PATH.'conf/attach.conf.php';
    $category_map = array(
        'image' => 'image',
        'video' => 'video',
        'audio' => 'music',
        'document' => array('office', 'pdf', 'text'),
        'archive' => 'zip',
    );
    $type_stats = array();
    $type_stats['image'] = 0;
    $type_stats['video'] = 0;
    $type_stats['audio'] = 0;
    $type_stats['document'] = 0;
    $type_stats['archive'] = 0;
    $type_stats['other'] = 0;

    // 简单方式：按 isimage 字段统计图片
    $image_count = db_count('attach', array('isimage'=>1));
    $type_stats['image'] = $image_count;
    $type_stats['other'] = max(0, $total - $image_count);

    // hook model_attach_admin_stats_end.php

    return array(
        'total' => $total,
        'total_size' => $total_size,
        'total_size_fmt' => format_filesize($total_size),
        'orphan_count' => $orphan_count,
        'type_stats' => $type_stats,
    );
}

// 获取所有孤儿附件ID列表
function attach_admin_orphan_ids() {
    global $db;
    $tablepre = $db->tablepre;

    // tid=0 AND pid=0
    $orphans1 = db_find('attach', array('tid'=>0, 'pid'=>0), array(), 1, 10000);
    $ids = array();
    if($orphans1) {
        foreach($orphans1 as $a) $ids[$a['aid']] = $a['aid'];
    }

    // tid>0 AND pid=0 但帖子已删除
    $sql1 = "SELECT a.aid FROM `{$tablepre}attach` a LEFT JOIN `{$tablepre}thread` t ON a.tid = t.tid WHERE a.tid > 0 AND a.pid = 0 AND t.tid IS NULL";
    $rows1 = db_find($sql1);
    if($rows1) {
        foreach($rows1 as $r) $ids[$r['aid']] = $r['aid'];
    }

    // pid>0 但回复已删除
    $sql2 = "SELECT a.aid FROM `{$tablepre}attach` a LEFT JOIN `{$tablepre}post` p ON a.pid = p.pid WHERE a.pid > 0 AND p.pid IS NULL";
    $rows2 = db_find($sql2);
    if($rows2) {
        foreach($rows2 as $r) $ids[$r['aid']] = $r['aid'];
    }

    return array_values($ids);
}

// 批量删除孤儿附件
function attach_admin_delete_orphans() {
    global $uid, $conf, $longip, $time;

    $ids = attach_admin_orphan_ids();
    if(empty($ids)) return 0;

    $deleted = 0;
    $deleted_filenames = array();
    foreach($ids as $aid) {
        $attach = attach_read($aid);
        if(empty($attach)) continue;

        $deleted_filenames[] = $attach['orgfilename'] . '(aid:' . $aid . ')';
        attach_delete($aid); // 已处理物理文件+缩略图+数据库记录
        $deleted++;
    }

    // 记录日志
    if($deleted > 0) {
        attach_admin_log('attach_batch_delete', 'attach', implode(',', $ids), '批量删除孤儿附件 ' . $deleted . ' 个：' . implode(', ', array_slice($deleted_filenames, 0, 20)));
    }

    return $deleted;
}

// 强制删除单个附件
function attach_admin_force_delete($aid) {
    global $uid, $conf, $longip, $time;

    $attach = attach_read($aid);
    if(empty($attach)) return FALSE;

    $filename = $attach['orgfilename'];

    // 先解除关联
    attach__update($aid, array('tid'=>0, 'pid'=>0));

    // 删除物理文件和数据库记录
    attach_delete($aid);

    // 记录日志
    attach_admin_log('attach_force_delete', 'attach', strval($aid), '强制删除附件：' . $filename);

    return TRUE;
}

// 写入管理操作日志（兼容旧调用，统一走 admin_log_create）
function attach_admin_log($action, $target_type, $target_ids, $detail = '') {
    admin_log_create($action, $target_type, $target_ids, $detail);
}

// hook model_attach_end.php

?>