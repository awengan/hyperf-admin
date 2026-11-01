<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Numeric input (`n-input-number`).
 */
class Number extends AbstractField
{
    protected string $type = 'number';

    protected int|float $min = 0;

    protected int|float $max = 100;

    protected int|float $step = 1;

    protected int $precision = 0;

    protected bool $step_strictly = false;

    protected bool $disabled = false;

    protected bool $show_button = true;

    protected string $placeholder = '';

    public function min(int|float $min): static
    {
        $this->min = $min;

        return $this;
    }

    public function max(int|float $max): static
    {
        $this->max = $max;

        return $this;
    }

    public function step(int|float $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function precision(int $precision): static
    {
        $this->precision = max(0, $precision);

        return $this;
    }

    public function stepStrictly(bool $stepStrictly = true): static
    {
        $this->step_strictly = $stepStrictly;

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    /**
     * Naive UI steppers can be hidden; Element UI always rendered them.
     */
    public function showButton(bool $showButton = true): static
    {
        $this->show_button = $showButton;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

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
