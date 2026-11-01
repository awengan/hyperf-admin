<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Command;

use Awengan\HyperfAdmin\Constants\Consts;
use Awengan\HyperfAdmin\Helper\Assets;
use Awengan\HyperfAdmin\Helper\VendorAssets;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * `php bin/hyperf.php admin:install`
 *
 * 发布前端资源、默认配置与建表 SQL，然后打印后续步骤。
 *
 * 本类通过 `ConfigProvider` 的 `commands` 注册（不使用 `#[Command]` 注解，
 * 因为注解注册要求包目录被扫描）。
 */
class InstallCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('admin:install');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('发布 Hyperf Admin (Naive UI) 的前端资源与配置');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, '覆盖已发布的配置、SQL 与静态资源');
        $this->addOption('migrate', null, InputOption::VALUE_NONE, '发布迁移文件并执行 migrate（替代手工导入 SQL）');
        $this->addOption('self-host', null, InputOption::VALUE_NONE, '把 Vue / Naive UI 从 CDN 下载到 public/static/vendor（离线/内网部署用）');
        $this->addOption('reset-vendor', null, InputOption::VALUE_NONE, '删除本地前端资源，切回 CDN');
        $this->addOption('gzip', null, InputOption::VALUE_NONE, '为已发布的静态资源预生成 .gz（传输层压缩，不改源码）');
    }

    public function handle(): void
    {
        $force = (bool) $this->input->getOption('force');
        $migrate = (bool) $this->input->getOption('migrate');
        $selfHost = (bool) $this->input->getOption('self-host');
        $resetVendor = (bool) $this->input->getOption('reset-vendor');
        $gzip = (bool) $this->input->getOption('gzip');

        try {
            $files = Assets::publish($force);

        // .gz 是派生文件：重新发布后必须失效，否则会发旧内容
        Assets::clearCompressed();
            $config = Assets::publishConfig($force);
            $sql = Assets::publishSql($force);
        } catch (Throwable $e) {
            $this->error('发布失败：' . $e->getMessage());

            return;
        }

        $this->line(sprintf('已发布 %d 个前端文件到 public/。', $files), 'info');
        $this->line('配置文件：' . $this->relative($config));

        if ($migrate) {
            $migrations = Assets::publishMigrations();
            $this->line(sprintf('迁移文件：%d 个 → migrations/（已存在的不覆盖）', count($migrations)));

            $code = $this->call('migrate');

            $this->newLine();

            if ($code === 0) {
                $this->line('迁移执行完成，表结构与种子数据已就绪。', 'info');
                $this->line('默认账号：admin / admin123（首次登录后请立刻改密码）', 'comment');
            } else {
                $this->warn('migrate 返回非 0，请检查上面的输出。');
                $this->line('也可以手工执行：php bin/hyperf.php migrate', 'comment');
            }
        } else {
            $this->line('建表 SQL：' . $this->relative($sql));
        }

        // 前端资源自托管（离线/内网部署）
        if ($resetVendor) {
            $this->newLine();
            $this->line(VendorAssets::reset() ? '已删除本地前端资源，切回 CDN。' : '本来就没有本地资源。', 'info');
        }

        if ($selfHost) {
            $this->newLine();

            $entries = \Awengan\HyperfAdmin\UI::effectiveImports();
            // 只处理 CDN 地址（已经自托管时 effectiveImports 返回的是本地路径）
            $entries = array_filter($entries, static fn ($url) => is_string($url) && preg_match('#^https?://#i', $url));

            if ($entries === []) {
                $this->line('已经是自托管资源，跳过下载（要重新下载：--reset-vendor --self-host）', 'info');
            } else {
                $this->line('从 CDN 下载前端资源到 public' . VendorAssets::VENDOR_PATH . ' …', 'comment');

                // esm.sh 加 `bundle`：把整张模块图压成一个文件。
                // 不加的话 naive-ui 会拆成 300+ 个小模块，安装要跑十几分钟。
                $entries = array_map(static function (string $url): string {
                    if (preg_match('#^https://esm\.sh/#i', $url) && ! str_contains($url, 'bundle')) {
                        return $url . (str_contains($url, '?') ? '&' : '?') . 'bundle';
                    }

                    return $url;
                }, $entries);

                $result = (new VendorAssets(VendorAssets::vendorDir()))->download($entries);

                $this->line(sprintf('已下载 %d 个模块（%.1f KB）', $result['files'], $result['bytes'] / 1024), 'info');

                foreach ($result['map'] as $name => $path) {
                    $this->line('  ' . $name . ' → ' . $path);
                }

                foreach ($result['errors'] as $error) {
                    $this->warn('  ' . $error);
                }

                if ($result['files'] > 0) {
                    $this->line('现在 import map 指向本地文件；删掉 public' . VendorAssets::VENDOR_PATH . ' 即可切回 CDN。', 'comment');
                }
            }
        }

        // 传输层 gzip：源码不动，只为已发布的静态文件生成 `.gz`
        if ($gzip) {
            $root = rtrim(BASE_PATH, '/') . '/public' . Consts::ASSET_PATH;
            $made = 0;
            $bytesRaw = 0;
            $bytesGz = 0;

            // 先把文件列表收集完再写：边遍历边往同一个目录写 `.gz`，
            // 遍历器会把新文件也扫进来，统计数字会翻倍。
            $sources = [];

            if (is_dir($root)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() !== 'gz') {
                        $sources[] = $file->getPathname();
                    }
                }
            }

            foreach ($sources as $source) {
                $content = (string) file_get_contents($source);
                $compressed = (string) gzencode($content, 9);

                if ($compressed !== '' && file_put_contents($source . '.gz', $compressed) !== false) {
                    ++$made;
                    $bytesRaw += strlen($content);
                    $bytesGz += strlen($compressed);
                }
            }

            $this->newLine();
            $this->line(sprintf(
                '已生成 %d 个 .gz（%.0f KB → %.0f KB，压缩到 %.0f%%）',
                $made,
                $bytesRaw / 1024,
                $bytesGz / 1024,
                $bytesRaw > 0 ? $bytesGz / $bytesRaw * 100 : 0
            ), 'info');
            $this->line('浏览器带 Accept-Encoding: gzip 时会自动收到压缩版；源码保持可读。', 'comment');
            $this->line('资源有变动时这些 .gz 会自动重建（`admin:install --gzip`）；只是重启不会删。', 'comment');
        }

        $config = $this->container->get(ConfigInterface::class);
        $home = (string) $config->get('hyperf-admin.home_path', '/admin/home');
        $login = (string) $config->get('hyperf-admin.login_path', Consts::LOGIN_PATH);
        $apiBase = rtrim((string) $config->get('hyperf-admin.api_base', ''), '/');

        // 端口取宿主实际配置，别硬编码 9501
        $servers = $config->get('server.servers', []);
        $port = (int) ($servers[0]['port'] ?? 9501);

        $this->newLine();
        $this->line('接下来：', 'comment');

        $step = 0;

        if (! $migrate) {
            $this->line(sprintf('  %d. 建库：导入 SQL（mysql ... < %s）或改用 admin:install --migrate', ++$step, $this->relative($sql)));
        }

        $this->line(sprintf('  %d. 按需修改 config/autoload/hyperf-admin.php（默认账号 admin / admin123）', ++$step));
        $this->line(sprintf('  %d. php bin/hyperf.php start  →  打开 http://127.0.0.1:%d%s', ++$step, $port, $apiBase === '' ? '/' : $apiBase));
        $this->line(sprintf('     首页 %s，登录页 %s', $home, $login));
        $this->newLine();
        $this->line('重置密码：php bin/hyperf.php admin:password <用户名> <新密码>', 'comment');
        $this->line('强制下线：php bin/hyperf.php admin:revoke [用户名]', 'comment');
    }

    private function relative(string $path): string
    {
        $base = BASE_PATH . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
