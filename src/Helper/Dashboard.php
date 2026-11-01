<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Awengan\HyperfAdmin\Content;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Throwable;

/**
 * 首页概览（dashboard）。
 *
 * 布局：**左右两栏并列**
 *
 *   左栏「概览」：KPI 卡片 + 运行状态
 *   右栏「特点」：6 条，对应这套实现真正的取舍
 *
 * 下方通栏：技术栈、最近操作。
 *
 * 所有取数都包了 try/catch：**数据库连不上时首页仍然要能打开**，
 * 否则「后台打不开 → 看不到哪里坏了」会变成死循环。
 */
final class Dashboard
{
    public static function make(): Content
    {
        return (new Content())
            ->title('概览')
            ->html(
                // 通栏：概况（KPI 卡片 + 运行状态）
                self::section('概况', self::cardsHtml() . self::envTable())
                // 两栏并列：技术栈 ｜ 特点
                . '<div class="ea-dash__cols">'
                . '<div class="ea-dash__col">' . self::section('技术栈', self::stackTable()) . '</div>'
                . '<div class="ea-dash__col">' . self::section('特点', self::highlights()) . '</div>'
                . '</div>'
                // 通栏：最近操作
                . self::section('最近操作', self::recentTable())
            );
    }

    /* ----------------------------------------------------------- 卡片 --- */

    private static function cardsHtml(): string
    {
        $html = '<div class="ea-dash__cards">';

        foreach (self::cards() as $card) {
            $html .= sprintf(
                '<div class="ea-dash__card"><div class="ea-card__label">%s</div>'
                . '<div class="ea-card__value">%s</div><div class="ea-card__hint">%s</div></div>',
                self::e($card['label']),
                self::e($card['value']),
                self::e($card['hint'])
            );
        }

        return $html . '</div>';
    }

    /**
     * @return array<int, array{label: string, value: string, hint: string}>
     */
    private static function cards(): array
    {
        return [
            ['label' => '用户', 'value' => self::count('admin_users'), 'hint' => '账号总数'],
            ['label' => '角色', 'value' => self::count('admin_roles'), 'hint' => '权限集合'],
            ['label' => '权限', 'value' => self::count('admin_permissions'), 'hint' => '路径前缀粒度'],
            ['label' => '菜单', 'value' => self::count('admin_menu'), 'hint' => '侧边栏条目'],
            ['label' => '审计日志', 'value' => self::count('admin_logs'), 'hint' => '今日 ' . self::countToday() . ' 条'],
        ];
    }

    /* ------------------------------------------------------- 运行状态 --- */

    private static function envTable(): string
    {
        $rows = [
            ['PHP', PHP_VERSION, '', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'warn'],
            ['Swoole', self::swooleVersion(), '', self::swooleVersion() === '-' ? 'warn' : 'ok'],
            ['MySQL', self::mysqlVersion(), '', self::mysqlVersion() === '-' ? 'warn' : 'ok'],
            ['前端资源', VendorAssets::enabled() ? '自托管' : 'CDN', '', 'info'],
            ['CSRF', self::config('csrf.enabled', true) ? '开启（双提交）' : '关闭', '', 'info'],
            ['CSP', self::config('security.csp.enabled', true) ? '开启（nonce）' : '关闭', '', 'info'],
            ['上传目录', self::uploadsWritable() ? '可写' : '不可写', '', self::uploadsWritable() ? 'ok' : 'warn'],
            ['资源版本', Assets::version(), '', 'info'],
        ];

        return self::table($rows);
    }

    private static function swooleVersion(): string
    {
        return extension_loaded('swoole') ? (string) phpversion('swoole') : '-';
    }

    private static function mysqlVersion(): string
    {
        return self::guard(static function (): string {
            $row = Db::selectOne('select version() as v');

            return is_object($row) ? (string) ($row->v ?? '-') : '-';
        }, '-');
    }

    private static function uploadsWritable(): bool
    {
        return self::guard(static function (): bool {
            $dir = rtrim(BASE_PATH, '/') . '/public/uploads';

            return is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
        }, false);
    }

    /* --------------------------------------------------------- 技术栈 --- */

    private static function stackTable(): string
    {
        $rows = [
            ['后端', 'Hyperf 3.1 · PHP 8.2 · Swoole', '注解路由 / 依赖注入 / 协程', 'info'],
            ['前端', 'Vue 3.5 · Naive UI 2.45', '浏览器原生 ESM + import map', 'info'],
            ['构建', '无', '零构建：没有 node_modules、没有打包', 'info'],
            ['存储', 'MySQL', '手写分页，不依赖 ORM 分页器', 'info'],
            ['认证', 'HMAC 签名 Cookie', '无会话（不装 hyperf/session）', 'info'],
            ['测试', '136 项 · 4 层', 'payload / wiring / 前端 / SSR', 'info'],
        ];

        return self::table($rows);
    }

    /* ------------------------------------------------------------ 特点 --- */

    private static function highlights(): string
    {
        // 和技术栈同一套格式：标签 | 主值 + 灰色说明
        $rows = [
            ['响应跟手', '切换不闪屏', '点菜单 / 翻页只换取几 KB JSON，页面骨架常驻，不重载整页；服务端 5~6 ms，点击后几乎无等待。首屏是一次性资源下载（gzip 后约 660 KB）', 'info'],
            ['零构建', '改一行 JS 直接生效', '不需要 npm / 打包 / 等编译，宿主项目不用引入前端工具链', 'info'],
            ['装得进别人的项目', '不动宿主配置', 'routes.php / middlewares.php / translation 都不用改，composer require + 一行 Provider', 'info'],
            ['Builder API', '四种页面 · 8 行 CRUD', '列表 / 表单 / 详情 / 内容；带筛选、排序、导出、批量删除、行操作弹层', 'info'],
            ['安全基线开箱', '6 项默认全开', 'CSRF 双提交、CSP nonce、登录限流、密码指纹吊销、审计日志、上传白名单', 'info'],
            ['测试真的会红', '136 项 · 4 层', 'SSR 拦截 Vue 告警 —— 组件没注册这类「浏览器里静默不渲染」的问题会被抓到', 'info'],
        ];

        return self::table($rows);
    }

    /* ------------------------------------------------------- 最近操作 --- */

    private static function recentTable(): string
    {
        $logs = self::guard(static fn (): array => Db::table('admin_logs')
            ->orderBy('id', 'desc')
            ->limit(6)
            ->get()
            ->toArray(), []);

        if ($logs === []) {
            return '<p class="ea-dash__muted">还没有操作记录。</p>';
        }

        $html = '<table class="ea-dash__table"><thead><tr>'
            . '<th>时间</th><th>操作人</th><th>动作</th><th>摘要</th>'
            . '</tr></thead><tbody>';

        foreach ($logs as $log) {
            $log = (array) $log;

            $html .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                self::e((string) ($log['created_at'] ?? '')),
                self::e((string) ($log['username'] ?? '-')),
                self::e((string) ($log['action'] ?? '-')),
                self::e((string) ($log['summary'] ?? $log['path'] ?? ''))
            );
        }

        return $html . '</tbody></table>';
    }

    /* ----------------------------------------------------------- 工具 --- */

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $rows
     */
    private static function table(array $rows): string
    {
        $html = '<table class="ea-dash__table"><tbody>';

        foreach ($rows as [$name, $value, $note, $state]) {
            $html .= sprintf(
                '<tr><th>%s</th><td><b>%s</b>%s</td><td class="ea-dash__state ea-dash__state--%s">%s</td></tr>',
                self::e($name),
                self::e($value),
                $note === '' ? '' : '<br><span class="ea-dash__muted">' . self::e($note) . '</span>',
                in_array($state, ['ok', 'warn'], true) ? $state : 'blank',
                self::e(match ($state) {
                    'ok' => '正常',
                    'warn' => '注意',
                    default => '',
                })
            );
        }

        return $html . '</tbody></table>';
    }

    private static function section(string $title, string $body): string
    {
        return sprintf(
            '<section class="ea-dash__section"><h3>%s</h3>%s</section>',
            self::e($title),
            $body
        );
    }

    private static function count(string $table): string
    {
        return self::guard(static fn (): string => (string) Db::table($table)->count(), '-');
    }

    private static function countToday(): string
    {
        return self::guard(static fn (): string => (string) Db::table('admin_logs')
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->count(), '-');
    }

    private static function config(string $key, mixed $default): mixed
    {
        return self::guard(static fn (): mixed => Auth::config($key, $default), $default);
    }

    /**
     * 任何取数失败都退回默认值：首页永远能打开。
     */
    private static function guard(callable $callback, mixed $fallback): mixed
    {
        if (! ApplicationContext::hasContainer()) {
            return $fallback;
        }

        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
