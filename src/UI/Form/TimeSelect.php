<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Fixed-interval time list (Element UI's `el-time-select`).
 *
 * Naive UI ships no equivalent, so the frontend renders an `n-select` whose
 * options are generated from `start`, `end` and `step`.
 */
class TimeSelect extends TimeComponent
{
    protected string $start = '';

    protected string $end = '';

    protected string $step = '';

    protected string $placeholder = '选择时间';

    public function start(string $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function end(string $end): static
    {
        $this->end = $end;

        return $this;
    }

    /**
     * Interval between two options, e.g. `00:15`.
     */
    public function step(string $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }
}
