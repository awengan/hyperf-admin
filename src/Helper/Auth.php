<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Awengan\HyperfAdmin\Constants\Consts;
use Awengan\HyperfAdmin\Model\AdminUser;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Throwable;

/**
 * Stateless, signed-cookie authentication.
 *
 * The Element UI implementation relied on `hyperf/session`; the Naive UI
 * rewrite keeps a stateless cookie instead so the component needs no extra
 * composer dependency and works behind any number of workers.
 */
final class Auth
{
    public static function config(string $key, mixed $default = null): mixed
    {
        return ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('hyperf-admin.' . $key, $default);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * The authenticated user, or `null`.
     *
     * @return array<string, mixed>|null
     */
    public static function user(?RequestInterface $request = null): ?array
    {
        if (Context::has('hyperf-admin.auth.user')) {
            return Context::get('hyperf-admin.auth.user');
        }

        $request ??= Utils::getRequest();
        $token = $request->getCookieParams()[Consts::AUTH_COOKIE] ?? '';

        $user = is_string($token) ? self::decode($token) : null;

        // 会话吊销：token 里带密码指纹，改密码或 admin:revoke 之后对不上就作废
        if ($user !== null && ! self::stillValid($user)) {
            $user = null;
        }

        Context::set('hyperf-admin.auth.user', $user);

        return $user;
    }

    /**
     * token 里的指纹是否仍然匹配（关掉 revoke_on_password_change 时恒为 true）。
     *
     * @param array<string, mixed> $payload
     */
    private static function stillValid(array $payload): bool
    {
        if (self::config('auth.revoke_on_password_change', true) !== true) {
            return true;
        }

        $fingerprint = (string) ($payload['pv'] ?? '');

        if ($fingerprint === '') {
            // 旧格式 token（没有指纹）：宁可不认，避免改密码后旧会话还活着
            return false;
        }

        $model = AdminUser::query()->find((int) ($payload['uid'] ?? $payload['id'] ?? 0));

        if ($model === null) {
            return false;   // 账号被删了
        }

        return hash_equals(self::fingerprint((string) $model->password, (int) $model->session_version), $fingerprint);
    }

    /**
     * 会话指纹：密码哈希 + 会话版本的摘要。
     *
     * 改密码（哈希变）或 `admin:revoke`（版本 +1）都会让指纹变化，
     * 从而让所有已签发的 Cookie 立即失效。
     */
    public static function fingerprint(string $passwordHash, int $sessionVersion = 0): string
    {
        return substr(hash_hmac('sha256', $passwordHash . '|' . $sessionVersion, (string) self::config('auth.secret', '')), 0, 24);
    }

    /**
     * Normalised user profile for the frontend (`id` / `username` / `name`),
     * so the raw token payload never leaks into a response.
     *
     * @return array{id: mixed, username: mixed, name: mixed}|null
     */
    public static function profile(?RequestInterface $request = null): ?array
    {
        $user = self::user($request);

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user['uid'] ?? $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'name' => $user['name'] ?? ($user['username'] ?? null),
        ];
    }

    /**
     * 当前登录用户的 id（未登录返回 0）。
     */
    public static function id(?RequestInterface $request = null): int
    {
        $user = self::user($request);

        return (int) ($user['uid'] ?? $user['id'] ?? 0);
    }

    /**
     * 是否超级管理员：配置 `hyperf-admin.auth.super_admin_ids` 里的用户
     * 直接跳过所有权限校验（默认就是 id=1）。
     */
    public static function isSuperAdmin(?RequestInterface $request = null): bool
    {
        $id = self::id($request);

        if ($id <= 0) {
            return false;
        }

        $ids = (array) self::config('auth.super_admin_ids', [1]);

        return in_array($id, array_map('intval', $ids), true);
    }

    /**
     * 当前用户的权限标识集合（来自其所有角色），按请求缓存。
     *
     * @return array<int, string>
     */
    public static function permissionSlugs(?RequestInterface $request = null): array
    {
        if (Context::has('hyperf-admin.auth.permissions')) {
            return Context::get('hyperf-admin.auth.permissions');
        }

        $id = self::id($request);
        $slugs = [];

        if ($id > 0) {
            try {
                $slugs = AdminUser::query()->find($id)?->permissionSlugs() ?? [];
            } catch (Throwable) {
                // 表还没建（刚装好/未导入 SQL）时不要让整个面板 500
                $slugs = [];
            }
        }

        Context::set('hyperf-admin.auth.permissions', $slugs);

        return $slugs;
    }

    /**
     * 权限判断：超管恒为 true，其余看角色授予的 slug。
     */
    public static function can(string $permission, ?RequestInterface $request = null): bool
    {
        if (self::isSuperAdmin($request)) {
            return true;
        }

        return in_array($permission, self::permissionSlugs($request), true);
    }

    /**
     * Issue an auth cookie for the given user payload.
     *
     * `fingerprint` 来自 `AdminUser` 的密码哈希 + 会话版本（见 {@see fingerprint()}），
     * 用于「改密码 / revoke 之后旧 Cookie 立即失效」。
     *
     * @param array<string, mixed> $user
     */
    public static function login(array $user): void
    {
        $ttl = (int) self::config('auth.ttl', Consts::AUTH_TTL);
        $payload = [
            'uid' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'name' => $user['name'] ?? null,
            'pv' => $user['fingerprint'] ?? '',
            'exp' => time() + $ttl,
        ];

        Context::set(Consts::AUTH_COOKIE_CONTEXT, self::cookieHeader(self::encode($payload), $ttl));
        Context::set('hyperf-admin.auth.user', $user);
    }

    public static function logout(): void
    {
        Context::set(Consts::AUTH_COOKIE_CONTEXT, self::cookieHeader('', -3600));
        Context::set('hyperf-admin.auth.user', null);
    }

    /**
     * 吊销某个用户（或全部用户）的会话：会话版本 +1。
     *
     * @return int 受影响的行数
     */
    public static function revoke(?string $username = null): int
    {
        $query = AdminUser::query();

        if ($username !== null && $username !== '') {
            $query->where('username', $username);
        }

        $affected = 0;

        foreach ($query->get() ?? [] as $user) {
            $user->session_version = (int) $user->session_version + 1;
            $user->save();
            ++$affected;
        }

        return $affected;
    }

    /**
     * `Set-Cookie` value queued by {@see login()} / {@see logout()}, if any.
     */
    public static function pendingCookie(): ?string
    {
        $value = Context::get(Consts::AUTH_COOKIE_CONTEXT);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $encoded = self::base64UrlEncode($json);
        $signature = hash_hmac('sha256', $encoded, self::secret(), true);

        return $encoded . '.' . self::base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $encoded, self::secret(), true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $json = self::base64UrlDecode($encoded);

        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private static function secret(): string
    {
        $secret = self::config('auth.secret');

        if (! is_string($secret) || $secret === '') {
            $secret = getenv('ADMIN_SECRET') ?: '';
        }

        if ($secret === '') {
            // Deterministic per installation, so no configuration is required
            // to get started; set `admin.auth.secret` in production.
            $secret = hash('sha256', BASE_PATH . '|' . (getenv('APP_ENV') ?: 'dev'));
        }

        return $secret;
    }

    private static function cookieHeader(string $value, int $ttl): string
    {
        $path = (string) self::config('auth.cookie_path', '/');
        $sameSite = (string) self::config('auth.same_site', 'Lax');
        $secure = self::isSecureRequest() ? '; Secure' : '';

        if ($value === '') {
            return sprintf(
                '%s=; Path=%s; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=%s%s',
                Consts::AUTH_COOKIE,
                $path,
                $sameSite,
                $secure
            );
        }

        return sprintf(
            '%s=%s; Path=%s; Max-Age=%d; HttpOnly; SameSite=%s%s',
            Consts::AUTH_COOKIE,
            $value,
            $path,
            $ttl,
            $sameSite,
            $secure
        );
    }

    private static function isSecureRequest(): bool
    {
        try {
            return strtolower(Utils::getRequest()->getUri()->getScheme()) === 'https';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
