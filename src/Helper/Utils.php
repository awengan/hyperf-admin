<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Throwable;

use function Hyperf\Support\make;

final class Utils
{
    public static function getRequest(): RequestInterface
    {
        return make(RequestInterface::class);
    }

    public static function getResponse(): ResponseInterface
    {
        return make(ResponseInterface::class);
    }

    /**
     * 当前请求的**路径**（不含 scheme/host）。
     *
     * 注意别用 `$request->url()`：Hyperf 返回的是完整 URL
     * （`http://host/menu/1`），直接当跳转地址或前端路由 key 用会拼出
     * `/http://host/menu` 这种非法路径。
     */
    public static function currentPath(?RequestInterface $request = null): string
    {
        $request ??= self::getRequest();

        $path = $request->getUri()->getPath();

        return $path === '' ? '/' : $path;
    }

    /**
     * Detect requests issued by the SPA (axios/fetch) so a controller can answer
     * with JSON instead of rendering the HTML shell for deep links.
     */
    public static function isAjax(?RequestInterface $request = null): bool
    {
        $request ??= self::getRequest();

        if (str_contains(strtolower($request->getHeaderLine('accept')), 'application/json')) {
            return true;
        }

        if (strtolower($request->getHeaderLine('x-requested-with')) === 'xmlhttprequest') {
            return true;
        }

        return in_array(strtolower($request->getHeaderLine('sec-fetch-dest')), ['empty'], true);
    }

    /**
     * Normalise a URI path: always leading slash, never a trailing one.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function html(ResponseInterface $response, string $html): PsrResponseInterface
    {
        return $response->html($html);
    }

    /**
     * 客户端 IP：优先 `X-Forwarded-For` 的第一段（反代场景），否则 `remote_addr`。
     */
    public static function clientIp(?RequestInterface $request = null): string
    {
        try {
            $request ??= self::getRequest();
        } catch (Throwable) {
            return '';
        }

        if ($request === null) {
            return '';
        }

        $forwarded = $request->getHeaderLine('x-forwarded-for');

        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return (string) ($request->getServerParams()['remote_addr'] ?? '');
    }
}
