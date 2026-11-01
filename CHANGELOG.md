# Changelog

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
