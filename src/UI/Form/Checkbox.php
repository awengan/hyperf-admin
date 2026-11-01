<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

use Awengan\HyperfAdmin\Traits\ListField;

/**
 * Checkbox group (`n-checkbox-group` + `n-checkbox`).
 *
 * Naive UI has no checkbox-button variant, so `button(true)` renders the same
 * bordered cards as `border()`.
 */
class Checkbox extends AbstractField
{
    use ListField;

    protected string $type = 'checkbox';

    /**
     * @var array<int, mixed>
     */
    protected array $disabled = [];

    /**
     * @var array<int, mixed>
     */
    protected array $border = [];

    protected bool $button = false;

    protected string $size = 'medium';

    protected int $min = 0;

    protected int $max = 0;

    /**
     * @param array<int, mixed> $disabled
     */
    public function disabled(array $disabled): static
    {
        $this->disabled = $this->onlyKnownOptions($disabled);

        return $this;
    }

    /**
     * @param array<int, mixed> $border
     */
    public function border(array $border): static
    {
        $this->border = $this->onlyKnownOptions($border);

        return $this;
    }

    public function button(bool $button = true): static
    {
        $this->button = $button;

        return $this;
    }

    public function size(string $size): static
    {
        $this->size = $this->normalizeSize($size, $this->size);

        return $this;
    }

    /**
     * Minimum amount of checked options (checked client side).
     */
    public function min(int $min): static
    {
        if ($min > 0) {
            $this->min = $min;
        }

        return $this;
    }

    /**
     * Maximum amount of checked options (checked client side).
     */
    public function max(int $max): static
    {
        if ($max > 0) {
            $this->max = $max;
        }

        return $this;
    }

    protected function beforeBuild(): void
    {
        parent::beforeBuild();

        if ($this->max === 0) {
            $this->max = count($this->options);
        }
    }
}
