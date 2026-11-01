<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * 树形选择（Naive UI 的 `n-tree-select`）。
 *
 * 用于「上级菜单」这类自引用字段：
 *
 *     $form->treeSelect('parent_id', '上级菜单')
 *         ->options(fn () => Menu::selectTree())
 *         ->default(0);
 *
 * `options()` 接受**树形**结构（`[['label' => .., 'key' => .., 'children' => [...]]]`），
 * 和 `select` 的扁平 `key => label` 不同 —— 树形下拉需要层级。
 */
class TreeSelect extends AbstractField
{
    protected string $type = 'treeSelect';

    protected array $hideAttrs = ['default'];

    /**
     * 树形选项。
     *
     * @var array<int, array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param array<int, array<string, mixed>>|callable $options 树形选项或返回它的闭包
     */
    public function options(array|callable $options): static
    {
        $this->options = is_callable($options) ? (array) $options() : $options;

        return $this;
    }

    /**
     * 是否允许清空（对应数据库里的 0 / null）。
     */
    public bool $clearable = true;

    public function clearable(bool $clearable = true): static
    {
        $this->clearable = $clearable;

        return $this;
    }

    /**
     * 下拉打开时是否**全展开**（默认 true）。
     *
     * 不展开的话「上级菜单」只看得到根节点「顶级菜单」，得手动点箭头才能选父级 ——
     * 实测时这个坑很费解。层级很深、节点很多时可以关掉。
     */
    public bool $defaultExpandAll = true;

    public function defaultExpandAll(bool $expand = true): static
    {
        $this->defaultExpandAll = $expand;

        return $this;
    }
}
