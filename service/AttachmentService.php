<?php

/**
 * 附件统一上传服务类
 * 支持 FormData 文件上传、图片缩略图生成、视频信息提取
 * @since 1.0.2
 */
class AttachmentService {

    private $db;

    // 文件大小限制配置（字节）
    private static $maxSizeConfig = array(
        'image'  => 10485760,   // 10MB
        'video'  => 104857600,  // 100MB
        'music'  => 20971520,   // 20MB
        'default' => 20971520,  // 20MB
    );

    // 缩略图配置
    private static $thumbConfig = array(
        'max_width'  => 200,
        'max_height' => 200,
        'quality'    => 80,
    );

    /**
     * 构造函数，DatabaseInterface 可选，不传时使用全局 $db
     * @param DatabaseInterface|null $db
     */
    public function __construct(?DatabaseInterface $db = null) {
        if($db !== null) {
            $this->db = $db;
        } else {
            global $db;
            $this->db = $db;
        }
    }

    /**
     * 静态工厂方法
     * @return AttachmentService
     */
    public static function getInstance() {
        return new self();
    }

    /**
     * 图片上传，含缩略图生成
     * @param array $file $_FILES 元素
     * @param int $uid 用户ID
     * @param array $options 选项
     * @return array 上传结果
     */
    public function uploadImage(array $file, int $uid = 0, array $options = array()) {
        global $conf;

        if(empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return array('code' => -1, 'message' => '上传文件无效');
        }

        $name = $file['name'];
        $size = $file['size'];
        $tmp_name = $file['tmp_name'];
        $ext = file_ext($name, 7);
        $filetypes = include APP_PATH.'conf/attach.conf.php';
        $filetype = attach_type($name, $filetypes);

        // 文件类型校验
        $allowed_image_types = self::getAllowedTypes('image');
        if(!in_array($ext, $allowed_image_types)) {
            return array('code' => -1, 'message' => '仅允许上传图片文件');
        }

        // 文件大小校验
        $max_size = self::getMaxSize('image');
        if($size > $max_size) {
            $max_fmt = self::formatSize($max_size);
            return array('code' => -1, 'message' => "图片大小不能超过 {$max_fmt}");
        }

        // 生成临时文件
        $uid = $uid ?: intval($GLOBALS['uid']);
        $tmpname = $uid.'_'.xn_rand(15).'.'.$ext;
        $tmpfile = $conf['upload_path'].'tmp/'.$tmpname;
        $tmpurl = $conf['upload_url'].'tmp/'.$tmpname;

        if(!move_uploaded_file($tmp_name, $tmpfile)) {
            return array('code' => -1, 'message' => '写入文件失败');
        }

        // 获取图片尺寸
        $width = 0;
        $height = 0;
        $imginfo = getimagesize($tmpfile);
        if($imginfo) {
            $width = $imginfo[0];
            $height = $imginfo[1];
        }

        // 生成缩略图
        $thumb_url = '';
        $thumb_result = self::generateThumbnail($tmpfile, $filetypes);
        if($thumb_result) {
            $thumb_url = $conf['upload_url'].'tmp/'.$thumb_result;
        }

        // 保存到 session
        $this->saveToSession($tmpurl, $tmpfile, $name, 'image', filesize($tmpfile), $width, $height, 1, $thumb_url);

        $n = count($_SESSION['tmp_files']) - 1;
        return array(
            'code' => 0,
            'message' => array(
                'url'         => $tmpurl,
                'thumb_url'   => $thumb_url,
                'width'       => $width,
                'height'      => $height,
                'filetype'    => 'image',
                'aid'         => '_'.$n,
                'orgfilename' => $name,
                'filesize'    => filesize($tmpfile),
                'isimage'     => 1,
            )
        );
    }

    /**
     * 视频上传，含视频信息提取
     * @param array $file $_FILES 元素
     * @param int $uid 用户ID
     * @param array $options 选项
     * @return array 上传结果
     */
    public function uploadVideo(array $file, int $uid = 0, array $options = array()) {
        global $conf;

        if(empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return array('code' => -1, 'message' => '上传文件无效');
        }

        $name = $file['name'];
        $size = $file['size'];
        $tmp_name = $file['tmp_name'];
        $ext = file_ext($name, 7);
        $filetypes = include APP_PATH.'conf/attach.conf.php';
        $filetype = attach_type($name, $filetypes);

        // 文件类型校验
        $allowed_video_types = self::getAllowedTypes('video');
        if(!in_array($ext, $allowed_video_types)) {
            return array('code' => -1, 'message' => '仅允许上传视频文件');
        }

        // 文件大小校验
        $max_size = self::getMaxSize('video');
        if($size > $max_size) {
            $max_fmt = self::formatSize($max_size);
            return array('code' => -1, 'message' => "视频大小不能超过 {$max_fmt}");
        }

        // 生成临时文件
        $uid = $uid ?: intval($GLOBALS['uid']);
        $tmpname = $uid.'_'.xn_rand(15).'.'.$ext;
        $tmpfile = $conf['upload_path'].'tmp/'.$tmpname;
        $tmpurl = $conf['upload_url'].'tmp/'.$tmpname;

        if(!move_uploaded_file($tmp_name, $tmpfile)) {
            return array('code' => -1, 'message' => '写入文件失败');
        }

        // 获取视频信息
        $width = 0;
        $height = 0;
        $duration = 0;
        $video_info = self::getVideoInfo($tmpfile);
        if($video_info) {
            $width = $video_info['width'];
            $height = $video_info['height'];
            $duration = $video_info['duration'];
        }

        // 保存到 session
        $this->saveToSession($tmpurl, $tmpfile, $name, 'video', filesize($tmpfile), $width, $height, 0, '', $duration);

        $n = count($_SESSION['tmp_files']) - 1;
        return array(
            'code' => 0,
            'message' => array(
                'url'         => $tmpurl,
                'thumb_url'   => '',
                'width'       => $width,
                'height'      => $height,
                'filetype'    => 'video',
                'aid'         => '_'.$n,
                'orgfilename' => $name,
                'filesize'    => filesize($tmpfile),
                'isimage'     => 0,
                'duration'    => $duration,
            )
        );
    }

    /**
     * 通用文件上传
     * @param array $file $_FILES 元素
     * @param int $uid 用户ID
     * @param array $options 选项
     * @return array 上传结果
     */
    public function uploadFile(array $file, int $uid = 0, array $options = array()) {
        global $conf;

        if(empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return array('code' => -1, 'message' => '上传文件无效');
        }

        $name = $file['name'];
        $size = $file['size'];
        $tmp_name = $file['tmp_name'];
        $ext = file_ext($name, 7);
        $filetypes = include APP_PATH.'conf/attach.conf.php';
        $filetype = attach_type($name, $filetypes);

        // 文件类型校验
        if(!in_array($ext, $filetypes['all'])) {
            return array('code' => -1, 'message' => '不允许的文件类型');
        }

        // 文件大小校验
        $max_size = self::getMaxSize($filetype);
        if($size > $max_size) {
            $max_fmt = self::formatSize($max_size);
            return array('code' => -1, 'message' => "文件大小不能超过 {$max_fmt}");
        }

        // 生成临时文件
        $uid = $uid ?: intval($GLOBALS['uid']);
        $tmpname = $uid.'_'.xn_rand(15).'.'.$ext;
        $tmpfile = $conf['upload_path'].'tmp/'.$tmpname;
        $tmpurl = $conf['upload_url'].'tmp/'.$tmpname;

        if(!move_uploaded_file($tmp_name, $tmpfile)) {
            return array('code' => -1, 'message' => '写入文件失败');
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
            $thumb_result = self::generateThumbnail($tmpfile, $filetypes);
            if($thumb_result) {
                $thumb_url = $conf['upload_url'].'tmp/'.$thumb_result;
            }
        }

        // 视频处理
        if($filetype == 'video') {
            $video_info = self::getVideoInfo($tmpfile);
            if($video_info) {
                $width = $video_info['width'];
                $height = $video_info['height'];
                $duration = $video_info['duration'];
            }
        }

        // 保存到 session
        $this->saveToSession($tmpurl, $tmpfile, $name, $filetype, filesize($tmpfile), $width, $height, $isimage, $thumb_url, $duration);

        $n = count($_SESSION['tmp_files']) - 1;
        $result = array(
            'url'         => $tmpurl,
            'thumb_url'   => $thumb_url,
            'width'       => $width,
            'height'      => $height,
            'filetype'    => $filetype,
            'aid'         => '_'.$n,
            'orgfilename' => $name,
            'filesize'    => filesize($tmpfile),
            'isimage'     => $isimage,
        );
        if($filetype == 'video') {
            $result['duration'] = $duration;
        }

        return array('code' => 0, 'message' => $result);
    }

    /**
     * 通用上传方法（兼容旧接口）
     * @param array $file $_FILES 元素
     * @param array $options 选项（driver: local/oss/cos）
     * @return array 上传结果
     */
    public function upload(array $file, array $options = array()) {
        $driver = $options['driver'] ?? 'local';

        $filetypes = include APP_PATH.'conf/attach.conf.php';
        $name = $file['name'];
        $ext = file_ext($name, 7);
        $filetype = attach_type($name, $filetypes);

        // 文件类型校验
        if(!in_array($ext, $filetypes['all'])) {
            return array('code' => -1, 'message' => '不允许的文件类型');
        }

        // 文件大小校验
        $max_size = self::getMaxSize($filetype);
        if($file['size'] > $max_size) {
            return array('code' => -1, 'message' => '文件大小超出限制');
        }

        if($driver === 'local') {
            return $this->uploadFile($file, intval($options['uid'] ?? 0), $options);
        }

        return array('code' => -1, 'message' => '不支持的上传驱动: '.$driver);
    }

    /**
     * 保存上传信息到 session
     * @param string $url 文件URL
     * @param string $path 文件物理路径
     * @param string $orgfilename 原始文件名
     * @param string $filetype 文件类型分类
     * @param int $filesize 文件大小
     * @param int $width 宽度
     * @param int $height 高度
     * @param int $isimage 是否图片
     * @param string $thumb_url 缩略图URL
     * @param int $duration 视频时长（秒）
     */
    private function saveToSession($url, $path, $orgfilename, $filetype, $filesize, $width = 0, $height = 0, $isimage = 0, $thumb_url = '', $duration = 0) {
        sess_restart();

        empty($_SESSION['tmp_files']) AND $_SESSION['tmp_files'] = array();
        $n = count($_SESSION['tmp_files']);
        $attach = array(
            'url'         => $url,
            'thumb_url'   => $thumb_url,
            'path'        => $path,
            'orgfilename' => $orgfilename,
            'filetype'    => $filetype,
            'filesize'    => $filesize,
            'width'       => $width,
            'height'      => $height,
            'isimage'     => $isimage,
            'downloads'   => 0,
            'aid'         => '_'.$n,
        );
        if($duration > 0) {
            $attach['duration'] = $duration;
        }
        $_SESSION['tmp_files'][$n] = $attach;
    }

    /**
     * 获取文件类型对应的最大上传大小
     * @param string $filetype 文件类型分类（image/video/music/other）
     * @return int 最大字节数
     */
    public static function getMaxSize($filetype = 'default') {
        global $conf;
        $key = 'upload_max_'.$filetype.'_size';
        if(!empty($conf[$key])) {
            return intval($conf[$key]);
        }
        return isset(self::$maxSizeConfig[$filetype]) ? self::$maxSizeConfig[$filetype] : self::$maxSizeConfig['default'];
    }

    /**
     * 设置文件大小限制
     * @param string $filetype 文件类型分类
     * @param int $bytes 最大字节数
     */
    public static function setMaxSize($filetype, $bytes) {
        self::$maxSizeConfig[$filetype] = intval($bytes);
    }

    /**
     * 获取允许的文件类型列表
     * @param string $category 类型分类（image/video/file）
     * @return array 允许的扩展名列表
     */
    public static function getAllowedTypes($category = 'image') {
        global $conf;
        $key = 'upload_allowed_'.$category.'_types';
        if(!empty($conf[$key])) {
            return explode(',', $conf[$key]);
        }
        $filetypes = include APP_PATH.'conf/attach.conf.php';
        $map = array(
            'image' => 'image',
            'video' => 'video',
            'file'  => 'all',
        );
        $type_key = isset($map[$category]) ? $map[$category] : 'all';
        return isset($filetypes[$type_key]) ? $filetypes[$type_key] : $filetypes['all'];
    }

    /**
     * 格式化文件大小为人类可读格式
     * @param int $bytes 字节数
     * @return string 格式化后的字符串
     */
    public static function formatSize($bytes) {
        if($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).'GB';
        } elseif($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).'MB';
        } elseif($bytes >= 1024) {
            return number_format($bytes / 1024, 2).'KB';
        }
        return $bytes.'B';
    }

    /**
     * 校验文件类型是否允许
     * @param string $filename 文件名
     * @param array|null $filetypes 文件类型配置，为空时自动加载
     * @return bool
     */
    public static function validateFileType($filename, $filetypes = null) {
        global $conf;
        if($filetypes === null) {
            $filetypes = include APP_PATH.'conf/attach.conf.php';
        }
        $ext = file_ext($filename, 7);

        // 从配置读取允许的文件类型
        $conf_image_types = isset($conf['upload_allowed_image_types']) ? explode(',', $conf['upload_allowed_image_types']) : null;
        $conf_video_types = isset($conf['upload_allowed_video_types']) ? explode(',', $conf['upload_allowed_video_types']) : null;
        $conf_file_types = isset($conf['upload_allowed_file_types']) ? explode(',', $conf['upload_allowed_file_types']) : null;

        if($conf_image_types !== null || $conf_video_types !== null || $conf_file_types !== null) {
            $allowed = array();
            if($conf_image_types !== null) $allowed = array_merge($allowed, $conf_image_types);
            if($conf_video_types !== null) $allowed = array_merge($allowed, $conf_video_types);
            if($conf_file_types !== null) $allowed = array_merge($allowed, $conf_file_types);
            return in_array($ext, $allowed);
        }

        return in_array($ext, $filetypes['all']);
    }

    /**
     * 校验文件大小是否在限制内
     * @param int $size 文件大小（字节）
     * @param string $filetype 文件类型分类
     * @return bool
     */
    public static function validateFileSize($size, $filetype = 'default') {
        $max_size = self::getMaxSize($filetype);
        return $size <= $max_size;
    }

    /**
     * 生成图片缩略图（静态方法，供路由直接调用）
     * @param string $srcPath 原图路径
     * @param array|null $filetypes 文件类型配置（未使用，保留兼容）
     * @param int $maxWidth 缩略图最大宽度
     * @param int $maxHeight 缩略图最大高度
     * @return string|false 成功返回缩略图文件名，失败返回 false
     */
    public static function generateThumbnail($srcPath, $filetypes = null, $maxWidth = 200, $maxHeight = 200) {
        global $conf;

        // 从配置读取缩略图开关
        $thumb_enabled = isset($conf['upload_thumb_enabled']) ? intval($conf['upload_thumb_enabled']) : 1;
        if(!$thumb_enabled) return false;

        // 从配置读取缩略图宽度
        if(!empty($conf['upload_thumb_width'])) {
            $maxWidth = intval($conf['upload_thumb_width']);
            $maxHeight = $maxWidth;
        }

        if(!function_exists('imagecreatefromjpeg')) return false;

        $imginfo = getimagesize($srcPath);
        if(!$imginfo) return false;

        $srcW = $imginfo[0];
        $srcH = $imginfo[1];
        $mime = $imginfo['mime'];

        // 小图不生成缩略图
        if($srcW <= $maxWidth && $srcH <= $maxHeight) return false;

        $ratio = min($maxWidth / $srcW, $maxHeight / $srcH);
        $dstW = intval($srcW * $ratio);
        $dstH = intval($srcH * $ratio);

        // 根据原图类型创建源图资源
        switch($mime) {
            case 'image/jpeg': $srcImg = imagecreatefromjpeg($srcPath); break;
            case 'image/png':  $srcImg = imagecreatefrompng($srcPath);  break;
            case 'image/gif':  $srcImg = imagecreatefromgif($srcPath);  break;
            case 'image/webp': $srcImg = @imagecreatefromwebp($srcPath); break;
            case 'image/bmp':  $srcImg = imagecreatefrombmp($srcPath);  break;
            default: return false;
        }
        if(!$srcImg) return false;

        // 创建目标图资源
        $dstImg = imagecreatetruecolor($dstW, $dstH);

        // PNG/GIF 保留透明通道
        if($mime == 'image/png' || $mime == 'image/gif') {
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
        }

        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        // 生成缩略图文件名：在原文件名基础上加 _thumb 后缀
        $pathinfo = pathinfo($srcPath);
        $thumbDir = $pathinfo['dirname'].'/thumb';
        if(!is_dir($thumbDir)) mkdir($thumbDir, 0777, TRUE);
        $thumbFilename = $pathinfo['filename'].'_thumb.jpg';
        $thumbPath = $thumbDir.'/'.$thumbFilename;

        $quality = self::$thumbConfig['quality'];
        $result = imagejpeg($dstImg, $thumbPath, $quality);

        // PHP 8.0+ 自动释放图像资源，imagedestroy() 已废弃
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($srcImg);
            imagedestroy($dstImg);
        }

        return $result ? 'thumb/'.$thumbFilename : false;
    }

    /**
     * 获取视频信息（宽、高、时长）
     * 优先使用 ffprobe，不可用时尝试从文件头解析
     * @param string $filepath 视频文件路径
     * @return array|false 成功返回数组，失败返回 false
     */
    public static function getVideoInfo($filepath) {
        $info = array('width' => 0, 'height' => 0, 'duration' => 0);

        // 尝试使用 ffprobe 获取视频信息
        if(function_exists('exec')) {
            $ffprobe = trim((string)shell_exec('which ffprobe 2>/dev/null'));
            if(!empty($ffprobe) && is_executable($ffprobe)) {
                $filepath_esc = escapeshellarg($filepath);
                $cmd = $ffprobe.' -v quiet -print_format json -show_streams -show_format '.$filepath_esc.' 2>/dev/null';
                $output = shell_exec($cmd);
                if($output) {
                    $json = json_decode($output, true);
                    if($json && !empty($json['streams'])) {
                        foreach($json['streams'] as $stream) {
                            if(isset($stream['width']) && isset($stream['height'])) {
                                $info['width'] = intval($stream['width']);
                                $info['height'] = intval($stream['height']);
                                break;
                            }
                        }
                    }
                    if(!empty($json['format']['duration'])) {
                        $info['duration'] = intval(floatval($json['format']['duration']));
                    }
                    if($info['width'] > 0 || $info['duration'] > 0) {
                        return $info;
                    }
                }
            }
        }

        // 尝试使用getID3库（如果存在）
        $getid3_path = APP_PATH.'plugin/getid3/getid3.php';
        if(file_exists($getid3_path)) {
            include_once $getid3_path;
            if(class_exists('getID3')) {
                try {
                    $getID3 = new getID3();
                    $fileinfo = $getID3->analyze($filepath);
                    if(!empty($fileinfo['video']['resolution_x'])) {
                        $info['width'] = intval($fileinfo['video']['resolution_x']);
                    }
                    if(!empty($fileinfo['video']['resolution_y'])) {
                        $info['height'] = intval($fileinfo['video']['resolution_y']);
                    }
                    if(!empty($fileinfo['playtime_seconds'])) {
                        $info['duration'] = intval($fileinfo['playtime_seconds']);
                    }
                    if($info['width'] > 0 || $info['duration'] > 0) {
                        return $info;
                    }
                } catch(Exception $e) {
                    // 忽略异常
                }
            }
        }

        return ($info['width'] > 0 || $info['duration'] > 0) ? $info : false;
    }

    /**
     * 根据附件ID获取附件信息
     * @param int $aid 附件ID
     * @return array|null
     */
    public function getAttachmentById(int $aid) {
        if($aid <= 0) return null;
        if($this->db) {
            return $this->db->findOne('attach', array('aid' => $aid));
        }
        return attach_read($aid);
    }

    /**
     * 删除附件
     * @param int $aid 附件ID
     * @return int
     */
    public function deleteAttachment(int $aid) {
        if($aid <= 0) return 0;
        $attach = $this->getAttachmentById($aid);
        if(!$attach) return 0;

        global $conf;
        if(!empty($attach['filename'])) {
            $filepath = $conf['upload_path'].'attach/'.$attach['filename'];
            if(file_exists($filepath)) @unlink($filepath);
        }

        if($this->db) {
            return $this->db->delete('attach', array('aid' => $aid));
        }
        return attach_delete($aid) ? 1 : 0;
    }
}
