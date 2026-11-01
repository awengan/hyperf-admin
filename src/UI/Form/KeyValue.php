<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * 键值对（前端组件：键值对）。
 */
class KeyValue extends AbstractField
{
    public function __construct(string $name = '', string $label = '')
    {
        $this->type = strtolower('KeyValue');

        parent::__construct($name, $label);
    }
}