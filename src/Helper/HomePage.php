<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Awengan\HyperfAdmin\Content;
use Throwable;

/**
 * 首页解析：**优先用用户项目里的 `App\Admin\Dashboard`**，没有就退回包内默认。
 *
 * 用户改 `app/Admin/Dashboard.php` 即可完全接管首页；
 * 升级组件不会覆盖它（只有 `admin:install --force` 会重置）。
 */
final class HomePage
{
    public static function resolve(): Content
    {
        $class = (string) self::config('home_page', 'App\\Admin\\Dashboard');

        if ($class !== '' && class_exists($class)) {
            try {
                $page = (new $class())->make();

                if ($page instanceof Content) {
                    return $page;
                }
            } catch (Throwable) {
                // 用户代码报错时不要让首页白屏，退回默认页
            }
        }

        return Dashboard::make();
    }

    private static function config(string $key, mixed $default): mixed
    {
        try {
            return Auth::config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }
}