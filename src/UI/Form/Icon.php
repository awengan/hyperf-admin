<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * 图标选择（前端组件：n-select + 图标预览）。
 */
class Icon extends AbstractField
{
    public function __construct(string $name = '', string $label = '')
    {
        $this->type = strtolower('Icon');

        parent::__construct($name, $label);
    }
}