<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

use Closure;

/**
 * Date picker (`n-date-picker`).
 *
 * Values stay strings: the frontend drives Naive UI through
 * `formatted-value` + `value-format`, exactly like Element UI's `value-format`.
 *
 * Mapping notes:
 *  - `type` is translated to the closest Naive UI picker type (`week` and
 *    `dates` fall back to `date`).
 *  - `shortcuts` map to Naive UI's `shortcuts` prop.
 *  - `disabledDateByEarliest` / `disabledDateByDeadline` map to `isDateDisabled`.
 *  - `startDefaultTime` / `endDefaultTime` / `defaultTime` map to `defaultTime`.
 *  - `unlinkPanels` and `align` have no Naive UI counterpart and are ignored.
 */
class Date extends AbstractField
{
    protected string $type = 'date';

    protected string $date_type = 'date';

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $shortcuts = [];

    protected bool $readonly = false;

    protected bool $disabled = false;

    protected bool $editable = true;

    protected bool $clearable = true;

    protected string $size = 'medium';

    protected string $align = 'left';

    protected string $placeholder = '选择日期';

    protected string $range_separator = '至';

    protected string $start_placeholder = '开始日期';

    protected string $end_placeholder = '结束日期';

    protected bool $unlink_panels = false;

    protected ?string $startDefaultTime = null;

    protected ?string $endDefaultTime = null;

    /**
     * Earliest selectable timestamp in milliseconds.
     */
    protected ?int $disabledDateByEarliest = null;

    /**
     * Latest selectable timestamp in milliseconds.
     */
    protected ?int $disabledDateByDeadline = null;

    /**
     * Picker granularity: `date`, `week`, `month`, `year`, `dates`, `months`,
     * `years`, `daterange`, `monthrange`.
     */
    public function type(string $type): static
    {
        $allowed = ['date', 'week', 'month', 'year', 'dates', 'months', 'years', 'daterange', 'monthrange'];

        if (in_array($type, $allowed, true)) {
            $this->date_type = $type;
        }

        return $this;
    }

    /**
     * Shortcut buttons.
     *
     * Single pickers: `['text' => '今天', 'time' => 'today'|int|Closure]`
     * Range pickers:  `['text' => '最近一周', 'start' => .., 'end' => ..]`
     *
     * Timestamps are milliseconds, matching JavaScript's `Date`.
     *
     * @param array<string, mixed> $shortcuts
     */
    public function shortcuts(array $shortcuts): static
    {
        $single = ['date', 'datetime', 'week', 'month', 'year', 'dates', 'months', 'years'];
        $range = ['daterange', 'datetimerange', 'monthrange'];

        if (isset($shortcuts['text'], $shortcuts['time']) && in_array($this->date_type, $single, true)) {
            if ($shortcuts['time'] instanceof Closure) {
                $shortcuts['time'] = $shortcuts['time']();
            }

            $this->shortcuts[] = $shortcuts;
        } elseif (isset($shortcuts['text'], $shortcuts['start'], $shortcuts['end'])
            && in_array($this->date_type, $range, true)) {
            if ($shortcuts['start'] instanceof Closure) {
                $shortcuts['start'] = $shortcuts['start']();
            }

            if ($shortcuts['end'] instanceof Closure) {
                $shortcuts['end'] = $shortcuts['end']();
            }

            $this->shortcuts[] = $shortcuts;
        }

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

    /**
     * Whether the text input accepts typing (inverse of Naive UI's
     * `input-readonly`).
     */
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

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function rangeSeparator(string $rangeSeparator): static
    {
        $this->range_separator = $rangeSeparator;

        return $this;
    }

    public function startPlaceholder(string $startPlaceholder): static
    {
        $this->start_placeholder = $startPlaceholder;

        return $this;
    }

    public function endPlaceholder(string $endPlaceholder): static
    {
        $this->end_placeholder = $endPlaceholder;

        return $this;
    }

    /**
     * @deprecated Naive UI's dual panels are always independent; kept for API
     * compatibility.
     */
    public function unlinkPanels(bool $unlinkPanels = true): static
    {
        $this->unlink_panels = $unlinkPanels;

        return $this;
    }

    /**
     * Default time applied to the start of a range (range pickers only).
     */
    public function startDefaultTime(string $startDefaultTime): static
    {
        if (in_array($this->date_type, ['daterange', 'datetimerange', 'monthrange'], true)) {
            $this->startDefaultTime = $startDefaultTime;
        }

        return $this;
    }

    /**
     * Default time applied to the end of a range (range pickers only).
     */
    public function endDefaultTime(string $endDefaultTime): static
    {
        if (in_array($this->date_type, ['daterange', 'datetimerange', 'monthrange'], true)) {
            $this->endDefaultTime = $endDefaultTime;
        }

        return $this;
    }

    /**
     * Earliest selectable date (millisecond timestamp).
     */
    public function disabledDateByEarliest(int|Closure $disabledDateByEarliest): static
    {
        $this->disabledDateByDeadline = null;

        if ($disabledDateByEarliest instanceof Closure) {
            $disabledDateByEarliest = (int) $disabledDateByEarliest();
        }

        $this->disabledDateByEarliest = $disabledDateByEarliest;

        return $this;
    }

    /**
     * Latest selectable date (millisecond timestamp).
     */
    public function disabledDateByDeadline(int|Closure $disabledDateByDeadline): static
    {
        $this->disabledDateByEarliest = null;

        if ($disabledDateByDeadline instanceof Closure) {
            $disabledDateByDeadline = (int) $disabledDateByDeadline();
        }

        $this->disabledDateByDeadline = $disabledDateByDeadline;

        return $this;
    }
}
