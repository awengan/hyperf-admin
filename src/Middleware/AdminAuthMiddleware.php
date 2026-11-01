<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Middleware;

use Awengan\HyperfAdmin\Constants\Consts;
use Awengan\HyperfAdmin\Helper\Auth;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Helper\Utils;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Guards every admin route.
 *
 * - XHR requests receive a `401` JSON envelope, so the SPA can react instead of
 *   following a redirect and parsing HTML as JSON.
 * - Browser navigations are redirected to the login page.
 * - `Set-Cookie` headers queued by {@see Auth::login()} / {@see Auth::logout()}
 *   are attached to the outgoing response.
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    /**
     * Paths that are always reachable (relative to the admin prefix).
     *
     * 注意：真实请求路径是 `/admin/login`、`/admin/login.html`，
     * 前缀由 `api_base` 决定，所以这里只写相对部分，比较前统一加前缀。
     *
     * @var array<int, string>
     */
    private const WHITELIST = [
        '/login',
        '/login.html',
    ];

    public function __construct(private ResponseInterface $response)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        if (! (bool) Auth::config('auth.enabled', true) || $this->isPublic($request->getUri()->getPath())) {
            return $this->attachCookie($handler->handle($request));
        }

        if (Auth::check()) {
            return $this->attachCookie($handler->handle($request));
        }

        if (Utils::isAjax()) {
            return $this->response
                ->json(Result::unauthorized())
                ->withStatus(401);
        }

        return $this->response->redirect((string) Auth::config('login_path', Consts::LOGIN_PATH));
    }

    private function isPublic(string $path): bool
    {
        $path = Utils::normalizePath($path);

        // 登录页 / 登录接口：带上前缀再比（`/admin/login.html`）
        $prefix = '/' . trim((string) Auth::config('api_base', '/admin'), '/');

        foreach (self::WHITELIST as $public) {
            if ($path === $prefix . $public) {
                return true;
            }
        }

        // 兼容没改前缀的老部署
        if (in_array($path, self::WHITELIST, true)) {
            return true;
        }

        // 静态资源必须免登录，否则登录页加载不到 CSS/JS。
        if (str_starts_with($path, Consts::ASSET_PATH . '/')) {
            return true;
        }

        foreach ((array) Auth::config('auth.except', []) as $except) {
            if (is_string($except) && ($path === $except || str_starts_with($path, rtrim($except, '/') . '/'))) {
                return true;
            }
        }

        return false;
    }

    private function attachCookie(PsrResponseInterface $response): PsrResponseInterface
    {
        $cookie = Auth::pendingCookie();

        return $cookie === null ? $response : $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
