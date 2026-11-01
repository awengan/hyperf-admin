<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Constants\ErrorCode;
use Awengan\HyperfAdmin\Form;
use Awengan\HyperfAdmin\Grid;
use Awengan\HyperfAdmin\Helper\AuditLog;
use Awengan\HyperfAdmin\Helper\Auth;
use Awengan\HyperfAdmin\Helper\ErrorPage;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Helper\ActionResponse;
use Awengan\HyperfAdmin\Middleware\AdminAuthMiddleware;
use Awengan\HyperfAdmin\Middleware\ErrorPageMiddleware;
use Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware;
use Awengan\HyperfAdmin\Middleware\SecurityHeadersMiddleware;
use Awengan\HyperfAdmin\Middleware\CsrfMiddleware;
use Awengan\HyperfAdmin\Middleware\PermissionMiddleware;
use Awengan\HyperfAdmin\Middleware\AuditLogMiddleware;
use Awengan\HyperfAdmin\Model\AdminUser;
use Awengan\HyperfAdmin\Model\Role;
use Awengan\HyperfAdmin\Show;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 用户管理：账号增删改 + 角色绑定。
 *
 * 两个要点：
 *  1. **密码哈希绝不能回显到前端** —— `Form::edit()` 会把模型属性填进 form_data，
 *     所以编辑页要在 `edit()` 之后把 password 改成空串（留空 = 不修改）。
 *  2. 密码写入走 `AdminUser::setPasswordAttribute()`（自动 bcrypt），
 *     这里只在非空时才赋值。
 */
#[Controller(prefix: '/admin')]
#[Middleware(SecurityHeadersMiddleware::class)]
#[Middleware(ErrorPageMiddleware::class)]
#[Middleware(ReplyStatusMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
#[Middleware(AdminAuthMiddleware::class)]
#[Middleware(AuditLogMiddleware::class)]
#[Middleware(PermissionMiddleware::class)]
class UserController extends AbstractAdminController
{
    public function __construct(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        parent::__construct($request, $response);
    }

    #[GetMapping(path: 'user')]
    public function index(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->gridPage());
    }

    #[GetMapping(path: 'user/create')]
    public function create(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->formPage(true)->title('新增用户'));
    }

    #[GetMapping(path: 'user/{id:\d+}/edit')]
    public function edit(int $id): Reply|array|PsrResponseInterface
    {
        $user = AdminUser::query()->find($id);

        if ($user === null) {
            return ErrorPage::render($this->request, $this->response, 404, '404 用户不存在', '用户不存在', ['/' => '回到首页']);
        }

        $form = $this->formPage(false)->title('编辑用户')->url('/user/' . $id);
        $form->edit($id);
        $form->formData([
            // 修正：不要把哈希塞回表单
            'password' => '',
            'roles' => Db::table('admin_role_user')->where('user_id', $id)->pluck('role_id')->all(),
        ]);

        return $this->page($form);
    }

    #[PostMapping(path: 'user/store')]
    public function store(): Reply|array|PsrResponseInterface
    {
        $payload = $this->request->all();

        if ($errors = $this->formPage(true)->validate($payload)) {
            return $this->validationError($errors);
        }

        if (AdminUser::getUserByUserName((string) $payload['username']) !== null) {
            return $this->validationError(['username' => ['该用户名已被占用']]);
        }

        $user = $this->transaction(function () use ($payload): AdminUser {
            $user = new AdminUser();
            $user->username = (string) $payload['username'];
            $user->name = (string) $payload['name'];
            $user->avatar = (string) ($payload['avatar'] ?? '');
            $user->password = (string) $payload['password'];
            $user->save();

            $this->syncRoles((int) $user->id, $payload);

            return $user;
        });

        AuditLog::record('create', [
            'target' => AdminUser::class,
            'target_id' => $user->id,
            'summary' => '新增用户：' . $user->username,
        ]);

        return Reply::success('新增成功')->redirect('/user');
    }

    #[PostMapping(path: 'user/{id:\d+}')]
    public function update(int $id): Reply|array|PsrResponseInterface
    {
        $user = AdminUser::query()->find($id);

        if ($user === null) {
            return Reply::error('用户不存在', 40400);
        }

        $payload = $this->request->all();

        if ($errors = $this->formPage(false)->validate($payload)) {
            return $this->validationError($errors);
        }

        $exists = AdminUser::getUserByUserName((string) $payload['username']);

        if ($exists !== null && (int) $exists->id !== $id) {
            return $this->validationError(['username' => ['该用户名已被占用']]);
        }

        $this->transaction(function () use ($user, $id, $payload): void {
            $user->username = (string) $payload['username'];
            $user->name = (string) $payload['name'];
            $user->avatar = (string) ($payload['avatar'] ?? '');

            // 留空 = 不修改密码
            if ((string) ($payload['password'] ?? '') !== '') {
                $user->password = (string) $payload['password'];
            }

            $user->save();
            $this->syncRoles($id, $payload);
        });

        AuditLog::record('update', [
            'target' => AdminUser::class,
            'target_id' => $id,
            'summary' => '编辑用户：' . $user->username,
        ]);

        return Reply::success('编辑成功')->redirect('/user');
    }

    #[PostMapping(path: 'user/{id:\d+}/delete')]
    public function destroy(int $id): Reply|array|PsrResponseInterface
    {
        // 不能删自己，否则直接把登录态弄没了
        if ($id === Auth::id()) {
            return Reply::error('不能删除当前登录账号', ErrorCode::FORBIDDEN);
        }

        $result = $this->deleteRecord(AdminUser::class, $id, '用户');

        if (Result::isOk($result)) {
            $this->transaction(static fn () => Db::table('admin_role_user')->where('user_id', $id)->delete());
        }

        return $result;
    }

    #[PostMapping(path: 'user/batch-delete')]
    public function batchDestroy(): Reply|array|PsrResponseInterface
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->input('ids', []))));
        $ids = array_values(array_diff($ids, [Auth::id()]));

        if ($ids === []) {
            return Reply::error('请选择要删除的用户（不能包含当前登录账号）', ErrorCode::VALIDATION_ERROR);
        }

        $result = $this->deleteRecords(AdminUser::class, $ids, '用户');
        $this->transaction(static fn () => Db::table('admin_role_user')->whereIn('user_id', $ids)->delete());

        return $result;
    }

    protected function gridPage(): Grid
    {
        $grid = new Grid(new AdminUser());

        $grid->title('用户管理')
            ->url('/user')
            ->pageSize(10)
            ->column('avatar', '头像')->image('36x36')
            ->column('username', '账号')
            ->column('name', '姓名')
            ->column('created_at', '创建时间')->date('YYYY-MM-DD HH:mm')->between(true)
            ->viewAction('查看')
            ->rowAction('reset-password', '重置密码', [
                'type' => 'error',
                'confirm' => '会随机生成一个新密码并显示出来，确定重置吗？',
            ])
            ->deletable()
            ->exportable()
            ->selectable()
            ->searchable(['username' => '账号', 'name' => '姓名'])
            ->query(static fn ($query) => $query->orderBy('id'));

        return $grid;
    }

    /**
     * 详情页（`Show` 构建器）。
     */
    #[GetMapping(path: 'user/{id:\d+}')]
    public function show(int $id): Reply|array|PsrResponseInterface
    {
        $user = AdminUser::query()->find($id);

        if ($user === null) {
            return ErrorPage::render($this->request, $this->response, 404, '404 用户不存在', '用户不存在', ['/' => '回到首页']);
        }

        $roles = Db::table('admin_role_user')
            ->join('admin_roles', 'admin_roles.id', '=', 'admin_role_user.role_id')
            ->where('admin_role_user.user_id', $id)
            ->pluck('admin_roles.name')
            ->all();

        $show = new Show(new AdminUser());
        $show->title('用户详情')->url('/user');

        $show->panel('基本信息', static function (Show $show): void {
            $show->field('username', '账号')->copyable();
            $show->field('name', '姓名');
            $show->field('avatar', '头像')->image();
            $show->field('session_version', '会话版本');
        });

        $show->panel('角色与时间', static function (Show $show) use ($roles): void {
            $show->field('roles', '角色')->tag($roles === [] ? [] : array_combine($roles, $roles));
            $show->field('created_at', '创建时间')->date();
        }, columns: 1);

        return $this->page($show->with(['roles' => $roles])->edit($id));
    }

    /**
     * 行操作分发：一个方法处理所有自定义动作（配合 `Grid::rowAction()`）。
     */
        /**
     * 导出 CSV（全部 / 当前页 / 勾选行）。
     */
    /**
     * 行操作弹层表单的 schema（前端点按钮时来取）。
     */
    #[GetMapping(path: 'user/{id:\d+}/action-form')]
    public function actionForm(): Reply|array|PsrResponseInterface
    {
        return $this->rowActionFormPayload();
    }

    /**
     * 上移 / 下移（`Grid::orderable()` 用）。
     */
    #[PostMapping(path: 'user/{id:\d+}/move')]
    public function move(): Reply|array|PsrResponseInterface
    {
        return $this->moveRecord($this->gridPage());
    }

    #[GetMapping(path: 'user/export')]
    public function export(): Reply|array|PsrResponseInterface
    {
        return $this->exportGrid($this->gridPage(), '用户-' . date('Ymd-His') . '.csv');
    }

    #[PostMapping(path: 'user/{id:\d+}/action')]
    public function action(int $id): Reply|array|PsrResponseInterface
    {
        return $this->dispatchRowAction($id, [
            'reset-password' => fn (int $target): Reply|array => $this->resetPassword($target),
        ]);
    }

    /**
     * 重置密码：随机生成一个，明文只在响应里出现一次。
     */
    private function resetPassword(int $id): Reply|array
    {
        $user = AdminUser::query()->find($id);

        if ($user === null) {
            return Reply::error('用户不存在', ErrorCode::NOT_FOUND);
        }

        $plain = bin2hex(random_bytes(4));
        $user->password = $plain;
        $user->save();

        AuditLog::record('reset-password', [
            'target' => AdminUser::class,
            'target_id' => $id,
            'summary' => '重置密码：' . $user->username,
        ]);

        // 只刷新列表 + 强提示（不动页面跳转）
        return Reply::success('新密码：' . $plain . '（请立即转告用户）')
            ->refresh()
            ->alert('warning');
    }

    private function formPage(bool $creating): Form
    {
        $form = new Form(new AdminUser());

        // 分页签 + 一行两列（Form::tab() / Form::row()）
        $form->tab('基本信息', function (Form $form): void {
            $form->text('username', '账号')->rules('required|max:64|alpha_dash');
            $form->text('name', '姓名')->rules('required|max:64');
            $form->row(['username', 'name'], 2);
            $form->upload('avatar', '头像')->image()->multiple()->maxSize(2);
        });

        $form->tab('账号安全', function (Form $form) use ($creating): void {
            $form->password('password', $creating ? '密码' : '密码（留空不修改）')
                ->rules($creating ? 'required|min:6|max:64' : 'min:6|max:64');
            $form->checkbox('roles', '角色')->options(static fn (): array => Role::optionMap());
        });

        return $form;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function syncRoles(int $userId, array $payload): void
    {
        Db::table('admin_role_user')->where('user_id', $userId)->delete();

        foreach ((array) ($payload['roles'] ?? []) as $roleId) {
            $roleId = (int) $roleId;

            if ($roleId > 0) {
                Db::table('admin_role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
            }
        }
    }
}
