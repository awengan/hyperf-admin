<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin;

use Awengan\HyperfAdmin\Exception\FormComponentException;
use Awengan\HyperfAdmin\Helper\Auth;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Reply;
use Awengan\HyperfAdmin\Helper\Utils;
use Awengan\HyperfAdmin\Traits\AdminJsonBuilder;
use Awengan\HyperfAdmin\UI\Form\Checkbox;
use Awengan\HyperfAdmin\UI\Form\KeyValue;
use Awengan\HyperfAdmin\UI\Form\Tags;
use Awengan\HyperfAdmin\UI\Form\Icon;
use Awengan\HyperfAdmin\UI\Form\Rate;
use Awengan\HyperfAdmin\UI\Form\Color;
use Awengan\HyperfAdmin\UI\Form\Date;
use Awengan\HyperfAdmin\UI\Form\DateTime;
use Awengan\HyperfAdmin\UI\Form\Number;
use Awengan\HyperfAdmin\UI\Form\Password;
use Awengan\HyperfAdmin\UI\Form\Radio;
use Awengan\HyperfAdmin\UI\Form\Select;
use Awengan\HyperfAdmin\UI\Form\Slider;
use Awengan\HyperfAdmin\UI\Form\Switches;
use Awengan\HyperfAdmin\UI\Form\Text;
use Awengan\HyperfAdmin\UI\Form\Textarea;
use Awengan\HyperfAdmin\UI\Form\Time;
use Awengan\HyperfAdmin\UI\Form\Transfer;
use Awengan\HyperfAdmin\UI\Form\TreeSelect;
use Awengan\HyperfAdmin\UI\Form\Upload;
use Closure;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Model;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

use function Hyperf\Support\make;

/**
 * Form page builder.
 *
 * @package HyperfAdmin
 * @method Text text(string $field, string $label = '')
 * @method Password password(string $field, string $label = '')
 * @method Select select(string $field, string $label = '')
 * @method Textarea textarea(string $field, string $label = '')
 * @method Radio radio(string $field, string $label = '')
 * @method Checkbox checkbox(string $field, string $label = '')
 * @method Number number(string $field, string $label = '')
 * @method Switches switch(string $field, string $label = '')
 * @method Slider slider(string $field, string $label = '')
 * @method Time time(string $field, string $label = '')
 * @method Date date(string $field, string $label = '')
 * @method DateTime datetime(string $field, string $label = '')
 * @method Transfer transfer(string $field, string $label = '')
 */
class Form extends AdminJsonBuilder
{
    protected array $hideAttrs = ['model', 'fields', 'formData', 'componentMap', 'rowOf', 'currentTab'];

    protected Model $model;

    protected string $type = 'form';

    protected string $title = '';

    /**
     * 分页签标题列表（`tab()` 声明，按出现顺序）。
     *
     * @var array<int, string>
     */
    protected array $tabs = [];

    /**
     * 当前正在构建的 Tab 标题（空 = 不在 Tab 里）。
     */
    protected string $currentTab = '';

    /**
     * `字段 => 栅格列数`（`row()` 声明）。
     *
     * @var array<string, int>
     */
    protected array $rowOf = [];

    /**
     * @var array<int, object>
     */
    protected array $fields = [];

    /**
     * Values coming from the database when editing.
     *
     * @var array<string, mixed>
     */
    protected array $formData = [];

    /**
     * Frontend payload: `['fields' => [...], 'form_data' => [...]]`.
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * Endpoint the form posts to.
     */
    protected string $url = '';

    /**
     * Where the frontend navigates after a successful submit.
     */
    protected string $jumpUrl = '';

    /**
     * Read-only forms render the widgets without a submit action
     * (`Form::readonly()`).
     */
    protected bool $readonly = false;

    /**
     * Explicit field type => class registry.
     *
     * An explicit map keeps the lookup case-sensitive safe on Linux, where
     * `ucfirst('datetime')` would resolve to a non existent `Datetime.php`, and
     * lets `switch` (a PHP keyword) point at the `Switches` class.
     *
     * @var array<string, class-string>
     */
    protected array $componentMap = [
        'text' => Text::class,
        'password' => Password::class,
        'select' => Select::class,
        'textarea' => Textarea::class,
        'radio' => Radio::class,
        'checkbox' => Checkbox::class,
        'number' => Number::class,
        'switch' => Switches::class,
        'switches' => Switches::class,
        'slider' => Slider::class,
        'time' => Time::class,
        'date' => Date::class,
        'datetime' => DateTime::class,
        'transfer' => Transfer::class,
        'upload' => Upload::class,
        'image' => Upload::class,
        // 注意：`addField()` 会先 strtolower()，所以这里必须是小写键
        'color' => Color::class,
        'rate' => Rate::class,
        'icon' => Icon::class,
        'tags' => Tags::class,
        'keyvalue' => KeyValue::class,
        'key-value' => KeyValue::class,
        'tree' => TreeSelect::class,
        'treeselect' => TreeSelect::class,
        'tree-select' => TreeSelect::class,
    ];

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function addField(string $type, string $name, string $label = ''): object
    {
        $key = strtolower($type);
        $class = $this->componentMap[$key] ?? null;

        if ($class === null) {
            throw new FormComponentException(sprintf('The form component [%s] does not exist.', $type));
        }

        $field = new $class($name, $label);

        // `tab()` 里加进来的字段带上分页签名，前端按它分组渲染
        if ($this->currentTab !== '') {
            $field->tab = $this->currentTab;
        }

        $this->fields[] = $field;

        return $field;
    }

    /**
     * 分页签：回调里加的字段归到 `$title` 这个 Tab。
     *
     *     $form->tab('基本信息', function (Form $form) {
     *         $form->text('username', '账号');
     *     });
     *     $form->tab('安全', function (Form $form) {
     *         $form->password('password', '密码');
     *     });
     */
    public function tab(string $title, Closure $callback): static
    {
        $previous = $this->currentTab;
        $this->currentTab = $title;

        if (! in_array($title, $this->tabs, true)) {
            $this->tabs[] = $title;
        }

        $callback($this);

        $this->currentTab = $previous;

        return $this;
    }

    /**
     * 一行放多个字段（`columns` 列栅格）。
     *
     *     $form->row(['账号', '姓名'], 2);   // 这两个字段并排
     *
     * 传字段名（而不是在回调里建字段），是为了让 `fields` 的声明顺序保持简单、
     * 也让 `rules()` / `validate()` 完全不受布局影响。
     *
     * @param array<int, string> $fields
     */
    public function row(array $fields, int $columns = 2): static
    {
        $columns = max(1, min(4, $columns));

        foreach ($fields as $field) {
            $this->rowOf[$field] = $columns;
        }

        return $this;
    }

    public function __call(string $name, array $arguments): object
    {
        return $this->addField($name, ...$arguments);
    }

    protected function beforeBuild(): void
    {
        if (! $this->readonly && $this->url === '' && ApplicationContext::hasContainer()) {
            // 用路径而不是完整 URL：前端要拿它当 fetch 目标与路由 key
            $this->url = self::relativeUrl(str_replace('/create', '/store', Utils::currentPath()));
        }

        $this->data = ['fields' => [], 'form_data' => []];

        foreach ($this->fields as $field) {
            $payload = $field->getData();

            // 布局信息挂在字段上，前端按 `tab` / `rowOf` 分组渲染
            if (isset($this->rowOf[$field->name])) {
                $payload['row'] = $this->rowOf[$field->name];
            }

            $this->data['fields'][] = $payload;
            $this->data['form_data'][$field->name] = $this->formData[$field->name] ?? $field->default;
        }

        if ($this->tabs !== []) {
            $this->data['tabs'] = $this->tabs;
        }
    }

    /**
     * Persist a new record and tell the SPA where to go next.
     */
    public function store(): Reply|array
    {
        $payload = Utils::getRequest()->all();

        foreach ($this->fields as $field) {
            $this->model->{$field->name} = $payload[$field->name] ?? null;
        }

        $this->model->save();

        return Reply::success('新增成功', [
            'jumpUrl' => $this->jumpUrl !== '' ? $this->jumpUrl : $this->getDefaultJumpUrl(),
        ]);
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

    /**
     * 手动填充 `form_data`（在 `edit()` 之后调用可覆盖某个字段的值）。
     *
     * 典型用途：多对多字段（角色的权限/用户、标签等）不是模型的属性，
     * 需要在 `edit()` 之后把中间表里已选中的 id 塞进去。
     *
     * @param array<string, mixed> $values
     */
    public function formData(array $values): static
    {
        $this->formData = array_merge($this->formData, $values);

        return $this;
    }

    /**
     * Load an existing record into `form_data`.
     */
    public function edit(int|string $id): static
    {
        if ($this->url === '' && ApplicationContext::hasContainer()) {
            $this->url = self::relativeUrl(str_replace('/edit', '', Utils::currentPath()));
        }

        $model = $this->model->find($id);

        if ($model === null) {
            throw new FormComponentException(sprintf('Record [%s] does not exist.', (string) $id));
        }

        $this->model = $model;

        foreach ($this->fields as $field) {
            $this->formData[$field->name] = $this->model->{$field->name};
        }

        return $this;
    }

    /**
     * 收集所有字段声明的校验规则。
     *
     * @return array<string, string>
     */
    public function rules(): Reply|array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $rule = method_exists($field, 'getRules') ? trim($field->getRules()) : '';

            if ($rule !== '') {
                $rules[$field->name] = $rule;
            }
        }

        return $rules;
    }

    /**
     * 服务端校验（权威）。通过返回 null，失败返回 `[字段 => [消息...]]`。
     *
     * 前端也会拿 `rules()` 生成 Naive UI 的校验做即时提示，但**只有这里算数** ——
     * 客户端校验可以被绕过。
     *
     * @param array<string, mixed> $data
     * @return array<string, array<int, string>>|null
     */
    public function validate(array $data): ?array
    {
        $rules = $this->rules();

        if ($rules === []) {
            return null;
        }

        /** @var ValidatorFactoryInterface $factory */
        $factory = make(ValidatorFactoryInterface::class);
        $validator = $factory->make($data, $rules, $this->validationMessages($rules), $this->labels());

        if (! $validator->fails()) {
            return null;
        }

        return $validator->errors()->toArray();
    }

    /**
     * 字段名 => 中文标签，用于拼校验消息。
     *
     * @return array<string, string>
     */
    private function labels(): Reply|array
    {
        $labels = [];

        foreach ($this->fields as $field) {
            $labels[$field->name] = $field->label !== '' ? $field->label : $field->name;
        }

        return $labels;
    }

    /**
     * 中文校验消息。
     *
     * 自己拼而不是依赖 translation 语言包：组件不该去改宿主的
     * `config/autoload/translation.php` 与 `storage/languages`。
     *
     * @param array<string, string> $rules
     * @return array<string, string>
     */
    private function validationMessages(array $rules): Reply|array
    {
        $labels = $this->labels();
        $templates = [
            'required' => '%s不能为空',
            'max' => '%s不能超过 %s',
            'min' => '%s不能少于 %s',
            'between' => '%s必须在 %s 之间',
            'integer' => '%s必须是整数',
            'numeric' => '%s必须是数字',
            'email' => '%s格式不正确',
            'alpha_dash' => '%s只能包含字母、数字、下划线和连字符',
            'in' => '%s的取值不在允许范围内',
            'date' => '%s不是有效日期',
            'confirmed' => '%s两次输入不一致',
            'same' => '%s与指定字段不一致',
            'unique' => '%s已经存在',
            'array' => '%s必须是数组',
        ];

        $messages = [];

        foreach ($rules as $field => $ruleString) {
            $label = $labels[$field] ?? $field;

            foreach (explode('|', $ruleString) as $rule) {
                $parts = explode(':', $rule, 2);
                $name = $parts[0];
                $parameter = $parts[1] ?? '';

                if (! isset($templates[$name])) {
                    continue;
                }

                $messages[$field . '.' . $name] = match ($name) {
                    'max' => sprintf($templates[$name], $label, $this->describeMax($field, $parameter)),
                    'min' => sprintf($templates[$name], $label, $this->describeMin($field, $parameter)),
                    'between' => sprintf($templates[$name], $label, str_replace(',', ' 和 ', $parameter)),
                    default => sprintf($templates[$name], $label),
                };
            }
        }

        return $messages;
    }

    private function describeMax(string $field, string $parameter): string
    {
        return ctype_digit(trim($parameter)) ? $parameter . ' 个字符' : $parameter;
    }

    private function describeMin(string $field, string $parameter): string
    {
        return ctype_digit(trim($parameter)) ? $parameter . ' 个字符' : $parameter;
    }

    public function update(int|string $id): Reply|array
    {
        $model = $this->model->find($id);

        if ($model === null) {
            throw new FormComponentException(sprintf('Record [%s] does not exist.', (string) $id));
        }

        $this->model = $model;
        $payload = Utils::getRequest()->all();

        foreach ($this->fields as $field) {
            $this->model->{$field->name} = $payload[$field->name] ?? null;
        }

        $this->model->save();

        return Reply::success('编辑成功', [
            'jumpUrl' => $this->jumpUrl !== '' ? $this->jumpUrl : $this->getDefaultJumpUrl(),
        ]);
    }

    public function jumpUrl(string $jumpUrl): static
    {
        $this->jumpUrl = $jumpUrl;

        return $this;
    }

    /**
     * Render the widgets without a submit action.
     */
    public function readonly(bool $readonly = true): static
    {
        $this->readonly = $readonly;

        return $this;
    }

    /**
     * Strip the trailing segment (`/store`, `/1`) to land on the list page.
     */
    protected function getDefaultJumpUrl(): string
    {
        $url = Utils::currentPath();
        $position = strrpos($url, '/');

        return self::relativeUrl($position === false || $position === 0 ? $url : substr($url, 0, $position));
    }

    /**
     * 去掉 `api_base` 前缀，得到前端用的**相对路径**。
     *
     * 前端 `http` 会把 `apiBase`（`/admin`）拼回去，所以 payload 里的 url 必须是
     * `/menu/store` 这种相对写法；否则浏览器会请求 `/admin/admin/menu/store` → 404。
     */
    private static function relativeUrl(string $path): string
    {
        $base = rtrim((string) Auth::config('api_base', ''), '/');

        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $relative = substr($path, strlen($base));

            return $relative === '' ? '/' : $relative;
        }

        return $path;
    }
}
