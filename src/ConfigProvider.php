<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin;

use Awengan\HyperfAdmin\Command\InstallCommand;
use Awengan\HyperfAdmin\Command\PasswordCommand;
use Awengan\HyperfAdmin\Command\RevokeCommand;
use Awengan\HyperfAdmin\Listener\BootListener;

/**
 * Hyperf 组件注册入口。
 *
 * `composer.json` 里的 `extra.hyperf.config` 指向本类，Hyperf 启动时会把
 * `__invoke()` 的返回值合并进应用配置。
 *
 * 为什么要 `annotations.scan.paths`：`Hyperf\HttpServer\Router\DispatcherFactory`
 * 只从「注解收集器 + 应用自己的 config/routes.php」构建路由，而宿主默认只扫描
 * `BASE_PATH/app`。把 `src/` 加进扫描路径后，本包控制器上的 `#[Controller]`
 * `#[GetMapping]` `#[Middleware]` 才会生效 —— 这是组件声明路由的标准做法，
 * 宿主不需要改 `config/routes.php`。
 */
class ConfigProvider
{
    public function __invoke(): array
    {
        $config = [
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'listeners' => [
                BootListener::class,
            ],
            'commands' => [
                InstallCommand::class,
                PasswordCommand::class,
                RevokeCommand::class,
            ],
        ];

        // 重要：默认配置与宿主配置文件**只能有一个来源**。
        //
        // `Hyperf\Config\ConfigFactory` 用 `array_merge_recursive()` 合并
        // ProviderConfig 与应用配置，而同名**字符串键的标量会被合并成数组**
        // （`['title' => 'A']` + `['title' => 'B']` => `['title' => ['A', 'B']]`）。
        // 如果 Provider 和 `config/autoload/hyperf-admin.php` 同时提供默认值，
        // 那么 `title`、`auth.ttl`、`cdn.imports` 等全部会变成数组，
        // import map 和资源路径随之失效。
        //
        // 所以：宿主已经发布了配置文件时（admin:install 会发布），这里不再注入默认值；
        // 未发布时由本 Provider 兜底，组件开箱即用。
        // 所有读取处都带默认值（见 Assets/Auth/UI 的 config() 调用），部分键缺失也能工作。
        if (! is_file(BASE_PATH . '/config/autoload/hyperf-admin.php')) {
            $config['hyperf-admin'] = require dirname(__DIR__) . '/config/hyperf-admin.php';
        }

        return $config;
    }
}
