<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Middleware;

use Awengan\HyperfAdmin\Exception\FormComponentException;
use Awengan\HyperfAdmin\Helper\AuditLog;
use Awengan\HyperfAdmin\Helper\Auth;
use Awengan\HyperfAdmin\Helper\ErrorPage;
use Awengan\HyperfAdmin\Helper\Utils;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * 把异常变成「面板内的错误页」，而不是一段裸 JSON 或框架默认错误页。
 *
 * - `FormComponentException`（记录不存在）→ 404
 * - 其它异常 → 500，同时记一条审计日志（摘要里带异常消息，便于排查）
 *
 * 声明顺序**要放在最前面**（最外层），这样鉴权/权限中间件抛的错也会被包住。
 */
class ErrorPageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestInterface $request,
        private ResponseInterface $response,
        private LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (FormComponentException $e) {
            return ErrorPage::render(
                $this->request,
                $this->response,
                404,
                '404 记录不存在',
                $e->getMessage(),
                ['/' => '回到首页']
            );
        } catch (Throwable $e) {
            // 落日志：线上排查只能靠它（渲染出来的错误页对运维没用）
            $this->logger->error(sprintf(
                '[hyperf-admin] %s %s 失败: %s
%s',
                $this->request->getMethod(),
                Utils::currentPath($this->request),
                $e->getMessage(),
                $e->getTraceAsString()
            ), [
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'user' => Auth::id($this->request),
            ]);

            AuditLog::record('error', [
                'summary' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 250),
                'status' => 500,
            ]);

            $message = Utils::isAjax($this->request)
                ? $e->getMessage()
                : '服务器处理请求时出错了' . (mb_strlen($e->getMessage()) > 0 ? '：' . $e->getMessage() : '');

            return ErrorPage::render(
                $this->request,
                $this->response,
                500,
                '500 出错了',
                $message,
                ['/' => '回到首页']
            );
        }
    }
}
