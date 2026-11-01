<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Command;

use Awengan\HyperfAdmin\Model\AdminUser;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Throwable;

/**
 * `php bin/hyperf.php admin:password <username> <password>`
 *
 * 不依赖 SQL 就能创建或重置后台账号。
 */
class PasswordCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('admin:password');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('创建后台账号或重置密码');
        $this->addArgument('username', InputArgument::REQUIRED, '登录用户名');
        $this->addArgument('password', InputArgument::REQUIRED, '新密码（明文）');
        $this->addArgument('name', InputArgument::OPTIONAL, '显示名称');
    }

    public function handle(): void
    {
        $username = (string) $this->input->getArgument('username');
        $password = (string) $this->input->getArgument('password');
        $name = (string) ($this->input->getArgument('name') ?: $username);

        try {
            $user = AdminUser::getUserByUserName($username) ?? new AdminUser();

            $user->username = $username;
            $user->name = $name;
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->save();
        } catch (Throwable $e) {
            $this->error('保存失败：' . $e->getMessage());

            return;
        }

        $this->info(sprintf('已更新账号 "%s" 的密码。', $username));
    }
}
