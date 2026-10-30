<?php

!defined('DEBUG') AND exit('Access Denied.');

// 加载附件上传服务
if(!class_exists('AttachmentService')) {
    include APP_PATH.'service/AttachmentService.php';
}

include_once APP_PATH . 'lib/AttachmentDownloadService.php';
$action = param(1);

// hook attach_start.php

if(empty($action) || $action == 'create') {

    CsrfService::check();

    $user = user_read($uid);
    user_login_check();

    // hook attach_create_start.php

    !PermissionService::check('allowattach') AND message(-1, 'Attachment upload is disabled.');

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
        // 行为受 security_upload_strict_mime 控制：严格模式必须有 fileinfo；兼容模式 fileinfo 不可时图片降级到 getimagesize
        if(!AttachmentService::verifyUploadMime($tmp_name, $allowed_mimes, $filetype == 'image')) {
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
        $tmpfile = PrivateAttachmentStorage::publicMedia(array('filename'=>$tmpname, 'isimage'=>$filetype === 'image', 'filetype'=>$filetype)) ? $conf['upload_path'].'tmp/'.$tmpname : PrivateAttachmentStorage::privatePath($tmpname, 'tmp');
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

        // ponytail: 接收 page_token 用于多标签页/跨帖子附件隔离
        // 前端 UploadService 上传时携带，attach_assoc_post 据此只关联本次提交的附件
        $page_token = param('page_token', '');

        empty($_SESSION['tmp_files']) AND $_SESSION['tmp_files'] = array();
        // ponytail: 用 max(array_keys)+1 替代 count()，避免并发上传或中途删除某项后 key 重叠覆盖
        // 旧逻辑 count() 在 [0,1,3] 这种非连续数组上会得到 3，下次上传 key=3 覆盖已有数据
        $_keys = array_keys($_SESSION['tmp_files']);
        $n = empty($_keys) ? 0 : (intval(max($_keys)) + 1);
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
            'aid'        => '_'.$n,
            'page_token' => $page_token,
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
        $tmpfile = PrivateAttachmentStorage::publicMedia(array('filename'=>$tmpname, 'isimage'=>$filetype === 'image', 'filetype'=>$filetype)) ? $conf['upload_path'].'tmp/'.$tmpname : PrivateAttachmentStorage::privatePath($tmpname, 'tmp');
        $tmpurl = $conf['upload_url'].'tmp/'.$tmpname;

        // 确保上传临时目录存在
        $tmpdir = $conf['upload_path'].'tmp/';
        if(!is_dir($tmpdir)) {
            @mkdir($tmpdir, 0755, TRUE);
        }

        // hook attach_create_save_before.php

        file_put_contents($tmpfile, $data) OR message(-1, lang('write_to_file_failed'));

        // 真实 MIME 校验，防止伪造扩展名上传恶意文件
        // 行为受 security_upload_strict_mime 控制：严格模式必须有 fileinfo；兼容模式 fileinfo 不可时图片降级到 getimagesize
        if(!AttachmentService::verifyUploadMime($tmpfile, $allowed_mimes, $filetype == 'image')) {
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

        // ponytail: 接收 page_token 用于多标签页/跨帖子附件隔离
        $page_token = param('page_token', '');

        empty($_SESSION['tmp_files']) AND $_SESSION['tmp_files'] = array();
        // ponytail: 同上，用 max(array_keys)+1 避免并发或非连续 key 覆盖
        $_keys = array_keys($_SESSION['tmp_files']);
        $n = empty($_keys) ? 0 : (intval(max($_keys)) + 1);
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
            'aid'        => '_'.$n,
            'page_token' => $page_token,
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
        $attach['uid'] != $uid AND !$allowdelete AND message(-1, lang('insufficient_privilege'));

        $r = attach_delete($aid);
        $r ===  FALSE AND message(-1, lang('delete_failed'));
    }

    // hook attach_delete_end.php

    if(is_htmx_request()) {
        htmx_trigger('attachDeleted', array('aid' => $aid));
    }
    message(0, lang('delete_successfully'));

 } elseif (in_array($action, array('read', 'download', 'fetch'), true)) {
    $aid = intval(param(2, 0));
    $attach = $aid > 0 ? attach_read($aid) : null;
    if (!$attach) {
        http_response_code(404);
        header('Cache-Control: private, no-store');
        exit('Attachment not found');
    }
    // 安全: 授权必须先于存储驱动接管（如 S3 302 重定向），否则直链绕过业务校验；
    // serve() 内部会重复校验并追加限流，此处提前校验是为 hook 点兜底
    AttachmentAccessService::assertAllowed($attach, intval($uid), intval($gid));
    // hook storage_serve.php
    AttachmentDownloadService::serve($attach, intval($uid), intval($gid), $action === 'read');
}

// hook attach_end.php
