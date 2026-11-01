<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Constants;

/**
 * Runtime constants shared by the admin component.
 */
final class Consts
{
    /**
     * Kept for backwards compatibility with the Element UI implementation.
     */
    public const SESSION_USER_KEY = 'user';

    /**
     * Signed cookie that carries the admin identity between requests.
     */
    public const AUTH_COOKIE = 'hyperf_admin_token';

    /**
     * Default auth cookie lifetime, in seconds (7 days).
     */
    public const AUTH_TTL = 604800;

    /**
     * Public URL prefix of the compiled frontend bundle.
     */
    public const ASSET_PATH = '/static';

    /**
     * URL of the login page.
     */
    public const LOGIN_PATH = '/admin/login.html';

    /**
     * Context key used to queue a `Set-Cookie` header for the current request.
     */
    public const AUTH_COOKIE_CONTEXT = 'hyperf-admin.auth.cookie';

    /**
     * `Reply::error()` 把算好的 HTTP 状态码放在这里，由
     * {@see \Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware} 取出来套到响应上。
     */
    public const REPLY_STATUS_CONTEXT = 'hyperf-admin.reply.status';
}
