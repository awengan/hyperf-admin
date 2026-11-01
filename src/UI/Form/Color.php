<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * 颜色选择（前端组件：n-color-picker）。
 */
class Color extends AbstractField
{
    public function __construct(string $name = '', string $label = '')
    {
        $this->type = strtolower('Color');

        parent::__construct($name, $label);
    }
}