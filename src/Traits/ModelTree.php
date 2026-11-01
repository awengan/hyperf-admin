<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Traits;

use Closure;
use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hyperf\DbConnection\Db;

/**
 * Nested-set-free tree helper for models owning a `parent_id` column.
 */
trait ModelTree
{
    protected static array $branchOrder = [];

    protected string $parentColumn = 'parent_id';

    protected string $titleColumn = 'title';

    protected string $orderColumn = 'order';

    protected ?Closure $queryCallback = null;

    public function children()
    {
        return $this->hasMany(static::class, $this->parentColumn);
    }

    public function parent()
    {
        return $this->belongsTo(static::class, $this->parentColumn);
    }

    public function getParentColumn(): string
    {
        return $this->parentColumn;
    }

    public function setParentColumn(string $column): void
    {
        $this->parentColumn = $column;
    }

    public function getTitleColumn(): string
    {
        return $this->titleColumn;
    }

    public function setTitleColumn(string $column): void
    {
        $this->titleColumn = $column;
    }

    public function getOrderColumn(): string
    {
        return $this->orderColumn;
    }

    public function setOrderColumn(string $column): void
    {
        $this->orderColumn = $column;
    }

    public function withQuery(?Closure $query = null): static
    {
        $this->queryCallback = $query;

        return $this;
    }

    public function toTree(): array
    {
        return $this->buildNestedArray();
    }

    public function buildNestedArray(array $nodes = [], int|string $parentId = 0): array
    {
        $branch = [];

        if (empty($nodes)) {
            $nodes = $this->allNodes();
        }

        foreach ($nodes as $node) {
            if ($node[$this->parentColumn] != $parentId) {
                continue;
            }

            $children = $this->buildNestedArray($nodes, $node[$this->getKeyName()]);

            if ($children) {
                $node['children'] = $children;
            }

            $branch[] = $node;
        }

        return $branch;
    }

    /**
     * All nodes, ordered so every branch is listed after its root.
     */
    public function allNodes(): array
    {
        $orderColumn = Db::getQueryGrammar()->wrap($this->orderColumn);
        $byOrder = $orderColumn . ' = 0,' . $orderColumn;

        $self = new static();

        if ($this->queryCallback instanceof Closure) {
            $self = call_user_func($this->queryCallback, $self);
        }

        return $self->orderByRaw($byOrder)->get()?->toArray() ?? [];
    }

    /**
     * Persist the order of a tree like array.
     */
    public static function saveOrder(array $tree = [], int|string $parentId = 0): void
    {
        if (empty(static::$branchOrder)) {
            static::setBranchOrder($tree);
        }

        foreach ($tree as $branch) {
            $node = static::find($branch['id']);

            if ($node === null) {
                continue;
            }

            $node->{$node->getParentColumn()} = $parentId;
            $node->{$node->getOrderColumn()} = static::$branchOrder[$branch['id']] ?? 0;
            $node->save();

            if (isset($branch['children'])) {
                static::saveOrder($branch['children'], $branch['id']);
            }
        }
    }

    /**
     * Options keyed by id, indented for use in a select field.
     */
    public static function selectOptions(?Closure $closure = null, string $rootText = 'ROOT'): Collection
    {
        $options = (new static())->withQuery($closure)->buildSelectOptions();

        return (new Collection($options))->prepend($rootText, 0);
    }

    public function delete()
    {
        $this->where($this->parentColumn, $this->getKey())->delete();

        return parent::delete();
    }

    protected static function setBranchOrder(array $order): void
    {
        static::$branchOrder = array_flip(Arr::flatten($order));

        static::$branchOrder = array_map(static fn ($item) => ++$item, static::$branchOrder);
    }

    protected function buildSelectOptions(array $nodes = [], int|string $parentId = 0, string $prefix = '', string $space = '　'): array
    {
        $prefix = $prefix ?: '┝' . $space;

        $options = [];

        if (empty($nodes)) {
            $nodes = $this->allNodes();
        }

        foreach ($nodes as $node) {
            if ($node[$this->parentColumn] != $parentId) {
                continue;
            }

            $node[$this->titleColumn] = $prefix . $space . $node[$this->titleColumn];

            $childrenPrefix = str_replace('┝', str_repeat($space, 1), $prefix)
                . '┝' . str_replace(['┝', $space], '', $prefix);

            $children = $this->buildSelectOptions($nodes, $node[$this->getKeyName()], $childrenPrefix);

            $options[$node[$this->getKeyName()]] = $node[$this->titleColumn];

            if ($children) {
                $options += $children;
            }
        }

        return $options;
    }

    protected static function bootModelTree(): void
    {
        // Reserved for future model event wiring.
    }
}
