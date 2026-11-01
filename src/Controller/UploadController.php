<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Helper\AuditLog;
use Awengan\HyperfAdmin\Helper\Auth;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Middleware\AdminAuthMiddleware;
use Awengan\HyperfAdmin\Middleware\ErrorPageMiddleware;
use Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware;
use Awengan\HyperfAdmin\Middleware\SecurityHeadersMiddleware;
use Awengan\HyperfAdmin\Middleware\CsrfMiddleware;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * 文件上传：`POST /upload`（登录即可，不额外要权限）。
 *
 * 安全要点：
 *  - 扩展名白名单（默认图片；不包含 svg —— 它能内嵌脚本）
 *  - 大小上限
 *  - 文件名完全由服务端生成（`uniqid`），不信任客户端名字，避免路径穿越
 *  - 落在 `public/uploads/` 下，按年月分目录
 */
#[Controller(prefix: '/admin')]
#[Middleware(SecurityHeadersMiddleware::class)]
#[Middleware(ErrorPageMiddleware::class)]
#[Middleware(ReplyStatusMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
#[Middleware(AdminAuthMiddleware::class)]
class UploadController extends AbstractAdminController
{
    /**
     * 默认可上传的图片扩展名（刻意不含 svg）。
     *
     * @var array<int, string>
     */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const FILE_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt', 'csv'];

    /**
     * 扩展名 → MIME（发给浏览器时用，避免靠内容嗅探）。
     *
     * @var array<string, string>
     */
    private const MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        parent::__construct($request, $response);
    }

    #[PostMapping(path: 'upload')]
    public function store(): Reply|array|PsrResponseInterface
    {
        $settings = (array) Auth::config('upload', []);
        $maxBytes = max(1, (int) ($settings['max_size'] ?? 2)) * 1024 * 1024;
        $imageOnly = (bool) ($this->request->input('image', 1));

        $file = $this->request->file('file');

        if (! $file instanceof UploadedFileInterface) {
            return Reply::error('没有收到文件', 400);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Reply::error('上传失败（错误码 ' . $file->getError() . '）', 400);
        }

        if ($file->getSize() > $maxBytes) {
            return Reply::error(sprintf('文件不能超过 %d MB', (int) ($settings['max_size'] ?? 2)), 413);
        }

        $original = (string) $file->getClientFilename();
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        $allowed = $imageOnly
            ? (array) ($settings['image_ext'] ?? self::IMAGE_EXT)
            : (array) ($settings['file_ext'] ?? array_merge(self::IMAGE_EXT, self::FILE_EXT));

        if ($extension === '' || ! in_array($extension, array_map('strtolower', $allowed), true)) {
            return Reply::error('不支持的文件类型' . ($extension !== '' ? '：' . $extension : '') . '（允许：' . implode(', ', $allowed) . '）', 415);
        }

        $directory = sprintf(
            '%s/%s/%s',
            rtrim((string) ($settings['path'] ?? 'uploads'), '/'),
            date('Y'),
            date('m')
        );

        $basePath = rtrim(BASE_PATH, '/') . '/public/' . $directory;

        if (! is_dir($basePath) && ! @mkdir($basePath, 0o755, true) && ! is_dir($basePath)) {
            return Reply::error('上传目录不可写：' . $directory, 500);
        }

        $filename = bin2hex(random_bytes(8)) . '.' . $extension;
        $target = $basePath . '/' . $filename;

        try {
            $file->moveTo($target);
        } catch (Throwable $e) {
            return Reply::error('保存文件失败：' . $e->getMessage(), 500);
        }

        $url = '/' . $directory . '/' . $filename;

        AuditLog::record('upload', [
            'summary' => sprintf('上传文件：%s → %s', $original, $url),
            'payload' => ['name' => $original, 'size' => $file->getSize()],
        ]);

        return Reply::success('上传成功', [
            'url' => $url,
            'name' => $original,
            'size' => (int) $file->getSize(),
        ]);
    }

    /**
     * 提供上传文件的访问（没有 nginx 时也能直接看到图）。
     *
     * 生产环境建议交给 nginx 直接发静态文件；这里保留是为了本地预览开箱可用。
     * 安全措施：扩展名白名单 + `X-Content-Type-Options: nosniff` + 拒绝路径穿越。
     */
    #[GetMapping(path: 'uploads/{path:.+}')]
    public function serve(string $path): PsrResponseInterface
    {
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..') || preg_match('/^\//', $path) === 1) {
            return $this->textResponse(404, 'Not Found');
        }

        $settings = (array) Auth::config('upload', []);
        $allowed = array_map('strtolower', (array) ($settings['file_ext'] ?? self::FILE_EXT));
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            return $this->textResponse(403, 'Forbidden');
        }

        $root = rtrim(BASE_PATH, '/') . '/public/' . trim((string) ($settings['path'] ?? 'uploads'), '/');
        $file = $root . '/' . $path;

        if (! is_file($file)) {
            return $this->textResponse(404, 'Not Found');
        }

        return $this->response
            ->raw((string) file_get_contents($file))
            ->withHeader('Content-Type', self::MIME[$extension] ?? 'application/octet-stream')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Length', (string) filesize($file))
            // 文件名是随机的，内容不会变，可以长缓存
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }

    private function textResponse(int $status, string $message): PsrResponseInterface
    {
        return $this->response
            ->raw($message)
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
