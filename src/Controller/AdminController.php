<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Controller;

use Awengan\HyperfAdmin\Reply;

use Awengan\HyperfAdmin\Form;
use Awengan\HyperfAdmin\Helper\ActionResponse;
use Awengan\HyperfAdmin\Helper\AuditLog;
use Awengan\HyperfAdmin\Helper\Auth;
use Awengan\HyperfAdmin\Helper\ErrorPage;
use Awengan\HyperfAdmin\Helper\Csrf;
use Awengan\HyperfAdmin\Helper\HomePage;
use Awengan\HyperfAdmin\Helper\LoginThrottle;
use Awengan\HyperfAdmin\Helper\Result;
use Awengan\HyperfAdmin\Helper\Utils;
use Awengan\HyperfAdmin\Middleware\AdminAuthMiddleware;
use Awengan\HyperfAdmin\Middleware\ErrorPageMiddleware;
use Awengan\HyperfAdmin\Middleware\ReplyStatusMiddleware;
use Awengan\HyperfAdmin\Middleware\SecurityHeadersMiddleware;
use Awengan\HyperfAdmin\Middleware\CsrfMiddleware;
use Awengan\HyperfAdmin\Middleware\AuditLogMiddleware;
use Awengan\HyperfAdmin\Model\AdminUser;
use Awengan\HyperfAdmin\UI;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Throwable;

/**
 * 外壳渲染与登录鉴权。
 *
 * 路由来自注解（宿主无需改 `config/routes.php`）；`#[Middleware]` 作用于本类
 * 全部路由，登录页与 `/login` 由中间件内置白名单放行。
 */
#[Controller(prefix: '/admin')]
#[Middleware(SecurityHeadersMiddleware::class)]
#[Middleware(ErrorPageMiddleware::class)]
#[Middleware(ReplyStatusMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
#[Middleware(AdminAuthMiddleware::class)]
#[Middleware(AuditLogMiddleware::class)]
class AdminController extends AbstractAdminController
{
    public function __construct(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 入口：渲染 SPA 并预置首页数据。
     */
    #[GetMapping(path: '')]
    public function index(): PsrResponseInterface
    {
        return $this->shell(['page' => HomePage::resolve()->getData()]);
    }

    /**
     * 首页内容（登录后跳转、侧边栏「首页」、深链接刷新都落在这里）。
     *
     * 内容来自 `hyperf-admin.home_page` 指定的类（默认 `App\Admin\Dashboard`，
     * 宿主没有就退回包内默认实现）。
     */
    #[GetMapping(path: 'home')]
    public function home(): Reply|array|PsrResponseInterface
    {
        return $this->page(HomePage::resolve());
    }

    #[GetMapping(path: 'login.html')]
    public function loginPage(): PsrResponseInterface
    {
        if (Auth::check()) {
            return $this->response->redirect('/admin');
        }

        return $this->response
            ->html(UI::login([
                'title' => '登录 · ' . (string) Auth::config('title', 'Hyperf Admin'),
            ]))
            ->withHeader('Cache-Control', 'no-cache');
    }

    #[PostMapping(path: 'login')]
    public function login(): Reply|array
    {
        $username = (string) $this->request->input('username', '');
        $password = (string) $this->request->input('password', '');
        $ip = Utils::clientIp($this->request);
        $throttle = (array) Auth::config('auth.login_throttle', []);

        if ($username === '' || $password === '') {
            AuditLog::record('login', ['summary' => '登录失败：用户名或密码为空', 'status' => 400]);

            return Reply::error('请输入用户名和密码', 400);
        }

        // 已锁定：密码对不对都拒绝（避免撞库）
        if (($locked = LoginThrottle::lockedMessage($username, $ip, $throttle)) !== null) {
            AuditLog::record('login', [
                'summary' => '登录被拒（锁定中）：' . $username,
                'payload' => ['username' => $username],
                'status' => 429,
            ]);

            return Reply::error($locked, 429);
        }

        try {
            $user = AdminUser::getUserByUserName($username);

            if ($user === null || ! $user->checkPassword($password)) {
                LoginThrottle::hit($username, $ip, $throttle);

                $remaining = LoginThrottle::remaining($username, $ip, $throttle);
                $message = $remaining > 0
                    ? sprintf('用户名或密码错误（还可尝试 %d 次）', $remaining)
                    : '用户名或密码错误';

                AuditLog::record('login', [
                    'summary' => '登录失败：' . $username,
                    'payload' => ['username' => $username],
                    'status' => 400,
                ]);

                return Reply::error($message, 400);
            }

            LoginThrottle::clear($username, $ip);

            Auth::login([
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                // 改密码 / revoke 之后旧 Cookie 立即失效
                'fingerprint' => Auth::fingerprint((string) $user->password, (int) $user->session_version),
            ]);

            AuditLog::record('login', [
                'target' => AdminUser::class,
                'target_id' => $user->id,
                'summary' => '登录成功：' . $user->username,
                'status' => 200,
            ]);

            return Reply::success('登录成功', null);
        } catch (Throwable $e) {
            // 数据库还没配好时也让登录页有明确反馈，而不是空白 500。
            AuditLog::record('login', ['summary' => '登录异常：' . $e->getMessage(), 'status' => 500]);

            return Reply::error('登录失败：' . $e->getMessage(), 500);
        }
    }

    #[PostMapping(path: 'logout')]
    public function logout(): Reply|array
    {
        AuditLog::record('logout', ['summary' => '退出登录']);

        Auth::logout();

        return Reply::success('退出成功', null);
    }

    /**
     * 当前登录用户，供头部下拉使用。
     */
    #[GetMapping(path: 'me')]
    public function me(): Reply|array
    {
        return Reply::success('ok', Auth::profile());
    }

    /**
     * 修改自己的密码。
     *
     * 需要验旧的密码，成功后**重新签发 Cookie**（指纹变了，旧 token 会立刻失效，
     * 不重签的话用户改完密码下一秒就被踢出去）。
     */
    #[GetMapping(path: 'profile')]
    public function profilePage(): Reply|array|PsrResponseInterface
    {
        return $this->page($this->profileForm());
    }

    #[PostMapping(path: 'profile')]
    public function updateProfile(): Reply|array|PsrResponseInterface
    {
        $form = $this->profileForm();
        $payload = $this->request->all();

        if ($errors = $form->validate($payload)) {
            return $this->validationError($errors);
        }

        $user = AdminUser::query()->find(Auth::id());

        if ($user === null) {
            return ErrorPage::render($this->request, $this->response, 404, '404 用户不存在', '用户不存在', ['/login.html' => '重新登录']);
        }

        if (! $user->checkPassword((string) ($payload['current_password'] ?? ''))) {
            return $this->validationError(['current_password' => ['当前密码不正确']]);
        }

        $user->password = (string) $payload['password'];
        $user->save();

        // 指纹随密码变了：重签 Cookie，免得用户改完密码就被踢下线
        Auth::login([
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'fingerprint' => Auth::fingerprint((string) $user->password, (int) $user->session_version),
        ]);

        // token 也轮换一次
        Csrf::rotate();

        AuditLog::record('update', [
            'target' => AdminUser::class,
            'target_id' => $user->id,
            'summary' => '修改自己的密码：' . $user->username,
        ]);

        return ActionResponse::success('密码已修改')->redirect('/admin')->toArray();
    }

    private function profileForm(): Form
    {
        $form = new Form(new AdminUser());

        $form->title('修改密码')->url('/profile');
        $form->password('current_password', '当前密码')->rules('required');
        $form->password('password', '新密码')->rules('required|min:6|max:64');
        $form->password('password_confirmation', '确认新密码')->rules('required|same:password');

        return $form;
    }
}
