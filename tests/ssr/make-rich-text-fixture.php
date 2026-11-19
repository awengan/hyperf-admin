<?php
/**
 * 生成 SSR 用的「富文本表单页」fixture。
 *
 * 走真实的 Form + RichText 构建器，产出和 HTTP 响应同一个形状，
 * 只是省掉了网络与鉴权。改字段实现后重跑本脚本即可刷新。
 *
 *   HYPERF_VENDOR=/www/hyperf-admin/vendor/autoload.php php tests/ssr/make-rich-text-fixture.php
 */
declare(strict_types=1);

$packagePath = dirname(__DIR__, 2);

$vendor = getenv('HYPERF_VENDOR') ?: null;

if ($vendor === null) {
    $directory = $packagePath;

    while (true) {
        if (is_file($directory . '/vendor/autoload.php')) {
            $vendor = $directory . '/vendor/autoload.php';
            break;
        }

        $parent = dirname($directory);

        if ($parent === $directory) {
            break;
        }

        $directory = $parent;
    }
}

if ($vendor === null || ! is_file($vendor)) {
    fwrite(STDERR, "找不到 vendor/autoload.php，请设置 HYPERF_VENDOR。" . PHP_EOL);
    exit(1);
}

defined('BASE_PATH') || define('BASE_PATH', getenv('HYPERF_BASE_PATH') ?: dirname($vendor, 2));

require $vendor;

spl_autoload_register(static function (string $class) use ($packagePath): void {
    $prefix = 'Awengan\HyperfAdmin\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = $packagePath . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
}, true, true);

use Awengan\HyperfAdmin\Form;
use Awengan\HyperfAdmin\Model\Menu;
use Hyperf\Context\ApplicationContext;
use Psr\Container\ContainerInterface;

// 模型构造会触发事件分发（Model::bootIfNotBooted），需要一个最小容器
$container = new class implements ContainerInterface {
    public function get(string $id): mixed
    {
        return null;
    }

    public function has(string $id): bool
    {
        return false;
    }
};

ApplicationContext::setContainer($container);

$form = new Form(new Menu());
$form->url('/article/store')->title('新增文章');
$form->text('title', '标题')->rules('required|max:120');
$form->richText('content', '正文')
    ->height(300)
    ->placeholder('请输入正文')
    ->maxLength(5000);
$form->select('status', '状态')->options([1 => '草稿', 2 => '已发布'])->default(1);

$payload = $form->getData();

// 和 HTTP 响应同一形状：{data: {...}, code, msg}
$envelope = [
    'data' => $payload,
    'code' => 200,
    'msg' => 'success',
];

$target = __DIR__ . '/fixtures/rich-text.json';
file_put_contents($target, json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo '写入 ' . $target . '（' . strlen((string) file_get_contents($target)) . " 字节）\n";
