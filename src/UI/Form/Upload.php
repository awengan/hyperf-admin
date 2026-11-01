<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\UI\Form;

/**
 * 文件 / 图片上传字段。
 *
 * 值就是文件 URL（单文件）。真正的写盘由 `UploadController` 负责：
 * `POST {api_base}/upload` → `{ url, name, size }`。
 *
 *     $form->upload('avatar', '头像')->image()->maxSize(2);
 *
 * 默认只允许图片；`->file()` 放开到文档类扩展名。
 */
class Upload extends AbstractField
{
    protected string $type = 'upload';

    protected array $hideAttrs = ['default'];

    /**
     * 是否只允许图片（前端也据此显示预览）。
     */
    public bool $image = true;

    /**
     * 允许的扩展名（空 = 用服务端默认配置）。
     *
     * @var array<int, string>
     */
    public array $extensions = [];

    /**
     * 单文件大小上限（MB，0 = 用服务端默认配置）。
     */
    public int $maxSize = 0;

    /**
     * 多选（一次传多张）。值的形状不变：逗号分隔的 URL 串。
     */
    public bool $multiple = false;

    public function image(bool $image = true): static
    {
        $this->image = $image;

        return $this;
    }

    /**
     * 放开为普通文件（不再是图片）。
     */
    public function file(): static
    {
        return $this->image(false);
    }

    /**
     * @param array<int, string>|string $extensions `'pdf,docx'` 或 `['pdf', 'docx']`
     */
    public function extensions(array|string $extensions): static
    {
        $this->extensions = is_array($extensions)
            ? array_values(array_map('strtolower', $extensions))
            : array_values(array_filter(array_map('trim', explode(',', strtolower($extensions)))));

        return $this;
    }

    /**
     * 允许多选（多图）。存成逗号分隔的 URL 串。
     */
    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function maxSize(int $megabytes): static
    {
        $this->maxSize = max(0, $megabytes);

        return $this;
    }
}
