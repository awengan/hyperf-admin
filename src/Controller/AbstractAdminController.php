<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Constants\ErrorCode;
use Awengan\HyperfAdmin\Content;
use Awengan\HyperfAdmin\Form;
use Awengan\HyperfAdmin\Grid;
use Awengan\HyperfAdmin\Helper\ActionResponse;
use Awengan\HyperfAdmin\Helper\AuditLog;
use Awengan\HyperfAdmin\Helper\Auth;
use Awengan\HyperfAdmin\Helper\Forbidden;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Helper\Utils;
use Awengan\HyperfAdmin\Traits\AdminJsonBuilder;
use Awengan\HyperfAdmin\UI;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 面板控制器基类。
 *
 * 同一个页面服务两种请求：
 *  - SPA 的 `fetch`（带 `X-Requested-With` / `Accept: application/json`）返回 JSON 信封；
 *  - 浏览器直接访问（如地址栏输入 `GET /grid`、刷新页面）返回带首屏数据的 HTML 外壳，
 *    所以深链接和刷新都能用。
 *
 * 依赖用构造器注入而不是 `#[Inject]`：宿主的注解扫描路径默认只有 `app/`，
 * 构造器注入不依赖扫描结果，组件里更稳。
 */
abstract class AbstractAdminController
{
    public function __construct(
        protected RequestInterface $request,
        protected ResponseInterface $response
    ) {
    }

    /**
     * @return array<string, mixed>|PsrResponseInterface
     */
    protected function page(AdminJsonBuilder $builder): Reply|array|PsrResponseInterface
    {
        if (Utils::isAjax($this->request)) {
            return $builder->jsonSerialize();
        }

        return $this->shell(['page' => $builder->getData()]);
    }

    /**
     * @param array<string, mixed> $boot
     */
    protected function shell(array $boot = []): PsrResponseInterface
    {
        // 控制器持有请求，因此由它把当前登录用户（规范化后的 profile）交给 UI 渲染。
        // 外壳 HTML 必须不缓存：它携带指向 `/static/v<hash>/…` 的版本指针。
        return $this->response
            ->html(UI::render(array_merge(['user' => Auth::profile()], $boot)))
            ->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function json(array $payload): PsrResponseInterface
    {
        return $this->response->json($payload);
    }

    /**
     * 校验失败：HTTP 422 + `data.errors`（字段 => 消息数组）。
     *
     * @param array<string, array<int, string>> $errors
     */
    protected function validationError(array $errors): PsrResponseInterface
    {
        $first = '';

        foreach ($errors as $messages) {
            $first = (string) ($messages[0] ?? '');
            break;
        }

        return $this->response
            ->json(Result::error(ErrorCode::VALIDATION_ERROR, $first !== '' ? $first : '参数校验失败', ['errors' => $errors]))
            ->withStatus(422);
    }

    /**
     * 表单「新增」：校验 + 保存。
     */
    protected function storeForm(Form $form): Reply|array|PsrResponseInterface
    {
        $payload = $this->request->all();

        if ($errors = $form->validate($payload)) {
            return $this->validationError($errors);
        }

        return $form->store();
    }

    /**
     * 表单「更新」：校验 + 保存。
     */
    protected function updateForm(Form $form, int|string $id): Reply|array|PsrResponseInterface
    {
        $payload = $this->request->all();

        if ($errors = $form->validate($payload)) {
            return $this->validationError($errors);
        }

        return $form->update($id);
    }

    /**
     * 通用删除：删一行 + 记审计日志。
     */
    protected function deleteRecord(string $modelClass, int|string $id, string $label = ''): Reply|array
    {
        $model = $modelClass::query()->find($id);

        if ($model === null) {
            return Reply::error('记录不存在', ErrorCode::NOT_FOUND);
        }

        $title = (string) ($model->title ?? $model->name ?? $model->username ?? $id);

        AuditLog::record('delete', [
            'target' => $modelClass,
            'target_id' => $id,
            'summary' => sprintf('删除%s：%s (#%s)', $label !== '' ? $label : '记录', $title, (string) $id),
        ]);

        $model->delete();

        return Reply::success('删除成功', ['id' => $id]);
    }

    /**
     * 通用批量删除。
     *
     * @param array<int, int|string>|null $ids 不传则取请求里的 `ids`
     */
    protected function deleteRecords(string $modelClass, ?array $ids = null, string $label = ''): Reply|array
    {
        $ids = $ids ?? (array) $this->request->input('ids', []);
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return Reply::error('请先选择要删除的记录', ErrorCode::VALIDATION_ERROR);
        }

        $deleted = $modelClass::query()->whereIn('id', $ids)->delete();

        AuditLog::record('batch-delete', [
            'target' => $modelClass,
            'summary' => sprintf('批量删除%s：%d 条（id: %s）', $label !== '' ? $label : '记录', count($ids), implode(',', $ids)),
        ]);

        return Reply::success('', ['deleted' => $deleted, 'ids' => $ids], sprintf('已删除 %d 条', $deleted));
    }

    /**
     * 行操作分发（配合 `Grid::rowAction()`）。
     *
     * 前端点自定义动作按钮时会 `POST {url}/{id}/action`，body 里带 `action` 名称。
     * 控制器只写**一个** action 方法，用这个 helper 按名字分发：
     *
     *     #[PostMapping(path: 'user/{id:\d+}/action')]
     *     public function action(int $id): Reply|array|PsrResponseInterface
     *     {
     *         return $this->dispatchRowAction($id, [
     *             'reset-password' => fn () => $this->resetPassword($id),
     *             'enable' => fn () => $this->toggleEnabled($id, true),
     *         ]);
     *     }
     *
     * @param array<string, callable> $handlers 动作名 => 处理函数（返回 array|PsrResponseInterface）
     */
    protected function dispatchRowAction(int|string $id, array $handlers): Reply|array|PsrResponseInterface
    {
        $name = (string) $this->request->input('action');

        if ($name === '' || ! isset($handlers[$name])) {
            return Reply::error('未知的操作：' . $name, ErrorCode::VALIDATION_ERROR);
        }

        return $handlers[$name]($id);
    }

    /**
     * 通用行内改值：`POST {url}/{id}/toggle`，body `{field: value}`。
     *
     * 供 `Column::switch()` / `Column::editable()` 用。只允许改白名单里的字段，
     * 避免前端传个 `password` 上来。
     *
     * @param array<int, string> $allowedFields
     */
    protected function toggleColumn(string $modelClass, int|string $id, array $allowedFields, string $label = ''): Reply|array
    {
        $field = (string) $this->request->input('field', '');

        if (! in_array($field, $allowedFields, true)) {
            return Reply::error('不允许修改字段：' . $field, ErrorCode::VALIDATION_ERROR);
        }

        $model = $modelClass::query()->find($id);

        if ($model === null) {
            return Reply::error('记录不存在', ErrorCode::NOT_FOUND);
        }

        $value = $this->request->input('value');

        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        $model->{$field} = $value;
        $model->save();

        AuditLog::record('update', [
            'target' => $modelClass,
            'target_id' => $id,
            'summary' => sprintf('行内修改%s #%s 的 %s', $label !== '' ? $label : '记录', (string) $id, $field),
            'payload' => [$field => $value],
        ]);

        return Reply::success('已保存')
            ->refresh()
            ->with(['field' => $field, 'value' => $value]);
    }

    /**
     * 行操作弹层：返回表单 schema。
     */
    protected function rowActionFormPayload(): Reply|array|PsrResponseInterface
    {
        $grid = $this->gridPage();
        $name = (string) $this->request->input('action', '');
        $form = $grid->rowActionForm($name);

        if ($form === null) {
            return Reply::error('这个操作没有表单：' . $name, ErrorCode::NOT_FOUND);
        }

        return Reply::success('ok', [
            'action' => $name,
            // Form 没有 toArray()（会被 __call 当成字段名），取它的数据负载
            'form' => $form->getData(),
        ]);
    }

    /**
     * 上移 / 下移：和目标行交换排序值。
     */
    protected function moveRecord(Grid $grid): Reply|array|PsrResponseInterface
    {
        $id = (int) $this->request->route('id', 0);
        $direction = (string) $this->request->input('direction', 'up');
        $field = $grid->orderableField();

        if ($field === '') {
            return Reply::error('这个列表不支持排序', ErrorCode::VALIDATION_ERROR);
        }

        $model = $grid->getModel();
        $record = $model->newQuery()->find($id);

        if ($record === null) {
            return Reply::error('记录不存在', ErrorCode::NOT_FOUND);
        }

        $current = (int) $record->{$field};

        // 找相邻的那一行（同一个排序值的方向上最近的一条）
        $neighbour = $model->newQuery()
            ->where($field, $direction === 'up' ? '<' : '>', $current)
            ->orderBy($field, $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if ($neighbour === null) {
            return Reply::success('已经在最' . ($direction === 'up' ? '前' : '后') . '面了')->refresh();
        }

        $this->transaction(function () use ($record, $neighbour, $field, $current): void {
            $record->{$field} = $neighbour->{$field};
            $record->save();

            $neighbour->{$field} = $current;
            $neighbour->save();
        });

        return Reply::success('排序已调整')->refresh();
    }

    /**
     * 把多表写入包进事务。
     *
     * 一个业务动作常常要写 2~3 张表（角色 + 两张中间表、用户 + 角色关联…），
     * 中途失败会留下「角色存了但权限没存」这种脏数据 —— 而且审计日志已经记成成功。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function transaction(callable $callback): mixed
    {
        return Db::transaction($callback);
    }

    /**
     * 导出 CSV：带 BOM（Excel 不乱码）+ 下载头。
     */
    protected function csvDownload(string $filename, string $csv): PsrResponseInterface
    {
        return $this->response
            ->raw($csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * 通用导出：`?scope=all|page|selected&ids=1,2&page=2`（其余筛选参数原样透传）。
     */
    protected function exportGrid(Grid $grid, string $filename): PsrResponseInterface
    {
        $scope = (string) $this->request->input('scope', 'all');
        $ids = [];

        if ($scope === 'selected') {
            $ids = array_values(array_filter(
                array_map('intval', explode(',', (string) $this->request->input('ids', '')))
            ));
        }

        $csv = $grid->exportCsv($ids);

        AuditLog::record('export', [
            'summary' => sprintf('导出 %s（scope=%s，%d 条勾选）', $filename, $scope, count($ids)),
            'payload' => ['scope' => $scope, 'ids' => $ids],
        ]);

        return $this->csvDownload($filename, $csv);
    }

    /**
     * 权限守卫（手动版）。
     *
     * 常规的「按路径前缀拦截」已经由 {@see \Awengan\HyperfAdmin\Middleware\PermissionMiddleware}
     * 自动完成，**不需要**在每个 action 里手写。这里保留给两类场景：
     *  - 同一个路径下不同 action 需要不同权限
     *  - 需要在控制器内部（比如保存前）再确认一次
     */
    protected function guard(string $permission): ?PsrResponseInterface
    {
        if (Auth::can($permission)) {
            return null;
        }

        return Forbidden::response($this->request, $this->response, $permission);
    }
}
