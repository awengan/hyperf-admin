<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Middleware;

use Awengan\HyperfAdmin\Helper\Auth;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 安全响应头（含 CSP）。
 *
 * CSP 的难点是**两处内联 script**（importmap + boot payload），所以：
 *  - 每个请求生成一个 nonce（{@see nonce()}），`UI::render()` 把它写到 script 标签上
 *  - 这里的 CSP 用同一个 nonce 放行
 *  - `style-src` 必须带 `unsafe-inline`：Naive UI 是 CSS-in-JS，运行时注入 <style>
 *
 * 声明在最外层（第一个 `#[Middleware]`），这样鉴权/CSRF 的响应也带这些头。
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CONTEXT_NONCE = 'hyperf-admin.csp.nonce';

    /**
     * 与 `UI::render()` 共享的 nonce（同一请求内一致）。
     */
    public static function nonce(): string
    {
        if (Context::has(self::CONTEXT_NONCE)) {
            return (string) Context::get(self::CONTEXT_NONCE);
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        Context::set(self::CONTEXT_NONCE, $nonce);

        return $nonce;
    }

    public function __construct(private RequestInterface $request)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        $csp = $this->contentSecurityPolicy();

        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $csp;
        }

        // 只在 HTTPS 下加 HSTS（给 HTTP 站加会把纯 HTTP 环境锁死）
        if (strtolower($this->request->getUri()->getScheme()) === 'https') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * 从 `cdn.imports` 里推出需要放行的外部主机；自托管时列表为空。
     */
    private function contentSecurityPolicy(): string
    {
        if (Auth::config('security.csp.enabled', true) !== true) {
            return '';
        }

        $nonce = self::nonce();
        $origins = $this->assetOrigins();

        // `unsafe-eval` 是本架构的固有代价：组件用 `template:` 字符串写，
        // 靠 Vue 的**运行时编译器**（内部 `new Function()`）编译，不给 eval 就白屏。
        // 想彻底去掉它，只能引入构建步骤把模板预编译成 render 函数。
        // 即便如此，这条 CSP 仍挡住了「注入外部脚本」「base 标签劫持」「被 iframe 嵌套」等。
        $script = "'self' 'nonce-{$nonce}' 'unsafe-eval'";
        // Naive UI 是 CSS-in-JS，运行时注入 <style>，必须有 unsafe-inline
        $style = "'self' 'unsafe-inline'";
        $font = "'self' data:";
        $connect = "'self'";

        if ($origins !== []) {
            $list = implode(' ', $origins);
            $script .= ' ' . $list;
            $style .= ' ' . $list;
            $font .= ' ' . $list;
            $connect .= ' ' . $list;
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "img-src 'self' data: blob:",
            "font-src {$font}",
            "connect-src {$connect}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function assetOrigins(): array
    {
        $origins = [];

        // 用「实际生效」的 import map：自托管后就没有外部主机的白名单了
        foreach ((array) \Awengan\HyperfAdmin\UI::effectiveImports() as $url) {
            if (! is_string($url) || ! preg_match('#^https?://([^/]+)#i', $url, $m)) {
                continue;
            }

            $origin = $m[0];
            $origins[$origin] = $origin;
        }

        return array_values($origins);
    }
}
