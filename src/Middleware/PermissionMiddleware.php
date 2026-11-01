<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Middleware;

use Awengan\HyperfAdmin\Helper\Auth;
use Awengan\HyperfAdmin\Helper\Forbidden;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 权限守卫（默认拦截，而不是让每个 action 手写 `guard()`）。
 *
 * 按**路径前缀**匹配权限标识，最长前缀优先：
 *
 *   /admin/user      → user.manage
 *   /admin/role      → role.manage
 *   /admin/permission→ permission.manage
 *   /admin/menu      → menu.manage
 *   /admin/log       → log.view
 *
 * 映射可以用 `hyperf-admin.permission_map` 覆盖/补充（同样是最长前缀）。
 * 没匹配到的路径不拦（宿主自己加的页面默认是放行的）。
 *
 * 为什么不做成 `#[RequirePermission]` 注解：注解需要自定义注解收集器去和
 * 路由表关联，而 Hyperf 的路由收集由 DispatcherFactory 独占；用中间件 +
 * 映射表能用官方扩展点、可测试、也不依赖宿主改配置。
 */
class PermissionMiddleware implements MiddlewareInterface
{
    /**
     * 内置映射（宿主可用 `permission_map` 覆盖）。
     */
    private const DEFAULT_MAP = [
        '/admin/user' => 'user.manage',
        '/admin/role' => 'role.manage',
        '/admin/permission' => 'permission.manage',
        '/admin/menu' => 'menu.manage',
        '/admin/log' => 'log.view',
    ];

    public function __construct(
        private RequestInterface $request,
        private ResponseInterface $response
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        // 未登录时交给 AdminAuthMiddleware 处理（401 / 302）：
        // 本中间件只管「已登录的人有没有权限」，这样也不依赖中间件声明顺序。
        if (Auth::user($this->request) === null) {
            return $handler->handle($request);
        }

        $permission = $this->resolve($request->getUri()->getPath());

        if ($permission === null || Auth::can($permission, $this->request)) {
            return $handler->handle($request);
        }

        return Forbidden::response($this->request, $this->response, $permission);
    }

    /**
     * 最长前缀匹配；只匹配到路径分隔符边界（`/user` 不会命中 `/users`）。
     */
    public function resolve(string $path): ?string
    {
        $map = self::DEFAULT_MAP;
        $custom = Auth::config('permission_map', []);

        if (is_array($custom) && $custom !== []) {
            $map = array_merge($map, $custom);
        }

        $matched = null;
        $matchedLength = -1;

        foreach ($map as $prefix => $permission) {
            if (! is_string($prefix) || ! is_string($permission) || $prefix === '') {
                continue;
            }

            $prefix = '/' . trim($prefix, '/');

            if ($path !== $prefix && ! str_starts_with($path, $prefix . '/')) {
                continue;
            }

            if (strlen($prefix) > $matchedLength) {
                $matched = $permission;
                $matchedLength = strlen($prefix);
            }
        }

        return $matched;
    }
}
