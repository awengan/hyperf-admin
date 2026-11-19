<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

use Awengan\HyperfAdmin\Helper\HtmlSanitizer;

/**
 * 富文本编辑器字段（Quill 2）。
 *
 *     $form->richText('content', '正文')
 *         ->toolbar(['bold', 'italic', 'underline', 'strike', 'heading',
 *                    'bulletList', 'orderedList', 'blockquote', 'codeBlock',
 *                    'link', 'image', 'undo', 'redo', 'clear'])
 *         ->height(320)
 *         ->placeholder('请输入正文')
 *         ->maxLength(50000)
 *         ->upload(true);          // 允许从本地上传图片（走 /admin/upload）
 *
 * 值就是一段 HTML。**服务端一定会清洗**（{@see HtmlSanitizer}），
 * 不管前端提交了什么：白名单标签/属性/协议，剥掉 script/iframe/on*。
 */
class RichText extends AbstractField
{
    protected string $type = 'richText';

    /**
     * 工具栏按钮白名单（前端按这个名字渲染按钮；不认识的名字忽略）。
     *
     * @var array<int, string>
     */
    public const TOOLBAR = [
        'bold', 'italic', 'underline', 'strike', 'heading',
        'bulletList', 'orderedList', 'blockquote', 'codeBlock',
        'code', 'link', 'image', 'undo', 'redo', 'clear',
    ];

    /** 编辑区高度（px） */
    protected int $height = 320;

    /** @var array<int, string> */
    protected array $toolbar = [];

    protected string $placeholder = '';

    protected bool $disabled = false;

    protected bool $upload = true;

    protected int $max_length = 0;

    public function height(int $px): static
    {
        $this->height = max(80, $px);

        return $this;
    }

    /**
     * @param array<int, string> $buttons
     */
    public function toolbar(array $buttons): static
    {
        $this->toolbar = $buttons;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    /**
     * 是否允许从本地上传图片（插入同源 URL）。默认允许。
     */
    public function upload(bool $allow = true): static
    {
        $this->upload = $allow;

        return $this;
    }

    /**
     * 长度上限。**按 HTML 字符串长度算**（不是可见文字），
     * 同时下发到前端做计数与即时提示。
     */
    public function maxLength(int $chars): static
    {
        $this->max_length = max(0, $chars);

        if ($this->max_length > 0) {
            $this->rules(trim('max:' . $this->max_length . '|' . $this->rules, '|'));
        }

        return $this;
    }

    /**
     * 落库前清洗。空值统一成空串，避免 `null` 和 `''` 两种空状态。
     */
    public function prepareValue(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        return HtmlSanitizer::make()->clean(is_string($value) ? $value : (string) $value);
    }

    protected function beforeBuild(): void
    {
        parent::beforeBuild();

        if ($this->toolbar === []) {
            $this->toolbar = self::TOOLBAR;
        } else {
            $this->toolbar = array_values(array_intersect(self::TOOLBAR, $this->toolbar));
        }

        if ($this->placeholder === '') {
            $this->placeholder = '请输入' . $this->label;
        }
    }
}
