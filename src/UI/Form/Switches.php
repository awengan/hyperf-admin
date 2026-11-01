<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Toggle (`n-switch`).
 */
class Switches extends AbstractField
{
    protected string $type = 'switch';

    /**
     * Colour of the rail while the switch is on.
     */
    protected string $active_color = '#18a058';

    /**
     * Colour of the rail while the switch is off.
     */
    protected string $inactive_color = '#00000073';

    protected string $active_text = '';

    protected string $inactive_text = '';

    protected mixed $active_value = 1;

    protected mixed $inactive_value = 0;

    protected bool $disabled = false;

    public function activeColor(string $activeColor): static
    {
        $this->active_color = $activeColor;

        return $this;
    }

    public function inactiveColor(string $inactiveColor): static
    {
        $this->inactive_color = $inactiveColor;

        return $this;
    }

    public function activeText(string $activeText): static
    {
        $this->active_text = $activeText;

        return $this;
    }

    public function inactiveText(string $inactiveText): static
    {
        $this->inactive_text = $inactiveText;

        return $this;
    }

    /**
     * Value emitted when the switch turns on (bool, string or int).
     */
    public function activeValue(mixed $activeValue): static
    {
        if (is_bool($activeValue) || is_string($activeValue) || is_int($activeValue)) {
            $this->active_value = $activeValue;
        }

        return $this;
    }

    /**
     * Value emitted when the switch turns off (bool, string or int).
     */
    public function inactiveValue(mixed $inactiveValue): static
    {
        if (is_bool($inactiveValue) || is_string($inactiveValue) || is_int($inactiveValue)) {
            $this->inactive_value = $inactiveValue;
        }

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }
}
