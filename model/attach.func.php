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
	if(empty($attachlist)) return 0;

	// 删除物理文件和缩略图
	foreach($attachlist as $attach) {
		$path = $conf['upload_path'].'attach/'.$attach['filename'];
		file_exists($path) AND unlink($path);
		// 删除缩略图
		$thumb_path = attach_thumb_path($attach['filename']);
		if($thumb_path) {
			$full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
			file_exists($full_thumb_path) AND unlink($full_thumb_path);
		}
	}

	// 批量删除数据库记录，消除 N+1 查询
	$aids = arrlist_values($attachlist, 'aid');
	if(!empty($aids)) {
		db_delete('attach', array('aid'=>$aids));
	}

	// hook model_attach_delete_by_pid_end.php
	return count($attachlist);
}

function attach_delete_by_uid($uid) {
	global $conf;
	// hook model_attach_delete_by_uid_start.php
	// 分批处理，避免一次查询过多数据
	$batch_size = 1000;
	$page = 1;
	while(true) {
		$attachlist = db_find('attach', array('uid'=>$uid), array(), $page, $batch_size);
		if(empty($attachlist)) break;

		// 删除物理文件和缩略图
		foreach ($attachlist as $attach) {
			$path = $conf['upload_path'].'attach/'.$attach['filename'];
			file_exists($path) AND unlink($path);
			// 删除缩略图
			$thumb_path = attach_thumb_path($attach['filename']);
			if($thumb_path) {
				$full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
				file_exists($full_thumb_path) AND unlink($full_thumb_path);
			}
		}

		// 批量删除数据库记录，消除 N+1 查询
		$aids = arrlist_values($attachlist, 'aid');
		if(!empty($aids)) {
			db_delete('attach', array('aid'=>$aids));
		}

		if(count($attachlist) < $batch_size) break;
		$page++;
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
	
	$attach_dir_save_rule = array_value($conf, 'attach_dir_save_rule', 'Ym');
	$day = date($attach_dir_save_rule, $time);
	$attach_path = $conf['upload_path'].'attach/'.$day;
	$attach_url = $conf['upload_url'].'attach/'.$day;
	!is_dir($attach_path) AND mkdir($attach_path, 0777, TRUE);
	
	// ===== 方案1：从 session 中读取临时文件信息（原有逻辑） =====
	// 修复孤儿附件：只处理 message 中实际存在的临时文件
	// 对于附件（非图片/视频），由于不插入编辑器，始终保留
	$tmp_files = $sess_tmp_files;
	if($tmp_files) {
		foreach($tmp_files as $file) {
			
			// 检查临时文件 URL 是否在 message 中（图片/视频）
			// 附件（非图片/视频）不插入编辑器，始终保留
			$file_url_in_message = (strpos($post['message'], $file['url']) !== false || strpos($post['message_fmt'], $file['url']) !== false);
			$is_image_or_video = !empty($file['isimage']) || (isset($file['filetype']) && $file['filetype'] == 'video');
			
			if($is_image_or_video && !$file_url_in_message) {
				// 图片/视频在编辑器中已被删除，跳过创建附件记录，直接清理临时文件
				if(is_file($file['path'])) @unlink($file['path']);
				if(!empty($file['thumb_url'])) {
					$thumb_relative = str_replace($conf['upload_url'].'tmp/', '', $file['thumb_url']);
					$thumb_src_path = $conf['upload_path'].'tmp/'.$thumb_relative;
					if(is_file($thumb_src_path)) @unlink($thumb_src_path);
				}
				continue;
			}
			
			// 将文件移动到 upload/attach 目录
			$filename = file_name($file['url']);
			
			$path = $attach_path;
			$url = $attach_url;
			
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

	// ===== 方案2：兜底扫描 message 中的 /upload/tmp/ 路径 =====
	// 当 session 丢失或 str_replace 未匹配时，直接扫描 message 中的临时路径
	// 匹配 src="/upload/tmp/xxx.ext" 或 src="/upload/tmp/xxx.ext" 等格式
	$tmp_url_prefix = $conf['upload_url'].'tmp/';
	$tmp_path_prefix = $conf['upload_path'].'tmp/';
	$need_scan = (empty($tmp_files) || strpos($post['message'], $tmp_url_prefix) !== false || strpos($post['message_fmt'], $tmp_url_prefix) !== false);
	if($need_scan) {
		// 扫描 message 和 message_fmt 中所有 /upload/tmp/xxx.ext 的文件名
		$pattern = '#'.preg_quote($tmp_url_prefix, '#').'([0-9a-f]+\.\w+)#';
		$found_files = array();
		if(preg_match_all($pattern, $post['message'], $m1)) {
			$found_files = array_merge($found_files, $m1[1]);
		}
		if(preg_match_all($pattern, $post['message_fmt'], $m2)) {
			$found_files = array_merge($found_files, $m2[1]);
		}
		$found_files = array_unique($found_files);

		// 批量查询已存在的附件记录，消除 N+1 查询
		$filenames_to_check = array();
		foreach($found_files as $filename) {
			$filenames_to_check[] = "$day/$filename";
		}
		$existing_attaches = empty($filenames_to_check) ? array() : db_find('attach', array('pid'=>$pid, 'filename'=>$filenames_to_check), array(), 1, count($filenames_to_check), 'filename');
		$existing_filenames = array();
		if($existing_attaches) {
			foreach($existing_attaches as $ea) {
				$existing_filenames[$ea['filename']] = 1;
			}
		}

		foreach($found_files as $filename) {
			$tmp_file_path = $tmp_path_prefix.$filename;
			$tmp_file_url = $tmp_url_prefix.$filename;
			$dest_file_path = $attach_path.'/'.$filename;
			$dest_file_url = $attach_url.'/'.$filename;

			// 检查临时文件是否存在（可能已被方案1移走）
			if(!is_file($tmp_file_path)) continue;

			// 移动文件到 attach 目录
			$r = xn_copy($tmp_file_path, $dest_file_path);
			!$r AND xn_log("xn_copy($tmp_file_path, $dest_file_path) failed (scan fallback), pid:$pid, tid:$tid", 'php_error');
			if(is_file($dest_file_path) && filesize($dest_file_path) == filesize($tmp_file_path)) {
				@unlink($tmp_file_path);
			}

			// 替换 message 中的 URL
			$post['message'] = str_replace($tmp_file_url, $dest_file_url, $post['message']);
			$post['message_fmt'] = str_replace($tmp_file_url, $dest_file_url, $post['message_fmt']);

			// 检查是否已在方案1中创建了附件记录，避免重复（使用批量查询结果）
			if(!isset($existing_filenames["$day/$filename"])) {
				// 获取文件信息
				$filesize = filesize($dest_file_path);
				$filetype = attach_type($filename, include APP_PATH.'conf/attach.conf.php');
				$isimage = ($filetype == 'image') ? 1 : 0;
				$width = 0;
				$height = 0;
				if($isimage) {
					$imginfo = @getimagesize($dest_file_path);
					if($imginfo) {
						$width = $imginfo[0];
						$height = $imginfo[1];
					}
				}
				if($filetype == 'video') {
					if(class_exists('AttachmentService')) {
						$video_info = AttachmentService::getVideoInfo($dest_file_path);
						if($video_info) {
							$width = $video_info['width'];
							$height = $video_info['height'];
						}
					}
				}
				
				$arr = array(
					'tid'=>$tid,
					'pid'=>$pid,
					'uid'=>$uid,
					'filesize'=>$filesize,
					'width'=>$width,
					'height'=>$height,
					'filename'=>"$day/$filename",
					'orgfilename'=>$filename,
					'filetype'=>$filetype,
					'create_date'=>$time,
					'comment'=>'',
					'downloads'=>0,
					'isimage'=>$isimage
				);
				attach_create($arr);
			}
		}
	}

	// 清空 session
	$_SESSION['tmp_files'] = array();
	
	// 更新帖子内容（URL 替换后必须保存）
	post__update($pid, array('message'=>$post['message'], 'message_fmt'=>$post['message_fmt']));
	
	// 清理孤儿附件：删除不在 message 中的图片/视频附件
	// 仅处理图片和视频（它们插入在编辑器中），附件（非图片/视频）不插入编辑器故不清理
	list($attachlist, $imagelist, $filelist) = attach_find_by_pid($pid);

	// 收集需要删除的孤儿附件，避免循环内逐个 attach_delete 造成的 N+1 查询
	$orphan_aids = array();
	$orphan_attaches = array();
	foreach($attachlist as $attach) {
		// 只清理图片和视频类型
		$is_image_or_video = !empty($attach['isimage']) || (isset($attach['filetype']) && $attach['filetype'] == 'video');
		if(!$is_image_or_video) continue;

		$url = $conf['upload_url'].'attach/'.$attach['filename'];
		// 检查 URL 是否在 message 中，不在则说明用户已从编辑器删除
		if(strpos($post['message_fmt'], $url) === FALSE && strpos($post['message'], $url) === FALSE) {
			$orphan_aids[] = $attach['aid'];
			$orphan_attaches[] = $attach;
		}
	}

	// 批量删除孤儿附件：物理文件 + 缩略图 + 数据库记录
	if(!empty($orphan_aids)) {
		// 删除物理文件和缩略图（保留 attach_delete 的 unlink 逻辑）
		foreach($orphan_attaches as $attach) {
			$path = $conf['upload_path'].'attach/'.$attach['filename'];
			file_exists($path) AND unlink($path);
			$thumb_path = attach_thumb_path($attach['filename']);
			if($thumb_path) {
				$full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
				file_exists($full_thumb_path) AND unlink($full_thumb_path);
			}
		}
		// 批量删除数据库记录，消除 N+1 查询
		db_delete('attach', array('aid'=>$orphan_aids));

		// 从 attachlist 中过滤掉已删除的，避免第二次 attach_find_by_pid 查询
		$deleted_aid_map = array_flip($orphan_aids);
		foreach($attachlist as $k => $attach) {
			if(isset($deleted_aid_map[$attach['aid']])) {
				unset($attachlist[$k]);
			}
		}
		$attachlist = array_values($attachlist);
	}

	// 更新 images videos files（从过滤后的 attachlist 统计，无需重复查询）
	$images = 0;
	$videos = 0;
	$files = 0;
	foreach($attachlist as $attach) {
		if(!empty($attach['isimage'])) {
			$images++;
		} elseif(isset($attach['filetype']) && $attach['filetype'] == 'video') {
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

// 构建附件筛选 WHERE 条件（供 attach_admin_count 和 attach_admin_find 复用）
// 注意：孤儿筛选需要 LEFT JOIN，通过 $joins 参数返回 JOIN SQL
function attach_admin_build_where($filter = array(), &$joins = '') {
    $where = '';

    // 类型筛选：根据 filetype 或后缀归类
    if(!empty($filter['type_category'])) {
        $types = include APP_PATH.'conf/attach.conf.php';
        $category = $filter['type_category'];
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
            if(!empty($exts)) {
                $like_parts = array();
                foreach($exts as $ext) {
                    $like_parts[] = "a.orgfilename LIKE '%." . addslashes($ext) . "'";
                }
                $where .= ($where ? ' AND ' : '') . '(' . implode(' OR ', $like_parts) . ')';
            } elseif($category === 'other') {
                $known_exts = array();
                foreach($types as $k => $v) {
                    if($k !== 'all' && $k !== 'other' && !empty($v)) {
                        $known_exts = array_merge($known_exts, $v);
                    }
                }
                if(!empty($known_exts)) {
                    $like_parts = array();
                    foreach($known_exts as $ext) {
                        $like_parts[] = "a.orgfilename NOT LIKE '%." . addslashes($ext) . "'";
                    }
                    $where .= ($where ? ' AND ' : '') . implode(' AND ', $like_parts);
                }
            }
        }
    }

    // 孤儿状态筛选
    // 孤儿定义：tid=0 AND pid=0，或 tid>0 但帖子已删除，或 pid>0 但回复已删除
    // 正常定义：tid>0 且帖子存在（pid=0 时），或 pid>0 且回复存在
    if(isset($filter['orphan']) && $filter['orphan'] !== '') {
        if($filter['orphan'] == 1) {
            // 仅孤儿：使用 LEFT JOIN 检测关联帖子/回复不存在
            $joins .= " LEFT JOIN {$GLOBALS['db']->tablepre}thread t ON a.tid > 0 AND a.tid = t.tid";
            $joins .= " LEFT JOIN {$GLOBALS['db']->tablepre}post p ON a.pid > 0 AND a.pid = p.pid";
            $where .= ($where ? ' AND ' : '') . "(t.tid IS NULL AND p.pid IS NULL)";
        } elseif($filter['orphan'] == 0) {
            // 仅正常：tid>0 且帖子存在，或 pid>0 且回复存在
            $joins .= " LEFT JOIN {$GLOBALS['db']->tablepre}thread t ON a.tid > 0 AND a.tid = t.tid";
            $joins .= " LEFT JOIN {$GLOBALS['db']->tablepre}post p ON a.pid > 0 AND a.pid = p.pid";
            $where .= ($where ? ' AND ' : '') . "(t.tid IS NOT NULL OR p.pid IS NOT NULL)";
        }
    }

    // 关键词搜索
    if(!empty($filter['keyword'])) {
        $kw = addslashes($filter['keyword']);
        $where .= ($where ? ' AND ' : '') . "a.orgfilename LIKE '%{$kw}%'";
    }

    return $where;
}

// 带筛选条件的附件总数查询（修复全表 count bug）
function attach_admin_count($filter = array()) {
    global $db;
    // hook model_attach_admin_count_start.php
    $joins = '';
    $where = attach_admin_build_where($filter, $joins);
    $where_sql = $where ? " WHERE $where" : '';
    $sql = "SELECT COUNT(*) AS num FROM {$db->tablepre}attach a{$joins}{$where_sql}";
    $arr = db_sql_find_one($sql);
    // hook model_attach_admin_count_end.php
    return !empty($arr) ? intval($arr['num']) : 0;
}

// 带筛选/排序/分页的附件列表查询
function attach_admin_find($filter = array(), $orderby = array('aid'=>-1), $page = 1, $pagesize = 20) {
    global $db;
    // hook model_attach_admin_find_start.php

    // 排序字段白名单验证，防止 SQL 注入
    $allow_orders = array('aid', 'filesize', 'create_date');
    if(!is_array($orderby) || empty($orderby) || !in_array(key($orderby), $allow_orders)) {
        $orderby = array('aid'=>-1);
    }
    $order_key = key($orderby);
    $order_dir = current($orderby) > 0 ? 'ASC' : 'DESC';

    // 构建筛选 WHERE 条件（复用 attach_admin_build_where）
    $joins = '';
    $where = attach_admin_build_where($filter, $joins);
    $where_sql = $where ? " WHERE $where" : '';

    $page = max(1, intval($page));
    $pagesize = max(1, intval($pagesize));
    $offset = ($page - 1) * $pagesize;

    // 使用原生 SQL 查询，支持复杂的 LIKE/OR/JOIN 条件
    $sql = "SELECT a.* FROM {$db->tablepre}attach a{$joins}{$where_sql} ORDER BY a.`{$order_key}` {$order_dir} LIMIT {$offset},{$pagesize}";
    $attachlist = db_sql_find($sql);

    if($attachlist) {
        // 批量收集需要检查的 pid 和 tid，消除 N+1 查询
        $pids_to_check = array();
        $tids_to_check = array();
        foreach($attachlist as $attach) {
            if($attach['pid'] > 0) {
                $pids_to_check[] = $attach['pid'];
            }
            if($attach['tid'] > 0) {
                $tids_to_check[] = $attach['tid'];
            }
        }

        // 批量查询 post 和 thread
        $existing_posts = empty($pids_to_check) ? array() : db_find('post', array('pid'=>$pids_to_check), array(), 1, count($pids_to_check), 'pid');
        $existing_threads = empty($tids_to_check) ? array() : db_find('thread', array('tid'=>$tids_to_check), array(), 1, count($tids_to_check), 'tid');

        foreach ($attachlist as &$attach) {
            attach_format($attach);
            // 批量检测孤儿状态：tid=0 AND pid=0，或 tid>0 但帖子已删除，或 pid>0 但回复已删除
            $is_orphan = FALSE;
            if($attach['tid'] == 0 && $attach['pid'] == 0) {
                $is_orphan = TRUE;
            } else {
                // 检查 tid 对应的 thread 是否存在
                if($attach['tid'] > 0 && !isset($existing_threads[$attach['tid']])) {
                    $is_orphan = TRUE;
                }
                // 检查 pid 对应的 post 是否存在
                if($attach['pid'] > 0 && !isset($existing_posts[$attach['pid']])) {
                    $is_orphan = TRUE;
                }
            }
            $attach['is_orphan'] = $is_orphan;
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
    $size_row = db_sql_find_one("SELECT SUM(filesize) AS total_size FROM `{$tablepre}attach`");
    $total_size = !empty($size_row['total_size']) ? $size_row['total_size'] : 0;

    // 孤儿数量统计（与 attach_admin_build_where 的孤儿定义保持一致）
    // 孤儿定义：tid=0 AND pid=0，或 tid>0 但帖子已删除，或 pid>0 但回复已删除
    // 使用单条 SQL + LEFT JOIN 避免重复计数（同一个附件 tid>0 且 pid>0 但两者都删除时只算一次）
    $orphan_count = 0;
    $sql_orphan = "SELECT COUNT(*) AS cnt FROM `{$tablepre}attach` a
        LEFT JOIN `{$tablepre}thread` t ON a.tid > 0 AND a.tid = t.tid
        LEFT JOIN `{$tablepre}post` p ON a.pid > 0 AND a.pid = p.pid
        WHERE (a.tid = 0 AND a.pid = 0) OR (a.tid > 0 AND t.tid IS NULL) OR (a.pid > 0 AND p.pid IS NULL)";
    $orphan_row = db_sql_find_one($sql_orphan);
    if(!empty($orphan_row['cnt'])) $orphan_count = intval($orphan_row['cnt']);

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
function attach_admin_orphan_ids($page = 1, $pagesize = 1000) {
    global $db;
    $tablepre = $db->tablepre;

    // 参数安全处理
    $page = max(1, intval($page));
    $pagesize = max(1, intval($pagesize));
    $offset = ($page - 1) * $pagesize;

    // tid=0 AND pid=0
    $orphans1 = db_find('attach', array('tid'=>0, 'pid'=>0), array(), $page, $pagesize);
    $ids = array();
    if($orphans1) {
        foreach($orphans1 as $a) $ids[$a['aid']] = $a['aid'];
    }

    // tid>0 但帖子已删除（不论 pid 是否为 0）
    $sql1 = "SELECT a.aid FROM `{$tablepre}attach` a LEFT JOIN `{$tablepre}thread` t ON a.tid = t.tid WHERE a.tid > 0 AND t.tid IS NULL LIMIT $offset, $pagesize";
    $rows1 = db_sql_find($sql1);
    if($rows1) {
        foreach($rows1 as $r) $ids[$r['aid']] = $r['aid'];
    }

    // pid>0 但回复已删除
    $sql2 = "SELECT a.aid FROM `{$tablepre}attach` a LEFT JOIN `{$tablepre}post` p ON a.pid = p.pid WHERE a.pid > 0 AND p.pid IS NULL LIMIT $offset, $pagesize";
    $rows2 = db_sql_find($sql2);
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

    // 批量读取附件信息，消除 N+1 查询
    $attachlist = db_find('attach', array('aid'=>$ids), array(), 1, count($ids), 'aid');
    if(empty($attachlist)) return 0;

    $deleted = 0;
    $deleted_filenames = array();
    foreach($attachlist as $attach) {
        // 删除物理文件和缩略图
        $path = $conf['upload_path'].'attach/'.$attach['filename'];
        file_exists($path) AND unlink($path);
        $thumb_path = attach_thumb_path($attach['filename']);
        if($thumb_path) {
            $full_thumb_path = $conf['upload_path'].'attach/'.$thumb_path;
            file_exists($full_thumb_path) AND unlink($full_thumb_path);
        }
        $deleted_filenames[] = $attach['orgfilename'] . '(aid:' . $attach['aid'] . ')';
        $deleted++;
    }

    // 批量删除数据库记录
    db_delete('attach', array('aid'=>$ids));

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