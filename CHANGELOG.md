# Changelog

## 0.1.1 — 2026-11-20

修两个自托管相关的坑（都只在「离线/内网」路径上暴露）。

### 修复

- **全新项目第一次 `admin:install --self-host` 不会下载 quill**：此时宿主配置还没发布，
  import map 退回内置兜底表，而兜底表里没有 quill → 编辑器仍走 CDN，内网直接白屏。
  现在兜底表与默认配置一致（vue / naive-ui / quill）
- **`--self-host` 下载失败却退出 0**：半残的 `public/static/vendor` 会让整个面板白屏，
  现在只要有一个模块失败就**非 0 退出**，并打印失败清单与重跑命令
- 单个模块抓取失败会自动重试 3 次（CDN 抖动很常见，尤其是几 MB 的 bundle）

## 0.1.0 — 2026-11-20

新增**富文本字段**（Quill 2），CDN 与自托管两种资源模式都开箱可用。

### 新增

- `$form->richText('content', '正文')`：14 个工具栏按钮、`height()` / `placeholder()` /
  `maxLength()` / `upload()`，支持只读
- 服务端清洗器 `Helper\HtmlSanitizer`：标签/属性/协议白名单，剥掉 `script`、`iframe`、
  `on*`、`style`、`javascript:`；并把 Quill 方言归一化成标准 HTML
  （`<li data-list="bullet">` → `<ul><li>`，`ql-code-block` → `<pre><code>`）
- `Grid\Column::html($limit)` 与 `Show\Field::html($limit)`：展示富文本，
  全量渲染时服务端已清洗，带 `limit` 时下发纯文本摘要
- 配置项 `rich_text.external_images`（默认 `true`）：允许正文里的外链图片，
  自动把 `https:` 加进 CSP 的 `img-src`
- 编辑器**懒加载**：只有渲染富文本字段的页面才下载 Quill（CDN 1 个请求 / 自托管 2 个文件）
- 加载失败自动降级成 textarea 并提示原因，不让表单白屏

### 修复

- `VendorAssets` 增量自托管会覆盖 `import-map.json`：新加一个依赖后再跑
  `--self-host` 会把之前的条目冲掉（现在改为合并）

### 文档

- README 新增「富文本（RichText）」一节：字段 API、清洗白名单、两种资源模式的代价、
  CSP 与图片的关系
- 新增 `docs/rich-text.md`：选型评估（Quill vs Tiptap vs Sky Tiptap）与两种资源模式实测数据

## 0.0.4 — 2026-11-01

只改文档，没有代码变更。

- README 顶部收敛成「你要做的事，就两条」：`composer require` + `admin:install`，
  其余命令（改密码、强制下线、只放文件不建表）挪到各自该在的章节
- README 说明 `admin:install` 会连数据库，装之前先配好 `.env` 的 `DB_*`、建好库
- 目录结构里 `database/migrations/` 的注释改为「`admin:install` 默认执行」

## 0.0.3 — 2026-11-01

- **行为变更**：`admin:install` 现在默认就建表 + 写初始数据（不再需要 `--migrate`），
  配好 `.env` 后一条命令，直接 `start` 就能登录
- 新增 `--no-migrate`：只发布文件、不连数据库（构建机/只放资源的场景）
- `--migrate` 保留为兼容写法（默认已执行）
- 数据库连不上时不再抛堆栈：先探测连接，只输出一行原因 + 「配好 `.env` 后重跑」的提示，
  退出码仍为 0（前端资源已发布成功）
- 文档：快速开始改成「配好 `.env` → `admin:install` → `start`」三步；离线部署改成
  构建机用 `--no-migrate`、内网机器再建表；升级说明补充「迁移自动补跑」

## 0.0.2 — 2026-11-01

- 新增配置项 `assets.gzip`（此前往代码里写死了、配置里没有；默认 `true`，
  有预生成 `.gz` 时优先发压缩版），并在默认配置里补了注释
- 文档：README 新增「静态资源：要不要配？默认不用」一节 —— 版本号、缓存头、
  压缩的默认行为，以及只读文件系统 / nginx 直发 / 多机部署时的正确做法
- 文档：说明 Composer 下载的 dist 包不含 `tests/`、`docs/`、`.github/`
- 文档：安装方式只剩 `composer require`（去掉了本地 path 仓库的写法）

## 0.0.1 — 2026-11-01

首个发布版本。

- Builder API：`Form` / `Grid` / `Show` / `Content`，20 种表单字段
- 前端 Vue 3 + Naive UI，原生 ES module + import map，**无构建步骤**
- 前端依赖两种来源：CDN（默认）或自托管（`admin:install --self-host`，内网/离线可用）
- 登录鉴权：HMAC 签名 Cookie（不依赖 `hyperf/session`）、CSRF 双提交、CSP、登录限流
- RBAC：权限 / 角色 / 中间表 + 路径前缀拦截 + 侧边栏按权限裁剪
- 后台模块：用户、角色、权限、菜单（树形）、审计日志、自助改密
- 一键安装：`php bin/hyperf.php admin:install [--migrate] [--self-host] [--gzip]`
- 种子数据只含一个 `admin` 账号（首次登录后请立刻改密码）
