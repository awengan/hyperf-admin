<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 权限字典（`/permission` 模块）。
 *
 * `slug` 是被代码引用的稳定标识，例如 `role.manage`；
 * `name` 只用于展示。
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $remark
 */
class Permission extends Model
{
    protected ?string $table = 'admin_permissions';

    protected array $fillable = ['name', 'slug', 'remark'];

    protected array $casts = [
        'id' => 'integer',
        'name' => 'string',
        'slug' => 'string',
        'remark' => 'string',
    ];

    /**
     * 拥有该权限的角色。
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'admin_role_permission', 'permission_id', 'role_id');
    }

    /**
     * `[id => name]`，供角色表单的权限勾选使用。
     *
     * @return array<int, string>
     */
    public static function optionMap(): array
    {
        $options = [];

        foreach (static::query()->orderBy('id')->get() ?? [] as $permission) {
            $options[(int) $permission->id] = (string) $permission->name;
        }

        return $options;
    }
}
