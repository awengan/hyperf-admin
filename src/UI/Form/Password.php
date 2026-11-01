<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * Password input (`n-input` with `type="password"`).
 */
class Password extends Text
{
    protected string $type = 'password';

    protected string $placeholder = '';

    /**
     * Naive UI reveals the value while the eye icon is pressed (`click`) or
     * hovered (`mousedown`); `false` disables the toggle entirely.
     */
    protected string|bool $show_password_on = 'click';

    public function showPasswordOn(string|bool $mode): static
    {
        $this->show_password_on = $mode;

        return $this;
    }

    protected function beforeBuild(): void
    {
        parent::beforeBuild();

        if ($this->placeholder === '') {
            $this->placeholder = '请输入' . $this->label;
        }
    }
}
