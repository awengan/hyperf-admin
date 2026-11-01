<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Show;

/**
 * 详情页的一个字段。
 *
 * 与 `UI\Grid\Column` 的展示器同名同形状（`displayer` + `displayer_options`），
 * 前端共用一套渲染函数。
 */
class Field
{
    public ?string $displayer = null;

    /**
     * @var array<string, mixed>
     */
    public array $displayerOptions = [];

    public function __construct(public string $field, public string $label = '')
    {
    }

    public function image(string $size = '80x80'): static
    {
        return $this->use('image', ['size' => $size]);
    }

    /**
     * @param array<int|string, string> $map
     */
    public function tag(array $map = [], string $type = 'default'): static
    {
        return $this->use('tag', ['map' => $map, 'type' => $type]);
    }

    public function bool(): static
    {
        return $this->use('bool');
    }

    public function link(bool $newTab = true): static
    {
        return $this->use('link', ['newTab' => $newTab]);
    }

    public function date(string $format = 'YYYY-MM-DD HH:mm:ss'): static
    {
        return $this->use('date', ['format' => $format]);
    }

    public function copyable(): static
    {
        return $this->use('copyable');
    }

    public function expand(): static
    {
        return $this->use('expand');
    }

    public function progressBar(): static
    {
        return $this->use('progressBar');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function displayer(string $name, array $options = []): static
    {
        return $this->use($name, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function use(string $name, array $options = []): static
    {
        $this->displayer = $name;
        $this->displayerOptions = $options;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'field' => $this->field,
            'label' => $this->label !== '' ? $this->label : $this->field,
            'displayer' => $this->displayer,
            'displayer_options' => $this->displayerOptions,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
