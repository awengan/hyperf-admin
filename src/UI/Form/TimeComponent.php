<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Base class of the two concrete time controls.
 */
abstract class TimeComponent
{
    /**
     * All properties of the concrete control.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $data = [];

        foreach ($this as $key => $value) {
            $data[$key] = $value;
        }

        return $data;
    }
}
