<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Awengan\HyperfAdmin\Model\Menu;
use Awengan\HyperfAdmin\Model\Permission;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Throwable;

/**
 * Menu source for the sidebar / `n-menu`.
 *
 * A menu node is `['title' => string, 'url' => string, 'icon' => string,
 * 'child' => array, 'permission' => string]`. `icon` is a logical name resolved by
 * the frontend (`home`, `table`, `document`, `settings`, ...); legacy `el-icon-*`
 * names are mapped for backwards compatibility.
 *
 * `permission` 可选：设置后，没有该权限的用户看不到这一项（超管恒可见）。
 * 只是**显示层**过滤，真正的拦截在控制器里（`AbstractAdminController::guard()`），
 * 因为手输 URL 也必须挡住。
 */
final class MenuHelper
{
    /**
     * 同一次请求里只查一次库（外壳 HTML 会同时用到菜单与权限过滤）。
     */
    private const DB_MENU_CONTEXT = 'hyperf-admin.menus.db';

    public static function getMenu(): array
    {
        $menus = self::rawMenu();

        return self::filterByPermission($menus);
    }

    private static function rawMenu(): array
    {
        if (ApplicationContext::hasContainer()) {
            $configured = ApplicationContext::getContainer()
                ->get(ConfigInterface::class)
                ->get('hyperf-admin.menus');

            if (is_array($configured) && $configured !== []) {
                return $configured;
            }
        }

        // 配置留空时，用「菜单管理」那张表（`admin_menu`）——否则后台里改菜单没有任何效果
        $fromDb = self::dbMenu();

        if ($fromDb !== []) {
            return $fromDb;
        }

        return self::defaultMenu();
    }

    /**
     * 从 `admin_menu` 表生成侧边栏菜单。
     *
     * 优先级：`config('hyperf-admin.menus')` > 这张表 > 包内默认。
     * 表不存在 / 连不上 / 没数据时返回 `[]`，由调用方回退到代码默认，
     * 所以「不想用数据库菜单」的宿主不受影响。
     *
     * @return array<int, array<string, mixed>>
     */
    private static function dbMenu(): array
    {
        try {
            if (! ApplicationContext::hasContainer()) {
                return [];
            }

            // 没配数据库（单元测试 / 纯配置环境）就别去连了
            $container = ApplicationContext::getContainer();

            if (! $container->has(ConfigInterface::class) || ! is_array($container->get(ConfigInterface::class)->get('databases'))) {
                return [];
            }

            $cached = \Hyperf\Context\Context::get(self::DB_MENU_CONTEXT);

            if (is_array($cached)) {
                return $cached;
            }

            $rows = Menu::query()
                ->orderBy('parent_id')
                ->orderBy('order')
                ->orderBy('id')
                ->get();

            if ($rows === null || $rows->isEmpty()) {
                \Hyperf\Context\Context::set(self::DB_MENU_CONTEXT, []);

                return [];
            }

            // 权限存的是 id 数组，展示层要的是 slug
            $slugs = self::permissionSlugMap();

            $grouped = [];

            foreach ($rows as $row) {
                $grouped[(int) $row->parent_id][] = $row;
            }

            $build = static function (int $parentId) use (&$build, $grouped, $slugs): array {
                $nodes = [];

                foreach ($grouped[$parentId] ?? [] as $row) {
                    $children = $build((int) $row->id);
                    // 分组（is_menu=0）但没有可见子项 → 不显示
                    if ((int) $row->is_menu === 0 && $children === []) {
                        continue;
                    }

                    $uri = trim((string) $row->uri);

                    $node = [
                        'title' => (string) $row->title,
                        // 分组不导航；叶子没有 uri 也不导航
                        'url' => $children === [] && $uri !== '' ? '/' . ltrim($uri, '/') : '',
                        'icon' => (string) $row->icon,
                        'child' => $children,
                    ];

                    $required = [];

                    foreach ((array) ($row->permission ?? []) as $permissionId) {
                        $slug = $slugs[(int) $permissionId] ?? '';

                        if ($slug !== '') {
                            $required[] = $slug;
                        }
                    }

                    if ($required !== []) {
                        // 数组语义 = 满足任意一个即可
                        $node['permission'] = $required;
                    }

                    $nodes[] = $node;
                }

                return $nodes;
            };

            $menu = $build(0);
            \Hyperf\Context\Context::set(self::DB_MENU_CONTEXT, $menu);

            return $menu;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string> 权限 id => slug
     */
    private static function permissionSlugMap(): array
    {
        try {
            $map = [];

            foreach (Permission::query()->get(['id', 'slug']) ?? [] as $row) {
                $map[(int) $row->id] = (string) $row->slug;
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 按当前用户权限裁剪菜单。
     *
     * 拿不到请求上下文（CLI / 单元测试 / 未登录的登录页）时原样返回 ——
     * 过滤只影响显示，不影响安全。
     *
     * @param array<int, array<string, mixed>> $menus
     * @return array<int, array<string, mixed>>
     */
    private static function filterByPermission(array $menus): array
    {
        try {
            if (! ApplicationContext::hasContainer() || Auth::user() === null || Auth::isSuperAdmin()) {
                return $menus;
            }
        } catch (Throwable) {
            return $menus;
        }

        return self::filterNodes($menus);
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private static function filterNodes(array $nodes): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $permission = $node['permission'] ?? null;

            // 单条 slug 或数组（数组 = 满足任意一个即可，来自菜单表的权限 id 列表）
            if (! self::hasPermission($permission)) {
                continue;
            }

            $children = isset($node['child']) && is_array($node['child']) ? $node['child'] : null;

            if ($children !== null && $children !== []) {
                $node['child'] = self::filterNodes($children);

                // 分组里的子项全被过滤掉时，整个分组也不显示
                if ($node['child'] === [] ) {
                    continue;
                }
            }

            $kept[] = $node;
        }

        return $kept;
    }

    /**
     * 没写 requirement 的菜单一律可见。
     *
     * @param mixed $permission `null` / `''` / `'user.manage'` / `['user.manage', 'log.view']`
     */
    private static function hasPermission(mixed $permission): bool
    {
        $required = is_array($permission) ? $permission : [$permission];
        $required = array_values(array_filter($required, static fn ($slug) => is_string($slug) && $slug !== ''));

        if ($required === []) {
            return true;
        }

        foreach ($required as $slug) {
            if (Auth::can($slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 内置菜单 —— 只有「系统管理」一组。
     *
     * 想要更多菜单：改 `config/autoload/hyperf-admin.php` 的 `menus`，
     * 或照着 `MenuController` 加一个模块（`Grid` + `Form` + 控制器）。
     */
    public static function defaultMenu(): array
    {
        return [
            [
                'title' => '系统管理',
                'url' => '',
                'icon' => 'settings',
                'child' => [
                    [
                        'title' => '用户管理',
                        'url' => '/user',
                        'icon' => 'user',
                        'child' => [],
                        'permission' => 'user.manage',
                    ],
                    [
                        'title' => '角色管理',
                        'url' => '/role',
                        'icon' => 'settings',
                        'child' => [],
                        'permission' => 'role.manage',
                    ],
                    [
                        'title' => '权限管理',
                        'url' => '/permission',
                        'icon' => 'document',
                        'child' => [],
                        'permission' => 'permission.manage',
                    ],
                    [
                        'title' => '菜单管理',
                        'url' => '/menu',
                        'icon' => 'menu',
                        'child' => [],
                        'permission' => 'menu.manage',
                    ],
                    [
                        'title' => '审计日志',
                        'url' => '/log',
                        'icon' => 'table',
                        'child' => [],
                        'permission' => 'log.view',
                    ],
                ],
            ],
        ];
    }

    /**
     * 菜单 URL 归一化：**所有来源**（配置 / 数据库 / 代码）都从这里出口。
     *
     * 规则：
     *   `grid`        → `/admin/grid`
     *   `/grid`       → `/admin/grid`
     *   `/admin/grid` → `/admin/grid`（幂等）
     *   `''`          → `''`（父级节点本身没有链接）
     *   外链 http(s)  → 原样
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $prefix = '/' . trim((string) (self::config('route.prefix', 'admin')), '/');

        // 去掉可能已有的前缀，再统一补一次（保证幂等）
        if ($prefix !== '/' && str_starts_with($url, $prefix . '/')) {
            return $prefix . '/' . ltrim(substr($url, strlen($prefix)), '/');
        }

        return $prefix . '/' . ltrim($url, '/');
    }

    private static function config(string $key, mixed $default): mixed
    {
        try {
            return \Awengan\HyperfAdmin\Helper\Auth::config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
