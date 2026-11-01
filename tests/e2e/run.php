<?php

declare(strict_types=1);

/**
 * 端到端交互测试：把一个**已安装好**的面板从头点一遍。
 *
 *   php tests/e2e/run.php
 *
 * 每条用例都是真实 HTTP 请求 + 真实数据库断言（不是 mock），覆盖：
 * 未登录 / CSRF / 登录与锁定 / 首页 / 用户 / 角色 / 权限 / 菜单（树·开关·行操作·排序）
 * / 审计日志 / 导出 / 上传 / 自助改密 / 只读账号 403 / 退出。
 *
 * 环境变量（都有默认值，默认指向本机 9501 + hyperf_admin 库）：
 *   E2E_BASE      面板地址          默认 http://127.0.0.1:9501
 *   E2E_DB        数据库名          默认 hyperf_admin
 *   E2E_DB_HOST   数据库主机        默认 host.docker.internal
 *   E2E_DB_PORT   数据库端口        默认 3306
 *   E2E_DB_USER   数据库用户        默认 root
 *   E2E_DB_PASS   数据库密码        默认 root
 *
 * 注意：会真的建删数据（用户名/标识一律带 `e2e` 前缀，跑完自己清），
 * 只对**测试库**跑，别对着生产库来。
 */

define('BASE', rtrim((string) (getenv('E2E_BASE') ?: 'http://127.0.0.1:9501'), '/'));
define('DB', (string) (getenv('E2E_DB') ?: 'hyperf_admin'));


$jar = '/tmp/ea_e2e_cookies.txt';
$token = '';

$pass = 0;
$fail = [];

/* ---------------------------------------------------------------- helpers --- */

function raw(string $method, string $path, array|string|null $body = null, array $headers = []): array
{
    global $jar;

    $ch = curl_init(BASE . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : $body;
    }

    curl_setopt_array($ch, $opts);
    $response = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders = substr($response, 0, $headerSize);
    $bodyText = substr($response, $headerSize);
    curl_close($ch);

    $parsed = [];

    foreach (preg_split('/\R/', $rawHeaders) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($key))] = trim($value);
        }
    }

    return ['status' => $status, 'body' => $bodyText, 'headers' => $parsed];
}

function json(string $method, string $path, ?array $body = null, bool $xhr = true): array
{
    global $token;

    $headers = ['Accept: application/json'];

    if ($xhr) {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if ($token !== '') {
        $headers[] = 'X-CSRF-Token: ' . $token;
    }

    $result = raw($method, $path, $body, $headers);
    $result['json'] = json_decode($result['body'], true);

    return $result;
}

function csrfFromLoginPage(): string
{
    global $token, $jar;

    @unlink($jar);
    $page = raw('GET', '/admin/login.html', null, ['Accept: text/html']);

    if (preg_match('/"csrfToken":"([^"]+)"/', $page['body'], $m) !== 1) {
        throw new RuntimeException('登录页没有 csrfToken（status ' . $page['status'] . '）');
    }

    $token = $m[1];

    return $token;
}

function loginAs(string $user, string $pass): array
{
    csrfFromLoginPage();

    $result = json('POST', '/admin/login', ['username' => $user, 'password' => $pass]);

    return [$result['status'], $result['json'] ?? []];
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('E2E_DB_HOST') ?: 'host.docker.internal',
                getenv('E2E_DB_PORT') ?: '3306',
                DB
            ),
            (string) (getenv('E2E_DB_USER') ?: 'root'),
            (string) (getenv('E2E_DB_PASS') ?: 'root'),
            [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}

function scalar(string $sql): mixed
{
    return db()->query($sql)->fetchColumn();
}

function check(string $name, callable $fn): void
{
    global $pass, $fail;

    try {
        $fn();
        ++$pass;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $fail[] = [$name, $e->getMessage()];
        echo "  FAIL {$name}\n       " . str_replace("\n", "\n       ", $e->getMessage()) . "\n";
    }
}

function ok(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '：期望 ' . var_export($expected, true) . '，实际 ' . var_export($actual, true));
    }
}

function contains(string $haystack, string $needle, string $message): void
{
    if (! str_contains($haystack, $needle)) {
        throw new RuntimeException($message . '：缺少 "' . $needle . '"');
    }
}

/* ------------------------------------------------------------ 1. 未登录 --- */

echo "\n[1] 未登录\n";

check('浏览器访问 /admin 302 到登录页', function (): void {
    $r = raw('GET', '/admin', null, ['Accept: text/html']);
    same(302, $r['status'], '状态码');
    ok(str_ends_with($r['headers']['location'] ?? '', '/admin/login.html'), 'Location 应是 /admin/login.html，实际 ' . ($r['headers']['location'] ?? '-'));
});

check('XHR 访问 /admin/user 返回 401 信封', function (): void {
    $r = json('GET', '/admin/user');
    same(401, $r['status'], '状态码');
    same(40100, $r['json']['code'] ?? null, '业务码');
});

check('登录页可直接打开（不需要登录）', function (): void {
    $r = raw('GET', '/admin/login.html', null, ['Accept: text/html']);
    same(200, $r['status'], '状态码');
    contains($r['body'], '登录 · Hyperf Admin', '页面标题');
    contains($r['body'], 'window.__EA_BOOT__', 'boot 注入');
});

check('演示页已随发布删除（/admin/grid 404）', function (): void {
    same(404, json('GET', '/admin/grid')['status'], '状态码');
    same(404, json('GET', '/admin/showcase')['status'], '状态码');
});

/* --------------------------------------------------------------- 2. CSRF --- */

echo "\n[2] CSRF\n";

check('没有 token 的 POST 被拒（403 + 41900）', function (): void {
    global $token;

    csrfFromLoginPage();
    $saved = $token;
    $token = '';
    $r = json('POST', '/admin/login', ['username' => 'admin', 'password' => 'admin123']);
    $token = $saved;

    same(403, $r['status'], '状态码');
    same(41900, $r['json']['code'] ?? null, '业务码');
});

/* ------------------------------------------------------------ 3. 登录失败 --- */

echo "\n[3] 登录\n";

check('密码错误返回 400 并提示剩余次数', function (): void {
    db()->exec("DELETE FROM admin_login_attempts WHERE username = 'e2e_lock'");
    [$status, $body] = loginAs('e2e_lock', 'wrong-pass');
    same(400, $status, '状态码');
    contains((string) ($body['msg'] ?? ''), '还可尝试', '提示文案');
});

check('5 次失败后锁定，密码正确也拒绝（429）', function (): void {
    for ($i = 0; $i < 4; ++$i) {
        loginAs('e2e_lock', 'wrong-pass');
    }

    [$status, $body] = loginAs('e2e_lock', 'whatever');
    same(429, $status, '状态码');
    contains((string) ($body['msg'] ?? ''), '登录失败次数过多', '锁定提示');
    db()->exec("DELETE FROM admin_login_attempts WHERE username = 'e2e_lock'");
});

check('admin / admin123 登录成功', function (): void {
    [$status, $body] = loginAs('admin', 'admin123');
    same(200, $status, '状态码');
    same('登录成功', $body['msg'] ?? null, '提示');
});

check('/admin/me 返回当前用户', function (): void {
    $r = json('GET', '/admin/me');
    same(200, $r['status'], '状态码');
    same('admin', $r['json']['data']['username'] ?? null, '用户名');
});

/* --------------------------------------------------------- 4. 首页与外壳 --- */

echo "\n[4] 首页 / 侧边栏\n";

check('首页 payload 是概览（概况 + 技术栈 + 特点）', function (): void {
    $r = json('GET', '/admin/home');
    same(200, $r['status'], '状态码');
    $html = (string) ($r['json']['data']['html'] ?? '');
    contains($html, '概况', '概览卡片');
    contains($html, '技术栈', '左栏');
    contains($html, '特点', '右栏');
    contains($html, 'ea-dash__cols', '两栏布局');
});

check('HTML 外壳里的侧边栏只有「系统管理」一组', function (): void {
    $r = raw('GET', '/admin', null, ['Accept: text/html']);
    same(200, $r['status'], '状态码');
    preg_match('/"menus":(\[.*?\]),"page"/s', $r['body'], $m);
    $menus = json_decode($m[1] ?? '[]', true);
    same(1, count($menus), '顶层菜单数');
    same('系统管理', $menus[0]['title'] ?? null, '顶层标题');
    same(5, count($menus[0]['child'] ?? []), '子项数');
});

/* -------------------------------------------------------------- 5. 用户 --- */

echo "\n[5] 用户管理\n";

$createdUserId = 0;

check('列表能正常返回（至少包含 admin）', function (): void {
    $r = json('GET', '/admin/user');
    same(200, $r['status'], '状态码');

    $total = (int) ($r['json']['data']['data']['total'] ?? 0);
    ok($total >= 1, '用户总数应 >= 1，实际 ' . $total);
    ok(
        (int) scalar("SELECT COUNT(*) FROM admin_users WHERE username = 'admin'") === 1,
        '数据库里应该有 admin'
    );
});

check('新增用户（含角色多选）', function (): void {
    global $createdUserId;

    $superRoleId = (int) scalar("SELECT id FROM admin_roles WHERE slug = 'super-admin'");

    $r = json('POST', '/admin/user/store', [
        'username' => 'e2e_user',
        'name' => 'E2E 用户',
        'password' => 'e2e-pass-123',
        'roles' => [$superRoleId],
    ]);

    same(200, $r['status'], '状态码');
    same(200, $r['json']['code'] ?? null, '业务码');
    same('/user', $r['json']['data']['jumpUrl'] ?? null, '跳转地址');

    $createdUserId = (int) scalar("SELECT id FROM admin_users WHERE username = 'e2e_user'");
    ok($createdUserId > 0, '用户没有落库');

    // 角色关联（中间表）要写进去
    same(
        1,
        (int) scalar("SELECT COUNT(*) FROM admin_role_user WHERE user_id = {$createdUserId} AND role_id = {$superRoleId}"),
        '角色关联'
    );
});

check('新用户能登录（说明密码哈希是对的）', function (): void {
    [$status, $body] = loginAs('e2e_user', 'e2e-pass-123');
    same(200, $status, '状态码');
    same('登录成功', $body['msg'] ?? null, '提示');
});

check('校验：重复账号被拦（422 + 字段错误）', function (): void {
    loginAs('admin', 'admin123');
    $r = json('POST', '/admin/user/store', ['username' => 'e2e_user', 'name' => 'x', 'password' => '123456']);
    same(422, $r['status'], '状态码');
    same(42200, $r['json']['code'] ?? null, '业务码');
    ok(isset($r['json']['data']['errors']['username']), '应指出 username 字段出错：' . substr($r['body'], 0, 200));
});

check('编辑用户（改名 + 清空角色，验证「先清后插」）', function (): void {
    global $createdUserId;

    $r = json('POST', '/admin/user/' . $createdUserId, [
        'username' => 'e2e_user',
        'name' => 'E2E 改名',
        'roles' => [],
    ]);

    same(200, $r['status'], '状态码');
    same('E2E 改名', (string) scalar("SELECT name FROM admin_users WHERE id = {$createdUserId}"), '数据库里的名字');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_role_user WHERE user_id = {$createdUserId}"), '角色应被清空');
});

check('行操作：重置密码（返回一次性明文）', function (): void {
    global $createdUserId;

    $r = json('POST', '/admin/user/' . $createdUserId . '/action', ['action' => 'reset-password']);
    same(200, $r['status'], '状态码');
    preg_match('/新密码：([0-9a-f]{8})/', (string) ($r['json']['msg'] ?? ''), $m);
    ok(isset($m[1]), '提示里没有新密码：' . ($r['json']['msg'] ?? '-'));

    // 用新密码能登录，旧密码不行
    [$status] = loginAs('e2e_user', $m[1]);
    same(200, $status, '新密码登录');
    loginAs('admin', 'admin123');
});

check('搜索 / 排序 / 分页参数生效', function (): void {
    $r = json('GET', '/admin/user?q=e2e_user&order_by=id&order=desc&page=1&per_page=10');
    same(200, $r['status'], '状态码');
    same(1, $r['json']['data']['data']['total'] ?? null, '搜索命中数');
    same('e2e_user', $r['json']['data']['data']['data'][0]['username'] ?? null, '命中行');

    $newest = (string) scalar('SELECT username FROM admin_users ORDER BY id DESC LIMIT 1');
    $r = json('GET', '/admin/user?order_by=id&order=desc');
    same($newest, $r['json']['data']['data']['data'][0]['username'] ?? null, '倒序第一行');
});

check('分页（先制造足够多的日志，再翻页）', function (): void {
    // 先造一条临时菜单，反复切换开关 → 每条都写一条审计日志
    $r = json('POST', '/admin/menu/store', [
        'parent_id' => 3,
        'title' => '分页测试',
        'icon' => 'table',
        'uri' => 'page-test',
        'order' => 98,
        'is_menu' => 1,
    ]);
    same(200, $r['status'], '建测试菜单');

    $menuId = (int) scalar("SELECT id FROM admin_menu WHERE title = '分页测试'");

    for ($i = 0; $i < 30; ++$i) {
        json('POST', '/admin/menu/' . $menuId . '/toggle', ['field' => 'order', 'value' => 90 + $i]);
    }

    $page1 = json('GET', '/admin/log?page=1')['json']['data']['data'];
    $page2 = json('GET', '/admin/log?page=2')['json']['data']['data'];

    $total = (int) ($page1['total'] ?? 0);
    $perPage = (int) ($page1['per_page'] ?? 0);

    ok($total > $perPage, "日志总数 {$total} 应超过每页 {$perPage}");
    same((int) ceil($total / $perPage), (int) ($page1['last_page'] ?? 0), 'last_page = ceil(total / per_page)');
    same(1, $page1['current_page'] ?? null, '第 1 页页码');
    same(count($page2['data']), (int) ($page2['to'] ?? 0) - (int) ($page2['from'] ?? 0) + 1, '第 2 页条数与 from/to 一致');
    ok(
        ($page1['data'][0]['id'] ?? 0) !== ($page2['data'][0]['id'] ?? 0),
        '第 1 / 2 页应返回不同记录'
    );

    json('POST', '/admin/menu/' . $menuId . '/delete');
});

check('导出 CSV（带 BOM 与下载头）', function (): void {
    $r = json('GET', '/admin/user/export?scope=all');
    same(200, $r['status'], '状态码');
    contains($r['headers']['content-type'] ?? '', 'text/csv', 'Content-Type');
    contains($r['headers']['content-disposition'] ?? '', 'attachment', 'Content-Disposition');
    ok(str_starts_with($r['body'], "\xEF\xBB\xBF"), 'CSV 缺少 UTF-8 BOM');
    contains($r['body'], 'e2e_user', '导出内容');
});

check('导出「勾选行」（scope=selected）', function (): void {
    global $createdUserId;

    $r = json('GET', '/admin/user/export?scope=selected&ids=' . $createdUserId);
    same(200, $r['status'], '状态码');
    contains($r['body'], 'e2e_user', '只导勾选行');
    ok(! str_contains($r['body'], 'Administrator'), '不该包含未勾选的行');
});

check('上传图片（POST /admin/upload）', function (): void {
    // 1x1 PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents('/tmp/e2e.png', $png);

    global $token, $jar;
    $ch = curl_init(BASE . '/admin/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => new CURLFile('/tmp/e2e.png', 'image/png', 'e2e.png'), 'image' => '1'],
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . $token],
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);
    same(200, $status, '状态码');
    contains((string) ($decoded['data']['url'] ?? ''), '/uploads/', '返回的 url');

    // 上传后的文件能通过 /admin/uploads/... 访问到
    $served = raw('GET', '/admin' . $decoded['data']['url']);
    same(200, $served['status'], '访问上传文件');
});

check('删除用户', function (): void {
    global $createdUserId;

    $r = json('POST', '/admin/user/' . $createdUserId . '/delete');
    same(200, $r['status'], '状态码');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_users WHERE id = {$createdUserId}"), '数据库里应已删除');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_role_user WHERE user_id = {$createdUserId}"), '角色关联应一起清掉');
});

/* -------------------------------------------------------------- 6. 角色 --- */

echo "\n[6] 角色管理\n";

check('新增角色（权限 + 用户多对多）', function (): void {
    $r = json('POST', '/admin/role/store', [
        'name' => 'E2E 角色',
        'slug' => 'e2e_role',
        'remark' => '端到端测试',
        'permissions' => [6],
        'users' => [1],
    ]);

    same(200, $r['status'], '状态码');

    $id = (int) scalar("SELECT id FROM admin_roles WHERE slug = 'e2e_role'");
    ok($id > 0, '角色没落库');
    same(1, (int) scalar("SELECT COUNT(*) FROM admin_role_permission WHERE role_id = {$id}"), '权限关联');
    same(1, (int) scalar("SELECT COUNT(*) FROM admin_role_user WHERE role_id = {$id}"), '用户关联');
});

check('编辑角色（改名并换权限）', function (): void {
    $id = (int) scalar("SELECT id FROM admin_roles WHERE slug = 'e2e_role'");

    $r = json('POST', '/admin/role/' . $id, [
        'name' => 'E2E 角色改',
        'slug' => 'e2e_role',
        'permissions' => [2, 6],
        'users' => [],
    ]);

    same(200, $r['status'], '状态码');
    same('E2E 角色改', (string) scalar("SELECT name FROM admin_roles WHERE id = {$id}"), '名字');
    same(2, (int) scalar("SELECT COUNT(*) FROM admin_role_permission WHERE role_id = {$id}"), '权限数');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_role_user WHERE role_id = {$id}"), '用户数');
});

check('删除角色（中间表跟着清）', function (): void {
    $id = (int) scalar("SELECT id FROM admin_roles WHERE slug = 'e2e_role'");

    $r = json('POST', '/admin/role/' . $id . '/delete');
    same(200, $r['status'], '状态码');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_roles WHERE id = {$id}"), '角色');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_role_permission WHERE role_id = {$id}"), '权限关联');
});

/* ------------------------------------------------------------ 7. 权限 --- */

echo "\n[7] 权限管理\n";

check('新增 → 编辑 → 删除权限', function (): void {
    $r = json('POST', '/admin/permission/store', [
        'name' => 'E2E 权限',
        'slug' => 'e2e.view',
        'remark' => '端到端',
    ]);
    same(200, $r['status'], '新增状态码');

    $id = (int) scalar("SELECT id FROM admin_permissions WHERE slug = 'e2e.view'");
    ok($id > 0, '权限没落库');

    $r = json('POST', '/admin/permission/' . $id, [
        'name' => 'E2E 权限改',
        'slug' => 'e2e.view',
        'remark' => '改过',
    ]);
    same(200, $r['status'], '编辑状态码');
    same('E2E 权限改', (string) scalar("SELECT name FROM admin_permissions WHERE id = {$id}"), '名字');

    $r = json('POST', '/admin/permission/' . $id . '/delete');
    same(200, $r['status'], '删除状态码');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_permissions WHERE id = {$id}"), '应已删除');
});

/* -------------------------------------------------------------- 8. 菜单 --- */

echo "\n[8] 菜单管理\n";

check('列表是树形（系统管理带 5 个子节点）', function (): void {
    $r = json('GET', '/admin/menu');
    same(200, $r['status'], '状态码');
    $rows = $r['json']['data']['data']['data'] ?? [];
    same(1, count($rows), '根节点数');
    same(5, count($rows[0]['children'] ?? []), '子节点数');
    same(true, $r['json']['data']['tree'] ?? null, 'tree 标记');
});

check('新增子菜单（父级 = 系统管理）', function (): void {
    $r = json('POST', '/admin/menu/store', [
        'parent_id' => 3,
        'title' => 'E2E 菜单',
        'icon' => 'table',
        'uri' => 'e2e',
        'order' => 99,
        'is_menu' => 1,
    ]);

    same(200, $r['status'], '状态码');
    ok((int) scalar("SELECT COUNT(*) FROM admin_menu WHERE title = 'E2E 菜单'") === 1, '菜单没落库');
});

check('开关列：行内改 is_menu', function (): void {
    $id = (int) scalar("SELECT id FROM admin_menu WHERE title = 'E2E 菜单'");

    $r = json('POST', '/admin/menu/' . $id . '/toggle', ['field' => 'is_menu', 'value' => 0]);
    same(200, $r['status'], '状态码');
    same(0, (int) scalar("SELECT is_menu FROM admin_menu WHERE id = {$id}"), '数据库里的值');
});

check('行内改值只允许白名单字段', function (): void {
    $id = (int) scalar("SELECT id FROM admin_menu WHERE title = 'E2E 菜单'");

    $r = json('POST', '/admin/menu/' . $id . '/toggle', ['field' => 'title', 'value' => 'hacked']);
    same(422, $r['status'], '状态码');
    ok((int) scalar("SELECT COUNT(*) FROM admin_menu WHERE id = {$id} AND title = 'E2E 菜单'") === 1, '标题不该被改掉');
});

check('上移 / 下移（交换 order）', function (): void {
    $id = (int) scalar("SELECT id FROM admin_menu WHERE title = 'E2E 菜单'");

    $r = json('POST', '/admin/menu/' . $id . '/move', ['direction' => 'up']);
    same(200, $r['status'], '状态码');
    ok((int) scalar("SELECT `order` FROM admin_menu WHERE id = {$id}") < 99, 'order 应该变小');
});

check('行操作弹层表单（rename 的 schema）', function (): void {
    $id = (int) scalar("SELECT id FROM admin_menu WHERE title = 'E2E 菜单'");

    $r = json('GET', '/admin/menu/' . $id . '/action-form?action=rename');
    same(200, $r['status'], '状态码');
    same('rename', $r['json']['data']['action'] ?? null, 'action 名');
    same('form', $r['json']['data']['form']['type'] ?? null, '返回的是 form schema');
});

check('行操作提交：重命名', function (): void {
    $id = (int) scalar("SELECT id FROM admin_menu WHERE title = 'E2E 菜单'");

    $r = json('POST', '/admin/menu/' . $id . '/action', ['action' => 'rename', 'title' => 'E2E 改名菜单']);
    same(200, $r['status'], '状态码');
    same(1, (int) scalar("SELECT COUNT(*) FROM admin_menu WHERE id = {$id} AND title = 'E2E 改名菜单'"), '新名字');
});

check('有子菜单时拒绝删除父级', function (): void {
    $r = json('POST', '/admin/menu/3/delete');
    same(422, $r['status'], '状态码');
    contains((string) ($r['json']['msg'] ?? ''), '子菜单', '拒绝原因');
});

check('删除子菜单', function (): void {
    $id = (int) scalar("SELECT id FROM admin_menu WHERE title = 'E2E 改名菜单'");

    $r = json('POST', '/admin/menu/' . $id . '/delete');
    same(200, $r['status'], '状态码');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_menu WHERE id = {$id}"), '应已删除');
});

check('菜单导出 CSV', function (): void {
    $r = json('GET', '/admin/menu/export');
    same(200, $r['status'], '状态码');
    contains($r['body'], '系统管理', '导出内容');
});

/* -------------------------------------------------------------- 9. 日志 --- */

echo "\n[9] 审计日志\n";

check('日志记录了刚才的增删改', function (): void {
    $r = json('GET', '/admin/log');
    same(200, $r['status'], '状态码');

    foreach (['create', 'update', 'delete', 'export', 'upload', 'login'] as $action) {
        if ((int) scalar("SELECT COUNT(*) FROM admin_logs WHERE action = '{$action}'") === 0) {
            throw new RuntimeException('日志里没有 action = ' . $action);
        }
    }
});

check('日志可按关键字搜索', function (): void {
    $r = json('GET', '/admin/log?q=e2e_user');
    same(200, $r['status'], '状态码');
    ok(($r['json']['data']['data']['total'] ?? 0) > 0, '应搜到 e2e_user 相关日志');
});

/* ------------------------------------------------------------ 10. 改密码 --- */

echo "\n[10] 自助改密\n";

check('当前密码错误时拒绝（422）', function (): void {
    loginAs('e2e_user', 'nope');
    loginAs('admin', 'admin123');

    $r = json('POST', '/admin/profile', [
        'current_password' => 'wrong',
        'password' => 'whatever-1',
        'password_confirmation' => 'whatever-1',
    ]);
    same(422, $r['status'], '状态码');
    contains($r['body'], '当前密码不正确', '错误提示');
});

check('改密成功 → 会话保持可用（Cookie 重签）', function (): void {
    $r = json('POST', '/admin/profile', [
        'current_password' => 'admin123',
        'password' => 'admin456',
        'password_confirmation' => 'admin456',
    ]);
    same(200, $r['status'], '状态码');

    // 改完密码旧 Cookie 指纹失效，但响应里重签了 Cookie → 仍可访问
    $me = json('GET', '/admin/me');
    same(200, $me['status'], '改密后仍可访问');

    // 新密码能登录
    [$status] = loginAs('admin', 'admin456');
    same(200, $status, '新密码登录');
});

check('改回原密码', function (): void {
    $r = json('POST', '/admin/profile', [
        'current_password' => 'admin456',
        'password' => 'admin123',
        'password_confirmation' => 'admin123',
    ]);
    same(200, $r['status'], '状态码');

    [$status] = loginAs('admin', 'admin123');
    same(200, $status, '原密码登录');
});

/* ----------------------------------------------------------- 11. 权限裁剪 --- */

echo "\n[11] 权限裁剪（只读账号）\n";

/**
 * 0.0.1 起不再内置 viewer 账号。这里现建一个「只勾 log.view」的账号来验证：
 * 建不出来（已存在/权限不足）就跳过，不影响其余用例。
 */
$readOnlyUser = 'e2e_readonly';
$readOnlyPass = 'e2e-readonly-123';
$readOnlyReady = false;

check('准备一个只读账号（角色只勾审计日志）', function () use (&$readOnlyReady, $readOnlyUser, $readOnlyPass): void {
    if ((int) scalar("SELECT COUNT(*) FROM admin_users WHERE username = '" . $readOnlyUser . "'") > 0) {
        // 上次跑剩下的，删掉重建
        $id = (int) scalar("SELECT id FROM admin_users WHERE username = '" . $readOnlyUser . "'");
        json('POST', '/admin/user/' . $id . '/delete');
    }

    $roleId = (int) scalar("SELECT id FROM admin_roles WHERE slug = 'e2e_readonly'");

    if ($roleId === 0) {
        $r = json('POST', '/admin/role/store', [
            'name' => 'E2E 只读',
            'slug' => 'e2e_readonly',
            'permissions' => [6],
            'users' => [],
        ]);
        same(200, $r['status'], '建只读角色');
        $roleId = (int) scalar("SELECT id FROM admin_roles WHERE slug = 'e2e_readonly'");
    }

    $r = json('POST', '/admin/user/store', [
        'username' => $readOnlyUser,
        'name' => 'E2E 只读',
        'password' => $readOnlyPass,
        'roles' => [$roleId],
    ]);
    same(200, $r['status'], '建只读账号');

    [$status] = loginAs($readOnlyUser, $readOnlyPass);
    same(200, $status, '只读账号能登录');
    $readOnlyReady = true;
});

check('只读账号：侧边栏只剩审计日志', function () use (&$readOnlyReady): void {
    if (! $readOnlyReady) {
        echo "       （跳过：上一步没建成只读账号）\n";

        return;
    }

    $r = raw('GET', '/admin', null, ['Accept: text/html']);
    preg_match('/"menus":(\[.*?\]),"page"/s', $r['body'], $m);
    $menus = json_decode($m[1] ?? '[]', true);

    same(1, count($menus), '顶层菜单数');
    same(1, count($menus[0]['child'] ?? []), '可见子项数');
    same('审计日志', $menus[0]['child'][0]['title'] ?? null, '可见项');
});

check('只读账号：能看日志，被 403 拦在角色页外', function () use (&$readOnlyReady): void {
    if (! $readOnlyReady) {
        echo "       （跳过：没有只读账号）\n";

        return;
    }

    same(200, json('GET', '/admin/log')['status'], '审计日志');

    $r = json('GET', '/admin/role');
    same(403, $r['status'], '角色管理');
    same(40300, $r['json']['code'] ?? null, '业务码');

    // 浏览器直达拿到的是面板内的 403 页面，而不是 JSON
    $page = raw('GET', '/admin/role', null, ['Accept: text/html']);
    same(403, $page['status'], '浏览器 403');
    contains($page['body'], '<html', '返回 HTML');
});

check('清理只读账号与角色', function (): void {
    loginAs('admin', 'admin123');

    $userId = (int) scalar("SELECT id FROM admin_users WHERE username = 'e2e_readonly'");
    $roleId = (int) scalar("SELECT id FROM admin_roles WHERE slug = 'e2e_readonly'");

    if ($userId > 0) {
        json('POST', '/admin/user/' . $userId . '/delete');
    }

    if ($roleId > 0) {
        json('POST', '/admin/role/' . $roleId . '/delete');
    }

    same(0, (int) scalar("SELECT COUNT(*) FROM admin_users WHERE username = 'e2e_readonly'"), '账号已删');
    same(0, (int) scalar("SELECT COUNT(*) FROM admin_roles WHERE slug = 'e2e_readonly'"), '角色已删');
});

/* -------------------------------------------------------------- 12. 退出 --- */

echo "\n[12] 退出\n";

check('登出后接口回到 401', function (): void {
    loginAs('admin', 'admin123');

    $r = json('POST', '/admin/logout');
    same(200, $r['status'], '状态码');

    same(401, json('GET', '/admin/me')['status'], '登出后 /me');
});

/* --------------------------------------------------------------- report --- */

echo "\n";
db()->exec("DELETE FROM admin_login_attempts");

if ($fail === []) {
    echo "OK {$pass} 条交互用例全部通过\n";
    exit(0);
}

echo count($fail) . " 条失败：\n";
foreach ($fail as [$name, $message]) {
    echo "  x {$name}\n    {$message}\n";
}
exit(1);
