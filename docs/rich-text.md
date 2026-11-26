# 富文本表单字段：方案评估与实现

> 状态：**已实现并发布（0.1.0 / 0.1.1）**。选型结论与实测数据保留在下面，
> 第 12 节的三个决策点已按「Quill 2 + 内置白名单 + 支持图片上传与外链图片」落地。

## 0. 落地情况（先看这个）

| 项 | 实现 |
| --- | --- |
| 编辑器 | Quill 2.0.3，`import('quill')` **懒加载**（没有富文本字段的页面不下载） |
| 字段 | `$form->richText('content', '正文')->toolbar([...])->height(320)->maxLength(50000)->upload()` |
| 清洗 | `Helper\HtmlSanitizer`：标签/属性/协议白名单 + Quill 方言归一化（`<li data-list>` → `<ul><li>`，代码块 → `<pre><code>`） |
| 展示 | `Grid\Column::html($limit)`、`Show\Field::html($limit)`（服务端清洗后才下发） |
| 图片 | 工具栏上传走 `/admin/upload`（同源 + CSRF）；允许外链图片，CSP 自动放宽 `img-src` |
| 资源模式 | CDN：1 个请求（约 196 KB）；自托管：`--self-host` 多下 2 个文件 / 200 KB |
| 兜底 | 编辑器加载失败降级成 textarea 并提示原因 |

测试：清洗器 24 条（XSS 用例）、payload 45、wiring 20、前端 60、SSR 19、e2e 47、真实浏览器 25。


## 1. 结论

| 问题 | 结论 |
| --- | --- |
| 要不要加富文本字段 | 要加。做成「字段 + **懒加载** + 双资源模式」，不能把编辑器塞进主包 |
| Sky Tiptap 适合吗 | **不适合**（5 条硬理由，见 §5） |
| 那用谁 | **v1 用 Quill 2**：单包、CDN 4 个请求、自托管 2 个文件，**现有下载器零改造**就能跑通两种模式 |
| Tiptap 呢 | 技术上可行（两种模式我都跑通了），但**必须先修自托管下载器**，且 CDN 模式在这台网络下要 44 秒、82 个请求；建议作为「进阶引擎」后续再做 |
| 有没有坑 | 有，两个：① `VendorAssets` 抓多包模块图会漏文件（实测 4 个模块失败 → 编辑器白屏）；② Quill 存的是「Quill 方言 HTML」，清洗器要认 `data-list` |

一句话：**字段 API 与两套资源模式的设计按 Tiptap 的复杂度来做（更严），实现先用 Quill 落地**。

## 2. 需求（v1 范围）

- `$form->richText('content', '正文')`，值是一段 HTML 字符串，直接落库
- 工具栏：加粗 / 斜体 / 下划线 / 删除线 / 标题 / 无序·有序列表 / 引用 / 代码 /
  链接 / 图片 / 撤销重做 / 清除格式
- 图片：从本地上传（复用 `POST /admin/upload` + CSRF）后插入**同源** URL
- `height()` / `placeholder()` / `disabled()`（只读）/ 可裁剪工具栏
- **服务端强制清洗**（安全边界，不是可选项）
- 列表页 / 详情页能把 HTML 渲染出来（`->html()` 展示器，同样过清洗）
- CDN 与自托管两种模式都可用；自托管**离线可用**

不做：协同编辑、评论、@、表格（v1）、Markdown 双向、Word 粘贴保真。

## 3. 候选对比（实测）

体积 = 浏览器实际下载的字节（esm.sh 产物，minified）。

| 候选 | 许可 | 周下载 | 模块形态 | CDN 挂载（本机） | 自托管 | 维护 |
| --- | --- | --- | --- | --- | --- | --- |
| **Quill 2.0.3** | BSD-3 | 8.6M | 单包，bundle **1 个 196 KB** | **2.1 秒 / 4 请求** | **2 文件 / 200 KB / 12 秒**，现有下载器即可 | 稳定（2024-11） |
| **Tiptap 3.34**（`vue-3`+`starter-kit`+`extension-image`） | MIT | 2.8M / 33M | 多包图，**77 个模块 / 476 KB** | **44.6 秒 / 82 请求** | 77 文件 / 476 KB / 38 秒，**需修下载器** | 活跃（2026-11） |
| Sky Tiptap 1.7.0 | MIT | **4** | 单文件 **701 KB** + 独立 CSS | 未测（不建议） | 1 文件，但 CSS 无处安放 | 0 star，Tiptap **2.27** |
| WangEditor 5.1.23 | MIT | 64k | UMD 为主，7 MB | — | — | **2022 年后停更** |
| TinyMCE 8 | GPL/商业 | 1.45M | 非 ESM | — | — | 许可不适合 |
| Lexical 0.53 | MIT | 9.5M | ESM，3.6 MB | — | 无官方 Vue 3 绑定 | 活跃 |

两种形态的差别（同一套代码，两种加载策略）：

| | 图模式（逐模块） | bundle（单文件） |
| --- | --- | --- |
| Tiptap | 77 文件 / 476 KB，**只有一份 ProseMirror** | 每个入口各内联一份 ProseMirror（795 KB）→ **两份实例，会坏** |
| Quill | 16 文件 / 282 KB | **1 个 196 KB**，只有一个包，安全 |

> Tiptap 官方文档也承认 CDN + 重复依赖这条路径会出问题（回车、列表崩溃）。
> 所以 Tiptap 的自托管只能走图模式，Quill 可以放心 bundle。

## 4. 实测：两种资源模式

同一套用例（输入、回车、加粗、列表、撤销/重做、链接、图片、`setContent` 往返），
headless Chrome 记录挂载耗时 / 请求数 / 控制台错误。

| 引擎 | 模式 | 挂载 | 请求 | 外网 | 控制台错误 | 交互 |
| --- | --- | --- | --- | --- | --- | --- |
| Tiptap | CDN（esm.sh，`?external=vue`） | 44.6 s | 82 | 81 | 0 | 全过 |
| Tiptap | 自托管（本机） | **0.21 s** | 77 | 0 | 0 | 全过 |
| Quill | CDN（`?bundle`） | **2.1 s** | 4 | 2 | 0 | 全过（链接项是探针选区问题） |
| Quill | 自托管（本机） | **0.15 s** | 3 | 0 | 0 | 全过 |

### 4.1 现有自托管下载器抓 Tiptap：装不起来

```
下载 72 个文件，438.5 KB，耗时 275.8 秒
错误：抓取失败：.../@tiptap/core@3.34.0/es2022/jsx-runtime.mjs/dist/jsx-runtime/jsx-runtime.mjs
错误：抓取失败：.../linkifyjs@4.4.0/es2022/linkifyjs.mjs
错误：抓取失败：.../prosemirror-commands@1.7.2/es2022/prosemirror-commands.mjs
错误：抓取失败：.../prosemirror-transform@^1.7.3?target=es2022
```

用这批文件加载页面 → 浏览器 `404 core-*.mjs`，**编辑器完全没挂载**。

三个根因（与 Tiptap 无关，任何多包依赖都会踩）：

1. **跟随重定向后没换基准**：拿到的是最终模块，相对 import 仍按原始 stub URL 解析 →
   拼出 `.../jsx-runtime.mjs/dist/jsx-runtime/jsx-runtime.mjs` 这种不存在的地址
2. **相对路径按「整个文件 URL」拼**：没先去掉文件名 → 目录错一层
3. **esm.sh 输出的「范围版本」裸标识符**（`prosemirror-transform@^1.7.3?target=es2022`）
   被当成真裸标识符（像 `vue`）留给 import map → 永远不下载
4. （性能）**串行下载**：72 个模块一个个来 → 275 秒

### 4.2 修完后

打补丁（并行 8 并发 + `CURLINFO_EFFECTIVE_URL` 做基准 + 相对路径按目录解析 +
识别范围版本 + **按 path 去重**）：

```
下载 70 个文件，474.9 KB，耗时 27.5 秒，失败 0
```

浏览器：挂载 214 ms、77 同源请求、0 报错、交互全过。

### 4.3 但 Tiptap 还有一层「隐性地雷」：重复版本

即使修好下载器，esm.sh 生成的图里仍会出现**同名包的多份实例**（我实测到）：

```
prosemirror-transform @ 1.12.6   和  @ 1.12.7     ← 两个版本
prosemirror-view      @ 1.42.9   和  @ 1.43.0     ← 两个版本
```

加上 `?deps=prosemirror-transform@1.12.7,prosemirror-view@1.43.0` 能把版本收敛，
但同一版本仍可能以不同的 `X-…`/`deps=` 形态各存一份（我们的探针在 70 个文件时
简单用例仍然通过，但这是**隐患**：ProseMirror 的 `instanceof` 检查与
「同名 plugin key 加两次」的报错不会每次都触发）。

另一个实测到的细节：给扩展加 `external=@tiptap/core` 后，**import map 必须同时映射
`@tiptap/core`**，否则浏览器报
`Failed to resolve module specifier "@tiptap/core"`；`starter-kit` 还会要求
`@tiptap/pm/{state,view,model,transform,commands,keymap,history,dropcursor,gapcursor}`。
也就是「把 peer 全部登记」这条路要写 10~13 条 specifier（django-tiptap-editor 的
external 模式就是这么干的），配置会很啰嗦。

结论：Tiptap 的自托管不是不能做，而是**要连带维护一张 specifier 清单或一套去重规则**，
这对「发出去给别人用、以后还要升级」的组件来说是持续成本。

### 4.4 Quill 的代价：HTML 是「Quill 方言」

实测产物（列表 + 光标标记）：

```html
<p><span class="ql-cursor">﻿</span>第一行&nbsp;</p>
<ol><li data-list="bullet"><span class="ql-ui" contenteditable="false"></span>
  <strong>第二行</strong></li></ol>
```

- 列表不是标准 `<ul><li>`，而是 `<ol><li data-list="bullet">` + `ql-ui` 占位 span
- 所以：清洗器要**保留 `data-list`**（否则列表语义丢失），展示时最好带上 Quill 的 CSS
- 相比 Tiptap 输出标准 `<ul><li>`，Quill 的 HTML 更「脏」一点

这三条就是「Quill 省钱」的代价，权衡时要知道。

## 5. Sky Tiptap 为什么不适合

1. **没人兜底**：npm 周下载 **4**，GitHub **0 star / 0 fork / 0 issue**，自述是「模板」。
   一个要发给政务/金融客户的组件，不能把富文本压在这上面。
2. **落后一个大版本**：依赖 `@tiptap/*@^2.27.2`（当前 3.34）。starter-kit 在 3.x 才包含
   `link` / `underline`（实测 2.27.2 的依赖清单里确实没有这两个扩展）。
3. **依赖声明不干净**：`vue` 被写成 **dependencies**（不是 peer）——我们只能靠
   `?external=vue` 补救，而双份 Vue 会让 Naive UI 的 `useMessage()` 直接失效
   （本项目 0.0.1 踩过）。它还把 `@vitejs/plugin-vue`、`sass` 这类构建工具列为运行时依赖。
4. **自带 CSS + 工具栏**：`dist/sky-tiptap.css` 是独立文件；我们的资源管线只处理 `.mjs`，
   CSS 在 CDN/自托管两种模式下都得另想办法；而且它的视觉与 Naive UI 不是一套，
   改起来比从零画工具栏还费劲 —— 正好把最需要我们自己控制的两层（UI、样式）定死了。
5. **功能错配**：内置 highlight.js 16 种语言、markdown-it、B站/抖音/YouTube 嵌入、AI 动作。
   内网用不上，却要背体积和 `iframe` 注入面。

它唯一省掉的是「画工具栏」——而这正是我们最该自己写的一层（Naive UI 的按钮/弹层/图标都在手上，
工具栏约 150 行）。

## 6. 推荐方案

### 6.1 字段 API（引擎无关，先按这个定）

```php
$form->richText('content', '正文')
    ->toolbar(['bold', 'italic', 'underline', 'strike', 'heading', 'bulletList',
               'orderedList', 'blockquote', 'codeBlock', 'link', 'image', 'undo', 'redo', 'clear'])
    ->height(320)
    ->placeholder('请输入正文')
    ->maxLength(50000)
    ->upload(true);
```

payload：

```json
{"type":"richText","name":"content","label":"正文","height":320,
 "toolbar":["bold","..."],"upload":true,"placeholder":"请输入正文"}
```

`Form::__call()` 已能把 `richText()` 映射到字段类，不用改 Form。

### 6.2 前端：懒加载 + 兜底

```js
// resources/static/js/fields/richText.js
export default {
  name: 'EaRichText',
  async setup(props, emit) {
    // 没有富文本字段的页面，一个字节都不下载
    const { default: engine } = await import('../lib/rich-text/quill.js')  // 或 tiptap.js
    ...
  },
}
```

两条硬要求（探针里都踩到了）：

- **工具栏 active 态要自己做成响应式**（Tiptap 的 `editor` 不是 Vue 响应式对象，
  要在 `onTransaction` 里刷新；Quill 用 `text-change`/`selection-change`）
- **加载失败要降级**：`import()` 失败（CDN 挂了 / 自托管漏文件）时退回
  `n-input type="textarea"` 并给出可操作提示，不能让整个表单白屏

### 6.3 资源模式

`config/hyperf-admin.php`（Quill，v1 推荐）：

```php
'cdn' => [
    'imports' => [
        // ... 现有 vue / naive-ui
        'quill' => 'https://esm.sh/quill@2.0.3?bundle',
    ],
],
```

self-host 结果（实测，现有下载器零改造）：**2 文件 / 200 KB / 12 秒**。

如果以后上 Tiptap，`cdn.imports` 至少要 3~4 条，并且**不能**加 `&bundle`：

```php
'@tiptap/core'          => ['url' => 'https://esm.sh/@tiptap/core@3.34.0?bundle', 'bundle' => false],
'@tiptap/vue-3'         => ['url' => 'https://esm.sh/@tiptap/vue-3@3.34.0?external=vue,@tiptap/core', 'bundle' => false],
'@tiptap/starter-kit'   => ['url' => 'https://esm.sh/@tiptap/starter-kit@3.34.0?external=@tiptap/core', 'bundle' => false],
```

为此 `InstallCommand` 的「给所有 esm.sh 地址自动追加 `&bundle`」要改成可关闭
（配置项支持 `['url' => ..., 'bundle' => false]` 这种形态）。

### 6.4 服务端清洗（安全边界）

- 内置白名单清洗器（`ext-dom`，约 150 行）：标签
  `p br strong em u s h2 h3 h4 ul ol li blockquote pre code a img hr`；
  属性 `href src alt title target rel data-list`（`data-list` 是为 Quill 列表语义保留的）；
  协议只放 `http https mailto` 与相对路径；`script/style/iframe/svg/on*` 一律剥掉；
  `a` 补 `rel="noopener noreferrer"`
- 若装了 `symfony/html-sanitizer`（MIT，PHP 8.1 用 6.x 分支）则自动改用它
- 不加 `ezyang/htmlpurifier` 为依赖：**LGPL-2.1**，对二次分发不友好
- `maxLength` 服务端也校验；`content` 列建议 `LONGTEXT`

## 7. 与现有 CSP 的关系

```
script-src 'self' 'nonce-…' 'unsafe-eval' <import map 里的外部主机>
style-src  'self' 'unsafe-inline'
img-src    'self' data: blob:
```

- **CDN 模式**：`esm.sh` 已按 import map 自动进白名单，加编辑器**不用改 CSP**
- **自托管模式**：全同源，天然更严
- **`img-src` 是唯一要决策的地方**：现在只允许同源/data/blob，所以富文本里
  **粘贴外链图片会被浏览器拦掉**。v1 建议只允许「上传后插入」（同源 URL）；
  要外链就加 `rich_text.image_hosts` 配置，按域名放宽 `img-src`

## 8. 必须顺带修的东西

1. `VendorAssets` 的 3 个 bug（§4.1）+ 并行下载 + 按 path 去重
2. `--self-host` 现在**失败只 warning、退出码 0** —— 半残的 vendor 目录 = 白屏，
   应改成**有失败就非 0 退出**并提示重跑
3. `InstallCommand` 的 `&bundle` 自动追加要能按条目关闭（为 Tiptap 预留）
4. CSS 资源：Quill 的 `quill.snow.css` 我们不引（体积 + 与 Naive UI 冲突），
   自己写约 120 行编辑器样式（`.ql-editor` / 列表 / 引用 / 代码块）放进 `app.css`

## 9. 测试计划

| 层 | 用例 |
| --- | --- |
| `PayloadTest` | 字段序列化、toolbar 白名单、`height/placeholder` 默认值 |
| 清洗器单测（新增） | `<script>`、`onerror=`、`javascript:`、`data:text/html`、`<svg onload>`、`<iframe>`、大小写混淆、`style` 属性、**正常内容不被误杀**（含 `<ul><li>` 与 Quill 的 `<li data-list>`） |
| `WiringTest` | 新配置项默认值；CSP 在两种模式下都正确 |
| SSR | 表单页渲染出编辑器容器（不执行 JS） |
| e2e | 提交 HTML → 落库是清洗后的 → 编辑回显 → 越权标签被剥 |
| 浏览器 | 键入/加粗/列表/链接/图片/撤销；**CDN 与自托管各跑一遍**（自托管要断网跑） |

## 10. 工作量

| 项 | Quill 路线 | Tiptap 路线 |
| --- | --- | --- |
| 下载器修复（并行+3 bug+去重） | 不需要 | 0.5 天 |
| 字段 + 前端组件 + 工具栏 | 1 天 | 1.5 天 |
| 清洗器 + 单测 | 0.5 天 | 0.5 天 |
| `->html()` 展示器 | 0.25 天 | 0.25 天 |
| 测试（双模式）+ 文档 + 发版 | 0.75 天 | 1 天 |
| **合计** | **约 2.5 天** | **约 3.75 天** |

## 11. 风险

1. **Quill 的 HTML 方言**：列表用 `data-list`、有 `ql-cursor` 残留 → 清洗器与展示要专门处理；
   导出/第三方渲染时可能不如 Tiptap 的标准 HTML
2. **Tiptap 的重复实例**：修完下载器仍有隐患（§4.3），要靠浏览器回归兜住
3. **CDN 模式在本网络很慢**（Tiptap 44 秒）：文档要明确「内网/离线用 `--self-host`」
4. **CSP 的 `img-src`**：外链图片会被拦，属于「设计选择」而非 bug，需在文档写清

## 12. 决策（已定）

1. **v1 用 Quill 2** ✅ —— 2 个文件 / 200 KB，两种资源模式零改造
2. **清洗器用内置白名单** ✅ —— 零依赖，24 条 XSS 用例覆盖
3. **包含图片上传 + 允许外链图片** ✅ —— 上传走 `/admin/upload`；外链图片由
   `rich_text.external_images`（默认 true）控制，开启时 CSP 的 `img-src` 追加 `https:`

实现过程中额外发现并修掉的两个自托管问题（0.1.1）：

- 全新项目第一次 `--self-host` 时宿主配置还没发布，`UI::DEFAULT_IMPORT_MAP` 是兜底来源，
  它少了 `quill` → 编辑器仍走 CDN（内网白屏）。现在兜底表与默认配置一致
- `--self-host` 下载失败只打 warning、退出码 0 → 半残 vendor 目录 = 白屏。
  现在有模块失败就**非 0 退出**，并打印失败清单与重跑命令；单个模块失败自动重试 3 次

---

## 复现方式

```bash
# 1) 体积 / 模块数：esm.sh 编译产物
curl -sL 'https://esm.sh/quill@2.0.3?bundle'                 # 单文件
curl -sL 'https://esm.sh/@tiptap/starter-kit@3.34.0?bundle'  # 对比：bundle 会内联 ProseMirror

# 2) 自托管：用包内 VendorAssets 真下载
php -r 'require "vendor/autoload.php";
  (new Awengan\HyperfAdmin\Helper\VendorAssets("/tmp/v"))->download([
     "quill" => "https://esm.sh/quill@2.0.3?bundle",
  ]);'

# 3) 浏览器：headless Chrome 量挂载/请求/错误
node probe.mjs http://127.0.0.1:9501/static/probe/quill-cdn.html
node probe.mjs http://127.0.0.1:9501/static/probe/quill-selfhost.html .ql-editor
```

探针页面（`public/static/probe/*.html`）与脚本是评估产物，**不随包发布**。
