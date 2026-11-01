# Hyperf Admin (Naive UI)

[![Latest Version](https://img.shields.io/packagist/v/awengan/hyperf-admin.svg)](https://packagist.org/packages/awengan/hyperf-admin)
[![License](https://img.shields.io/packagist/l/awengan/hyperf-admin.svg)](https://packagist.org/packages/awengan/hyperf-admin)

Hyperf 3.1 的后台面板组件：**登录、RBAC、增删改查、审计日志**都齐了，装完就能用，
写业务只需要拼 `Form` / `Grid`。

- 后端是 Builder 风格：`Form` / `Grid` / `Show` / `Content`，20 种表单字段
- 前端是 Vue 3 + Naive UI，用**原生 ES module + import map**，**没有构建步骤**（不需要 npm）
- 只加**一个** composer 包依赖；不用 `hyperf/session`、`hyperf/paginator`，不改宿主路由
- 前端资源有两种来源：**CDN** 或 **自托管**，一条命令切换（[见下](#前端资源cdn-还是自托管)）

**你要做的事，就两条：**

```bash
composer require awengan/hyperf-admin
php bin/hyperf.php admin:install
```

第 2 步会发布前端资源、建 9 张表、写入初始数据；然后 `php bin/hyperf.php start`，
用 `admin / admin123` 登录就完事了。

> 因为它会连数据库，**装之前先把 `.env` 的 `DB_*` 配好、库建好**（`CREATE DATABASE ...`）；
> 没配好也不会中断，会提示你配好再跑一次。
> **上线前一定要改密码并设置 `ADMIN_SECRET`**，见[上线检查清单](#上线检查清单)。

---

## 环境要求

| 项 | 要求 |
| --- | --- |
| PHP | >= 8.1 |
| Hyperf | 3.1（Swoole >= 5.0 或 Swow） |
| 数据库 | MySQL 5.7+ / 其他 Hyperf 支持的库（用 `hyperf/db-connection`） |
| 浏览器 | 原生 ESM + import map：Chrome/Edge 89+、Safari 16.4+、Firefox 108+ |
| 外网 | **只有 CDN 模式**需要浏览器能访问 `unpkg.com` 和 `esm.sh`；自托管模式装完即离线 |

---

## 快速开始

### 1. 安装

```bash
composer require awengan/hyperf-admin
```

`skeleton` 里没有 `hyperf/validation`，它会作为依赖自动带进来；`ConfigProvider`
通过 `extra.hyperf.config` 自动生效，**宿主不用改任何 config 文件**。

### 2. 先配好数据库

`admin:install` 会**自动建表并写入初始数据**，所以它需要能连上库。先改 `.env`：

```dotenv
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hyperf_admin     # 库要先建好：CREATE DATABASE hyperf_admin DEFAULT CHARSET utf8mb4
DB_USERNAME=root
DB_PASSWORD=你的密码
```

### 3. 一条命令装好（资源 + 建表）

```bash
php bin/hyperf.php admin:install
rm -rf runtime/container              # 注解扫描路径变了，必须清缓存
```

它做三件事：发布前端资源到 `public/static`、放好默认配置、**建 9 张表并写入初始数据**
（8 张业务表 + `1 个 admin 账号 / 1 个超级管理员角色 / 5 个权限 / 6 条菜单`）。

三种常见组合，按环境挑一个：

```bash
php bin/hyperf.php admin:install                      # ① 公网：前端依赖走 CDN（默认）
php bin/hyperf.php admin:install --self-host          # ② 内网/离线：把 Vue+NaiveUI 下到本地（需一次外网）
php bin/hyperf.php admin:install --no-migrate         # ③ 只想放文件、暂不碰数据库
```

②③ 可以叠加（`--self-host --no-migrate`）。**资源模式的取舍见下一节**，选错了面板会白屏。

> **数据库连不上时它不会崩**：前端资源照常发布，然后提示
> `连不上数据库，表还没建：SQLSTATE[...]`，让你配好 `.env` 后重跑一次。
> 也可以完全绕开它，自己 `mysql < sql/hyperf-admin.sql` 导 SQL（两条路结果一样）。

`admin:install` 的完整选项：

| 选项 | 作用 |
| --- | --- |
| （无） | 发布前端资源、默认配置、SQL，**并执行迁移建表 + 写初始数据** |
| `--no-migrate` | 只发布文件，不连数据库（`.env` 还没配好、或想自己导 SQL 时用） |
| `--migrate` | 旧写法，现在默认就执行，保留只为兼容脚本 |
| `--self-host` | 把 Vue / Naive UI 从 CDN 下载到本地（[两种资源模式](#前端资源cdn-还是自托管)） |
| `--reset-vendor` | 删掉本地那一份，切回 CDN |
| `--gzip` | 给已发布的静态资源预生成 `.gz`（可选，省带宽） |
| `-f` / `--force` | **重置**：重写配置、SQL，并**删掉整个 `public/static`**（自托管资源也会没） |

> `--force` 是重置不是更新。只升级组件代码时**别带 `-f`**；要新的配置/SQL 又不想重置，
> 手动覆盖 `config/autoload/hyperf-admin.php` 和 `sql/hyperf-admin.sql` 两个文件即可。

### 4. 启动

```bash
php bin/hyperf.php start
```

表和数据都齐了，直接就能登录。打开 **`http://127.0.0.1:9501/admin`**
（登录页是 `/admin/login.html`），用 `admin / admin123` 登录。

> 想手工建表也可以：`mysql -u root -p <库> < sql/hyperf-admin.sql`（脚本可重复执行）。
> 它和迁移的区别只是记账方式不同 —— 最终都是 8 张业务表 + 同一份初始数据。

- 面板所有页面都在 **`/admin`** 下面；静态资源在 `/static`
- 想换前缀：只改 `hyperf-admin.api_base`（同时改 `login_path` / `home_path`）
- 只想本地看界面、不接数据库：`hyperf-admin.auth.enabled = false`（**仅限本地**，面板变公开）

---

## 前端资源：CDN 还是自托管？

面板的前端依赖（Vue 3、Naive UI）**没有打包进这个 composer 包** —— 因为整套前端
刻意不做构建步骤。浏览器靠 HTML 里的 **import map** 找到它们，而 import map 指向哪里，
就是这里要选的两件事。

> 两种模式都**不需要 node / npm / 打包**：服务器上不用 `npm install`、不用 `npm run build`，
> 部署就是 `composer install` + 一条 `admin:install`。区别只是「依赖文件从谁的服务器下载」。

### 两种模式的差别（实测数据）

| | **CDN 模式**（默认） | **自托管模式** |
| --- | --- | --- |
| 依赖来自 | `unpkg.com` + `esm.sh` | 自己的 `public/static/vendor/` |
| 浏览器要求 | 必须能访问这两个域名 | 全部同源，零外网 |
| 登录页请求数 | **468 个**（其中 427 个来自 CDN） | **47 个**（全部同源） |
| 登录页传输量 | 约 **2.9 MB** | 约 **0.8 MB**（gzip） |
| 首屏耗时 | 取决于外网质量 | 稳定，走自己的带宽 |
| 额外磁盘 | 0 | 约 **2.5 MB**（7 个文件） |
| 装机时需要外网 | 不需要 | **需要一次**（下载依赖） |
| 生效判定 | 没有 `import-map.json` | `public/static/vendor/import-map.json` 存在 |
| 切换命令 | `admin:install --reset-vendor` | `admin:install --self-host` |

> 为什么 CDN 模式请求数那么大：`esm.sh` 会把 Naive UI 拆成几百个小模块逐个 import。
> 自托管时安装器给 esm.sh 加了 `&bundle`，把整张模块图压成 **1 个** 文件（2.4 MB）。

### 怎么选

| 你的情况 | 选 |
| --- | --- |
| 面向公网、用户网络正常、想少占磁盘 | CDN（默认，什么都不用做） |
| 内网 / 政务云 / 金融机房，浏览器出不了外网 | **自托管** |
| 部署环境有白名单，挡了 unpkg / esm.sh | **自托管** |
| 首屏要求快、不想看外网抖动 | **自托管** |
| 只有一台机器，且它自己也不能上网 | 在**能上网的机器**上 `--self-host`，把 `public/static/vendor/` 整个目录拷过去 |

**选错的典型症状**：登录页一片空白、或者一直转圈、或者控制台报
`Failed to load module script` / `ERR_NAME_NOT_RESOLVED`。—— 换成自托管即可。

### 切到自托管

```bash
php bin/hyperf.php admin:install --self-host
```

它会从 `unpkg.com` / `esm.sh` 递归下载整张模块图，**实测输出**：

```
从 CDN 下载前端资源到 public/static/vendor …
已下载 7 个模块（2525.3 KB）
  vue → /static/vendor/vue-8e103254.mjs
  naive-ui → /static/vendor/naive-ui-4f580ecf.mjs
现在 import map 指向本地文件；删掉 public/static/vendor 即可切回 CDN。
```

跑完不需要改任何配置、不需要重启（HTML 是 `no-cache`，刷新页面即生效）。

### 切回 CDN

```bash
php bin/hyperf.php admin:install --reset-vendor
# 已删除本地前端资源，切回 CDN。
```

### 怎么确认现在是哪种

任选一种：

```bash
# 1. 看文件（存在 = 自托管）
ls public/static/vendor/import-map.json

# 2. 看页面里的 import map
curl -s http://127.0.0.1:9501/admin/login.html | grep -o '"vue":"[^"]*"'
#   自托管： "vue":"/static/vendor/vue-xxxxxxxx.mjs"
#   CDN  ： "vue":"https://unpkg.com/vue@3.5.13/dist/vue.esm-browser.prod.js"

# 3. 看 CSP 响应头（自托管时不会出现外部域名）
curl -sI http://127.0.0.1:9501/admin/login.html | grep -i content-security-policy
```

CSP 是**自动跟随**的：CDN 模式下 `script-src` 会自动放行 `https://unpkg.com https://esm.sh`，
切到自托管后这些主机就消失了 —— 不用手改任何安全头。

### 离线 / 内网部署（服务器完全出不了外网）

在**能上网的机器**上准备资源，再把整个目录搬过去：

```bash
# 1) 构建机上（同一个项目、同一个组件版本）
#    --no-migrate：这一步只是准备前端文件，构建机通常没配数据库
php bin/hyperf.php admin:install --no-migrate --self-host

# 2) 打包前端资源（2.5 MB 左右）
tar czf admin-assets.tar.gz public/static

# 3) 拷到内网服务器，解到项目根目录
tar xzf admin-assets.tar.gz

# 4) 内网服务器上配好 .env 的 DB_*，再跑一次 admin:install（会自动建表），然后 start
php bin/hyperf.php admin:install
php bin/hyperf.php start
```

要点：`public/static/js` 和 `public/static/vendor` **要一起拷**（import map 里写的是
`/static/vendor/xxx.mjs`，两边的版本必须配套）；`public/static/vendor/import-map.json`
跟着过去，就自动是自托管模式了，不用再跑任何命令。

### 六个常见坑

1. **`--force` 会把自托管资源删掉**：它删整个 `public/static`。重新 `--self-host` 即可。
2. **升级组件后要重新下载**：CDN 上的依赖版本变了，本地那份还是旧的。用
   `php bin/hyperf.php admin:install --reset-vendor --self-host` 一次搞定（先删再下）。
3. **已经是自托管时再跑 `--self-host`** 会提示 `已经是自托管资源，跳过下载` —— 正常，不是失败。
4. **别手改 `public/static/**`**：那是发布产物，每次启动会被包内 `resources/static` 覆盖
   （`assets.auto_publish`）。要改前端就改包内源码，或者把 `auto_publish` 设为 `false` 自己管。
5. **nginx 层别重复 gzip**：`--gzip` 已经在 `public/static` 里放了 `.gz`，`AssetController`
   会按 `Accept-Encoding` 直接发压缩版；nginx 再压一遍没意义（也不报错）。
6. **自托管目录不要放进版本库**：`public/static/vendor` 是生成物，几个 MB，部署时生成即可。

### 私有 CDN / 锁定版本

想换 CDN 来源（自建镜像、或钉死某个版本）：

```php
// config/autoload/hyperf-admin.php
'cdn' => [
    'imports' => [
        'vue' => 'https://your.cdn/vue@3.5.13/dist/vue.esm-browser.prod.js',
        'naive-ui' => 'https://your.cdn/naive-ui@2.45.3?external=vue',
    ],
],
```

两条硬要求：

- `vue` 必须是**运行时编译器版**（`vue.esm-browser.prod.js`）。组件用 `template:` 字符串写，
  没有编译器就白屏
- `naive-ui` 必须保留 **`?external=vue`**，否则它自带一份 Vue，`useMessage()` 之类的
  composable 全部失效（这是本组件最早踩过的坑）

改完 URL 后，如果用的是自托管模式，重跑 `--reset-vendor --self-host` 让本地下新的版本。

### 可选：预压缩（gzip）

```bash
php bin/hyperf.php admin:install --gzip
# 已生成 48 个 .gz（2659 KB → 665 KB，压缩到 25%）
```

- 源码保持可读，只多出同名 `.gz`；浏览器带 `Accept-Encoding: gzip` 时自动拿压缩版
- 重启不会丢；**资源内容变了会自动失效重建**
- **不是必须的**：Swoole 自己就会压（见下一节），这一项只是把压缩提前到安装时做，
  省掉每次请求的实时压缩 CPU

---

## 静态资源：要不要配？默认不用

`public/static` 那套东西（版本号、缓存头、压缩、404）**开箱就是对的**，单机部署什么都不用配。
下面是你可能需要动的四种情况。

### 默认行为（实测）

| 请求 | 行为 |
| --- | --- |
| `/static/v1450f12f/js/app.js`（带版本） | `Cache-Control: public, max-age=31536000, immutable` + `ETag` |
| `/static/js/app.js`（老的无版本 URL） | `Cache-Control: no-cache` + `ETag`，内容没变时返回 **304** |
| 带 `Accept-Encoding: gzip` | `Content-Encoding: gzip`（1701 → 792 字节，Swoole 自动压） |
| 文件不存在 | 404，不会漏出目录内容 |
| 外壳 HTML（`/admin`、`/admin/login.html`） | `Cache-Control: no-cache`（里面有 CSRF token 和版本指针，不能缓存） |

版本号来自 `public/static` 下所有文件的内容信息（`Assets::version()`），改任何一个文件
都会换 URL —— 所以不需要手动清 CDN / 浏览器缓存。

### 什么时候要动配置

| 场景 | 要做的 |
| --- | --- |
| 容器镜像 / 只读文件系统 | `assets.auto_publish = false`，在**构建镜像时**跑一次 `admin:install`（否则每次启动同步会失败，只记一条 warning） |
| 前端资源交给 nginx 直发 | 加一个 `location`，并**把版本段 rewrite 掉**（见下面的片段）；否则 `/static/v<hash>/…` 会 404 |
| 想省 CPU，或网关不压缩 | `admin:install --gzip`；不想让 PHP 发预压文件就 `assets.gzip = false` |
| `public` 不在默认位置 | 改 `assets.public_path`（**绝对路径**），nginx 的 root 跟着改 |

```nginx
# 可选：让 nginx 直接发前端资源（省掉 PHP 的转发）
location ^~ /static/ {
    root /path/to/project/public;
    # 面板的 URL 带版本号，磁盘上没有 v<hash> 这层目录，必须先撸掉
    rewrite ^/static/v[0-9a-z]{6,16}/(.*)$ /static/$1 break;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
}
```

不写这段 rewrite、也不改别的 —— 让 PHP 发反而更省事：`AssetController` 已经把
版本前缀、`ETag`/304、`.gz` 协商、目录穿越防护都处理了。

### 四条别踩的线

1. **别手改 `public/static/**`**：那是发布产物，每次启动会被包内 `resources/static` 覆盖
   （要改就改包内源码，或把 `assets.auto_publish` 设成 `false` 自己托管）。
2. **多机 / 多副本**：`public/static` 是本地文件，每台机器都要有同一份 —— 在镜像里
   跑 `admin:install` 最省心，不要去共享目录里各写各的。
3. **面板自身的资源必须同源**：`assetBase` 固定是 `/static`（只有 Vue / Naive UI 这两个
   依赖能走 CDN，见上一节）。想整站换域名，用反向代理把它带过去。
4. **别给外壳 HTML 加缓存**：它带 `no-cache` 是有原因的（版本指针 + CSRF token）。

---

## 页面与菜单

| 路径 | 说明 |
| --- | --- |
| `/admin` | 概览首页（KPI 卡片 + 技术栈/特点 + 最近操作） |
| `/admin/user` | **用户管理**：账号增删改、重置密码、分配角色 |
| `/admin/role` | **角色管理**：增删改 + 权限分配 + 用户绑定 |
| `/admin/permission` | **权限管理**：权限字典 |
| `/admin/menu` | **菜单管理**：读写 `admin_menu`，树形展示 |
| `/admin/log` | **审计日志**（只读）：谁在什么时候改了什么 |
| `/admin/profile` | 自助改密（顶栏下拉进入） |
| `/admin/login.html` | 登录页 |
| `/static/…` | 前端资源（生产可交给 nginx） |

内置菜单：

```
系统管理  (分组)
├─ 用户管理  (/user)        user.manage
├─ 角色管理  (/role)        role.manage
├─ 权限管理  (/permission)  permission.manage
├─ 菜单管理  (/menu)        menu.manage
└─ 审计日志  (/log)         log.view
```

**侧边栏菜单的来源（优先级从高到低）**：

1. `config('hyperf-admin.menus')` —— 完全由代码控制
2. `admin_menu` 表 —— 也就是「菜单管理」维护的那份，改完刷新页面即可
3. 包内 `MenuHelper::defaultMenu()` —— 兜底

表里的 `permission` 存权限 id 数组（如 `[5]`），渲染时换成 slug；挂多个权限时满足任意一个即可见。

---

## 写自己的模块

一个模块 = 一个控制器 + 一个模型，路由来自注解，**不用改 `config/routes.php`**。

```php
<?php
namespace App\Admin;

use Awengan\HyperfAdmin\Controller\AbstractAdminController;
use Awengan\HyperfAdmin\Grid;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller(prefix: '/admin')]        // 前缀只写这一处
class ArticleController extends AbstractAdminController
{
    #[GetMapping(path: 'article')]      // ← 不要写前导斜杠，否则 prefix 会被丢弃
    public function index(): array|PsrResponseInterface
    {
        $grid = (new Grid(new Article()))
            ->title('文章')
            ->url('/article')                 // 相对地址，前缀由 api_base 统一加
            ->pageSize(15)
            ->column('title', '标题')
            ->column('created_at', '发布时间')->date('Y-m-d')
            ->deletable()
            ->searchable(['title' => '标题']);

        return $this->page($grid);            // XHR → JSON；浏览器直达 → HTML 外壳
    }
}
```

> **踩坑提醒**：`#[GetMapping(path: '/article')]` 里带了前导斜杠，Hyperf 会用这个 path
> 覆盖掉 `#[Controller(prefix)]`，最终路由变成 `/article` 而不是 `/admin/article`。
> 所有路由的 path 都写**相对**形式。

### Grid（列表）

```php
$grid = (new Grid(new Menu()))
    ->title('菜单管理')
    ->url('/menu')
    ->tree('parent_id')                          // 树形表格
    ->orderable('order')                         // 行内 ↑↓ 排序
    ->exportable()                               // 导出 CSV
    ->selectable()->deletable()                  // 勾选 + 批量删除
    ->searchable(['title' => '名称', 'uri' => '路由'])
    ->columnGroup('基本信息', function (Grid $grid) {   // 合并表头
        $grid->column('title', '名称');
        $grid->column('icon', '图标');
    })
    ->columnGroup('跳转', function (Grid $grid) {
        $grid->column('uri', '路由')->fixed('left');      // 固定列
        $grid->column('order', '排序');
    });
```

**列展示器**（`column()` 返回可链式的 `Column`）：

```php
$grid->column('avatar', '头像')->image('36x36')
     ->column('status', '状态')->switch()              // 点开关直接改数据
     ->column('title', '标题')->editable()             // 单元格变输入框，失焦即保存
     ->column('created_at', '时间')->date('Y-m-d')->between(true)   // 时间区间筛选
     ->column('tags', '标签')->expand()                // 多值展开
     ->column('name', '姓名')->copyable()->help('点一下复制');
```

**行操作**：一个模块一条 action 路由，方法里分发。

```php
$grid->viewAction('查看')
     ->rowAction('reset-password', '重置密码', ['type' => 'error', 'confirm' => '确定重置？'])
     // 带弹层表单：点「重命名」在弹层里填，不用跳页
     ->rowAction('rename', '重命名', [
         'form' => static function (Form $form): Form {
             $form->text('title', '名称')->rules('required');

             return $form;
         },
     ]);

#[PostMapping(path: 'menu/{id:\d+}/action')]
public function action(int $id): Reply|array|PsrResponseInterface
{
    return $this->dispatchRowAction($id, [
        'reset-password' => fn (int $target): Reply|array => $this->resetPassword($target),
        'rename' => fn (int $target): Reply|array => $this->rename($target),
    ]);
}
```

### Form（新增/编辑）

```php
protected function formPage(): Form
{
    $form = new Form(new Article());

    $form->text('title', '标题')->rules('required|max:120');
    $form->select('status', '状态')->options([1 => '草稿', 2 => '已发布'])->default(1);
    $form->upload('cover', '封面')->image()->maxSize(2);
    $form->treeSelect('parent_id', '上级')->options(fn () => Menu::selectTree());

    // 分页签 + 一行两列（row 要在字段都声明完之后调，它只负责排版）
    $form->tab('基本信息', function (Form $form): void {
        $form->text('author', '作者');
        $form->row(['author', 'status'], 2);
    });

    return $form;
}

#[GetMapping(path: 'article/create')]
public function create(): Reply|array|PsrResponseInterface
{
    return $this->page($this->formPage());
}

#[GetMapping(path: 'article/{id:\d+}/edit')]
public function edit(int $id): Reply|array|PsrResponseInterface
{
    return $this->page($this->formPage()->edit($id));   // edit() 负责回填
}

#[PostMapping(path: 'article/store')]
public function store(): Reply|array
{
    return $this->storeForm($this->formPage());          // 校验 → 保存 → 跳转
}

#[PostMapping(path: 'article/{id:\d+}')]
public function update(int $id): Reply|array
{
    return $this->updateForm($this->formPage(), $id);
}
```

`storeForm()` / `updateForm()` 把「校验 + 保存 + 跳转」合成一步。

### Show（详情页）

```php
$show = (new Show(new AdminUser()))->title('用户详情');

$show->panel('基本信息', function (Show $show): void {
    $show->field('username', '账号')->copyable();
    $show->field('name', '姓名');
    $show->field('avatar', '头像')->image();
}, 2);   // 两列

$show->panel('角色与时间', function (Show $show): void {
    $show->field('roles', '角色')->tag(['admin' => '管理员']);
    $show->field('created_at', '创建时间')->date();
});
```

### Content（首页 / 任意内容页）

```php
return (new Content())
    ->title('概览')
    ->card(['label' => '用户', 'value' => 12, 'hint' => '账号总数'])
    ->html('<p>随便写点 HTML</p>');
```

想让首页完全自定义：在宿主写 `app/Admin/Dashboard.php`（`make()` 返回 `Content`），
或者把 `hyperf-admin.home_page` 指到你自己的类。

---

## 认证与权限（RBAC）

不依赖 `hyperf/session`：身份放在**HMAC 签名 Cookie** 里，多 worker 天然一致。

| 表 | 说明 |
| --- | --- |
| `admin_permissions` | 权限字典：`name` / `slug` |
| `admin_roles` | 角色 |
| `admin_role_permission` | 角色 ↔ 权限 |
| `admin_role_user` | 角色 ↔ 用户 |

内置权限：`menu.manage`、`role.manage`、`permission.manage`、`user.manage`、`log.view`。
内置账号只有一个：**`admin / admin123`**（超级管理员，拥有全部权限）。

想验证「权限裁剪」的效果，自己在**用户管理**里加一个账号、在**角色管理**里建一个只勾
「审计日志」的角色再绑定过去 —— 用那个账号登录，侧边栏就只剩「审计日志」，
访问 `/admin/role` 会拿到面板内的 403 页面。

```php
Auth::can('role.manage');   // 权限判断（超管恒 true）
Auth::isSuperAdmin();
Auth::permissionSlugs();    // 当前用户的权限集合（按请求缓存）
```

控制器里用基类守卫：

```php
if ($deny = $this->guard('role.manage')) {
    return $deny;           // XHR → 403 JSON；浏览器直达 → 面板内 403 页面
}
```

另外有 `PermissionMiddleware`：按**路径前缀**自动拦（`/admin/role` → `role.manage` 等），
映射可用 `hyperf-admin.permission_map` 覆盖。侧边栏的 `permission` 只是**显示层**过滤，
真正的拦截在中间件和 `guard()` 里。

超级管理员在 `hyperf-admin.auth.super_admin_ids` 配置（默认 `[1]`）。

---

## 接口形状

控制器只有两条出口：

```php
// ① 信封：数据 / 消息 / 让前端做点什么
return Reply::success('新增成功')->redirect('/user');
return Reply::success('已删除', ['deleted' => 3])->refresh();
return Reply::error('记录不存在', 40400)->with(['id' => $id]);

// ② PSR Response：本质不同的事
return $this->page($grid);              // HTML 外壳
return $this->csvDownload($n, $csv);    // 文件下载
return $this->validationError($errors); // 422 + data.errors
return ErrorPage::render(...);          // 403 / 404 / 419 面板内错误页
```

`Reply` 实现 `Hyperf\Contract\Jsonable`，直接 `return` 即可。信封固定
`{data, code, msg, then?}`：

| 方法 | 效果 |
| --- | --- |
| `->refresh()` | 前端刷新当前列表 |
| `->redirect($url)` / `->location($url)` | SPA 内跳转（同时写 `data.jumpUrl`） |
| `->download($url)` | 触发下载 |
| `->alert('warning')` | 提示级别 |
| `->with([...])` | 追加数据 |
| `->isOk()` | 是否 `code === 200` |

**业务码会同步成 HTTP 状态码**（`ReplyStatusMiddleware` 负责，只改本来是 200 的响应）：

| 业务码 | HTTP | 场景 |
| --- | --- | --- |
| `400` / `40000` | 400 | 账号密码错误 |
| `40100` | 401 | 未登录（XHR） |
| `40300` | 403 | 没有权限 |
| `40400` | 404 | 记录不存在 |
| `41900` | 403 | CSRF 过期（Swoole 没有 419 的原因短语） |
| `42200` | 422 | 校验失败（`data.errors` 里是字段级消息） |
| `429` | 429 | 登录被锁 |
| `500` / `50000` | 500 | 服务端异常 |

所以 curl / Postman 拿到的状态码可以按 HTTP 语义判断，前端仍然只读信封里的 `code`。

---

## 校验

规则写在字段上，前后端共用一份声明：

```php
$form->text('name', '名称')->rules('required|max:64');
$form->text('slug', '标识')->rules('required|max:64|regex:/^[A-Za-z0-9._-]+$/');
```

- **服务端**（权威）：`hyperf/validation`，失败返回 HTTP 422 + `data.errors`
- **客户端**（即时提示）：`lib/rules.js` 把 `required|max|min|integer|numeric|email|date|alpha_dash|regex|in|between`
  映射成 `n-form` 规则；映射不了的（`unique` 这种要查库的）只走服务端
- 客户端不通过就不发请求；服务端仍会再校验一遍

---

## 列表页：搜索 / 筛选 / 排序

```php
$grid->searchable(['username' => '账号', 'name' => '姓名'])   // 关键字 → 这些字段 OR LIKE
     ->column('created_at', '创建时间')->between(true)        // 列级筛选控件放工具栏
     ->column('status', '状态')->in([1 => '启用', 0 => '停用'])
     ->sortable(['id', 'created_at']);                        // 允许排序的字段白名单
```

条件都从 query string 来：`?q=xxx&order_by=id&order=desc&page=2&filters[status][type]=in&filters[status][value]=1`。
**每页条数由服务端 `pageSize()` 决定**，不跟 URL 走（防止 `?per_page=99999`）。

## 删除与批量删除

```php
$grid->deletable()->selectable();      // 行内删除 + 勾选后批量删除
```

删除会先弹确认框；有外键依赖的模块（角色、用户、权限）在控制器里再清中间表 —— 都包在事务里。
只读列表用 `$grid->readonly()`（连操作列都不渲染）。

## 上传与树形选择

```php
$form->upload('avatar', '头像')->image()->maxSize(2);
$form->upload('doc', '附件')->file()->extensions('pdf,docx');
$form->treeSelect('parent_id', '上级菜单')->options(fn () => Menu::selectTree($id));
```

- 上传走 `POST /admin/upload`，落盘到 `public/uploads/Y/m/<随机名>.<ext>`，返回 `{url, name, size}`
- 返回的 `url` 是**相对路径**（`/uploads/2026/10/x.png`），渲染时前端补 `api_base` 前缀
  —— 所以以后改前缀不会让老数据里的图片失效
- 扩展名白名单，**默认不含 svg**（能内嵌脚本）；文件名完全由服务端生成
- `treeSelect` 的下拉默认**全展开**（`->defaultExpandAll(false)` 可关）

## 审计日志

`AuditLogMiddleware` 统一记录所有写请求（方法、路径、打码后的请求体、响应码），
业务里可以再补一条语义更清楚的：

```php
AuditLog::record('update', [
    'target' => AdminUser::class,
    'target_id' => $user->id,
    'summary' => '修改自己的密码：' . $user->username,
]);
```

日志页只读，支持按操作人/路径/摘要搜索，可导出 CSV。

---

## 配置项

`config/autoload/hyperf-admin.php`（`admin:install` 会放一份，已存在不覆盖）：

| 键 | 默认 | 说明 |
| --- | --- | --- |
| `title` | `Hyperf Admin` | 侧边栏标题 |
| `api_base` | `/admin` | 面板前缀，**唯一的前缀来源** |
| `login_path` / `home_path` | `/admin/login.html` / `/admin/home` | 要和 `api_base` 对上 |
| `locale` | `zh-CN` | `zh-CN` / `en-US` |
| `theme` | `[]` | 透传给 `n-config-provider` 的 `themeOverrides` |
| `cdn.imports.vue` | unpkg 3.5.13 | 必须是**运行时编译器版** |
| `cdn.imports.naive-ui` | `esm.sh/naive-ui@2.45.3?external=vue` | 必须带 `external=vue` |
| `auth.enabled` | `true` | 关掉即公开（仅本地预览） |
| `auth.secret` | 空 | 签名 Cookie 的密钥，生产必须设置（`ADMIN_SECRET`） |
| `auth.ttl` | `604800` | Cookie 有效期（秒） |
| `auth.except` | `['/health', '/favicon.ico']` | 免登录路径 |
| `auth.super_admin_ids` | `[1]` | 跳过权限校验的账号 |
| `auth.login_throttle` | 5 次 / 300s / 锁 300s | 登录失败限制 |
| `permission_map` | `[]` | 额外的「路径前缀 => 权限」 |
| `upload.path` / `max_size` | `uploads` / `2` | 上传目录（相对 `public/`）、单文件上限 MB |
| `menus` | `[]` | 侧边栏；留空用 `admin_menu` 表，再空用内置默认 |
| `assets.auto_publish` | `true` | 每次启动把前端资源同步到 `public/` |
| `assets.public_path` | `BASE_PATH/public` | 静态资源根目录（**绝对路径**） |
| `assets.gzip` | `true` | 有预生成 `.gz` 时优先发压缩版（Swoole 本来也会实时压） |
| `security.csp.enabled` | `true` | 关掉就不发 CSP（只留 X-Frame-Options 等） |
| `home_page` | `App\Admin\Dashboard` | 首页实现类，不存在则用包内默认 |

会话安全：Cookie 里带 `pv` 指纹（= `sha1(密码哈希 + session_version)`），改密码或
`admin:revoke` 后旧 Cookie 立即失效；改自己的密码会自动重签，不会把自己踢下线。

---

## 上线检查清单

- [ ] **改掉 `admin123`**：`php bin/hyperf.php admin:password admin '强密码'`
- [ ] **设置 `ADMIN_SECRET`**（否则签名密钥由代码路径推导，是可预测的）
- [ ] 确认资源模式：内网/离线环境用**自托管**（`--self-host`）
- [ ] `hyperf-admin.auth.enabled` 保持 `true`，`auth.except` 别放太宽的路径
- [ ] HTTPS 下确认响应头有 HSTS（`SecurityHeadersMiddleware` 会自动加）
- [ ] 只读文件系统/镜像部署：`assets.auto_publish = false`，构建时跑 `admin:install`
- [ ] 确认 `public/uploads` 可写（或把上传目录挂到持久卷）
- [ ] `storage`/`runtime` 目录不可通过 Web 访问

> 前端资源默认由 PHP 发就够用；要交给 nginx 的话看[「静态资源」那节](#静态资源要不要配默认不用)的片段
> （重点是那条 `rewrite`，漏了会 404）。

---

## 升级

```bash
composer update awengan/hyperf-admin
php bin/hyperf.php admin:install            # 同步前端资源 + 补跑新增的迁移
#   （不会覆盖你改过的配置/SQL；表结构没变时就是 Nothing to migrate）
# 自托管模式还要刷新本地依赖：
php bin/hyperf.php admin:install --reset-vendor --self-host
rm -rf runtime/container && php bin/hyperf.php start
```

新版本带的迁移会跟着 `admin:install` 自动补跑；也可以对比新的 `sql/hyperf-admin.sql`
（里面带可重复执行的清理/补列语句），老库导入后同样会补齐结构、清掉已下线的演示数据。

---

## 测试

> 以下测试都在 **Git 仓库**里（`tests/`）。Composer 下载的 dist 包为了瘦身不含
> `tests/`、`docs/`、`.github/`，要跑测试请 `git clone` 源码仓库。

前三个不需要 Swoole、不需要数据库：

```bash
HYPERF_VENDOR=/path/to/host/vendor/autoload.php php tests/PayloadTest.php   # 42 项：payload 契约 / 校验规则
HYPERF_VENDOR=/path/to/host/vendor/autoload.php php tests/WiringTest.php    # 19 项：注解路由 / 配置装配 / 中间件
node tests/frontend/run.mjs                                                # 60 项：前端纯逻辑
```

第四个是 SSR 渲染测试（真实 Vue + Naive UI 把每个页面渲染成 HTML，fixtures 是真实服务端响应）：

```bash
cd tests/ssr && pnpm install && pnpm test    # 18 个页面，0 告警
```

第五个是**端到端交互测试**（真实 HTTP + 真实数据库，45 条），需要先装好并跑起面板：

```bash
E2E_BASE=http://127.0.0.1:9501 E2E_DB=hyperf_admin php tests/e2e/run.php
```

第六个是**真实浏览器验证**（Playwright 驱动本机 Chrome/Edge，不下载 Chromium，25 条）：

```bash
cd tests/browser && pnpm install && node run.mjs     # 默认打 9501
BASE=http://127.0.0.1:9502 node run.mjs              # 换实例
```

> 这两个测试目录都依赖 `node_modules`，跑完记得删掉（`.gitignore` 已经排除，不会进包）。

从 0 安装一遍的完整实测记录（新建 Hyperf 项目 + 新数据库 + 45 条交互用例 + 8 个
「HTTP 层看不见」的问题）在 [docs/install-from-zero.md](docs/install-from-zero.md)。

---

## 常见问题

**登录页空白 / 一直转圈？**
八成是 CDN 模式但浏览器到不了 unpkg/esm.sh。看控制台的 network，或直接
`admin:install --self-host` 转自托管。

**改前端 JS 没效果？**
`public/static` 是发布产物，每次启动会被包内源码覆盖。改 `resources/static/js/**`；
改完 `admin:install` 或重启即可（URL 带版本号，浏览器不会用旧缓存）。

**登录成功后刷新又回登录页？**
通常是 `api_base` 和 `login_path` 不一致（比如只改了 `api_base`）。两者要一起改；
另外确认 `assets.auto_publish` 没在只读文件系统上抛错（会记 warning，不影响启动）。

**访问接口返回 403 但菜单里能看到？**
菜单的 `permission` 只管显示。真正拦截在 `PermissionMiddleware`（按路径前缀）和
控制器的 `guard()`；给角色补上对应权限即可。

**导出的 CSV 在 Excel 里乱码？**
导出带 UTF-8 BOM，Excel 双击能正常打开；如果用了第三方工具另存，注意别丢 BOM。

**上传的图片 404？**
图片地址是相对路径，渲染时补前缀；若你把 `/static` 交给 nginx，记得
`/admin/uploads/…` 也要能访问（或改成 nginx 直接发 `public/uploads`）。

**改「菜单管理」里的菜单，侧边栏没变？**
侧边栏来自 `admin_menu` 表（刷新页面生效）。如果你在配置里写了 `menus` 数组，
配置优先 —— 那是代码菜单，表里的改动就看不到了。

---

## 已知限制

- **必须能用原生 ESM**：IE 与很老的浏览器不支持 import map
- CSP 里保留 `'unsafe-eval'`：组件用 `template:` 字符串 + Vue 运行时编译器，想彻底去掉只能引入构建步骤
- 路由固定在 `api_base` 下（默认 `/admin`）；宿主不要再往这个前缀下注册自己的路由
- 权限粒度到「路径前缀」，没有行级/字段级数据权限
- 树形表格只对**当前页**的行做嵌套，层级数据建议一次取全
- 没有拖拽排序（用 ↑↓ 按钮换排序值）；行内编辑只有单字段输入框（`->editable()`），没有整行批量编辑
- 导出只支持 CSV（带 BOM），没有 xlsx 样式/多 sheet

**还没做的**：i18n 文案（校验消息是中文模板）、`HasMany` 子表单、性能压测。

---

## 目录结构

```
composer/hyperf-admin/
├── config/hyperf-admin.php       # 默认配置（发布到宿主可覆盖）
├── database/admin.sql            # 建表 + 种子数据
├── database/migrations/          # 等价迁移（--migrate 用）
├── docs/
│   ├── naive-ui-rewrite.md       # 重写说明：字段映射、行为差异
│   └── install-from-zero.md      # 从 0 安装的实测记录
├── resources/static/             # 前端源码（发布到宿主 public/static）
│   ├── css/app.css
│   └── js/{app.js,lib,components,fields}
├── src/
│   ├── ConfigProvider.php        # 组件注册入口
│   ├── Form.php Grid.php Show.php Content.php Reply.php UI.php
│   ├── Command/                  # admin:install / admin:password / admin:revoke
│   ├── Controller/               # Admin / User / Role / Permission / Menu / Log / Upload / Asset
│   ├── Helper/                   # Assets / VendorAssets / Auth / MenuHelper / Dashboard / Utils
│   ├── Listener/                 # BootListener（启动同步前端资源）
│   ├── Middleware/               # Auth / Csrf / Permission / AuditLog / SecurityHeaders / ErrorPage / ReplyStatus
│   ├── Model/                    # AdminUser / Role / Permission / Menu / AuditLogEntry
│   ├── Traits/                   # AdminJsonBuilder / ListField / ModelTree
│   └── UI/Form/                  # 20 种字段构建器
└── tests/                        # 契约 / 装配 / 前端 / SSR / e2e / 浏览器
```

包名 `awengan/hyperf-admin`，命名空间 **`Awengan\HyperfAdmin\`**（PSR-4，大小写敏感）。

## License

MIT
