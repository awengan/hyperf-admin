<?php

declare(strict_types=1);

/**
 * 合约测试：后端 Builder 产出的 payload 必须和 Naive UI 前端消费的形状一致。
 *
 *   php tests/PayloadTest.php
 *
 * 不需要 Swoole、不需要数据库。Hyperf 的 vendor 通过以下顺序定位：
 *   1. 环境变量 HYPERF_VENDOR（指向宿主项目的 vendor/autoload.php）
 *   2. 从本文件向上逐级查找 vendor/autoload.php（组件装在宿主的 vendor 里时成立）
 */

$packagePath = dirname(__DIR__);

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
    fwrite(STDERR, "找不到 Hyperf 的 vendor/autoload.php，请设置 HYPERF_VENDOR 环境变量。" . PHP_EOL);
    exit(1);
}

$basePath = getenv('HYPERF_BASE_PATH') ?: dirname($vendor, 2);

define('BASE_PATH', $basePath);

require $vendor;

// 优先从**源码目录**加载组件类（prepend 到 composer 之前）。
//
// 否则组件装进宿主 vendor 后，composer 会命中 vendor 里的副本 —— 测试就变成了
// 「测已安装的旧代码」，本地改动要重新 install 才生效。
$sourceLoader = static function (string $class) use ($packagePath): void {
    $prefix = 'Awengan\HyperfAdmin\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = $packagePath . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
};

spl_autoload_register($sourceLoader, true, true);

use Awengan\HyperfAdmin\Content;
use Awengan\HyperfAdmin\Exception\FormComponentException;
use Awengan\HyperfAdmin\Form;
use Awengan\HyperfAdmin\Helper\MenuHelper;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Model\Menu;
use Awengan\HyperfAdmin\UI;
use Awengan\HyperfAdmin\UI\Form\Checkbox;
use Awengan\HyperfAdmin\UI\Form\DateTime;
use Awengan\HyperfAdmin\UI\Form\Select;
use Awengan\HyperfAdmin\UI\Form\Switches;
use Awengan\HyperfAdmin\UI\Form\Text;
use Awengan\HyperfAdmin\UI\Form\Time;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

/**
 * Minimal container so Hyperf models can boot and `UI` can read config without
 * a Swoole runtime. `get()` returns `null` for everything that is not the
 * config (e.g. the event dispatcher, the database resolver).
 */
final class StubContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function set(string $id, mixed $value): void
    {
        $this->items[$id] = $value;
    }

    public function get(string $id): mixed
    {
        return $this->items[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->items);
    }
}

final class StubConfig implements ConfigInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $keys): bool
    {
        return array_key_exists($keys, $this->values);
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
}

$container = new StubContainer();
$container->set(ConfigInterface::class, new StubConfig());
ApplicationContext::setContainer($container);

$passed = 0;
$failures = [];

function test(string $name, callable $fn): void
{
    global $passed, $failures;

    try {
        $fn();
        ++$passed;
    } catch (Throwable $e) {
        $failures[] = [$name, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()];
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $message === '' ? '' : $message . ': ',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue($value, string $message = ''): void
{
    if ($value !== true) {
        throw new RuntimeException($message === '' ? 'expected true' : $message);
    }
}

function assertArrayHasKey(string $key, array $array, string $message = ''): void
{
    if (! array_key_exists($key, $array)) {
        throw new RuntimeException(sprintf(
            '%smissing key "%s" (have: %s)',
            $message === '' ? '' : $message . ': ',
            $key,
            implode(', ', array_keys($array))
        ));
    }
}

function assertArrayNotHasKey(string $key, array $array, string $message = ''): void
{
    if (array_key_exists($key, $array)) {
        throw new RuntimeException(($message === '' ? '' : $message . ': ') . 'unexpected key "' . $key . '"');
    }
}

/* --------------------------------------------------------------- Result --- */

test('Result envelope has data/code/msg', function () {
    $payload = Result::result(['a' => 1])->toArray();
    assertSame(200, $payload['code']);
    assertSame('success', $payload['msg']);
    assertSame(['a' => 1], $payload['data']);
});

test('Result::result keeps empty data as an object', function () {
    $payload = Result::result()->toArray();
    assertTrue($payload['data'] instanceof stdClass, 'empty data should be stdClass');
    assertSame('{}', json_encode($payload['data']));
});

test('Result::error keeps the legacy argument order', function () {
    $payload = Result::error(40000, 'boom', ['x' => 1])->toArray();
    assertSame(40000, $payload['code']);
    assertSame('boom', $payload['msg']);
    assertSame(['x' => 1], $payload['data']);
});

test('Result::isOk only accepts 200', function () {
    assertTrue(Result::isOk(Result::success()));
    assertTrue(Result::isOk(Result::error()) === false, 'error payload must not be ok');

    // Reply 自己也提供 isOk()，控制器里读删除结果时用它
    assertTrue(\Awengan\HyperfAdmin\Reply::success('ok')->isOk());
    assertTrue(\Awengan\HyperfAdmin\Reply::error('boom', 42200)->isOk() === false);
});

test('Reply::error 把业务码映射成 HTTP 状态码放进上下文', function () {
    $context = \Awengan\HyperfAdmin\Constants\Consts::REPLY_STATUS_CONTEXT;

    // 40100 → 401
    \Awengan\HyperfAdmin\Reply::error('未登录', 40100);
    assertSame(401, \Hyperf\Context\Context::get($context));

    // 42200 → 422
    \Awengan\HyperfAdmin\Reply::error('校验失败', 42200);
    assertSame(422, \Hyperf\Context\Context::get($context));

    // 40400 → 404
    \Awengan\HyperfAdmin\Reply::error('不存在', 40400);
    assertSame(404, \Hyperf\Context\Context::get($context));

    // 419 在 Swoole 里没有 reason phrase，降级成 403
    \Awengan\HyperfAdmin\Reply::error('令牌过期', 41900);
    assertSame(403, \Hyperf\Context\Context::get($context));

    // 已经是 HTTP 状态码的直接用
    \Awengan\HyperfAdmin\Reply::error('错了', 500);
    assertSame(500, \Hyperf\Context\Context::get($context));

    // 算不出来的保持 200（不写上下文）
    \Hyperf\Context\Context::destroy($context);
    \Awengan\HyperfAdmin\Reply::error('奇怪的值', 1234);
    assertSame(null, \Hyperf\Context\Context::get($context), '算不出状态码时不应写上下文');

    // 成功要把残留的错误状态清掉
    \Awengan\HyperfAdmin\Reply::error('失败', 42200);
    \Awengan\HyperfAdmin\Reply::success('成功');
    assertSame(null, \Hyperf\Context\Context::get($context), 'success 应清掉错误状态');
});

/* ----------------------------------------------------------- field basics -- */

test('Text field serialises type/name/label/placeholder', function () {
    $field = (new Text('name', '姓名'))->placeholder('请输入姓名');
    $data = $field->getData();

    assertSame('text', $data['type']);
    assertSame('name', $data['name']);
    assertSame('姓名', $data['label']);
    assertSame('请输入姓名', $data['placeholder']);
    assertArrayNotHasKey('default', $data, 'default must stay hidden');
});

test('Text field derives a placeholder from the label', function () {
    $data = (new Text('name', '姓名'))->getData();
    assertSame('请输入姓名', $data['placeholder']);
});

test('Text field defaults the label to the name', function () {
    $data = (new Text('email'))->getData();
    assertSame('email', $data['label']);
});

test('default() is chainable and hidden from the payload', function () {
    $field = (new Text('name', '姓名'))->default('张三');
    assertSame('张三', $field->default);
    assertArrayNotHasKey('default', $field->getData());
});

/* ---------------------------------------------------------- list options -- */

test('ListField normalises an associative array', function () {
    $data = (new Select('sex', '性别'))->options([1 => '男', 2 => '女'])->getData();
    assertSame(
        [['key' => 1, 'value' => '男'], ['key' => 2, 'value' => '女']],
        $data['options']
    );
});

test('ListField treats numeric arrays keys as option keys', function () {
    // The keys are authoritative, exactly like the Element UI builder: a plain
    // list yields the keys 0 and 1, while `[1 => ..]` yields 1.
    $data = (new Select('city', '城市'))->options(['北京', '长沙'])->getData();
    assertSame(
        [['key' => 0, 'value' => '北京'], ['key' => 1, 'value' => '长沙']],
        $data['options']
    );
});

test('ListField keeps an already normalised list', function () {
    $data = (new Select('city', '城市'))
        ->options([['key' => 1, 'value' => '北京'], ['key' => 2, 'value' => '长沙']])
        ->getData();
    assertSame([['key' => 1, 'value' => '北京'], ['key' => 2, 'value' => '长沙']], $data['options']);
});

test('ListField accepts a closure', function () {
    $data = (new Select('city', '城市'))->options(static fn () => [1 => '北京'])->getData();
    assertSame([['key' => 1, 'value' => '北京']], $data['options']);
});

/* ------------------------------------------------------------- checkbox --- */

test('Checkbox filters disabled/border against its options', function () {
    $field = (new Checkbox('hobby', '爱好'))
        ->options(['game' => '游戏', 'music' => '音乐'])
        ->disabled(['game', 'missing'])
        ->border(['music', 'nope']);

    $data = $field->getData();

    assertSame(['game'], $data['disabled']);
    assertSame(['music'], $data['border']);
});

test('Checkbox max defaults to the option count', function () {
    $data = (new Checkbox('hobby', '爱好'))->options(['a' => 'A', 'b' => 'B'])->getData();
    assertSame(2, $data['max']);
});

test('Checkbox keeps an explicit max', function () {
    $data = (new Checkbox('hobby', '爱好'))->options(['a' => 'A', 'b' => 'B'])->max(1)->getData();
    assertSame(1, $data['max']);
});

/* --------------------------------------------------------------- switch --- */

test('Switches exposes Naive UI compatible keys', function () {
    $data = (new Switches('is_active', '启用'))
        ->activeValue(1)
        ->inactiveValue(0)
        ->activeText('开')
        ->inactiveText('关')
        ->getData();

    assertSame('switch', $data['type']);
    assertSame(1, $data['active_value']);
    assertSame(0, $data['inactive_value']);
    assertSame('开', $data['active_text']);
    assertSame('关', $data['inactive_text']);
    assertArrayHasKey('active_color', $data);
});

/* --------------------------------------------------------------- select --- */

test('Select::load records the cascading contract', function () {
    $data = (new Select('province', '省份'))->load('city', '/area')->getData();

    assertSame('city', $data['loadField']);
    assertSame('/area', $data['loadUrl']);
    assertSame('请选择省份', $data['placeholder']);
});

test('Select::allowCreate implies filterable', function () {
    $data = (new Select('tag', '标签'))->allowCreate()->getData();
    assertSame(true, $data['filterable']);
    assertSame(true, $data['allow_create']);
});

/* ----------------------------------------------------------------- time --- */

test('Time flattens the concrete control and hides it', function () {
    $field = (new Time('time', '学习时间'))
        ->type('picker')
        ->isRange(true)
        ->selectableRange('18:00:00 - 23:00:00');

    $data = $field->getData();

    assertSame('time', $data['type']);
    assertSame('picker', $data['time_type']);
    assertSame(true, $data['is_range']);
    assertSame('18:00:00 - 23:00:00', $data['selectableRange']);
    assertArrayHasKey('start_placeholder', $data);
    assertArrayNotHasKey('time_component', $data, 'the control object must not leak');
});

test('Time defaults to the fixed select list', function () {
    $data = (new Time('time', '时间'))->getData();
    assertSame('select', $data['time_type']);
    assertArrayHasKey('placeholder', $data);
});

test('Time forwards arguments to the concrete control', function () {
    $data = (new Time('time', '时间'))->type('select')->start('08:30')->end('09:30')->step('00:15')->getData();
    assertSame('08:30', $data['start']);
    assertSame('09:30', $data['end']);
    assertSame('00:15', $data['step']);
});

test('Time rejects unknown control methods', function () {
    try {
        (new Time('time', '时间'))->type('select')->doesNotExist();
        throw new RuntimeException('expected FormComponentException');
    } catch (FormComponentException $e) {
        assertTrue(true);
    }
});

/* -------------------------------------------------------------- datetime --- */

test('DateTime restricts its picker types', function () {
    $field = (new DateTime('plan', '计划'))->type('datetimerange');
    assertSame('datetimerange', $field->getData()['date_type']);

    $field->type('date'); // not a datetime type, ignored
    assertSame('datetimerange', $field->getData()['date_type']);
});

/* ------------------------------------------------------------------ form --- */

test('Form builds fields + form_data in the frontend shape', function () {
    $form = new Form(new Menu());
    $form->url('/user/store');
    $form->text('name', '姓名');
    $form->number('age', '年龄')->default(18);
    $form->checkbox('hobby', '爱好')->options(['a' => 'A']);

    $data = $form->getData();

    assertSame('form', $data['type']);
    assertSame('/user/store', $data['url']);
    assertSame(3, count($data['data']['fields']));
    assertSame('text', $data['data']['fields'][0]['type']);
    assertSame('number', $data['data']['fields'][1]['type']);
    assertSame('checkbox', $data['data']['fields'][2]['type']);
    assertSame('', $data['data']['form_data']['name']);
    assertSame(18, $data['data']['form_data']['age']);
    // PHP keeps the field default; the frontend turns '' into [] for groups.
    assertSame('', $data['data']['form_data']['hobby']);
    assertArrayNotHasKey('fields', $data, 'fields must stay hidden');
    assertArrayNotHasKey('formData', $data, 'formData must stay hidden');
    assertArrayNotHasKey('model', $data, 'model must stay hidden');
    assertArrayNotHasKey('componentMap', $data, 'componentMap must stay hidden');
});

test('Form maps the switch and datetime aliases', function () {
    $form = new Form(new Menu());
    $form->url('/x');
    $form->switch('is_active', '启用');
    $form->datetime('plan', '计划');

    $fields = $form->getData()['data']['fields'];

    assertSame('switch', $fields[0]['type']);
    assertSame('datetime', $fields[1]['type']);
});

test('Form rejects unknown field types', function () {
    $form = new Form(new Menu());
    try {
        $form->nonexistent('x', 'X');
        throw new RuntimeException('expected FormComponentException');
    } catch (FormComponentException $e) {
        assertTrue(true);
    }
});

test('Form::getData is idempotent', function () {
    $form = new Form(new Menu());
    $form->url('/x');
    $form->text('name', '姓名');

    $first = $form->getData();
    $second = $form->getData();

    assertSame(count($first['data']['fields']), count($second['data']['fields']), 'fields must not accumulate');
});

test('Form json serialises into the Result envelope', function () {
    $form = new Form(new Menu());
    $form->url('/x');
    $form->text('name', '姓名');

    $payload = $form->jsonSerialize();

    assertSame(200, $payload['code']);
    assertSame('form', $payload['data']['type']);
});

/* --------------------------------------------------------------- content --- */

test('Content builds cards', function () {
    $content = (new Content())->title('Home')->card(['label' => 'A', 'value' => 1]);
    $data = $content->getData();

    assertSame('content', $data['type']);
    assertSame('Home', $data['title']);
    assertSame(1, count($data['data']));
    assertArrayNotHasKey('row', $data, 'row must stay hidden');
});

/* -------------------------------------------------------------------- UI --- */

test('UI::importMap pins vue and externalises it for naive-ui', function () {
    $map = json_decode(UI::importMap(), true);

    assertArrayHasKey('imports', $map);
    assertArrayHasKey('vue', $map['imports']);
    assertArrayHasKey('naive-ui', $map['imports']);

    // 自托管（public/static/vendor/import-map.json 存在）时会指向本地文件，
    // 否则必须带 external=vue —— 否则 esm.sh 会打包第二份 Vue
    if (\Awengan\HyperfAdmin\Helper\VendorAssets::enabled()) {
        assertTrue(
            str_starts_with($map['imports']['vue'], '/static/vendor/'),
            '自托管时 vue 应指向本地文件'
        );
    } else {
        assertTrue(
            str_contains($map['imports']['naive-ui'], 'external=vue'),
            'naive-ui must reuse the import-mapped vue build'
        );
    }
});

test('UI::boot provides the documented defaults', function () {
    $boot = UI::boot([]);

    assertSame('app', $boot['mode']);
    assertSame('Hyperf Admin', $boot['title']);
    assertSame('', $boot['apiBase']);
    assertSame('/static', $boot['assetBase']);
    // 路由前缀默认 'admin'：登录页也跟着走 /admin/login.html
    assertSame('/admin/login.html', $boot['loginPath']);
    assertSame('zh-CN', $boot['locale']);
    assertSame(null, $boot['user']);
    assertTrue(is_array($boot['menus']) && $boot['menus'] !== [], 'built-in menu must be present');
    assertSame(null, $boot['page']);
});

test('UI::render embeds an escaped boot payload', function () {
    $html = UI::render(['page' => ['type' => 'content', 'title' => '</script><script>alert(1)</script>']]);

    assertTrue(str_contains($html, 'type="importmap"'), 'import map must be present');
    assertTrue(str_contains($html, 'window.__EA_BOOT__'), 'boot payload must be present');
    assertTrue(
        preg_match('#/static/v[0-9a-f]{8}/js/app\.js#', $html) === 1,
        '入口脚本必须带路径版本号（原生 ESM 的相对 import 靠它换 URL）'
    );
    assertTrue(
        preg_match('#/static/v[0-9a-f]{8}/css/app\.css#', $html) === 1,
        '样式表必须带路径版本号'
    );
    assertTrue(
        ! str_contains($html, '</script><script>alert(1)'),
        'boot payload must not be able to close the script tag'
    );
});

test('资源版本号由已发布内容决定且稳定', function () {
    $version = \Awengan\HyperfAdmin\Helper\Assets::version();

    assertTrue(preg_match('/^[0-9a-f]{8}$/', $version) === 1, '版本号应为 8 位十六进制');
    // 同一份内容多次调用必须一致（否则每次请求都会换 URL，缓存全失效）
    assertSame($version, \Awengan\HyperfAdmin\Helper\Assets::version());

    $html = UI::render([]);
    assertTrue(
        str_contains($html, '/static/v' . $version . '/js/app.js'),
        'HTML 里的版本号应与 Assets::version() 一致'
    );
});

test('UI::login switches the render mode', function () {
    $boot = UI::boot(['mode' => 'login']);
    $html = UI::login(['title' => '登录']);

    assertSame('login', $boot['mode']);
    assertTrue(str_contains($html, '登录'), 'title must be rendered');
    assertTrue(str_contains($html, '"mode":"login"'), 'login mode must be embedded');
});

test('MenuHelper ships the built-in menu', function () {
    $menus = MenuHelper::defaultMenu();

    // 顶层只剩「系统管理」一组：演示页（表格/组件示例）与「子菜单」示例组已下线
    assertSame(1, count($menus));
    assertSame('系统管理', $menus[0]['title']);
    assertSame('', $menus[0]['url'], '分组本身不导航');
    assertSame('settings', $menus[0]['icon']);
    assertSame(5, count($menus[0]['child']));

    $children = $menus[0]['child'];
    $expectedChildren = [
        ['用户管理', '/user', 'user.manage'],
        ['角色管理', '/role', 'role.manage'],
        ['权限管理', '/permission', 'permission.manage'],
        ['菜单管理', '/menu', 'menu.manage'],
        ['审计日志', '/log', 'log.view'],
    ];

    foreach ($expectedChildren as $index => [$title, $url, $permission]) {
        assertSame($title, $children[$index]['title']);
        assertSame($url, $children[$index]['url']);
        assertSame($permission, $children[$index]['permission']);
    }

    // 演示页 / 子菜单分组都不应再出现（顶层或子项里都不许有）
    $urls = [];

    $walk = static function (array $nodes) use (&$walk, &$urls): void {
        foreach ($nodes as $node) {
            $urls[] = $node['url'] ?? '';

            if (! empty($node['child'])) {
                $walk($node['child']);
            }
        }
    };

    $walk($menus);

    foreach (['/grid', '/showcase'] as $gone) {
        assertTrue(! in_array($gone, $urls, true), sprintf('演示页 %s 不应出现在菜单里', $gone));
    }

    $titles = array_column($menus, 'title');
    assertTrue(! in_array('子菜单', $titles, true), '「子菜单」演示分组应已删除');
    assertTrue(! in_array('表格', $titles, true), '「表格」菜单应已删除');
    assertTrue(! in_array('组件示例', $titles, true), '「组件示例」菜单应已删除');
});

test('admin.sql 的内置菜单与 MenuHelper 描述同一棵树', function () {
    $sql = file_get_contents(dirname(__DIR__) . '/database/admin.sql');
    assertTrue(is_string($sql) && $sql !== '', '读不到 database/admin.sql');

    // 内置菜单只有系统管理一组
    $expected = [
        ['', '系统管理', '', null],
        ['系统管理', '用户管理', 'user', '[5]'],
        ['系统管理', '角色管理', 'role', '[3]'],
        ['系统管理', '权限管理', 'permission', '[4]'],
        ['系统管理', '菜单管理', 'menu', '[2]'],
        ['系统管理', '审计日志', 'log', '[6]'],
    ];

    foreach ($expected as [$parent, $title, $uri, $permission]) {
        assertTrue(
            str_contains($sql, "'" . $title . "'"),
            sprintf('admin.sql 缺少内置菜单「%s」', $title)
        );
    }

    // 关键结构：系统管理是顶层分组，5 个子项都挂在它下面
    assertTrue(
        preg_match("/\(\s*3,\s*0,\s*\d+,\s*0,\s*'系统管理'/u", $sql) === 1,
        '系统管理应作为顶层分组（id=3, parent_id=0, is_menu=0）'
    );

    foreach ([[11, '用户管理'], [5, '角色管理'], [6, '权限管理'], [4, '菜单管理'], [12, '审计日志']] as [$id, $title]) {
        assertTrue(
            preg_match("/\(\s*" . $id . ",\s*3,\s*\d+,\s*1,\s*'" . $title . "'/u", $sql) === 1,
            sprintf('%s 应挂在系统管理下（id=%d, parent_id=3）', $title, $id)
        );
    }

    // 已下线的内置菜单不能再被 INSERT 回库里
    foreach ([1 => '表格', 2 => '组件示例', 7 => '子菜单', 8 => '首页', 9 => '子子菜单', 10 => '小首页'] as $id => $title) {
        assertTrue(
            preg_match("/\(\s*" . $id . ",\s*\d+,\s*\d+,\s*\d+,\s*'" . $title . "'/u", $sql) !== 1,
            sprintf('admin.sql 不应再插入已下线的菜单「%s」（id=%d）', $title, $id)
        );
    }

    // 但要有清理语句，保证老库导入后也只剩系统管理
    assertTrue(
        preg_match('/DELETE\s+FROM\s+`admin_menu`\s+WHERE\s+`id`\s+IN\s*\(\s*1\s*,\s*2\s*,\s*7\s*,\s*8\s*,\s*9\s*,\s*10\s*\)/i', $sql) === 1,
        'admin.sql 应删除已下线的菜单（id 1,2,7,8,9,10）'
    );

    assertTrue(
        str_contains($sql, 'ON DUPLICATE KEY UPDATE'),
        '内置菜单种子应可重复执行（ON DUPLICATE KEY UPDATE）'
    );
});

test('审计日志与 RBAC 的建表语句齐全', function () {
    $sql = file_get_contents(dirname(__DIR__) . '/database/admin.sql');
    assertTrue(is_string($sql) && $sql !== '', '读不到 database/admin.sql');

    foreach (['admin_logs', 'admin_permissions', 'admin_roles', 'admin_role_permission', 'admin_role_user'] as $table) {
        assertTrue(
            str_contains($sql, 'CREATE TABLE IF NOT EXISTS `' . $table . '`'),
            sprintf('缺少建表语句：%s', $table)
        );
    }

    // 新增的两条权限也要有种子（已装过的库靠 upsert 补）
    foreach (['user.manage', 'log.view'] as $slug) {
        assertTrue(str_contains($sql, "'" . $slug . "'"), sprintf('权限种子缺少 %s', $slug));
    }
});

test('安装 SQL 不再包含演示表与演示权限', function () {
    $sql = file_get_contents(dirname(__DIR__) . '/database/admin.sql');
    assertTrue(is_string($sql) && $sql !== '', '读不到 database/admin.sql');

    // 演示表（原 /grid 的数据源）和它的种子都不该再出现
    assertTrue(
        ! str_contains($sql, 'CREATE TABLE IF NOT EXISTS `test`'),
        '安装 SQL 不应再建演示表 test'
    );
    assertTrue(! str_contains($sql, "'张三'"), '安装 SQL 不应再插演示数据');

    // 演示权限 demo.view 不再作为种子插入
    assertTrue(! str_contains($sql, "'示例页面'"), 'demo.view 权限种子应已删除');

    // 但要有清理语句，让老库导入后也干净
    assertTrue(
        str_contains($sql, "DELETE FROM `admin_permissions` WHERE `slug` = 'demo.view'"),
        '应清理老库里的 demo.view 权限'
    );

    // 0.0.1 起不再内置演示账号 / 演示角色（只允许出现在「清理老库」的 DELETE 里）
    assertTrue(! str_contains($sql, "SELECT 'viewer'"), '安装 SQL 不应再插入 viewer 账号');
    assertTrue(! str_contains($sql, '只读用户'), '安装 SQL 不应再含「只读用户」');
    assertTrue(
        str_contains($sql, "DELETE FROM `admin_users` WHERE `username` = 'viewer'"),
        '应清理老库里的 viewer 账号'
    );
    assertTrue(
        str_contains($sql, "DELETE FROM `admin_roles` WHERE `slug` = 'viewer'"),
        '应清理老库里的 viewer 角色'
    );
});

test('Form 收集字段校验规则并暴露给前端', function () {
    $form = new Form(new Menu());
    $form->url('/test');
    $form->text('name', '姓名')->rules('required|max:64');
    $form->text('age', '年龄')->rules(['integer']);
    $form->text('address', '地址');          // 无规则

    $rules = $form->rules();

    assertSame('required|max:64', $rules['name']);
    assertSame('integer', $rules['age']);
    assertArrayNotHasKey('address', $rules, '没设规则的字段不应出现');

    // 规则要能到前端（`n-form` 的 rules 就是从这里生成的）
    $data = $form->getData();
    $names = array_column($data['data']['fields'], 'rules', 'name');

    assertSame('required|max:64', $names['name']);
    assertSame('integer', $names['age']);
    assertSame('', $names['address']);
});

test('Form::validate 在没有规则时直接放行', function () {
    $form = new Form(new Menu());
    $form->text('name', '姓名');

    assertSame(null, $form->validate(['name' => '']));
});

test('Form::readonly keeps the url empty so nothing is posted', function () {
    $form = (new Form(new Menu()))->readonly();
    $form->title('只读表单');
    $form->text('name', '姓名');

    $data = $form->getData();

    assertSame(true, $data['readonly']);
    assertSame('', $data['url'], 'a read-only form must not derive a submit url');
});

/* --------------------------------------------------------------- report --- */

echo PHP_EOL;

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " test(s) FAILED:\n\n");

    foreach ($failures as [$name, $message]) {
        fwrite(STDERR, "  x {$name}\n    {$message}\n");
    }

    exit(1);
}

echo "OK {$passed} PHP payload tests passed" . PHP_EOL;
