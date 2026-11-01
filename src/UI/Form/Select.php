<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

use Awengan\HyperfAdmin\Traits\ListField;

/**
 * Select (`n-select`).
 *
 * Mapping notes:
 *  - `multiple`, `filterable`, `clearable`, `disabled`, `size`, `placeholder`
 *    and `load()` (cascading selects) are fully supported.
 *  - `collapseTags` maps to `maxTagCount`, `allowCreate` to `tag`.
 *  - `filterMethod`, `noMatchText`, `noDataText`, `reserveKeyword`,
 *    `defaultFirstOption`, `automaticDropdown` and `autocomplete` have no
 *    Naive UI counterpart; the props are still serialised so the API stays
 *    backwards compatible, but the frontend ignores them.
 */
class Select extends AbstractField
{
    use ListField;

    protected string $type = 'select';

    protected string $placeholder = '';

    protected bool $multiple = false;

    protected int $multiple_limit = 0;

    protected bool $disabled = false;

    protected string $size = 'medium';

    protected bool $clearable = true;

    protected bool $collapse_tags = false;

    protected mixed $autocomplete = false;

    protected bool $filterable = false;

    protected bool $allow_create = false;

    protected string $filter_method = '';

    protected string $no_match_text = '无匹配数据';

    protected string $no_data_text = '无数据';

    protected bool $reserve_keyword = false;

    protected bool $default_first_option = false;

    protected bool $automatic_dropdown = false;

    /**
     * Field filled by `load()` once this select changes.
     */
    protected string $loadField = '';

    /**
     * Endpoint queried by `load()`; receives the selected value as `q`.
     */
    protected string $loadUrl = '';

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function multiple(bool $multiple = true, int $multipleLimit = 0): static
    {
        $this->multiple = $multiple;
        $this->multiple_limit = $multipleLimit;

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    public function size(string $size): static
    {
        $this->size = $this->normalizeSize($size, $this->size);

        return $this;
    }

    public function clearable(bool $clearable = true): static
    {
        $this->clearable = $clearable;

        return $this;
    }

    public function collapseTags(bool $collapseTags = true): static
    {
        $this->collapse_tags = $collapseTags;

        return $this;
    }

    public function autocomplete(bool $autocomplete = true): static
    {
        $this->autocomplete = $autocomplete;

        return $this;
    }

    public function filterable(bool $filterable = true): static
    {
        $this->filterable = $filterable;

        return $this;
    }

    /**
     * Allows storing values that are not part of `options()` (implies
     * `filterable()`).
     */
    public function allowCreate(bool $allowCreate = true): static
    {
        $this->filterable = true;
        $this->allow_create = $allowCreate;

        return $this;
    }

    /**
     * @deprecated Naive UI filters on the option label itself and exposes no
     * custom filter callback for `n-select`.
     */
    public function filterMethod(string $filterMethod): static
    {
        $this->filterable = true;
        $this->filter_method = $filterMethod;

        return $this;
    }

    /**
     * @deprecated no Naive UI counterpart, kept for API compatibility.
     */
    public function noMatchText(string $noMatchText): static
    {
        $this->filterable = true;
        $this->no_match_text = $noMatchText;

        return $this;
    }

    /**
     * @deprecated no Naive UI counterpart, kept for API compatibility.
     */
    public function noDataText(string $noDataText): static
    {
        $this->no_data_text = $noDataText;

        return $this;
    }

    /**
     * @deprecated no Naive UI counterpart, kept for API compatibility.
     */
    public function reserveKeyword(bool $reserveKeyword = true): static
    {
        $this->multiple = true;
        $this->filterable = true;
        $this->reserve_keyword = $reserveKeyword;

        return $this;
    }

    /**
     * @deprecated no Naive UI counterpart, kept for API compatibility.
     */
    public function defaultFirstOption(bool $defaultFirstOption = true): static
    {
        if ($this->filterable) {
            $this->default_first_option = $defaultFirstOption;
        }

        return $this;
    }

    /**
     * @deprecated no Naive UI counterpart, kept for API compatibility.
     */
    public function automaticDropdown(bool $automaticDropdown = true): static
    {
        if (! $this->filterable) {
            $this->automatic_dropdown = $automaticDropdown;
        }

        return $this;
    }

    /**
     * Cascading select: when this field changes, `$field` is refreshed from
     * `$url?q=<value>`.
     */
    public function load(string $field, string $url): static
    {
        $this->loadField = $field;
        $this->loadUrl = $url;

        return $this;
    }

    protected function beforeBuild(): void
    {
        parent::beforeBuild();

        if ($this->placeholder === '') {
            $this->placeholder = '请选择' . $this->label;
        }
    }
}
