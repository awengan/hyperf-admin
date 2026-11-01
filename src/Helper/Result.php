<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Awengan\HyperfAdmin\Constants\ErrorCode;
use Awengan\HyperfAdmin\Reply;

/**
 * @deprecated 用 {@see Reply} 替代。
 *
 * 保留这个门面是为了**不打断已有调用点**：它的参数顺序还是老的
 * （`success($data, $msg)` / `error($code, $msg)`），内部换序后返回 `Reply`。
 *
 * 也就是说：无论写 `Result::success(...)` 还是 `Reply::success(...)`，
 * 控制器返回的**类型都是 `Reply`** —— 这就是「统一返回形状」的落点。
 */
final class Result
{
    public static function result(mixed $data = null, int $code = 200, string $msg = 'success'): Reply
    {
        $payload = is_array($data) ? $data : ($data === null ? [] : ['value' => $data]);

        if ($code === 200) {
            return Reply::success($msg, $payload);
        }

        return Reply::error($msg, $code, $payload);
    }

    public static function success(mixed $data = null, string $msg = 'success'): Reply
    {
        return self::result($data, 200, $msg);
    }

    /**
     * 参数顺序与 Element UI 那版保持一致，老调用点不用改。
     */
    public static function error(int $code = ErrorCode::COMMON_ERROR, string $msg = 'error', mixed $data = null): Reply
    {
        return self::result($data, $code, $msg);
    }

    public static function unauthorized(string $msg = 'Unauthenticated'): Reply
    {
        return self::error(ErrorCode::UNAUTHORIZED, $msg);
    }

    /**
     * @param array<string, mixed>|Reply $payload
     */
    public static function isOk(array|Reply $payload): bool
    {
        $code = $payload instanceof Reply ? $payload->code() : (int) ($payload['code'] ?? 0);

        return $code === 200;
    }
}
