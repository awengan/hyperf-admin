<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Date + time picker (`n-date-picker` with `datetime` / `datetimerange`).
 */
class DateTime extends Date
{
    /**
     * The frontend dispatches on `type`, so it must not stay `date`.
     */
    protected string $type = 'datetime';

    protected string $date_type = 'datetime';

    /**
     * Time applied to a single (non range) date time picker.
     */
    protected ?string $defaultTime = null;

    /**
     * @param string $type `datetime` or `datetimerange`
     */
    public function type(string $type): static
    {
        if (in_array($type, ['datetime', 'datetimerange'], true)) {
            $this->date_type = $type;
        }

        return $this;
    }

    /**
     * Default time of day applied when a date is picked.
     */
    public function defaultTime(string $defaultTime): static
    {
        if ($this->date_type === 'datetime') {
            $this->defaultTime = $defaultTime;
        }

        return $this;
    }
}
