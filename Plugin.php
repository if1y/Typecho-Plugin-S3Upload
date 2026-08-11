<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Options;
use Typecho\Common;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;

/**
 * S3 协议上传插件
 *  ① 路径格式从 /year/month 改为 /cid
 *  ② 简化数据库字段写入
 *  ③ 支持迁移远端存量文件到新路径
 * 
 * @package S3Upload
 * @author if1y
 * @version 1.3.3_migration
 * @link https://github.com/if1y/Typecho-Plugin-S3Upload
 * @dependence 1.3-*
 */
class S3Upload_Plugin implements PluginInterface
{
    const MIGRATION_ACTION = 's3upload-migration';

    /**
     * 激活插件方法
     */
    public static function activate()
    {
        // 检查依赖
        $warnings = self::checkDependencies();
        
        // 注册钩子 - 使用新的Typecho 1.3.0方式
        \Typecho\Plugin::factory('Widget\Upload')->uploadHandle = ['S3Upload_FileHandler', 'uploadHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->modifyHandle = ['S3Upload_FileHandler', 'modifyHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->deleteHandle = ['S3Upload_FileHandler', 'deleteHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->attachmentHandle = ['S3Upload_FileHandler', 'attachmentHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->attachmentDataHandle = ['S3Upload_FileHandler', 'attachmentDataHandle'];

        // 注册迁移 Action 路由
        Helper::addAction(self::MIGRATION_ACTION, 'S3Upload_Migration');

        $message = _t('插件已经激活，请设置 S3 配置信息');
        if (!empty($warnings)) {
            $message .= '<br/>' . implode('<br/>', $warnings);
        }

        return $message;
    }

    /**
     * 检查依赖
     */
    private static function checkDependencies()
    {
        if (!extension_loaded('curl')) {
            throw new \Typecho\Plugin\Exception(_t('PHP cURL 扩展未安装，插件无法上传文件，请先安装并启用 cURL 扩展'));
        }

        $warnings = [];
        if (!extension_loaded('gd')) {
            $warnings[] = _t('提醒：PHP GD 扩展未安装，图片压缩功能将不可用');
        }

        return $warnings;
    }

    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        // 清理迁移 Action 路由
        Helper::removeAction(self::MIGRATION_ACTION);

        return _t('插件已被禁用');
    }

    /**
     * 获取插件配置面板
     */
    public static function config(Form $form)
    {
        // S3基本设置
        $endpoint = new \Typecho\Widget\Helper\Form\Element\Text(
            'endpoint', 
            null,
            's3.amazonaws.com',
            _t('S3 Endpoint'),
            _t('S3 服务器地址，例如：s3.amazonaws.com')
        );
        $form->addInput($endpoint->addRule('required', _t('必须填写 Endpoint')));

        $bucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'bucket',
            null,
            '',
            _t('Bucket'),
            _t('存储桶名称')
        );
        $form->addInput($bucket->addRule('required', _t('必须填写 Bucket')));

        $region = new \Typecho\Widget\Helper\Form\Element\Text(
            'region',
            null,
            'us-east-1',
            _t('Region'),
            _t('区域，例如：us-east-1')
        );
        $form->addInput($region->addRule('required', _t('必须填写 Region')));

        $accessKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'accessKey',
            null,
            '',
            _t('Access Key'),
            _t('访问密钥 ID')
        );
        $form->addInput($accessKey->addRule('required', _t('必须填写 Access Key')));

        $secretKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'secretKey',
            null,
            '',
            _t('Secret Key'),
            _t('访问密钥密码')
        );
        $form->addInput($secretKey->addRule('required', _t('必须填写 Secret Key')));

        // CDN设置
        $customDomain = new \Typecho\Widget\Helper\Form\Element\Text(
            'customDomain',
            null,
            '',
            _t('自定义域名'),
            _t('设置自定义域名，例如：cdn.example.com（不要包含 http:// 或 https://）')
        );
        $form->addInput($customDomain);

        $useHttps = new \Typecho\Widget\Helper\Form\Element\Radio(
            'useHttps',
            [
                'true' => _t('使用'),
                'false' => _t('不使用'),
            ],
            'true',
            _t('使用HTTPS'),
            _t('是否使用HTTPS协议')
        );
        $form->addInput($useHttps);

        // 高级设置
        $customPath = new \Typecho\Widget\Helper\Form\Element\Text(
            'customPath',
            null,
            '/',
            _t('自定义路径前缀'),
            _t('设置文件存储路径前缀，例如：uploads/（以/结尾）')
        );
        $form->addInput($customPath);

        $saveLocal = new \Typecho\Widget\Helper\Form\Element\Radio(
            'saveLocal',
            [
                'true' => _t('保存'),
                'false' => _t('不保存'),
            ],
            'false',
            _t('保存本地备份'),
            _t('是否在本地保存文件备份')
        );
        $form->addInput($saveLocal);

        $urlStyle = new \Typecho\Widget\Helper\Form\Element\Radio(
            'urlStyle',
            [
                'path' => _t('路径形式'),
                'virtual' => _t('虚拟主机形式'),
            ],
            'path',
            _t('URL访问方式'),
            _t('路径形式：http(s)://endpoint/bucket/object<br/>虚拟主机形式：http(s)://bucket.endpoint/object')
        );
        $form->addInput($urlStyle);

        // 图片压缩设置
        $compressImages = new \Typecho\Widget\Helper\Form\Element\Radio(
            'compressImages',
            [
                '1' => _t('启用'),
                '0' => _t('禁用'),
            ],
            '0',
            _t('图片压缩'),
            _t('是否对上传的图片进行自动压缩')
        );
        $form->addInput($compressImages);

        $compressQuality = new \Typecho\Widget\Helper\Form\Element\Text(
            'compressQuality',
            null,
            '85',
            _t('压缩质量'),
            _t('图片压缩质量 (1-100)，数值越大质量越好但文件越大')
        );
        $compressQuality->addRule('isInteger', _t('请输入整数'));
        $compressQuality->addRule('min', _t('请输入不小于1的数字'), 1);
        $compressQuality->addRule('max', _t('请输入不大于100的数字'), 100);
        $form->addInput($compressQuality);

        // SSL证书验证设置
        $sslVerify = new \Typecho\Widget\Helper\Form\Element\Radio(
            'sslVerify',
            [
                'true' => _t('启用'),
                'false' => _t('禁用'),
            ],
            'false',
            _t('SSL证书验证'),
            _t('是否验证S3服务器的SSL证书。如果上传失败且服务器SSL证书配置有问题，可以尝试禁用此选项')
        );
        $form->addInput($sslVerify);

        // 输出迁移工具
        $actionUrl = \Typecho\Common::url('/action/s3upload-migration', \Typecho\Widget::widget('Widget\Options')->index);
        echo self::renderMigrationUI($actionUrl);
    }

    /**
     * 渲染迁移工具 UI
     */
    private static function renderMigrationUI(string $actionUrl): string
    {
        return <<<HTML
<style>
.s3-migration { margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; }
.s3-migration h3 { margin: 0 0 10px; font-size: 14px; }
.s3-migration .desc { color: #666; font-size: 12px; margin-bottom: 10px; }
.s3-migration .ctrl { margin-bottom: 10px; }
.s3-migration .ctrl label { margin-right: 15px; font-size: 13px; }
.s3-migration .btn { display: inline-block; padding: 6px 16px; background: #467b96; color: #fff; border: none; border-radius: 3px; cursor: pointer; font-size: 13px; }
.s3-migration .btn:hover { background: #386a82; }
.s3-migration .btn:disabled { background: #ccc; cursor: not-allowed; }
.s3-migration .log { background: #fff; border: 1px solid #ddd; border-radius: 3px; padding: 8px; max-height: 300px; overflow-y: auto; font-size: 12px; line-height: 1.6; font-family: monospace; }
.s3-migration .log .ok { color: #090; }
.s3-migration .log .fail { color: #c00; }
.s3-migration .log .info { color: #888; }
.s3-migration .log .skip { color: #f90; }
.s3-migration .summary { margin-top: 8px; font-size: 13px; font-weight: bold; }
</style>

<div class="s3-migration">
    <h3>📦 存量文件迁移</h3>
    <div class="desc">
        将旧路径格式（year/month/filename）的附件迁移到新路径格式（parentCid/filename）。<br>
        迁移过程：S3 服务端复制 → 更新数据库 → 替换文章内容中的旧 URL。<br>
        <strong>不会删除 S3 上的旧文件</strong>，可手动确认后再清理。
    </div>
    <div class="ctrl">
        <label><input type="checkbox" id="s3-preview-mode" checked> 仅预览（不实际执行）</label>
        <button class="btn" id="s3-start-btn" onclick="s3StartMigration()">开始迁移</button>
    </div>
    <div class="log" id="s3-log">点击「开始迁移」查看结果</div>
    <div class="summary" id="s3-summary"></div>
</div>

<script>
var s3ActionUrl = '{$actionUrl}';
var s3Migrating = false;

function s3Log(msg, cls) {
    var log = document.getElementById('s3-log');
    log.innerHTML += '<div class="' + (cls || '') + '">' + msg + '</div>';
    log.scrollTop = log.scrollHeight;
}

function s3StartMigration() {
    if (s3Migrating) return;
    s3Migrating = true;

    var btn = document.getElementById('s3-start-btn');
    var preview = document.getElementById('s3-preview-mode').checked;
    btn.disabled = true;
    btn.textContent = '处理中...';
    document.getElementById('s3-log').innerHTML = '';
    document.getElementById('s3-summary').innerHTML = '';

    if (preview) {
        // 预览模式：一次性扫描全部附件
        s3Log('正在扫描附件列表...', 'info');
        fetch(s3ActionUrl + '?preview=1')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) { s3Log('错误: ' + data.error, 'fail'); return; }
                s3Log('共扫描到 ' + data.total + ' 个需要迁移的附件：', 'info');
                data.items.forEach(function(item) {
                    s3Log('  CID=' + item.cid + '  parent=' + item.parent + '  ' + item.oldPath + ' → ' + item.newPath, 'ok');
                });
                if (data.total === 0) {
                    s3Log('无需迁移，全部附件已使用新路径格式。', 'skip');
                }
                document.getElementById('s3-summary').textContent = '预览完成，共 ' + data.total + ' 个附件需要迁移。';
                s3Migrating = false;
                btn.disabled = false;
                btn.textContent = '开始迁移';
            })
            .catch(function(err) {
                s3Log('请求失败: ' + err.message, 'fail');
                s3Migrating = false;
                btn.disabled = false;
                btn.textContent = '开始迁移';
            });
    } else {
        // 执行模式：先预览获取列表，再逐条迁移
        s3Log('正在扫描附件列表...', 'info');
        fetch(s3ActionUrl + '?preview=1')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) { s3Log('错误: ' + data.error, 'fail'); s3Migrating = false; btn.disabled = false; btn.textContent = '开始迁移'; return; }
                if (data.total === 0) {
                    s3Log('无需迁移。', 'skip');
                    s3Migrating = false;
                    btn.disabled = false;
                    btn.textContent = '开始迁移';
                    return;
                }

                var items = data.items;
                var idx = 0;
                var success = 0, failed = 0;

                function migrateNext() {
                    if (idx >= items.length) {
                        var msg = '迁移完成！成功: ' + success + ', 失败: ' + failed;
                        s3Log('── ' + msg + ' ──', success > 0 ? 'ok' : 'fail');
                        document.getElementById('s3-summary').textContent = msg;
                        s3Migrating = false;
                        btn.disabled = false;
                        btn.textContent = '开始迁移';
                        return;
                    }

                    var item = items[idx];
                    s3Log('(' + (idx+1) + '/' + items.length + ') 迁移 ' + item.oldPath + ' → ' + item.newPath + ' ...', 'info');
                    btn.textContent = '迁移中 ' + (idx+1) + '/' + items.length;

                    fetch(s3ActionUrl + '?cid=' + item.cid)
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res.status === 'success') {
                                success++;
                                var postInfo = res.updatedPosts > 0 ? ' (更新了 ' + res.updatedPosts + ' 篇文章的 URL)' : '';
                                s3Log('  ✓ ' + res.oldPath + ' → ' + res.newPath + postInfo, 'ok');
                            } else if (res.error) {
                                failed++;
                                s3Log('  ✗ ' + res.oldPath + ' → 跳过: ' + res.error, 'skip');
                            } else {
                                failed++;
                                s3Log('  ✗ ' + (res.oldPath || 'CID=' + item.cid) + ' → ' + (res.reason || '未知错误'), 'fail');
                            }
                            idx++;
                            migrateNext();
                        })
                        .catch(function(err) {
                            failed++;
                            s3Log('  ✗ 请求失败: ' + err.message, 'fail');
                            idx++;
                            migrateNext();
                        });
                }

                s3Log('开始迁移 ' + items.length + ' 个附件...', 'info');
                migrateNext();
            })
            .catch(function(err) {
                s3Log('扫描失败: ' + err.message, 'fail');
                s3Migrating = false;
                btn.disabled = false;
                btn.textContent = '开始迁移';
            });
    }
}
</script>
HTML;
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Form $form)
    {
    }
}