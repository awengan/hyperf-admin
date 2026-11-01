<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin;

use Awengan\HyperfAdmin\Helper\Utils;
use Awengan\HyperfAdmin\Traits\AdminJsonBuilder;
use Awengan\HyperfAdmin\UI\Show\Field;
use Closure;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Model;

/**
 * 详情页构建器（只读）。
 *
 *     $show = new Show(new AdminUser());
 *     $show->title('用户详情')->url('/user/' . $id);
 *     $show->panel('基本信息', function (Show $show) {
 *         $show->field('username', '账号');
 *         $show->field('name', '姓名');
 *         $show->field('avatar', '头像')->image();
 *     });
 *     $show->panel('角色', function (Show $show) {
 *         $show->field('roles', '角色')->expand();
 *     }, columns: 1);
 *
 * 分节（`panel`）是详情页最小的组织单位：一节 = 一个卡片，里面 1~2 列字段。
 * 值由 `edit($id)`（名字留着是为了和 Form 对称）从模型读出来。
 */
class Show extends AdminJsonBuilder
{
    protected array $hideAttrs = ['model', 'sections', 'current', 'pendingFields'];

    protected Model $model;

    protected string $type = 'show';

    protected string $title = '';

    /**
     * 字段值（`字段 => 值`）。
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * 分节：`[{title, columns, fields: [...]}]`。
     *
     * @var array<int, array{title: string, columns: int, fields: array<int, array<string, mixed>>}>
     */
    protected array $sections = [];

    /**
     * 正在往哪一节里加字段。
     */
    protected int $current = -1;

    /**
     * 当前节里还没定型的字段对象（`panel()` 结束时收集成数组）。
     *
     * @var array<int, Field>
     */
    protected array $pendingFields = [];

    protected string $url = '';

    /**
     * 额外注入的值（非模型属性，如关联数据）。
     *
     * @var array<string, mixed>
     */
    protected array $extraData = [];

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * 当前页地址（供「返回列表」按钮用）。
     */
    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * 加一个字段，返回 {@see Field} 以便挂展示器。
     */
    public function field(string $name, string $label = ''): Field
    {
        if ($this->current < 0) {
            $this->panel('', static function (): void {
            });
        }

        $field = new Field($name, $label !== '' ? $label : $name);
        $this->pendingFields[] = $field;

        return $field;
    }

    /**
     * 开一节（卡片）。`columns` 为 1 时单列，2 时两列。
     *
     * 回调里加的字段都归这一节。
     */
    public function panel(string $title, ?Closure $callback = null, int $columns = 2): static
    {
        $this->flush();

        $this->sections[] = ['title' => $title, 'columns' => max(1, min(2, $columns)), 'fields' => []];
        $this->current = count($this->sections) - 1;
        $this->pendingFields = [];

        if ($callback instanceof Closure) {
            $callback($this);
            $this->flush();
        }

        return $this;
    }

    /**
     * 分隔线（视觉分组，不带标题）。
     */
    public function divider(string $title = ''): static
    {
        return $this->panel($title, null, 1);
    }

    /**
     * 注入非模型属性的值（比如关联查出来的 oles）。
     *
     * @param array<string, mixed> $values
     */
    public function with(array $values): static
    {
        $this->extraData = array_merge($this->extraData, $values);

        return $this;
    }

    /**
     * 从模型读值（可指定主键 id，也可以用已绑定的模型）。
     */
    public function edit(int|string|null $id = null): static
    {
        if ($id !== null) {
            $model = $this->model->newQuery()->find($id);

            if ($model !== null) {
                $this->model = $model;
            }
        }

        $this->data = array_merge($this->model->toArray(), $this->extraData);

        if ($this->url === '' && ApplicationContext::hasContainer()) {
            $this->url = Utils::currentPath();
        }

        return $this;
    }

    /**
     * 把待定字段收进当前节。
     */
    private function flush(): void
    {
        if ($this->current < 0 || $this->pendingFields === []) {
            $this->pendingFields = [];

            return;
        }

        foreach ($this->pendingFields as $field) {
            $this->sections[$this->current]['fields'][] = $field->toArray();
        }

        $this->pendingFields = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->flush();

        $data = parent::getData();
        $data['url'] = $this->url;
        $data['sections'] = $this->sections;

        return $data;
    }
}
