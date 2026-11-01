<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Model;

use Hyperf\DbConnection\Db;
use Hyperf\DbConnection\Model\Model;

/**
 * 角色（`/role` 模块）。
 *
 * 与权限、用户都是多对多，中间表 `admin_role_permission` / `admin_role_user`。
 * 注意：Hyperf 3.1 的 `BelongsToMany` **没有** `sync()/attach()/detach()`，
 * 中间表由控制器用 `Db::table()` 维护。
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $remark
 */
class Role extends Model
{
    protected ?string $table = 'admin_roles';

    protected array $fillable = ['name', 'slug', 'remark'];

    protected array $casts = [
        'id' => 'integer',
        'name' => 'string',
        'slug' => 'string',
        'remark' => 'string',
    ];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'admin_role_permission', 'role_id', 'permission_id');
    }

    public function users()
    {
        return $this->belongsToMany(AdminUser::class, 'admin_role_user', 'role_id', 'user_id');
    }

    /**
     * `[id => name]`，供用户表单的角色勾选使用。
     *
     * @return array<int, string>
     */
    public static function optionMap(): array
    {
        $options = [];

        foreach (static::query()->orderBy('id')->get() ?? [] as $role) {
            $options[(int) $role->id] = (string) $role->name;
        }

        return $options;
    }

    /**
     * 该角色已勾选的权限 id。
     *
     * 直接查中间表（比起 `$this->permissions()->pluck(...)` 更可控，
     * 也不依赖 Hyperf 对限定列名 pluck 的行为）。
     *
     * @return array<int, int>
     */
    public function permissionIds(): array
    {
        return array_map('intval', Db::table('admin_role_permission')
            ->where('role_id', (int) $this->id)
            ->pluck('permission_id')
            ->all());
    }

    /**
     * 该角色已绑定的用户 id。
     *
     * @return array<int, int>
     */
    public function userIds(): array
    {
        return array_map('intval', Db::table('admin_role_user')
            ->where('role_id', (int) $this->id)
            ->pluck('user_id')
            ->all());
    }
}
