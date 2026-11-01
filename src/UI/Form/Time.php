<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

use Awengan\HyperfAdmin\Exception\FormComponentException;

/**
 * Time field (`n-time-picker`, or a generated `n-select`).
 *
 * The concrete control is composed rather than copied onto this object (dynamic
 * properties are deprecated in PHP 8.2 and removed in PHP 9): `getData()`
 * flattens the control's properties into the field payload, so the JSON shape
 * is identical to the Element UI implementation.
 */
class Time extends AbstractField
{
    protected array $hideAttrs = ['default', 'time_component'];

    protected string $type = 'time';

    protected string $time_type = '';

    protected ?TimeComponent $time_component = null;

    protected bool $arrow_control = false;

    protected bool $readonly = false;

    protected bool $disabled = false;

    protected bool $editable = true;

    protected bool $clearable = true;

    protected string $size = 'medium';

    protected string $align = 'left';

    /**
     * @param string $timeType `select` (fixed list) or `picker` (free picker)
     */
    public function type(string $timeType): static
    {
        if (! in_array($timeType, ['select', 'picker'], true)) {
            return $this;
        }

        $this->time_type = $timeType;
        $this->time_component = $timeType === 'select'
            ? new TimeSelect()
            : new TimePicker();

        return $this;
    }

    /**
     * Forward `start()`, `end()`, `step()`, `isRange()`, `selectableRange()`,
     * `startPlaceholder()`, `endPlaceholder()` and `placeholder()` to the
     * concrete control.
     */
    public function __call(string $name, array $arguments): static
    {
        $component = $this->component();

        if (! method_exists($component, $name)) {
            throw new FormComponentException(sprintf(
                'The time component [%s] has no method [%s].',
                $this->time_type,
                $name
            ));
        }

        $component->{$name}(...$arguments);

        return $this;
    }

    public function arrowControl(bool $arrowControl = true): static
    {
        $this->arrow_control = $arrowControl;

        return $this;
    }

    public function readonly(bool $readonly = true): static
    {
        $this->readonly = $readonly;

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    public function editable(bool $editable = true): static
    {
        $this->editable = $editable;

        return $this;
    }

    public function clearable(bool $clearable = true): static
    {
        $this->clearable = $clearable;

        return $this;
    }

    public function size(string $size): static
    {
        $this->size = $this->normalizeSize($size, $this->size);

        return $this;
    }

    /**
     * @deprecated Naive UI aligns the picker automatically; kept for API
     * compatibility.
     */
    public function align(string $align): static
    {
        if (in_array($align, ['left', 'center', 'right'], true)) {
            $this->align = $align;
        }

        return $this;
    }

    /**
     * Field payload with the concrete control's properties flattened in.
     */
    public function getData(): array
    {
        $data = parent::getData();

        foreach ($this->component()->getData() as $key => $value) {
            $data[$key] = $value;
        }

        return $data;
    }

    protected function beforeBuild(): void
    {
        parent::beforeBuild();

        // Element UI required an explicit `type()` call; defaulting to the
        // fixed list keeps old forms working.
        if ($this->time_type === '') {
            $this->type('select');
        }
    }

    private function component(): TimeComponent
    {
        if ($this->time_component === null) {
            $this->type('select');
        }

        return $this->time_component;
    }
}
