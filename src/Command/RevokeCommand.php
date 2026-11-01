<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Command;

use Awengan\HyperfAdmin\Helper\Auth;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * 吊销会话：把用户的 `session_version` +1，已签发的 Cookie 立即失效。
 *
 *   php bin/hyperf.php admin:revoke            # 全部用户
 *   php bin/hyperf.php admin:revoke admin      # 只踢 admin
 *
 * 效果等价于「强制下线」；用户下次访问会被要求重新登录。改密码也会达到同样效果
 * （指纹里包含密码哈希），这个命令用于「密码没问题但想把某人踢下线」。
 */
#[Command]
class RevokeCommand extends HyperfCommand
{
    public function __construct()
    {
        parent::__construct('admin:revoke');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('吊销后台会话（让已签发的 Cookie 立即失效）');
        $this->addArgument('username', InputArgument::OPTIONAL, '只吊销这个账号；留空 = 全部用户');
        $this->addOption('list', null, InputOption::VALUE_NONE, '只列出当前会话版本，不改动');
    }

    public function handle(): int
    {
        $username = $this->input->getArgument('username');
        $username = is_string($username) && $username !== '' ? $username : null;

        if ($this->input->getOption('list')) {
            foreach (\Awengan\HyperfAdmin\Model\AdminUser::query()->orderBy('id')->get() ?? [] as $user) {
                $this->line(sprintf('  %-10s session_version=%d', $user->username, (int) $user->session_version));
            }

            return 0;
        }

        $affected = Auth::revoke($username);

        if ($affected === 0) {
            $this->warn($username === null ? '没有可吊销的账号' : sprintf('账号 %s 不存在', $username));

            return 1;
        }

        $this->info(sprintf(
            '已吊销 %d 个账号的会话%s，其已签发的 Cookie 立即失效。',
            $affected,
            $username === null ? '' : '（' . $username . '）'
        ));

        return 0;
    }
}
