<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Middleware;

use Awengan\HyperfAdmin\Helper\Csrf;
use Awengan\HyperfAdmin\Helper\ErrorPage;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF 拦截：所有写方法（POST/PUT/PATCH/DELETE）都必须带匹配的 `X-CSRF-Token`。
 *
 * 失败返回 **419**（Laravel 的惯例）：XHR 拿到 JSON 信封，浏览器直达看到面板内页面。
 * 顺手把待写回的 CSRF cookie 挂到响应上（首次访问 / 轮换后）。
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * 不需要 CSRF 的路径（登录前的静态资源之类）。
     *
     * @var array<int, string>
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private RequestInterface $request,
        private ResponseInterface $response
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $method = strtoupper($request->getMethod());

        // 先保证这个请求里 token 存在（可能顺带生成待写回的 cookie）
        Csrf::token($this->request);

        if (! in_array($method, self::SAFE_METHODS, true) && ! Csrf::validate($this->request)) {
            return $this->reject();
        }

        return $this->attachCookie($handler->handle($request));
    }

    /**
     * 拒绝：HTTP 用 **403**，信封里给 `41900`。
     *
     * 为什么不用 419：Swoole 内置的原因短语表里没有 419，
     * `status(419)` 会被静默丢弃、最终仍是 200（403/404/422 都正常）。
     * 前端只认 `code`，所以语义放在信封里。
     */
    private function reject(): PsrResponseInterface
    {
        $response = ErrorPage::render(
            $this->request,
            $this->response,
            403,
            '419 页面已过期',
            '表单令牌不匹配或已过期，请刷新页面后重试。',
            ['/' => '刷新页面'],
            41900
        );

        return $this->attachCookie($response);
    }

    private function attachCookie(PsrResponseInterface $response): PsrResponseInterface
    {
        $cookie = Csrf::pendingCookie();

        return $cookie === null ? $response : $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
