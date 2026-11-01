<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin;

use Awengan\HyperfAdmin\Traits\AdminJsonBuilder;

/**
 * Free-form content page.
 *
 * Renders whatever the frontend `ea-content` component understands: a title,
 * arbitrary HTML and an optional list of dashboard cards.
 */
class Content extends AdminJsonBuilder
{
    protected array $hideAttrs = ['row'];

    protected string $type = 'content';

    protected string $title = '';

    /**
     * Raw HTML injected into the page (trusted, server authored).
     */
    protected string $html = '';

    /**
     * @var array<string, mixed>
     */
    protected array $row = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $data = [];

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function html(string $html): static
    {
        $this->html = $html;

        return $this;
    }

    /**
     * Add a statistic card: `['label' => ..., 'value' => ..., 'hint' => ...]`.
     *
     * @param array<string, mixed> $card
     */
    public function card(array $card): static
    {
        $this->data[] = $card;

        return $this;
    }

    protected function beforeBuild(): void
    {
        if ($this->data === []) {
            $this->data = [];
        }
    }
}
