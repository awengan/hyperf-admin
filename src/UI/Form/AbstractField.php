<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

use Awengan\HyperfAdmin\Traits\AdminJsonBuilder;

/**
 * Base class of every form field.
 *
 * A field serialises to `['type' => .., 'name' => .., 'label' => .., ...props]`
 * and the frontend resolves `type` to a Naive UI component.
 */
abstract class AbstractField extends AdminJsonBuilder
{
    /**
     * `default` seeds `form_data` and must not leak into the field payload.
     */
    protected array $hideAttrs = ['default'];

    /**
     * Frontend component key (`text`, `select`, `date`, ...).
     */
    protected string $type = '';

    public string $name = '';

    public string $label = '';

    public mixed $default = '';

    /**
     * 校验规则（Laravel 风格字符串），例如 `'required|max:64'`。
     *
     * 会被 `Form::rules()` 收集：服务端用它做权威校验，前端拿它生成
     * Naive UI 的表单校验（能映射的规则才做客户端提示，其余交给服务端）。
     */
    public string $rules = '';

    /**
     * 联动规则：`[依赖字段 => [值 => [要显示/隐藏的字段]]]`。
     *
     * @var array<string, array<string, array<int, string>>>
     */
    public array $depends = [];

    /**
     * 所属 Tab 标题（`Form::tab()` 里加的字段会自动带上）。
     */
    public string $tab = '';

    public function __construct(string $name = '', string $label = '')
    {
        $this->name = $name;
        $this->label = $label !== '' ? $label : $name;
    }

    /**
     * Default value used when the form is rendered for creation.
     */
    public function default(mixed $default): static
    {
        $this->default = $default;

        return $this;
    }

    /**
     * 设置校验规则。
     *
     * 数组形式会立刻拼成 `|` 分隔的字符串 —— 这样字段 payload 里的 `rules`
     * 永远是字符串，前端不必处理两种形状。
     *
     * @param string|array<int, mixed> $rules `'required|max:64'` 或 `['required', 'max:64']`
     */
    /**
     * 字段联动：依赖的字段等于某个值时，才显示/隐藏。
     *
     *     $form->select('type', '类型')->options(['text' => '文本', 'image' => '图片']);
     *     $form->text('content', '内容')->depends('type', ['text']);          // 简化写法
     *     $form->upload('cover', '封面')->depends('type', ['image' => ['cover']]);
     *
     * 规则下发到前端，由 FieldList 按当前值过滤可见字段。
     */
    public function depends(string $field, array $rules): static
    {
        if (array_is_list($rules)) {
            $list = $rules;
            $rules = [];

            foreach ($list as $value) {
                $rules[(string) $value] = [(string) $this->name];
            }
        }

        foreach ($rules as $value => $fields) {
            $this->depends[$field][(string) $value] = array_values((array) $fields);
        }

        return $this;
    }

    public function rules(string|array $rules): static
    {
        $this->rules = is_array($rules) ? implode('|', array_map('strval', $rules)) : $rules;

        return $this;
    }

    /**
     * 快捷方法：必填。
     */
    public function required(bool $required = true): static
    {
        return $this->rules($required ? 'required' : '');
    }

    public function getRules(): string
    {
        return $this->rules;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Naive UI only knows `small|medium|large`; the Element UI `mini` alias is
     * folded into `small`.
     */
    protected function normalizeSize(string $size, string $default = 'medium'): string
    {
        return match ($size) {
            'mini', 'small' => 'small',
            'large' => 'large',
            'medium' => 'medium',
            default => $default,
        };
    }

    protected function beforeBuild(): void
    {
        if ($this->label === '') {
            $this->label = $this->name;
        }
    }
}
