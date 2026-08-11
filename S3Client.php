<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 简单的 S3 客户端实现
 */
class S3Upload_S3Client
{
    private static $instance = null;
    private $options = null;
    private $endpoint;
    private $bucket;
    private $region;
    private $accessKey;
    private $secretKey;

    /**
     * 私有构造函数
     */
    private function __construct()
    {
        $options = \Typecho\Widget::widget('Widget\Options');
        $this->options = $options->plugin('S3Upload');
        $this->endpoint = $this->options->endpoint;
        $this->bucket = $this->options->bucket;
        $this->region = $this->options->region;
        $this->accessKey = $this->options->accessKey;
        $this->secretKey = $this->options->secretKey;
    }

    /**
     * 获取实例
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 上传文件到 S3
     */
    public function putObject($path, $file)
    {
        S3Upload_Utils::log("S3Client::putObject 开始 - 路径: {$path}", 'debug');

        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);

        $payload = file_get_contents($file);
        if ($payload === false) {
            S3Upload_Utils::log("无法读取文件: {$file}", 'error');
            throw new Exception('无法读取文件');
        }

        S3Upload_Utils::log("文件读取成功，大小: " . strlen($payload) . " bytes", 'debug');

        $contentType = S3Upload_Utils::getMimeType($file);
        $contentSha256 = hash('sha256', $payload);

        // 准备请求
        $canonical_uri = $this->buildCanonicalUri($path);
        $canonical_querystring = '';

        S3Upload_Utils::log("请求URI: {$canonical_uri}, Content-Type: {$contentType}", 'debug');

        // 准备请求头
        $headers = array(
            'content-length' => strlen($payload),
            'content-type' => $contentType,
            'host' => $this->endpoint,
            'x-amz-content-sha256' => $contentSha256,
            'x-amz-date' => $date
        );

        // 签名
        $signature = $this->getSignature(
            'PUT',
            $canonical_uri,
            $canonical_querystring,
            $headers,
            $contentSha256,
            $shortDate
        );

        // 准备 cURL 请求
        $ch = curl_init();
        $url = 'https://' . $this->endpoint . $canonical_uri;

        S3Upload_Utils::log("上传URL: {$url}", 'debug');

        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;

        // 获取SSL验证设置
        $sslVerify = isset($this->options->sslVerify) && $this->options->sslVerify === 'true';
        S3Upload_Utils::log("SSL验证设置: " . ($sslVerify ? '启用' : '禁用'), 'debug');

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_HEADER => true
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        S3Upload_Utils::log("HTTP响应码: {$httpCode}", 'debug');

        if ($httpCode !== 200) {
            $errorMsg = "上传失败，HTTP状态码：{$httpCode}";
            if ($curlError) {
                $errorMsg .= "，cURL错误：{$curlError}";
            }
            $errorMsg .= "\n请求URL：{$url}\n响应：{$response}";
            S3Upload_Utils::log($errorMsg, 'error');
            throw new Exception($errorMsg);
        }

        S3Upload_Utils::log("上传成功", 'debug');

        return array(
            'path' => $path,
            'url' => $this->getObjectUrl($path)
        );
    }

    /**
     * 获取 S3 对象元数据（HEAD 请求）
     *
     * @param string $path 对象路径
     * @return array 包含 lastModified 等元数据
     * @throws Exception
     */
    public function headObject($path)
    {
        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);

        $canonical_uri = $this->buildCanonicalUri($path);
        $canonical_querystring = '';

        $headers = array(
            'host' => $this->endpoint,
            'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'x-amz-date' => $date
        );

        $signature = $this->getSignature(
            'HEAD',
            $canonical_uri,
            $canonical_querystring,
            $headers,
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $shortDate
        );

        $ch = curl_init();
        $url = 'https://' . $this->endpoint . $canonical_uri;

        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;

        $sslVerify = isset($this->options->sslVerify) && $this->options->sslVerify === 'true';

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("获取对象元数据失败，HTTP状态码：{$httpCode}，路径：{$path}");
        }

        // 解析响应头中的 Last-Modified
        $lastModified = '';
        foreach (explode("\r\n", $response) as $line) {
            if (stripos($line, 'Last-Modified:') === 0) {
                $lastModified = trim(substr($line, 14));
                break;
            }
        }

        return array(
            'lastModified' => $lastModified
        );
    }

    /**
     * 复制 S3 对象（服务端复制，不经过本地服务器）
     * 会读取源文件的 Last-Modified 并写入 x-amz-meta-mtime 自定义元数据以保留时间戳
     *
     * @param string $sourcePath 源对象路径
     * @param string $destPath 目标对象路径
     * @return array
     * @throws Exception
     */
    public function copyObject($sourcePath, $destPath)
    {
        S3Upload_Utils::log("S3Client::copyObject 开始 - 源: {$sourcePath} -> 目标: {$destPath}", 'debug');

        // 1. 先 HEAD 获取源文件的 Last-Modified
        $headMeta = $this->headObject($sourcePath);
        $lastModified = $headMeta['lastModified'];

        S3Upload_Utils::log("源文件 Last-Modified: {$lastModified}", 'debug');

        // 2. 将 Last-Modified 转为 Unix 时间戳（用于 x-amz-meta-mtime）
        $mtime = 0;
        if (!empty($lastModified)) {
            $mtime = strtotime($lastModified);
        }

        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);

        $sourceObjectKey = $this->buildObjectKey($sourcePath);
        $destCanonicalUri = $this->buildCanonicalUri($destPath);

        $copySource = '/' . $this->bucket . '/' . $sourceObjectKey;
        $contentSha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        // 准备请求头
        // 使用 x-amz-metadata-directive: REPLACE 以便写入自定义元数据
        $headers = array(
            'host' => $this->endpoint,
            'x-amz-copy-source' => $copySource,
            'x-amz-metadata-directive' => 'REPLACE',
            'x-amz-content-sha256' => $contentSha256,
            'x-amz-date' => $date
        );

        // 写入 x-amz-meta-mtime 保留原始时间戳
        if ($mtime > 0) {
            $headers['x-amz-meta-mtime'] = $mtime;
        }

        $signature = $this->getSignature('PUT', $destCanonicalUri, '', $headers, $contentSha256, $shortDate);

        $ch = curl_init();
        $url = 'https://' . $this->endpoint . $destCanonicalUri;

        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;

        $sslVerify = isset($this->options->sslVerify) && $this->options->sslVerify === 'true';

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_HEADER => true
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            $errorMsg = "复制文件失败，HTTP状态码：{$httpCode}";
            if ($curlError) {
                $errorMsg .= "，cURL错误：{$curlError}";
            }
            $errorMsg .= "\n源：{$sourcePath} -> 目标：{$destPath}\n响应：{$response}";
            S3Upload_Utils::log($errorMsg, 'error');
            throw new Exception($errorMsg);
        }

        S3Upload_Utils::log("复制成功: {$sourcePath} -> {$destPath} (mtime={$mtime})", 'debug');

        return array(
            'path' => $destPath,
            'url' => $this->getObjectUrl($destPath)
        );
    }

    /**
     * 删除 S3 对象
     */
    public function deleteObject($path)
    {
        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        
        $canonical_uri = $this->buildCanonicalUri($path);
        $canonical_querystring = '';
        
        $headers = array(
            'host' => $this->endpoint,
            'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'x-amz-date' => $date
        );
        
        $signature = $this->getSignature(
            'DELETE',
            $canonical_uri,
            $canonical_querystring,
            $headers,
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $shortDate
        );
        
        $ch = curl_init();
        $url = 'https://' . $this->endpoint . $canonical_uri;
        
        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;

        // 获取SSL验证设置
        $sslVerify = isset($this->options->sslVerify) && $this->options->sslVerify === 'true';

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0
        ));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 204 || $httpCode === 200;
    }

    /**
     * 获取签名
     */
    private function getSignature($method, $uri, $querystring, $headers, $payload_hash, $shortDate)
    {
        $algorithm = 'AWS4-HMAC-SHA256';
        $service = 's3';
        
        // Canonical Request
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        $canonical_request = $method . "\n"
            . $uri . "\n"
            . $querystring . "\n"
            . $canonical_headers . "\n"
            . $signed_headers . "\n"
            . $payload_hash;
        
        // String to Sign
        $credential_scope = $shortDate . '/' . $this->region . '/' . $service . '/aws4_request';
        $string_to_sign = $algorithm . "\n"
            . $headers['x-amz-date'] . "\n"
            . $credential_scope . "\n"
            . hash('sha256', $canonical_request);
        
        // Signing
        $kSecret = 'AWS4' . $this->secretKey;
        $kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);
        
        return $algorithm 
            . ' Credential=' . $this->accessKey . '/' . $credential_scope
            . ',SignedHeaders=' . $signed_headers
            . ',Signature=' . $signature;
    }

    /**
     * 获取对象URL
     * 
     * @param string $path 对象路径
     * @return string
     */
    public function getObjectUrl($path)
    {
        $protocol = $this->options->useHttps === 'true' ? 'https://' : 'http://';
        $objectKey = $this->buildObjectKey($path);
        if ($objectKey === '') {
            return '';
        }
        
        // 如果设置了自定义域名
        if (!empty($this->options->customDomain)) {
            $domain = rtrim($this->options->customDomain, '/');

            return $protocol . $domain . '/' . $objectKey;
        }

        // 没有自定义域名时，根据URL风格生成地址
        if ($this->options->urlStyle === 'virtual') {
            return $protocol . $this->bucket . '.' . $this->endpoint . '/' . $objectKey;
        }

        // 路径形式
        return $protocol . $this->endpoint . '/' . $this->bucket . '/' . $objectKey;
    }

    /**
     * 生成存储路径（使用父级 CID 作为目录名）
     *
     * @param array $file 上传文件信息
     * @param int $parentCid 父级 CID（文章/页面 CID），0 表示未关联
     * @return string
     */
    public function generatePath($file, $parentCid = 0)
    {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $ext = $ext ? strtolower($ext) : '';

        // 生成文件名
        $fileName = sprintf('%u', crc32(uniqid())) . ($ext ? '.' . $ext : '');

        // 使用父级 CID 作为目录名（parent=0 表示未归档，用 0 目录）
        $parentCid = intval($parentCid);

        // 合并路径: {parentCid}/{filename}
        return $parentCid . '/' . $fileName;
    }

    /**
     * 构建对象 Key（自动拼接并规范化 customPath）
     */
    private function buildObjectKey($path)
    {
        $path = ltrim((string)$path, '/');
        if ($path === '') {
            return '';
        }

        // 兼容旧调用：如果 path 已经带了原始 customPath，则先剥离，避免重复拼接
        $rawCustomPath = trim((string)$this->options->customPath, '/');
        if ($rawCustomPath !== '') {
            if ($path === $rawCustomPath) {
                $path = '';
            } elseif (strpos($path, $rawCustomPath . '/') === 0) {
                $path = substr($path, strlen($rawCustomPath) + 1);
            }
        }

        $customPath = $this->getNormalizedCustomPath();
        if ($customPath === '') {
            return $path;
        }

        if ($path === '') {
            return $customPath;
        }

        if ($path === $customPath || strpos($path, $customPath . '/') === 0) {
            return $path;
        }

        return $customPath . '/' . $path;
    }

    /**
     * 构建 S3 Canonical URI
     */
    private function buildCanonicalUri($path)
    {
        $objectKey = $this->buildObjectKey($path);
        return '/' . $this->bucket . '/' . ltrim($objectKey, '/');
    }

    /**
     * 规范化 customPath
     */
    private function getNormalizedCustomPath()
    {
        $customPath = trim((string)$this->options->customPath, '/');
        return trim($customPath, '/');
    }
}
