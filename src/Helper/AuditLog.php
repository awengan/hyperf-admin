<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;
use Throwable;

/**
 * 审计日志。
 *
 * 两条来源：
 *  - {@see \Awengan\HyperfAdmin\Middleware\AuditLogMiddleware}：统一记录所有写请求
 *  - 控制器里的显式调用：登录成功/失败、删除时带上业务摘要（哪条数据、标题是什么）
 *
 * 设计原则：**记日志失败绝不能影响主流程**，所以全程 try/catch 吞异常。
 */
final class AuditLog
{
    /**
     * Context 标记：本请求已经记过业务日志。
     *
     * `AuditLogMiddleware` 据此避免为同一次请求再记一条通用记录。
     */
    public const RECORDED_FLAG = 'hyperf-admin.audit.recorded';

    /**
     * 请求体里需要打码的字段。
     *
     * @var array<int, string>
     */
    private const SECRETS = ['password', 'password_confirmation', 'old_password', 'new_password', 'secret', 'token'];

    /**
     * @param array<string, mixed> $context action/target/target_id/summary/payload/status 等
     */
    public static function record(string $action, array $context = []): void
    {
        try {
            $user = self::user();
            $request = self::request();

            Db::table('admin_logs')->insert([
                'user_id' => (int) ($user['uid'] ?? $user['id'] ?? 0),
                'username' => (string) ($user['username'] ?? ''),
                'action' => mb_substr($action, 0, 32),
                'method' => (string) ($context['method'] ?? ($request?->getMethod() ?? '')),
                'path' => mb_substr((string) ($context['path'] ?? ($request ? Utils::currentPath($request) : '')), 0, 255),
                'target' => mb_substr((string) ($context['target'] ?? ''), 0, 128),
                'target_id' => mb_substr((string) ($context['target_id'] ?? ''), 0, 64),
                'summary' => mb_substr((string) ($context['summary'] ?? ''), 0, 255),
                'payload' => self::encodePayload($context['payload'] ?? null),
                'ip' => mb_substr((string) self::clientIp($request), 0, 64),
                'ua' => mb_substr((string) ($request?->getHeaderLine('user-agent') ?? ''), 0, 255),
                'status' => (int) ($context['status'] ?? 0),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // 标记本请求已记业务日志，中间件就不再补一条通用记录
            Context::set(self::RECORDED_FLAG, true);
        } catch (Throwable) {
            // 日志表还没建、DB 抖动…… 都不该让业务 500
        }
    }

    /**
     * 本请求是否已经记过业务日志。
     */
    public static function alreadyRecorded(): bool
    {
        try {
            return Context::get(self::RECORDED_FLAG, false) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 把请求体打码后转成 JSON。
     */
    public static function maskPayload(mixed $payload): ?string
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        array_walk_recursive($payload, static function (&$value) {
            if (is_string($value) && mb_strlen($value) > 200) {
                $value = mb_substr($value, 0, 200) . '…';
            }
        });

        foreach (self::SECRETS as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '***';
            }
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    private static function encodePayload(mixed $payload): ?string
    {
        return is_string($payload) ? $payload : self::maskPayload($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function user(): ?array
    {
        try {
            return Auth::user();
        } catch (Throwable) {
            return null;
        }
    }

    private static function request(): ?\Hyperf\HttpServer\Contract\RequestInterface
    {
        try {
            return Utils::getRequest();
        } catch (Throwable) {
            return null;
        }
    }

    private static function clientIp(?\Hyperf\HttpServer\Contract\RequestInterface $request): string
    {
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
