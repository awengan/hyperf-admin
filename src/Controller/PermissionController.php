<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Form;
use Awengan\HyperfAdmin\Grid;
use Awengan\HyperfAdmin\Middleware\AdminAuthMiddleware;
use Awengan\HyperfAdmin\Middleware\ErrorPageMiddleware;
use Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware;
use Awengan\HyperfAdmin\Middleware\SecurityHeadersMiddleware;
use Awengan\HyperfAdmin\Middleware\CsrfMiddleware;
use Awengan\HyperfAdmin\Middleware\PermissionMiddleware;
use Awengan\HyperfAdmin\Middleware\AuditLogMiddleware;
use Awengan\HyperfAdmin\Model\Permission;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 权限管理：权限字典的增删改查。
 *
 * `slug` 是被代码引用的稳定标识（例如 `role.manage`），改它要同步改控制器里的
 * `$this->guard('...')`。
 */
#[Controller(prefix: '/admin')]
#[Middleware(SecurityHeadersMiddleware::class)]
#[Middleware(ErrorPageMiddleware::class)]
#[Middleware(ReplyStatusMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
#[Middleware(AdminAuthMiddleware::class)]
#[Middleware(AuditLogMiddleware::class)]
#[Middleware(PermissionMiddleware::class)]
class PermissionController extends AbstractAdminController
{
    public function __construct(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        parent::__construct($request, $response);
    }

    #[GetMapping(path: 'permission')]
    public function index(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->gridPage());
    }

    #[GetMapping(path: 'permission/create')]
    public function create(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->formPage()->title('新增权限'));
    }

    #[GetMapping(path: 'permission/{id:\d+}/edit')]
    public function edit(int $id): Reply|array|PsrResponseInterface
    {
        $form = $this->formPage()->title('编辑权限')->url('/permission/' . $id);
        $form->edit($id);

        return $this->page($form);
    }

    #[PostMapping(path: 'permission/store')]
    public function store(): Reply|array|PsrResponseInterface
    {
        return $this->storeForm($this->formPage());
    }

    #[PostMapping(path: 'permission/{id:\d+}')]
    public function update(int $id): Reply|array|PsrResponseInterface
    {
        return $this->updateForm($this->formPage(), $id);
    }

    #[PostMapping(path: 'permission/{id:\d+}/delete')]
    public function destroy(int $id): Reply|array|PsrResponseInterface
    {
        $result = $this->deleteRecord(Permission::class, $id, '权限');

        if ($result instanceof Reply ? $result->isOk() : (int) ($result['code'] ?? 0) === 200) {
            // 权限删了，角色与它的关联也要清掉
            $this->transaction(static fn () => Db::table('admin_role_permission')->where('permission_id', $id)->delete());
        }

        return $result;
    }

        /**
     * 导出 CSV（全部 / 当前页 / 勾选行）。
     */
    /**
     * 行操作弹层表单的 schema（前端点按钮时来取）。
     */
    #[GetMapping(path: 'permission/{id:\d+}/action-form')]
    public function actionForm(): Reply|array|PsrResponseInterface
    {
        return $this->rowActionFormPayload();
    }

    /**
     * 上移 / 下移（`Grid::orderable()` 用）。
     */
    #[PostMapping(path: 'permission/{id:\d+}/move')]
    public function move(): Reply|array|PsrResponseInterface
    {
        return $this->moveRecord($this->gridPage());
    }
    #[GetMapping(path: 'permission/export')]
    public function export(): Reply|array|PsrResponseInterface
    {
        return $this->exportGrid($this->gridPage(), '权限-' . date('Ymd-His') . '.csv');
    }
#[PostMapping(path: 'permission/batch-delete')]
    public function batchDestroy(): Reply|array|PsrResponseInterface
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->input('ids', []))));
        $result = $this->deleteRecords(Permission::class, $ids, '权限');

        if ($ids !== []) {
            $this->transaction(static fn () => Db::table('admin_role_permission')->whereIn('permission_id', $ids)->delete());
        }

        return $result;
    }

    protected function gridPage(): Grid
    {
        $grid = new Grid(new Permission());

        $grid->title('权限管理')
            ->url('/permission')
            ->pageSize(20)
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
        $form = new Form(new Permission());

        $form->text('name', '名称')->rules('required|max:64');
        // 内置标识都带点（user.manage / log.view），所以这里必须允许 `.`
        $form->text('slug', '标识')->placeholder('如 role.manage')
            ->rules('required|max:64|regex:/^[A-Za-z0-9._-]+$/');
        $form->textarea('remark', '备注')->rules('max:255');

        return $form;
    }
}
