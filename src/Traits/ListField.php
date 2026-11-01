<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Traits;

use Closure;

/**
 * Shared option handling for every "list of choices" field
 * (select / radio / checkbox / transfer).
 *
 * Options are always normalised to `[['key' => ..., 'value' => ...], ...]`,
 * which the frontend maps onto Naive UI's `{ label, value }` shape.
 */
trait ListField
{
    /**
     * @var array<int, array{key: mixed, value: mixed}>
     */
    protected array $options = [];

    /**
     * @param array|Closure $options either `[key => label]`, a list of
     *                               `['key' => .., 'value' => ..]` pairs, or a
     *                               closure returning one of those
     */
    public function options(array|Closure $options): static
    {
        if ($options instanceof Closure) {
            $options = $options();
        }

        if (! is_array($options)) {
            return $this;
        }

        // Already normalised (`[['key' => .., 'value' => ..], ...]`).
        if (array_is_list($options) && isset($options[0]) && is_array($options[0])
            && array_key_exists('key', $options[0])) {
            $this->options = array_values(array_map(
                static fn (array $item): array => [
                    'key' => $item['key'],
                    'value' => $item['value'] ?? $item['key'],
                ],
                $options
            ));

            return $this;
        }

        // `[1 => '男', 2 => '女']` => the array key is the option key.
        // A plain list `['北京', '长沙']` therefore yields the keys 0 and 1,
        // which is what the original Element UI builder produced too.
        $this->options = array_map(
            static fn ($key, $value): array => ['key' => $key, 'value' => $value],
            array_keys($options),
            array_values($options)
        );

        return $this;
    }

    /**
     * Raw list of selectable keys (used to validate `disabled()` / `border()`).
     *
     * @return array<int, mixed>
     */
    protected function getOptionsList(): array
    {
        return array_map(static fn (array $item) => $item['key'], $this->options);
    }

    /**
     * Keep only the given keys that actually exist in the option list.
     *
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    protected function onlyKnownOptions(array $values): array
    {
        $known = $this->getOptionsList();

        return array_values(array_filter(
            $values,
            static fn ($value): bool => in_array($value, $known, true)
        ));
    }
}
