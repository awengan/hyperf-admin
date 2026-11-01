<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Multi-line text input (`n-input type="textarea"` with autosize).
 */
class Textarea extends AbstractField
{
    protected string $type = 'textarea';

    protected int $min_rows = 2;

    protected int $max_rows = 4;

    protected string $placeholder = '';

    protected bool $disabled = false;

    protected bool $clearable = true;

    public function minRows(int $rows): static
    {
        $this->min_rows = max(1, $rows);

        return $this;
    }

    public function maxRows(int $rows): static
    {
        $this->max_rows = max($this->min_rows, $rows);

        return $this;
    }

    /**
     * Snake_case aliases kept for backwards compatibility.
     */
    public function min_rows(int $rows): static
    {
        return $this->minRows($rows);
    }

    public function max_rows(int $rows): static
    {
        return $this->maxRows($rows);
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    public function clearable(bool $clearable = true): static
    {
        $this->clearable = $clearable;

        return $this;
    }

    protected function beforeBuild(): void
    {
        parent::beforeBuild();

        if ($this->placeholder === '') {
            $this->placeholder = '请输入' . $this->label;
        }
    }
}
