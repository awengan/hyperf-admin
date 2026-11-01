<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Model;

use Awengan\HyperfAdmin\Traits\ModelTree;
use Hyperf\DbConnection\Model\Model;

/**
 * Menu node.
 *
 * @property int $id
 * @property int $parent_id
 * @property int $order
 * @property int $is_menu
 * @property string $title
 * @property string $icon
 * @property string $uri
 * @property array $permission
 */
class Menu extends Model
{
    use ModelTree;

    protected ?string $table = 'admin_menu';

    protected array $appends = ['route'];

    protected array $hidden = ['created_at', 'updated_at'];

    protected array $fillable = ['parent_id', 'order', 'is_menu', 'title', 'icon', 'uri', 'permission'];

    protected array $casts = [
        'parent_id' => 'integer',
        'order' => 'integer',
        'is_menu' => 'integer',
        'permission' => 'array',
    ];

    public function children()
    {
        return $this->hasMany(static::class, $this->getParentColumn())->orderBy($this->getOrderColumn());
    }

    public function getRouteAttribute(): string
    {
        return '/' . ltrim((string) ($this->uri ?? ''), '/');
    }

    /**
     * 树形选项（给 `n-tree-select` 用）：`[['label' => .., 'key' => .., 'children' => [...]]]`。
     *
     * @param int $exceptId 编辑时排除自己（否则会把自己的子树设成自己的父级）
     * @return array<int, array<string, mixed>>
     */
    public static function selectTree(int $exceptId = 0): array
    {
        $rows = static::query()
            ->orderBy('parent_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get() ?? [];

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->parent_id][] = $row;
        }

        $build = static function (int $parentId, int $depth) use (&$build, $grouped, $exceptId): array {
            $nodes = [];

            foreach ($grouped[$parentId] ?? [] as $row) {
                $id = (int) $row->id;

                if ($exceptId > 0 && $id === $exceptId) {
                    continue;   // 自己和自己的子孙都不作为候选
                }

                $node = [
                    'label' => ($depth > 0 ? str_repeat('　', $depth) : '') . (string) $row->title,
                    'key' => $id,
                ];

                $children = $build($id, $depth + 1);

                if ($children !== []) {
                    $node['children'] = $children;
                }

                $nodes[] = $node;
            }

            return $nodes;
        };

        $tree = $build(0, 1);

        // 根节点「顶级菜单」= parent_id 0，选中它就是「不挂在别人下面」
        return [[
            'label' => '顶级菜单',
            'key' => 0,
            'children' => $tree,
        ]];
    }

    /**
     * Convert a nested menu tree into the structure the frontend `n-menu`
     * expects: `['title' => .., 'url' => .., 'icon' => .., 'child' => []]`.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    public static function toMenuItems(array $nodes): array
    {
        $items = [];

        foreach ($nodes as $node) {
            $items[] = [
                'title' => (string) ($node['title'] ?? ''),
                'url' => '/' . ltrim((string) ($node['uri'] ?? ''), '/'),
                'icon' => (string) ($node['icon'] ?? ''),
                'child' => self::toMenuItems((array) ($node['children'] ?? [])),
            ];
        }

        return $items;
    }
}
