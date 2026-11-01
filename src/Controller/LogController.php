<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Grid;
use Awengan\HyperfAdmin\Middleware\AdminAuthMiddleware;
use Awengan\HyperfAdmin\Middleware\ErrorPageMiddleware;
use Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware;
use Awengan\HyperfAdmin\Middleware\SecurityHeadersMiddleware;
use Awengan\HyperfAdmin\Middleware\CsrfMiddleware;
use Awengan\HyperfAdmin\Middleware\PermissionMiddleware;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 审计日志（只读）。
 *
 * 用原生查询 + 一个轻量模型包装，避免为日志再建一个完整 Model。
 */
#[Controller(prefix: '/admin')]
#[Middleware(SecurityHeadersMiddleware::class)]
#[Middleware(ErrorPageMiddleware::class)]
#[Middleware(ReplyStatusMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
#[Middleware(AdminAuthMiddleware::class)]
#[Middleware(PermissionMiddleware::class)]
class LogController extends AbstractAdminController
{
    #[GetMapping(path: 'log')]
    public function index(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->gridPage());
    }

    /**
     * 导出 CSV（全部 / 当前页 / 勾选行）。
     */
    #[GetMapping(path: 'log/export')]
    public function export(): Reply|array|PsrResponseInterface
    {
        return $this->exportGrid($this->gridPage(), '审计日志-' . date('Ymd-His') . '.csv');
    }

    protected function gridPage(): Grid
    {
        $grid = new Grid(new \Awengan\HyperfAdmin\Model\AuditLogEntry());

        $grid->title('审计日志')
            ->url('/log')
            ->pageSize(20)
            ->readonly()
            ->exportable()
            ->searchable(['username' => '操作人', 'summary' => '摘要', 'path' => '路径'])
            ->sortable(['id', 'created_at'])
            ->column('username', '操作人')
            ->column('action', '动作')
            ->column('path', '路径')
            ->column('summary', '摘要')
            ->column('ip', 'IP')
            ->column('created_at', '时间')->date('YYYY-MM-DD HH:mm:ss')->sortable()
            ->query(static fn ($query) => $query->orderBy('id', 'desc'));

        return $grid;
    }
}
