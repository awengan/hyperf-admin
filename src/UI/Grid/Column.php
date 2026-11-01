<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Grid;

use Awengan\HyperfAdmin\Grid;
use Closure;

/**
 * 一个列表列（`Grid::column()` 的返回值）。
 *
 * 为什么要有这个对象：展示器与列级筛选是**挂在某一列上**的，
 * 用「上一次 column() 的结果」当载体最自然：
 *
 *     $grid->column('avatar', '头像')->image('40x40');
 *     $grid->column('status', '状态')->switch();
 *     $grid->column('created_at', '创建时间')->between();
 *
 * 兼容性：`__call()` 会把没定义的方法转发给 Grid，所以
 * `->column('a')->column('b')->deletable()` 这种「列后面直接接 Grid 方法」的
 * 老写法继续有效（返回的仍是 Grid）。
 */
class Column
{
    /**
     * 展示器名（对应前端 `GridPage` 的 render 分派表）。
     */
    public ?string $displayer = null;

    /**
     * 展示器参数（如 `['size' => '40x40']`）。
     *
     * @var array<string, mixed>
     */
    public array $displayerOptions = [];

    /**
     * 列级筛选：`['type' => 'between', 'options' => [...]]`。
     *
     * @var array{type: string, options: array<string, mixed>}|null
     */
    public ?array $filter = null;

    /**
     * 是否允许点表头按这一列排序（`sortable()` 的列级版本）。
     */
    public bool $sortable = false;

    /**
     * 固定列：`left` / `right` / 空串表示不固定。
     */
    public string $fixed = '';

    /**
     * 列说明（表头旁的小问号）。
     */
    public string $help = '';

    public function __construct(
        protected Grid $grid,
        public string $field,
        public string $label = ''
    ) {
    }

    /* ------------------------------------------------------------ 展示器 --- */

    /**
     * 图片：单元格显示缩略图，点击可放大。
     */
    public function image(string $size = '40x40'): static
    {
        return $this->useDisplayer('image', ['size' => $size]);
    }

    /**
     * 开关：直接点它改数据（`POST {url}/{id}/toggle`，body `{field: 0|1}`）。
     */
    public function switch(bool $disabled = false): static
    {
        return $this->useDisplayer('switch', ['disabled' => $disabled]);
    }

    /**
     * 标签（每个值一枚 tag）。
     *
     * @param array<int|string, string> $map 值 => 文案，如 `[1 => '启用', 0 => '停用']`
     */
    public function tag(array $map = [], string $type = 'default'): static
    {
        return $this->useDisplayer('tag', ['map' => $map, 'type' => $type]);
    }

    /**
     * 徽标（圆角小标签，适合状态）。
     *
     * @param array<int|string, string> $map
     */
    public function badge(array $map = []): static
    {
        return $this->tag($map);
    }

    /**
     * 布尔：`✓` / `✗`。
     */
    public function bool(): static
    {
        return $this->useDisplayer('bool');
    }

    /**
     * 链接：单元格文字变成可点链接（`newTab` 控制是否新窗口）。
     */
    public function link(bool $newTab = false): static
    {
        return $this->useDisplayer('link', ['newTab' => $newTab]);
    }

    /**
     * 时间格式化。
     */
    public function date(string $format = 'YYYY-MM-DD HH:mm:ss'): static
    {
        return $this->useDisplayer('date', ['format' => $format]);
    }

    /**
     * 进度条（0–100）。
     */
    public function progressBar(): static
    {
        return $this->useDisplayer('progressBar');
    }

    /**
     * 可复制（点击复制到剪贴板）。
     */
    public function copyable(): static
    {
        return $this->useDisplayer('copyable');
    }

    /**
     * 多值展开（数组值渲染成多枚 tag）。
     */
    public function expand(): static
    {
        return $this->useDisplayer('expand');
    }

    /**
     * 行内编辑（双击单元格改数据，`POST {url}/{id}/toggle`，body `{field: 新值}`）。
     */
    public function editable(): static
    {
        return $this->useDisplayer('editable');
    }

    /**
     * 自定义展示器：`name` 必须是前端 `GridPage` 认识的键。
     *
     * @param array<string, mixed> $options
     */
    public function displayer(string $name, array $options = []): static
    {
        return $this->useDisplayer($name, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function useDisplayer(string $name, array $options = []): static
    {
        $this->displayer = $name;
        $this->displayerOptions = $options;

        return $this;
    }

    /* -------------------------------------------------------- 列级筛选 --- */

    /**
     * 范围筛选（日期或数值，前端渲染两个输入/日期选择）。
     */
    public function between(bool $datetime = false): static
    {
        return $this->useFilter('between', ['datetime' => $datetime]);
    }

    /**
     * 模糊匹配。
     */
    public function like(): static
    {
        return $this->useFilter('like');
    }

    /**
     * 多选（`in`）。
     *
     * @param array<int|string, string> $options
     */
    public function in(array $options): static
    {
        return $this->useFilter('in', ['options' => $options]);
    }

    /**
     * 等值（下拉或输入）。
     *
     * @param array<int|string, string> $options
     */
    public function equal(array $options = []): static
    {
        return $this->useFilter('equal', ['options' => $options]);
    }

    /**
     * 数值/日期比较。
     */
    public function gt(): static
    {
        return $this->useFilter('gt');
    }

    public function lt(): static
    {
        return $this->useFilter('lt');
    }

    /**
     * 前缀匹配。
     */
    public function startWith(): static
    {
        return $this->useFilter('startWith');
    }

    /**
     * 自由输入（精确匹配输入框）。
     */
    public function input(): static
    {
        return $this->useFilter('input');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function useFilter(string $type, array $options = []): static
    {
        $this->filter = ['type' => $type, 'options' => $options];

        return $this;
    }

    /* ------------------------------------------------------------ 其它 --- */

    /**
     * 只让这一列可排序（等价于 Grid 级 `sortable()` 的列级写法）。
     */
    /**
     * 固定这一列（左侧或右侧）。
     */
    public function fixed(string $side = 'left'): static
    {
        $this->fixed = $side === 'right' ? 'right' : 'left';

        return $this;
    }
    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    /**
     * 表头加一个说明小图标。
     */
    public function help(string $text): static
    {
        $this->help = $text;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'field' => $this->field,
            'fixed' => $this->fixed,
            'label' => $this->label !== '' ? $this->label : $this->field,
            'displayer' => $this->displayer,
            'displayer_options' => $this->displayerOptions,
            'column_filter' => $this->filter,
            'sortable' => $this->sortable ?: null,
            'help' => $this->help !== '' ? $this->help : null,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }

    /**
     * 未定义的方法转发给 Grid，保证老写法（列后直接接 Grid 方法）不断。
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->grid->{$name}(...$arguments);
    }

    /**
     * 支持把列当闭包参数用：`$grid->row(fn (Column $c) => ...)` 之类。
     */
    public function getGrid(): Grid
    {
        return $this->grid;
    }

    /**
     * 允许在 `column()` 里直接用闭包微调（可选写法）。
     */
    public function tap(Closure $callback): static
    {
        $callback($this);

        return $this;
    }

    public function getField(): string
    {
        return $this->field;
    }
}
