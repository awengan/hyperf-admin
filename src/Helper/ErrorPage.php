<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Awengan\HyperfAdmin\Content;
use Awengan\HyperfAdmin\UI;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Throwable;

/**
 * 面板内的错误页（403 / 404 / 500）。
 *
 * XHR 走 JSON 信封，浏览器直达走带侧边栏的 HTML 页 —— 否则用户会看到一段裸 JSON。
 * `$boot` 渲染失败时会退化成极简 HTML，避免「错误页本身又抛错」的循环。
 */
final class ErrorPage
{
    /**
     * @param array<string, string> $links 可选：底部的「去这里」链接（路径 => 文案）
     */
    public static function render(
        RequestInterface $request,
        ResponseInterface $response,
        int $status,
        string $title,
        string $message,
        array $links = [],
        ?int $code = null
    ): PsrResponseInterface {
        if (Utils::isAjax($request)) {
            return $response
                ->json(Result::error($code ?? $status * 100, $message))
                ->withStatus($status)
                ->withHeader('Cache-Control', 'no-cache');
        }

        $body = sprintf(
            '<div class="ea-error"><h2>%s</h2><p>%s</p>%s</div>',
            htmlspecialchars($title, ENT_QUOTES),
            htmlspecialchars($message, ENT_QUOTES),
            self::links($links)
        );

        try {
            $page = (new Content())->title($title)->html($body);

            $html = UI::render(array_merge(
                ['user' => Auth::profile($request)],
                UI::boot(),
                ['page' => $page->getData()]
            ));
        } catch (Throwable) {
            // 连外壳都渲染不出来（比如配置错误）：给个能看懂的最小页面
            $html = '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
                . '<div style="font-family:sans-serif;padding:48px"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
                . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></div>';
        }

        return $response->html($html)->withStatus($status)->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * @param array<string, string> $links
     */
    private static function links(array $links): string
    {
        if ($links === []) {
            return '';
        }

        $items = '';

        foreach ($links as $href => $label) {
            $items .= sprintf(
                '<a class="ea-error__link" href="%s">%s</a>',
                htmlspecialchars((string) $href, ENT_QUOTES),
                htmlspecialchars((string) $label, ENT_QUOTES)
            );
        }

        return '<div class="ea-error__links">' . $items . '</div>';
    }
}
