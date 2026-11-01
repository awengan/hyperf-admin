<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Helper\Assets;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 静态资源控制器。
 *
 * Hyperf 自身不提供静态文件服务，而面板要能只靠
 * `php bin/hyperf.php start` 跑起来，所以 `/static/*` 由这个路由兜底。
 * 生产环境用 nginx 托管 `public/static` 时，这段逻辑不会命中。
 *
 * 本控制器**不加**鉴权中间件：登录页本身也要加载 CSS/JS。
 */
#[Controller]
class AssetController
{
    /**
     * @var array<string, string>
     */
    private const MIME = [
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
    ];

    public function __construct(
        private RequestInterface $request,
        private ResponseInterface $response
    ) {
    }

    #[GetMapping(path: '/static/{path:.+}')]
    public function serve(string $path): PsrResponseInterface
    {
        // 支持 `/static/v<hash>/js/app.js` 这种带版本的路径：版本号只是为了换 URL，
        // 磁盘上仍然是 `public/static/js/app.js`。
        $versioned = preg_match('#^v[0-9a-z]{6,16}/(.+)$#', $path, $matches) === 1;

        if ($versioned) {
            $path = $matches[1];
        }

        $file = $this->resolve($path);

        if ($file === null) {
            return $this->response->json([
                'code' => 404,
                'msg' => 'Asset not found',
                'data' => new \stdClass(),
            ])->withStatus(404);
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $etag = '"' . dechex((int) filemtime($file)) . '-' . dechex((int) filesize($file)) . '"';

        // 带版本号的 URL 内容不会变，可以放心长缓存；
        // 未带版本号的老 URL（以及被 nginx/代理直接命中时）退回 no-cache + ETag 校验，
        // 保证改动能被重新验证到。
        $immutable = $versioned && in_array($extension, ['js', 'mjs', 'css', 'map', 'json', 'html', 'txt'], true);
        $revalidate = ! $immutable;

        // 按规范 ETag 会带引号，但个别代理/客户端会去掉，这里两边都归一化再比较。
        $incoming = trim($this->request->getHeaderLine('if-none-match'), " \t\"");
        $current = trim($etag, '"');

        if (! $immutable && $incoming !== '' && $incoming === $current) {
            return $this->response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'no-cache');
        }

        $response = $this->response
            ->withHeader('Content-Type', self::MIME[$extension] ?? 'application/octet-stream')
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $immutable ? 'public, max-age=31536000, immutable' : 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        // 传输层 gzip：安装时预生成的 `.gz` 存在、且客户端接受，就直接发压缩版
        $gzip = $this->acceptsGzip() && $this->gzipEnabled() && is_file($file . '.gz');

        if ($gzip) {
            $response = $response
                ->withHeader('Content-Encoding', 'gzip')
                ->withHeader('Vary', 'Accept-Encoding');
            $file .= '.gz';
        }

        if ($this->request->getMethod() === 'HEAD') {
            return $response->withHeader('Content-Length', (string) filesize($file));
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return $this->response->json([
                'code' => 500,
                'msg' => 'Asset unreadable',
                'data' => new \stdClass(),
            ])->withStatus(500);
        }

        return $response->withBody(new SwooleStream($contents));
    }

    /**
     * 把请求路径映射到 `public/static` 下的真实文件，并拒绝目录穿越。
     */
    /**
     * 客户端是否接受 gzip。
     */
    private function acceptsGzip(): bool
    {
        return str_contains(strtolower($this->request->getHeaderLine('accept-encoding')), 'gzip');
    }

    private function gzipEnabled(): bool
    {
        try {
            return (bool) \Awengan\HyperfAdmin\Helper\Auth::config('assets.gzip', true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolve(string $path): ?string
    {
        $publicPath = (string) Assets::config('assets.public_path', BASE_PATH . '/public');
        $realPublic = realpath($publicPath);

        if ($realPublic === false) {
            return null;
        }

        $relative = str_replace('\\', '/', rawurldecode($path));

        if (str_contains($relative, '..')) {
            return null;
        }

        $candidate = realpath($realPublic . '/static/' . ltrim($relative, '/'));

        if ($candidate === false || ! is_file($candidate)) {
            return null;
        }

        return str_starts_with($candidate, $realPublic . DIRECTORY_SEPARATOR) ? $candidate : null;
    }
}
