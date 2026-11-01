<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * Hyperf Admin 的全部表结构 + 内置种子数据。
 *
 * 与 `database/admin.sql` 等价 —— 想用迁移管理就
 * `php bin/hyperf.php admin:install --migrate && php bin/hyperf.php migrate`。
 *
 * 每个表都先 `hasTable()` 判断，种子用 `exists()` 兜底，所以**可以重复执行**，
 * 也可以安全地跑在一个已经有 `admin.sql` 建好表的库上。
 */
return new class extends Migration {
    public function up(): void
    {
        $this->users();
        $this->menus();
        $this->rbac();
        $this->logs();

        $this->seed();
    }

    public function down(): void
    {
        foreach ([
            'admin_logs',
            'admin_login_attempts',
            'admin_role_permission',
            'admin_role_user',
            'admin_permissions',
            'admin_roles',
            'admin_menu',
            'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function users(): void
    {
        if (Schema::hasTable('admin_users')) {
            return;
        }

        Schema::create('admin_users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username', 64)->unique('admin_users_username_unique');
            $table->string('password', 255);
            $table->string('name', 64)->default('');
            $table->string('avatar', 255)->default('');
            $table->unsignedInteger('session_version')->default(0);
            $table->timestamps();
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
    }

    private function menus(): void
    {
        if (Schema::hasTable('admin_menu')) {
            return;
        }

        Schema::create('admin_menu', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->default(0)->index('admin_menu_parent_id_index');
            $table->integer('order')->default(0);
            $table->boolean('is_menu')->default(true);
            $table->string('title', 64)->default('');
            $table->string('icon', 64)->default('');
            $table->string('uri', 255)->default('');
            $table->json('permission')->nullable();
            $table->timestamps();
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
    }

    private function rbac(): void
    {
        if (! Schema::hasTable('admin_permissions')) {
            Schema::create('admin_permissions', static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 64)->default('');
                $table->string('slug', 64)->default('')->unique('admin_permissions_slug_unique');
                $table->string('remark', 255)->default('');
                $table->timestamps();
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }

        if (! Schema::hasTable('admin_roles')) {
            Schema::create('admin_roles', static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 64)->default('');
                $table->string('slug', 64)->default('')->unique('admin_roles_slug_unique');
                $table->string('remark', 255)->default('');
                $table->timestamps();
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }

        if (! Schema::hasTable('admin_role_permission')) {
            Schema::create('admin_role_permission', static function (Blueprint $table): void {
                $table->unsignedInteger('role_id');
                $table->unsignedInteger('permission_id')->index('admin_role_permission_permission_id_index');
                $table->primary(['role_id', 'permission_id']);
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }

        if (! Schema::hasTable('admin_role_user')) {
            Schema::create('admin_role_user', static function (Blueprint $table): void {
                $table->unsignedInteger('role_id');
                $table->unsignedInteger('user_id')->index('admin_role_user_user_id_index');
                $table->primary(['role_id', 'user_id']);
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }
    }

    private function logs(): void
    {
        if (! Schema::hasTable('admin_logs')) {
            Schema::create('admin_logs', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id')->default(0)->index('admin_logs_user_id_index');
                $table->string('username', 64)->default('');
                $table->string('action', 32)->default('');
                $table->string('method', 8)->default('');
                $table->string('path', 255)->default('')->index('admin_logs_path_index');
                $table->string('target', 128)->default('');
                $table->string('target_id', 64)->default('');
                $table->string('summary', 255)->default('');
                $table->json('payload')->nullable();
                $table->string('ip', 64)->default('');
                $table->string('ua', 255)->default('');
                $table->integer('status')->default(0);
                $table->timestamp('created_at')->nullable()->index('admin_logs_created_at_index');
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }

        if (! Schema::hasTable('admin_login_attempts')) {
            Schema::create('admin_login_attempts', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('username', 64)->default('');
                $table->string('ip', 64)->default('');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('window_started_at')->nullable();
                $table->timestamp('locked_until')->nullable();
                $table->unique(['username', 'ip'], 'admin_login_attempts_key_unique');
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
            });
        }
    }

    /**
     * 种子：与 `database/admin.sql` 完全一致（只有一个 admin 账号）。
     */
    private function seed(): void
    {
        $now = date('Y-m-d H:i:s');
        $db = $this->db();

        // 账号
        if (! $db->table('admin_users')->where('username', 'admin')->exists()) {
            $db->table('admin_users')->insert([
                'username' => 'admin',
                'password' => '$2y$10$F.PtFhcHQ9qqI1p5.iD/Q.61iQbZHVaiIrDoWgbi4qcr3yewErvE.',
                'name' => 'Administrator',
                'session_version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 0.0.1 起不再内置演示账号：老库里如果有，顺手清掉
        $db->table('admin_users')->where('username', 'viewer')->delete();

        // 权限（id 1–6 保留原编号，其中 1 是已下线的 demo.view，见下方清理）
        $permissions = [
            [2, '菜单管理', 'menu.manage', '菜单增删改'],
            [3, '角色管理', 'role.manage', '角色增删改 + 分配权限/用户'],
            [4, '权限管理', 'permission.manage', '权限增删改'],
            [5, '用户管理', 'user.manage', '用户增删改 + 分配角色'],
            [6, '审计日志', 'log.view', '查看操作日志'],
        ];

        foreach ($permissions as [$id, $name, $slug, $remark]) {
            if (! $db->table('admin_permissions')->where('slug', $slug)->exists()) {
                $db->table('admin_permissions')->insert([
                    'id' => $id, 'name' => $name, 'slug' => $slug, 'remark' => $remark,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // 演示页下线：清掉 demo.view 权限与它的角色关联
        $db->table('admin_permissions')->where('slug', 'demo.view')->delete();

        // 角色：只发一个超级管理员，其余按需创建
        $roles = [
            [1, '超级管理员', 'super-admin', '拥有全部权限'],
        ];

        foreach ($roles as [$id, $name, $slug, $remark]) {
            if (! $db->table('admin_roles')->where('slug', $slug)->exists()) {
                $db->table('admin_roles')->insert([
                    'id' => $id, 'name' => $name, 'slug' => $slug, 'remark' => $remark,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // 清理老库里的演示角色（连同两张中间表）
        foreach ($db->table('admin_roles')->where('slug', 'viewer')->get() ?? [] as $role) {
            $roleId = (int) $role->id;
            $db->table('admin_role_permission')->where('role_id', $roleId)->delete();
            $db->table('admin_role_user')->where('role_id', $roleId)->delete();
            $db->table('admin_roles')->where('id', $roleId)->delete();
        }

        // 关联
        $adminId = (int) $db->table('admin_users')->where('username', 'admin')->value('id');

        foreach ([2, 3, 4, 5, 6] as $permissionId) {
            if (! $db->table('admin_role_permission')->where('role_id', 1)->where('permission_id', $permissionId)->exists()) {
                $db->table('admin_role_permission')->insert(['role_id' => 1, 'permission_id' => $permissionId]);
            }
        }

        if ($adminId > 0 && ! $db->table('admin_role_user')->where('role_id', 1)->where('user_id', $adminId)->exists()) {
            $db->table('admin_role_user')->insert(['role_id' => 1, 'user_id' => $adminId]);
        }

        // 内置菜单（与 MenuHelper::defaultMenu() 同一棵树：只有「系统管理」一组）
        $menus = [
            [3, 0, 1, 0, '系统管理', 'settings', '', null],
            [11, 3, 1, 1, '用户管理', 'user', 'user', '[5]'],
            [5, 3, 2, 1, '角色管理', 'settings', 'role', '[3]'],
            [6, 3, 3, 1, '权限管理', 'document', 'permission', '[4]'],
            [4, 3, 4, 1, '菜单管理', 'menu', 'menu', '[2]'],
            [12, 3, 5, 1, '审计日志', 'table', 'log', '[6]'],
        ];

        // 已经下线的内置菜单（表格 1 / 组件示例 2 / 子菜单整组 7,8,9,10）。
        // 老库升级时清掉，只动这 6 个固定 id。
        $db->table('admin_menu')->whereIn('id', [1, 2, 7, 8, 9, 10])->delete();

        foreach ($menus as [$id, $parentId, $order, $isMenu, $title, $icon, $uri, $permission]) {
            $exists = $db->table('admin_menu')->where('id', $id)->exists();

            $row = [
                'parent_id' => $parentId,
                'order' => $order,
                'is_menu' => $isMenu,
                'title' => $title,
                'icon' => $icon,
                'uri' => $uri,
                'permission' => $permission,
                'updated_at' => $now,
            ];

            if ($exists) {
                $db->table('admin_menu')->where('id', $id)->update($row);
            } else {
                $db->table('admin_menu')->insert($row + ['id' => $id, 'created_at' => $now]);
            }
        }
    }

    private function db(): \Hyperf\DbConnection\Db
    {
        return new \Hyperf\DbConnection\Db(\Hyperf\Context\ApplicationContext::getContainer());
    }
};
