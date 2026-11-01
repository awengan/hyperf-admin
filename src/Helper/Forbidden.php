<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Awengan\HyperfAdmin\Constants\ErrorCode;
use Awengan\HyperfAdmin\Content;
use Awengan\HyperfAdmin\UI;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 403 的两种呈现方式，供权限中间件和 `guard()` 共用。
 *
 * - SPA 的 XHR：403 JSON 信封（前端弹 msg）
 * - 浏览器直达：面板内的 403 页面（不甩一段裸 JSON）
 */
final class Forbidden
{
    public static function response(
        RequestInterface $request,
        ResponseInterface $response,
        string $permission
    ): PsrResponseInterface {
        $message = '无权限访问（需要 ' . $permission . '）';

        if (Utils::isAjax($request)) {
            return $response
                ->json(Result::error(ErrorCode::FORBIDDEN, $message))
                ->withStatus(403)
                ->withHeader('Cache-Control', 'no-cache');
        }

        $page = (new Content())
            ->title('403 无权限')
            ->html('<p style="margin:0;color:#6b7280">当前账号没有 <code>' . htmlspecialchars($permission, ENT_QUOTES) . '</code> 权限。</p>');

        return $response
            ->html(UI::render(array_merge(
                ['user' => Auth::profile($request)],
                UI::boot(),
                ['page' => $page->getData()]
            )))
            ->withStatus(403)
            ->withHeader('Cache-Control', 'no-cache');
    }
}
