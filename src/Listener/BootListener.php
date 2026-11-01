<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Listener;

use Awengan\HyperfAdmin\Helper\Assets;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 启动时把前端资源同步到宿主 `public/`。
 *
 * 前端没有构建步骤，`resources/` 里的 ES module 就是浏览器执行的源码，所以
 * 「发布」等于一次目录拷贝。可用 `hyperf-admin.assets.auto_publish` 关闭
 * （只读文件系统，或在 CI 里预发布）。
 *
 * 本类通过 `ConfigProvider` 的 `listeners` 注册，**不要**再加 `#[Listener]`
 * 注解，否则会被注册两次。
 */
final class BootListener implements ListenerInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->get('hyperf-admin');
    }

    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    public function process(object $event): void
    {
        if (! Assets::autoPublishEnabled()) {
            return;
        }

        try {
            // 资源真变了才让 `.gz` 失效：否则每次重启都会把 `--gzip` 的成果删掉
            $before = Assets::signature(true);

            Assets::publish();

            if (Assets::signature(true) !== $before) {
                // .gz 是派生文件：内容变了必须一并失效，否则会发旧内容
                Assets::clearCompressed();
            }
        } catch (Throwable $e) {
            // 只读 public/ 不应该让服务起不来；admin:install 可在部署阶段发布。
            $this->logger->warning('Unable to publish hyperf-admin assets: ' . $e->getMessage());
        }
    }
}
