#!/usr/bin/env node
/**
 * SSR 冒烟测试：用**真实**的 Vue + Naive UI 把每个页面渲染成 HTML。
 *
 *   cd tests/ssr
 *   pnpm install          # 或 npm install
 *   pnpm test             # node --import ./resolve-hook.mjs run.mjs
 *
 * 这是唯一能覆盖「浏览器端 bundle」的自动化测试：
 *  - 每个组件的 `template` 字符串都会真的被编译（语法错误会直接暴露）
 *  - 组件注册是否对得上（例如 `<ea-side-menu>` 必须注册成 `EaSideMenu`）
 *  - 传给 Naive UI 的 prop 是否合法（过时/未知 prop 会产生告警）
 *  - fixtures 是真实服务端响应，所以同时校验了前后端 payload 契约
 *
 * 任何一个 Vue / Naive UI 告警都会让测试失败（白名单为空）。
 */

import { cp, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

// vue.esm-bundler 需要的特性开关
globalThis.__VUE_OPTIONS_API__ = true
globalThis.__VUE_PROD_DEVTOOLS__ = false
globalThis.__VUE_PROD_HYDRATION_MISMATCH_DETAILS__ = false

const here = fileURLToPath(new URL('.', import.meta.url))
const jsSource = join(here, '..', '..', 'resources', 'static', 'js')

/**
 * 已知可忽略的告警（目前为空 —— 有告警就说明有问题）。
 */
const IGNORED_WARNINGS = []

const warnings = []

// 保留原件：收尾报告要用它们，否则报告本身也会被吞进 warnings（静默 exit 1）
const originalWarn = console.warn.bind(console)
const originalError = console.error.bind(console)

console.warn = (...args) => warnings.push(args.join(' '))
console.error = (...args) => warnings.push(args.join(' '))

// 先加载 vue / naive-ui：此时全局没有 window，naive-ui 的依赖（vooks）才会走
// SSR 分支；若提前注入 window，vooks 会误判成浏览器并直接访问 document 而崩溃。
const { createSSRApp, h } = await import('vue')
const { renderToString } = await import('@vue/server-renderer')
const naive = await import('naive-ui')
const { NConfigProvider, NMessageProvider, NDialogProvider, dateZhCN, zhCN } = naive
const { setup: setupSsr } = await import('@css-render/vue3-ssr')

// 之后再注入：Shell 的 setup 用到 window.location / history
globalThis.window = {
  location: { pathname: '/grid', href: 'http://127.0.0.1:9501/' },
  history: { pushState() {} },
  addEventListener() {},
  removeEventListener() {},
}

// 临时目录必须放在 tests/ssr 内：前端模块用裸说明符 import 'vue' / 'naive-ui'，
// 放到系统临时目录会导致找不到 node_modules。
const workdir = await mkdtemp(join(here, '.tmp-'))

async function prepare() {
  await writeFile(join(workdir, 'package.json'), '{"type":"module"}\n')
  await cp(jsSource, join(workdir, 'js'), { recursive: true })
}

const load = async (name) => (await import(pathToFileURL(join(workdir, 'js', name)).href)).default

async function loadFixture(name) {
  const raw = await readFile(join(here, 'fixtures', `${name}.json`), 'utf8')
  const parsed = JSON.parse(raw)

  // fixture 是服务端原始响应，剥掉 {code,msg,data} 信封
  return parsed.data ?? parsed
}

const http = {
  get: async () => ({ code: 200, data: {} }),
  post: async () => ({ code: 200, msg: 'ok', data: {} }),
  page: async () => ({ code: 200, data: {} }),
  request: async () => ({ code: 200, data: {} }),
}

function render(component, props) {
  const app = createSSRApp({
    render: () =>
      h(NConfigProvider, { locale: zhCN, dateLocale: dateZhCN }, {
        default: () =>
          h(NMessageProvider, null, {
            default: () => h(NDialogProvider, null, { default: () => h(component, props) }),
          }),
      }),
  })

  // 安装 css-render 的 SSR adapter，否则 style.mount() 会访问 document.head
  setupSsr(app)

  return renderToString(app)
}

let passed = 0
const failures = []

async function check(name, fn) {
  try {
    await fn()
    passed += 1
    console.log(`  ok   ${name}`)
  } catch (error) {
    failures.push([name, error.message])
    console.log(`  FAIL ${name}: ${error.message}`)
  }
}

function expect(html, needles, name) {
  for (const needle of needles) {
    if (!html.includes(needle)) {
      throw new Error(`${name} 渲染结果缺少 "${needle}"`)
    }
  }

  // 函数源码泄漏的回归守卫：`icon` 之类的 render prop 一旦写错（函数套函数），
  // Vue 会把函数 toString() 当文本渲染出来。
  if (html.includes('() =>') || html.includes('function (') || html.includes('=> {')) {
    throw new Error(`${name} 渲染结果里出现了函数源码（render prop 契约写错了？）`)
  }
}

function expectMarkup(html, markers, name) {
  for (const marker of markers) {
    if (!html.includes(marker)) {
      throw new Error(`${name} 没有渲染出 ${marker}`)
    }
  }
}

try {
  await prepare()

  const GridPage = await load('components/GridPage.js')
  const FormPage = await load('components/FormPage.js')
const ShowPage = await load('components/ShowPage.js')
  const ContentPage = await load('components/ContentPage.js')
  const SideMenu = await load('components/SideMenu.js')
  const LoginPage = await load('components/LoginPage.js')
  const Shell = await load('components/Shell.js')

  console.log('\nSSR 渲染（真实 naive-ui）:')

  await check('ContentPage（概览首页）', async () => {
    const page = await loadFixture('home')
    const html = await render(ContentPage, { page })
    expect(html, ['概览', '概况', '技术栈', '特点', '响应跟手', 'Hyperf 3.1'], 'ContentPage')
  })

  await check('菜单管理（列表）', async () => {
    const page = await loadFixture('menu')
    const html = await render(GridPage, { page, http })
    expect(html, ['菜单管理', '名称', '图标', '路由', '排序', '系统管理', '用户管理', '审计日志', '操作'], '菜单管理(列表)')
    expectMarkup(html, ['n-data-table', 'n-pagination'], '菜单管理(列表)')

    // 演示菜单（表格 / 组件示例 / 子菜单）已下线，库里不该再有它们
    for (const gone of ['表格', '组件示例', '子菜单']) {
      if (html.includes(gone)) throw new Error(`菜单列表里仍有已下线的「${gone}」`)
    }
  })

  await check('菜单管理（新增，上级是树形选择）', async () => {
    const page = await loadFixture('menu-create')
    const html = await render(FormPage, { page, http })
    expect(
      html,
      ['新增菜单', '上级菜单', '名称', '图标', '路由', '排序', '显示为菜单', '提交'],
      '菜单管理(新增)'
    )
    // 上级菜单已从 n-select 换成 n-tree-select（层级可见）
    expectMarkup(html, ['n-form', 'n-tree-select'], '菜单管理(新增)')

    // 树形选项来自后端（`Menu::selectTree()`），根节点是「顶级菜单」
    const parentField = (page.data.fields || []).find((field) => field.name === 'parent_id')
    if (!parentField || parentField.type !== 'treeSelect') throw new Error('上级菜单应是 treeSelect 字段')
    if (!Array.isArray(parentField.options) || parentField.options.length === 0) {
      throw new Error('treeSelect 缺少树形 options')
    }
    if (parentField.options[0].label !== '顶级菜单' || parentField.options[0].key !== 0) {
      throw new Error('树形选项的根节点应为「顶级菜单」且 key=0')
    }
  })

  await check('菜单管理（编辑回填）', async () => {
    const page = await loadFixture('menu-edit')
    const html = await render(FormPage, { page, http })
    expect(html, ['编辑菜单', '菜单管理'], '菜单管理(编辑)')
  })

  await check('角色管理（列表）', async () => {
    const page = await loadFixture('role')
    const html = await render(GridPage, { page, http })
    expect(html, ['角色管理', '名称', '标识', '备注', '超级管理员', '操作'], '角色管理(列表)')
    expectMarkup(html, ['n-data-table'], '角色管理(列表)')
  })

  await check('角色管理（新增，多对多勾选框）', async () => {
    const page = await loadFixture('role-create')
    const html = await render(FormPage, { page, http })
    expect(
      html,
      ['新增角色', '角色名称', '标识', '权限', '绑定用户', '备注', '菜单管理', '角色管理', 'Administrator', '提交'],
      '角色管理(新增)'
    )
    // 权限 / 用户 都是 n-checkbox-group
    if (!html.includes('n-checkbox')) throw new Error('权限/用户勾选框没有渲染')
  })

  await check('角色管理（编辑回填权限与用户）', async () => {
    const page = await loadFixture('role-edit')
    const html = await render(FormPage, { page, http })
    expect(html, ['编辑角色', '超级管理员', 'super-admin'], '角色管理(编辑)')
    // 超级管理员勾了全部权限，应当有选中态
    if (!html.includes('n-checkbox--checked')) throw new Error('已勾选的权限/用户没有回填成选中态')
  })

  await check('权限管理（列表）', async () => {
    const page = await loadFixture('permission')
    const html = await render(GridPage, { page, http })
    expect(html, ['权限管理', '名称', '标识', '备注', 'role.manage', 'permission.manage'], '权限管理(列表)')
  })

  await check('用户管理（列表，带删除与批量选择）', async () => {
    const page = await loadFixture('user')
    const html = await render(GridPage, { page, http })
    expect(html, ['用户管理', '账号', '姓名', 'admin', '删除'], '用户管理(列表)')
    // selectable → 出现勾选列；deletable → 出现批量删除按钮
    if (!html.includes('n-data-table-th--selection')) throw new Error('开启 selectable 后应渲染勾选列')
    if (!html.includes('批量删除')) throw new Error('开启 deletable + selectable 后应渲染批量删除按钮')
  })

  await check('用户管理（新增，密码必填 + 头像上传）', async () => {
    const page = await loadFixture('user-create')
    const html = await render(FormPage, { page, http })
    expect(html, ['新增用户', '账号', '姓名', '密码', '头像', '角色', '超级管理员'], '用户管理(新增)')
    expectMarkup(html, ['n-upload', 'n-upload-dragger'], '用户管理(新增)')

    const avatar = (page.data.fields || []).find((field) => field.name === 'avatar')
    if (!avatar || avatar.type !== 'upload') throw new Error('头像应是 upload 字段')
    if (avatar.image !== true) throw new Error('头像 upload 应标记为图片')
  })

  await check('用户管理（编辑不回显密码哈希）', async () => {
    const page = await loadFixture('user-edit')
    const html = await render(FormPage, { page, http })
    expect(html, ['编辑用户', '密码（留空不修改）', 'admin'], '用户管理(编辑)')

    // 关键安全断言：bcrypt 哈希绝不能出现在 HTML 里
    if (html.includes('$2y$') || html.includes('2y$10$')) {
      throw new Error('编辑页把密码哈希回显到了前端')
    }
  })

  await check('审计日志（列表，只读）', async () => {
    const page = await loadFixture('log')
    const html = await render(GridPage, { page, http })
    expect(html, ['审计日志', '操作人', '动作', '路径', '摘要', 'IP', '时间'], '审计日志(列表)')

    // 只读列表：不能出现任何写操作入口（只认按钮，别被日志摘要里的文字骗到）
    for (const forbidden of ['批量删除', '新增']) {
      if (html.includes(`n-button__content">${forbidden}`)) {
        throw new Error(`只读日志页不应出现「${forbidden}」按钮`)
      }
    }
    if (html.includes('n-data-table-th--selection')) throw new Error('只读日志页不应有勾选列')
    // 也没有「操作」列
    if (html.includes('ea-actions')) throw new Error('只读日志页不应渲染操作列')
  })

  await check('用户详情（Show + 展示器）', async () => {
    const page = await loadFixture('user-show')
    const html = await render(ShowPage, { page, http })

    expect(html, ['用户详情', '基本信息', '角色与时间', '账号', '姓名', '头像', '会话版本'], '用户详情')

    const sections = page.sections || []
    if (sections.length !== 2) throw new Error('应渲染 2 个分节，实际 ' + sections.length)
    if (sections[0].columns !== 2) throw new Error('第一分区应为两列布局')

    // 展示器要真的生效（copyable 的账号 / image 的头像）
    const flat = JSON.stringify(sections)
    for (const displayer of ['copyable', 'image']) {
      if (!flat.includes(displayer)) throw new Error('详情页字段缺少展示器：' + displayer)
    }
  })

  await check('用户表单（Tab 分页签 + 一行两列）', async () => {
    const page = await loadFixture('user-create')
    const html = await render(FormPage, { page, http })

    // 只渲染当前 Tab 的内容，所以「密码」不在首屏（在第二页签里）
    expect(html, ['新增用户', '基本信息', '账号安全', '账号', '姓名', '头像'], '用户表单布局')

    const tabs = page.data.tabs || []
    if (tabs.length !== 2) throw new Error('应有 2 个 Tab，实际 ' + tabs.length)

    const names = (page.data.fields || []).map((field) => field.name)
    if (!names.includes('password')) throw new Error('第二页签的 password 字段应在 payload 里')

    const grouped = (page.data.fields || []).filter((field) => field.row === 2).map((field) => field.name)
    if (grouped.length !== 2) throw new Error('应有 2 个字段并排，实际 ' + JSON.stringify(grouped))

    // 渲染层要出得来 Tab 与栅格
    expectMarkup(html, ['n-tabs', 'n-grid'], '用户表单布局')
  })

  await check('列表展示器与列筛选标记', async () => {
    const page = await loadFixture('user')
    const html = await render(GridPage, { page, http })

    const columns = page.columns || []
    const image = columns.find((column) => column.field === 'avatar')
    const ranged = columns.find((column) => column.field === 'created_at')

    if (image?.displayer !== 'image') throw new Error('头像列应是 image 展示器')
    if (ranged?.column_filter?.type !== 'between') throw new Error('创建时间列应声明 between 筛选')

    // 行操作按钮（查看 / 重置密码）要出现
    expect(html, ['查看', '重置密码'], '列表行操作')
  })
  await check('SideMenu', async () => {
    const html = await render(SideMenu, {
      menus: [
        { title: '审计日志', url: '/log', icon: 'table', child: [] },
        {
          title: '系统管理',
          url: '',
          icon: 'settings',
          child: [{ title: '用户管理', url: '/user', icon: 'user', child: [] }],
        },
      ],
      activeKey: '/log',
    })
    expect(html, ['审计日志', '系统管理'], 'SideMenu')
    expectMarkup(html, ['n-menu', '<svg'], 'SideMenu')
  })

  await check('LoginPage', async () => {
    const html = await render(LoginPage, { boot: { title: 'Hyperf Admin' }, http })
    expect(html, ['Hyperf Admin', '请登录以继续', '用户名', '密码', '登录'], 'LoginPage')
  })

  await check('Shell（完整布局）', async () => {
    const page = await loadFixture('menu')
    const html = await render(Shell, {
      boot: {
        mode: 'app',
        title: 'Hyperf Admin',
        apiBase: '/admin',
        assetBase: '/static',
        loginPath: '/admin/login.html',
        locale: 'zh-CN',
        theme: {},
        user: { id: 1, username: 'admin', name: 'Administrator' },
        menus: [
          {
            title: '系统管理',
            url: '',
            icon: 'settings',
            child: [
              { title: '用户管理', url: '/user', icon: 'user', child: [] },
              { title: '菜单管理', url: '/menu', icon: 'menu', child: [] },
            ],
          },
        ],
        page,
      },
      http,
    })
    expect(html, ['Hyperf Admin', 'Administrator', '菜单管理', '系统管理'], 'Shell')
    expectMarkup(html, ['n-layout', 'n-layout-sider'], 'Shell')
  })
} finally {
  await rm(workdir, { recursive: true, force: true })
}

const relevant = warnings.filter(
  (warning) => !IGNORED_WARNINGS.some((ignored) => warning.includes(ignored))
)

if (relevant.length > 0) {
  originalError(`\n发现 ${relevant.length} 条 Vue / Naive UI 告警：`)
  for (const warning of [...new Set(relevant)].slice(0, 10)) {
    originalError('  - ' + warning.split('\n')[0].slice(0, 200))
  }
}

if (failures.length > 0 || relevant.length > 0) {
  if (failures.length > 0) {
    originalError(`\n${failures.length} 个渲染用例失败`)
  }
  process.exit(1)
}

console.log(`\nOK ${passed} 个页面 SSR 渲染通过，无告警`)
