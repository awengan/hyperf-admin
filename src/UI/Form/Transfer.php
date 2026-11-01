<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

use Awengan\HyperfAdmin\Traits\ListField;

/**
 * Transfer (`n-transfer`).
 *
 * Mapping notes:
 *  - `titles()` maps to `sourceTitle` / `targetTitle`.
 *  - `filterable()` is native; searching always matches the option label.
 *  - `filterMethod()` and `renderContent()` carried JavaScript source that the
 *    Element UI frontend executed with `new Function()`. The Naive UI rewrite
 *    deliberately does not evaluate server-provided code, so both props are
 *    accepted for API compatibility but ignored by the frontend.
 *  - `buttonTexts()` and `targetOrder()` have no Naive UI counterpart.
 */
class Transfer extends AbstractField
{
    use ListField;

    protected string $type = 'transfer';

    /**
     * @var array<int, mixed>
     */
    protected array $disabled = [];

    protected bool $filterable = false;

    protected string $filter_method = '';

    /**
     * @var array<int, string>
     */
    protected array $titles = [];

    /**
     * @var array<int, string>
     */
    protected array $button_texts = [];

    protected string $target_order = 'original';

    protected string $render_content = '';

    /**
     * @var array<int, mixed>
     */
    protected array $left_default_checked = [];

    /**
     * @var array<int, mixed>
     */
    protected array $right_default_checked = [];

    /**
     * @param array<int, mixed> $disabled
     */
    public function disabled(array $disabled): static
    {
        $this->disabled = $this->onlyKnownOptions($disabled);

        return $this;
    }

    public function filterable(bool $filterable = true): static
    {
        $this->filterable = $filterable;

        return $this;
    }

    /**
     * @deprecated the Naive UI frontend does not evaluate server-side
     * JavaScript; searching always matches the option label.
     */
    public function filterMethod(string $filterMethod): static
    {
        $this->filterable = true;
        $this->filter_method = $filterMethod;

        return $this;
    }

    public function titles(string $leftTitle, string $rightTitle): static
    {
        $this->titles = [$leftTitle, $rightTitle];

        return $this;
    }

    /**
     * @deprecated no Naive UI counterpart, kept for API compatibility.
     */
    public function buttonTexts(string $leftButtonText, string $rightButtonText): static
    {
        $this->button_texts = [$leftButtonText, $rightButtonText];

        return $this;
    }

    /**
     * @deprecated no Naive UI counterpart, kept for API compatibility.
     *
     * @param string $targetOrder `original`, `push` or `unshift`
     */
    public function targetOrder(string $targetOrder): static
    {
        if (in_array($targetOrder, ['original', 'push', 'unshift'], true)) {
            $this->target_order = $targetOrder;
        }

        return $this;
    }

    /**
     * @deprecated the Naive UI frontend does not evaluate server-side
     * JavaScript.
     */
    public function renderContent(string $renderContent): static
    {
        $this->render_content = $renderContent;

        return $this;
    }

    /**
     * @param array<int, mixed> $leftDefaultChecked
     */
    public function leftDefaultChecked(array $leftDefaultChecked): static
    {
        $this->left_default_checked = $this->onlyKnownOptions($leftDefaultChecked);

        return $this;
    }

    /**
     * @param array<int, mixed> $rightDefaultChecked
     */
    public function rightDefaultChecked(array $rightDefaultChecked): static
    {
        $known = is_array($this->default) ? $this->default : [];

        $this->right_default_checked = array_values(array_filter(
            $rightDefaultChecked,
            static fn ($value): bool => in_array($value, $known, true)
        ));

        return $this;
    }
}
