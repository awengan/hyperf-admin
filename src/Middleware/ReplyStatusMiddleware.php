<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Middleware;

use Awengan\HyperfAdmin\Constants\Consts;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 把信封里的 `code` 同步成 **HTTP 状态码**。
 *
 * 控制器里 `return Reply::error('标识已被占用', 42200)` 时，Hyperf 会把对象当
 * `application/json` 输出，但 HTTP 状态码仍是 200 —— 用 curl / Postman / 别的
 * 客户端接的时候，看着 200 却是一份错误，很难受。
 *
 * `Reply::error()` 会把算好的状态码放进协程上下文，这里取出来套到响应上：
 *
 *   42200 → 422   40400 → 404   41900 → 403（Swoole 没有 419 这个 reason phrase）
 *
 * 只改「本来还是 200」的响应：`validationError()`（422）、`Csrf`（403）、
 * `ErrorPage`（404/500）会自己设状态，不覆盖。
 *
 * 前端不依赖它（`http.js` 只读信封里的 `code`），所以这是给「非 SPA 调用方」用的。
 */
class ReplyStatusMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $response = $handler->handle($request);

        $status = Context::get(Consts::REPLY_STATUS_CONTEXT);

        // 用完即清：同一协程里后续再写响应不能带上别人的状态码
        Context::destroy(Consts::REPLY_STATUS_CONTEXT);

        if (! is_int($status) || $status < 400 || $status > 599) {
            return $response;
        }

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        return $response->withStatus($status);
    }
}
