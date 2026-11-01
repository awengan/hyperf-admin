<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Traits;

use Awengan\HyperfAdmin\Helper\Result;
use JsonSerializable;

/**
 * Base class of every page/field builder.
 *
 * Sub classes expose themselves as plain arrays: every non-hidden, non-null
 * property becomes part of the payload the Naive UI frontend consumes.
 */
abstract class AdminJsonBuilder implements JsonSerializable
{
    /**
     * Properties that must never be exposed to the frontend.
     *
     * @var array<int, string>
     */
    protected array $hideAttrs = [];

    /**
     * Guard so `beforeBuild()` side effects (queries, defaults) only run once
     * per payload, no matter how many times `getData()` is called.
     */
    protected bool $built = false;

    /**
     * @return array{data: mixed, code: int, msg: string}
     */
    public function jsonSerialize(): array
    {
        // 序列化器给数组：json_encode() 和单测都按数组消费
        return Result::result($this->getData())->toArray();
    }

    public function getData(): array
    {
        if (! $this->built) {
            $this->built = true;
            $this->beforeBuild();
        }

        $hidden = array_merge($this->hideAttrs, ['hideAttrs', 'built']);
        $data = [];

        foreach ($this as $key => $value) {
            if ($value === null || in_array($key, $hidden, true)) {
                continue;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * Payload only (no envelope) — used for the SPA bootstrap script.
     */
    public function coreJson(): string
    {
        return json_encode(
            $this->getData(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    public function __toString(): string
    {
        return json_encode(
            $this->jsonSerialize(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '';
    }

    /**
     * Hook running right before the payload is produced.
     */
    protected function beforeBuild(): void
    {
    }
}
