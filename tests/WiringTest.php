<?php

declare(strict_types=1);

/**
 * 组件装配测试：验证 ConfigProvider 输出、注解路由声明与配置默认值。
 *
 *   php tests/WiringTest.php
 *
 * 不需要 Swoole / 数据库 —— 用反射读取属性，等价于 DispatcherFactory 扫描注解后
 * 看到的内容，可以提前发现 `#[GetMapping]` 路径写错、控制器漏加中间件之类的问题。
 */

$packagePath = dirname(__DIR__);

$vendor = getenv('HYPERF_VENDOR') ?: null;

if ($vendor === null) {
    $directory = $packagePath;

    while (true) {
        if (is_file($directory . '/vendor/autoload.php')) {
            $vendor = $directory . '/vendor/autoload.php';
            break;
        }

        $parent = dirname($directory);

        if ($parent === $directory) {
            break;
        }

        $directory = $parent;
    }
}

if ($vendor === null || ! is_file($vendor)) {
    fwrite(STDERR, "找不到 Hyperf 的 vendor/autoload.php，请设置 HYPERF_VENDOR 环境变量。" . PHP_EOL);
    exit(1);
}

define('BASE_PATH', getenv('HYPERF_BASE_PATH') ?: dirname($vendor, 2));

require $vendor;

// 优先从**源码目录**加载组件类（prepend 到 composer 之前），避免测到 vendor 里的旧副本
spl_autoload_register(static function (string $class) use ($packagePath): void {
    $prefix = 'Awengan\HyperfAdmin\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = $packagePath . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
}, true, true);

use Awengan\HyperfAdmin\ConfigProvider;
use Awengan\HyperfAdmin\Controller\AdminController;
use Awengan\HyperfAdmin\Controller\AssetController;
use Awengan\HyperfAdmin\Controller\MenuController;
use Awengan\HyperfAdmin\Controller\PermissionController;
use Awengan\HyperfAdmin\Controller\RoleController;
use Awengan\HyperfAdmin\Controller\UserController;
use Awengan\HyperfAdmin\Controller\LogController;
use Awengan\HyperfAdmin\Middleware\AdminAuthMiddleware;
use Awengan\HyperfAdmin\Middleware\AuditLogMiddleware;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;

$passed = 0;
$failures = [];

function test(string $name, callable $fn): void
{
    global $passed, $failures;

    try {
        $fn();
        ++$passed;
    } catch (Throwable $e) {
        $failures[] = [$name, $e->getMessage()];
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $message === '' ? '' : $message . ': ',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue($value, string $message = ''): void
{
    if ($value !== true) {
        throw new RuntimeException($message === '' ? 'expected true' : $message);
    }
}

/**
 * 收集某个类的注解路由：`[method => [[verb, path], ...]]`。
 *
 * 这里**复刻** `DispatcherFactory::handleController()` 的拼法：
 * path 不以 `/` 开头时拼上 controller prefix，以 `/` 开头时**丢弃 prefix**。
 * 所以断言的是「最终注册到路由表里的路径」，前导斜杠写错会立刻暴露。
 *
 * @return array<int, array{verb: string, path: string}>
 */
function routesOf(string $class): array
{
    $routes = [];
    $mapping = [GetMapping::class => 'GET', PostMapping::class => 'POST'];

    $prefix = '';

    foreach ((new ReflectionClass($class))->getAttributes(Controller::class) as $attribute) {
        $prefix = (string) ($attribute->newInstance()->prefix ?? '');
    }

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getAttributes() as $attribute) {
            $name = $attribute->getName();

            if (! isset($mapping[$name])) {
                continue;
            }

            $path = (string) $attribute->newInstance()->path;

            // Hyperf 的规则：`''` 或相对路径 → 拼 prefix；以 `/` 开头 → prefix 作废
            if ($path === '') {
                $full = $prefix;
            } elseif ($path[0] !== '/') {
                $full = rtrim($prefix, '/') . '/' . $path;
            } else {
                $full = $path;
            }

            $routes[] = [
                'verb' => $mapping[$name],
                'path' => $full,
                'annotation_path' => $path,
                'method' => $method->getName(),
            ];
        }
    }

    return $routes;
}

/* ------------------------------------------------------- ConfigProvider --- */

test('ConfigProvider 注册了注解扫描路径', function () {
    $config = (new ConfigProvider())();

    assertTrue(isset($config['annotations']['scan']['paths']), 'annotations.scan.paths 缺失');

    // 组件可能被装进宿主 vendor，__DIR__ 会指向 vendor 副本，因此以
    // 实际加载到的 ConfigProvider 所在目录为基准，而不是包源码目录。
    $expected = str_replace('\\', '/', dirname((new ReflectionClass(ConfigProvider::class))->getFileName()));
    $paths = array_map(static fn ($p) => str_replace('\\', '/', $p), $config['annotations']['scan']['paths']);

    assertTrue(in_array($expected, $paths, true), '组件 src 目录不在扫描路径里，注解路由不会生效');

    // 扫描路径必须真的含有控制器，否则路径对了也没用
    assertTrue(
        is_file($expected . '/Controller/AdminController.php'),
        '扫描路径下没有 Controller/AdminController.php'
    );
});

test('ConfigProvider 注册的监听器与命令真实存在', function () {
    $config = (new ConfigProvider())();

    assertTrue(count($config['listeners']) > 0, 'listeners 为空');

    foreach ($config['listeners'] as $listener) {
        assertTrue(class_exists($listener), sprintf('监听器 %s 不存在', $listener));
        assertTrue(
            is_subclass_of($listener, Hyperf\Event\Contract\ListenerInterface::class),
            sprintf('%s 未实现 ListenerInterface', $listener)
        );
    }

    foreach ($config['commands'] as $command) {
        assertTrue(class_exists($command), sprintf('命令 %s 不存在', $command));
        assertTrue(
            is_subclass_of($command, Hyperf\Command\Command::class),
            sprintf('%s 未继承 Hyperf\Command\Command', $command)
        );
    }
});

test('命令名与文档一致', function () {
    $names = [];

    foreach ((new ConfigProvider())()['commands'] as $command) {
        $reflection = new ReflectionClass($command);
        $source = file_get_contents($reflection->getFileName());
        preg_match_all("/parent::__construct\('([^']+)'\)/", $source, $matches);
        $names = array_merge($names, $matches[1]);
    }

    sort($names);

    assertSame(['admin:install', 'admin:password', 'admin:revoke'], $names);
});

test('包内默认配置齐全', function () {
    // 直接读包内默认配置：ConfigProvider 在宿主已发布配置时不会再注入默认值
    $config = require dirname(__DIR__) . '/config/hyperf-admin.php';

    foreach (['title', 'api_base', 'login_path', 'locale', 'cdn', 'auth', 'menus', 'assets'] as $key) {
        assertTrue(array_key_exists($key, $config), sprintf('缺少配置项 %s', $key));
    }

    assertTrue(isset($config['cdn']['imports']['vue']), 'CDN 缺少 vue');
    assertTrue(isset($config['cdn']['imports']['naive-ui']), 'CDN 缺少 naive-ui');
    assertTrue(
        str_contains($config['cdn']['imports']['naive-ui'], 'external=vue'),
        'naive-ui 必须带 external=vue，否则会加载第二份 Vue'
    );
    assertSame(true, $config['auth']['enabled'], '鉴权默认必须开启');
    assertSame([1], $config['auth']['super_admin_ids'], '默认超级管理员应为 id=1');
});

test('RBAC 相关的模型与权限判断入口存在', function () {
    foreach ([
        \Awengan\HyperfAdmin\Model\Permission::class,
        \Awengan\HyperfAdmin\Model\Role::class,
        \Awengan\HyperfAdmin\Model\AdminUser::class,
    ] as $model) {
        assertTrue(class_exists($model), sprintf('模型 %s 不存在', $model));
    }

    // Auth 的权限入口（配置默认值里已断言 super_admin_ids）
    foreach (['can', 'isSuperAdmin', 'permissionSlugs', 'profile'] as $method) {
        assertTrue(
            method_exists(\Awengan\HyperfAdmin\Helper\Auth::class, $method),
            sprintf('Auth::%s() 缺失', $method)
        );
    }

    // 权限守卫在控制器基类上
    assertTrue(
        method_exists(\Awengan\HyperfAdmin\Controller\AbstractAdminController::class, 'guard'),
        'AbstractAdminController::guard() 缺失'
    );

    // Form 支持手动填充多对多字段
    assertTrue(method_exists(\Awengan\HyperfAdmin\Form::class, 'formData'), 'Form::formData() 缺失');
});

test('默认配置与宿主配置文件互斥（避免 array_merge_recursive 把标量变数组）', function () {
    $published = is_file(BASE_PATH . '/config/autoload/hyperf-admin.php');
    $injected = array_key_exists('hyperf-admin', (new ConfigProvider())());

    // Hyperf\Config\ConfigFactory 用 array_merge_recursive 合并 Provider 配置与
    // 应用配置，同名标量会被并成数组；两边只能有一边提供 hyperf-admin.*。
    assertSame(! $published, $injected, '默认配置与宿主配置文件同时存在会把标量合并成数组');

    if (! $published) {
        return;
    }

    // 宿主配置与包内默认值仍然必须是「标量对同名字符串键」的同一份结构，
    // 只是不再经过 ConfigProvider，避免回归。
    $hostConfig = require BASE_PATH . '/config/autoload/hyperf-admin.php';
    $packageConfig = require dirname(__DIR__) . '/config/hyperf-admin.php';

    assertSame(
        array_keys($packageConfig),
        array_keys($hostConfig),
        '发布出来的配置结构应与包内默认值一致'
    );
});

/* ----------------------------------------------------------- 注解路由 --- */

test('AdminController 声明了入口、登录与登出路由', function () {
    $routes = array_map(
        static fn (array $route) => $route['verb'] . ' ' . $route['path'],
        routesOf(AdminController::class)
    );

    sort($routes);

    assertSame([
        'GET /admin',
        'GET /admin/home',
        'GET /admin/login.html',
        'GET /admin/me',
        'GET /admin/profile',
        'POST /admin/login',
        'POST /admin/logout',
        'POST /admin/profile',
    ], $routes);
});

test('演示模块已整体下线', function () {
    assertTrue(
        ! is_file(dirname(__DIR__) . '/src/Controller/DemoController.php'),
        'DemoController 应已删除'
    );
    assertTrue(
        ! is_file(dirname(__DIR__) . '/src/Model/Test.php'),
        '演示模型 Model\Test 应已删除'
    );

    // 所有控制器的路由里都不该再出现演示页（/grid、/showcase、/area）
    foreach ([
        AdminController::class,
        MenuController::class,
        RoleController::class,
        PermissionController::class,
        UserController::class,
        LogController::class,
        \Awengan\HyperfAdmin\Controller\UploadController::class,
    ] as $class) {
        foreach (routesOf($class) as $route) {
            foreach (['/admin/grid', '/admin/showcase', '/admin/area'] as $gone) {
                assertTrue(
                    ! str_starts_with($route['path'], $gone),
                    sprintf('%s::%s() 仍暴露演示页 %s', $class, $route['method'], $route['path'])
                );
            }
        }
    }
});

test('MenuController 声明了列表 / 新增 / 编辑路由', function () {
    $routes = array_map(
        static fn (array $route) => $route['verb'] . ' ' . $route['path'],
        routesOf(MenuController::class)
    );

    sort($routes);

    assertSame([
        'GET /admin/menu',
        'GET /admin/menu/create',
        'GET /admin/menu/export',
        'GET /admin/menu/options',
        'GET /admin/menu/{id:\d+}/action-form',
        'GET /admin/menu/{id:\d+}/edit',
        'POST /admin/menu/batch-delete',
        'POST /admin/menu/store',
        'POST /admin/menu/{id:\d+}',
        'POST /admin/menu/{id:\d+}/action',
        'POST /admin/menu/{id:\d+}/delete',
        'POST /admin/menu/{id:\d+}/move',
        'POST /admin/menu/{id:\d+}/toggle',
    ], $routes);
});

test('RoleController 声明了角色 CRUD 路由', function () {
    $routes = array_map(
        static fn (array $route) => $route['verb'] . ' ' . $route['path'],
        routesOf(RoleController::class)
    );

    sort($routes);

    assertSame([
        'GET /admin/role',
        'GET /admin/role/create',
        'GET /admin/role/export',
        'GET /admin/role/{id:\d+}/action-form',
        'GET /admin/role/{id:\d+}/edit',
        'POST /admin/role/batch-delete',
        'POST /admin/role/store',
        'POST /admin/role/{id:\d+}',
        'POST /admin/role/{id:\d+}/delete',
        'POST /admin/role/{id:\d+}/move',
    ], $routes);
});

test('PermissionController 声明了权限 CRUD 路由', function () {
    $routes = array_map(
        static fn (array $route) => $route['verb'] . ' ' . $route['path'],
        routesOf(PermissionController::class)
    );

    sort($routes);

    assertSame([
        'GET /admin/permission',
        'GET /admin/permission/create',
        'GET /admin/permission/export',
        'GET /admin/permission/{id:\d+}/action-form',
        'GET /admin/permission/{id:\d+}/edit',
        'POST /admin/permission/batch-delete',
        'POST /admin/permission/store',
        'POST /admin/permission/{id:\d+}',
        'POST /admin/permission/{id:\d+}/delete',
        'POST /admin/permission/{id:\d+}/move',
    ], $routes);

    // 用户 / 日志模块
    $userRoutes = array_map(
        static fn (array $route) => $route['verb'] . ' ' . $route['path'],
        routesOf(UserController::class)
    );
    sort($userRoutes);
    assertSame([
        'GET /admin/user',
        'GET /admin/user/create',
        'GET /admin/user/export',
        'GET /admin/user/{id:\d+}',
        'GET /admin/user/{id:\d+}/action-form',
        'GET /admin/user/{id:\d+}/edit',
        'POST /admin/user/batch-delete',
        'POST /admin/user/store',
        'POST /admin/user/{id:\d+}',
        'POST /admin/user/{id:\d+}/action',
        'POST /admin/user/{id:\d+}/delete',
        'POST /admin/user/{id:\d+}/move',
    ], $userRoutes);

    $logRoutes = array_map(
        static fn (array $route) => $route['verb'] . ' ' . $route['path'],
        routesOf(LogController::class)
    );
    sort($logRoutes);
    assertSame(['GET /admin/log', 'GET /admin/log/export'], $logRoutes);
});

test('注解路径不能带前导斜杠（否则 #[Controller(prefix)] 会被丢弃）', function () {
    foreach ([
        AdminController::class,
        MenuController::class,
        RoleController::class,
        PermissionController::class,
        UserController::class,
        LogController::class,
        \Awengan\HyperfAdmin\Controller\UploadController::class,
    ] as $class) {
        foreach (routesOf($class) as $route) {
            assertTrue(
                $route['annotation_path'] === '' || $route['annotation_path'][0] !== '/',
                sprintf(
                    '%s::%s() 的 path "%s" 以 / 开头，Hyperf 会丢掉 prefix，最终变成 %s',
                    $class,
                    $route['method'],
                    $route['annotation_path'],
                    $route['path']
                )
            );

            assertTrue(
                str_starts_with($route['path'], '/admin/') || $route['path'] === '/admin',
                sprintf('%s::%s() 最终路径 %s 不在 /admin 下', $class, $route['method'], $route['path'])
            );
        }
    }
});

test('AssetController 兜底 /static 请求', function () {
    $routes = routesOf(AssetController::class);

    assertSame(1, count($routes));
    assertSame('/static/{path:.+}', $routes[0]['path']);
    assertSame('serve', $routes[0]['method']);
});

test('受保护的控制器都挂了鉴权中间件', function () {
    foreach ([AdminController::class, MenuController::class, RoleController::class, PermissionController::class, UserController::class, LogController::class] as $class) {
        $attributes = (new ReflectionClass($class))->getAttributes(Middleware::class);
        $names = array_map(
            static fn (ReflectionAttribute $attribute) => (string) $attribute->newInstance()->middleware,
            $attributes
        );

        assertTrue(in_array(AdminAuthMiddleware::class, $names, true), sprintf('%s 没有挂 AdminAuthMiddleware', $class));

        // 写操作的控制器还要挂审计中间件（LogController 只读，不需要）
        if ($class !== LogController::class) {
            assertTrue(in_array(AuditLogMiddleware::class, $names, true), sprintf('%s 没有挂 AuditLogMiddleware', $class));
        }
    }
});

test('资源控制器保持公开（登录页也要能加载 CSS/JS）', function () {
    $attributes = (new ReflectionClass(AssetController::class))->getAttributes(Middleware::class);

    assertSame(0, count($attributes), 'AssetController 不应加鉴权中间件');
});

test('返回信封的控制器都挂了 ReplyStatusMiddleware', function () {
    // 信封里的 code 要能同步成 HTTP 状态码，否则 curl / Postman 看到全是 200
    foreach ([
        AdminController::class,
        MenuController::class,
        RoleController::class,
        PermissionController::class,
        UserController::class,
        LogController::class,
        \Awengan\HyperfAdmin\Controller\UploadController::class,
    ] as $class) {
        $names = array_map(
            static fn (ReflectionAttribute $attribute) => (string) $attribute->newInstance()->middleware,
            (new ReflectionClass($class))->getAttributes(Middleware::class)
        );

        assertTrue(
            in_array(\Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware::class, $names, true),
            sprintf('%s 没有挂 ReplyStatusMiddleware', $class)
        );
    }

    // 静态资源控制器不返回信封，不需要
    $assetNames = array_map(
        static fn (ReflectionAttribute $attribute) => (string) $attribute->newInstance()->middleware,
        (new ReflectionClass(AssetController::class))->getAttributes(Middleware::class)
    );

    assertTrue(
        ! in_array(\Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware::class, $assetNames, true),
        'AssetController 不该挂 ReplyStatusMiddleware'
    );

    assertTrue(
        is_subclass_of(
            \Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware::class,
            \Psr\Http\Server\MiddlewareInterface::class
        ),
        'ReplyStatusMiddleware 必须实现 MiddlewareInterface'
    );
});

test('所有控制器都带 #[Controller] 注解', function () {
    foreach ([AdminController::class, AssetController::class, MenuController::class] as $class) {
        $attributes = (new ReflectionClass($class))->getAttributes(Controller::class);

        assertTrue(count($attributes) === 1, sprintf('%s 缺少 #[Controller]', $class));
    }
});

/* ------------------------------------------------------------- 资源路径 --- */

test('组件自带前端资源与 SQL', function () use ($packagePath) {
    foreach ([
        '/resources/static/js/app.js',
        '/resources/static/css/app.css',
        '/resources/static/js/fields/index.js',
        '/database/admin.sql',
        '/config/hyperf-admin.php',
    ] as $file) {
        assertTrue(is_file($packagePath . $file), sprintf('缺少文件 %s', $file));
    }
});

test('应用内没有残留旧命名空间', function () use ($packagePath) {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packagePath . '/src'));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        // 只看 namespace / use 声明：文档注释里提到 `App\Admin\Dashboard`
        // （首页接管用的默认类名）是正常的，不算残留。
        if (preg_match('/^\s*(?:namespace|use)\s+App\\\\Admin\b/m', $contents) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    assertSame([], $offenders, '仍有文件使用 App\\Admin 命名空间');
});

/* --------------------------------------------------------------- report --- */

echo PHP_EOL;

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " test(s) FAILED:\n\n");

    foreach ($failures as [$name, $message]) {
        fwrite(STDERR, "  x {$name}\n    {$message}\n");
    }

    exit(1);
}

echo "OK {$passed} wiring tests passed" . PHP_EOL;
