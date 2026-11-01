<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Model;

use Hyperf\DbConnection\Db;
use Hyperf\DbConnection\Model\Model;

/**
 * Admin user.
 *
 * @property int $id
 * @property string $username
 * @property string $password
 * @property string $name
 * @property string $avatar
 */
class AdminUser extends Model
{
    protected ?string $table = 'admin_users';

    protected array $fillable = ['username', 'password', 'name', 'avatar'];

    protected array $hidden = ['password'];

    protected array $casts = [
        'id' => 'integer',
        'username' => 'string',
        'password' => 'string',
        'name' => 'string',
        'avatar' => 'string',
        'session_version' => 'integer',
    ];

    public static function getUserByUserName(string $userName): ?self
    {
        return self::where('username', $userName)->first();
    }

    public function checkPassword(string $password): bool
    {
        return password_verify($password, (string) $this->password);
    }

    public function setPasswordAttribute(string $password): void
    {
        $this->attributes['password'] = password_hash($password, PASSWORD_DEFAULT);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'admin_role_user', 'user_id', 'role_id');
    }

    /**
     * 该用户的权限标识（`slug`）集合，来自其所有角色。
     *
     * @return array<int, string>
     */
    public function permissionSlugs(): array
    {
        $roleIds = Db::table('admin_role_user')
            ->where('user_id', (int) $this->id)
            ->pluck('role_id')
            ->all();

        if ($roleIds === []) {
            return [];
        }

        return array_values(array_unique(array_map(
            'strval',
            Db::table('admin_role_permission')
                ->join('admin_permissions', 'admin_permissions.id', '=', 'admin_role_permission.permission_id')
                ->whereIn('admin_role_permission.role_id', $roleIds)
                ->pluck('admin_permissions.slug')
                ->all()
        )));
    }

    /**
     * `[id => 显示名]`，供角色表单的用户勾选使用。
     *
     * @return array<int, string>
     */
    public static function optionMap(): array
    {
        $options = [];

        foreach (static::query()->orderBy('id')->get() ?? [] as $user) {
            $options[(int) $user->id] = (string) ($user->name !== '' ? $user->name : $user->username);
        }

        return $options;
    }
}
