<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Constants\Consts;
use Hyperf\Context\Context;
use Hyperf\Contract\Jsonable;
use JsonSerializable;

/**
 * 控制器统一的返回信封。
 *
 * 这是**唯一**一种「返回数据/消息/动作」的写法：
 *
 *     return Reply::success('新增成功')->redirect('/user');
 *     return Reply::success('已保存')->refresh()->with(['id' => 3]);
 *     return Reply::error('标识已被占用', 422);
 *     return Reply::success()->download('/exports/user.csv');
 *
 * 之所以能直接 `return` 对象：Hyperf 的 `transferToResponse()` 对
 * `Hyperf\Contract\Jsonable` 的实现会按 `application/json` 输出。
 *
 * **唯一的另一条出口**是 PSR Response —— 只用于四件本质不同的事：
 * 渲染 HTML 外壳（`page()`）、下载文件（`csvDownload()`）、
 * 强制 HTTP 状态码（`validationError()` 的 422）、错误页（403/404/419）。
 * 因为「信封」是一份 JSON，而这四者连内容类型都不一样。
 *
 * 历史：本类由 `Helper\ActionResponse` 演进而来（那个类保留为别名，已弃用）。
 */
final class Reply implements Jsonable, JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $then
     */
    private function __construct(
        private array $data,
        private int $code,
        private string $msg,
        private array $then = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    /**
     * @param array<string, mixed>|null $data 允许 null：老代码里常见 `success($msg, null)`
     */
    public static function success(string $msg = 'success', ?array $data = null): self
    {
        // 成功就是 200：把可能残留的错误状态清掉
        Context::destroy(Consts::REPLY_STATUS_CONTEXT);

        return new self($data ?? [], 200, $msg);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function error(string $msg, int $code = 500, ?array $data = null): self
    {
        // 让 `ReplyStatusMiddleware` 把业务码同步成 HTTP 状态码
        $status = self::httpStatus($code);

        if ($status !== null) {
            Context::set(Consts::REPLY_STATUS_CONTEXT, $status);
        }

        return new self($data ?? [], $code, $msg);
    }

    /**
     * 业务码 → HTTP 状态码；算不出来返回 null（保持 200）。
     *
     * `42200 → 422`、`40400 → 404`、`50000 → 500`；已经是 400~599 的直接用；
     * 419 在 Swoole 里没有 reason phrase，降级成 403。
     */
    private static function httpStatus(int $code): ?int
    {
        if ($code >= 400 && $code <= 599) {
            return $code === 419 ? 403 : $code;
        }

        if ($code < 40000 || $code >= 60000) {
            return null;
        }

        $status = intdiv($code, 100);

        if ($status < 400 || $status > 599) {
            return null;
        }

        return $status === 419 ? 403 : $status;
    }

    public function isOk(): bool
    {
        return $this->code === 200;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function warning(string $msg, ?array $data = null): self
    {
        return (new self($data ?? [], 200, $msg))->alert('warning');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function info(string $msg, ?array $data = null): self
    {
        return (new self($data ?? [], 200, $msg))->alert('info');
    }

    /**
     * 只刷新当前列表（不整页跳转）。
     */
    public function refresh(): self
    {
        $this->then['refresh'] = true;

        return $this;
    }

    /**
     * 跳到另一个地址（SPA 内部导航）。
     *
     * 同时写一份 `data.jumpUrl`：老的前端/调用方还在读它，双写能平滑过渡。
     */
    public function redirect(string $url): self
    {
        $this->then['redirect'] = $url;
        $this->data['jumpUrl'] = $url;

        return $this;
    }

    /**
     * 别名（从 dcat 那套 API 迁过来时不用改）。
     */
    public function location(string $url): self
    {
        return $this->redirect($url);
    }

    /**
     * 触发浏览器下载。
     */
    public function download(string $url): self
    {
        $this->then['download'] = $url;

        return $this;
    }

    /**
     * 提示级别：`success` / `info` / `warning` / `error`。
     */
    public function alert(string $level): self
    {
        $this->then['alert'] = $level;

        return $this;
    }

    /**
     * 追加数据。
     *
     * @param array<string, mixed> $data
     */
    public function with(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    public function code(): int
    {
        return $this->code;
    }

    /**
     * @return array{data: array<string, mixed>|object, code: int, msg: string, then?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $payload = [
            // 空负载保持 `{}`：`json_encode([])` 会输出 `[]`，前端按对象访问会踩空
            'data' => $this->data === [] ? new \stdClass() : $this->data,
            'code' => $this->code,
            'msg' => $this->msg,
        ];

        if ($this->then !== []) {
            $payload['then'] = $this->then;
        }

        return $payload;
    }

    /**
     * 交给 `json_encode()` 时用什么结构（`->json($reply)` 这条路径）。
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Hyperf 依赖它把对象当 JSON 输出。
     */
    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
