<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 存量文件迁移工具
 * 将旧路径 (year/month/filename) 格式的附件迁移到新路径 (parentCid/filename) 格式
 *
 * 两种模式：
 * - 预览模式 (preview=1)：一次性扫描所有附件，返回完整迁移预览列表
 * - 执行模式 (cid=XXX)：逐条迁移单个附件，避免 PHP 超时
 *
 * 迁移流程（单条）：
 * 1. S3 COPY 从旧路径到新路径（服务端复制，不删除旧文件）
 * 2. 更新 contents 表附件记录的 text 字段（path 值）
 * 3. 替换文章/页面内容中引用的旧 S3 URL 为新 URL
 *
 * @package S3Upload
 */
class S3Upload_Migration extends \Typecho\Widget implements \Widget\ActionInterface
{
    /**
     * 执行初始化（Widget::widget() 在实例化时会调用此方法）
     */
    public function execute()
    {
    }

    /**
     * 入口方法
     */
    public function action()
    {
        // 仅管理员可执行
        $user = \Widget\User::alloc();
        if (!$user->pass('administrator', true)) {
            throw new \Typecho\Widget\Exception(_t('无权访问'), 403);
        }

        $preview = isset($_REQUEST['preview']) && $_REQUEST['preview'] === '1';
        $cid = isset($_REQUEST['cid']) ? intval($_REQUEST['cid']) : 0;

        if ($preview) {
            // 预览模式：一次性扫描全部附件，返回完整列表
            $this->previewAll();
        } elseif ($cid > 0) {
            // 执行模式：逐条迁移单个附件
            $this->migrateOne($cid);
        } else {
            $this->json(['error' => '参数错误，请指定 preview=1 或 cid=XXX']);
        }
    }

    /**
     * 预览模式：扫描所有附件，返回需要迁移的完整列表
     */
    private function previewAll()
    {
        $s3Client = S3Upload_S3Client::getInstance();
        $db = \Typecho\Db::get();

        $rows = $db->fetchAll($db->select()
            ->from('table.contents')
            ->where('type = ?', 'attachment'));

        $items = [];
        foreach ($rows as $row) {
            $cid    = intval($row['cid']);
            $parent = intval($row['parent']);
            $meta   = @unserialize($row['text']);

            if (!is_array($meta) || !isset($meta['path'])) {
                continue;
            }

            $oldPath = $meta['path'];

            // 只筛选旧路径格式（year/month/filename，可能带 / 前缀或自定义路径前缀）
            if (!preg_match('#\d{4}/\d{1,2}/#', $oldPath)) {
                continue;
            }

            // 提取纯文件名，去除所有目录前缀
            $filename = basename($oldPath);
            $newPath  = $parent . '/' . $filename;

            $items[] = [
                'cid'     => $cid,
                'parent'  => $parent,
                'name'    => $meta['name'] ?? basename($oldPath),
                'oldPath' => $oldPath,
                'newPath' => $newPath,
                'oldUrl'  => $s3Client->getObjectUrl($oldPath),
                'newUrl'  => $s3Client->getObjectUrl($newPath)
            ];
        }

        $this->json([
            'total' => count($items),
            'items' => $items
        ]);
    }

    /**
     * 执行模式：迁移单个附件
     */
    private function migrateOne(int $cid)
    {
        $db = \Typecho\Db::get();
        $s3Client = S3Upload_S3Client::getInstance();

        // 查询附件记录
        $row = $db->fetchRow($db->select()
            ->from('table.contents')
            ->where('cid = ?', $cid)
            ->where('type = ?', 'attachment'));

        if (!$row) {
            $this->json(['error' => '附件不存在']);
        }

        $parent = intval($row['parent']);
        $meta   = @unserialize($row['text']);

        if (!is_array($meta) || !isset($meta['path'])) {
            $this->json(['error' => '无法解析附件元数据']);
        }

        $oldPath = $meta['path'];

        if (!preg_match('#\d{4}/\d{1,2}/#', $oldPath)) {
            $this->json(['error' => '已经是新路径格式，无需迁移']);
        }

        $filename = basename($oldPath);
        $newPath  = $parent . '/' . $filename;
        $oldUrl   = $s3Client->getObjectUrl($oldPath);
        $newUrl   = $s3Client->getObjectUrl($newPath);

        try {
            // 1. S3 COPY 服务端复制（不删除旧文件）
            S3Upload_Utils::log("迁移: S3 COPY {$oldPath} -> {$newPath}");
            $s3Client->copyObject($oldPath, $newPath);

            // 2. 更新附件 DB 中的 path 字段
            $meta['path'] = $newPath;
            $db->query($db->update('table.contents')
                ->rows(['text' => serialize($meta)])
                ->where('cid = ?', $cid));

            // 3. 替换文章/页面内容中的旧 URL 为新 URL
            $updatedPosts = $this->replaceUrlInPosts($db, $oldUrl, $newUrl);

            S3Upload_Utils::log("迁移成功: {$oldPath} -> {$newPath}");

            $this->json([
                'status'       => 'success',
                'cid'          => $cid,
                'parent'       => $parent,
                'name'         => $meta['name'] ?? $filename,
                'oldPath'      => $oldPath,
                'newPath'      => $newPath,
                'oldUrl'       => $oldUrl,
                'newUrl'       => $newUrl,
                'updatedPosts' => $updatedPosts
            ]);

        } catch (\Exception $e) {
            S3Upload_Utils::log("迁移失败: {$oldPath} -> {$newPath}, 错误: " . $e->getMessage(), 'error');

            $this->json([
                'status'  => 'failed',
                'cid'     => $cid,
                'parent'  => $parent,
                'name'    => $meta['name'] ?? $filename,
                'oldPath' => $oldPath,
                'newPath' => $newPath,
                'reason'  => $e->getMessage()
            ]);
        }
    }

    /**
     * 替换所有文章/页面内容中引用的旧 URL 为新 URL
     *
     * @return int 被更新的文章数量
     */
    private function replaceUrlInPosts($db, string $oldUrl, string $newUrl): int
    {
        $count = 0;

        $rows = $db->fetchAll($db->select('cid', 'text')
            ->from('table.contents')
            ->where('type = ? OR type = ?', 'post', 'page')
            ->where('text LIKE ?', '%' . $this->escapeLike($oldUrl) . '%'));

        foreach ($rows as $row) {
            $updated = str_replace($oldUrl, $newUrl, $row['text']);

            if ($updated !== $row['text']) {
                $db->query($db->update('table.contents')
                    ->rows(['text' => $updated])
                    ->where('cid = ?', $row['cid']));
                $count++;
            }
        }

        return $count;
    }

    private function escapeLike(string $str): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $str);
    }

    private function json(array $data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}