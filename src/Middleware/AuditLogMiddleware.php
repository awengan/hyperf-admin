<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Middleware;

use Awengan\HyperfAdmin\Helper\AuditLog;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 统一审计：记录所有**写请求**（POST/PUT/PATCH/DELETE）。
 *
 * 只记「方法 + 路径 + 打码后的请求体 + 响应码」，不解读业务语义 ——
 * 这样任何新模块自动被覆盖，不需要每个 action 里写日志。
 * 需要业务摘要（比如「删除角色：运营 (#3)」）时，控制器再显式调 {@see AuditLog::record()}。
 *
 * 登录/登出也在其中，但密码会被打码。
 */
class AuditLogMiddleware implements MiddlewareInterface
{
    /**
     * 这些路径不记（噪音太大 / 无意义）。
     *
     * @var array<int, string>
     */
    private const IGNORE = ['/logout'];

    public function __construct(private RequestInterface $request)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        $response = $handler->handle($request);

        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || in_array($path, self::IGNORE, true)) {
            return $response;
        }

        // 控制器已经记过业务日志（带摘要）就不再补通用记录，避免一次操作两行
        if (AuditLog::alreadyRecorded()) {
            return $response;
        }

        $payload = null;

        try {
            $payload = $this->request->all();
        } catch (\Throwable) {
            $payload = null;
        }

        AuditLog::record('request', [
            'method' => $method,
            'path' => $path,
            'status' => $response->getStatusCode(),
            'payload' => $payload,
            'summary' => $method . ' ' . $path,
        ]);

        return $response;
    }
}
