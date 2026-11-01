# 从 dcat-admin 能学什么（对照笔记）

上游：<https://github.com/jqhph/dcat-admin>（分支 `2.0`，Laravel + Bootstrap 4/AdminLTE）。
本地克隆在 `D:\docker\_ref\dcat-admin`（`--depth 1`，约 50 MB / 2600 文件）。

写这份笔记的前提：**dcat 是 Laravel + 编译前端，我们是 Hyperf + CDN 无构建**。
所以下面每条都标了「能不能在零构建约束下落地」，避免照抄搬不动。

---

## 1. 它的盘子有多大

| 维度 | dcat | 我们 |
| --- | --- | --- |
| 页面类型 | `Grid` / `Form` / `Show`（详情）/ `Tree` | `Grid` / `Form` / `Content` |
| 表单字段 | **67 个**（含 Markdown、Editor、Map、Icon、Color、Rate、KeyValue、CascadeGroup、Embeds、HasMany、Table、ArrayField） | 15 |
| 列表列展示器 | **27 种**（Image/Editable/Switch/Label/Badge/ProgressBar/Expand/Orderable/QRCode/Copyable/Modal…） | 纯文本 |
| 行/批量操作 | `Actions\RowAction` + `BatchAction` + 统一分发控制器 | 硬编码「编辑 / 删除」 |
| 筛选 | 独立 `Filter` 面板 + **列级 11 种操作符**（Between/In/Like/Gt/Lt/StartWith…） | 单关键字 + 等值下拉 |
| 导出 | `Exporter`（全部/当前页/选中 + chunk + 多驱动） | 无 |
| 数据源 | `Repository` 双驱动（Eloquent / QueryBuilder） | 只有 Model |
| 菜单 | 全 DB 驱动 + 角色/权限绑定 + `MenuCache` 命令 | 配置文件驱动（`admin_menu` 表只是数据） |
| 代码生成 | `Scaffold`（9 个 Creator + stubs）+ `gen:form/grid/show` | 无（手写 Controller） |
| 插件 | `Extension` 全生命周期（install/enable/disable/update/uninstall + 版本回滚） | 无 |
| 命令 | 23 个 | 3 个 |

先说结论：**它的「量」不值得追**，但它的**几个抽象**做得比我们干净，值得抄。

---

## 2. 最值得学的 6 个点

### ① 动作的响应协议（`Actions\Action` + `Http\JsonResponse`）

它把「服务端想干什么」编码成一份可链式的 JSON，前端按字段执行：

```php
abstract class Action
{
    public function handle() {
        // 想刷新列表 + 弹成功提示
        return $this->response()->success('保存成功')->refresh();

        // 想跳转
        return $this->response()->redirect('/admin/user');

        // 想下载
        return $this->response()->download($url);

        // 想刷新某个元素 / 关弹窗 / 执行脚本 / 让前端轮询
        return $this->response()->location('#user-3')->script('...')->timeout(5);
    }
}
```

`JsonResponse` 上有 ~20 个链式方法：`status / message / success / info / warning / error /
alert / detail / redirect / location / download / refresh / script / html / options /
withValidation / withException / timeout`。

**我们现在的样子**：所有保存接口都返回 `Result::result(['jumpUrl' => ...])`，
前端只有一种反应（跳到 jumpUrl 或弹提示）。想要「保存后只刷新当前行」「弹框里保存不关窗」
「保存后停在原地再弹提示」就得各写一套约定。

**值不值得**：值得，而且**成本很低** —— 前端只需在 `lib/http.js` 里加一个 `applyResponse(payload)`
分发器，后端把 `Result` 扩出一个 `ActionResponse` 就够。这会让「新增一个行操作」从
「写路由 + 写前端」变成「写一个方法」。

### ② `Show` 详情页

它有独立的 `Show` builder：`field / row / panel / relation / divider / newline / tools`，
支持分栏、面板、关联表只读展开。

**我们现在的样子**：`Form::readonly()` 只是把表单变只读，没有「详情」这个概念 ——
关系数据（角色的权限、用户的角色）在详情页没法展示，只能看列表。

**值不值得**：值得。我们的 `Grid` 列是纯文本，装不下「这个用户有哪些角色」这种复合信息。

### ③ 列展示器（Displayers）

```php
$grid->column('avatar')->image('40x40');
$grid->column('status')->switch();          // 直接点开关改数据
$grid->column('title')->editable();         // 双击行内编辑
$grid->column('progress')->progressBar();
$grid->column('url')->link();
$grid->column('hobby')->label()->expand();  // 展开多值
```

27 种展示器 = 一个「列 → 渲染方式」的可插拔层。

**我们现在的样子**：`Grid::column($field, $label)` 只有标签，显示就是纯文本 `{{ row[field] }}`。
`icon` 列显示的是 `menu`/`settings` 这种字符串，`permission` 是 `[2]` —— 很难看。

**值不值得**：**最值得**。这一条直接决定后台"好不好看"。而且在我们这套里很好落地：
`column()` 加一个 `displayer` 字段（`image / switch / label / link / tag / date / bool`），
前端 `GridPage` 的 `render` 里按 `displayer` 分派 —— 全部是纯 JS，不需要构建。

### ④ 列级筛选（`Column\Filter\*`）

```php
$grid->column('created_at')->filter(
    Grid\Column\Filter\Between::make()->datetime()
);
$grid->column('name')->filter(Grid\Column\Filter\Like::make());
```

11 种操作符：`Between / Checkbox / Equal / Gt / In / Input / Like / Lt / Ngt / Nlt / StartWith`。
每个筛选器自己负责：渲染控件 → 解析请求 → 改 query。

**我们现在的样子**：`searchable()` 是全局 OR LIKE，`filter()` 是等值下拉。
想「按创建时间范围筛」得自己写。

**值不值得**：值得，但可以按需加。优先 `Between(时间) / In / Like` 三种就够覆盖 80%。

### ⑤ 表单布局：`row` / `tab` / `fieldset` / 步骤

```php
$form->row(function (Form\Row $row) {
    $row->width(6)->text('name');
    $row->width(6)->text('email');
});
$form->tab('基本信息', function ($form) { ... });
$form->fieldset('高级选项', function ($form) { ... });
```

**我们现在的样子**：严格单列（`n-form-item` 竖排），`label-width=110`。
字段一多就是一条长卷轴。

**值不值得**：值得，且**纯前端就能做**（`n-grid` / `n-tabs` 包一层）。
后端只在 payload 里加 `layout` 结构即可。

### ⑥ `Repository` 抽象

`EloquentRepository` 与 `QueryBuilderRepository` 同一接口，`Grid`/`Form` 只依赖接口。
所以 dcat 的 Grid 既能挂模型也能挂一条手写 SQL。

**我们现在的样子**：`new Grid(new Test())` 写死 `Model`，`$this->model->newQuery()`。
想做「一张报表视图」「多表 join 的列表」就得绕。

**值不值得**：值得，但优先级低于 ①③⑤ —— 我们的使用者主要是自家模块。

---

## 3. 其他可借鉴的（按价值排）

| 点 | dcat 的做法 | 我们的现状 | 备注 |
| --- | --- | --- | --- |
| 导出 | `Exporter`：全部/当前页/选中 + `chunkSize()` | 无 | 纯后端，可直接抄思路 |
| 行操作可扩展 | `RowAction` 内联定义 + 单个 `HandleActionController` 按 selector 分发，**不用为每个动作注册路由** | 每个动作一条路由 | 这个「selector 分发」很聪明，值得抄 |
| 快捷搜索语法 | `?q=<绑定串>` 支持 `like:name / in:status / between:created_at` | 单个 `q` 只能全字段 LIKE | 我们的 `q` 可以升级成这种迷你语法 |
| 代码生成 | `Scaffold` 9 个 Creator + stubs；`gen:form/grid/show` | 无 | 抄「生成 Controller 骨架」那部分 |
| 插件机制 | `Extension`：composer 装 + enable/disable/update/回滚 + 迁移 | 无 | 对我们过重，先不做 |
| 菜单 DB 驱动 | 全 DB + 角色/权限绑定 + `MenuCache` 命令缓存 | 配置文件驱动 | README 里已经把这个列为已知取舍 |
| `Tree` 页面 | 可拖动排序（`nestable`）、`maxDepth`、`saveOrder` | 无（菜单列表是平铺） | 菜单管理最需要它 |
| 权限 | `Permission::check/allow/free` + 超管短路 | `Auth::can()` + `PermissionMiddleware` | 我们已有，思路一致 |
| `QuickEdit` / `Orderable` | 行内编辑、拖动排序 | 无 | 依赖 ①③ 先落地 |

---

## 4. 不该照抄的

1. **Bootstrap / AdminLTE 那套前端** —— 我们已选 Naive UI，回不去了（也不该回去）。
2. **编译型资源管线** —— dcat 要 `npm + webpack`；我们的硬约束是 CDN + 零构建。
3. **`Repository` 双驱动 + 插件 + 多主题** —— 这是框架级野心，我们是「组件」，摊子铺大了维护不动。
4. **67 个字段类型** —— 绝大多数用不上。我们 15 个字段覆盖了 95% 场景，
   真缺的时候按 ④⑤ 那两个坑加，而不是对着 dcat 的清单补。
5. **`Embeds` / `HasMany` 这类重型关联字段** —— 交互复杂度高（嵌套表单、增删行、局部保存），
   收益不如先把「详情页 + 列展示器」做好。

---

## 5. 如果要动手，我建议的顺序

| 顺序 | 做什么 | 为什么先做 | 工作量 |
| --- | --- | --- | --- |
| 1 | **列展示器**（image / switch / tag / link / date / bool / editable） | 一条 `column()` 的可选参数 + 前端 render 分派，投入产出比最高 | 小 |
| 2 | **动作响应协议**（`refresh / location / download / script / toast`） | 后端一次改造，之后所有模块都能「保存后只刷新、弹框不关」 | 中 |
| 3 | **`Show` 详情页** | 补齐 Grid/Form 之外的最后一种页面，也是展示关联数据的地方 | 中 |
| 4 | **表单布局**（row / tab） | 纯前端，字段多的表单立刻好看 | 中 |
| 5 | **列级筛选 + 导出** | 列表页的「能用」到「好用」 | 中 |

前两项做完，这个组件的「手感」会明显不一样；后三项属于业务量上来之后的必需。

---

## 5.1 落地情况（6 条 + 两批「低垂果实」已实现）

| 能力 | 我们的 API | 与 dcat 的差异 |
| --- | --- | --- |
| 列展示器 | `$grid->column('avatar','头像')->image('36x36')`<br>`->switch() / ->tag($map) / ->bool() / ->link() / ->date() / ->progressBar() / ->copyable() / ->expand() / ->editable()` | 27 → 10 种，够用；`editable` 是输入框直改，没有 dcat 的弹层 |
| 列级筛选 | `$grid->column('created_at','创建时间')->between(true)`<br>`->like() / ->in($opts) / ->equal() / ->gt() / ->lt() / ->startWith()` | **控件放在工具栏**而不是表头弹层（原因见下） |
| 行操作 | `$grid->rowAction('reset-password','重置密码', ['confirm' => '...'])`<br>`->viewAction('查看')` | 一个模块一条 `POST {url}/{id}/action` 路由 + `dispatchRowAction()` 分发，思路同 dcat 的 selector 分发 |
| 动作响应协议 | `ActionResponse::success('保存成功')->refresh()->toArray()`<br>`->redirect() / ->location() / ->download() / ->alert() / ->with()` | 去掉 dcat 的 `script()` 与 `html()`：前者等于让服务端注入任意 JS，后者依赖它那套渲染器体系 |
| 详情页 | `new Show($model)` + `panel()` / `field()->image()` / `with(['roles' => $roles])` | 只做 `panel`（分节）+ 单/双列，没做 dcat 的 `row`/`relation` 全套 |
| 表单布局 | `$form->tab('基本信息', fn ($f) => ...)`<br>`$form->row(['账号','姓名'], 2)` | 用「按字段名声明分组」而不是闭包里建字段：声明顺序 = 校验顺序，实现也简单得多 |

实现时被测试抓到的 4 个真 bug（「模板字符串 + CDN」这条路上必然踩、dcat 那套遇不到）：

1. **`n-tabs` 默认只挂载当前页签** → 切页签前，另一页签的必填字段不会被
   `formRef.validate()` 看到（提交能漏校验）。修法：pane 上 `display-directive="show"`。
2. **表头弹层不稳**：`n-data-table` 重渲染会把 `n-popover` 关掉，列筛选点不开。
   改成工具栏里的控件 —— 更稳，也更好点。
3. **查询串只展开一层嵌套**：`filters[name]` 里的对象被 `String()` 成 `[object Object]`，
   表现是「填了筛选条件但结果没变」。改成递归展开，并补了单测。
4. **`Form` 忘了 `use Closure;`** → `Closure` 被解析成 `Awengan\HyperfAdmin\Closure`，
   `tab()` 直接抛类型错误。

---

## 5.2 两批补充的落地（对照第 3 节的价值排序）

第三批做完的是「结构」而不是「数量」——字段数只从 18 增到 24，但补上了三个结构性能力。

| dcat 的 | 我们的 | 状态 |
| --- | --- | --- |
| `Tree` / `DialogTree` | `Grid::tree($parentField)`，用 `children` 递归 | ✅ 已实现（菜单管理直接受益） |
| `FixColumns` | `Column::fixed('left' \| 'right')` | ✅ 已实现 |
| `ComplexHeader` | `Grid::columnGroup($label, Closure)` | ✅ 已实现 |
| `HasDepends` / `CanCascadeFields` | `AbstractField::depends($field, $rules)` | ✅ 已实现（规则下发，前端过滤） |
| `LazyRenderable`（弹层表单） | `rowAction(..., ['form' => Closure])` + `action-form` 路由 | ✅ 已实现 |
| `Orderable`（拖拽排序） | `Grid::orderable($field)` + `/move` 路由（↑↓ 而非拖拽） | ✅ 已实现（不依赖 dnd 库） |
| `QuickEdit`（行内编辑） | 部分：`Column::switch()` 可改单值 | ⚠️ 未做（需单元格编辑态） |
| `Exporter`（xlsx） | CSV + BOM | ⚠️ 只做 CSV |
| `HasMany`（一对多子表单） | — | ❌ 未做 |
| `Fieldset`（分组标题） | `tab()` / `row()` 可用 | ❌ 未做 |

几个实现上的坑（都踩过）：

1. **`columnGroup()` 之后 `columnFields()` 必须包含分组列**，否则 SELECT 出来的行只有 id，列表全是空行。
2. **`Grid::column()` 的构造是 `new Column($this, $field, $label)`**（三个参数）——按两个参数写补丁会静默失配。
3. **`Form` 没有 `toArray()`**：会命中 `__call` 变成 `addField('toArray')`，报一个和真实原因完全无关的错。
4. **弹层表单的字段在 `form.data.fields`**，不是 `form.fields`。
5. **字符串模板必须注册组件**：`FieldList` 只 import 不注册 → SSR 报 `Failed to resolve component`，而浏览器里只是"静默不渲染"。

## 附：API 对照速查

| 需求 | dcat | 我们（现在） |
| --- | --- | --- |
| 列表 | `new Grid($repo)` | `new Grid(new Model())` |
| 列 | `$grid->column('name')` | `$grid->column('name', '名称')` |
| 列显示图片 | `->image('40x40')` | ✗ |
| 列开关改值 | `->switch()` | ✗ |
| 行内编辑 | `->editable()` | ✗ |
| 快捷搜索 | `->quickSearch('like:name')` | `->searchable(['name' => '姓名'])` |
| 筛选 | `$grid->filter(fn($f) => $f->like('name'))` | `->filter('status', '状态', [...])` |
| 行操作 | `$grid->actions(fn($a) => $a->append(new Delete()))` | 硬编码 |
| 导出 | `->export()` | ✗ |
| 表单字段 | `$form->text('name')` | `$form->text('name', '名称')` |
| 表单分栏 | `$form->row(fn($r) => ...)` | ✗ |
| 表单 Tab | `$form->tab('基本', fn($f) => ...)` | ✗ |
| 详情页 | `new Show($repo)` | ✗ |
| 树形页 | `new Tree($repo)` | ✗ |
| 动作返回 | `$this->response()->success()->refresh()` | `Result::success(..., 'msg')` |
| 代码生成 | `php artisan admin:form User` | ✗ |
