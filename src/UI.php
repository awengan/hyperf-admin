<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin;

use Awengan\HyperfAdmin\Constants\Consts;
use Awengan\HyperfAdmin\Helper\Assets;
use Awengan\HyperfAdmin\Helper\MenuHelper;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * Renders the HTML shell that boots the Naive UI single page application.
 *
 * Naive UI is loaded straight from a CDN through an import map — there is no
 * bundling step. Only `vue` has to be resolved by the import map; `naive-ui`
 * is fetched from esm.sh with `?external=vue` so it reuses the very same Vue
 * instance instead of shipping its own copy.
 */
final class UI
{
    private const DEFAULT_IMPORT_MAP = [
        'vue' => 'https://unpkg.com/vue@3.5.13/dist/vue.esm-browser.prod.js',
        'naive-ui' => 'https://esm.sh/naive-ui@2.45.3?external=vue',
    ];

    /**
     * @param array<string, mixed> $boot
     */
    public static function render(array $boot = []): string
    {
        $boot = self::boot($boot);

        $title = self::escape((string) ($boot['title'] ?? 'Hyperf Admin'));
        $importMap = self::importMap();
        $appJs = self::asset('js/app.js');
        $appCss = self::asset('css/app.css');
        $payload = self::json($boot);
        $nonce = self::nonce();

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>{$title}</title>
    <link rel="stylesheet" href="{$appCss}">
    <script type="importmap" nonce="{$nonce}">{$importMap}</script>
</head>
<body>
<div id="app">
    <div class="ea-boot">
        <div class="ea-boot__spinner"></div>
        <p>Loading…</p>
    </div>
</div>
<noscript>This admin panel requires JavaScript.</noscript>
<script nonce="{$nonce}">window.__EA_BOOT__ = {$payload};</script>
<script type="module" src="{$appJs}" nonce="{$nonce}"></script>
</body>
</html>
HTML;
    }

    /**
     * Login page: same bundle, different render mode.
     *
     * @param array<string, mixed> $boot
     */
    public static function login(array $boot = []): string
    {
        return self::render(array_merge($boot, ['mode' => 'login', 'page' => null]));
    }

    /**
     * Assemble the payload handed to the frontend.
     *
     * The authenticated user is supplied by the controller (`$boot['user']`) so
     * this stays a pure renderer with no request/coroutine dependency.
     *
     * @param array<string, mixed> $boot
     * @return array<string, mixed>
     */
    public static function boot(array $boot = []): array
    {
        $user = $boot['user'] ?? null;

        return array_merge([
            'mode' => 'app',
            // 前端写请求要带的 CSRF token（与 cookie 双提交校验）
            'csrfToken' => self::csrfToken(),
            'title' => (string) self::config('title', 'Hyperf Admin'),
            'apiBase' => (string) self::config('api_base', ''),
            'assetBase' => Consts::ASSET_PATH,
            'loginPath' => (string) self::config('login_path', Consts::LOGIN_PATH),
            'locale' => (string) self::config('locale', 'zh-CN'),
            'theme' => (array) self::config('theme', []),
            'user' => $user === null ? null : [
                'id' => $user['uid'] ?? $user['id'] ?? null,
                'username' => $user['username'] ?? null,
                'name' => $user['name'] ?? ($user['username'] ?? null),
            ],
            'menus' => MenuHelper::getMenu(),
            'page' => null,
        ], $boot);
    }

    /**
     * Import map, overridable through `config/autoload/hyperf-admin.php`.
     */
    public static function importMap(): string
    {
        return self::json(['imports' => self::effectiveImports()]);
    }

    /**
     * 实际生效的 import map：默认值 + 配置 + **本地自托管覆盖**。
     *
     * 自托管的判定是「`public/static/vendor/import-map.json` 存在」——
     * `admin:install --self-host` 会生成它，`--reset-vendor` 会删掉它。
     * 所以切回 CDN 不需要改任何配置文件。
     *
     * @return array<string, string>
     */
    public static function effectiveImports(): array
    {
        $imports = array_merge(self::DEFAULT_IMPORT_MAP, (array) self::config('cdn.imports', []));
        $local = \Awengan\HyperfAdmin\Helper\VendorAssets::localImportMap();

        return $local === [] ? $imports : array_merge($imports, $local);
    }

    /**
     * CSRF token（首次访问会生成并排队一个 `Set-Cookie`）。
     *
     * 拿不到请求上下文时返回空串 —— CLI / 单测里没有浏览器，不需要。
     */
    private static function csrfToken(): string
    {
        try {
            return \Awengan\HyperfAdmin\Helper\Csrf::token();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * CSP nonce：与 `SecurityHeadersMiddleware::nonce()` 共享同一个值。
     */
    private static function nonce(): string
    {
        try {
            return \Awengan\HyperfAdmin\Middleware\SecurityHeadersMiddleware::nonce();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 资源 URL：版本号放在**路径**里（`/static/v<hash>/js/app.js`）。
     *
     * 因为前端是原生 ESM，`app.js` 里的 `import './lib/icons.js'` 是相对路径，
     * 查询串不会带给子模块；只有路径里的版本号才会让整张模块图一起换 URL，
     * 浏览器就不会把「旧的 icons.js + 新的 app.js」混着用。
     */
    private static function asset(string $path): string
    {
        return Consts::ASSET_PATH . '/v' . Assets::version() . '/' . ltrim($path, '/');
    }

    /**
     * JSON that is safe to embed inside a `<script>` element.
     *
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR
        );
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function config(string $key, mixed $default = null): mixed
    {
        if (! ApplicationContext::hasContainer()) {
            return $default;
        }

        return ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('hyperf-admin.' . $key, $default);
    }
}
