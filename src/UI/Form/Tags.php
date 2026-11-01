<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * 标签组（前端组件：n-dynamic-tags）。
 */
class Tags extends AbstractField
{
    public function __construct(string $name = '', string $label = '')
    {
        $this->type = strtolower('Tags');

        parent::__construct($name, $label);
    }
}