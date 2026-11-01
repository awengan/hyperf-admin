<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Form;
use Awengan\HyperfAdmin\Grid;
use Awengan\HyperfAdmin\Helper\AuditLog;
use Awengan\HyperfAdmin\Helper\ErrorPage;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Middleware\AdminAuthMiddleware;
use Awengan\HyperfAdmin\Middleware\ErrorPageMiddleware;
use Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware;
use Awengan\HyperfAdmin\Middleware\SecurityHeadersMiddleware;
use Awengan\HyperfAdmin\Middleware\CsrfMiddleware;
use Awengan\HyperfAdmin\Middleware\PermissionMiddleware;
use Awengan\HyperfAdmin\Middleware\AuditLogMiddleware;
use Awengan\HyperfAdmin\Model\AdminUser;
use Awengan\HyperfAdmin\Model\Permission;
use Awengan\HyperfAdmin\Model\Role;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 角色管理：角色增删改 + 权限分配 + 用户绑定。
 *
 * 与 `/menu` 的区别：角色带两个**多对多**字段（权限、用户），中间表不是模型属性，
 * 所以保存走本类自己的 `store()/update()`，而不是 `Form::store()`；
 * `Form` 在这里只负责渲染表单。
 * （Hyperf 3.1 的 `BelongsToMany` 没有 `sync()`，中间表用 `Db::table()` 维护。）
 */
#[Controller(prefix: '/admin')]
#[Middleware(SecurityHeadersMiddleware::class)]
#[Middleware(ErrorPageMiddleware::class)]
#[Middleware(ReplyStatusMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
#[Middleware(AdminAuthMiddleware::class)]
#[Middleware(AuditLogMiddleware::class)]
#[Middleware(PermissionMiddleware::class)]
class RoleController extends AbstractAdminController
{
    public function __construct(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        parent::__construct($request, $response);
    }

    #[GetMapping(path: 'role')]
    public function index(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->gridPage());
    }

    #[GetMapping(path: 'role/create')]
    public function create(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->formPage()->title('新增角色'));
    }

    #[GetMapping(path: 'role/{id:\d+}/edit')]
    public function edit(int $id): Reply|array|PsrResponseInterface
    {
        $role = Role::query()->find($id);

        if ($role === null) {
            return ErrorPage::render($this->request, $this->response, 404, '404 角色不存在', '角色不存在', ['/' => '回到首页']);
        }

        $form = $this->formPage()->title('编辑角色')->url('/role/' . $id);
        $form->edit($id);
        // 多对多字段不是模型属性，edit() 之后手动回填
        $form->formData([
            'permissions' => $role->permissionIds(),
            'users' => $role->userIds(),
        ]);

        return $this->page($form);
    }

    #[PostMapping(path: 'role/store')]
    public function store(): Reply|array|PsrResponseInterface
    {
        $payload = $this->request->all();

        if ($errors = $this->formPage()->validate($payload)) {
            return $this->validationError($errors);
        }

        // slug 在 DB 里有唯一索引，先给友好提示，别让它变成 500
        if ($this->slugTaken((string) ($payload['slug'] ?? ''))) {
            return $this->validationError(['slug' => ['该标识已被占用']]);
        }

        // 角色 + 两张中间表必须在同一个事务里
        $role = $this->transaction(function () use ($payload): Role {
            $role = new Role();
            $this->fill($role, $payload);
            $role->save();
            $this->syncRelations((int) $role->id, $payload);

            return $role;
        });

        AuditLog::record('create', [
            'target' => Role::class,
            'target_id' => $role->id,
            'summary' => '新增角色：' . $role->name,
        ]);

        return Reply::success('新增成功')->redirect('/role');
    }

    #[PostMapping(path: 'role/{id:\d+}')]
    public function update(int $id): Reply|array|PsrResponseInterface
    {
        $role = Role::query()->find($id);

        if ($role === null) {
            return Reply::error('角色不存在', 40400);
        }

        $payload = $this->request->all();

        if ($errors = $this->formPage()->validate($payload)) {
            return $this->validationError($errors);
        }

        if ($this->slugTaken((string) ($payload['slug'] ?? ''), $id)) {
            return $this->validationError(['slug' => ['该标识已被占用']]);
        }

        $this->transaction(function () use ($role, $payload): void {
            $this->fill($role, $payload);
            $role->save();
            $this->syncRelations((int) $role->id, $payload);
        });

        AuditLog::record('update', [
            'target' => Role::class,
            'target_id' => $id,
            'summary' => '编辑角色：' . $role->name,
        ]);

        return Reply::success('编辑成功')->redirect('/role');
    }

    /**
     * 删除角色（同时清掉两张中间表）。
     */
    #[PostMapping(path: 'role/{id:\d+}/delete')]
    public function destroy(int $id): Reply|array|PsrResponseInterface
    {
        $result = $this->deleteRecord(Role::class, $id, '角色');

        if (Result::isOk($result)) {
            $this->transaction(function () use ($id): void {
                Db::table('admin_role_permission')->where('role_id', $id)->delete();
                Db::table('admin_role_user')->where('role_id', $id)->delete();
            });
        }

        return $result;
    }

    /**
     * 批量删除角色。
     */
        /**
     * 导出 CSV（全部 / 当前页 / 勾选行）。
     */
    /**
     * 行操作弹层表单的 schema（前端点按钮时来取）。
     */
    #[GetMapping(path: 'role/{id:\d+}/action-form')]
    public function actionForm(): Reply|array|PsrResponseInterface
    {
        return $this->rowActionFormPayload();
    }

    /**
     * 上移 / 下移（`Grid::orderable()` 用）。
     */
    #[PostMapping(path: 'role/{id:\d+}/move')]
    public function move(): Reply|array|PsrResponseInterface
    {
        return $this->moveRecord($this->gridPage());
    }
    #[GetMapping(path: 'role/export')]
    public function export(): Reply|array|PsrResponseInterface
    {
        return $this->exportGrid($this->gridPage(), '角色-' . date('Ymd-His') . '.csv');
    }
#[PostMapping(path: 'role/batch-delete')]
    public function batchDestroy(): Reply|array|PsrResponseInterface
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->input('ids', []))));
        $result = $this->deleteRecords(Role::class, $ids, '角色');

        if ($ids !== []) {
            $this->transaction(function () use ($ids): void {
                Db::table('admin_role_permission')->whereIn('role_id', $ids)->delete();
                Db::table('admin_role_user')->whereIn('role_id', $ids)->delete();
            });
        }

        return $result;
    }

    protected function gridPage(): Grid
    {
        $grid = new Grid(new Role());

        $grid->title('角色管理')
            ->url('/role')
            ->pageSize(10)
            ->column('name', '名称')
            ->column('slug', '标识')
            ->column('remark', '备注')
            ->deletable()
            ->exportable()
            ->selectable()
            ->searchable(['name' => '名称', 'slug' => '标识'])
            ->query(static fn ($query) => $query->orderBy('id'));

        return $grid;
    }

    private function formPage(): Form
    {
        $form = new Form(new Role());

        $form->text('name', '角色名称')->rules('required|max:64');
        // 和权限标识一致：允许 `.`，方便照 `demo.viewer` 这种风格命名
        $form->text('slug', '标识')->placeholder('如 super-admin')
            ->rules('required|max:64|regex:/^[A-Za-z0-9._-]+$/');
        $form->checkbox('permissions', '权限')
            ->options(fn (): array => Permission::optionMap())
            ->default([]);
        $form->checkbox('users', '绑定用户')
            ->options(fn (): array => AdminUser::optionMap())
            ->default([]);
        $form->textarea('remark', '备注')->rules('max:255');

        return $form;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fill(Role $role, array $payload): void
    {
        $role->name = (string) ($payload['name'] ?? '');
        $role->slug = (string) ($payload['slug'] ?? '');
        $role->remark = (string) ($payload['remark'] ?? '');
    }

    /**
     * slug 是否被别的角色占用（`$exceptId` 用于编辑时忽略自己）。
     */
    private function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        if ($slug === '') {
            return false;
        }

        $query = Role::query()->where('slug', $slug);

        if ($exceptId !== null && $exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->exists();
    }

    /**
     * 重建两张中间表（先清后插，简单可靠）。
     *
     * @param array<string, mixed> $payload
     */
    private function syncRelations(int $roleId, array $payload): void
    {
        Db::table('admin_role_permission')->where('role_id', $roleId)->delete();
        Db::table('admin_role_user')->where('role_id', $roleId)->delete();

        foreach ((array) ($payload['permissions'] ?? []) as $permissionId) {
            $permissionId = (int) $permissionId;

            if ($permissionId > 0) {
                Db::table('admin_role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        foreach ((array) ($payload['users'] ?? []) as $userId) {
            $userId = (int) $userId;

            if ($userId > 0) {
                Db::table('admin_role_user')->insert([
                    'role_id' => $roleId,
                    'user_id' => $userId,
                ]);
            }
        }
    }
}
