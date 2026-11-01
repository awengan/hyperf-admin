<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

use Awengan\HyperfAdmin\Traits\ListField;

/**
 * Radio group (`n-radio-group` + `n-radio` / `n-radio-button`).
 */
class Radio extends AbstractField
{
    use ListField;

    protected string $type = 'radio';

    /**
     * @var array<int, mixed>
     */
    protected array $disabled = [];

    /**
     * Naive UI has no "bordered radio"; the frontend renders a bordered card
     * for every key listed here.
     *
     * @var array<int, mixed>
     */
    protected array $border = [];

    protected bool $button = false;

    protected string $size = 'medium';

    /**
     * Disable individual options (values must exist in `options()`).
     *
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

    /**
     * Render as `n-radio-button` instead of plain radios.
     */
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
}
