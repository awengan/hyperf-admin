<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/**
 * Hyperf Admin (Naive UI) 默认配置。
 *
 * 用 `php bin/hyperf.php admin:install` 发布到宿主的
 * `config/autoload/hyperf-admin.php` 后按需修改（同名标量以宿主为准，
 * 数组会与组件默认值递归合并）。
 */
return [
    /* ------------------------------------------------------------------ 外观 */

    'title' => env('ADMIN_TITLE', 'Hyperf Admin'),

    /**
     * SPA 所有 XHR 的前缀。面板默认占用根路径，保持空字符串即可。
     */
    'api_base' => env('ADMIN_API_BASE', '/admin'),

    /**
     * 登录页与登录后的首页（**都写完整路径**，要和上面的 `api_base` 对上）。
     */
    'login_path' => '/admin/login.html',
    'home_path' => '/admin/home',

    /**
     * Naive UI 语言：`zh-CN` 或 `en-US`。
     */
    'locale' => env('ADMIN_LOCALE', 'zh-CN'),

    /**
     * 透传给 `n-config-provider :theme-overrides`，例如
     * `['common' => ['primaryColor' => '#18a058']]`。
     */
    'theme' => [],

    /* ------------------------------------------------------------------- CDN */

    /**
     * 注入 HTML 的 import map。
     *
     * `naive-ui` 必须带 `?external=vue`，否则 esm.sh 会给它打包一份独立的 Vue，
     * 导致 `useMessage()` 等 composable 全部失效。
     */
    'cdn' => [
        'imports' => [
            'vue' => env('ADMIN_CDN_VUE', 'https://unpkg.com/vue@3.5.13/dist/vue.esm-browser.prod.js'),
            'naive-ui' => env('ADMIN_CDN_NAIVE_UI', 'https://esm.sh/naive-ui@2.45.3?external=vue'),
        ],
    ],

    /* ------------------------------------------------------------------ 鉴权 */

    'auth' => [
        /**
         * 关掉后所有面板路由变为公开，**仅限本地预览**。
         */
        'enabled' => (bool) env('ADMIN_AUTH_ENABLED', true),

        /**
         * 签名 Cookie 的 HMAC 密钥。留空时用 BASE_PATH 派生，生产环境务必显式设置。
         */
        'secret' => env('ADMIN_SECRET'),

        /**
         * Cookie 有效期（秒）。
         */
        'ttl' => (int) env('ADMIN_AUTH_TTL', 604800),

        /**
         * 超级管理员用户 id：跳过所有权限校验（`Auth::can()` 恒为 true）。
         */
        'super_admin_ids' => [1],

        'cookie_path' => '/',
        'same_site' => 'Lax',

        /**
         * 免登录路径（登录页与静态资源已在中间件里内置放行）。
         */
        'except' => ['/health', '/favicon.ico'],

        /**
         * 登录限流：窗口内失败 `max_attempts` 次就锁定 `lockout` 秒。
         *
         * 计数按「用户名 + IP」两个维度分别记；锁定期间即使密码正确也拒绝，
         * 避免被拿去撞库。设 `max_attempts => 0` 关闭。
         */
        'login_throttle' => [
            'max_attempts' => 5,
            'window' => 300,      // 秒：多久内的失败算一次「窗口」
            'lockout' => 300,     // 秒：触发后锁多久
        ],

        /**
         * 会话吊销：token 里带用户当前的「密码指纹」，
         * 改密码 / `admin:revoke` 之后旧 Cookie 立即失效（不用等 ttl 过期）。
         */
        'revoke_on_password_change' => true,
    ],

    /* ----------------------------------------------------------------- CSRF */

    /**
     * CSRF 防护（双提交 Cookie）：写请求必须带与 `ea_csrf` cookie 一致的
     * `X-CSRF-Token` 头。前端会从 boot payload 里拿到 token 自动带上。
     */
    'csrf' => [
        'enabled' => env('ADMIN_CSRF_ENABLED', true),
    ],

    /* ------------------------------------------------------- 安全响应头 */

    'security' => [
        'csp' => [
            /**
             * 关掉后不发 Content-Security-Policy（只留 X-Frame-Options 等）。
             * CSP 里的外部主机是从 `cdn.imports` 自动推导的。
             */
            'enabled' => env('ADMIN_CSP_ENABLED', true),
        ],
    ],

    /* -------------------------------------------------------- 权限路径映射 */
    /**
     * 路径前缀 => 权限标识（最长前缀优先），由 `PermissionMiddleware` 执行。
     *
     * 内置：/user /role /permission /menu /log。宿主自己的模块可以在这里补：
     *   'permission_map' => ['/order' => 'order.manage'],
     * 没匹配到的路径不拦（例如公开的演示页）。
     */
    'permission_map' => [],

    /* ------------------------------------------------------------------ 上传 */

    /**
     * 文件上传（`POST /upload`，落盘到 `public/{path}/Y/m/`）。
     *
     * 默认只允许图片，且**不含 svg**（svg 能内嵌脚本，等同 XSS）。
     */
    'upload' => [
        'path' => 'uploads',
        'max_size' => 2,                  // MB
        'image_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        'file_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt', 'csv'],
    ],

    /* ------------------------------------------------------------------ 菜单 */

    /**
     * 侧边栏菜单，留空使用内置菜单（只有「系统管理」一组）。
     *
     * 每项：`['title' => .., 'url' => .., 'icon' => .., 'child' => [...], 'permission' => '..']`
     * `icon` 取值：home | table | document | settings | menu | user
     * （兼容旧的 `el-icon-*` 名称）。`url` 写**不含 `/admin` 前缀的相对路径**
     * （如 `/user`），前缀由 `api_base` 统一加，写不写斜杠都可以。
     *
     * `permission` 可选：填了就按当前用户权限决定是否显示（超管恒显示）。
     * 这只是显示层过滤，接口拦截见控制器里的 `guard()`。
     */
    'menus' => [],

    /* ------------------------------------------------------------------ 资源 */

    'assets' => [
        /**
         * 每次启动把组件内的 `resources/` 同步到宿主 `public/`。
         * 只读文件系统或交给 CI 发布时设为 false。
         */
        'auto_publish' => (bool) env('ADMIN_ASSETS_AUTO_PUBLISH', true),

        /**
         * 静态资源根目录（宿主 public 下的相对路径）。
         */
        'public_path' => BASE_PATH . '/public',
    ],

    /* ------------------------------------------------------------------ 数据 */

    'database' => [
        'admin_users_table' => 'admin_users',
        'menu_table' => 'admin_menu',
    ],
];
