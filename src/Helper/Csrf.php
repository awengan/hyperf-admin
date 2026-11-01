<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Throwable;

/**
 * CSRF 防护：双提交 Cookie（double-submit cookie）。
 *
 * 为什么不塞进会话：本组件是无会话的（HMAC 签名 Cookie），所以用双提交最自然 ——
 *
 *   1. 服务端把随机 token 写进 **可被 JS 读的** cookie `ea_csrf`（HttpOnly=false）
 *   2. `UI::render()` 把它放进 boot payload，SPA 每次写请求带 `X-CSRF-Token` 头
 *   3. 服务端比对「头的值」与「cookie 的值」
 *
 * 攻击者能逼浏览器带上 cookie，但**读不到**它（同源策略），所以拼不出这个头。
 *
 * 注意：`SameSite=Lax` 只挡跨站，挡不住同站子域（被拿下的兄弟子域可以发请求），
 * 这就是为什么光靠 SameSite 不够。
 */
final class Csrf
{
    /**
     * cookie 名（故意不是 HttpOnly，前端要读）。
     */
    public const COOKIE = 'ea_csrf';

    public const HEADER = 'x-csrf-token';

    private const CONTEXT_TOKEN = 'hyperf-admin.csrf.token';

    private const CONTEXT_COOKIE = 'hyperf-admin.csrf.cookie';

    /**
     * 取当前请求的 token：优先 cookie，没有就生成一个待写回的。
     *
     * 同一个请求内多次调用返回同一个值。
     */
    public static function token(?RequestInterface $request = null): string
    {
        if (Context::has(self::CONTEXT_TOKEN)) {
            return (string) Context::get(self::CONTEXT_TOKEN);
        }

        $request ??= Utils::getRequest();
        $token = (string) ($request->getCookieParams()[self::COOKIE] ?? '');

        if (self::isWellFormed($token)) {
            Context::set(self::CONTEXT_TOKEN, $token);

            return $token;
        }

        $token = bin2hex(random_bytes(16));
        Context::set(self::CONTEXT_TOKEN, $token);
        Context::set(self::CONTEXT_COOKIE, self::cookieHeader($token, self::ttl()));

        return $token;
    }

    /**
     * 换一个新 token（登录/退出/改密码时轮换，降低固定 token 的风险）。
     */
    public static function rotate(): string
    {
        $token = bin2hex(random_bytes(16));

        Context::set(self::CONTEXT_TOKEN, $token);
        Context::set(self::CONTEXT_COOKIE, self::cookieHeader($token, self::ttl()));

        return $token;
    }

    /**
     * `Set-Cookie` 头（如果有待写回的 token）。
     */
    public static function pendingCookie(): ?string
    {
        $cookie = Context::get(self::CONTEXT_COOKIE);

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /**
     * 校验：头的值必须与 cookie 的值一致（常数时间比较）。
     */
    public static function validate(RequestInterface $request): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $cookie = (string) ($request->getCookieParams()[self::COOKIE] ?? '');
        $header = (string) $request->getHeaderLine(self::HEADER);

        if (! self::isWellFormed($cookie) || ! self::isWellFormed($header)) {
            return false;
        }

        return hash_equals($cookie, $header);
    }

    public static function enabled(): bool
    {
        try {
            return Auth::config('csrf.enabled', true) === true;
        } catch (Throwable) {
            return false;   // 容器没就绪（CLI/测试）时不拦
        }
    }

    /**
     * token 形状：32 位小写 hex。
     */
    private static function isWellFormed(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    private static function ttl(): int
    {
        try {
            return (int) Auth::config('auth.ttl', 604800);
        } catch (Throwable) {
            return 604800;
        }
    }

    private static function cookieHeader(string $token, int $ttl): string
    {
        $path = '/';
        $secure = (strtolower((string) (Utils::getRequest()->getUri()->getScheme())) === 'https') ? '; Secure' : '';

        return sprintf(
            '%s=%s; Path=%s; Max-Age=%d; SameSite=Lax%s',
            self::COOKIE,
            $token,
            $path,
            $ttl,
            $secure
        );
    }
}
