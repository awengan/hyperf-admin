<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * 组件资源发布。
 *
 * 前端是纯 CDN + 原生 ES module，没有构建步骤，所以「发布」就是把组件里的
 * `resources/` 拷到宿主 `public/`；配置文件与 SQL 另外单独发布。
 */
final class Assets
{
    public static function config(string $key, mixed $default = null): mixed
    {
        if (! ApplicationContext::hasContainer()) {
            return $default;
        }

        try {
            return ApplicationContext::getContainer()
                ->get(ConfigInterface::class)
                ->get('hyperf-admin.' . $key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * 组件根目录（本文件位于 <package>/src/Helper/）。
     */
    public static function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function sourcePath(): string
    {
        return self::packagePath() . '/resources';
    }

    public static function targetPath(): string
    {
        return (string) self::config('assets.public_path', BASE_PATH . '/public');
    }

    /**
     * 前端资源是否已经发布。
     */
    public static function published(): bool
    {
        return is_file(self::targetPath() . '/static/js/app.js');
    }

    /**
     * 已发布前端资源的短版本号（内容变化即变化）。
     *
     * 前端的模块图是原生 ESM：`app.js` 用 `import './lib/icons.js'` 这类**相对路径**
     * 引用子模块，查询串（`app.js?v=…`）不会传递给它们。所以版本号必须放在**路径**
     * 里（`/static/v<hash>/js/app.js`），相对导入才会解析成
     * `/static/v<hash>/js/lib/icons.js` —— 整张图一起换 URL，浏览器不会混用新旧模块。
     */
    /**
     * 删掉所有预生成的 `.gz`。
     *
     * `.gz` 是**派生文件**且不在版本目录里（`/static/v<hash>/...` 只是 URL 前缀），
     * 所以重新发布后必须一并失效，否则会发出旧内容。
     *
     * @return int 删除的文件数
     */
    public static function clearCompressed(): int
    {
        $root = self::targetPath() . '/static';

        if (! is_dir($root)) {
            return 0;
        }

        $removed = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'gz' && @unlink($file->getPathname())) {
                ++$removed;
            }
        }

        return $removed;
    }

    public static function version(): string
    {
        return self::signature();
    }

    /**
     * 资源指纹：**不含 `.gz`**。
     *
     * `.gz` 是派生文件，打不打包不该改变 URL 版本 —— 否则 `admin:install --gzip`
     * 会让所有浏览器重新下载整张模块图，而内容其实没变。
     *
     * @param bool $fresh 不走静态缓存（启动时比较「发布前后」用）
     */
    public static function signature(bool $fresh = false): string
    {
        static $cached = null;

        if (! $fresh && $cached !== null) {
            return $cached;
        }

        $root = self::targetPath() . '/static';
        $newest = 0;
        $bytes = 0;
        $count = 0;

        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() === 'gz') {
                    continue;
                }

                $newest = max($newest, (int) $file->getMTime());
                $bytes += (int) $file->getSize();
                ++$count;
            }
        }

        $hash = substr(md5($newest . '|' . $bytes . '|' . $count), 0, 8);

        return $fresh ? $hash : $cached = $hash;
    }

    /**
     * 是否每次启动自动同步资源。
     */
    public static function autoPublishEnabled(): bool
    {
        return (bool) self::config('assets.auto_publish', true);
    }

    /**
     * 把组件 `resources/` 同步到宿主 `public/`。
     *
     * @return int 拷贝的文件数
     */
    public static function publish(bool $force = false): int
    {
        $source = self::sourcePath();

        if (! is_dir($source)) {
            throw new RuntimeException(sprintf('Admin asset source "%s" is missing.', $source));
        }

        $target = self::targetPath();

        if ($force && is_dir($target . '/static')) {
            FileHelper::deleteDir($target . '/static');
        }

        return FileHelper::copyDir($source, $target);
    }

    /**
     * 发布默认配置（已存在时不覆盖，除非 $force）。
     */
    public static function publishConfig(bool $force = false): string
    {
        $source = self::packagePath() . '/config/hyperf-admin.php';
        $target = BASE_PATH . '/config/autoload/hyperf-admin.php';

        $force ? FileHelper::copyFile($source, $target) : FileHelper::copyFileIfMissing($source, $target);

        return $target;
    }

    /**
     * 发布建表 SQL（已存在时不覆盖，除非 $force）。
     */
    public static function publishSql(bool $force = false): string
    {
        $source = self::packagePath() . '/database/admin.sql';
        $target = BASE_PATH . '/sql/hyperf-admin.sql';

        $force ? FileHelper::copyFile($source, $target) : FileHelper::copyFileIfMissing($source, $target);

        return $target;
    }

    /**
     * 发布数据库迁移文件到宿主的 `migrations/` 目录。
     *
     * 已存在时**一律不覆盖**（迁移文件改了会让 `migrate` 的批次记录错乱）。
     *
     * @return array<int, string> 已就位的文件路径
     */
    public static function publishMigrations(): array
    {
        $source = self::packagePath() . '/database/migrations';
        $target = BASE_PATH . '/migrations';

        if (! is_dir($target)) {
            @mkdir($target, 0o755, true);
        }

        $published = [];

        foreach (glob($source . '/*.php') ?: [] as $file) {
            $destination = $target . '/' . basename($file);
            FileHelper::copyFileIfMissing($file, $destination);
            $published[] = $destination;
        }

        return $published;
    }
}
