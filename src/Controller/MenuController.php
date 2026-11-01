<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Form;
use Awengan\HyperfAdmin\Grid;
use Awengan\HyperfAdmin\Helper\AuditLog;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Middleware\AdminAuthMiddleware;
use Awengan\HyperfAdmin\Middleware\ErrorPageMiddleware;
use Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware;
use Awengan\HyperfAdmin\Middleware\SecurityHeadersMiddleware;
use Awengan\HyperfAdmin\Middleware\CsrfMiddleware;
use Awengan\HyperfAdmin\Middleware\PermissionMiddleware;
use Awengan\HyperfAdmin\Middleware\AuditLogMiddleware;
use Awengan\HyperfAdmin\Model\Menu;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 菜单管理：列表 / 新增 / 编辑 / 删除。
 *
 * 这是「照着抄就能写自己的模块」的完整范例 —— 表结构、字段、路由、校验、保存都在一个文件里。
 * 数据来自 `admin_menu` 表（`database/admin.sql` 里已建好并带种子数据）。
 *
 * 注意：侧边栏目前仍由 `hyperf-admin.menus` 配置（`MenuHelper`）驱动，
 * 这里的增删改不会实时反映到左侧菜单；需要的话把 `MenuHelper::getMenu()`
 * 换成 `Menu::toMenuItems((new Menu())->toTree())`。
 */
#[Controller(prefix: '/admin')]
#[Middleware(SecurityHeadersMiddleware::class)]
#[Middleware(ErrorPageMiddleware::class)]
#[Middleware(ReplyStatusMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
#[Middleware(AdminAuthMiddleware::class)]
#[Middleware(AuditLogMiddleware::class)]
#[Middleware(PermissionMiddleware::class)]
class MenuController extends AbstractAdminController
{
    /**
     * 编辑时用于把「自己」从上级候选里排除（`Menu::selectTree($id)`）。
     */
    private int $editingId = 0;

    /**
     * 供下拉框使用的图标名（前端 `lib/icons.js` 内置的 SVG 集合）。
     *
     * @var array<string, string>
     */
    private const ICONS = [
        'home' => '首页',
        'table' => '表格',
        'document' => '文档',
        'settings' => '设置',
        'menu' => '菜单',
        'user' => '用户',
    ];

    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response
    ) {
        parent::__construct($request, $response);
    }

    #[GetMapping(path: 'menu')]
    public function index(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->gridPage());
    }

    #[GetMapping(path: 'menu/create')]
    public function create(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->formPage()->title('新增菜单'));
    }

    #[GetMapping(path: 'menu/{id:\d+}/edit')]
    public function edit(int $id): Reply|array|PsrResponseInterface
    {
        $form = $this->formPage($id)->title('编辑菜单')->url('/menu/' . $id);
        $form->edit($id);

        return $this->page($form);
    }

    #[PostMapping(path: 'menu/store')]
    public function store(): Reply|array|PsrResponseInterface
    {
        return $this->storeForm($this->formPage());
    }

    #[PostMapping(path: 'menu/{id:\d+}')]
    public function update(int $id): Reply|array|PsrResponseInterface
    {
        return $this->updateForm($this->formPage($id), $id);
    }

    #[PostMapping(path: 'menu/{id:\d+}/delete')]
    public function destroy(int $id): Reply|array|PsrResponseInterface
    {
        // 有子菜单就先别删，避免孤儿节点
        $children = Db::table('admin_menu')->where('parent_id', $id)->count();

        if ($children > 0) {
            return Reply::error('该菜单下还有 ' . $children . ' 个子菜单，请先删除子菜单', 42200);
        }

        return $this->deleteRecord(Menu::class, $id, '菜单');
    }

    /**
     * 行内改值（Column::switch() 用）。
     */
    #[PostMapping(path: 'menu/{id:\d+}/toggle')]
    public function toggle(int $id): Reply|array|PsrResponseInterface
    {
        return $this->toggleColumn(Menu::class, $id, ['is_menu', 'order'], '菜单');
    }

    /**
     * 行操作分发（含带弹层表单的 `rename`）。
     */
    #[PostMapping(path: 'menu/{id:\d+}/action')]
    public function action(int $id): Reply|array|PsrResponseInterface
    {
        return $this->dispatchRowAction($id, [
            'rename' => function (int $target): Reply|array|PsrResponseInterface {
                $menu = Menu::query()->find($target);

                if ($menu === null) {
                    return Reply::error('菜单不存在', 40400);
                }

                $title = trim((string) $this->request->input('title', ''));

                if ($title === '') {
                    return Reply::error('名称不能为空', 42200, ['title' => ['请填写名称']]);
                }

                $menu->title = $title;
                $menu->save();

                AuditLog::record('update', [
                    'target' => Menu::class,
                    'target_id' => $target,
                    'summary' => '重命名菜单：' . $title,
                ]);

                return Reply::success('已重命名')->refresh();
            },
        ]);
    }

        /**
     * 导出 CSV（全部 / 当前页 / 勾选行）。
     */
    /**
     * 行操作弹层表单的 schema（前端点按钮时来取）。
     */
    #[GetMapping(path: 'menu/{id:\d+}/action-form')]
    public function actionForm(): Reply|array|PsrResponseInterface
    {
        return $this->rowActionFormPayload();
    }

    /**
     * 上移 / 下移（`Grid::orderable()` 用）。
     */
    #[PostMapping(path: 'menu/{id:\d+}/move')]
    public function move(): Reply|array|PsrResponseInterface
    {
        return $this->moveRecord($this->gridPage());
    }
    #[GetMapping(path: 'menu/export')]
    public function export(): Reply|array|PsrResponseInterface
    {
        return $this->exportGrid($this->gridPage(), '菜单-' . date('Ymd-His') . '.csv');
    }
#[PostMapping(path: 'menu/batch-delete')]
    public function batchDestroy(): Reply|array|PsrResponseInterface
    {
        return $this->deleteRecords(Menu::class, null, '菜单');
    }

    /**
     * 父级候选（供其它模块做上级下拉时复用）。
     */
    #[GetMapping(path: 'menu/options')]
    public function options(): Reply|array|PsrResponseInterface
    {
        return Reply::success('ok', );
    }

    protected function gridPage(): Grid
    {
        $grid = new Grid(new Menu());

        // 注意：Grid 构造函数已经自动加了 id 列，这里不要再 column('id') —— 否则会重复一列。
        $grid->title('菜单管理')
            ->url('/menu')
            ->pageSize(200)
            // 树形表格：按 parent_id 拼 children（菜单天然是层级的）
            ->tree('parent_id')
            // 上移 / 下移
            ->orderable('order')
            ->column('parent_id', '上级')
            // 合并表头：基本信息和路由分成两大组
            ->columnGroup('基本信息', static function (Grid $grid): void {
                $grid->column('title', '名称');
                $grid->column('icon', '图标');
            })
            ->columnGroup('跳转', static function (Grid $grid): void {
                $grid->column('uri', '路由')->fixed('left');
                $grid->column('order', '排序');
            })
            ->column('is_menu', '显示为菜单')->switch()
            ->deletable()
            ->exportable()
            ->selectable()
            ->searchable(['title' => '名称', 'uri' => '路由'])
            // 带弹层表单的行操作：点「重命名」在弹层里填，不用跳页
            ->rowAction('rename', '重命名', [
                'form' => static function (Form $form): Form {
                $form->text('title', '名称');

                return $form;
            },
            ])
            // 先按 parent_id 再按 order，父子才不会被 order 交错在一起；
            // `order` 是 MySQL 保留字，交给 query builder 转义。
            ->query(static fn ($query) => $query->orderBy('parent_id')->orderBy('order')->orderBy('id'));

        return $grid;
    }

    private function formPage(?int $exceptId = null): Form
    {
        $this->editingId = (int) ($exceptId ?? 0);

        $form = new Form(new Menu());

        $form->treeSelect('parent_id', '上级菜单')
            ->options(fn (): array => Menu::selectTree($this->editingId))
            ->default(0)
            ->required()
            ->clearable(false);
        $form->text('title', '名称')->rules('required|max:64');
        $form->select('icon', '图标')->options(self::ICONS)->default('document');
        $form->text('uri', '路由')->placeholder('不带前导斜杠，例如 grid')->rules('max:255');
        $form->number('order', '排序')->default(0)->min(0)->max(999)->rules('integer|between:0,999');
        $form->switch('is_menu', '显示为菜单')->default(1);

        return $form;
    }

    /**
     * 父级候选：0 = 顶级，其余按树形缩进。
     *
     * @return array<int|string, string>
     */
    private function parentOptions(): Reply|array
    {
        $options = [0 => '顶级菜单'];

        foreach (Menu::selectOptions()->all() as $id => $title) {
            if ((int) $id === 0) {
                continue;
            }

            $options[$id] = $title;
        }

        return $options;
    }
}
