<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Throwable;

/**
 * 前端资源自托管：把 CDN 上的 ESM 模块图**递归下载到本地**。
 *
 * 为什么不能只下一个文件：`naive-ui` 由 esm.sh 提供，它本身是一张模块图
 * （`/naive-ui@2.45.3/es2022/naive-ui.mjs` 再 import `vueuc` / `vooks` /
 * `css-render` / `date-fns`…）。所以这里做的事是：
 *
 *   1. 从 import map 里的两个入口开始 BFS
 *   2. 抓每个模块，扫描 `import/export ... from 'spec'` 与 `import('spec')`
 *   3. `http(s)://` 与 `/…`（esm.sh 根相对）→ 入队；裸标识符（`vue`）→ 保留，
 *      交给 import map
 *   4. 把 specifier 改写成相对本文件的本地文件名
 *   5. 写一份 `public/static/vendor/import-map.json`，`UI::importMap()` 会合并它
 *
 * **自托管 = 这个 json 存在**。删掉它就回到 CDN，配置文件一个字都不用改。
 *
 * 限额（防止跑飞）：最多 {@see MAX_FILES} 个文件、深度 {@see MAX_DEPTH}。
 */
final class VendorAssets
{
    public const VENDOR_PATH = '/static/vendor';

    public const MAP_FILE = 'import-map.json';

    private const MAX_FILES = 400;

    private const MAX_DEPTH = 8;

    /**
     * `URL => 本地文件名`
     *
     * @var array<string, string>
     */
    private array $downloaded = [];

    /**
     * 待抓队列：`[url, depth]`
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private array $queue = [];

    /**
     * @var array<int, string>
     */
    private array $errors = [];

    public function __construct(private string $targetDir)
    {
    }

    /**
     * 本地 import map 文件路径（存在即表示已自托管）。
     */
    public static function mapPath(string $vendorDir): string
    {
        return rtrim($vendorDir, '/') . '/' . self::MAP_FILE;
    }

    /**
     * 是否已经自托管。
     */
    public static function enabled(): bool
    {
        return is_file(self::mapPath(self::vendorDir()));
    }

    public static function vendorDir(): string
    {
        return rtrim(BASE_PATH, '/') . '/public' . self::VENDOR_PATH;
    }

    /**
     * 已发布的本地 import map（没有就返回空数组）。
     *
     * @return array<string, string>
     */
    public static function localImportMap(): array
    {
        $path = self::mapPath(self::vendorDir());

        if (! is_file($path)) {
            return [];
        }

        $map = json_decode((string) file_get_contents($path), true);

        return is_array($map) ? $map : [];
    }

    /**
     * 删掉本地资源，回到 CDN 模式。
     *
     * @return bool 是否真的删了东西
     */
    public static function reset(): bool
    {
        $dir = self::vendorDir();

        if (! is_dir($dir)) {
            return false;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            is_file($file) && @unlink($file);
        }

        @rmdir($dir);

        return true;
    }

    /**
     * 下载整张模块图，并写 import map。
     *
     * @param array<string, string> $entries 入口（`包名 => URL`），通常来自 `cdn.imports`
     * @return array{files: int, bytes: int, errors: array<int, string>, map: array<string, string>}
     */
    public function download(array $entries): array
    {
        if (! is_dir($this->targetDir) && ! @mkdir($this->targetDir, 0o755, true) && ! is_dir($this->targetDir)) {
            return ['files' => 0, 'bytes' => 0, 'errors' => ['无法创建目录：' . $this->targetDir], 'map' => []];
        }

        // 入口先入队（裸包名 → 绝对 URL）
        $entryUrls = [];

        foreach ($entries as $name => $url) {
            if (! is_string($url) || ! preg_match('#^https?://#i', $url)) {
                continue;
            }

            $entryUrls[$name] = $url;
            $this->queue[] = [$url, 0];
        }

        $bytes = 0;

        // BFS
        while ($this->queue !== [] && count($this->downloaded) < self::MAX_FILES) {
            [$url, $depth] = array_shift($this->queue);

            if (isset($this->downloaded[$url]) || $depth > self::MAX_DEPTH) {
                continue;
            }

            $code = $this->fetch($url);

            if ($code === null) {
                $this->errors[] = '抓取失败：' . $url;

                continue;
            }

            $filename = $this->filenameFor($url);
            $this->downloaded[$url] = $filename;

            // 改写并收集依赖
            $rewritten = $this->rewrite($code, $url, $depth, $filename);

            if (file_put_contents($this->targetDir . '/' . $filename, $rewritten) === false) {
                $this->errors[] = '写入失败：' . $filename;
            } else {
                $bytes += strlen($rewritten);
            }
        }

        if ($this->queue !== []) {
            $this->errors[] = sprintf('达到文件数上限（%d），还有 %d 个模块没下载', self::MAX_FILES, count($this->queue));
        }

        // 入口映射：`vue` => `/static/vendor/vue-xxxx.mjs`
        $map = [];

        foreach ($entryUrls as $name => $url) {
            if (isset($this->downloaded[$url])) {
                $map[$name] = self::VENDOR_PATH . '/' . $this->downloaded[$url];
            }
        }

        if ($map !== []) {
            file_put_contents(
                self::mapPath($this->targetDir),
                json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        return ['files' => count($this->downloaded), 'bytes' => $bytes, 'errors' => $this->errors, 'map' => $map];
    }

    /**
     * 扫描 specifier 并改写成本地相对路径，同时把新 URL 入队。
     */
    private function rewrite(string $code, string $baseUrl, int $depth, string $currentFile): string
    {
        // import ... from 'x' / export ... from 'x' / import 'x'
        $patterns = [
            '/(\bfrom\s*)([\'"])([^\'"]+)\2/',
            '/(\bimport\s*\(\s*)([\'"])([^\'"]+)\2/',
            '/(\bimport\s+)([\'"])([^\'"]+)\2/',
        ];

        foreach ($patterns as $pattern) {
            $code = (string) preg_replace_callback(
                $pattern,
                function (array $match) use ($baseUrl, $depth, $currentFile): string {
                    $spec = $match[3];
                    $resolved = $this->resolve($spec, $baseUrl);

                    if ($resolved === null) {
                        return $match[0];   // 裸标识符：交给 import map
                    }

                    $this->queue[] = [$resolved, $depth + 1];

                    // 本地相对路径（同一个目录，所以直接 ./<file>）
                    $target = $this->downloaded[$resolved] ?? $this->filenameFor($resolved);

                    return $match[1] . $match[2] . './' . $target . $match[2];
                },
                $code
            );
        }

        return $code;
    }

    /**
     * specifier → 绝对 URL；裸标识符返回 null。
     */
    private function resolve(string $spec, string $baseUrl): ?string
    {
        if ($spec === '' || str_starts_with($spec, 'data:') || str_starts_with($spec, 'node:')) {
            return null;
        }

        if (preg_match('#^https?://#i', $spec)) {
            return $spec;
        }

        // esm.sh 的根相对路径
        if (str_starts_with($spec, '/')) {
            $parts = parse_url($baseUrl);

            return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'esm.sh') . $spec;
        }

        // 相对路径
        if (str_starts_with($spec, './') || str_starts_with($spec, '../')) {
            return $this->normalizeUrl($baseUrl . '/' . $spec);
        }

        return null;   // 裸标识符
    }

    /**
     * 折叠 `../`、去掉 hash。
     */
    private function normalizeUrl(string $url): string
    {
        $url = preg_replace('/#.*$/', '', $url) ?? $url;
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . '/' . implode('/', $segments) . $query;
    }

    /**
     * 稳定、可读的文件名：`包名-8位哈希.mjs`。
     */
    private function filenameFor(string $url): string
    {
        if (isset($this->downloaded[$url])) {
            return $this->downloaded[$url];
        }

        $name = 'mod';
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if (preg_match('#/([a-z0-9._-]+)@#i', $path, $m)) {
            $name = $m[1];
        } elseif (preg_match('#/([^/]+?)(\.mjs|\.js)?$#i', $path, $m)) {
            $name = basename($m[1]);
        }

        $name = preg_replace('/[^a-z0-9._-]+/i', '-', $name) ?: 'mod';

        return $name . '-' . substr(md5($url), 0, 8) . '.mjs';
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'awengan-hyperf-admin/self-host',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        return (string) $body;
    }
}
