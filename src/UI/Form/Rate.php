<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * 评分（前端组件：n-rate）。
 */
class Rate extends AbstractField
{
    public function __construct(string $name = '', string $label = '')
    {
        $this->type = strtolower('Rate');

        parent::__construct($name, $label);
    }
}