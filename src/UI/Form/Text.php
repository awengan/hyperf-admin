<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Single line text input (`n-input`).
 */
class Text extends AbstractField
{
    protected string $type = 'text';

    protected string $placeholder = '';

    protected bool $disabled = false;

    protected bool $clearable = true;

    protected bool $readonly = false;

    protected int $maxlength = 0;

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

    public function readonly(bool $readonly = true): static
    {
        $this->readonly = $readonly;

        return $this;
    }

    public function maxlength(int $maxlength): static
    {
        $this->maxlength = $maxlength;

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
