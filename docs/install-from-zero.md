# 从 0 安装（实测记录）

一次**全新 Hyperf 项目 + 全新数据库**的完整安装与交互验证记录。
不是设计文档，是照着做一遍、把每一步的真实输出和踩到的问题都记下来的结果。

- 日期：2026-11-01
- 环境：`hyperf/hyperf:8.2-alpine-vedge-swoole-slim-v6`（PHP 8.2.29 / Swoole 6.0.2）、MySQL 5.7.26
- 项目：`hyperf-skeleton-3.1` 的完整副本 → `/www/admin-fresh`
- 数据库：`hyperf_admin_fresh`（与已有的 `hyperf_admin` 完全隔离）
- 端口：**9502**（避开已在跑的 9501）
- 结果：**安装通过，45 条交互用例全绿，4 个测试套件全绿（42 + 19 + 59 + 18）**

---

## 1. 准备项目

```bash
cp -r hyperf-skeleton-3.1 admin-fresh
cd admin-fresh
```

`.env` 改数据库，端口在 `config/autoload/server.php` 改（`'port' => 9502`）：

```dotenv
APP_NAME=admin-fresh
DB_HOST=host.docker.internal      # 容器里连宿主机 MySQL
DB_DATABASE=hyperf_admin_fresh
DB_USERNAME=root
DB_PASSWORD=root
```

## 2. 装组件

> 这轮实测时组件还没发到 Packagist，所以用的是 **path 仓库**。
> 0.0.1 起已发布，装法就是一行：`composer require awengan/hyperf-admin`。

```bash
composer config repositories.hyperf-admin path /www/composer/hyperf-admin   # 仅 path 安装需要
composer require awengan/hyperf-admin:@dev
```

`skeleton` 里没有 `hyperf/validation`，它会作为依赖被自动带进来 —— 本次新增 7 个包：

```
hyperf/validation  v3.1.71        hyperf/translation  v3.1.63
egulias/email-validator 3.2.6     doctrine/lexer 2.1.1
doctrine/deprecations 1.1.6       symfony/polyfill-intl-idn v1.43.0
awengan/hyperf-admin dev-main（symlink）
```

`ConfigProvider` 是自动生效的（`extra.hyperf.config`），**宿主不用改 `config/autoload/` 里的任何文件**。

## 3. 一条命令安装

```bash
php bin/hyperf.php admin:install --migrate --self-host
```

真实输出：

```
已发布 39 个前端文件到 public/。
配置文件：config/autoload/hyperf-admin.php
迁移文件：1 个 → migrations/（已存在的不覆盖）
Migration table created successfully.
Migrating: 2024_01_01_000000_create_hyperf_admin_tables
Migrated:  2024_01_01_000000_create_hyperf_admin_tables

迁移执行完成，表结构与种子数据已就绪。
默认账号：admin / admin123（首次登录后请立刻改密码）

从 CDN 下载前端资源到 public/static/vendor …
已下载 7 个模块（2525.3 KB）
  vue → /static/vendor/vue-8e103254.mjs
  naive-ui → /static/vendor/naive-ui-4f580ecf.mjs
现在 import map 指向本地文件；删掉 public/static/vendor 即可切回 CDN。

接下来：
  1. 按需修改 config/autoload/hyperf-admin.php（默认账号 admin / admin123）
  2. php bin/hyperf.php start  →  打开 http://127.0.0.1:9502/admin
     首页 /admin/home，登录页 /admin/login.html
```

它会做四件事：**发布前端资源 → 发布配置 → 发布并执行迁移 → 下载 Vue/Naive UI 到本地**。
不想用迁移就走 `admin:install`（只发布 `sql/hyperf-admin.sql`），然后 `mysql … < sql/hyperf-admin.sql`。

## 4. 两条建库路径结果一致

| | `--migrate`（本次主路径） | 导入 `sql/hyperf-admin.sql` |
| --- | --- | --- |
| 业务表 | 8 张 | 8 张 |
| `migrations` 记账表 | 1 张 | 无（不用迁移就没有它） |
| `admin_menu` | 6 行（系统管理 + 5 项） | 同 |
| `admin_permissions` | 5 行 | 同 |
| `admin_roles` / `admin_role_user` | 1 / 1 | 同 |
| `admin_role_permission` | 5 行（全部给超级管理员） | 同 |
| `admin_users` | 1 行（admin） | 同 |

> 这轮实测时 seed 里还带一个 `viewer` 演示账号（2 行用户）。0.0.1 发布时已删除，
> 只剩 `admin` 一个账号 —— 上表按发布版填写。

两条路径都用脚本核对过内容（表名、行数、菜单层级、权限绑定、密码哈希前缀）。

## 5. 启动

```bash
php bin/hyperf.php start     # http://127.0.0.1:9502/admin
```

```
/                    200      # 宿主 skeleton 自己的 IndexController，不冲突
/admin               302 → /admin/login.html
/admin/login.html    200
/admin/grid          404      # 演示页已随发布删除
```

## 6. 交互验证（45 条，全绿）

脚本 `_tmp_e2e_fresh.php`：真实 HTTP 请求 + 真实数据库断言，不是 mock。

**未登录**：`/admin` 302 到登录页；XHR 拿 401 + `code:40100`；登录页可直开；演示页 404。

**CSRF**：不带 `X-CSRF-Token` 的 POST → 403 + `code:41900`。

**登录**：错密码 → 400「还可尝试 N 次」；连续 5 次 → 429 锁定（密码正确也拒）；对密码 → 200；`/admin/me` 返回当前用户。

**首页/外壳**：`/admin/home` 返回 `type:content`（概况 + 技术栈 + 特点 + 两栏布局）；HTML 外壳里的 `boot.menus` 只有「系统管理」一组 5 项。

**用户管理**：列表 2 条 → 新增（带角色多选，落库 + 中间表）→ 新账号能登录 → 重复账号 422 并指出 `username` 字段 → 编辑（改名 + 换角色，中间表先清后插）→ 行操作「重置密码」返回一次性明文且新密码可登录 → 搜索/排序 → 分页（`last_page = ceil(total/per_page)`）→ 导出全部 CSV（BOM + 下载头）→ 导出勾选行（不含未勾选）→ 上传图片（`/admin/uploads/...` 能取回）→ 删除（角色关联一起清）。

**角色管理**：新增（权限 + 用户多对多）→ 编辑（换权限、清用户）→ 删除（两张中间表跟着清）。

**权限管理**：新增（标识带点，如 `e2e.view`）→ 编辑 → 删除。

**菜单管理**：树形列表（根节点 1、子节点 5）→ 新增子菜单 → 开关列改 `is_menu` → 白名单外的字段（`title`）改不动（422）→ 上移/下移交换 `order` → 行操作弹层表单 schema（`action=rename`）→ 提交重命名 → 有子菜单时删父级被拒（422「该菜单下还有 N 个子菜单」）→ 删除子菜单 → 导出 CSV。

**审计日志**：写操作都留痕（`create/update/delete/export/upload/login`）→ 关键字搜索。

**自助改密**：旧密码错 → 422；改成功 → Cookie 重签（不掉线）→ 新密码可登录 → 改回。

**权限裁剪**：用一个只绑 `log.view` 的账号登录 → 侧边栏只剩「审计日志」；`/admin/log` 200；
`/admin/role` XHR 403 + `code:40300`，浏览器直达是面板内 403 页面。
（实测时这个账号是 seed 里的 `viewer`；0.0.1 起不再内置，用例改为「有就测、没有就跳过」。）

**退出**：登出后 `/admin/me` 回到 401。

## 7. 这次实测发现并修掉的问题

| # | 现象 | 根因 | 修法 |
| --- | --- | --- | --- |
| 1 | 删除用户 / 角色 / 权限 → **500**「Cannot use object of type Reply as array」 | 控制器还在按数组读 `$result['code']`，而 `deleteRecord()` 早就改成返回 `Reply` | 三处改成 `Result::isOk($result)` / `$result->isOk()` |
| 2 | 新建权限时标识带点被拒：`422 标识只能包含字母、数字、下划线和连字符` | `alpha_dash` 不允许 `.`，可内置标识全是 `user.manage` / `log.view` | 规则换成 `regex:/^[A-Za-z0-9._-]+$/`，前端 `rules.js` 同步支持 `regex:` |
| 3 | 业务错误一律 **HTTP 200**（curl 看不出失败） | 信封里的 `code` 没被套到响应状态码上 | 新增 `ReplyStatusMiddleware` + `Reply::error()` 写协程上下文：`42200→422`、`40400→404`、`41900→403`、`400→400` |
| 4 | `admin:install` 的提示写死 `http://127.0.0.1:9501/` 和 `/login.html` | 文案硬编码 | 改成读 `server.servers[0].port`、`api_base`、`login_path`、`home_path`，步骤号也跟着 `--migrate` 自适应 |
| 5 | `UploadController::store()` 的 `{` 后面直接跟代码 | 早前批量替换留下的排版 | 格式化 |

顺带确认已经修好的两个老问题：登录页白名单现在带前缀（否则未登录访问任何页都在 302 死循环）、合并表头的子列会被 `normalizeGrid` 规范化（否则「名称/图标/路由」整列空白）。

## 8. 已知行为（不是 bug）

- **`per_page` 由服务端定**（`Grid::pageSize()`），URL 上传了也不生效 —— 避免有人用 `?per_page=99999` 拖库。
- **`jumpUrl` 前缀不统一**：显式 `->url('/user')` 的模块 `jumpUrl=/user`，没显式设 url 的模块由路由推导成 `/admin/permission`。前端 `navKey()` 会归一化，两种都能跳；写自定义模块时建议显式 `url()`。
- **419 → 403**：Swoole 的原因短语表里没有 419，`status(419)` 会被静默丢掉，所以 CSRF 过期用 403 承载，语义放在 `code:41900`。
- **`--force` 是重置**：会重写 `config/autoload/hyperf-admin.php` 并删掉整个 `public/static`（自托管 vendor 也会没）。只升级代码时别带 `-f`。

## 9. 复现

```bash
# 1. 复制骨架、建库
cp -r hyperf-skeleton-3.1 admin-fresh
mysql -u root -p -e 'CREATE DATABASE hyperf_admin_fresh DEFAULT CHARSET utf8mb4'

# 2. 装 + 配（0.0.1 起第一行就够；path 仓库只在没发布时用）
cd admin-fresh
composer require awengan/hyperf-admin
# 改 .env 的 DB_*，改 config/autoload/server.php 的端口

# 3. 一条命令安装并启动
php bin/hyperf.php admin:install --migrate --self-host
php bin/hyperf.php start
```

跑完打开 `http://127.0.0.1:9502/admin`，用 `admin / admin123` 登录。

---

## 10. 又跑了两遍（不同组合）

光跑一遍不够说明问题：**第一次跑通的组合不代表别的组合也通**。所以又开了一个
独立实例，专门走另外两条分支：

| | 实例 A（本文主流程） | 实例 B（同一份重跑） | 实例 C（另一条分支） |
| --- | --- | --- | --- |
| 位置 | `/www/admin-fresh` | 同 A，清空发布物后重跑 | `/www/admin-cdn` |
| 数据库 | `hyperf_admin_fresh` | 重建同名库 | `hyperf_admin_fresh2` |
| 端口 | 9502 | 9502 | 9503 |
| 建库 | `--migrate` | `--migrate` | **导入 `sql/hyperf-admin.sql`** |
| 前端资源 | `--self-host`（本地 7 个模块） | `--self-host` | **CDN（unpkg + esm.sh）** |
| 结果 | 45/45 | 45/45 | 45/45 |

实例 B 的价值：确认结果**不依赖上一次留下的文件** —— 先 `rm -rf public/static
public/uploads config/autoload/hyperf-admin.php sql/ migrations/… runtime/`，
再走一遍 `admin:install`，装出来的东西和第一次完全一致。

实例 C 顺带验了两个只有 CDN 模式才会碰到的东西：

```text
# import map 指向外部
{"imports":{"vue":"https://unpkg.com/vue@3.5.13/dist/vue.esm-browser.prod.js",
            "naive-ui":"https://esm.sh/naive-ui@2.45.3?external=vue"}}

# CSP 会自动把这些主机加进白名单（漏了就是浏览器白屏）
script-src 'self' 'nonce-…' 'unsafe-eval' https://unpkg.com https://esm.sh
style-src  … https://unpkg.com https://esm.sh
font-src / connect-src 同理
```

即：**切 CDN / 切自托管都不用改 CSP**，`UI::effectiveImports()` 会把实际用到的主机
一并交给 `SecurityHeadersMiddleware`。两个 CDN 地址在容器内实测都返回 200。

> 注意：CDN 模式要求浏览器能访问 unpkg / esm.sh。内网、离线、或者客户网络有
> 白名单限制时，必须用 `--self-host`（实例 A 的做法），否则登录页会停在加载中。

---

## 11. 真实浏览器验证（Playwright）

前 10 节的用例都是 HTTP 层。但前端的坑在 HTTP 层是**看不见**的：请求都 200，
页面照样能白屏、按钮照样能点不动。所以又用 Playwright 驱动本机 Chrome
（headless，不下载 Chromium）把界面点了一遍：

```bash
cd tests/browser && pnpm install && node run.mjs
# 25 条用例；BASE 换实例，SHOT_DIR 换截图目录
```

覆盖：侧边栏导航与地址栏 / 合并表头的组头与子列（含单元格非空）/ 树形展开折叠 /
行内开关（含刷新后保持）/ 列排序 / 关键字搜索与重置 / 弹层行操作表单（回填、提交、
校验、取消）/ 导出按钮真的下载到 CSV / 新增·编辑·删除全链路 / 深链接刷新 /
控制台无报错。

**结果：25/25 通过。** 但第一遍跑下来抓出 8 个 HTTP 用例完全没覆盖的问题：

| # | 现象 | 根因 | 修法 |
| --- | --- | --- | --- |
| 1 | 点「导出」整页跳到 `/menu/export` → 404，页面变成 Not Found | `window.location.href` 用的是 payload 里的相对 url，没补 `apiBase` | GridPage 加 `absoluteUrl()`，导出地址走它 |
| 2 | 点「重命名」，弹层里输入框是**空的** | 弹层初值只取服务端 `form_data`，而入参表单的 form_data 就是空串 | 用当前行的值回填（`seedActionValues()`），服务端有真值则优先 |
| 3 | 新增菜单/用户时「提交」点了没反应，只提示「请先修正表单里的错误」 | `required` 规则没写 `type`，async-validator 默认按**字符串**校验 → 数字值（上级菜单 id、排序）永远判空 | `toRule('required')` 改成自定义 `validator`，绕开类型系统 |
| 4 | 新增时 POST 到 `/admin/admin/menu/store` → 404 | 服务端 `Form` 推导的提交地址带了 `/admin` 前缀，前端 `http` 又拼一次 | `Form::relativeUrl()` 剥掉前缀；`http.endpoint()` 也做成幂等 |
| 5 | 「上级菜单」下拉只显示「顶级菜单」，选不到父级 | 树形下拉默认收起 | TreeSelect 默认 `defaultExpandAll(true)`（可关） |
| 6 | 在「菜单管理」里改了名字，侧边栏没变 | 侧边栏只读代码里的默认菜单，根本没读 `admin_menu` 表 | `MenuHelper` 加 DB 菜单：config > 表 > 默认；权限 id 换成 slug，多权限「满足任一」 |
| 7 | 用户列表的头像 404（`/uploads/…`） | 上传返回的是相对路径，图片直接 `<img src>` 用，没补前缀 | 新增 `lib/url.js` 的 `adminUrl()`，列表/详情/上传预览都走它 |
| 8 | 点「重置」后树形表格变成收起 | 先重建表格（`tableKey++`）再取数，重建时用的还是旧的单行数据 | 改成取完数再重建 |

这 8 个里,3 和 4 是**功能性阻塞**（新增任何模块都失败），1 和 7 是**必现的坏体验**
（导出、头像），5 和 6 是**功能失效**（树选不到父级、改菜单不生效）。
HTTP 层一个都测不出来 —— 因为服务端接口本身是对的，错在「前端怎么用这些接口」。

## 12. 最终测试矩阵

| 套件 | 命令 | 结果 |
| --- | --- | --- |
| payload 契约 | `php tests/PayloadTest.php` | 42 ✅ |
| 装配 / 路由 | `php tests/WiringTest.php` | 19 ✅ |
| 前端纯逻辑 | `node tests/frontend/run.mjs` | 60 ✅ |
| SSR 渲染 | `cd tests/ssr && node --import ./resolve-hook.mjs run.mjs` | 18 ✅ 0 告警 |
| HTTP 交互 | `php tests/e2e/run.php` | 45 ✅ × 3 个实例 |
| 真实浏览器 | `cd tests/browser && node run.mjs` | 25 ✅（9501 / 9502） |


