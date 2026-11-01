<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Free time picker (`n-time-picker`).
 *
 * Naive UI has no range variant, so `isRange(true)` renders two pickers and
 * keeps the value as a `[start, end]` pair.
 */
class TimePicker extends TimeComponent
{
    protected bool $is_range = false;

    protected string $start_placeholder = '开始时间';

    protected string $end_placeholder = '结束时间';

    /**
     * Selectable window, e.g. `18:00:00 - 23:00:00`.
     */
    protected string $selectableRange = '';

    public function isRange(bool $isRange = true): static
    {
        $this->is_range = $isRange;

        return $this;
    }

    public function startPlaceholder(string $startPlaceholder): static
    {
        $this->is_range = true;
        $this->start_placeholder = $startPlaceholder;

        return $this;
    }

    public function endPlaceholder(string $endPlaceholder): static
    {
        $this->is_range = true;
        $this->end_placeholder = $endPlaceholder;

        return $this;
    }

    public function selectableRange(string $selectableRange): static
    {
        $this->selectableRange = $selectableRange;

        return $this;
    }
}
