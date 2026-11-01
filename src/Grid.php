<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin;

use Awengan\HyperfAdmin\Helper\Utils;
use Awengan\HyperfAdmin\Traits\AdminJsonBuilder;
use Awengan\HyperfAdmin\UI\Grid\Column;
use Closure;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Model;
use Hyperf\HttpServer\Contract\RequestInterface;

use function Hyperf\Support\make;

/**
 * Grid (list) page builder.
 *
 * Pagination is produced without `hyperf/paginator` so the component needs no
 * extra composer dependency. The payload mirrors what
 * `LengthAwarePaginator::toArray()` used to return, which is what `n-pagination`
 * needs on the frontend:
 *
 *     { data: [...], current_page: 1, per_page: 10, total: 42, last_page: 5 }
 */
class Grid extends AdminJsonBuilder
{
    /**
     * `pageSize` / `rowKey` 不直接暴露：前者已等同于 `data.per_page`，后者统一以
     * snake_case 的 `row_key` 输出（见 getData()），避免 payload 里出现两份同义字段。
     *
     * `columns` 也必须隐藏：它现在是 `Column` 对象（持有 Grid 引用），
     * 直接序列化会变成循环引用；由 `getData()` 用 `Column::toArray()` 展开。
     */
    protected array $hideAttrs = ['model', 'queryCallback', 'pageSize', 'rowKey', 'columns', 'rowActions', 'columnGroups', 'activeGroup', 'treeParentField', 'orderableField', 'rowActionForms'];

    protected Model $model;

    protected string $type = 'grid';

    protected string $title = '';

    /**
     * @var array<int, array{field: string, label: string}>
     */
    protected array $columns = [];

    /**
     * 合并表头的分组（`columnGroup()` 声明）。
     *
     * @var array<int, array{label: string, columns: array<int, Column>}>
     */
    protected array $columnGroups = [];

    /**
     * 正在收集列的分组下标（`columnGroup(closure)` 期间有效）。
     */
    protected ?int $activeGroup = null;

    /**
     * 树形表格：用哪个字段做父子关系（空 = 普通平铺表格）。
     */
    protected string $treeParentField = '';

    /**
     * 排序按钮用的字段（空 = 不显示上移/下移）。
     */
    protected string $orderableField = '';

    /**
     * 行操作的弹层表单（不参与序列化）。
     *
     * @var array<string, Closure>
     */
    protected array $rowActionForms = [];

    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    protected int $pageSize = 10;

    /**
     * Endpoint used for pagination, editing and creating.
     */
    protected string $url = '';

    /**
     * Column holding the primary key (used by the "edit" action).
     */
    protected string $rowKey = 'id';

    protected ?Closure $queryCallback = null;

    /**
     * 是否显示「删除」操作（行内 + 批量删除按钮）。
     */
    protected bool $deletable = false;

    /**
     * 是否显示行选择列（批量操作需要）。
     */
    protected bool $selectable = false;

    /**
     * 只读：不显示「新增 / 编辑 / 删除 / 批量删除」等任何写操作入口。
     */
    protected bool $readonly = false;

    /**
     * 关键字搜索字段：`字段 => 标签`。
     *
     * @var array<string, string>
     */
    protected array $searchable = [];

    /**
     * 允许排序的列（白名单）。
     *
     * @var array<int, string>
     */
    protected array $sortable = [];

    /**
     * 是否显示「导出」按钮。
     */
    protected bool $exportable = false;

    /**
     * 自定义行操作（`rowAction()` 声明，前端渲染成按钮）。
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $rowActions = [];

    /**
     * 列级筛选支持的操作符白名单（前端传来的 `type` 必须在这里面）。
     *
     * @var array<int, string>
     */
    private const COLUMN_OPERATORS = ['like', 'startWith', 'equal', 'input', 'in', 'gt', 'lt', 'between'];

    public function __construct(Model $model)
    {
        $this->model = $model;
        // 构造函数会预置 id 列（沿用旧版行为），调用方不要重复 column('id')。
        $this->column('id', 'ID');
    }

    /**
     * 合并表头：一组列共用一个大标题。
     *
     *     $grid->columnGroup('基本信息', function (Grid $grid) {
     *         $grid->column('username', '账号');
     *         $grid->column('name', '姓名');
     *     });
     */
    public function columnGroup(string $label, Closure $callback): static
    {
        $this->columnGroups[] = ['label' => $label, 'columns' => []];
        $this->activeGroup = count($this->columnGroups) - 1;

        $callback($this);

        $this->activeGroup = null;

        return $this;
    }

    /**
     * 树形表格：按 `parent_id`（或自定义字段）把平铺结果拼成 children。
     *
     * 需要 `rowKey` 是主键；菜单管理这类层级数据直接受益。
     */
    public function tree(string $parentField = 'parent_id'): static
    {
        $this->treeParentField = $parentField;

        return $this;
    }

    /**
     * 显示上移 / 下移按钮（不需要拖拽库）。
     *
     * 配合 `#[PostMapping('/{base}/{id:\d+}/move')]` 使用，见 exportMove()。
     */
    public function orderable(string $field = 'order'): static
    {
        $this->orderableField = $field;

        return $this;
    }

    /**
     * 打开「导出」（CSV，Excel 可直接打开）。
     *
     * 前端会给三个范围：全部 / 当前页 / 勾选行。
     */
    public function exportable(bool $exportable = true): static
    {
        $this->exportable = $exportable;

        return $this;
    }

    /**
     * 导出 CSV 内容（带 UTF-8 BOM，Excel 打开不乱码）。
     *
     * @param array<int, int|string> $ids 只导出这些主键（空 = 按当前筛选全量）
     */
    public function exportCsv(array $ids = []): string
    {
        $request = null;

        if (ApplicationContext::hasContainer()) {
            /** @var RequestInterface $request */
            $request = make(RequestInterface::class);
        }

        $keyword = $request === null ? '' : trim((string) $request->input('q', ''));
        $filterValues = $request === null ? [] : (array) $request->input('filters', []);
        $orderBy = $request === null ? '' : (string) $request->input('order_by', '');
        $order = strtolower($request === null ? '' : (string) $request->input('order', ''));

        $query = $this->model->newQuery();

        if ($this->queryCallback instanceof Closure) {
            $query = call_user_func($this->queryCallback, $query) ?? $query;
        }

        $this->applyConditions($query, $keyword, $filterValues, $orderBy, $order);

        // 勾选行：只导这些（同时仍然受上面的筛选约束，避免越权导出）
        if ($ids !== []) {
            $query->whereIn($this->rowKey, $ids);
        }

        $fields = array_column($this->columnFields(), 0);
        $rows = $query->select($fields)->limit(50000)->get()->toArray();

        $handle = fopen('php://temp', 'r+');

        // BOM：Excel 认它才知道是 UTF-8
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_map(static fn (Column $column): string => $column->label, $this->allColumns()));

        foreach ($rows as $row) {
            $line = [];

            foreach ($this->allColumns() as $column) {
                $line[] = $this->stringify($row[$column->field] ?? null);
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * 导出到单元格文本：数组用逗号、布尔用是/否、null 用空串。
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        if (is_array($value)) {
            return implode(', ', array_map(static fn ($item): string => (string) $item, $value));
        }

        return (string) $value;
    }

    /**
     * 只读列表（如审计日志）：隐藏所有操作列与新增按钮。
     */
    public function readonly(bool $readonly = true): static    {
        $this->readonly = $readonly;

        if ($readonly) {
            $this->deletable = false;
            $this->selectable = false;
        }

        return $this;
    }

    /**
     * 关键字搜索：`?q=xxx` 会在这些字段上做 `LIKE %xxx%`（OR 关系）。
     *
     * @param array<int|string, string> $fields `['title', 'uri']` 或 `['title' => '名称']`
     */
    public function searchable(array $fields): static
    {
        foreach ($fields as $key => $label) {
            $field = is_int($key) ? (string) $label : (string) $key;
            $title = is_int($key) ? (string) $label : (string) $label;

            if ($field !== '') {
                $this->searchable[$field] = $title !== '' ? $title : $field;
            }
        }

        return $this;
    }

    /**
     * 允许按哪些列排序（`?order_by=created_at&order=desc`）。
     *
     * 只允许列名白名单，避免 SQL 注入。
     *
     * @param array<int, string> $fields 留空 = 所有已声明的列
     */
    public function sortable(array $fields = []): static
    {
        $this->sortable = $fields;

        return $this;
    }

    /**
     * 打开行内删除（前端会二次确认，调用 `POST {url}/{id}/delete`）。
     */
    public function deletable(bool $deletable = true): static
    {
        $this->deletable = $deletable;

        return $this;
    }

    /**
     * 打开行选择（前端会出现勾选列 + 批量删除，调用 `POST {url}/batch-delete`）。
     */
    public function selectable(bool $selectable = true): static
    {
        $this->selectable = $selectable;

        return $this;
    }

    /**
     * 加一列，返回 {@see Column} 以便挂展示器 / 列级筛选：
     *
     *     $grid->column('avatar', '头像')->image('40x40');
     *     $grid->column('created_at', '创建时间')->between(true)->date();
     *
     * `Column` 的 `__call()` 会把未知方法转发回 Grid，所以
     * `->column('a')->column('b')->deletable()` 这种老写法依然有效。
     */
    public function column(string $field, string $label = ''): Column
    {
        $column = new Column($this, $field, $label !== '' ? $label : $field);

        // 在 columnGroup() 的闭包里声明的列，归到那一组
        if ($this->activeGroup !== null) {
            $this->columnGroups[$this->activeGroup]['columns'][] = $column;

            return $column;
        }

        $this->columns[] = $column;

        return $column;
    }

    /**
     * Narrow the underlying query.
     */
    public function query(Closure $callback): static
    {
        $this->queryCallback = $callback;

        return $this;
    }

    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function pageSize(int $pageSize): static
    {
        $this->pageSize = max(1, $pageSize);

        return $this;
    }

    public function rowKey(string $rowKey): static
    {
        $this->rowKey = $rowKey;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $data = parent::getData();
        $data['row_key'] = $this->rowKey;

        // 前端据此渲染搜索栏 / 表头排序
        $data['searchable'] = $this->searchable;
        $data['sortable'] = $this->sortableFields();

        // 列定义改用 Column::toArray()（带展示器与列级筛选）；顶层 columns 由
        // AdminJsonBuilder 输出，这里覆盖掉，保持「只输出一份」。
        $data['tree'] = $this->treeParentField !== '';
        $data['tree_parent'] = $this->treeParentField;
        $data['orderable'] = $this->orderableField;
        $data['column_groups'] = array_map(
            static fn (array $group): array => [
                'label' => $group['label'],
                'columns' => array_map(static fn (Column $column): array => $column->toArray(), $group['columns']),
            ],
            $this->columnGroups
        );
        $data['columns'] = array_map(
            static fn (Column $column): array => $column->toArray(),
            $this->columns
        );

        // 行操作（自定义动作按钮）
        $data['row_actions'] = $this->rowActions;
        $data['exportable'] = $this->exportable;

        return $data;
    }

    /**
     * 实际的排序白名单：显式声明优先，否则用所有列（含列级 `sortable()`）。
     *
     * @return array<int, string>
     */
    private function sortableFields(): array
    {
        if ($this->sortable !== []) {
            return $this->sortable;
        }

        $fields = [];

        foreach ($this->allColumns() as $column) {
            if ($column->sortable) {
                $fields[] = $column->field;
            }
        }

        return $fields !== [] ? $fields : array_column($this->columnFields(), 0);
    }

    /**
     * @return array<int, string>
     */
    /**
     * 所有列（含合并表头分组里的）。
     *
     * @return array<int, Column>
     */
    private function allColumns(): array
    {
        $all = $this->columns;

        foreach ($this->columnGroups as $group) {
            foreach ($group['columns'] as $column) {
                $all[] = $column;
            }
        }

        return $all;
    }

    private function columnFields(): array
    {
        $fields = [];

        foreach ($this->allColumns() as $column) {
            $fields[] = [$column->field];
        }

        return $fields;
    }

    protected function beforeBuild(): void
    {
        if ($this->columns === []) {
            return;
        }

        if ($this->url === '' && ApplicationContext::hasContainer()) {
            $this->url = Utils::currentPath();
        }

        $page = 1;
        $keyword = '';
        $filterValues = [];
        $orderBy = '';
        $order = '';

        if (ApplicationContext::hasContainer()) {
            /** @var RequestInterface $request */
            $request = make(RequestInterface::class);
            $page = max(1, (int) $request->input('page', 1));
            $keyword = trim((string) $request->input('q', ''));
            $filterValues = (array) $request->input('filters', []);
            $orderBy = (string) $request->input('order_by', '');
            $order = strtolower((string) $request->input('order', ''));
        }

        $query = $this->model->newQuery();

        if ($this->queryCallback instanceof Closure) {
            $query = call_user_func($this->queryCallback, $query) ?? $query;
        }

        // 关键字：在 searchable 字段里做 OR LIKE
        if ($keyword !== '' && $this->searchable !== []) {
            $query->where(function ($builder) use ($keyword): void {
                foreach (array_keys($this->searchable) as $field) {
                    $builder->orWhere($field, 'like', '%' . $keyword . '%');
                }
            });
        }


        // 列级筛选：`?filters[字段][type]=值`，操作符白名单，列名必须已声明
        $this->applyColumnFilters($query, $filterValues);

        // 排序：列名必须在白名单里，方向只允许 asc/desc。
        // 用 reorder() 而不是 orderBy() —— 否则 `query()` 里预设的 orderBy
        // 会排在前面，用户点表头排序看起来「没反应」。
        if ($orderBy !== '' && in_array($orderBy, $this->sortableFields(), true)) {
            $query->reorder($orderBy, $order === 'desc' ? 'desc' : 'asc');
        }

        $total = (clone $query)->count();
        $fields = array_column($this->columnFields(), 0);

        $rows = $query->select($fields)
            ->forPage($page, $this->pageSize)
            ->get()
            ->toArray();

        if ($this->treeParentField !== '') {
            $rows = $this->nest($rows, $this->treeParentField);
        }

        $lastPage = max(1, (int) ceil($total / $this->pageSize));

        $this->data = [
            'data' => $rows,
            'current_page' => $page,
            'per_page' => $this->pageSize,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $rows === [] ? 0 : ($page - 1) * $this->pageSize + 1,
            'to' => $rows === [] ? 0 : ($page - 1) * $this->pageSize + count($rows),
            // 回显当前查询条件，前端刷新时能保持一致
            'q' => $keyword,
            'filter_values' => $filterValues,
            'order_by' => $orderBy,
            'order' => $order,
        ];
    }

    /**
     * 当前模型（控制器里做排序交换时要用）。
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * 排序字段（空 = 不支持上移/下移）。
     */
    public function orderableField(): string
    {
        return $this->orderableField;
    }

    /**
     * 取某个行操作对应的表单 schema（前端弹层用）。
     */
    public function rowActionForm(string $name): ?Form
    {
        $factory = $this->rowActionForms[$name] ?? null;

        return $factory === null ? null : $factory(new Form($this->model->newInstance()));
    }

    /**
     * 把平铺结果按父字段拼成 `children`（树形表格）。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function nest(array $rows, string $parentField): array
    {
        $byId = [];
        $order = [];

        foreach ($rows as $index => $row) {
            $row['children'] = [];
            $byId[(string) ($row[$this->rowKey] ?? '')] = $row;
            $order[] = (string) ($row[$this->rowKey] ?? '');
        }

        $roots = [];

        foreach ($order as $id) {
            $row = $byId[$id];
            $parent = (string) ($row[$parentField] ?? '0');

            // 父节点不在本页（或就是自己）就当根节点，避免整页消失
            if ($parent === '' || $parent === '0' || $parent === $id || ! isset($byId[$parent])) {
                $roots[] = $id;

                continue;
            }

            $byId[$parent]['children'][] = $id;
        }

        // 自底向上装配（先算出每个节点的子数组）
        $build = static function (string $id) use (&$build, &$byId): array {
            $row = $byId[$id];
            $children = [];

            foreach ($row['children'] as $childId) {
                $children[] = $build((string) $childId);
            }

            $row['children'] = $children;

            return $row;
        };

        $nested = [];

        foreach ($roots as $id) {
            $nested[] = $build($id);
        }

        return $nested;
    }

    /**
     * 声明一个自定义行操作按钮。
     *
     * 前端会向 `POST {url}/{id}/action` 发 `{action: name}`，控制器里用一个
     * action 方法 switch 分发即可 —— **不用为每个动作单开一条路由**。
     *
     * @param array{type?: string, confirm?: string, reload?: bool} $options
     */
    public function rowAction(string $name, string $label, array $options = []): static
    {
        // 弹层表单：`rowAction('rename', '重命名', ['form' => fn (Form $f) => ...])`
        if (isset($options['form']) && $options['form'] instanceof Closure) {
            $this->rowActionForms[$name] = $options['form'];

            unset($options['form']);
            $options['form'] = true;
        }

        $this->rowActions[] = array_merge(
            ['name' => $name, 'label' => $label, 'type' => 'default', 'confirm' => '', 'reload' => true],
            $options
        );

        return $this;
    }

    /**
     * 便捷方法：加一个「查看」按钮（需要控制器提供 `GET {url}/{id}`）。
     */
    public function viewAction(string $label = '查看'): static
    {
        $this->rowActions[] = array_merge(
            ['name' => '__view', 'label' => $label, 'type' => 'default', 'confirm' => '', 'reload' => false, 'link' => true],
            []
        );

        return $this;
    }

    /**
     * 关键字 + 列筛选 + 排序（列表与导出共用，保证导出结果和页面一致）。
     *
     * @param mixed $query
     * @param array<string, mixed> $filterValues
     */
    private function applyConditions($query, string $keyword, array $filterValues, string $orderBy, string $order): void
    {
        // 关键字：在 searchable 字段里做 OR LIKE
        if ($keyword !== '' && $this->searchable !== []) {
            $query->where(function ($builder) use ($keyword): void {
                foreach (array_keys($this->searchable) as $field) {
                    $builder->orWhere($field, 'like', '%' . $keyword . '%');
                }
            });
        }

        $this->applyColumnFilters($query, $filterValues);

        // 排序：列名必须在白名单里，方向只允许 asc/desc。
        // 用 reorder() 而不是 orderBy() —— 否则 `query()` 里预设的 orderBy
        // 会排在前面，用户点表头排序看起来「没反应」。
        if ($orderBy !== '' && in_array($orderBy, $this->sortableFields(), true)) {
            $query->reorder($orderBy, $order === 'desc' ? 'desc' : 'asc');
        }
    }

    /**
     * 应用列级筛选：`?filters[字段][type]=...&filters[字段][value]=...`
     *
     * 只处理**已声明了筛选**的列，且操作符必须在白名单里 —— 这两个限制
     * 让「前端能传的查询」完全收敛到后端声明的范围内。
     *
     * @param mixed $query
     * @param array<string, mixed> $filterValues
     */
    private function applyColumnFilters($query, array $filterValues): void
    {
        foreach ($this->allColumns() as $column) {
            if ($column->filter === null) {
                continue;
            }

            $raw = $filterValues[$column->field] ?? null;

            // 只认结构化形状 `{type, value}` / `{type, start, end}`
            if (! is_array($raw)) {
                continue;
            }

            $type = (string) ($raw['type'] ?? $column->filter['type']);

            if (! in_array($type, self::COLUMN_OPERATORS, true)) {
                continue;
            }

            $field = $column->field;
            $value = $raw['value'] ?? '';
            $start = $raw['start'] ?? '';
            $end = $raw['end'] ?? '';

            switch ($type) {
                case 'like':
                    if (is_string($value) && $value !== '') {
                        $query->where($field, 'like', '%' . $value . '%');
                    }
                    break;

                case 'startWith':
                    if (is_string($value) && $value !== '') {
                        $query->where($field, 'like', $value . '%');
                    }
                    break;

                case 'equal':
                case 'input':
                    if ($value !== '' && $value !== null) {
                        $query->where($field, $value);
                    }
                    break;

                case 'in': {
                    $values = is_array($value) ? $value : [$value];
                    $values = array_values(array_filter($values, static fn ($v): bool => $v !== '' && $v !== null));

                    if ($values !== []) {
                        $query->whereIn($field, $values);
                    }
                    break;
                }

                case 'gt':
                    if ($value !== '' && $value !== null) {
                        $query->where($field, '>', $value);
                    }
                    break;

                case 'lt':
                    if ($value !== '' && $value !== null) {
                        $query->where($field, '<', $value);
                    }
                    break;

                case 'between':
                    if ($start !== '' && $end !== '') {
                        $query->whereBetween($field, [$start, $end]);
                    } elseif ($start !== '') {
                        $query->where($field, '>=', $start);
                    } elseif ($end !== '') {
                        $query->where($field, '<=', $end);
                    }
                    break;
            }
        }
    }
}
