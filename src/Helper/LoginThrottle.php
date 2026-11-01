<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Hyperf\DbConnection\Db;
use Throwable;

/**
 * 登录失败限流。
 *
 * 按「用户名 + IP」计数：
 *  - 窗口内（默认 300s）失败达到 `max_attempts`（默认 5）次
 *  - 锁定 `lockout`（默认 300s），期间**即使密码正确也拒绝**
 *  - 登录成功后清零
 *
 * 为什么不用 `hyperf/rate-limit`：那套是给接口限流用的滑动窗口，
 * 这里要的是「针对某个账号的失败锁定」，而且组件刻意不引入额外依赖。
 *
 * 设计上刻意做的取舍：只按 username+IP 组合计数（分布式撞库仍需靠网关/WAF），
 * 计数表独立于审计日志，失败不会因为日志表写失败而绕过限流。
 */
final class LoginThrottle
{
    /**
     * 是否被锁定；锁定返回提示文案，未锁定返回 null。
     *
     * @param array{max_attempts?: int, window?: int, lockout?: int} $config
     */
    public static function lockedMessage(string $username, string $ip, array $config): ?string
    {
        $max = (int) ($config['max_attempts'] ?? 5);

        if ($max <= 0) {
            return null;   // 关闭限流
        }

        try {
            $row = self::find($username, $ip);

            if ($row === null || ($row['locked_until'] ?? null) === null) {
                return null;
            }

            $until = strtotime((string) $row['locked_until']);

            if ($until === false || $until <= time()) {
                return null;
            }

            $left = $until - time();

            return sprintf('登录失败次数过多，请 %d 秒后再试', $left);
        } catch (Throwable) {
            // 表没建 / DB 抖动：不因为限流组件故障挡住正常登录
            return null;
        }
    }

    /**
     * 记一次失败。
     *
     * @param array{max_attempts?: int, window?: int, lockout?: int} $config
     */
    public static function hit(string $username, string $ip, array $config): void
    {
        $max = (int) ($config['max_attempts'] ?? 5);

        if ($max <= 0) {
            return;
        }

        $window = max(1, (int) ($config['window'] ?? 300));
        $lockout = max(1, (int) ($config['lockout'] ?? 300));

        try {
            $now = time();
            $row = self::find($username, $ip);

            if ($row === null) {
                Db::table('admin_login_attempts')->insert([
                    'username' => $username,
                    'ip' => $ip,
                    'attempts' => 1,
                    'window_started_at' => date('Y-m-d H:i:s', $now),
                ]);

                return;
            }

            $windowStart = ($row['window_started_at'] ?? null) !== null ? (int) strtotime((string) $row['window_started_at']) : 0;
            $attempts = (int) ($row['attempts'] ?? 0);

            // 窗口过期 → 重新开始计数
            if ($windowStart === 0 || $now - $windowStart > $window) {
                Db::table('admin_login_attempts')
                    ->where('id', $row['id'])
                    ->update([
                        'attempts' => 1,
                        'window_started_at' => date('Y-m-d H:i:s', $now),
                        'locked_until' => null,
                    ]);

                return;
            }

            ++$attempts;

            Db::table('admin_login_attempts')
                ->where('id', $row['id'])
                ->update([
                    'attempts' => $attempts,
                    'locked_until' => $attempts >= $max ? date('Y-m-d H:i:s', $now + $lockout) : null,
                ]);
        } catch (Throwable) {
            // 同上：限流失败不影响登录主流程
        }
    }

    /**
     * 登录成功：清零。
     */
    public static function clear(string $username, string $ip): void
    {
        try {
            Db::table('admin_login_attempts')
                ->where('username', $username)
                ->where('ip', $ip)
                ->delete();
        } catch (Throwable) {
        }
    }

    /**
     * 剩余尝试次数（用于前端提示，可选）。
     *
     * @param array{max_attempts?: int, window?: int, lockout?: int} $config
     */
    public static function remaining(string $username, string $ip, array $config): int
    {
        $max = (int) ($config['max_attempts'] ?? 5);

        try {
            $row = self::find($username, $ip);
        } catch (Throwable) {
            return $max;
        }

        if ($row === null) {
            return $max;
        }

        return max(0, $max - (int) ($row['attempts'] ?? 0));
    }

    /**
     * 取一行（Hyperf 的 `first()` 返回 stdClass，统一转成数组再用）。
     *
     * @return array<string, mixed>|null
     */
    private static function find(string $username, string $ip): ?array
    {
        $row = Db::table('admin_login_attempts')
            ->where('username', $username)
            ->where('ip', $ip)
            ->first();

        return $row === null ? null : (array) $row;
    }
}
