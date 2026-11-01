<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Slider (`n-slider`).
 *
 * Mapping notes:
 *  - `step` / `min` / `max` / `range` / `vertical` / `formatTooltip` are native.
 *  - `showStops` generates a tick mark per step on the frontend.
 *  - `showInput` renders a companion `n-input-number` next to the slider.
 *  - `height` is applied to the wrapper when `vertical` is on.
 */
class Slider extends AbstractField
{
    protected string $type = 'slider';

    protected bool $show_tooltip = true;

    /**
     * Divisor applied to the raw value before it is shown in the tooltip.
     */
    protected int|float $format_tooltip = 1;

    protected int|float $step = 1;

    protected bool $show_stops = false;

    protected bool $show_input = false;

    protected int|float $min = 0;

    protected int|float $max = 100;

    protected bool $range = false;

    protected bool $vertical = false;

    protected string $height = '0px';

    protected bool $disabled = false;

    public function showTooltip(bool $showTooltip = true): static
    {
        $this->show_tooltip = $showTooltip;

        return $this;
    }

    /**
     * Values are divided by this number in the tooltip (e.g. `10` renders a
     * `0..10` slider as `0..1`).
     */
    public function formatTooltip(int|float $formatTooltip): static
    {
        if ($formatTooltip != 0) {
            $this->format_tooltip = $formatTooltip;
        }

        return $this;
    }

    public function step(int|float $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function showStops(bool $showStops = true): static
    {
        $this->show_stops = $showStops;

        return $this;
    }

    public function showInput(bool $showInput = true): static
    {
        $this->show_input = $showInput;

        return $this;
    }

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

    public function range(bool $range = true): static
    {
        $this->range = $range;

        return $this;
    }

    /**
     * Switches to vertical mode and sets the wrapper height.
     */
    public function height(string $height): static
    {
        $this->vertical = true;
        $this->height = $height;

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    /**
     * @deprecated Naive UI renders labels through `marks`; a mark map is only
     * kept for API compatibility and is not applied by the frontend.
     *
     * @param array<int|string, mixed> $marks
     */
    public function marks(array $marks): static
    {
        return $this;
    }
}
