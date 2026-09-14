<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 工具类
 * 
 * @package S3Upload
 * @author jkjoy
 * @version 1.0.0
 */
class S3Upload_Utils
{
    /**
     * 记录日志
     * 
     * @param string $message 日志信息
     * @param string $level 日志级别 [info, warning, error]
     */
    public static function log($message, $level = 'info')
    {
        // 读取日志开关，配置不可用时视为禁用，避免影响上传主流程
        try {
            $options = \Typecho\Widget::widget('Widget\Options')->plugin('S3Upload');
            $enable = isset($options->enableLog) ? (string) $options->enableLog : '0';
        } catch (\Throwable $e) {
            $enable = '0';
        }

        // 未开启日志时直接返回，不创建任何目录或文件
        if ($enable !== '1' && $enable !== 'true') {
            return;
        }

        $date = date('Y-m-d H:i:s');
        $logMessage = "[{$date}] [{$level}] {$message}\n";

        // 日志写入插件目录下的 log.txt
        $logFile = __DIR__ . '/log.txt';
        error_log($logMessage, 3, $logFile);
    }

    /**
     * 获取文件MIME类型
     * 
     * @param string $file 文件路径
     * @return string
     */
    public static function getMimeType($file)
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($file);
        }
        
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file);
            finfo_close($finfo);
            return $mime;
        }
        
        return 'application/octet-stream';
    }
}