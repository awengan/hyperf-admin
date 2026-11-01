# Element UI → Naive UI 重写说明

本文记录 `easy-admin`（Vue 2 + Element UI，命名空间 `App\Admin`）→ 本组件
（Vue 3 + Naive UI，命名空间 `Awengan\HyperfAdmin`）的映射关系、行为差异与验证方式。

安装与快速开始见 [README](../README.md)。

> **后记**：本文是重写过程的记录。发布版后来把**演示模块整体删除**了
> （`DemoController`、`Model\Test`、`test` 表、演示菜单与 `demo.view` 权限），
> 所以文中出现的 `/grid`、`/grid/create`、`/showcase`、`/area`、`demo.view`
> 属于当时的验证样例，现在只剩「系统管理」一组功能页。表单字段的各种用法
> 保留在 `src/UI/Form/` 的字段类里，用 `Form::text()` / `->date()` 之类照写即可。

---

## 1. 技术形态变化

| 项 | 旧版 | 新版 |
| --- | --- | --- |
| 形态 | 裸组件目录（拷到宿主 `app/Admin`） | composer 包（`awengan/hyperf-admin`），`ConfigProvider` 自动注册 |
| 命名空间 | `App\Admin` | `Awengan\HyperfAdmin` |
| Vue | 2.x | 3.5（CDN，运行时编译器版） |
| 组件库 | Element UI 2.15 | Naive UI 2.45 |
| 前端加载 | CDN `<script>` + 全局 `Vue.component` | import map + 原生 ESM，无打包 |
| 路由 | 宿主 `@Controller` 注解 | 包内注解 + `ConfigProvider` 追加扫描路径 |
| 鉴权 | `hyperf/session` | HMAC 签名 Cookie（零额外依赖） |
| 分页 | `hyperf/paginator` | 自行查询组装（零额外依赖） |
| HTTP | axios（CDN） | 原生 `fetch` |
| 静态文件 | 依赖 nginx | `AssetController` 兜底 `/static/*` |
| 深链接 | `/grid` 直接返回 JSON | 非 XHR 请求返回带首屏数据的 HTML，可刷新/收藏 |

前端依旧不打包：`resources/` 里的 `.js` 就是浏览器执行的模块，
组件注册全靠 `composer.json` 的 `extra.hyperf.config`。

### 命名空间为什么是 `Awengan\HyperfAdmin\`

包名 `awengan/hyperf-admin` 映射到命名空间时首字母大写（与 `symfony/` → `Symfony\`、
`hyperf/` → `Hyperf\` 一致）。原来平铺的 `HyperfAdmin\` 太泛，收进 vendor 段后不会
和别人的包撞名。

注意 **PHP 类名大小写不敏感，但 Composer 的 PSR-4 前缀匹配是大小写敏感的**：
`ClassLoader::findFileWithExtension()` 用 `isset($this->prefixDirsPsr4[$search])`
按字面字符串查表。实测（全新进程、前缀 `Awengan\HyperfAdmin\`）：

- `Awengan\HyperfAdmin\Form` → 正常 autoload
- `awengan\HyperfAdmin\Grid` → **不会** autoload（Class not found）

所以 `use` 语句、`extra.hyperf.config`、`composer.json` 的 `psr-4` 必须完全一致。

### 为什么 `naive-ui` 要带 `?external=vue`

`esm.sh` 默认会把 `naive-ui` 的整条依赖树（`vueuc`、`vooks`、`@css-render/vue3-ssr`…）
各自打包，每个都可能内联一份 Vue。`?external=vue` 让这些子模块把 `vue` 保留成
bare import，交给 import map 解析 —— 保证全局只有一份 Vue，否则
`useMessage()` / `useDialog()` 等注入全部失效。

已验证：`esm.sh/naive-ui@2.45.3?external=vue` 产物里 `from"vue"` 出现 363 次，
绝对 Vue URL 0 次，导出的 588 个符号覆盖本组件用到的全部 38 个。

### 为什么 `vue` 必须是 `vue.esm-browser.prod.js`

组件的 UI 用 `template: '<n-button/>'` 字符串书写（无 SFC 编译），需要运行时编译器。
`vue.esm-browser.prod.js` 是**完整版**（`compile()` 可用）；`vue.runtime.esm-browser.js`
不含编译器，换成它会直接白屏。

## 2. 字段映射

| PHP 字段 | Element UI | Naive UI | 说明 |
| --- | --- | --- | --- |
| `text` | `el-input` | `n-input` | 新增 `disabled` / `readonly` / `clearable` / `maxlength` |
| `password` | `el-input show-password` | `n-input type=password` | `showPasswordOn('click'\|'mousedown'\|false)` |
| `textarea` | `el-input type=textarea` | `n-input type=textarea` | `autosize` 保留 `minRows`/`maxRows` |
| `number` | `el-input-number` | `n-input-number` | `stepStrictly`、新增 `showButton` |
| `radio` | `el-radio-group` | `n-radio-group` | `button()` → `n-radio-button`；无「带边框」属性，用 CSS 卡片补 |
| `checkbox` | `el-checkbox-group` | `n-checkbox-group` | Naive 无 checkbox-button，`button()`/`border()` 合并为带框卡片；`min`/`max` 前端校验 |
| `switch` | `el-switch` | `n-switch` | `activeColor`/`inactiveColor` → `rail-style`；`activeText`/`inactiveText` → 插槽 |
| `slider` | `el-slider` | `n-slider` | `showStops` → `marks` 刻度；`showInput` → 附带 `n-input-number` |
| `select` | `el-select` | `n-select` | 联动 `load($field, $url)` 保留 |
| `date` | `el-date-picker` | `n-date-picker` | 用 `formatted-value` + `value-format`，值仍是字符串 |
| `datetime` | `el-date-picker datetime*` | `n-date-picker` | 同上；单/范围各自映射 `defaultTime` |
| `time` | `el-time-select` / `el-time-picker` | `n-select` / `n-time-picker` | Naive 无时间范围选择器，`isRange()` 用两个 picker 组合成 `[start, end]` |
| `transfer` | `el-transfer` | `n-transfer` | `titles()` → `sourceTitle`/`targetTitle` |

**没有 Naive UI 对应实现、因此被忽略的属性**（PHP 方法保留，仅前端不消费）：

- `Select`：`filterMethod`, `noMatchText`, `noDataText`, `reserveKeyword`,
  `defaultFirstOption`, `automaticDropdown`, `autocomplete`
- `Slider`：`marks()`（改用 `showStops` 生成刻度）
- `Transfer`：`filterMethod`, `renderContent`, `buttonTexts`, `targetOrder`
- `Date`：`unlinkPanels`, `align`（Naive 双面板本就独立）
- `Time` 的 `rangeSeparator()`（旧版即被注释掉的 bug，未恢复）

刻意不再支持的：旧版把 `filterMethod()` / `renderContent()` 里的字符串用
`new Function()` 在浏览器执行（服务端下发 JS = XSS / 后门面）。重写版**不执行任何
服务端下发的代码**，搜索改为按选项文本匹配。

`date` 的 `week`、`dates`（多选日期）类型 Naive 无对应控件，降级为 `date`。

## 3. 顺带修掉的旧缺陷

| 旧代码 | 问题 | 处理 |
| --- | --- | --- |
| `Form::addField()` 用 `ucfirst($type)` 拼类名 | `datetime` → `Datetime.php`、`switch` 无对应类，**Linux 上必崩** | 显式 `componentMap` 注册表 |
| `Time` 用动态属性把子控件属性拷到自身 | PHP 8.2 弃用、PHP 9 移除 | 组合 + `getData()` 展平，payload 形状不变 |
| `AdminJsonBuilder::getData()` 每次调用都追加 `fields` | 重复调用导致字段翻倍 | `built` 守卫，可重复调用 |
| `AuthMiddleWare` 只比对精确路径 | `/static/*`、favicon 被拦 | 中间件内置白名单 + `auth.except` |
| 演示表单字段与 `test` 表不一致 | 提交必失败 | 表格字段与表结构对齐；另设只读 `/showcase` |
| `Result::error($code, $msg, $data)` 与 `result($data, $code, $msg)` 参数顺序不一致 | 易错 | 保留旧签名兼容，新增 `success()` / `unauthorized()` / `isOk()` |

## 4. 验证

```bash
HYPERF_VENDOR=/path/to/host/vendor/autoload.php php tests/PayloadTest.php  # 40 项
HYPERF_VENDOR=/path/to/host/vendor/autoload.php php tests/WiringTest.php   # 17 项
node tests/frontend/run.mjs                                                # 46 项
cd tests/ssr && pnpm install && pnpm test                                  # 19 个页面 SSR
node --check <每个 .js>                                                     # 29 个模块
php -l <每个 .php>                                                         # PHP 8.2
```

### 实跑结果

在 Hyperf 3.1 + PHP 8.2.29 + Swoole + MySQL 5.7.26 的环境中，以 path 仓库安装后
`composer require` → `admin:install` → `bin/hyperf.php start` 跑通，并用 curl 验证：

| 用例 | 结果 |
| --- | --- |
| `GET /` 未登录 | 200，HTML 外壳含 import map / boot payload |
| `GET /login.html` | 200，登录页外壳 |
| `GET /static/**` | 30 个文件全部 200，MIME 正确 |
| `GET /grid`（XHR，未登录） | 401 `{"code":40100,"msg":"Unauthenticated"}` |
| `POST /login` 正确 / 错误密码 | 200 + `Set-Cookie`（HttpOnly/SameSite=Lax）/ 400 |
| `GET /grid`（带 Cookie） | 从 MySQL 读出真实数据，分页字段完整 |
| `GET /grid/create`、`/grid/8/edit` | 表单页；编辑页正确回填 |
| `POST /grid/store`、`POST /grid/8` | 落库成功（`hobby` 按 JSON 数组存取） |
| `GET /grid` 浏览器直达（无 XHR） | 返回带首屏数据的 HTML（深链接可用） |
| `GET /area?q=2` | 联动下拉数据 |
| `POST /logout` → `GET /grid` | 退出后回到 401 |
| `GET /menu`（菜单管理） | 读 `admin_menu`，按 `parent_id → order` 排序，12 行种子数据 |
| `GET /menu/create` | 新增表单；`url` 自动推导为 `/menu/store`，父级下拉按树形缩进 |
| `GET /menu/4/edit` | 编辑表单，6 个字段全部正确回填 |
| `POST /menu/store`、`POST /menu/8` | 新增 / 修改成功，列表顺序随 `order` 变化 |
| `GET /menu/options` | 返回 `{id: 标题}` 的树形父级选项 |
| `GET /menu` 未登录 | XHR 401 / 浏览器 302 → 登录页 |

`/menu` 是完整的 CRUD 模块范例（`MenuController`）：列表、新增、编辑、保存、
父级下拉、排序全部走通。

### RBAC（角色 / 权限）

| 用例 | 结果 |
| --- | --- |
| `GET /permission`、`GET /role`（admin） | 6 条权限、2 个角色 |
| `GET /role/create` | 权限/用户勾选项来自数据库（6 + 2 项） |
| `GET /role/2/edit` | `permissions=[1]`、`users=[2]` 从中间表正确回填 |
| `POST /role/store` | 新角色落库，两张中间表同时写入 |
| `POST /role/{id}` | 权限/用户整体替换（旧关联被清掉） |
| `POST /role/{id}/delete` | 角色与两张中间表一起删除 |
| viewer 登录后侧边栏 | 只剩「表格 / 组件示例 / 子菜单」（「系统管理」整组被过滤） |
| viewer 访问 `/role`、`/permission`、`/menu`、`/user`、`/log` | XHR → 403 JSON 信封；浏览器直达 → 面板内 403 页面 |
| viewer 访问 `/grid`、`/showcase`、`/home` | 200（仅需 `demo.view`） |
| 浏览器里勾选态 | `/role/2/edit` 只有「示例页面」+「只读用户」是选中态（共 8 个勾选项） |

### 用户管理

| 用例 | 结果 |
| --- | --- |
| `GET /user` | 列 = ID / 账号 / 姓名 / 操作；`deletable`、`selectable` 均为 true |
| `GET /user/create` | 字段规则 `required|max:64|alpha_dash`（账号）、`required|min:6|max:64`（密码） |
| `GET /user/2/edit` | `password` 为**空串**（不回显哈希）、`roles=[2]` |
| `POST /user/store` 空账号/空姓名/短密码 | 422，一次返回三个字段的错误 |
| `POST /user/store` 账号含空格 | 422「账号只能包含字母、数字、下划线和连字符」 |
| `POST /user/store` 重复账号 | 422「该用户名已被占用」 |
| `POST /user/{id}` 密码留空 | 密码不变（仍可用原密码登录） |
| `POST /user/{id}/delete` | 用户与 `admin_role_user` 关联一起删除 |
| `POST /user/{id}/delete` 删自己 | 拒绝（不能删当前登录账号） |

### 校验 / 删除 / 审计

| 用例 | 结果 |
| --- | --- |
| `POST /role/store` 空名称 | HTTP **422** + `data.errors`（中文字段级消息） |
| 客户端提交空表单 | 停在新增页，三个字段下方出现行内错误，未发请求 |
| 行内删除 | 弹「确认删除 删除后不可恢复…」→ 确认 → toast「删除成功」→ 行消失 |
| 批量删除 | 勾选后按钮显示数量，`POST {url}/batch-delete`，关联表一起清 |
| 删除有子菜单的菜单 | 拒绝：「该菜单下还有 N 个子菜单，请先删除子菜单」 |
| 审计日志 | 成功操作 1 条带摘要记录；校验失败 1 条通用记录；不重复 |
| 审计日志里的密码 | 打码为 `***`，库里查不到明文 |
| `/log` 只读 | 无「新增 / 编辑 / 删除 / 批量删除」，无勾选列 |

### 权限中间件（不再手写 guard）

| 用例 | 结果 |
| --- | --- |
| viewer 访问 `/user` `/role` `/permission` `/menu` `/log`（及 create/edit 子路径） | 全部 403 + 指明需要哪个权限 |
| viewer 浏览器直达 `/role` | 403 + `text/html` + 面板内 403 页（不是裸 JSON） |
| viewer 访问 `/grid` `/showcase` `/home` | 200（未命中映射，不拦） |
| admin 访问全部 | 200 |
| 未登录 | XHR 401 JSON / 浏览器 302 |
| 映射表命中规则 | 最长前缀 + 路径分隔符边界（`/user` 不命中 `/users`） |

### 登录限流 + 会话吊销

| 用例 | 结果 |
| --- | --- |
| 连续输错密码 | 逐次提示「还可尝试 N 次」→ 第 5 次后锁定 |
| 锁定期间用正确密码 | 仍被拒：「登录失败次数过多，请 300 秒后再试」（`code: 429`） |
| 另一个账号 | 不受影响，正常登录 |
| 改密码后旧 Cookie | 立即 401（不用等 ttl） |
| `admin:revoke viewer` | `session_version` +1，viewer 的旧 Cookie 立即失效 |
| `admin:revoke --list` | 列出各账号的 `session_version` |

### 搜索 / 筛选 / 排序

| 用例 | 结果 |
| --- | --- |
| `GET /log` | payload 带 `searchable`（3 字段）、`filters`、`sortable`（2 列） |
| `?q=login` | 命中 40 条，摘要/路径/操作人都参与 LIKE |
| `?q=zzzz` | 0 条 + 前端显示空态「暂无数据」 |
| `?order_by=id&order=asc` / `desc` | `1,2,3…` / `57,56…`（`reorder()` 覆盖了预设排序） |
| `?order_by=password`（非白名单） | 忽略，仍 200，不报错也不拼接 SQL |
| 前端搜索框 | 只有一排：占位文案 = 实际搜索字段（「搜索：账号 / 姓名」）+ 列筛选控件 |
| 表头 | 可排序列出现排序箭头，点击触发重新取数 |

### 错误页 / 空态 / 面包屑

| 用例 | 结果 |
| --- | --- |
| `/user/99999/edit`（XHR） | 404 JSON |
| `/user/99999/edit`（浏览器） | 404 + HTML + 面板内 404 页（含「回到首页」） |
| `/menu/99999/edit` | 同上（`FormComponentException` → 404 而不是 500） |
| 空结果 | `n-data-table` 空态 + 「新增一条」按钮（只读页不显示按钮） |
| `/user` 面包屑 | `系统管理 /` + 标题「用户管理」 |
| `/user/create` 面包屑 | `系统管理 / 用户管理` + 标题「新增用户」 |

### 上传 / 树形选择

| 用例 | 结果 |
| --- | --- |
| `POST /upload` 合法 PNG | 200，落盘 `public/uploads/Y/m/<随机名>.png`，返回 `{url,name,size}` |
| `GET <url>` 带登录态 | 200 + `image/png` + `nosniff` + `immutable` |
| `GET <url>` 无登录态 | 302 到登录页（上传文件同样受鉴权保护） |
| `GET /uploads/../../composer.json` | 404（路径穿越被挡） |
| 上传 `shell.php` | 415「不支持的文件类型：php」 |
| 上传 `x.svg` | 415（svg 能内嵌脚本，默认排除） |
| `/menu/create` 的上级字段 | `treeSelect`，树形 options 根节点「顶级菜单」key=0 |
| `/menu/3/edit` 的上级字段 | 候选里**不含自己**（避免把父级设成自己的子树） |

### 迁移

| 用例 | 结果 |
| --- | --- |
| 在已有库上 `admin:install --migrate` | 表已存在则跳过，种子 `exists()` 兜底，幂等 |
| 在**全新空库**上 `migrate` | 8 张表 + 12 条菜单 + 6 权限 + 2 账号 + 4 条演示数据，与 `admin.sql` 一致 |
| `admin:install`（不带 --migrate） | 提示改用 SQL 或 `--migrate`，行为不变 |

### SSR 渲染

用真实 Vue + Naive UI 在 Node 里渲染 19 个页面（fixtures 取自上面这些真实响应），
全部通过且**零告警**：`GridPage` / `FormPage`（新增、编辑回填、showcase 全字段）/
`ContentPage` / `SideMenu` / `LoginPage` / `Shell`，外加菜单、角色、权限、用户、日志的各页。
断言还覆盖了：渲染结果不含函数源码、编辑页不含密码哈希、只读页不含任何操作按钮、
上级菜单渲染成 `n-tree-select`、头像渲染成 `n-upload`。

> 这些断言真的抓到过东西：本轮 `treeSelect` / `upload` 两个新字段一开始忘了在组件里
> 注册 `NTreeSelect` / `NUpload`，浏览器里是空白，SSR 直接报
> 「Failed to resolve component: n-tree-select」。

### 真实浏览器验证（无头 Chrome + puppeteer）

SSR 通过**不等于**浏览器正常 —— 实测有一条差异：`icon` 写成「函数套函数」时，
服务端仍能渲染出 `<svg>`，客户端却会把函数源码当文本打印出来。因此又用
无头 Chrome 走了一遍真实用户路径（登录 → 侧边栏 → 列表 → 编辑 → 提交）：

| 用例 | 结果 |
| --- | --- |
| `/login.html` 渲染 | Naive UI 表单正常，无控制台报错 |
| 登录后 `/` | 侧边栏 4 个顶层项、系统管理展开后 5 个子项，图标正常 |
| 点「菜单管理」 | URL 变 `/menu`，标题与表格（ID/上级/名称/图标/路由/排序/操作）正确 |
| 点「新增」 | `/menu/create`，6 个字段全部渲染（下拉/开关/数字框） |
| 点行内「编辑」 | `/menu/1/edit`，`["表格","grid","1"]` + 两个下拉 + 开关全部回填 |
| 改名后点「提交」 | 回到 `/menu`，列表显示新名称，无报错 |
| `/user` 列表 | 出现勾选列 + 批量删除按钮 + 行内「编辑/删除」 |
| 空表单点「提交」 | 停在新增页，账号/姓名/密码三个字段下方出现行内错误，未发请求 |
| 点行内「删除」→ 确认 | 弹确认框「删除后不可恢复…」→ toast「删除成功」→ 行消失 |
| `/log` | 无操作列/新增按钮；摘要、操作人、IP、时间正常，登录与增删改都有记录 |

### 仍然未知

- 没有覆盖交互细节：下拉展开、日期弹层、分页点击、文件上传等仍需人工点一遍。
- 只在 Chrome 里验证过；Safari / Firefox 的 import map 行为未测（要求
  Chrome/Edge 89+、Safari 16.4+、Firefox 108+）。
- 无头浏览器脚本没有收进测试套件（依赖运行中的服务和 puppeteer），
  需要时可按上面的用例手写。
- 安全上还差的几项（刻意没做，因为要引入新依赖或改宿主配置）：
  登录失败次数限制 / 锁定、CSRF token（现在只靠 `SameSite=Lax`）、
  改密码后让旧 Cookie 失效（需要 token 版本号）、数据库迁移（现在靠 `admin.sql` 手导）。

### 这套测试抓到的真 bug

| 问题 | 症状 | 修法 |
| --- | --- | --- |
| `Shell` 里写 `<ea-side-menu>`，但注册键是 `SideMenu` | 浏览器中侧边栏整块渲染不出来（`Failed to resolve component`） | 注册键改为 `EaSideMenu` |
| `n-transfer` 用了 `:filterable` | Naive UI 2.45 已废弃，运行告警 | 改用 `source-filterable` / `target-filterable` |
| `renderIcon()` 返回渲染函数，`toMenuOptions()` 又包了一层 | **菜单项显示成一堆 `() => h(NIcon, ...)` 源码**；SSR 却看着正常 | `renderIcon` 直接返回 VNode；SSR 测试加「渲染结果不得含函数源码」守卫 |
| 跳转地址用 `$request->url()`（Hyperf 返回**完整 URL**） | 保存成功后前端跳到 `/http://127.0.0.1:9501/menu` → 404 | 新增 `Utils::currentPath()` 只用 path；`navKey()` 兼容绝对 URL（有单测） |
| `Grid` 构造函数已预置 `id` 列，示例里又 `column('id')` 一次 | 表格出现两列 ID | 示例去掉重复列，构造函数补注释 |
| 静态资源 `max-age=3600` + 版本号只加在入口 `app.js?v=` | 修复后用户浏览器仍用缓存里的旧 `lib/icons.js`（相对 import 不带 query），菜单依旧坏 | 版本号移入路径 `/static/v<hash>/…`，整张模块图换 URL；带版本的 `immutable`，老 URL 走 `no-cache` + ETag/304 |

还有一个只有实跑才会暴露的**设计缺陷**：`ConfigProvider` 提供默认配置的同时
`admin:install` 又发布了一份同名配置，而 `Hyperf\Config\ConfigFactory` 用的是
`array_merge_recursive()` —— 同名**字符串键的标量会被并成数组**，导致
`cdn.imports` 变成 `{'vue': [url, url]}`，import map 直接失效。现在两者互斥：
宿主已发布配置文件时 Provider 不再注入默认值（见 `ConfigProvider` 注释与
`WiringTest` 中的互斥断言）。

### 仍然未知

- 没有覆盖交互细节：下拉展开、日期弹层、分页点击、文件上传等仍需人工点一遍。
- 只在 Chrome 里验证过；Safari / Firefox 的 import map 行为未测（要求
  Chrome/Edge 89+、Safari 16.4+、Firefox 108+）。
- 无头浏览器脚本没有收进测试套件（依赖运行中的服务和 puppeteer），
  需要时可按上面的用例手写。
