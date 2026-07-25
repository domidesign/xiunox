<?php

// ponytail: 加载 $grouplist，PermissionService::check 对非管理员会回退查 $grouplist[$_gid]['allowattach']
// 未加载时非管理员上传附件必然 403（与 post.php/thread.php/mod.php 一致）
if (!function_exists('group_list_cache')) {
    include_once APP_PATH . 'model/group.func.php';
}
if (empty($GLOBALS['grouplist'])) {
    $GLOBALS['grouplist'] = group_list_cache();
}

$id = intval($segments[1] ?? 0);

switch ($method) {
    case 'GET':
        if ($id <= 0) {
            ApiResponse::validationError('Attachment ID is required');
        }
        $attach = $attachmentService->getAttachmentById($id);
        if (!$attach) {
            ApiResponse::notFound('Attachment not found');
        }
        unset($attach['driver']);
        ApiResponse::success($attach);
        break;

    case 'POST':
        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
        if (!$authUser) {
            ApiResponse::unauthorized();
        }
        // ponytail: 同步全局 $uid/$gid/$user，bootstrap 早期认证可能因 try-catch 吞异常失败，
        // 导致全局变量为 0；此处已通过 validateAccessToken 确认身份，必须回填全局变量
        // 供 PermissionService::check 等 core 函数使用
        $GLOBALS['uid'] = intval($authUser['uid']);
        $GLOBALS['gid'] = intval($authUser['gid']);
        $GLOBALS['user'] = $authUser;
        global $uid, $gid, $user;

        // 检查上传附件权限（显式传 uid，不依赖全局变量）
        include_once APP_PATH . 'lib/PermissionService.php';
        if (!PermissionService::check('allowattach', intval($authUser['uid']))) {
            ApiResponse::forbidden('您无权上传附件');
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            ApiResponse::validationError('File is required (multipart/form-data, field name: file)');
        }

        global $conf;
        $file = $_FILES['file'];
        $name = $file['name'];
        $size = $file['size'];
        $tmp_name = $file['tmp_name'];
        $ext = file_ext($name, 7);
        $filetypes = include APP_PATH.'conf/attach.conf.php';
        $filetype = attach_type($name, $filetypes);

        // 文件类型校验
        if(!in_array($ext, $filetypes['all'])) {
            ApiResponse::validationError('不允许的文件类型: '.$ext);
        }

        // 真实 MIME 校验，防止伪造扩展名上传恶意文件
        // 白名单与 conf/attach.conf.php 扩展名白名单对应
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
        // 真实 MIME 校验，行为受 security_upload_strict_mime 控制
        if(!AttachmentService::verifyUploadMime($tmp_name, $allowed_mimes, $filetype == 'image')) {
            ApiResponse::validationError('文件类型不允许');
        }

        // 文件大小校验
        $max_size = AttachmentService::getMaxSize($filetype);
        if($size > $max_size) {
            $max_fmt = AttachmentService::formatSize($max_size);
            ApiResponse::validationError("文件大小不能超过 {$max_fmt}");
        }

        // 生成临时文件名（密码学安全随机数）
        $uid = intval($authUser['uid']);
        $tmpname = $uid.'_'.bin2hex(random_bytes(16)).'.'.$ext;
        $tmpfile = $conf['upload_path'].'tmp/'.$tmpname;
        $tmpurl = $conf['upload_url'].'tmp/'.$tmpname;

        // ponytail: 确保上传临时目录存在（与 route/attach.php 一致）
        $tmpdir = $conf['upload_path'].'tmp/';
        if(!is_dir($tmpdir)) {
            @mkdir($tmpdir, 0755, TRUE);
        }

        // 移动上传文件到临时目录
        if(!move_uploaded_file($tmp_name, $tmpfile)) {
            ApiResponse::error(500, '写入文件失败');
        }

        $width = 0;
        $height = 0;
        $isimage = 0;
        $thumb_url = '';
        $duration = 0;

        // 图片处理
        if($filetype == 'image') {
            $isimage = 1;
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
                $duration = $video_info['duration'];
            }
        }

        $filesize = filesize($tmpfile);

        // 保存元数据到 JSON 文件，供发帖时关联
        // ponytail: 不写 path 字段（物理路径），避免 .meta.json 被 web 访问时泄露服务器路径
        // api_attach_assoc_post 通过 $conf['upload_path'].'tmp/'.$key 定位文件，无需 path
        $meta = array(
            'url'         => $tmpurl,
            'thumb_url'   => $thumb_url,
            'orgfilename' => $name,
            'filetype'    => $filetype,
            'filesize'    => $filesize,
            'width'       => $width,
            'height'      => $height,
            'isimage'     => $isimage,
            'uid'         => $uid,
            'duration'    => $duration,
        );
        $meta_file = $conf['upload_path'].'tmp/'.$tmpname.'.meta.json';
        file_put_contents($meta_file, json_encode($meta, JSON_UNESCAPED_UNICODE));

        $result = array(
            'key'         => $tmpname,
            'url'         => $tmpurl,
            'thumb_url'   => $thumb_url,
            'width'       => $width,
            'height'      => $height,
            'filetype'    => $filetype,
            'orgfilename' => $name,
            'filesize'    => $filesize,
            'isimage'     => $isimage,
        );
        if($duration > 0) {
            $result['duration'] = $duration;
        }

        ApiResponse::success($result, 'Uploaded');
        break;

    case 'DELETE':
        $authUser = $apiAuth->validateAccessToken(ApiAuthService::getBearerToken());
        if (!$authUser) {
            ApiResponse::unauthorized();
        }
        if ($id <= 0) {
            ApiResponse::validationError('Attachment ID is required');
        }
        $attach = $attachmentService->getAttachmentById($id);
        if (!$attach) {
            ApiResponse::notFound('Attachment not found');
        }
        if (intval($attach['uid']) !== intval($authUser['uid']) && intval($authUser['gid']) !== 1) {
            ApiResponse::forbidden();
        }
        $attachmentService->deleteAttachment($id);
        ApiResponse::success(null, 'Deleted');
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
