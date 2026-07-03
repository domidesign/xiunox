<?php

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
        // 检查上传附件权限
        include_once APP_PATH . 'lib/PermissionService.php';
        if (!PermissionService::check('allowattach')) {
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

        // 文件大小校验
        $max_size = AttachmentService::getMaxSize($filetype);
        if($size > $max_size) {
            $max_fmt = AttachmentService::formatSize($max_size);
            ApiResponse::validationError("文件大小不能超过 {$max_fmt}");
        }

        // 生成临时文件名
        $uid = intval($authUser['uid']);
        $tmpname = $uid.'_'.xn_rand(15).'.'.$ext;
        $tmpfile = $conf['upload_path'].'tmp/'.$tmpname;
        $tmpurl = $conf['upload_url'].'tmp/'.$tmpname;

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
        $meta = array(
            'url'         => $tmpurl,
            'thumb_url'   => $thumb_url,
            'path'        => $tmpfile,
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
