<?php

!defined('DEBUG') AND exit('Access Denied.');

// 加载附件上传服务
if(!class_exists('AttachmentService')) {
    include APP_PATH.'service/AttachmentService.php';
}

$action = param(1);

// hook attach_start.php

if(empty($action) || $action == 'create') {

    CsrfService::check();

    $user = user_read($uid);
    user_login_check();

    // hook attach_create_start.php

    !PermissionService::check('allowattach') AND message(-1, '您无权上传');

    $filetypes = include APP_PATH.'conf/attach.conf.php';

    // 真实 MIME 类型白名单，与 conf/attach.conf.php 扩展名白名单对应
    // 用于 finfo_file 校验，防止伪造扩展名上传恶意文件（如 .php 伪装成 .jpg）
    $allowed_mimes = array(
        'image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-ms-bmp',
        'video/mp4', 'video/webm', 'video/ogg', 'video/x-msvideo', 'video/avi', 'video/x-ms-wmv', 'video/x-ms-asf',
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/x-ms-wma', 'audio/ogg',
        'application/pdf', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip', 'application/x-zip-compressed', 'application/gzip', 'application/x-gzip',
        'application/x-tar', 'application/x-rar', 'application/x-rar-compressed', 'application/x-7z-compressed',
        'application/x-bzip', 'application/x-bzip2',
        'text/plain', 'text/x-c', 'text/x-c++src',
        'application/vnd.rn-realmedia', 'application/vnd.rn-realmedia-vbr',
        'application/x-font-ttf', 'font/ttf',
        'application/x-bittorrent',
        'application/vnd.ms-htmlhelp',
    );

    // 判断是否为 FormData 文件上传
    $is_formdata = !empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;

    if($is_formdata) {
        // FormData 文件上传模式
        $file = $_FILES['file'];
        $name = $file['name'];
        $size = $file['size'];
        $tmp_name = $file['tmp_name'];
        $ext = file_ext($name, 7);
        $filetype = attach_type($name, $filetypes);

        // 文件类型校验
        if(!in_array($ext, $filetypes['all'])) {
            $ext = '_'.$ext;
            message(-1, lang('filetype_not_allowed'));
        }

        // 真实 MIME 校验，防止伪造扩展名上传恶意文件
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = $finfo ? @finfo_file($finfo, $tmp_name) : false;
        if($finfo) finfo_close($finfo);
        // finfo_file 可能返回 false（如 magic 数据库缺失），此时跳过 MIME 校验，仅依赖扩展名校验
        if($real_mime !== false && !in_array($real_mime, $allowed_mimes)) {
            message(-1, lang('file_mime_not_allowed'));
        }

        // 文件大小校验
        $max_size = AttachmentService::getMaxSize($filetype);
        if($size > $max_size) {
            $max_fmt = AttachmentService::formatSize($max_size);
            message(-1, lang('filesize_too_large', array('maxsize'=>$max_fmt, 'size'=>$size)));
        }

        // 生成临时文件名
        $tmpname = bin2hex(random_bytes(16)).'.'.$ext;
        $tmpfile = $conf['upload_path'].'tmp/'.$tmpname;
        $tmpurl = $conf['upload_url'].'tmp/'.$tmpname;

        // 确保上传临时目录存在
        $tmpdir = $conf['upload_path'].'tmp/';
        if(!is_dir($tmpdir)) {
            @mkdir($tmpdir, 0755, TRUE);
        }

        // hook attach_create_save_before.php

        // 移动上传文件到临时目录
        if(!move_uploaded_file($tmp_name, $tmpfile)) {
            message(-1, lang('write_to_file_failed'));
        }

        $width = 0;
        $height = 0;
        $isimage = 0;
        $thumb_url = '';

        // 图片处理：获取尺寸、生成缩略图
        if($filetype == 'image') {
            $isimage = 1;
            $imginfo = getimagesize($tmpfile);
            if($imginfo) {
                $width = $imginfo[0];
                $height = $imginfo[1];
            }
            // 生成缩略图
            $thumb_result = AttachmentService::generateThumbnail($tmpfile, $filetypes);
            if($thumb_result) {
                $thumb_url = $conf['upload_url'].'tmp/'.$thumb_result;
            }
        }

        // 视频处理：获取宽高和时长
        if($filetype == 'video') {
            $video_info = AttachmentService::getVideoInfo($tmpfile);
            if($video_info) {
                $width = $video_info['width'];
                $height = $video_info['height'];
            }
        }

        // 保存到 session
        sess_restart();

        empty($_SESSION['tmp_files']) AND $_SESSION['tmp_files'] = array();
        $n = count($_SESSION['tmp_files']);
        $filesize = filesize($tmpfile);
        $attach = array(
            'url'        => $tmpurl,
            'thumb_url'  => $thumb_url,
            'path'       => $tmpfile,
            'orgfilename' => $name,
            'filetype'   => $filetype,
            'filesize'   => $filesize,
            'width'      => $width,
            'height'     => $height,
            'isimage'    => $isimage,
            'downloads'  => 0,
            'aid'        => '_'.$n
        );
        $_SESSION['tmp_files'][$n] = $attach;

        unset($attach['path']);

        // 立即保存 session，确保 tmp_files 写入数据库
        // 避免后续请求（发帖/回帖）读不到 tmp_files 导致路径替换失败
        sess_save();

        // hook attach_create_end.php

        message(0, $attach);

    } else {
        // 兼容旧的 base64 上传模式
        $width = param('width', 0);
        $height = param('height', 0);
        $is_image = param('is_image', 0);
        $name = param('name');
        $data = param_base64('data');

        empty($data) AND message(-1, lang('data_is_empty'));
        $size = strlen($data);

        // 文件大小校验
        $ext = file_ext($name, 7);
        $filetype = attach_type($name, $filetypes);
        $max_size = AttachmentService::getMaxSize($filetype);
        if($size > $max_size) {
            $max_fmt = AttachmentService::formatSize($max_size);
            message(-1, lang('filesize_too_large', array('maxsize'=>$max_fmt, 'size'=>$size)));
        }

        // 文件类型校验
        if(!in_array($ext, $filetypes['all'])) {
            $ext = '_'.$ext;
        }

        $tmpname = bin2hex(random_bytes(16)).'.'.$ext;
        $tmpfile = $conf['upload_path'].'tmp/'.$tmpname;
        $tmpurl = $conf['upload_url'].'tmp/'.$tmpname;

        // 确保上传临时目录存在
        $tmpdir = $conf['upload_path'].'tmp/';
        if(!is_dir($tmpdir)) {
            @mkdir($tmpdir, 0755, TRUE);
        }

        // hook attach_create_save_before.php

        file_put_contents($tmpfile, $data) OR message(-1, lang('write_to_file_failed'));

        // 真实 MIME 校验，防止伪造扩展名上传恶意文件
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = $finfo ? @finfo_file($finfo, $tmpfile) : false;
        if($finfo) finfo_close($finfo);
        if($real_mime !== false && !in_array($real_mime, $allowed_mimes)) {
            is_file($tmpfile) AND unlink($tmpfile);
            message(-1, lang('file_mime_not_allowed'));
        }

        $thumb_url = '';

        // 图片处理：获取尺寸、生成缩略图
        if($filetype == 'image') {
            $is_image = 1;
            $imginfo = getimagesize($tmpfile);
            if($imginfo) {
                $width = $imginfo[0];
                $height = $imginfo[1];
            }
            $thumb_result = AttachmentService::generateThumbnail($tmpfile, $filetypes);
            if($thumb_result) {
                $thumb_url = $conf['upload_url'].'tmp/'.$thumb_result;
            }
        }

        // 视频处理
        if($filetype == 'video') {
            $video_info = AttachmentService::getVideoInfo($tmpfile);
            if($video_info) {
                $width = $video_info['width'];
                $height = $video_info['height'];
            }
        }

        sess_restart();

        empty($_SESSION['tmp_files']) AND $_SESSION['tmp_files'] = array();
        $n = count($_SESSION['tmp_files']);
        $filesize = filesize($tmpfile);
        $attach = array(
            'url'        => $tmpurl,
            'thumb_url'  => $thumb_url,
            'path'       => $tmpfile,
            'orgfilename' => $name,
            'filetype'   => $filetype,
            'filesize'   => $filesize,
            'width'      => $width,
            'height'     => $height,
            'isimage'    => $is_image,
            'downloads'  => 0,
            'aid'        => '_'.$n
        );
        $_SESSION['tmp_files'][$n] = $attach;

        unset($attach['path']);

        // 立即保存 session，确保 tmp_files 写入数据库
        sess_save();

        // hook attach_create_end.php

        message(0, $attach);
    }

} elseif($action == 'delete') {

    CsrfService::check();

    $user = user_read($uid);
    user_login_check();

    $aid = param(2);

    // hook attach_delete_start.php

    // 临时的文件 id / temp attach id : _0 _1 _2 _3 ...
    if(substr($aid, 0, 1) == '_') {
        $key = intval(substr($aid, 1));
        $tmp_files = _SESSION('tmp_files');
        !isset($tmp_files[$key]) AND message(-1, lang('item_not_exists', array('item'=>$key)));
        $attach = $tmp_files[$key];
        !is_file($attach['path']) AND message(-1, lang('file_not_exists'));
        unlink($attach['path']);

        // 删除缩略图
        if(!empty($attach['thumb_url'])) {
            $thumb_relative = str_replace($conf['upload_url'].'tmp/', '', $attach['thumb_url']);
            $thumb_path = $conf['upload_path'].'tmp/'.$thumb_relative;
            is_file($thumb_path) AND unlink($thumb_path);
        }

        unset($_SESSION['tmp_files'][$key]);
    } else {
        $aid = intval($aid);
        $attach = attach_read($aid);
        empty($attach) AND message(-1, lang('attach_not_exists'));

        $thread = thread_read($attach['tid']);
        empty($thread) AND message(-1, lang('thread_not_exists'));
        $fid = $thread['fid'];

        $allowdelete = forum_access_mod($fid, $gid, 'allowdelete');
        $attach['uid'] != $uid AND !$allowdelete AND message(0, lang('insufficient_privilege'));

        $r = attach_delete($aid);
        $r ===  FALSE AND message(-1, lang('delete_failed'));
    }

    // hook attach_delete_end.php

    if(is_htmx_request()) {
        htmx_trigger('attachDeleted', array('aid' => $aid));
    }
    message(0, lang('delete_successfully'));

} elseif($action == 'read') {

    // hook attach_read_start.php

    // 图片签名URL访问：/attach-read-{aid}-{token}
    $aid = param(2, 0);
    $token = param(3, '');

    $attach = attach_read($aid);
    empty($attach) AND http_status(404) AND exit('Attach not found');

    // 签名校验
    $sign_key = array_value($conf, 'attach_sign_key', '');
    $expected_token = md5($aid . $attach['filename'] . $sign_key);
    if($token !== $expected_token) {
        http_status(403);
        exit('Invalid token');
    }

    // 防盗链检查
    if(!empty($conf['attach_referer_check'])) {
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        if(!empty($referer)) {
            $host = parse_url($referer, PHP_URL_HOST);
            $site_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
            if($host !== $site_host) {
                http_status(403);
                exit('Hotlinking denied');
            }
        }
    }

    // 读取物理文件
    $filepath = $conf['upload_path'].'attach/'.$attach['filename'];
    if(!is_file($filepath)) {
        http_status(404);
        exit('File not found');
    }

    // 设置 MIME 类型
    $ext = strtolower(pathinfo($attach['filename'], PATHINFO_EXTENSION));
    $mime_types = array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg',
    );
    $content_type = isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';

    // 缓存头
    $timefmt = date('D, d M Y H:i:s', $attach['create_date']).' GMT';
    header('Date: '.gmdate('D, d M Y H:i:s').' GMT');
    header('Last-Modified: '.$timefmt);
    header('Cache-Control: public, max-age=86400');
    header('Content-Type: '.$content_type);
    header('Content-Length: '.filesize($filepath));

    // hook attach_read_output_before.php

    // 支持 Nginx X-Accel-Redirect
    if(!empty($conf['attach_x_accel_redirect'])) {
        header('X-Accel-Redirect: '.$conf['upload_url'].'attach/'.$attach['filename']);
    } else {
        readfile($filepath);
    }
    exit;

} elseif($action == 'download') {

    // hook attach_download_start.php

    // 判断权限
    $aid = param(2, 0);
    $attach = attach_read($aid);
    empty($attach) AND message(-1, lang('attach_not_exists'));
    $tid = $attach['tid'];
    $thread = thread_read($tid);
    $fid = $thread['fid'];
    $allowdown = forum_access_user($fid, $gid, 'allowdown');
    empty($allowdown) AND message(-1, lang('insufficient_privilege_to_download'));

    $attachpath = $conf['upload_path'].'attach/'.$attach['filename'];
    $attachurl = $conf['upload_url'].'attach/'.$attach['filename'];
    !is_file($attachpath)AND message(-1, lang('attach_not_exists'));

    $type = 'php';

    // hook attach_output_before.php

    // php 输出
    if($type == 'php') {

        attach_update($aid, array('downloads+'=>1));

        // 下载日志记录
        $log_dir = $conf['tmp_path'];
        $log_file = $log_dir.'attach_download.log';
        $log_uid = isset($uid) ? $uid : 0;
        $log_ip = isset($longip) ? $longip : ip2long($_SERVER['REMOTE_ADDR']);
        $log_line = date('Y-m-d H:i:s')."|aid={$aid}|uid={$log_uid}|ip={$log_ip}\n";
        @file_put_contents($log_file, $log_line, FILE_APPEND);

        $filesize = $attach['filesize'];
        if(stripos($_SERVER["HTTP_USER_AGENT"], 'MSIE') !== FALSE || stripos($_SERVER["HTTP_USER_AGENT"], 'Edge') !== FALSE || stripos($_SERVER["HTTP_USER_AGENT"], 'Trident') !== FALSE) {
            $attach['orgfilename'] = urlencode($attach['orgfilename']);
            $attach['orgfilename'] = str_replace("+", "%20", $attach['orgfilename']);
        }
        $timefmt = date('D, d M Y H:i:s', $time).' GMT';
        header('Date: '.$timefmt);
        header('Last-Modified: '.$timefmt);
        header('Expires: '.$timefmt);
        header('Cache-control: max-age=86400');
        header('Content-Transfer-Encoding: binary');
        header("Pragma: public");
        header('Content-Disposition: attachment; filename="'.$attach['orgfilename'].'"');
        header('Content-Type: application/octet-stream');

        // hook attach_download_readfile_before.php

        readfile($attachpath);
        exit;
    } else {

        // hook attach_download_location_before.php

        // 附件 URL 可能指向 CDN 外链，显式放行
        http_location($attachurl, TRUE);
    }

} elseif($action == 'fetch') {

    // 方案A：签名 token + 浏览器原生下载，依靠 token+时效签名防盗链
    // URL 格式：attach-fetch-{aid}-{token}-{expires}
    // token 由服务端在 post_file_list_html 渲染时生成
    // 不再强制 X-Requested-With 头，以便浏览器原生 <a download> 触发并显示进度条
    $aid = param(2, 0);
    $token = param(3, '');
    $expires = param(4, 0);

    $attach = attach_read($aid);
    empty($attach) AND message(-1, lang('attach_not_exists'));

    // 签名校验
    $sign_key = array_value($conf, 'attach_sign_key', '');
    $expected_token = md5($aid . $expires . $sign_key);
    if($token !== $expected_token) {
        http_status(403);
        exit('Invalid token');
    }

    // 时效校验（默认 1 小时 = 3600 秒）
    if($expires < $time) {
        http_status(403);
        exit('Token expired');
    }

    // 权限校验
    $tid = $attach['tid'];
    $thread = thread_read($tid);
    $fid = $thread['fid'];
    $allowdown = forum_access_user($fid, $gid, 'allowdown');
    empty($allowdown) AND message(-1, lang('insufficient_privilege_to_download'));

    $attachpath = $conf['upload_path'].'attach/'.$attach['filename'];
    !is_file($attachpath) AND message(-1, lang('attach_not_exists'));

    // 记录下载
    attach_update($aid, array('downloads+'=>1));

    // 输出文件流
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.$attach['orgfilename'].'"');
    header('Content-Length: '.filesize($attachpath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($attachpath);
    exit;
}

// hook attach_end.php

?>
